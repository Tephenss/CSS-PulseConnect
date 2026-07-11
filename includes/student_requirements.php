<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/supabase.php';
require_once __DIR__ . '/helpers.php';

const STUDENT_REQUIREMENT_PRESETS = [
    'PARENT_CONSENT' => 'Parent Consent',
    'MEDICAL_CERTIFICATE' => 'Medical Certificate',
    'STUDENT_ID' => 'Student ID',
    'PARENTS_ID' => "Parent's ID",
];

function student_requirement_headers(): array
{
    return [
        'Accept: application/json',
        'apikey: ' . SUPABASE_KEY,
        'Authorization: Bearer ' . SUPABASE_KEY,
    ];
}

function student_requirement_write_headers(): array
{
    return [
        'Content-Type: application/json',
        'Accept: application/json',
        'Prefer: return=representation',
        'apikey: ' . SUPABASE_KEY,
        'Authorization: Bearer ' . SUPABASE_KEY,
    ];
}

function student_requirement_missing_table_error(array $response): bool
{
    $body = strtolower((string) ($response['body'] ?? ''));
    return str_contains($body, 'event_student_')
        && (
            str_contains($body, 'does not exist')
            || str_contains($body, '42p01')
            || str_contains($body, 'pgrst205')
            || str_contains($body, 'schema cache')
        );
}

function normalize_student_requirement_input(array $items): array
{
    $normalized = [];
    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }

        $code = strtoupper(trim((string) ($item['code'] ?? '')));
        $label = trim((string) ($item['label'] ?? ''));

        if ($code === '' && $label === '') {
            continue;
        }

        if ($code === '' || $code === 'OTHER') {
            $code = 'OTHER';
            if ($label === '') {
                continue;
            }
        } elseif ($label === '' && isset(STUDENT_REQUIREMENT_PRESETS[$code])) {
            $label = STUDENT_REQUIREMENT_PRESETS[$code];
        } elseif ($label === '') {
            $label = $code;
        }

        $key = $code . '|' . mb_strtolower($label);
        if (isset($normalized[$key])) {
            continue;
        }

        $normalized[$key] = [
            'code' => mb_substr($code, 0, 32),
            'label' => mb_substr($label, 0, 120),
            'sort_order' => count($normalized),
        ];
    }

    return array_values($normalized);
}

function student_requirement_signature(array $item): string
{
    $code = strtoupper(trim((string) ($item['code'] ?? '')));
    $label = trim((string) ($item['label'] ?? ''));

    if ($code === '') {
        $code = 'OTHER';
    }
    if ($label === '') {
        $label = STUDENT_REQUIREMENT_PRESETS[$code] ?? $code;
    }

    return mb_substr($code, 0, 32) . '|' . mb_strtolower(mb_substr($label, 0, 120));
}

function fetch_student_requirements_map(array $eventIds, array $headers): array
{
    $eventIds = array_values(array_filter(array_map(
        static fn ($id): string => trim((string) $id),
        $eventIds
    )));

    if ($eventIds === []) {
        return [];
    }

    $inList = '(' . implode(',', array_map('rawurlencode', array_unique($eventIds))) . ')';
    $url = rtrim(SUPABASE_URL, '/') . '/rest/v1/event_student_requirements'
        . '?select=id,event_id,code,label,sort_order,created_at'
        . '&event_id=in.' . $inList
        . '&order=sort_order.asc,created_at.asc';

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
        $eventId = trim((string) ($row['event_id'] ?? ''));
        if ($eventId === '') {
            continue;
        }
        $map[$eventId][] = $row;
    }

    return $map;
}

function fetch_student_documents_map(array $eventIds, array $headers, ?string $studentId = null): array
{
    $eventIds = array_values(array_filter(array_map(
        static fn ($id): string => trim((string) $id),
        $eventIds
    )));

    if ($eventIds === []) {
        return [];
    }

    $inList = '(' . implode(',', array_map('rawurlencode', array_unique($eventIds))) . ')';
    $url = rtrim(SUPABASE_URL, '/') . '/rest/v1/event_student_documents'
        . '?select=id,event_id,requirement_id,student_id,file_name,file_path,file_url,mime_type,uploaded_at,updated_at'
        . '&event_id=in.' . $inList;

    if ($studentId !== null && trim($studentId) !== '') {
        $url .= '&student_id=eq.' . rawurlencode(trim($studentId));
    }

    $url .= '&order=uploaded_at.asc';

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
        $eventId = trim((string) ($row['event_id'] ?? ''));
        $sid = trim((string) ($row['student_id'] ?? ''));
        if ($eventId === '' || $sid === '') {
            continue;
        }
        $map[$eventId][$sid][] = $row;
    }

    return $map;
}

