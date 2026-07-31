<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/session.php';
session_bootstrap();

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/supabase.php';
require_once __DIR__ . '/../includes/json.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/registration_access.php';

$user = require_role(['admin']);
$data = require_post_json();
require_csrf_from_json($data);

function normalize_id_list(mixed $raw): array
{
    if (!is_array($raw)) {
        return [];
    }

    $unique = [];
    foreach ($raw as $value) {
        $id = trim((string) $value);
        if ($id !== '') {
            $unique[$id] = true;
        }
    }

    return array_keys($unique);
}

function evaluation_seed_event_questions_if_missing(string $eventId, array $headers): void
{
    if ($eventId === '') {
        return;
    }

    // Prevent duplicates: seed only when there are currently no event-level questions.
    $checkUrl = rtrim(SUPABASE_URL, '/')
        . '/rest/v1/evaluation_questions?select=id,event_id'
        . '&event_id=eq.' . rawurlencode($eventId)
        . '&limit=1';
    $checkRes = supabase_request('GET', $checkUrl, $headers);
    if (!$checkRes['ok']) {
        return;
    }

    $rows = json_decode((string) ($checkRes['body'] ?? ''), true);
    if (is_array($rows) && count($rows) > 0) {
        return;
    }

    $commonQuestions = [
        [
            'question_text' => 'How would you rate this event overall? (1-5)',
            'field_type' => 'rating',
            'required' => true,
            'sort_order' => 1,
        ],
        [
            'question_text' => 'What did you like most about this event?',
            'field_type' => 'text',
            'required' => false,
            'sort_order' => 2,
        ],
        [
            'question_text' => 'Any suggestions to improve for next time?',
            'field_type' => 'text',
            'required' => false,
            'sort_order' => 3,
        ],
    ];

    $payloads = [];
    foreach ($commonQuestions as $q) {
        $payloads[] = [
            'event_id' => $eventId,
            'question_text' => (string) ($q['question_text'] ?? ''),
            'field_type' => (string) ($q['field_type'] ?? 'text'),
            'required' => !empty($q['required']),
            'sort_order' => isset($q['sort_order']) ? max(0, (int) $q['sort_order']) : 0,
        ];
    }

    $insertUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/evaluation_questions';
    $insertHeaders = $headers;
    $insertHeaders[] = 'Prefer: return=minimal';

    // Best-effort: if seed fails (table not ready, RLS, etc), don't block publishing.
    $insertRes = supabase_request('POST', $insertUrl, $insertHeaders, json_encode($payloads, JSON_UNESCAPED_SLASHES));
    if (!$insertRes['ok']) {
        return;
    }
}

function evaluation_seed_session_questions_if_missing(string $eventId, array $headers): void
{
    if ($eventId === '') {
        return;
    }

    // Fetch all sessions for this event.
    $sessionsUrl = rtrim(SUPABASE_URL, '/')
        . '/rest/v1/event_sessions?select=id,event_id,title,topic'
        . '&event_id=eq.' . rawurlencode($eventId);
    $sessionsRes = supabase_request('GET', $sessionsUrl, $headers);
    if (!$sessionsRes['ok']) {
        return;
    }

    $sessionRows = json_decode((string) ($sessionsRes['body'] ?? ''), true);
    if (!is_array($sessionRows) || count($sessionRows) === 0) {
        return;
    }

    $baseQuestions = [
        [
            'question_text' => 'How would you rate this seminar overall? (1-5)',
            'field_type' => 'rating',
            'required' => true,
            'sort_order' => 1,
        ],
        [
            'question_text' => 'What did you learn most from this seminar?',
            'field_type' => 'text',
            'required' => false,
            'sort_order' => 2,
        ],
        [
            'question_text' => 'Any suggestions to improve this seminar?',
            'field_type' => 'text',
            'required' => false,
            'sort_order' => 3,
        ],
    ];

    $insertUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/event_session_evaluation_questions';
    $baseHeaders = $headers;
    $baseHeaders[] = 'Prefer: return=minimal';

    foreach ($sessionRows as $session) {
        if (!is_array($session)) {
            continue;
        }

        $sessionId = trim((string) ($session['id'] ?? ''));
        if ($sessionId === '') {
            continue;
        }

        // Skip if this session already has questions.
        $checkUrl = rtrim(SUPABASE_URL, '/')
            . '/rest/v1/event_session_evaluation_questions?select=id,session_id'
            . '&session_id=eq.' . rawurlencode($sessionId)
            . '&limit=1';
        $checkRes = supabase_request('GET', $checkUrl, $headers);
        if (!$checkRes['ok']) {
            continue;
        }

        $existing = json_decode((string) ($checkRes['body'] ?? ''), true);
        if (is_array($existing) && count($existing) > 0) {
            continue;
        }

        $payloads = [];
        foreach ($baseQuestions as $q) {
            $payloads[] = [
                'session_id' => $sessionId,
                'question_text' => (string) ($q['question_text'] ?? ''),
                'field_type' => (string) ($q['field_type'] ?? 'text'),
                'required' => !empty($q['required']),
                'sort_order' => isset($q['sort_order']) ? max(0, (int) $q['sort_order']) : 0,
            ];
        }

        $insertRes = supabase_request('POST', $insertUrl, $baseHeaders, json_encode($payloads, JSON_UNESCAPED_SLASHES));
        if (!$insertRes['ok']) {
            // Best-effort; continue seeding other sessions.
            continue;
        }
    }
}

