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

$action = strtolower(trim((string) ($data['action'] ?? 'reorder')));

if ($action === 'reorder') {
    $order = $data['order'] ?? null;
    if (!is_array($order) || $order === []) {
        json_response(['ok' => false, 'error' => 'Missing slide order.'], 400);
    }

    $nowIso = gmdate('c');
    foreach (array_values($order) as $index => $slideId) {
        $slideId = trim((string) $slideId);
        if ($slideId === '') {
            continue;
        }
        $patchUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/app_showcase_slides'
            . '?id=eq.' . rawurlencode($slideId);
        supabase_request(
            'PATCH',
            $patchUrl,
            showcase_write_headers(),
            json_encode([
                'sort_order' => $index,
                'updated_at' => $nowIso,
            ], JSON_UNESCAPED_SLASHES)
        );
    }

    json_response(['ok' => true]);
}

if ($action === 'update') {
    $slideId = trim((string) ($data['id'] ?? ''));
    if ($slideId === '') {
        json_response(['ok' => false, 'error' => 'Missing slide id.'], 400);
    }

    $payload = ['updated_at' => gmdate('c')];
    if (array_key_exists('label', $data)) {
        $label = trim((string) $data['label']);
        if ($label === '') {
            json_response(['ok' => false, 'error' => 'Label cannot be empty.'], 400);
        }
        if (mb_strlen($label) > 80) {
            json_response(['ok' => false, 'error' => 'Label must be 80 characters or fewer.'], 400);
        }
        $payload['label'] = $label;
    }
    if (array_key_exists('is_active', $data)) {
        $makeActive = $data['is_active'] === true || $data['is_active'] === 1
            || $data['is_active'] === '1' || $data['is_active'] === 'true';
        if ($makeActive) {
            $currentUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/app_showcase_slides'
                . '?select=is_active&id=eq.' . rawurlencode($slideId)
                . '&limit=1';
            $currentRes = supabase_request('GET', $currentUrl, showcase_service_headers());
            $currentRows = json_decode((string) ($currentRes['body'] ?? ''), true);
            $currentlyActive = is_array($currentRows)
                && isset($currentRows[0]['is_active'])
                && $currentRows[0]['is_active'] === true;
            if (!$currentlyActive && showcase_count_active_slides() >= SHOWCASE_MAX_ACTIVE_SLIDES) {
                json_response([
                    'ok' => false,
                    'error' => 'Maximum of ' . SHOWCASE_MAX_ACTIVE_SLIDES . ' active slides reached.',
                ], 400);
            }
        }
        $payload['is_active'] = $makeActive;
    }

    $patchUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/app_showcase_slides'
        . '?id=eq.' . rawurlencode($slideId)
        . '&select=id,label,image_url,sort_order,is_active,updated_at';
    $patchRes = supabase_request(
        'PATCH',
        $patchUrl,
        showcase_write_headers(),
        json_encode($payload, JSON_UNESCAPED_SLASHES)
    );
    if (!$patchRes['ok']) {
        json_response([
            'ok' => false,
            'error' => build_error(
                $patchRes['body'] ?? null,
                (int) ($patchRes['status'] ?? 0),
                $patchRes['error'] ?? null,
                'Unable to update slide'
            ),
        ], 500);
    }

    $rows = json_decode((string) ($patchRes['body'] ?? ''), true);
    $slide = is_array($rows) && isset($rows[0]) && is_array($rows[0]) ? $rows[0] : null;
    json_response(['ok' => true, 'slide' => $slide]);
}

json_response(['ok' => false, 'error' => 'Unknown action.'], 400);
