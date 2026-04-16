<?php
declare(strict_types=1);
session_start();

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
