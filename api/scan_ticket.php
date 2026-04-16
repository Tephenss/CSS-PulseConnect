<?php
declare(strict_types=1);

session_start();

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/supabase.php';
require_once __DIR__ . '/../includes/json.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/event_sessions.php';
require_once __DIR__ . '/../includes/scan_context.php';

$user = require_role(['teacher', 'admin']);
$data = require_post_json();
require_csrf_from_json($data);

$token = isset($data['token']) ? (string) $data['token'] : '';

if ($token === '' || !preg_match('/^[a-f0-9]{32}$/', $token)) {
    json_response(['ok' => false, 'error' => 'Invalid Ticket'], 400);
}

$headers = [
    'Accept: application/json',
    'apikey: ' . SUPABASE_KEY,
    'Authorization: Bearer ' . SUPABASE_KEY,
];

// Resolve ticket
$ticketUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/tickets'
    . '?select=id,token,registration_id,event_registrations(event_id),event_registrations(events(start_at,end_at))'
    . '&token=eq.' . rawurlencode($token)
    . '&limit=1';

$ticketRes = supabase_request('GET', $ticketUrl, $headers);
if (!$ticketRes['ok']) {
    json_response(['ok' => false, 'error' => build_error($ticketRes['body'] ?? null, (int) ($ticketRes['status'] ?? 0), $ticketRes['error'] ?? null, 'Ticket lookup failed')], 500);
}

$ticketRows = json_decode((string) $ticketRes['body'], true);
$ticket = is_array($ticketRows) && isset($ticketRows[0]) ? $ticketRows[0] : null;
if (!is_array($ticket) || empty($ticket['id'])) {
    json_response(['ok' => false, 'error' => 'Invalid Ticket'], 404);
}

$ticketId = (string) $ticket['id'];
$registrationId = (string) ($ticket['registration_id'] ?? '');
$eventId = '';
if (isset($ticket['event_registrations']) && is_array($ticket['event_registrations'])) {
    $eventId = (string) ($ticket['event_registrations']['event_id'] ?? '');
}
if ($registrationId === '' || $eventId === '') {
    json_response(['ok' => false, 'error' => 'Invalid Ticket'], 409);
}

$nowIso = gmdate('c');
$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

// Load event and validate scanner access.
$eventUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/events?select=id,title,status,start_at,end_at,location,event_mode,event_structure'
    . '&id=eq.' . rawurlencode($eventId)
    . '&limit=1';
$eventRes = supabase_request('GET', $eventUrl, $headers);
$eventRows = $eventRes['ok'] ? json_decode((string) $eventRes['body'], true) : [];
$event = is_array($eventRows) && isset($eventRows[0]) ? $eventRows[0] : null;
if (!is_array($event)) {
    json_response(['ok' => false, 'error' => 'Event lookup failed'], 500);
}

if (strtolower((string) ($event['status'] ?? 'draft')) !== 'published') {
    json_response(['ok' => false, 'error' => 'Scanning is only allowed for published events'], 409);
}

$role = (string) ($user['role'] ?? 'teacher');
if ($role === 'teacher' && !teacher_can_scan_event((string) ($user['id'] ?? ''), $eventId, $headers)) {
    json_response(['ok' => false, 'error' => 'You are not assigned to scan this event'], 403);
}

$scanContext = resolve_event_scan_context($event, $now, $headers);
$scanStatus = (string) ($scanContext['status'] ?? 'closed');
if ($scanStatus !== 'open') {
    $statusMessage = match ($scanStatus) {
        'waiting' => 'Scanning has not opened yet for this schedule.',
        'closed' => 'Scanning window is closed for this schedule.',
        'missing_schedule' => 'No valid schedule found for scanning.',
        'conflict' => 'Schedule conflict detected. Contact admin.',
        default => 'Scanning is unavailable right now.',
    };
    json_response(['ok' => false, 'error' => $statusMessage], 409);
}

