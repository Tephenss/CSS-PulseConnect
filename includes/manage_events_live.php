<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/supabase.php';
require_once __DIR__ . '/proposal_requirements.php';
require_once __DIR__ . '/web_notifications.php';

function manage_events_live_headers(): array
{
    return [
        'Accept: application/json',
        'apikey: ' . SUPABASE_KEY,
        'Authorization: Bearer ' . SUPABASE_KEY,
    ];
}

function manage_events_live_stage_label(string $stage, string $role = 'admin'): string
{
    $stage = strtolower(trim($stage));
    if ($role === 'teacher') {
        return match ($stage) {
            'requirements_requested' => 'Upload documents',
            'under_review' => 'Waiting for admin',
            'approved' => 'Approved',
            default => 'Waiting for requirements',
        };
    }

    return match ($stage) {
        'requirements_requested' => 'Waiting on teacher',
        'under_review' => 'Under review',
        'approved' => 'Ready for publish',
        default => 'Needs requirements',
    };
}

function manage_events_live_teacher_progress_note(string $stage, string $status): string
{
    $stage = strtolower(trim($stage));
    $status = strtolower(trim($status));

    if ($status === 'pending' && $stage === 'requirements_requested') {
        return 'Open View/Edit to upload the requested proposal documents.';
    }
    if ($status === 'pending' && $stage === 'under_review') {
        return 'Documents submitted. Waiting for admin approval.';
    }
    if ($status === 'approved') {
        return 'Your proposal was approved. Open Event Details to review before publish.';
    }

    return '';
}

function manage_events_live_teacher_approval_count(array $events, string $teacherId): int
{
    $count = 0;
    foreach ($events as $event) {
        if (!is_array($event)) {
            continue;
        }
        if (trim((string) ($event['created_by'] ?? '')) !== $teacherId) {
            continue;
        }

        $status = strtolower(trim((string) ($event['status'] ?? '')));
        $description = (string) ($event['description'] ?? '');
        $isRejected = str_contains($description, '[REJECT_REASON:');

        if (in_array($status, ['pending', 'approved', 'rejected'], true)) {
            $count++;
            continue;
        }
        if ($status === 'archived' && $isRejected) {
            $count++;
        }
    }

    return $count;
}

function manage_events_live_extract_reject_reason(string $description): string
{
    if (preg_match('/\[REJECT_REASON:\s*(.*?)\]\s*/s', $description, $matches) !== 1) {
        return '';
    }

    return trim((string) ($matches[1] ?? ''));
}

function manage_events_live_requirements_button(string $stage, int $requirementCount): string
{
    if ($requirementCount <= 0) {
        return 'Send Req';
    }

    return strtolower(trim($stage)) === 'under_review' ? 'Review Docs' : 'View';
}

function manage_events_live_effective_stage(string $status, string $stage, array $requirements): string
{
    $status = strtolower(trim($status));
    $stage = strtolower(trim($stage));

    if (in_array($status, ['approved', 'published'], true)) {
        return 'approved';
    }

    // Legacy rows with blank stage but requirements already assigned by admin.
    if ($status === 'pending' && $requirements !== [] && $stage === '') {
        return 'requirements_requested';
    }

    return $stage !== '' ? $stage : 'pending_requirements';
}

function manage_events_admin_pending_visible(string $status, string $proposalStage): bool
{
    $status = strtolower(trim($status));
    $stage = strtolower(trim($proposalStage));

    if ($status !== 'pending') {
        return true;
    }

    // Hide teacher compose/upload phase until they submit for admin review.
    return in_array($stage, ['under_review', 'requirements_requested'], true);
}

function manage_events_live_event_visible_on_page(array $user, array $event): bool
{
    $role = strtolower(trim((string) ($user['role'] ?? '')));
    $userId = trim((string) ($user['id'] ?? ''));
    $eventId = trim((string) ($event['id'] ?? ''));

    if ($eventId === '') {
        return false;
    }

    $status = strtolower(trim((string) ($event['status'] ?? '')));
    $description = (string) ($event['description'] ?? '');
    $isRejected = str_contains($description, '[REJECT_REASON:');
    $createdBy = trim((string) ($event['created_by'] ?? ''));

    if ($role === 'teacher') {
        if ($status === 'archived' && !$isRejected) {
            return false;
        }

        if ($createdBy !== $userId && $status !== 'published') {
            return false;
        }
    }

    if ($role === 'admin' && $status === 'pending') {
        $proposalStage = strtolower(trim((string) ($event['proposal_stage'] ?? 'pending_requirements')));
        if (!manage_events_admin_pending_visible($status, $proposalStage)) {
            return false;
        }
    }

    return true;
}

