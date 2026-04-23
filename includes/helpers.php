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
        return ['course' => 'ALL', 'year' => 'ALL'];
    }

    if ($raw === 'NONE') {
        return ['course' => 'ALL', 'year' => 'ALL'];
    }

    $course = 'ALL';
    $year = 'ALL';

    // Supports BSIT-1 / BSCS_2 / BSIT|3 style values
    if (preg_match('/^(BSIT|BSCS)\s*[-_|]\s*([1-4])$/', $raw, $m)) {
        $course = $m[1];
        $year = $m[2];
        return ['course' => $course, 'year' => $year];
    }

    if ($raw === 'BSIT' || $raw === 'BSCS') {
        $course = $raw;
    } elseif (in_array($raw, ['1', '2', '3', '4'], true)) {
        $year = $raw;
    }

    return ['course' => $course, 'year' => $year];
}

function encode_target_participant(string $course, string $year): string
{
    $course = strtoupper(trim($course));
    $year = trim($year);

    if (!in_array($course, ['ALL', 'BSIT', 'BSCS'], true)) {
        $course = 'ALL';
    }
    if (!in_array($year, ['ALL', '1', '2', '3', '4'], true)) {
        $year = 'ALL';
    }

    if ($course === 'ALL' && $year === 'ALL') {
        return 'All';
    }
    if ($course === 'ALL') {
        return $year;
    }
    if ($year === 'ALL') {
        return $course;
    }

    return $course . '-' . $year;
}

function format_target_participant(string $eventFor): string
{
    $decoded = decode_target_participant($eventFor);
    $course = (string) ($decoded['course'] ?? 'ALL');
    $year = (string) ($decoded['year'] ?? 'ALL');

    $courseLabel = match ($course) {
        'BSIT' => 'BSIT',
        'BSCS' => 'BSCS',
        default => 'All Courses',
    };

    $yearLabel = match ($year) {
        '1' => '1st Year',
        '2' => '2nd Year',
        '3' => '3rd Year',
        '4' => '4th Year',
        default => 'All Levels',
    };

    if ($course === 'ALL' && $year === 'ALL') {
        return 'All Courses - All Levels';
    }

    return $courseLabel . ' - ' . $yearLabel;
}



