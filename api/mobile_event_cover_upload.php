<?php
declare(strict_types=1);

/**
 * Teacher/admin event cover upload (mobile session).
 * Stores covers in the public Supabase event-covers bucket, matching the web flow.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/supabase.php';
require_once __DIR__ . '/../includes/json.php';
require_once __DIR__ . '/../includes/mobile_api.php';
require_once __DIR__ . '/../includes/mobile_session.php';
require_once __DIR__ . '/../includes/api_rate_limit.php';

function mobile_event_cover_public_url(string $path): string
{
    $segments = array_map(
        'rawurlencode',
        array_filter(explode('/', $path), static fn($part): bool => $part !== '')
    );
    return rtrim(SUPABASE_URL, '/') . '/storage/v1/object/public/event-covers/' . implode('/', $segments);
}

function mobile_event_cover_extension(string $mimeType): string
{
    return match ($mimeType) {
        'image/png' => 'png',
        'image/webp' => 'webp',
        default => 'jpg',
    };
}

mobile_api_handle_preflight();
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    json_response(['ok' => false, 'error' => 'Method not allowed'], 405);
}

mobile_api_validate_key($_POST);
$sessionUser = mobile_api_require_user_from_post();
$userId = trim((string) ($sessionUser['id'] ?? ''));
$role = strtolower(trim((string) ($sessionUser['role'] ?? '')));
if ($role !== 'teacher' && $role !== 'admin') {
    json_response(['ok' => false, 'error' => 'Only teachers can upload event covers.'], 403);
}

$clientIp = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
if (!api_rate_limit_allow('mobile_event_cover:' . $userId . ':' . $clientIp, 20, 60)) {
    json_response(['ok' => false, 'error' => 'Too many uploads. Please wait.'], 429);
}

$eventId = trim((string) ($_POST['event_id'] ?? ''));
if ($eventId === '') {
    json_response(['ok' => false, 'error' => 'Missing event id.'], 400);
}

$fileKey = isset($_FILES['cover_file']) ? 'cover_file' : (isset($_FILES['file']) ? 'file' : '');
if ($fileKey === '' || !is_array($_FILES[$fileKey])) {
    json_response(['ok' => false, 'error' => 'Select a cover image to upload.'], 400);
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
    json_response(['ok' => false, 'error' => 'Cover image must be 5MB or smaller.'], 400);
}

$headers = mobile_api_supabase_headers();
$eventUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/events'
    . '?id=eq.' . rawurlencode($eventId)
    . '&select=id,created_by,status'
    . '&limit=1';
$eventRes = supabase_request('GET', $eventUrl, $headers);
$eventRows = json_decode((string) ($eventRes['body'] ?? ''), true);
$event = is_array($eventRows) && isset($eventRows[0]) && is_array($eventRows[0]) ? $eventRows[0] : null;
if (!is_array($event)) {
    json_response(['ok' => false, 'error' => 'Event not found.'], 404);
}

$createdBy = trim((string) ($event['created_by'] ?? ''));
if ($role !== 'admin' && $createdBy !== $userId) {
    json_response(['ok' => false, 'error' => 'You can only upload covers for your own events.'], 403);
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

$imageInfo = @getimagesize($tmpName);
$coverWidth = is_array($imageInfo) ? (int) ($imageInfo[0] ?? 0) : 0;
$coverHeight = is_array($imageInfo) ? (int) ($imageInfo[1] ?? 0) : 0;
if ($coverWidth < 1 || $coverHeight < 1) {
    json_response(['ok' => false, 'error' => 'Unable to read cover image dimensions.'], 400);
}

$coverRatio = $coverWidth / $coverHeight;
$targetRatio = 16 / 9;
if (abs($coverRatio - $targetRatio) > 0.08) {
    json_response([
        'ok' => false,
        'error' => sprintf(
            'Cover must be 16:9 landscape (≈1.78:1). Uploaded image is %dx%d (%.2f:1).',
            $coverWidth,
            $coverHeight,
            $coverRatio
        ),
    ], 400);
}

$fileBytes = file_get_contents($tmpName);
if (!is_string($fileBytes) || $fileBytes === '') {
    json_response(['ok' => false, 'error' => 'Unable to read the uploaded image.'], 400);
}

$ext = mobile_event_cover_extension($mimeType);
$objectPath = $eventId . '/' . bin2hex(random_bytes(8)) . '.' . $ext;
$storageUrl = rtrim(SUPABASE_URL, '/') . '/storage/v1/object/event-covers/'
    . implode('/', array_map('rawurlencode', explode('/', $objectPath)));
$storageRes = supabase_request('POST', $storageUrl, [
    'Content-Type: ' . $mimeType,
    'x-upsert: true',
    'apikey: ' . SUPABASE_KEY,
    'Authorization: Bearer ' . SUPABASE_KEY,
], $fileBytes);
if (!($storageRes['ok'] ?? false)) {
    json_response([
        'ok' => false,
        'error' => 'Failed to upload cover image.',
    ], 500);
}

$coverUrl = mobile_event_cover_public_url($objectPath);
$patchUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/events?id=eq.' . rawurlencode($eventId)
    . '&select=id,cover_image_url';
$patchRes = supabase_request(
    'PATCH',
    $patchUrl,
    [
        'Content-Type: application/json',
        'Accept: application/json',
        'Prefer: return=representation',
        'apikey: ' . SUPABASE_KEY,
        'Authorization: Bearer ' . SUPABASE_KEY,
    ],
    json_encode(['cover_image_url' => $coverUrl], JSON_UNESCAPED_SLASHES)
);
if (!($patchRes['ok'] ?? false)) {
    json_response(['ok' => false, 'error' => 'Cover uploaded but failed to save on the event.'], 500);
}

$rows = json_decode((string) ($patchRes['body'] ?? ''), true);
$updated = is_array($rows) && isset($rows[0]) && is_array($rows[0]) ? $rows[0] : null;

// Keep the public event catalog aligned with the event row.
try {
    require_once __DIR__ . '/../includes/firestore_catalog.php';
    $catalogUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/events'
        . '?select=id,title,description,location,start_at,end_at,status,cover_image_url,event_type,event_for,updated_at'
        . '&id=eq.' . rawurlencode($eventId)
        . '&limit=1';
    $catalogRes = supabase_request('GET', $catalogUrl, [
        'Accept: application/json',
        'apikey: ' . SUPABASE_KEY,
        'Authorization: Bearer ' . SUPABASE_KEY,
    ]);
    if ($catalogRes['ok'] ?? false) {
        $catalogRows = json_decode((string) ($catalogRes['body'] ?? ''), true);
        if (is_array($catalogRows) && isset($catalogRows[0]) && is_array($catalogRows[0])) {
            firestore_catalog_sync_event($catalogRows[0]);
        }
    }
} catch (Throwable $e) {
    // Fail-open: the cover is already saved in Supabase and on the event row.
}

json_response([
    'ok' => true,
    'cover_image_url' => $coverUrl,
    'event' => $updated,
], 200);
