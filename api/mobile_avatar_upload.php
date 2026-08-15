<?php
declare(strict_types=1);

/**
 * Mobile avatar upload via PHP BFF.
 * Compresses and stores on Hostinger (private dir + HMAC serve) to cut Supabase Storage egress.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/supabase.php';
require_once __DIR__ . '/../includes/json.php';
require_once __DIR__ . '/../includes/mobile_api.php';
require_once __DIR__ . '/../includes/mobile_session.php';
require_once __DIR__ . '/../includes/storage_signed.php';
require_once __DIR__ . '/../includes/media_assets.php';
require_once __DIR__ . '/../includes/api_rate_limit.php';

mobile_api_handle_preflight();
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    json_response(['ok' => false, 'error' => 'Method not allowed'], 405);
}

mobile_api_validate_key($_POST);
$sessionUser = mobile_api_require_user_from_post();
$userId = strtolower(trim((string) ($sessionUser['id'] ?? '')));
if ($userId === '' || !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $userId)) {
    json_response(['ok' => false, 'error' => 'Invalid session user.'], 401);
}

$clientIp = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
if (!api_rate_limit_allow('mobile_avatar_upload:' . $userId . ':' . $clientIp, 12, 60)) {
    json_response(['ok' => false, 'error' => 'Too many uploads. Please wait.'], 429);
}

$fileKey = isset($_FILES['avatar_file']) ? 'avatar_file' : (isset($_FILES['file']) ? 'file' : '');
if ($fileKey === '' || !is_array($_FILES[$fileKey])) {
    json_response(['ok' => false, 'error' => 'Select a photo to upload.'], 400);
}

$upload = $_FILES[$fileKey];
if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    json_response(['ok' => false, 'error' => 'Upload failed. Please try again.'], 400);
}

$tmpName = (string) ($upload['tmp_name'] ?? '');
if ($tmpName === '' || !is_uploaded_file($tmpName)) {
    json_response(['ok' => false, 'error' => 'Invalid upload payload.'], 400);
}

$size = (int) ($upload['size'] ?? 0);
if ($size <= 0 || $size > 5 * 1024 * 1024) {
    json_response(['ok' => false, 'error' => 'Photo must be 5 MB or smaller.'], 400);
}

$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mimeType = $finfo ? (string) finfo_file($finfo, $tmpName) : '';
if ($finfo) {
    finfo_close($finfo);
}

$allowed = ['image/jpeg', 'image/png', 'image/webp'];
if (!in_array($mimeType, $allowed, true)) {
    json_response(['ok' => false, 'error' => 'Only JPG, PNG, or WEBP photos are allowed.'], 400);
}

$fileBytes = file_get_contents($tmpName);
if (!is_string($fileBytes) || $fileBytes === '') {
    json_response(['ok' => false, 'error' => 'Unable to read the uploaded photo.'], 400);
}

$stored = media_store_user_avatar($userId, $fileBytes);
if (!($stored['ok'] ?? false)) {
    json_response(['ok' => false, 'error' => (string) ($stored['error'] ?? 'Failed to upload the photo.')], 500);
}

$objectPath = (string) ($stored['path'] ?? '');
$now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DATE_ATOM);
$patchUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/' . SUPABASE_TABLE_USERS
    . '?id=eq.' . rawurlencode($userId)
    . '&select=' . rawurlencode(mobile_user_public_fields());
$patchRes = supabase_request('PATCH', $patchUrl, [
    'Content-Type: application/json',
    'Accept: application/json',
    'apikey: ' . SUPABASE_KEY,
    'Authorization: Bearer ' . SUPABASE_KEY,
    'Prefer: return=representation',
], json_encode([
    'photo_url' => $objectPath,
    'updated_at' => $now,
], JSON_UNESCAPED_SLASHES));

$user = null;
if ($patchRes['ok'] ?? false) {
    $rows = json_decode((string) ($patchRes['body'] ?? ''), true);
    $user = is_array($rows) && isset($rows[0]) && is_array($rows[0])
        ? mobile_user_strip_secrets($rows[0])
        : null;
}

$signed = media_avatar_signed_url($objectPath, 60 * 60 * 12);

json_response([
    'ok' => true,
    'path' => $objectPath,
    'signed_url' => $signed,
    'user' => $user,
    'stored_bytes' => (int) ($stored['bytes'] ?? 0),
], 200);
