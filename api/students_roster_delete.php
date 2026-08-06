<?php
declare(strict_types=1);

/**
 * Admin: soft-archive a student roster entry (and linked student account).
 * Permanent delete is only from Archive via permanent=true.
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
if (!api_rate_limit_allow('students_roster_delete:' . $adminId . ':' . $clientIp, 40, 3600)) {
    json_response(['ok' => false, 'error' => 'Too many deletes. Try again later.'], 429);
}

$rosterId = trim((string) ($data['roster_id'] ?? ''));
$userId = trim((string) ($data['user_id'] ?? ''));
$studentNo = student_roster_normalize_no((string) ($data['student_no'] ?? ''));
$permanent = !empty($data['permanent']);

if ($rosterId === '' && $userId === '' && $studentNo === '') {
    json_response(['ok' => false, 'error' => 'Nothing to delete.'], 400);
}

$headers = student_roster_supabase_headers(true);
$now = gmdate('c');
$archivedRoster = false;
$archivedUser = false;
$deletedRoster = false;
$deletedUser = false;

$roster = null;
if ($rosterId !== '') {
    $getUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/student_roster'
        . '?select=id,student_no,user_id,archived_at&id=eq.' . rawurlencode($rosterId) . '&limit=1';
    $getRes = supabase_request('GET', $getUrl, student_roster_supabase_headers());
    $rows = $getRes['ok'] ? json_decode((string) ($getRes['body'] ?? ''), true) : [];
    $roster = is_array($rows) && isset($rows[0]) && is_array($rows[0]) ? $rows[0] : null;
} elseif ($studentNo !== '') {
    $roster = student_roster_fetch_by_no($studentNo, true);
    if ($roster) {
        $rosterId = trim((string) ($roster['id'] ?? ''));
    }
}

if ($roster && $userId === '' && !empty($roster['user_id'])) {
    $userId = trim((string) $roster['user_id']);
}
if ($roster && $studentNo === '') {
    $studentNo = student_roster_normalize_no((string) ($roster['student_no'] ?? ''));
}

if ($permanent) {
    // Hard delete (Archive → permanent).
    if ($rosterId !== '') {
        $delUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/student_roster?id=eq.' . rawurlencode($rosterId);
        $delRes = supabase_request('DELETE', $delUrl, $headers);
        if (!$delRes['ok']) {
            json_response(['ok' => false, 'error' => 'Failed to permanently delete roster row.'], 500);
        }
        $deletedRoster = true;
    } elseif ($studentNo !== '') {
        $delUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/student_roster?student_no=eq.' . rawurlencode($studentNo);
        $delRes = supabase_request('DELETE', $delUrl, $headers);
        if ($delRes['ok']) {
            $deletedRoster = true;
        }
    }

    if ($userId !== '') {
        $userUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/' . SUPABASE_TABLE_USERS
            . '?select=id,role&id=eq.' . rawurlencode($userId) . '&limit=1';
        $userRes = supabase_request('GET', $userUrl, student_roster_supabase_headers());
        $userRows = $userRes['ok'] ? json_decode((string) ($userRes['body'] ?? ''), true) : [];
        $userRow = is_array($userRows) && isset($userRows[0]) && is_array($userRows[0]) ? $userRows[0] : null;
        if ($userRow && (string) ($userRow['role'] ?? '') !== 'student') {
            json_response(['ok' => false, 'error' => 'Refusing to delete a non-student account.'], 403);
        }
        if ($userRow) {
            $delUserUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/' . SUPABASE_TABLE_USERS
                . '?id=eq.' . rawurlencode($userId) . '&role=eq.student';
            $delUserRes = supabase_request('DELETE', $delUserUrl, $headers);
            if (!$delUserRes['ok']) {
                json_response([
                    'ok' => false,
                    'error' => 'Roster removed, but failed to delete the registered account.',
                    'deleted_roster' => $deletedRoster,
                ], 500);
            }
            $deletedUser = true;
        }
    }

    if (!$deletedRoster && !$deletedUser) {
        json_response(['ok' => false, 'error' => 'Student not found.'], 404);
    }

    json_response([
        'ok' => true,
        'permanent' => true,
        'deleted_roster' => $deletedRoster,
        'deleted_user' => $deletedUser,
    ]);
}

// Soft-archive (Users page trash → Archive).
if ($rosterId === '' && $studentNo === '' && $userId === '') {
    json_response(['ok' => false, 'error' => 'Student not found.'], 404);
}

if ($rosterId !== '') {
    $patchUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/student_roster?id=eq.' . rawurlencode($rosterId);
    $patchRes = supabase_request(
        'PATCH',
        $patchUrl,
        $headers,
        json_encode([
            'archived_at' => $now,
            'archived_by' => $adminId !== '' ? $adminId : null,
            'updated_at' => $now,
        ], JSON_UNESCAPED_SLASHES)
    );
    if (!$patchRes['ok']) {
        $body = (string) ($patchRes['body'] ?? '');
        if (str_contains($body, 'archived_at') || str_contains($body, 'does not exist')) {
            json_response([
                'ok' => false,
                'error' => 'Archive columns missing. Apply migration 058_student_roster_archive.sql in Supabase.',
            ], 500);
        }
        json_response(['ok' => false, 'error' => 'Failed to archive roster row.'], 500);
    }
    $archivedRoster = true;
} elseif ($studentNo !== '') {
    $patchUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/student_roster?student_no=eq.' . rawurlencode($studentNo);
    $patchRes = supabase_request(
        'PATCH',
        $patchUrl,
        $headers,
        json_encode([
            'archived_at' => $now,
            'archived_by' => $adminId !== '' ? $adminId : null,
            'updated_at' => $now,
        ], JSON_UNESCAPED_SLASHES)
    );
    if ($patchRes['ok']) {
        $archivedRoster = true;
    }
}

if ($userId !== '') {
    $userUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/' . SUPABASE_TABLE_USERS
        . '?select=id,role&id=eq.' . rawurlencode($userId) . '&limit=1';
    $userRes = supabase_request('GET', $userUrl, student_roster_supabase_headers());
    $userRows = $userRes['ok'] ? json_decode((string) ($userRes['body'] ?? ''), true) : [];
    $userRow = is_array($userRows) && isset($userRows[0]) && is_array($userRows[0]) ? $userRows[0] : null;
    if ($userRow && (string) ($userRow['role'] ?? '') !== 'student') {
        json_response(['ok' => false, 'error' => 'Refusing to archive a non-student account.'], 403);
    }
    if ($userRow) {
        $patchUserUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/' . SUPABASE_TABLE_USERS
            . '?id=eq.' . rawurlencode($userId) . '&role=eq.student';
        $patchUserRes = supabase_request(
            'PATCH',
            $patchUserUrl,
            $headers,
            json_encode(['archived_at' => $now], JSON_UNESCAPED_SLASHES)
        );
        if (!$patchUserRes['ok']) {
            $body = (string) ($patchUserRes['body'] ?? '');
            if (str_contains($body, 'archived_at') || str_contains($body, 'does not exist')) {
                json_response([
                    'ok' => false,
                    'error' => 'Archive columns missing. Apply migration 058_student_roster_archive.sql in Supabase.',
                    'archived_roster' => $archivedRoster,
                ], 500);
            }
            json_response([
                'ok' => false,
                'error' => 'Roster archived, but failed to archive the registered account.',
                'archived_roster' => $archivedRoster,
            ], 500);
        }
        $archivedUser = true;
    }
}

if (!$archivedRoster && !$archivedUser) {
    json_response(['ok' => false, 'error' => 'Student not found.'], 404);
}

json_response([
    'ok' => true,
    'archived' => true,
    'archived_roster' => $archivedRoster,
    'archived_user' => $archivedUser,
    'message' => 'Student moved to Archive.',
]);
