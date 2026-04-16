<?php
declare(strict_types=1);

function is_event_sessions_missing_column_error(array $response): bool
{
    $body = strtolower((string) ($response['body'] ?? ''));
    if ($body === '') {
        return false;
    }

    return str_contains($body, "'event_sessions'")
        && (
            str_contains($body, 'schema cache')
            || str_contains($body, 'column')
            || str_contains($body, 'does not exist')
        );
}

function event_sessions_supported_columns(array $headers): array
{
    static $cached = null;
    if (is_array($cached)) {
        return $cached;
    }

    if (session_status() === PHP_SESSION_ACTIVE) {
        $sessionCached = $_SESSION['event_sessions_supported_columns'] ?? null;
        $sessionCachedAt = (int) ($_SESSION['event_sessions_supported_columns_cached_at'] ?? 0);
        if (is_array($sessionCached) && $sessionCachedAt > 0 && (time() - $sessionCachedAt) < 600) {
            $cached = array_values(array_unique(array_map('strval', $sessionCached)));
            return $cached;
        }
    }

    $candidates = [
        'event_id',
        'title',
        'start_at',
        'end_at',
        'scan_window_minutes',
        'attendance_window_minutes',
        'sort_order',
        'session_no',
        'topic',
        'description',
        'location',
        'updated_at',
    ];

    $supported = ['id'];
    foreach ($candidates as $column) {
        $url = rtrim(SUPABASE_URL, '/') . '/rest/v1/event_sessions'
            . '?select=id,' . rawurlencode($column)
            . '&limit=1';
        $res = supabase_request('GET', $url, $headers);
        if ($res['ok']) {
            $supported[] = $column;
        }
    }

    $cached = array_values(array_unique($supported));

    if (session_status() === PHP_SESSION_ACTIVE) {
        $_SESSION['event_sessions_supported_columns'] = $cached;
        $_SESSION['event_sessions_supported_columns_cached_at'] = time();
    }

    return $cached;
}

function normalize_event_mode(?string $rawMode): string
{
    $mode = strtolower(trim((string) $rawMode));
    if (in_array($mode, ['seminar_based', 'one_seminar', 'two_seminars'], true)) {
        return 'seminar_based';
    }

    return 'simple';
}

function event_uses_sessions(array $event): bool
{
    if (isset($event['sessions']) && is_array($event['sessions']) && count($event['sessions']) > 0) {
        return true;
    }

    $structure = strtolower(trim((string) ($event['event_structure'] ?? '')));
    if ($structure !== '') {
        return in_array($structure, ['one_seminar', 'two_seminars'], true);
    }

    return normalize_event_mode((string) ($event['event_mode'] ?? 'simple')) === 'seminar_based';
}

function normalize_event_sessions(mixed $rawSessions, string $fallbackLocation = ''): array
{
    if (!is_array($rawSessions)) {
        return [];
    }

    $sessions = [];
    foreach (array_values($rawSessions) as $index => $rawSession) {
        if (!is_array($rawSession)) {
            continue;
        }

        $title = clean_string((string) ($rawSession['title'] ?? ''));
        $topic = clean_string((string) ($rawSession['topic'] ?? ''));
        $description = clean_text((string) ($rawSession['description'] ?? ''));
        $location = clean_string((string) ($rawSession['location'] ?? $fallbackLocation));
        $startAtRaw = trim((string) ($rawSession['start_at'] ?? ''));
        $endAtRaw = trim((string) ($rawSession['end_at'] ?? ''));

        if ($startAtRaw === '' || $endAtRaw === '') {
            continue;
        }

        try {
            $startAt = new DateTimeImmutable($startAtRaw);
            $endAt = new DateTimeImmutable($endAtRaw);
        } catch (Throwable $e) {
            throw new RuntimeException('Invalid seminar schedule.');
        }

        if ($endAt <= $startAt) {
            throw new RuntimeException('Each seminar must end after it starts.');
        }

        if ($title === '') {
            $title = $topic !== '' ? $topic : 'Seminar ' . ($index + 1);
        }

        $scanWindowMinutes = isset($rawSession['scan_window_minutes'])
            ? (int) $rawSession['scan_window_minutes']
            : (isset($rawSession['attendance_window_minutes']) ? (int) $rawSession['attendance_window_minutes'] : 30);
        if ($scanWindowMinutes <= 0) {
            $scanWindowMinutes = 30;
        }

        $sessions[] = [
            'title' => $title,
            'topic' => $topic !== '' ? $topic : null,
            'description' => $description !== '' ? $description : null,
            'location' => $location !== '' ? $location : null,
            'start_at' => $startAt->format('c'),
            'end_at' => $endAt->format('c'),
            'scan_window_minutes' => $scanWindowMinutes,
            'sort_order' => isset($rawSession['sort_order']) ? max(0, (int) $rawSession['sort_order']) : $index,
        ];
    }

    usort($sessions, static function (array $a, array $b): int {
        $sortCompare = ((int) $a['sort_order']) <=> ((int) $b['sort_order']);
        if ($sortCompare !== 0) {
            return $sortCompare;
        }

        return strcmp((string) $a['start_at'], (string) $b['start_at']);
    });

    return array_values($sessions);
}

