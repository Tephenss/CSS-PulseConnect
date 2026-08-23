<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/session.php';
session_bootstrap();

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/supabase.php';
require_once __DIR__ . '/../includes/json.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/student_requirements.php';
require_once __DIR__ . '/../includes/media_assets.php';

$user = require_role(['student']);
csrf_validate($_POST['csrf_token'] ?? null);

$eventId = trim((string) ($_POST['event_id'] ?? ''));
$requirementId = trim((string) ($_POST['requirement_id'] ?? ''));
$studentId = trim((string) ($user['id'] ?? ''));

if ($eventId === '' || $requirementId === '' || $studentId === '') {
    json_response(['ok' => false, 'error' => 'Missing event or requirement id.'], 400);
}

if (!isset($_FILES['student_file']) || !is_array($_FILES['student_file'])) {
    json_response(['ok' => false, 'error' => 'Select a file to upload.'], 400);
}

$upload = $_FILES['student_file'];
if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    json_response(['ok' => false, 'error' => 'Upload failed. Please try again.'], 400);
}

$tmpName = (string) ($upload['tmp_name'] ?? '');
if ($tmpName === '' || !is_uploaded_file($tmpName)) {
    json_response(['ok' => false, 'error' => 'Invalid upload payload.'], 400);
}

$headers = student_requirement_headers();
$eventUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/events'
    . '?id=eq.' . rawurlencode($eventId)
    . '&select=id,status'
    . '&limit=1';
$eventRes = supabase_request('GET', $eventUrl, $headers);
if (!$eventRes['ok']) {
    json_response(['ok' => false, 'error' => build_error($eventRes['body'] ?? null, (int) ($eventRes['status'] ?? 0), $eventRes['error'] ?? null, 'Unable to load the event')], 500);
}

$eventRows = json_decode((string) ($eventRes['body'] ?? ''), true);
$event = is_array($eventRows) && isset($eventRows[0]) && is_array($eventRows[0]) ? $eventRows[0] : null;
if (!is_array($event)) {
    json_response(['ok' => false, 'error' => 'Event not found.'], 404);
}

if (strtolower(trim((string) ($event['status'] ?? ''))) !== 'published') {
    json_response(['ok' => false, 'error' => 'Document uploads are only available for published events.'], 400);
}

$requirements = fetch_student_requirements_map([$eventId], $headers)[$eventId] ?? [];
$requirement = null;
foreach ($requirements as $row) {
    if (trim((string) ($row['id'] ?? '')) === $requirementId) {
        $requirement = $row;
        break;
    }
}
if (!is_array($requirement)) {
    json_response(['ok' => false, 'error' => 'Student requirement not found.'], 404);
}

$submission = fetch_student_submissions_map([$eventId], $headers, $studentId)[$eventId][$studentId] ?? null;
$submissionStatus = is_array($submission) ? strtolower(trim((string) ($submission['status'] ?? ''))) : '';
if ($submissionStatus === 'pending_review') {
    json_response(['ok' => false, 'error' => 'Your documents are under review. Wait for the result before uploading again.'], 400);
}
if ($submissionStatus === 'approved') {
    json_response(['ok' => false, 'error' => 'Your documents are already approved.'], 400);
}

$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mimeType = $finfo ? (string) finfo_file($finfo, $tmpName) : 'application/octet-stream';
if ($finfo) {
    finfo_close($finfo);
}

if (!in_array($mimeType, student_requirement_allowed_mime_types(), true)) {
    json_response(['ok' => false, 'error' => 'Only PDF, DOC, DOCX, JPG, PNG, or WEBP files are allowed.'], 400);
}

$maxBytes = 10 * 1024 * 1024;
$fileSize = (int) ($upload['size'] ?? 0);
if ($fileSize <= 0 || $fileSize > $maxBytes) {
    json_response(['ok' => false, 'error' => 'Each file must be 10MB or smaller.'], 400);
}

$fileBytes = file_get_contents($tmpName);
if (!is_string($fileBytes) || $fileBytes === '') {
    json_response(['ok' => false, 'error' => 'Unable to read the uploaded file.'], 400);
}

$optimized = media_optimize_if_image($fileBytes, $mimeType);
$fileBytes = (string) ($optimized['bytes'] ?? $fileBytes);
$mimeType = (string) ($optimized['mime'] ?? $mimeType);

$storedFileName = student_document_extension($mimeType);
$objectPath = $eventId . '/' . $studentId . '/' . $requirementId . '-' . time() . '.' . $storedFileName;
$storageUrl = rtrim(SUPABASE_URL, '/') . '/storage/v1/object/student-documents/' . implode('/', array_map('rawurlencode', explode('/', $objectPath)));
$storageRes = supabase_request('POST', $storageUrl, [
    'Content-Type: ' . $mimeType,
    'x-upsert: true',
    'apikey: ' . SUPABASE_KEY,
    'Authorization: Bearer ' . SUPABASE_KEY,
], $fileBytes);

if (!$storageRes['ok']) {
    json_response(['ok' => false, 'error' => build_error($storageRes['body'] ?? null, (int) ($storageRes['status'] ?? 0), $storageRes['error'] ?? null, 'Failed to upload the document')], 500);
}

$originalName = trim((string) ($upload['name'] ?? ''));
if ($originalName === '') {
    $originalName = trim((string) ($requirement['label'] ?? 'student-document'));
}

$documentPayload = json_encode([
    'event_id' => $eventId,
    'requirement_id' => $requirementId,
    'student_id' => $studentId,
    'file_name' => mb_substr($originalName, 0, 180),
    'file_path' => $objectPath,
    'file_url' => student_document_public_url($objectPath),
    'mime_type' => $mimeType,
    'uploaded_at' => gmdate('c'),
    'updated_at' => gmdate('c'),
], JSON_UNESCAPED_SLASHES);

$documentUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/event_student_documents?on_conflict=requirement_id,student_id';
$documentRes = supabase_request('POST', $documentUrl, [
    'Content-Type: application/json',
    'Accept: application/json',
    'Prefer: resolution=merge-duplicates,return=representation',
    'apikey: ' . SUPABASE_KEY,
    'Authorization: Bearer ' . SUPABASE_KEY,
], $documentPayload);

if (!$documentRes['ok']) {
    json_response(['ok' => false, 'error' => build_error($documentRes['body'] ?? null, (int) ($documentRes['status'] ?? 0), $documentRes['error'] ?? null, 'Failed to save the uploaded document')], 500);
}

$rows = json_decode((string) ($documentRes['body'] ?? ''), true);
$document = is_array($rows) && isset($rows[0]) && is_array($rows[0]) ? $rows[0] : null;

json_response(['ok' => true, 'document' => $document], 200);
