<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/session.php';
session_bootstrap();

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/supabase.php';
require_once __DIR__ . '/../includes/json.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/event_targeting.php';
require_once __DIR__ . '/../includes/student_requirements.php';
require_once __DIR__ . '/../includes/document_review_access.php';
require_once __DIR__ . '/../includes/document_review_claim.php';

$user = require_role(['admin', 'teacher']);
$userId = trim((string) ($user['id'] ?? ''));
$role = strtolower(trim((string) ($user['role'] ?? '')));
$eventId = trim((string) ($_GET['event_id'] ?? ''));

if ($eventId === '') {
    json_response(['ok' => false, 'error' => 'event_id is required.'], 400);
}

$headers = student_requirement_headers();
$eventUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/events'
    . '?select=id,title,status,created_by'
    . '&id=eq.' . rawurlencode($eventId)
    . '&limit=1';
$eventRes = supabase_request('GET', $eventUrl, $headers);
$eventRows = $eventRes['ok'] ? json_decode((string) $eventRes['body'], true) : null;
$event = is_array($eventRows) && isset($eventRows[0]) ? $eventRows[0] : null;

if (!is_array($event)) {
    json_response(['ok' => false, 'error' => 'Event not found.'], 404);
}

$isCreator = trim((string) ($event['created_by'] ?? '')) === $userId;
if (!event_user_can_review_documents($eventId, $userId, $role, $isCreator)) {
    json_response(['ok' => false, 'error' => 'You do not have Document Review access for this event.'], 403);
}

$requirements = fetch_student_requirements_map([$eventId], $headers)[$eventId] ?? [];
if ($requirements === []) {
    json_response(['ok' => false, 'error' => 'This event has no student document requirements.'], 404);
}

$submissionsMap = fetch_student_submissions_map([$eventId], $headers)[$eventId] ?? [];
$documentsMap = fetch_student_documents_map([$eventId], $headers)[$eventId] ?? [];

$studentIds = array_keys($submissionsMap);
$studentsById = [];
if ($studentIds !== []) {
    $inList = '(' . implode(',', array_map('rawurlencode', $studentIds)) . ')';
    $studentsUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/users'
        . '?select=id,first_name,middle_name,last_name,suffix,email,student_id,course,sections(name)'
        . '&id=in.' . $inList
        . '&role=eq.student'
        . '&limit=1000';
    $studentsRes = supabase_request('GET', $studentsUrl, $headers);
    $studentRows = $studentsRes['ok'] ? json_decode((string) $studentsRes['body'], true) : [];
    if (is_array($studentRows)) {
        foreach ($studentRows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $sid = trim((string) ($row['id'] ?? ''));
            if ($sid === '') {
                continue;
            }
            $row['display_name'] = compose_student_display_name($row);
            $row['section_name'] = extract_section_name($row['sections'] ?? null);
            $studentsById[$sid] = $row;
        }
    }
}

$reviewRows = [];
foreach ($submissionsMap as $studentId => $submission) {
    if (!is_array($submission)) {
        continue;
    }
    $student = $studentsById[$studentId] ?? null;
    $documents = $documentsMap[$studentId] ?? [];
    $summary = build_student_requirement_summary($requirements, $documents);
    $reviewRows[] = [
        'student_id' => $studentId,
        'student' => $student,
        'submission' => $submission,
        'documents' => $documents,
        'summary' => $summary,
    ];
}

usort($reviewRows, static function (array $a, array $b): int {
    $statusOrder = ['pending_review' => 0, 'declined' => 1, 'approved' => 2];
    $aStatus = strtolower(trim((string) ($a['submission']['status'] ?? '')));
    $bStatus = strtolower(trim((string) ($b['submission']['status'] ?? '')));
    $aRank = $statusOrder[$aStatus] ?? 9;
    $bRank = $statusOrder[$bStatus] ?? 9;
    if ($aRank !== $bRank) {
        return $aRank <=> $bRank;
    }

    $aTime = strtotime((string) ($a['submission']['submitted_at'] ?? '')) ?: 0;
    $bTime = strtotime((string) ($b['submission']['submitted_at'] ?? '')) ?: 0;
    return $bTime <=> $aTime;
});

$reviewerIds = [];
foreach ($reviewRows as $row) {
    $rid = trim((string) ($row['submission']['reviewed_by'] ?? ''));
    if ($rid !== '') {
        $reviewerIds[] = $rid;
    }
    $cid = trim((string) ($row['submission']['review_claimed_by'] ?? ''));
    if ($cid !== '') {
        $reviewerIds[] = $cid;
    }
}
$reviewerProfiles = document_review_load_user_profiles($reviewerIds);

