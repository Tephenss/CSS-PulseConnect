<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/supabase.php';
require_once __DIR__ . '/../includes/json.php';
require_once __DIR__ . '/../includes/mobile_api.php';
require_once __DIR__ . '/../includes/api_rate_limit.php';

$data = mobile_api_require_post_json();
mobile_api_validate_key($data);

$email = strtolower(trim((string) ($data['email'] ?? '')));
$code = trim((string) ($data['code'] ?? ''));

$clientIp = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
if (!api_rate_limit_allow('mobile_pw_reset_verify:' . $clientIp, 15, 300)) {
    json_response(['ok' => false, 'error' => 'Too many verification attempts. Please wait.'], 429);
}
if ($email !== '' && !api_rate_limit_allow('mobile_pw_reset_verify_email:' . $email, 10, 300)) {
    json_response(['ok' => false, 'error' => 'Too many verification attempts. Please wait.'], 429);
}

if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    json_response(['ok' => false, 'error' => 'Please enter a valid email address.'], 400);
}
if (!preg_match('/^\d{6}$/', $code)) {
    json_response(['ok' => false, 'error' => 'Verification code must be 6 digits.'], 400);
}

$headers = mobile_api_supabase_headers();
$lookupUserUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/' . SUPABASE_TABLE_USERS
    . '?select=id'
    . '&email=eq.' . rawurlencode($email)
    . '&limit=1';
$lookupUserRes = supabase_request('GET', $lookupUserUrl, $headers);
if (!$lookupUserRes['ok']) {
    json_response(['ok' => false, 'error' => 'Failed to verify account.'], 500);
}
$userRows = json_decode((string) $lookupUserRes['body'], true);
$user = is_array($userRows) && isset($userRows[0]) && is_array($userRows[0]) ? $userRows[0] : null;
if (!$user || empty($user['id'])) {
    // Avoid email enumeration detail in mobile path — same generic message.
    json_response(['ok' => false, 'error' => 'Invalid verification code.'], 400);
}
$userId = (string) $user['id'];

$codeUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/password_reset_codes'
    . '?select=user_id,code,expires_at'
    . '&user_id=eq.' . rawurlencode($userId)
    . '&limit=1';
$codeRes = supabase_request('GET', $codeUrl, $headers);
if (!$codeRes['ok']) {
    json_response(['ok' => false, 'error' => 'Failed to verify code.'], 500);
}
$codeRows = json_decode((string) $codeRes['body'], true);
$row = is_array($codeRows) && isset($codeRows[0]) && is_array($codeRows[0]) ? $codeRows[0] : null;
if (!$row) {
    json_response(['ok' => false, 'error' => 'No reset code found. Please request a new code.'], 400);
}

$storedCode = trim((string) ($row['code'] ?? ''));
$expiresAtRaw = (string) ($row['expires_at'] ?? '');
try {
    $expiresAt = new DateTimeImmutable($expiresAtRaw);
} catch (Throwable $e) {
    $expiresAt = new DateTimeImmutable('1970-01-01T00:00:00Z');
}
$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

if ($storedCode === '' || !hash_equals($storedCode, $code)) {
    json_response(['ok' => false, 'error' => 'Invalid verification code.'], 400);
}
if ($now > $expiresAt) {
    json_response(['ok' => false, 'error' => 'Verification code expired. Please request a new one.'], 400);
}

$resetToken = bin2hex(random_bytes(24));
$tokenExpiry = $now->add(new DateInterval('PT15M'));
$updatePayload = [
    'verified_at' => $now->format(DATE_ATOM),
    'reset_token' => $resetToken,
    'token_expires_at' => $tokenExpiry->format(DATE_ATOM),
    'updated_at' => $now->format(DATE_ATOM),
];
$updateHeaders = [
    'Content-Type: application/json',
    'Accept: application/json',
    'apikey: ' . SUPABASE_KEY,
    'Authorization: Bearer ' . SUPABASE_KEY,
    'Prefer: return=minimal',
];
$updateUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/password_reset_codes'
    . '?user_id=eq.' . rawurlencode($userId);
$updateRes = supabase_request('PATCH', $updateUrl, $updateHeaders, json_encode($updatePayload, JSON_UNESCAPED_SLASHES));
if (!$updateRes['ok']) {
    json_response(['ok' => false, 'error' => 'Failed to verify code.'], 500);
}

json_response([
    'ok' => true,
    'reset_token' => $resetToken,
    'message' => 'Code verified. You can now set a new password.',
], 200);
