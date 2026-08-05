<?php
declare(strict_types=1);

/**
 * Teacher-auth PATCH for an existing certificate template (canvas + optional title/thumb).
 */

require_once __DIR__ . '/../includes/session.php';
session_bootstrap();

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/supabase.php';
require_once __DIR__ . '/../includes/json.php';
require_once __DIR__ . '/../includes/csrf.php';

$user = require_role(['teacher']);
$userId = trim((string) ($user['id'] ?? ''));
$input = require_post_json();
csrf_validate($input['csrf_token'] ?? null);

$templateId = trim((string) ($input['template_id'] ?? ''));
$templateScope = strtolower(trim((string) ($input['template_scope'] ?? '')));
$canvasState = $input['canvas_state'] ?? null;
$thumbnailUrl = $input['thumbnail_url'] ?? null;
$title = isset($input['title']) ? trim((string) $input['title']) : null;

if ($templateId === '') {
    json_response(['ok' => false, 'error' => 'template_id required'], 400);
}
if ($canvasState === null) {
    json_response(['ok' => false, 'error' => 'canvas_state required'], 400);
}
if (is_string($canvasState)) {
    $decoded = json_decode($canvasState, true);
    if (!is_array($decoded)) {
        json_response(['ok' => false, 'error' => 'Invalid canvas_state JSON'], 400);
    }
    $canvasState = $decoded;
}
if (!is_array($canvasState)) {
    json_response(['ok' => false, 'error' => 'Invalid canvas_state'], 400);
}

$headers = [
    'Accept: application/json',
    'apikey: ' . SUPABASE_KEY,
    'Authorization: Bearer ' . SUPABASE_KEY,
];

$table = 'certificate_templates';
if ($templateScope === 'session') {
    $table = 'event_session_certificate_templates';
}

$getUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/' . $table
    . '?select=id,created_by'
    . '&id=eq.' . rawurlencode($templateId)
    . '&limit=1';
$getRes = supabase_request('GET', $getUrl, $headers);
$rows = json_decode((string) ($getRes['body'] ?? ''), true);
$row = is_array($rows) && isset($rows[0]) && is_array($rows[0]) ? $rows[0] : null;

// If scope was wrong/missing, try the other table.
if (!$row && $templateScope !== 'session') {
    $table = 'event_session_certificate_templates';
    $getUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/' . $table
        . '?select=id,created_by'
        . '&id=eq.' . rawurlencode($templateId)
        . '&limit=1';
    $getRes = supabase_request('GET', $getUrl, $headers);
    $rows = json_decode((string) ($getRes['body'] ?? ''), true);
    $row = is_array($rows) && isset($rows[0]) && is_array($rows[0]) ? $rows[0] : null;
    if ($row) {
        $templateScope = 'session';
    } else {
        $table = 'certificate_templates';
    }
} elseif (!$row && $templateScope === 'session') {
    $table = 'certificate_templates';
    $getUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/' . $table
        . '?select=id,created_by'
        . '&id=eq.' . rawurlencode($templateId)
        . '&limit=1';
    $getRes = supabase_request('GET', $getUrl, $headers);
    $rows = json_decode((string) ($getRes['body'] ?? ''), true);
    $row = is_array($rows) && isset($rows[0]) && is_array($rows[0]) ? $rows[0] : null;
    if ($row) {
        $templateScope = 'library';
    }
}

if (!$row) {
    json_response(['ok' => false, 'error' => 'Template not found'], 404);
}
$owner = (string) ($row['created_by'] ?? '');
if ($owner !== '' && $owner !== $userId) {
    json_response(['ok' => false, 'error' => 'Forbidden'], 403);
}

$payload = ['canvas_state' => $canvasState];
if ($thumbnailUrl !== null && is_string($thumbnailUrl) && $thumbnailUrl !== '') {
    $payload['thumbnail_url'] = $thumbnailUrl;
}
if ($title !== null && $title !== '') {
    $payload['title'] = $title;
}

$patchHeaders = [
    'Accept: application/json',
    'Content-Type: application/json',
    'apikey: ' . SUPABASE_KEY,
    'Authorization: Bearer ' . SUPABASE_KEY,
    'Prefer: return=representation',
];
$patchUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/' . $table . '?id=eq.' . rawurlencode($templateId);
$patch = supabase_request('PATCH', $patchUrl, $patchHeaders, json_encode($payload));
if (!$patch['ok']) {
    json_response(['ok' => false, 'error' => 'Failed to update template'], 500);
}

json_response([
    'ok' => true,
    'template_id' => $templateId,
    'template_scope' => $templateScope !== '' ? $templateScope : 'library',
]);
