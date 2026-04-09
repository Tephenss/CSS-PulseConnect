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

$data = require_post_json();
require_csrf_from_json($data);

$rawText = trim((string)($data['raw_text'] ?? ''));

if (empty($rawText)) {
    json_response(['ok' => false, 'error' => 'No text provided.'], 400);
}

if (!defined('GEMINI_API_KEY') || GEMINI_API_KEY === 'YOUR_GEMINI_API_KEY_HERE') {
    json_response(['ok' => false, 'error' => 'GEMINI_API_KEY is missing or just a placeholder in config.php. Please add your real key.'], 500);
}

// Prepare the Prompt for Gemini
$systemPrompt = "You are an expert event copywriter and AI editor for the College of Computer Studies (CCS). Your job is to POLISH and EXPAND the user's raw notes into an engaging event description while STRICTLY PRESERVING their identity and specific context. "
              . "REQUIREMENTS:\n"
              . "1. IDENTITY PRESERVATION: If the user provides their name or introduces themselves (e.g., 'Hi, I am Mark...'), you MUST retain this in the final output. Polish it into a professional opening (e.g., 'Greetings! I am Mark Stephen Espinosa, and I am pleased to announce...') but NEVER remove the name.\n"
              . "2. DO NOT MENTION PULSECONNECT: You are writing a description for an event. Do NOT mention the system/platform 'PulseConnect' anywhere in your response unless the user explicitly types it in their raw text. Just focus purely on the event itself.\n"
              . "3. INTELLIGENT EXPANSION: Analyze the user's core idea and expand it significantly into a professional, engaging announcement. Add relevant highlights, goals, or 'what to expect' if they fit the context of a university IT event. Do not just use a generic template.\n"
              . "4. FIX & POLISH: Correct typos, mixed Taglish, and grammar. Make the tone sophisticated and exciting but grounded in the user's original intent.\n"
              . "5. CRITICAL LAYOUT: Format the output nicely using multiple short paragraphs. Use standard bullet symbol '•' or dashes '-' for key highlights. Ensure it looks clean and readable.\n"
              . "6. CRITICAL RAW TEXT CONSTRAINT: DO NOT use any Markdown formatting! NO asterisks (**), NO markdown bolding, NO markdown italics. The text will be displayed in a basic HTML textarea, so it must be 100% plain text.\n"
              . "7. Output ONLY the final polished text with no introductory polite phrases (like 'Here is the improved text:').";

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

$maxAttempts = 3;
$attempt = 0;
$response = '';
$httpCode = 0;
$curlError = '';

while ($attempt < $maxAttempts) {
    $attempt++;
    $ch = curl_init($geminiUrl);
    if ($ch === false) {
        json_response(['ok' => false, 'error' => 'Failed to initialize AI request.'], 500);
    }
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_SLASHES));
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15);
    curl_setopt($ch, CURLOPT_TIMEOUT, 45);

    if (defined('SUPABASE_DEV_SKIP_SSL_VERIFY') && SUPABASE_DEV_SKIP_SSL_VERIFY) {
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
    }

    $response = (string) curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = (string) curl_error($ch);
    curl_close($ch);

    // Retry only for temporary overload/rate limit responses.
    if (($httpCode === 503 || $httpCode === 429) && $attempt < $maxAttempts) {
        usleep($attempt * 400000); // 0.4s, 0.8s
        continue;
    }
    break;
}

if ($curlError !== '') {
    json_response(['ok' => false, 'error' => 'AI service is unreachable right now. Please try again in a moment.'], 502);
}

if ($httpCode !== 200) {
    if ($httpCode === 503 || $httpCode === 429) {
        json_response([
            'ok' => false,
            'temporary' => true,
            'error' => 'AI service is currently busy. Please try again after a few seconds.'
        ], 503);
    }
    json_response(['ok' => false, 'error' => 'Google Gemini Error (' . $httpCode . '): ' . $response], 502);
}

$jsonRes = json_decode((string)$response, true);
$formattedText = $jsonRes['candidates'][0]['content']['parts'][0]['text'] ?? '';

if (empty($formattedText)) {
    json_response(['ok' => false, 'error' => 'Gemini returned an empty response.'], 502);
}

// Return the beautifully formatted text to the frontend
json_response([
    'ok' => true,
    'improved_text' => trim($formattedText)
], 200);
