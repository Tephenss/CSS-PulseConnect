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
$sessionUser = mobile_api_require_user($data);

$bucket = trim((string) ($data['bucket'] ?? ''));
$path = trim((string) ($data['path'] ?? ''));
$expires = (int) ($data['expires_in'] ?? 3600);
$findUserAvatar = !empty($data['find_user_avatar']);

$allowedBuckets = ['student-documents', 'proposal-documents', 'avatars'];
if ($bucket === '' && $findUserAvatar) {
    $bucket = 'avatars';
}
if (!in_array($bucket, $allowedBuckets, true)) {
    json_response(['ok' => false, 'error' => 'Bucket not allowed.'], 400);
}

if ($findUserAvatar) {
    if ($bucket !== 'avatars') {
        json_response(['ok' => false, 'error' => 'Avatar lookup is only allowed for avatars.'], 400);
    }
    $userId = trim((string) ($sessionUser['id'] ?? ''));
    $fromProfile = storage_normalize_avatar_photo_value((string) ($sessionUser['photo_url'] ?? ''));
    $path = $fromProfile !== '' ? $fromProfile : storage_find_user_avatar_path($userId);
    if ($path === '') {
        json_response(['ok' => false, 'error' => 'No avatar found.'], 404);
    }
} elseif ($path === '' || str_contains($path, '..')) {
    json_response(['ok' => false, 'error' => 'Invalid path.'], 400);
}

// Hostinger-local avatars (new uploads) — no Supabase Storage egress.
if ($bucket === 'avatars' && media_is_local_avatar_path($path)) {
    require_once __DIR__ . '/../includes/media_assets.php';
    $signed = media_avatar_signed_url($path, $expires);
    if ($signed === '') {
        json_response(['ok' => false, 'error' => 'Avatar file not found.'], 404);
    }
    json_response([
        'ok' => true,
        'signed_url' => $signed,
        'path' => media_normalize_local_avatar_path($path),
    ], 200);
}

$signed = storage_create_signed_url($bucket, $path, $expires);
if ($signed === null) {
    json_response(['ok' => false, 'error' => 'Failed to create signed URL.'], 500);
}

json_response([
    'ok' => true,
    'signed_url' => $signed,
    'path' => $path,
], 200);
