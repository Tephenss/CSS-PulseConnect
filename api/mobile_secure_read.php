<?php
declare(strict_types=1);

/**
 * Session-bound reads for sensitive tables revoked from anon.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/supabase.php';
require_once __DIR__ . '/../includes/json.php';
require_once __DIR__ . '/../includes/mobile_api.php';
require_once __DIR__ . '/../includes/mobile_session.php';
require_once __DIR__ . '/../includes/api_rate_limit.php';

$data = mobile_api_require_post_json();
mobile_api_validate_key($data);
$sessionUser = mobile_api_require_user($data);
$userId = (string) ($sessionUser['id'] ?? '');
$role = strtolower(trim((string) ($sessionUser['role'] ?? 'student')));

$clientIp = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
if (!api_rate_limit_allow('mobile_secure_read:' . $userId . ':' . $clientIp, 120, 60)) {
    json_response(['ok' => false, 'error' => 'Too many requests.'], 429);
}

$table = trim((string) ($data['table'] ?? ''));
$select = trim((string) ($data['select'] ?? '*'));
$filters = $data['filters'] ?? [];
$limit = (int) ($data['limit'] ?? 100);
$limit = max(1, min(500, $limit));

$allowed = [
    'event_registrations',
    'tickets',
    'attendance',
    'event_session_attendance',
    'event_student_requirements',
    'event_student_documents',
    'event_student_submissions',
    'user_notifications',
    'user_notification_reads',
    'user_notification_watermarks',
    'fcm_tokens',
    'attendance_absence_reasons',
    'evaluation_answers',
    'event_session_evaluation_answers',
];

if (!in_array($table, $allowed, true)) {
    json_response(['ok' => false, 'error' => 'Table not allowed.'], 400);
}
if ($select === '' || !preg_match('/^[a-z0-9_,.\s*]+$/i', $select)) {
    json_response(['ok' => false, 'error' => 'Invalid select.'], 400);
}
if (!is_array($filters)) {
    json_response(['ok' => false, 'error' => 'filters must be an object.'], 400);
}

// Force ownership scoping for student role on user-bound tables.
// Note: attendance / event_session_attendance have ticket_id / registration_id,
// not student_id — never force a fake student_id filter (42703).
$studentScoped = [
    'event_registrations' => 'student_id',
    'event_student_documents' => 'student_id',
    'event_student_submissions' => 'student_id',
    'user_notifications' => 'user_id',
    'user_notification_reads' => 'user_id',
    'user_notification_watermarks' => 'user_id',
    'fcm_tokens' => 'user_id',
    'attendance_absence_reasons' => 'student_id',
    'evaluation_answers' => 'student_id',
    'event_session_evaluation_answers' => 'student_id',
];

if ($role === 'student' && isset($studentScoped[$table])) {
    $filters[$studentScoped[$table]] = $userId;
}

$query = 'select=' . rawurlencode($select);
foreach ($filters as $key => $value) {
    $col = (string) $key;
    if (!preg_match('/^[a-z0-9_]+$/i', $col)) {
        continue;
    }
    $val = is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
    $query .= '&' . rawurlencode($col) . '=eq.' . rawurlencode($val);
}
$query .= '&limit=' . $limit;

$url = rtrim(SUPABASE_URL, '/') . '/rest/v1/' . $table . '?' . $query;
$res = supabase_request('GET', $url, mobile_api_supabase_headers());
if (!$res['ok']) {
    json_response(['ok' => false, 'error' => 'Read failed.'], 500);
}

$rows = json_decode((string) $res['body'], true);
json_response([
    'ok' => true,
    'rows' => is_array($rows) ? $rows : [],
], 200);
