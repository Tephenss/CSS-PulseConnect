<?php
declare(strict_types=1);

@set_time_limit(45);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/supabase.php';
require_once __DIR__ . '/../includes/json.php';
require_once __DIR__ . '/../includes/mobile_api.php';
require_once __DIR__ . '/../includes/event_registration_submit.php';
require_once __DIR__ . '/../includes/api_rate_limit.php';

require_once __DIR__ . '/../includes/mobile_session.php';

$data = mobile_api_require_post_json();
mobile_api_validate_key($data);

$sessionUser = mobile_api_require_user($data);
$userId = (string) ($sessionUser['id'] ?? '');

$eventId = trim((string) ($data['event_id'] ?? ''));
if ($eventId === '' || $userId === '') {
    json_response(['ok' => false, 'error' => 'event_id and authenticated user are required.'], 400);
}

$clientIp = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
if (!api_rate_limit_allow('mobile_register:' . $userId . ':' . $clientIp, 8, 60)) {
    json_response(['ok' => false, 'error' => 'Too many registration attempts. Please wait a moment.'], 429);
}
if (!api_request_dedupe_first('mobile_register:' . $userId . ':' . $eventId, 4)) {
    json_response(['ok' => false, 'error' => 'Registration already in progress. Please wait.'], 409);
}

$headers = mobile_api_supabase_headers();
$result = submit_student_event_registration($eventId, $userId, $headers);
$status = (int) ($result['status'] ?? 200);
unset($result['status']);

json_response($result, $result['ok'] ? 200 : ($status >= 400 ? $status : 500));
