<?php
declare(strict_types=1);

session_start();

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/supabase.php';
require_once __DIR__ . '/../includes/json.php';
require_once __DIR__ . '/../includes/csrf.php';

$user = require_role(['admin']);
$data = require_post_json();
require_csrf_from_json($data);

function normalize_id_list(mixed $raw): array
{
    if (!is_array($raw)) {
        return [];
    }

    $unique = [];
    foreach ($raw as $value) {
        $id = trim((string) $value);
        if ($id !== '') {
            $unique[$id] = true;
        }
    }

    return array_keys($unique);
}

function fetch_event_for_approval(string $eventId, array $headers): ?array
{
    $url = rtrim(SUPABASE_URL, '/') . '/rest/v1/events'
        . '?id=eq.' . rawurlencode($eventId)
        . '&select=id,status,title,created_by,description,event_for,start_at,end_at,location'
        . '&limit=1';
    $res = supabase_request('GET', $url, $headers);
    $rows = $res['ok'] ? json_decode((string) $res['body'], true) : [];
    return is_array($rows) && isset($rows[0]) && is_array($rows[0]) ? $rows[0] : null;
}

function validate_teacher_ids(array $teacherIds, array $headers): array
{
    if (empty($teacherIds)) {
        return [];
    }

    $inList = '(' . implode(',', array_map('rawurlencode', $teacherIds)) . ')';
    $url = rtrim(SUPABASE_URL, '/') . '/rest/v1/users'
        . '?select=id'
        . '&role=eq.teacher'
        . '&id=in.' . $inList;

    $res = supabase_request('GET', $url, $headers);
    $rows = $res['ok'] ? json_decode((string) $res['body'], true) : [];
    if (!is_array($rows)) {
        return [];
    }

    $valid = [];
    foreach ($rows as $row) {
        $id = trim((string) ($row['id'] ?? ''));
        if ($id !== '') {
            $valid[$id] = true;
        }
    }

    return array_keys($valid);
}

function sync_event_teacher_membership(string $eventId, array $teacherIds, string $adminId, array $headers): array
{
    $readUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/event_teacher_assignments'
        . '?select=teacher_id'
        . '&event_id=eq.' . rawurlencode($eventId);
    $currentRes = supabase_request('GET', $readUrl, $headers);
    $currentRows = $currentRes['ok'] ? json_decode((string) $currentRes['body'], true) : [];
    if (!$currentRes['ok']) {
        return [
            'ok' => false,
            'errors' => [build_error($currentRes['body'] ?? null, (int) ($currentRes['status'] ?? 0), $currentRes['error'] ?? null, 'Failed to load current event teachers')],
            'added' => [],
        ];
    }

    $currentIds = [];
    if (is_array($currentRows)) {
        foreach ($currentRows as $row) {
            $teacherId = trim((string) ($row['teacher_id'] ?? ''));
            if ($teacherId !== '') {
                $currentIds[$teacherId] = true;
            }
        }
    }

    $selectedIds = [];
    foreach ($teacherIds as $teacherId) {
        $selectedIds[$teacherId] = true;
    }

    $toAdd = array_values(array_diff(array_keys($selectedIds), array_keys($currentIds)));
    $toRemove = array_values(array_diff(array_keys($currentIds), array_keys($selectedIds)));
    $errors = [];

    $writeHeaders = [
        'Accept: application/json',
        'Content-Type: application/json',
        'Prefer: return=representation',
        'apikey: ' . SUPABASE_KEY,
        'Authorization: Bearer ' . SUPABASE_KEY,
    ];

    foreach ($teacherIds as $teacherId) {
        $payload = json_encode([
            'event_id' => $eventId,
            'teacher_id' => $teacherId,
            'can_scan' => false,
            'can_manage_assistants' => false,
            'assigned_by' => $adminId,
            'assigned_at' => gmdate('c'),
        ], JSON_UNESCAPED_SLASHES);

        if (!is_string($payload)) {
            $errors[] = 'Failed to prepare teacher assignment payload.';
            continue;
        }

        $url = rtrim(SUPABASE_URL, '/') . '/rest/v1/event_teacher_assignments?on_conflict=event_id,teacher_id';
        $res = supabase_request('POST', $url, $writeHeaders, $payload);
        if (!$res['ok']) {
            $errors[] = build_error($res['body'] ?? null, (int) ($res['status'] ?? 0), $res['error'] ?? null, 'Failed to save event teacher');
        }
    }

    foreach ($toRemove as $teacherId) {
        $url = rtrim(SUPABASE_URL, '/') . '/rest/v1/event_teacher_assignments'
            . '?event_id=eq.' . rawurlencode($eventId)
            . '&teacher_id=eq.' . rawurlencode($teacherId);
        $res = supabase_request('DELETE', $url, $headers);
        if (!$res['ok']) {
            $errors[] = build_error($res['body'] ?? null, (int) ($res['status'] ?? 0), $res['error'] ?? null, 'Failed to remove old event teacher');
        }
    }

    return [
        'ok' => empty($errors),
        'errors' => array_values(array_unique($errors)),
        'added' => $toAdd,
    ];
}

