<?php
declare(strict_types=1);

/**
 * Teacher: certificate auto-send status + manual send for missing recipients.
 * Actions: status | send
 */

require_once __DIR__ . '/../includes/session.php';
session_bootstrap();

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/supabase.php';
require_once __DIR__ . '/../includes/json.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/certificate_code_pool.php';
require_once __DIR__ . '/../includes/certificate_auto_issue.php';
require_once __DIR__ . '/../includes/api_rate_limit.php';

$user = require_role(['teacher']);
$userId = trim((string) ($user['id'] ?? ''));

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    json_response(['ok' => false, 'error' => 'POST required'], 405);
}

$input = require_post_json();
$eventId = trim((string) ($input['event_id'] ?? ''));
$action = strtolower(trim((string) ($input['action'] ?? 'status')));
$csrf = $input['csrf_token'] ?? null;
csrf_validate(is_string($csrf) ? $csrf : null);

if ($eventId === '') {
    json_response(['ok' => false, 'error' => 'event_id required'], 400);
}
if (!certificate_pool_teacher_may_manage($eventId, $userId)) {
    json_response(['ok' => false, 'error' => 'Forbidden'], 403);
}

$clientIp = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
if (!api_rate_limit_allow('cert_manual:' . $userId . ':' . $clientIp, 60, 3600)) {
    json_response(['ok' => false, 'error' => 'Too many requests. Try again later.'], 429);
}

if ($action === 'send') {
    $studentIds = $input['student_ids'] ?? [];
    if (!is_array($studentIds) || $studentIds === []) {
        json_response(['ok' => false, 'error' => 'student_ids required'], 400);
    }
    $result = certificate_auto_issue_manual_for_students($eventId, $studentIds);
    $status = certificate_auto_issue_status_for_event($eventId);
    json_response([
        'ok' => (bool) ($result['ok'] ?? false),
        'attempted' => (int) ($result['attempted'] ?? 0),
        'issued_total' => (int) ($result['issued_total'] ?? 0),
        'students' => $result['students'] ?? [],
        'received' => $status['received'] ?? [],
        'missing' => $status['missing'] ?? [],
        'received_count' => (int) ($status['received_count'] ?? 0),
        'missing_count' => (int) ($status['missing_count'] ?? 0),
        'pool_ready' => (bool) ($status['pool_ready'] ?? true),
        'error' => (string) ($result['error'] ?? ''),
    ], ($result['ok'] ?? false) ? 200 : 500);
}

$status = certificate_auto_issue_status_for_event($eventId);
json_response([
    'ok' => (bool) ($status['ok'] ?? false),
    'uses_sessions' => (bool) ($status['uses_sessions'] ?? false),
    'received' => $status['received'] ?? [],
    'missing' => $status['missing'] ?? [],
    'received_count' => (int) ($status['received_count'] ?? 0),
    'missing_count' => (int) ($status['missing_count'] ?? 0),
    'pool_ready' => (bool) ($status['pool_ready'] ?? true),
    'error' => (string) ($status['error'] ?? ''),
], ($status['ok'] ?? false) ? 200 : 500);