function derive_event_window_from_sessions(array $sessions): array
{
    if (empty($sessions)) {
        throw new RuntimeException('At least one seminar is required.');
    }

    $startAt = null;
    $endAt = null;
    foreach ($sessions as $session) {
        $sessionStart = new DateTimeImmutable((string) ($session['start_at'] ?? ''));
        $sessionEndRaw = trim((string) ($session['end_at'] ?? ''));
        $sessionEnd = $sessionEndRaw !== ''
            ? new DateTimeImmutable($sessionEndRaw)
            : $sessionStart->modify('+60 minutes');

        if ($startAt === null || $sessionStart < $startAt) {
            $startAt = $sessionStart;
        }
        if ($endAt === null || $sessionEnd > $endAt) {
            $endAt = $sessionEnd;
        }
    }

    return [
        'start_at' => $startAt?->format('c'),
        'end_at' => $endAt?->format('c'),
    ];
}

function validate_non_overlapping_sessions(array $sessions): void
{
    $ordered = $sessions;
    usort($ordered, static function (array $a, array $b): int {
        return strcmp((string) $a['start_at'], (string) $b['start_at']);
    });

    for ($i = 1, $len = count($ordered); $i < $len; $i++) {
        $prevStart = new DateTimeImmutable((string) $ordered[$i - 1]['start_at']);
        $prevEndRaw = trim((string) ($ordered[$i - 1]['end_at'] ?? ''));
        $prevEnd = $prevEndRaw !== '' ? new DateTimeImmutable($prevEndRaw) : $prevStart->modify('+60 minutes');
        $currStart = new DateTimeImmutable((string) ($ordered[$i]['start_at'] ?? ''));
        if ($currStart < $prevEnd) {
            throw new RuntimeException('Seminar schedules must not overlap.');
        }
    }
}

function replace_event_sessions(string $eventId, array $sessions, array $headers): void
{
    $deleteUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/event_sessions?event_id=eq.' . rawurlencode($eventId);
    $deleteRes = supabase_request('DELETE', $deleteUrl, $headers);
    if (!$deleteRes['ok']) {
        throw new RuntimeException(build_error($deleteRes['body'] ?? null, (int) ($deleteRes['status'] ?? 0), $deleteRes['error'] ?? null, 'Failed to clear seminar sessions'));
    }

    if (count($sessions) === 0) {
        return;
    }

    $supportedColumns = event_sessions_supported_columns($headers);
    $hasEndAt = in_array('end_at', $supportedColumns, true);
    $hasScanWindow = in_array('scan_window_minutes', $supportedColumns, true);
    $hasAttendanceWindow = in_array('attendance_window_minutes', $supportedColumns, true);
    $hasSortOrder = in_array('sort_order', $supportedColumns, true);
    $hasSessionNo = in_array('session_no', $supportedColumns, true);
    $hasTopic = in_array('topic', $supportedColumns, true);
    $hasDescription = in_array('description', $supportedColumns, true);
    $hasLocation = in_array('location', $supportedColumns, true);
    $hasUpdatedAt = in_array('updated_at', $supportedColumns, true);

    $payload = [];
    foreach (array_values($sessions) as $index => $session) {
        $row = [
            'event_id' => $eventId,
            'title' => $session['title'],
            'start_at' => $session['start_at'],
        ];

        if ($hasEndAt) {
            $row['end_at'] = $session['end_at'];
        }
        if ($hasScanWindow) {
            $row['scan_window_minutes'] = $session['scan_window_minutes'];
        } elseif ($hasAttendanceWindow) {
            $row['attendance_window_minutes'] = $session['scan_window_minutes'];
        }
        if ($hasSortOrder) {
            $row['sort_order'] = $session['sort_order'];
        }
        if ($hasSessionNo) {
            $row['session_no'] = $index + 1;
        }
        if ($hasTopic) {
            $row['topic'] = $session['topic'];
        }
        if ($hasDescription) {
            $row['description'] = $session['description'];
        }
        if ($hasLocation) {
            $row['location'] = $session['location'];
        }
        if ($hasUpdatedAt) {
            $row['updated_at'] = gmdate('c');
        }

        $payload[] = $row;
    }

    $createUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/event_sessions?select=id,event_id,title,start_at';
    $createHeaders = $headers;
    $createHeaders[] = 'Content-Type: application/json';
    $createHeaders[] = 'Prefer: return=representation';
    $createRes = supabase_request('POST', $createUrl, $createHeaders, json_encode($payload, JSON_UNESCAPED_SLASHES));
    if (!$createRes['ok']) {
        throw new RuntimeException(build_error($createRes['body'] ?? null, (int) ($createRes['status'] ?? 0), $createRes['error'] ?? null, 'Failed to save seminar sessions'));
    }
}

