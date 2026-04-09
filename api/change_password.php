<?php
declare(strict_types=1);

session_start();

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/supabase.php';
require_once __DIR__ . '/../includes/json.php';
require_once __DIR__ . '/../includes/csrf.php';

$data = require_post_json();
require_csrf_from_json($data);

$userId = isset($_SESSION['user']['id']) ? (string) $_SESSION['user']['id'] : '';
if ($userId === '') {
    json_response(['ok' => false, 'error' => 'Unauthorized. Please login.'], 401);
}

$oldPassword = isset($data['current_password']) ? (string) $data['current_password'] : '';
$newPassword = isset($data['new_password']) ? (string) $data['new_password'] : '';
if ($oldPassword === '' || $newPassword === '') {
    json_response(['ok' => false, 'error' => 'Missing required password fields.'], 400);
}

$url = rtrim(SUPABASE_URL, '/') . '/rest/v1/users?id=eq.' . rawurlencode($userId) . '&select=id,password';
$headers = [
    'Accept: application/json',
    'apikey: ' . SUPABASE_KEY,
    'Authorization: Bearer ' . SUPABASE_KEY,
];

$userQuery = supabase_request('GET', $url, $headers);
if (!$userQuery['ok']) {
    json_response([
        'ok' => false,
        'error' => build_error($userQuery['body'] ?? null, (int) ($userQuery['status'] ?? 0), $userQuery['error'] ?? null, 'User lookup failed'),
    ], 500);
}

$usersData = json_decode((string) $userQuery['body'], true);
$user = (is_array($usersData) && isset($usersData[0]) && is_array($usersData[0])) ? $usersData[0] : null;
if (!is_array($user)) {
    json_response(['ok' => false, 'error' => 'User not found.'], 404);
}

$storedHash = isset($user['password']) ? (string) $user['password'] : '';
if ($storedHash === '' || !password_verify($oldPassword, $storedHash)) {
    json_response(['ok' => false, 'error' => 'Incorrect current password.'], 400);
}

$newHash = password_hash($newPassword, PASSWORD_DEFAULT);
$updateUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/users?id=eq.' . rawurlencode($userId);
$updateHeaders = [
    'Content-Type: application/json',
    'Accept: application/json',
    'apikey: ' . SUPABASE_KEY,
    'Authorization: Bearer ' . SUPABASE_KEY,
    'Prefer: return=minimal',
];
$updatePayload = ['password' => $newHash];

$updateResult = supabase_request('PATCH', $updateUrl, $updateHeaders, json_encode($updatePayload, JSON_UNESCAPED_SLASHES));
if (!$updateResult['ok']) {
    json_response([
        'ok' => false,
        'error' => build_error($updateResult['body'] ?? null, (int) ($updateResult['status'] ?? 0), $updateResult['error'] ?? null, 'Failed to update database.'),
    ], 500);
}

// Keep legacy `success` field for existing frontend checks while standardizing on `ok`.
json_response(['ok' => true, 'success' => true], 200);
