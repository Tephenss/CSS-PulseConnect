<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/session.php';
session_bootstrap();

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/supabase.php';
require_once __DIR__ . '/../includes/json.php';
require_once __DIR__ . '/../includes/csrf.php';

$attendanceWindowsPath = __DIR__ . '/../includes/event_attendance_windows.php';
if (is_file($attendanceWindowsPath)) {
    require_once $attendanceWindowsPath;
}
$scanContextPath = __DIR__ . '/../includes/scan_context.php';
if (is_file($scanContextPath)) {
    require_once $scanContextPath;
}

$user = require_role(['admin', 'teacher']);
$data = require_post_json();
require_csrf_from_json($data);

$registrationId = isset($data['registration_id']) ? trim((string) $data['registration_id']) : '';
if ($registrationId === '') {
    json_response(['ok' => false, 'error' => 'registration_id required'], 400);
}

$role = strtolower(trim((string) ($user['role'] ?? '')));
$userId = trim((string) ($user['id'] ?? ''));

$readHeaders = [
    'Accept: application/json',
    'apikey: ' . SUPABASE_KEY,
    'Authorization: Bearer ' . SUPABASE_KEY,
];

$writeHeaders = [
    'Content-Type: application/json',
    'Accept: application/json',
    'apikey: ' . SUPABASE_KEY,
    'Authorization: Bearer ' . SUPABASE_KEY,
    'Prefer: return=representation',
];

/**
 * Time-out phase = Early Out is active, OR the event scheduled end has passed.
 * Otherwise = time-in phase.
 *
 * @param array<string,mixed> $event
 */
function participant_reset_is_timeout_phase(array $event, DateTimeImmutable $nowUtc): bool
{
    $earlyOutRaw = isset($event['early_out_enabled_at']) ? (string) $event['early_out_enabled_at'] : '';
    if (function_exists('attendance_early_out_is_active')
        && attendance_early_out_is_active($earlyOutRaw, $nowUtc)) {
        return true;
    }

    $endRaw = trim((string) ($event['end_at'] ?? ''));
    if ($endRaw === '') {
        return false;
    }
    $endAt = function_exists('parse_iso_datetime')
        ? parse_iso_datetime($endRaw)
        : null;
    if (!$endAt instanceof DateTimeImmutable) {
        try {
            $endAt = new DateTimeImmutable($endRaw);
        } catch (Throwable $e) {
            return false;
        }
    }
    return $nowUtc >= $endAt->setTimezone(new DateTimeZone('UTC'));
}

$regUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/event_registrations?select=id,event_id'
    . '&id=eq.' . rawurlencode($registrationId)
    . '&limit=1';
$regRes = supabase_request('GET', $regUrl, $readHeaders);
if (!$regRes['ok']) {
    json_response([
        'ok' => false,
        'error' => build_error($regRes['body'] ?? null, (int) ($regRes['status'] ?? 0), $regRes['error'] ?? null, 'Registration lookup failed'),
    ], 500);
}
$regRows = json_decode((string) $regRes['body'], true);
$registration = is_array($regRows) && isset($regRows[0]) && is_array($regRows[0]) ? $regRows[0] : null;
if (!is_array($registration) || empty($registration['event_id'])) {
    json_response(['ok' => false, 'error' => 'Registration not found'], 404);
}
$eventId = (string) $registration['event_id'];

$eventSelectCandidates = [
    'id,title,start_at,end_at,early_out_enabled_at,grace_time,status,created_by',
    'id,title,start_at,end_at,grace_time,status,created_by',
];
$event = null;
$lastEventRes = null;
foreach ($eventSelectCandidates as $select) {
    $eventUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/events?select=' . rawurlencode($select)
        . '&id=eq.' . rawurlencode($eventId) . '&limit=1';
    $lastEventRes = supabase_request('GET', $eventUrl, $readHeaders);
    if (!($lastEventRes['ok'] ?? false)) {
        continue;
    }
    $eventRows = json_decode((string) ($lastEventRes['body'] ?? ''), true);
    if (is_array($eventRows) && isset($eventRows[0]) && is_array($eventRows[0])) {
        $event = $eventRows[0];
        break;
    }
}
if (!is_array($event)) {
    json_response([
        'ok' => false,
        'error' => build_error($lastEventRes['body'] ?? null, (int) ($lastEventRes['status'] ?? 0), $lastEventRes['error'] ?? null, 'Event lookup failed'),
    ], 500);
}

