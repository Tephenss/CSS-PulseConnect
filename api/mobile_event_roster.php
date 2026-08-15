<?php
declare(strict_types=1);

/**
 * Roster reads (participants / assistants) with user profile joins via service role.
 * Flutter anon cannot read users — route roster through this BFF.
 * Teachers/admins: participants + assistants. Assigned student scanners: participants only.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/supabase.php';
require_once __DIR__ . '/../includes/json.php';
require_once __DIR__ . '/../includes/mobile_api.php';
require_once __DIR__ . '/../includes/mobile_session.php';
require_once __DIR__ . '/../includes/api_rate_limit.php';
require_once __DIR__ . '/../includes/student_roster.php';

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
$isAdmin = $role === 'admin';
$isTeacher = $role === 'teacher' && mobile_teacher_can_access_event($eventId, $userId, $headers);
$isAssistantScanner = $role === 'student'
    && $type === 'participants'
    && mobile_student_is_event_assistant($eventId, $userId, $headers);
if (!$isAdmin && !$isTeacher && !$isAssistantScanner) {
    json_response(['ok' => false, 'error' => 'You do not have access to this event roster.'], 403);
}

if ($type === 'participants') {
    $url = rtrim(SUPABASE_URL, '/') . '/rest/v1/event_registrations'
        . '?select=' . rawurlencode(
            'id,registered_at,student_id,'
            . 'users:student_id(first_name,middle_name,last_name,suffix,email,student_id,photo_url,course,sections(name)),'
            . 'tickets(*,attendance(*))'
        )
        . '&event_id=eq.' . rawurlencode($eventId)
        . '&order=registered_at.desc'
        . '&limit=500';
} else {
    $url = rtrim(SUPABASE_URL, '/') . '/rest/v1/event_assistants'
        . '?select=' . rawurlencode(
            'id,event_id,student_id,allow_scan,assigned_by_teacher_id,'
            . 'users:student_id(first_name,middle_name,last_name,suffix,email,student_id,photo_url,course,sections(name))'
        )
        . '&event_id=eq.' . rawurlencode($eventId)
        . '&order=student_id.asc'
        . '&limit=200';
}

$res = supabase_request('GET', $url, $headers);
if (!$res['ok'] && $type === 'participants') {
    $url = rtrim(SUPABASE_URL, '/') . '/rest/v1/event_registrations'
        . '?select=' . rawurlencode(
            'id,registered_at,student_id,'
            . 'users:student_id(first_name,middle_name,last_name,suffix,email,student_id,photo_url,course),'
            . 'tickets(*,attendance(*))'
        )
        . '&event_id=eq.' . rawurlencode($eventId)
        . '&order=registered_at.desc'
        . '&limit=500';
    $res = supabase_request('GET', $url, $headers);
}
if (!$res['ok']) {
    json_response(['ok' => false, 'error' => 'Failed to load roster.'], 500);
}

$rows = json_decode((string) ($res['body'] ?? ''), true);
if (!is_array($rows)) {
    $rows = [];
}

$userIds = [];
$studentNos = [];
foreach ($rows as $row) {
    if (!is_array($row)) {
        continue;
    }
    $uid = trim((string) ($row['student_id'] ?? ''));
    if ($uid !== '') {
        $userIds[] = $uid;
    }
    $u = isset($row['users']) && is_array($row['users']) ? $row['users'] : [];
    $no = trim((string) ($u['student_id'] ?? ''));
    if ($no !== '') {
        $studentNos[] = $no;
    }
}
$yearMaps = student_roster_fetch_year_maps($userIds, $studentNos, $headers);
foreach ($rows as &$row) {
    if (!is_array($row)) {
        continue;
    }
    $u = isset($row['users']) && is_array($row['users']) ? $row['users'] : [];
    $sections = isset($u['sections']) && is_array($u['sections']) ? $u['sections'] : [];
    $sectionName = trim((string) ($sections['name'] ?? ($u['section_name'] ?? '')));
    $yearKey = student_roster_resolve_year_key(
        trim((string) ($row['student_id'] ?? '')),
        trim((string) ($u['student_id'] ?? '')),
        $sectionName,
        $yearMaps
    );
    $yearLabel = student_roster_year_ordinal_label($yearKey);
    $u['section_name'] = $sectionName;
    $u['year_level'] = $yearLabel;
    $u['year_key'] = $yearKey;
    $row['users'] = $u;
}
unset($row);

json_response([
    'ok' => true,
    'type' => $type,
    'rows' => $rows,
], 200);
