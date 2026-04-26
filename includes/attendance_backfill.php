<?php
declare(strict_types=1);

function attendance_backfill_to_utc(?string $raw): ?DateTimeImmutable
{
    if ($raw === null || trim($raw) === '') {
        return null;
    }

    try {
        return (new DateTimeImmutable($raw))->setTimezone(new DateTimeZone('UTC'));
    } catch (Throwable $e) {
        return null;
    }
}

function attendance_backfill_counts_as_present(?array $row): bool
{
    if (!is_array($row)) {
        return false;
    }

    $status = strtolower(trim((string) ($row['status'] ?? '')));
    if (in_array($status, ['present', 'scanned', 'late', 'early', 'completed'], true)) {
        return true;
    }

    return trim((string) ($row['check_in_at'] ?? '')) !== '';
}

function attendance_backfill_grace_minutes(array $event): int
{
    $minutes = (int) ($event['grace_time'] ?? 30);
    return max(1, $minutes);
}

function attendance_backfill_session_window_minutes(array $session): int
{
    $scanWindow = (int) ($session['scan_window_minutes'] ?? 0);
    if ($scanWindow > 0) {
        return $scanWindow;
    }

    $attendanceWindow = (int) ($session['attendance_window_minutes'] ?? 0);
    if ($attendanceWindow > 0) {
        return $attendanceWindow;
    }

    return 30;
}

function attendance_backfill_update_absent_row(
    string $table,
    array $headers,
    string $filterQuery,
    string $nowIso
): ?array {
    $url = rtrim(SUPABASE_URL, '/') . '/rest/v1/' . $table
        . '?' . $filterQuery
        . '&check_in_at=is.null'
        . '&select=id,session_id,registration_id,ticket_id,status,check_in_at,last_scanned_at';

    $res = supabase_request(
        'PATCH',
        $url,
        [
            'Content-Type: application/json',
            'Accept: application/json',
            'Prefer: return=representation',
            ...$headers,
        ],
        json_encode([
            'status' => 'absent',
            'last_scanned_at' => $nowIso,
        ], JSON_UNESCAPED_SLASHES)
    );

    if (!$res['ok']) {
        return null;
    }

    $rows = json_decode((string) $res['body'], true);
    return is_array($rows) && isset($rows[0]) && is_array($rows[0]) ? $rows[0] : null;
}

function attendance_backfill_insert_absent_row(
    string $table,
    array $headers,
    array $payload
): ?array {
    $url = rtrim(SUPABASE_URL, '/') . '/rest/v1/' . $table
        . '?select=id,session_id,registration_id,ticket_id,status,check_in_at,last_scanned_at';

    $res = supabase_request(
        'POST',
        $url,
        [
            'Content-Type: application/json',
            'Accept: application/json',
            'Prefer: return=representation',
            ...$headers,
        ],
        json_encode([$payload], JSON_UNESCAPED_SLASHES)
    );

    if (!$res['ok']) {
        return null;
    }

    $rows = json_decode((string) $res['body'], true);
    return is_array($rows) && isset($rows[0]) && is_array($rows[0]) ? $rows[0] : null;
}

