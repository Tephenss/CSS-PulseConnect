<?php
declare(strict_types=1);

session_start();

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/supabase.php';
require_once __DIR__ . '/../includes/json.php';
require_once __DIR__ . '/../includes/csrf.php';

$user = require_role(['teacher', 'admin']);
$data = require_post_json();
require_csrf_from_json($data);

$token = isset($data['token']) ? (string) $data['token'] : '';
$action = isset($data['action']) ? (string) $data['action'] : 'check_in'; // check_in|check_out

if ($token === '' || !preg_match('/^[a-f0-9]{32}$/', $token)) {
    json_response(['ok' => false, 'error' => 'Invalid Ticket'], 400);
}
if (!in_array($action, ['check_in', 'check_out'], true)) {
    json_response(['ok' => false, 'error' => 'Invalid action'], 400);
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
$eventId = '';
if (isset($ticket['event_registrations']) && is_array($ticket['event_registrations'])) {
    $eventId = (string) ($ticket['event_registrations']['event_id'] ?? '');
}

// Load attendance row
$attUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/attendance?select=id,check_in_at,check_out_at,status,last_scanned_at'
    . '&ticket_id=eq.' . rawurlencode($ticketId)
    . '&limit=1';
$attRes = supabase_request('GET', $attUrl, $headers);
$attRows = $attRes['ok'] ? json_decode((string) $attRes['body'], true) : null;
$att = is_array($attRows) && isset($attRows[0]) ? $attRows[0] : null;
if (!is_array($att) || empty($att['id'])) {
    json_response(['ok' => false, 'error' => 'Attendance missing'], 500);
}

$attId = (string) $att['id'];
$nowIso = gmdate('c');
$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

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

// Load event start/end (for early/late checks)
$eventUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/events?select=id,start_at,end_at'
    . '&id=eq.' . rawurlencode($eventId)
    . '&limit=1';
$eventRes = supabase_request('GET', $eventUrl, $headers);
$eventRows = $eventRes['ok'] ? json_decode((string) $eventRes['body'], true) : null;
$event = is_array($eventRows) && isset($eventRows[0]) ? $eventRows[0] : null;
if (!is_array($event)) {
    json_response(['ok' => false, 'error' => 'Event lookup failed'], 500);
}

$startAt = isset($event['start_at']) ? new DateTimeImmutable((string) $event['start_at']) : null;
$endAt = isset($event['end_at']) ? new DateTimeImmutable((string) $event['end_at']) : null;

$update = [
    'last_scanned_by' => (string) ($user['id'] ?? ''),
    'last_scanned_at' => $nowIso,
    'updated_at' => $nowIso,
];

if ($action === 'check_in') {
    if (!empty($att['check_in_at'])) {
        json_response(['ok' => false, 'error' => 'Ticket already scanned recently. Try again later.'], 409);
    }
    if ($startAt && $now < $startAt) {
        // Mark early but keep status as early
        $update['check_in_at'] = $nowIso;
        $update['status'] = 'early';
        $message = 'The event has not started yet.';
    } else {
        $update['check_in_at'] = $nowIso;
        $lateThreshold = $startAt ? $startAt->modify('+15 minutes') : null;
        $update['status'] = ($lateThreshold && $now > $lateThreshold) ? 'late' : 'present';
        $message = 'Checked in at ' . $now->format('Y-m-d H:i') . (($update['status'] === 'late') ? ' (Arrived late)' : '');
    }
} else {
    if (empty($att['check_in_at'])) {
        json_response(['ok' => false, 'error' => 'Not checked in yet'], 409);
    }
    if (!empty($att['check_out_at'])) {
        json_response(['ok' => false, 'error' => 'Ticket already scanned recently. Try again later.'], 409);
    }
    $update['check_out_at'] = $nowIso;
    $attendanceStatus = (string) ($att['status'] ?? 'present');
    $attendanceLabel = $attendanceStatus === 'late' ? 'Late' : 'Present';
    $message = 'Checked out at ' . $now->format('Y-m-d H:i') . ' (Attendance: ' . $attendanceLabel . ')';
    // Keep existing status (present/late/early)
}

$patchUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/attendance?id=eq.' . rawurlencode($attId) . '&select=id,status,check_in_at,check_out_at,last_scanned_at';
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

