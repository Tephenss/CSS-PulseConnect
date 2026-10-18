<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/session.php';
session_bootstrap();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/supabase.php';
require_once __DIR__ . '/../includes/json.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/event_registration_submit.php';

$user = require_role(['admin', 'teacher']);
$data = require_post_json();
require_csrf_from_json($data);
$submissionId = trim((string) ($data['submission_id'] ?? ''));
$action = strtolower(trim((string) ($data['action'] ?? '')));
$reviewNote = trim((string) ($data['review_note'] ?? ''));
if ($submissionId === '' || !in_array($action, ['approve', 'reject'], true)) {
    json_response(['ok' => false, 'error' => 'Choose a payment submission and review action.'], 400);
}
if (mb_strlen($reviewNote) > 240) {
    json_response(['ok' => false, 'error' => 'Review note must be 240 characters or fewer.'], 400);
}

$headers = [
    'Accept: application/json',
    'apikey: ' . SUPABASE_KEY,
    'Authorization: Bearer ' . SUPABASE_KEY,
];
$submissionUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/event_payment_submissions'
    . '?id=eq.' . rawurlencode($submissionId) . '&limit=1';
$submissionRes = supabase_request('GET', $submissionUrl . '&select=*', $headers);
$rows = json_decode((string) ($submissionRes['body'] ?? ''), true);
$submission = is_array($rows) && isset($rows[0]) && is_array($rows[0]) ? $rows[0] : null;
if (!is_array($submission)) {
    json_response(['ok' => false, 'error' => 'Payment submission not found.'], 404);
}
if ((string) ($submission['status'] ?? '') !== 'pending') {
    json_response(['ok' => false, 'error' => 'This payment has already been reviewed.'], 409);
}

$eventId = trim((string) ($submission['event_id'] ?? ''));
$eventRes = supabase_request('GET', rtrim(SUPABASE_URL, '/') . '/rest/v1/events'
    . '?id=eq.' . rawurlencode($eventId) . '&select=id,created_by&limit=1', $headers);
$eventRows = json_decode((string) ($eventRes['body'] ?? ''), true);
$event = is_array($eventRows) && isset($eventRows[0]) && is_array($eventRows[0]) ? $eventRows[0] : null;
$role = strtolower(trim((string) ($user['role'] ?? '')));
$userId = trim((string) ($user['id'] ?? ''));
if (!is_array($event)) {
    json_response(['ok' => false, 'error' => 'Event not found.'], 404);
}
if ($role !== 'admin' && (string) ($event['created_by'] ?? '') !== $userId) {
    json_response(['ok' => false, 'error' => 'Only the event creator can review these payments.'], 403);
}

if ($action === 'approve') {
    $amount = (float) ($submission['amount'] ?? 0);
    $reference = trim((string) ($submission['reference_number'] ?? ''));
    $result = submit_staff_paid_event_registration(
        $eventId,
        (string) ($submission['student_id'] ?? ''),
        $amount,
        $userId,
        $headers,
        'GCash transfer | Ref: ' . mb_substr($reference, 0, 150)
    );
    if (!($result['ok'] ?? false)) {
        $status = (int) ($result['status'] ?? 500);
        unset($result['status']);
        json_response($result, $status >= 400 ? $status : 500);
    }
}

$updateUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/event_payment_submissions'
    . '?id=eq.' . rawurlencode($submissionId) . '&status=eq.pending';
$updateRes = supabase_request('PATCH', $updateUrl, [
    'Content-Type: application/json', 'Accept: application/json',
    'apikey: ' . SUPABASE_KEY, 'Authorization: Bearer ' . SUPABASE_KEY,
    'Prefer: return=representation',
], json_encode([
    'status' => $action === 'approve' ? 'approved' : 'rejected',
    'review_note' => $reviewNote !== '' ? $reviewNote : null,
    'reviewed_by' => $userId !== '' ? $userId : null,
    'reviewed_at' => gmdate('c'),
], JSON_UNESCAPED_SLASHES));
if (!$updateRes['ok']) {
    json_response(['ok' => false, 'error' => 'Could not save the review status. Refresh before retrying.'], 500);
}
$updated = json_decode((string) ($updateRes['body'] ?? ''), true);
if (!is_array($updated) || $updated === []) {
    json_response(['ok' => false, 'error' => 'This payment was reviewed by someone else. Refresh the page.'], 409);
}

json_response([
    'ok' => true,
    'status' => $action === 'approve' ? 'approved' : 'rejected',
    'message' => $action === 'approve'
        ? 'Payment approved and student slot secured.'
        : 'Payment submission rejected.',
    'amount_paid' => (float) ($submission['amount'] ?? 0),
]);
