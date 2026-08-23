<?php
declare(strict_types=1);

/**
 * Mobile scan context for teachers + student assistants.
 * Same window rules as web api/scan_context.php / mobile_scan_ticket.php.
 * Used to warm offline scanner snapshots so local open/closed matches the BFF.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/supabase.php';
require_once __DIR__ . '/../includes/json.php';
require_once __DIR__ . '/../includes/mobile_api.php';
require_once __DIR__ . '/../includes/mobile_session.php';
require_once __DIR__ . '/../includes/api_rate_limit.php';
require_once __DIR__ . '/../includes/event_sessions.php';
require_once __DIR__ . '/../includes/scan_context.php';
require_once __DIR__ . '/../includes/api_cache.php';

$attendanceWindowsPath = __DIR__ . '/../includes/event_attendance_windows.php';
if (is_file($attendanceWindowsPath)) {
    require_once $attendanceWindowsPath;
}

$data = mobile_api_require_post_json();
mobile_api_validate_key($data);
$sessionUser = mobile_api_require_user($data);
$userId = trim((string) ($sessionUser['id'] ?? ''));
$role = strtolower(trim((string) ($sessionUser['role'] ?? 'student')));

$clientIp = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
if (!api_rate_limit_allow('mobile_scan_context:' . $userId . ':' . $clientIp, 90, 60)) {
    json_response(['ok' => false, 'error' => 'Too many requests.', 'status' => 'error'], 429);
}

if (!in_array($role, ['teacher', 'admin', 'super_admin', 'student'], true)) {
    json_response([
        'ok' => false,
        'error' => 'Only teachers and assigned student assistants can use the scanner.',
        'status' => 'forbidden',
        'scanner_enabled' => false,
        'context' => null,
        'assignments' => 0,
    ], 403);
}

$fresh = !empty($data['fresh']);
$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
$cacheKey = 'mobile_scan_context:' . $role . ':' . $userId;
$headers = mobile_api_supabase_headers();

$build = static function () use ($sessionUser, $now, $headers): array {
    try {
        $resolved = resolve_user_scan_context($sessionUser, $now, $headers);
    } catch (RuntimeException $e) {
        return [
            'ok' => false,
            'error' => $e->getMessage(),
            'status' => 'error',
            'scanner_enabled' => false,
            'context' => null,
            'assignments' => 0,
            'server_time' => $now->format('c'),
            '_http' => 500,
        ];
    }

    $status = (string) ($resolved['status'] ?? 'closed');
    return [
        'ok' => true,
        'status' => $status,
        'scanner_enabled' => !empty($resolved['scanner_enabled']),
        'message' => (string) ($resolved['message'] ?? ''),
        'context' => is_array($resolved['context'] ?? null) ? $resolved['context'] : null,
        'assignments' => (int) ($resolved['assignments'] ?? 0),
        'server_time' => $now->format('c'),
    ];
};

if ($fresh) {
    $response = $build();
    $http = (int) ($response['_http'] ?? 200);
    unset($response['_http']);
    if ($http === 200 && ($response['ok'] ?? false) === true) {
        api_cache_write($cacheKey, $response);
    }
    json_response($response, $http >= 400 ? $http : 200);
}

$cached = api_cache_read($cacheKey, 15);
if (is_array($cached) && ($cached['ok'] ?? false) === true) {
    json_response($cached, 200);
}

$gotLock = api_cache_try_lock($cacheKey);
if (!$gotLock) {
    $stale = api_cache_read_stale($cacheKey, 15, 20);
    if (is_array($stale) && ($stale['ok'] ?? false) === true) {
        json_response($stale, 200);
    }
}

try {
    $response = $build();
    $http = (int) ($response['_http'] ?? 200);
    unset($response['_http']);
    if ($http === 200 && ($response['ok'] ?? false) === true) {
        api_cache_write($cacheKey, $response);
    }
    json_response($response, $http >= 400 ? $http : 200);
} finally {
    if ($gotLock) {
        api_cache_release_lock($cacheKey);
    }
}
