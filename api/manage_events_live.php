<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/session.php';
session_bootstrap();
set_time_limit(25);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/json.php';
require_once __DIR__ . '/../includes/manage_events_live.php';
require_once __DIR__ . '/../includes/api_cache.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_response(['ok' => false, 'error' => 'Method not allowed'], 405);
}

$user = require_role(['admin', 'teacher']);
$lite = isset($_GET['lite']) && (string) $_GET['lite'] === '1';
$skipCache = isset($_GET['fresh']) && (string) $_GET['fresh'] === '1';
$userId = trim((string) ($user['id'] ?? ''));
$role = trim((string) ($user['role'] ?? ''));
$cacheGen = api_cache_generation('manage_events');
$cacheKey = 'manage_events_live:g' . $cacheGen . ':' . $role . ':' . $userId . ':' . ($lite ? 'lite' : 'full');
$ttl = $lite ? 30 : 25;

try {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    if ($skipCache) {
        $payload = manage_events_live_payload($user, $lite);
        api_cache_write($cacheKey, $payload);
        json_response($payload, 200);
    }

    $payload = api_cache_remember(
        $cacheKey,
        $ttl,
        static function () use ($user, $lite): array {
            return manage_events_live_payload($user, $lite);
        },
        40
    );
    json_response($payload, 200);
} catch (Throwable $e) {
    json_response(['ok' => false, 'error' => 'Failed to load live event updates'], 500);
}
