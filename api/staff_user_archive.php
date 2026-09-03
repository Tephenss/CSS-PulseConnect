<?php
declare(strict_types=1);

/**
 * Admin: soft-archive or restore a teacher/admin account (users.archived_at).
 */

require_once __DIR__ . '/../includes/session.php';
session_bootstrap();

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/supabase.php';
require_once __DIR__ . '/../includes/json.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/api_rate_limit.php';

$admin = require_role(['admin']);
$adminId = trim((string) ($admin['id'] ?? ''));
$data = require_post_json();
require_csrf_from_json($data);

$clientIp = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
if (!api_rate_limit_allow('staff_user_archive:' . $adminId . ':' . $clientIp, 40, 3600)) {
    json_response(['ok' => false, 'error' => 'Too many archive actions. Try again later.'], 429);
}

$targetId = trim((string) ($data['user_id'] ?? ''));
$action = strtolower(trim((string) ($data['action'] ?? 'archive')));
if ($targetId === '') {
    json_response(['ok' => false, 'error' => 'user_id required'], 400);
}
if (!in_array($action, ['archive', 'restore', 'hard_delete'], true)) {
    json_response(['ok' => false, 'error' => 'Invalid action.'], 400);
}

$headers = [
    'Accept: application/json',
    'apikey: ' . SUPABASE_KEY,
    'Authorization: Bearer ' . SUPABASE_KEY,
];
$writeHeaders = array_merge($headers, [
    'Content-Type: application/json',
    'Prefer: return=representation',
]);

$getUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/' . SUPABASE_TABLE_USERS
    . '?select=id,email,role,archived_at'
    . '&id=eq.' . rawurlencode($targetId)
    . '&limit=1';
$getRes = supabase_request('GET', $getUrl, $headers);
$rows = $getRes['ok'] ? json_decode((string) ($getRes['body'] ?? ''), true) : [];
$existing = is_array($rows) && isset($rows[0]) && is_array($rows[0]) ? $rows[0] : null;
if ($existing === null) {
    json_response(['ok' => false, 'error' => 'Account not found.'], 404);
}

$role = strtolower(trim((string) ($existing['role'] ?? '')));
if ($role !== 'teacher' && $role !== 'admin') {
    json_response(['ok' => false, 'error' => 'Only teacher or admin accounts can be archived here.'], 403);
}

if ($action === 'hard_delete') {
    if ($targetId === $adminId) {
        json_response(['ok' => false, 'error' => 'You cannot delete your own admin account.'], 400);
    }
    if (empty($existing['archived_at'])) {
        json_response(['ok' => false, 'error' => 'Archive the account first before permanent delete.'], 400);
    }
    $deleteUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/' . SUPABASE_TABLE_USERS
        . '?id=eq.' . rawurlencode($targetId)
        . '&role=in.(teacher,admin)';
    $deleteRes = supabase_request('DELETE', $deleteUrl, $writeHeaders);
    if (!$deleteRes['ok']) {
        json_response([
            'ok' => false,
            'error' => build_error($deleteRes['body'] ?? null, (int) ($deleteRes['status'] ?? 0), $deleteRes['error'] ?? null, 'Permanent delete failed'),
        ], 500);
    }
    json_response(['ok' => true, 'action' => $action, 'message' => 'Account permanently deleted.']);
}

if ($action === 'archive') {
    if ($targetId === $adminId) {
        json_response(['ok' => false, 'error' => 'You cannot archive your own admin account.'], 400);
    }
    if ($role === 'admin') {
        $countUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/' . SUPABASE_TABLE_USERS
            . '?select=id&role=eq.admin&archived_at=is.null';
        $countRes = supabase_request('GET', $countUrl, $headers);
        $admins = $countRes['ok'] ? json_decode((string) ($countRes['body'] ?? ''), true) : [];
        $adminCount = is_array($admins) ? count($admins) : 0;
        if ($adminCount <= 1) {
            json_response(['ok' => false, 'error' => 'Cannot archive the last active admin.'], 400);
        }
    }
}

$payload = [
    'archived_at' => $action === 'archive' ? gmdate('c') : null,
    'updated_at' => gmdate('c'),
];

$patchUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/' . SUPABASE_TABLE_USERS
    . '?id=eq.' . rawurlencode($targetId)
    . '&select=id,email,role,archived_at';
$patchRes = supabase_request('PATCH', $patchUrl, $writeHeaders, json_encode($payload, JSON_UNESCAPED_SLASHES));
if (!$patchRes['ok']) {
    $body = strtolower((string) ($patchRes['body'] ?? ''));
    if (str_contains($body, 'archived_at') && str_contains($body, 'does not exist')) {
        json_response(['ok' => false, 'error' => 'Run supabase/migrations/058_student_roster_archive.sql (users.archived_at).'], 500);
    }
    json_response([
        'ok' => false,
        'error' => build_error($patchRes['body'] ?? null, (int) ($patchRes['status'] ?? 0), $patchRes['error'] ?? null, 'Archive failed'),
    ], 500);
}

json_response([
    'ok' => true,
    'action' => $action,
    'message' => $action === 'archive'
        ? 'Account moved to Archive.'
        : 'Account restored.',
]);
