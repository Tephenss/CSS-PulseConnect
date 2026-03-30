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

$registrationId = isset($data['registration_id']) ? (string) $data['registration_id'] : '';
if ($registrationId === '') {
    json_response(['ok' => false, 'error' => 'registration_id required'], 400);
}

$url = rtrim(SUPABASE_URL, '/') . '/rest/v1/event_registrations?id=eq.' . rawurlencode($registrationId);
$headers = [
    'Accept: application/json',
    'apikey: ' . SUPABASE_KEY,
    'Authorization: Bearer ' . SUPABASE_KEY,
];

$res = supabase_request('DELETE', $url, $headers);
if (!$res['ok']) {
    json_response(['ok' => false, 'error' => build_error($res['body'] ?? null, (int) ($res['status'] ?? 0), $res['error'] ?? null, 'Remove failed')], 500);
}

json_response(['ok' => true], 200);

