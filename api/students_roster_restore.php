<?php
declare(strict_types=1);

/**
 * Admin: restore a soft-archived student roster (+ linked student user).
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
if (!api_rate_limit_allow('students_roster_restore:' . $adminId . ':' . $clientIp, 40, 3600)) {
    json_response(['ok' => false, 'error' => 'Too many restores. Try again later.'], 429);
}

$rosterId = trim((string) ($data['roster_id'] ?? ''));
$userId = trim((string) ($data['user_id'] ?? ''));
$studentNo = student_roster_normalize_no((string) ($data['student_no'] ?? ''));

if ($rosterId === '' && $userId === '' && $studentNo === '') {
    json_response(['ok' => false, 'error' => 'Nothing to restore.'], 400);
}

$headers = student_roster_supabase_headers(true);
$now = gmdate('c');
$restoredRoster = false;
$restoredUser = false;

$roster = null;
if ($rosterId !== '') {
    $getUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/student_roster'
        . '?select=id,student_no,user_id&id=eq.' . rawurlencode($rosterId) . '&limit=1';
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

if ($rosterId !== '') {
    $patchRes = supabase_request(
        'PATCH',
        rtrim(SUPABASE_URL, '/') . '/rest/v1/student_roster?id=eq.' . rawurlencode($rosterId),
        $headers,
        json_encode([
            'archived_at' => null,
            'archived_by' => null,
            'updated_at' => $now,
        ], JSON_UNESCAPED_SLASHES)
    );
    if (!$patchRes['ok']) {
        json_response(['ok' => false, 'error' => 'Failed to restore roster row.'], 500);
    }
    $restoredRoster = true;
}

if ($userId !== '') {
    $patchUserRes = supabase_request(
        'PATCH',
        rtrim(SUPABASE_URL, '/') . '/rest/v1/' . SUPABASE_TABLE_USERS
            . '?id=eq.' . rawurlencode($userId) . '&role=eq.student',
        $headers,
        json_encode(['archived_at' => null], JSON_UNESCAPED_SLASHES)
    );
    if ($patchUserRes['ok']) {
        $restoredUser = true;
    }
}

if (!$restoredRoster && !$restoredUser) {
    json_response(['ok' => false, 'error' => 'Archived student not found.'], 404);
}

json_response([
    'ok' => true,
    'restored_roster' => $restoredRoster,
    'restored_user' => $restoredUser,
]);