function manage_events_live_list_hash(array $user, array $events): string
{
    $parts = [];

    foreach ($events as $event) {
        if (!is_array($event) || !manage_events_live_event_visible_on_page($user, $event)) {
            continue;
        }

        $eventId = trim((string) ($event['id'] ?? ''));
        $status = strtolower(trim((string) ($event['status'] ?? '')));
        $parts[] = $eventId . '|' . $status;
    }

    sort($parts);

    return substr(sha1(implode(',', $parts)), 0, 16);
}

function manage_events_live_visible_event_ids(array $user, array $events): array
{
    $ids = [];

    foreach ($events as $event) {
        if (!is_array($event) || !manage_events_live_event_visible_on_page($user, $event)) {
            continue;
        }

        $eventId = trim((string) ($event['id'] ?? ''));
        if ($eventId !== '') {
            $ids[] = $eventId;
        }
    }

    sort($ids);

    return $ids;
}

function manage_events_live_revision(array $event, array $summary, array $requirements): string
{
    $status = strtolower(trim((string) ($event['status'] ?? '')));
    $stage = manage_events_live_effective_stage(
        $status,
        (string) ($event['proposal_stage'] ?? 'pending_requirements'),
        $requirements
    );

    $parts = [
        $status,
        $stage,
        (string) ($event['updated_at'] ?? ''),
        (string) ($event['requirements_submitted_at'] ?? ''),
        (string) ($event['description'] ?? ''),
        json_encode($summary, JSON_UNESCAPED_SLASHES) ?: '',
    ];

    foreach ($requirements as $requirement) {
        if (!is_array($requirement)) {
            continue;
        }
        $parts[] = (string) ($requirement['id'] ?? '') . ':' . (!empty($requirement['uploaded']) ? '1' : '0');
    }

    return substr(sha1(implode('|', $parts)), 0, 16);
}

function manage_events_live_fetch_events(array $user, array $headers, bool $lite = false): array
{
    $role = strtolower(trim((string) ($user['role'] ?? '')));
    $userId = trim((string) ($user['id'] ?? ''));
    $select = 'id,title,description,status,created_by,created_at,updated_at,proposal_stage,requirements_requested_at,requirements_submitted_at';
    // Badge/lite polls only need recent rows for signals + pending counts.
    $limit = $lite ? 40 : 120;

    $url = rtrim(SUPABASE_URL, '/') . '/rest/v1/events?select=' . rawurlencode($select);
    if ($role === 'admin') {
        $url .= '&status=neq.archived&order=updated_at.desc&limit=' . $limit;
    } elseif ($role === 'teacher' && $userId !== '') {
        $url .= '&or=(created_by.eq.' . rawurlencode($userId) . ',status.eq.published)&order=updated_at.desc&limit=' . $limit;
    } else {
        return [];
    }

    $res = supabase_request('GET', $url, $headers);
    if (!($res['ok'] ?? false)) {
        return [];
    }

    $rows = json_decode((string) ($res['body'] ?? '[]'), true);
    return is_array($rows) ? $rows : [];
}

