<?php
declare(strict_types=1);

require_once __DIR__ . '/event_targeting.php';
require_once __DIR__ . '/supabase.php';

function registration_access_missing_table_message(string $message): bool
{
    $lower = strtolower($message);
    return (str_contains($lower, 'event_registration_access') && str_contains($lower, 'does not exist'))
        || str_contains($lower, '42p01')
        || str_contains($lower, 'pgrst205');
}

function registration_access_missing_column_message(string $message, string $column): bool
{
    $lower = strtolower($message);
    $columnLower = strtolower($column);
    return str_contains($lower, $columnLower)
        && (
            str_contains($lower, 'column')
            || str_contains($lower, 'does not exist')
            || str_contains($lower, 'schema cache')
            || str_contains($lower, 'pgrst204')
        );
}

function normalize_registration_bool(mixed $value): bool
{
    if (is_bool($value)) {
        return $value;
    }

    $normalized = strtolower(trim((string) $value));
    return in_array($normalized, ['1', 'true', 't', 'yes', 'y', 'on', 'paid', 'check', 'checked', 'ok', 'approve', 'approved', '✓', '✔'], true);
}

function normalize_registration_payment_status(mixed $value): string
{
    $normalized = strtolower(trim((string) $value));
    return match ($normalized) {
        'paid', 'approve', 'approved', 'allow', 'allowed', 'yes', 'y', '1', 'true', 't', 'check', 'checked', '✓', '✔' => 'paid',
        'waived', 'waive', 'free', 'exempt' => 'waived',
        'rejected', 'reject', 'declined', 'denied', 'deny', 'blocked', 'no', 'n', '0', 'false', 'f' => 'rejected',
        default => 'pending',
    };
}

function event_allows_open_registration(array $event): bool
{
    return normalize_registration_bool($event['allow_registration'] ?? false);
}

function event_is_free_registration_event(array $event): bool
{
    return normalize_registration_bool($event['is_free_event'] ?? true);
}

function event_registration_open_to_all(array $event): bool
{
    if (event_allows_open_registration($event)) {
        return true;
    }

    if (!event_is_free_registration_event($event)) {
        return false;
    }

    $status = strtolower(trim((string) ($event['status'] ?? '')));
    return $status === 'published';
}

function normalize_registration_limit(mixed $value): ?int
{
    if ($value === null || $value === '') {
        return null;
    }

    $limit = (int) $value;
    if ($limit < 1 || $limit > 9999) {
        return null;
    }

    return $limit;
}

function event_registration_limit(array $event): ?int
{
    return normalize_registration_limit($event['registration_limit'] ?? null);
}

function format_event_registration_total(int $count, array $event): string
{
    $limit = event_registration_limit($event);
    if ($limit !== null) {
        return $count . '/' . $limit;
    }

    return (string) $count;
}

function normalize_registration_close_weeks(mixed $value): ?int
{
    if ($value === null || $value === '') {
        return null;
    }

    $weeks = (int) $value;
    return ($weeks >= 1 && $weeks <= 4) ? $weeks : null;
}

function event_registration_close_weeks(array $event): ?int
{
    return normalize_registration_close_weeks($event['registration_close_weeks'] ?? null);
}

function event_registration_last_day(array $event): ?DateTimeImmutable
{
    $weeks = event_registration_close_weeks($event);
    if ($weeks === null) {
        return null;
    }

    $startAt = trim((string) ($event['start_at'] ?? ''));
    if ($startAt === '') {
        return null;
    }

    try {
        $start = new DateTimeImmutable($startAt);
    } catch (Throwable $e) {
        return null;
    }

    $manila = new DateTimeZone('Asia/Manila');
    $startInManila = $start->setTimezone($manila);
    $startDate = new DateTimeImmutable($startInManila->format('Y-m-d'), $manila);

    return $startDate->modify('-' . $weeks . ' weeks');
}

