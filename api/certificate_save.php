<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/session.php';
session_bootstrap();

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/supabase.php';
require_once __DIR__ . '/../includes/json.php';
require_once __DIR__ . '/../includes/csrf.php';

$user = require_role(['admin', 'teacher']);
$input = require_post_json();
csrf_validate($input['csrf_token'] ?? null);

$event_id = trim((string) ($input['event_id'] ?? ''));
$session_id = trim((string) ($input['session_id'] ?? ''));
$template_scope = strtolower(trim((string) ($input['template_scope'] ?? 'event')));
$name = trim((string) ($input['title'] ?? 'Custom Layout'));
$canvas_state = $input['canvas_state'] ?? null;
$thumbnail_url = $input['thumbnail_url'] ?? null;

if (!$canvas_state) {
    json_response(['ok' => false, 'error' => 'Invalid canvas state data.'], 400);
}

if (!in_array($template_scope, ['event', 'session'], true)) {
    $template_scope = 'event';
}

if ($template_scope === 'session' && $session_id === '') {
    json_response(['ok' => false, 'error' => 'A seminar must be selected before saving this template.'], 400);
}

$role = strtolower(trim((string) ($user['role'] ?? '')));
$userId = trim((string) ($user['id'] ?? ''));
$headersRead = [
    'Accept: application/json',
    'apikey: ' . SUPABASE_KEY,
    'Authorization: Bearer ' . SUPABASE_KEY,
];

// Teachers may only save templates for events they own or are assigned to.
if ($role === 'teacher') {
    $checkEventId = $event_id;
    if ($template_scope === 'session' && $session_id !== '') {
        $sessUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/event_sessions'
            . '?select=event_id&id=eq.' . rawurlencode($session_id) . '&limit=1';
        $sessRes = supabase_request('GET', $sessUrl, $headersRead);
        $sessRows = json_decode((string) ($sessRes['body'] ?? ''), true);
        $checkEventId = is_array($sessRows) && isset($sessRows[0]['event_id'])
            ? (string) $sessRows[0]['event_id']
            : '';
    }
    if ($checkEventId === '') {
        json_response(['ok' => false, 'error' => 'event_id required for teacher template save.'], 400);
    }
    $eventUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/events'
        . '?select=id,created_by&id=eq.' . rawurlencode($checkEventId) . '&limit=1';
    $eventRes = supabase_request('GET', $eventUrl, $headersRead);
    $eventRows = json_decode((string) ($eventRes['body'] ?? ''), true);
    $event = is_array($eventRows) && isset($eventRows[0]) ? $eventRows[0] : null;
    $owned = is_array($event) && (string) ($event['created_by'] ?? '') === $userId;
    if (!$owned) {
        $assignUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/event_teacher_assignments'
            . '?select=id&event_id=eq.' . rawurlencode($checkEventId)
            . '&teacher_id=eq.' . rawurlencode($userId) . '&limit=1';
        $assignRes = supabase_request('GET', $assignUrl, $headersRead);
        $assignRows = json_decode((string) ($assignRes['body'] ?? ''), true);
        $owned = is_array($assignRows) && count($assignRows) > 0;
    }
    if (!$owned) {
        json_response(['ok' => false, 'error' => 'You do not have permission to save templates for this event.'], 403);
    }
    if ($event_id === '') {
        $event_id = $checkEventId;
    }
}

// Prepare payload
$payload = [
    'title' => $name,
    'canvas_state' => is_string($canvas_state) ? json_decode($canvas_state, true) : $canvas_state,
    'thumbnail_url' => $thumbnail_url
];

if (json_last_error() !== JSON_ERROR_NONE && is_string($canvas_state)) {
    $payload['canvas_state'] = []; // fallback if raw JSON parsing bombs
}

if ($template_scope === 'session') {
    $payload['session_id'] = $session_id;
    $payload['body_text'] = 'This certifies that {{name}} participated in {{session}}.';
    $payload['footer_text'] = null;
    if (!empty($user['id'])) {
        $payload['created_by'] = (string) $user['id'];
    }
} elseif ($event_id !== '') {
    $payload['event_id'] = $event_id;
}

$url = rtrim(SUPABASE_URL, '/') . (
    $template_scope === 'session'
        ? '/rest/v1/event_session_certificate_templates'
        : '/rest/v1/certificate_templates'
);
$headers = [
    'Accept: application/json',
    'Content-Type: application/json',
    'apikey: ' . SUPABASE_KEY,
    'Authorization: Bearer ' . SUPABASE_KEY,
    'Prefer: return=representation'
];

$res = supabase_request('POST', $url, $headers, json_encode($payload));

if (!$res['ok']) {
    $err = build_error($res['body'], $res['status'], $res['error'], 'Failed to save certificate layout to templates.');
    json_response(['ok' => false, 'error' => $err], 500);
}

$savedRows = json_decode((string) ($res['body'] ?? '[]'), true);
$savedRow = is_array($savedRows) && isset($savedRows[0]) && is_array($savedRows[0]) ? $savedRows[0] : [];

json_response([
    'ok' => true,
    'template_id' => (string) ($savedRow['id'] ?? ''),
    'template_scope' => $template_scope,
    'session_id' => $session_id,
]);
