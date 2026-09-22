<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/session.php';
session_bootstrap();

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/supabase.php';
require_once __DIR__ . '/../includes/json.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/student_requirements.php';
require_once __DIR__ . '/../includes/document_review_access.php';
require_once __DIR__ . '/../includes/document_review_claim.php';

$user = require_role(['admin', 'teacher']);
$data = require_post_json();
require_csrf_from_json($data);

$eventId = trim((string) ($data['event_id'] ?? ''));
$studentId = trim((string) ($data['student_id'] ?? ''));
$action = strtolower(trim((string) ($data['action'] ?? '')));
$reason = trim((string) ($data['reason'] ?? ''));
$reviewerId = trim((string) ($user['id'] ?? ''));
$role = strtolower(trim((string) ($user['role'] ?? '')));

if ($eventId === '' || $studentId === '') {
    json_response(['ok' => false, 'error' => 'event_id and student_id are required.'], 400);
}
if (!in_array($action, ['approve', 'decline'], true)) {
    json_response(['ok' => false, 'error' => 'Invalid action.'], 400);
}
if ($action === 'decline' && $reason === '') {
    json_response(['ok' => false, 'error' => 'Decline reason is required.'], 400);
}

$headers = student_requirement_headers();
$eventUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/events'
    . '?id=eq.' . rawurlencode($eventId)
    . '&select=id,title,created_by'
    . '&limit=1';
$eventRes = supabase_request('GET', $eventUrl, $headers);
if (!$eventRes['ok']) {
    json_response(['ok' => false, 'error' => 'Unable to load event.'], 500);
}

$eventRows = json_decode((string) ($eventRes['body'] ?? ''), true);
$event = is_array($eventRows) && isset($eventRows[0]) ? $eventRows[0] : null;
if (!is_array($event)) {
    json_response(['ok' => false, 'error' => 'Event not found.'], 404);
}

$isCreator = trim((string) ($event['created_by'] ?? '')) === $reviewerId;
if (!event_user_can_review_documents($eventId, $reviewerId, $role, $isCreator)) {
    json_response(['ok' => false, 'error' => 'You do not have Document Review access for this event.'], 403);
}

$submission = fetch_student_submissions_map([$eventId], $headers, $studentId)[$eventId][$studentId] ?? null;
if (!is_array($submission)) {
    json_response(['ok' => false, 'error' => 'No student submission found for review.'], 404);
}

$currentStatus = strtolower(trim((string) ($submission['status'] ?? '')));
if ($currentStatus !== 'pending_review') {
    json_response([
        'ok' => false,
        'conflict' => true,
        'error' => document_review_conflict_message($submission),
    ], 409);
}

$now = gmdate('c');
$payload = [
    'status' => $action === 'approve' ? 'approved' : 'declined',
    'reviewed_at' => $now,
    'reviewed_by' => $reviewerId,
    'decline_reason' => $action === 'decline' ? mb_substr($reason, 0, 500) : null,
    'review_claimed_by' => null,
    'review_claimed_at' => null,
    'updated_at' => $now,
];

$submissionId = trim((string) ($submission['id'] ?? ''));
if ($submissionId === '') {
    json_response(['ok' => false, 'error' => 'Submission is missing an id.'], 500);
}

$patchUrl = document_review_decision_patch_url($submissionId, $reviewerId, true);
$patchRes = supabase_request('PATCH', $patchUrl, student_requirement_write_headers(), json_encode($payload, JSON_UNESCAPED_SLASHES));
if (!($patchRes['ok'] ?? false) && document_review_claim_column_missing((string) ($patchRes['body'] ?? ''))) {
    unset($payload['review_claimed_by'], $payload['review_claimed_at']);
    $patchUrl = document_review_decision_patch_url($submissionId, $reviewerId, false);
    $patchRes = supabase_request('PATCH', $patchUrl, student_requirement_write_headers(), json_encode($payload, JSON_UNESCAPED_SLASHES));
}
if (!$patchRes['ok']) {
    json_response(['ok' => false, 'error' => build_error($patchRes['body'] ?? null, (int) ($patchRes['status'] ?? 0), $patchRes['error'] ?? null, 'Failed to update submission.')], 500);
}

$updatedRows = json_decode((string) ($patchRes['body'] ?? ''), true);
if (!is_array($updatedRows) || $updatedRows === []) {
    $fresh = fetch_student_submissions_map([$eventId], $headers, $studentId)[$eventId][$studentId] ?? $submission;
    $fresh = is_array($fresh) ? $fresh : $submission;
    if (document_review_claim_is_active($fresh) && !document_review_claim_held_by($fresh, $reviewerId)) {
        json_response([
            'ok' => false,
            'conflict' => true,
            'error' => document_review_in_review_message($fresh),
        ], 409);
    }
    json_response([
        'ok' => false,
        'conflict' => true,
        'error' => document_review_conflict_message($fresh),
    ], 409);
}

$eventTitle = trim((string) ($event['title'] ?? 'Event'));
if ($action === 'approve') {
    notify_student_requirement_review(
        $studentId,
        'Documents Approved',
        'Your documents for "' . $eventTitle . '" were approved. You may now register.',
        ['type' => 'student_requirements_approved', 'event_id' => $eventId]
    );
} else {
    notify_student_requirement_review(
        $studentId,
        'Documents Declined',
        'Your documents for "' . $eventTitle . '" were declined. Please review the reason and resubmit.',
        ['type' => 'student_requirements_declined', 'event_id' => $eventId, 'reason' => $reason]
    );
}

json_response([
    'ok' => true,
    'status' => $payload['status'],
    'decline_reason' => $payload['decline_reason'],
], 200);