$isCreator = $userId !== '' && (string) ($event['created_by'] ?? '') === $userId;
if ($role !== 'admin' && !($role === 'teacher' && $isCreator)) {
    json_response(['ok' => false, 'error' => 'Only the event creator or an admin can reset attendance.'], 403);
}

$nowUtc = new DateTimeImmutable('now', new DateTimeZone('UTC'));
$requestedSessionId = trim((string) ($data['session_id'] ?? ''));

$eventSessionsPath = __DIR__ . '/../includes/event_sessions.php';
if (is_file($eventSessionsPath)) {
    require_once $eventSessionsPath;
}
$sessions = function_exists('fetch_event_sessions')
    ? fetch_event_sessions($eventId, $readHeaders)
    : [];
$usesSessions = is_array($sessions) && count($sessions) > 0;
$sessionIds = [];
foreach ($sessions as $sessionRow) {
    if (!is_array($sessionRow)) {
        continue;
    }
    $sid = trim((string) ($sessionRow['id'] ?? ''));
    if ($sid !== '') {
        $sessionIds[$sid] = true;
    }
}

$rowHasTimeIn = static function (?array $row): bool {
    if (!is_array($row)) {
        return false;
    }
    if (function_exists('attendance_has_valid_time_in')) {
        return attendance_has_valid_time_in($row);
    }
    $status = strtolower(trim((string) ($row['status'] ?? '')));
    if ($status === 'absent') {
        return false;
    }
    if (trim((string) ($row['check_in_at'] ?? '')) !== '') {
        return true;
    }
    return in_array($status, ['present', 'checked_in', 'in', 'scanned', 'late', 'early'], true);
};

$rowHasTimeOut = static function (?array $row): bool {
    return is_array($row) && trim((string) ($row['check_out_at'] ?? '')) !== '';
};

$ticketUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/tickets?select=id,registration_id'
    . '&registration_id=eq.' . rawurlencode($registrationId)
    . '&limit=1';
$ticketRes = supabase_request('GET', $ticketUrl, $readHeaders);
if (!$ticketRes['ok']) {
    json_response([
        'ok' => false,
        'error' => build_error($ticketRes['body'] ?? null, (int) ($ticketRes['status'] ?? 0), $ticketRes['error'] ?? null, 'Ticket lookup failed'),
    ], 500);
}

$ticketRows = json_decode((string) $ticketRes['body'], true);
$ticket = is_array($ticketRows) && isset($ticketRows[0]) && is_array($ticketRows[0]) ? $ticketRows[0] : null;
if (!is_array($ticket) || empty($ticket['id'])) {
    json_response(['ok' => false, 'error' => 'Ticket not found for this participant'], 404);
}

$ticketId = (string) $ticket['id'];
$nowIso = $nowUtc->format('c');

