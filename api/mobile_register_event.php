<?php
declare(strict_types=1);

@set_time_limit(45);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/supabase.php';
require_once __DIR__ . '/../includes/json.php';
require_once __DIR__ . '/../includes/mobile_api.php';
require_once __DIR__ . '/../includes/event_registration_submit.php';
require_once __DIR__ . '/../includes/api_rate_limit.php';

require_once __DIR__ . '/../includes/mobile_session.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

$data = mobile_api_require_post_json();
mobile_api_validate_key($data);

$sessionUser = mobile_api_require_user($data);
$userId = (string) ($sessionUser['id'] ?? '');

$eventId = trim((string) ($data['event_id'] ?? ''));
if ($eventId === '' || $userId === '') {
    json_response(['ok' => false, 'error' => 'event_id and authenticated user are required.'], 400);
}

$clientIp = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
if (!api_rate_limit_allow('mobile_register:' . $userId . ':' . $clientIp, 8, 60)) {
    json_response(['ok' => false, 'error' => 'Too many registration attempts. Please wait a moment.'], 429);
}
if (!api_request_dedupe_first('mobile_register:' . $userId . ':' . $eventId, 4)) {
    json_response(['ok' => false, 'error' => 'Registration already in progress. Please wait.'], 409);
}

$headers = mobile_api_supabase_headers();
$result = submit_student_event_registration($eventId, $userId, $headers);
if (($result['ok'] ?? false) === true) {
    // The successful insert (or existing registration) is authoritative even if a count header is stale.
    if (is_array($result['session_capacity'] ?? null)) {
        $result['session_capacity'] = event_session_capacity_include_registered_student(
            $result['session_capacity'],
            is_array($result['eligible_sessions'] ?? null) ? $result['eligible_sessions'] : []
        );
        $result['registration_count'] = (int) $result['session_capacity']['registration_count'];
    }
    $result['registration_count'] = max(1, (int) ($result['registration_count'] ?? 0));
    $result['is_registered'] = true;
    $limit = $result['registration_limit'] ?? null;
    if ($limit === null && is_array($result['session_capacity'] ?? null)) {
        $limit = $result['session_capacity']['registration_limit'] ?? null;
    }
    $result['participant_total'] = $limit !== null
        ? $result['registration_count'] . '/' . (int) $limit
        : (string) $result['registration_count'];
}
$status = (int) ($result['status'] ?? 200);
unset($result['status']);

json_response($result, $result['ok'] ? 200 : ($status >= 400 ? $status : 500));