function is_event_registration_window_closed(array $event, ?DateTimeInterface $now = null): bool
{
    $lastDay = event_registration_last_day($event);
    if ($lastDay === null) {
        return false;
    }

    $manila = new DateTimeZone('Asia/Manila');
    $today = $now !== null
        ? DateTimeImmutable::createFromInterface($now)->setTimezone($manila)->modify('today')
        : new DateTimeImmutable('today', $manila);

    return $today > $lastDay;
}

function fetch_event_registration_count(string $eventId, array $headers, ?array $event = null): int
{
    if (is_array($event) && array_key_exists('registered_count', $event)) {
        return max(0, (int) ($event['registered_count'] ?? 0));
    }

    $eventId = trim($eventId);
    if ($eventId === '') {
        return 0;
    }

    $url = rtrim(SUPABASE_URL, '/') . '/rest/v1/event_registrations'
        . '?select=id'
        . '&event_id=eq.' . rawurlencode($eventId)
        . '&limit=10000';

    $res = supabase_request('GET', $url, [
        'Accept: application/json',
        'apikey: ' . SUPABASE_KEY,
        'Authorization: Bearer ' . SUPABASE_KEY,
    ]);

    if (!$res['ok']) {
        return 0;
    }

    $rows = json_decode((string) $res['body'], true);
    return is_array($rows) ? count($rows) : 0;
}

function event_registration_is_full(string $eventId, array $event, array $headers): bool
{
    $limit = event_registration_limit($event);
    if ($limit === null) {
        return false;
    }

    return fetch_event_registration_count($eventId, $headers, $event) >= $limit;
}

function close_event_registration(string $eventId, array $headers): void
{
    $eventId = trim($eventId);
    if ($eventId === '') {
        return;
    }

    $url = rtrim(SUPABASE_URL, '/') . '/rest/v1/events?id=eq.' . rawurlencode($eventId);
    supabase_request('PATCH', $url, array_merge($headers, [
        'Content-Type: application/json',
        'Prefer: return=minimal',
    ]), json_encode([
        'allow_registration' => false,
        'updated_at' => gmdate('c'),
    ], JSON_UNESCAPED_SLASHES));
}

function maybe_close_event_registration_at_capacity(string $eventId, array $event, array $headers): void
{
    if (!event_registration_is_full($eventId, $event, $headers)) {
        return;
    }

    close_event_registration($eventId, $headers);
}

function build_registration_access_template_key(string $eventId): string
{
    return hash_hmac('sha256', 'registration-access|' . trim($eventId), SUPABASE_KEY);
}

function fetch_event_with_registration_settings(string $eventId, array $headers): ?array
{
    $baseUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/events'
        . '?id=eq.' . rawurlencode($eventId)
        . '&limit=1';
    $selectWithColumn = $baseUrl
        . '&select=id,title,status,created_by,description,location,event_for,event_type,start_at,end_at,grace_time,event_span,allow_registration,is_free_event,registration_limit,registration_close_weeks,registered_count';
    $res = supabase_request('GET', $selectWithColumn, $headers);

    if (!$res['ok']) {
        $message = strtolower((string) ($res['body'] ?? '') . ' ' . (string) ($res['error'] ?? ''));
        if (registration_access_missing_column_message($message, 'allow_registration')
            || registration_access_missing_column_message($message, 'is_free_event')
            || registration_access_missing_column_message($message, 'registration_limit')
            || registration_access_missing_column_message($message, 'registration_close_weeks')
            || registration_access_missing_column_message($message, 'registered_count')) {
            $fallbackUrl = $baseUrl
                . '&select=id,title,status,created_by,description,location,event_for,event_type,start_at,end_at,grace_time,event_span,allow_registration';
            $res = supabase_request('GET', $fallbackUrl, $headers);
            if (!$res['ok']) {
                $message = strtolower((string) ($res['body'] ?? '') . ' ' . (string) ($res['error'] ?? ''));
                if (!registration_access_missing_column_message($message, 'allow_registration')) {
                    return null;
                }

                $fallbackUrl = $baseUrl
                    . '&select=id,title,status,created_by,description,location,event_for,event_type,start_at,end_at,grace_time,event_span';
                $res = supabase_request('GET', $fallbackUrl, $headers);
                if (!$res['ok']) {
                    return null;
                }
            }
        } else {
            return null;
        }
    }

    $rows = json_decode((string) $res['body'], true);
    if (!is_array($rows) || !isset($rows[0]) || !is_array($rows[0])) {
        return null;
    }

    $event = $rows[0];
    if (!array_key_exists('allow_registration', $event)) {
        $event['allow_registration'] = false;
    }
    if (!array_key_exists('is_free_event', $event)) {
        $event['is_free_event'] = true;
    }
    if (!array_key_exists('registration_limit', $event)) {
        $event['registration_limit'] = null;
    }
    if (!array_key_exists('registration_close_weeks', $event)) {
        $event['registration_close_weeks'] = null;
    }
    if (!array_key_exists('registered_count', $event)) {
        $event['registered_count'] = fetch_event_registration_count((string) ($event['id'] ?? ''), $headers, $event);
    }

    return $event;
}

