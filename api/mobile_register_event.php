<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/supabase.php';
require_once __DIR__ . '/../includes/json.php';
require_once __DIR__ . '/../includes/mobile_api.php';
require_once __DIR__ . '/../includes/event_registration_submit.php';

$data = mobile_api_require_post_json();
mobile_api_validate_key($data);

$eventId = trim((string) ($data['event_id'] ?? ''));
$userId = trim((string) ($data['user_id'] ?? ''));
if ($eventId === '' || $userId === '') {
    json_response(['ok' => false, 'error' => 'event_id and user_id are required.'], 400);
}

$headers = mobile_api_supabase_headers();
$result = submit_student_event_registration($eventId, $userId, $headers);
$status = (int) ($result['status'] ?? 200);
unset($result['status']);

json_response($result, $result['ok'] ? 200 : ($status >= 400 ? $status : 500));
