<?php
declare(strict_types=1);

session_start();

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/supabase.php';
require_once __DIR__ . '/../includes/json.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/event_sessions.php';

function mode_to_structure(string $eventMode, array $sessions): string
{
    if ($eventMode !== 'seminar_based') {
        return 'simple';
    }

    return count($sessions) > 1 ? 'two_seminars' : 'one_seminar';
}

function is_missing_column_error(array $response, string $column): bool
{
    $body = strtolower((string) ($response['body'] ?? ''));
    return str_contains($body, "'" . strtolower($column) . "'")
        && (
            str_contains($body, 'column')
            || str_contains($body, 'does not exist')
            || str_contains($body, 'schema cache')
        );
}

$user = require_role(['teacher', 'admin']);
$data = require_post_json();
require_csrf_from_json($data);

$eventId = isset($data['event_id']) ? (string) $data['event_id'] : '';
if ($eventId === '') {
    json_response(['ok' => false, 'error' => 'event_id required'], 400);
}

$readHeaders = [
    'Accept: application/json',
    'apikey: ' . SUPABASE_KEY,
    'Authorization: Bearer ' . SUPABASE_KEY,
];

$checkUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/events?id=eq.' . rawurlencode($eventId)
    . '&select=id,status,created_by'
    . '&limit=1';
$checkRes = supabase_request('GET', $checkUrl, $readHeaders);
if (!$checkRes['ok']) {
    json_response(['ok' => false, 'error' => 'Event lookup failed'], 500);
}

$rows = json_decode((string) $checkRes['body'], true);
$currentEvent = is_array($rows) && isset($rows[0]) ? $rows[0] : null;
if (!is_array($currentEvent)) {
    json_response(['ok' => false, 'error' => 'Event not found'], 404);
}

$currentSessions = fetch_event_sessions($eventId, $readHeaders);
$currentEventMode = count($currentSessions) > 0 ? 'seminar_based' : 'simple';
$eventMode = isset($data['event_mode'])
    ? normalize_event_mode((string) $data['event_mode'])
    : $currentEventMode;
$sessionsProvided = array_key_exists('sessions', $data);
$sessions = $sessionsProvided
    ? normalize_event_sessions($data['sessions'] ?? null, isset($data['location']) ? clean_string((string) $data['location']) : '')
    : [];

// Allow title/location/description/start_at/end_at updates.
$fields = [];
if (isset($data['title'])) {
    $t = clean_string((string) $data['title']);
    if ($t !== '' && mb_strlen($t) <= 150) $fields['title'] = $t;
}
if (isset($data['location'])) {
    $loc = clean_string((string) $data['location']);
    if ($loc !== '') $fields['location'] = $loc;
}
if (isset($data['description'])) {
    $desc = clean_text((string) $data['description']);
    $fields['description'] = $desc !== '' ? $desc : null;
}
if (isset($data['start_at']) && isset($data['end_at']) && $data['start_at'] !== '' && $data['end_at'] !== '') {
    $fields['start_at'] = (new DateTimeImmutable((string)$data['start_at']))->format('c');
    $fields['end_at'] = (new DateTimeImmutable((string)$data['end_at']))->format('c');
}
if (isset($data['event_type'])) {
    $et = clean_string((string) $data['event_type']);
    if ($et !== '') $fields['event_type'] = $et;
}
if (isset($data['event_for'])) {
    $ef = clean_string((string) $data['event_for']);
    if ($ef !== '') $fields['event_for'] = $ef;
}
if (isset($data['grace_time'])) {
    $gt = clean_string((string) $data['grace_time']);
    if ($gt !== '') $fields['grace_time'] = $gt;
}
$shouldUpdateMode = isset($data['event_mode']) || $eventMode !== $currentEventMode;
if ($shouldUpdateMode) {
    $fields['event_mode'] = $eventMode;
}

if ($eventMode === 'seminar_based') {
    if (!$sessionsProvided) {
        $sessions = $currentSessions;
    }
    if (count($sessions) === 0) {
        json_response(['ok' => false, 'error' => 'At least one seminar is required'], 400);
    }
    validate_non_overlapping_sessions($sessions);
    $window = derive_event_window_from_sessions($sessions);
    $fields['start_at'] = (string) ($window['start_at'] ?? '');
    $fields['end_at'] = (string) ($window['end_at'] ?? '');
}


if (count($fields) === 0) {
    json_response(['ok' => false, 'error' => 'No fields to update'], 400);
}

// Teacher can only update pending events they created.
$role = (string) ($user['role'] ?? 'student');
if ($role === 'teacher') {
    if ((string) ($currentEvent['created_by'] ?? '') !== (string) ($user['id'] ?? '')) {
        json_response(['ok' => false, 'error' => 'Forbidden'], 403);
    }
    
    $currentStatus = (string) ($currentEvent['status'] ?? '');
    if (!in_array($currentStatus, ['pending', 'archived'], true)) {
        json_response(['ok' => false, 'error' => 'Only pending or rejected events can be edited'], 409);
    }

    // If it was archived (rejected), move it back to pending for review
    if ($currentStatus === 'archived') {
        $fields['status'] = 'pending';
        // Optional: clear the [REJECT_REASON] if they are updating the description
        if (isset($fields['description'])) {
            $fields['description'] = trim(preg_replace('/\[REJECT_REASON:.*?\]/', '', (string)$fields['description']));
        }
    }
}

$fields['updated_at'] = gmdate('c');

$url = rtrim(SUPABASE_URL, '/') . '/rest/v1/events?id=eq.' . rawurlencode($eventId) . '&select=id,title,status,start_at,end_at';
$headers = [
    'Content-Type: application/json',
    'Accept: application/json',
    'apikey: ' . SUPABASE_KEY,
    'Authorization: Bearer ' . SUPABASE_KEY,
    'Prefer: return=representation',
];

$res = supabase_request('PATCH', $url, $headers, json_encode($fields, JSON_UNESCAPED_SLASHES));
if (!$res['ok'] && is_missing_column_error($res, 'event_mode')) {
    $retryFields = $fields;
    unset($retryFields['event_mode']);
    if ($shouldUpdateMode) {
        $retryFields['event_structure'] = mode_to_structure($eventMode, $eventMode === 'seminar_based' ? $sessions : []);
    }
    $res = supabase_request('PATCH', $url, $headers, json_encode($retryFields, JSON_UNESCAPED_SLASHES));
}

if (!$res['ok']) {
    json_response(['ok' => false, 'error' => build_error($res['body'] ?? null, (int) ($res['status'] ?? 0), $res['error'] ?? null, 'Update failed')], 500);
}

$rows = json_decode((string) $res['body'], true);
$event = is_array($rows) && isset($rows[0]) ? $rows[0] : null;

try {
    if ($eventMode === 'seminar_based') {
        replace_event_sessions($eventId, $sessions, $readHeaders);
    } elseif ($currentEventMode === 'seminar_based' || $sessionsProvided) {
        replace_event_sessions($eventId, [], $readHeaders);
    }
} catch (RuntimeException $e) {
    json_response(['ok' => false, 'error' => $e->getMessage()], 500);
}

if (is_array($event) && $eventMode === 'seminar_based') {
    $event['sessions'] = fetch_event_sessions($eventId, $readHeaders);
}

json_response(['ok' => true, 'event' => $event], 200);

