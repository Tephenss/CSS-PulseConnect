<?php
declare(strict_types=1);

require_once __DIR__ . '/event_targeting.php';

function clean_string(string $v): string
{
    return trim(preg_replace('/\s+/', ' ', $v) ?? '');
}

function is_valid_person_name(string $name, bool $required = true, int $minLen = 2, int $maxLen = 60): bool
{
    if ($name === '') {
        return !$required;
    }
    $len = mb_strlen($name);
    if ($len < $minLen || $len > $maxLen) {
        return false;
    }
    if (preg_match('/\d/u', $name)) {
        return false;
    }
    return (bool) preg_match("/^[\\p{L}][\\p{L}\\s.'\\-]*$/u", $name);
}

function normalize_ph_contact_digits(string $raw): string
{
    return preg_replace('/\D+/', '', $raw) ?? '';
}

function is_valid_ph_contact(string $digits, bool $required = false): bool
{
    if ($digits === '') {
        return !$required;
    }
    return strlen($digits) === 11 && ctype_digit($digits);
}

function clean_text(string $v): string
{
    return trim($v);
}

function format_date_local(?string $dateStr, string $format = 'M d, Y - g:i A'): string
{
    if (!$dateStr)
        return '';
    try {
        $dt = new DateTimeImmutable($dateStr);
        $dt = $dt->setTimezone(new DateTimeZone('Asia/Manila'));
        return $dt->format($format);
    } catch (Throwable $e) {
        return $dateStr;
    }
}

function build_display_name(string $first, string $middle, string $last, string $suffix): string
{
    $parts = [];
    if ($first !== '') {
        $parts[] = $first;
    }
    if ($middle !== '') {
        $parts[] = $middle;
    }
    if ($last !== '') {
        $parts[] = $last;
    }

    $base = implode(' ', $parts);
    if ($suffix !== '') {
        return $base . ', ' . $suffix;
    }

    return $base;
}

function password_policy_error(): string
{
    return 'Use 8+ chars with upper, lower, number, and symbol.';
}

function password_policy_score(string $value): int
{
    $score = 0;
    if (mb_strlen($value) >= 8) {
        $score++;
    }
    if (preg_match('/[A-Z]/', $value)) {
        $score++;
    }
    if (preg_match('/[a-z]/', $value)) {
        $score++;
    }
    if (preg_match('/\d/', $value)) {
        $score++;
    }
    if (preg_match('/[^A-Za-z0-9]/', $value)) {
        $score++;
    }
    return $score;
}

function is_strong_password(string $value): bool
{
    return mb_strlen($value) >= 8
        && preg_match('/[A-Z]/', $value) === 1
        && preg_match('/[a-z]/', $value) === 1
        && preg_match('/\d/', $value) === 1
        && preg_match('/[^A-Za-z0-9]/', $value) === 1;
}

/**
 * Decode legacy event_for value into separate course/year selectors.
 *
 * course: ALL | BSIT | BSIT-SD | BSIT-BA | BSCS
 * year:   ALL | 1 | 2 | 3 | 4
 */
function decode_target_participant(string $eventFor): array
{
    $raw = strtoupper(trim($eventFor));
    if ($raw === '' || $raw === 'ALL' || $raw === 'ALL LEVELS') {
        return ['course' => 'ALL', 'year' => 'ALL', 'years' => ['ALL']];
    }

    if ($raw === 'NONE') {
        return ['course' => 'ALL', 'year' => 'ALL', 'years' => ['ALL']];
    }

    $course = 'ALL';
    $years = ['ALL'];

    // Supports BSIT-1 / BSCS_2 / BSIT|3 style values
    if (preg_match('/^(BSIT|BSCS)\s*[-_|]\s*([1-4])$/', $raw, $m)) {
        $course = $m[1];
        $years = [$m[2]];
        return ['course' => $course, 'year' => $years[0], 'years' => $years];
    }

    // New format: COURSE=BSIT;YEARS=1,2 (also BSIT-SD / BSIT-BA)
    if (preg_match('/^COURSE\s*=\s*(ALL|BSIT-SD|BSIT-BA|BSIT|BSCS)\s*;\s*YEARS\s*=\s*([0-9,\sA-Z]+)$/', $raw, $m)) {
        $course = normalize_event_target_course($m[1]);
        $rawYears = preg_split('/\s*,\s*/', trim($m[2])) ?: [];
        $normalizedYears = [];
        foreach ($rawYears as $y) {
            $candidate = strtoupper(trim((string) $y));
            if ($candidate === 'ALL') {
                $normalizedYears = ['ALL'];
                break;
            }
            if (in_array($candidate, ['1', '2', '3', '4'], true)) {
                $normalizedYears[$candidate] = true;
            }
        }
        if (empty($normalizedYears)) {
            $years = ['ALL'];
        } elseif (array_is_list($normalizedYears)) {
            $years = $normalizedYears;
        } else {
            $years = array_keys($normalizedYears);
        }

        return ['course' => $course, 'year' => $years[0], 'years' => $years];
    }

    $standalone = normalize_event_target_course($raw);
    if (in_array($standalone, ['BSIT', 'BSIT-SD', 'BSIT-BA', 'BSCS'], true)) {
        $course = $standalone;
    } elseif (in_array($raw, ['1', '2', '3', '4'], true)) {
        $years = [$raw];
    }

    return ['course' => $course, 'year' => $years[0], 'years' => $years];
}

