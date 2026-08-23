<?php
declare(strict_types=1);

require_once __DIR__ . '/event_sessions.php';

$attendanceWindowsPath = __DIR__ . '/event_attendance_windows.php';
if (is_file($attendanceWindowsPath)) {
    require_once $attendanceWindowsPath;
}

/**
 * Default time-in window (minutes) when events.grace_time is missing/invalid.
 * Seminar-based events use per-session scan_window_minutes.
 */
const SIMPLE_EVENT_SCAN_WINDOW_MINUTES = 30;

/**
 * Resolve simple-event time-in window minutes from stored grace_time.
 */
function simple_event_grace_minutes(array $event): int
{
    if (!array_key_exists('grace_time', $event) || $event['grace_time'] === null || $event['grace_time'] === '') {
        return SIMPLE_EVENT_SCAN_WINDOW_MINUTES;
    }
    $parsed = (int) $event['grace_time'];
    if ($parsed <= 0) {
        return SIMPLE_EVENT_SCAN_WINDOW_MINUTES;
    }
    return max(1, $parsed);
}

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
        'early_out_enabled_at' => isset($session['early_out_enabled_at'])
            ? (string) $session['early_out_enabled_at']
            : null,
    ];
}

if (!function_exists('parse_iso_datetime')) {
    function parse_iso_datetime(?string $raw): ?DateTimeImmutable
    {
        $raw = trim((string) $raw);
        if ($raw === '') {
            return null;
        }

        try {
            return new DateTimeImmutable($raw);
        } catch (Throwable $e) {
            return null;
        }
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

    $sessions = is_array($event['sessions'] ?? null) ? $event['sessions'] : fetch_event_sessions($eventId, $headers);
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

    // Attach per-seminar Early Out flags (same concept as simple event early_out_enabled_at).
    if (function_exists('attendance_lazy_clear_early_out') && function_exists('attendance_early_out_is_active')) {
        $eoUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/event_sessions'
            . '?select=id,early_out_enabled_at&event_id=eq.' . rawurlencode($eventId);
        $eoRes = supabase_request('GET', $eoUrl, $headers);
        $eoMap = [];
        if (($eoRes['ok'] ?? false) === true) {
            $eoRows = json_decode((string) ($eoRes['body'] ?? ''), true);
            if (is_array($eoRows)) {
                foreach ($eoRows as $row) {
                    if (!is_array($row) || empty($row['id'])) {
                        continue;
                    }
                    $sid = (string) $row['id'];
                    attendance_lazy_clear_early_out('event_sessions', $sid, $row['early_out_enabled_at'] ?? null, $nowUtc, $headers);
                    $eoMap[$sid] = attendance_early_out_is_active((string) ($row['early_out_enabled_at'] ?? ''), $nowUtc)
                        ? (string) $row['early_out_enabled_at']
                        : null;
                }
            }
        }
        foreach ($sessions as &$sessionRow) {
            if (!is_array($sessionRow)) {
                continue;
            }
            $sid = (string) ($sessionRow['id'] ?? '');
            if ($sid === '') {
                continue;
            }
            if (array_key_exists($sid, $eoMap)) {
                $sessionRow['early_out_enabled_at'] = $eoMap[$sid];
            } elseif (!array_key_exists('early_out_enabled_at', $sessionRow)) {
                $sessionRow['early_out_enabled_at'] = null;
            } elseif (!attendance_early_out_is_active((string) ($sessionRow['early_out_enabled_at'] ?? ''), $nowUtc)) {
                $sessionRow['early_out_enabled_at'] = null;
            }
        }
        unset($sessionRow);
    }

    $open = [];
    $upcoming = [];
    $closed = [];
    $outOpen = [];
    $outWaiting = [];

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

        // After seminar grace: Early Out / end+1h time-out (same rules as simple events).
        if (function_exists('attendance_check_out_window')) {
            $endAt = parse_iso_datetime((string) ($session['end_at'] ?? ''));
            $outWin = attendance_check_out_window(
                $endAt,
                isset($session['early_out_enabled_at']) ? (string) $session['early_out_enabled_at'] : null,
                $nowUtc
            );
            $outMeta = $meta + [
                'out_window' => $outWin,
                'out_opens_at' => $outWin['opens_at'] ?? null,
                'out_closes_at' => $outWin['closes_at'] ?? null,
            ];
            if (($outWin['open'] ?? false) === true) {
                $outOpen[] = $outMeta;
            } elseif ((string) ($outWin['status'] ?? '') === 'too_early_checkout') {
                $outWaiting[] = $outMeta;
            }
        }
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
            'scan_mode' => 'check_in',
            'message' => 'Seminar scanning is open.',
        ];
    }

    if (count($outOpen) > 1) {
        return [
            'status' => 'conflict',
            'source' => 'session',
            'event' => $eventSummary,
            'session' => null,
            'opens_at' => null,
            'closes_at' => null,
            'window_minutes' => null,
            'scan_mode' => 'check_out',
            'message' => 'Multiple seminars are open for time-out. Fix overlapping schedule.',
        ];
    }

    if (count($outOpen) === 1) {
        $meta = $outOpen[0];
        $outWin = is_array($meta['out_window'] ?? null) ? $meta['out_window'] : [];
        return [
            'status' => 'open',
            'source' => 'session',
            'event' => $eventSummary,
            'session' => scan_context_session_summary((array) $meta['session']),
            'opens_at' => $meta['out_opens_at'] ?? $meta['opens_at'],
            'closes_at' => $meta['out_closes_at'] ?? $meta['closes_at'],
            'window_minutes' => 60,
            'scan_mode' => 'check_out',
            'message' => (string) ($outWin['message'] ?? 'Seminar time-out is open.'),
        ];
    }

    // Prefer waiting for the next seminar time-in when no timeout is open yet.
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

        // If a prior seminar is waiting for Early Out / scheduled end, surface that
        // when the next seminar has not started yet and grace already ended.
        if (!empty($outWaiting)) {
            usort($outWaiting, static function (array $a, array $b): int {
                return strcmp((string) ($b['closes_at'] ?? ''), (string) ($a['closes_at'] ?? ''));
            });
            $waitOut = $outWaiting[0];
            $outWin = is_array($waitOut['out_window'] ?? null) ? $waitOut['out_window'] : [];
            return [
                'status' => 'closed',
                'source' => 'session',
                'event' => $eventSummary,
                'session' => scan_context_session_summary((array) $waitOut['session']),
                'opens_at' => $waitOut['out_opens_at'] ?? $waitOut['opens_at'],
                'closes_at' => $waitOut['out_closes_at'] ?? $waitOut['closes_at'],
                'window_minutes' => $waitOut['window_minutes'],
                'scan_mode' => 'check_out',
                'message' => (string) ($outWin['message'] ?? 'Too early to time out for this seminar.'),
            ];
        }

        return [
            'status' => 'waiting',
            'source' => 'session',
            'event' => $eventSummary,
            'session' => scan_context_session_summary((array) $meta['session']),
            'opens_at' => $meta['opens_at'],
            'closes_at' => $meta['closes_at'],
            'window_minutes' => $meta['window_minutes'],
            'scan_mode' => 'check_in',
            'message' => 'Waiting for seminar scan window.',
        ];
    }

    try {
        sync_session_event_absences($eventId, $sessions, $nowUtc);
    } catch (Throwable $e) {
        // Keep scanner status available even if absence sync fails.
    }

    if (!empty($outWaiting)) {
        usort($outWaiting, static function (array $a, array $b): int {
            return strcmp((string) ($b['closes_at'] ?? ''), (string) ($a['closes_at'] ?? ''));
        });
        $meta = $outWaiting[0];
        $outWin = is_array($meta['out_window'] ?? null) ? $meta['out_window'] : [];
        return [
            'status' => 'closed',
            'source' => 'session',
            'event' => $eventSummary,
            'session' => scan_context_session_summary((array) $meta['session']),
            'opens_at' => $meta['out_opens_at'] ?? $meta['opens_at'],
            'closes_at' => $meta['out_closes_at'] ?? $meta['closes_at'],
            'window_minutes' => $meta['window_minutes'],
            'scan_mode' => 'check_out',
            'message' => (string) ($outWin['message'] ?? 'Too early to time out for this seminar.'),
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
            'window_minutes' => simple_event_grace_minutes($event),
            'message' => 'Event start time is missing.',
        ];
    }

    $windowMinutes = simple_event_grace_minutes($event);
    $windowEnd = $startAt->modify('+' . $windowMinutes . ' minutes');

    if ($nowUtc < $startAt) {
        $startClock = function_exists('attendance_format_manila_time')
            ? attendance_format_manila_time($startAt)
            : $startAt->setTimezone(new DateTimeZone('Asia/Manila'))->format('g:i A');
        $sameDay = function_exists('attendance_same_manila_day') && attendance_same_manila_day($nowUtc, $startAt);
        return [
            'status' => 'waiting',
            'source' => 'event',
            'event' => $eventSummary,
            'session' => null,
            'opens_at' => $startAt->format('c'),
            'closes_at' => $windowEnd->format('c'),
            'window_minutes' => $windowMinutes,
            'message' => $sameDay
                ? ('Too early to time in. Wait for the scheduled start (' . $startClock . ').')
                : 'Too early to time in. Wait for the scheduled start.',
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
            'scan_mode' => 'check_in',
            'message' => 'Event scanning is open.',
        ];
    }

    // After time-in grace: keep scanner open for Early Out / normal time-out.
    if (function_exists('attendance_check_out_window')) {
        $endAt = parse_iso_datetime((string) ($event['end_at'] ?? ''));
        $outWin = attendance_check_out_window(
            $endAt,
            isset($event['early_out_enabled_at']) ? (string) $event['early_out_enabled_at'] : null,
            $nowUtc
        );
        if (($outWin['open'] ?? false) === true) {
            return [
                'status' => 'open',
                'source' => 'event',
                'event' => $eventSummary,
                'session' => null,
                'opens_at' => $outWin['opens_at'] ?? null,
                'closes_at' => $outWin['closes_at'] ?? null,
                'window_minutes' => 60,
                'scan_mode' => 'check_out',
                'message' => (string) ($outWin['message'] ?? 'Time-out is open.'),
            ];
        }

        // Between grace end and scheduled end (no Early Out): explain time-out wait.
        $outStatus = (string) ($outWin['status'] ?? 'closed');
        if ($outStatus === 'too_early_checkout') {
            return [
                'status' => 'closed',
                'source' => 'event',
                'event' => $eventSummary,
                'session' => null,
                'opens_at' => $outWin['opens_at'] ?? $startAt->format('c'),
                'closes_at' => $outWin['closes_at'] ?? $windowEnd->format('c'),
                'window_minutes' => $windowMinutes,
                'scan_mode' => 'check_out',
                'message' => (string) ($outWin['message'] ?? 'Too early to time out.'),
            ];
        }
        if ($outStatus === 'closed' && trim((string) ($outWin['message'] ?? '')) !== '') {
            return [
                'status' => 'closed',
                'source' => 'event',
                'event' => $eventSummary,
                'session' => null,
                'opens_at' => $outWin['opens_at'] ?? $startAt->format('c'),
                'closes_at' => $outWin['closes_at'] ?? $windowEnd->format('c'),
                'window_minutes' => $windowMinutes,
                'scan_mode' => 'check_out',
                'message' => (string) $outWin['message'],
            ];
        }
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
        'message' => 'Time-in grace ended at '
            . (function_exists('attendance_format_manila_time')
                ? attendance_format_manila_time($windowEnd)
                : $windowEnd->format('g:i A'))
            . '. You can no longer time in for this schedule.',
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

    $windowEnd = $startAt->modify('+' . simple_event_grace_minutes($event) . ' minutes');
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
    // Production stores per-session rows in event_session_attendance only.
    // Do not fall back to attendance.session_id (column does not exist → 42703 ERROR).
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
    $eventId = trim((string) ($event['id'] ?? ''));
    $sessions = [];
    if ($eventId !== '' && function_exists('fetch_event_sessions')) {
        $sessions = fetch_event_sessions($eventId, $headers);
    }
    // Prefer seminar windows whenever session rows exist, even if event_mode is still "simple".
    if (event_uses_sessions(array_merge($event, ['sessions' => $sessions]))) {
        return resolve_session_scan_context(array_merge($event, ['sessions' => $sessions]), $nowUtc, $headers);
    }

    $event = scan_context_attach_event_early_out($event, $nowUtc, $headers);
    return resolve_simple_event_scan_context($event, $nowUtc);
}

