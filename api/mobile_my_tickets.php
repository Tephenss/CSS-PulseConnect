<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/supabase.php';
require_once __DIR__ . '/../includes/json.php';
require_once __DIR__ . '/../includes/mobile_api.php';
require_once __DIR__ . '/../includes/mobile_session.php';

$data = mobile_api_require_post_json();
mobile_api_validate_key($data);
$sessionUser = mobile_api_require_user($data);
$userId = (string) ($sessionUser['id'] ?? '');

// Explicit columns + hard limit — avoid unbounded *,events(*),tickets(*) under concurrent students.
$url = rtrim(SUPABASE_URL, '/') . '/rest/v1/event_registrations'
    . '?select=' . rawurlencode(
        'id,registered_at,student_id,event_id,'
        . 'events(id,title,start_at,end_at,location,status,event_type,cover_image_url),'
        . 'tickets(id,token,attendance(id,status,check_in_at,check_out_at,last_scanned_at))'
    )
    . '&student_id=eq.' . rawurlencode($userId)
    . '&order=registered_at.desc'
    . '&limit=150';

$res = supabase_request('GET', $url, mobile_api_supabase_headers());
if (!$res['ok']) {
    $bodySnippet = substr((string) ($res['body'] ?? ''), 0, 300);
    json_response([
        'ok' => false,
        'error' => 'Failed to load tickets.',
        'debug_status' => (int) ($res['status'] ?? 0),
        'debug_curl' => (string) ($res['error'] ?? ''),
        'debug_body' => $bodySnippet,
    ], 500);
}

$rows = json_decode((string) $res['body'], true);
json_response([
    'ok' => true,
    'rows' => is_array($rows) ? $rows : [],
], 200);
