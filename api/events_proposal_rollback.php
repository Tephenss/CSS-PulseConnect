<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/session.php';
session_bootstrap();

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/json.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/proposal_requirements.php';
require_once __DIR__ . '/../includes/event_proposal_rollback.php';

$user = require_role(['teacher']);
$data = require_post_json();
require_csrf_from_json($data);

$eventId = trim((string) ($data['event_id'] ?? ''));
$teacherId = trim((string) ($user['id'] ?? ''));

if ($eventId === '') {
    json_response(['ok' => false, 'error' => 'Missing event id.'], 400);
}

$result = rollback_draft_proposal_event($eventId, $teacherId, proposal_requirement_headers());
if (!($result['ok'] ?? false)) {
    json_response(['ok' => false, 'error' => (string) ($result['error'] ?? 'Rollback failed.')], 400);
}

json_response(['ok' => true], 200);
