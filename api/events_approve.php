<?php
declare(strict_types=1);

session_start();

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/supabase.php';
require_once __DIR__ . '/../includes/json.php';
require_once __DIR__ . '/../includes/csrf.php';

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
        . '&select=id,status,title,created_by,description,event_for,start_at,end_at,location,allow_registration,proposal_stage,requirements_requested_at,requirements_submitted_at'
        . '&limit=1';
    $res = supabase_request('GET', $url, $headers);
    if (!$res['ok']) {
        $message = strtolower((string) ($res['body'] ?? '') . ' ' . (string) ($res['error'] ?? ''));
        if ((str_contains($message, 'allow_registration') || str_contains($message, 'proposal_stage') || str_contains($message, 'requirements_requested_at') || str_contains($message, 'requirements_submitted_at'))
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

function extract_section_name(mixed $rawSections): string
{
    if (is_array($rawSections)) {
        if (isset($rawSections['name'])) {
            return trim((string) $rawSections['name']);
        }

        if (isset($rawSections[0]) && is_array($rawSections[0])) {
            return trim((string) ($rawSections[0]['name'] ?? ''));
        }
    }

    return '';
}

function normalize_student_course_code(array $row): string
{
    $rawCourse = strtoupper(trim((string) ($row['course'] ?? '')));
    if (in_array($rawCourse, ['IT', 'BSIT'], true)) {
        return 'BSIT';
    }
    if (in_array($rawCourse, ['CS', 'BSCS'], true)) {
        return 'BSCS';
    }

    $sectionName = strtoupper(extract_section_name($row['sections'] ?? null));
    if (str_starts_with($sectionName, 'BSIT')) {
        return 'BSIT';
    }
    if (str_starts_with($sectionName, 'BSCS')) {
        return 'BSCS';
    }

    return '';
}

function extract_student_year_level(array $row): string
{
    $sectionName = trim(extract_section_name($row['sections'] ?? null));
    if ($sectionName === '') {
        return '';
    }

    if (preg_match('/\b([1-4])\b/', $sectionName, $matches)) {
        return (string) $matches[1];
    }

    // Common section formats like "BSIT SD 1B" / "BSCS-2A".
    if (preg_match('/([1-4])[A-Z]\b/i', $sectionName, $matches)) {
        return (string) $matches[1];
    }

    if (preg_match('/-([1-4])[A-Z]?$/i', $sectionName, $matches)) {
        return (string) $matches[1];
    }

    return '';
}

function student_matches_event_target(array $row, string $eventFor): bool
{
    $normalizedTarget = strtoupper(trim($eventFor));
    if ($normalizedTarget === '' || $normalizedTarget === 'ALL') {
        return true;
    }

    $studentCourse = normalize_student_course_code($row);
    $studentYear = extract_student_year_level($row);

    if (preg_match('/^(BSIT|BSCS)\s*-\s*([1-4])$/', $normalizedTarget, $matches)) {
        return $studentCourse === $matches[1] && $studentYear === $matches[2];
    }

    if (in_array($normalizedTarget, ['BSIT', 'BSCS'], true)) {
        return $studentCourse === $normalizedTarget;
    }

    if (in_array($normalizedTarget, ['1', '2', '3', '4'], true)) {
        return $studentYear === $normalizedTarget;
    }

    if (preg_match('/^COURSE\s*=\s*(ALL|BSIT|BSCS)\s*;\s*YEARS\s*=\s*([0-9,\sA-Z]+)$/', $normalizedTarget, $matches)) {
        $targetCourse = $matches[1];
        $rawYears = preg_split('/\s*,\s*/', trim($matches[2])) ?: [];

        $targetYears = [];
        foreach ($rawYears as $rawYear) {
            $candidate = strtoupper(trim((string) $rawYear));
            if ($candidate === 'ALL') {
                $targetYears = ['ALL'];
                break;
            }
            if (in_array($candidate, ['1', '2', '3', '4'], true)) {
                $targetYears[$candidate] = true;
            }
        }

        if (empty($targetYears)) {
            $targetYears = ['ALL'];
        } elseif (!array_is_list($targetYears)) {
            $targetYears = array_keys($targetYears);
        }

        $courseMatches = $targetCourse === 'ALL'
            ? true
            : ($studentCourse !== '' && $studentCourse === $targetCourse);
        if (!$courseMatches) {
            return false;
        }

        if (count($targetYears) === 1 && $targetYears[0] === 'ALL') {
            return true;
        }

        return $studentYear !== '' && in_array($studentYear, $targetYears, true);
    }

    return false;
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
    if (empty($userIds)) {
        return;
    }

    require_once __DIR__ . '/../includes/fcm.php';

    $inList = '(' . implode(',', array_map('rawurlencode', $userIds)) . ')';
    $tokensRes = supabase_request('GET',
        rtrim(SUPABASE_URL, '/') . '/rest/v1/fcm_tokens?select=token&user_id=in.' . $inList,
        ['apikey: ' . SUPABASE_KEY, 'Authorization: Bearer ' . SUPABASE_KEY]
    );

    if (!$tokensRes['ok']) {
        return;
    }

    $tokenRows = json_decode((string) $tokensRes['body'], true);
    $tokens = [];
    if (is_array($tokenRows)) {
        foreach ($tokenRows as $row) {
            $token = trim((string) ($row['token'] ?? ''));
            if ($token !== '') {
                $tokens[$token] = true;
            }
        }
    }

    if (!empty($tokens)) {
        send_fcm_notification(array_keys($tokens), $title, $body, $data);
    }
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
    $payload['allow_registration'] = false;
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
$event = is_array($rows) && isset($rows[0]) ? $rows[0] : null;

$notifyTeacher = false;
if ($status === 'approved') {
    $notifyTeacher = true;
} elseif (in_array($status, ['draft', 'archived'], true) && $rejectionReason !== '') {
    $notifyTeacher = true;
}

if ($event && $notifyTeacher) {
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

if ($event && $initialPublishFlow && !empty($validTeacherIds)) {
    $eventTitle = (string) ($event['title'] ?? 'Event');
    $body = 'You have been assigned to "' . $eventTitle . '".';
    send_notification_to_users($validTeacherIds, 'Assigned to Event', $body, [
        'event_id' => $eventId,
        'type' => 'teacher_event_assigned',
    ]);
}

if ($event && in_array($status, ['published', 'draft'], true)) {
    $eventFor = (string) ($event['event_for'] ?? 'All');

    // Fetch all students with their section name and course
    $usersUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/users?select=id,course,sections(name)&role=eq.student';

    $usersRes = supabase_request('GET', $usersUrl, [
        'apikey: ' . SUPABASE_KEY,
        'Authorization: Bearer ' . SUPABASE_KEY,
    ]);

    $targetUserIds = [];
    if ($usersRes['ok']) {
        $userRows = json_decode((string) $usersRes['body'], true);
        if (is_array($userRows)) {
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
        }
    }

    if (!empty($targetUserIds)) {
        $eventTitle = (string) ($event['title'] ?? 'Event');
        $notifTitle = '';
        $notifBody = '';
        $notifType = '';

        if ($status === 'published' && in_array($previousStatus, ['approved', 'pending', 'draft'], true)) {
            $notifTitle = 'New Event Published';
            $notifBody = '"' . $eventTitle . '" has been published. Registration opens once the organizer enables it.';
            $notifType = 'event_published';
        }

        if ($notifTitle !== '' && $notifType !== '') {
            send_notification_to_users(array_keys($targetUserIds), $notifTitle, $notifBody, [
                'event_id' => $eventId,
                'type' => $notifType,
            ]);
        }
    }
}

// Seed default "common questions" on initial publish so evaluation isn't blank.
if ($event && $initialPublishFlow) {
    evaluation_seed_event_questions_if_missing($eventId, $headers);
    evaluation_seed_session_questions_if_missing($eventId, $headers);
}

json_response(['ok' => true, 'event' => $event], 200);