$pendingCount = 0;
$approvedCount = 0;
$declinedCount = 0;
$submissions = [];

foreach ($reviewRows as $row) {
    $student = is_array($row['student']) ? $row['student'] : [];
    $submission = is_array($row['submission']) ? $row['submission'] : [];
    $documents = is_array($row['documents']) ? $row['documents'] : [];
    $studentId = (string) ($row['student_id'] ?? '');
    if ($studentId === '') {
        continue;
    }

    $status = strtolower(trim((string) ($submission['status'] ?? '')));
    if ($status === '') {
        $status = 'pending_review';
    }

    if ($status === 'pending_review') {
        $pendingCount++;
    } elseif ($status === 'approved') {
        $approvedCount++;
    } elseif ($status === 'declined') {
        $declinedCount++;
    }

    $documentsByRequirement = [];
    foreach ($documents as $doc) {
        if (!is_array($doc)) {
            continue;
        }
        $reqId = trim((string) ($doc['requirement_id'] ?? ''));
        if ($reqId !== '') {
            $documentsByRequirement[$reqId] = $doc;
        }
    }

    $docItems = [];
    foreach ($requirements as $requirement) {
        if (!is_array($requirement)) {
            continue;
        }
        $reqId = trim((string) ($requirement['id'] ?? ''));
        $doc = $documentsByRequirement[$reqId] ?? null;
        $mime = is_array($doc) ? strtolower(trim((string) ($doc['mime_type'] ?? ''))) : '';
        $fileUrl = is_array($doc) ? (string) ($doc['file_url'] ?? '') : '';
        if ($mime === '' && $fileUrl !== '') {
            $path = strtolower((string) parse_url($fileUrl, PHP_URL_PATH));
            if (str_ends_with($path, '.pdf')) {
                $mime = 'application/pdf';
            } elseif (preg_match('/\.(jpe?g|png|webp|gif)$/', $path)) {
                $mime = 'image/' . (str_ends_with($path, '.jpg') || str_ends_with($path, '.jpeg') ? 'jpeg' : (str_ends_with($path, '.png') ? 'png' : (str_ends_with($path, '.webp') ? 'webp' : 'gif')));
            }
        }

        $docItems[] = [
            'label' => (string) ($requirement['label'] ?? 'Requirement'),
            'file_name' => is_array($doc) ? (string) ($doc['file_name'] ?? 'View document') : '',
            'file_url' => $fileUrl,
            'mime_type' => $mime,
            'uploaded' => is_array($doc),
        ];
    }

    $reviewerId = trim((string) ($submission['reviewed_by'] ?? ''));
    $reviewerName = ($reviewerId !== '' && isset($reviewerProfiles[$reviewerId]))
        ? document_review_teacher_display_name($reviewerProfiles[$reviewerId])
        : '';

    $submissions[$studentId] = [
        'student_id' => $studentId,
        'display_name' => (string) ($student['display_name'] ?? 'Student'),
        'email' => (string) ($student['email'] ?? ''),
        'student_no' => (string) ($student['student_id'] ?? ''),
        'section_name' => (string) ($student['section_name'] ?? ''),
        'status' => $status,
        'status_label' => match ($status) {
            'approved' => 'Approved',
            'declined' => 'Declined',
            default => 'Pending',
        },
        'submitted_at' => !empty($submission['submitted_at'])
            ? format_date_local((string) $submission['submitted_at'], 'M j, Y g:i A')
            : '',
        'decline_reason' => trim((string) ($submission['decline_reason'] ?? '')),
        'reviewed_by' => $reviewerId,
        'reviewed_by_name' => $reviewerName,
        'claim_active' => $status === 'pending_review' && document_review_claim_is_active($submission),
        'claimed_by_me' => $status === 'pending_review' && document_review_claim_held_by($submission, $userId),
        'claimed_by_name' => (static function () use ($status, $submission, $reviewerProfiles): string {
            if ($status !== 'pending_review' || !document_review_claim_is_active($submission)) {
                return '';
            }
            $cid = trim((string) ($submission['review_claimed_by'] ?? ''));
            if ($cid === '' || !isset($reviewerProfiles[$cid])) {
                return '';
            }
            return document_review_teacher_display_name($reviewerProfiles[$cid]);
        })(),
        'documents' => $docItems,
    ];
}

json_response([
    'ok' => true,
    'event' => [
        'id' => (string) ($event['id'] ?? ''),
        'title' => (string) ($event['title'] ?? 'Event'),
    ],
    'counts' => [
        'pending' => $pendingCount,
        'approved' => $approvedCount,
        'declined' => $declinedCount,
        'total' => count($submissions),
    ],
    'submissions' => $submissions,
], 200);
