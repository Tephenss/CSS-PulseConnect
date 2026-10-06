<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/session.php';
session_bootstrap();

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/supabase.php';
require_once __DIR__ . '/../includes/json.php';
require_once __DIR__ . '/../includes/csrf.php';

$user = require_role(['admin', 'teacher']);
$data = require_post_json();
require_csrf_from_json($data);

$registrationId = trim((string) ($data['registration_id'] ?? ''));
if ($registrationId === '') {
    json_response(['ok' => false, 'error' => 'registration_id required'], 400);
}

$headers = [
    'Accept: application/json',
    'apikey: ' . SUPABASE_KEY,
    'Authorization: Bearer ' . SUPABASE_KEY,
];

$role = strtolower(trim((string) ($user['role'] ?? '')));
$userId = trim((string) ($user['id'] ?? ''));
$registrationUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/event_registrations?select=id,event_id'
    . '&id=eq.' . rawurlencode($registrationId)
    . '&limit=1';
$registrationRes = supabase_request('GET', $registrationUrl, $headers);
if (!($registrationRes['ok'] ?? false)) {
    json_response([
        'ok' => false,
        'error' => build_error($registrationRes['body'] ?? null, (int) ($registrationRes['status'] ?? 0), $registrationRes['error'] ?? null, 'Participant lookup failed'),
    ], 500);
}
$registrationRows = json_decode((string) ($registrationRes['body'] ?? ''), true);
$registration = is_array($registrationRows) && isset($registrationRows[0]) && is_array($registrationRows[0])
    ? $registrationRows[0]
    : null;
if (!is_array($registration) || trim((string) ($registration['event_id'] ?? '')) === '') {
    json_response(['ok' => false, 'error' => 'Participant registration not found.'], 404);
}

$eventId = trim((string) $registration['event_id']);
if ($role !== 'admin') {
    $eventUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/events?select=created_by'
        . '&id=eq.' . rawurlencode($eventId)
        . '&limit=1';
    $eventRes = supabase_request('GET', $eventUrl, $headers);
    if (!($eventRes['ok'] ?? false)) {
        json_response([
            'ok' => false,
            'error' => build_error($eventRes['body'] ?? null, (int) ($eventRes['status'] ?? 0), $eventRes['error'] ?? null, 'Event lookup failed'),
        ], 500);
    }
    $eventRows = json_decode((string) ($eventRes['body'] ?? ''), true);
    $event = is_array($eventRows) && isset($eventRows[0]) && is_array($eventRows[0]) ? $eventRows[0] : null;
    if (!is_array($event)) {
        json_response(['ok' => false, 'error' => 'Event not found.'], 404);
    }
    if ($userId === '' || !hash_equals((string) ($event['created_by'] ?? ''), $userId)) {
        json_response(['ok' => false, 'error' => 'Only this event\'s teacher or an administrator can remove participants.'], 403);
    }
}

$url = rtrim(SUPABASE_URL, '/') . '/rest/v1/event_registrations?id=eq.' . rawurlencode($registrationId)
    . '&event_id=eq.' . rawurlencode($eventId);
$res = supabase_request('DELETE', $url, $headers);
if (!$res['ok']) {
    json_response(['ok' => false, 'error' => build_error($res['body'] ?? null, (int) ($res['status'] ?? 0), $res['error'] ?? null, 'Remove failed')], 500);
}

json_response(['ok' => true], 200);

