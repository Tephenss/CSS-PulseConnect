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

$url = rtrim(SUPABASE_URL, '/') . '/rest/v1/event_payment_submissions'
    . '?select=status,amount,created_at'
    . '&event_id=eq.' . rawurlencode($eventId)
    . '&student_id=eq.' . rawurlencode($studentId)
    . '&status=eq.pending&order=created_at.desc&limit=1';
$res = supabase_request('GET', $url, mobile_api_supabase_headers());
if (!$res['ok']) {
    json_response(['ok' => false, 'error' => 'Payment status is temporarily unavailable.'], 500);
}
$rows = json_decode((string) ($res['body'] ?? ''), true);
$submission = is_array($rows) && isset($rows[0]) && is_array($rows[0]) ? $rows[0] : null;
json_response([
    'ok' => true,
    'pending' => is_array($submission),
    'status' => is_array($submission) ? 'pending' : null,
    'amount' => is_array($submission) ? (float) ($submission['amount'] ?? 0) : null,
    'created_at' => is_array($submission) ? ($submission['created_at'] ?? null) : null,
]);
