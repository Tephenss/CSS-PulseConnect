<?php
declare(strict_types=1);

session_start();

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/supabase.php';
require_once __DIR__ . '/../includes/json.php';
require_once __DIR__ . '/../includes/csrf.php';

$data = require_post_json();
require_csrf_from_json($data);

$email = strtolower(trim((string) ($data['email'] ?? '')));
$resetToken = trim((string) ($data['reset_token'] ?? ''));
$newPassword = (string) ($data['new_password'] ?? '');

if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    json_response(['ok' => false, 'error' => 'Please enter a valid email address.'], 400);
}
if ($resetToken === '') {
    json_response(['ok' => false, 'error' => 'Missing reset token.'], 400);
}
if (mb_strlen($newPassword) < 8) {
    json_response(['ok' => false, 'error' => 'Password must be at least 8 characters.'], 400);
}

$headers = [
    'Accept: application/json',
    'apikey: ' . SUPABASE_KEY,
    'Authorization: Bearer ' . SUPABASE_KEY,
];

$lookupUserUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/' . SUPABASE_TABLE_USERS
    . '?select=id'
    . '&email=eq.' . rawurlencode($email)
    . '&limit=1';
$lookupUserRes = supabase_request('GET', $lookupUserUrl, $headers);
if (!$lookupUserRes['ok']) {
    json_response(['ok' => false, 'error' => 'Failed to reset password.'], 500);
}
$userRows = json_decode((string) $lookupUserRes['body'], true);
$user = is_array($userRows) && isset($userRows[0]) && is_array($userRows[0]) ? $userRows[0] : null;
if (!$user || empty($user['id'])) {
    json_response(['ok' => false, 'error' => 'No account found with that email address.'], 404);
}
$userId = (string) $user['id'];

$codeUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/password_reset_codes'
    . '?select=user_id,reset_token,token_expires_at'
    . '&user_id=eq.' . rawurlencode($userId)
    . '&limit=1';
$codeRes = supabase_request('GET', $codeUrl, $headers);
if (!$codeRes['ok']) {
    json_response(['ok' => false, 'error' => 'Failed to validate reset token.'], 500);
}
$rows = json_decode((string) $codeRes['body'], true);
$row = is_array($rows) && isset($rows[0]) && is_array($rows[0]) ? $rows[0] : null;
if (!$row) {
    json_response(['ok' => false, 'error' => 'Reset session not found. Please request a new code.'], 400);
}

$storedToken = trim((string) ($row['reset_token'] ?? ''));
$tokenExpiresRaw = (string) ($row['token_expires_at'] ?? '');
$tokenExpires = DateTimeImmutable::createFromFormat(DateTimeInterface::ATOM, $tokenExpiresRaw)
    ?: new DateTimeImmutable($tokenExpiresRaw ?: '1970-01-01T00:00:00Z');
$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

if ($storedToken === '' || !hash_equals($storedToken, $resetToken)) {
    json_response(['ok' => false, 'error' => 'Invalid reset session. Please verify code again.'], 400);
}
if ($now > $tokenExpires) {
    json_response(['ok' => false, 'error' => 'Reset session expired. Please verify code again.'], 400);
}

$newHash = password_hash($newPassword, PASSWORD_DEFAULT);
$updateHeaders = [
    'Content-Type: application/json',
    'Accept: application/json',
    'apikey: ' . SUPABASE_KEY,
    'Authorization: Bearer ' . SUPABASE_KEY,
    'Prefer: return=minimal',
];

$updateUserPayload = [
    'password' => $newHash,
    'updated_at' => $now->format(DATE_ATOM),
];
$updateUserUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/' . SUPABASE_TABLE_USERS
    . '?id=eq.' . rawurlencode($userId);
$updateUserRes = supabase_request('PATCH', $updateUserUrl, $updateHeaders, json_encode($updateUserPayload, JSON_UNESCAPED_SLASHES));
if (!$updateUserRes['ok']) {
    json_response(['ok' => false, 'error' => 'Failed to update password.'], 500);
}

$deleteUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/password_reset_codes'
    . '?user_id=eq.' . rawurlencode($userId);
supabase_request('DELETE', $deleteUrl, $headers);

json_response(['ok' => true, 'message' => 'Password updated successfully.'], 200);