if ((string) ($scanContext['source'] ?? '') === 'session') {
    $sessionContext = is_array($scanContext['session'] ?? null) ? $scanContext['session'] : [];
    $sessionId = (string) ($sessionContext['id'] ?? '');
    if ($sessionId === '') {
        json_response(['ok' => false, 'error' => 'Seminar lookup failed'], 500);
    }

    $sessionAttendanceUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/event_session_attendance?select=id,check_in_at,last_scanned_at'
        . '&session_id=eq.' . rawurlencode($sessionId)
        . '&ticket_id=eq.' . rawurlencode($ticketId)
        . '&limit=1';
    $sessionAttendanceRes = supabase_request('GET', $sessionAttendanceUrl, $headers);
    if (!$sessionAttendanceRes['ok']) {
        json_response(['ok' => false, 'error' => build_error($sessionAttendanceRes['body'] ?? null, (int) ($sessionAttendanceRes['status'] ?? 0), $sessionAttendanceRes['error'] ?? null, 'Seminar attendance lookup failed')], 500);
    }
    $sessionAttendanceRows = json_decode((string) $sessionAttendanceRes['body'], true);
    $existingSessionAttendance = is_array($sessionAttendanceRows) && isset($sessionAttendanceRows[0]) ? $sessionAttendanceRows[0] : null;
    if (is_array($existingSessionAttendance) && !empty($existingSessionAttendance['id'])) {
        json_response(['ok' => false, 'error' => 'This ticket is already recorded for the active seminar'], 409);
    }

    $payload = [[
        'session_id' => $sessionId,
        'registration_id' => $registrationId,
        'ticket_id' => $ticketId,
        'status' => 'present',
        'check_in_at' => $nowIso,
        'last_scanned_by' => (string) ($user['id'] ?? ''),
        'last_scanned_at' => $nowIso,
        'updated_at' => $nowIso,
    ]];
    $writeHeaders = [
        'Content-Type: application/json',
        'Accept: application/json',
        'apikey: ' . SUPABASE_KEY,
        'Authorization: Bearer ' . SUPABASE_KEY,
        'Prefer: return=representation',
    ];
    $writeUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/event_session_attendance?select=id,status,check_in_at,last_scanned_at';
    $writeRes = supabase_request('POST', $writeUrl, $writeHeaders, json_encode($payload, JSON_UNESCAPED_SLASHES));
    if (!$writeRes['ok']) {
        json_response(['ok' => false, 'error' => build_error($writeRes['body'] ?? null, (int) ($writeRes['status'] ?? 0), $writeRes['error'] ?? null, 'Seminar scan update failed')], 500);
    }

    $writeRows = json_decode((string) $writeRes['body'], true);
    $attendance = is_array($writeRows) && isset($writeRows[0]) ? $writeRows[0] : null;
    $sessionName = trim((string) ($sessionContext['display_name'] ?? $sessionContext['title'] ?? 'Seminar'));
    $message = 'Checked in for ' . ($sessionName !== '' ? $sessionName : 'Seminar');
    json_response(['ok' => true, 'message' => $message, 'attendance' => $attendance], 200);
}

// Load attendance row
$attUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/attendance?select=id,check_in_at,status,last_scanned_at'
    . '&ticket_id=eq.' . rawurlencode($ticketId)
    . '&limit=1';
$attRes = supabase_request('GET', $attUrl, $headers);
$attRows = $attRes['ok'] ? json_decode((string) $attRes['body'], true) : null;
$att = is_array($attRows) && isset($attRows[0]) ? $attRows[0] : null;
if (!is_array($att) || empty($att['id'])) {
    json_response(['ok' => false, 'error' => 'Attendance missing'], 500);
}

$attId = (string) $att['id'];

// Cooldown for repeated scans
$lastScannedAt = isset($att['last_scanned_at']) ? (string) $att['last_scanned_at'] : '';
if ($lastScannedAt !== '') {
    try {
        $last = new DateTimeImmutable($lastScannedAt);
        if ($now->getTimestamp() - $last->getTimestamp() < 10) {
            json_response(['ok' => false, 'error' => 'Ticket already scanned recently. Try again later.'], 409);
        }
    } catch (Throwable $e) {
        // ignore invalid date
    }
}

$update = [
    'last_scanned_by' => (string) ($user['id'] ?? ''),
    'last_scanned_at' => $nowIso,
    'updated_at' => $nowIso,
];

if (!empty($att['check_in_at'])) {
    json_response(['ok' => false, 'error' => 'Ticket already scanned recently. Try again later.'], 409);
}

$update['check_in_at'] = $nowIso;
$update['status'] = 'present';
$message = 'Checked in for ' . ((string) ($event['title'] ?? 'event'));

$patchUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/attendance?id=eq.' . rawurlencode($attId) . '&select=id,status,check_in_at,last_scanned_at';
$patchHeaders = [
    'Content-Type: application/json',
    'Accept: application/json',
    'apikey: ' . SUPABASE_KEY,
    'Authorization: Bearer ' . SUPABASE_KEY,
    'Prefer: return=representation',
];

$patchRes = supabase_request('PATCH', $patchUrl, $patchHeaders, json_encode($update, JSON_UNESCAPED_SLASHES));
if (!$patchRes['ok']) {
    json_response(['ok' => false, 'error' => build_error($patchRes['body'] ?? null, (int) ($patchRes['status'] ?? 0), $patchRes['error'] ?? null, 'Scan update failed')], 500);
}

$rows = json_decode((string) $patchRes['body'], true);
$updated = is_array($rows) && isset($rows[0]) ? $rows[0] : null;

json_response(['ok' => true, 'message' => $message, 'attendance' => $updated], 200);

