<?php
declare(strict_types=1);

/**
 * Parse LU registration-form PDF (Create Account preview). Does not store the file.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/json.php';
require_once __DIR__ . '/../includes/mobile_api.php';
require_once __DIR__ . '/../includes/api_rate_limit.php';
require_once __DIR__ . '/../includes/registration_form_parse.php';
require_once __DIR__ . '/../includes/student_class_schedules.php';

mobile_api_install_json_error_trap();
$fields = mobile_api_require_post_fields();
mobile_api_validate_key($fields);

$clientIp = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
if (!api_rate_limit_allow('mobile_schedule_parse:' . $clientIp, 12, 600)) {
    json_response(['ok' => false, 'error' => 'Too many parse attempts. Please wait.'], 429);
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

json_response([
    'ok' => true,
    'subjects' => student_class_schedules_public_rows($parsed['subjects'] ?? []),
]);
