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
require_once __DIR__ . '/../includes/document_review_access.php';
require_once __DIR__ . '/../includes/document_review_claim.php';

$user = require_role(['admin', 'teacher']);
$data = require_post_json();
require_csrf_from_json($data);

$eventId = trim((string) ($data['event_id'] ?? ''));
$studentId = trim((string) ($data['student_id'] ?? ''));
$action = strtolower(trim((string) ($data['action'] ?? 'claim')));
$userId = trim((string) ($user['id'] ?? ''));
$role = strtolower(trim((string) ($user['role'] ?? '')));

if ($eventId === '' || $studentId === '') {
    json_response(['ok' => false, 'error' => 'event_id and student_id are required.'], 400);
}
if (!in_array($action, ['claim', 'release', 'heartbeat'], true)) {
    json_response(['ok' => false, 'error' => 'Invalid action.'], 400);
}

$headers = student_requirement_headers();
$eventUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/events'
    . '?id=eq.' . rawurlencode($eventId)
    . '&select=id,created_by'
    . '&limit=1';
$eventRes = supabase_request('GET', $eventUrl, $headers);
$eventRows = ($eventRes['ok'] ?? false) ? json_decode((string) ($eventRes['body'] ?? ''), true) : [];
$event = is_array($eventRows) && isset($eventRows[0]) && is_array($eventRows[0]) ? $eventRows[0] : null;
if (!is_array($event)) {
    json_response(['ok' => false, 'error' => 'Event not found.'], 404);
}

$isCreator = trim((string) ($event['created_by'] ?? '')) === $userId;
if (!event_user_can_review_documents($eventId, $userId, $role, $isCreator)) {
    json_response(['ok' => false, 'error' => 'You do not have Document Review access for this event.'], 403);
}

if ($action === 'release') {
    $result = document_review_claim_release($eventId, $studentId, $userId);
    json_response(['ok' => (bool) ($result['ok'] ?? false)], 200);
}

if ($action === 'heartbeat') {
    $result = document_review_claim_heartbeat($eventId, $studentId, $userId);
    if (!($result['ok'] ?? false)) {
        json_response([
            'ok' => false,
            'conflict' => !empty($result['conflict']),
            'error' => (string) ($result['error'] ?? 'Review lock expired.'),
        ], !empty($result['conflict']) ? 409 : 400);
    }
    json_response(['ok' => true, 'skipped' => !empty($result['skipped'])], 200);
}

$result = document_review_claim_take($eventId, $studentId, $userId);
if (!($result['ok'] ?? false)) {
    json_response([
        'ok' => false,
        'conflict' => !empty($result['conflict']),
        'error' => (string) ($result['error'] ?? 'This student is already being reviewed.'),
    ], !empty($result['conflict']) ? 409 : 400);
}

json_response([
    'ok' => true,
    'skipped' => !empty($result['skipped']),
], 200);
