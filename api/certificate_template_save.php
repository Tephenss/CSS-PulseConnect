<?php
declare(strict_types=1);

session_start();

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/supabase.php';
require_once __DIR__ . '/../includes/json.php';
require_once __DIR__ . '/../includes/csrf.php';

$user = require_role(['admin']);
$data = require_post_json();
require_csrf_from_json($data);

$eventId = isset($data['event_id']) ? (string) $data['event_id'] : '';
$title = isset($data['title']) ? clean_string((string) $data['title']) : 'Certificate of Participation';
$bodyText = isset($data['body_text']) ? clean_string((string) $data['body_text']) : '';
$footerText = isset($data['footer_text']) ? clean_string((string) $data['footer_text']) : '';

if ($eventId === '' || $bodyText === '') {
    json_response(['ok' => false, 'error' => 'event_id and body_text required'], 400);
}

$payload = [
    'event_id' => $eventId,
    'title' => $title !== '' ? $title : 'Certificate of Participation',
    'body_text' => $bodyText,
    'footer_text' => $footerText !== '' ? $footerText : null,
    'created_by' => (string) ($user['id'] ?? ''),
    'updated_at' => gmdate('c'),
];

$url = rtrim(SUPABASE_URL, '/') . '/rest/v1/certificate_templates?select=id,event_id,title';
$headers = [
    'Content-Type: application/json',
    'Accept: application/json',
    'apikey: ' . SUPABASE_KEY,
    'Authorization: Bearer ' . SUPABASE_KEY,
    'Prefer: return=representation,resolution=merge-duplicates',
];

$res = supabase_request('POST', $url, $headers, json_encode([$payload], JSON_UNESCAPED_SLASHES));
if (!$res['ok']) {
    json_response(['ok' => false, 'error' => build_error($res['body'] ?? null, (int) ($res['status'] ?? 0), $res['error'] ?? null, 'Save template failed')], 500);
}

$rows = json_decode((string) $res['body'], true);
$tpl = is_array($rows) && isset($rows[0]) ? $rows[0] : null;
json_response(['ok' => true, 'template' => $tpl], 200);

