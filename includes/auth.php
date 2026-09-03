<?php
declare(strict_types=1);

function current_user(): ?array
{
    return (isset($_SESSION['user']) && is_array($_SESSION['user'])) ? $_SESSION['user'] : null;
}

function auth_ph_timezone(): DateTimeZone
{
    return new DateTimeZone('Asia/Manila');
}

function auth_verified_on_current_ph_day(?string $verifiedAtIso): bool
{
    if (!is_string($verifiedAtIso) || trim($verifiedAtIso) === '') {
        return false;
    }

    try {
        $verified = (new DateTimeImmutable($verifiedAtIso))->setTimezone(auth_ph_timezone());
        $today = new DateTimeImmutable('now', auth_ph_timezone());
        return $verified->format('Y-m-d') === $today->format('Y-m-d');
    } catch (Throwable $e) {
        return false;
    }
}

function auth_session_trust_keys_match(string $trustKey): bool
{
    if ($trustKey !== '' && (string) ($_SESSION['web_daily_ok_trust'] ?? '') === $trustKey) {
        return true;
    }
    $cachedKeys = $_SESSION['web_daily_ok_trust_keys'] ?? [];
    if (!is_array($cachedKeys)) {
        return false;
    }
    $requestKeys = function_exists('device_trust_request_keys') ? device_trust_request_keys() : [];
    if ($trustKey !== '') {
        $requestKeys[] = $trustKey;
    }
    foreach ($requestKeys as $key) {
        $key = strtolower(trim((string) $key));
        if ($key === '') {
            continue;
        }
        foreach ($cachedKeys as $cached) {
            if ($key === strtolower(trim((string) $cached))) {
                return true;
            }
        }
    }

    return false;
}

/**
 * After 12:00 AM Manila, or on a new network, ask admin/teacher for OTP once.
 * Do not destroy the login session — that looked like auto-logout after OTP.
 */
