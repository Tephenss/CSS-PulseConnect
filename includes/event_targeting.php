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

    if (preg_match('/-([1-4])[A-Z]?$/i', $sectionName, $matches)) {
        return (string) $matches[1];
    }

    return '';
}

function student_matches_event_target(array $row, string $eventFor): bool
{
    $normalizedTarget = strtoupper(trim($eventFor));
    if ($normalizedTarget === '' || $normalizedTarget === 'ALL') {
        return true;
    }

    $studentCourse = normalize_student_course_code($row);
    $studentYear = extract_student_year_level($row);

    if (preg_match('/^(BSIT|BSCS)\s*-\s*([1-4])$/', $normalizedTarget, $matches)) {
        return $studentCourse === $matches[1] && $studentYear === $matches[2];
    }

    if (in_array($normalizedTarget, ['BSIT', 'BSCS'], true)) {
        return $studentCourse === $normalizedTarget;
    }

    if (in_array($normalizedTarget, ['1', '2', '3', '4'], true)) {
        return $studentYear === $normalizedTarget;
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
