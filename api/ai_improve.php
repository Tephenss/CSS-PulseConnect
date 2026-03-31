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

$input = file_get_contents('php://input');
$data = json_decode($input, true);
$rawText = trim((string)($data['raw_text'] ?? ''));

if (empty($rawText)) {
    echo json_encode(['ok' => false, 'error' => 'No text provided.']);
    exit;
}

if (!defined('GEMINI_API_KEY') || GEMINI_API_KEY === 'YOUR_GEMINI_API_KEY_HERE') {
    echo json_encode(['ok' => false, 'error' => 'GEMINI_API_KEY is missing or just a placeholder in config.php. Please add your real key.']);
    exit;
}

// Prepare the Prompt for Gemini
$systemPrompt = "You are an expert event copywriter and AI editor for the 'College of Computer Studies (CCS) PulseConnect', a university Event Management System. Your job is to take raw, messy, dictated Speech-to-Text notes (which may contain typos, bad grammar, or mixed Taglish) and polish them into a highly professional, engaging event description for students and faculty. "
              . "REQUIREMENTS:\n"
              . "1. Fix all typos, misheard STT words (e.g. 'is Isa ang event' -> 'is an event').\n"
              . "2. Improve the grammar, vocabulary, and flow significantly. Make it sound professional, academic, yet exciting.\n"
              . "3. Automatically EXPAND the idea appropriately for an IT/Computer Science university event. Add relevant professional hooks (e.g., 'Join us to enhance your skills', 'Network with fellow tech enthusiasts', 'Open to all aspiring developers') if they fit the context.\n"
              . "4. CRITICAL LAYOUT: Format the output nicely using multiple short paragraphs. Use standard bullet symbol '•' or dashes '-' for key highlights. \n"
              . "5. CRITICAL RAW TEXT CONSTRAINT: DO NOT use any Markdown formatting! NO asterisks (**), NO markdown bolding, NO markdown italics. The text will be displayed in a basic HTML textarea, so it must be 100% plain text.\n"
              . "6. Output ONLY the final polished text with no introductory polite phrases (like 'Here is the improved text:').";

$geminiUrl = "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent?key=" . GEMINI_API_KEY;

$payload = [
    "contents" => [
        [
            "role" => "user",
            "parts" => [
                ["text" => $systemPrompt . "\n\nRAW TEXT TO FORMAT:\n" . $rawText]
            ]
        ]
    ]
];

$ch = curl_init($geminiUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));

if (defined('SUPABASE_DEV_SKIP_SSL_VERIFY') && SUPABASE_DEV_SKIP_SSL_VERIFY) {
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
}

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

if ($curlError) {
    echo json_encode(['ok' => false, 'error' => 'cURL Error connecting to Gemini: ' . $curlError]);
    exit;
}

if ($httpCode !== 200) {
    echo json_encode(['ok' => false, 'error' => 'Google Gemini Error (' . $httpCode . '): ' . $response]);
    exit;
}

$jsonRes = json_decode((string)$response, true);
$formattedText = $jsonRes['candidates'][0]['content']['parts'][0]['text'] ?? '';

if (empty($formattedText)) {
    echo json_encode(['ok' => false, 'error' => 'Gemini returned an empty response.']);
    exit;
}

// Return the beautifully formatted text to the frontend
echo json_encode([
    'ok' => true,
    'improved_text' => trim($formattedText)
]);
