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
$name = trim((string) ($input['title'] ?? 'Custom Layout'));
$canvas_state = $input['canvas_state'] ?? null;
$thumbnail_url = $input['thumbnail_url'] ?? null;

if (!$canvas_state) {
    json_response(['ok' => false, 'error' => 'Invalid canvas state data.'], 400);
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

if ($event_id !== '') {
    $payload['event_id'] = $event_id;
}

$url = rtrim(SUPABASE_URL, '/') . '/rest/v1/certificate_templates';
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

json_response(['ok' => true]);
