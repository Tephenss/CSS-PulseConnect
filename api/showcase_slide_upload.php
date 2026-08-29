<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/session.php';
session_bootstrap();

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/supabase.php';
require_once __DIR__ . '/../includes/json.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/image_compression.php';
require_once __DIR__ . '/../includes/showcase_lib.php';

$user = require_role(['admin']);
csrf_validate($_POST['csrf_token'] ?? null);

$label = trim((string) ($_POST['label'] ?? ''));
if ($label === '') {
    json_response(['ok' => false, 'error' => 'Slide label is required.'], 400);
}
if (mb_strlen($label) > 80) {
    json_response(['ok' => false, 'error' => 'Label must be 80 characters or fewer.'], 400);
}

if (showcase_count_active_slides() >= SHOWCASE_MAX_ACTIVE_SLIDES) {
    json_response([
        'ok' => false,
        'error' => 'Maximum of ' . SHOWCASE_MAX_ACTIVE_SLIDES . ' active slides reached. Deactivate or delete one first.',
    ], 400);
}

if (!isset($_FILES['slide_file']) || !is_array($_FILES['slide_file'])) {
    json_response(['ok' => false, 'error' => 'Select an image to upload.'], 400);
}

$upload = $_FILES['slide_file'];
if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    json_response(['ok' => false, 'error' => 'Upload failed. Please try again.'], 400);
}

$tmpName = (string) ($upload['tmp_name'] ?? '');
if ($tmpName === '' || !is_uploaded_file($tmpName)) {
    json_response(['ok' => false, 'error' => 'Invalid upload payload.'], 400);
}

$size = (int) ($upload['size'] ?? 0);
if ($size <= 0 || $size > SHOWCASE_MAX_UPLOAD_BYTES) {
    json_response(['ok' => false, 'error' => 'Image must be 5MB or smaller.'], 400);
}

$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mimeType = $finfo ? (string) finfo_file($finfo, $tmpName) : 'application/octet-stream';
if ($finfo) {
    finfo_close($finfo);
}

$allowed = ['image/jpeg', 'image/png', 'image/webp'];
if (!in_array($mimeType, $allowed, true)) {
    json_response(['ok' => false, 'error' => 'Only JPG, PNG, or WEBP images are allowed.'], 400);
}

$rawBytes = file_get_contents($tmpName);
if (!is_string($rawBytes) || $rawBytes === '') {
    json_response(['ok' => false, 'error' => 'Unable to read the uploaded image.'], 400);
}

$optimized = image_upload_optimize($rawBytes, $mimeType, 1200, 800, 82);
$fileBytes = (string) ($optimized['bytes'] ?? $rawBytes);
$outMime = (string) ($optimized['mime'] ?? $mimeType);
$ext = (string) ($optimized['ext'] ?? showcase_extension($mimeType));

$objectPath = bin2hex(random_bytes(8)) . '.' . $ext;
$storageUrl = rtrim(SUPABASE_URL, '/') . '/storage/v1/object/'
    . SHOWCASE_SLIDES_BUCKET . '/'
    . rawurlencode($objectPath);
$storageRes = supabase_request('POST', $storageUrl, [
    'Content-Type: ' . $outMime,
    'x-upsert: true',
    'apikey: ' . SUPABASE_KEY,
    'Authorization: Bearer ' . SUPABASE_KEY,
], $fileBytes);
if (!$storageRes['ok']) {
    json_response([
        'ok' => false,
        'error' => build_error(
            $storageRes['body'] ?? null,
            (int) ($storageRes['status'] ?? 0),
            $storageRes['error'] ?? null,
            'Failed to upload showcase image'
        ),
    ], 500);
}

$imageUrl = showcase_public_url($objectPath);
$userId = trim((string) ($user['id'] ?? ''));
$nowIso = gmdate('c');

$sortUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/app_showcase_slides'
    . '?select=sort_order&order=sort_order.desc&limit=1';
$sortRes = supabase_request('GET', $sortUrl, showcase_service_headers());
$nextSort = 0;
if ($sortRes['ok']) {
    $sortRows = json_decode((string) ($sortRes['body'] ?? ''), true);
    if (is_array($sortRows) && isset($sortRows[0]['sort_order'])) {
        $nextSort = (int) $sortRows[0]['sort_order'] + 1;
    }
}

$insertUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/app_showcase_slides'
    . '?select=id,label,image_url,storage_path,sort_order,is_active,updated_at';
$insertRes = supabase_request(
    'POST',
    $insertUrl,
    showcase_write_headers(),
    json_encode([
        'label' => $label,
        'image_url' => $imageUrl,
        'storage_path' => $objectPath,
        'sort_order' => $nextSort,
        'is_active' => true,
        'created_by' => $userId !== '' ? $userId : null,
        'updated_at' => $nowIso,
    ], JSON_UNESCAPED_SLASHES)
);
if (!$insertRes['ok']) {
    showcase_delete_storage_object($objectPath);
    json_response([
        'ok' => false,
        'error' => build_error(
            $insertRes['body'] ?? null,
            (int) ($insertRes['status'] ?? 0),
            $insertRes['error'] ?? null,
            'Image uploaded but failed to save slide'
        ),
    ], 500);
}

$rows = json_decode((string) ($insertRes['body'] ?? ''), true);
$slide = is_array($rows) && isset($rows[0]) && is_array($rows[0]) ? $rows[0] : null;

json_response([
    'ok' => true,
    'slide' => $slide,
], 201);
