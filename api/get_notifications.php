<?php
declare(strict_types=1);

session_start();

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/json.php';
require_once __DIR__ . '/../includes/web_notifications.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_response(['ok' => false, 'error' => 'Method not allowed'], 405);
}

$user = require_role(['admin', 'teacher']);
$limit = isset($_GET['limit']) ? max(1, min(100, (int) $_GET['limit'])) : 10;

$notifications = web_fetch_notifications_for_user($user, $limit);

json_response([
    'ok' => true,
    'notifications' => $notifications,
    'count' => count($notifications),
], 200);