if ($usesSessions) {
    if ($requestedSessionId !== '' && !isset($sessionIds[$requestedSessionId])) {
        json_response(['ok' => false, 'error' => 'Seminar does not belong to this event.'], 400);
    }

    $sessionAttUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/event_session_attendance'
        . '?select=id,session_id,registration_id,ticket_id,status,check_in_at,check_out_at,updated_at'
        . '&registration_id=eq.' . rawurlencode($registrationId);
    $sessionAttRes = supabase_request('GET', $sessionAttUrl, $readHeaders);
    $sessionAttRows = json_decode((string) ($sessionAttRes['body'] ?? ''), true);
    if (!is_array($sessionAttRows)) {
        $sessionAttRows = [];
    }

    $target = null;
    foreach ($sessionAttRows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $sid = trim((string) ($row['session_id'] ?? ''));
        if ($sid === '' || !isset($sessionIds[$sid])) {
            continue;
        }
        if ($requestedSessionId !== '' && $sid !== $requestedSessionId) {
            continue;
        }
        if ($target === null) {
            $target = $row;
            continue;
        }
        $targetOut = $rowHasTimeOut($target);
        $rowOut = $rowHasTimeOut($row);
        if ($rowOut && !$targetOut) {
            $target = $row;
            continue;
        }
        if ($rowOut === $targetOut) {
            $rowUpdated = trim((string) ($row['updated_at'] ?? ''));
            $targetUpdated = trim((string) ($target['updated_at'] ?? ''));
            if ($rowUpdated !== '' && $rowUpdated > $targetUpdated) {
                $target = $row;
            }
        }
    }

    if (!is_array($target) || empty($target['id'])) {
        json_response(['ok' => false, 'error' => 'No seminar attendance to reset for this participant.'], 404);
    }

    $targetId = (string) $target['id'];
    $targetSessionId = trim((string) ($target['session_id'] ?? ''));
    $resetOut = $rowHasTimeOut($target);

    if ($resetOut) {
        $patchUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/event_session_attendance?id=eq.' . rawurlencode($targetId)
            . '&select=id,session_id,status,check_in_at,check_out_at';
        $patchRes = supabase_request(
            'PATCH',
            $patchUrl,
            $writeHeaders,
            json_encode(['check_out_at' => null, 'updated_at' => $nowIso], JSON_UNESCAPED_SLASHES)
        );
        if (!$patchRes['ok']) {
            json_response([
                'ok' => false,
                'error' => build_error($patchRes['body'] ?? null, (int) ($patchRes['status'] ?? 0), $patchRes['error'] ?? null, 'Time-out reset failed'),
            ], 500);
        }
        $patchedRows = json_decode((string) $patchRes['body'], true);
        json_response([
            'ok' => true,
            'reset_target' => 'time_out',
            'session_id' => $targetSessionId,
            'message' => 'Time-out cleared. Time-in is kept — reset again to clear time-in.',
            'attendance' => is_array($patchedRows) && isset($patchedRows[0]) ? $patchedRows[0] : $target,
        ], 200);
    }

    $deleteUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/event_session_attendance?id=eq.' . rawurlencode($targetId);
    $deleteRes = supabase_request('DELETE', $deleteUrl, $readHeaders);
    if (!$deleteRes['ok']) {
        json_response([
            'ok' => false,
            'error' => build_error($deleteRes['body'] ?? null, (int) ($deleteRes['status'] ?? 0), $deleteRes['error'] ?? null, 'Time-in reset failed'),
        ], 500);
    }

    $remainingOut = false;
    $remainingIn = false;
    foreach ($sessionAttRows as $row) {
        if (!is_array($row) || (string) ($row['id'] ?? '') === $targetId) {
            continue;
        }
        if ($rowHasTimeOut($row)) {
            $remainingOut = true;
        }
        if ($rowHasTimeIn($row)) {
            $remainingIn = true;
        }
    }

    // Keep ticket-level attendance aligned only when no other seminar still has a scan.
    if (!$remainingIn) {
        $ticketPatch = [
            'status' => 'unscanned',
            'check_in_at' => null,
            'check_out_at' => null,
            'last_scanned_at' => null,
            'last_scanned_by' => null,
            'updated_at' => $nowIso,
        ];
        supabase_request(
            'PATCH',
            rtrim(SUPABASE_URL, '/') . '/rest/v1/attendance?ticket_id=eq.' . rawurlencode($ticketId),
            $writeHeaders,
            json_encode($ticketPatch, JSON_UNESCAPED_SLASHES)
        );
    } elseif (!$remainingOut) {
        supabase_request(
            'PATCH',
            rtrim(SUPABASE_URL, '/') . '/rest/v1/attendance?ticket_id=eq.' . rawurlencode($ticketId),
            $writeHeaders,
            json_encode(['check_out_at' => null, 'updated_at' => $nowIso], JSON_UNESCAPED_SLASHES)
        );
    }

    json_response([
        'ok' => true,
        'reset_target' => 'time_in',
        'session_id' => $targetSessionId,
        'message' => 'Time-in cleared for this seminar. Other seminars are unchanged.',
        'attendance' => null,
    ], 200);
}

$attUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/attendance'
    . '?select=id,ticket_id,status,check_in_at,check_out_at,last_scanned_at,last_scanned_by'
    . '&ticket_id=eq.' . rawurlencode($ticketId)
    . '&limit=1';
$attRes = supabase_request('GET', $attUrl, $readHeaders);
$attRows = json_decode((string) ($attRes['body'] ?? ''), true);
$attendance = is_array($attRows) && isset($attRows[0]) && is_array($attRows[0]) ? $attRows[0] : null;
if (!is_array($attendance)) {
    json_response(['ok' => false, 'error' => 'No attendance record to reset'], 404);
}

if ($rowHasTimeOut($attendance)) {
    $patchUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/attendance?id=eq.' . rawurlencode((string) $attendance['id'])
        . '&select=id,ticket_id,status,check_in_at,check_out_at,last_scanned_at,last_scanned_by';
    $patchRes = supabase_request(
        'PATCH',
        $patchUrl,
        $writeHeaders,
        json_encode(['check_out_at' => null, 'updated_at' => $nowIso], JSON_UNESCAPED_SLASHES)
    );
    if (!$patchRes['ok']) {
        json_response([
            'ok' => false,
            'error' => build_error($patchRes['body'] ?? null, (int) ($patchRes['status'] ?? 0), $patchRes['error'] ?? null, 'Time-out reset failed'),
        ], 500);
    }
    $patchedRows = json_decode((string) $patchRes['body'], true);
    json_response([
        'ok' => true,
        'reset_target' => 'time_out',
        'message' => 'Time-out cleared. Time-in is kept — reset again to clear time-in.',
        'attendance' => is_array($patchedRows) && isset($patchedRows[0]) ? $patchedRows[0] : $attendance,
    ], 200);
}

$patchPayload = [
    'status' => 'unscanned',
    'check_in_at' => null,
    'check_out_at' => null,
    'last_scanned_at' => null,
    'last_scanned_by' => null,
    'updated_at' => $nowIso,
];
$patchUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/attendance?id=eq.' . rawurlencode((string) $attendance['id'])
    . '&select=id,ticket_id,status,check_in_at,check_out_at,last_scanned_at,last_scanned_by';
$patchRes = supabase_request('PATCH', $patchUrl, $writeHeaders, json_encode($patchPayload, JSON_UNESCAPED_SLASHES));
if (!$patchRes['ok']) {
    unset($patchPayload['check_out_at']);
    $patchRes = supabase_request('PATCH', $patchUrl, $writeHeaders, json_encode($patchPayload, JSON_UNESCAPED_SLASHES));
}
if (!$patchRes['ok']) {
    json_response([
        'ok' => false,
        'error' => build_error($patchRes['body'] ?? null, (int) ($patchRes['status'] ?? 0), $patchRes['error'] ?? null, 'Time-in reset failed'),
    ], 500);
}
$patchedRows = json_decode((string) $patchRes['body'], true);

json_response([
    'ok' => true,
    'reset_target' => 'time_in',
    'message' => 'Time-in cleared. Student can be timed in again.',
    'attendance' => is_array($patchedRows) && isset($patchedRows[0]) ? $patchedRows[0] : $attendance,
], 200);
