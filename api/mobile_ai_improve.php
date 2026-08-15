<?php
declare(strict_types=1);

/**
 * Mobile AI Enhance Description (teacher/admin).
 * Uses server GEMINI_API_KEY / GROQ_API_KEY — Flutter must not hold AI keys.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/json.php';
require_once __DIR__ . '/../includes/curl_ssl.php';
require_once __DIR__ . '/../includes/mobile_api.php';
require_once __DIR__ . '/../includes/mobile_session.php';
require_once __DIR__ . '/../includes/api_rate_limit.php';
require_once __DIR__ . '/../includes/ai_improve_lib.php';

$data = mobile_api_require_post_json();
mobile_api_validate_key($data);
$sessionUser = mobile_api_require_user($data);
$userId = (string) ($sessionUser['id'] ?? '');
$role = strtolower(trim((string) ($sessionUser['role'] ?? 'student')));

if ($role !== 'teacher' && $role !== 'admin') {
    json_response(['ok' => false, 'error' => 'Only teachers can use AI Enhance.'], 403);
}

$clientIp = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
if (!api_rate_limit_allow('mobile_ai_improve:' . $userId . ':' . $clientIp, 20, 60)) {
    json_response(['ok' => false, 'error' => 'Too many AI requests. Please wait a moment.'], 429);
}

$rawText = trim((string) ($data['raw_text'] ?? $data['text'] ?? ''));
$result = ai_improve_text($rawText);
if (($result['ok'] ?? false) === true) {
    json_response(['ok' => true, 'improved_text' => $result['improved_text']], 200);
}

json_response(
    ['ok' => false, 'error' => (string) ($result['error'] ?? 'AI formatting failed.')],
    (int) ($result['status'] ?? 502)
);
