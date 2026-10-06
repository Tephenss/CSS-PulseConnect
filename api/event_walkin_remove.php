<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/session.php';
session_bootstrap();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/supabase.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/json.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/student_roster.php';
require_once __DIR__ . '/../includes/event_walkin.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('X-Content-Type-Options: nosniff');

$user = require_role(['admin', 'teacher']);
$data = require_post_json();
require_csrf_from_json($data);

$eventId = trim((string) ($data['event_id'] ?? ''));
$studentNo = student_roster_normalize_no((string) ($data['student_no'] ?? ''));
if (!preg_match('/^[0-9a-f-]{36}$/i', $eventId) || $studentNo === '') {
    json_response(['ok' => false, 'error' => 'Invalid event or student ID.'], 400);
}

$headers = [
    'Accept: application/json',
    'apikey: ' . SUPABASE_KEY,
    'Authorization: Bearer ' . SUPABASE_KEY,
];
$eventLookup = fetch_event_row_by_id($eventId, $headers, 'id,created_by');
if (!($eventLookup['ok'] ?? false) || !is_array($eventLookup['event'] ?? null)) {
    json_response(['ok' => false, 'error' => 'Event not found.'], 404);
}
$event = $eventLookup['event'];
$role = strtolower((string) ($user['role'] ?? ''));
$isOwner = trim((string) ($event['created_by'] ?? '')) !== ''
    && hash_equals((string) $event['created_by'], (string) ($user['id'] ?? ''));
if ($role !== 'admin' && !$isOwner) {
    json_response(['ok' => false, 'error' => 'Only the event owner or an administrator can remove walk-in attendance.'], 403);
}

$walkIns = event_walkin_fetch_rows($eventId);
if (!($walkIns['ok'] ?? false)) {
    json_response(['ok' => false, 'error' => 'Could not load walk-in attendance from Firebase.'], 503);
}

$matches = [];
foreach ($walkIns['rows'] as $row) {
    if (!is_array($row)
        || student_roster_normalize_no((string) ($row['student_no'] ?? '')) !== $studentNo) {
        continue;
    }
    $documentId = trim((string) ($row['id'] ?? ''));
    if (preg_match('/^[a-f0-9]{64}$/i', $documentId)) {
        $matches[] = $documentId;
    }
}
if ($matches === []) {
    json_response(['ok' => false, 'error' => 'No walk-in attendance was found for this student.'], 404);
}

$deleted = 0;
foreach ($matches as $documentId) {
    $documentUrl = firestore_doc_url(
        'event_walkin_events/' . rawurlencode($eventId) . '/participants/' . rawurlencode($documentId)
    );
    if ($documentUrl === null) {
        json_response(['ok' => false, 'error' => 'Firebase credentials are unavailable.'], 503);
    }
    $result = firestore_request('DELETE', $documentUrl);
    if ($result['ok'] || (int) ($result['status'] ?? 0) === 404) {
        $deleted++;
        continue;
    }
    error_log('Walk-in attendance deletion failed for event ' . $eventId);
    json_response(['ok' => false, 'error' => 'Could not remove all walk-in attendance records. Please retry.'], 503);
}

json_response(['ok' => true, 'deleted' => $deleted]);