function send_notification_to_users(array $userIds, string $title, string $body, array $data = []): void
{
    if (empty($userIds)) {
        return;
    }

    require_once __DIR__ . '/../includes/fcm.php';

    $inList = '(' . implode(',', array_map('rawurlencode', $userIds)) . ')';
    $tokensRes = supabase_request('GET',
        rtrim(SUPABASE_URL, '/') . '/rest/v1/fcm_tokens?select=token&user_id=in.' . $inList,
        ['apikey: ' . SUPABASE_KEY, 'Authorization: Bearer ' . SUPABASE_KEY]
    );

    if (!$tokensRes['ok']) {
        return;
    }

    $tokenRows = json_decode((string) $tokensRes['body'], true);
    $tokens = [];
    if (is_array($tokenRows)) {
        foreach ($tokenRows as $row) {
            $token = trim((string) ($row['token'] ?? ''));
            if ($token !== '') {
                $tokens[$token] = true;
            }
        }
    }

    if (!empty($tokens)) {
        send_fcm_notification(array_keys($tokens), $title, $body, $data);
    }
}

$eventId = isset($data['event_id']) ? trim((string) $data['event_id']) : '';
$status = isset($data['status']) ? trim((string) $data['status']) : 'approved';
$rejectionReason = isset($data['reason']) ? trim((string) $data['reason']) : '';
$teacherIds = normalize_id_list($data['teacher_ids'] ?? null);

if ($eventId === '') {
    json_response(['ok' => false, 'error' => 'event_id required'], 400);
}
if (!in_array($status, ['draft', 'pending', 'approved', 'published', 'closed', 'archived'], true)) {
    json_response(['ok' => false, 'error' => 'Invalid status'], 400);
}

$headers = [
    'Content-Type: application/json',
    'Accept: application/json',
    'apikey: ' . SUPABASE_KEY,
    'Authorization: Bearer ' . SUPABASE_KEY,
];

$existingEvent = fetch_event_for_approval($eventId, $headers);
if (!is_array($existingEvent)) {
    json_response(['ok' => false, 'error' => 'Event not found'], 404);
}

$previousStatus = (string) ($existingEvent['status'] ?? '');
$initialPublishFlow = $status === 'published' && in_array($previousStatus, ['approved', 'pending'], true);
$validTeacherIds = [];

if ($initialPublishFlow) {
    if (empty($teacherIds)) {
        json_response(['ok' => false, 'error' => 'Select at least one teacher before publishing this event.'], 400);
    }

    $validTeacherIds = validate_teacher_ids($teacherIds, $headers);
    if (count($validTeacherIds) !== count($teacherIds)) {
        json_response(['ok' => false, 'error' => 'One or more selected teachers are invalid. Refresh the page and try again.'], 400);
    }

    $syncResult = sync_event_teacher_membership(
        $eventId,
        $validTeacherIds,
        (string) ($user['id'] ?? ''),
        $headers
    );

    if (!($syncResult['ok'] ?? false)) {
        json_response([
            'ok' => false,
            'error' => implode(' ', array_values(array_unique($syncResult['errors'] ?? ['Failed to assign event teachers.']))),
        ], 500);
    }
}

$payload = [
    'status' => $status,
    'approved_by' => (string) ($user['id'] ?? ''),
    'updated_at' => gmdate('c'),
];

if (in_array($status, ['draft', 'archived'], true) && $rejectionReason !== '') {
    $cleanDesc = (string) ($existingEvent['description'] ?? '');
    $cleanDesc = preg_replace('/\[REJECT_REASON:.*?\]\s*/s', '', $cleanDesc);
    $payload['description'] = '[REJECT_REASON: ' . $rejectionReason . '] ' . $cleanDesc;
}

