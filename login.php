<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/session.php';
session_bootstrap();

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/supabase.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/email_notifications.php';
require_once __DIR__ . '/includes/device_trust.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

$webTrustKey = device_trust_ip_key();

$needsReverify = !empty($_SESSION['web_needs_otp'])
    || (isset($_GET['reverify']) && (string) $_GET['reverify'] === '1');
$forceLoginScreen = $needsReverify
    || (isset($_GET['unauth']) && (string) $_GET['unauth'] === '1');

if (!$forceLoginScreen && isset($_SESSION['user']) && is_array($_SESSION['user'])) {
    header('Location: /home');
    return;
}

// CSRF token (basic protection).
csrf_ensure_token();

$error = null;
$info = null;
$loginRetryAfter = 0;
$old = [
    'email' => '',
];
$challenge = (isset($_SESSION['admin_login_challenge']) && is_array($_SESSION['admin_login_challenge']))
    ? $_SESSION['admin_login_challenge']
    : null;
$isVerificationStep = (isset($_GET['step']) && (string) $_GET['step'] === 'verify')
    || $needsReverify;

if ($needsReverify) {
    $why = strtolower(trim((string) ($_GET['why'] ?? (string) ($_SESSION['web_needs_otp'] ?? ''))));
    if ($why === 'ip') {
        $info = 'New network detected — enter the code once to trust this connection.';
    } elseif ($why === 'day') {
        $info = 'New day after 12:00 AM (Manila) — verify once to continue.';
    } else {
        $info = 'Verification required — new day (12:00 AM Manila) or a new network.';
    }
}

function admin_login_headers(): array
{
    return [
        'Accept: application/json',
        'apikey: ' . SUPABASE_KEY,
        'Authorization: Bearer ' . SUPABASE_KEY,
    ];
}

function admin_login_write_headers(): array
{
    return [
        'Content-Type: application/json',
        'Accept: application/json',
        'apikey: ' . SUPABASE_KEY,
        'Authorization: Bearer ' . SUPABASE_KEY,
        'Prefer: resolution=merge-duplicates,return=representation',
    ];
}

function admin_login_ph_timezone(): DateTimeZone
{
    return new DateTimeZone('Asia/Manila');
}

function admin_login_verified_on_current_ph_day(?string $verifiedAtIso): bool
{
    if (!is_string($verifiedAtIso) || trim($verifiedAtIso) === '') {
        return false;
    }

    try {
        $verified = (new DateTimeImmutable($verifiedAtIso))->setTimezone(admin_login_ph_timezone());
        $today = new DateTimeImmutable('now', admin_login_ph_timezone());

        return $verified->format('Y-m-d') === $today->format('Y-m-d');
    } catch (Throwable $e) {
        return false;
    }
}

function admin_login_resolve_full_name(array $user): string
{
    $fullName = trim((string) ($user['full_name'] ?? ''));
    if ($fullName !== '') {
        return $fullName;
    }

    return build_display_name(
        (string) ($user['first_name'] ?? ''),
        (string) ($user['middle_name'] ?? ''),
        (string) ($user['last_name'] ?? ''),
        (string) ($user['suffix'] ?? '')
    );
}

function admin_login_fetch_daily_verification(string $userId): ?string
{
    if ($userId === '') {
        return null;
    }

    $res = supabase_request(
        'GET',
        rtrim(SUPABASE_URL, '/') . '/rest/v1/admin_login_daily_verifications'
            . '?select=verified_at'
            . '&user_id=eq.' . rawurlencode($userId)
            . '&limit=1',
        admin_login_headers()
    );

    if (!$res['ok']) {
        return null;
    }

    $rows = json_decode((string) $res['body'], true);
    if (!is_array($rows) || !isset($rows[0]['verified_at'])) {
        return null;
    }

    return (string) $rows[0]['verified_at'];
}

function admin_login_has_valid_daily_verification(string $userId): bool
{
    $verifiedAt = admin_login_fetch_daily_verification($userId);

    return admin_login_verified_on_current_ph_day($verifiedAt);
}

function admin_login_store_daily_verification(string $userId): void
{
    if ($userId === '') {
        return;
    }

    $now = gmdate('c');
    $payload = [
        'user_id' => $userId,
        'verified_at' => $now,
        'updated_at' => $now,
    ];

    supabase_request(
        'POST',
        rtrim(SUPABASE_URL, '/') . '/rest/v1/admin_login_daily_verifications',
        admin_login_write_headers(),
        json_encode($payload, JSON_UNESCAPED_SLASHES)
    );
}

function admin_login_normalize_staff_role(string $role): ?string
{
    $role = strtolower(trim($role));
    if ($role === 'admin' || $role === 'teacher') {
        return $role;
    }
    return null;
}

/**
 * Prefer live DB role over session challenge (challenge can be corrupted, e.g. Resend
 * historically dropped role and defaulted to admin).
 */
function admin_login_fetch_staff_role(string $userId): ?string
{
    $userId = trim($userId);
    if ($userId === '') {
        return null;
    }

    $res = supabase_request(
        'GET',
        rtrim(SUPABASE_URL, '/') . '/rest/v1/' . SUPABASE_TABLE_USERS
            . '?select=role'
            . '&id=eq.' . rawurlencode($userId)
            . '&limit=1',
        admin_login_headers()
    );
    if (!($res['ok'] ?? false)) {
        return null;
    }
    $rows = json_decode((string) ($res['body'] ?? ''), true);
    if (!is_array($rows) || !isset($rows[0]) || !is_array($rows[0])) {
        return null;
    }

    return admin_login_normalize_staff_role((string) ($rows[0]['role'] ?? ''));
}

