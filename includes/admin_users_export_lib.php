<?php
declare(strict_types=1);

require_once __DIR__ . '/student_roster.php';

function admin_users_export_headers(): array
{
    return [
        'Accept: application/json',
        'apikey: ' . SUPABASE_KEY,
        'Authorization: Bearer ' . SUPABASE_KEY,
    ];
}

/**
 * @return array<int, array<string, mixed>>
 */
function admin_users_export_fetch_users(): array
{
    $headers = admin_users_export_headers();
    $users = [];
    $offset = 0;
    $hasArchiveCol = true;

    while (true) {
        $select = 'id,first_name,middle_name,last_name,suffix,email,role,section_id,contact_number,created_at,student_id,course'
            . ($hasArchiveCol ? ',archived_at' : '');
        $url = rtrim(SUPABASE_URL, '/') . '/rest/v1/' . SUPABASE_TABLE_USERS
            . '?select=' . $select
            . ($hasArchiveCol ? '&archived_at=is.null' : '')
            . '&order=last_name.asc,first_name.asc'
            . '&limit=1000&offset=' . $offset;
        $res = supabase_request('GET', $url, $headers);
        if (!$res['ok'] && $hasArchiveCol && str_contains((string) ($res['body'] ?? ''), 'archived_at')) {
            $hasArchiveCol = false;
            continue;
        }
        $chunk = $res['ok'] ? json_decode((string) ($res['body'] ?? ''), true) : [];
        if (!is_array($chunk) || $chunk === []) {
            break;
        }
        foreach ($chunk as $row) {
            if (is_array($row)) {
                $users[] = $row;
            }
        }
        if (count($chunk) < 1000) {
            break;
        }
        $offset += 1000;
        if ($offset >= 20000) {
            break;
        }
    }

    return $users;
}

/**
 * @return array<string, string>
 */
function admin_users_export_fetch_section_map(): array
{
    $headers = admin_users_export_headers();
    $url = rtrim(SUPABASE_URL, '/') . '/rest/v1/sections?select=id,name';
    $res = supabase_request('GET', $url, $headers);
    $rows = $res['ok'] ? json_decode((string) ($res['body'] ?? ''), true) : [];
    $map = [];
    if (is_array($rows)) {
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $map[(string) ($row['id'] ?? '')] = (string) ($row['name'] ?? '');
        }
    }
    return $map;
}

/**
 * @return array<int, array<string, mixed>>
 */
function admin_users_export_fetch_roster(): array
{
    $headers = admin_users_export_headers();
    $rosterRows = [];
    $offset = 0;
    $hasArchiveCol = true;

    while (true) {
        $url = rtrim(SUPABASE_URL, '/') . '/rest/v1/student_roster'
            . '?select=id,student_no,full_name_raw,first_name,middle_name,last_name,suffix,'
            . 'course_code,program_label,year_level,block,is_irregular,section_id,user_id,imported_at'
            . ($hasArchiveCol ? ',archived_at&archived_at=is.null' : '')
            . '&order=last_name.asc'
            . '&limit=1000&offset=' . $offset;
        $res = supabase_request('GET', $url, $headers);
        if (!$res['ok'] && $hasArchiveCol && str_contains((string) ($res['body'] ?? ''), 'archived_at')) {
            $hasArchiveCol = false;
            continue;
        }
        $chunk = $res['ok'] ? json_decode((string) ($res['body'] ?? ''), true) : [];
        if (!is_array($chunk) || $chunk === []) {
            break;
        }
        foreach ($chunk as $row) {
            if (is_array($row)) {
                $rosterRows[] = $row;
            }
        }
        if (count($chunk) < 1000) {
            break;
        }
        $offset += 1000;
        if ($offset >= 20000) {
            break;
        }
    }

    return $rosterRows;
}

/**
 * @return array{name:string,year:string,course:string,section:string}
 */
