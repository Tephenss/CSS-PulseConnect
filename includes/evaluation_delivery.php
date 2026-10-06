<?php
declare(strict_types=1);

require_once __DIR__ . '/evaluation_feedback_lib.php';
require_once __DIR__ . '/evaluation_notifications.php';

/** Fetch all rows from a PostgREST URL without exposing partial lists at its row cap. */
function evaluation_delivery_fetch_all(string $baseUrl, array $headers): ?array
{
    $all = [];
    $pageSize = 1000;
    for ($offset = 0; $offset < 50000; $offset += $pageSize) {
        $url = $baseUrl . (str_contains($baseUrl, '?') ? '&' : '?')
            . 'limit=' . $pageSize . '&offset=' . $offset;
        $res = supabase_request('GET', $url, $headers);
        if (!($res['ok'] ?? false)) {
            return null;
        }
        $page = json_decode((string) ($res['body'] ?? ''), true);
        if (!is_array($page)) {
            return null;
        }
        foreach ($page as $row) {
            if (is_array($row)) {
                $all[] = $row;
            }
        }
        if (count($page) < $pageSize) {
            return $all;
        }
    }

    return null;
}

/** @return list<array<string,mixed>> */
function evaluation_delivery_relation_rows(mixed $value): array
{
    if (!is_array($value)) {
        return [];
    }
    if ($value === []) {
        return [];
    }
    $keys = array_keys($value);
    if ($keys === range(0, count($value) - 1)) {
        return array_values(array_filter($value, 'is_array'));
    }
    return [$value];
}

/**
 * Find app-account attendees eligible for the evaluation notification.
 * The eligibility rule matches the notification sender: a present check-out,
 * using the final seminar for multi-session events.
 *
 * @return array{ok:bool,student_ids:list<string>,uses_sessions:bool,error?:string}
 */
function evaluation_delivery_eligible_students(string $eventId, array $event, array $headers): array
{
    $sessions = fetch_event_sessions($eventId, $headers);
    $sessions = is_array($sessions) ? array_values(array_filter($sessions, static fn ($s): bool =>
        is_array($s) && trim((string) ($s['id'] ?? '')) !== ''
    )) : [];
    $usesSessions = $sessions !== [];
    $studentIds = [];

    if ($usesSessions) {
        if (count($sessions) === 1 && evaluation_event_expects_two_seminars($eventId)) {
            return [
                'ok' => false,
                'student_ids' => [],
                'uses_sessions' => true,
                'error' => 'This event expects a final seminar, but that seminar schedule is unavailable.',
            ];
        }
        $targetSession = count($sessions) > 1
            ? evaluation_final_seminar_for_event($eventId)
            : $sessions[0];
        $sessionId = trim((string) ($targetSession['id'] ?? ''));
        if ($sessionId === '') {
            return [
                'ok' => false,
                'student_ids' => [],
                'uses_sessions' => true,
                'error' => 'The final seminar schedule is unavailable, so eligible recipients cannot be verified.',
            ];
        }

        $url = rtrim(SUPABASE_URL, '/') . '/rest/v1/event_session_attendance'
            . '?select=session_id,status,check_in_at,check_out_at,registration:event_registrations(student_id)'
            . '&session_id=eq.' . rawurlencode($sessionId);
        $rows = evaluation_delivery_fetch_all($url, $headers);
        if ($rows === null) {
            return ['ok' => false, 'student_ids' => [], 'uses_sessions' => true, 'error' => 'Could not load final-seminar attendance.'];
        }

        foreach ($rows as $row) {
            if (!feedback_attendance_counts_as_present($row) || trim((string) ($row['check_out_at'] ?? '')) === '') {
                continue;
            }
            foreach (evaluation_delivery_relation_rows($row['registration'] ?? null) as $registration) {
                $studentId = trim((string) ($registration['student_id'] ?? ''));
                if ($studentId !== '') {
                    $studentIds[$studentId] = true;
                }
            }
        }
    } else {
        if (evaluation_event_expects_two_seminars($eventId)) {
            return [
                'ok' => false,
                'student_ids' => [],
                'uses_sessions' => false,
                'error' => 'This event expects multiple seminars, but its seminar attendance is unavailable.',
            ];
        }

        $url = rtrim(SUPABASE_URL, '/') . '/rest/v1/attendance'
            . '?select=status,check_in_at,check_out_at,tickets(registration_id,event_registrations(student_id,event_id))'
            . '&tickets.event_registrations.event_id=eq.' . rawurlencode($eventId);
        $rows = evaluation_delivery_fetch_all($url, $headers);
        if ($rows === null) {
            return ['ok' => false, 'student_ids' => [], 'uses_sessions' => false, 'error' => 'Could not load event attendance.'];
        }

        foreach ($rows as $row) {
            if (!feedback_attendance_counts_as_present($row) || trim((string) ($row['check_out_at'] ?? '')) === '') {
                continue;
            }
            foreach (evaluation_delivery_relation_rows($row['tickets'] ?? null) as $ticket) {
                foreach (evaluation_delivery_relation_rows($ticket['event_registrations'] ?? null) as $registration) {
                    if (trim((string) ($registration['event_id'] ?? $eventId)) !== $eventId) {
                        continue;
                    }
                    $studentId = trim((string) ($registration['student_id'] ?? ''));
                    if ($studentId !== '') {
                        $studentIds[$studentId] = true;
                    }
                }
            }
        }
    }

    return [
        'ok' => true,
        'student_ids' => array_values(array_keys($studentIds)),
        'uses_sessions' => $usesSessions,
    ];
}