function admin_login_establish_user_session(string $userId, string $fullName, string $email, string $role): void
{
    $normalized = admin_login_normalize_staff_role($role);
    if ($normalized === null) {
        throw new InvalidArgumentException('Invalid staff role for web session.');
    }

    // Prefer regenerate, but never abort login if it fails (Windows/php -S / locked session files).
    if (session_status() === PHP_SESSION_ACTIVE) {
        try {
            @session_regenerate_id(true);
        } catch (Throwable $e) {
            error_log('admin_login_establish_user_session: session_regenerate_id failed: ' . $e->getMessage());
        }
    }

    $_SESSION['user'] = [
        'id' => $userId,
        'full_name' => $fullName !== '' ? $fullName : ($normalized === 'teacher' ? 'Teacher' : 'Admin'),
        'email' => $email,
        'role' => $normalized,
    ];
    unset($_SESSION['admin_login_challenge']);
}

/**
 * Seed the gate cache used by auth_enforce_daily_web_verification
 * so /home does not race-logout right after a successful OTP (Hostinger hop / IP glitch).
 */
function admin_login_seed_daily_ok_cache(string $userId, string $trustKey): void
{
    $userId = trim($userId);
    if ($userId === '') {
        return;
    }
    $phDay = (new DateTimeImmutable('now', admin_login_ph_timezone()))->format('Y-m-d');
    $keys = function_exists('device_trust_request_keys') ? device_trust_request_keys() : [];
    if ($trustKey !== '' && !in_array($trustKey, $keys, true)) {
        array_unshift($keys, $trustKey);
    }
    $_SESSION['web_daily_ok_user'] = $userId;
    $_SESSION['web_daily_ok_trust'] = $trustKey;
    $_SESSION['web_daily_ok_trust_keys'] = $keys;
    $_SESSION['web_daily_ok_ph_day'] = $phDay;
    $_SESSION['web_daily_ok_until'] = time() + 3600;
    $_SESSION['web_otp_grace_until'] = time() + 1800;
    unset($_SESSION['web_needs_otp']);
}

function admin_login_resend_cooldown_seconds(): int
{
    return 60;
}

function admin_login_parse_sent_at(?string $raw): ?int
{
    $raw = trim((string) $raw);
    if ($raw === '') {
        return null;
    }
    $ts = strtotime($raw);
    return ($ts !== false && $ts > 0) ? $ts : null;
}

/**
 * Seconds remaining before another OTP email may be sent (0 = allowed).
 */
function admin_login_resend_seconds_remaining(string $userId, ?array $challenge = null): int
{
    $cooldown = admin_login_resend_cooldown_seconds();
    $now = time();
    $lastSent = null;

    $issuedAt = is_array($challenge) ? (int) ($challenge['issued_at'] ?? 0) : 0;
    if ($issuedAt > 0) {
        $lastSent = $issuedAt;
    }

    $userId = trim($userId);
    if ($userId !== '') {
        $res = supabase_request(
            'GET',
            rtrim(SUPABASE_URL, '/') . '/rest/v1/email_verification_codes'
                . '?select=last_sent_at'
                . '&user_id=eq.' . rawurlencode($userId)
                . '&limit=1',
            admin_login_headers()
        );
        if ($res['ok'] ?? false) {
            $rows = json_decode((string) ($res['body'] ?? ''), true);
            $dbTs = is_array($rows) && isset($rows[0])
                ? admin_login_parse_sent_at(isset($rows[0]['last_sent_at']) ? (string) $rows[0]['last_sent_at'] : null)
                : null;
            if ($dbTs !== null && ($lastSent === null || $dbTs > $lastSent)) {
                $lastSent = $dbTs;
            }
        }
    }

    if ($lastSent === null) {
        return 0;
    }
    $elapsed = $now - $lastSent;
    if ($elapsed < 0) {
        return $cooldown;
    }
    if ($elapsed >= $cooldown) {
        return 0;
    }
    return $cooldown - $elapsed;
}

function admin_login_generate_code(): string
{
    return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
}

function admin_login_store_code(string $userId, string $code): bool
{
    $now = gmdate('c');
    $expiresAt = gmdate('c', time() + 300);
    $payload = [
        'user_id' => $userId,
        'code' => $code,
        'expires_at' => $expiresAt,
        'created_at' => $now,
        'last_sent_at' => $now,
        'purpose' => 'login',
    ];

    $res = supabase_request(
        'POST',
        rtrim(SUPABASE_URL, '/') . '/rest/v1/email_verification_codes?on_conflict=user_id,purpose',
        [
            'Content-Type: application/json',
            'Accept: application/json',
            'apikey: ' . SUPABASE_KEY,
            'Authorization: Bearer ' . SUPABASE_KEY,
            'Prefer: resolution=merge-duplicates,return=representation',
        ],
        json_encode($payload, JSON_UNESCAPED_SLASHES)
    );
    if ($res['ok'] === true) {
        return true;
    }

    // Legacy table without purpose column.
    unset($payload['purpose']);
    $res = supabase_request(
        'POST',
        rtrim(SUPABASE_URL, '/') . '/rest/v1/email_verification_codes',
        [
            'Content-Type: application/json',
            'Accept: application/json',
            'apikey: ' . SUPABASE_KEY,
            'Authorization: Bearer ' . SUPABASE_KEY,
            'Prefer: resolution=merge-duplicates,return=representation',
        ],
        json_encode($payload, JSON_UNESCAPED_SLASHES)
    );

    return $res['ok'] === true;
}

function admin_login_fetch_code_row(string $userId): ?array
{
    $res = supabase_request(
        'GET',
        rtrim(SUPABASE_URL, '/') . '/rest/v1/email_verification_codes'
            . '?select=user_id,code,expires_at'
            . '&user_id=eq.' . rawurlencode($userId)
            . '&purpose=eq.login'
            . '&limit=1',
        admin_login_headers()
    );

    if (!$res['ok']) {
        $res = supabase_request(
            'GET',
            rtrim(SUPABASE_URL, '/') . '/rest/v1/email_verification_codes'
                . '?select=user_id,code,expires_at'
                . '&user_id=eq.' . rawurlencode($userId)
                . '&limit=1',
            admin_login_headers()
        );
    }

    if (!$res['ok']) {
        return null;
    }

    $rows = json_decode((string) $res['body'], true);
    return (is_array($rows) && isset($rows[0]) && is_array($rows[0])) ? $rows[0] : null;
}

