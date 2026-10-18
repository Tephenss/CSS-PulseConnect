<?php
declare(strict_types=1);

require_once __DIR__ . '/event_session_targeting.php';

/**
 * Aggregated scanner attendance % (counts only — no PII).
 * Teacher / assigned assistant scanners, online live indicator.
 */

function mobile_scan_stats_is_present(array $row): bool
{
    $status = strtolower(trim((string) ($row['status'] ?? '')));
    if (in_array($status, ['present', 'checked_in', 'in', 'late', 'early', 'scanned'], true)) {
        return true;
    }
    return trim((string) ($row['check_in_at'] ?? '')) !== '';
}

function mobile_scan_stats_is_checked_out(array $row): bool
{
    return trim((string) ($row['check_out_at'] ?? '')) !== '';
}

function mobile_scan_stats_clean_ids(array $ids): array
{
    $clean = [];
    foreach ($ids as $id) {
        $id = trim((string) $id);
        if ($id === '' || !preg_match('/^[0-9a-fA-F-]{8,}$/', $id)) {
            continue;
        }
        $clean[$id] = $id;
    }
    return array_values($clean);
}

function mobile_scan_stats_in_list(array $ids): string
{
    return implode(',', mobile_scan_stats_clean_ids($ids));
}

function mobile_scan_stats_fetch_rows(string $url, array $headers): array
{
    $res = supabase_request('GET', $url, $headers);
    if (!($res['ok'] ?? false)) {
        return [];
    }
    $rows = json_decode((string) ($res['body'] ?? ''), true);
    return is_array($rows) ? $rows : [];
}

function mobile_scan_stats_mark_present(array &$presentKeys, array $row): void
{
    if (!mobile_scan_stats_is_present($row)) {
        return;
    }
    $regId = trim((string) ($row['registration_id'] ?? ''));
    $ticketId = trim((string) ($row['ticket_id'] ?? ''));
    if ($regId !== '') {
        $presentKeys['r:' . $regId] = true;
        return;
    }
    if ($ticketId !== '') {
        $presentKeys['t:' . $ticketId] = true;
    }
}

function mobile_scan_stats_mark_checked_out(array &$checkedOutKeys, array $row): void
{
    if (!mobile_scan_stats_is_checked_out($row)) {
        return;
    }
    $regId = trim((string) ($row['registration_id'] ?? ''));
    $ticketId = trim((string) ($row['ticket_id'] ?? ''));
    if ($regId !== '') {
        $checkedOutKeys['r:' . $regId] = true;
        return;
    }
    if ($ticketId !== '') {
        $checkedOutKeys['t:' . $ticketId] = true;
    }
}

function mobile_scan_stats_session_registration_count(
    string $eventId,
    string $sessionId,
    array $headers
): int {
    if ($eventId === '' || $sessionId === '') {
        return 0;
    }

    return max(0, supabase_exact_count(
        'event_session_registrations',
        $headers,
        'event_id=eq.' . rawurlencode($eventId)
            . '&session_id=eq.' . rawurlencode($sessionId)
    ));
}

function mobile_scan_stats_fetch_event_sessions(string $eventId, array $headers): array
{
    $columnsToTry = [
        'id,event_id,title,start_at,end_at,target_participant,registration_limit,scan_window_minutes,sort_order',
        'id,event_id,title,start_at,end_at,target_participant,scan_window_minutes,sort_order',
        'id,event_id,title,start_at,end_at,target_participant,scan_window_minutes',
        'id,event_id,title,start_at,end_at,target_participant',
        'id,event_id,title,start_at,end_at',
        'id,event_id,title,start_at',
    ];
    foreach ($columnsToTry as $columns) {
        $url = rtrim(SUPABASE_URL, '/') . '/rest/v1/event_sessions'
            . '?select=' . rawurlencode($columns)
            . '&event_id=eq.' . rawurlencode(trim($eventId))
            . '&limit=10'
            . (str_contains($columns, 'sort_order')
                ? '&order=sort_order.asc,start_at.asc'
                : '&order=start_at.asc');
        $rows = mobile_scan_stats_fetch_rows($url, $headers);
        if ($rows === []) {
            continue;
        }

        $sessions = [];
        foreach ($rows as $row) {
            if (!is_array($row) || trim((string) ($row['id'] ?? '')) === '') {
                continue;
            }
            $row['registration_limit'] = isset($row['registration_limit'])
                ? max(1, (int) $row['registration_limit'])
                : null;
            $row['scan_window_minutes'] = max(1, (int) ($row['scan_window_minutes'] ?? 30));
            $sessions[] = $row;
        }
        if ($sessions !== []) {
            return $sessions;
        }
    }

    return [];
}

