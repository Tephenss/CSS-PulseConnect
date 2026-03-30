<?php
declare(strict_types=1);

session_start();

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/supabase.php';
require_once __DIR__ . '/../includes/json.php';
require_once __DIR__ . '/../includes/csrf.php';

$user = require_role(['student']);
$data = require_post_json();
require_csrf_from_json($data);

$eventId = isset($data['event_id']) ? (string) $data['event_id'] : '';
if ($eventId === '') {
    json_response(['ok' => false, 'error' => 'event_id required'], 400);
}

$answers = [];
if (isset($data['answers']) && is_array($data['answers'])) {
    $answers = $data['answers'];
} elseif (isset($data['answers_json']) && is_string($data['answers_json'])) {
    $decoded = json_decode($data['answers_json'], true);
    $answers = is_array($decoded) ? $decoded : [];
}

// Load questions to enforce "required"
$questionsUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/evaluation_questions'
    . '?select=id,required,field_type,question_text'
    . '&event_id=eq.' . rawurlencode($eventId)
    . '&order=sort_order.asc';

$headers = [
    'Accept: application/json',
    'apikey: ' . SUPABASE_KEY,
    'Authorization: Bearer ' . SUPABASE_KEY,
];
$qRes = supabase_request('GET', $questionsUrl, $headers);
if (!$qRes['ok']) {
    json_response(['ok' => false, 'error' => build_error($qRes['body'] ?? null, (int) ($qRes['status'] ?? 0), $qRes['error'] ?? null, 'Question lookup failed')], 500);
}
$qRows = json_decode((string) $qRes['body'], true);
$questions = is_array($qRows) ? $qRows : [];

$requiredQuestionIds = [];
foreach ($questions as $q) {
    if (!empty($q['required'])) {
        $requiredQuestionIds[] = (string) ($q['id'] ?? '');
    }
}

foreach ($requiredQuestionIds as $qid) {
    if (!isset($answers[$qid])) {
        json_response(['ok' => false, 'error' => 'Please answer all required questions.'], 400);
    }
    $val = $answers[$qid];
    if (is_string($val) && trim($val) === '') {
        json_response(['ok' => false, 'error' => 'Please answer all required questions.'], 400);
    }
}

// Upsert answers (merge duplicates).
$payloads = [];
foreach ($questions as $q) {
    $qid = (string) ($q['id'] ?? '');
    if ($qid === '') continue;
    $val = isset($answers[$qid]) ? $answers[$qid] : '';
    if (is_string($val)) {
        $val = trim($val);
    }
    // Only store non-empty answers; required checks already validated.
    if ($val === '' || $val === null) continue;

    $payloads[] = [
        'event_id' => $eventId,
        'question_id' => $qid,
        'student_id' => (string) ($user['id'] ?? ''),
        'answer_text' => is_scalar($val) ? (string) $val : null,
        'submitted_at' => gmdate('c'),
    ];
}

if (count($payloads) === 0) {
    json_response(['ok' => false, 'error' => 'No answers provided'], 400);
}

$postUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/evaluation_answers'
    . '?select=id,question_id'
    . '&Prefer=return=representation,resolution=merge-duplicates';
$postHeaders = [
    'Content-Type: application/json',
    'Accept: application/json',
    'apikey: ' . SUPABASE_KEY,
    'Authorization: Bearer ' . SUPABASE_KEY,
];

$postRes = supabase_request('POST', $postUrl, $postHeaders, json_encode($payloads, JSON_UNESCAPED_SLASHES));
if (!$postRes['ok']) {
    json_response(['ok' => false, 'error' => build_error($postRes['body'] ?? null, (int) ($postRes['status'] ?? 0), $postRes['error'] ?? null, 'Submit failed')], 500);
}

json_response(['ok' => true], 200);

