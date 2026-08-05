<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/session.php';
session_bootstrap();

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/supabase.php';
require_once __DIR__ . '/../includes/json.php';
require_once __DIR__ . '/../includes/csrf.php';

$user = require_role(['teacher']);
$input = require_post_json();
csrf_validate($input['csrf_token'] ?? null);

$event_id = trim((string) ($input['event_id'] ?? ''));
$session_id = trim((string) ($input['session_id'] ?? ''));
$template_scope = strtolower(trim((string) ($input['template_scope'] ?? 'library')));
$name = trim((string) ($input['title'] ?? 'Custom Layout'));
$canvas_state = $input['canvas_state'] ?? null;
$thumbnail_url = $input['thumbnail_url'] ?? null;

if (!$canvas_state) {
    json_response(['ok' => false, 'error' => 'Invalid canvas state data.'], 400);
}

// Design library templates are standalone (no event). Session scope still needs a seminar.
if (!in_array($template_scope, ['library', 'event', 'session'], true)) {
    $template_scope = $event_id !== '' ? 'event' : 'library';
}
if ($template_scope === 'event' && $event_id === '') {
    $template_scope = 'library';
}
if ($template_scope === 'session' && $session_id === '') {
    json_response(['ok' => false, 'error' => 'A seminar must be selected before saving this template.'], 400);
}

$userId = trim((string) ($user['id'] ?? ''));
$headersRead = [
    'Accept: application/json',
    'apikey: ' . SUPABASE_KEY,
    'Authorization: Bearer ' . SUPABASE_KEY,
];

$teacherMayAccessEvent = static function (string $checkEventId, string $teacherId, array $headers): bool {
    if ($checkEventId === '' || $teacherId === '') {
        return false;
    }
    $eventUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/events'
        . '?select=id,created_by&id=eq.' . rawurlencode($checkEventId) . '&limit=1';
    $eventRes = supabase_request('GET', $eventUrl, $headers);
    $eventRows = json_decode((string) ($eventRes['body'] ?? ''), true);
    $event = is_array($eventRows) && isset($eventRows[0]) ? $eventRows[0] : null;
    if (is_array($event) && (string) ($event['created_by'] ?? '') === $teacherId) {
        return true;
    }
    $assignUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/event_teacher_assignments'
        . '?select=id&event_id=eq.' . rawurlencode($checkEventId)
        . '&teacher_id=eq.' . rawurlencode($teacherId) . '&limit=1';
    $assignRes = supabase_request('GET', $assignUrl, $headers);
    $assignRows = json_decode((string) ($assignRes['body'] ?? ''), true);
    return is_array($assignRows) && count($assignRows) > 0;
};

if ($template_scope === 'session') {
    $sessUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/event_sessions'
        . '?select=event_id&id=eq.' . rawurlencode($session_id) . '&limit=1';
    $sessRes = supabase_request('GET', $sessUrl, $headersRead);
    $sessRows = json_decode((string) ($sessRes['body'] ?? ''), true);
    $checkEventId = is_array($sessRows) && isset($sessRows[0]['event_id'])
        ? (string) $sessRows[0]['event_id']
        : '';
    if ($checkEventId === '' || !$teacherMayAccessEvent($checkEventId, $userId, $headersRead)) {
        json_response(['ok' => false, 'error' => 'You do not have permission to save templates for this seminar.'], 403);
    }
    if ($event_id === '') {
        $event_id = $checkEventId;
    }
} elseif ($template_scope === 'event') {
    if (!$teacherMayAccessEvent($event_id, $userId, $headersRead)) {
        json_response(['ok' => false, 'error' => 'You do not have permission to save templates for this event.'], 403);
    }
}
// library scope: any authenticated teacher may save their own design (created_by = self)

$payload = [
    'title' => $name !== '' ? $name : 'Custom Layout',
    'canvas_state' => is_string($canvas_state) ? json_decode($canvas_state, true) : $canvas_state,
    'thumbnail_url' => $thumbnail_url,
    'created_by' => $userId,
];

if (json_last_error() !== JSON_ERROR_NONE && is_string($canvas_state)) {
    $payload['canvas_state'] = [];
}

$urlPath = '/rest/v1/certificate_templates';
if ($template_scope === 'session') {
    $urlPath = '/rest/v1/event_session_certificate_templates';
    $payload['session_id'] = $session_id;
    $payload['body_text'] = 'This certifies that {{name}} participated in {{session}}.';
    $payload['footer_text'] = null;
} elseif ($template_scope === 'event') {
    $payload['event_id'] = $event_id;
    $payload['body_text'] = $payload['body_text'] ?? 'This certifies that {{name}} participated in {{event}}.';
} else {
    // Standalone library design — explicitly null event link.
    $payload['event_id'] = null;
    $payload['body_text'] = 'This certifies that {{name}} participated in {{event}}.';
}

$url = rtrim(SUPABASE_URL, '/') . $urlPath;
$headers = [
    'Accept: application/json',
    'Content-Type: application/json',
    'apikey: ' . SUPABASE_KEY,
    'Authorization: Bearer ' . SUPABASE_KEY,
    'Prefer: return=representation',
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
    'template_scope' => $template_scope === 'library' ? 'library' : $template_scope,
    'session_id' => $session_id,
]);
