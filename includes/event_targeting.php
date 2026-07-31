<?php
declare(strict_types=1);

function extract_section_name(mixed $rawSections): string
{
    if (is_array($rawSections)) {
        if (isset($rawSections['name'])) {
            return trim((string) $rawSections['name']);
        }

        if (isset($rawSections[0]) && is_array($rawSections[0])) {
            return trim((string) ($rawSections[0]['name'] ?? ''));
        }
    }

    return '';
}

function normalize_student_course_code(array $row): string
{
    $rawCourse = strtoupper(trim((string) ($row['course'] ?? '')));
    if (in_array($rawCourse, ['IT', 'BSIT'], true)) {
        return 'BSIT';
    }
    if (in_array($rawCourse, ['CS', 'BSCS'], true)) {
        return 'BSCS';
    }

    $sectionName = strtoupper(extract_section_name($row['sections'] ?? null));
    if (str_starts_with($sectionName, 'BSIT')) {
        return 'BSIT';
    }
    if (str_starts_with($sectionName, 'BSCS')) {
        return 'BSCS';
    }

    return '';
}

function extract_student_year_level(array $row): string
{
    $sectionName = trim(extract_section_name($row['sections'] ?? null));
    if ($sectionName === '') {
        return '';
    }

    if (preg_match('/\b([1-4])\b/', $sectionName, $matches)) {
        return (string) $matches[1];
    }

    // Common section formats like "BSIT SD 1B" / "BSCS-2A".
    if (preg_match('/([1-4])[A-Z]\b/i', $sectionName, $matches)) {
        return (string) $matches[1];
    }

    if (preg_match('/-([1-4])[A-Z]?$/i', $sectionName, $matches)) {
        return (string) $matches[1];
    }

    return '';
}

function extract_student_specialization(array $row): string
{
    $rawCourse = strtoupper(trim((string) ($row['course'] ?? '')));
    if ($rawCourse !== '') {
        if (preg_match('/\bBSIT\s*[-_]?\s*SD\b/', $rawCourse)) {
            return 'SD';
        }
        if (preg_match('/\bBSIT\s*[-_]?\s*BA\b/', $rawCourse)) {
            return 'BA';
        }
    }

    $sectionName = strtoupper(extract_section_name($row['sections'] ?? null));
    if ($sectionName === '') {
        $sectionName = strtoupper(trim((string) ($row['section_name'] ?? '')));
    }
    if ($sectionName === '') {
        return '';
    }

    if (preg_match('/\bBSIT\s*[-_]?\s*SD\b/', $sectionName)) {
        return 'SD';
    }
    if (preg_match('/\bBSIT\s*[-_]?\s*BA\b/', $sectionName)) {
        return 'BA';
    }

    return '';
}

/**
 * Extract section block letter (A, B, C...) from section name like "BSIT SD 1A".
 */
function extract_student_block(array $row): string
{
    $sectionName = strtoupper(trim((string) ($row['section_name'] ?? '')));
    if ($sectionName === '') {
        $sectionName = strtoupper(extract_section_name($row['sections'] ?? null));
    }
    if ($sectionName === '') {
        return '';
    }

    if (preg_match('/^(?:BSIT\s*SD|BSIT\s*BA|BSCS|BSIT)\s*[1-4]\s*([A-Z])\b/', $sectionName, $m)) {
        return (string) $m[1];
    }
    if (preg_match('/\b([1-4])([A-Z])\b/', $sectionName, $m)) {
        return (string) $m[2];
    }
    if (preg_match('/-([A-Z])$/i', $sectionName, $m)) {
        return strtoupper((string) $m[1]);
    }

    return '';
}

/**
 * Group label for payment roster: "1st Year · BSIT-SD"
 */
function student_payment_group_key(array $row): array
{
    $year = extract_student_year_level($row);
    $course = normalize_student_course_code($row);
    $spec = extract_student_specialization($row);
    $block = extract_student_block($row);

    $courseLabel = $course;
    if ($course === 'BSIT' && $spec === 'SD') {
        $courseLabel = 'BSIT-SD';
    } elseif ($course === 'BSIT' && $spec === 'BA') {
        $courseLabel = 'BSIT-BA';
    } elseif ($course === '') {
        $courseLabel = 'Other';
    }

    $yearLabels = [
        '1' => '1st Year',
        '2' => '2nd Year',
        '3' => '3rd Year',
        '4' => '4th Year',
    ];
    $yearLabel = $yearLabels[$year] ?? ($year !== '' ? $year . ' Year' : 'Unassigned Year');
    $blockLabel = $block !== '' ? ('Block ' . $block) : 'No Block';

    return [
        'group_key' => $year . '|' . $courseLabel,
        'group_label' => $yearLabel . ' · ' . $courseLabel,
        'year' => $year,
        'course_label' => $courseLabel,
        'block' => $block,
        'block_label' => $blockLabel,
        'sort_year' => $year !== '' ? (int) $year : 99,
        'sort_block' => $block !== '' ? $block : 'ZZ',
    ];
}

