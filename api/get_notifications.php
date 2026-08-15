<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/session.php';
session_bootstrap();
set_time_limit(25);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/json.php';
require_once __DIR__ . '/../includes/web_notifications.php';
require_once __DIR__ . '/../includes/api_cache.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_response(['ok' => false, 'error' => 'Method not allowed'], 405);
}

$user = require_role(['admin', 'teacher']);
$limit = isset($_GET['limit']) ? max(1, min(100, (int) $_GET['limit'])) : 10;
$userId = trim((string) ($user['id'] ?? ''));
$role = trim((string) ($user['role'] ?? ''));
$cacheKey = 'notifications:' . $role . ':' . $userId . ':' . $limit;
$skipCache = isset($_GET['fresh']) && (string) $_GET['fresh'] === '1';

if (!$skipCache) {
    $payload = api_cache_remember($cacheKey, 70, static function () use ($user, $limit): array {
        $notifications = web_fetch_notifications_for_user($user, $limit);
        return [
            'ok' => true,
            'notifications' => $notifications,
            'count' => count($notifications),
        ];
    }, 45);
    json_response($payload, 200);
}

$notifications = web_fetch_notifications_for_user($user, $limit);
$payload = [
    'ok' => true,
    'notifications' => $notifications,
    'count' => count($notifications),
];
api_cache_write($cacheKey, $payload);
json_response($payload, 200);
