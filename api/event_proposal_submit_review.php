<?php
declare(strict_types=1);

session_start();

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/supabase.php';
require_once __DIR__ . '/../includes/json.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/proposal_requirements.php';

$user = require_role(['teacher']);
$data = require_post_json();
require_csrf_from_json($data);

$eventId = trim((string) ($data['event_id'] ?? ''));
$teacherId = trim((string) ($user['id'] ?? ''));

if ($eventId === '') {
    json_response(['ok' => false, 'error' => 'Missing event id.'], 400);
}

$headers = proposal_requirement_headers();
$eventUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/events'
    . '?id=eq.' . rawurlencode($eventId)
    . '&select=id,title,status,created_by,proposal_stage'
    . '&limit=1';
$eventRes = supabase_request('GET', $eventUrl, $headers);
if (!$eventRes['ok']) {
    json_response(['ok' => false, 'error' => build_error($eventRes['body'] ?? null, (int) ($eventRes['status'] ?? 0), $eventRes['error'] ?? null, 'Unable to load the event proposal')], 500);
}

$eventRows = json_decode((string) ($eventRes['body'] ?? ''), true);
$event = is_array($eventRows) && isset($eventRows[0]) && is_array($eventRows[0]) ? $eventRows[0] : null;
if (!is_array($event)) {
    json_response(['ok' => false, 'error' => 'Proposal event not found.'], 404);
}

if (trim((string) ($event['created_by'] ?? '')) !== $teacherId) {
    json_response(['ok' => false, 'error' => 'You can only submit your own proposal for review.'], 403);
}

if (strtolower(trim((string) ($event['status'] ?? ''))) !== 'pending') {
    json_response(['ok' => false, 'error' => 'Only pending proposals can be submitted for review.'], 400);
}

$requirements = fetch_proposal_requirements_map([$eventId], $headers)[$eventId] ?? [];
if ($requirements === []) {
    json_response(['ok' => false, 'error' => 'Add the required proposal documents before submitting for review.'], 400);
}

$submissionMap = fetch_proposal_submissions_map([$eventId], $headers)[$eventId] ?? [];
$summary = build_proposal_requirement_summary($requirements, $submissionMap);
if (($summary['complete'] ?? false) !== true) {
    $submitted = (int) ($summary['submitted'] ?? 0);
    $total = (int) ($summary['total'] ?? count($requirements));
    json_response(['ok' => false, 'error' => 'Upload all required proposal documents before submitting for review. (' . $submitted . '/' . $total . ')'], 400);
}

$documentsUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/event_proposal_documents'
    . '?event_id=eq.' . rawurlencode($eventId)
    . '&teacher_id=eq.' . rawurlencode($teacherId);
$documentsPayload = json_encode([
    'admin_visible' => true,
    'visible_at' => gmdate('c'),
    'updated_at' => gmdate('c'),
], JSON_UNESCAPED_SLASHES);
if (!is_string($documentsPayload)) {
    json_response(['ok' => false, 'error' => 'Unable to prepare proposal document visibility payload.'], 500);
}

$documentsRes = supabase_request('PATCH', $documentsUrl, proposal_requirement_write_headers(), $documentsPayload);
if (!$documentsRes['ok']) {
    json_response(['ok' => false, 'error' => build_error($documentsRes['body'] ?? null, (int) ($documentsRes['status'] ?? 0), $documentsRes['error'] ?? null, 'Failed to reveal the uploaded proposal documents')], 500);
}

$eventUpdatePayload = json_encode([
    'proposal_stage' => 'under_review',
    'requirements_requested_at' => gmdate('c'),
    'requirements_submitted_at' => gmdate('c'),
    'updated_at' => gmdate('c'),
], JSON_UNESCAPED_SLASHES);
if (!is_string($eventUpdatePayload)) {
    json_response(['ok' => false, 'error' => 'Unable to prepare the proposal review payload.'], 500);
}

$eventUpdateUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/events?id=eq.' . rawurlencode($eventId);
$eventUpdateRes = supabase_request('PATCH', $eventUpdateUrl, proposal_requirement_write_headers(), $eventUpdatePayload);
if (!$eventUpdateRes['ok']) {
    json_response(['ok' => false, 'error' => build_error($eventUpdateRes['body'] ?? null, (int) ($eventUpdateRes['status'] ?? 0), $eventUpdateRes['error'] ?? null, 'Failed to submit the proposal for review')], 500);
}

json_response([
    'ok' => true,
    'message' => 'Proposal submitted for admin review.',
], 200);