function admin_users_export_format_section_bits(string $secNameRaw, string $courseCode = ''): array
{
    $secNameRaw = trim($secNameRaw);
    $course = strtoupper(trim($courseCode));
    if (!in_array($course, ['IT', 'CS'], true)) {
        $course = '';
    }
    if ($secNameRaw === '' || strcasecmp($secNameRaw, 'No Section') === 0) {
        return ['name' => '', 'year' => '—', 'course' => $course !== '' ? $course : '—', 'section' => '—'];
    }
    if (strcasecmp($secNameRaw, 'IRREGULAR') === 0) {
        return ['name' => $secNameRaw, 'year' => '—', 'course' => $course !== '' ? $course : '—', 'section' => 'IRREGULAR'];
    }
    if (str_contains($secNameRaw, '-')) {
        $parts = explode('-', $secNameRaw, 2);
        return [
            'name' => $secNameRaw,
            'year' => trim($parts[0]),
            'course' => $course !== '' ? $course : '—',
            'section' => trim($parts[1] ?? '—'),
        ];
    }
    if (preg_match('/^(.*?)\s+([1-4])([A-F])$/i', $secNameRaw, $m)) {
        return [
            'name' => $secNameRaw,
            'year' => $m[2],
            'course' => $course !== '' ? $course : trim($m[1]),
            'section' => $secNameRaw,
        ];
    }
    return [
        'name' => $secNameRaw,
        'year' => $secNameRaw,
        'course' => $course !== '' ? $course : '—',
        'section' => $secNameRaw,
    ];
}

/**
 * @param array<int, array<string, mixed>> $users
 * @param array<int, array<string, mixed>> $rosterRows
 * @param array<string, string> $sectionMap
 * @return array<int, array<string, string>>
 */