function fetch_event_for_approval(string $eventId, array $headers): ?array
{
    $supportsProposalStage = true;
    $url = rtrim(SUPABASE_URL, '/') . '/rest/v1/events'
        . '?id=eq.' . rawurlencode($eventId)
        . '&select=id,status,title,created_by,description,event_for,start_at,end_at,location,cover_image_url,event_type,allow_registration,is_free_event,registration_limit,proposal_stage,requirements_requested_at,requirements_submitted_at'
        . '&limit=1';
    $res = supabase_request('GET', $url, $headers);
    if (!$res['ok']) {
        $message = strtolower((string) ($res['body'] ?? '') . ' ' . (string) ($res['error'] ?? ''));
        if ((str_contains($message, 'allow_registration') || str_contains($message, 'proposal_stage') || str_contains($message, 'requirements_requested_at') || str_contains($message, 'requirements_submitted_at') || str_contains($message, 'is_free_event') || str_contains($message, 'registration_limit') || str_contains($message, 'cover_image_url') || str_contains($message, 'event_type'))
            && (str_contains($message, 'column') || str_contains($message, 'does not exist') || str_contains($message, 'schema cache'))) {
            $supportsProposalStage = false;
            $fallbackUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/events'
                . '?id=eq.' . rawurlencode($eventId)
                . '&select=id,status,title,created_by,description,event_for,start_at,end_at,location'
                . '&limit=1';
            $res = supabase_request('GET', $fallbackUrl, $headers);
        }
    }
    $rows = $res['ok'] ? json_decode((string) $res['body'], true) : [];
    if (!is_array($rows) || !isset($rows[0]) || !is_array($rows[0])) {
        return null;
    }

    $event = $rows[0];
    if (!array_key_exists('allow_registration', $event)) {
        $event['allow_registration'] = false;
    }
    if (!array_key_exists('is_free_event', $event)) {
        $event['is_free_event'] = true;
    }
    if (!array_key_exists('proposal_stage', $event)) {
        $event['proposal_stage'] = null;
    }
    $event['proposal_stage_supported'] = $supportsProposalStage;
    return $event;
}

function validate_teacher_ids(array $teacherIds, array $headers): array
{
    if (empty($teacherIds)) {
        return [];
    }

    $inList = '(' . implode(',', array_map('rawurlencode', $teacherIds)) . ')';
    $url = rtrim(SUPABASE_URL, '/') . '/rest/v1/users'
        . '?select=id'
        . '&role=eq.teacher'
        . '&id=in.' . $inList;

    $res = supabase_request('GET', $url, $headers);
    $rows = $res['ok'] ? json_decode((string) $res['body'], true) : [];
    if (!is_array($rows)) {
        return [];
    }

    $valid = [];
    foreach ($rows as $row) {
        $id = trim((string) ($row['id'] ?? ''));
        if ($id !== '') {
            $valid[$id] = true;
        }
    }

    return array_keys($valid);
}

