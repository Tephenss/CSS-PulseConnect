<?php
declare(strict_types=1);

session_start();
set_time_limit(25);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/supabase.php';
require_once __DIR__ . '/../includes/json.php';
require_once __DIR__ . '/../includes/api_cache.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_response(['ok' => false, 'error' => 'Method not allowed'], 405);
}

$user = current_user();
if ($user === null) {
    json_response(['ok' => false, 'error' => 'Unauthorized'], 401);
}
if (($user['role'] ?? '') !== 'admin') {
    json_response(['ok' => false, 'error' => 'Forbidden'], 403);
}

$cached = api_cache_read('manage_applications_pending_count', 60);
if (is_array($cached)) {
    json_response($cached, 200);
}

$url = rtrim((string) SUPABASE_URL, '/') . '/rest/v1/' . SUPABASE_TABLE_USERS
    . '?select=id'
    . '&role=eq.student'
    . '&registration_source=eq.app'
    . '&account_status=eq.pending'
    . '&email_verified=eq.true';

$headers = [
    'Accept: application/json',
    'apikey: ' . SUPABASE_KEY,
    'Authorization: Bearer ' . SUPABASE_KEY,
];

$res = supabase_request('GET', $url, $headers);
if (!is_array($res) || empty($res['ok'])) {
    json_response(['ok' => false, 'error' => 'Failed to fetch pending applications'], 500);
}

$rows = json_decode((string) ($res['body'] ?? '[]'), true);
$count = is_array($rows) ? count($rows) : 0;

$payload = [
    'ok' => true,
    'count' => $count,
];
api_cache_write('manage_applications_pending_count', $payload);
json_response($payload, 200);
