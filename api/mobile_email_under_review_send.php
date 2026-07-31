<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/supabase.php';
require_once __DIR__ . '/../includes/json.php';
require_once __DIR__ . '/../includes/email_notifications.php';
require_once __DIR__ . '/../includes/mobile_api.php';

require_once __DIR__ . '/../includes/mobile_session.php';
require_once __DIR__ . '/../includes/api_rate_limit.php';

$data = mobile_api_require_post_json();
mobile_api_validate_key($data);

$sessionUser = mobile_api_require_user($data);
$userId = (string) ($sessionUser['id'] ?? '');
$email = strtolower(trim((string) ($sessionUser['email'] ?? '')));
$fullName = trim((string) ($data['full_name'] ?? ''));
if ($fullName === '') {
    $fullName = build_display_name(
        (string) ($sessionUser['first_name'] ?? ''),
        (string) ($sessionUser['middle_name'] ?? ''),
        (string) ($sessionUser['last_name'] ?? ''),
        (string) ($sessionUser['suffix'] ?? '')
    );
}

$clientIp = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
if (!api_rate_limit_allow('mobile_under_review:' . $userId . ':' . $clientIp, 3, 600)) {
    json_response(['ok' => false, 'error' => 'Too many requests. Please wait.'], 429);
}

if ($userId === '' || $email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    json_response(['ok' => false, 'error' => 'Invalid request.'], 400);
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

$sent = send_application_under_review_email($email, $fullName);
if (!$sent) {
    $smtpDebug = smtp_get_last_error();
    json_response([
        'ok' => false,
        'error' => $smtpDebug !== ''
            ? 'Unable to send under-review email: ' . $smtpDebug
            : 'Unable to send under-review email. Please try again.',
    ], 500);
}

json_response(['ok' => true, 'message' => 'Under-review email sent.'], 200);
