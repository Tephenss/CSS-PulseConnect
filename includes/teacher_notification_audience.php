<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/supabase.php';

function teacher_notification_supabase_headers(): array
{
    return [
        'Accept: application/json',
        'apikey: ' . SUPABASE_KEY,
        'Authorization: Bearer ' . SUPABASE_KEY,
    ];
}

/** @return list<array{user_id:string,program_label:string,year_level:?int}> */
function teacher_notification_roster_rows(): array
{
    $headers = teacher_notification_supabase_headers();
    $rows = [];
    $pageSize = 1000;
    $maximumRows = 10000;

    for ($offset = 0; $offset < $maximumRows; $offset += $pageSize) {
        $url = rtrim(SUPABASE_URL, '/') . '/rest/v1/student_roster'
            . '?select=user_id,program_label,year_level'
            . '&user_id=not.is.null&archived_at=is.null&order=id.asc'
            . '&limit=' . $pageSize . '&offset=' . $offset;
        $res = supabase_request('GET', $url, $headers);
        if (!($res['ok'] ?? false)) {
            $body = strtolower((string) ($res['body'] ?? ''));
            if (str_contains($body, 'archived_at')) {
                throw new RuntimeException('Student roster archive filtering is unavailable. Apply migration 058 before using group notifications.');
            }
            error_log('teacher_notification_audience: roster lookup failed: ' . substr((string) ($res['body'] ?? ''), 0, 300));
            throw new RuntimeException('Could not load the active student roster.');
        }

        $page = json_decode((string) ($res['body'] ?? ''), true);
        if (!is_array($page)) {
            throw new RuntimeException('The active student roster response was invalid.');
        }
        foreach ($page as $row) {
            if (!is_array($row)) {
                continue;
            }
            $userId = trim((string) ($row['user_id'] ?? ''));
            if ($userId === '') {
                continue;
            }
            $year = isset($row['year_level']) ? (int) $row['year_level'] : null;
            $rows[] = [
                'user_id' => $userId,
                'program_label' => trim((string) ($row['program_label'] ?? '')),
                'year_level' => ($year !== null && $year >= 1 && $year <= 4) ? $year : null,
            ];
        }
        if (count($page) < $pageSize) {
            return $rows;
        }
    }

    throw new RuntimeException('The active roster is too large to process safely. Narrow the audience or contact an administrator.');
}

/** @param list<string> $userIds @return array<string,true> */
function teacher_notification_active_student_ids(array $userIds): array
{
    $uuidPattern = '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/iD';
    $uniqueIds = [];
    foreach ($userIds as $userId) {
        $userId = trim($userId);
        if (preg_match($uuidPattern, $userId) === 1) {
            $uniqueIds[$userId] = true;
        }
    }

    $active = [];
    $headers = teacher_notification_supabase_headers();
    foreach (array_chunk(array_keys($uniqueIds), 80) as $chunk) {
        $inList = '(' . implode(',', $chunk) . ')';
        $url = rtrim(SUPABASE_URL, '/') . '/rest/v1/' . SUPABASE_TABLE_USERS
            . '?select=id&role=eq.student&id=in.' . rawurlencode($inList)
            . '&archived_at=is.null';
        $res = supabase_request('GET', $url, $headers);
        if (!($res['ok'] ?? false)) {
            if (str_contains(strtolower((string) ($res['body'] ?? '')), 'archived_at')) {
                throw new RuntimeException('Student account archive filtering is unavailable. Apply migration 058 before sending notifications.');
            }
            error_log('teacher_notification_audience: active student validation failed: ' . substr((string) ($res['body'] ?? ''), 0, 300));
            throw new RuntimeException('Could not verify active student accounts.');
        }
        $rows = json_decode((string) ($res['body'] ?? ''), true);
        if (!is_array($rows)) {
            throw new RuntimeException('The active student account response was invalid.');
        }
        foreach ($rows as $row) {
            if (is_array($row)) {
                $id = trim((string) ($row['id'] ?? ''));
                if ($id !== '') {
                    $active[$id] = true;
                }
            }
        }
    }

    return $active;
}

/**
 * @return array{total:int,years:array<int,int>,programs:list<array{label:string,total:int,years:array<int,int>}>,rows:list<array{user_id:string,program_label:string,year_level:?int}>}
 */
function teacher_notification_audience_snapshot(): array
{
    $rows = teacher_notification_roster_rows();
    $active = teacher_notification_active_student_ids(array_column($rows, 'user_id'));
    $all = [];
    $years = [1 => [], 2 => [], 3 => [], 4 => []];
    $programs = [];

    foreach ($rows as $row) {
        $id = $row['user_id'];
        if (!isset($active[$id])) {
            continue;
        }
        $all[$id] = true;
        $year = $row['year_level'];
        if ($year !== null) {
            $years[$year][$id] = true;
        }
        $label = $row['program_label'];
        if ($label === '') {
            continue;
        }
        $programs[$label] ??= ['all' => [], 'years' => [1 => [], 2 => [], 3 => [], 4 => []]];
        $programs[$label]['all'][$id] = true;
        if ($year !== null) {
            $programs[$label]['years'][$year][$id] = true;
        }
    }

    ksort($programs, SORT_NATURAL | SORT_FLAG_CASE);
    $programList = [];
    foreach ($programs as $label => $counts) {
        $programList[] = [
            'label' => (string) $label,
            'total' => count($counts['all']),
            'years' => array_map('count', $counts['years']),
        ];
    }

    return [
        'total' => count($all),
        'years' => array_map('count', $years),
        'programs' => $programList,
        'rows' => $rows,
    ];
}