/** @return array{event:array,sessions:array}|null */
function mobile_scan_stats_split_event_context(string $eventId, array $headers): ?array
{
    static $cache = [];
    $eventId = trim($eventId);
    if ($eventId === '') {
        return null;
    }
    if (array_key_exists($eventId, $cache)) {
        return $cache[$eventId];
    }

    $event = null;
    foreach ([
        'id,event_for,session_attendance_rule,registration_limit',
        'id,event_for,session_attendance_rule',
        'id,event_for,registration_limit',
        'id,event_for',
    ] as $columns) {
        $eventRows = mobile_scan_stats_fetch_rows(
            rtrim(SUPABASE_URL, '/') . '/rest/v1/events'
                . '?select=' . rawurlencode($columns)
                . '&id=eq.' . rawurlencode($eventId)
                . '&limit=1',
            $headers
        );
        if (isset($eventRows[0]) && is_array($eventRows[0])) {
            $event = $eventRows[0];
            break;
        }
    }
    if (!is_array($event)) {
        $cache[$eventId] = null;
        return null;
    }

    $sessions = mobile_scan_stats_fetch_event_sessions($eventId, $headers);
    $eventWithSessions = $event;
    $eventWithSessions['sessions'] = $sessions;
    $distinctTargets = [];
    foreach ($sessions as $session) {
        if (!is_array($session)) {
            continue;
        }
        $target = strtoupper(trim((string) ($session['target_participant'] ?? '')));
        if ($target !== '') {
            $distinctTargets[$target] = true;
        }
    }
    $hasDistinctSeminarTargets = count($sessions) === 2 && count($distinctTargets) > 1;
    $hasPerSeminarCapacity = count($sessions) === 2 && array_reduce(
        $sessions,
        static fn (bool $found, array $session): bool => $found
            || (int) ($session['registration_limit'] ?? 0) > 0,
        false
    );
    if (!event_uses_split_session_attendance($eventWithSessions)
        && !$hasDistinctSeminarTargets
        && !$hasPerSeminarCapacity) {
        $cache[$eventId] = null;
        return null;
    }

    $cache[$eventId] = [
        'event' => $event,
        'sessions' => $sessions,
    ];
    return $cache[$eventId];
}

function mobile_scan_stats_resolve_split_session_id(
    string $eventId,
    string $requestedSessionId,
    array $headers,
    string $mode
): ?string {
    $context = mobile_scan_stats_split_event_context($eventId, $headers);
    if ($context === null) {
        return null;
    }

    $requestedSessionId = trim($requestedSessionId);
    if ($requestedSessionId !== '') {
        return $requestedSessionId;
    }

    $sessions = $context['sessions'];
    if (count($sessions) !== 2) {
        return '';
    }

    $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $open = [];
    $upcoming = [];
    $started = [];
    foreach ($sessions as $session) {
        if (!is_array($session)) {
            continue;
        }
        $sessionId = trim((string) ($session['id'] ?? ''));
        if ($sessionId === '') {
            continue;
        }
        try {
            $startAt = new DateTimeImmutable((string) ($session['start_at'] ?? ''));
        } catch (Throwable $e) {
            continue;
        }

        $startTimestamp = $startAt->getTimestamp();
        if ($startAt > $now) {
            $upcoming[] = ['id' => $sessionId, 'start' => $startTimestamp];
            continue;
        }
        $started[] = ['id' => $sessionId, 'start' => $startTimestamp];

        $windowMinutes = max(1, (int) ($session['scan_window_minutes'] ?? 30));
        if ($now <= $startAt->modify('+' . $windowMinutes . ' minutes')) {
            $open[] = ['id' => $sessionId, 'start' => $startTimestamp];
        }
    }

    if (strtolower(trim($mode)) === 'check_out' && $started !== []) {
        usort($started, static fn (array $a, array $b): int => $b['start'] <=> $a['start']);
        return $started[0]['id'];
    }
    if (count($open) === 1) {
        return $open[0]['id'];
    }
    if (count($open) > 1) {
        return '';
    }
    if ($upcoming !== []) {
        usort($upcoming, static fn (array $a, array $b): int => $a['start'] <=> $b['start']);
        return $upcoming[0]['id'];
    }
    if ($started !== []) {
        usort($started, static fn (array $a, array $b): int => $b['start'] <=> $a['start']);
        return $started[0]['id'];
    }
    return '';
}

