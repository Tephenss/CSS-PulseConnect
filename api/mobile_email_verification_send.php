<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/supabase.php';
require_once __DIR__ . '/../includes/json.php';
require_once __DIR__ . '/../includes/email_notifications.php';
require_once __DIR__ . '/../includes/mobile_api.php';
require_once __DIR__ . '/../includes/api_rate_limit.php';

$data = mobile_api_require_post_json();
mobile_api_validate_key($data);

$userId = trim((string) ($data['user_id'] ?? ''));
$email = strtolower(trim((string) ($data['email'] ?? '')));
$fullName = trim((string) ($data['full_name'] ?? ''));

if ($userId === '' || $email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    json_response(['ok' => false, 'error' => 'Invalid request.'], 400);
}

$clientIp = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
if (!api_rate_limit_allow('mobile_email_verify:' . $userId . ':' . $clientIp, 6, 300)) {
    json_response(['ok' => false, 'error' => 'Too many verification emails. Please wait a few minutes.'], 429);
}

$lookupUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/' . SUPABASE_TABLE_USERS
    . '?select=id,first_name,middle_name,last_name,suffix,email'
    . '&id=eq.' . rawurlencode($userId)
    . '&limit=1';

$lookupRes = supabase_request('GET', $lookupUrl, mobile_api_supabase_headers());
if (!$lookupRes['ok']) {
    json_response(['ok' => false, 'error' => 'Failed to look up account.'], 500);
}

$rows = json_decode((string) $lookupRes['body'], true);
$user = is_array($rows) && isset($rows[0]) && is_array($rows[0]) ? $rows[0] : null;
if ($user === null) {
    json_response(['ok' => false, 'error' => 'Account not found.'], 404);
}

$storedEmail = strtolower(trim((string) ($user['email'] ?? '')));
if ($storedEmail !== $email) {
    json_response(['ok' => false, 'error' => 'Email does not match this account.'], 400);
}

if ($fullName === '') {
    $fullName = build_display_name(
        (string) ($user['first_name'] ?? ''),
        (string) ($user['middle_name'] ?? ''),
        (string) ($user['last_name'] ?? ''),
        (string) ($user['suffix'] ?? '')
    );
}

$code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
$expiresAt = $now->add(new DateInterval('PT5M'));

$payload = [
    'user_id' => $userId,
    'code' => $code,
    'expires_at' => $expiresAt->format(DATE_ATOM),
    'created_at' => $now->format(DATE_ATOM),
    'last_sent_at' => $now->format(DATE_ATOM),
];

$saveUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/email_verification_codes';
$saveRes = supabase_request(
    'POST',
    $saveUrl,
    mobile_api_supabase_write_headers(),
    json_encode([$payload], JSON_UNESCAPED_SLASHES)
);
if (!$saveRes['ok']) {
    json_response(['ok' => false, 'error' => 'Failed to prepare verification code.'], 500);
}

$sent = send_mobile_email_verification_code_email($email, $fullName, $code);
if (!$sent) {
    $smtpDebug = smtp_get_last_error();
    json_response([
        'ok' => false,
        'error' => $smtpDebug !== ''
            ? 'Unable to send verification email: ' . $smtpDebug
            : 'Unable to send verification email. Please try again.',
    ], 500);
}

json_response([
    'ok' => true,
    'message' => 'Verification code sent to your email.',
    'expires_at' => $expiresAt->format(DATE_ATOM),
], 200);
