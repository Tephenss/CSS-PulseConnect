<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/supabase.php';
require_once __DIR__ . '/../includes/json.php';
require_once __DIR__ . '/../includes/mobile_api.php';
require_once __DIR__ . '/../includes/student_requirements.php';

mobile_api_handle_preflight();
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    json_response(['ok' => false, 'error' => 'Method not allowed'], 405);
}

mobile_api_validate_key($_POST);

$eventId = trim((string) ($_POST['event_id'] ?? ''));
$requirementId = trim((string) ($_POST['requirement_id'] ?? ''));
$studentId = trim((string) ($_POST['user_id'] ?? $_POST['student_id'] ?? ''));

if ($eventId === '' || $requirementId === '' || $studentId === '') {
    json_response(['ok' => false, 'error' => 'event_id, requirement_id, and user_id are required.'], 400);
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

$headers = mobile_api_supabase_headers();
$eventUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/events'
    . '?id=eq.' . rawurlencode($eventId)
    . '&select=id,status'
    . '&limit=1';
$eventRes = supabase_request('GET', $eventUrl, $headers);
if (!$eventRes['ok']) {
    json_response(['ok' => false, 'error' => 'Unable to load the event.'], 500);
}

$eventRows = json_decode((string) ($eventRes['body'] ?? ''), true);
$event = is_array($eventRows) && isset($eventRows[0]) ? $eventRows[0] : null;
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
    json_response(['ok' => false, 'error' => 'Your documents are under review.'], 400);
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

$fileBytes = file_get_contents($tmpName);
if (!is_string($fileBytes) || $fileBytes === '') {
    json_response(['ok' => false, 'error' => 'Unable to read the uploaded file.'], 400);
}

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
    json_response(['ok' => false, 'error' => 'Failed to upload the document.'], 500);
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
$documentRes = supabase_request('POST', $documentUrl, array_merge(mobile_api_supabase_headers(), [
    'Content-Type: application/json',
    'Prefer: resolution=merge-duplicates,return=representation',
]), $documentPayload);

if (!$documentRes['ok']) {
    json_response(['ok' => false, 'error' => 'Failed to save the uploaded document.'], 500);
}

$rows = json_decode((string) ($documentRes['body'] ?? ''), true);
$document = is_array($rows) && isset($rows[0]) ? $rows[0] : null;

json_response(['ok' => true, 'document' => $document], 200);
