<?php
declare(strict_types=1);

session_start();

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/supabase.php';
require_once __DIR__ . '/../includes/json.php';
require_once __DIR__ . '/../includes/event_sessions.php';
require_once __DIR__ . '/../includes/scan_context.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_response(['ok' => false, 'error' => 'Method not allowed'], 405);
}

$user = require_role(['teacher', 'admin']);
$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

$headers = [
    'Accept: application/json',
    'apikey: ' . SUPABASE_KEY,
    'Authorization: Bearer ' . SUPABASE_KEY,
];

try {
    $resolved = resolve_user_scan_context($user, $now, $headers);
} catch (RuntimeException $e) {
    json_response(['ok' => false, 'error' => $e->getMessage()], 500);
}

$status = (string) ($resolved['status'] ?? 'closed');
$response = [
    'ok' => true,
    'status' => $status,
    'scanner_enabled' => !empty($resolved['scanner_enabled']),
    'message' => (string) ($resolved['message'] ?? ''),
    'context' => is_array($resolved['context'] ?? null) ? $resolved['context'] : null,
    'assignments' => (int) ($resolved['assignments'] ?? 0),
    'server_time' => $now->format('c'),
];

json_response($response, 200);

