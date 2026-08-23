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

$finfo = finfo_open(FILEINFO_MIME_TYPE);
$detectedMime = $finfo ? (string) finfo_file($finfo, $tmpName) : '';
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
    'video/webm', // some browsers record webm audio as video/webm
];
if ($detectedMime === '' || !in_array($detectedMime, $allowedMime, true)) {
    json_response(['ok' => false, 'error' => 'Unsupported audio format.'], 400);
}

if (!defined('GROQ_API_KEY') || GROQ_API_KEY === '' || GROQ_API_KEY === 'YOUR_GROQ_API_KEY_HERE') {
    error_log('speech_to_text: GROQ_API_KEY missing');
    json_response(['ok' => false, 'error' => 'Speech service is unavailable.'], 500);
}

$cfile = new CURLFile($tmpName, $detectedMime, 'audio.webm');

$postData = [
    'file' => $cfile,
    'model' => 'whisper-large-v3',
    'response_format' => 'json',
    'prompt' => 'Hello, ito ay Tagalog at English mix. Magandang araw po.',
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
