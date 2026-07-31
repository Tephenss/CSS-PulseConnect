<?php
declare(strict_types=1);

/**
 * Teacher Early Out toggle for Event QR time-out.
 * ON: early_out_enabled_at = now (valid 1 hour, then auto-off).
 * OFF: clear early_out_enabled_at.
 * Enable only after grace/time-in window ends, until event/seminar end.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/supabase.php';
require_once __DIR__ . '/../includes/json.php';
require_once __DIR__ . '/../includes/mobile_api.php';
require_once __DIR__ . '/../includes/mobile_session.php';
require_once __DIR__ . '/../includes/api_rate_limit.php';
require_once __DIR__ . '/../includes/scan_context.php';
require_once __DIR__ . '/../includes/event_attendance_windows.php';

$data = mobile_api_require_post_json();
mobile_api_validate_key($data);
$sessionUser = mobile_api_require_user($data);
$userId = (string) ($sessionUser['id'] ?? '');
$role = strtolower(trim((string) ($sessionUser['role'] ?? '')));

$clientIp = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
if (!api_rate_limit_allow('mobile_event_early_out:' . $userId . ':' . $clientIp, 40, 30)) {
    json_response(['ok' => false, 'error' => 'Too many requests. Please wait.'], 429);
}

$eventId = trim((string) ($data['event_id'] ?? ''));
$sessionId = trim((string) ($data['session_id'] ?? ''));
$action = strtolower(trim((string) ($data['action'] ?? 'status')));
$enabledRaw = $data['enabled'] ?? null;

if ($eventId === '') {
    json_response(['ok' => false, 'error' => 'event_id is required.'], 400);
}

$headers = mobile_api_supabase_headers();
$writeHeaders = [
    'Content-Type: application/json',
    'Accept: application/json',
    'apikey: ' . SUPABASE_KEY,
    'Authorization: Bearer ' . SUPABASE_KEY,
    'Prefer: return=representation',
];

$isAdmin = $role === 'admin';
$canAccess = $isAdmin || mobile_teacher_can_access_event($eventId, $userId, $headers);
if (!$canAccess) {
    json_response(['ok' => false, 'error' => 'Not allowed to manage early out for this event.', 'status' => 'forbidden'], 403);
}

$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

if ($sessionId !== '') {
    $sessUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/event_sessions'
        . '?select=id,event_id,title,early_out_enabled_at,start_at,end_at,scan_window_minutes'
        . '&id=eq.' . rawurlencode($sessionId)
        . '&event_id=eq.' . rawurlencode($eventId)
        . '&limit=1';
    $sessRes = supabase_request('GET', $sessUrl, $headers);
    $sessRows = $sessRes['ok'] ? json_decode((string) $sessRes['body'], true) : [];
    $session = is_array($sessRows) && isset($sessRows[0]) ? $sessRows[0] : null;
    if (!is_array($session)) {
        $sessUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/event_sessions'
            . '?select=id,event_id,title,early_out_enabled_at,start_at,end_at'
            . '&id=eq.' . rawurlencode($sessionId)
            . '&event_id=eq.' . rawurlencode($eventId)
            . '&limit=1';
        $sessRes = supabase_request('GET', $sessUrl, $headers);
        $sessRows = $sessRes['ok'] ? json_decode((string) $sessRes['body'], true) : [];
        $session = is_array($sessRows) && isset($sessRows[0]) ? $sessRows[0] : null;
    }
    if (!is_array($session)) {
        json_response(['ok' => false, 'error' => 'Seminar not found for this event.'], 404);
    }

    attendance_lazy_clear_early_out('event_sessions', $sessionId, $session['early_out_enabled_at'] ?? null, $now, $headers);

    $startAt = parse_iso_datetime((string) ($session['start_at'] ?? ''));
    $endAt = parse_iso_datetime((string) ($session['end_at'] ?? ''));
    $graceMinutes = max(0, (int) ($session['scan_window_minutes'] ?? 30));

    if ($action === 'set' || $enabledRaw !== null) {
        $enable = filter_var($enabledRaw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($enable === null) {
            $enable = in_array(strtolower(trim((string) $enabledRaw)), ['1', 'true', 'yes', 'on'], true);
        }
        if ($enable) {
            if (!attendance_early_out_schedule_allows_enable($startAt, $endAt, $now, $graceMinutes)) {
                json_response([
                    'ok' => false,
                    'error' => 'Early Out is available only after the seminar grace period ends.',
                    'status' => 'outside_schedule',
                ], 409);
            }
        }
        $payload = ['early_out_enabled_at' => $enable ? $now->format(DATE_ATOM) : null];
        $patchUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/event_sessions?id=eq.' . rawurlencode($sessionId);
        $patchRes = supabase_request('PATCH', $patchUrl, $writeHeaders, json_encode($payload, JSON_UNESCAPED_SLASHES));
        if (!$patchRes['ok']) {
            json_response(['ok' => false, 'error' => 'Failed to update early out. Run migration 054 if column is missing.'], 500);
        }
        $enabledAt = $enable ? $now->format(DATE_ATOM) : null;
    } else {
        $enabledAt = attendance_early_out_is_active((string) ($session['early_out_enabled_at'] ?? ''), $now)
            ? (string) $session['early_out_enabled_at']
            : null;
    }

    $status = attendance_early_out_status($enabledAt, $now, $startAt, $endAt, $graceMinutes);
    json_response([
        'ok' => true,
        'event_id' => $eventId,
        'session_id' => $sessionId,
        'early_out' => $status,
    ]);
}

$eventUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/events'
    . '?select=id,early_out_enabled_at,start_at,end_at,grace_time&id=eq.' . rawurlencode($eventId) . '&limit=1';
$eventRes = supabase_request('GET', $eventUrl, $headers);
$eventRows = $eventRes['ok'] ? json_decode((string) $eventRes['body'], true) : [];
$event = is_array($eventRows) && isset($eventRows[0]) ? $eventRows[0] : null;
if (!is_array($event)) {
    json_response(['ok' => false, 'error' => 'Event not found (or early_out column missing — run migration 054).'], 404);
}

attendance_lazy_clear_early_out('events', $eventId, $event['early_out_enabled_at'] ?? null, $now, $headers);

$startAt = parse_iso_datetime((string) ($event['start_at'] ?? ''));
$endAt = parse_iso_datetime((string) ($event['end_at'] ?? ''));
$graceMinutes = function_exists('simple_event_grace_minutes')
    ? simple_event_grace_minutes($event)
    : max(0, (int) ($event['grace_time'] ?? 30));

if ($action === 'set' || $enabledRaw !== null) {
    $enable = filter_var($enabledRaw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    if ($enable === null) {
        $enable = in_array(strtolower(trim((string) $enabledRaw)), ['1', 'true', 'yes', 'on'], true);
    }
    if ($enable) {
        if (!attendance_early_out_schedule_allows_enable($startAt, $endAt, $now, $graceMinutes)) {
            json_response([
                'ok' => false,
                'error' => 'Early Out is available only after the grace period ends.',
                'status' => 'outside_schedule',
            ], 409);
        }
    }
    $payload = ['early_out_enabled_at' => $enable ? $now->format(DATE_ATOM) : null];
    $patchUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/events?id=eq.' . rawurlencode($eventId);
    $patchRes = supabase_request('PATCH', $patchUrl, $writeHeaders, json_encode($payload, JSON_UNESCAPED_SLASHES));
    if (!$patchRes['ok']) {
        json_response(['ok' => false, 'error' => 'Failed to update early out.'], 500);
    }
    $enabledAt = $enable ? $now->format(DATE_ATOM) : null;
} else {
    $enabledAt = attendance_early_out_is_active((string) ($event['early_out_enabled_at'] ?? ''), $now)
        ? (string) $event['early_out_enabled_at']
        : null;
}

$status = attendance_early_out_status($enabledAt, $now, $startAt, $endAt, $graceMinutes);
json_response([
    'ok' => true,
    'event_id' => $eventId,
    'session_id' => null,
    'early_out' => $status,
]);