function sync_event_teacher_membership(string $eventId, array $teacherIds, string $adminId, array $headers): array
{
    $readUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/event_teacher_assignments'
        . '?select=teacher_id'
        . '&event_id=eq.' . rawurlencode($eventId);
    $currentRes = supabase_request('GET', $readUrl, $headers);
    $currentRows = $currentRes['ok'] ? json_decode((string) $currentRes['body'], true) : [];
    if (!$currentRes['ok']) {
        return [
            'ok' => false,
            'errors' => [build_error($currentRes['body'] ?? null, (int) ($currentRes['status'] ?? 0), $currentRes['error'] ?? null, 'Failed to load current event teachers')],
            'added' => [],
        ];
    }

    $currentIds = [];
    if (is_array($currentRows)) {
        foreach ($currentRows as $row) {
            $teacherId = trim((string) ($row['teacher_id'] ?? ''));
            if ($teacherId !== '') {
                $currentIds[$teacherId] = true;
            }
        }
    }

    $selectedIds = [];
    foreach ($teacherIds as $teacherId) {
        $selectedIds[$teacherId] = true;
    }

    $toAdd = array_values(array_diff(array_keys($selectedIds), array_keys($currentIds)));
    $toRemove = array_values(array_diff(array_keys($currentIds), array_keys($selectedIds)));
    $errors = [];

    $writeHeaders = [
        'Accept: application/json',
        'Content-Type: application/json',
        'Prefer: return=representation',
        'apikey: ' . SUPABASE_KEY,
        'Authorization: Bearer ' . SUPABASE_KEY,
    ];

    foreach ($teacherIds as $teacherId) {
        $payload = json_encode([
            'event_id' => $eventId,
            'teacher_id' => $teacherId,
            'can_scan' => false,
            'can_manage_assistants' => false,
            'assigned_by' => $adminId,
            'assigned_at' => gmdate('c'),
        ], JSON_UNESCAPED_SLASHES);

        if (!is_string($payload)) {
            $errors[] = 'Failed to prepare teacher assignment payload.';
            continue;
        }

        $url = rtrim(SUPABASE_URL, '/') . '/rest/v1/event_teacher_assignments?on_conflict=event_id,teacher_id';
        $res = supabase_request('POST', $url, $writeHeaders, $payload);
        if (!$res['ok']) {
            $errors[] = build_error($res['body'] ?? null, (int) ($res['status'] ?? 0), $res['error'] ?? null, 'Failed to save event teacher');
        }
    }

    foreach ($toRemove as $teacherId) {
        $url = rtrim(SUPABASE_URL, '/') . '/rest/v1/event_teacher_assignments'
            . '?event_id=eq.' . rawurlencode($eventId)
            . '&teacher_id=eq.' . rawurlencode($teacherId);
        $res = supabase_request('DELETE', $url, $headers);
        if (!$res['ok']) {
            $errors[] = build_error($res['body'] ?? null, (int) ($res['status'] ?? 0), $res['error'] ?? null, 'Failed to remove old event teacher');
        }
    }

    return [
        'ok' => empty($errors),
        'errors' => array_values(array_unique($errors)),
        'added' => $toAdd,
    ];
}

function send_notification_to_users(array $userIds, string $title, string $body, array $data = []): void
{
    require_once __DIR__ . '/../includes/user_notifications.php';
    dispatch_user_notifications($userIds, $title, $body, $data);
}

$eventId = isset($data['event_id']) ? trim((string) $data['event_id']) : '';
$status = isset($data['status']) ? trim((string) $data['status']) : 'approved';
$rejectionReason = isset($data['reason']) ? trim((string) $data['reason']) : '';
$teacherIds = normalize_id_list($data['teacher_ids'] ?? null);

if ($eventId === '') {
    json_response(['ok' => false, 'error' => 'event_id required'], 400);
}
if (!in_array($status, ['draft', 'pending', 'approved', 'published', 'closed', 'archived'], true)) {
    json_response(['ok' => false, 'error' => 'Invalid status'], 400);
}

$headers = [
    'Content-Type: application/json',
    'Accept: application/json',
    'apikey: ' . SUPABASE_KEY,
    'Authorization: Bearer ' . SUPABASE_KEY,
];

$existingEvent = fetch_event_for_approval($eventId, $headers);
if (!is_array($existingEvent)) {
    json_response(['ok' => false, 'error' => 'Event not found'], 404);
}

$previousStatus = (string) ($existingEvent['status'] ?? '');
$proposalStage = strtolower(trim((string) ($existingEvent['proposal_stage'] ?? 'pending_requirements')));
$supportsProposalStage = !empty($existingEvent['proposal_stage_supported']);
$initialPublishFlow = $status === 'published' && in_array($previousStatus, ['approved', 'pending'], true);
$validTeacherIds = [];

