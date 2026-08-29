<?php
declare(strict_types=1);

/**
 * Batch Early Out status for many events (scan-context enrichment).
 * Read-only — replaces N× status calls with 2 Rest queries.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/supabase.php';
require_once __DIR__ . '/../includes/json.php';
require_once __DIR__ . '/../includes/mobile_api.php';
require_once __DIR__ . '/../includes/mobile_session.php';
require_once __DIR__ . '/../includes/api_rate_limit.php';
require_once __DIR__ . '/../includes/event_attendance_windows.php';

$data = mobile_api_require_post_json();
mobile_api_validate_key($data);
$sessionUser = mobile_api_require_user($data);
$userId = (string) ($sessionUser['id'] ?? '');

$clientIp = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
if (!api_rate_limit_allow('mobile_events_early_out_batch:' . $userId . ':' . $clientIp, 30, 30)) {
    json_response(['ok' => false, 'error' => 'Too many requests. Please wait.'], 429);
}

$rawIds = $data['event_ids'] ?? [];
if (!is_array($rawIds)) {
    json_response(['ok' => false, 'error' => 'event_ids must be an array.'], 400);
}

$eventIds = [];
foreach ($rawIds as $raw) {
    $id = trim((string) $raw);
    if ($id !== '' && !isset($eventIds[$id])) {
        $eventIds[$id] = true;
    }
    if (count($eventIds) >= 40) {
        break;
    }
}
$ids = array_keys($eventIds);
if ($ids === []) {
    json_response(['ok' => true, 'items' => []]);
}

$headers = mobile_api_supabase_headers();
$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
$inList = implode(',', array_map('rawurlencode', $ids));

$eventsUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/events'
    . '?select=id,early_out_enabled_at,start_at,end_at,grace_time'
    . '&id=in.(' . $inList . ')'
    . '&limit=40';
$eventsRes = supabase_request('GET', $eventsUrl, $headers);
$eventRows = ($eventsRes['ok'] ?? false) ? json_decode((string) ($eventsRes['body'] ?? ''), true) : [];
$eventRows = is_array($eventRows) ? $eventRows : [];

$eventsById = [];
foreach ($eventRows as $row) {
    if (!is_array($row)) {
        continue;
    }
    $eid = trim((string) ($row['id'] ?? ''));
    if ($eid !== '') {
        $eventsById[$eid] = $row;
    }
}

$sessionUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/event_sessions'
    . '?select=id,event_id,early_out_enabled_at,start_at,end_at,scan_window_minutes,title,topic'
    . '&event_id=in.(' . $inList . ')'
    . '&order=start_at.asc'
    . '&limit=500';
$sessionRes = supabase_request('GET', $sessionUrl, $headers);
$sessionRows = ($sessionRes['ok'] ?? false) ? json_decode((string) ($sessionRes['body'] ?? ''), true) : [];
$sessionRows = is_array($sessionRows) ? $sessionRows : [];

$sessionsByEvent = [];
foreach ($sessionRows as $row) {
    if (!is_array($row)) {
        continue;
    }
    $eid = trim((string) ($row['event_id'] ?? ''));
    if ($eid === '') {
        continue;
    }
    $sessionsByEvent[$eid][] = $row;
}

$items = [];
foreach ($ids as $eventId) {
    $event = $eventsById[$eventId] ?? null;
    if (!is_array($event)) {
        continue;
    }

    $sessions = $sessionsByEvent[$eventId] ?? [];
    $sessionId = null;
    $enabledAt = null;

    if ($sessions !== [] && function_exists('attendance_resolve_early_out_target_session')) {
        $target = attendance_resolve_early_out_target_session($sessions, $now);
        if (is_array($target) && trim((string) ($target['id'] ?? '')) !== '') {
            $sessionId = trim((string) $target['id']);
            $rawEo = (string) ($target['early_out_enabled_at'] ?? '');
            $enabledAt = attendance_early_out_is_active($rawEo, $now) ? $rawEo : null;
        }
    }

    if ($sessionId === null) {
        $rawEo = (string) ($event['early_out_enabled_at'] ?? '');
        $enabledAt = attendance_early_out_is_active($rawEo, $now) ? $rawEo : null;
    }

    $items[] = [
        'event_id' => $eventId,
        'session_id' => $sessionId,
        'early_out' => [
            'enabled' => $enabledAt !== null && $enabledAt !== '',
            'enabled_at' => $enabledAt,
        ],
    ];
}

json_response(['ok' => true, 'items' => $items]);
