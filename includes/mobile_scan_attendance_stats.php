<?php
declare(strict_types=1);

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
    $total = count($regIds);
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

    $present = $mode === 'check_out'
        ? count($checkedOutKeys)
        : count($presentKeys);
    if ($mode === 'check_out') {
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
