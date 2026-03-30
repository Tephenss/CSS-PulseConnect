<?php
declare(strict_types=1);

session_start();

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/supabase.php';
require_once __DIR__ . '/../includes/json.php';
require_once __DIR__ . '/../includes/csrf.php';

$user = require_role(['teacher', 'admin']);
$data = require_post_json();
require_csrf_from_json($data);

$eventId = isset($data['event_id']) ? (string) $data['event_id'] : '';
$questionText = isset($data['question_text']) ? clean_string((string) $data['question_text']) : '';
$fieldType = isset($data['field_type']) ? (string) $data['field_type'] : 'text';
$required = isset($data['required']) ? (bool) $data['required'] : false;
$sortOrder = isset($data['sort_order']) ? (int) $data['sort_order'] : 0;

if ($eventId === '' || $questionText === '') {
    json_response(['ok' => false, 'error' => 'event_id and question_text required'], 400);
}
if (!in_array($fieldType, ['text', 'rating'], true)) {
    json_response(['ok' => false, 'error' => 'Invalid field_type'], 400);
}
if ($sortOrder < 0) $sortOrder = 0;

// Optional: teacher can only manage their own events.
if ((string) ($user['role'] ?? 'teacher') === 'teacher') {
    $checkUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/events?select=id,created_by&'
        . 'id=eq.' . rawurlencode($eventId) . '&limit=1';
    $headers = [
        'Accept: application/json',
        'apikey: ' . SUPABASE_KEY,
        'Authorization: Bearer ' . SUPABASE_KEY,
    ];
    $checkRes = supabase_request('GET', $checkUrl, $headers);
    $checkRows = $checkRes['ok'] ? json_decode((string) $checkRes['body'], true) : null;
    $ev = is_array($checkRows) && isset($checkRows[0]) ? $checkRows[0] : null;
    if (!is_array($ev)) json_response(['ok' => false, 'error' => 'Event lookup failed'], 404);
    if ((string) ($ev['created_by'] ?? '') !== (string) ($user['id'] ?? '')) {
        json_response(['ok' => false, 'error' => 'Forbidden'], 403);
    }
}

$payload = [
    'event_id' => $eventId,
    'question_text' => $questionText,
    'field_type' => $fieldType,
    'required' => $required,
    'sort_order' => $sortOrder,
    'created_at' => gmdate('c'),
];

$url = rtrim(SUPABASE_URL, '/') . '/rest/v1/evaluation_questions?select=id,event_id,question_text,field_type,required,sort_order';
$headers = [
    'Content-Type: application/json',
    'Accept: application/json',
    'apikey: ' . SUPABASE_KEY,
    'Authorization: Bearer ' . SUPABASE_KEY,
    'Prefer: return=representation',
];

$res = supabase_request('POST', $url, $headers, json_encode([$payload], JSON_UNESCAPED_SLASHES));
if (!$res['ok']) {
    json_response(['ok' => false, 'error' => build_error($res['body'] ?? null, (int) ($res['status'] ?? 0), $res['error'] ?? null, 'Save failed')], 500);
}

$rows = json_decode((string) $res['body'], true);
$q = is_array($rows) && isset($rows[0]) ? $rows[0] : null;
json_response(['ok' => true, 'question' => $q], 200);