function attendance_backfill_for_simple_event(array $event, array $headers): void
{
    $startAt = attendance_backfill_to_utc((string) ($event['start_at'] ?? ''));
    if (!$startAt instanceof DateTimeImmutable) {
        return;
    }

    $closesAt = $startAt->modify('+' . attendance_backfill_grace_minutes($event) . ' minutes');
    $endAt = attendance_backfill_to_utc((string) ($event['end_at'] ?? ''));
    if ($endAt instanceof DateTimeImmutable && $endAt < $closesAt) {
        $closesAt = $endAt;
    }

    $nowUtc = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    if ($nowUtc <= $closesAt) {
        return;
    }

    $eventId = trim((string) ($event['id'] ?? ''));
    if ($eventId === '') {
        return;
    }

    $participantUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/event_registrations'
        . '?select=id,tickets(id,attendance(id,status,check_in_at,last_scanned_at,session_id))'
        . '&event_id=eq.' . rawurlencode($eventId);
    $participantRes = supabase_request('GET', $participantUrl, $headers);
    $participants = $participantRes['ok'] ? json_decode((string) $participantRes['body'], true) : [];
    if (!is_array($participants)) {
        return;
    }

    $nowIso = $nowUtc->format('c');
    foreach ($participants as $participant) {
        if (!is_array($participant)) {
            continue;
        }

        $tickets = isset($participant['tickets']) && is_array($participant['tickets']) ? $participant['tickets'] : [];
        foreach ($tickets as $ticket) {
            if (!is_array($ticket)) {
                continue;
            }

            $ticketId = trim((string) ($ticket['id'] ?? ''));
            if ($ticketId === '') {
                continue;
            }

            $attendanceRows = isset($ticket['attendance']) && is_array($ticket['attendance']) ? $ticket['attendance'] : [];
            $existing = null;
            foreach ($attendanceRows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $sessionId = trim((string) ($row['session_id'] ?? ''));
                if ($sessionId === '') {
                    $existing = $row;
                    break;
                }
            }

            if (attendance_backfill_counts_as_present(is_array($existing) ? $existing : null)) {
                continue;
            }

            $status = strtolower(trim((string) (is_array($existing) ? ($existing['status'] ?? '') : '')));
            if ($status === 'absent') {
                continue;
            }

            $attendanceId = trim((string) (is_array($existing) ? ($existing['id'] ?? '') : ''));
            $updated = null;
            if ($attendanceId !== '') {
                $updated = attendance_backfill_update_absent_row(
                    'attendance',
                    $headers,
                    'id=eq.' . rawurlencode($attendanceId),
                    $nowIso
                );
            }

            if (!$updated) {
                $updated = attendance_backfill_update_absent_row(
                    'attendance',
                    $headers,
                    'ticket_id=eq.' . rawurlencode($ticketId) . '&session_id=is.null',
                    $nowIso
                );
            }

            if (!$updated) {
                attendance_backfill_insert_absent_row(
                    'attendance',
                    $headers,
                    [
                        'ticket_id' => $ticketId,
                        'status' => 'absent',
                        'last_scanned_at' => $nowIso,
                    ]
                );
            }
        }
    }
}