function fetch_student_submissions_map(array $eventIds, array $headers, ?string $studentId = null): array
{
    $eventIds = array_values(array_filter(array_map(
        static fn ($id): string => trim((string) $id),
        $eventIds
    )));

    if ($eventIds === []) {
        return [];
    }

    $inList = '(' . implode(',', array_map('rawurlencode', array_unique($eventIds))) . ')';
    $url = rtrim(SUPABASE_URL, '/') . '/rest/v1/event_student_submissions'
        . '?select=id,event_id,student_id,status,submitted_at,reviewed_at,reviewed_by,decline_reason,created_at,updated_at'
        . '&event_id=in.' . $inList;

    if ($studentId !== null && trim($studentId) !== '') {
        $url .= '&student_id=eq.' . rawurlencode(trim($studentId));
    }

    $url .= '&order=submitted_at.desc,created_at.desc';

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
        $eventId = trim((string) ($row['event_id'] ?? ''));
        $sid = trim((string) ($row['student_id'] ?? ''));
        if ($eventId === '' || $sid === '') {
            continue;
        }
        $map[$eventId][$sid] = $row;
    }

    return $map;
}

function build_student_requirement_summary(array $requirements, array $documents): array
{
    $total = count($requirements);
    if ($total === 0) {
        return [
            'total' => 0,
            'submitted' => 0,
            'complete' => true,
            'percent' => 100,
        ];
    }

    $uploadedIds = [];
    foreach ($documents as $doc) {
        if (!is_array($doc)) {
            continue;
        }
        $reqId = trim((string) ($doc['requirement_id'] ?? ''));
        if ($reqId !== '') {
            $uploadedIds[$reqId] = true;
        }
    }

    $submitted = 0;
    foreach ($requirements as $req) {
        if (!is_array($req)) {
            continue;
        }
        $reqId = trim((string) ($req['id'] ?? ''));
        if ($reqId !== '' && isset($uploadedIds[$reqId])) {
            $submitted++;
        }
    }

    return [
        'total' => $total,
        'submitted' => $submitted,
        'complete' => $submitted >= $total,
        'percent' => $total > 0 ? (int) round(($submitted / $total) * 100) : 100,
    ];
}

function save_student_requirements(
    string $eventId,
    array $requirements,
    string $createdBy,
    array $headers
): array {
    $eventId = trim($eventId);
    if ($eventId === '') {
        return ['ok' => false, 'error' => 'Missing event id.'];
    }

    $requirements = normalize_student_requirement_input($requirements);
    $existingRows = fetch_student_requirements_map([$eventId], $headers)[$eventId] ?? [];

    $existingBySig = [];
    foreach ($existingRows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $existingBySig[student_requirement_signature($row)] = $row;
    }

    $keepIds = [];
    $toInsert = [];

    foreach ($requirements as $requirement) {
        $sig = student_requirement_signature($requirement);
        if (isset($existingBySig[$sig])) {
            $keepIds[] = (string) ($existingBySig[$sig]['id'] ?? '');
            continue;
        }
        $toInsert[] = [
            'event_id' => $eventId,
            'code' => $requirement['code'],
            'label' => $requirement['label'],
            'sort_order' => (int) ($requirement['sort_order'] ?? 0),
            'created_by' => $createdBy !== '' ? $createdBy : null,
        ];
    }

    $deleteIds = [];
    foreach ($existingRows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $id = trim((string) ($row['id'] ?? ''));
        if ($id !== '' && !in_array($id, $keepIds, true)) {
            $deleteIds[] = $id;
        }
    }

    if ($deleteIds !== []) {
        $inList = '(' . implode(',', array_map('rawurlencode', $deleteIds)) . ')';
        $deleteUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/event_student_requirements?id=in.' . $inList;
        supabase_request('DELETE', $deleteUrl, student_requirement_write_headers());
    }

    if ($toInsert !== []) {
        $insertPayload = json_encode($toInsert, JSON_UNESCAPED_SLASHES);
        if (!is_string($insertPayload)) {
            return ['ok' => false, 'error' => 'Unable to prepare student requirements payload.'];
        }

        $insertUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/event_student_requirements';
        $insertRes = supabase_request('POST', $insertUrl, student_requirement_write_headers(), $insertPayload);
        if (!$insertRes['ok']) {
            if (student_requirement_missing_table_error($insertRes)) {
                return [
                    'ok' => false,
                    'error' => 'Student requirements table is missing. Run supabase/migrations/038_event_student_requirements.sql in Supabase SQL Editor first.',
                ];
            }

            return [
                'ok' => false,
                'error' => build_error($insertRes['body'] ?? null, (int) ($insertRes['status'] ?? 0), $insertRes['error'] ?? null, 'Failed to save student requirements.'),
            ];
        }
    }

    $saved = fetch_student_requirements_map([$eventId], $headers)[$eventId] ?? [];

    return [
        'ok' => true,
        'requirements' => $saved,
    ];
}