function load_teacher_scan_assignments(string $teacherId, array $headers): array
{
    if (trim($teacherId) === '') {
        return [];
    }

    $select = rawurlencode(
        'event_id,can_scan,can_manage_assistants,'
        . 'events!inner(id,title,status,start_at,end_at,location,event_mode,event_structure,grace_time,early_out_enabled_at)'
    );
    $url = rtrim(SUPABASE_URL, '/') . '/rest/v1/event_teacher_assignments'
        . '?select=' . $select
        . '&teacher_id=eq.' . rawurlencode($teacherId)
        . '&events.status=eq.published'
        . '&or=(can_scan.eq.true,can_manage_assistants.eq.true)';

    $res = supabase_request('GET', $url, $headers);
    if (!$res['ok']) {
        // Legacy schema without can_manage_assistants / early_out.
        $selectLegacy = rawurlencode(
            'event_id,can_scan,'
            . 'events!inner(id,title,status,start_at,end_at,location,event_mode,event_structure,grace_time)'
        );
        $url = rtrim(SUPABASE_URL, '/') . '/rest/v1/event_teacher_assignments'
            . '?select=' . $selectLegacy
            . '&teacher_id=eq.' . rawurlencode($teacherId)
            . '&can_scan=eq.true'
            . '&events.status=eq.published';
        $res = supabase_request('GET', $url, $headers);
        if (!$res['ok']) {
            throw new RuntimeException(build_error($res['body'] ?? null, (int) ($res['status'] ?? 0), $res['error'] ?? null, 'Failed to load scanner assignments'));
        }
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

/**
 * Student assistants assigned to scan tickets (allow_scan + assigned_by_teacher_id).
 *
 * @return list<array>
 */
function load_assistant_scan_assignments(string $studentId, array $headers): array
{
    if (trim($studentId) === '') {
        return [];
    }

    $select = rawurlencode(
        'event_id,allow_scan,assigned_by_teacher_id,'
        . 'events!inner(id,title,status,start_at,end_at,location,event_mode,event_structure,grace_time,early_out_enabled_at)'
    );
    $url = rtrim(SUPABASE_URL, '/') . '/rest/v1/event_assistants'
        . '?select=' . $select
        . '&student_id=eq.' . rawurlencode($studentId)
        . '&allow_scan=eq.true'
        . '&events.status=eq.published';

    $res = supabase_request('GET', $url, $headers);
    if (!$res['ok']) {
        $selectLegacy = rawurlencode(
            'event_id,allow_scan,'
            . 'events!inner(id,title,status,start_at,end_at,location,grace_time)'
        );
        $url = rtrim(SUPABASE_URL, '/') . '/rest/v1/event_assistants'
            . '?select=' . $selectLegacy
            . '&student_id=eq.' . rawurlencode($studentId)
            . '&allow_scan=eq.true'
            . '&events.status=eq.published';
        $res = supabase_request('GET', $url, $headers);
        if (!$res['ok']) {
            throw new RuntimeException(build_error($res['body'] ?? null, (int) ($res['status'] ?? 0), $res['error'] ?? null, 'Failed to load assistant scanner assignments'));
        }
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

/**
 * Attach / refresh early_out_enabled_at on a simple (non-seminar) event row.
 */
function scan_context_attach_event_early_out(array $event, DateTimeImmutable $nowUtc, array $headers): array
{
    $eventId = trim((string) ($event['id'] ?? ''));
    if ($eventId === '' || !function_exists('attendance_early_out_is_active')) {
        return $event;
    }

    $url = rtrim(SUPABASE_URL, '/') . '/rest/v1/events'
        . '?select=early_out_enabled_at&id=eq.' . rawurlencode($eventId) . '&limit=1';
    $res = supabase_request('GET', $url, $headers);
    if (!($res['ok'] ?? false)) {
        return $event;
    }
    $rows = json_decode((string) ($res['body'] ?? ''), true);
    $raw = is_array($rows) && isset($rows[0]) ? ($rows[0]['early_out_enabled_at'] ?? null) : null;
    if (function_exists('attendance_lazy_clear_early_out')) {
        attendance_lazy_clear_early_out('events', $eventId, is_string($raw) ? $raw : null, $nowUtc, $headers);
    }
    $event['early_out_enabled_at'] = attendance_early_out_is_active(is_string($raw) ? $raw : null, $nowUtc)
        ? (string) $raw
        : null;

    return $event;
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
    $role = strtolower(trim((string) ($user['role'] ?? 'student')));
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

    if ($role === 'student') {
        $events = load_assistant_scan_assignments($userId, $headers);
        if (empty($events)) {
            return [
                'status' => 'no_assignment',
                'scanner_enabled' => false,
                'context' => null,
                'message' => 'No published scan assignment found.',
                'assignments' => 0,
            ];
        }
    } elseif (in_array($role, ['teacher', 'admin', 'super_admin'], true)) {
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
    } else {
        return [
            'status' => 'forbidden',
            'scanner_enabled' => false,
            'context' => null,
            'message' => 'Only teacher/admin/assistant roles can use scanner.',
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
