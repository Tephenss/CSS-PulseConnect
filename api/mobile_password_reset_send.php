<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/supabase.php';
require_once __DIR__ . '/../includes/json.php';
require_once __DIR__ . '/../includes/email_notifications.php';
require_once __DIR__ . '/../includes/mobile_api.php';
require_once __DIR__ . '/../includes/api_rate_limit.php';

$data = mobile_api_require_post_json();
mobile_api_validate_key($data);

$email = strtolower(trim((string) ($data['email'] ?? '')));
$clientIp = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
if (!api_rate_limit_allow('mobile_pw_reset_send:' . $clientIp, 8, 300)) {
    json_response(['ok' => false, 'error' => 'Too many reset requests. Please wait.'], 429);
}
if ($email !== '' && !api_rate_limit_allow('mobile_pw_reset_send_email:' . $email, 5, 600)) {
    json_response(['ok' => false, 'error' => 'Too many reset requests for this email. Please wait.'], 429);
}

if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    json_response(['ok' => false, 'error' => 'Please enter a valid email address.'], 400);
}

$lookupUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/' . SUPABASE_TABLE_USERS
    . '?select=id,first_name,last_name,email'
    . '&email=eq.' . rawurlencode($email)
    . '&limit=1';

$lookupRes = supabase_request('GET', $lookupUrl, mobile_api_supabase_headers());
if (!$lookupRes['ok']) {
    json_response(['ok' => false, 'error' => 'Failed to look up account.'], 500);
}

$rows = json_decode((string) $lookupRes['body'], true);
$user = is_array($rows) && isset($rows[0]) && is_array($rows[0]) ? $rows[0] : null;
if ($user === null) {
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

$saveUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/password_reset_codes';
$saveRes = supabase_request(
    'POST',
    $saveUrl,
    mobile_api_supabase_write_headers(),
    json_encode([$payload], JSON_UNESCAPED_SLASHES)
);
if (!$saveRes['ok']) {
    json_response(['ok' => false, 'error' => 'Failed to generate reset code.'], 500);
}

$fullName = trim(((string) ($user['first_name'] ?? '')) . ' ' . ((string) ($user['last_name'] ?? '')));
$sent = send_password_reset_code_email($email, $fullName, $code);
if (!$sent) {
    $smtpDebug = smtp_get_last_error();
    json_response([
        'ok' => false,
        'error' => $smtpDebug !== ''
            ? 'Unable to send reset code email: ' . $smtpDebug
            : 'Unable to send reset code email. Please try again.',
    ], 500);
}

json_response([
    'ok' => true,
    'message' => 'Reset code sent to your email.',
    'expires_at' => $expiresAt->format(DATE_ATOM),
], 200);
