<?php
declare(strict_types=1);

session_start();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';

// Ensure user is logged in
$user = require_login();

header('Content-Type: application/json');

// Only allow POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

if (!isset($_FILES['audio']) || $_FILES['audio']['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['ok' => false, 'error' => 'Audio file missing or upload error.']);
    exit;
}

if (!defined('GROQ_API_KEY') || GROQ_API_KEY === 'YOUR_GROQ_API_KEY_HERE') {
    echo json_encode(['ok' => false, 'error' => 'GROQ_API_KEY is missing or just a placeholder in config.php. Please add your real key.']);
    exit;
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
    echo json_encode(['ok' => false, 'error' => 'cURL Error connecting to Groq: ' . $curlError]);
    exit;
}

if ($httpCode !== 200) {
    $errObj = json_decode((string)$response, true);
    $msg = $errObj['error']['message'] ?? $response;
    echo json_encode(['ok' => false, 'error' => 'Groq Server API Error (' . $httpCode . '): ' . $msg]);
    exit;
}

$jsonRes = json_decode((string)$response, true);
$transcription = $jsonRes['text'] ?? '';

echo json_encode([
    'ok' => true,
    'text' => trim($transcription)
]);
