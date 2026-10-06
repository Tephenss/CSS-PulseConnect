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
require_once __DIR__ . '/../includes/teacher_notification_audience.php';

$user = require_role(['teacher']);
$input = require_post_json();
require_csrf_from_json($input);

$titleChoices = [
    'Reminder',
    'Schedule Update',
    'Event Update',
    'Important Announcement',
    'Deadline',
    'Other',
];
$titleChoice = trim((string) ($input['title_choice'] ?? ''));
if (!in_array($titleChoice, $titleChoices, true)) {
    json_response(['ok' => false, 'error' => 'Choose a notification title from the list.'], 400);
}
$title = $titleChoice === 'Other'
    ? trim((string) ($input['custom_title'] ?? ''))
    : $titleChoice;
$body = trim(str_replace(["\r\n", "\r"], "\n", (string) ($input['body'] ?? '')));
if ($title === '' || mb_strlen($title) > 100) {
    json_response(['ok' => false, 'error' => 'Enter a custom title of 1 to 100 characters.'], 400);
}
if ($body === '' || mb_strlen($body) > 500) {
    json_response(['ok' => false, 'error' => 'Enter a message of 1 to 500 characters.'], 400);
}

$mode = (string) ($input['mode'] ?? 'individual');
$maxGroupRecipients = 3000;
$recipientIds = [];
$audienceLabel = '';
$targetEvent = null;
if ($mode === 'individual') {
    $rawIds = $input['recipient_ids'] ?? null;
    if (!is_array($rawIds) || $rawIds === [] || count($rawIds) > 250) {
        json_response(['ok' => false, 'error' => 'Select between 1 and 250 individual student accounts.'], 400);
    }
    foreach ($rawIds as $value) {
        if (!is_string($value)) {
            json_response(['ok' => false, 'error' => 'One or more selected accounts are invalid.'], 400);
        }
    }
    $recipientIds = array_values(array_unique(array_filter(array_map(
        static fn ($value): string => trim($value),
        $rawIds
    ))));
    if ($recipientIds === [] || count($recipientIds) > 250) {
        json_response(['ok' => false, 'error' => 'Select between 1 and 250 individual student accounts.'], 400);
    }

    $uuidPattern = '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/iD';
    foreach ($recipientIds as $recipientId) {
        if (preg_match($uuidPattern, $recipientId) !== 1) {
            json_response(['ok' => false, 'error' => 'One or more selected accounts are invalid. Refresh the list and try again.'], 400);
        }
    }
    try {
        $eligibleIds = teacher_notification_active_student_ids($recipientIds);
    } catch (Throwable $e) {
        error_log('teacher_notification_send: individual account validation failed: ' . $e->getMessage());
        json_response(['ok' => false, 'error' => $e->getMessage()], 503);
    }
    if (count($eligibleIds) !== count($recipientIds)) {
        json_response(['ok' => false, 'error' => 'Some selected accounts are no longer active student accounts. Refresh the list and select recipients again.'], 400);
    }
    $audienceLabel = count($recipientIds) . ' individually selected student(s)';
} elseif ($mode === 'group') {
    $program = trim((string) ($input['program_label'] ?? ''));
    if (mb_strlen($program) > 80) {
        json_response(['ok' => false, 'error' => 'The selected program is invalid.'], 400);
    }
    $rawYear = trim((string) ($input['year_level'] ?? ''));
    $year = null;
    if ($rawYear !== '') {
        if (!in_array($rawYear, ['1', '2', '3', '4'], true)) {
            json_response(['ok' => false, 'error' => 'Choose a valid year level.'], 400);
        }
        $year = (int) $rawYear;
    }
    $expectedTargets = filter_var($input['expected_targets'] ?? null, FILTER_VALIDATE_INT);
    if ($expectedTargets === false || $expectedTargets < 1 || $expectedTargets > $maxGroupRecipients) {
        json_response(['ok' => false, 'error' => 'Choose a group with 1 to ' . $maxGroupRecipients . ' active student accounts.'], 400);
    }

    try {
        $rosterRows = teacher_notification_roster_rows();
        $recipientIds = teacher_notification_group_recipients($rosterRows, $program, $year);
    } catch (Throwable $e) {
        error_log('teacher_notification_send: group audience resolution failed: ' . $e->getMessage());
        json_response(['ok' => false, 'error' => $e->getMessage()], 503);
    }
    if ($recipientIds === []) {
        json_response(['ok' => false, 'error' => 'No active student accounts match this group. Refresh the audience list and try again.'], 409);
    }
    if (count($recipientIds) > $maxGroupRecipients) {
        json_response(['ok' => false, 'error' => 'This group exceeds the safe limit of ' . $maxGroupRecipients . ' student accounts. Narrow it by program or year level.'], 400);
    }
    if (count($recipientIds) !== $expectedTargets) {
        json_response(['ok' => false, 'error' => 'The active group count changed from ' . $expectedTargets . ' to ' . count($recipientIds) . '. Refresh the count and confirm before sending.'], 409);
    }
    $programLabel = $program !== '' ? $program : 'All programs';
    $yearLabel = $year !== null ? ' · Year ' . $year : '';
    $audienceLabel = $programLabel . $yearLabel;
} elseif ($mode === 'event') {
    $eventId = trim((string) ($input['event_id'] ?? ''));
    if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/iD', $eventId) !== 1) {
        json_response(['ok' => false, 'error' => 'Choose a valid event.'], 400);
    }
    $expectedTargets = filter_var($input['expected_targets'] ?? null, FILTER_VALIDATE_INT);
    if ($expectedTargets === false || $expectedTargets < 1 || $expectedTargets > $maxGroupRecipients) {
        json_response(['ok' => false, 'error' => 'Choose an event with 1 to ' . $maxGroupRecipients . ' active app participants.'], 400);
    }
    try {
        $targetEvent = teacher_notification_event_recipients($eventId, trim((string) ($user['id'] ?? '')));
        $recipientIds = $targetEvent['recipient_ids'];
    } catch (Throwable $e) {
        error_log('teacher_notification_send: event participant resolution failed: ' . $e->getMessage());
        json_response(['ok' => false, 'error' => $e->getMessage()], 503);
    }
    if ($recipientIds === []) {
        json_response(['ok' => false, 'error' => 'This event has no active app participants to notify.'], 409);
    }
    if (count($recipientIds) > $maxGroupRecipients) {
        json_response(['ok' => false, 'error' => 'This event exceeds the safe limit of ' . $maxGroupRecipients . ' active app participants.'], 400);
    }
    if (count($recipientIds) !== $expectedTargets) {
        json_response(['ok' => false, 'error' => 'The event participant count changed from ' . $expectedTargets . ' to ' . count($recipientIds) . '. Refresh the count and confirm before sending.'], 409);
    }
    $audienceLabel = 'Event: ' . $targetEvent['title'];
} else {
    json_response(['ok' => false, 'error' => 'Choose individual or group recipients.'], 400);
}

