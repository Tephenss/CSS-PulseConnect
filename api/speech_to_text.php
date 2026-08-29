<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/session.php';
session_bootstrap();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/json.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/api_rate_limit.php';

$user = (isset($_SESSION['user']) && is_array($_SESSION['user'])) ? $_SESSION['user'] : null;
if (!is_array($user)) {
    json_response(['ok' => false, 'error' => 'Unauthorized. Please login.'], 401);
}
$role = strtolower(trim((string) ($user['role'] ?? '')));
if (!in_array($role, ['teacher', 'admin', 'super_admin'], true)) {
    json_response(['ok' => false, 'error' => 'Forbidden.'], 403);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    json_response(['ok' => false, 'error' => 'Method not allowed'], 405);
}

$csrfToken = isset($_POST['csrf_token']) ? (string) $_POST['csrf_token'] : null;
csrf_validate($csrfToken);

$userId = trim((string) ($user['id'] ?? ''));
$clientIp = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
if (!api_rate_limit_allow('web_speech:' . $userId . ':' . $clientIp, 10, 600)) {
    json_response(['ok' => false, 'error' => 'Too many speech requests. Please wait.'], 429);
}

if (!isset($_FILES['audio']) || $_FILES['audio']['error'] !== UPLOAD_ERR_OK) {
    json_response(['ok' => false, 'error' => 'Audio file missing or upload error.'], 400);
}

$maxBytes = 10 * 1024 * 1024;
$fileSize = (int) ($_FILES['audio']['size'] ?? 0);
if ($fileSize <= 0 || $fileSize > $maxBytes) {
    json_response(['ok' => false, 'error' => 'Audio must be 10MB or smaller.'], 400);
}

$tmpName = (string) ($_FILES['audio']['tmp_name'] ?? '');
if ($tmpName === '' || !is_uploaded_file($tmpName)) {
    json_response(['ok' => false, 'error' => 'Invalid audio upload.'], 400);
}

$browserMime = strtolower(trim((string) ($_FILES['audio']['type'] ?? '')));
$finfo = finfo_open(FILEINFO_MIME_TYPE);
$detectedMime = $finfo ? strtolower(trim((string) finfo_file($finfo, $tmpName))) : '';
if ($finfo) {
    finfo_close($finfo);
}

$allowedMime = [
    'audio/webm',
    'audio/wav',
    'audio/x-wav',
    'audio/mpeg',
    'audio/mp4',
    'audio/ogg',
    'audio/x-m4a',
    'video/webm', // browsers often label webm audio as video/webm
    'application/octet-stream', // some hosts report webm this way
];
$mime = $detectedMime !== '' ? $detectedMime : $browserMime;
if ($mime === '' || !in_array($mime, $allowedMime, true)) {
    json_response(['ok' => false, 'error' => 'Unsupported audio format.'], 400);
}

// Groq prefers audio/*; normalize browser webm labels.
$groqMime = $mime;
if ($groqMime === 'video/webm' || $groqMime === 'application/octet-stream') {
    $groqMime = 'audio/webm';
}
if ($groqMime === 'audio/x-wav') {
    $groqMime = 'audio/wav';
}
if ($groqMime === 'audio/x-m4a') {
    $groqMime = 'audio/mp4';
}

$extMap = [
    'audio/webm' => 'webm',
    'audio/wav' => 'wav',
    'audio/mpeg' => 'mp3',
    'audio/mp4' => 'm4a',
    'audio/ogg' => 'ogg',
];
$ext = $extMap[$groqMime] ?? 'webm';
$originalName = trim((string) ($_FILES['audio']['name'] ?? ''));
if ($originalName !== '' && preg_match('/\.(webm|wav|mp3|m4a|ogg|mp4)$/i', $originalName, $m)) {
    $ext = strtolower($m[1]);
    if ($ext === 'mp4') {
        $ext = 'm4a';
    }
}

if (!defined('GROQ_API_KEY') || GROQ_API_KEY === '' || GROQ_API_KEY === 'YOUR_GROQ_API_KEY_HERE') {
    error_log('speech_to_text: GROQ_API_KEY missing');
    json_response(['ok' => false, 'error' => 'Speech service is unavailable.'], 500);
}

$cfile = new CURLFile($tmpName, $groqMime, 'audio.' . $ext);

// Taglish event-description prompt (same approach as latest 15, tightened for CCS).
$prompt = 'Ito ay Tagalog at English mix (Taglish) na event description para sa College of Computer Studies PulseConnect. '
    . 'Transcribe exactly what was spoken. Keep Filipino and English words as said. '
    . 'Common terms: seminar, workshop, students, registration, attendance, certificate, venue, schedule.';

$postData = [
    'file' => $cfile,
    'model' => 'whisper-large-v3',
    'response_format' => 'json',
    'temperature' => '0',
    'prompt' => $prompt,
];

$ch = curl_init('https://api.groq.com/openai/v1/audio/transcriptions');
if ($ch === false) {
    json_response(['ok' => false, 'error' => 'Failed to initialize Speech API request.'], 500);
}
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Authorization: Bearer ' . GROQ_API_KEY,
]);

if (defined('SUPABASE_DEV_SKIP_SSL_VERIFY') && SUPABASE_DEV_SKIP_SSL_VERIFY) {
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
} else {
    require_once __DIR__ . '/../includes/curl_ssl.php';
    apply_curl_ssl_policy($ch);
}

$response = curl_exec($ch);
$httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

if ($curlError) {
    error_log('speech_to_text curl: ' . substr($curlError, 0, 300));
    json_response(['ok' => false, 'error' => 'Speech service temporarily unavailable.'], 502);
}

if ($httpCode !== 200) {
    error_log('speech_to_text http=' . $httpCode . ' body=' . substr((string) $response, 0, 300));
    json_response(['ok' => false, 'error' => 'Speech transcription failed.'], 502);
}

$jsonRes = json_decode((string) $response, true);
$transcription = is_array($jsonRes) ? (string) ($jsonRes['text'] ?? '') : '';

json_response([
    'ok' => true,
    'text' => trim($transcription),
], 200);
