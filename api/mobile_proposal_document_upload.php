<?php
declare(strict_types=1);

/**
 * Teacher proposal document upload (mobile session).
 * Same behavior as web event_proposal_document_upload.php + Flutter upload UX.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/supabase.php';
require_once __DIR__ . '/../includes/json.php';
require_once __DIR__ . '/../includes/mobile_api.php';
require_once __DIR__ . '/../includes/mobile_session.php';
require_once __DIR__ . '/../includes/proposal_requirements.php';
require_once __DIR__ . '/../includes/api_rate_limit.php';
require_once __DIR__ . '/../includes/media_assets.php';

mobile_api_handle_preflight();
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    json_response(['ok' => false, 'error' => 'Method not allowed'], 405);
}

mobile_api_validate_key($_POST);
$sessionUser = mobile_api_require_user_from_post();
$teacherId = (string) ($sessionUser['id'] ?? '');
$role = strtolower(trim((string) ($sessionUser['role'] ?? '')));
if ($role !== 'teacher' && $role !== 'admin') {
    json_response(['ok' => false, 'error' => 'Only teachers can upload proposal documents.'], 403);
}

$clientIp = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
if (!api_rate_limit_allow('mobile_proposal_upload:' . $teacherId . ':' . $clientIp, 30, 60)) {
    json_response(['ok' => false, 'error' => 'Too many uploads. Please wait.'], 429);
}

$eventId = trim((string) ($_POST['event_id'] ?? ''));
$requirementId = trim((string) ($_POST['requirement_id'] ?? ''));
if ($eventId === '' || $requirementId === '') {
    json_response(['ok' => false, 'error' => 'Missing event or requirement id.'], 400);
}

$fileKey = isset($_FILES['proposal_file']) ? 'proposal_file' : (isset($_FILES['file']) ? 'file' : '');
if ($fileKey === '' || !is_array($_FILES[$fileKey])) {
    json_response(['ok' => false, 'error' => 'Select a file to upload.'], 400);
}
$upload = $_FILES[$fileKey];
if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    json_response(['ok' => false, 'error' => 'Upload failed. Please try again.'], 400);
}
$tmpName = (string) ($upload['tmp_name'] ?? '');
if ($tmpName === '' || !is_uploaded_file($tmpName)) {
    json_response(['ok' => false, 'error' => 'Invalid upload payload.'], 400);
}

$headers = mobile_api_supabase_headers();
$eventUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/events'
    . '?id=eq.' . rawurlencode($eventId)
    . '&select=id,status,created_by'
    . '&limit=1';
$eventRes = supabase_request('GET', $eventUrl, $headers);
$eventRows = json_decode((string) ($eventRes['body'] ?? ''), true);
$event = is_array($eventRows) && isset($eventRows[0]) ? $eventRows[0] : null;
if (!is_array($event)) {
    json_response(['ok' => false, 'error' => 'Event not found.'], 404);
}
if ($role !== 'admin' && trim((string) ($event['created_by'] ?? '')) !== $teacherId) {
    json_response(['ok' => false, 'error' => 'You can only upload documents for your own proposal.'], 403);
}

$requirements = fetch_proposal_requirements_map([$eventId], $headers)[$eventId] ?? [];
$requirement = null;
foreach ($requirements as $row) {
    if (trim((string) ($row['id'] ?? '')) === $requirementId) {
        $requirement = $row;
        break;
    }
}
if (!is_array($requirement)) {
    json_response(['ok' => false, 'error' => 'Proposal requirement not found.'], 404);
}

$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mimeType = $finfo ? (string) finfo_file($finfo, $tmpName) : 'application/octet-stream';
if ($finfo) {
    finfo_close($finfo);
}

$reqCode = trim((string) ($requirement['code'] ?? ''));
$allowedMimeTypes = proposal_document_allowed_mime_types($reqCode);
if (!in_array($mimeType, $allowedMimeTypes, true)) {
    $err = proposal_requirement_is_pdf_only($reqCode)
        ? 'This requirement accepts PDF files only.'
        : 'Only PDF, DOC, DOCX, JPG, PNG, or WEBP files are allowed.';
    json_response(['ok' => false, 'error' => $err], 400);
}

$maxBytes = 10 * 1024 * 1024;
$fileSize = (int) ($upload['size'] ?? 0);
if ($fileSize <= 0 || $fileSize > $maxBytes) {
    json_response(['ok' => false, 'error' => 'Each proposal file must be 10MB or smaller.'], 400);
}

$fileBytes = file_get_contents($tmpName);
if (!is_string($fileBytes) || $fileBytes === '') {
    json_response(['ok' => false, 'error' => 'Unable to read the uploaded file.'], 400);
}

$optimized = media_optimize_if_image($fileBytes, $mimeType);
$fileBytes = (string) ($optimized['bytes'] ?? $fileBytes);
$mimeType = (string) ($optimized['mime'] ?? $mimeType);

$ext = match ($mimeType) {
    'image/png' => 'png',
    'image/webp' => 'webp',
    'application/pdf' => 'pdf',
    'application/msword' => 'doc',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
    default => 'jpg',
};
// Keep Flutter-compatible path shape: teacherId/eventId/requirement-time.ext
$objectPath = $teacherId . '/' . $eventId . '/' . $requirementId . '-' . time() . '.' . $ext;
$storageUrl = rtrim(SUPABASE_URL, '/') . '/storage/v1/object/proposal-documents/'
    . implode('/', array_map('rawurlencode', explode('/', $objectPath)));
$storageRes = supabase_request('POST', $storageUrl, [
    'Content-Type: ' . $mimeType,
    'x-upsert: true',
    'apikey: ' . SUPABASE_KEY,
    'Authorization: Bearer ' . SUPABASE_KEY,
], $fileBytes);
if (!($storageRes['ok'] ?? false)) {
    json_response(['ok' => false, 'error' => 'Failed to upload the proposal document.'], 500);
}

$publicUrl = rtrim(SUPABASE_URL, '/') . '/storage/v1/object/public/proposal-documents/'
    . implode('/', array_map('rawurlencode', explode('/', $objectPath)));

$originalName = trim((string) ($upload['name'] ?? ''));
if ($originalName === '') {
    $originalName = trim((string) ($requirement['label'] ?? 'proposal-document'));
}

$documentPayload = [
    'event_id' => $eventId,
    'requirement_id' => $requirementId,
    'teacher_id' => $teacherId,
    'file_name' => mb_substr($originalName, 0, 180),
    'file_path' => $objectPath,
    'file_url' => $publicUrl,
    'mime_type' => $mimeType,
    'admin_visible' => false,
    'visible_at' => null,
    'uploaded_at' => gmdate('c'),
    'updated_at' => gmdate('c'),
];

$documentUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/event_proposal_documents?on_conflict=requirement_id,teacher_id';
$documentRes = supabase_request('POST', $documentUrl, [
    'Content-Type: application/json',
    'Accept: application/json',
    'Prefer: resolution=merge-duplicates,return=representation',
    'apikey: ' . SUPABASE_KEY,
    'Authorization: Bearer ' . SUPABASE_KEY,
], json_encode($documentPayload, JSON_UNESCAPED_SLASHES));
if (!($documentRes['ok'] ?? false)) {
    json_response(['ok' => false, 'error' => 'Failed to save the uploaded proposal document.'], 500);
}

$rows = json_decode((string) ($documentRes['body'] ?? ''), true);
$document = is_array($rows) && isset($rows[0]) ? $rows[0] : $documentPayload;
json_response(['ok' => true, 'document' => $document], 200);
