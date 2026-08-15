<?php
declare(strict_types=1);

/**
 * Authenticated re-upload of LU registration-form PDF (Student Settings).
 * Parses and stores subject rows; discards the file.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/json.php';
require_once __DIR__ . '/../includes/supabase.php';
require_once __DIR__ . '/../includes/mobile_api.php';
require_once __DIR__ . '/../includes/mobile_session.php';
require_once __DIR__ . '/../includes/api_rate_limit.php';
require_once __DIR__ . '/../includes/registration_form_parse.php';
require_once __DIR__ . '/../includes/student_class_schedules.php';
require_once __DIR__ . '/../includes/student_roster.php';

mobile_api_install_json_error_trap();
mobile_api_handle_preflight();
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    json_response(['ok' => false, 'error' => 'Method not allowed'], 405);
}

mobile_api_validate_key($_POST);
$sessionUser = mobile_api_require_user_from_post();
$userId = strtolower(trim((string) ($sessionUser['id'] ?? '')));
$role = strtolower(trim((string) ($sessionUser['role'] ?? '')));
if ($userId === '' || $role !== 'student') {
    json_response(['ok' => false, 'error' => 'Only students can update a class schedule.'], 403);
}

$clientIp = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
if (!api_rate_limit_allow('mobile_schedule_upload:' . $userId . ':' . $clientIp, 8, 600)) {
    json_response(['ok' => false, 'error' => 'Too many uploads. Please wait.'], 429);
}

$upload = registration_form_accept_upload();
if (!$upload['ok']) {
    json_response(['ok' => false, 'error' => $upload['error']], 400);
}

$binary = (string) file_get_contents($upload['path']);
registration_form_discard($upload['path']);

$parsed = registration_form_parse_pdf_bytes($binary);
unset($binary);
if (!($parsed['ok'] ?? false)) {
    json_response(['ok' => false, 'error' => (string) ($parsed['error'] ?? 'Could not read that PDF.')], 400);
}

$studentNo = student_roster_normalize_no((string) ($sessionUser['student_id'] ?? ''));
if (!student_class_schedules_replace($userId, $studentNo, $parsed['subjects'] ?? [])) {
    json_response(['ok' => false, 'error' => 'Could not save class schedule. Ask admin to run migration 059, then try again.'], 500);
}

json_response([
    'ok' => true,
    'subjects' => student_class_schedules_public_rows($parsed['subjects'] ?? []),
]);
