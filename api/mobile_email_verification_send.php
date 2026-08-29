<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/supabase.php';
require_once __DIR__ . '/../includes/json.php';
require_once __DIR__ . '/../includes/email_notifications.php';
require_once __DIR__ . '/../includes/mobile_api.php';
require_once __DIR__ . '/../includes/api_rate_limit.php';
require_once __DIR__ . '/../includes/mobile_session.php';

/**
 * Normalize OTP purpose. Signup and login must stay separate.
 */
function mobile_email_otp_normalize_purpose(string $raw): string
{
    $p = strtolower(trim($raw));
    if (in_array($p, ['signup', 'register', 'registration', 'create'], true)) {
        return 'signup';
    }
    if (in_array($p, ['web_login', 'admin_login', 'teacher_login'], true)) {
        return 'login';
    }
    return 'login';
}

$data = mobile_api_require_post_json();
mobile_api_validate_key($data);

$sessionToken = mobile_session_extract_token($data);
$userId = '';
$email = strtolower(trim((string) ($data['email'] ?? '')));
$fullName = trim((string) ($data['full_name'] ?? ''));
$purpose = mobile_email_otp_normalize_purpose((string) ($data['purpose'] ?? 'login'));

// Signup OTP must not bind to a leftover login session from another account.
if ($purpose === 'signup') {
    $userId = trim((string) ($data['user_id'] ?? ''));
} elseif ($sessionToken !== '') {
    $sessionUser = mobile_api_require_user($data);
    $userId = (string) ($sessionUser['id'] ?? '');
    $email = strtolower(trim((string) ($sessionUser['email'] ?? $email)));
    if ($fullName === '') {
        $fullName = build_display_name(
            (string) ($sessionUser['first_name'] ?? ''),
            (string) ($sessionUser['middle_name'] ?? ''),
            (string) ($sessionUser['last_name'] ?? ''),
            (string) ($sessionUser['suffix'] ?? '')
        );
    }
} else {
    $userId = trim((string) ($data['user_id'] ?? ''));
}

if ($userId === '' || $email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    json_response(['ok' => false, 'error' => 'Invalid request.'], 400);
}

$clientIp = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
if (!api_rate_limit_allow('mobile_email_verify:' . $purpose . ':' . $userId . ':' . $clientIp, 6, 300)) {
    json_response(['ok' => false, 'error' => 'Too many verification emails. Please wait a few minutes.'], 429);
}

$lookupUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/' . SUPABASE_TABLE_USERS
    . '?select=id,first_name,middle_name,last_name,suffix,email'
    . '&id=eq.' . rawurlencode($userId)
    . '&limit=1';

$lookupRes = supabase_request('GET', $lookupUrl, mobile_api_supabase_headers());
if (!$lookupRes['ok']) {
    json_response(['ok' => false, 'error' => 'Failed to look up account.'], 500);
}

$rows = json_decode((string) $lookupRes['body'], true);
$user = is_array($rows) && isset($rows[0]) && is_array($rows[0]) ? $rows[0] : null;
if ($user === null) {
    json_response(['ok' => false, 'error' => 'Account not found.'], 404);
}

$storedEmail = strtolower(trim((string) ($user['email'] ?? '')));
if ($storedEmail !== $email) {
    json_response(['ok' => false, 'error' => 'Email does not match this account.'], 400);
}

if ($fullName === '') {
    $fullName = build_display_name(
        (string) ($user['first_name'] ?? ''),
        (string) ($user['middle_name'] ?? ''),
        (string) ($user['last_name'] ?? ''),
        (string) ($user['suffix'] ?? '')
    );
}

$resendCooldownSeconds = 60;
$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
$headers = mobile_api_supabase_headers();

$parseExisting = static function (array $existing) use ($now): array {
    $lastSentRaw = trim((string) ($existing['last_sent_at'] ?? ''));
    $expiresAtRaw = trim((string) ($existing['expires_at'] ?? ''));
    $code = trim((string) ($existing['code'] ?? ''));
    $lastSentAt = false;
    if ($lastSentRaw !== '') {
        $lastSentAt = DateTimeImmutable::createFromFormat(DateTimeInterface::ATOM, $lastSentRaw);
        if ($lastSentAt === false) {
            try {
                $lastSentAt = new DateTimeImmutable($lastSentRaw);
            } catch (Throwable $e) {
                $lastSentAt = false;
            }
        }
    }
    $expiresAt = false;
    if ($expiresAtRaw !== '') {
        try {
            $expiresAt = new DateTimeImmutable($expiresAtRaw);
        } catch (Throwable $e) {
            $expiresAt = false;
        }
    }
    $validCode = $code !== ''
        && $expiresAt instanceof DateTimeImmutable
        && $now <= $expiresAt->setTimezone(new DateTimeZone('UTC'));
    $elapsed = null;
    if ($lastSentAt instanceof DateTimeImmutable) {
        $elapsed = $now->getTimestamp() - $lastSentAt->setTimezone(new DateTimeZone('UTC'))->getTimestamp();
    }
    return [
        'valid_code' => $validCode,
        'elapsed' => $elapsed,
        'expires_at' => $expiresAtRaw,
    ];
};

