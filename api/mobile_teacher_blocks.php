<?php
declare(strict_types=1);

/**
 * Teacher/admin class-block directory.
 * Registered student accounts only (users.section_id) — never password, never roster dump.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/supabase.php';
require_once __DIR__ . '/../includes/json.php';
require_once __DIR__ . '/../includes/mobile_api.php';
require_once __DIR__ . '/../includes/mobile_session.php';
require_once __DIR__ . '/../includes/api_rate_limit.php';
require_once __DIR__ . '/../includes/storage_signed.php';

$data = mobile_api_require_post_json();
mobile_api_validate_key($data);
$sessionUser = mobile_api_require_user($data);
$userId = (string) ($sessionUser['id'] ?? '');
$role = strtolower(trim((string) ($sessionUser['role'] ?? 'student')));

if ($role !== 'teacher' && $role !== 'admin') {
    json_response(['ok' => false, 'error' => 'Only teachers can view class blocks.'], 403);
}

$clientIp = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
if (!api_rate_limit_allow('mobile_teacher_blocks:' . $userId . ':' . $clientIp, 60, 60)) {
    json_response(['ok' => false, 'error' => 'Too many requests.'], 429);
}

$headers = mobile_api_supabase_headers();
$sectionId = trim((string) ($data['section_id'] ?? ''));

function mobile_block_display_name(array $row): string
{
    $last = trim((string) ($row['last_name'] ?? ''));
    $first = trim((string) ($row['first_name'] ?? ''));
    $middle = trim((string) ($row['middle_name'] ?? ''));
    $suffix = trim((string) ($row['suffix'] ?? ''));
    $given = trim($first . ($middle !== '' ? ' ' . $middle : ''));
    $name = $last !== '' && $given !== ''
        ? $last . ', ' . $given
        : trim($last . ' ' . $given);
    if ($suffix !== '') {
        $name = trim($name . ' ' . $suffix);
    }
    return $name !== '' ? $name : 'Student';
}

if ($sectionId === '') {
    $sectionsUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/sections'
        . '?select=id,name&status=eq.active&order=name.asc&limit=500';
    $sectionsRes = supabase_request('GET', $sectionsUrl, $headers);
    if (!($sectionsRes['ok'] ?? false)) {
        json_response(['ok' => false, 'error' => 'Failed to load class blocks.'], 500);
    }
    $sectionRows = json_decode((string) ($sectionsRes['body'] ?? ''), true);
    if (!is_array($sectionRows)) {
        $sectionRows = [];
    }

    $counts = [];
    $countUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/users'
        . '?select=section_id'
        . '&role=eq.student'
        . '&archived_at=is.null'
        . '&section_id=not.is.null'
        . '&limit=5000';
    $countRes = supabase_request('GET', $countUrl, $headers);
    if (!($countRes['ok'] ?? false) && str_contains((string) ($countRes['body'] ?? ''), 'archived_at')) {
        $countUrlFb = rtrim(SUPABASE_URL, '/') . '/rest/v1/users'
            . '?select=section_id'
            . '&role=eq.student'
            . '&section_id=not.is.null'
            . '&limit=5000';
        $countRes = supabase_request('GET', $countUrlFb, $headers);
    }
    if ($countRes['ok'] ?? false) {
        $countRows = json_decode((string) ($countRes['body'] ?? ''), true);
        if (is_array($countRows)) {
            foreach ($countRows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $sid = trim((string) ($row['section_id'] ?? ''));
                if ($sid === '') {
                    continue;
                }
                $counts[$sid] = (int) ($counts[$sid] ?? 0) + 1;
            }
        }
    }

    $blocks = [];
    foreach ($sectionRows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $id = trim((string) ($row['id'] ?? ''));
        if ($id === '') {
            continue;
        }
        $blocks[] = [
            'id' => $id,
            'name' => trim((string) ($row['name'] ?? '')),
            'student_count' => (int) ($counts[$id] ?? 0),
        ];
    }

    json_response(['ok' => true, 'blocks' => $blocks], 200);
}

$sectionUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/sections'
    . '?select=id,name,status&id=eq.' . rawurlencode($sectionId) . '&limit=1';
$sectionRes = supabase_request('GET', $sectionUrl, $headers);
$sectionRows = json_decode((string) ($sectionRes['body'] ?? ''), true);
$section = is_array($sectionRows) && isset($sectionRows[0]) && is_array($sectionRows[0])
    ? $sectionRows[0]
    : null;
if (!is_array($section)) {
    json_response(['ok' => false, 'error' => 'Class block not found.'], 404);
}

$select = 'id,first_name,middle_name,last_name,suffix,email,student_id,photo_url,archived_at';
$studentsUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/users'
    . '?select=' . rawurlencode($select)
    . '&role=eq.student'
    . '&section_id=eq.' . rawurlencode($sectionId)
    . '&archived_at=is.null'
    . '&order=last_name.asc'
    . '&limit=1000';
$studentsRes = supabase_request('GET', $studentsUrl, $headers);
if (!($studentsRes['ok'] ?? false) && str_contains((string) ($studentsRes['body'] ?? ''), 'archived_at')) {
    $selectFb = 'id,first_name,middle_name,last_name,suffix,email,student_id,photo_url';
    $studentsUrlFb = rtrim(SUPABASE_URL, '/') . '/rest/v1/users'
        . '?select=' . rawurlencode($selectFb)
        . '&role=eq.student'
        . '&section_id=eq.' . rawurlencode($sectionId)
        . '&order=last_name.asc'
        . '&limit=1000';
    $studentsRes = supabase_request('GET', $studentsUrlFb, $headers);
}
if (!($studentsRes['ok'] ?? false)) {
    json_response(['ok' => false, 'error' => 'Failed to load students in this block.'], 500);
}

$rows = json_decode((string) ($studentsRes['body'] ?? ''), true);
if (!is_array($rows)) {
    $rows = [];
}

$students = [];
foreach ($rows as $row) {
    if (!is_array($row) || !empty($row['archived_at'])) {
        continue;
    }
    $students[] = [
        'id' => (string) ($row['id'] ?? ''),
        'name' => mobile_block_display_name($row),
        'student_id' => trim((string) ($row['student_id'] ?? '')),
        'email' => trim((string) ($row['email'] ?? '')),
        'photo_url' => storage_resolve_user_avatar_url(
            (string) ($row['id'] ?? ''),
            trim((string) ($row['photo_url'] ?? '')),
            14400
        ),
    ];
}

json_response([
    'ok' => true,
    'section_id' => $sectionId,
    'section_name' => (string) ($section['name'] ?? ''),
    'students' => $students,
], 200);