function attendance_backfill_for_sessions(array $event, array $headers, array $sessions): void
{
    $eventId = trim((string) ($event['id'] ?? ''));
    if ($eventId === '' || empty($sessions)) {
        return;
    }

    $closedSessions = [];
    $nowUtc = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    foreach ($sessions as $session) {
        if (!is_array($session)) {
            continue;
        }

        $sessionId = trim((string) ($session['id'] ?? ''));
        $startAt = attendance_backfill_to_utc((string) ($session['start_at'] ?? ''));
        if ($sessionId === '' || !$startAt instanceof DateTimeImmutable) {
            continue;
        }

        $closesAt = $startAt->modify('+' . attendance_backfill_session_window_minutes($session) . ' minutes');
        $endAt = attendance_backfill_to_utc((string) ($session['end_at'] ?? ''));
        if ($endAt instanceof DateTimeImmutable && $endAt < $closesAt) {
            $closesAt = $endAt;
        }

        if ($nowUtc > $closesAt) {
            $closedSessions[$sessionId] = true;
        }
    }

    if (empty($closedSessions)) {
        return;
    }

    $participantUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/event_registrations'
        . '?select=id,tickets(id)'
        . '&event_id=eq.' . rawurlencode($eventId);
    $participantRes = supabase_request('GET', $participantUrl, $headers);
    $participants = $participantRes['ok'] ? json_decode((string) $participantRes['body'], true) : [];
    if (!is_array($participants)) {
        return;
    }

    $pairs = [];
    foreach ($participants as $participant) {
        if (!is_array($participant)) {
            continue;
        }

        $registrationId = trim((string) ($participant['id'] ?? ''));
        if ($registrationId === '') {
            continue;
        }

        $tickets = isset($participant['tickets']) && is_array($participant['tickets']) ? $participant['tickets'] : [];
        foreach ($tickets as $ticket) {
            if (!is_array($ticket)) {
                continue;
            }
            $ticketId = trim((string) ($ticket['id'] ?? ''));
            if ($ticketId === '') {
                continue;
            }
            $pairs[] = [
                'registration_id' => $registrationId,
                'ticket_id' => $ticketId,
            ];
        }
    }

    if (empty($pairs)) {
        return;
    }

    $sessionList = implode(',', array_map(
        static fn(string $sessionId): string => '"' . $sessionId . '"',
        array_keys($closedSessions)
    ));
    $existingByKey = [];
    $table = 'event_session_attendance';

    $attendanceUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/' . $table
        . '?select=id,session_id,registration_id,ticket_id,status,check_in_at,last_scanned_at'
        . '&session_id=in.(' . $sessionList . ')';
    $attendanceRes = supabase_request('GET', $attendanceUrl, $headers);
    if (!$attendanceRes['ok']) {
        $table = 'attendance';
        $attendanceUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/attendance'
            . '?select=id,session_id,ticket_id,status,check_in_at,last_scanned_at'
            . '&session_id=in.(' . $sessionList . ')';
        $attendanceRes = supabase_request('GET', $attendanceUrl, $headers);
    }

    $attendanceRows = $attendanceRes['ok'] ? json_decode((string) $attendanceRes['body'], true) : [];
    if (is_array($attendanceRows)) {
        foreach ($attendanceRows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $sessionId = trim((string) ($row['session_id'] ?? ''));
            $registrationId = trim((string) ($row['registration_id'] ?? ''));
            $ticketId = trim((string) ($row['ticket_id'] ?? ''));
            if ($sessionId === '') {
                continue;
            }
            if ($registrationId !== '') {
                $existingByKey[$registrationId . '|' . $sessionId] = $row;
            } elseif ($ticketId !== '') {
                $existingByKey[$ticketId . '|' . $sessionId] = $row;
            }
        }
    }

    $nowIso = $nowUtc->format('c');
    foreach ($pairs as $pair) {
        $registrationId = $pair['registration_id'];
        $ticketId = $pair['ticket_id'];
        foreach (array_keys($closedSessions) as $sessionId) {
            $existing = $existingByKey[$registrationId . '|' . $sessionId]
                ?? $existingByKey[$ticketId . '|' . $sessionId]
                ?? null;
            if (attendance_backfill_counts_as_present(is_array($existing) ? $existing : null)) {
                continue;
            }

            $status = strtolower(trim((string) (is_array($existing) ? ($existing['status'] ?? '') : '')));
            if ($status === 'absent') {
                continue;
            }

            $attendanceId = trim((string) (is_array($existing) ? ($existing['id'] ?? '') : ''));
            $updated = null;
            if ($attendanceId !== '') {
                $updated = attendance_backfill_update_absent_row(
                    $table,
                    $headers,
                    'id=eq.' . rawurlencode($attendanceId),
                    $nowIso
                );
            }

            if (!$updated && $table === 'event_session_attendance') {
                $updated = attendance_backfill_update_absent_row(
                    $table,
                    $headers,
                    'session_id=eq.' . rawurlencode($sessionId)
                    . '&registration_id=eq.' . rawurlencode($registrationId),
                    $nowIso
                );
            }

            if (!$updated) {
                $updated = attendance_backfill_update_absent_row(
                    $table,
                    $headers,
                    'session_id=eq.' . rawurlencode($sessionId)
                    . '&ticket_id=eq.' . rawurlencode($ticketId),
                    $nowIso
                );
            }

            if (!$updated) {
                $payload = [
                    'session_id' => $sessionId,
                    'ticket_id' => $ticketId,
                    'status' => 'absent',
                    'last_scanned_at' => $nowIso,
                ];
                if ($table === 'event_session_attendance') {
                    $payload['registration_id'] = $registrationId;
                }
                attendance_backfill_insert_absent_row($table, $headers, $payload);
            }
        }
    }
}

function attendance_backfill_for_event(array $event, array $headers, array $sessions = []): void
{
    try {
        if (!empty($sessions)) {
            attendance_backfill_for_sessions($event, $headers, $sessions);
            return;
        }

        attendance_backfill_for_simple_event($event, $headers);
    } catch (Throwable $e) {
        // Best-effort only. Rendering should not fail on backfill issues.
    }
}
