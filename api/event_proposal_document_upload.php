<?php
declare(strict_types=1);

session_start();

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/supabase.php';
require_once __DIR__ . '/../includes/json.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/proposal_requirements.php';

function proposal_document_public_url(string $path): string
{
    $segments = array_map('rawurlencode', array_filter(explode('/', $path), static fn($part): bool => $part !== ''));
    return rtrim(SUPABASE_URL, '/') . '/storage/v1/object/public/proposal-documents/' . implode('/', $segments);
}

function proposal_document_extension(string $mimeType): string
{
    return match ($mimeType) {
        'image/png' => 'png',
        'image/webp' => 'webp',
        'application/pdf' => 'pdf',
        'application/msword' => 'doc',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
        default => 'jpg',
    };
}

$user = require_role(['teacher']);
csrf_validate($_POST['csrf_token'] ?? null);

$eventId = trim((string) ($_POST['event_id'] ?? ''));
$requirementId = trim((string) ($_POST['requirement_id'] ?? ''));
$teacherId = trim((string) ($user['id'] ?? ''));

if ($eventId === '' || $requirementId === '') {
    json_response(['ok' => false, 'error' => 'Missing event or requirement id.'], 400);
}

if (!isset($_FILES['proposal_file']) || !is_array($_FILES['proposal_file'])) {
    json_response(['ok' => false, 'error' => 'Select a file to upload.'], 400);
}

$upload = $_FILES['proposal_file'];
if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    json_response(['ok' => false, 'error' => 'Upload failed. Please try again.'], 400);
}

$tmpName = (string) ($upload['tmp_name'] ?? '');
if ($tmpName === '' || !is_uploaded_file($tmpName)) {
    json_response(['ok' => false, 'error' => 'Invalid upload payload.'], 400);
}

$eventHeaders = proposal_requirement_headers();
$eventUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/events'
    . '?id=eq.' . rawurlencode($eventId)
    . '&select=id,status,created_by'
    . '&limit=1';
$eventRes = supabase_request('GET', $eventUrl, $eventHeaders);
if (!$eventRes['ok']) {
    json_response(['ok' => false, 'error' => build_error($eventRes['body'] ?? null, (int) ($eventRes['status'] ?? 0), $eventRes['error'] ?? null, 'Unable to load the event')], 500);
}

$eventRows = json_decode((string) ($eventRes['body'] ?? ''), true);
$event = is_array($eventRows) && isset($eventRows[0]) && is_array($eventRows[0]) ? $eventRows[0] : null;
if (!is_array($event)) {
    json_response(['ok' => false, 'error' => 'Event not found.'], 404);
}

if (trim((string) ($event['created_by'] ?? '')) !== $teacherId) {
    json_response(['ok' => false, 'error' => 'You can only upload documents for your own proposal.'], 403);
}

$requirements = fetch_proposal_requirements_map([$eventId], $eventHeaders)[$eventId] ?? [];
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

$allowedMimeTypes = [
    'image/jpeg',
    'image/png',
    'image/webp',
    'application/pdf',
    'application/msword',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
];
if (!in_array($mimeType, $allowedMimeTypes, true)) {
    json_response(['ok' => false, 'error' => 'Only PDF, DOC, DOCX, JPG, PNG, or WEBP files are allowed.'], 400);
}

$fileBytes = file_get_contents($tmpName);
if (!is_string($fileBytes) || $fileBytes === '') {
    json_response(['ok' => false, 'error' => 'Unable to read the uploaded file.'], 400);
}

$storedFileName = proposal_document_extension($mimeType);
$objectPath = $eventId . '/' . $teacherId . '/' . $requirementId . '-' . time() . '.' . $storedFileName;
$storageUrl = rtrim(SUPABASE_URL, '/') . '/storage/v1/object/proposal-documents/' . implode('/', array_map('rawurlencode', explode('/', $objectPath)));
$storageHeaders = [
    'Content-Type: ' . $mimeType,
    'x-upsert: true',
    'apikey: ' . SUPABASE_KEY,
    'Authorization: Bearer ' . SUPABASE_KEY,
];
$storageRes = supabase_request('POST', $storageUrl, $storageHeaders, $fileBytes);
if (!$storageRes['ok']) {
    json_response(['ok' => false, 'error' => build_error($storageRes['body'] ?? null, (int) ($storageRes['status'] ?? 0), $storageRes['error'] ?? null, 'Failed to upload the proposal document')], 500);
}

$originalName = trim((string) ($upload['name'] ?? ''));
if ($originalName === '') {
    $originalName = trim((string) ($requirement['label'] ?? 'proposal-document'));
}

$documentPayload = json_encode([
    'event_id' => $eventId,
    'requirement_id' => $requirementId,
    'teacher_id' => $teacherId,
    'file_name' => mb_substr($originalName, 0, 180),
    'file_path' => $objectPath,
    'file_url' => proposal_document_public_url($objectPath),
    'mime_type' => $mimeType,
    'admin_visible' => false,
    'visible_at' => null,
    'uploaded_at' => gmdate('c'),
    'updated_at' => gmdate('c'),
], JSON_UNESCAPED_SLASHES);

if (!is_string($documentPayload)) {
    json_response(['ok' => false, 'error' => 'Unable to prepare the proposal document payload.'], 500);
}

$documentUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/event_proposal_documents?on_conflict=requirement_id,teacher_id';
$documentHeaders = [
    'Content-Type: application/json',
    'Accept: application/json',
    'Prefer: resolution=merge-duplicates,return=representation',
    'apikey: ' . SUPABASE_KEY,
    'Authorization: Bearer ' . SUPABASE_KEY,
];
$documentRes = supabase_request('POST', $documentUrl, $documentHeaders, $documentPayload);
if (!$documentRes['ok']) {
    json_response(['ok' => false, 'error' => build_error($documentRes['body'] ?? null, (int) ($documentRes['status'] ?? 0), $documentRes['error'] ?? null, 'Failed to save the uploaded proposal document')], 500);
}

$rows = json_decode((string) ($documentRes['body'] ?? ''), true);
$document = is_array($rows) && isset($rows[0]) && is_array($rows[0]) ? $rows[0] : null;

json_response([
    'ok' => true,
    'document' => $document,
], 200);
