<?php
declare(strict_types=1);

require_once __DIR__ . '/event_targeting.php';
require_once __DIR__ . '/supabase.php';
require_once __DIR__ . '/student_requirements.php';

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
    // Driven only by the Allow Registration toggle (set true for free events on publish).
    return event_allows_open_registration($event);
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

function normalize_event_fee(mixed $value): ?float
{
    if ($value === null || $value === '') {
        return null;
    }

    if (is_string($value)) {
        $value = str_replace([',', ' '], '', trim($value));
    }

    if (!is_numeric($value)) {
        return null;
    }

    $fee = round((float) $value, 2);
    if ($fee < 0 || $fee > 9999999.99) {
        return null;
    }

    return $fee;
}

function event_settlement_fee(array $event): ?float
{
    return normalize_event_fee($event['event_fee'] ?? null);
}

function format_event_fee_php(?float $fee): string
{
    if ($fee === null) {
        return '';
    }
    return '₱' . number_format($fee, 2);
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

function normalize_registration_close_extend_days(mixed $value): int
{
    if ($value === null || $value === '') {
        return 0;
    }

    $days = (int) $value;
    // Stored offset from base close; hard ceiling (actual cap is start-3 days).
    return ($days >= 0 && $days <= 60) ? $days : 0;
}

function event_registration_close_weeks(array $event): ?int
{
    return normalize_registration_close_weeks($event['registration_close_weeks'] ?? null);
}

/**
 * Largest close-weeks value (0–4) whose close date is still on/after today.
 * 0 means the start is less than 1 week away (no weeks-based close option).
 */
function max_registration_close_weeks_for_start(
    DateTimeInterface $startAt,
    ?DateTimeInterface $now = null
): int {
    $manila = new DateTimeZone('Asia/Manila');
    $startDay = DateTimeImmutable::createFromInterface($startAt)
        ->setTimezone($manila)
        ->modify('today');
    $today = $now !== null
        ? DateTimeImmutable::createFromInterface($now)->setTimezone($manila)->modify('today')
        : new DateTimeImmutable('today', $manila);

    $diffDays = (int) floor(($startDay->getTimestamp() - $today->getTimestamp()) / 86400);
    if ($diffDays < 7) {
        return 0;
    }

    return min(4, intdiv($diffDays, 7));
}

function event_registration_close_extend_days(array $event): int
{
    return normalize_registration_close_extend_days($event['registration_close_extend_days'] ?? 0);
}

/**
 * Base close date from weeks rule only (no extension).
 */
function event_registration_base_last_day(array $event): ?DateTimeImmutable
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

/**
 * Latest allowed registration close date: 3 calendar days before event start.
 */
function event_registration_max_last_day(array $event): ?DateTimeImmutable
{
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

    return $startDate->modify('-3 days');
}

/**
 * Apply a user-facing extension request (days from anchor).
 * Anchor = base close if not yet past; otherwise today (Manila).
 * Resulting close date cannot pass start_date - 3 days.
 *
 * @return array{ok:bool,extend_days?:int,last_day?:?string,anchor?:?string,max_last_day?:?string,error?:string}
 */
function resolve_registration_close_extend_request(
    array $event,
    mixed $requestedDaysFromAnchor,
    ?DateTimeInterface $now = null
): array {
    $base = event_registration_base_last_day($event);
    $maxLast = event_registration_max_last_day($event);
    if ($base === null || $maxLast === null) {
        return [
            'ok' => true,
            'extend_days' => 0,
            'last_day' => null,
            'anchor' => null,
            'max_last_day' => null,
        ];
    }

    if ($requestedDaysFromAnchor === null || $requestedDaysFromAnchor === '') {
        $requested = 0;
    } elseif (!is_numeric($requestedDaysFromAnchor)) {
        return [
            'ok' => false,
            'error' => 'Extension days must be a whole number (0 or greater).',
        ];
    } else {
        $requested = (int) $requestedDaysFromAnchor;
    }

    if ($requested < 0) {
        return [
            'ok' => false,
            'error' => 'Extension days cannot be negative.',
        ];
    }
    if ($requested > 60) {
        return [
            'ok' => false,
            'error' => 'Extension days cannot be more than 60.',
        ];
    }

    $manila = new DateTimeZone('Asia/Manila');
    $today = $now !== null
        ? DateTimeImmutable::createFromInterface($now)->setTimezone($manila)->modify('today')
        : new DateTimeImmutable('today', $manila);

    $anchor = $today > $base ? $today : $base;
    $proposed = $anchor->modify('+' . $requested . ' days');

    if ($proposed > $maxLast) {
        $maxFromAnchor = (int) floor(($maxLast->getTimestamp() - $anchor->getTimestamp()) / 86400);
        if ($maxFromAnchor < 0) {
            return [
                'ok' => false,
                'error' => 'Registration can only stay open until 3 days before the event start ('
                    . $maxLast->format('M j, Y')
                    . '). That date has already passed for this schedule.',
                'anchor' => $anchor->format('Y-m-d'),
                'max_last_day' => $maxLast->format('Y-m-d'),
            ];
        }

        return [
            'ok' => false,
            'error' => 'Too many extension days. Maximum close date is 3 days before start ('
                . $maxLast->format('M j, Y')
                . '). From '
                . $anchor->format('M j, Y')
                . ' you can add at most +'
                . $maxFromAnchor
                . ' day'
                . ($maxFromAnchor === 1 ? '' : 's')
                . '.',
            'anchor' => $anchor->format('Y-m-d'),
            'max_last_day' => $maxLast->format('Y-m-d'),
        ];
    }

    // Persist as offset from base so the absolute last day stays fixed after save.
    $stored = (int) floor(($proposed->getTimestamp() - $base->getTimestamp()) / 86400);
    if ($stored < 0) {
        $stored = 0;
        $proposed = $base;
    }

    return [
        'ok' => true,
        'extend_days' => $stored,
        'last_day' => $proposed->format('Y-m-d'),
        'anchor' => $anchor->format('Y-m-d'),
        'max_last_day' => $maxLast->format('Y-m-d'),
    ];
}

function event_registration_last_day(array $event): ?DateTimeImmutable
{
    $baseLastDay = event_registration_base_last_day($event);
    if ($baseLastDay === null) {
        return null;
    }

    $extendDays = event_registration_close_extend_days($event);
    if ($extendDays > 0) {
        return $baseLastDay->modify('+' . $extendDays . ' days');
    }

    return $baseLastDay;
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

    // Prefer count=exact — never download up to 10k registration ids.
    return max(
        0,
        supabase_exact_count(
            'event_registrations',
            $headers,
            'event_id=eq.' . rawurlencode($eventId)
        )
    );
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

/**
 * When the registration close-limit date has passed, turn Allow Registration OFF.
 * Re-enabling later (toggle ON) clears the close-limit so it is no longer enforced.
 *
 * @param array<string, mixed> $event
 */
function maybe_close_event_registration_by_window(string $eventId, array &$event, array $headers): bool
{
    if (!event_allows_open_registration($event)) {
        return false;
    }
    if (!is_event_registration_window_closed($event)) {
        return false;
    }

    close_event_registration($eventId, $headers);
    $event['allow_registration'] = false;
    return true;
}

/**
 * Throttled sweep: published events still marked Allow ON past their close limit.
 */
function pulse_auto_close_registration_windows(array $headers, int $ttlSeconds = 300): void
{
    if (!function_exists('api_cache_read') || !function_exists('api_cache_write')) {
        require_once __DIR__ . '/api_cache.php';
    }

    if (is_array(api_cache_read('auto_close_registration_windows', $ttlSeconds))) {
        return;
    }

    try {
        $url = rtrim(SUPABASE_URL, '/') . '/rest/v1/events'
            . '?status=eq.published'
            . '&allow_registration=eq.true'
            . '&registration_close_weeks=not.is.null'
            . '&select=id,start_at,allow_registration,registration_close_weeks,registration_close_extend_days'
            . '&limit=200';
        $res = supabase_request('GET', $url, $headers);
        if (($res['ok'] ?? false) === true) {
            $rows = json_decode((string) ($res['body'] ?? ''), true);
            if (is_array($rows)) {
                foreach ($rows as $row) {
                    if (!is_array($row)) {
                        continue;
                    }
                    $id = trim((string) ($row['id'] ?? ''));
                    if ($id === '') {
                        continue;
                    }
                    maybe_close_event_registration_by_window($id, $row, $headers);
                }
            }
        }
    } catch (Throwable $e) {
        // Best-effort only.
    }

    api_cache_write('auto_close_registration_windows', ['ok' => true, 'at' => gmdate('c')]);
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
        . '&select=id,title,status,created_by,description,location,event_for,event_type,start_at,end_at,grace_time,event_span,allow_registration,is_free_event,event_fee,registration_limit,registration_close_weeks,registration_close_extend_days,registered_count,cover_image_url';
    $res = supabase_request('GET', $selectWithColumn, $headers);

    if (!$res['ok']) {
        $message = strtolower((string) ($res['body'] ?? '') . ' ' . (string) ($res['error'] ?? ''));
        if (registration_access_missing_column_message($message, 'registration_close_extend_days')) {
            $selectWithColumn = $baseUrl
                . '&select=id,title,status,created_by,description,location,event_for,event_type,start_at,end_at,grace_time,event_span,allow_registration,is_free_event,event_fee,registration_limit,registration_close_weeks,registered_count,cover_image_url';
            $res = supabase_request('GET', $selectWithColumn, $headers);
            $message = strtolower((string) ($res['body'] ?? '') . ' ' . (string) ($res['error'] ?? ''));
        }
        if (!$res['ok'] && registration_access_missing_column_message($message, 'event_fee')) {
            $selectWithColumn = $baseUrl
                . '&select=id,title,status,created_by,description,location,event_for,event_type,start_at,end_at,grace_time,event_span,allow_registration,is_free_event,registration_limit,registration_close_weeks,registered_count,cover_image_url';
            $res = supabase_request('GET', $selectWithColumn, $headers);
            $message = strtolower((string) ($res['body'] ?? '') . ' ' . (string) ($res['error'] ?? ''));
        }
        if (!$res['ok'] && registration_access_missing_column_message($message, 'cover_image_url')) {
            $selectWithColumn = $baseUrl
                . '&select=id,title,status,created_by,description,location,event_for,event_type,start_at,end_at,grace_time,event_span,allow_registration,is_free_event,registration_limit,registration_close_weeks,registered_count';
            $res = supabase_request('GET', $selectWithColumn, $headers);
            $message = strtolower((string) ($res['body'] ?? '') . ' ' . (string) ($res['error'] ?? ''));
        }
        if (!$res['ok'] && (registration_access_missing_column_message($message, 'allow_registration')
            || registration_access_missing_column_message($message, 'is_free_event')
            || registration_access_missing_column_message($message, 'registration_limit')
            || registration_access_missing_column_message($message, 'registration_close_weeks')
            || registration_access_missing_column_message($message, 'registered_count'))) {
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
        } elseif (!$res['ok']) {
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
    if (!array_key_exists('event_fee', $event)) {
        $event['event_fee'] = null;
    }
    if (!array_key_exists('registration_limit', $event)) {
        $event['registration_limit'] = null;
    }
    if (!array_key_exists('registration_close_weeks', $event)) {
        $event['registration_close_weeks'] = null;
    }
    if (!array_key_exists('registration_close_extend_days', $event)) {
        $event['registration_close_extend_days'] = 0;
    }
    if (!array_key_exists('registered_count', $event)) {
        $event['registered_count'] = fetch_event_registration_count((string) ($event['id'] ?? ''), $headers, $event);
    }

    $eventId = trim((string) ($event['id'] ?? ''));
    if ($eventId !== '') {
        maybe_close_event_registration_by_window($eventId, $event, $headers);
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
        . '?select=student_id,approved,payment_status,payment_note,amount_paid,updated_at'
        . '&event_id=eq.' . rawurlencode($eventId)
        . '&limit=100000';

    $res = supabase_request('GET', $url, $headers);
    if (!$res['ok']) {
        $message = (string) ($res['body'] ?? '') . ' ' . (string) ($res['error'] ?? '');
        if (registration_access_missing_table_message($message)) {
            return [];
        }
        if (registration_access_missing_column_message($message, 'amount_paid')) {
            $fallbackUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/event_registration_access'
                . '?select=student_id,approved,payment_status,payment_note,updated_at'
                . '&event_id=eq.' . rawurlencode($eventId)
                . '&limit=100000';
            $fallback = supabase_request('GET', $fallbackUrl, $headers);
            if ($fallback['ok']) {
                $rows = json_decode((string) $fallback['body'], true);
                return is_array($rows) ? $rows : [];
            }
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

    require_once __DIR__ . '/user_notifications.php';

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

    persist_user_notifications($userIds, $title, $body, $data);

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
    if ($eventId !== '') {
        maybe_close_event_registration_by_window($eventId, $event, $headers);
    }

    if ($eventId !== '' && event_registration_is_full($eventId, $event, $headers)) {
        return [
            'allowed' => false,
            'target_allowed' => true,
            'approval_required' => false,
            'controlled_registration' => !event_registration_open_to_all($event),
            'message' => 'Registration is full for this event.',
        ];
    }

    // Close-limit date passed: Allow Registration is forced OFF above.
    // Keep this gate until an organizer re-opens (which clears the close limit).
    if (is_event_registration_window_closed($event)) {
        return [
            'allowed' => false,
            'target_allowed' => true,
            'approval_required' => false,
            'controlled_registration' => !event_registration_open_to_all($event),
            'message' => 'Registration is closed for this event.',
        ];
    }

    $studentId = trim((string) ($studentRow['id'] ?? ''));
    $isPaidEvent = !event_is_free_registration_event($event);
    $controlled = !event_registration_open_to_all($event);

    // Free event with Allow Registration OFF — registration is paused.
    if ($controlled && !$isPaidEvent) {
        return [
            'allowed' => false,
            'target_allowed' => true,
            'approval_required' => false,
            'controlled_registration' => true,
            'payment_required' => false,
            'is_paid_event' => false,
            'message' => 'Registration is currently closed by the organizer.',
        ];
    }

    // Paid events with Allow Registration OFF: settle payment first, then documents.
    if ($controlled) {
        $accessRows = $accessMap ?? build_event_registration_access_map(
            fetch_event_registration_access_rows((string) ($event['id'] ?? ''), $headers)
        );

        $accessRow = $studentId !== '' && isset($accessRows[$studentId]) && is_array($accessRows[$studentId])
            ? $accessRows[$studentId]
            : null;

        if ($accessRow === null || !registration_access_row_allows($accessRow)) {
            $fee = event_settlement_fee($event);
            $feeText = format_event_fee_php($fee);
            $message = 'Settle your payment first with the authorized person assigned for this event.';
            if ($feeText !== '') {
                $message = 'Settle ' . $feeText . ' with the authorized person assigned for this event before you can continue.';
            }

            return [
                'allowed' => false,
                'target_allowed' => true,
                'approval_required' => true,
                'controlled_registration' => true,
                'payment_required' => true,
                'is_paid_event' => $isPaidEvent,
                'event_fee' => $fee,
                'amount_paid' => isset($accessRow['amount_paid']) ? (float) $accessRow['amount_paid'] : null,
                'message' => $message,
            ];
        }

        if ($eventId !== '' && $studentId !== '') {
            $docAccess = resolve_student_document_access($eventId, $studentId, $headers);
            if (($docAccess['required'] ?? false) && !($docAccess['approved'] ?? false)) {
                return [
                    'allowed' => false,
                    'target_allowed' => true,
                    'approval_required' => false,
                    'controlled_registration' => true,
                    'payment_required' => false,
                    'payment_cleared' => true,
                    'is_paid_event' => $isPaidEvent,
                    'amount_paid' => isset($accessRow['amount_paid']) ? (float) $accessRow['amount_paid'] : null,
                    'requirements_required' => true,
                    'requirements_complete' => (bool) ($docAccess['complete'] ?? false),
                    'requirements_status' => (string) ($docAccess['status'] ?? ''),
                    'requirements_decline_reason' => (string) ($docAccess['decline_reason'] ?? ''),
                    'message' => (string) ($docAccess['message'] ?? 'Submit and get your documents approved.'),
                ];
            }
        }

        return [
            'allowed' => true,
            'target_allowed' => true,
            'approval_required' => false,
            'controlled_registration' => true,
            'payment_required' => false,
            'payment_cleared' => true,
            'is_paid_event' => $isPaidEvent,
            'amount_paid' => isset($accessRow['amount_paid']) ? (float) $accessRow['amount_paid'] : null,
            'message' => '',
        ];
    }

    // Free / open registration: documents gate registration as before.
    if ($eventId !== '' && $studentId !== '') {
        $docAccess = resolve_student_document_access($eventId, $studentId, $headers);
        if (($docAccess['required'] ?? false) && !($docAccess['approved'] ?? false)) {
            return [
                'allowed' => false,
                'target_allowed' => true,
                'approval_required' => false,
                'controlled_registration' => false,
                'requirements_required' => true,
                'requirements_complete' => (bool) ($docAccess['complete'] ?? false),
                'requirements_status' => (string) ($docAccess['status'] ?? ''),
                'requirements_decline_reason' => (string) ($docAccess['decline_reason'] ?? ''),
                'message' => (string) ($docAccess['message'] ?? 'Submit and get your documents approved before registering.'),
            ];
        }
    }

    return [
        'allowed' => true,
        'target_allowed' => true,
        'approval_required' => false,
        'controlled_registration' => false,
        'message' => '',
    ];
}
