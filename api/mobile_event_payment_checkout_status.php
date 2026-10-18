<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/supabase.php';
require_once __DIR__ . '/../includes/json.php';
require_once __DIR__ . '/../includes/mobile_api.php';
require_once __DIR__ . '/../includes/mobile_session.php';

$data = mobile_api_require_post_json();
mobile_api_validate_key($data);
$user = mobile_api_require_user($data);
if (strtolower(trim((string) ($user['role'] ?? ''))) !== 'student') {
    json_response(['ok' => false, 'error' => 'Student account required.'], 403);
}
$eventId = trim((string) ($data['event_id'] ?? ''));
$studentId = trim((string) ($user['id'] ?? ''));
if ($eventId === '' || $studentId === '') {
    json_response(['ok' => false, 'error' => 'Missing event or student.'], 400);
}

$url = rtrim(SUPABASE_URL, '/') . '/rest/v1/event_payment_checkouts'
    . '?select=id,amount,status,qr_image_url,expires_at,paid_at,registration_id,failure_reason,created_at'
    . '&event_id=eq.' . rawurlencode($eventId)
    . '&student_id=eq.' . rawurlencode($studentId)
    . '&order=created_at.desc&limit=1';
$res = supabase_request('GET', $url, mobile_api_supabase_headers());
if (!$res['ok']) {
    json_response(['ok' => false, 'error' => 'Payment status is temporarily unavailable. Run migrations 074 and 075 if needed.'], 500);
}
$rows = json_decode((string) ($res['body'] ?? ''), true);
$checkout = is_array($rows) && isset($rows[0]) && is_array($rows[0]) ? $rows[0] : null;
if (!is_array($checkout)) {
    json_response([
        'ok' => true,
        'status' => 'not_started',
        'paid' => false,
        'registered' => false,
    ]);
}

$status = strtolower(trim((string) ($checkout['status'] ?? '')));
$registered = $status === 'paid';
json_response([
    'ok' => true,
    'checkout_id' => (string) ($checkout['id'] ?? ''),
    'status' => $status,
    'amount' => (float) ($checkout['amount'] ?? 0),
    'paid' => $status === 'paid',
    'registered' => $registered,
    'qr_image_url' => $status === 'pending' ? (string) ($checkout['qr_image_url'] ?? '') : null,
    'expires_at' => $checkout['expires_at'] ?? null,
    'paid_at' => $checkout['paid_at'] ?? null,
    'failure_reason' => $checkout['failure_reason'] ?? null,
]);