function encode_target_participant(string $course, mixed $year): string
{
    $course = strtoupper(trim($course));
    $years = [];
    if (is_array($year)) {
        foreach ($year as $candidate) {
            $value = strtoupper(trim((string) $candidate));
            if ($value !== '') {
                $years[] = $value;
            }
        }
    } else {
        $single = strtoupper(trim((string) $year));
        if ($single !== '') {
            $years[] = $single;
        }
    }

    if (!in_array($course, ['ALL', 'BSIT', 'BSIT-SD', 'BSIT-BA', 'BSCS'], true)) {
        $course = 'ALL';
    }

    $normalizedYears = [];
    foreach ($years as $y) {
        if ($y === 'ALL') {
            $normalizedYears = ['ALL'];
            break;
        }
        if (in_array($y, ['1', '2', '3', '4'], true)) {
            $normalizedYears[$y] = true;
        }
    }
    if (empty($normalizedYears)) {
        $normalizedYears = ['ALL'];
    } elseif (!array_is_list($normalizedYears)) {
        $normalizedYears = array_keys($normalizedYears);
    }

    $isAllYears = count($normalizedYears) === 1 && $normalizedYears[0] === 'ALL';
    if ($course === 'ALL' && $isAllYears) {
        return 'All';
    }
    if ($course === 'ALL' && count($normalizedYears) === 1) {
        return $normalizedYears[0];
    }
    if ($isAllYears) {
        return $course;
    }

    return 'COURSE=' . $course . ';YEARS=' . implode(',', $normalizedYears);
}

function format_target_participant(string $eventFor): string
{
    $decoded = decode_target_participant($eventFor);
    $course = (string) ($decoded['course'] ?? 'ALL');
    $years = isset($decoded['years']) && is_array($decoded['years']) ? $decoded['years'] : ['ALL'];

    $courseLabel = match ($course) {
        'BSIT' => 'BSIT',
        'BSIT-SD' => 'BSIT-SD',
        'BSIT-BA' => 'BSIT-BA',
        'BSCS' => 'BSCS',
        default => 'All Courses',
    };

    if (count($years) === 1 && $years[0] === 'ALL' && $course === 'ALL') {
        return 'All Courses - All Levels';
    }

    if (count($years) === 1 && $years[0] === 'ALL') {
        return $courseLabel . ' - All Levels';
    }

    $yearLabelMap = [
        '1' => '1st Year',
        '2' => '2nd Year',
        '3' => '3rd Year',
        '4' => '4th Year',
    ];
    $yearLabels = [];
    foreach ($years as $year) {
        $key = (string) $year;
        if (isset($yearLabelMap[$key])) {
            $yearLabels[] = $yearLabelMap[$key];
        }
    }
    if (empty($yearLabels)) {
        $yearLabels[] = 'All Levels';
    }

    return $courseLabel . ' - ' . implode(', ', $yearLabels);
}

/**
 * @return array{ok:bool,event:?array,status:int,message:string}
 */
function fetch_event_row_by_id(
    string $eventId,
    array $headers,
    string $select = 'id,title,start_at,end_at,created_by,status,grace_time'
): array {
    $eventId = trim($eventId);
    if ($eventId === '') {
        return [
            'ok' => false,
            'event' => null,
            'status' => 400,
            'message' => 'Missing event_id.',
        ];
    }

    $url = rtrim(SUPABASE_URL, '/') . '/rest/v1/events?select=' . $select
        . '&id=eq.' . rawurlencode($eventId)
        . '&limit=1';
    $res = supabase_request('GET', $url, $headers);

    if (!($res['ok'] ?? false)) {
        return [
            'ok' => false,
            'event' => null,
            'status' => (int) ($res['status'] ?? 0) >= 400 ? (int) $res['status'] : 503,
            'message' => build_error(
                $res['body'] ?? null,
                (int) ($res['status'] ?? 0),
                $res['error'] ?? null,
                'Could not load event.'
            ),
        ];
    }

    $rows = json_decode((string) ($res['body'] ?? ''), true);
    $event = is_array($rows) && isset($rows[0]) && is_array($rows[0]) ? $rows[0] : null;
    if ($event === null) {
        return [
            'ok' => false,
            'event' => null,
            'status' => 404,
            'message' => 'Event not found.',
        ];
    }

    return [
        'ok' => true,
        'event' => $event,
        'status' => 200,
        'message' => '',
    ];
}

