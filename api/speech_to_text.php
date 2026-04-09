<?php
declare(strict_types=1);

session_start();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/json.php';
require_once __DIR__ . '/../includes/csrf.php';

// Ensure user is logged in (JSON API contract; avoid HTML redirects).
$user = (isset($_SESSION['user']) && is_array($_SESSION['user'])) ? $_SESSION['user'] : null;
if (!is_array($user)) {
    json_response(['ok' => false, 'error' => 'Unauthorized. Please login.'], 401);
}

// Only allow POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok' => false, 'error' => 'Method not allowed'], 405);
}

$csrfToken = isset($_POST['csrf_token']) ? (string) $_POST['csrf_token'] : null;
csrf_validate($csrfToken);

if (!isset($_FILES['audio']) || $_FILES['audio']['error'] !== UPLOAD_ERR_OK) {
    json_response(['ok' => false, 'error' => 'Audio file missing or upload error.'], 400);
}

if (!defined('GROQ_API_KEY') || GROQ_API_KEY === 'YOUR_GROQ_API_KEY_HERE') {
    json_response(['ok' => false, 'error' => 'GROQ_API_KEY is missing or just a placeholder in config.php. Please add your real key.'], 500);
}

// Prepare CURLFile to send audio Blob directly to Groq Whisper
$cfile = new CURLFile($_FILES['audio']['tmp_name'], $_FILES['audio']['type'] ?? 'audio/webm', 'audio.webm');

$postData = [
    'file' => $cfile,
    'model' => 'whisper-large-v3', // Powerful, lightning fast Groq model built for multi-language
    'response_format' => 'json',
    'prompt' => "Hello, ito ay Tagalog at English mix. Magandang araw po." // Anchors model to Taglish to prevent parsing silence as Korean
];

$ch = curl_init('https://api.groq.com/openai/v1/audio/transcriptions');
if ($ch === false) {
    json_response(['ok' => false, 'error' => 'Failed to initialize Speech API request.'], 500);
}
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Authorization: Bearer ' . GROQ_API_KEY
]);

if (defined('SUPABASE_DEV_SKIP_SSL_VERIFY') && SUPABASE_DEV_SKIP_SSL_VERIFY) {
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
}

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

if ($curlError) {
    json_response(['ok' => false, 'error' => 'cURL Error connecting to Groq: ' . $curlError], 502);
}

if ($httpCode !== 200) {
    $errObj = json_decode((string)$response, true);
    $msg = $errObj['error']['message'] ?? $response;
    json_response(['ok' => false, 'error' => 'Groq Server API Error (' . $httpCode . '): ' . $msg], 502);
}

$jsonRes = json_decode((string)$response, true);
$transcription = $jsonRes['text'] ?? '';

json_response([
    'ok' => true,
    'text' => trim($transcription)
], 200);