function event_has_student_requirements(string $eventId, array $headers): bool
{
    $requirements = fetch_student_requirements_map([$eventId], $headers)[$eventId] ?? [];
    return count($requirements) > 0;
}

function resolve_student_document_access(
    string $eventId,
    string $studentId,
    array $headers
): array {
    $requirements = fetch_student_requirements_map([$eventId], $headers)[$eventId] ?? [];
    if ($requirements === []) {
        return [
            'required' => false,
            'complete' => true,
            'status' => '',
            'approved' => true,
            'decline_reason' => '',
            'message' => '',
            'summary' => build_student_requirement_summary([], []),
        ];
    }

    $documents = fetch_student_documents_map([$eventId], $headers, $studentId)[$eventId][$studentId] ?? [];
    $submission = fetch_student_submissions_map([$eventId], $headers, $studentId)[$eventId][$studentId] ?? null;
    $summary = build_student_requirement_summary($requirements, $documents);
    $status = is_array($submission) ? strtolower(trim((string) ($submission['status'] ?? ''))) : '';
    $declineReason = is_array($submission) ? trim((string) ($submission['decline_reason'] ?? '')) : '';

    if ($status === 'approved') {
        return [
            'required' => true,
            'complete' => true,
            'status' => 'approved',
            'approved' => true,
            'decline_reason' => '',
            'message' => '',
            'summary' => $summary,
            'submission' => $submission,
        ];
    }

    if ($status === 'pending_review') {
        return [
            'required' => true,
            'complete' => (bool) ($summary['complete'] ?? false),
            'status' => 'pending_review',
            'approved' => false,
            'decline_reason' => '',
            'message' => 'Your documents are under review. Registration will open after approval.',
            'summary' => $summary,
            'submission' => $submission,
        ];
    }

    if ($status === 'declined') {
        return [
            'required' => true,
            'complete' => (bool) ($summary['complete'] ?? false),
            'status' => 'declined',
            'approved' => false,
            'decline_reason' => $declineReason,
            'message' => $declineReason !== ''
                ? 'Your documents were declined: ' . $declineReason
                : 'Your documents were declined. Please update and resubmit.',
            'summary' => $summary,
            'submission' => $submission,
        ];
    }

    if (!($summary['complete'] ?? false)) {
        return [
            'required' => true,
            'complete' => false,
            'status' => 'incomplete',
            'approved' => false,
            'decline_reason' => '',
            'message' => 'Submit the required documents before registering.',
            'summary' => $summary,
            'submission' => $submission,
        ];
    }

    return [
        'required' => true,
        'complete' => true,
        'status' => 'ready_to_submit',
        'approved' => false,
        'decline_reason' => '',
        'message' => 'Submit your documents for review before registering.',
        'summary' => $summary,
        'submission' => $submission,
    ];
}

function student_document_public_url(string $path): string
{
    $segments = array_map('rawurlencode', array_filter(explode('/', $path), static fn ($part): bool => $part !== ''));
    return rtrim(SUPABASE_URL, '/') . '/storage/v1/object/public/student-documents/' . implode('/', $segments);
}

function student_document_extension(string $mimeType): string
{
    return match ($mimeType) {
        'image/png' => 'png',
        'image/webp' => 'webp',
        'application/pdf' => 'pdf',
        'application/msword' => 'doc',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
        default => 'jpg',
    };
}

function student_requirement_allowed_mime_types(): array
{
    return [
        'image/jpeg',
        'image/png',
        'image/webp',
        'application/pdf',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    ];
}

function notify_student_requirement_review(string $studentId, string $title, string $body, array $data = []): void
{
    $studentId = trim($studentId);
    if ($studentId === '') {
        return;
    }

    require_once __DIR__ . '/fcm.php';

    $res = supabase_request(
        'GET',
        rtrim(SUPABASE_URL, '/') . '/rest/v1/fcm_tokens?select=token&user_id=eq.' . rawurlencode($studentId),
        student_requirement_headers()
    );

    if (!$res['ok']) {
        return;
    }

    $rows = json_decode((string) $res['body'], true);
    $tokens = [];
    if (is_array($rows)) {
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $token = trim((string) ($row['token'] ?? ''));
            if ($token !== '') {
                $tokens[$token] = true;
            }
        }
    }

    if ($tokens !== []) {
        send_fcm_notification(array_keys($tokens), $title, $body, $data);
    }
}