/**
 * Return the number assigned to the active split seminar, or null for events
 * that should retain the legacy event-wide denominator.
 */
function mobile_scan_stats_split_session_total(
    string $eventId,
    string $sessionId,
    array $headers,
    int $eventRegistrationTotal
): ?int {
    $context = mobile_scan_stats_split_event_context($eventId, $headers);
    if ($context === null) {
        return null;
    }
    $sessions = $context['sessions'];
    if ($sessionId === '' || count($sessions) !== 2) {
        return 0;
    }

    $activeSession = null;
    $explicitSessions = [];
    foreach ($sessions as $session) {
        $target = trim((string) ($session['target_participant'] ?? ''));
        if ($target !== '') {
            $explicitSessions[] = $session;
        }
        if ((string) ($session['id'] ?? '') === $sessionId) {
            $activeSession = $session;
        }
    }
    if (!is_array($activeSession)) {
        return 0;
    }

    $sessionLimitRaw = trim((string) ($activeSession['registration_limit'] ?? ''));
    if ($sessionLimitRaw !== '' && preg_match('/\A[1-9]\d{0,3}\z/', $sessionLimitRaw)) {
        return (int) $sessionLimitRaw;
    }

    // Registration capacity also falls back to the event-level student limit
    // when a seminar does not have its own allocation.
    $eventLimitRaw = trim((string) ($context['event']['registration_limit'] ?? ''));
    if ($eventLimitRaw !== '' && preg_match('/\A[1-9]\d{0,3}\z/', $eventLimitRaw)) {
        return (int) $eventLimitRaw;
    }

    // A saved legacy split may have one blank target; that session represents
    // the complement, so subtract the explicitly targeted seminar's roster.
    if (count($explicitSessions) === 1) {
        $activeTarget = trim((string) ($activeSession['target_participant'] ?? ''));
        if ($activeTarget === '') {
            $otherId = trim((string) ($explicitSessions[0]['id'] ?? ''));
            return max(
                0,
                $eventRegistrationTotal
                    - mobile_scan_stats_session_registration_count($eventId, $otherId, $headers)
            );
        }
    }

    if (count($explicitSessions) === 2) {
        $activeTarget = trim((string) ($activeSession['target_participant'] ?? ''));
        $activeCount = mobile_scan_stats_session_registration_count($eventId, $sessionId, $headers);
        foreach ($explicitSessions as $otherSession) {
            $otherId = trim((string) ($otherSession['id'] ?? ''));
            if ($otherId === '' || $otherId === $sessionId) {
                continue;
            }
            $otherTarget = trim((string) ($otherSession['target_participant'] ?? ''));
            if (event_session_target_is_strict_subset($activeTarget, $otherTarget)) {
                return $activeCount;
            }
            if (event_session_target_is_strict_subset($otherTarget, $activeTarget)) {
                return max(
                    0,
                    $activeCount
                        - mobile_scan_stats_session_registration_count($eventId, $otherId, $headers)
                );
            }
        }
        return $activeCount;
    }

    // When targeting metadata is blank, the saved session roster is the
    // authoritative seminar-specific denominator.
    return mobile_scan_stats_session_registration_count($eventId, $sessionId, $headers);
}

