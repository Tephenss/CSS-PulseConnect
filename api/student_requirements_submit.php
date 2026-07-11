<?php
declare(strict_types=1);

session_start();

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/supabase.php';
require_once __DIR__ . '/../includes/json.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/student_requirements.php';

$user = require_role(['student']);
$data = require_post_json();
require_csrf_from_json($data);

$eventId = trim((string) ($data['event_id'] ?? ''));
$studentId = trim((string) ($user['id'] ?? ''));

if ($eventId === '' || $studentId === '') {
    json_response(['ok' => false, 'error' => 'Missing event id.'], 400);
}

$headers = student_requirement_headers();
$requirements = fetch_student_requirements_map([$eventId], $headers)[$eventId] ?? [];
if ($requirements === []) {
    json_response(['ok' => false, 'error' => 'This event has no student document requirements.'], 400);
}

$documents = fetch_student_documents_map([$eventId], $headers, $studentId)[$eventId][$studentId] ?? [];
$summary = build_student_requirement_summary($requirements, $documents);
if (!($summary['complete'] ?? false)) {
    json_response([
        'ok' => false,
        'error' => 'Upload all required documents before submitting.',
        'summary' => $summary,
    ], 400);
}

$submission = fetch_student_submissions_map([$eventId], $headers, $studentId)[$eventId][$studentId] ?? null;
$currentStatus = is_array($submission) ? strtolower(trim((string) ($submission['status'] ?? ''))) : '';
if ($currentStatus === 'pending_review') {
    json_response(['ok' => false, 'error' => 'Your documents are already under review.'], 400);
}
if ($currentStatus === 'approved') {
    json_response(['ok' => true, 'already_approved' => true, 'submission' => $submission], 200);
}

$now = gmdate('c');
$payload = [
    'event_id' => $eventId,
    'student_id' => $studentId,
    'status' => 'pending_review',
    'submitted_at' => $now,
    'reviewed_at' => null,
    'reviewed_by' => null,
    'decline_reason' => null,
    'updated_at' => $now,
];

$url = rtrim(SUPABASE_URL, '/') . '/rest/v1/event_student_submissions?on_conflict=event_id,student_id';
$res = supabase_request('POST', $url, student_requirement_write_headers(), json_encode($payload, JSON_UNESCAPED_SLASHES));

if (!$res['ok']) {
    json_response(['ok' => false, 'error' => build_error($res['body'] ?? null, (int) ($res['status'] ?? 0), $res['error'] ?? null, 'Failed to submit documents for review.')], 500);
}

$rows = json_decode((string) ($res['body'] ?? ''), true);
$saved = is_array($rows) && isset($rows[0]) ? $rows[0] : null;

json_response([
    'ok' => true,
    'submission' => $saved,
    'summary' => $summary,
], 200);
