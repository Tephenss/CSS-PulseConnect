<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/session.php';
session_bootstrap();

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/supabase.php';
require_once __DIR__ . '/../includes/json.php';
require_once __DIR__ . '/../includes/event_sessions.php';
require_once __DIR__ . '/../includes/scan_context.php';
require_once __DIR__ . '/../includes/api_cache.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_response(['ok' => false, 'error' => 'Method not allowed'], 405);
}

$user = require_role(['teacher', 'admin']);
$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
$userId = trim((string) ($user['id'] ?? ''));
$role = trim((string) ($user['role'] ?? ''));
$cacheKey = 'scan_context:' . $role . ':' . $userId;
$skipCache = isset($_GET['fresh']) && (string) $_GET['fresh'] === '1';

$headers = [
    'Accept: application/json',
    'apikey: ' . SUPABASE_KEY,
    'Authorization: Bearer ' . SUPABASE_KEY,
];

$build = static function () use ($user, $now, $headers): array {
    try {
        $resolved = resolve_user_scan_context($user, $now, $headers);
    } catch (RuntimeException $e) {
        return ['ok' => false, 'error' => $e->getMessage(), '_status' => 500];
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

if ($skipCache) {
    $response = $build();
    $status = (int) ($response['_status'] ?? 200);
    unset($response['_status']);
    if ($status === 200 && ($response['ok'] ?? false) === true) {
        api_cache_write($cacheKey, $response);
    }
    json_response($response, $status >= 400 ? $status : 200);
}

$fresh = api_cache_read($cacheKey, 15);
if (is_array($fresh) && ($fresh['ok'] ?? false) === true) {
    json_response($fresh, 200);
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
    $status = (int) ($response['_status'] ?? 200);
    unset($response['_status']);
    if ($status === 200 && ($response['ok'] ?? false) === true) {
        api_cache_write($cacheKey, $response);
    }
    json_response($response, $status >= 400 ? $status : 200);
} finally {
    if ($gotLock) {
        api_cache_release_lock($cacheKey);
    }
}

