<?php
declare(strict_types=1);

/**
 * Warm pack for student Event-QR (Take Attendance) offline mode.
 * Returns registered events + schedule + attendance state for local window checks.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/supabase.php';
require_once __DIR__ . '/../includes/json.php';
require_once __DIR__ . '/../includes/mobile_api.php';
require_once __DIR__ . '/../includes/mobile_session.php';
require_once __DIR__ . '/../includes/event_sessions.php';
require_once __DIR__ . '/../includes/scan_context.php';
require_once __DIR__ . '/../includes/event_attendance_windows.php';
require_once __DIR__ . '/../includes/storage_signed.php';

$data = mobile_api_require_post_json();
mobile_api_validate_key($data);
$sessionUser = mobile_api_require_user($data);
$userId = (string) ($sessionUser['id'] ?? '');
$role = strtolower(trim((string) ($sessionUser['role'] ?? 'student')));

if ($role !== 'student') {
    json_response(['ok' => false, 'error' => 'Only students can warm self-attendance packs.', 'status' => 'forbidden'], 403);
}

$headers = mobile_api_supabase_headers();
$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

$userUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/users'
    . '?select=id,student_id,first_name,middle_name,last_name,suffix,photo_url'
    . '&id=eq.' . rawurlencode($userId) . '&limit=1';
$userRes = supabase_request('GET', $userUrl, $headers);
$userRows = json_decode((string) ($userRes['body'] ?? ''), true);
$userRow = is_array($userRows) && isset($userRows[0]) ? $userRows[0] : null;
$participantName = '';
$participantPhoto = '';
$participantStudentNo = '';
if (is_array($userRow)) {
    $last = trim((string) ($userRow['last_name'] ?? ''));
    $first = trim((string) ($userRow['first_name'] ?? ''));
    $middle = trim((string) ($userRow['middle_name'] ?? ''));
    $suffix = trim((string) ($userRow['suffix'] ?? ''));
    $given = trim(implode(' ', array_values(array_filter(
        [$first, $middle],
        static fn($p) => $p !== ''
    ))));
    if ($last !== '' && $given !== '') {
        $participantName = $last . ', ' . $given;
    } elseif ($last !== '') {
        $participantName = $last;
    } else {
        $participantName = $given;
    }
    if ($suffix !== '' && $participantName !== '') {
        $participantName .= ' ' . $suffix;
    } elseif ($suffix !== '') {
        $participantName = $suffix;
    }
    $participantPhoto = trim((string) ($userRow['photo_url'] ?? ''));
    if (function_exists('storage_resolve_user_avatar_url')) {
        $signed = storage_resolve_user_avatar_url($userId, $participantPhoto, 14400);
        if ($signed !== '') {
            $participantPhoto = $signed;
        }
    } elseif ($participantPhoto !== '' && function_exists('storage_resolve_avatar_url')) {
        $signed = storage_resolve_avatar_url($participantPhoto, 14400);
        if ($signed !== '') {
            $participantPhoto = $signed;
        }
    }
    $participantStudentNo = trim((string) ($userRow['student_id'] ?? ''));
}

$regSelectCandidates = [
    'id,registered_at,student_id,event_id,'
        . 'events(id,title,status,start_at,end_at,location,event_mode,event_structure,grace_time,early_out_enabled_at),'
        . 'tickets(id,token,attendance(id,status,check_in_at,check_out_at,last_scanned_at))',
    'id,registered_at,student_id,event_id,'
        . 'events(id,title,status,start_at,end_at,location,event_mode,event_structure,grace_time),'
        . 'tickets(id,token,attendance(id,status,check_in_at,check_out_at,last_scanned_at))',
    'id,registered_at,student_id,event_id,'
        . 'events(id,title,status,start_at,end_at,location,grace_time),'
        . 'tickets(id,token,attendance(id,status,check_in_at,check_out_at))',
];

$regRows = [];
$regOk = false;
foreach ($regSelectCandidates as $select) {
    $url = rtrim(SUPABASE_URL, '/') . '/rest/v1/event_registrations'
        . '?select=' . rawurlencode($select)
        . '&student_id=eq.' . rawurlencode($userId)
        . '&order=registered_at.desc'
        . '&limit=80';
    $res = supabase_request('GET', $url, $headers);
    if (!($res['ok'] ?? false)) {
        continue;
    }
    $decoded = json_decode((string) ($res['body'] ?? ''), true);
    if (!is_array($decoded)) {
        $decoded = [];
    }
    $regRows = $decoded;
    $regOk = true;
    break;
}

if (!$regOk) {
    json_response(['ok' => false, 'error' => 'Failed to load self-attendance pack.', 'status' => 'error'], 500);
}

$eventIds = [];
foreach ($regRows as $row) {
    if (!is_array($row)) {
        continue;
    }
    $eid = trim((string) ($row['event_id'] ?? ''));
    if ($eid === '' && is_array($row['events'] ?? null)) {
        $eid = trim((string) ($row['events']['id'] ?? ''));
    }
    if ($eid !== '') {
        $eventIds[$eid] = true;
    }
}

$sessionsByEvent = fetch_event_sessions_map(array_keys($eventIds), $headers);

// Enrich sessions with early_out_enabled_at when available.
foreach ($sessionsByEvent as $eid => &$sessionList) {
    if (!is_array($sessionList) || empty($sessionList)) {
        continue;
    }
    $eoUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/event_sessions'
        . '?select=id,early_out_enabled_at&event_id=eq.' . rawurlencode((string) $eid);
    $eoRes = supabase_request('GET', $eoUrl, $headers);
    if (!($eoRes['ok'] ?? false)) {
        continue;
    }
    $eoRows = json_decode((string) ($eoRes['body'] ?? ''), true);
    $eoMap = [];
    if (is_array($eoRows)) {
        foreach ($eoRows as $eoRow) {
            if (!is_array($eoRow) || empty($eoRow['id'])) {
                continue;
            }
            $sid = (string) $eoRow['id'];
            $eoMap[$sid] = attendance_early_out_is_active((string) ($eoRow['early_out_enabled_at'] ?? ''), $now)
                ? (string) $eoRow['early_out_enabled_at']
                : null;
        }
    }
    foreach ($sessionList as &$sessionRow) {
        if (!is_array($sessionRow)) {
            continue;
        }
        $sid = (string) ($sessionRow['id'] ?? '');
        $sessionRow['early_out_enabled_at'] = $eoMap[$sid] ?? null;
    }
    unset($sessionRow);
}
unset($sessionList);

$packEvents = [];
foreach ($regRows as $row) {
    if (!is_array($row)) {
        continue;
    }
    $event = $row['events'] ?? null;
    if (!is_array($event)) {
        continue;
    }
    $eventId = trim((string) ($event['id'] ?? $row['event_id'] ?? ''));
    if ($eventId === '') {
        continue;
    }
    $eventStatus = strtolower(trim((string) ($event['status'] ?? '')));
    if (!in_array($eventStatus, ['published', 'approved', 'finished', 'expired'], true)) {
        continue;
    }

    $ticketsRaw = $row['tickets'] ?? null;
    $ticket = null;
    if (is_array($ticketsRaw)) {
        if (isset($ticketsRaw[0]) && is_array($ticketsRaw[0])) {
            $ticket = $ticketsRaw[0];
        } elseif (isset($ticketsRaw['id'])) {
            $ticket = $ticketsRaw;
        }
    }
    if (!is_array($ticket) || empty($ticket['id'])) {
        continue;
    }
    $ticketId = (string) $ticket['id'];

    $attendanceRaw = $ticket['attendance'] ?? null;
    $attendance = null;
    if (is_array($attendanceRaw)) {
        if (isset($attendanceRaw[0]) && is_array($attendanceRaw[0])) {
            $attendance = $attendanceRaw[0];
        } elseif (isset($attendanceRaw['id']) || isset($attendanceRaw['status'])) {
            $attendance = $attendanceRaw;
        }
    }

    $sessions = $sessionsByEvent[$eventId] ?? [];
    if (!is_array($sessions)) {
        $sessions = [];
    }
    $usesSessions = event_uses_sessions(array_merge($event, ['sessions' => $sessions]));

    $sessionAttendanceById = [];
    if ($usesSessions && $ticketId !== '') {
        $attUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/event_session_attendance'
            . '?select=id,session_id,status,check_in_at,check_out_at'
            . '&ticket_id=eq.' . rawurlencode($ticketId);
        $attRes = supabase_request('GET', $attUrl, $headers);
        if ($attRes['ok'] ?? false) {
            $attRows = json_decode((string) ($attRes['body'] ?? ''), true);
            if (is_array($attRows)) {
                foreach ($attRows as $attRow) {
                    if (!is_array($attRow) || empty($attRow['session_id'])) {
                        continue;
                    }
                    $sessionAttendanceById[(string) $attRow['session_id']] = [
                        'id' => (string) ($attRow['id'] ?? ''),
                        'session_id' => (string) ($attRow['session_id'] ?? ''),
                        'status' => (string) ($attRow['status'] ?? ''),
                        'check_in_at' => (string) ($attRow['check_in_at'] ?? ''),
                        'check_out_at' => (string) ($attRow['check_out_at'] ?? ''),
                    ];
                }
            }
        }
    }

    $slimSessions = [];
    foreach ($sessions as $session) {
        if (!is_array($session)) {
            continue;
        }
        $sid = trim((string) ($session['id'] ?? ''));
        if ($sid === '') {
            continue;
        }
        $slimSessions[] = [
            'id' => $sid,
            'title' => (string) ($session['title'] ?? ''),
            'topic' => isset($session['topic']) ? (string) $session['topic'] : '',
            'location' => isset($session['location']) ? (string) $session['location'] : '',
            'start_at' => (string) ($session['start_at'] ?? ''),
            'end_at' => (string) ($session['end_at'] ?? ''),
            'scan_window_minutes' => max(1, (int) ($session['scan_window_minutes'] ?? 30)),
            'early_out_enabled_at' => $session['early_out_enabled_at'] ?? null,
            'attendance' => $sessionAttendanceById[$sid] ?? null,
        ];
    }

    $eventEarlyOut = null;
    if (attendance_early_out_is_active((string) ($event['early_out_enabled_at'] ?? ''), $now)) {
        $eventEarlyOut = (string) $event['early_out_enabled_at'];
    }

    $packEvents[] = [
        'event_id' => $eventId,
        'title' => (string) ($event['title'] ?? 'Event'),
        'status' => $eventStatus,
        'start_at' => (string) ($event['start_at'] ?? ''),
        'end_at' => (string) ($event['end_at'] ?? ''),
        'location' => (string) ($event['location'] ?? ''),
        'event_mode' => (string) ($event['event_mode'] ?? ''),
        'event_structure' => (string) ($event['event_structure'] ?? ''),
        'grace_time' => simple_event_grace_minutes($event),
        'early_out_enabled_at' => $eventEarlyOut,
        'uses_sessions' => $usesSessions,
        'qr_payload' => 'PULSE-EVENT-' . $eventId,
        'registration_id' => (string) ($row['id'] ?? ''),
        'ticket_id' => $ticketId,
        'attendance' => is_array($attendance) ? [
            'id' => (string) ($attendance['id'] ?? ''),
            'status' => (string) ($attendance['status'] ?? ''),
            'check_in_at' => (string) ($attendance['check_in_at'] ?? ''),
            'check_out_at' => (string) ($attendance['check_out_at'] ?? ''),
            'last_scanned_at' => (string) ($attendance['last_scanned_at'] ?? ''),
        ] : null,
        'sessions' => $slimSessions,
        'participant_name' => $participantName,
        'participant_photo_url' => $participantPhoto,
        'participant_student_id' => $participantStudentNo !== '' ? $participantStudentNo : $userId,
        'participant_student_no' => $participantStudentNo,
    ];
}

json_response([
    'ok' => true,
    'synced_at' => $now->format(DATE_ATOM),
    'event_count' => count($packEvents),
    'events' => $packEvents,
], 200);