function admin_users_export_build_student_directory(array $users, array $rosterRows, array $sectionMap): array
{
    $studentUserById = [];
    $studentUserByNo = [];
    $studentUserByDigits = [];
    foreach ($users as $u) {
        if (($u['role'] ?? '') !== 'student') {
            continue;
        }
        $uid = trim((string) ($u['id'] ?? ''));
        if ($uid !== '') {
            $studentUserById[$uid] = $u;
        }
        $sid = student_roster_normalize_no((string) ($u['student_id'] ?? ''));
        if ($sid !== '') {
            $studentUserByNo[$sid] = $u;
        }
        $dig = student_roster_digits_key((string) ($u['student_id'] ?? ''));
        if ($dig !== '') {
            $studentUserByDigits[$dig] = $u;
        }
    }

    $studentDirectory = [];
    $shownUserIds = [];
    $shownStudentNos = [];

    foreach ($rosterRows as $r) {
        if (!is_array($r)) {
            continue;
        }
        $sno = student_roster_normalize_no((string) ($r['student_no'] ?? ''));
        $yearInt = isset($r['year_level']) && $r['year_level'] !== null && $r['year_level'] !== ''
            ? (int) $r['year_level']
            : null;
        $irregular = !empty($r['is_irregular']);
        $program = trim((string) ($r['program_label'] ?? ''));
        if ($program === '') {
            $program = strtoupper((string) ($r['course_code'] ?? '')) === 'CS' ? 'BSCS' : '—';
        }

        $linked = null;
        $uid = trim((string) ($r['user_id'] ?? ''));
        if ($uid !== '' && isset($studentUserById[$uid])) {
            $linked = $studentUserById[$uid];
        } elseif ($sno !== '' && isset($studentUserByNo[$sno])) {
            $linked = $studentUserByNo[$sno];
        } else {
            $dig = student_roster_digits_key($sno);
            if ($dig !== '' && isset($studentUserByDigits[$dig])) {
                $linked = $studentUserByDigits[$dig];
            }
        }

        $isRegistered = is_array($linked);
        if ($isRegistered) {
            $linkedId = trim((string) ($linked['id'] ?? ''));
            if ($linkedId !== '') {
                $shownUserIds[$linkedId] = true;
            }
        }
        if ($sno !== '') {
            $shownStudentNos[$sno] = true;
            $dig = student_roster_digits_key($sno);
            if ($dig !== '') {
                $shownStudentNos[$dig] = true;
            }
        }

        $email = $isRegistered ? trim((string) ($linked['email'] ?? '')) : '';
        $studentDirectory[] = [
            'student_no' => (string) ($r['student_no'] ?? ''),
            'last_name' => trim((string) ($r['last_name'] ?? '')),
            'first_name' => trim((string) ($r['first_name'] ?? '')),
            'middle_name' => trim((string) ($r['middle_name'] ?? '')),
            'program' => $program,
            'year' => student_roster_format_year_label($yearInt),
            'block' => student_roster_format_block_label($yearInt, (string) ($r['block'] ?? ''), $irregular),
            'email' => $email,
            'status' => $isRegistered ? 'Registered' : 'Awaiting signup',
        ];
    }

    foreach ($users as $u) {
        if (($u['role'] ?? '') !== 'student') {
            continue;
        }
        $uid = trim((string) ($u['id'] ?? ''));
        if ($uid !== '' && isset($shownUserIds[$uid])) {
            continue;
        }
        $sno = student_roster_normalize_no((string) ($u['student_id'] ?? ''));
        $dig = student_roster_digits_key($sno);
        if (($sno !== '' && isset($shownStudentNos[$sno])) || ($dig !== '' && isset($shownStudentNos[$dig]))) {
            continue;
        }

        $secId = (string) ($u['section_id'] ?? '');
        $secName = $sectionMap[$secId] ?? '';
        $bits = admin_users_export_format_section_bits($secName, (string) ($u['course'] ?? ''));
        $yearInt = ctype_digit((string) $bits['year']) ? (int) $bits['year'] : student_roster_parse_year_level((string) $bits['year'], (string) $bits['section']);
        $program = '—';
        if (preg_match('/^(BSIT\s*BA|BSIT\s*SD|BSCS)\b/i', $secName, $pm)) {
            $hit = strtoupper(trim((string) ($pm[0] ?? '')));
            if (str_contains($hit, 'BA')) {
                $program = 'BSIT BA';
            } elseif (str_contains($hit, 'SD')) {
                $program = 'BSIT SD';
            } else {
                $program = 'BSCS';
            }
        } elseif (strtoupper((string) ($u['course'] ?? '')) === 'CS') {
            $program = 'BSCS';
        } elseif (strtoupper((string) ($u['course'] ?? '')) === 'IT') {
            $program = 'BSIT';
        }
        $blockLabel = '—';
        if (strcasecmp($secName, 'IRREGULAR') === 0) {
            $blockLabel = '--';
        } elseif (preg_match('/([1-4][A-F])$/i', $secName, $bm)) {
            $blockLabel = strtoupper($bm[1]);
        }

        $studentDirectory[] = [
            'student_no' => $sno,
            'last_name' => trim((string) ($u['last_name'] ?? '')),
            'first_name' => trim((string) ($u['first_name'] ?? '')),
            'middle_name' => trim((string) ($u['middle_name'] ?? '')),
            'program' => $program,
            'year' => student_roster_format_year_label($yearInt),
            'block' => $blockLabel,
            'email' => trim((string) ($u['email'] ?? '')),
            'status' => 'Registered',
        ];
    }

    usort($studentDirectory, static function (array $a, array $b): int {
        $c = strcasecmp($a['last_name'], $b['last_name']);
        if ($c !== 0) {
            return $c;
        }
        $c = strcasecmp($a['first_name'], $b['first_name']);
        if ($c !== 0) {
            return $c;
        }
        return strcasecmp($a['student_no'], $b['student_no']);
    });

    return $studentDirectory;
}

/**
 * @param array<int, array<string, mixed>> $users
 * @param array<string, string> $sectionMap
 * @return array<int, array<string, string>>
 */
