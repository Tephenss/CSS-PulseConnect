<?php
declare(strict_types=1);

/**
 * For simple events, scanning is allowed from start_at up to +30 minutes.
 * Seminar-based events use per-session scan_window_minutes.
 */
const SIMPLE_EVENT_SCAN_WINDOW_MINUTES = 30;

function scan_context_event_summary(array $event): array
{
    return [
        'id' => (string) ($event['id'] ?? ''),
        'title' => (string) ($event['title'] ?? 'Event'),
        'location' => (string) ($event['location'] ?? ''),
        'start_at' => (string) ($event['start_at'] ?? ''),
        'end_at' => (string) ($event['end_at'] ?? ''),
    ];
}

function scan_context_session_summary(array $session): array
{
    return [
        'id' => (string) ($session['id'] ?? ''),
        'title' => (string) ($session['title'] ?? ''),
        'topic' => (string) ($session['topic'] ?? ''),
        'display_name' => build_session_display_name($session),
        'start_at' => (string) ($session['start_at'] ?? ''),
        'end_at' => (string) ($session['end_at'] ?? ''),
        'scan_window_minutes' => max(1, (int) ($session['scan_window_minutes'] ?? 30)),
    ];
}

function parse_iso_datetime(string $raw): ?DateTimeImmutable
{
    $raw = trim($raw);
    if ($raw === '') {
        return null;
    }

    try {
        return new DateTimeImmutable($raw);
    } catch (Throwable $e) {
        return null;
    }
}

function resolve_session_scan_context(array $event, DateTimeImmutable $nowUtc, array $headers): array
{
    $eventId = (string) ($event['id'] ?? '');
    $eventSummary = scan_context_event_summary($event);
    if ($eventId === '') {
        return [
            'status' => 'missing_schedule',
            'source' => 'session',
            'event' => $eventSummary,
            'session' => null,
            'opens_at' => null,
            'closes_at' => null,
            'window_minutes' => null,
            'message' => 'Event ID is missing.',
        ];
    }

    $sessions = fetch_event_sessions($eventId, $headers);
    if (empty($sessions)) {
        return [
            'status' => 'missing_schedule',
            'source' => 'session',
            'event' => $eventSummary,
            'session' => null,
            'opens_at' => null,
            'closes_at' => null,
            'window_minutes' => null,
            'message' => 'No seminar schedule found for this event.',
        ];
    }

    $open = [];
    $upcoming = [];
    $closed = [];

    foreach ($sessions as $session) {
        if (!is_array($session)) {
            continue;
        }

        $startAt = parse_iso_datetime((string) ($session['start_at'] ?? ''));
        if (!$startAt) {
            continue;
        }

        $windowMinutes = max(1, (int) ($session['scan_window_minutes'] ?? 30));
        $windowEnd = $startAt->modify('+' . $windowMinutes . ' minutes');

        $meta = [
            'session' => $session,
            'opens_at' => $startAt->format('c'),
            'closes_at' => $windowEnd->format('c'),
            'window_minutes' => $windowMinutes,
        ];

        if ($nowUtc < $startAt) {
            $upcoming[] = $meta;
            continue;
        }

        if ($nowUtc <= $windowEnd) {
            $open[] = $meta;
            continue;
        }

        $closed[] = $meta;
    }

    if (count($open) > 1) {
        return [
            'status' => 'conflict',
            'source' => 'session',
            'event' => $eventSummary,
            'session' => null,
            'opens_at' => null,
            'closes_at' => null,
            'window_minutes' => null,
            'message' => 'Multiple seminars are open at the same time. Fix overlapping schedule.',
        ];
    }

    if (count($open) === 1) {
        $meta = $open[0];
        return [
            'status' => 'open',
            'source' => 'session',
            'event' => $eventSummary,
            'session' => scan_context_session_summary((array) $meta['session']),
            'opens_at' => $meta['opens_at'],
            'closes_at' => $meta['closes_at'],
            'window_minutes' => $meta['window_minutes'],
            'message' => 'Seminar scanning is open.',
        ];
    }

    if (!empty($upcoming)) {
        usort($upcoming, static function (array $a, array $b): int {
            return strcmp((string) ($a['opens_at'] ?? ''), (string) ($b['opens_at'] ?? ''));
        });
        $meta = $upcoming[0];
        return [
            'status' => 'waiting',
            'source' => 'session',
            'event' => $eventSummary,
            'session' => scan_context_session_summary((array) $meta['session']),
            'opens_at' => $meta['opens_at'],
            'closes_at' => $meta['closes_at'],
            'window_minutes' => $meta['window_minutes'],
            'message' => 'Waiting for seminar scan window.',
        ];
    }

    usort($closed, static function (array $a, array $b): int {
        return strcmp((string) ($b['closes_at'] ?? ''), (string) ($a['closes_at'] ?? ''));
    });
    $meta = $closed[0] ?? null;

    return [
        'status' => 'closed',
        'source' => 'session',
        'event' => $eventSummary,
        'session' => is_array($meta) ? scan_context_session_summary((array) $meta['session']) : null,
        'opens_at' => is_array($meta) ? (string) ($meta['opens_at'] ?? '') : null,
        'closes_at' => is_array($meta) ? (string) ($meta['closes_at'] ?? '') : null,
        'window_minutes' => is_array($meta) ? (int) ($meta['window_minutes'] ?? 30) : 30,
        'message' => 'Seminar scan window has closed.',
    ];
}