/**
 * In check_out mode, `present` is the completed time-out count and `total`
 * is the number of students who timed in (the only students eligible to time out).
 *
 * @return array{ok:bool,present:int,total:int,percent:float,mode:string,session_id:?string}
 */
function mobile_scan_attendance_stats(
    string $eventId,
    string $sessionId,
    array $headers,
    string $mode = 'check_in'
): array
{
    $eventId = trim($eventId);
    $sessionId = trim($sessionId);
    $mode = strtolower(trim($mode)) === 'check_out' ? 'check_out' : 'check_in';
    $empty = [
        'ok' => true,
        'present' => 0,
        'total' => 0,
        'percent' => 0.0,
        'mode' => $mode,
        'session_id' => $sessionId !== '' ? $sessionId : null,
    ];
    if ($eventId === '') {
        return $empty;
    }

    $resolvedSplitSessionId = mobile_scan_stats_resolve_split_session_id(
        $eventId,
        $sessionId,
        $headers,
        $mode
    );
    if ($resolvedSplitSessionId !== null) {
        $sessionId = $resolvedSplitSessionId;
    }

    $regRows = mobile_scan_stats_fetch_rows(
        rtrim(SUPABASE_URL, '/') . '/rest/v1/event_registrations'
            . '?select=id'
            . '&event_id=eq.' . rawurlencode($eventId)
            . '&limit=2000',
        $headers
    );
    $regIds = [];
    foreach ($regRows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $id = trim((string) ($row['id'] ?? ''));
        if ($id !== '') {
            $regIds[] = $id;
        }
    }
    $regIds = mobile_scan_stats_clean_ids($regIds);
    $total = max(
        count($regIds),
        supabase_exact_count(
            'event_registrations',
            $headers,
            'event_id=eq.' . rawurlencode($eventId)
        )
    );
    $presentKeys = [];
    $checkedOutKeys = [];

    $sessionIds = [];
    if ($sessionId !== '') {
        $sessionIds = [$sessionId];
    } else {
        $sessionRows = mobile_scan_stats_fetch_rows(
            rtrim(SUPABASE_URL, '/') . '/rest/v1/event_sessions'
                . '?select=id'
                . '&event_id=eq.' . rawurlencode($eventId)
                . '&limit=80',
            $headers
        );
        foreach ($sessionRows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = trim((string) ($row['id'] ?? ''));
            if ($id !== '') {
                $sessionIds[] = $id;
            }
        }
        $sessionIds = mobile_scan_stats_clean_ids($sessionIds);
    }

    if ($sessionIds !== []) {
        foreach (array_chunk($sessionIds, 40) as $chunk) {
            $in = mobile_scan_stats_in_list($chunk);
            if ($in === '') {
                continue;
            }
            $attRows = mobile_scan_stats_fetch_rows(
                rtrim(SUPABASE_URL, '/') . '/rest/v1/event_session_attendance'
                    . '?select=registration_id,ticket_id,status,check_in_at,check_out_at'
                    . '&session_id=in.(' . $in . ')'
                    . '&limit=5000',
                $headers
            );
            foreach ($attRows as $row) {
                if (is_array($row)) {
                    mobile_scan_stats_mark_present($presentKeys, $row);
                    mobile_scan_stats_mark_checked_out($checkedOutKeys, $row);
                }
            }
        }
    } else {
        foreach (array_chunk($regIds, 80) as $chunk) {
            $in = mobile_scan_stats_in_list($chunk);
            if ($in === '') {
                continue;
            }
            $ticketRows = mobile_scan_stats_fetch_rows(
                rtrim(SUPABASE_URL, '/') . '/rest/v1/tickets'
                    . '?select=id,registration_id'
                    . '&registration_id=in.(' . $in . ')'
                    . '&limit=2000',
                $headers
            );
            $ticketIds = [];
            $ticketToReg = [];
            foreach ($ticketRows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $ticketId = trim((string) ($row['id'] ?? ''));
                $regId = trim((string) ($row['registration_id'] ?? ''));
                if ($ticketId === '') {
                    continue;
                }
                $ticketIds[] = $ticketId;
                if ($regId !== '') {
                    $ticketToReg[$ticketId] = $regId;
                }
            }
            foreach (array_chunk($ticketIds, 80) as $ticketChunk) {
                $ticketIn = mobile_scan_stats_in_list($ticketChunk);
                if ($ticketIn === '') {
                    continue;
                }
                $legacyRows = mobile_scan_stats_fetch_rows(
                    rtrim(SUPABASE_URL, '/') . '/rest/v1/attendance'
                        . '?select=ticket_id,status,check_in_at,check_out_at'
                        . '&ticket_id=in.(' . $ticketIn . ')'
                        . '&limit=2000',
                    $headers
                );
                foreach ($legacyRows as $row) {
                    if (!is_array($row)) {
                        continue;
                    }
                    $ticketId = trim((string) ($row['ticket_id'] ?? ''));
                    if ($ticketId !== '' && isset($ticketToReg[$ticketId])) {
                        $row['registration_id'] = $ticketToReg[$ticketId];
                    }
                    mobile_scan_stats_mark_present($presentKeys, $row);
                    mobile_scan_stats_mark_checked_out($checkedOutKeys, $row);
                }
            }
        }
    }

    $splitSessionTotal = mobile_scan_stats_split_session_total(
        $eventId,
        $sessionId,
        $headers,
        $total
    );
    $isSplitSessionScoped = $splitSessionTotal !== null;
    if ($splitSessionTotal !== null) {
        $total = $splitSessionTotal;
    }

    $present = $mode === 'check_out'
        ? count($checkedOutKeys)
        : count($presentKeys);
    if ($mode === 'check_out' && !$isSplitSessionScoped) {
        $total = count($presentKeys);
    }
    if ($present > $total && $total > 0) {
        $present = $total;
    }
    $percent = $total > 0 ? round(($present / $total) * 1000) / 10 : 0.0;

    return [
        'ok' => true,
        'present' => $present,
        'total' => $total,
        'percent' => $percent,
        'mode' => $mode,
        'session_id' => $sessionId !== '' ? $sessionId : null,
    ];
}