/**
 * Return app-inbox notification status for the eligible event attendees.
 * This confirms an in-app inbox row exists; it does not claim the device showed it.
 *
 * @return array<string,mixed>
 */
function evaluation_delivery_status_for_event(string $eventId, array $event, array $headers): array
{
    $eligibility = evaluation_delivery_eligible_students($eventId, $event, $headers);
    if (empty($eligibility['ok'])) {
        return ['ok' => false, 'error' => (string) ($eligibility['error'] ?? 'Could not verify recipients.')];
    }

    $studentIds = is_array($eligibility['student_ids'] ?? null) ? $eligibility['student_ids'] : [];
    $profiles = [];
    $inboxByUser = [];

    foreach (array_chunk($studentIds, 80) as $chunk) {
        $inList = implode(',', array_map('rawurlencode', $chunk));
        $usersUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/users'
            . '?select=id,first_name,middle_name,last_name,suffix,student_id'
            . '&id=in.(' . $inList . ')';
        $userRes = supabase_request('GET', $usersUrl, $headers);
        if (!($userRes['ok'] ?? false)) {
            return ['ok' => false, 'error' => 'Could not load attendee profiles.'];
        }
        $userRows = json_decode((string) ($userRes['body'] ?? ''), true);
        if (!is_array($userRows)) {
            return ['ok' => false, 'error' => 'Attendee profile response was invalid.'];
        }
        foreach ($userRows as $userRow) {
            if (!is_array($userRow)) {
                continue;
            }
            $userId = trim((string) ($userRow['id'] ?? ''));
            if ($userId === '') {
                continue;
            }
            $parts = array_values(array_filter([
                trim((string) ($userRow['first_name'] ?? '')),
                trim((string) ($userRow['middle_name'] ?? '')),
                trim((string) ($userRow['last_name'] ?? '')),
                trim((string) ($userRow['suffix'] ?? '')),
            ], static fn (string $part): bool => $part !== ''));
            $profiles[$userId] = [
                'name' => $parts !== [] ? implode(' ', $parts) : 'Student',
                'student_no' => trim((string) ($userRow['student_id'] ?? '')),
            ];
        }

        $notificationsUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/user_notifications'
            . '?select=user_id,data,created_at'
            . '&event_id=eq.' . rawurlencode($eventId)
            . '&user_id=in.(' . $inList . ')';
        $notificationRows = evaluation_delivery_fetch_all($notificationsUrl, $headers);
        if ($notificationRows === null) {
            return ['ok' => false, 'error' => 'Could not verify in-app notification records.'];
        }
        foreach ($notificationRows as $notification) {
            $data = is_array($notification['data'] ?? null) ? $notification['data'] : [];
            if (strtolower(trim((string) ($data['type'] ?? ''))) !== 'eval_open') {
                continue;
            }
            $userId = trim((string) ($notification['user_id'] ?? ''));
            if ($userId === '') {
                continue;
            }
            $createdAt = trim((string) ($notification['created_at'] ?? ''));
            if (!isset($inboxByUser[$userId]) || $createdAt > $inboxByUser[$userId]) {
                $inboxByUser[$userId] = $createdAt;
            }
        }
    }

    $received = [];
    $missing = [];
    foreach ($studentIds as $studentId) {
        $profile = $profiles[$studentId] ?? ['name' => 'Student', 'student_no' => ''];
        $row = [
            'student_id' => $studentId,
            'name' => (string) $profile['name'],
            'student_no' => (string) $profile['student_no'],
        ];
        if (isset($inboxByUser[$studentId])) {
            $received[] = $row + ['sent_at' => (string) $inboxByUser[$studentId]];
        } else {
            $missing[] = $row;
        }
    }
    $sortByName = static fn (array $a, array $b): int => strnatcasecmp((string) $a['name'], (string) $b['name']);
    usort($received, $sortByName);
    usort($missing, $sortByName);

    $hasQuestions = evaluation_event_has_open_questions($eventId);
    return [
        'ok' => true,
        'has_questions' => $hasQuestions,
        'uses_sessions' => (bool) ($eligibility['uses_sessions'] ?? false),
        'eligible_count' => count($studentIds),
        'received_count' => count($received),
        'missing_count' => count($missing),
        'received' => $received,
        'missing' => $missing,
    ];
}

/** @return array<string,true>|null Returns null if notification lookup fails. */
function evaluation_delivery_existing_inbox_users(string $eventId, array $studentIds, array $headers): ?array
{
    $found = [];
    foreach (array_chunk($studentIds, 80) as $chunk) {
        $inList = implode(',', array_map('rawurlencode', $chunk));
        $url = rtrim(SUPABASE_URL, '/') . '/rest/v1/user_notifications'
            . '?select=user_id,data&event_id=eq.' . rawurlencode($eventId)
            . '&user_id=in.(' . $inList . ')';
        $rows = evaluation_delivery_fetch_all($url, $headers);
        if ($rows === null) {
            return null;
        }
        foreach ($rows as $row) {
            $data = is_array($row['data'] ?? null) ? $row['data'] : [];
            if (strtolower(trim((string) ($data['type'] ?? ''))) !== 'eval_open') {
                continue;
            }
            $userId = trim((string) ($row['user_id'] ?? ''));
            if ($userId !== '') {
                $found[$userId] = true;
            }
        }
    }

    return $found;
}
