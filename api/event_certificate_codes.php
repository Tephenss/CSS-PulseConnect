<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/session.php';
session_bootstrap();

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/supabase.php';
require_once __DIR__ . '/../includes/json.php';
require_once __DIR__ . '/../includes/certificate_code_pool.php';

$user = require_role(['teacher']);
$userId = trim((string) ($user['id'] ?? ''));
$eventId = trim((string) ($_GET['event_id'] ?? ''));
$sessionId = trim((string) ($_GET['session_id'] ?? ''));

if ($eventId === '') {
    json_response(['ok' => false, 'error' => 'event_id required'], 400);
}
if (!certificate_pool_teacher_may_manage($eventId, $userId)) {
    json_response(['ok' => false, 'error' => 'Forbidden'], 403);
}

$status = certificate_pool_status($eventId, $sessionId !== '' ? $sessionId : null);

$importsUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/event_certificate_imports'
    . '?select=id,source_filename,source_kind,status,codes_found,created_at,session_id'
    . '&event_id=eq.' . rawurlencode($eventId)
    . '&order=created_at.desc&limit=20';
if ($sessionId !== '') {
    $importsUrl .= '&session_id=eq.' . rawurlencode($sessionId);
} else {
    $importsUrl .= '&session_id=is.null';
}
$importsRes = supabase_request('GET', $importsUrl, [
    'Accept: application/json',
    'apikey: ' . SUPABASE_KEY,
    'Authorization: Bearer ' . SUPABASE_KEY,
]);
$imports = $importsRes['ok'] ? json_decode((string) $importsRes['body'], true) : [];
if (!is_array($imports)) {
    $imports = [];
}

json_response([
    'ok' => true,
    'pool' => [
        'available' => $status['available'],
        'assigned' => $status['assigned'],
        'total' => $status['total'],
    ],
    'codes' => $status['codes'],
    'imports' => $imports,
]);