function admin_login_delete_code(string $userId): void
{
    $res = supabase_request(
        'DELETE',
        rtrim(SUPABASE_URL, '/') . '/rest/v1/email_verification_codes'
            . '?user_id=eq.' . rawurlencode($userId)
            . '&purpose=eq.login',
        admin_login_headers()
    );
    if (!($res['ok'] ?? false)) {
        supabase_request(
            'DELETE',
            rtrim(SUPABASE_URL, '/') . '/rest/v1/email_verification_codes'
                . '?user_id=eq.' . rawurlencode($userId),
            admin_login_headers()
        );
    }
}

function admin_login_issue_challenge(
    array $user,
    ?string &$error,
    ?string &$info,
    string $role = 'admin',
    string $gateReason = ''
): bool
{
    $userId = trim((string) ($user['id'] ?? ''));
    $email = trim((string) ($user['email'] ?? ''));
    $fullName = admin_login_resolve_full_name($user);
    $normalizedRole = admin_login_normalize_staff_role($role);
    if ($normalizedRole === null) {
        $error = 'This account cannot use web login verification.';
        return false;
    }
    $role = $normalizedRole;

    if ($userId === '' || $email === '') {
        $error = 'Missing account details. Please try again.';
        return false;
    }

    $code = admin_login_generate_code();
    if (!admin_login_store_code($userId, $code)) {
        $error = 'Unable to prepare the verification code. Please try again.';
        return false;
    }

    if ($role === 'teacher') {
        $sent = send_teacher_login_verification_email($email, $fullName, $code);
    } else {
        $sent = send_admin_login_verification_email($email, $fullName, $code);
    }
    if (!$sent) {
        $smtpDebug = function_exists('smtp_get_last_error') ? smtp_get_last_error() : '';
        $error = $smtpDebug !== ''
            ? 'Unable to send verification code email: ' . $smtpDebug
            : 'Unable to send verification code email. Please try again.';
        return false;
    }

    $_SESSION['admin_login_challenge'] = [
        'id' => $userId,
        'full_name' => $fullName !== '' ? $fullName : ($role === 'teacher' ? 'Teacher' : 'Admin'),
        'email' => $email,
        'role' => $role,
        'issued_at' => time(),
        'gate_reason' => $gateReason,
    ];

    $maskedEmail = preg_replace('/(^.).*(@.*$)/', '$1••••$2', $email) ?: $email;
    $info = 'A 6-digit verification code was sent to ' . $maskedEmail . '.';
    if ($gateReason === 'new_ip') {
        $info .= ' New network/IP detected — verify once to trust this connection.';
    } elseif ($gateReason === 'daily') {
        $info .= ' Daily verification resets at 12:00 AM (Manila).';
    }
    return true;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $sessionUser = (isset($_SESSION['user']) && is_array($_SESSION['user'])) ? $_SESSION['user'] : null;
    $sessionUserId = is_array($sessionUser) ? trim((string) ($sessionUser['id'] ?? '')) : '';

    if ($challenge !== null && $sessionUserId !== '' && trim((string) ($challenge['id'] ?? '')) !== $sessionUserId) {
        unset($_SESSION['admin_login_challenge']);
        $challenge = null;
    }

    if ($challenge !== null && !$isVerificationStep) {
        admin_login_delete_code((string) ($challenge['id'] ?? ''));
        unset($_SESSION['admin_login_challenge']);
        $challenge = null;
        if (!empty($_GET)) {
            header('Location: /login');
            exit;
        }
    } elseif ($challenge === null && $isVerificationStep && $sessionUser === null) {
        header('Location: /login');
        exit;
    }

    if ($sessionUser !== null && $needsReverify && $challenge === null) {
        $issueError = null;
        $issueInfo = null;
        $issueRole = admin_login_normalize_staff_role((string) ($sessionUser['role'] ?? ''))
            ?? admin_login_fetch_staff_role($sessionUserId)
            ?? 'admin';
        $gateWhy = strtolower(trim((string) ($_GET['why'] ?? (string) ($_SESSION['web_needs_otp'] ?? ''))));
        $gateReason = $gateWhy === 'day' ? 'daily' : 'new_ip';
        if (admin_login_issue_challenge($sessionUser, $issueError, $issueInfo, $issueRole, $gateReason)) {
            $challenge = $_SESSION['admin_login_challenge'] ?? $challenge;
            if (is_string($issueInfo) && $issueInfo !== '') {
                $info = $issueInfo;
            }
        } elseif (is_string($issueError) && $issueError !== '') {
            $error = $issueError;
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $csrfToken = isset($_POST['csrf_token']) ? (string) $_POST['csrf_token'] : null;
        $expected = isset($_SESSION['csrf_token']) && is_string($_SESSION['csrf_token']) ? $_SESSION['csrf_token'] : '';
        if ($expected === '' || $csrfToken === null || $csrfToken === '' || !hash_equals($expected, $csrfToken)) {
            $error = 'Invalid request. Please refresh and try again.';
            throw new RuntimeException('Invalid CSRF token');
        }

        $authStep = strtolower(trim((string) ($_POST['auth_step'] ?? 'credentials')));

        if ($authStep === 'resend_code') {
            if ($challenge === null) {
                $error = 'Verification session expired. Please log in again.';
            } else {
                $resendRole = admin_login_normalize_staff_role((string) ($challenge['role'] ?? ''))
                    ?? admin_login_fetch_staff_role((string) ($challenge['id'] ?? ''));
                if ($resendRole === null) {
                    $error = 'Verification session expired. Please log in again.';
                    unset($_SESSION['admin_login_challenge']);
                    $challenge = null;
                } else {
                    $wait = admin_login_resend_seconds_remaining(
                        (string) ($challenge['id'] ?? ''),
                        $challenge
                    );
                    if ($wait > 0) {
                        $error = 'Please wait ' . $wait . 's before resending the code.';
                    } else {
                        require_once __DIR__ . '/includes/api_rate_limit.php';
                        $clientIp = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
                        $rateKey = 'web_login_resend:' . (string) ($challenge['id'] ?? '') . ':' . $clientIp;
                        if (!api_rate_limit_allow($rateKey, 6, 300)) {
                            $error = 'Too many resend attempts. Try again in a few minutes.';
                        } else {
                            admin_login_issue_challenge([
                                'id' => $challenge['id'] ?? '',
                                'full_name' => $challenge['full_name'] ?? '',
                                'email' => $challenge['email'] ?? '',
                            ], $error, $info, $resendRole, (string) ($challenge['gate_reason'] ?? ''));
                            $challenge = $_SESSION['admin_login_challenge'] ?? $challenge;
                            if ($error === null) {
                                header('Location: /login?step=verify');
                                exit;
                            }
                        }
                    }
                }
            }
        } elseif ($authStep === 'verify_code') {
            if ($challenge === null) {
                $error = 'Verification session expired. Please log in again.';
            } else {
                require_once __DIR__ . '/includes/api_rate_limit.php';
                $clientIp = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
                $verifyRateKey = 'web_login_verify:' . (string) ($challenge['id'] ?? '') . ':' . $clientIp;
                if (!api_rate_limit_allow($verifyRateKey, 10, 900)) {
                    $error = 'Too many verification attempts. Try again in a few minutes.';
                } else {
                $enteredCode = trim((string) ($_POST['verification_code'] ?? ''));
                if ($enteredCode === '' || !preg_match('/^\d{6}$/', $enteredCode)) {
                    $error = 'Enter the 6-digit verification code.';
                } else {
                    $row = admin_login_fetch_code_row((string) ($challenge['id'] ?? ''));
                    if ($row === null) {
                        $error = 'Verification code not found. Please resend the code.';
                    } else {
                        $storedCode = trim((string) ($row['code'] ?? ''));
                        $expiresAt = isset($row['expires_at']) ? strtotime((string) $row['expires_at']) : false;
                        if ($expiresAt === false || time() > $expiresAt) {
                            $error = 'Verification code expired. Please resend the code.';
                        } elseif ($storedCode === '' || !preg_match('/^\d{6}$/', $storedCode)
                            || !hash_equals($storedCode, $enteredCode)) {
                            $error = 'Invalid verification code.';
                        } else {
                            admin_login_delete_code((string) ($challenge['id'] ?? ''));
                            $verifiedUserId = (string) ($challenge['id'] ?? '');
                            $sessionRole = admin_login_fetch_staff_role($verifiedUserId)
                                ?? admin_login_normalize_staff_role((string) ($challenge['role'] ?? ''));
                            if ($sessionRole === null) {
                                $error = 'Unable to verify account role. Please log in again.';
                                unset($_SESSION['admin_login_challenge']);
                                $challenge = null;
                            } else {
                                // 1) Create the web session FIRST so a later DB blip cannot strand trust rows
                                //    without a logged-in session (or wipe a fresh login at /home).
                                admin_login_establish_user_session(
                                    $verifiedUserId,
                                    (string) ($challenge['full_name'] ?? ''),
                                    (string) ($challenge['email'] ?? ''),
                                    $sessionRole
                                );
                                admin_login_seed_daily_ok_cache($verifiedUserId, $webTrustKey);

                                // 2) Persist OTP gate state (best-effort — session already valid for ~10 min).
                                try {
                                    admin_login_store_daily_verification($verifiedUserId);
                                } catch (Throwable $persistEx) {
                                    error_log('OTP daily verification store failed: ' . $persistEx->getMessage());
                                }
                                try {
                                    $trustRes = device_trust_upsert_request($verifiedUserId, 'web');
                                    if (!($trustRes['ok'] ?? false)) {
                                        error_log(
                                            'OTP device trust upsert failed user=' . $verifiedUserId
                                            . ' err=' . (string) ($trustRes['error'] ?? 'unknown')
                                        );
                                    }
                                } catch (Throwable $trustEx) {
                                    error_log('OTP device trust upsert exception: ' . $trustEx->getMessage());
                                }

                                session_write_close();
                                header('Location: /home');
                                exit;
                            }
                        }
                    }
                }
                }
            }
        } else {
            require_once __DIR__ . '/includes/api_rate_limit.php';
            $clientIp = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
            $email = isset($_POST['email']) ? strtolower(clean_string((string) $_POST['email'])) : '';
            $password = isset($_POST['password']) ? (string) $_POST['password'] : '';
            $old['email'] = $email;

            // Per-email lockout so admin failures don't block a teacher account.
            // Soft IP cap only stops spraying many different emails from one IP.
            $loginWindow = 900; // 15 minutes
            $emailMaxFails = 8;
            $ipMaxFails = 40;
            $emailBucket = $email !== '' ? ('web_login:email:' . $email) : '';
            $ipBucket = 'web_login:ip:' . $clientIp;

            $retryEmail = ($emailBucket !== '' && filter_var($email, FILTER_VALIDATE_EMAIL))
                ? api_rate_limit_retry_after($emailBucket, $emailMaxFails, $loginWindow)
                : 0;
            $retryIp = api_rate_limit_retry_after($ipBucket, $ipMaxFails, $loginWindow);
            $loginRetryAfter = max($retryEmail, $retryIp);

            if ($loginRetryAfter > 0) {
                $waitLabel = api_rate_limit_format_wait($loginRetryAfter);
                if ($retryEmail > 0 && $retryEmail >= $retryIp) {
                    $error = 'Too many login attempts for this account. Try again in ' . $waitLabel . '.';
                } else {
                    $error = 'Too many login attempts from this network. Try again in ' . $waitLabel . '.';
                }
            } elseif ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $error = 'Invalid email or password.';
                api_rate_limit_record($ipBucket, $loginWindow);
            } elseif ($password === '') {
                $error = 'Invalid email or password.';
                if ($emailBucket !== '') {
                    api_rate_limit_record($emailBucket, $loginWindow);
                }
                api_rate_limit_record($ipBucket, $loginWindow);
            } else {
                $filterEmail = rawurlencode($email);
                $url = rtrim(SUPABASE_URL, '/') . '/rest/v1/' . SUPABASE_TABLE_USERS
                    . '?select=id,first_name,middle_name,last_name,suffix,email,role,password&email=eq.' . $filterEmail
                    . '&limit=1';

                $res = supabase_request('GET', $url, admin_login_headers());

                if (!$res['ok']) {
                    $error = 'Login failed. Please try again.';
                } else {
                    $decoded = is_string($res['body']) ? json_decode($res['body'], true) : null;
                    $rows = is_array($decoded) ? $decoded : [];

                    if (count($rows) < 1 || !isset($rows[0]['password'])) {
                        $error = 'Invalid email or password.';
                        api_rate_limit_record($emailBucket, $loginWindow);
                        api_rate_limit_record($ipBucket, $loginWindow);
                        $loginRetryAfter = api_rate_limit_retry_after($emailBucket, $emailMaxFails, $loginWindow);
                        if ($loginRetryAfter > 0) {
                            $error = 'Too many login attempts for this account. Try again in '
                                . api_rate_limit_format_wait($loginRetryAfter) . '.';
                        }
                    } else {
                        $user = $rows[0];
                        $storedHash = (string) $user['password'];

                        if (!password_verify($password, $storedHash)) {
                            $error = 'Invalid email or password.';
                            api_rate_limit_record($emailBucket, $loginWindow);
                            api_rate_limit_record($ipBucket, $loginWindow);
                            $loginRetryAfter = api_rate_limit_retry_after($emailBucket, $emailMaxFails, $loginWindow);
                            if ($loginRetryAfter > 0) {
                                $error = 'Too many login attempts for this account. Try again in '
                                    . api_rate_limit_format_wait($loginRetryAfter) . '.';
                            }
                        } else {
                            $role = admin_login_normalize_staff_role((string) ($user['role'] ?? ''));

                            if ($role === 'teacher' || $role === 'admin') {
                                $userId = trim((string) ($user['id'] ?? ''));
                                $fullName = admin_login_resolve_full_name($user);
                                $userEmail = trim((string) ($user['email'] ?? ''));

                                $verifiedToday = admin_login_has_valid_daily_verification($userId);
                                $trustedIp = device_trust_is_trusted($userId, $webTrustKey);

                                // Same day + same network → skip OTP.
                                // New day (12AM Manila) OR new network → OTP once, then that network is trusted.
                                if ($verifiedToday && $trustedIp) {
                                    device_trust_upsert_request($userId, 'web');
                                    admin_login_establish_user_session($userId, $fullName, $userEmail, $role);
                                    admin_login_seed_daily_ok_cache($userId, $webTrustKey);
                                    session_write_close();
                                    header('Location: /home');
                                    exit;
                                }

                                $gateReason = !$trustedIp ? 'new_ip' : 'daily';
                                if (admin_login_issue_challenge($user, $error, $info, $role, $gateReason)) {
                                    header('Location: /login?step=verify');
                                    exit;
                                }
                            } else {
                                $error = 'Students must use the mobile app to login.';
                            }
                        }
                    }
                }
            }
        }
    } catch (Throwable $e) {
        error_log(
            'login.php POST failed: ' . $e->getMessage()
            . ' @ ' . $e->getFile() . ':' . $e->getLine()
        );
        // OTP may have already created a session before a later step threw.
        if (isset($_SESSION['user']) && is_array($_SESSION['user'])) {
            header('Location: /home');
            exit;
        }
        $error = 'Login failed. Please try again.';
    }
}
$isVerificationMode = $challenge !== null && (
    $isVerificationStep
    || (
        ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST'
        && in_array(strtolower(trim((string) ($_POST['auth_step'] ?? ''))), ['resend_code', 'verify_code'], true)
    )
);
$roleLabel = ($challenge && ($challenge['role'] ?? '') === 'teacher') ? 'teacher' : 'admin';
$roleLabelCap = ucfirst($roleLabel);
$gateReason = is_array($challenge) ? strtolower(trim((string) ($challenge['gate_reason'] ?? ''))) : '';
$gateReasonMessage = match ($gateReason) {
    'new_ip' => 'New network detected — verify once to trust this connection.',
    'daily' => 'Daily security check — verification resets every day at 12:00 AM (Manila).',
    default => 'Enter the 6-digit code sent to your email to continue.',
};
$resendCooldownRemaining = 0;
if ($isVerificationMode && is_array($challenge)) {
    $resendCooldownRemaining = admin_login_resend_seconds_remaining(
        (string) ($challenge['id'] ?? ''),
        $challenge
    );
}
?>

