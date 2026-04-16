<?php
declare(strict_types=1);

session_start();

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/supabase.php';
require_once __DIR__ . '/../includes/json.php';
require_once __DIR__ . '/../includes/csrf.php';

$user = require_role(['teacher', 'admin']);
$data = require_post_json();
require_csrf_from_json($data);

$questionId = isset($data['question_id']) ? (string) $data['question_id'] : '';
$sessionId = isset($data['session_id']) ? trim((string) $data['session_id']) : '';
$required = isset($data['required']) ? (bool) $data['required'] : false;
if ($questionId === '') {
    json_response(['ok' => false, 'error' => 'question_id required'], 400);
}

$payload = [
    'required' => $required,
    'updated_at' => gmdate('c'),
];

$table = $sessionId !== '' ? 'event_session_evaluation_questions' : 'evaluation_questions';
$url = rtrim(SUPABASE_URL, '/') . '/rest/v1/' . $table . '?id=eq.' . rawurlencode($questionId) . '&select=id,required';
$headers = [
    'Content-Type: application/json',
    'Accept: application/json',
    'apikey: ' . SUPABASE_KEY,
    'Authorization: Bearer ' . SUPABASE_KEY,
    'Prefer: return=representation',
];

$res = supabase_request('PATCH', $url, $headers, json_encode($payload, JSON_UNESCAPED_SLASHES));
if (!$res['ok']) {
    json_response(['ok' => false, 'error' => build_error($res['body'] ?? null, (int) ($res['status'] ?? 0), $res['error'] ?? null, 'Update failed')], 500);
}

$rows = json_decode((string) $res['body'], true);
$q = is_array($rows) && isset($rows[0]) ? $rows[0] : null;
json_response(['ok' => true, 'question' => $q], 200);