if ($status === 'approved' && $supportsProposalStage && $proposalStage !== 'under_review') {
    json_response([
        'ok' => false,
        'error' => 'Request and review the required proposal documents before approving this proposal.',
    ], 400);
}

if ($initialPublishFlow) {
    if (empty($teacherIds)) {
        json_response(['ok' => false, 'error' => 'Select at least one teacher before publishing this event.'], 400);
    }

    $validTeacherIds = validate_teacher_ids($teacherIds, $headers);
    if (count($validTeacherIds) !== count($teacherIds)) {
        json_response(['ok' => false, 'error' => 'One or more selected teachers are invalid. Refresh the page and try again.'], 400);
    }

    $syncResult = sync_event_teacher_membership(
        $eventId,
        $validTeacherIds,
        (string) ($user['id'] ?? ''),
        $headers
    );

    if (!($syncResult['ok'] ?? false)) {
        json_response([
            'ok' => false,
            'error' => implode(' ', array_values(array_unique($syncResult['errors'] ?? ['Failed to assign event teachers.']))),
        ], 500);
    }
}

$payload = [
    'status' => $status,
    'approved_by' => (string) ($user['id'] ?? ''),
    'updated_at' => gmdate('c'),
];

if ($status === 'approved' && $supportsProposalStage) {
    $payload['proposal_stage'] = 'approved';
}

if ($status === 'published' && $previousStatus !== 'published') {
    $payload['allow_registration'] = normalize_registration_bool($existingEvent['is_free_event'] ?? true);
}

if (in_array($status, ['draft', 'archived'], true) && $rejectionReason !== '') {
    $cleanDesc = (string) ($existingEvent['description'] ?? '');
    $cleanDesc = preg_replace('/\[REJECT_REASON:.*?\]\s*/s', '', $cleanDesc);
    $payload['description'] = '[REJECT_REASON: ' . $rejectionReason . '] ' . $cleanDesc;
}

$updateHeaders = $headers;
$updateHeaders[] = 'Prefer: return=representation';
$updateUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/events?id=eq.' . rawurlencode($eventId) . '&select=id,status,title,created_by,description,event_for,start_at,end_at,location,allow_registration,proposal_stage';
$res = supabase_request('PATCH', $updateUrl, $updateHeaders, json_encode($payload, JSON_UNESCAPED_SLASHES));
if (!$res['ok']) {
    $message = strtolower((string) ($res['body'] ?? '') . ' ' . (string) ($res['error'] ?? ''));
    if ((str_contains($message, 'allow_registration') || str_contains($message, 'proposal_stage'))
        && (str_contains($message, 'column') || str_contains($message, 'does not exist') || str_contains($message, 'schema cache'))) {
        $retryPayload = $payload;
        unset($retryPayload['allow_registration']);
        unset($retryPayload['proposal_stage']);
        $fallbackUpdateUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/events?id=eq.' . rawurlencode($eventId) . '&select=id,status,title,created_by,description,event_for,start_at,end_at,location';
        $res = supabase_request('PATCH', $fallbackUpdateUrl, $updateHeaders, json_encode($retryPayload, JSON_UNESCAPED_SLASHES));
    }
}
if (!$res['ok']) {
    json_response([
        'ok' => false,
        'error' => build_error($res['body'] ?? null, (int) ($res['status'] ?? 0), $res['error'] ?? null, 'Approve failed'),
    ], 500);
}

$rows = json_decode((string) $res['body'], true);
$event = is_array($rows) && isset($rows[0]) && is_array($rows[0]) ? $rows[0] : null;
// Prefer return=representation can occasionally be empty even when PATCH succeeded.
// Never skip publish notifications because of that — fall back to the pre-update row.
if (!is_array($event) && is_array($existingEvent)) {
    $event = $existingEvent;
    $event['status'] = $status;
    $event['updated_at'] = gmdate('c');
}
$mergedEvent = is_array($event)
    ? array_merge(is_array($existingEvent) ? $existingEvent : [], $event)
    : null;
if (is_array($mergedEvent)) {
    $mergedEvent['status'] = $status;
    $mergedEvent['id'] = $eventId;
}

/**
 * Load student recipients for publish push (paginated; fail-open).
 *
 * @return list<string>
 */
