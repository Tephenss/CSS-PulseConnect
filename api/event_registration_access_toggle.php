<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/session.php';
session_bootstrap();

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
$clearCloseLimitOnReopen = $allowRegistration
    && !$previousAllowRegistration
    && is_event_registration_window_closed($event);

$updateHeaders = $headers;
$updateHeaders[] = 'Prefer: return=representation';
$updateUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/events'
    . '?id=eq.' . rawurlencode($eventId)
    . '&select=id,title,status,event_for,event_type,start_at,end_at,created_by,allow_registration,registration_close_weeks,registration_close_extend_days';

$updateFields = [
    'allow_registration' => $allowRegistration,
    'updated_at' => gmdate('c'),
];
// Re-opening after the scheduled close date: drop the close-limit so Allow
// Registration alone controls access going forward.
if ($clearCloseLimitOnReopen) {
    $updateFields['registration_close_weeks'] = null;
    $updateFields['registration_close_extend_days'] = 0;
}

$payload = json_encode($updateFields, JSON_UNESCAPED_SLASHES);

if (!is_string($payload)) {
    json_response(['ok' => false, 'error' => 'Failed to prepare registration access update.'], 500);
}

$res = supabase_request('PATCH', $updateUrl, $updateHeaders, $payload);
if (!$res['ok'] && $clearCloseLimitOnReopen) {
    // Older DBs may lack extend column — retry clearing weeks only.
    $retryFields = [
        'allow_registration' => $allowRegistration,
        'registration_close_weeks' => null,
        'updated_at' => gmdate('c'),
    ];
    $retryPayload = json_encode($retryFields, JSON_UNESCAPED_SLASHES);
    if (is_string($retryPayload)) {
        $res = supabase_request('PATCH', $updateUrl, $updateHeaders, $retryPayload);
    }
}
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
if ($clearCloseLimitOnReopen) {
    $updatedEvent['registration_close_weeks'] = null;
    $updatedEvent['registration_close_extend_days'] = 0;
}

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
        // Notify students only when registration is opened via toggle.
        // Keep OFF toggles silent to avoid unnecessary noise.
        if ($allowRegistration && !$previousAllowRegistration) {
            $eventTitle = trim((string) ($updatedEvent['title'] ?? 'this event'));
            notify_users_for_registration_access(
                $targetIds,
                'Registration Open',
                'Registration is now open for "' . $eventTitle . '". You can now register.',
                [
                    'event_id' => (string) ($updatedEvent['id'] ?? $eventId),
                    'type' => 'reg_open',
                ]
            );
        }
    }
}

json_response([
    'ok' => true,
    'event' => $updatedEvent,
    'allow_registration' => $allowRegistration,
    'registration_close_limit_cleared' => $clearCloseLimitOnReopen,
], 200);
