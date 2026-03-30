<?php
declare(strict_types=1);

session_start();

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/supabase.php';
require_once __DIR__ . '/../includes/json.php';
require_once __DIR__ . '/../includes/csrf.php';

$user = require_role(['admin']);
$data = require_post_json();
require_csrf_from_json($data);

$userId = isset($data['user_id']) ? (string) $data['user_id'] : '';
$role = isset($data['role']) ? (string) $data['role'] : '';

if ($userId === '') {
    json_response(['ok' => false, 'error' => 'user_id required'], 400);
}
if (!in_array($role, ['admin', 'teacher', 'student'], true)) {
    json_response(['ok' => false, 'error' => 'Invalid role'], 400);
}

$payload = [
    'role' => $role,
    'updated_at' => gmdate('c'),
];

$url = rtrim(SUPABASE_URL, '/') . '/rest/v1/' . SUPABASE_TABLE_USERS
    . '?id=eq.' . rawurlencode($userId) . '&select=id,email,role';

$headers = [
    'Content-Type: application/json',
    'Accept: application/json',
    'apikey: ' . SUPABASE_KEY,
    'Authorization: Bearer ' . SUPABASE_KEY,
    'Prefer: return=representation',
];

$res = supabase_request('PATCH', $url, $headers, json_encode($payload, JSON_UNESCAPED_SLASHES));
if (!$res['ok']) {
    json_response(['ok' => false, 'error' => build_error($res['body'] ?? null, (int) ($res['status'] ?? 0), $res['error'] ?? null, 'Update role failed')], 500);
}

$rows = json_decode((string) $res['body'], true);
$updated = is_array($rows) && isset($rows[0]) ? $rows[0] : null;
json_response(['ok' => true, 'user' => $updated], 200);