function fetch_event_sessions(string $eventId, array $headers): array
{
    $supportedColumns = event_sessions_supported_columns($headers);
    $selectColumns = ['id', 'event_id', 'title', 'start_at'];
    foreach (['topic', 'description', 'location', 'end_at', 'scan_window_minutes', 'attendance_window_minutes', 'sort_order', 'session_no'] as $column) {
        if (in_array($column, $supportedColumns, true)) {
            $selectColumns[] = $column;
        }
    }

    $url = rtrim(SUPABASE_URL, '/') . '/rest/v1/event_sessions'
        . '?select=' . implode(',', $selectColumns)
        . '&event_id=eq.' . rawurlencode($eventId);
    if (in_array('sort_order', $supportedColumns, true)) {
        $url .= '&order=sort_order.asc';
    } elseif (in_array('session_no', $supportedColumns, true)) {
        $url .= '&order=session_no.asc';
    }
    $url .= '&order=start_at.asc';

    $res = supabase_request('GET', $url, $headers);
    if (!$res['ok']) {
        if (is_event_sessions_missing_column_error($res)) {
            return [];
        }
        return [];
    }

    $rows = json_decode((string) $res['body'], true);
    if (!is_array($rows)) {
        return [];
    }

    $normalized = [];
    foreach (array_values($rows) as $index => $row) {
        if (!is_array($row)) {
            continue;
        }
        $startAt = trim((string) ($row['start_at'] ?? ''));
        if ($startAt === '') {
            continue;
        }

        $scanWindow = 30;
        if (isset($row['scan_window_minutes'])) {
            $scanWindow = max(1, (int) $row['scan_window_minutes']);
        } elseif (isset($row['attendance_window_minutes'])) {
            $scanWindow = max(1, (int) $row['attendance_window_minutes']);
        }

        $endAt = trim((string) ($row['end_at'] ?? ''));
        if ($endAt === '') {
            try {
                $endAt = (new DateTimeImmutable($startAt))->modify('+60 minutes')->format('c');
            } catch (Throwable $e) {
                $endAt = $startAt;
            }
        }

        $normalized[] = [
            'id' => (string) ($row['id'] ?? ''),
            'event_id' => (string) ($row['event_id'] ?? $eventId),
            'title' => (string) ($row['title'] ?? ''),
            'topic' => isset($row['topic']) ? (string) $row['topic'] : null,
            'description' => isset($row['description']) ? (string) $row['description'] : null,
            'location' => isset($row['location']) ? (string) $row['location'] : null,
            'start_at' => $startAt,
            'end_at' => $endAt,
            'scan_window_minutes' => $scanWindow,
            'sort_order' => isset($row['sort_order'])
                ? (int) $row['sort_order']
                : (isset($row['session_no']) ? max(0, (int) $row['session_no'] - 1) : $index),
            'session_no' => isset($row['session_no']) ? (int) $row['session_no'] : ($index + 1),
        ];
    }

    return $normalized;
}

function attach_event_sessions_to_events(array $events, array $headers): array
{
    foreach ($events as $index => $event) {
        if (!is_array($event)) {
            continue;
        }

        $eventId = (string) ($event['id'] ?? '');
        if ($eventId === '') {
            continue;
        }

        $events[$index]['sessions'] = fetch_event_sessions($eventId, $headers);
    }

    return $events;
}

function resolve_active_event_session(string $eventId, DateTimeImmutable $nowUtc, array $headers): array
{
    $sessions = fetch_event_sessions($eventId, $headers);
    if (empty($sessions)) {
        return ['status' => 'missing', 'session' => null, 'sessions' => []];
    }

    $openSessions = [];
    foreach ($sessions as $session) {
        try {
            $startAt = new DateTimeImmutable((string) ($session['start_at'] ?? ''));
        } catch (Throwable $e) {
            continue;
        }

        $windowMinutes = max(1, (int) ($session['scan_window_minutes'] ?? 30));
        $windowEnd = $startAt->modify('+' . $windowMinutes . ' minutes');

        if ($nowUtc < $startAt) {
            continue;
        }

        if ($nowUtc <= $windowEnd) {
            $session['_window_end'] = $windowEnd->format('c');
            $openSessions[] = $session;
        }
    }

    if (count($openSessions) === 1) {
        return ['status' => 'open', 'session' => $openSessions[0], 'sessions' => $sessions];
    }

    if (count($openSessions) > 1) {
        return ['status' => 'ambiguous', 'session' => null, 'sessions' => $openSessions];
    }

    return ['status' => 'closed', 'session' => null, 'sessions' => $sessions];
}

function build_session_display_name(array $session, ?string $fallback = null): string
{
    $title = trim((string) ($session['title'] ?? ''));
    if ($title !== '') {
        return $title;
    }

    $topic = trim((string) ($session['topic'] ?? ''));
    if ($topic !== '') {
        return $topic;
    }

    return $fallback !== null && trim($fallback) !== '' ? $fallback : 'Seminar';
}