function normalize_event_target_course(string $raw): string
{
    $normalized = strtoupper(trim($raw));
    if ($normalized === '') {
        return 'ALL';
    }

    $compact = preg_replace('/[^A-Z0-9]/', '', $normalized) ?? '';
    if ($compact === 'BSITSD') {
        return 'BSIT-SD';
    }
    if ($compact === 'BSITBA') {
        return 'BSIT-BA';
    }
    if (in_array($normalized, ['BSIT-SD', 'BSIT_SD'], true)) {
        return 'BSIT-SD';
    }
    if (in_array($normalized, ['BSIT-BA', 'BSIT_BA'], true)) {
        return 'BSIT-BA';
    }
    if (in_array($normalized, ['BSIT', 'IT'], true) || str_starts_with($compact, 'BSIT')) {
        return 'BSIT';
    }
    if (in_array($normalized, ['BSCS', 'CS'], true) || str_starts_with($compact, 'BSCS')) {
        return 'BSCS';
    }
    if ($normalized === 'ALL') {
        return 'ALL';
    }

    return $normalized;
}

function student_course_matches_target(string $studentCourse, string $studentSpec, string $targetCourse): bool
{
    $target = normalize_event_target_course($targetCourse);
    if ($target === 'ALL') {
        return true;
    }
    if ($target === 'BSIT-SD') {
        return $studentCourse === 'BSIT' && $studentSpec === 'SD';
    }
    if ($target === 'BSIT-BA') {
        return $studentCourse === 'BSIT' && $studentSpec === 'BA';
    }
    if ($target === 'BSIT') {
        return $studentCourse === 'BSIT';
    }
    if ($target === 'BSCS') {
        return $studentCourse === 'BSCS';
    }

    return $studentCourse !== '' && $studentCourse === $target;
}

function student_matches_event_target(array $row, string $eventFor): bool
{
    $normalizedTarget = strtoupper(trim($eventFor));
    // Treat common "everyone" aliases as ALL (encode_target_participant uses "All").
    if (
        $normalizedTarget === ''
        || $normalizedTarget === 'ALL'
        || $normalizedTarget === 'ALL LEVELS'
        || $normalizedTarget === 'NONE'
        || $normalizedTarget === 'ALL STUDENTS'
        || $normalizedTarget === 'ALL COURSES'
        || $normalizedTarget === 'ALL COURSES - ALL LEVELS'
    ) {
        return true;
    }

    $studentCourse = normalize_student_course_code($row);
    $studentYear = extract_student_year_level($row);
    $studentSpec = extract_student_specialization($row);

    if (preg_match('/^(BSIT|BSCS)\s*-\s*([1-4])$/', $normalizedTarget, $matches)) {
        if ($studentCourse !== $matches[1]) {
            return false;
        }
        // Soft-match when section/year cannot be derived from profile.
        if ($studentYear === '') {
            return true;
        }
        return $studentYear === $matches[2];
    }

    $standaloneTarget = normalize_event_target_course($normalizedTarget);
    if (in_array($standaloneTarget, ['BSIT', 'BSIT-SD', 'BSIT-BA', 'BSCS'], true)) {
        return student_course_matches_target($studentCourse, $studentSpec, $standaloneTarget);
    }

    if (in_array($normalizedTarget, ['1', '2', '3', '4'], true)) {
        if ($studentYear === '') {
            return true;
        }
        return $studentYear === $normalizedTarget;
    }

    if (preg_match('/^COURSE\s*=\s*(ALL|BSIT-SD|BSIT-BA|BSIT|BSCS)\s*;\s*YEARS\s*=\s*([0-9,\sA-Z]+)$/', $normalizedTarget, $matches)) {
        $targetCourse = normalize_event_target_course($matches[1]);
        $rawYears = preg_split('/\s*,\s*/', trim($matches[2])) ?: [];

        $targetYears = [];
        foreach ($rawYears as $rawYear) {
            $candidate = strtoupper(trim((string) $rawYear));
            if ($candidate === 'ALL') {
                $targetYears = ['ALL'];
                break;
            }
            if (in_array($candidate, ['1', '2', '3', '4'], true)) {
                $targetYears[$candidate] = true;
            }
        }

        if (empty($targetYears)) {
            $targetYears = ['ALL'];
        } elseif (!array_is_list($targetYears)) {
            $targetYears = array_keys($targetYears);
        }

        $courseMatches = student_course_matches_target($studentCourse, $studentSpec, $targetCourse);
        if (!$courseMatches) {
            return false;
        }

        if (count($targetYears) === 1 && $targetYears[0] === 'ALL') {
            return true;
        }

        // Many student rows lack section/year — still notify on course match.
        if ($studentYear === '') {
            return true;
        }

        return in_array($studentYear, $targetYears, true);
    }

    return false;
}

function compose_student_display_name(array $row): string
{
    $parts = [
        trim((string) ($row['first_name'] ?? '')),
        trim((string) ($row['middle_name'] ?? '')),
        trim((string) ($row['last_name'] ?? '')),
        trim((string) ($row['suffix'] ?? '')),
    ];

    $clean = array_values(array_filter($parts, static fn ($value) => $value !== ''));
    return $clean === [] ? 'Student' : implode(' ', $clean);
}
