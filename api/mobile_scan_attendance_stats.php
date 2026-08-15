<?php
declare(strict_types=1);

/**
 * Live attendance % for teacher / student-assist scanners (online only).
 * Counts only — never returns participant PII.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/supabase.php';
require_once __DIR__ . '/../includes/json.php';
require_once __DIR__ . '/../includes/mobile_api.php';
require_once __DIR__ . '/../includes/mobile_session.php';
require_once __DIR__ . '/../includes/api_rate_limit.php';
require_once __DIR__ . '/../includes/scan_context.php';
require_once __DIR__ . '/../includes/mobile_scan_attendance_stats.php';

$data = mobile_api_require_post_json();
mobile_api_validate_key($data);
$sessionUser = mobile_api_require_user($data);
$userId = (string) ($sessionUser['id'] ?? '');
$role = strtolower(trim((string) ($sessionUser['role'] ?? 'student')));

$clientIp = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
if (!api_rate_limit_allow('mobile_scan_attendance_stats:' . $userId . ':' . $clientIp, 90, 60)) {
    json_response(['ok' => false, 'error' => 'Too many requests.'], 429);
}

$eventId = trim((string) ($data['event_id'] ?? ''));
$sessionId = trim((string) ($data['session_id'] ?? ''));
$mode = strtolower(trim((string) ($data['mode'] ?? 'check_in')));
if (!in_array($mode, ['check_in', 'check_out'], true)) {
    $mode = 'check_in';
}
if ($eventId === '') {
    json_response(['ok' => false, 'error' => 'event_id is required.'], 400);
}

$headers = mobile_api_supabase_headers();
if (!mobile_scan_stats_can_view($userId, $role, $eventId, $headers)) {
    json_response(['ok' => false, 'error' => 'Not allowed to view attendance for this event.'], 403);
}

json_response(mobile_scan_attendance_stats($eventId, $sessionId, $headers, $mode));
