<?php
declare(strict_types=1);

/**
 * Web Early Out toggle (session + CSRF). Same rules as mobile_event_early_out.php.
 * Seminar events: one control — auto-targets the active seminar (they do not overlap).
 */
require_once __DIR__ . '/../includes/session.php';
session_bootstrap();

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/supabase.php';
require_once __DIR__ . '/../includes/json.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/mobile_api.php';
require_once __DIR__ . '/../includes/scan_context.php';
require_once __DIR__ . '/../includes/event_sessions.php';
require_once __DIR__ . '/../includes/event_attendance_windows.php';

$user = require_role(['teacher', 'admin']);
$data = require_post_json();
require_csrf_from_json($data);

$userId = (string) ($user['id'] ?? '');
$role = strtolower(trim((string) ($user['role'] ?? '')));
$eventId = trim((string) ($data['event_id'] ?? ''));
$sessionId = trim((string) ($data['session_id'] ?? ''));
$enabledRaw = $data['enabled'] ?? null;
$action = strtolower(trim((string) ($data['action'] ?? 'status')));

if ($eventId === '') {
    json_response(['ok' => false, 'error' => 'event_id is required.'], 400);
}

$headers = [
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

$isAdmin = $role === 'admin';
$canAccess = $isAdmin || mobile_teacher_can_access_event($eventId, $userId, $headers);
if (!$canAccess) {
    json_response(['ok' => false, 'error' => 'Not allowed.', 'status' => 'forbidden'], 403);
}
$isWrite = $action === 'set' || $enabledRaw !== null;
$canManageEarlyOut = $isAdmin || (function_exists('mobile_api_is_event_creator')
    ? mobile_api_is_event_creator($eventId, $userId, $headers)
    : false);
if ($isWrite && !$canManageEarlyOut) {
    json_response([
        'ok' => false,
        'error' => 'Only the event creator can enable Early Out.',
        'status' => 'forbidden',
    ], 403);
}

$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

$loadSession = static function (string $sid) use ($eventId, $headers): ?array {
    $sessUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/event_sessions'
        . '?select=id,event_id,title,topic,early_out_enabled_at,start_at,end_at,scan_window_minutes'
        . '&id=eq.' . rawurlencode($sid)
        . '&event_id=eq.' . rawurlencode($eventId)
        . '&limit=1';
    $sessRes = supabase_request('GET', $sessUrl, $headers);
    $sessRows = $sessRes['ok'] ? json_decode((string) $sessRes['body'], true) : [];
    $session = is_array($sessRows) && isset($sessRows[0]) ? $sessRows[0] : null;
    if (is_array($session)) {
        return $session;
    }
    $sessUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/event_sessions'
        . '?select=id,event_id,title,topic,early_out_enabled_at,start_at,end_at'
        . '&id=eq.' . rawurlencode($sid)
        . '&event_id=eq.' . rawurlencode($eventId)
        . '&limit=1';
    $sessRes = supabase_request('GET', $sessUrl, $headers);
    $sessRows = $sessRes['ok'] ? json_decode((string) $sessRes['body'], true) : [];
    return is_array($sessRows) && isset($sessRows[0]) && is_array($sessRows[0]) ? $sessRows[0] : null;
};

// Auto-pick the active seminar when none was specified (single Early Out control).
if ($sessionId === '' && function_exists('fetch_event_sessions') && function_exists('attendance_resolve_early_out_target_session')) {
    $sessions = fetch_event_sessions($eventId, $headers);
    if (!empty($sessions)) {
        // Enrich early_out flags for resolver priority (already ON).
        $eoUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/event_sessions'
            . '?select=id,early_out_enabled_at&event_id=eq.' . rawurlencode($eventId);
        $eoRes = supabase_request('GET', $eoUrl, $headers);
        $eoMap = [];
        if (($eoRes['ok'] ?? false) === true) {
            $eoRows = json_decode((string) ($eoRes['body'] ?? ''), true);
            if (is_array($eoRows)) {
                foreach ($eoRows as $row) {
                    if (is_array($row) && !empty($row['id'])) {
                        $eoMap[(string) $row['id']] = $row['early_out_enabled_at'] ?? null;
                    }
                }
            }
        }
        foreach ($sessions as &$srow) {
            if (!is_array($srow)) {
                continue;
            }
            $sid = (string) ($srow['id'] ?? '');
            if ($sid !== '' && array_key_exists($sid, $eoMap)) {
                $srow['early_out_enabled_at'] = $eoMap[$sid];
            }
        }
        unset($srow);

        $target = attendance_resolve_early_out_target_session($sessions, $now);
        if (is_array($target) && trim((string) ($target['id'] ?? '')) !== '') {
            $sessionId = trim((string) $target['id']);
        }
    }
}

if ($sessionId !== '') {
    $session = $loadSession($sessionId);
    if (!is_array($session)) {
        json_response(['ok' => false, 'error' => 'Seminar not found.'], 404);
    }
    attendance_lazy_clear_early_out('event_sessions', $sessionId, $session['early_out_enabled_at'] ?? null, $now, $headers);

    $startAt = parse_iso_datetime((string) ($session['start_at'] ?? ''));
    $endAt = parse_iso_datetime((string) ($session['end_at'] ?? ''));
    $graceMinutes = max(0, (int) ($session['scan_window_minutes'] ?? 30));
    $sessionName = trim((string) (function_exists('build_session_display_name')
        ? (build_session_display_name($session) ?: ($session['title'] ?? 'Seminar'))
        : ($session['title'] ?? 'Seminar')));
    if ($sessionName === '') {
        $sessionName = 'Seminar';
    }

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
            json_response(['ok' => false, 'error' => 'Failed to update early out.'], 500);
        }
        $enabledAt = $enable ? $now->format(DATE_ATOM) : null;
    } else {
        $enabledAt = attendance_early_out_is_active((string) ($session['early_out_enabled_at'] ?? ''), $now)
            ? (string) $session['early_out_enabled_at']
            : null;
    }

    json_response([
        'ok' => true,
        'event_id' => $eventId,
        'session_id' => $sessionId,
        'session_name' => $sessionName,
        'early_out' => attendance_early_out_status($enabledAt, $now, $startAt, $endAt, $graceMinutes),
    ]);
}

$eventUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/events'
    . '?select=id,early_out_enabled_at,start_at,end_at,grace_time&id=eq.' . rawurlencode($eventId) . '&limit=1';
$eventRes = supabase_request('GET', $eventUrl, $headers);
$eventRows = $eventRes['ok'] ? json_decode((string) $eventRes['body'], true) : [];
$event = is_array($eventRows) && isset($eventRows[0]) ? $eventRows[0] : null;
if (!is_array($event)) {
    json_response(['ok' => false, 'error' => 'Event not found.'], 404);
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

json_response([
    'ok' => true,
    'event_id' => $eventId,
    'session_id' => null,
    'session_name' => null,
    'early_out' => attendance_early_out_status($enabledAt, $now, $startAt, $endAt, $graceMinutes),
]);
