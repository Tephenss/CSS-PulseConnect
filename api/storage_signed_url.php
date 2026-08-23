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
require_once __DIR__ . '/../includes/mobile_secure_access.php';
require_once __DIR__ . '/../includes/api_rate_limit.php';

$user = require_role(['teacher', 'admin', 'super_admin']);
$data = require_post_json();
require_csrf_from_json($data);

$role = strtolower(trim((string) ($user['role'] ?? '')));
$uid = trim((string) ($user['id'] ?? ''));
$clientIp = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
if (!api_rate_limit_allow('web_signed_url:' . $uid . ':' . $clientIp, 60, 60)) {
    json_response(['ok' => false, 'error' => 'Too many requests.'], 429);
}

$bucket = trim((string) ($data['bucket'] ?? ''));
$path = trim((string) ($data['path'] ?? ''));
$urlHint = trim((string) ($data['url'] ?? ''));
$expires = (int) ($data['expires_in'] ?? 3600);
$expires = max(60, min(3600, $expires));

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

$headers = [
    'Accept: application/json',
    'apikey: ' . SUPABASE_KEY,
    'Authorization: Bearer ' . SUPABASE_KEY,
];

// Proposal / student docs / avatars: enforce ownership (admins unrestricted).
if (!mobile_secure_storage_path_allowed($bucket, $path, $uid, $role, $headers)) {
    json_response(['ok' => false, 'error' => 'Forbidden.'], 403);
}

$signed = storage_create_signed_url($bucket, $path, $expires);
if ($signed === null) {
    json_response([
        'ok' => false,
        'error' => 'Failed to create signed URL.',
    ], 500);
}

json_response([
    'ok' => true,
    'signed_url' => $signed,
    'bucket' => $bucket,
    'path' => $path,
], 200);
