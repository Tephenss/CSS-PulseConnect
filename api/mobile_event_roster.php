<?php
declare(strict_types=1);

/**
 * Teacher roster reads (participants / assistants) with user profile joins via service role.
 * Flutter anon cannot read users — route roster through this BFF.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/supabase.php';
require_once __DIR__ . '/../includes/json.php';
require_once __DIR__ . '/../includes/mobile_api.php';
require_once __DIR__ . '/../includes/mobile_session.php';
require_once __DIR__ . '/../includes/api_rate_limit.php';

$data = mobile_api_require_post_json();
mobile_api_validate_key($data);
$sessionUser = mobile_api_require_user($data);
$userId = (string) ($sessionUser['id'] ?? '');
$role = strtolower(trim((string) ($sessionUser['role'] ?? 'student')));

$clientIp = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
if (!api_rate_limit_allow('mobile_event_roster:' . $userId . ':' . $clientIp, 90, 60)) {
    json_response(['ok' => false, 'error' => 'Too many requests.'], 429);
}

$eventId = trim((string) ($data['event_id'] ?? ''));
$type = strtolower(trim((string) ($data['type'] ?? 'participants')));

if ($eventId === '') {
    json_response(['ok' => false, 'error' => 'event_id is required.'], 400);
}
if (!in_array($type, ['participants', 'assistants'], true)) {
    json_response(['ok' => false, 'error' => 'Invalid roster type.'], 400);
}

$headers = mobile_api_supabase_headers();
if ($role !== 'admin' && ($role !== 'teacher' || !mobile_teacher_can_access_event($eventId, $userId, $headers))) {
    json_response(['ok' => false, 'error' => 'You do not have access to this event roster.'], 403);
}

if ($type === 'participants') {
    $url = rtrim(SUPABASE_URL, '/') . '/rest/v1/event_registrations'
        . '?select=' . rawurlencode(
            'id,registered_at,student_id,'
            . 'users:student_id(first_name,middle_name,last_name,suffix,email,student_id,photo_url),'
            . 'tickets(*,attendance(*))'
        )
        . '&event_id=eq.' . rawurlencode($eventId)
        . '&order=registered_at.desc'
        . '&limit=500';
} else {
    $url = rtrim(SUPABASE_URL, '/') . '/rest/v1/event_assistants'
        . '?select=' . rawurlencode(
            'id,event_id,student_id,allow_scan,assigned_by_teacher_id,'
            . 'users:student_id(first_name,middle_name,last_name,suffix,email,student_id,photo_url)'
        )
        . '&event_id=eq.' . rawurlencode($eventId)
        . '&order=student_id.asc'
        . '&limit=200';
}

$res = supabase_request('GET', $url, $headers);
if (!$res['ok']) {
    json_response(['ok' => false, 'error' => 'Failed to load roster.'], 500);
}

$rows = json_decode((string) ($res['body'] ?? ''), true);
if (!is_array($rows)) {
    $rows = [];
}

json_response([
    'ok' => true,
    'type' => $type,
    'rows' => $rows,
], 200);
