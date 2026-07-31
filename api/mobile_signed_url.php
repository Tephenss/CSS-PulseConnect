<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/supabase.php';
require_once __DIR__ . '/../includes/json.php';
require_once __DIR__ . '/../includes/mobile_api.php';
require_once __DIR__ . '/../includes/mobile_session.php';
require_once __DIR__ . '/../includes/storage_signed.php';

$data = mobile_api_require_post_json();
mobile_api_validate_key($data);
mobile_api_require_user($data);

$bucket = trim((string) ($data['bucket'] ?? ''));
$path = trim((string) ($data['path'] ?? ''));
$expires = (int) ($data['expires_in'] ?? 3600);

$allowedBuckets = ['student-documents', 'proposal-documents', 'avatars'];
if (!in_array($bucket, $allowedBuckets, true)) {
    json_response(['ok' => false, 'error' => 'Bucket not allowed.'], 400);
}
if ($path === '' || str_contains($path, '..')) {
    json_response(['ok' => false, 'error' => 'Invalid path.'], 400);
}

$signed = storage_create_signed_url($bucket, $path, $expires);
if ($signed === null) {
    json_response(['ok' => false, 'error' => 'Failed to create signed URL.'], 500);
}

json_response(['ok' => true, 'signed_url' => $signed], 200);
