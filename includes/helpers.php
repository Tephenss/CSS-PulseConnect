<?php
declare(strict_types=1);

require_once __DIR__ . '/event_targeting.php';

function clean_string(string $v): string
{
    return trim(preg_replace('/\s+/', ' ', $v) ?? '');
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
        $nowUtc = gmdate('c');
        $finishUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/events'
            . '?status=eq.published'
            . '&end_at=lt.' . rawurlencode($nowUtc)
            . '&select=id,status,title,description,location,start_at,end_at,cover_image_url,event_type,event_for,updated_at';
        $finishHeaders = array_merge($headers, [
            'Content-Type: application/json',
            'Prefer: return=representation',
        ]);
        $finishPayload = json_encode(['status' => 'finished'], JSON_UNESCAPED_SLASHES);
        if (!is_string($finishPayload)) {
            api_cache_write('auto_finish_published_events', ['ok' => true, 'at' => gmdate('c')]);
            return;
        }

        $finishRes = supabase_request('PATCH', $finishUrl, $finishHeaders, $finishPayload);
        $finishedRows = [];
        if (($finishRes['ok'] ?? false) === true) {
            $decoded = json_decode((string) ($finishRes['body'] ?? ''), true);
            if (is_array($decoded)) {
                $finishedRows = $decoded;
            }
        }

        // Catalog can lag behind Supabase status — drop finished docs so Events
        // "Published" does not keep showing the same event.
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