function resolve_simple_event_scan_context(array $event, DateTimeImmutable $nowUtc): array
{
    $eventSummary = scan_context_event_summary($event);
    $startAt = parse_iso_datetime((string) ($event['start_at'] ?? ''));
    if (!$startAt) {
        return [
            'status' => 'missing_schedule',
            'source' => 'event',
            'event' => $eventSummary,
            'session' => null,
            'opens_at' => null,
            'closes_at' => null,
            'window_minutes' => SIMPLE_EVENT_SCAN_WINDOW_MINUTES,
            'message' => 'Event start time is missing.',
        ];
    }

    $windowMinutes = SIMPLE_EVENT_SCAN_WINDOW_MINUTES;
    $windowEnd = $startAt->modify('+' . $windowMinutes . ' minutes');

    if ($nowUtc < $startAt) {
        return [
            'status' => 'waiting',
            'source' => 'event',
            'event' => $eventSummary,
            'session' => null,
            'opens_at' => $startAt->format('c'),
            'closes_at' => $windowEnd->format('c'),
            'window_minutes' => $windowMinutes,
            'message' => 'Waiting for event scan window.',
        ];
    }

    if ($nowUtc <= $windowEnd) {
        return [
            'status' => 'open',
            'source' => 'event',
            'event' => $eventSummary,
            'session' => null,
            'opens_at' => $startAt->format('c'),
            'closes_at' => $windowEnd->format('c'),
            'window_minutes' => $windowMinutes,
            'message' => 'Event scanning is open.',
        ];
    }

    return [
        'status' => 'closed',
        'source' => 'event',
        'event' => $eventSummary,
        'session' => null,
        'opens_at' => $startAt->format('c'),
        'closes_at' => $windowEnd->format('c'),
        'window_minutes' => $windowMinutes,
        'message' => 'Event scan window has closed.',
    ];
}

function resolve_event_scan_context(array $event, DateTimeImmutable $nowUtc, array $headers): array
{
    if (event_uses_sessions($event)) {
        return resolve_session_scan_context($event, $nowUtc, $headers);
    }

    return resolve_simple_event_scan_context($event, $nowUtc);
}

function load_teacher_scan_assignments(string $teacherId, array $headers): array
{
    if (trim($teacherId) === '') {
        return [];
    }

    $select = rawurlencode('event_id,events!inner(id,title,status,start_at,end_at,location,event_mode,event_structure,grace_time)');
    $url = rtrim(SUPABASE_URL, '/') . '/rest/v1/event_teacher_assignments'
        . '?select=' . $select
        . '&teacher_id=eq.' . rawurlencode($teacherId)
        . '&can_scan=eq.true'
        . '&events.status=eq.published';

    $res = supabase_request('GET', $url, $headers);
    if (!$res['ok']) {
        throw new RuntimeException(build_error($res['body'] ?? null, (int) ($res['status'] ?? 0), $res['error'] ?? null, 'Failed to load scanner assignments'));
    }

    $rows = json_decode((string) $res['body'], true);
    if (!is_array($rows)) {
        return [];
    }

    $events = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $event = $row['events'] ?? null;
        if (is_array($event) && trim((string) ($event['id'] ?? '')) !== '') {
            $events[] = $event;
        }
    }

    return $events;
}

