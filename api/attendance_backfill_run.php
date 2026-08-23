<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/session.php';
session_bootstrap();
set_time_limit(45);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/supabase.php';
require_once __DIR__ . '/../includes/json.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/api_cache.php';
require_once __DIR__ . '/../includes/event_sessions.php';
require_once __DIR__ . '/../includes/attendance_backfill.php';
require_once __DIR__ . '/../includes/helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok' => false, 'error' => 'Method not allowed'], 405);
}

$user = require_role(['admin', 'teacher']);
$data = require_post_json();
require_csrf_from_json($data);

$eventId = trim((string) ($data['event_id'] ?? ''));
if ($eventId === '') {
    json_response(['ok' => false, 'error' => 'event_id required'], 400);
}

$throttleKey = 'attendance_backfill_run:' . $eventId;
if (is_array(api_cache_read($throttleKey, 45))) {
    json_response(['ok' => true, 'skipped' => true, 'reason' => 'throttled'], 200);
}

$headers = [
    'Accept: application/json',
    'apikey: ' . SUPABASE_KEY,
    'Authorization: Bearer ' . SUPABASE_KEY,
];

$lookup = fetch_event_row_by_id(
    $eventId,
    $headers,
    'id,title,start_at,end_at,created_by,status,grace_time'
);
if (!$lookup['ok'] || !is_array($lookup['event'] ?? null)) {
    json_response(['ok' => false, 'error' => 'Event not found'], 404);
}

$event = $lookup['event'];
$role = (string) ($user['role'] ?? '');
$userId = (string) ($user['id'] ?? '');
if ($role === 'teacher') {
    $isOwner = ((string) ($event['created_by'] ?? '') === $userId);
    $isAssigned = false;
    if (!$isOwner && $userId !== '') {
        $assignUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/event_teacher_assignments'
            . '?select=id&event_id=eq.' . rawurlencode($eventId)
            . '&teacher_id=eq.' . rawurlencode($userId)
            . '&limit=1';
        $assignRes = supabase_request('GET', $assignUrl, $headers);
        $assignRows = json_decode((string) ($assignRes['body'] ?? ''), true);
        $isAssigned = is_array($assignRows) && count($assignRows) > 0;
    }
    if (!$isOwner && !$isAssigned) {
        json_response(['ok' => false, 'error' => 'Forbidden'], 403);
    }
}

try {
    $sessions = fetch_event_sessions($eventId, $headers);
    attendance_backfill_for_event($event, $headers, $sessions);
    api_cache_write($throttleKey, ['ok' => true, 'at' => gmdate('c')]);
    json_response(['ok' => true, 'skipped' => false], 200);
} catch (Throwable $e) {
    json_response(['ok' => false, 'error' => 'Backfill failed'], 500);
}
