<?php
declare(strict_types=1);

require_once __DIR__ . '/registration_access.php';
require_once __DIR__ . '/event_sessions.php';
require_once __DIR__ . '/event_session_targeting.php';

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

/** @return array<string,array<string,mixed>> student user id => targeting profile */
function attendance_backfill_student_profiles(array $participants, array $headers): array
{
    $studentIds = [];
    foreach ($participants as $participant) {
        if (!is_array($participant)) {
            continue;
        }
        $studentId = trim((string) ($participant['student_id'] ?? ''));
        if ($studentId !== '') {
            $studentIds[$studentId] = true;
        }
    }
    if ($studentIds === []) {
        return [];
    }

    $profiles = [];
    foreach (array_chunk(array_keys($studentIds), 80) as $chunk) {
        $url = rtrim(SUPABASE_URL, '/') . '/rest/v1/users'
            . '?select=id,student_id,course,sections(name),role'
            . '&id=in.(' . implode(',', array_map('rawurlencode', $chunk)) . ')'
            . '&role=eq.student&limit=500';
        $res = supabase_request('GET', $url, $headers);
        $rows = $res['ok'] ? json_decode((string) ($res['body'] ?? ''), true) : [];
        if (!is_array($rows)) {
            continue;
        }
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $userId = trim((string) ($row['id'] ?? ''));
            if ($userId !== '') {
                $profiles[$userId] = $row;
            }
        }
    }

    if ($profiles === []) {
        return [];
    }

    $rosters = student_roster_fetch_map_by_user_ids(array_keys($profiles));
    $studentNos = [];
    foreach ($profiles as $userId => $profile) {
        if (isset($rosters[$userId])) {
            continue;
        }
        $studentNo = student_roster_normalize_no((string) ($profile['student_id'] ?? ''));
        if ($studentNo !== '') {
            $studentNos[$studentNo] = true;
        }
    }
    $rostersByNo = [];
    foreach (array_chunk(array_keys($studentNos), 80) as $chunk) {
        $url = rtrim(SUPABASE_URL, '/') . '/rest/v1/student_roster'
            . '?select=student_no,course_code,program_label,year_level,is_irregular'
            . '&student_no=in.(' . implode(',', array_map('rawurlencode', $chunk)) . ')'
            . '&archived_at=is.null&limit=500';
        $res = supabase_request('GET', $url, student_roster_supabase_headers());
        $rows = $res['ok'] ? json_decode((string) ($res['body'] ?? ''), true) : [];
        if (!is_array($rows)) {
            continue;
        }
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $studentNo = student_roster_normalize_no((string) ($row['student_no'] ?? ''));
            if ($studentNo !== '') {
                $rostersByNo[$studentNo] = $row;
            }
        }
    }
    foreach ($profiles as $userId => &$profile) {
        $studentNo = student_roster_normalize_no((string) ($profile['student_id'] ?? ''));
        $roster = $rosters[$userId] ?? $rostersByNo[$studentNo] ?? null;
        $profile = event_targeting_apply_roster($profile, $roster);
        $profile['section_name'] = extract_section_name($profile['sections'] ?? null);
        $profile['year_level'] = extract_student_year_level($profile);
    }
    unset($profile);

    return $profiles;
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

        if ($nowUtc > $closesAt) {
            $closedSessions[$sessionId] = true;
        }
    }

    if (empty($closedSessions)) {
        return;
    }

    $participantUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/event_registrations'
        . '?select=id,student_id,tickets(id)'
        . '&event_id=eq.' . rawurlencode($eventId);
    $participantRes = supabase_request('GET', $participantUrl, $headers);
    $participants = $participantRes['ok'] ? json_decode((string) $participantRes['body'], true) : [];
    if (!is_array($participants)) {
        return;
    }

    $studentProfiles = attendance_backfill_student_profiles($participants, $headers);
    $registrationIds = array_map(
        static fn(array $participant): string => trim((string) ($participant['id'] ?? '')),
        array_values(array_filter($participants, 'is_array'))
    );
    $savedAssignments = event_session_assignments_for_registrations(
        $eventId,
        $registrationIds,
        $sessions,
        $headers
    );
    if (!is_array($savedAssignments)) {
        return;
    }

    $pairs = [];
    foreach ($participants as $participant) {
        if (!is_array($participant)) {
            continue;
        }

        $registrationId = trim((string) ($participant['id'] ?? ''));
        $studentId = trim((string) ($participant['student_id'] ?? ''));
        if ($registrationId === '' || $studentId === '') {
            continue;
        }

        $student = $studentProfiles[$studentId] ?? null;
        if (!is_array($student)) {
            continue;
        }
        $eligibleSessions = $savedAssignments[$registrationId] ?? [];
        if ($eligibleSessions === []) {
            $eligibleSessions = event_matching_sessions_for_student($event, $sessions, $student);
        }
        $splitAttendance = event_uses_split_session_attendance(array_merge($event, ['sessions' => $sessions]));
        if ($splitAttendance && count($eligibleSessions) !== 1) {
            continue;
        }
        $eligibleSessionIds = array_fill_keys(array_map(
            static fn(array $row): string => trim((string) ($row['id'] ?? '')),
            $eligibleSessions
        ), true);

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
                'eligible_session_ids' => $eligibleSessionIds,
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
            if (!isset($pair['eligible_session_ids'][$sessionId])) {
                continue;
            }
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
