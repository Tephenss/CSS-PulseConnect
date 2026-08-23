<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/supabase.php';
require_once __DIR__ . '/../includes/json.php';
require_once __DIR__ . '/../includes/mobile_api.php';
require_once __DIR__ . '/../includes/mobile_session.php';
require_once __DIR__ . '/../includes/event_sessions.php';

$data = mobile_api_require_post_json();
mobile_api_validate_key($data);
$sessionUser = mobile_api_require_user($data);
$userId = (string) ($sessionUser['id'] ?? '');

$headers = mobile_api_supabase_headers();

// Include seminar flags so the app can show per-seminar check-in/out (not "Simple event").
$url = rtrim(SUPABASE_URL, '/') . '/rest/v1/event_registrations'
    . '?select=' . rawurlencode(
        'id,registered_at,student_id,event_id,'
        . 'events(id,title,start_at,end_at,location,status,event_type,cover_image_url,event_mode,event_structure,grace_time,event_span),'
        . 'tickets(id,token,attendance(id,status,check_in_at,check_out_at,last_scanned_at))'
    )
    . '&student_id=eq.' . rawurlencode($userId)
    . '&order=registered_at.desc'
    . '&limit=150';

$res = supabase_request('GET', $url, $headers);
if (!$res['ok']) {
    // Retry without optional event columns (older DBs).
    $fallbackUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/event_registrations'
        . '?select=' . rawurlencode(
            'id,registered_at,student_id,event_id,'
            . 'events(id,title,start_at,end_at,location,status,event_type,cover_image_url,event_mode,event_structure),'
            . 'tickets(id,token,attendance(id,status,check_in_at,check_out_at,last_scanned_at))'
        )
        . '&student_id=eq.' . rawurlencode($userId)
        . '&order=registered_at.desc'
        . '&limit=150';
    $res = supabase_request('GET', $fallbackUrl, $headers);
}
if (!$res['ok']) {
    error_log(
        'mobile_my_tickets failed user=' . $userId
        . ' status=' . (int) ($res['status'] ?? 0)
        . ' body=' . substr((string) ($res['body'] ?? ''), 0, 300)
    );
    json_response([
        'ok' => false,
        'error' => 'Failed to load tickets.',
    ], 500);
}

$rows = json_decode((string) $res['body'], true);
if (!is_array($rows)) {
    $rows = [];
}

// If event_mode is wrong/missing but seminars exist, mark as seminar_based for the app.
$eventIds = [];
foreach ($rows as $row) {
    if (!is_array($row)) {
        continue;
    }
    $eid = trim((string) ($row['event_id'] ?? ''));
    if ($eid === '' && is_array($row['events'] ?? null)) {
        $eid = trim((string) ($row['events']['id'] ?? ''));
    }
    if ($eid !== '') {
        $eventIds[$eid] = true;
    }
}

$sessionCountByEvent = [];
$sessionsByEvent = fetch_event_sessions_map(array_keys($eventIds), $headers);
foreach ($sessionsByEvent as $eid => $sessions) {
    $sessionCountByEvent[(string) $eid] = is_array($sessions) ? count($sessions) : 0;
}

foreach ($rows as &$row) {
    if (!is_array($row)) {
        continue;
    }
    $event = $row['events'] ?? null;
    if (!is_array($event)) {
        continue;
    }
    $eid = trim((string) ($event['id'] ?? $row['event_id'] ?? ''));
    $count = (int) ($sessionCountByEvent[$eid] ?? 0);
    $rawSessions = $sessionsByEvent[$eid] ?? [];
    if ($count > 0) {
        $event['uses_sessions'] = true;
        $event['event_mode'] = 'seminar_based';
        if (trim((string) ($event['event_structure'] ?? '')) === '') {
            $event['event_structure'] = $count > 1 ? 'two_seminars' : 'one_seminar';
        }
        $event['session_count'] = $count;
        $slimSessions = [];
        if (is_array($rawSessions)) {
            foreach ($rawSessions as $session) {
                if (!is_array($session)) {
                    continue;
                }
                $slimSessions[] = [
                    'id' => (string) ($session['id'] ?? ''),
                    'title' => (string) ($session['title'] ?? ''),
                    'topic' => isset($session['topic']) ? (string) $session['topic'] : '',
                    'location' => isset($session['location']) ? (string) $session['location'] : '',
                    'start_at' => (string) ($session['start_at'] ?? ''),
                    'end_at' => (string) ($session['end_at'] ?? ''),
                ];
            }
        }
        $event['sessions'] = $slimSessions;
    } else {
        if (!isset($event['event_mode']) || trim((string) $event['event_mode']) === '') {
            $event['event_mode'] = 'simple';
        }
        $event['uses_sessions'] = false;
        $event['session_count'] = 0;
        $event['sessions'] = [];
    }
    $row['events'] = $event;
}
unset($row);

json_response([
    'ok' => true,
    'rows' => $rows,
], 200);
