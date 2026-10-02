<?php
declare(strict_types=1);

require_once __DIR__ . '/student_roster.php';
require_once __DIR__ . '/event_targeting.php';

/**
 * The roster row linked by user_id can be stale or incomplete after imports.
 * Use the student number as a fallback before returning a student's target
 * scope to the mobile client.
 *
 * @param array<string,mixed> $user
 * @return array<string,mixed>
 */
function mobile_student_profile_apply_roster(array $user): array
{
    if (strtolower(trim((string) ($user['role'] ?? ''))) !== 'student') {
        return $user;
    }

    $roster = student_roster_fetch_by_user_id((string) ($user['id'] ?? ''));
    $program = strtoupper(trim((string) ($roster['program_label'] ?? '')));
    $year = (int) ($roster['year_level'] ?? 0);
    $hasProgram = in_array($program, ['BSIT SD', 'BSIT BA', 'BSCS'], true);
    $hasYear = $year >= 1 && $year <= 4;

    if ($roster === null || !$hasProgram || !$hasYear) {
        $studentNo = student_roster_normalize_no((string) ($user['student_id'] ?? ''));
        if ($studentNo !== '') {
            $byStudentNo = student_roster_fetch_by_no($studentNo);
            if ($byStudentNo !== null) {
                $roster = $byStudentNo;
            }
        }
    }

    if ($roster === null) {
        return $user;
    }

    $user = event_targeting_apply_roster($user, $roster);
    $program = strtoupper(trim((string) ($roster['program_label'] ?? '')));
    $courseCode = strtoupper(trim((string) ($roster['course_code'] ?? '')));
    $year = (int) ($roster['year_level'] ?? 0);
    $isIrregular = !empty($roster['is_irregular']) || student_section_is_irregular($user);

    if ($program !== '' && $program !== 'IRREGULAR') {
        $user['course'] = $program;
        $user['program_label'] = $program;
    }
    if ($courseCode !== '' && $courseCode !== 'IRREGULAR') {
        $user['course_code'] = $courseCode;
    }
    if ($year >= 1 && $year <= 4) {
        $user['year_level'] = (string) $year;
    }

    $user['is_irregular'] = $isIrregular;
    $user['section_name'] = student_roster_section_name(
        $program,
        $year >= 1 && $year <= 4 ? $year : null,
        (string) ($roster['block'] ?? ''),
        $isIrregular
    );

    unset($user['password'], $user['password_hash']);
    return $user;
}
