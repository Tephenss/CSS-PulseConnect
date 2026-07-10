<?php
declare(strict_types=1);

session_start();

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/supabase.php';
require_once __DIR__ . '/../includes/json.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/event_registration_submit.php';

$user = require_role(['student']);
$data = require_post_json();
require_csrf_from_json($data);

$eventId = isset($data['event_id']) ? (string) $data['event_id'] : '';
if ($eventId === '') {
    json_response(['ok' => false, 'error' => 'event_id required'], 400);
}

$headers = event_registration_service_headers();
$result = submit_student_event_registration($eventId, (string) ($user['id'] ?? ''), $headers);
$status = (int) ($result['status'] ?? 200);
unset($result['status']);

json_response($result, $result['ok'] ? 200 : ($status >= 400 ? $status : 500));