function admin_users_export_build_teacher_rows(array $users, array $sectionMap): array
{
    $rows = [];
    foreach ($users as $u) {
        if (($u['role'] ?? '') !== 'teacher') {
            continue;
        }
        $secId = (string) ($u['section_id'] ?? '');
        $secNameRaw = $sectionMap[$secId] ?? '';
        $bits = admin_users_export_format_section_bits($secNameRaw);
        $gradeLvl = $bits['year'] !== '' ? $bits['year'] : '—';
        $parsedBlock = $bits['section'] !== '' ? $bits['section'] : '—';
        if (preg_match('/([1-4][A-F])$/i', $secNameRaw, $bm)) {
            $parsedBlock = strtoupper($bm[1]);
        } elseif (strcasecmp($secNameRaw, 'IRREGULAR') === 0) {
            $parsedBlock = '--';
        }

        $rows[] = [
            'last_name' => trim((string) ($u['last_name'] ?? '')),
            'first_name' => trim((string) ($u['first_name'] ?? '')),
            'middle_name' => trim((string) ($u['middle_name'] ?? '')),
            'email' => trim((string) ($u['email'] ?? '')),
            'contact_number' => trim((string) ($u['contact_number'] ?? '')),
            'year_level' => $gradeLvl,
            'block' => $parsedBlock,
        ];
    }

    usort($rows, static function (array $a, array $b): int {
        $c = strcasecmp($a['last_name'], $b['last_name']);
        if ($c !== 0) {
            return $c;
        }
        return strcasecmp($a['first_name'], $b['first_name']);
    });

    return $rows;
}

function admin_users_export_normalize_program_key(string $program): string
{
    $program = trim($program);
    if ($program === '' || $program === '—') {
        return 'Other';
    }
    $upper = strtoupper($program);
    if (str_starts_with($upper, 'BSIT SD') || $upper === 'BSITSD') {
        return 'BSIT SD';
    }
    if (str_starts_with($upper, 'BSIT BA') || $upper === 'BSITBA') {
        return 'BSIT BA';
    }
    if (str_starts_with($upper, 'BSCS')) {
        return 'BSCS';
    }
    if ($upper === 'BSIT') {
        return 'BSIT';
    }
    return $program;
}

function admin_users_export_program_sheet_name(string $program): string
{
    $key = admin_users_export_normalize_program_key($program);
    $name = match ($key) {
        'BSIT SD' => 'BSIT-SD',
        'BSIT BA' => 'BSIT-BA',
        'BSCS' => 'BSCS',
        'BSIT' => 'BSIT',
        'Other' => 'Other',
        default => str_replace(' ', '-', $key),
    };
    $name = trim(preg_replace('/[\\\\\\/\\?\\*\\[\\]:]+/', '-', $name) ?? $name);
    if ($name === '') {
        $name = 'Sheet';
    }
    if (strlen($name) > 31) {
        $name = substr($name, 0, 31);
    }
    return $name;
}

/**
 * @param array<int, array<string, string>> $directory
 * @return array<string, array<int, array<string, string>>>
 */
function admin_users_export_group_students_by_program(array $directory): array
{
    $groups = [];
    foreach ($directory as $row) {
        $key = admin_users_export_normalize_program_key((string) ($row['program'] ?? ''));
        $groups[$key][] = $row;
    }

    $order = ['BSIT SD', 'BSIT BA', 'BSCS', 'BSIT', 'Other'];
    $ordered = [];
    foreach ($order as $key) {
        if (!empty($groups[$key])) {
            $ordered[$key] = $groups[$key];
            unset($groups[$key]);
        }
    }
    ksort($groups);
    foreach ($groups as $key => $rows) {
        $ordered[$key] = $rows;
    }

    return $ordered;
}

/**
 * @param array<string, string> $row
 * @return list<string>
 */
function admin_users_export_student_row_to_values(array $row): array
{
    return [
        (string) ($row['student_no'] ?? ''),
        (string) ($row['last_name'] ?? ''),
        (string) ($row['first_name'] ?? ''),
        (string) ($row['middle_name'] ?? ''),
        (string) ($row['program'] ?? ''),
        (string) ($row['year'] ?? ''),
        (string) ($row['block'] ?? ''),
        (string) ($row['email'] ?? ''),
        (string) ($row['status'] ?? ''),
    ];
}