function auth_enforce_daily_web_verification(array $user): void
{
    $role = strtolower(trim((string) ($user['role'] ?? '')));
    if ($role !== 'admin' && $role !== 'teacher') {
        return;
    }

    $userId = trim((string) ($user['id'] ?? ''));
    if ($userId === '') {
        return;
    }

    // Lazy-load helpers only when needed (logged-in pages).
    if (!function_exists('supabase_request')) {
        require_once __DIR__ . '/../config.php';
        require_once __DIR__ . '/supabase.php';
    }
    if (!function_exists('device_trust_ip_key')) {
        require_once __DIR__ . '/device_trust.php';
    }

    $trustKey = device_trust_ip_key();
    $phDay = (new DateTimeImmutable('now', auth_ph_timezone()))->format('Y-m-d');
    $cacheTtlSeconds = 3600;
    $cachedUser = (string) ($_SESSION['web_daily_ok_user'] ?? '');
    $cachedDay = (string) ($_SESSION['web_daily_ok_ph_day'] ?? '');
    $cachedUntil = (int) ($_SESSION['web_daily_ok_until'] ?? 0);
    $graceUntil = (int) ($_SESSION['web_otp_grace_until'] ?? 0);

    $sameUserDay = $cachedUser === $userId && $cachedDay === $phDay;

    // Right after OTP, Hostinger may present a slightly different hop than the POST.
    // Keep the session and persist whatever keys this GET shows.
    if ($sameUserDay && $graceUntil > time()) {
        if (function_exists('device_trust_upsert_request')) {
            device_trust_upsert_request($userId, 'web');
        }
        return;
    }

    if ($sameUserDay && $cachedUntil > time() && auth_session_trust_keys_match($trustKey)) {
        return;
    }

    $headers = [
        'Accept: application/json',
        'apikey: ' . SUPABASE_KEY,
        'Authorization: Bearer ' . SUPABASE_KEY,
    ];

    $dailyRes = supabase_request(
        'GET',
        rtrim(SUPABASE_URL, '/') . '/rest/v1/admin_login_daily_verifications'
            . '?select=verified_at'
            . '&user_id=eq.' . rawurlencode($userId)
            . '&limit=1',
        $headers
    );

    // Transient Supabase/network failures must NOT wipe the session mid-navigation
    // (tab clicks / browser Back were force-logging users out with redirect storms).
    if (!($dailyRes['ok'] ?? false)) {
        error_log('auth_enforce_daily_web_verification: daily verification lookup failed; keeping session');
        return;
    }

    $verifiedAt = null;
    $rows = json_decode((string) ($dailyRes['body'] ?? ''), true);
    if (is_array($rows) && isset($rows[0]['verified_at'])) {
        $verifiedAt = (string) $rows[0]['verified_at'];
    }

    $verifiedToday = auth_verified_on_current_ph_day($verifiedAt);

    // Cannot resolve client IP (proxy glitch) — do not treat as "new network" if
    // the Manila daily OTP is still valid for today.
    if ($trustKey === '') {
        if ($verifiedToday) {
            $_SESSION['web_daily_ok_user'] = $userId;
            $_SESSION['web_daily_ok_trust'] = '';
            $_SESSION['web_daily_ok_trust_keys'] = [];
            $_SESSION['web_daily_ok_ph_day'] = $phDay;
            $_SESSION['web_daily_ok_until'] = time() + $cacheTtlSeconds;
            unset($_SESSION['web_needs_otp']);
            return;
        }
        $why = 'day';
    } else {
        if (function_exists('device_trust_status')) {
            $trustStatus = device_trust_status($userId, $trustKey);
            if ($trustStatus === null) {
                error_log('auth_enforce_daily_web_verification: trusted_devices lookup failed; keeping session');
                return;
            }
        } else {
            $trustStatus = device_trust_is_trusted($userId, $trustKey);
        }

        if ($verifiedToday && $trustStatus === true) {
            $_SESSION['web_daily_ok_user'] = $userId;
            $_SESSION['web_daily_ok_trust'] = $trustKey;
            $_SESSION['web_daily_ok_trust_keys'] = function_exists('device_trust_request_keys')
                ? device_trust_request_keys()
                : [$trustKey];
            $_SESSION['web_daily_ok_ph_day'] = $phDay;
            $_SESSION['web_daily_ok_until'] = time() + $cacheTtlSeconds;
            unset($_SESSION['web_needs_otp']);
            device_trust_touch($userId, $trustKey);
            return;
        }

        $why = !$verifiedToday ? 'day' : 'ip';
    }

    unset(
        $_SESSION['web_daily_ok_user'],
        $_SESSION['web_daily_ok_trust'],
        $_SESSION['web_daily_ok_trust_keys'],
        $_SESSION['web_daily_ok_ph_day'],
        $_SESSION['web_daily_ok_until'],
        $_SESSION['web_otp_grace_until']
    );

    $why = in_array($why, ['day', 'ip'], true) ? $why : 'unknown';
    $_SESSION['web_needs_otp'] = $why;
    error_log('auth_enforce_daily_web_verification: OTP required user=' . $userId . ' why=' . $why . ' trust=' . $trustKey);
    header('Location: /login?step=verify&reverify=1&why=' . rawurlencode($why));
    exit;
}

function require_login(bool $enforceDailyVerification = true): array
{
    $user = current_user();
    if ($user === null) {
        header('Location: /login?unauth=1');
        exit;
    }
    if ($enforceDailyVerification) {
        auth_enforce_daily_web_verification($user);
    }
    return $user;
}

function require_role(array $allowedRoles, bool $enforceDailyVerification = true): array
{
    $user = require_login($enforceDailyVerification);
    $role = isset($user['role']) ? (string) $user['role'] : 'student';
    if (!in_array($role, $allowedRoles, true)) {
        http_response_code(403);
        echo 'Forbidden';
        exit;
    }
    return $user;
}

