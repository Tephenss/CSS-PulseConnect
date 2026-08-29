<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/session.php';
session_bootstrap();

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/supabase.php';
require_once __DIR__ . '/../includes/json.php';
require_once __DIR__ . '/../includes/showcase_lib.php';

$user = require_role(['admin']);
$data = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($data)) {
    json_response(['ok' => false, 'error' => 'Invalid JSON body.'], 400);
}
require_csrf_from_json($data);

$slideId = trim((string) ($data['id'] ?? ''));
if ($slideId === '') {
    json_response(['ok' => false, 'error' => 'Missing slide id.'], 400);
}

$fetchUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/app_showcase_slides'
    . '?select=id,storage_path&id=eq.' . rawurlencode($slideId)
    . '&limit=1';
$fetchRes = supabase_request('GET', $fetchUrl, showcase_service_headers());
if (!$fetchRes['ok']) {
    json_response(['ok' => false, 'error' => 'Unable to load slide.'], 500);
}

$rows = json_decode((string) ($fetchRes['body'] ?? ''), true);
$row = is_array($rows) && isset($rows[0]) && is_array($rows[0]) ? $rows[0] : null;
if (!is_array($row)) {
    json_response(['ok' => false, 'error' => 'Slide not found.'], 404);
}

$deleteUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/app_showcase_slides'
    . '?id=eq.' . rawurlencode($slideId);
$deleteRes = supabase_request('DELETE', $deleteUrl, showcase_service_headers());
if (!$deleteRes['ok']) {
    json_response([
        'ok' => false,
        'error' => build_error(
            $deleteRes['body'] ?? null,
            (int) ($deleteRes['status'] ?? 0),
            $deleteRes['error'] ?? null,
            'Unable to delete slide'
        ),
    ], 500);
}

showcase_delete_storage_object((string) ($row['storage_path'] ?? ''));

json_response(['ok' => true]);
