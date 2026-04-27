<?php
declare(strict_types=1);

session_start();

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/supabase.php';
require_once __DIR__ . '/../includes/json.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/registration_access.php';

function can_manage_registration_access(array $event, array $user): bool
{
    $role = (string) ($user['role'] ?? '');
    if ($role === 'admin') {
        return true;
    }

    return $role === 'teacher'
        && (string) ($event['created_by'] ?? '') !== ''
        && (string) ($event['created_by'] ?? '') === (string) ($user['id'] ?? '');
}

$user = require_role(['admin', 'teacher']);
$data = require_post_json();
require_csrf_from_json($data);

$eventId = trim((string) ($data['event_id'] ?? ''));
if ($eventId === '') {
    json_response(['ok' => false, 'error' => 'event_id required'], 400);
}

$allowRegistration = normalize_registration_bool($data['allow_registration'] ?? false);

$headers = [
    'Content-Type: application/json',
    'Accept: application/json',
    'apikey: ' . SUPABASE_KEY,
    'Authorization: Bearer ' . SUPABASE_KEY,
];

$event = fetch_event_with_registration_settings($eventId, $headers);
if (!is_array($event)) {
    json_response(['ok' => false, 'error' => 'Event not found'], 404);
}

if (!can_manage_registration_access($event, $user)) {
    json_response(['ok' => false, 'error' => 'Forbidden'], 403);
}

if (strtolower(trim((string) ($event['status'] ?? ''))) !== 'published') {
    json_response(['ok' => false, 'error' => 'Publish the event first before changing registration access.'], 409);
}

$previousAllowRegistration = event_allows_open_registration($event);

$updateHeaders = $headers;
$updateHeaders[] = 'Prefer: return=representation';
$updateUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/events'
    . '?id=eq.' . rawurlencode($eventId)
    . '&select=id,title,status,event_for,event_type,start_at,end_at,created_by,allow_registration';
$payload = json_encode([
    'allow_registration' => $allowRegistration,
    'updated_at' => gmdate('c'),
], JSON_UNESCAPED_SLASHES);

if (!is_string($payload)) {
    json_response(['ok' => false, 'error' => 'Failed to prepare registration access update.'], 500);
}

$res = supabase_request('PATCH', $updateUrl, $updateHeaders, $payload);
if (!$res['ok']) {
    $message = (string) ($res['body'] ?? '') . ' ' . (string) ($res['error'] ?? '');
    if (registration_access_missing_column_message($message, 'allow_registration')) {
        json_response([
            'ok' => false,
            'error' => 'Database update required: run migration 024_registration_access_control.sql first.',
        ], 500);
    }

    json_response([
        'ok' => false,
        'error' => build_error($res['body'] ?? null, (int) ($res['status'] ?? 0), $res['error'] ?? null, 'Failed to update registration access'),
    ], 500);
}

$rows = json_decode((string) $res['body'], true);
$updatedEvent = is_array($rows) && isset($rows[0]) && is_array($rows[0]) ? $rows[0] : $event;
$updatedEvent['allow_registration'] = $allowRegistration;

if ($previousAllowRegistration !== $allowRegistration) {
    $targetStudents = fetch_target_students_for_event($updatedEvent, [
        'Accept: application/json',
        'apikey: ' . SUPABASE_KEY,
        'Authorization: Bearer ' . SUPABASE_KEY,
    ]);
    $targetIds = array_values(array_filter(array_map(
        static fn (array $row): string => trim((string) ($row['id'] ?? '')),
        $targetStudents
    )));

    if ($targetIds !== []) {
        // Intentionally keep registration-access toggles silent.
        // Publishing notifications are handled by events_approve.php.
    }
}

json_response([
    'ok' => true,
    'event' => $updatedEvent,
    'allow_registration' => $allowRegistration,
], 200);
