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

    // If a previous seminar's scan window already closed but a later seminar
    // is still upcoming, show Waiting for the next seminar (same-day gap).
    if (!empty($upcoming)) {
        try {
            sync_session_event_absences($eventId, $sessions, $nowUtc);
        } catch (Throwable $e) {
            // Keep scanner status available even if absence sync fails.
        }

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

    try {
        sync_session_event_absences($eventId, $sessions, $nowUtc);
    } catch (Throwable $e) {
        // Keep scanner status available even if absence sync fails.
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

    try {
        sync_simple_event_absences($event, $nowUtc);
    } catch (Throwable $e) {
        // Keep scanner status available even if absence sync fails.
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

function sync_simple_event_absences(array $event, DateTimeImmutable $nowUtc): void
{
    $eventId = trim((string) ($event['id'] ?? ''));
    if ($eventId === '') {
        return;
    }

    $startAt = parse_iso_datetime((string) ($event['start_at'] ?? ''));
    if (!$startAt) {
        return;
    }

    $windowEnd = $startAt->modify('+' . SIMPLE_EVENT_SCAN_WINDOW_MINUTES . ' minutes');
    if ($nowUtc <= $windowEnd) {
        return;
    }

    $headers = [
        'Accept: application/json',
        'apikey: ' . SUPABASE_KEY,
        'Authorization: Bearer ' . SUPABASE_KEY,
    ];
    $jsonHeaders = [
        'Content-Type: application/json',
        'Accept: application/json',
        'apikey: ' . SUPABASE_KEY,
        'Authorization: Bearer ' . SUPABASE_KEY,
        'Prefer: return=representation',
    ];

    $registrationsUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/event_registrations'
        . '?select=id,tickets(id,attendance(id,status,check_in_at,last_scanned_at))'
        . '&event_id=eq.' . rawurlencode($eventId)
        . '&limit=1000';
    $registrationsRes = supabase_request('GET', $registrationsUrl, $headers);
    if (!$registrationsRes['ok']) {
        return;
    }

    $registrations = json_decode((string) $registrationsRes['body'], true);
    if (!is_array($registrations)) {
        return;
    }

    $syncNowIso = $nowUtc->format('c');
    foreach ($registrations as $registration) {
        if (!is_array($registration)) {
            continue;
        }

        $tickets = isset($registration['tickets']) && is_array($registration['tickets']) ? $registration['tickets'] : [];
        $ticket = isset($tickets[0]) && is_array($tickets[0]) ? $tickets[0] : [];
        $ticketId = trim((string) ($ticket['id'] ?? ''));
        if ($ticketId === '') {
            continue;
        }

        $attendance = null;
        if (isset($ticket['attendance']) && is_array($ticket['attendance'])) {
            $rows = $ticket['attendance'];
            $attendance = isset($rows[0]) && is_array($rows[0]) ? $rows[0] : (is_array($rows) ? $rows : null);
        }

        $status = strtolower(trim((string) (is_array($attendance) ? ($attendance['status'] ?? '') : '')));
        $checkInAt = trim((string) (is_array($attendance) ? ($attendance['check_in_at'] ?? '') : ''));
        if ($checkInAt !== '' || in_array($status, ['present', 'scanned', 'late', 'early'], true)) {
            continue;
        }
        if ($status === 'absent') {
            continue;
        }

        $attendanceId = trim((string) (is_array($attendance) ? ($attendance['id'] ?? '') : ''));
        $updated = false;
        if ($attendanceId !== '') {
            $patchByIdUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/attendance'
                . '?id=eq.' . rawurlencode($attendanceId)
                . '&check_in_at=is.null'
                . '&select=id';
            $patchByIdRes = supabase_request(
                'PATCH',
                $patchByIdUrl,
                $jsonHeaders,
                json_encode([
                    'status' => 'absent',
                    'last_scanned_at' => $syncNowIso,
                ], JSON_UNESCAPED_SLASHES)
            );
            if ($patchByIdRes['ok']) {
                $patched = json_decode((string) $patchByIdRes['body'], true);
                $updated = is_array($patched) && isset($patched[0]) && is_array($patched[0]);
            }
        }

        if (!$updated) {
            $patchByTicketUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/attendance'
                . '?ticket_id=eq.' . rawurlencode($ticketId)
                . '&check_in_at=is.null'
                . '&select=id';
            $patchByTicketRes = supabase_request(
                'PATCH',
                $patchByTicketUrl,
                $jsonHeaders,
                json_encode([
                    'status' => 'absent',
                    'last_scanned_at' => $syncNowIso,
                ], JSON_UNESCAPED_SLASHES)
            );
            if ($patchByTicketRes['ok']) {
                $patched = json_decode((string) $patchByTicketRes['body'], true);
                $updated = is_array($patched) && isset($patched[0]) && is_array($patched[0]);
            }
        }

        if (!$updated) {
            $insertUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/attendance?select=id';
            supabase_request(
                'POST',
                $insertUrl,
                $jsonHeaders,
                json_encode([[
                    'ticket_id' => $ticketId,
                    'status' => 'absent',
                    'last_scanned_at' => $syncNowIso,
                ]], JSON_UNESCAPED_SLASHES)
            );
        }
    }
}

function sync_session_event_absences(string $eventId, array $sessions, DateTimeImmutable $nowUtc): void
{
    if (trim($eventId) === '' || empty($sessions)) {
        return;
    }

    $sessionMeta = [];
    $sessionIds = [];
    foreach ($sessions as $session) {
        if (!is_array($session)) {
            continue;
        }
        $sessionId = trim((string) ($session['id'] ?? ''));
        $startAt = parse_iso_datetime((string) ($session['start_at'] ?? ''));
        if ($sessionId === '' || !$startAt) {
            continue;
        }
        $windowMinutes = max(1, (int) ($session['scan_window_minutes'] ?? 30));
        $windowEnd = $startAt->modify('+' . $windowMinutes . ' minutes');
        $sessionMeta[$sessionId] = [
            'closed' => $nowUtc > $windowEnd,
            'window_end' => $windowEnd,
        ];
        $sessionIds[] = '"' . $sessionId . '"';
    }
    if (empty($sessionMeta) || empty($sessionIds)) {
        return;
    }

    $closedSessionIds = array_keys(array_filter(
        $sessionMeta,
        static fn(array $meta): bool => !empty($meta['closed'])
    ));
    if (empty($closedSessionIds)) {
        return;
    }

    $headers = [
        'Accept: application/json',
        'apikey: ' . SUPABASE_KEY,
        'Authorization: Bearer ' . SUPABASE_KEY,
    ];
    $jsonHeaders = [
        'Content-Type: application/json',
        'Accept: application/json',
        'apikey: ' . SUPABASE_KEY,
        'Authorization: Bearer ' . SUPABASE_KEY,
        'Prefer: return=representation',
    ];

    $registrationsUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/event_registrations'
        . '?select=id,tickets(id)'
        . '&event_id=eq.' . rawurlencode($eventId)
        . '&limit=1000';
    $registrationsRes = supabase_request('GET', $registrationsUrl, $headers);
    if (!$registrationsRes['ok']) {
        return;
    }
    $registrations = json_decode((string) $registrationsRes['body'], true);
    if (!is_array($registrations) || empty($registrations)) {
        return;
    }

    $sessionFilter = implode(',', $sessionIds);
    // Some deployments store per-session attendance rows in `public.attendance`
    // (using the `session_id` column) instead of `public.event_session_attendance`.
    // Auto-absent must work for both schemas.
    $store = 'event_session_attendance';
    $attendanceRows = [];
    $attendanceRes = null;

    $attendanceUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/event_session_attendance'
        . '?select=id,session_id,registration_id,ticket_id,status,check_in_at,last_scanned_at'
        . '&session_id=in.(' . $sessionFilter . ')'
        . '&limit=5000';
    $attendanceRes = supabase_request('GET', $attendanceUrl, $headers);
    if ($attendanceRes['ok']) {
        $decoded = json_decode((string) $attendanceRes['body'], true);
        $attendanceRows = is_array($decoded) ? $decoded : [];
    } else {
        $status = (int) ($attendanceRes['status'] ?? 0);
        $bodyText = (string) ($attendanceRes['body'] ?? '');
        $looksLikeMissingTable =
            $status >= 400
            && (stripos($bodyText, 'event_session_attendance') !== false
                || stripos($bodyText, 'relation') !== false
                || stripos($bodyText, '42P01') !== false);
        if ($looksLikeMissingTable) {
            $store = 'attendance';
            $fallbackUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/attendance'
                . '?select=id,session_id,ticket_id,status,check_in_at,last_scanned_at'
                . '&session_id=in.(' . $sessionFilter . ')'
                . '&limit=5000';
            $fallbackRes = supabase_request('GET', $fallbackUrl, $headers);
            if ($fallbackRes['ok']) {
                $decoded = json_decode((string) $fallbackRes['body'], true);
                $attendanceRows = is_array($decoded) ? $decoded : [];
            }
        }
    }

    $selectFields = $store === 'event_session_attendance'
        ? 'id,session_id,registration_id,ticket_id,status,check_in_at,last_scanned_at'
        : 'id,session_id,ticket_id,status,check_in_at,last_scanned_at';

    $byRegistrationSession = [];
    $byTicketSession = [];
    foreach ($attendanceRows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $sessionId = trim((string) ($row['session_id'] ?? ''));
        if ($sessionId === '') {
            continue;
        }
        $registrationId = trim((string) ($row['registration_id'] ?? ''));
        if ($registrationId !== '') {
            $byRegistrationSession[$registrationId][$sessionId] = $row;
        }
        $ticketId = trim((string) ($row['ticket_id'] ?? ''));
        if ($ticketId !== '') {
            $byTicketSession[$ticketId][$sessionId] = $row;
        }
    }

    $syncNowIso = $nowUtc->format('c');
    foreach ($registrations as $registration) {
        if (!is_array($registration)) {
            continue;
        }
        $registrationId = trim((string) ($registration['id'] ?? ''));
        if ($registrationId === '') {
            continue;
        }
        $tickets = isset($registration['tickets']) && is_array($registration['tickets']) ? $registration['tickets'] : [];
        $ticket = isset($tickets[0]) && is_array($tickets[0]) ? $tickets[0] : [];
        $ticketId = trim((string) ($ticket['id'] ?? ''));
        if ($ticketId === '') {
            continue;
        }

        foreach ($closedSessionIds as $sessionId) {
            $row = $byRegistrationSession[$registrationId][$sessionId]
                ?? $byTicketSession[$ticketId][$sessionId]
                ?? null;
            $status = strtolower(trim((string) (is_array($row) ? ($row['status'] ?? '') : '')));
            $checkInAt = trim((string) (is_array($row) ? ($row['check_in_at'] ?? '') : ''));
            if ($checkInAt !== '' || in_array($status, ['present', 'scanned', 'late', 'early', 'absent'], true)) {
                continue;
            }

            $attendanceId = trim((string) (is_array($row) ? ($row['id'] ?? '') : ''));
            $updatedRow = null;
            if ($attendanceId !== '') {
                $patchByIdUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/' . $store
                    . '?id=eq.' . rawurlencode($attendanceId)
                    . '&check_in_at=is.null'
                    . '&select=' . rawurlencode($selectFields);
                $patchByIdRes = supabase_request(
                    'PATCH',
                    $patchByIdUrl,
                    $jsonHeaders,
                    json_encode([
                        'status' => 'absent',
                        'last_scanned_at' => $syncNowIso,
                    ], JSON_UNESCAPED_SLASHES)
                );
                if ($patchByIdRes['ok']) {
                    $patched = json_decode((string) $patchByIdRes['body'], true);
                    if (is_array($patched) && isset($patched[0]) && is_array($patched[0])) {
                        $updatedRow = $patched[0];
                    }
                }
            }

            if (!is_array($updatedRow)) {
                // `attendance` fallback schema doesn't have `registration_id`.
                if ($store === 'event_session_attendance') {
                    $patchByRegUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/event_session_attendance'
                        . '?session_id=eq.' . rawurlencode($sessionId)
                        . '&registration_id=eq.' . rawurlencode($registrationId)
                        . '&check_in_at=is.null'
                        . '&select=id,session_id,registration_id,ticket_id,status,check_in_at,last_scanned_at';
                    $patchByRegRes = supabase_request(
                        'PATCH',
                        $patchByRegUrl,
                        $jsonHeaders,
                        json_encode([
                            'status' => 'absent',
                            'last_scanned_at' => $syncNowIso,
                        ], JSON_UNESCAPED_SLASHES)
                    );
                    if ($patchByRegRes['ok']) {
                        $patched = json_decode((string) $patchByRegRes['body'], true);
                        if (is_array($patched) && isset($patched[0]) && is_array($patched[0])) {
                            $updatedRow = $patched[0];
                        }
                    }
                }
            }

            if (!is_array($updatedRow)) {
                $patchByTicketUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/' . $store
                    . '?session_id=eq.' . rawurlencode($sessionId)
                    . '&ticket_id=eq.' . rawurlencode($ticketId)
                    . '&check_in_at=is.null'
                    . '&select=' . rawurlencode($selectFields);
                $patchByTicketRes = supabase_request(
                    'PATCH',
                    $patchByTicketUrl,
                    $jsonHeaders,
                    json_encode([
                        'status' => 'absent',
                        'last_scanned_at' => $syncNowIso,
                    ], JSON_UNESCAPED_SLASHES)
                );
                if ($patchByTicketRes['ok']) {
                    $patched = json_decode((string) $patchByTicketRes['body'], true);
                    if (is_array($patched) && isset($patched[0]) && is_array($patched[0])) {
                        $updatedRow = $patched[0];
                    }
                }
            }

            if (!is_array($updatedRow)) {
                $insertUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/' . $store
                    . '?select=' . rawurlencode($selectFields);
                $payload = [
                    'session_id' => $sessionId,
                    'ticket_id' => $ticketId,
                    'status' => 'absent',
                    'last_scanned_at' => $syncNowIso,
                ];
                if ($store === 'event_session_attendance') {
                    $payload['registration_id'] = $registrationId;
                }
                $insertRes = supabase_request(
                    'POST',
                    $insertUrl,
                    $jsonHeaders,
                    json_encode([ $payload ], JSON_UNESCAPED_SLASHES)
                );
                if ($insertRes['ok']) {
                    $inserted = json_decode((string) $insertRes['body'], true);
                    if (is_array($inserted) && isset($inserted[0]) && is_array($inserted[0])) {
                        $updatedRow = $inserted[0];
                    }
                }
            }

            if (is_array($updatedRow)) {
                $byRegistrationSession[$registrationId][$sessionId] = $updatedRow;
                $byTicketSession[$ticketId][$sessionId] = $updatedRow;
            }
        }
    }
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
