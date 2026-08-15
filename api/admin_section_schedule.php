<?php
declare(strict_types=1);

/**
 * Admin Blocks: shared View Sched (regulars) or per-student View Sched (irregular).
 */
require_once __DIR__ . '/../includes/session.php';
session_bootstrap();

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/json.php';
require_once __DIR__ . '/../includes/supabase.php';
require_once __DIR__ . '/../includes/student_roster.php';
require_once __DIR__ . '/../includes/student_class_schedules.php';

$user = require_role(['admin']);
$data = require_post_json();
require_csrf_from_json($data);

$sectionId = trim((string) ($data['section_id'] ?? ''));
$studentId = strtolower(trim((string) ($data['student_id'] ?? '')));
if ($sectionId === '') {
    json_response(['ok' => false, 'error' => 'section_id required'], 400);
}

$headers = [
    'Accept: application/json',
    'apikey: ' . SUPABASE_KEY,
    'Authorization: Bearer ' . SUPABASE_KEY,
];

$secUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/sections'
    . '?select=id,name&id=eq.' . rawurlencode($sectionId) . '&limit=1';
$secRes = supabase_request('GET', $secUrl, $headers);
$secRows = ($secRes['ok'] ?? false) ? json_decode((string) ($secRes['body'] ?? ''), true) : [];
$section = is_array($secRows) && isset($secRows[0]) && is_array($secRows[0]) ? $secRows[0] : null;
if ($section === null) {
    json_response(['ok' => false, 'error' => 'Block not found.'], 404);
}
$sectionName = trim((string) ($section['name'] ?? ''));
$isIrregularBlock = strcasecmp($sectionName, 'IRREGULAR') === 0;

if ($studentId !== '') {
    if (!preg_match('/^[0-9a-f-]{36}$/', $studentId)) {
        json_response(['ok' => false, 'error' => 'Invalid student id.'], 400);
    }
    $userUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/' . SUPABASE_TABLE_USERS
        . '?select=id,first_name,last_name,student_id,section_id'
        . '&id=eq.' . rawurlencode($studentId)
        . '&role=eq.student&limit=1';
    $userRes = supabase_request('GET', $userUrl, $headers);
    $userRows = ($userRes['ok'] ?? false) ? json_decode((string) ($userRes['body'] ?? ''), true) : [];
    $stu = is_array($userRows) && isset($userRows[0]) && is_array($userRows[0]) ? $userRows[0] : null;
    if ($stu === null || trim((string) ($stu['section_id'] ?? '')) !== $sectionId) {
        json_response(['ok' => false, 'error' => 'Student is not in this block.'], 404);
    }
    $byUser = student_class_schedules_fetch_by_user_ids([$studentId], $headers);
    $subjects = student_class_schedules_public_rows($byUser[$studentId] ?? []);
    $name = trim((string) ($stu['last_name'] ?? '') . ', ' . (string) ($stu['first_name'] ?? ''), ' ,');
    json_response([
        'ok' => true,
        'mode' => 'student',
        'section_name' => $sectionName,
        'student_name' => $name,
        'subjects' => $subjects,
    ]);
}

if ($isIrregularBlock) {
    json_response([
        'ok' => false,
        'error' => 'Irregular students have individual schedules. Use View Sched on the student row.',
    ], 400);
}

$uUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/' . SUPABASE_TABLE_USERS
    . '?select=id,first_name,last_name,student_id,archived_at'
    . '&role=eq.student'
    . '&section_id=eq.' . rawurlencode($sectionId)
    . '&limit=2000';
$uRes = supabase_request('GET', $uUrl, $headers);
$uRows = [];
if ($uRes['ok'] ?? false) {
    $decodedUsers = json_decode((string) ($uRes['body'] ?? ''), true);
    $uRows = is_array($decodedUsers) ? $decodedUsers : [];
} elseif (str_contains((string) ($uRes['body'] ?? ''), 'archived_at')) {
    $uUrlFb = rtrim(SUPABASE_URL, '/') . '/rest/v1/' . SUPABASE_TABLE_USERS
        . '?select=id,first_name,last_name,student_id'
        . '&role=eq.student'
        . '&section_id=eq.' . rawurlencode($sectionId)
        . '&limit=2000';
    $uResFb = supabase_request('GET', $uUrlFb, $headers);
    $decodedUsersFb = ($uResFb['ok'] ?? false) ? json_decode((string) ($uResFb['body'] ?? ''), true) : [];
    $uRows = is_array($decodedUsersFb) ? $decodedUsersFb : [];
}

$rosterUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/student_roster'
    . '?select=id,student_no,first_name,last_name,user_id,archived_at'
    . '&section_id=eq.' . rawurlencode($sectionId)
    . '&archived_at=is.null'
    . '&limit=2000';
