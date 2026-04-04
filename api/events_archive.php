<?php
declare(strict_types=1);

session_start();

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/supabase.php';
require_once __DIR__ . '/../includes/json.php';
require_once __DIR__ . '/../includes/csrf.php';

$user = require_role(['admin']);
$data = require_post_json();
require_csrf_from_json($data);

$eventId = isset($data['event_id']) ? (string) $data['event_id'] : '';

if ($eventId === '') {
    json_response(['ok' => false, 'error' => 'event_id required'], 400);
}

$action = isset($data['action']) ? (string) $data['action'] : 'archive';
$status = $action === 'restore' ? 'pending' : 'archived';

$payload = [
    'status' => $status,
    'updated_at' => gmdate('c'),
];

// If restoring, we should also clean up the [REJECT_REASON] tag from the description
if ($action === 'restore') {
    // We need the current description first
    $getUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/events?id=eq.' . rawurlencode($eventId) . '&select=description';
    $getHeaders = [
        'Accept: application/json',
        'apikey: ' . SUPABASE_KEY,
        'Authorization: Bearer ' . SUPABASE_KEY,
    ];
    $getRes = supabase_request('GET', $getUrl, $getHeaders);
    if ($getRes['ok']) {
        $rows = json_decode((string) $getRes['body'], true);
        if (isset($rows[0]['description'])) {
            $desc = (string)$rows[0]['description'];
            $newDesc = preg_replace('/\[REJECT_REASON:.*?\]/', '', $desc);
            $payload['description'] = trim($newDesc);
        }
    }
}

$url = rtrim(SUPABASE_URL, '/') . '/rest/v1/events?id=eq.' . rawurlencode($eventId) . '&select=id,status';
$headers = [
    'Content-Type: application/json',
    'Accept: application/json',
    'apikey: ' . SUPABASE_KEY,
    'Authorization: Bearer ' . SUPABASE_KEY,
    'Prefer: return=representation',
];

$res = supabase_request('PATCH', $url, $headers, json_encode($payload, JSON_UNESCAPED_SLASHES));
if (!$res['ok']) {
    json_response(['ok' => false, 'error' => build_error($res['body'] ?? null, (int) ($res['status'] ?? 0), $res['error'] ?? null, 'Archive failed')], 500);
}

$rows = json_decode((string) $res['body'], true);
$event = is_array($rows) && isset($rows[0]) ? $rows[0] : null;
json_response(['ok' => true, 'event' => $event], 200);