function registration_access_row_allows(array $row): bool
{
    if (normalize_registration_bool($row['approved'] ?? false)) {
        return true;
    }

    $status = normalize_registration_payment_status($row['payment_status'] ?? '');
    return in_array($status, ['paid', 'waived'], true);
}

function fetch_target_students_for_event(array $event, array $headers): array
{
    $url = rtrim(SUPABASE_URL, '/') . '/rest/v1/users'
        . '?select=id,first_name,middle_name,last_name,suffix,email,student_id,course,sections(name)'
        . '&role=eq.student'
        . '&limit=100000';

    $res = supabase_request('GET', $url, $headers);
    $rows = $res['ok'] ? json_decode((string) $res['body'], true) : [];
    if (!is_array($rows)) {
        return [];
    }

    $eventFor = (string) ($event['event_for'] ?? 'All');
    $filtered = [];
    foreach ($rows as $row) {
        if (!is_array($row) || !student_matches_event_target($row, $eventFor)) {
            continue;
        }

        $row['section_name'] = extract_section_name($row['sections'] ?? null);
        $row['year_level'] = extract_student_year_level($row);
        $row['normalized_course'] = normalize_student_course_code($row);
        $row['display_name'] = compose_student_display_name($row);
        $filtered[] = $row;
    }

    usort($filtered, static function (array $a, array $b): int {
        return strcmp(
            strtolower((string) ($a['display_name'] ?? '')),
            strtolower((string) ($b['display_name'] ?? ''))
        );
    });

    return $filtered;
}

function fetch_event_registration_access_rows(string $eventId, array $headers): array
{
    $url = rtrim(SUPABASE_URL, '/') . '/rest/v1/event_registration_access'
        . '?select=student_id,approved,payment_status,payment_note,updated_at'
        . '&event_id=eq.' . rawurlencode($eventId)
        . '&limit=100000';

    $res = supabase_request('GET', $url, $headers);
    if (!$res['ok']) {
        $message = (string) ($res['body'] ?? '') . ' ' . (string) ($res['error'] ?? '');
        if (registration_access_missing_table_message($message)) {
            return [];
        }
        return [];
    }

    $rows = json_decode((string) $res['body'], true);
    return is_array($rows) ? $rows : [];
}

function build_event_registration_access_map(array $rows): array
{
    $mapped = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $studentId = trim((string) ($row['student_id'] ?? ''));
        if ($studentId === '') {
            continue;
        }
        $mapped[$studentId] = $row;
    }
    return $mapped;
}