function select_best_scan_context(array $contexts): array
{
    if (empty($contexts)) {
        return [
            'status' => 'no_assignment',
            'context' => null,
            'message' => 'No scanning assignment found for your account.',
        ];
    }

    $open = array_values(array_filter($contexts, static fn(array $ctx): bool => (string) ($ctx['status'] ?? '') === 'open'));
    if (count($open) > 1) {
        return [
            'status' => 'conflict',
            'context' => null,
            'message' => 'Multiple assigned events are open at the same time. Contact admin.',
        ];
    }
    if (count($open) === 1) {
        return [
            'status' => 'open',
            'context' => $open[0],
            'message' => (string) ($open[0]['message'] ?? 'Scanning is open.'),
        ];
    }

    $waiting = array_values(array_filter($contexts, static fn(array $ctx): bool => (string) ($ctx['status'] ?? '') === 'waiting'));
    if (!empty($waiting)) {
        usort($waiting, static function (array $a, array $b): int {
            return strcmp((string) ($a['opens_at'] ?? ''), (string) ($b['opens_at'] ?? ''));
        });
        return [
            'status' => 'waiting',
            'context' => $waiting[0],
            'message' => (string) (($waiting[0]['message'] ?? 'Waiting for scan window.') ?: 'Waiting for scan window.'),
        ];
    }

    $closed = array_values(array_filter($contexts, static fn(array $ctx): bool => (string) ($ctx['status'] ?? '') === 'closed'));
    if (!empty($closed)) {
        usort($closed, static function (array $a, array $b): int {
            return strcmp((string) ($b['closes_at'] ?? ''), (string) ($a['closes_at'] ?? ''));
        });
        return [
            'status' => 'closed',
            'context' => $closed[0],
            'message' => (string) (($closed[0]['message'] ?? 'Scan window is closed.') ?: 'Scan window is closed.'),
        ];
    }

    $missingSchedule = array_values(array_filter($contexts, static fn(array $ctx): bool => (string) ($ctx['status'] ?? '') === 'missing_schedule'));
    if (!empty($missingSchedule)) {
        return [
            'status' => 'missing_schedule',
            'context' => $missingSchedule[0],
            'message' => (string) (($missingSchedule[0]['message'] ?? 'Assigned event has incomplete schedule.') ?: 'Assigned event has incomplete schedule.'),
        ];
    }

    $conflicts = array_values(array_filter($contexts, static fn(array $ctx): bool => (string) ($ctx['status'] ?? '') === 'conflict'));
    if (!empty($conflicts)) {
        return [
            'status' => 'conflict',
            'context' => $conflicts[0],
            'message' => (string) (($conflicts[0]['message'] ?? 'Schedule conflict detected.') ?: 'Schedule conflict detected.'),
        ];
    }

    return [
        'status' => 'closed',
        'context' => $contexts[0],
        'message' => 'Scan window is currently unavailable.',
    ];
}

function resolve_user_scan_context(array $user, DateTimeImmutable $nowUtc, array $headers): array
{
    $role = (string) ($user['role'] ?? 'student');
    $userId = (string) ($user['id'] ?? '');
    if ($userId === '') {
        return [
            'status' => 'no_assignment',
            'scanner_enabled' => false,
            'context' => null,
            'message' => 'User session is missing an ID.',
            'assignments' => 0,
        ];
    }

    // Teachers and admins both follow assignment-based scanner context.
    if (!in_array($role, ['teacher', 'admin'], true)) {
        return [
            'status' => 'forbidden',
            'scanner_enabled' => false,
            'context' => null,
            'message' => 'Only teacher/admin roles can use scanner.',
            'assignments' => 0,
        ];
    }

    $events = load_teacher_scan_assignments($userId, $headers);
    if (empty($events)) {
        return [
            'status' => 'no_assignment',
            'scanner_enabled' => false,
            'context' => null,
            'message' => 'No published scan assignment found.',
            'assignments' => 0,
        ];
    }

    $contexts = [];
    foreach ($events as $event) {
        if (!is_array($event)) {
            continue;
        }
        $contexts[] = resolve_event_scan_context($event, $nowUtc, $headers);
    }

    $selected = select_best_scan_context($contexts);
    $status = (string) ($selected['status'] ?? 'closed');
    return [
        'status' => $status,
        'scanner_enabled' => $status === 'open',
        'context' => $selected['context'] ?? null,
        'message' => (string) ($selected['message'] ?? ''),
        'assignments' => count($events),
    ];
}

function teacher_can_scan_event(string $teacherId, string $eventId, array $headers): bool
{
    if (trim($teacherId) === '' || trim($eventId) === '') {
        return false;
    }

    $url = rtrim(SUPABASE_URL, '/') . '/rest/v1/event_teacher_assignments'
        . '?select=id'
        . '&teacher_id=eq.' . rawurlencode($teacherId)
        . '&event_id=eq.' . rawurlencode($eventId)
        . '&can_scan=eq.true'
        . '&limit=1';

    $res = supabase_request('GET', $url, $headers);
    if (!$res['ok']) {
        return false;
    }

    $rows = json_decode((string) $res['body'], true);
    return is_array($rows) && isset($rows[0]) && is_array($rows[0]) && !empty($rows[0]['id']);
}