$updateHeaders = $headers;
$updateHeaders[] = 'Prefer: return=representation';
$updateUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/events?id=eq.' . rawurlencode($eventId) . '&select=id,status,title,created_by,description,event_for,start_at,end_at,location';
$res = supabase_request('PATCH', $updateUrl, $updateHeaders, json_encode($payload, JSON_UNESCAPED_SLASHES));
if (!$res['ok']) {
    json_response([
        'ok' => false,
        'error' => build_error($res['body'] ?? null, (int) ($res['status'] ?? 0), $res['error'] ?? null, 'Approve failed'),
    ], 500);
}

$rows = json_decode((string) $res['body'], true);
$event = is_array($rows) && isset($rows[0]) ? $rows[0] : null;

$notifyTeacher = false;
if ($status === 'approved') {
    $notifyTeacher = true;
} elseif (in_array($status, ['draft', 'archived'], true) && $rejectionReason !== '') {
    $notifyTeacher = true;
}

if ($event && $notifyTeacher) {
    $teacherId = trim((string) ($event['created_by'] ?? ''));
    if ($teacherId !== '') {
        $eventTitle = (string) ($event['title'] ?? 'your event proposal');
        if ($status === 'approved') {
            $notifTitle = 'Proposal Approved';
            $notifBody = 'Great news! Your event "' . $eventTitle . '" has been approved by the admin.';
        } else {
            $notifTitle = 'Proposal Review Required';
            $notifBody = 'The admin has requested changes for "' . $eventTitle . '".';
            if ($rejectionReason !== '') {
                $notifBody .= ' Reason: ' . $rejectionReason;
            }
        }

        send_notification_to_users([$teacherId], $notifTitle, $notifBody, [
            'event_id' => $eventId,
            'type' => 'proposal_update',
        ]);
    }
}

if ($event && $initialPublishFlow && !empty($validTeacherIds)) {
    $eventTitle = (string) ($event['title'] ?? 'Event');
    $body = 'You have been assigned to "' . $eventTitle . '".';
    send_notification_to_users($validTeacherIds, 'Assigned to Event', $body, [
        'event_id' => $eventId,
        'type' => 'teacher_event_assigned',
    ]);
}

if ($event && in_array($status, ['published', 'draft'], true)) {
    $eventFor = (string) ($event['event_for'] ?? 'All');

    $usersUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/users?select=id&role=eq.student';
    if ($eventFor !== 'All' && $eventFor !== '' && strtolower($eventFor) !== 'all') {
        $usersUrl .= '&section_id=eq.' . rawurlencode($eventFor);
    }

    $usersRes = supabase_request('GET', $usersUrl, [
        'apikey: ' . SUPABASE_KEY,
        'Authorization: Bearer ' . SUPABASE_KEY,
    ]);

    $targetUserIds = [];
    if ($usersRes['ok']) {
        $userRows = json_decode((string) $usersRes['body'], true);
        if (is_array($userRows)) {
            foreach ($userRows as $row) {
                $id = trim((string) ($row['id'] ?? ''));
                if ($id !== '') {
                    $targetUserIds[$id] = true;
                }
            }
        }
    }

    if (!empty($targetUserIds)) {
        $eventTitle = (string) ($event['title'] ?? 'Event');
        $notifTitle = '';
        $notifBody = '';
        $notifType = '';

        if ($status === 'published') {
            if ($previousStatus === 'draft') {
                $notifTitle = 'Registration Open!';
                $notifBody = 'Registration for "' . $eventTitle . '" is now open.';
                $notifType = 'reg_open';
            } elseif (in_array($previousStatus, ['approved', 'pending'], true)) {
                $notifTitle = 'New Event Published';
                $notifBody = '"' . $eventTitle . '" has been published.';
                $notifType = 'event_published';
            }
        } elseif ($status === 'draft') {
            if (in_array($previousStatus, ['approved', 'pending'], true)) {
                $notifTitle = 'New Event Published';
                $notifBody = '"' . $eventTitle . '" has been published. Registration opens soon.';
                $notifType = 'event_published';
            } elseif ($previousStatus === 'published') {
                $notifTitle = 'Registration Closed';
                $notifBody = 'Registration for "' . $eventTitle . '" is now closed.';
                $notifType = 'reg_closed';
            }
        }

        if ($notifTitle !== '' && $notifType !== '') {
            send_notification_to_users(array_keys($targetUserIds), $notifTitle, $notifBody, [
                'event_id' => $eventId,
                'type' => $notifType,
            ]);
        }
    }
}

json_response(['ok' => true, 'event' => $event], 200);