// Prefer purpose-scoped row (signup vs login). Fall back to legacy single-row table.
$existing = null;
$purposeSupported = true;
$existingUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/email_verification_codes'
    . '?select=code,last_sent_at,expires_at,purpose'
    . '&user_id=eq.' . rawurlencode($userId)
    . '&purpose=eq.' . rawurlencode($purpose)
    . '&limit=1';
$existingRes = supabase_request('GET', $existingUrl, $headers);
if (!($existingRes['ok'] ?? false)) {
    $purposeSupported = false;
    $existingUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/email_verification_codes'
        . '?select=code,last_sent_at,expires_at'
        . '&user_id=eq.' . rawurlencode($userId)
        . '&limit=1';
    $existingRes = supabase_request('GET', $existingUrl, $headers);
}
if ($existingRes['ok'] ?? false) {
    $existingRows = json_decode((string) $existingRes['body'], true);
    $existing = is_array($existingRows) && isset($existingRows[0]) && is_array($existingRows[0])
        ? $existingRows[0]
        : null;
}

if (is_array($existing)) {
    $meta = $parseExisting($existing);
    $elapsed = $meta['elapsed'];
    // Only skip when a still-valid code exists for THIS purpose.
    // (Fixes: signup code consumed/deleted, but cooldown still blocks login send.)
    if ($elapsed !== null
        && $elapsed >= 0
        && $elapsed < $resendCooldownSeconds
        && !empty($meta['valid_code'])) {
        $remaining = $resendCooldownSeconds - $elapsed;
        json_response([
            'ok' => true,
            'skipped' => true,
            'purpose' => $purpose,
            'cooldown_seconds' => $remaining,
            'message' => 'Verification code already sent. Please check your email.',
            'expires_at' => ($meta['expires_at'] !== ''
                ? $meta['expires_at']
                : $now->add(new DateInterval('PT5M'))->format(DATE_ATOM)),
        ], 200);
    }
}

$code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
$expiresAt = $now->add(new DateInterval('PT5M'));

$payload = [
    'user_id' => $userId,
    'code' => $code,
    'expires_at' => $expiresAt->format(DATE_ATOM),
    'created_at' => $now->format(DATE_ATOM),
    'last_sent_at' => $now->format(DATE_ATOM),
];
if ($purposeSupported) {
    $payload['purpose'] = $purpose;
}

$saveUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/email_verification_codes'
    . ($purposeSupported ? '?on_conflict=user_id,purpose' : '');
$writeHeaders = mobile_api_supabase_write_headers();
$saveRes = supabase_request(
    'POST',
    $saveUrl,
    $writeHeaders,
    json_encode($payload, JSON_UNESCAPED_SLASHES)
);

// Legacy table without purpose column / old PK.
if (!($saveRes['ok'] ?? false) && $purposeSupported) {
    unset($payload['purpose']);
    $saveRes = supabase_request(
        'POST',
        rtrim(SUPABASE_URL, '/') . '/rest/v1/email_verification_codes',
        $writeHeaders,
        json_encode($payload, JSON_UNESCAPED_SLASHES)
    );
}

if (!($saveRes['ok'] ?? false)) {
    json_response(['ok' => false, 'error' => 'Failed to prepare verification code.'], 500);
}

$sent = send_mobile_email_verification_code_email($email, $fullName, $code);
if (!$sent) {
    $smtpDebug = smtp_get_last_error();
    json_response([
        'ok' => false,
        'error' => $smtpDebug !== ''
            ? 'Unable to send verification email: ' . $smtpDebug
            : 'Unable to send verification email. Please try again.',
    ], 500);
}

json_response([
    'ok' => true,
    'message' => 'Verification code sent to your email.',
    'purpose' => $purpose,
    'expires_at' => $expiresAt->format(DATE_ATOM),
    'cooldown_seconds' => $resendCooldownSeconds,
], 200);