<!doctype html>
<html lang="en" class="dark">

<head>
    <meta charset="utf-8" />
    <?php require_once __DIR__ . '/includes/favicon.php'; render_favicon_tags(); ?>
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>CCS PulseConnect — Login</title>
    <link rel="stylesheet" href="/assets/css/tailwind.css?v=<?= (int) @filemtime(__DIR__ . '/assets/css/tailwind.css') ?>" />
    <link rel="stylesheet" href="/assets/css/app.css?v=<?= (int) @filemtime(__DIR__ . '/assets/css/app.css') ?>" />
    <link rel="stylesheet" href="/assets/css/auth.css?v=<?= (int) @filemtime(__DIR__ . '/assets/css/auth.css') ?>" />
</head>

<body class="min-h-screen bg-zinc-950 text-zinc-100 auth-login-bg">
    <div class="interactive-bg"></div>
    <div class="min-h-screen grid grid-cols-1 lg:grid-cols-2 relative z-10">
        <div class="hidden lg:flex items-center justify-center p-10">
            <div class="max-w-md w-full">
                <div class="logo-collision-wrapper">
                    <img src="/assets/BSIT.png" alt="BSIT" class="logo-bsit" />
                    <img src="/assets/CS.png" alt="CS" class="logo-cs" />
                    <div class="collision-flash"></div>
                    <!-- Lightning & Spark Effects -->
                    <div class="lightning-strike"></div>
                    <div class="spark spark-1"></div>
                    <div class="spark spark-2"></div>
                    <div class="spark spark-3"></div>
                    <div class="spark spark-4"></div>
                    <img src="/assets/CCS.png" alt="CCS" class="logo-ccs" />
                </div>
                <div class="text-center mt-6">
                    <div class="text-xs tracking-[0.35em] uppercase text-zinc-400 font-bold">PulseCONNECT</div>
                    <h1 class="text-3xl font-semibold mt-2 leading-tight">Event Management System</h1>
                    <p class="text-zinc-400 mt-4 text-sm leading-relaxed min-h-[48px]">
                        <span
                            id="loginDescTyped"
                            data-full-text="Register for events, get your QR e-ticket, scan attendance, and download certificates."></span>
                        <span id="loginDescCaret" class="inline-block w-2 text-zinc-400 align-baseline"></span>
                        <noscript>Register for events, get your QR e-ticket, scan attendance, and download certificates.</noscript>
                    </p>
                </div>
            </div>
        </div>

        <div class="flex items-center justify-center p-6">
            <div class="w-full max-w-md">
                <div class="mb-6">
                    <div class="text-xs tracking-[0.35em] uppercase text-zinc-400">
                        <?= $isVerificationMode ? 'Verification required' : 'Welcome back' ?>
                    </div>
                    <h2 class="text-3xl font-semibold mt-3">
                        <?= $isVerificationMode ? "Verify {$roleLabel} login" : 'Log in' ?>
                    </h2>
                    <p class="text-zinc-400 mt-2 text-sm">
                        <?= $isVerificationMode
                            ? "Enter the 6-digit code sent to your {$roleLabel} email to continue."
                            : 'Use your email and password to continue.' ?>
                    </p>
                </div>

                <?php if ($info): ?>
                    <div class="mb-4 rounded-xl border border-emerald-900/40 bg-emerald-950/25 px-4 py-3 text-sm text-emerald-200">
                        <?= htmlspecialchars($info) ?>
                    </div>
                <?php endif; ?>

                <?php if ($error): ?>
                    <div class="mb-4 rounded-xl border border-red-900/50 bg-red-950/30 px-4 py-3 text-sm text-red-200">
                        <div><?= htmlspecialchars($error) ?></div>
                        <?php if ($loginRetryAfter > 0): ?>
                            <div class="mt-2 text-xs text-red-300/90" data-login-retry="<?= (int) $loginRetryAfter ?>">
                                Available again in
                                <strong id="loginRetryCountdown"><?= htmlspecialchars(api_rate_limit_format_wait($loginRetryAfter)) ?></strong>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <form method="POST" class="rounded-2xl border border-zinc-800 bg-zinc-900/30 p-6">
                    <input type="hidden" name="csrf_token"
                        value="<?= htmlspecialchars((string) $_SESSION['csrf_token']) ?>" />
                    <?php if ($isVerificationMode): ?>
                        <input type="hidden" name="auth_step" value="verify_code" />
                        <div class="mb-4 rounded-xl border border-zinc-800 bg-zinc-950/70 px-4 py-3">
                            <div class="text-[11px] uppercase tracking-[0.26em] text-zinc-500"><?= $roleLabelCap ?> email</div>
                            <div class="mt-1 text-sm font-semibold text-zinc-100">
                                <?= htmlspecialchars((string) ($challenge['email'] ?? '')) ?>
                            </div>
                            <div class="mt-2 text-xs leading-relaxed text-zinc-400">
                                <?= htmlspecialchars($gateReasonMessage) ?>
                            </div>
                        </div>

                        <label class="block text-xs text-zinc-400 mb-3" id="verification_code_label">Verification Code</label>
                        <input type="hidden" id="verification_code" name="verification_code" value="" autocomplete="one-time-code" />
                        <div class="otp-boxes" role="group" aria-labelledby="verification_code_label" data-otp-root>
                            <?php for ($i = 0; $i < 6; $i++): ?>
                                <input
                                    type="text"
                                    inputmode="numeric"
                                    pattern="[0-9]*"
                                    maxlength="6"
                                    class="otp-box"
                                    data-otp-index="<?= $i ?>"
                                    aria-label="Digit <?= $i + 1 ?> of 6"
                                    autocomplete="<?= $i === 0 ? 'one-time-code' : 'off' ?>"
                                    <?= $i === 0 ? 'autofocus' : '' ?>
                                />
                            <?php endfor; ?>
                        </div>

                        <div class="h-5"></div>

                        <button type="submit" id="btnVerifyCode"
                            class="w-full rounded-xl bg-zinc-100 text-zinc-900 px-4 py-3 font-medium hover:bg-zinc-200 transition">
                            Verify and Continue
                        </button>

                        <div class="mt-4">
                            <button type="submit" name="auth_step" value="resend_code" formnovalidate
                                id="btnResendCode"
                                data-cooldown="<?= (int) $resendCooldownRemaining ?>"
                                class="w-full rounded-xl border border-zinc-700 bg-zinc-950 px-4 py-3 text-sm font-medium text-zinc-100 hover:bg-zinc-900 transition disabled:opacity-50 disabled:cursor-not-allowed disabled:hover:bg-zinc-950"
                                <?= $resendCooldownRemaining > 0 ? 'disabled' : '' ?>>
                                <?= $resendCooldownRemaining > 0
                                    ? 'Resend in ' . (int) $resendCooldownRemaining . 's'
                                    : 'Resend Code' ?>
                            </button>
                            <p id="resendCooldownHint" class="mt-2 text-center text-[11px] text-zinc-500 <?= $resendCooldownRemaining > 0 ? '' : 'hidden' ?>">
                                Wait before requesting another code.
                            </p>
                        </div>
                    <?php else: ?>
                        <input type="hidden" name="auth_step" value="credentials" />

                        <label class="block text-xs text-zinc-400 mb-1" for="email">Email</label>
                        <input id="email" name="email" type="email" value="<?= htmlspecialchars((string) $old['email']) ?>"
                            required
                            class="w-full rounded-xl bg-zinc-950 border border-zinc-800 px-3 py-3 text-sm outline-none focus:ring-2 focus:ring-zinc-700"
                            placeholder="Enter Email" autocomplete="email" />

                        <div class="h-4"></div>

                        <label class="block text-xs text-zinc-400 mb-1" for="password">Password</label>
                        <div class="auth-password-field">
                            <input id="password" name="password" type="password" required
                                class="w-full rounded-xl bg-zinc-950 border border-zinc-800 px-3 py-3 pr-11 text-sm outline-none focus:ring-2 focus:ring-zinc-700"
                                placeholder="Your password" autocomplete="current-password" />
                            <button type="button" id="togglePassword" class="auth-password-toggle" aria-label="Show password" aria-pressed="false" title="Show password">
                                <svg class="auth-eye-icon auth-eye-show" xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                </svg>
                                <svg class="auth-eye-icon auth-eye-hide hidden" xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M3.98 8.223A10.477 10.477 0 001.934 12C3.226 16.338 7.244 19.5 12 19.5c.993 0 1.953-.138 2.863-.395M6.228 6.228A10.45 10.45 0 0112 4.5c4.756 0 8.773 3.162 10.065 7.498a10.523 10.523 0 01-4.293 5.774M6.228 6.228L3 3m3.228 3.228L3 3m3.228 3.228l3.65 3.65m7.894 7.894L21 21m-3.228-3.228l-3.65-3.65m0 0a3 3 0 10-4.243-4.243m4.242 4.242L9.88 9.88" />
                                </svg>
                            </button>
                        </div>

                        <div class="auth-links mt-2 mb-1">
                            <span></span>
                            <a href="forgot_password.php">Forgot password?</a>
                        </div>

                        <div class="h-3"></div>

                        <button type="submit"
                            class="w-full rounded-xl bg-zinc-100 text-zinc-900 px-4 py-3 font-medium hover:bg-zinc-200 transition">
                            Login
                        </button>
                    <?php endif; ?>

                </form>

                <div class="text-center text-xs text-zinc-500 mt-6">
                    © <?= htmlspecialchars((string) date('Y')) ?> PulseCONNECT
                </div>
            </div>
        </div>
    </div>
    <script>
        (function () {
            var root = document.querySelector('[data-otp-root]');
            var hidden = document.getElementById('verification_code');
            var form = root ? root.closest('form') : null;
            if (!root || !hidden || !form) return;

            var boxes = Array.prototype.slice.call(root.querySelectorAll('.otp-box'));
            if (boxes.length !== 6) return;

            function digitsOnly(value) {
                return String(value || '').replace(/\D/g, '');
            }

            function syncHidden() {
                hidden.value = boxes.map(function (box) {
                    return digitsOnly(box.value).slice(0, 1);
                }).join('');
                boxes.forEach(function (box) {
                    box.classList.toggle('is-filled', digitsOnly(box.value).length > 0);
                });
            }

            function focusBox(index) {
                var target = boxes[Math.max(0, Math.min(boxes.length - 1, index))];
                if (!target) return;
                target.focus();
                try { target.select(); } catch (_) {}
            }

            function applyCode(text, startIndex) {
                var digits = digitsOnly(text);
                if (!digits) return false;

                // Full / multi-digit paste always fills from the first box.
                var from = digits.length > 1 ? 0 : Math.max(0, startIndex | 0);
                if (digits.length > 1) {
                    boxes.forEach(function (box) { box.value = ''; });
                }

                var slice = digits.slice(0, boxes.length - from);
                for (var i = 0; i < slice.length; i++) {
                    boxes[from + i].value = slice.charAt(i);
                }
                syncHidden();
                var next = from + slice.length;
                focusBox(next >= boxes.length ? boxes.length - 1 : next);
                return true;
            }

            function readClipboard(event) {
                try {
                    if (event && event.clipboardData) {
                        return event.clipboardData.getData('text')
                            || event.clipboardData.getData('text/plain')
                            || '';
                    }
                } catch (_) {}
                try {
                    if (window.clipboardData) {
                        return window.clipboardData.getData('Text') || '';
                    }
                } catch (_) {}
                return '';
            }

            function onPaste(event, startIndex) {
                var pasted = readClipboard(event);
                if (!digitsOnly(pasted)) return;
                event.preventDefault();
                applyCode(pasted, startIndex);
            }

            boxes.forEach(function (box, index) {
                box.addEventListener('input', function () {
                    var cleaned = digitsOnly(box.value);
                    if (cleaned.length > 1) {
                        applyCode(cleaned, 0);
                        return;
                    }
                    box.value = cleaned.slice(0, 1);
                    syncHidden();
                    if (box.value && index < boxes.length - 1) {
                        focusBox(index + 1);
                    }
                });

                box.addEventListener('beforeinput', function (event) {
                    if (!event || event.inputType !== 'insertFromPaste') return;
                    var data = event.data || '';
                    if (digitsOnly(data).length > 1) {
                        event.preventDefault();
                        applyCode(data, 0);
                    }
                });

                box.addEventListener('keydown', function (event) {
                    if ((event.ctrlKey || event.metaKey) && String(event.key || '').toLowerCase() === 'v') {
                        // Let the native paste event run (handler below fills all boxes).
                        return;
                    }
                    if (event.key === 'Backspace') {
                        if (box.value === '' && index > 0) {
                            event.preventDefault();
                            boxes[index - 1].value = '';
                            syncHidden();
                            focusBox(index - 1);
                        } else {
                            window.setTimeout(syncHidden, 0);
                        }
                        return;
                    }
                    if (event.key === 'ArrowLeft' && index > 0) {
                        event.preventDefault();
                        focusBox(index - 1);
                        return;
                    }
                    if (event.key === 'ArrowRight' && index < boxes.length - 1) {
                        event.preventDefault();
                        focusBox(index + 1);
                        return;
                    }
                });

                box.addEventListener('paste', function (event) {
                    onPaste(event, index);
                });
            });

            // Paste anywhere on the OTP row (even between boxes).
            root.addEventListener('paste', function (event) {
                onPaste(event, 0);
            });

            form.addEventListener('submit', function (event) {
                var submitter = event.submitter;
                if (submitter && submitter.getAttribute('name') === 'auth_step'
                    && submitter.getAttribute('value') === 'resend_code') {
                    return;
                }
                syncHidden();
                if (!/^\d{6}$/.test(hidden.value)) {
                    event.preventDefault();
                    focusBox(hidden.value.length);
                    return;
                }
                // Prevent double-submit (double click / Enter spam) which can
                // delete the OTP on the first request and fail the second.
                if (form.getAttribute('data-otp-submitting') === '1') {
                    event.preventDefault();
                    return;
                }
                form.setAttribute('data-otp-submitting', '1');
                var verifyBtn = document.getElementById('btnVerifyCode');
                if (verifyBtn) {
                    verifyBtn.disabled = true;
                    verifyBtn.textContent = 'Verifying…';
                }
            });

            syncHidden();
        })();

        (function () {
            var btn = document.getElementById('btnResendCode');
            var hint = document.getElementById('resendCooldownHint');
            if (!btn) return;
            var left = parseInt(btn.getAttribute('data-cooldown') || '0', 10);
            if (!Number.isFinite(left) || left <= 0) return;

            function tick() {
                if (left <= 0) {
                    btn.disabled = false;
                    btn.textContent = 'Resend Code';
                    if (hint) hint.classList.add('hidden');
                    return;
                }
                btn.disabled = true;
                btn.textContent = 'Resend in ' + left + 's';
                if (hint) hint.classList.remove('hidden');
                left -= 1;
                window.setTimeout(tick, 1000);
            }
            tick();
        })();

        (function () {
            var wrap = document.querySelector('[data-login-retry]');
            var el = document.getElementById('loginRetryCountdown');
            if (!wrap || !el) return;
            var left = parseInt(wrap.getAttribute('data-login-retry') || '0', 10);
            if (!Number.isFinite(left) || left <= 0) return;

            function fmt(sec) {
                sec = Math.max(0, sec | 0);
                var m = Math.floor(sec / 60);
                var s = sec % 60;
                if (m <= 0) return s + ' second' + (s === 1 ? '' : 's');
                if (s <= 0) return m + ' minute' + (m === 1 ? '' : 's');
                return m + ' minute' + (m === 1 ? '' : 's') + ' ' + s + ' second' + (s === 1 ? '' : 's');
            }

            function tick() {
                if (left <= 0) {
                    el.textContent = 'now — refresh to try again';
                    return;
                }
                el.textContent = fmt(left);
                left -= 1;
                window.setTimeout(tick, 1000);
            }
            tick();
        })();

        // Typewriter effect for the login description (PC/desktop).
        (function () {
            var el = document.getElementById('loginDescTyped');
            var caret = document.getElementById('loginDescCaret');
            if (!el || !caret) return;

            var full = el.getAttribute('data-full-text') || '';
            var i = 0;
            var pauseAfterCompleteMs = 3000; // restart 3 seconds after typing finishes
            var caretOn = true;

            function render() {
                caret.textContent = caretOn ? '|' : '';
                el.textContent = full.slice(0, i);
            }

            // Typing speed per character (ms).
            // Keeps the typing readable; the 3s restart is handled after completion.
            var typingSpeedMs = 22;
            var typingTimer = null;
            var restartTimeout = null;

            function start() {
                // Reset and start typing again.
                i = 0;
                el.textContent = '';
                caret.textContent = caretOn ? '|' : '';

                if (typingTimer) window.clearInterval(typingTimer);
                if (restartTimeout) window.clearTimeout(restartTimeout);
                typingTimer = window.setInterval(function () {
                    i++;
                    render();
                    if (i >= full.length) {
                        window.clearInterval(typingTimer);
                        typingTimer = null;
                        // Wait 3 seconds after completion before restarting.
                        restartTimeout = window.setTimeout(function () {
                            start();
                        }, pauseAfterCompleteMs);
                    }
                }, typingSpeedMs);
            }

            // Blinking caret (independent of typing).
            window.setInterval(function () {
                caretOn = !caretOn;
                render();
            }, 520);

            start();
        })();

        (function () {
            var input = document.getElementById('password');
            var btn = document.getElementById('togglePassword');
            if (!input || !btn) return;
            var showIcon = btn.querySelector('.auth-eye-show');
            var hideIcon = btn.querySelector('.auth-eye-hide');
            btn.addEventListener('click', function () {
                var showing = input.type === 'text';
                input.type = showing ? 'password' : 'text';
                var nowShowing = !showing;
                btn.setAttribute('aria-pressed', nowShowing ? 'true' : 'false');
                btn.setAttribute('aria-label', nowShowing ? 'Hide password' : 'Show password');
                btn.setAttribute('title', nowShowing ? 'Hide password' : 'Show password');
                if (showIcon) showIcon.classList.toggle('hidden', nowShowing);
                if (hideIcon) hideIcon.classList.toggle('hidden', !nowShowing);
            });
        })();

        let mouseTimeout;
        let targetX = typeof window !== 'undefined' ? window.innerWidth / 2 : 0;
        let targetY = typeof window !== 'undefined' ? window.innerHeight / 2 : 0;
        let currentX = targetX, currentY = targetY;

        document.addEventListener('mousemove', function (e) {
            targetX = e.clientX;
            targetY = e.clientY;

            const bg = document.querySelector('.interactive-bg');
            if (bg) {
                bg.style.opacity = '0.85'; // Show on move

                clearTimeout(mouseTimeout);
                mouseTimeout = setTimeout(() => {
                    bg.style.opacity = '0'; // Hide after stop
                }, 500);
            }
        });

        document.addEventListener('mouseleave', function () {
            const bg = document.querySelector('.interactive-bg');
            if (bg) bg.style.opacity = '0';
        });

        function animateBg() {
            // Linear Interpolation for smooth trailing delay
            currentX += (targetX - currentX) * 0.06;
            currentY += (targetY - currentY) * 0.06;

            const bg = document.querySelector('.interactive-bg');
            if (bg) {
                bg.style.setProperty('--mouse-x', currentX + 'px');
                bg.style.setProperty('--mouse-y', currentY + 'px');
            }

            requestAnimationFrame(animateBg);
        }
        animateBg();
    </script>
</body>

</html>
