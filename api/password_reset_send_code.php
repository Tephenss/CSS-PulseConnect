<?php
declare(strict_types=1);

session_start();

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/supabase.php';
require_once __DIR__ . '/../includes/json.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/email_notifications.php';

$data = require_post_json();
require_csrf_from_json($data);

$email = strtolower(trim((string) ($data['email'] ?? '')));
if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    json_response(['ok' => false, 'error' => 'Please enter a valid email address.'], 400);
}

$headers = [
    'Accept: application/json',
    'apikey: ' . SUPABASE_KEY,
    'Authorization: Bearer ' . SUPABASE_KEY,
];

$lookupUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/' . SUPABASE_TABLE_USERS
    . '?select=id,first_name,last_name,email'
    . '&email=eq.' . rawurlencode($email)
    . '&limit=1';

$lookupRes = supabase_request('GET', $lookupUrl, $headers);
if (!$lookupRes['ok']) {
    json_response(['ok' => false, 'error' => 'Failed to look up account.'], 500);
}

$rows = json_decode((string) $lookupRes['body'], true);
$user = is_array($rows) && isset($rows[0]) && is_array($rows[0]) ? $rows[0] : null;
if (!$user) {
    json_response(['ok' => false, 'error' => 'No account found with that email address.'], 404);
}

$userId = (string) ($user['id'] ?? '');
if ($userId === '') {
    json_response(['ok' => false, 'error' => 'Invalid account record.'], 500);
}

$code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
$expiresAt = $now->add(new DateInterval('PT10M'));

$payload = [
    'user_id' => $userId,
    'code' => $code,
    'expires_at' => $expiresAt->format(DATE_ATOM),
    'verified_at' => null,
    'reset_token' => null,
    'token_expires_at' => null,
    'updated_at' => $now->format(DATE_ATOM),
];

$saveHeaders = [
    'Content-Type: application/json',
    'Accept: application/json',
    'apikey: ' . SUPABASE_KEY,
    'Authorization: Bearer ' . SUPABASE_KEY,
    'Prefer: return=minimal',
    'Prefer: resolution=merge-duplicates',
];

$saveUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/password_reset_codes';
$saveRes = supabase_request('POST', $saveUrl, $saveHeaders, json_encode([$payload], JSON_UNESCAPED_SLASHES));
if (!$saveRes['ok']) {
    json_response(['ok' => false, 'error' => 'Failed to generate reset code.'], 500);
}

$fullName = trim(((string) ($user['first_name'] ?? '')) . ' ' . ((string) ($user['last_name'] ?? '')));
$sent = send_password_reset_code_email($email, $fullName, $code);
if (!$sent) {
    json_response(['ok' => false, 'error' => 'Unable to send reset code email. Please try again.'], 500);
}

json_response(['ok' => true, 'message' => 'Reset code sent to your email.'], 200);

