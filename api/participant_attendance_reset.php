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

$registrationId = isset($data['registration_id']) ? trim((string) $data['registration_id']) : '';
if ($registrationId === '') {
    json_response(['ok' => false, 'error' => 'registration_id required'], 400);
}

$readHeaders = [
    'Accept: application/json',
    'apikey: ' . SUPABASE_KEY,
    'Authorization: Bearer ' . SUPABASE_KEY,
];

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
$patchPayload = [
    'status' => 'unscanned',
    'check_in_at' => null,
    'check_out_at' => null,
    'last_scanned_at' => null,
    'last_scanned_by' => null,
    'updated_at' => gmdate('c'),
];

$writeHeaders = [
    'Content-Type: application/json',
    'Accept: application/json',
    'apikey: ' . SUPABASE_KEY,
    'Authorization: Bearer ' . SUPABASE_KEY,
    'Prefer: return=representation',
];

$patchUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/attendance?ticket_id=eq.' . rawurlencode($ticketId)
    . '&select=id,ticket_id,status,check_in_at,check_out_at,last_scanned_at,last_scanned_by';
$patchRes = supabase_request('PATCH', $patchUrl, $writeHeaders, json_encode($patchPayload, JSON_UNESCAPED_SLASHES));
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

json_response(['ok' => true, 'attendance' => $attendance], 200);