function manage_events_live_build_signals(array $user, array $events): array
{
    $role = strtolower(trim((string) ($user['role'] ?? '')));
    $teacherId = trim((string) ($user['id'] ?? ''));
    $signals = [];

    foreach ($events as $event) {
        if (!is_array($event)) {
            continue;
        }

        $eventId = trim((string) ($event['id'] ?? ''));
        $title = trim((string) ($event['title'] ?? 'Event'));
        $status = strtolower(trim((string) ($event['status'] ?? '')));
        $stage = strtolower(trim((string) ($event['proposal_stage'] ?? 'pending_requirements')));
        $createdAt = trim((string) ($event['created_at'] ?? gmdate('c')));
        $updatedAt = trim((string) ($event['updated_at'] ?? $createdAt));
        $submittedAt = trim((string) ($event['requirements_submitted_at'] ?? ''));
        $requestedAt = trim((string) ($event['requirements_requested_at'] ?? ''));
        $description = (string) ($event['description'] ?? '');
        $createdBy = trim((string) ($event['created_by'] ?? ''));

        if ($eventId === '') {
            continue;
        }

        if ($role === 'admin') {
            if ($status === 'pending' && $stage === 'under_review') {
                $signals[] = [
                    'id' => web_notification_hash_id('manage-events-review', $eventId . '|' . ($submittedAt !== '' ? $submittedAt : $updatedAt)),
                    'event_id' => $eventId,
                    'dedupe_key' => web_notification_dedupe_key('proposal-review', $eventId),
                    'kind' => 'proposal-review',
                    'area' => 'Manage Events · Pending Proposals',
                    'title' => 'Teacher submitted proposal documents',
                    'description' => 'Documents for "' . $title . '" are ready. Open Manage Events → Pending Proposals → Review Docs.',
                    'created_at' => $submittedAt !== '' ? $submittedAt : $updatedAt,
                ];
            }
            continue;
        }

        if ($role === 'teacher' && $teacherId !== '' && $createdBy !== $teacherId) {
            continue;
        }

        if ($role === 'teacher') {
            $requestedAt = trim((string) ($event['requirements_requested_at'] ?? ''));
            $needsDocuments = $status === 'pending'
                && ($stage === 'requirements_requested' || $requestedAt !== '');

            if ($needsDocuments) {
                $signalRequestedAt = $requestedAt !== '' ? $requestedAt : $createdAt;
                $signals[] = [
                    'id' => web_notification_hash_id('manage-events-docs', $eventId . '|' . $signalRequestedAt),
                    'event_id' => $eventId,
                    'dedupe_key' => web_notification_dedupe_key('proposal-documents', $eventId),
                    'kind' => 'proposal-documents',
                    'area' => 'Manage Events · Approval Tab',
                    'title' => 'Admin requested proposal documents',
                    'description' => 'Upload required files for "' . $title . '". Go to Manage Events → Approval tab → View/Edit.',
                    'created_at' => $signalRequestedAt,
                ];
                continue;
            }

            if ($status === 'approved') {
                continue;
            }

            $rejectReason = manage_events_live_extract_reject_reason($description);
            if ($rejectReason !== '') {
                $signals[] = [
                    'id' => web_notification_hash_id('manage-events-rejected', $eventId . '|reject'),
                    'event_id' => $eventId,
                    'dedupe_key' => web_notification_dedupe_key('proposal-rejected', $eventId),
                    'kind' => 'proposal-rejected',
                    'area' => 'Manage Events · Approval Tab',
                    'title' => 'Admin asked for proposal changes',
                    'description' => '"' . $title . '" needs revision. Open Manage Events → Approval tab → View/Edit. Reason: ' . $rejectReason,
                    'created_at' => $updatedAt,
                ];
            }
        }
    }

    usort($signals, static function (array $a, array $b): int {
        $aTime = strtotime((string) ($a['created_at'] ?? '')) ?: 0;
        $bTime = strtotime((string) ($b['created_at'] ?? '')) ?: 0;
        return $bTime <=> $aTime;
    });

    return $signals;
}

