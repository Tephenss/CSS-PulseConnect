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
require_once __DIR__ . '/../includes/user_notifications.php';

$user = require_role(['admin', 'teacher']);
$data = require_post_json();
require_csrf_from_json($data);

$userId = trim((string) ($user['id'] ?? ''));
$role = strtolower(trim((string) ($user['role'] ?? '')));
$eventId = trim((string) ($data['event_id'] ?? ''));
$enabledRaw = $data['enabled'] ?? false;
$enabled = $enabledRaw === true || $enabledRaw === 1 || $enabledRaw === '1' || $enabledRaw === 'true';

$teacherIds = [];
if (isset($data['teacher_ids']) && is_array($data['teacher_ids'])) {
    foreach ($data['teacher_ids'] as $rawId) {
        $id = trim((string) $rawId);
        if ($id !== '') {
            $teacherIds[$id] = true;
        }
    }
}
$singleId = trim((string) ($data['teacher_id'] ?? ''));
if ($singleId !== '') {
    $teacherIds[$singleId] = true;
}
$teacherIds = array_keys($teacherIds);

if ($eventId === '' || $teacherIds === []) {
    json_response(['ok' => false, 'error' => 'event_id and at least one teacher are required.'], 400);
}
if (count($teacherIds) > 80) {
    json_response(['ok' => false, 'error' => 'Too many teachers in one request.'], 400);
}

$headers = document_review_access_headers();
$eventUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/events'
    . '?id=eq.' . rawurlencode($eventId)
    . '&select=id,title,created_by'
    . '&limit=1';
$eventRes = supabase_request('GET', $eventUrl, $headers);
$eventRows = ($eventRes['ok'] ?? false) ? json_decode((string) ($eventRes['body'] ?? ''), true) : [];
$event = is_array($eventRows) && isset($eventRows[0]) && is_array($eventRows[0]) ? $eventRows[0] : null;
if (!is_array($event)) {
    json_response(['ok' => false, 'error' => 'Event not found.'], 404);
}

$creatorId = trim((string) ($event['created_by'] ?? ''));
$isCreator = $creatorId !== '' && $creatorId === $userId;
if ($role !== 'admin' && !$isCreator) {
    json_response(['ok' => false, 'error' => 'Only the event creator can grant Document Review access.'], 403);
}

$profiles = document_review_load_teacher_profiles($teacherIds);
$updated = [];
$notifyIds = [];

foreach ($teacherIds as $teacherId) {
    if ($teacherId === $creatorId) {
        continue;
    }
    if (!isset($profiles[$teacherId])) {
        json_response(['ok' => false, 'error' => 'Teacher not found.'], 404);
    }

    $result = event_document_review_set_access($eventId, $teacherId, $enabled, $userId);
    if (!($result['ok'] ?? false)) {
        json_response(['ok' => false, 'error' => (string) ($result['error'] ?? 'Failed to update access.')], 500);
    }

    $profile = $profiles[$teacherId];
    $updated[] = [
        'teacher_id' => $teacherId,
        'name' => document_review_teacher_display_name($profile),
        'email' => (string) ($profile['email'] ?? ''),
    ];
    if ($enabled) {
        $notifyIds[] = $teacherId;
    }
}

if ($updated === []) {
    json_response(['ok' => false, 'error' => 'No teachers to update.'], 400);
}

if ($notifyIds !== []) {
    $eventTitle = trim((string) ($event['title'] ?? 'Event'));
    dispatch_user_notifications(
        $notifyIds,
        'Document Review Access Granted',
        'You can now review student documents for "' . $eventTitle . '". Open the event and use the Document Review tab.',
        [
            'event_id' => $eventId,
            'type' => 'teacher_document_review_assigned',
        ]
    );
}

json_response([
    'ok' => true,
    'enabled' => $enabled,
    'teachers' => $updated,
], 200);
