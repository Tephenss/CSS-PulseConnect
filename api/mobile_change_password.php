<?php
declare(strict_types=1);

/**
 * Change password for logged-in mobile users (service role). Never allow anon users.update.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/supabase.php';
require_once __DIR__ . '/../includes/json.php';
require_once __DIR__ . '/../includes/mobile_api.php';
require_once __DIR__ . '/../includes/mobile_session.php';
require_once __DIR__ . '/../includes/api_rate_limit.php';

$data = mobile_api_require_post_json();
mobile_api_validate_key($data);
$sessionUser = mobile_api_require_user($data);
$userId = (string) ($sessionUser['id'] ?? '');

$clientIp = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
if (!api_rate_limit_allow('mobile_change_password:' . $userId . ':' . $clientIp, 8, 300)) {
    json_response(['ok' => false, 'error' => 'Too many password change attempts. Please wait.'], 429);
}

$currentPassword = (string) ($data['current_password'] ?? $data['old_password'] ?? '');
$newPassword = (string) ($data['new_password'] ?? '');

if ($currentPassword === '' || $newPassword === '') {
    json_response(['ok' => false, 'error' => 'Missing required password fields.'], 400);
}
if (mb_strlen($newPassword) < 8) {
    json_response(['ok' => false, 'error' => 'Password must be at least 8 characters.'], 400);
}

$headers = mobile_api_supabase_headers();
$url = rtrim(SUPABASE_URL, '/') . '/rest/v1/users?id=eq.' . rawurlencode($userId) . '&select=id,password';
$userQuery = supabase_request('GET', $url, $headers);
if (!($userQuery['ok'] ?? false)) {
    json_response(['ok' => false, 'error' => 'User lookup failed.'], 500);
}

$usersData = json_decode((string) ($userQuery['body'] ?? ''), true);
$user = (is_array($usersData) && isset($usersData[0]) && is_array($usersData[0])) ? $usersData[0] : null;
if (!is_array($user)) {
    json_response(['ok' => false, 'error' => 'User not found.'], 404);
}

$storedHash = (string) ($user['password'] ?? '');
if ($storedHash === '' || !mobile_password_verify($currentPassword, $storedHash)) {
    json_response(['ok' => false, 'error' => 'Incorrect current password.'], 400);
}

$newHash = password_hash($newPassword, PASSWORD_DEFAULT);
$updateHeaders = mobile_api_supabase_write_headers();
$updateUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/users?id=eq.' . rawurlencode($userId);
$updateResult = supabase_request(
    'PATCH',
    $updateUrl,
    $updateHeaders,
    json_encode(['password' => $newHash], JSON_UNESCAPED_SLASHES)
);
if (!($updateResult['ok'] ?? false)) {
    json_response(['ok' => false, 'error' => 'Failed to update password.'], 500);
}

json_response(['ok' => true, 'message' => 'Password changed successfully.'], 200);