function manage_events_live_payload(array $user, bool $lite = false): array
{
    $headers = manage_events_live_headers();
    $role = strtolower(trim((string) ($user['role'] ?? '')));
    $events = manage_events_live_fetch_events($user, $headers, $lite);
    $signals = manage_events_live_build_signals($user, $events);
    $pendingCount = count(array_filter(
        $events,
        static function ($event) use ($role): bool {
            if (!is_array($event)) {
                return false;
            }
            $status = strtolower(trim((string) ($event['status'] ?? '')));
            if ($status !== 'pending') {
                return false;
            }
            if ($role !== 'admin') {
                return true;
            }
            $stage = strtolower(trim((string) ($event['proposal_stage'] ?? 'pending_requirements')));
            return manage_events_admin_pending_visible($status, $stage);
        }
    ));
    $approvalCount = 0;
    if ($role === 'teacher') {
        $approvalCount = manage_events_live_teacher_approval_count($events, trim((string) ($user['id'] ?? '')));
    }

    $listHash = manage_events_live_list_hash($user, $events);
    $visibleEventIds = manage_events_live_visible_event_ids($user, $events);

    if ($lite) {
        return [
            'ok' => true,
            'pending_count' => $pendingCount,
            'approval_count' => $approvalCount,
            'role' => $role,
            'signals' => $signals,
            'list_hash' => $listHash,
            'event_ids' => $visibleEventIds,
        ];
    }

    $proposalEventIds = [];
    foreach ($events as $event) {
        if (!is_array($event)) {
            continue;
        }
        $eventId = trim((string) ($event['id'] ?? ''));
        if ($eventId === '') {
            continue;
        }
        $status = strtolower(trim((string) ($event['status'] ?? '')));
        $proposalStage = strtolower(trim((string) ($event['proposal_stage'] ?? '')));
        if ($status === 'pending'
            || in_array($proposalStage, ['pending_requirements', 'requirements_requested', 'under_review'], true)) {
            $proposalEventIds[] = $eventId;
        }
    }
    $proposalEventIds = array_values(array_unique($proposalEventIds));

    $requirementMap = $proposalEventIds === [] ? [] : fetch_proposal_requirements_map($proposalEventIds, $headers);
    $submissionMap = $proposalEventIds === [] ? [] : fetch_proposal_submissions_map($proposalEventIds, $headers);
    $visibleSubmissionMap = filter_visible_proposal_submissions_map($submissionMap);

    $userId = trim((string) ($user['id'] ?? ''));

    $eventPayload = [];
    foreach ($events as $event) {
        if (!is_array($event)) {
            continue;
        }

        $eventId = trim((string) ($event['id'] ?? ''));
        if ($eventId === '') {
            continue;
        }

        $createdBy = trim((string) ($event['created_by'] ?? ''));
        if ($role === 'teacher' && $userId !== '' && $createdBy !== $userId) {
            continue;
        }

        $status = strtolower(trim((string) ($event['status'] ?? '')));
        if ($role === 'admin' && $status === 'pending') {
            $rawStage = strtolower(trim((string) ($event['proposal_stage'] ?? 'pending_requirements')));
            if (!manage_events_admin_pending_visible($status, $rawStage)) {
                continue;
            }
        }

        $description = (string) ($event['description'] ?? '');
        $isRejected = str_contains($description, '[REJECT_REASON:');
        $rejectReason = manage_events_live_extract_reject_reason($description);

        $requirements = $requirementMap[$eventId] ?? [];
        $submissions = $submissionMap[$eventId] ?? [];
        $visibleSubmissions = $visibleSubmissionMap[$eventId] ?? [];
        $summary = build_proposal_requirement_summary($requirements, $submissions);
        $summaryVisible = build_proposal_requirement_summary($requirements, $visibleSubmissions);

        $stage = manage_events_live_effective_stage(
            $status,
            (string) ($event['proposal_stage'] ?? 'pending_requirements'),
            $requirements
        );

        $adminWaitingOnFinalSubmit = $role === 'admin'
            && $status === 'pending'
            && $requirements !== []
            && $stage !== 'under_review'
            && $stage !== 'approved';

        $adminDraftPhase = $adminWaitingOnFinalSubmit;
        $summaryForCard = ($role === 'teacher' || $adminDraftPhase) ? $summary : $summaryVisible;
        $submissionsForCard = ($role === 'teacher' || $adminDraftPhase) ? $submissions : $visibleSubmissions;

        $requirementItems = [];
        foreach ($requirements as $requirement) {
            if (!is_array($requirement)) {
                continue;
            }
            $requirementId = trim((string) ($requirement['id'] ?? ''));
            $requirementItems[] = [
                'id' => $requirementId,
                'code' => (string) ($requirement['code'] ?? 'DOC'),
                'label' => (string) ($requirement['label'] ?? 'Document'),
                'uploaded' => $requirementId !== '' && isset($submissionsForCard[$requirementId]),
            ];
        }

        $revisionEvent = [
            'status' => $status,
            'proposal_stage' => $stage,
            'updated_at' => (string) ($event['updated_at'] ?? ''),
            'requirements_submitted_at' => (string) ($event['requirements_submitted_at'] ?? ''),
            'description' => $description,
        ];

        $eventPayload[] = [
            'id' => $eventId,
            'status' => (string) ($event['status'] ?? ''),
            'proposal_stage' => $stage,
            'updated_at' => (string) ($event['updated_at'] ?? ''),
            'description' => $description,
            'is_rejected' => $isRejected,
            'reject_reason' => $rejectReason,
            'revision' => manage_events_live_revision($revisionEvent, $summaryForCard, $requirementItems),
            'summary' => $summaryForCard,
            'requirements' => $requirementItems,
            'requirements_json' => array_values($requirements),
            'submissions_json' => array_values($submissionsForCard),
            'stage_label' => manage_events_live_stage_label($stage, $role),
            'requirements_button' => manage_events_live_requirements_button($stage, count($requirements)),
            'show_approve' => $role === 'admin' && $status === 'pending',
            'approve_ready' => $role === 'admin' && $status === 'pending' && $stage === 'under_review',
            'show_requirements' => $role === 'admin' && $status === 'pending',
            'admin_waiting_on_final_submit' => $adminWaitingOnFinalSubmit,
            'teacher_progress_note' => $role === 'teacher'
                ? manage_events_live_teacher_progress_note($stage, $status)
                : '',
            'requirements_empty_label' => $role === 'teacher'
                ? 'Waiting for admin to send document requirements.'
                : 'Admin has not requested the required documents yet.',
        ];
    }

    return [
        'ok' => true,
        'pending_count' => $pendingCount,
        'approval_count' => $approvalCount,
        'role' => $role,
        'signals' => $signals,
        'list_hash' => $listHash,
        'event_ids' => $visibleEventIds,
        'events' => $eventPayload,
    ];
}
