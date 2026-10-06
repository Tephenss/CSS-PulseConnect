<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/session.php';
session_bootstrap();

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/supabase.php';
require_once __DIR__ . '/../includes/json.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/api_rate_limit.php';
require_once __DIR__ . '/../includes/user_notifications.php';
require_once __DIR__ . '/../includes/evaluation_delivery.php';

$user = require_role(['teacher', 'admin']);
$input = require_post_json();
require_csrf_from_json($input);

$eventId = trim((string) ($input['event_id'] ?? ''));
$action = strtolower(trim((string) ($input['action'] ?? 'status')));
if ($eventId === '') {
    json_response(['ok' => false, 'error' => 'event_id required'], 400);
}
if (!in_array($action, ['status', 'resend'], true)) {
    json_response(['ok' => false, 'error' => 'Unsupported action'], 400);
}

$headers = [
    'Accept: application/json',
    'apikey: ' . SUPABASE_KEY,
    'Authorization: Bearer ' . SUPABASE_KEY,
];
$eventUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/events'
    . '?select=id,title,created_by,event_structure,event_mode'
    . '&id=eq.' . rawurlencode($eventId) . '&limit=1';
$eventRes = supabase_request('GET', $eventUrl, $headers);
$eventRows = $eventRes['ok'] ? json_decode((string) ($eventRes['body'] ?? ''), true) : [];
$event = is_array($eventRows) && isset($eventRows[0]) && is_array($eventRows[0]) ? $eventRows[0] : null;
if (!is_array($event)) {
    json_response(['ok' => false, 'error' => 'Event not found'], 404);
}

$userId = trim((string) ($user['id'] ?? ''));
$role = strtolower(trim((string) ($user['role'] ?? '')));
if ($role !== 'admin' && trim((string) ($event['created_by'] ?? '')) !== $userId) {
    json_response(['ok' => false, 'error' => 'Only the event creator or an admin can manage evaluation delivery.'], 403);
}

$status = evaluation_delivery_status_for_event($eventId, $event, $headers);
if (empty($status['ok'])) {
    json_response(['ok' => false, 'error' => (string) ($status['error'] ?? 'Could not load evaluation delivery status.')], 500);
}
if ($action === 'status') {
    json_response($status);
}
if (empty($status['has_questions'])) {
    json_response(['ok' => false, 'error' => 'Add at least one evaluation question before resending.'], 409);
}

$clientIp = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
if (!api_rate_limit_allow('evaluation_resend:' . $userId . ':' . $clientIp, 30, 3600)) {
    json_response(['ok' => false, 'error' => 'Too many resend requests. Please try again later.'], 429);
}

$rawStudentIds = $input['student_ids'] ?? [];
if (!is_array($rawStudentIds) || $rawStudentIds === []) {
    json_response(['ok' => false, 'error' => 'Select at least one attendee to resend the evaluation notice.'], 400);
}
$studentIds = [];
foreach ($rawStudentIds as $value) {
    if (!is_string($value)) {
        json_response(['ok' => false, 'error' => 'Invalid attendee ID.'], 400);
    }
    $studentId = trim($value);
    if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $studentId) !== 1) {
        json_response(['ok' => false, 'error' => 'Invalid attendee ID.'], 400);
    }
    $studentIds[$studentId] = true;
}
$studentIds = array_keys($studentIds);
if (count($studentIds) > 20) {
    json_response(['ok' => false, 'error' => 'Resend up to 20 attendees per request.'], 400);
}
$missingIds = array_fill_keys(array_map(
    static fn (array $row): string => trim((string) ($row['student_id'] ?? '')),
    is_array($status['missing'] ?? null) ? $status['missing'] : []
), true);
foreach ($studentIds as $studentId) {
    if (!isset($missingIds[$studentId])) {
        json_response(['ok' => false, 'error' => 'The attendee list changed. Refresh the delivery status and try again.'], 409);
    }
}

$eventTitle = trim((string) ($event['title'] ?? 'this event'));
$title = 'Evaluation Reminder';
$body = 'The evaluation form for "' . $eventTitle . '" is ready. Open PulseCONNECT to answer it.';
$data = [
    'type' => 'eval_open',
    'event_id' => $eventId,
    'event_title' => $eventTitle,
    'route' => 'evaluation',
    'reminder' => true,
];
$dispatch = dispatch_user_notifications_detailed($studentIds, $title, $body, $data);
if (empty($dispatch['inbox'])) {
    json_response(['ok' => false, 'error' => (string) ($dispatch['error'] ?? 'Could not queue the in-app notification.')], 500);
}

$inboxUsers = evaluation_delivery_existing_inbox_users($eventId, $studentIds, $headers);
$recordedCount = is_array($inboxUsers)
    ? count(array_filter($studentIds, static fn (string $id): bool => isset($inboxUsers[$id])))
    : 0;

json_response([
    'ok' => true,
    'attempted' => count($studentIds),
    'in_app_verified' => is_array($inboxUsers),
    'in_app_recorded' => $recordedCount,
    'push_sent' => (int) ($dispatch['fcm_sent'] ?? 0),
    'push_failed' => (int) ($dispatch['fcm_failed'] ?? 0),
    'push_skipped' => !empty($dispatch['fcm_skipped']),
]);
