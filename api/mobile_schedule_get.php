<?php
declare(strict_types=1);

/**
 * Authenticated read of the signed-in student's stored class schedule.
 * Session user only — never trust client-supplied user_id.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/json.php';
require_once __DIR__ . '/../includes/supabase.php';
require_once __DIR__ . '/../includes/mobile_api.php';
require_once __DIR__ . '/../includes/mobile_session.php';
require_once __DIR__ . '/../includes/api_rate_limit.php';
require_once __DIR__ . '/../includes/student_class_schedules.php';

$data = mobile_api_require_post_json();
mobile_api_validate_key($data);
$sessionUser = mobile_api_require_user($data);
$userId = strtolower(trim((string) ($sessionUser['id'] ?? '')));
$role = strtolower(trim((string) ($sessionUser['role'] ?? '')));
if ($userId === '' || $role !== 'student') {
    json_response(['ok' => false, 'error' => 'Only students can view a class schedule.'], 403);
}

$clientIp = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
if (!api_rate_limit_allow('mobile_schedule_get:' . $userId . ':' . $clientIp, 40, 60)) {
    json_response(['ok' => false, 'error' => 'Too many requests. Please wait.'], 429);
}

$byUser = student_class_schedules_fetch_by_user_ids([$userId], student_class_schedules_headers());
$subjects = student_class_schedules_public_rows($byUser[$userId] ?? []);

json_response([
    'ok' => true,
    'subjects' => $subjects,
]);