/** @return list<string> */
function teacher_notification_group_recipients(array $rows, string $program, ?int $year): array
{
    $candidateIds = [];
    foreach ($rows as $row) {
        if ($program !== '' && $row['program_label'] !== $program) {
            continue;
        }
        if ($year !== null && $row['year_level'] !== $year) {
            continue;
        }
        $candidateIds[$row['user_id']] = true;
    }
    $active = teacher_notification_active_student_ids(array_keys($candidateIds));
    return array_keys($active);
}

/** @return list<array{id:string,title:string,status:string,start_at:string}> */
function teacher_notification_events(string $teacherId): array
{
    if (!function_exists('pulse_auto_finish_published_events')) {
        require_once __DIR__ . '/helpers.php';
    }
    $headers = teacher_notification_supabase_headers();
    pulse_auto_finish_published_events($headers);

    $visibleStatuses = '(status.eq.published,and(status.eq.finished,created_by.eq.' . $teacherId . '))';
    $url = rtrim(SUPABASE_URL, '/') . '/rest/v1/events'
        . '?select=id,title,status,start_at,created_by'
        . '&or=' . rawurlencode($visibleStatuses)
        . '&order=start_at.desc&limit=500';
    $res = supabase_request('GET', $url, $headers);
    if (!($res['ok'] ?? false)) {
        error_log('teacher_notification_audience: event list failed: ' . substr((string) ($res['body'] ?? ''), 0, 300));
        throw new RuntimeException('Could not load your accessible events.');
    }
    $rows = json_decode((string) ($res['body'] ?? ''), true);
    if (!is_array($rows)) {
        throw new RuntimeException('The event list response was invalid.');
    }

    $events = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $id = trim((string) ($row['id'] ?? ''));
        $title = trim((string) ($row['title'] ?? ''));
        if ($id === '' || $title === '') {
            continue;
        }
        $events[] = [
            'id' => $id,
            'title' => $title,
            'status' => trim((string) ($row['status'] ?? '')),
            'start_at' => trim((string) ($row['start_at'] ?? '')),
        ];
    }
    return $events;
}

/** @return array{title:string,recipient_ids:list<string>} */
function teacher_notification_event_recipients(string $eventId, string $teacherId): array
{
    $headers = teacher_notification_supabase_headers();
    $eventUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/events'
        . '?select=id,title,status,created_by&id=eq.' . rawurlencode($eventId) . '&limit=1';
    $eventRes = supabase_request('GET', $eventUrl, $headers);
    if (!($eventRes['ok'] ?? false)) {
        error_log('teacher_notification_audience: event check failed: ' . substr((string) ($eventRes['body'] ?? ''), 0, 300));
        throw new RuntimeException('Could not verify the selected event.');
    }
    $eventRows = json_decode((string) ($eventRes['body'] ?? ''), true);
    $event = is_array($eventRows) && isset($eventRows[0]) && is_array($eventRows[0]) ? $eventRows[0] : null;
    if ($event === null) {
        throw new RuntimeException('The selected event no longer exists.');
    }
    $status = strtolower(trim((string) ($event['status'] ?? '')));
    $isOwner = hash_equals($teacherId, trim((string) ($event['created_by'] ?? '')));
    if ($status !== 'published' && !($status === 'finished' && $isOwner)) {
        throw new RuntimeException('You no longer have access to participants for this event.');
    }

    $registrationIds = [];
    $pageSize = 1000;
    $maxRows = 10000;
    for ($offset = 0; $offset < $maxRows; $offset += $pageSize) {
        $registrationUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/event_registrations'
            . '?select=student_id&event_id=eq.' . rawurlencode($eventId)
            . '&order=id.asc&limit=' . $pageSize . '&offset=' . $offset;
        $registrationRes = supabase_request('GET', $registrationUrl, $headers);
        if (!($registrationRes['ok'] ?? false)) {
            error_log('teacher_notification_audience: event registration lookup failed: ' . substr((string) ($registrationRes['body'] ?? ''), 0, 300));
            throw new RuntimeException('Could not load registered participants for this event.');
        }
        $page = json_decode((string) ($registrationRes['body'] ?? ''), true);
        if (!is_array($page)) {
            throw new RuntimeException('The event participant response was invalid.');
        }
        foreach ($page as $row) {
            if (is_array($row)) {
                $id = trim((string) ($row['student_id'] ?? ''));
                if ($id !== '') {
                    $registrationIds[$id] = true;
                }
            }
        }
        if (count($page) < $pageSize) {
            break;
        }
        if ($offset + $pageSize >= $maxRows) {
            throw new RuntimeException('This event has too many registrations to target safely.');
        }
    }

    $activeIds = teacher_notification_active_student_ids(array_keys($registrationIds));
    return [
        'title' => trim((string) ($event['title'] ?? 'Event')),
        'recipient_ids' => array_keys($activeIds),
    ];
}
