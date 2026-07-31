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

/**
 * After 12:00 AM Manila, force admin/teacher sessions to re-verify via login OTP.
 * Successful checks are cached in the PHP session (~10 min) so sidebar navigation
 * does not re-hit Supabase on every page load. Cache is IP-bound and day-bound.
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
    $cacheTtlSeconds = 600; // 10 minutes
    $cachedUser = (string) ($_SESSION['web_daily_ok_user'] ?? '');
    $cachedTrust = (string) ($_SESSION['web_daily_ok_trust'] ?? '');
    $cachedDay = (string) ($_SESSION['web_daily_ok_ph_day'] ?? '');
    $cachedUntil = (int) ($_SESSION['web_daily_ok_until'] ?? 0);

    if (
        $cachedUser === $userId
        && $cachedTrust === $trustKey
        && $cachedDay === $phDay
        && $cachedUntil > time()
    ) {
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

    $verifiedAt = null;
    if ($dailyRes['ok']) {
        $rows = json_decode((string) ($dailyRes['body'] ?? ''), true);
        if (is_array($rows) && isset($rows[0]['verified_at'])) {
            $verifiedAt = (string) $rows[0]['verified_at'];
        }
    }

    $trusted = device_trust_is_trusted($userId, $trustKey);
    $verifiedToday = auth_verified_on_current_ph_day($verifiedAt);

    if ($verifiedToday && $trusted) {
        $_SESSION['web_daily_ok_user'] = $userId;
        $_SESSION['web_daily_ok_trust'] = $trustKey;
        $_SESSION['web_daily_ok_ph_day'] = $phDay;
        $_SESSION['web_daily_ok_until'] = time() + $cacheTtlSeconds;
        // Touch once per successful revalidation window (not every sidebar click).
        device_trust_touch($userId, $trustKey);
        return;
    }

    unset(
        $_SESSION['web_daily_ok_user'],
        $_SESSION['web_daily_ok_trust'],
        $_SESSION['web_daily_ok_ph_day'],
        $_SESSION['web_daily_ok_until']
    );

    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires' => time() - 42000,
            'path' => (string) ($params['path'] ?? '/'),
            'domain' => (string) ($params['domain'] ?? ''),
            'secure' => (bool) ($params['secure'] ?? false),
            'httponly' => (bool) ($params['httponly'] ?? true),
            'samesite' => (string) ($params['samesite'] ?? 'Strict'),
        ]);
    }
    session_destroy();

    header('Location: /login?reverify=1');
    exit;
}

function require_login(): array
{
    $user = current_user();
    if ($user === null) {
        header('Location: /login?unauth=1');
        exit;
    }
    auth_enforce_daily_web_verification($user);
    return $user;
}

function require_role(array $allowedRoles): array
{
    $user = require_login();
    $role = isset($user['role']) ? (string) $user['role'] : 'student';
    if (!in_array($role, $allowedRoles, true)) {
        http_response_code(403);
        echo 'Forbidden';
        exit;
    }
    return $user;
}

