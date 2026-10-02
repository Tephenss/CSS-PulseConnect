<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/bug_reporting_security.php';
bug_report_session_start();
require_once __DIR__ . '/../includes/json.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/firestore_bug_reports.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    json_response(['ok' => false, 'error' => 'Method not allowed.'], 405);
}
csrf_validate(isset($_POST['csrf_token']) ? (string) $_POST['csrf_token'] : null);

// A quiet honeypot catches basic automated submissions without challenging reporters.
if (trim((string) ($_POST['website'] ?? '')) !== '') {
    json_response(['ok' => true, 'report_id' => null]);
}

$now = time();
$lastSubmit = (int) ($_SESSION['bug_report_last_submit_at'] ?? 0);
if ($lastSubmit > 0 && ($now - $lastSubmit) < 30) {
    json_response(['ok' => false, 'error' => 'Please wait a little before sending another report.'], 429);
}

$message = trim(str_replace(["\r\n", "\r"], "\n", (string) ($_POST['message'] ?? '')));
$message = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $message) ?? '';
$messageLength = mb_strlen($message, 'UTF-8');
if ($messageLength < 10 || $messageLength > 2500) {
    json_response(['ok' => false, 'error' => 'Describe the issue in 10 to 2,500 characters.'], 400);
}

$imagesJson = (string) ($_POST['report_images_json'] ?? '');
if (strlen($imagesJson) > 950 * 1024) {
    json_response(['ok' => false, 'error' => 'The combined screenshots are too large. Remove a few pictures and try again.'], 413);
}
$submittedImages = $imagesJson !== '' ? json_decode($imagesJson, true) : [];
if (!is_array($submittedImages)) {
    json_response(['ok' => false, 'error' => 'The selected pictures could not be read. Please try again.'], 400);
}

// Accept the previous single-file form while older browser tabs are still open.
if ($imagesJson === '' && isset($_FILES['report_image']) && is_array($_FILES['report_image'])) {
    $legacyImage = $_FILES['report_image'];
    $legacyError = (int) ($legacyImage['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($legacyError !== UPLOAD_ERR_NO_FILE) {
        $legacyPath = (string) ($legacyImage['tmp_name'] ?? '');
        $legacySize = (int) ($legacyImage['size'] ?? 0);
        if ($legacyError !== UPLOAD_ERR_OK || $legacyPath === '' || !is_uploaded_file($legacyPath)
            || $legacySize <= 0 || $legacySize > 600 * 1024) {
            json_response(['ok' => false, 'error' => 'The screenshot upload did not finish. Please try again.'], 400);
        }
        $legacyBytes = file_get_contents($legacyPath);
        $legacyFinfo = finfo_open(FILEINFO_MIME_TYPE);
        $legacyMime = $legacyFinfo ? (string) finfo_buffer($legacyFinfo, is_string($legacyBytes) ? $legacyBytes : '') : '';
        if ($legacyFinfo) {
            finfo_close($legacyFinfo);
        }
        if (!is_string($legacyBytes) || $legacyBytes === '') {
            json_response(['ok' => false, 'error' => 'Unable to read the screenshot.'], 400);
        }
        $submittedImages[] = ['mime' => $legacyMime, 'data' => base64_encode($legacyBytes)];
    }
}

$images = [];
$totalImageBytes = 0;
$expectedIndex = 0;
$allowedMimes = ['image/jpeg', 'image/png', 'image/webp'];
foreach ($submittedImages as $index => $submittedImage) {
    if ($index !== $expectedIndex++ || !is_array($submittedImage)) {
        json_response(['ok' => false, 'error' => 'The selected pictures could not be read. Please try again.'], 400);
    }
    $encoded = (string) ($submittedImage['data'] ?? '');
    $mime = strtolower(trim((string) ($submittedImage['mime'] ?? '')));
    if (!in_array($mime, $allowedMimes, true) || $encoded === '' || strlen($encoded) > 700 * 1024) {
        json_response(['ok' => false, 'error' => 'Use valid JPG, PNG, or WEBP pictures.'], 400);
    }
    $bytes = base64_decode($encoded, true);
    if (!is_string($bytes) || $bytes === '') {
        json_response(['ok' => false, 'error' => 'A selected picture could not be decoded. Please try again.'], 400);
    }
    $totalImageBytes += strlen($bytes);
    if ($totalImageBytes > 600 * 1024) {
        json_response(['ok' => false, 'error' => 'The combined screenshots are too large after compression. Remove a few pictures and try again.'], 413);
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $detectedMime = $finfo ? (string) finfo_buffer($finfo, $bytes) : '';
    if ($finfo) {
        finfo_close($finfo);
    }
    $imageInfo = @getimagesizefromstring($bytes);
    $width = is_array($imageInfo) ? (int) ($imageInfo[0] ?? 0) : 0;
    $height = is_array($imageInfo) ? (int) ($imageInfo[1] ?? 0) : 0;
    if (!in_array($detectedMime, $allowedMimes, true) || $detectedMime !== $mime
        || $detectedMime !== (string) ($imageInfo['mime'] ?? '')
        || $width < 1 || $height < 1 || $width > 12000 || $height > 12000 || ($width * $height) > 50000000) {
        json_response(['ok' => false, 'error' => 'Use valid JPG, PNG, or WEBP pictures.'], 400);
    }

    $images[] = [
        'mime' => $detectedMime,
        'data' => base64_encode($bytes),
    ];
}
$hasImage = count($images) > 0;

$createdAt = (new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))->format(DATE_ATOM);
$reportFields = [
    'message' => $message,
    'created_at' => $createdAt,
    'updated_at' => $createdAt,
    'status' => 'new',
    'review_note' => '',
    'public_update' => '',
    'has_image' => $hasImage,
    'images' => $images,
];
$encodedReport = json_encode(['fields' => firestore_encode_fields($reportFields)], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
if (!is_string($encodedReport) || strlen($encodedReport) > 900 * 1024) {
    json_response(['ok' => false, 'error' => 'The combined screenshots are too large for one report. Remove a few pictures and try again.'], 413);
}
$reportId = firestore_bug_report_create($reportFields);
if ($reportId === null) {
    error_log('bug_report_submit: Firestore write failed');
    json_response(['ok' => false, 'error' => 'The report could not be saved right now. Please try again later.'], 503);
}

$_SESSION['bug_report_last_submit_at'] = $now;
json_response([
    'ok' => true,
    'report_id' => $reportId,
    'ticket_code' => firestore_bug_report_ticket_code($reportId),
]);
