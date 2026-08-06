<?php
declare(strict_types=1);

/**
 * Parse all workbook sheets into student_roster upsert payloads.
 *
 * @param list<array{name?:string,rows?:list<list<string>>}> $workbookSheets
 * @return array{
 *   payloadsByNo: array<string,array<string,mixed>>,
 *   excelRowByNo: array<string,string>,
 *   sheetsUsed: list<string>,
 *   sheetErrors: list<string>,
 *   rowErrors: list<string>,
 *   skipped: int,
 *   processed: int,
 *   sectionCreated: list<string>,
 *   sectionCache: array<string,string>
 * }
 */
function student_roster_parse_workbook_sheets(array $workbookSheets, string $importedByUserId, string $now): array
{
    $normalizeHeader = static function (string $h): string {
        $h = strtolower(trim($h));
        $h = preg_replace('/^\xef\xbb\xbf/', '', $h) ?? $h;
        $h = preg_replace('/[^a-z0-9]+/', '_', $h) ?? $h;
        return trim($h, '_');
    };

    $aliases = [
        'student_no' => [
            'student_no', 'student_number', 'student_num', 'student_id', 'studentid',
            'school_id', 'id_number', 'id_no', 'idno', 'stud_no', 'stud_number',
            'stud_num', 'sno', 'sn', 'learner_reference_number', 'lrn',
        ],
        'full_name' => [
            'full_name', 'fullname', 'complete_name', 'student_name', 'students_name',
            'learner_name', 'name_of_student', 'name',
        ],
        'last_name' => ['last_name', 'lastname', 'surname', 'family_name', 'l_name', 'ln'],
        'first_name' => ['first_name', 'firstname', 'given_name', 'f_name', 'fn', 'forename'],
        'middle_name' => ['middle_name', 'middlename', 'middle_initial', 'mi', 'm_name', 'mn'],
        'course' => [
            'course', 'program', 'degree', 'course_program', 'program_course',
            'course_code', 'program_code', 'bs_program',
        ],
        'specialization' => [
            'specialization', 'major', 'track', 'spec', 'area_of_specialization', 'concentration',
        ],
        'year' => ['year', 'year_level', 'yr', 'yr_level', 'level', 'yearlevel', 'yl'],
        'block' => ['block', 'section_block', 'section', 'sec', 'class_section', 'block_section'],
    ];

    $mapRowHeaders = static function (array $headerRow) use ($normalizeHeader, $aliases): array {
        $colMap = [];
        foreach ($headerRow as $i => $label) {
            $key = $normalizeHeader((string) $label);
            if ($key === '') {
                continue;
            }
            foreach ($aliases as $canon => $list) {
                if (in_array($key, $list, true) && !isset($colMap[$canon])) {
                    $colMap[$canon] = (int) $i;
                }
            }
            if (!isset($colMap['student_no'])
                && str_contains($key, 'student')
                && (str_contains($key, 'no') || str_contains($key, 'num') || str_contains($key, 'id'))
                && !str_contains($key, 'name')
            ) {
                $colMap['student_no'] = (int) $i;
            }
            if (!isset($colMap['full_name'])
                && (str_contains($key, 'full_name')
                    || str_contains($key, 'complete_name')
                    || ($key !== 'username' && str_ends_with($key, '_name') && !str_contains($key, 'first')
                        && !str_contains($key, 'last') && !str_contains($key, 'middle')
                        && !str_contains($key, 'file') && !str_contains($key, 'school'))
                    || (str_contains($key, 'name') && str_contains($key, 'student') && !str_contains($key, 'first')
                        && !str_contains($key, 'last') && !str_contains($key, 'no')))
            ) {
                $colMap['full_name'] = (int) $i;
            }
        }
        return $colMap;
    };

    $headerHasName = static function (array $colMap): bool {
        return isset($colMap['full_name']) || (isset($colMap['first_name']) && isset($colMap['last_name']));
    };

    $inferProgramFromSheetName = static function (string $sheetName): string {
        $n = strtoupper(trim($sheetName));
        $compact = preg_replace('/[^A-Z0-9]+/', '', $n) ?? $n;
        $spaced = preg_replace('/[^A-Z0-9]+/', ' ', $n) ?? $n;
        $spaced = trim(preg_replace('/\s+/', ' ', $spaced) ?? $spaced);
        // Sheet tabs like BSIT-BA, BSIT-SD, or glued variants (BSITBA / BSITSD).
        if (preg_match('/BSIT.*BA|BA.*BSIT/', $compact) || preg_match('/\bBSIT\b.*\bBA\b/', $spaced)) {
            return 'BSIT BA';
        }
        if (preg_match('/BSIT.*SD|SD.*BSIT/', $compact) || preg_match('/\bBSIT\b.*\bSD\b/', $spaced)) {
            return 'BSIT SD';
        }
        if (str_contains($compact, 'BSCS') || str_contains($spaced, 'COMPUTER SCIENCE')) {
            return 'BSCS';
        }
        return '';
    };

    $payloadsByNo = [];
    $excelRowByNo = [];
    $sheetsUsed = [];
    $sheetErrors = [];
    $rowErrors = [];
    $skipped = 0;
    $processed = 0;
    $maxRows = 5000;
    $sectionCreated = [];
    $sectionCache = [];

    foreach ($workbookSheets as $sheetIndex => $sheet) {
        $sheetName = trim((string) ($sheet['name'] ?? ('Sheet' . ($sheetIndex + 1))));
        $rawRows = $sheet['rows'] ?? [];
        if (!is_array($rawRows) || $rawRows === []) {
            continue;
        }

        $sheetProgramHint = $inferProgramFromSheetName($sheetName);
        $headerRowIndex = null;
        $colMap = [];
        $scanLimit = min(20, count($rawRows));
        for ($i = 0; $i < $scanLimit; $i++) {
            $candidate = $rawRows[$i] ?? null;
            if (!is_array($candidate)) {
                continue;
            }
            $mapped = $mapRowHeaders($candidate);
            if (isset($mapped['student_no']) && $headerHasName($mapped) && (isset($mapped['course']) || $sheetProgramHint !== '')) {
                $headerRowIndex = $i;
                $colMap = $mapped;
                break;
            }
            if ($headerRowIndex === null && isset($mapped['student_no']) && $headerHasName($mapped)) {
                $headerRowIndex = $i;
                $colMap = $mapped;
            }
        }

        if ($headerRowIndex === null) {
            foreach ($rawRows as $i => $candidate) {
                if (!is_array($candidate)) {
                    continue;
                }
                $nonEmpty = array_filter(array_map(static fn ($c) => trim((string) $c), $candidate));
                if ($nonEmpty !== []) {
                    $headerRowIndex = $i;
                    $colMap = $mapRowHeaders($candidate);
                    break;
                }
            }
        }

        if ($headerRowIndex === null) {
            $sheetErrors[] = $sheetName . ': missing header row.';
            continue;
        }

        $headerRow = $rawRows[$headerRowIndex];
        $dataRows = array_slice($rawRows, $headerRowIndex + 1);
        $seenHeaders = [];
        foreach ($headerRow as $label) {
            $t = trim((string) $label);
            if ($t !== '') {
                $seenHeaders[] = $t;
            }
        }
        $seenHint = $seenHeaders !== []
            ? ' Found headers: ' . implode(', ', array_slice($seenHeaders, 0, 12)) . '.'
            : '';

        if (!isset($colMap['student_no'])) {
            $sheetErrors[] = $sheetName . ': missing Student No column.' . $seenHint;
            continue;
        }
        if (!$headerHasName($colMap)) {
            $sheetErrors[] = $sheetName . ': missing Full Name or First/Last Name.' . $seenHint;
            continue;
        }
        if (!isset($colMap['course']) && $sheetProgramHint === '') {
            $sheetErrors[] = $sheetName . ': missing Program/Course column.' . $seenHint;
            continue;
        }

        $sheetsUsed[] = $sheetName;

        foreach ($dataRows as $rowIndex => $row) {
            if (!is_array($row)) {
                continue;
            }
            $allEmpty = true;
            foreach ($row as $cell) {
                if (trim((string) $cell) !== '') {
                    $allEmpty = false;
                    break;
                }
            }
            if ($allEmpty) {
                continue;
            }

            $maybeHeader = $mapRowHeaders($row);
            if (isset($maybeHeader['student_no']) && $headerHasName($maybeHeader)) {
                $labelJoin = strtolower(implode(' ', array_map('strval', $row)));
                if (str_contains($labelJoin, 'student') && (str_contains($labelJoin, 'surname') || str_contains($labelJoin, 'first'))) {
                    continue;
                }
            }

            $processed++;
            if ($processed > $maxRows) {
                $rowErrors[] = 'Stopped after ' . $maxRows . ' data rows (limit).';
                break 2;
            }

            $excelRow = $sheetName . '!' . ($headerRowIndex + $rowIndex + 2);
            $get = static function (string $canon) use ($row, $colMap): string {
                if (!isset($colMap[$canon])) {
                    return '';
                }
                return trim((string) ($row[$colMap[$canon]] ?? ''));
            };

            $studentNo = student_roster_normalize_no($get('student_no'));
            $lastNameCol = $get('last_name');
            $firstNameCol = $get('first_name');
            $middleNameCol = $get('middle_name');
            $fullName = trim($get('full_name'));

            $hasSplitName = ($lastNameCol !== '' && $firstNameCol !== '');
            if ($hasSplitName) {
                $fullName = $lastNameCol . ', ' . $firstNameCol . ($middleNameCol !== '' ? ' ' . $middleNameCol : '');
                $firstName = $firstNameCol;
                $middleName = $middleNameCol !== '' ? $middleNameCol : null;
                $lastName = $lastNameCol;
                $suffix = null;
            } else {
                if ($fullName === '') {
                    $fullName = trim(implode(' ', array_filter([$lastNameCol, $firstNameCol, $middleNameCol])));
                }
                $parsed = student_roster_parse_full_name($fullName);
                $firstName = $parsed['first_name'] !== '' ? $parsed['first_name'] : $fullName;
                $middleName = $parsed['middle_name'] !== '' ? $parsed['middle_name'] : null;
                $lastName = $parsed['last_name'] !== '' ? $parsed['last_name'] : $fullName;
                $suffix = $parsed['suffix'] !== '' ? $parsed['suffix'] : null;
            }

            $courseRaw = $get('course');
            if ($courseRaw === '' && $sheetProgramHint !== '') {
                $courseRaw = $sheetProgramHint;
            }
            $specRaw = $get('specialization');
            $yearRaw = $get('year');
            $blockRaw = $get('block');

            if ($studentNo === '' || ($fullName === '' && $lastName === '' && $firstName === '')) {
                $skipped++;
                $rowErrors[] = $excelRow . ': missing student no or name.';
                continue;
            }

            $program = student_roster_map_program($courseRaw, $specRaw);
            if ($program === null && $sheetProgramHint !== '') {
                $program = student_roster_map_program($sheetProgramHint, '');
            }
            if ($program === null) {
                $skipped++;
                $rowErrors[] = $excelRow . ': unrecognized course/specialization ('
                    . trim($courseRaw . ' ' . $specRaw) . ').';
                continue;
            }

            $irregular = student_roster_is_irregular_block($blockRaw);
            $year = student_roster_parse_year_level($yearRaw, $blockRaw);
            $blockLetter = student_roster_parse_block_letter($blockRaw);
            if (!$irregular && ($year === null || $blockLetter === null)) {
                $irregular = true;
            }
            $blockStored = student_roster_format_block_label(
                $year,
                $blockRaw !== '' ? $blockRaw : $blockLetter,
                $irregular
            );

            $sectionName = student_roster_section_name(
                $program['program_label'],
                $year,
                $irregular ? null : $blockLetter,
                $irregular
            );
            if (!isset($sectionCache[$sectionName])) {
                $sectionCache[$sectionName] = student_roster_ensure_section($sectionName, $sectionCreated);
            }
            $sectionId = $sectionCache[$sectionName];
            if ($sectionId === '') {
                $skipped++;
                $rowErrors[] = $excelRow . ': could not resolve section "' . $sectionName . '".';
                continue;
            }

            $payloadsByNo[$studentNo] = [
                'student_no' => $studentNo,
                'full_name_raw' => $fullName !== '' ? $fullName : trim($lastName . ', ' . $firstName),
                'first_name' => $firstName,
                'middle_name' => $middleName,
                'last_name' => $lastName,
                'suffix' => $suffix,
                'course_code' => $program['course_code'],
                'program_label' => $program['program_label'],
                'year_level' => $year,
                'block' => $blockStored,
                'is_irregular' => $irregular,
                'section_id' => $sectionId,
                'imported_by' => $importedByUserId !== '' ? $importedByUserId : null,
                'imported_at' => $now,
                'updated_at' => $now,
                'archived_at' => null,
                'archived_by' => null,
            ];
            $excelRowByNo[$studentNo] = $excelRow;
        }
    }

    return [
        'payloadsByNo' => $payloadsByNo,
        'excelRowByNo' => $excelRowByNo,
        'sheetsUsed' => $sheetsUsed,
        'sheetErrors' => $sheetErrors,
        'rowErrors' => $rowErrors,
        'skipped' => $skipped,
        'processed' => $processed,
        'sectionCreated' => $sectionCreated,
        'sectionCache' => $sectionCache,
    ];
}