$rosterRes = supabase_request('GET', $rosterUrl, $headers);
$rosterRows = [];
if ($rosterRes['ok'] ?? false) {
    $decodedRoster = json_decode((string) ($rosterRes['body'] ?? ''), true);
    $rosterRows = is_array($decodedRoster) ? $decodedRoster : [];
} elseif (str_contains((string) ($rosterRes['body'] ?? ''), 'archived_at')) {
    $rosterUrlFb = rtrim(SUPABASE_URL, '/') . '/rest/v1/student_roster'
        . '?select=id,student_no,first_name,last_name,user_id'
        . '&section_id=eq.' . rawurlencode($sectionId)
        . '&limit=2000';
    $rosterResFb = supabase_request('GET', $rosterUrlFb, $headers);
    $decodedRosterFb = ($rosterResFb['ok'] ?? false) ? json_decode((string) ($rosterResFb['body'] ?? ''), true) : [];
    $rosterRows = is_array($decodedRosterFb) ? $decodedRosterFb : [];
}

$userById = [];
$userByNo = [];
$ids = [];
foreach ($uRows as $r) {
    if (!is_array($r) || !empty($r['archived_at'])) {
        continue;
    }
    $id = strtolower(trim((string) ($r['id'] ?? '')));
    $sno = student_roster_normalize_no((string) ($r['student_id'] ?? ''));
    if ($id !== '') {
        $userById[$id] = $r;
        $ids[] = $id;
    }
    if ($sno !== '') {
        $userByNo[$sno] = $r;
    }
}

$students = [];
$seenUserIds = [];
$seenNos = [];
foreach ($rosterRows as $r) {
    if (!is_array($r) || !empty($r['archived_at'])) {
        continue;
    }
    $sno = student_roster_normalize_no((string) ($r['student_no'] ?? ''));
    $linkedUid = strtolower(trim((string) ($r['user_id'] ?? '')));
    $appUser = null;
    if ($linkedUid !== '' && isset($userById[$linkedUid])) {
        $appUser = $userById[$linkedUid];
    } elseif ($sno !== '' && isset($userByNo[$sno])) {
        $appUser = $userByNo[$sno];
    }
    if (is_array($appUser)) {
        $uid = strtolower(trim((string) ($appUser['id'] ?? '')));
        $students[] = [
            'kind' => 'registered',
            'user_id' => $uid,
            'student_no' => $sno !== '' ? $sno : student_roster_normalize_no((string) ($appUser['student_id'] ?? '')),
            'first_name' => (string) ($appUser['first_name'] ?? $r['first_name'] ?? ''),
            'last_name' => (string) ($appUser['last_name'] ?? $r['last_name'] ?? ''),
        ];
        if ($uid !== '') {
            $seenUserIds[$uid] = true;
        }
        if ($sno !== '') {
            $seenNos[$sno] = true;
        }
        continue;
    }
    $students[] = [
        'kind' => 'roster',
        'user_id' => '',
        'student_no' => $sno,
        'first_name' => (string) ($r['first_name'] ?? ''),
        'last_name' => (string) ($r['last_name'] ?? ''),
    ];
    if ($sno !== '') {
        $seenNos[$sno] = true;
    }
}
foreach ($uRows as $r) {
    if (!is_array($r) || !empty($r['archived_at'])) {
        continue;
    }
    $uid = strtolower(trim((string) ($r['id'] ?? '')));
    $sno = student_roster_normalize_no((string) ($r['student_id'] ?? ''));
    if ($uid !== '' && isset($seenUserIds[$uid])) {
        continue;
    }
    if ($sno !== '' && isset($seenNos[$sno])) {
        continue;
    }
    $students[] = [
        'kind' => 'registered',
        'user_id' => $uid,
        'student_no' => $sno,
        'first_name' => (string) ($r['first_name'] ?? ''),
        'last_name' => (string) ($r['last_name'] ?? ''),
    ];
}

$byUser = student_class_schedules_fetch_by_user_ids($ids, $headers);
$monitor = student_class_schedules_block_monitor($students, $byUser);

json_response([
    'ok' => true,
    'mode' => 'block',
    'section_name' => $sectionName,
    'uploaded_count' => (int) ($monitor['uploaded_count'] ?? 0),
    'majority_count' => (int) ($monitor['majority_count'] ?? 0),
    'match_count' => (int) ($monitor['match_count'] ?? 0),
    'mismatch_count' => (int) ($monitor['mismatch_count'] ?? 0),
    'student_count' => (int) ($monitor['student_count'] ?? 0),
    'all_match' => (bool) ($monitor['all_match'] ?? false),
    'subjects' => $monitor['subjects'] ?? [],
    'mismatches' => $monitor['mismatches'] ?? [],
]);