$loadPublishStudentIds = static function (string $eventFor) use ($headers): array {
    $targetUserIds = [];
    $pageSize = 1000;
    $offset = 0;

    for ($page = 0; $page < 20; $page++) {
        $rangeEnd = $offset + $pageSize - 1;
        $pageHeaders = array_merge($headers, [
            'Range-Unit: items',
            'Range: ' . $offset . '-' . $rangeEnd,
            'Prefer: count=exact',
        ]);
        $usersUrl = rtrim(SUPABASE_URL, '/')
            . '/rest/v1/users?select=id,course,sections(name)&role=eq.student&order=id.asc';
        $usersRes = supabase_request('GET', $usersUrl, $pageHeaders);
        if (!($usersRes['ok'] ?? false)) {
            // Fallback without section embed if join fails.
            $fallbackUrl = rtrim(SUPABASE_URL, '/')
                . '/rest/v1/users?select=id,course&role=eq.student&order=id.asc';
            $usersRes = supabase_request('GET', $fallbackUrl, $pageHeaders);
            if (!($usersRes['ok'] ?? false)) {
                error_log('events_approve publish: failed to load students for FCM');
                break;
            }
        }

        $userRows = json_decode((string) ($usersRes['body'] ?? ''), true);
        if (!is_array($userRows) || $userRows === []) {
            break;
        }

        foreach ($userRows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = trim((string) ($row['id'] ?? ''));
            if ($id === '') {
                continue;
            }
            if (student_matches_event_target($row, $eventFor)) {
                $targetUserIds[$id] = true;
            }
        }

        if (count($userRows) < $pageSize) {
            break;
        }
        $offset += $pageSize;
    }

    return array_keys($targetUserIds);
};

// Student publish push FIRST — do not let catalog/Firestore delay or abort FCM.
$publishPush = [
    'attempted' => false,
    'targets' => 0,
    'tokens' => 0,
    'inbox' => false,
    'fcm_ok' => false,
    'error' => null,
    'event_for' => null,
];
if (is_array($mergedEvent)
    && $status === 'published'
    && in_array($previousStatus, ['approved', 'pending', 'draft', ''], true)
    && $previousStatus !== 'published'
) {
    try {
        @set_time_limit(180);
        require_once __DIR__ . '/../includes/event_targeting.php';
        require_once __DIR__ . '/../includes/user_notifications.php';

        $eventFor = (string) ($mergedEvent['event_for'] ?? 'All');
        $publishPush['attempted'] = true;
        $publishPush['event_for'] = $eventFor;
        $targetUserIds = [];
        try {
            $targetUserIds = $loadPublishStudentIds($eventFor);
        } catch (Throwable $e) {
            error_log('events_approve publish: load students exception: ' . $e->getMessage());
            $publishPush['error'] = 'load_students_failed';
        }

        error_log(
            'events_approve publish push: event=' . $eventId
            . ' previous=' . $previousStatus
            . ' targets=' . count($targetUserIds)
            . ' event_for=' . $eventFor
        );

        // Fail-open: if targeting matched nobody, still broadcast to all students.
        // Silent zero-target publishes are the #1 reason "push did not fire".
        if ($targetUserIds === []) {
            try {
                $allUrl = rtrim(SUPABASE_URL, '/')
                    . '/rest/v1/users?select=id&role=eq.student&order=id.asc&limit=5000';
                $allRes = supabase_request('GET', $allUrl, $headers);
                $allRows = json_decode((string) ($allRes['body'] ?? ''), true);
                if (is_array($allRows)) {
                    foreach ($allRows as $row) {
                        if (!is_array($row)) {
                            continue;
                        }
                        $id = trim((string) ($row['id'] ?? ''));
                        if ($id !== '') {
                            $targetUserIds[] = $id;
                        }
                    }
                }
                error_log('events_approve publish push: zero-match fallback targets=' . count($targetUserIds));
                if ($targetUserIds !== []) {
                    $publishPush['error'] = 'used_all_students_fallback';
                }
            } catch (Throwable $e) {
                error_log('events_approve publish ALL fallback exception: ' . $e->getMessage());
            }
        }

        if ($targetUserIds !== []) {
            $eventTitle = (string) ($mergedEvent['title'] ?? 'Event');
            $isFreeEvent = normalize_registration_bool($mergedEvent['is_free_event'] ?? true);
            $notifBody = $isFreeEvent
                ? '"' . $eventTitle . '" has been published. Registration is now open — you can register now.'
                : '"' . $eventTitle . '" has been published. Payment approval is required before you can register.';
            try {
                $dispatch = dispatch_user_notifications_detailed(
                    $targetUserIds,
                    'New Event Published',
                    $notifBody,
                    [
                        'event_id' => $eventId,
                        'type' => 'event_published',
                    ]
                );
                $publishPush['targets'] = (int) ($dispatch['targets'] ?? 0);
                $publishPush['tokens'] = (int) ($dispatch['tokens'] ?? 0);
                $publishPush['inbox'] = !empty($dispatch['inbox']);
                $publishPush['fcm_ok'] = !empty($dispatch['fcm_ok']);
                $publishPush['detail'] = $dispatch['detail'] ?? null;
                $publishPush['http_status'] = $dispatch['http_status'] ?? null;
                if (!empty($dispatch['error']) && ($publishPush['error'] === null || $publishPush['error'] === 'used_all_students_fallback')) {
                    // Keep fallback note if present; otherwise record dispatch error.
                    if ($publishPush['error'] === null) {
                        $publishPush['error'] = (string) $dispatch['error'];
                    } elseif (!$publishPush['fcm_ok']) {
                        $publishPush['error'] = $publishPush['error'] . '+' . (string) $dispatch['error'];
                    }
                }
            } catch (Throwable $e) {
                error_log('events_approve publish FCM exception: ' . $e->getMessage());
                $publishPush['error'] = 'exception:' . $e->getMessage();
            }
            error_log(
                'events_approve publish push result: fcm=' . ($publishPush['fcm_ok'] ? 'ok' : 'fail')
                . ' tokens=' . $publishPush['tokens']
                . ' targets=' . $publishPush['targets']
                . ' err=' . (string) ($publishPush['error'] ?? '')
            );
        } else {
            $publishPush['error'] = 'no_students_in_database';
            error_log('events_approve publish push skipped: no students found');
        }
    } catch (Throwable $e) {
        error_log('events_approve publish push block exception: ' . $e->getMessage());
        $publishPush['error'] = 'block_exception:' . $e->getMessage();
    }
}

