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
if ($questionId === '') {
    json_response(['ok' => false, 'error' => 'question_id required'], 400);
}

// Optional ownership check for teacher
if ((string) ($user['role'] ?? 'teacher') === 'teacher') {
    $checkUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/evaluation_questions?select=id,event_id,events(created_by)&'
        . 'id=eq.' . rawurlencode($questionId) . '&limit=1';
    $headers = [
        'Accept: application/json',
        'apikey: ' . SUPABASE_KEY,
        'Authorization: Bearer ' . SUPABASE_KEY,
    ];
    $checkRes = supabase_request('GET', $checkUrl, $headers);
    $rows = $checkRes['ok'] ? json_decode((string) $checkRes['body'], true) : null;
    $q = is_array($rows) && isset($rows[0]) ? $rows[0] : null;
    if (!is_array($q) || (string) (($q['events']['created_by'] ?? '') ?? '') !== (string) ($user['id'] ?? '')) {
        json_response(['ok' => false, 'error' => 'Forbidden'], 403);
    }
}

$url = rtrim(SUPABASE_URL, '/') . '/rest/v1/evaluation_questions?id=eq.' . rawurlencode($questionId);
$headers = [
    'Accept: application/json',
    'apikey: ' . SUPABASE_KEY,
    'Authorization: Bearer ' . SUPABASE_KEY,
];

$res = supabase_request('DELETE', $url, $headers);
if (!$res['ok']) {
    json_response(['ok' => false, 'error' => build_error($res['body'] ?? null, (int) ($res['status'] ?? 0), $res['error'] ?? null, 'Delete failed')], 500);
}

json_response(['ok' => true], 200);

