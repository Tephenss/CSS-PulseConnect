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
require_once __DIR__ . '/../includes/certificate_code_pool.php';

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
    . '?select=id,created_by,' . ($table === 'event_session_certificate_templates' ? 'session_id' : 'event_id')
    . '&id=eq.' . rawurlencode($templateId)
    . '&limit=1';
$getRes = supabase_request('GET', $getUrl, $headers);
$rows = json_decode((string) ($getRes['body'] ?? ''), true);
$row = is_array($rows) && isset($rows[0]) && is_array($rows[0]) ? $rows[0] : null;

// If scope was wrong/missing, try the other table.
if (!$row && $templateScope !== 'session') {
    $table = 'event_session_certificate_templates';
    $getUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/' . $table
        . '?select=id,created_by,' . ($table === 'event_session_certificate_templates' ? 'session_id' : 'event_id')
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
        . '?select=id,created_by,' . ($table === 'event_session_certificate_templates' ? 'session_id' : 'event_id')
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

// Same rule as create: the code printed on the design must not already belong
// to another event/seminar, otherwise issuing would collide on unique(code).
$scopeSessionId = trim((string) ($row['session_id'] ?? ''));
$scopeEventId = trim((string) ($row['event_id'] ?? ''));
if ($scopeSessionId !== '' && $scopeEventId === '') {
    $sessRows = json_decode((string) (supabase_request(
        'GET',
        rtrim(SUPABASE_URL, '/') . '/rest/v1/event_sessions'
            . '?select=event_id&id=eq.' . rawurlencode($scopeSessionId) . '&limit=1',
        $headers
    )['body'] ?? ''), true);
    $scopeEventId = isset($sessRows[0]['event_id']) ? trim((string) $sessRows[0]['event_id']) : '';
}

// Only conflict-check when the printed seed CHANGES. Re-saving the same design
// (thumb refresh, linked preview sync) must not 409 just because that seed is
// already in this event's pool or already issued to participants.
$existingCanvasUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/' . $table
    . '?select=canvas_state&id=eq.' . rawurlencode($templateId) . '&limit=1';
$existingCanvasRes = supabase_request('GET', $existingCanvasUrl, $headers);
$existingCanvasRows = json_decode((string) ($existingCanvasRes['body'] ?? ''), true);
$existingCanvas = is_array($existingCanvasRows) && isset($existingCanvasRows[0]['canvas_state'])
    ? $existingCanvasRows[0]['canvas_state']
    : null;
$previousSeed = certificate_pool_extract_seed_from_canvas($existingCanvas);
$seedOnDesign = certificate_pool_extract_seed_from_canvas($canvasState);
$seedChanged = is_string($seedOnDesign) && $seedOnDesign !== ''
    && strcasecmp($seedOnDesign, (string) ($previousSeed ?? '')) !== 0;
if ($seedChanged) {
    $usage = certificate_pool_code_usage(
        $seedOnDesign,
        $scopeEventId !== '' ? $scopeEventId : null,
        $scopeSessionId !== '' ? $scopeSessionId : null
    );
    if (($usage['taken'] ?? false) === true) {
        json_response([
            'ok' => false,
            'error' => certificate_pool_code_conflict_message($usage),
            'code_conflict' => $usage,
        ], 409);
    }
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
