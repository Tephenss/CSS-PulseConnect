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

function web_notification_dedupe_key(string $kind, string $eventId): string
{
    return $kind . ':' . $eventId;
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
        $userUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/users?select=id,first_name,middle_name,last_name,suffix&id=in.' . $inList;
        $userRes = supabase_request('GET', $userUrl, $headers);
        $userRows = $userRes['ok'] ? json_decode((string) $userRes['body'], true) : [];
        if (is_array($userRows)) {
            foreach ($userRows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $id = trim((string) ($row['id'] ?? ''));
                if ($id !== '') {
                    $creatorMap[$id] = build_display_name(
                        (string) ($row['first_name'] ?? ''),
                        (string) ($row['middle_name'] ?? ''),
                        (string) ($row['last_name'] ?? ''),
                        (string) ($row['suffix'] ?? '')
                    );
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
                'event_id' => $eventId,
                'dedupe_key' => web_notification_dedupe_key('proposal-review', $eventId),
                'area' => 'Manage Events · Pending Proposals',
                'title' => 'Teacher submitted proposal documents',
                'description' => $creatorName . ' finished uploading files for "' . $title . '". Open Manage Events, go to Pending Proposals, then click Review Docs.',
                'created_at' => $requirementsSubmittedAt !== '' ? $requirementsSubmittedAt : $createdAt,
                'link' => '/manage_events.php',
                'kind' => 'proposal-review',
            ];
        }
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

        $who = $displayName;
        if ($studentNumber !== '') {
            $who .= ' (' . $studentNumber . ')';
        }
        if ($course !== '') {
            $who .= ' · ' . $course;
        }
        $description = $who . ' registered on the mobile app. Open Manage Application to approve or reject this account.';

        $notifications[] = [
            'id' => web_notification_hash_id('admin-application', $studentId . '|' . $createdAt),
            'area' => 'Manage Application · Student Signups',
            'title' => 'New mobile app registration',
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

    // One query: own proposals + recent peer-published (split in PHP).
    $eventsUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/events'
        . '?select=id,title,status,description,updated_at,created_by,proposal_stage,requirements_requested_at,requirements_submitted_at'
        . '&or=(created_by.eq.' . rawurlencode($teacherId) . ',and(status.eq.published,created_by.neq.' . rawurlencode($teacherId) . '))'
        . '&order=updated_at.desc'
        . '&limit=50';
    $eventsRes = supabase_request('GET', $eventsUrl, $headers);
    $allEvents = $eventsRes['ok'] ? json_decode((string) $eventsRes['body'], true) : [];
    $allEvents = is_array($allEvents) ? $allEvents : [];

    $events = [];
    $peerPublishedEvents = [];
    foreach ($allEvents as $event) {
        if (!is_array($event)) {
            continue;
        }
        $createdBy = trim((string) ($event['created_by'] ?? ''));
        $status = strtolower(trim((string) ($event['status'] ?? '')));
        if ($createdBy === $teacherId) {
            if (in_array($status, ['pending', 'approved', 'published', 'draft', 'archived'], true)) {
                $events[] = $event;
            }
            continue;
        }
        if ($status === 'published') {
            $peerPublishedEvents[] = $event;
        }
    }
    if (count($events) > 25) {
        $events = array_slice($events, 0, 25);
    }
    if (count($peerPublishedEvents) > 25) {
        $peerPublishedEvents = array_slice($peerPublishedEvents, 0, 25);
    }

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
            $requestedAt = trim((string) ($event['requirements_requested_at'] ?? $updatedAt)) ?: $updatedAt;
            $notifications[] = [
                'id' => web_notification_hash_id('teacher-proposal-docs', $eventId . '|' . $requestedAt),
                'event_id' => $eventId,
                'dedupe_key' => web_notification_dedupe_key('proposal-documents', $eventId),
                'area' => 'Manage Events · Approval Tab',
                'title' => 'Admin requested proposal documents',
                'description' => 'Upload the required files for "' . $title . '". Go to Manage Events → Approval tab, then attach each requested document.',
                'created_at' => $requestedAt,
                'link' => '/manage_events.php',
                'kind' => 'proposal-documents',
            ];
            continue;
        }

        if ($status === 'pending' && $proposalStage === 'under_review') {
            $submittedAt = trim((string) ($event['requirements_submitted_at'] ?? $updatedAt)) ?: $updatedAt;
            $notifications[] = [
                'id' => web_notification_hash_id('teacher-proposal-under-review', $eventId . '|' . $submittedAt),
                'event_id' => $eventId,
                'dedupe_key' => web_notification_dedupe_key('proposal-under-review', $eventId),
                'area' => 'Manage Events · Approval Tab',
                'title' => 'Documents sent — waiting for admin',
                'description' => 'Your uploads for "' . $title . '" are under admin review. Check Manage Events → Approval tab for status updates.',
                'created_at' => $submittedAt,
                'link' => '/manage_events.php',
                'kind' => 'proposal-under-review',
            ];
            continue;
        }

        if ($status === 'approved') {
            $notifications[] = [
                'id' => web_notification_hash_id('teacher-proposal-approved', $eventId . '|approved'),
                'event_id' => $eventId,
                'dedupe_key' => web_notification_dedupe_key('proposal-approved', $eventId),
                'area' => 'Event Details',
                'title' => 'Event proposal approved',
                'description' => '"' . $title . '" was approved by the admin. Open Event Details to review info before it goes live.',
                'created_at' => $updatedAt,
                'link' => '/event_view.php?id=' . rawurlencode($eventId),
                'kind' => 'proposal-approved',
            ];
            continue;
        }

        if ($status === 'published') {
            $notifications[] = [
                'id' => web_notification_hash_id('teacher-event-published', $eventId . '|published'),
                'event_id' => $eventId,
                'dedupe_key' => web_notification_dedupe_key('event-published', $eventId),
                'area' => 'Event Details · Published',
                'title' => 'Your event is now live',
                'description' => '"' . $title . '" is published. Students can now view and register on the mobile app.',
                'created_at' => $updatedAt,
                'link' => '/event_view.php?id=' . rawurlencode($eventId),
                'kind' => 'event-published',
            ];
            continue;
        }

        $reason = web_notification_extract_reject_reason((string) ($event['description'] ?? ''));
        if ($reason !== '') {
            $notifications[] = [
                'id' => web_notification_hash_id('teacher-proposal-review', $eventId . '|reject'),
                'event_id' => $eventId,
                'dedupe_key' => web_notification_dedupe_key('proposal-rejected', $eventId),
                'area' => 'Event Details · Needs Changes',
                'title' => 'Admin asked you to revise the proposal',
                'description' => '"' . $title . '" needs changes. Open Event Details to read the admin note and edit the event. Reason: ' . $reason,
                'created_at' => $updatedAt,
                'link' => '/event_view.php?id=' . rawurlencode($eventId),
                'kind' => 'proposal-rejected',
            ];
        }
    }

    foreach ($peerPublishedEvents as $event) {
        if (!is_array($event)) {
            continue;
        }
        $eventId = trim((string) ($event['id'] ?? ''));
        $title = trim((string) ($event['title'] ?? 'Event'));
        $updatedAt = trim((string) ($event['updated_at'] ?? gmdate('c')));
        if ($eventId === '' || $updatedAt === '') {
            continue;
        }

        $notifications[] = [
            'id' => web_notification_hash_id('teacher-event-published-peer', $eventId . '|published'),
            'event_id' => $eventId,
            'dedupe_key' => web_notification_dedupe_key('event-published-peer', $eventId),
            'area' => 'Manage Events · Active Tab',
            'title' => 'New event published',
            'description' => '"' . $title . '" is now live. Open Manage Events → Active tab to view event details.',
            'created_at' => $updatedAt,
            'link' => '/manage_events.php',
            'kind' => 'event-published-peer',
        ];
    }

    $ownedEventIds = [];
    foreach ($events as $event) {
        if (!is_array($event)) {
            continue;
        }
        $ownedId = trim((string) ($event['id'] ?? ''));
        if ($ownedId !== '') {
            $ownedEventIds[$ownedId] = true;
        }
    }

    // Assignments + embedded event fields (no second events round-trip).
    $assignmentUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/event_teacher_assignments'
        . '?select=event_id,can_scan,assigned_at,events(id,title,status,updated_at)'
        . '&teacher_id=eq.' . rawurlencode($teacherId)
        . '&order=assigned_at.desc'
        . '&limit=40';
    $assignmentRes = supabase_request('GET', $assignmentUrl, $headers);
    $assignmentRows = $assignmentRes['ok'] ? json_decode((string) $assignmentRes['body'], true) : [];
    $assignmentRows = is_array($assignmentRows) ? $assignmentRows : [];

    foreach ($assignmentRows as $assignment) {
        if (!is_array($assignment)) {
            continue;
        }
        $eventId = trim((string) ($assignment['event_id'] ?? ''));
        $assignedAt = trim((string) ($assignment['assigned_at'] ?? gmdate('c')));
        if ($eventId === '') {
            continue;
        }
        $embedded = $assignment['events'] ?? null;
        if (is_array($embedded) && isset($embedded[0]) && is_array($embedded[0])) {
            $embedded = $embedded[0];
        }
        $event = is_array($embedded) ? $embedded : [];
        $title = trim((string) ($event['title'] ?? 'Event'));
        $link = '/event_view.php?id=' . rawurlencode($eventId);
        $eventStatus = strtolower(trim((string) ($event['status'] ?? '')));

        if ($eventStatus === 'published') {
            if (!empty($assignment['can_scan']) && !isset($ownedEventIds[$eventId])) {
                $notifications[] = [
                    'id' => web_notification_hash_id('teacher-qr', $eventId . '|' . $assignedAt . '|qr'),
                    'event_id' => $eventId,
                    'dedupe_key' => web_notification_dedupe_key('qr-access', $eventId),
                    'area' => 'Event Details · QR Scanner',
                    'title' => 'QR scanner access enabled',
                    'description' => 'You can scan student attendance for "' . $title . '". Open the event, then use Scan QR from the event page.',
                    'created_at' => $assignedAt,
                    'link' => $link,
                    'kind' => 'qr-access',
                ];
            }
            continue;
        }

        $notifications[] = [
            'id' => web_notification_hash_id('teacher-assigned', $eventId . '|' . $assignedAt . '|assigned'),
            'event_id' => $eventId,
            'dedupe_key' => web_notification_dedupe_key('assignment', $eventId),
            'area' => 'Event Details · Assignment',
            'title' => 'You were assigned to an event',
            'description' => 'You are now part of "' . $title . '". Open Event Details for schedule, location, and updates.',
            'created_at' => $assignedAt,
            'link' => $link,
            'kind' => 'assignment',
        ];

        if (!empty($assignment['can_scan'])) {
            $notifications[] = [
                'id' => web_notification_hash_id('teacher-qr', $eventId . '|' . $assignedAt . '|qr'),
                'event_id' => $eventId,
                'dedupe_key' => web_notification_dedupe_key('qr-access', $eventId),
                'area' => 'Event Details · QR Scanner',
                'title' => 'QR scanner access enabled',
                'description' => 'You can scan student attendance for "' . $title . '". Open the event, then use Scan QR from the event page.',
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
