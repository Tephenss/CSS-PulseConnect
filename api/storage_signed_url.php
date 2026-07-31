<?php
declare(strict_types=1);

/**
 * Web session signed URL for private Storage buckets (proposal/student docs, avatars).
 * Public object URLs fail after security lockdown (bucket public=false).
 */

require_once __DIR__ . '/../includes/session.php';
session_bootstrap();

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/supabase.php';
require_once __DIR__ . '/../includes/json.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/storage_signed.php';

$user = require_role(['teacher', 'admin', 'super_admin']);
$data = require_post_json();
require_csrf_from_json($data);

$bucket = trim((string) ($data['bucket'] ?? ''));
$path = trim((string) ($data['path'] ?? ''));
$urlHint = trim((string) ($data['url'] ?? ''));
$expires = (int) ($data['expires_in'] ?? 3600);

$allowedBuckets = ['proposal-documents', 'student-documents', 'avatars'];
if ($bucket === '' && $urlHint !== '') {
    if (str_contains($urlHint, '/proposal-documents/')) {
        $bucket = 'proposal-documents';
    } elseif (str_contains($urlHint, '/student-documents/')) {
        $bucket = 'student-documents';
    } elseif (str_contains($urlHint, '/avatars/')) {
        $bucket = 'avatars';
    }
}

if ($path === '' && $urlHint !== '') {
    $path = storage_object_path_from_url($urlHint, $bucket !== '' ? $bucket : null);
}

if (!in_array($bucket, $allowedBuckets, true)) {
    json_response(['ok' => false, 'error' => 'Bucket not allowed.'], 400);
}
if ($path === '' || str_contains($path, '..')) {
    json_response(['ok' => false, 'error' => 'Invalid path.'], 400);
}

// Proposal docs: teachers may only open their own upload paths
// ({eventId}/{teacherId}/...). Admins may open any.
// Student docs: any teacher/admin session may preview (document review).
$role = strtolower(trim((string) ($user['role'] ?? '')));
$uid = trim((string) ($user['id'] ?? ''));
$isAdmin = in_array($role, ['admin', 'super_admin'], true);
if (!$isAdmin && $bucket === 'proposal-documents') {
    if ($uid === '' || !str_contains('/' . $path . '/', '/' . $uid . '/')) {
        json_response(['ok' => false, 'error' => 'Forbidden.'], 403);
    }
}

$signed = storage_create_signed_url($bucket, $path, $expires);
if ($signed === null) {
    json_response([
        'ok' => false,
        'error' => 'Failed to create signed URL. Confirm the "' . $bucket . '" bucket exists in Supabase Storage.',
    ], 500);
}

json_response([
    'ok' => true,
    'signed_url' => $signed,
    'bucket' => $bucket,
    'path' => $path,
], 200);
