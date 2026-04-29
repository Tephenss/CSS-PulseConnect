<?php
declare(strict_types=1);

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
 * course: ALL | BSIT | BSCS
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

    // New format: COURSE=BSIT;YEARS=1,2
    if (preg_match('/^COURSE\s*=\s*(ALL|BSIT|BSCS)\s*;\s*YEARS\s*=\s*([0-9,\sA-Z]+)$/', $raw, $m)) {
        $course = $m[1];
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

    if ($raw === 'BSIT' || $raw === 'BSCS') {
        $course = $raw;
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

    if (!in_array($course, ['ALL', 'BSIT', 'BSCS'], true)) {
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