// Catalog sync AFTER push so Firestore slowness cannot block notifications.
if (is_array($mergedEvent)) {
    try {
        require_once __DIR__ . '/../includes/firestore_catalog.php';
        firestore_catalog_sync_event($mergedEvent);
    } catch (Throwable $e) {
        error_log('events_approve catalog sync exception: ' . $e->getMessage());
    }
}
require_once __DIR__ . '/../includes/api_cache.php';
api_cache_bump_generation('manage_events');

$notifyTeacher = false;
if ($status === 'approved') {
    $notifyTeacher = true;
} elseif (in_array($status, ['draft', 'archived'], true) && $rejectionReason !== '') {
    $notifyTeacher = true;
}

if (is_array($event) && $notifyTeacher) {
    $teacherId = trim((string) ($event['created_by'] ?? ''));
    if ($teacherId !== '') {
        $eventTitle = (string) ($event['title'] ?? 'your event proposal');
        if ($status === 'approved') {
            $notifTitle = 'Proposal Approved';
            $notifBody = 'Great news! Your event "' . $eventTitle . '" has been approved by the admin.';
        } else {
            $notifTitle = 'Proposal Review Required';
            $notifBody = 'The admin has requested changes for "' . $eventTitle . '".';
            if ($rejectionReason !== '') {
                $notifBody .= ' Reason: ' . $rejectionReason;
            }
        }

        send_notification_to_users([$teacherId], $notifTitle, $notifBody, [
            'event_id' => $eventId,
            'type' => 'proposal_update',
        ]);
    }
}

if (is_array($event) && $initialPublishFlow && !empty($validTeacherIds)) {
    $eventTitle = (string) ($event['title'] ?? 'Event');
    $body = 'You have been assigned to "' . $eventTitle . '".';
    send_notification_to_users($validTeacherIds, 'Assigned to Event', $body, [
        'event_id' => $eventId,
        'type' => 'teacher_event_assigned',
    ]);
}

// Seed default "common questions" on initial publish so evaluation isn't blank.
if (is_array($event) && $initialPublishFlow) {
    evaluation_seed_event_questions_if_missing($eventId, $headers);
    evaluation_seed_session_questions_if_missing($eventId, $headers);
}

json_response([
    'ok' => true,
    'event' => $event,
    'push' => $publishPush,
], 200);