function notify_users_for_registration_access(array $userIds, string $title, string $body, array $data = []): void
{
    $userIds = array_values(array_filter(array_map(
        static fn ($value): string => trim((string) $value),
        $userIds
    )));

    if ($userIds === []) {
        return;
    }

    require_once __DIR__ . '/fcm.php';

    $inList = '(' . implode(',', array_map('rawurlencode', $userIds)) . ')';
    $res = supabase_request(
        'GET',
        rtrim(SUPABASE_URL, '/') . '/rest/v1/fcm_tokens?select=token&user_id=in.' . $inList,
        [
            'Accept: application/json',
            'apikey: ' . SUPABASE_KEY,
            'Authorization: Bearer ' . SUPABASE_KEY,
        ]
    );

    if (!$res['ok']) {
        return;
    }

    $rows = json_decode((string) $res['body'], true);
    $tokens = [];
    if (is_array($rows)) {
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $token = trim((string) ($row['token'] ?? ''));
            if ($token !== '') {
                $tokens[$token] = true;
            }
        }
    }

    if ($tokens !== []) {
        send_fcm_notification(array_keys($tokens), $title, $body, $data);
    }
}

function fetch_student_profile_by_id(string $studentId, array $headers): ?array
{
    if ($studentId === '') {
        return null;
    }

    $url = rtrim(SUPABASE_URL, '/') . '/rest/v1/users'
        . '?select=id,first_name,middle_name,last_name,suffix,email,student_id,course,sections(name),role'
        . '&id=eq.' . rawurlencode($studentId)
        . '&role=eq.student'
        . '&limit=1';
    $res = supabase_request('GET', $url, $headers);
    $rows = $res['ok'] ? json_decode((string) $res['body'], true) : [];
    if (!is_array($rows) || !isset($rows[0]) || !is_array($rows[0])) {
        return null;
    }

    $row = $rows[0];
    $row['section_name'] = extract_section_name($row['sections'] ?? null);
    $row['year_level'] = extract_student_year_level($row);
    $row['normalized_course'] = normalize_student_course_code($row);
    $row['display_name'] = compose_student_display_name($row);
    return $row;
}

function resolve_student_registration_access(array $event, array $studentRow, array $headers, ?array $accessMap = null): array
{
    $status = strtolower(trim((string) ($event['status'] ?? '')));
    if ($status !== 'published') {
        return [
            'allowed' => false,
            'target_allowed' => false,
            'approval_required' => false,
            'controlled_registration' => false,
            'message' => 'Registration is currently closed.',
        ];
    }

    if (!student_matches_event_target($studentRow, (string) ($event['event_for'] ?? 'All'))) {
        return [
            'allowed' => false,
            'target_allowed' => false,
            'approval_required' => false,
            'controlled_registration' => false,
            'message' => 'This event is not available for your course/year level.',
        ];
    }

    $eventId = trim((string) ($event['id'] ?? ''));
    if ($eventId !== '' && event_registration_is_full($eventId, $event, $headers)) {
        return [
            'allowed' => false,
            'target_allowed' => true,
            'approval_required' => false,
            'controlled_registration' => !event_registration_open_to_all($event),
            'message' => 'Registration is full for this event.',
        ];
    }

    if (is_event_registration_window_closed($event)) {
        return [
            'allowed' => false,
            'target_allowed' => true,
            'approval_required' => false,
            'controlled_registration' => !event_registration_open_to_all($event),
            'message' => 'Registration is closed for this event.',
        ];
    }

    if (event_registration_open_to_all($event)) {
        return [
            'allowed' => true,
            'target_allowed' => true,
            'approval_required' => false,
            'controlled_registration' => false,
            'message' => '',
        ];
    }

    $accessRows = $accessMap ?? build_event_registration_access_map(
        fetch_event_registration_access_rows((string) ($event['id'] ?? ''), $headers)
    );

    $studentId = trim((string) ($studentRow['id'] ?? ''));
    $accessRow = $studentId !== '' && isset($accessRows[$studentId]) && is_array($accessRows[$studentId])
        ? $accessRows[$studentId]
        : null;

    if ($accessRow !== null && registration_access_row_allows($accessRow)) {
        return [
            'allowed' => true,
            'target_allowed' => true,
            'approval_required' => false,
            'controlled_registration' => true,
            'message' => '',
        ];
    }

    return [
        'allowed' => false,
        'target_allowed' => true,
        'approval_required' => true,
        'controlled_registration' => true,
        'message' => 'Registration requires payment approval first.',
    ];
}
