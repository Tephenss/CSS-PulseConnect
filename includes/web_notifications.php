<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/supabase.php';
require_once __DIR__ . '/helpers.php';

function web_notification_headers(): array
{
    return [
        'Accept: application/json',
        'apikey: ' . SUPABASE_KEY,
        'Authorization: Bearer ' . SUPABASE_KEY,
    ];
}

function web_notification_event_map(array $eventIds, array $headers): array
{
    $eventIds = array_values(array_filter(array_unique(array_map(
        static fn($id): string => trim((string) $id),
        $eventIds
    ))));

    if ($eventIds === []) {
        return [];
    }

    $inList = '(' . implode(',', array_map('rawurlencode', $eventIds)) . ')';
    $url = rtrim(SUPABASE_URL, '/') . '/rest/v1/events'
        . '?select=id,title,start_at,status,updated_at'
        . '&id=in.' . $inList;

    $res = supabase_request('GET', $url, $headers);
    if (!$res['ok']) {
        return [];
    }

    $rows = json_decode((string) $res['body'], true);
    if (!is_array($rows)) {
        return [];
    }

    $map = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $eventId = trim((string) ($row['id'] ?? ''));
        if ($eventId === '') {
            continue;
        }
        $map[$eventId] = $row;
    }

    return $map;
}

function web_notification_extract_reject_reason(string $description): string
{
    if (preg_match('/\[REJECT_REASON:\s*(.*?)\]\s*/s', $description, $matches) !== 1) {
        return '';
    }

    return trim((string) ($matches[1] ?? ''));
}

function web_notification_hash_id(string $prefix, string $seed): string
{
    return $prefix . '-' . substr(sha1($seed), 0, 16);
}

function web_fetch_admin_notifications(array $headers): array
{
    $notifications = [];

    $eventsUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/events'
        . '?select=id,title,created_at,created_by,status,proposal_stage,requirements_requested_at,requirements_submitted_at,updated_at'
        . '&status=eq.pending'
        . '&order=created_at.desc'
        . '&limit=25';
    $eventsRes = supabase_request('GET', $eventsUrl, $headers);
    $events = $eventsRes['ok'] ? json_decode((string) $eventsRes['body'], true) : [];
    $events = is_array($events) ? $events : [];

    $creatorMap = [];
    $creatorIds = array_values(array_filter(array_unique(array_map(
        static fn($row): string => trim((string) (($row['created_by'] ?? ''))),
        $events
    ))));

    if ($creatorIds !== []) {
        $inList = '(' . implode(',', array_map('rawurlencode', $creatorIds)) . ')';
        $userUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/users?select=id,full_name&id=in.' . $inList;
        $userRes = supabase_request('GET', $userUrl, $headers);
        $userRows = $userRes['ok'] ? json_decode((string) $userRes['body'], true) : [];
        if (is_array($userRows)) {
            foreach ($userRows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $id = trim((string) ($row['id'] ?? ''));
                if ($id !== '') {
                    $creatorMap[$id] = trim((string) ($row['full_name'] ?? ''));
                }
            }
        }
    }

    foreach ($events as $event) {
        if (!is_array($event)) {
            continue;
        }
        $eventId = trim((string) ($event['id'] ?? ''));
        if ($eventId === '') {
            continue;
        }
        $title = trim((string) ($event['title'] ?? 'Event'));
        $creatorId = trim((string) ($event['created_by'] ?? ''));
        $creatorName = $creatorMap[$creatorId] ?? 'A teacher';
        $createdAt = trim((string) ($event['created_at'] ?? gmdate('c')));
        $proposalStage = strtolower(trim((string) ($event['proposal_stage'] ?? 'pending_requirements')));
        $requirementsSubmittedAt = trim((string) ($event['requirements_submitted_at'] ?? ''));

        if ($proposalStage === 'under_review') {
            $notifications[] = [
                'id' => web_notification_hash_id('admin-proposal-review', $eventId . '|' . ($requirementsSubmittedAt !== '' ? $requirementsSubmittedAt : $createdAt)),
                'title' => 'Proposal Documents Submitted',
                'description' => $creatorName . ' completed the requested documents for "' . $title . '". Review the uploads and approve when ready.',
                'created_at' => $requirementsSubmittedAt !== '' ? $requirementsSubmittedAt : $createdAt,
                'link' => '/manage_events.php',
                'kind' => 'proposal-review',
            ];
            continue;
        }

        $notifications[] = [
            'id' => web_notification_hash_id('admin-proposal', $eventId . '|' . $createdAt),
            'title' => 'New Event Proposal',
            'description' => $creatorName . ' submitted "' . $title . '" for review.',
            'created_at' => $createdAt,
            'link' => '/manage_events.php',
            'kind' => 'proposal',
        ];
    }

    $applicationsUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/' . SUPABASE_TABLE_USERS
        . '?select=id,first_name,middle_name,last_name,suffix,student_id,created_at,course'
        . '&role=eq.student'
        . '&registration_source=eq.app'
        . '&account_status=eq.pending'
        . '&order=created_at.desc'
        . '&limit=25';
    $applicationsRes = supabase_request('GET', $applicationsUrl, $headers);
    $applications = $applicationsRes['ok'] ? json_decode((string) $applicationsRes['body'], true) : [];
    $applications = is_array($applications) ? $applications : [];

    foreach ($applications as $student) {
        if (!is_array($student)) {
            continue;
        }
        $studentId = trim((string) ($student['id'] ?? ''));
        if ($studentId === '') {
            continue;
        }
        $createdAt = trim((string) ($student['created_at'] ?? gmdate('c')));
        $displayName = build_display_name(
            (string) ($student['first_name'] ?? ''),
            (string) ($student['middle_name'] ?? ''),
            (string) ($student['last_name'] ?? ''),
            (string) ($student['suffix'] ?? '')
        );
        if ($displayName === '') {
            $displayName = 'A student';
        }
        $course = strtoupper(trim((string) ($student['course'] ?? '')));
        $studentNumber = trim((string) ($student['student_id'] ?? ''));
        $descriptor = $course !== '' ? $course : 'student';

        $description = $displayName . ' submitted a new mobile app registration request';
        if ($studentNumber !== '') {
            $description .= ' (' . $studentNumber . ')';
        }
        $description .= ' for ' . $descriptor . '.';

        $notifications[] = [
            'id' => web_notification_hash_id('admin-application', $studentId . '|' . $createdAt),
            'title' => 'New App Registration',
            'description' => $description,
            'created_at' => $createdAt,
            'link' => '/manage_applications.php',
            'kind' => 'application',
        ];
    }

    return $notifications;
}

