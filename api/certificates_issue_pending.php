<?php
declare(strict_types=1);

/**
 * Teacher-owned: sweep FIFO auto-issue for all eligible registrants missing certs.
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
$csrf = $input['csrf_token'] ?? null;
csrf_validate(is_string($csrf) ? $csrf : null);

if ($eventId === '') {
    json_response(['ok' => false, 'error' => 'event_id required'], 400);
}
if (!certificate_pool_teacher_may_manage($eventId, $userId)) {
    json_response(['ok' => false, 'error' => 'Forbidden'], 403);
}

$clientIp = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
if (!api_rate_limit_allow('cert_pending:' . $userId . ':' . $clientIp, 30, 3600)) {
    json_response(['ok' => false, 'error' => 'Too many requests. Try again later.'], 429);
}

$result = certificate_auto_issue_pending_for_event($eventId);

// Aggregate FIFO counts across event-level + all seminar pools.
require_once __DIR__ . '/../includes/event_sessions.php';
$readHeaders = [
    'Accept: application/json',
    'apikey: ' . SUPABASE_KEY,
    'Authorization: Bearer ' . SUPABASE_KEY,
];
$sessions = fetch_event_sessions($eventId, $readHeaders);
$pool = ['available' => 0, 'assigned' => 0, 'total' => 0];
if (is_array($sessions) && count($sessions) > 0) {
    foreach ($sessions as $srow) {
        if (!is_array($srow)) {
            continue;
        }
        $sid = trim((string) ($srow['id'] ?? ''));
        if ($sid === '') {
            continue;
        }
        $st = certificate_pool_status($eventId, $sid, 200);
        $pool['available'] += (int) ($st['available'] ?? 0);
        $pool['assigned'] += (int) ($st['assigned'] ?? 0);
        $pool['total'] += (int) ($st['total'] ?? 0);
    }
    $eventPool = certificate_pool_status($eventId, null, 200);
    $pool['available'] += (int) ($eventPool['available'] ?? 0);
    $pool['assigned'] += (int) ($eventPool['assigned'] ?? 0);
    $pool['total'] += (int) ($eventPool['total'] ?? 0);
} else {
    $pool = certificate_pool_status($eventId, null, 200);
}

json_response([
    'ok' => (bool) ($result['ok'] ?? false),
    'attempted' => (int) ($result['attempted'] ?? 0),
    'issued_total' => (int) ($result['issued_total'] ?? 0),
    'students' => $result['students'] ?? [],
    'pool' => [
        'available' => (int) ($pool['available'] ?? 0),
        'assigned' => (int) ($pool['assigned'] ?? 0),
        'total' => (int) ($pool['total'] ?? 0),
    ],
    'error' => (string) ($result['error'] ?? ''),
], ($result['ok'] ?? false) ? 200 : 500);
