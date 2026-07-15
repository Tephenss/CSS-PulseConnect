<?php
declare(strict_types=1);

/**
 * Cron/manual drain for queued scan writes.
 * Auth: shared API key, or logged-in admin/teacher session.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/json.php';
require_once __DIR__ . '/../includes/scan_write_queue.php';

$providedKey = trim((string) ($_GET['key'] ?? $_SERVER['HTTP_X_SCAN_QUEUE_KEY'] ?? ''));
$expectedKey = '';
if (defined('MOBILE_API_KEY')) {
    $expectedKey = trim((string) MOBILE_API_KEY);
} elseif (defined('MOBILE_PUSH_API_KEY')) {
    $expectedKey = trim((string) MOBILE_PUSH_API_KEY);
}

$authorized = false;
if ($expectedKey !== '' && $providedKey !== '' && hash_equals($expectedKey, $providedKey)) {
    $authorized = true;
}

if (!$authorized) {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    require_once __DIR__ . '/../includes/auth.php';
    $user = current_user();
    $role = strtolower(trim((string) ($user['role'] ?? '')));
    if (in_array($role, ['admin', 'teacher'], true)) {
        $authorized = true;
    }
}

if (!$authorized) {
    json_response(['ok' => false, 'error' => 'Unauthorized'], 401);
}

$limit = isset($_GET['limit']) ? max(1, min(50, (int) $_GET['limit'])) : 15;
$result = scan_write_queue_drain($limit);
json_response(['ok' => true] + $result, 200);