function pulse_auto_finish_published_events(array $headers, int $ttlSeconds = 300): void
{
    if (!function_exists('api_cache_read') || !function_exists('api_cache_write')) {
        require_once __DIR__ . '/api_cache.php';
    }

    if (is_array(api_cache_read('auto_finish_published_events', $ttlSeconds))) {
        return;
    }

    try {
        if (!function_exists('attendance_event_is_past_lifecycle')) {
            require_once __DIR__ . '/event_attendance_windows.php';
        }

        $nowUtc = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $selectCols = 'id,status,title,description,location,start_at,end_at,cover_image_url,event_type,event_for,updated_at,early_out_enabled_at';
        $listUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/events'
            . '?status=eq.published'
            . '&select=' . rawurlencode($selectCols)
            . '&order=end_at.asc'
            . '&limit=250';
        $listRes = supabase_request('GET', $listUrl, $headers);
        if (!($listRes['ok'] ?? false)) {
            // Older DBs may lack early_out_enabled_at.
            $selectCols = 'id,status,title,description,location,start_at,end_at,cover_image_url,event_type,event_for,updated_at';
            $listUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/events'
                . '?status=eq.published'
                . '&select=' . rawurlencode($selectCols)
                . '&order=end_at.asc'
                . '&limit=250';
            $listRes = supabase_request('GET', $listUrl, $headers);
        }

        $rows = ($listRes['ok'] ?? false) ? json_decode((string) ($listRes['body'] ?? ''), true) : [];
        if (!is_array($rows) || $rows === []) {
            api_cache_write('auto_finish_published_events', ['ok' => true, 'at' => gmdate('c')]);
            return;
        }

        $finishIds = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = trim((string) ($row['id'] ?? ''));
            if ($id === '') {
                continue;
            }
            $endAt = null;
            try {
                $endRaw = trim((string) ($row['end_at'] ?? ''));
                if ($endRaw !== '') {
                    $endAt = new DateTimeImmutable($endRaw);
                }
            } catch (Throwable $e) {
                $endAt = null;
            }
            $earlyOut = isset($row['early_out_enabled_at'])
                ? (string) $row['early_out_enabled_at']
                : null;
            // Early Out → finish at early_out+1h; else end_at+1h.
            if (attendance_event_is_past_lifecycle($endAt, $earlyOut, $nowUtc)) {
                $finishIds[] = $id;
            }
        }

        if ($finishIds === []) {
            api_cache_write('auto_finish_published_events', ['ok' => true, 'at' => gmdate('c')]);
            return;
        }

        $finishHeaders = array_merge($headers, [
            'Content-Type: application/json',
            'Prefer: return=representation',
        ]);
        $finishPayload = json_encode(['status' => 'finished'], JSON_UNESCAPED_SLASHES);
        if (!is_string($finishPayload)) {
            api_cache_write('auto_finish_published_events', ['ok' => true, 'at' => gmdate('c')]);
            return;
        }

        $finishedRows = [];
        // Patch in chunks to keep URLs reasonable.
        foreach (array_chunk($finishIds, 40) as $chunk) {
            $inList = implode(',', array_map(
                static fn(string $id): string => '"' . str_replace('"', '', $id) . '"',
                $chunk
            ));
            $finishUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/events'
                . '?status=eq.published'
                . '&id=in.(' . $inList . ')'
                . '&select=id,status,title,description,location,start_at,end_at,cover_image_url,event_type,event_for,updated_at';
            $finishRes = supabase_request('PATCH', $finishUrl, $finishHeaders, $finishPayload);
            if (($finishRes['ok'] ?? false) === true) {
                $decoded = json_decode((string) ($finishRes['body'] ?? ''), true);
                if (is_array($decoded)) {
                    foreach ($decoded as $decodedRow) {
                        if (is_array($decodedRow)) {
                            $finishedRows[] = $decodedRow;
                        }
                    }
                }
            }
        }

        if ($finishedRows !== []) {
            if (!function_exists('firestore_catalog_sync_event')) {
                require_once __DIR__ . '/firestore_catalog.php';
            }
            foreach ($finishedRows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $row['status'] = 'finished';
                firestore_catalog_sync_event($row, false);
            }
            if (function_exists('firestore_catalog_bump_signals')) {
                firestore_catalog_bump_signals();
            }
        }
    } catch (Throwable $e) {
        // Best-effort only.
    }

    api_cache_write('auto_finish_published_events', ['ok' => true, 'at' => gmdate('c')]);
}

