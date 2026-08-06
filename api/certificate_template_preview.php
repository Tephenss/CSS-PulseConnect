<?php
declare(strict_types=1);

/**
 * Preview for a saved certificate template.
 * Returns canvas_state for sharp client-side Fabric render + optional thumbnail fallback.
 */

require_once __DIR__ . '/../includes/session.php';
session_bootstrap();

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/supabase.php';
require_once __DIR__ . '/../includes/json.php';

$user = require_role(['teacher']);
$userId = trim((string) ($user['id'] ?? ''));
$templateId = trim((string) ($_GET['template_id'] ?? ''));

// Import/Link must always show the live design — never a cached canvas.
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

if ($templateId === '') {
    json_response(['ok' => false, 'error' => 'template_id required'], 400);
}

$headers = [
    'Accept: application/json',
    'apikey: ' . SUPABASE_KEY,
    'Authorization: Bearer ' . SUPABASE_KEY,
];

$url = rtrim(SUPABASE_URL, '/') . '/rest/v1/certificate_templates'
    . '?select=id,title,thumbnail_url,canvas_state,created_by,event_id'
    . '&id=eq.' . rawurlencode($templateId)
    . '&limit=1';
$res = supabase_request('GET', $url, $headers);
$rows = json_decode((string) ($res['body'] ?? ''), true);
$row = is_array($rows) && isset($rows[0]) && is_array($rows[0]) ? $rows[0] : null;

// Seminar-scoped linked designs live in a different table.
if (!$row) {
    $sessUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/event_session_certificate_templates'
        . '?select=id,title,thumbnail_url,canvas_state,created_by,session_id'
        . '&id=eq.' . rawurlencode($templateId)
        . '&limit=1';
    $sessRes = supabase_request('GET', $sessUrl, $headers);
    $sessRows = json_decode((string) ($sessRes['body'] ?? ''), true);
    $row = is_array($sessRows) && isset($sessRows[0]) && is_array($sessRows[0]) ? $sessRows[0] : null;
}

if (!$row) {
    json_response(['ok' => false, 'error' => 'Template not found'], 404);
}

$owner = (string) ($row['created_by'] ?? '');
if ($owner !== '' && $owner !== $userId) {
    json_response(['ok' => false, 'error' => 'Forbidden'], 403);
}

$canvas = $row['canvas_state'] ?? null;
if (is_string($canvas)) {
    $canvas = json_decode($canvas, true);
}
if (!is_array($canvas)) {
    $canvas = null;
}

$preview = null;
$thumb = trim((string) ($row['thumbnail_url'] ?? ''));
if ($thumb !== '' && (
    str_starts_with($thumb, 'data:image/')
    || str_starts_with($thumb, 'http://')
    || str_starts_with($thumb, 'https://')
    || str_starts_with($thumb, '/')
)) {
    $preview = $thumb;
}
if (($preview === null || $preview === '') && is_array($canvas)) {
    foreach (['preview_data_url', 'background_data_url'] as $key) {
        $v = trim((string) ($canvas[$key] ?? ''));
        if ($v !== '' && str_starts_with($v, 'data:image/')) {
            $preview = $v;
            break;
        }
    }
}

json_response([
    'ok' => true,
    'template_id' => $templateId,
    'title' => (string) ($row['title'] ?? ''),
    'preview_data_url' => $preview,
    'canvas_state' => $canvas,
]);