$teacherId = trim((string) ($user['id'] ?? ''));
if ($teacherId === '' || !api_rate_limit_allow('teacher-notification-send:' . $teacherId, 10, 900)) {
    json_response(['ok' => false, 'error' => 'Send limit reached. Please wait before sending another notification.'], 429);
}

$headers = teacher_notification_supabase_headers();
@set_time_limit(180);
$broadcastId = bin2hex(random_bytes(16));
$notificationData = [
    'type' => 'teacher_broadcast',
    'broadcast_id' => $broadcastId,
    'sender_role' => 'teacher',
    'sender_id' => $teacherId,
    'audience' => $audienceLabel,
    'route' => 'notifications',
];
if (is_array($targetEvent)) {
    $notificationData['target_event_id'] = trim((string) ($input['event_id'] ?? ''));
    $notificationData['target_event_title'] = $targetEvent['title'];
}

persist_user_notifications($recipientIds, $title, $body, $notificationData);

// Confirm all inbox records exist before attempting device pushes.
$persistedIds = [];
foreach (array_chunk($recipientIds, 80) as $chunk) {
    $inList = '(' . implode(',', $chunk) . ')';
    $url = rtrim(SUPABASE_URL, '/') . '/rest/v1/user_notifications'
        . '?select=user_id&user_id=in.' . rawurlencode($inList)
        . '&' . rawurlencode('data->>broadcast_id') . '=eq.' . rawurlencode($broadcastId);
    $res = supabase_request('GET', $url, $headers);
    if (!($res['ok'] ?? false)) {
        error_log('teacher_notification_send: inbox verification failed: ' . substr((string) ($res['body'] ?? ''), 0, 300));
        json_response(['ok' => false, 'error' => 'The inbox save could not be verified, so no phone push was sent. Please refresh the app inbox before retrying.'], 503);
    }
    $rows = json_decode((string) ($res['body'] ?? ''), true);
    if (is_array($rows)) {
        foreach ($rows as $row) {
            if (is_array($row)) {
                $id = trim((string) ($row['user_id'] ?? ''));
                if ($id !== '') {
                    $persistedIds[$id] = true;
                }
            }
        }
    }
}

if (count($persistedIds) !== count($recipientIds)) {
    error_log('teacher_notification_send: only ' . count($persistedIds) . ' of ' . count($recipientIds) . ' inbox rows verified.');
    json_response(['ok' => false, 'error' => 'Not all app inbox entries could be verified. No phone push was sent; check the bell before retrying.'], 503);
}

$tokens = [];
$tokenLookupFailed = false;
foreach (array_chunk($recipientIds, 80) as $chunk) {
    $inList = '(' . implode(',', $chunk) . ')';
    $url = rtrim(SUPABASE_URL, '/') . '/rest/v1/fcm_tokens?select=token&user_id=in.' . rawurlencode($inList);
    $res = supabase_request('GET', $url, $headers);
    if (!($res['ok'] ?? false)) {
        $tokenLookupFailed = true;
        error_log('teacher_notification_send: FCM token lookup failed: ' . substr((string) ($res['body'] ?? ''), 0, 300));
        continue;
    }
    $rows = json_decode((string) ($res['body'] ?? ''), true);
    if (!is_array($rows)) {
        continue;
    }
    foreach ($rows as $row) {
        if (is_array($row)) {
            $token = trim((string) ($row['token'] ?? ''));
            if ($token !== '') {
                $tokens[$token] = true;
            }
        }
    }
}

$fcmResult = ['sent' => 0, 'failed' => 0, 'error' => null];
if ($tokens !== []) {
    try {
        $fcmResult = send_fcm_notification_detailed(array_keys($tokens), $title, $body, $notificationData);
    } catch (Throwable $e) {
        error_log('teacher_notification_send: FCM exception: ' . $e->getMessage());
        $fcmResult['failed'] = count($tokens);
        $fcmResult['error'] = 'push_send_failed';
    }
}

json_response([
    'ok' => true,
    'broadcast_id' => $broadcastId,
    'targets' => count($recipientIds),
    'audience' => $audienceLabel,
    'in_app_saved' => count($persistedIds),
    'device_tokens' => count($tokens),
    'push_sent' => (int) ($fcmResult['sent'] ?? 0),
    'push_failed' => (int) ($fcmResult['failed'] ?? 0),
    'push_skipped' => $tokens === [],
    'push_lookup_partial' => $tokenLookupFailed,
    'push_error' => (string) ($fcmResult['error'] ?? ''),
]);