function web_fetch_teacher_notifications(array $user, array $headers): array
{
    $notifications = [];
    $teacherId = trim((string) ($user['id'] ?? ''));
    if ($teacherId === '') {
        return [];
    }

    $proposalUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/events'
        . '?select=id,title,status,description,updated_at,proposal_stage,requirements_requested_at,requirements_submitted_at'
        . '&created_by=eq.' . rawurlencode($teacherId)
        . '&status=in.(pending,approved,published,draft,archived)'
        . '&order=updated_at.desc'
        . '&limit=25';
    $proposalRes = supabase_request('GET', $proposalUrl, $headers);
    $events = $proposalRes['ok'] ? json_decode((string) $proposalRes['body'], true) : [];
    $events = is_array($events) ? $events : [];

    foreach ($events as $event) {
        if (!is_array($event)) {
            continue;
        }
        $eventId = trim((string) ($event['id'] ?? ''));
        $title = trim((string) ($event['title'] ?? 'Event'));
        $status = strtolower(trim((string) ($event['status'] ?? '')));
        $proposalStage = strtolower(trim((string) ($event['proposal_stage'] ?? 'pending_requirements')));
        $updatedAt = trim((string) ($event['updated_at'] ?? gmdate('c')));
        if ($eventId === '' || $updatedAt === '') {
            continue;
        }

        if ($status === 'pending' && $proposalStage === 'requirements_requested') {
            $notifications[] = [
                'id' => web_notification_hash_id('teacher-proposal-docs', $eventId . '|' . $updatedAt),
                'title' => 'Documents Requested',
                'description' => 'The admin requested proposal documents for "' . $title . '". Open the Approval tab to upload the required files.',
                'created_at' => trim((string) ($event['requirements_requested_at'] ?? $updatedAt)) ?: $updatedAt,
                'link' => '/manage_events.php',
                'kind' => 'proposal-documents',
            ];
            continue;
        }

        if ($status === 'pending' && $proposalStage === 'under_review') {
            $notifications[] = [
                'id' => web_notification_hash_id('teacher-proposal-under-review', $eventId . '|' . $updatedAt),
                'title' => 'Proposal Under Review',
                'description' => 'Your uploaded documents for "' . $title . '" are now waiting for final admin approval.',
                'created_at' => trim((string) ($event['requirements_submitted_at'] ?? $updatedAt)) ?: $updatedAt,
                'link' => '/manage_events.php',
                'kind' => 'proposal-under-review',
            ];
            continue;
        }

        if ($status === 'approved') {
            $notifications[] = [
                'id' => web_notification_hash_id('teacher-proposal-approved', $eventId . '|' . $updatedAt),
                'title' => 'Proposal Approved',
                'description' => 'Your event "' . $title . '" has been approved by the admin.',
                'created_at' => $updatedAt,
                'link' => '/event_view.php?id=' . rawurlencode($eventId),
                'kind' => 'proposal-approved',
            ];
            continue;
        }

        if ($status === 'published') {
            $notifications[] = [
                'id' => web_notification_hash_id('teacher-event-published', $eventId . '|' . $updatedAt),
                'title' => 'Event Published',
                'description' => '"' . $title . '" is now published and visible to its target participants.',
                'created_at' => $updatedAt,
                'link' => '/event_view.php?id=' . rawurlencode($eventId),
                'kind' => 'event-published',
            ];
            continue;
        }

        $reason = web_notification_extract_reject_reason((string) ($event['description'] ?? ''));
        if ($reason !== '') {
            $notifications[] = [
                'id' => web_notification_hash_id('teacher-proposal-review', $eventId . '|' . $updatedAt),
                'title' => 'Proposal Review Required',
                'description' => 'The admin requested changes for "' . $title . '". Reason: ' . $reason,
                'created_at' => $updatedAt,
                'link' => '/event_view.php?id=' . rawurlencode($eventId),
                'kind' => 'proposal-review',
            ];
        }
    }

    $assignmentUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/event_teacher_assignments'
        . '?select=event_id,can_scan,assigned_at'
        . '&teacher_id=eq.' . rawurlencode($teacherId)
        . '&order=assigned_at.desc'
        . '&limit=40';
    $assignmentRes = supabase_request('GET', $assignmentUrl, $headers);
    $assignmentRows = $assignmentRes['ok'] ? json_decode((string) $assignmentRes['body'], true) : [];
    $assignmentRows = is_array($assignmentRows) ? $assignmentRows : [];

    $eventMap = web_notification_event_map(array_map(
        static fn($row): string => is_array($row) ? (string) ($row['event_id'] ?? '') : '',
        $assignmentRows
    ), $headers);

    foreach ($assignmentRows as $assignment) {
        if (!is_array($assignment)) {
            continue;
        }
        $eventId = trim((string) ($assignment['event_id'] ?? ''));
        $assignedAt = trim((string) ($assignment['assigned_at'] ?? gmdate('c')));
        if ($eventId === '') {
            continue;
        }
        $event = $eventMap[$eventId] ?? [];
        $title = trim((string) ($event['title'] ?? 'Event'));
        $link = '/event_view.php?id=' . rawurlencode($eventId);

        $notifications[] = [
            'id' => web_notification_hash_id('teacher-assigned', $eventId . '|' . $assignedAt . '|assigned'),
            'title' => 'Assigned to Event',
            'description' => 'You were assigned to "' . $title . '". Check the event details for updates.',
            'created_at' => $assignedAt,
            'link' => $link,
            'kind' => 'assignment',
        ];

        if (!empty($assignment['can_scan'])) {
            $notifications[] = [
                'id' => web_notification_hash_id('teacher-qr', $eventId . '|' . $assignedAt . '|qr'),
                'title' => 'QR Scanner Access Granted',
                'description' => 'You can now scan attendance and manage assistants for "' . $title . '".',
                'created_at' => $assignedAt,
                'link' => $link,
                'kind' => 'qr-access',
            ];
        }
    }

    return $notifications;
}

function web_sort_notifications(array $notifications, int $limit): array
{
    usort($notifications, static function (array $a, array $b): int {
        $aTime = strtotime((string) ($a['created_at'] ?? '')) ?: 0;
        $bTime = strtotime((string) ($b['created_at'] ?? '')) ?: 0;
        return $bTime <=> $aTime;
    });

    if ($limit > 0 && count($notifications) > $limit) {
        $notifications = array_slice($notifications, 0, $limit);
    }

    return array_values($notifications);
}

function web_fetch_notifications_for_user(array $user, int $limit = 10): array
{
    $headers = web_notification_headers();
    $role = strtolower(trim((string) ($user['role'] ?? '')));

    $notifications = match ($role) {
        'admin' => web_fetch_admin_notifications($headers),
        'teacher' => web_fetch_teacher_notifications($user, $headers),
        default => [],
    };

    return web_sort_notifications($notifications, $limit);
}
