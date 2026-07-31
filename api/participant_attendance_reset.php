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

$user = require_role(['admin']);
$data = require_post_json();
require_csrf_from_json($data);

$registrationId = isset($data['registration_id']) ? trim((string) $data['registration_id']) : '';
if ($registrationId === '') {
    json_response(['ok' => false, 'error' => 'registration_id required'], 400);
}

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
    'id,title,start_at,end_at,early_out_enabled_at,grace_time,status',
    'id,title,start_at,end_at,grace_time,status',
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

$nowUtc = new DateTimeImmutable('now', new DateTimeZone('UTC'));
if (function_exists('attendance_lazy_clear_early_out')) {
    try {
        attendance_lazy_clear_early_out(
            'events',
            $eventId,
            $event['early_out_enabled_at'] ?? null,
            $nowUtc,
            $readHeaders
        );
    } catch (Throwable $e) {
        // Non-fatal.
    }
}
if (function_exists('attendance_early_out_is_active')
    && !attendance_early_out_is_active((string) ($event['early_out_enabled_at'] ?? ''), $nowUtc)) {
    $event['early_out_enabled_at'] = null;
}

$isTimeoutPhase = participant_reset_is_timeout_phase($event, $nowUtc);
$resetTarget = $isTimeoutPhase ? 'time_out' : 'time_in';

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

if ($isTimeoutPhase) {
    // Time-out phase (Early Out ON, or event already ended): clear time-out only.
    $patchPayload = [
        'check_out_at' => null,
        'updated_at' => $nowIso,
    ];
    $patchUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/attendance?ticket_id=eq.' . rawurlencode($ticketId)
        . '&select=id,ticket_id,status,check_in_at,check_out_at,last_scanned_at,last_scanned_by';
    $patchRes = supabase_request('PATCH', $patchUrl, $writeHeaders, json_encode($patchPayload, JSON_UNESCAPED_SLASHES));
    if (!$patchRes['ok']) {
        json_response([
            'ok' => false,
            'error' => build_error($patchRes['body'] ?? null, (int) ($patchRes['status'] ?? 0), $patchRes['error'] ?? null, 'Time-out reset failed'),
        ], 500);
    }

    $patchedRows = json_decode((string) $patchRes['body'], true);
    $attendance = is_array($patchedRows) && isset($patchedRows[0]) && is_array($patchedRows[0]) ? $patchedRows[0] : null;
    if (!is_array($attendance)) {
        json_response(['ok' => false, 'error' => 'No attendance record to reset for time-out'], 404);
    }

    // Seminar rows: clear check_out_at when column exists.
    $sessionPatchUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/event_session_attendance'
        . '?registration_id=eq.' . rawurlencode($registrationId);
    supabase_request(
        'PATCH',
        $sessionPatchUrl,
        $writeHeaders,
        json_encode(['check_out_at' => null, 'updated_at' => $nowIso], JSON_UNESCAPED_SLASHES)
    );

    json_response([
        'ok' => true,
        'reset_target' => $resetTarget,
        'message' => 'Time-out cleared. Student remains checked in and can be timed out again.',
        'attendance' => $attendance,
    ], 200);
}

// Time-in phase (event not ended, Early Out off): clear time-in (and any leftover time-out).
$patchPayload = [
    'status' => 'unscanned',
    'check_in_at' => null,
    'check_out_at' => null,
    'last_scanned_at' => null,
    'last_scanned_by' => null,
    'updated_at' => $nowIso,
];

$patchUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/attendance?ticket_id=eq.' . rawurlencode($ticketId)
    . '&select=id,ticket_id,status,check_in_at,check_out_at,last_scanned_at,last_scanned_by';
$patchRes = supabase_request('PATCH', $patchUrl, $writeHeaders, json_encode($patchPayload, JSON_UNESCAPED_SLASHES));
if (!$patchRes['ok']) {
    unset($patchPayload['check_out_at']);
    $patchUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/attendance?ticket_id=eq.' . rawurlencode($ticketId)
        . '&select=id,ticket_id,status,check_in_at,last_scanned_at,last_scanned_by';
    $patchRes = supabase_request('PATCH', $patchUrl, $writeHeaders, json_encode($patchPayload, JSON_UNESCAPED_SLASHES));
}
if (!$patchRes['ok']) {
    json_response([
        'ok' => false,
        'error' => build_error($patchRes['body'] ?? null, (int) ($patchRes['status'] ?? 0), $patchRes['error'] ?? null, 'Attendance reset failed'),
    ], 500);
}

$patchedRows = json_decode((string) $patchRes['body'], true);
$attendance = is_array($patchedRows) && isset($patchedRows[0]) && is_array($patchedRows[0]) ? $patchedRows[0] : null;

if (!is_array($attendance)) {
    $createPayload = [[
        'ticket_id' => $ticketId,
        'status' => 'unscanned',
        'check_in_at' => null,
        'check_out_at' => null,
        'last_scanned_at' => null,
        'last_scanned_by' => null,
    ]];
    $createUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/attendance?select=id,ticket_id,status,check_in_at,check_out_at,last_scanned_at,last_scanned_by';
    $createRes = supabase_request('POST', $createUrl, $writeHeaders, json_encode($createPayload, JSON_UNESCAPED_SLASHES));
    if (!$createRes['ok']) {
        $createPayload = [[
            'ticket_id' => $ticketId,
            'status' => 'unscanned',
            'check_in_at' => null,
            'last_scanned_at' => null,
            'last_scanned_by' => null,
        ]];
        $createUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/attendance?select=id,ticket_id,status,check_in_at,last_scanned_at,last_scanned_by';
        $createRes = supabase_request('POST', $createUrl, $writeHeaders, json_encode($createPayload, JSON_UNESCAPED_SLASHES));
    }
    if (!$createRes['ok']) {
        json_response([
            'ok' => false,
            'error' => build_error($createRes['body'] ?? null, (int) ($createRes['status'] ?? 0), $createRes['error'] ?? null, 'Attendance reset failed'),
        ], 500);
    }

    $createdRows = json_decode((string) $createRes['body'], true);
    $attendance = is_array($createdRows) && isset($createdRows[0]) && is_array($createdRows[0]) ? $createdRows[0] : null;
}

if (!is_array($attendance)) {
    json_response(['ok' => false, 'error' => 'Attendance reset failed'], 500);
}

$sessionResetUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/event_session_attendance'
    . '?registration_id=eq.' . rawurlencode($registrationId);
supabase_request('DELETE', $sessionResetUrl, $readHeaders);

json_response([
    'ok' => true,
    'reset_target' => $resetTarget,
    'message' => 'Time-in cleared. Student can be checked in again.',
    'attendance' => $attendance,
], 200);
