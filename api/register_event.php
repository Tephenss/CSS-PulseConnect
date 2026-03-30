<?php
declare(strict_types=1);

session_start();

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/supabase.php';
require_once __DIR__ . '/../includes/json.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/csrf.php';

$user = require_role(['student']);
$data = require_post_json();
require_csrf_from_json($data);

$eventId = isset($data['event_id']) ? (string) $data['event_id'] : '';
if ($eventId === '') {
    json_response(['ok' => false, 'error' => 'event_id required'], 400);
}

// Create registration.
$regPayload = [
    'event_id' => $eventId,
    'student_id' => (string) ($user['id'] ?? ''),
];

$regUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/event_registrations?select=id,event_id,student_id';
$headers = [
    'Content-Type: application/json',
    'Accept: application/json',
    'apikey: ' . SUPABASE_KEY,
    'Authorization: Bearer ' . SUPABASE_KEY,
    'Prefer: return=representation',
];

$regRes = supabase_request('POST', $regUrl, $headers, json_encode([$regPayload], JSON_UNESCAPED_SLASHES));
if (!$regRes['ok']) {
    json_response(['ok' => false, 'error' => build_error($regRes['body'] ?? null, (int) ($regRes['status'] ?? 0), $regRes['error'] ?? null, 'Registration failed')], 500);
}

$regRows = json_decode((string) $regRes['body'], true);
$reg = is_array($regRows) && isset($regRows[0]) ? $regRows[0] : null;
if (!is_array($reg) || empty($reg['id'])) {
    json_response(['ok' => false, 'error' => 'Registration failed'], 500);
}

// Create ticket token and attendance row.
$token = bin2hex(random_bytes(16)); // 32 hex chars
$ticketPayload = [
    'registration_id' => (string) ($reg['id'] ?? ''),
    'token' => $token,
];
$ticketUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/tickets?select=id,token,registration_id';

$ticketRes = supabase_request('POST', $ticketUrl, $headers, json_encode([$ticketPayload], JSON_UNESCAPED_SLASHES));
if (!$ticketRes['ok']) {
    json_response(['ok' => false, 'error' => build_error($ticketRes['body'] ?? null, (int) ($ticketRes['status'] ?? 0), $ticketRes['error'] ?? null, 'Ticket issue failed')], 500);
}

$ticketRows = json_decode((string) $ticketRes['body'], true);
$ticket = is_array($ticketRows) && isset($ticketRows[0]) ? $ticketRows[0] : null;
if (!is_array($ticket) || empty($ticket['id'])) {
    json_response(['ok' => false, 'error' => 'Ticket issue failed'], 500);
}

$attendancePayload = [
    'ticket_id' => (string) ($ticket['id'] ?? ''),
    'status' => 'unscanned',
];
$attUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/attendance?select=id,status';
supabase_request('POST', $attUrl, $headers, json_encode([$attendancePayload], JSON_UNESCAPED_SLASHES));

json_response(['ok' => true, 'ticket' => $ticket], 200);