function mobile_scan_stats_can_view(string $userId, string $role, string $eventId, array $headers): bool
{
    if ($userId === '' || $eventId === '') {
        return false;
    }
    if ($role === 'admin') {
        return true;
    }

    $eventUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/events'
        . '?select=id,created_by&id=eq.' . rawurlencode($eventId) . '&limit=1';
    $eventRes = supabase_request('GET', $eventUrl, $headers);
    $eventRows = json_decode((string) ($eventRes['body'] ?? ''), true);
    $event = is_array($eventRows) && isset($eventRows[0]) ? $eventRows[0] : null;
    if (is_array($event) && (string) ($event['created_by'] ?? '') === $userId) {
        return true;
    }

    if (in_array($role, ['teacher', 'admin'], true) && function_exists('teacher_can_scan_event')
        && teacher_can_scan_event($userId, $eventId, $headers)) {
        return true;
    }

    if ($role === 'teacher' && function_exists('mobile_teacher_can_access_event')
        && mobile_teacher_can_access_event($eventId, $userId, $headers)) {
        return true;
    }

    if ($role === 'student' && function_exists('mobile_student_is_event_assistant')
        && mobile_student_is_event_assistant($eventId, $userId, $headers)) {
        return true;
    }

    $assistUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/event_assistants'
        . '?select=id&event_id=eq.' . rawurlencode($eventId)
        . '&student_id=eq.' . rawurlencode($userId)
        . '&allow_scan=eq.true&limit=1';
    $assistRes = supabase_request('GET', $assistUrl, $headers);
    $assistRows = json_decode((string) ($assistRes['body'] ?? ''), true);
    return is_array($assistRows) && count($assistRows) > 0;
}
