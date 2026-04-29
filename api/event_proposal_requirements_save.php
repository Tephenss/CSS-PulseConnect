<?php
declare(strict_types=1);

session_start();

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/supabase.php';
require_once __DIR__ . '/../includes/json.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/proposal_requirements.php';

$user = require_role(['admin']);
$data = require_post_json();
require_csrf_from_json($data);

function proposal_requirements_send_notification(array $userIds, string $title, string $body, array $data = []): void
{
    if ($userIds === []) {
        return;
    }

    require_once __DIR__ . '/../includes/fcm.php';

    $inList = '(' . implode(',', array_map('rawurlencode', $userIds)) . ')';
    $tokensRes = supabase_request(
        'GET',
        rtrim(SUPABASE_URL, '/') . '/rest/v1/fcm_tokens?select=token&user_id=in.' . $inList,
        ['apikey: ' . SUPABASE_KEY, 'Authorization: Bearer ' . SUPABASE_KEY]
    );

    if (!$tokensRes['ok']) {
        return;
    }

    $tokenRows = json_decode((string) ($tokensRes['body'] ?? ''), true);
    if (!is_array($tokenRows)) {
        return;
    }

    $tokens = [];
    foreach ($tokenRows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $token = trim((string) ($row['token'] ?? ''));
        if ($token !== '') {
            $tokens[$token] = true;
        }
    }

    if ($tokens !== []) {
        send_fcm_notification(array_keys($tokens), $title, $body, $data);
    }
}

$eventId = trim((string) ($data['event_id'] ?? ''));
$requirements = is_array($data['requirements'] ?? null) ? $data['requirements'] : [];

if ($eventId === '') {
    json_response(['ok' => false, 'error' => 'Missing event id.'], 400);
}

$headers = proposal_requirement_headers();
$eventUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/events'
    . '?id=eq.' . rawurlencode($eventId)
    . '&select=id,title,status,proposal_stage,created_by'
    . '&limit=1';
$eventRes = supabase_request('GET', $eventUrl, $headers);

if (!$eventRes['ok']) {
    json_response([
        'ok' => false,
        'error' => build_error($eventRes['body'] ?? null, (int) ($eventRes['status'] ?? 0), $eventRes['error'] ?? null, 'Unable to load the proposal event'),
    ], 500);
}

$eventRows = json_decode((string) ($eventRes['body'] ?? ''), true);
if (!is_array($eventRows) || !isset($eventRows[0]) || !is_array($eventRows[0])) {
    json_response(['ok' => false, 'error' => 'Proposal event not found.'], 404);
}

$event = $eventRows[0];
$status = strtolower(trim((string) ($event['status'] ?? '')));
if ($status !== 'pending') {
    json_response(['ok' => false, 'error' => 'Only pending proposals can receive requirement requests.'], 400);
}

$saveResult = save_proposal_requirements(
    $eventId,
    $requirements,
    trim((string) ($user['id'] ?? '')),
    $headers
);

if (!($saveResult['ok'] ?? false)) {
    json_response([
        'ok' => false,
        'error' => (string) ($saveResult['error'] ?? 'Failed to save proposal requirements.'),
    ], 400);
}

$teacherId = trim((string) ($event['created_by'] ?? ''));
$eventTitle = trim((string) ($event['title'] ?? 'your proposal'));
$count = (int) ($saveResult['count'] ?? 0);

if ($teacherId !== '') {
    $body = $count === 1
        ? 'The admin requested 1 document for "' . $eventTitle . '". Upload it in the Approval tab to continue.'
        : 'The admin requested ' . $count . ' documents for "' . $eventTitle . '". Upload them in the Approval tab to continue.';

    proposal_requirements_send_notification(
        [$teacherId],
        'Proposal Documents Requested',
        $body,
        [
            'event_id' => $eventId,
            'type' => 'proposal_requirements_requested',
        ]
    );
}

json_response([
    'ok' => true,
    'message' => 'Proposal requirements saved successfully.',
    'count' => $count,
]);
