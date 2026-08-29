<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/session.php';
session_bootstrap();

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/supabase.php';
require_once __DIR__ . '/includes/event_sessions.php';
require_once __DIR__ . '/includes/evaluation_feedback_lib.php';

$user = require_role(['teacher', 'admin']);
$role = (string) ($user['role'] ?? 'teacher');
$userId = (string) ($user['id'] ?? '');

$eventId = isset($_GET['event_id']) ? trim((string) $_GET['event_id']) : '';
if ($eventId === '') {
    http_response_code(400);
    echo 'Missing event_id';
    exit;
}

$headers = [
    'Accept: application/json',
    'apikey: ' . SUPABASE_KEY,
    'Authorization: Bearer ' . SUPABASE_KEY,
];

$eventUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/events?select=id,title,status,created_by&id=eq.' . rawurlencode($eventId) . '&limit=1';
$eventRes = supabase_request('GET', $eventUrl, $headers);
$eventRows = $eventRes['ok'] ? json_decode((string) $eventRes['body'], true) : [];
$event = is_array($eventRows) && isset($eventRows[0]) ? $eventRows[0] : null;
if (!is_array($event)) {
    http_response_code(404);
    echo 'Event not found';
    exit;
}

$isEventCreator = (string) ($event['created_by'] ?? '') === $userId;
if ($role === 'teacher' && !$isEventCreator) {
    http_response_code(403);
    echo 'Forbidden';
    exit;
}

$sessions = fetch_event_sessions($eventId, $headers);
$usesSessions = count($sessions) > 0;

$eventQuestionsUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/evaluation_questions'
    . '?select=id,event_id,question_text,field_type,required,sort_order'
    . '&event_id=eq.' . rawurlencode($eventId)
    . '&order=sort_order.asc';
$eventQuestionsRes = supabase_request('GET', $eventQuestionsUrl, $headers);
$eventQuestionRows = $eventQuestionsRes['ok'] ? json_decode((string) $eventQuestionsRes['body'], true) : [];
$eventQuestions = is_array($eventQuestionRows) ? $eventQuestionRows : [];

$sessionQuestionGroups = [];
if ($usesSessions) {
    $sessionIds = [];
    foreach ($sessions as $session) {
        $sid = (string) ($session['id'] ?? '');
        if ($sid !== '') {
            $sessionIds[] = $sid;
        }
    }

    if (count($sessionIds) > 0) {
        $sessionQuestionsUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/event_session_evaluation_questions'
            . '?select=id,session_id,question_text,field_type,required,sort_order'
            . '&session_id=in.(' . implode(',', array_map('rawurlencode', $sessionIds)) . ')'
            . '&order=sort_order.asc';
        $sessionQuestionsRes = supabase_request('GET', $sessionQuestionsUrl, $headers);
        $sessionQuestionRows = $sessionQuestionsRes['ok'] ? json_decode((string) $sessionQuestionsRes['body'], true) : [];
        $sessionQuestions = is_array($sessionQuestionRows) ? $sessionQuestionRows : [];
        foreach ($sessionQuestions as $question) {
            $sid = (string) ($question['session_id'] ?? '');
            if ($sid === '') {
                continue;
            }
            if (!isset($sessionQuestionGroups[$sid])) {
                $sessionQuestionGroups[$sid] = [];
            }
            $groupKey = 'Questions';
            if (!isset($sessionQuestionGroups[$sid][$groupKey])) {
                $sessionQuestionGroups[$sid][$groupKey] = [];
            }
            $sessionQuestionGroups[$sid][$groupKey][] = $question;
        }
    }
}

$feedbackSections = evaluation_feedback_load_sections(
    $eventId,
    $headers,
    $sessions,
    $usesSessions,
    $eventQuestions,
    $sessionQuestionGroups
);

$autoPrint = isset($_GET['print']) && (string) $_GET['print'] === '1';
$eventTitle = (string) ($event['title'] ?? 'Event');

require __DIR__ . '/includes/evaluation_feedback_export_view.php';
