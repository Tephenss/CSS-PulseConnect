<?php
declare(strict_types=1);

/**
 * Admin: update a student roster row (and sync linked users row if registered).
 */

require_once __DIR__ . '/../includes/session.php';
session_bootstrap();

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/supabase.php';
require_once __DIR__ . '/../includes/json.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/api_rate_limit.php';
require_once __DIR__ . '/../includes/student_roster.php';

$admin = require_role(['admin']);
$adminId = trim((string) ($admin['id'] ?? ''));
$data = require_post_json();
require_csrf_from_json($data);

$clientIp = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
if (!api_rate_limit_allow('students_roster_update:' . $adminId . ':' . $clientIp, 60, 3600)) {
    json_response(['ok' => false, 'error' => 'Too many updates. Try again later.'], 429);
}

$rosterId = trim((string) ($data['roster_id'] ?? ''));
$userId = trim((string) ($data['user_id'] ?? ''));
$studentNo = student_roster_normalize_no((string) ($data['student_no'] ?? ''));
$lastName = trim((string) ($data['last_name'] ?? $data['surname'] ?? ''));
$firstName = trim((string) ($data['first_name'] ?? ''));
$middleName = trim((string) ($data['middle_name'] ?? ''));
$programRaw = trim((string) ($data['program'] ?? $data['course'] ?? ''));
$yearRaw = trim((string) ($data['year'] ?? ''));
$blockRaw = trim((string) ($data['block'] ?? ''));

if ($studentNo === '' || $lastName === '' || $firstName === '') {
    json_response(['ok' => false, 'error' => 'Student No, Surname, and First Name are required.'], 400);
}

$program = student_roster_map_program($programRaw, '');
if ($program === null) {
    json_response(['ok' => false, 'error' => 'Unrecognized program. Use BSIT BA, BSIT SD, or BSCS.'], 400);
}

$irregular = student_roster_is_irregular_block($blockRaw);
$year = student_roster_parse_year_level($yearRaw, $blockRaw);
$blockLetter = student_roster_parse_block_letter($blockRaw);
if (!$irregular && ($year === null || $blockLetter === null)) {
    $irregular = true;
}
$blockStored = student_roster_format_block_label($year, $blockRaw !== '' ? $blockRaw : $blockLetter, $irregular);

$sectionCreated = [];
$sectionName = student_roster_section_name(
    $program['program_label'],
    $year,
    $irregular ? null : $blockLetter,
    $irregular
);
$sectionId = student_roster_ensure_section($sectionName, $sectionCreated);
if ($sectionId === '') {
    json_response(['ok' => false, 'error' => 'Could not resolve section.'], 500);
}

$fullName = $lastName . ', ' . $firstName . ($middleName !== '' ? ' ' . $middleName : '');
$now = gmdate('c');
$headers = student_roster_supabase_headers(true);

$payload = [
    'student_no' => $studentNo,
    'full_name_raw' => $fullName,
    'first_name' => $firstName,
    'middle_name' => $middleName !== '' ? $middleName : null,
    'last_name' => $lastName,
    'course_code' => $program['course_code'],
    'program_label' => $program['program_label'],
    'year_level' => $year,
    'block' => $blockStored,
    'is_irregular' => $irregular,
    'section_id' => $sectionId,
    'updated_at' => $now,
];

// Resolve existing roster if only user_id given.
$existing = null;
if ($rosterId !== '') {
    $getUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/student_roster?select=*&id=eq.' . rawurlencode($rosterId) . '&limit=1';
    $getRes = supabase_request('GET', $getUrl, student_roster_supabase_headers());
    $rows = $getRes['ok'] ? json_decode((string) ($getRes['body'] ?? ''), true) : [];
    $existing = is_array($rows) && isset($rows[0]) && is_array($rows[0]) ? $rows[0] : null;
} elseif ($studentNo !== '') {
    $existing = student_roster_fetch_by_no($studentNo);
    if ($existing) {
        $rosterId = trim((string) ($existing['id'] ?? ''));
    }
}

// Student no uniqueness when changing.
if ($existing) {
    $oldNo = student_roster_normalize_no((string) ($existing['student_no'] ?? ''));
    if ($oldNo !== $studentNo) {
        $conflict = student_roster_fetch_by_no($studentNo);
        if ($conflict) {
            json_response(['ok' => false, 'error' => 'Another roster row already uses that Student No.'], 409);
        }
    }
    if (empty($payload['user_id']) && !empty($existing['user_id'])) {
        $payload['user_id'] = $existing['user_id'];
    }
    if ($userId === '' && !empty($existing['user_id'])) {
        $userId = trim((string) $existing['user_id']);
    }
}

if ($userId !== '') {
    $payload['user_id'] = $userId;
}

if ($rosterId !== '' && $existing) {
    $patchUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/student_roster?id=eq.' . rawurlencode($rosterId);
    $patchRes = supabase_request('PATCH', $patchUrl, $headers, json_encode($payload, JSON_UNESCAPED_SLASHES));
    if (!$patchRes['ok']) {
        json_response(['ok' => false, 'error' => 'Failed to update roster row.'], 500);
    }
} else {
    $payload['imported_at'] = $now;
    $payload['imported_by'] = $adminId !== '' ? $adminId : null;
    $postUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/student_roster?select=id';
    $postRes = supabase_request('POST', $postUrl, $headers, json_encode([$payload], JSON_UNESCAPED_SLASHES));
    if (!$postRes['ok']) {
        json_response(['ok' => false, 'error' => 'Failed to save roster row.'], 500);
    }
    $created = json_decode((string) ($postRes['body'] ?? ''), true);
    $rosterId = is_array($created) && isset($created[0]['id']) ? trim((string) $created[0]['id']) : $rosterId;
}

// Sync linked app user (do not touch password/email).
if ($userId !== '') {
    $userCheckUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/' . SUPABASE_TABLE_USERS
        . '?select=id,role&id=eq.' . rawurlencode($userId) . '&limit=1';
    $userCheck = supabase_request('GET', $userCheckUrl, student_roster_supabase_headers());
    $userRows = $userCheck['ok'] ? json_decode((string) ($userCheck['body'] ?? ''), true) : [];
    $userRow = is_array($userRows) && isset($userRows[0]) && is_array($userRows[0]) ? $userRows[0] : null;
    if ($userRow && (string) ($userRow['role'] ?? '') === 'student') {
        $userPatch = [
            'first_name' => $firstName,
            'middle_name' => $middleName !== '' ? $middleName : null,
            'last_name' => $lastName,
            'student_id' => $studentNo,
            'course' => $program['course_code'],
            'section_id' => $sectionId,
            'updated_at' => $now,
        ];
        supabase_request(
            'PATCH',
            rtrim(SUPABASE_URL, '/') . '/rest/v1/' . SUPABASE_TABLE_USERS . '?id=eq.' . rawurlencode($userId),
            $headers,
            json_encode($userPatch, JSON_UNESCAPED_SLASHES)
        );
    }
}

json_response([
    'ok' => true,
    'roster_id' => $rosterId,
    'user_id' => $userId !== '' ? $userId : null,
    'section_created' => $sectionCreated,
]);
