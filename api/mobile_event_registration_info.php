<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/supabase.php';
require_once __DIR__ . '/../includes/json.php';
require_once __DIR__ . '/../includes/mobile_api.php';
require_once __DIR__ . '/../includes/event_registration_submit.php';

require_once __DIR__ . '/../includes/mobile_session.php';

$data = mobile_api_require_post_json();
mobile_api_validate_key($data);

$eventId = trim((string) ($data['event_id'] ?? ''));
if ($eventId === '') {
    json_response(['ok' => false, 'error' => 'event_id required.'], 400);
}

// When a session is present, bind to that user (ignore forged user_id).
$userId = '';
$sessionToken = mobile_session_extract_token($data);
if ($sessionToken !== '') {
    $sessionUser = mobile_api_require_user($data);
    $userId = (string) ($sessionUser['id'] ?? '');
}

$headers = mobile_api_supabase_headers();
$result = build_event_registration_info($eventId, $headers, $userId !== '' ? $userId : null);
$status = (int) ($result['status'] ?? 200);
unset($result['status']);

json_response($result, $result['ok'] ? 200 : ($status >= 400 ? $status : 500));
