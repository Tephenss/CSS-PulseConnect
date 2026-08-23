<?php
declare(strict_types=1);

/**
 * Cross-event schedule collision ("banggaan") checks.
 *
 * A published event conflicts with a candidate when ALL of these match:
 * - overlapping date/time window
 * - same venue/location
 * - overlapping target participants (course + year)
 *
 * Different audiences at the same time/place (e.g. BSIT vs BSCS) are allowed.
 */

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/event_targeting.php';

function event_conflict_normalize_location(string $location): string
{
    $normalized = strtolower(trim($location));
    $normalized = preg_replace('/\s+/u', ' ', $normalized) ?? $normalized;
    return $normalized;
}

function event_conflict_parse_utc(string $raw): ?DateTimeImmutable
{
    $raw = trim($raw);
    if ($raw === '') {
        return null;
    }
    try {
        return (new DateTimeImmutable($raw))->setTimezone(new DateTimeZone('UTC'));
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * True when [startA, endA) overlaps [startB, endB).
 * Adjacent windows (one ends exactly when the other starts) do not conflict.
 */
function event_conflict_times_overlap(
    DateTimeImmutable $startA,
    DateTimeImmutable $endA,
    DateTimeImmutable $startB,
    DateTimeImmutable $endB
): bool {
    return $startA < $endB && $startB < $endA;
}

function event_conflict_courses_overlap(string $courseA, string $courseB): bool
{
    $a = normalize_event_target_course($courseA);
    $b = normalize_event_target_course($courseB);

    if ($a === '' || $a === 'ALL' || $b === '' || $b === 'ALL') {
        return true;
    }
    if ($a === $b) {
        return true;
    }

    // Generic BSIT covers both tracks; tracks do not collide with each other.
    $aIsBsitFamily = in_array($a, ['BSIT', 'BSIT-SD', 'BSIT-BA'], true);
    $bIsBsitFamily = in_array($b, ['BSIT', 'BSIT-SD', 'BSIT-BA'], true);
    if ($aIsBsitFamily && $bIsBsitFamily) {
        if ($a === 'BSIT' || $b === 'BSIT') {
            return true;
        }
        return false;
    }

    return false;
}

/**
 * @param list<string> $yearsA
 * @param list<string> $yearsB
 */
function event_conflict_years_overlap(array $yearsA, array $yearsB): bool
{
    $normalize = static function (array $years): array {
        $out = [];
        foreach ($years as $year) {
            $value = strtoupper(trim((string) $year));
            if ($value === '' || $value === 'ALL') {
                return ['ALL'];
            }
            if (in_array($value, ['1', '2', '3', '4'], true)) {
                $out[$value] = true;
            }
        }
        return $out === [] ? ['ALL'] : array_keys($out);
    };

    $a = $normalize($yearsA);
    $b = $normalize($yearsB);
    if (in_array('ALL', $a, true) || in_array('ALL', $b, true)) {
        return true;
    }

    return array_intersect($a, $b) !== [];
}

function event_conflict_targets_overlap(string $eventForA, string $eventForB): bool
{
    $decodedA = decode_target_participant($eventForA);
    $decodedB = decode_target_participant($eventForB);

    $courseA = (string) ($decodedA['course'] ?? 'ALL');
    $courseB = (string) ($decodedB['course'] ?? 'ALL');
    $yearsA = isset($decodedA['years']) && is_array($decodedA['years'])
        ? $decodedA['years']
        : ['ALL'];
    $yearsB = isset($decodedB['years']) && is_array($decodedB['years'])
        ? $decodedB['years']
        : ['ALL'];

    return event_conflict_courses_overlap($courseA, $courseB)
        && event_conflict_years_overlap($yearsA, $yearsB);
}

/**
 * @return array{ok:bool,conflict:?array,error?:string}
 * conflict shape: id, title, location, start_at, end_at, event_for
 */
function event_find_published_schedule_conflict(
    string $startAt,
    string $endAt,
    string $location,
    string $eventFor,
    ?string $excludeEventId = null
): array {
    $start = event_conflict_parse_utc($startAt);
    $end = event_conflict_parse_utc($endAt);
    if ($start === null || $end === null) {
        return ['ok' => false, 'conflict' => null, 'error' => 'Invalid event schedule.'];
    }
    if ($end <= $start) {
        return ['ok' => false, 'conflict' => null, 'error' => 'End must be after start.'];
    }

    $normalizedLocation = event_conflict_normalize_location($location);
    if ($normalizedLocation === '') {
        // No venue → cannot prove a room/place collision.
        return ['ok' => true, 'conflict' => null];
    }

    if (!defined('SUPABASE_URL') || !defined('SUPABASE_KEY')) {
        return ['ok' => false, 'conflict' => null, 'error' => 'Server configuration error.'];
    }

    $headers = [
        'Accept: application/json',
        'apikey: ' . SUPABASE_KEY,
        'Authorization: Bearer ' . SUPABASE_KEY,
    ];

    // Fetch published events whose window overlaps the candidate window.
    $url = rtrim(SUPABASE_URL, '/') . '/rest/v1/events'
        . '?select=' . rawurlencode('id,title,location,start_at,end_at,event_for,status')
        . '&status=eq.published'
        . '&start_at=lt.' . rawurlencode($end->format('c'))
        . '&end_at=gt.' . rawurlencode($start->format('c'))
        . '&order=start_at.asc'
        . '&limit=200';

    $res = supabase_request('GET', $url, $headers);
    if (!($res['ok'] ?? false)) {
        return [
            'ok' => false,
            'conflict' => null,
            'error' => 'Unable to validate schedule conflicts right now. Please try again.',
        ];
    }

    $rows = json_decode((string) ($res['body'] ?? ''), true);
    if (!is_array($rows)) {
        return ['ok' => true, 'conflict' => null];
    }

    $exclude = strtolower(trim((string) $excludeEventId));

    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $id = strtolower(trim((string) ($row['id'] ?? '')));
        if ($exclude !== '' && $id === $exclude) {
            continue;
        }

        $otherLocation = event_conflict_normalize_location((string) ($row['location'] ?? ''));
        if ($otherLocation === '' || $otherLocation !== $normalizedLocation) {
            continue;
        }

        $otherStart = event_conflict_parse_utc((string) ($row['start_at'] ?? ''));
        $otherEnd = event_conflict_parse_utc((string) ($row['end_at'] ?? ''));
        if ($otherStart === null || $otherEnd === null) {
            continue;
        }
        if (!event_conflict_times_overlap($start, $end, $otherStart, $otherEnd)) {
            continue;
        }

        $otherFor = (string) ($row['event_for'] ?? 'All');
        if (!event_conflict_targets_overlap($eventFor, $otherFor)) {
            continue;
        }

        return [
            'ok' => true,
            'conflict' => [
                'id' => (string) ($row['id'] ?? ''),
                'title' => (string) ($row['title'] ?? 'Published event'),
                'location' => (string) ($row['location'] ?? ''),
                'start_at' => (string) ($row['start_at'] ?? ''),
                'end_at' => (string) ($row['end_at'] ?? ''),
                'event_for' => $otherFor,
            ],
        ];
    }

    return ['ok' => true, 'conflict' => null];
}

function event_schedule_conflict_message(array $conflict): string
{
    $title = trim((string) ($conflict['title'] ?? 'another published event'));
    if ($title === '') {
        $title = 'another published event';
    }
    $place = trim((string) ($conflict['location'] ?? ''));
    $target = format_target_participant((string) ($conflict['event_for'] ?? 'All'));

    $bits = ['Schedule conflict with published event "' . $title . '"'];
    if ($place !== '') {
        $bits[] = 'same venue (' . $place . ')';
    }
    $bits[] = 'overlapping time';
    $bits[] = 'overlapping target participants (' . $target . ')';

    return implode(' — ', $bits)
        . '. Change the schedule, venue, or target audience (for example BSIT vs BSCS can share the same slot).';
}

/**
 * Hard-fail helper for JSON APIs.
 *
 * @return never
 */
function event_reject_if_published_schedule_conflict(
    string $startAt,
    string $endAt,
    string $location,
    string $eventFor,
    ?string $excludeEventId = null
): void {
    $result = event_find_published_schedule_conflict(
        $startAt,
        $endAt,
        $location,
        $eventFor,
        $excludeEventId
    );

    if (!($result['ok'] ?? false)) {
        json_response([
            'ok' => false,
            'error' => (string) ($result['error'] ?? 'Unable to validate schedule conflicts.'),
        ], 500);
    }

    $conflict = $result['conflict'] ?? null;
    if (is_array($conflict)) {
        json_response([
            'ok' => false,
            'error' => event_schedule_conflict_message($conflict),
            'conflict' => $conflict,
        ], 409);
    }
}
