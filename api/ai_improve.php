<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/session.php';
session_bootstrap();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/json.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/curl_ssl.php';
require_once __DIR__ . '/../includes/ai_improve_lib.php';
require_once __DIR__ . '/../includes/api_rate_limit.php';

$user = (isset($_SESSION['user']) && is_array($_SESSION['user'])) ? $_SESSION['user'] : null;
if (!is_array($user)) {
    json_response(['ok' => false, 'error' => 'Unauthorized. Please login.'], 401);
}
$role = strtolower(trim((string) ($user['role'] ?? '')));
if (!in_array($role, ['teacher', 'admin', 'super_admin'], true)) {
    json_response(['ok' => false, 'error' => 'Forbidden.'], 403);
}

$data = require_post_json();
require_csrf_from_json($data);

$userId = trim((string) ($user['id'] ?? ''));
$clientIp = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
if (!api_rate_limit_allow('web_ai_improve:' . $userId . ':' . $clientIp, 20, 60)) {
    json_response(['ok' => false, 'error' => 'Too many requests. Please wait.'], 429);
}

$rawText = trim((string) ($data['raw_text'] ?? ''));
$result = ai_improve_text($rawText);
if (($result['ok'] ?? false) === true) {
    json_response(['ok' => true, 'improved_text' => $result['improved_text']], 200);
}

json_response(
    ['ok' => false, 'error' => (string) ($result['error'] ?? 'AI formatting failed.')],
    (int) ($result['status'] ?? 502)
);
