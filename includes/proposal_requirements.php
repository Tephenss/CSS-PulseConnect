<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/supabase.php';
require_once __DIR__ . '/helpers.php';

function proposal_requirement_headers(): array
{
    return [
        'Accept: application/json',
        'apikey: ' . SUPABASE_KEY,
        'Authorization: Bearer ' . SUPABASE_KEY,
    ];
}

function proposal_requirement_write_headers(): array
{
    return [
        'Content-Type: application/json',
        'Accept: application/json',
        'Prefer: return=representation',
        'apikey: ' . SUPABASE_KEY,
        'Authorization: Bearer ' . SUPABASE_KEY,
    ];
}

function proposal_requirement_missing_column_error(array $response): bool
{
    $body = strtolower((string) ($response['body'] ?? ''));
    return $body !== ''
        && str_contains($body, 'column')
        && (
            str_contains($body, 'does not exist')
            || str_contains($body, 'schema cache')
            || str_contains($body, 'could not find')
        );
}

function normalize_proposal_requirement_input(array $items): array
{
    $normalized = [];
    foreach ($items as $index => $item) {
        if (!is_array($item)) {
            continue;
        }

        $code = strtoupper(trim((string) ($item['code'] ?? '')));
        $label = trim((string) ($item['label'] ?? ''));

        if ($code === '' && $label === '') {
            continue;
        }

        if ($code === '') {
            $code = 'DOC';
        }

        if ($label === '') {
            $label = $code;
        }

        $key = $code . '|' . mb_strtolower($label);
        if (isset($normalized[$key])) {
            continue;
        }

        $normalized[$key] = [
            'code' => mb_substr($code, 0, 24),
            'label' => mb_substr($label, 0, 120),
            'sort_order' => count($normalized),
        ];
    }

    return array_values($normalized);
}

function proposal_requirement_signature(array $item): string
{
    $code = strtoupper(trim((string) ($item['code'] ?? '')));
    $label = trim((string) ($item['label'] ?? ''));

    if ($code === '') {
        $code = 'DOC';
    }

    if ($label === '') {
        $label = $code;
    }

    return mb_substr($code, 0, 24) . '|' . mb_strtolower(mb_substr($label, 0, 120));
}

function fetch_proposal_requirements_map(array $eventIds, array $headers): array
{
    $eventIds = array_values(array_filter(array_map(
        static fn($id): string => trim((string) $id),
        $eventIds
    )));

    if ($eventIds === []) {
        return [];
    }

    $inList = '(' . implode(',', array_map('rawurlencode', array_unique($eventIds))) . ')';
    $url = rtrim(SUPABASE_URL, '/') . '/rest/v1/event_proposal_requirements'
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
        $map[$eventId] ??= [];
        $map[$eventId][] = $row;
    }

    return $map;
}

function fetch_proposal_submissions_map(array $eventIds, array $headers, ?bool $adminVisibleOnly = null): array
{
    $eventIds = array_values(array_filter(array_map(
        static fn($id): string => trim((string) $id),
        $eventIds
    )));

    if ($eventIds === []) {
        return [];
    }

    $inList = '(' . implode(',', array_map('rawurlencode', array_unique($eventIds))) . ')';
    $baseUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/event_proposal_documents'
        . '?select=id,event_id,requirement_id,teacher_id,file_name,file_path,file_url,mime_type,admin_visible,visible_at,uploaded_at,updated_at'
        . '&event_id=in.' . $inList;

    if ($adminVisibleOnly === true) {
        $baseUrl .= '&admin_visible=is.true';
    }

    $url = $baseUrl . '&order=updated_at.desc,uploaded_at.desc';

    $res = supabase_request('GET', $url, $headers);
    if (!$res['ok'] && proposal_requirement_missing_column_error($res)) {
        if ($adminVisibleOnly === true) {
            return [];
        }

        $fallbackUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/event_proposal_documents'
            . '?select=id,event_id,requirement_id,teacher_id,file_name,file_path,file_url,mime_type,uploaded_at,updated_at'
            . '&event_id=in.' . $inList
            . '&order=updated_at.desc,uploaded_at.desc';
        $res = supabase_request('GET', $fallbackUrl, $headers);
    }

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
        $requirementId = trim((string) ($row['requirement_id'] ?? ''));
        if ($eventId === '' || $requirementId === '') {
            continue;
        }
        $map[$eventId] ??= [];
        $map[$eventId][$requirementId] = $row;
    }

    return $map;
}

function build_proposal_requirement_summary(array $requirements, array $submissionMap): array
{
    $total = count($requirements);
    $submitted = 0;

    foreach ($requirements as $requirement) {
        if (!is_array($requirement)) {
            continue;
        }
        $requirementId = trim((string) ($requirement['id'] ?? ''));
        if ($requirementId === '') {
            continue;
        }
        $submission = $submissionMap[$requirementId] ?? null;
        $hasFile = is_array($submission)
            && trim((string) ($submission['file_url'] ?? $submission['file_path'] ?? '')) !== '';
        if ($hasFile) {
            $submitted += 1;
        }
    }

    return [
        'total' => $total,
        'submitted' => $submitted,
        'complete' => $total > 0 && $submitted >= $total,
        'percent' => $total > 0 ? (int) round(($submitted / $total) * 100) : 0,
    ];
}

function save_proposal_requirements(
    string $eventId,
    array $requirements,
    string $adminId,
    array $headers
): array {
    $requirements = normalize_proposal_requirement_input($requirements);
    if ($requirements === []) {
        return ['ok' => false, 'error' => 'Add at least one required document before sending the request.'];
    }

    $existingRows = fetch_proposal_requirements_map([$eventId], $headers)[$eventId] ?? [];
    $existingSubmissionMap = fetch_proposal_submissions_map([$eventId], $headers)[$eventId] ?? [];
    $existingByKey = [];
    $duplicateRequirementIds = [];
    $duplicateRequirementMoves = [];
    foreach ($existingRows as $row) {
        if (!is_array($row)) {
            continue;
        }

        $key = proposal_requirement_signature($row);
        $rowId = trim((string) ($row['id'] ?? ''));
        if ($rowId === '') {
            continue;
        }

        if (isset($existingByKey[$key])) {
            $duplicateRequirementIds[] = $rowId;
            $keptId = trim((string) ($existingByKey[$key]['id'] ?? ''));
            if ($keptId !== '') {
                $duplicateRequirementMoves[$rowId] = $keptId;
            }
            continue;
        }

        $existingByKey[$key] = $row;
    }

    $matchedKeys = [];
    $requirementsToInsert = [];
    $requirementsToPatch = [];

    foreach ($requirements as $index => $requirement) {
        $key = proposal_requirement_signature($requirement);
        $sortOrder = (int) ($requirement['sort_order'] ?? $index);

        if (isset($existingByKey[$key])) {
            $matchedKeys[$key] = true;
            $existing = $existingByKey[$key];
            $existingId = trim((string) ($existing['id'] ?? ''));
            if ($existingId !== '' && (int) ($existing['sort_order'] ?? -1) !== $sortOrder) {
                $requirementsToPatch[] = [
                    'id' => $existingId,
                    'sort_order' => $sortOrder,
                ];
            }
            continue;
        }

        $requirementsToInsert[] = [
            'event_id' => $eventId,
            'code' => (string) ($requirement['code'] ?? 'DOC'),
            'label' => (string) ($requirement['label'] ?? 'Document'),
            'sort_order' => $sortOrder,
            'created_by' => $adminId,
        ];
    }

    $requirementIdsToDelete = $duplicateRequirementIds;
    foreach ($existingByKey as $key => $existingRow) {
        if (isset($matchedKeys[$key])) {
            continue;
        }

        $existingId = trim((string) ($existingRow['id'] ?? ''));
        if ($existingId !== '') {
            $requirementIdsToDelete[] = $existingId;
        }
    }

    foreach ($duplicateRequirementMoves as $fromRequirementId => $toRequirementId) {
        if ($fromRequirementId === '' || $toRequirementId === '' || $fromRequirementId === $toRequirementId) {
            continue;
        }

        $fromSubmission = $existingSubmissionMap[$fromRequirementId] ?? null;
        if (!is_array($fromSubmission)) {
            continue;
        }

        $toSubmission = $existingSubmissionMap[$toRequirementId] ?? null;
        $shouldPromote = !is_array($toSubmission);

        if (is_array($toSubmission)) {
            $fromUpdatedAt = strtotime((string) ($fromSubmission['updated_at'] ?? $fromSubmission['uploaded_at'] ?? '')) ?: 0;
            $toUpdatedAt = strtotime((string) ($toSubmission['updated_at'] ?? $toSubmission['uploaded_at'] ?? '')) ?: 0;
            if ($fromUpdatedAt > $toUpdatedAt) {
                $shouldPromote = true;
            }
        }

        if ($shouldPromote) {
            $upsertPayload = json_encode([
                'event_id' => $eventId,
                'requirement_id' => $toRequirementId,
                'teacher_id' => (string) ($fromSubmission['teacher_id'] ?? ''),
                'file_name' => (string) ($fromSubmission['file_name'] ?? ''),
                'file_path' => (string) ($fromSubmission['file_path'] ?? ''),
                'file_url' => (string) ($fromSubmission['file_url'] ?? ''),
                'mime_type' => (string) ($fromSubmission['mime_type'] ?? ''),
                'admin_visible' => (bool) ($fromSubmission['admin_visible'] ?? false),
                'visible_at' => $fromSubmission['visible_at'] ?? null,
                'uploaded_at' => (string) ($fromSubmission['uploaded_at'] ?? gmdate('c')),
                'updated_at' => (string) ($fromSubmission['updated_at'] ?? gmdate('c')),
            ], JSON_UNESCAPED_SLASHES);

            if (!is_string($upsertPayload)) {
                return ['ok' => false, 'error' => 'Unable to prepare the existing proposal upload merge payload.'];
            }

            $upsertUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/event_proposal_documents?on_conflict=requirement_id,teacher_id';
            $upsertHeaders = [
                'Content-Type: application/json',
                'Accept: application/json',
                'Prefer: resolution=merge-duplicates,return=representation',
                'apikey: ' . SUPABASE_KEY,
                'Authorization: Bearer ' . SUPABASE_KEY,
            ];
            $upsertRes = supabase_request(
                'POST',
                $upsertUrl,
                $upsertHeaders,
                $upsertPayload
            );

            if (!$upsertRes['ok']) {
                return ['ok' => false, 'error' => build_error($upsertRes['body'] ?? null, (int) ($upsertRes['status'] ?? 0), $upsertRes['error'] ?? null, 'Failed to preserve the existing uploaded proposal documents')];
            }
        }

        $existingSubmissionId = trim((string) ($fromSubmission['id'] ?? ''));
        if ($existingSubmissionId !== '') {
            $deleteMovedSubmissionUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/event_proposal_documents'
                . '?id=eq.' . rawurlencode($existingSubmissionId);
            $deleteMovedSubmissionRes = supabase_request('DELETE', $deleteMovedSubmissionUrl, $headers);
            if (!$deleteMovedSubmissionRes['ok']) {
                return ['ok' => false, 'error' => build_error($deleteMovedSubmissionRes['body'] ?? null, (int) ($deleteMovedSubmissionRes['status'] ?? 0), $deleteMovedSubmissionRes['error'] ?? null, 'Failed to finalize merged proposal uploads')];
            }
        }
    }

    if ($requirementIdsToDelete !== []) {
        $encodedIds = '(' . implode(',', array_map('rawurlencode', array_values(array_unique($requirementIdsToDelete)))) . ')';

        $deleteSubmissionsUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/event_proposal_documents'
            . '?requirement_id=in.' . $encodedIds;
        $deleteRes = supabase_request('DELETE', $deleteSubmissionsUrl, $headers);
        if (!$deleteRes['ok']) {
            return ['ok' => false, 'error' => build_error($deleteRes['body'] ?? null, (int) ($deleteRes['status'] ?? 0), $deleteRes['error'] ?? null, 'Failed to clear removed proposal document uploads')];
        }

        $deleteRequirementsUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/event_proposal_requirements'
            . '?id=in.' . $encodedIds;
        $deleteReqRes = supabase_request('DELETE', $deleteRequirementsUrl, $headers);
        if (!$deleteReqRes['ok']) {
            return ['ok' => false, 'error' => build_error($deleteReqRes['body'] ?? null, (int) ($deleteReqRes['status'] ?? 0), $deleteReqRes['error'] ?? null, 'Failed to remove old proposal requirements')];
        }
    }

    foreach ($requirementsToPatch as $patchRow) {
        $patchPayload = json_encode(['sort_order' => (int) ($patchRow['sort_order'] ?? 0)], JSON_UNESCAPED_SLASHES);
        if (!is_string($patchPayload)) {
            return ['ok' => false, 'error' => 'Unable to prepare the requirement update payload.'];
        }

        $patchUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/event_proposal_requirements'
            . '?id=eq.' . rawurlencode((string) $patchRow['id']);
        $patchRes = supabase_request('PATCH', $patchUrl, proposal_requirement_write_headers(), $patchPayload);
        if (!$patchRes['ok']) {
            return ['ok' => false, 'error' => build_error($patchRes['body'] ?? null, (int) ($patchRes['status'] ?? 0), $patchRes['error'] ?? null, 'Failed to update proposal requirement ordering')];
        }
    }

    if ($requirementsToInsert !== []) {
        $payload = json_encode($requirementsToInsert, JSON_UNESCAPED_SLASHES);
        if (!is_string($payload)) {
            return ['ok' => false, 'error' => 'Unable to prepare the requirement list payload.'];
        }

        $insertUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/event_proposal_requirements';
        $insertRes = supabase_request('POST', $insertUrl, proposal_requirement_write_headers(), $payload);
        if (!$insertRes['ok']) {
            return ['ok' => false, 'error' => build_error($insertRes['body'] ?? null, (int) ($insertRes['status'] ?? 0), $insertRes['error'] ?? null, 'Failed to save proposal requirements')];
        }
    }

    $eventPayload = json_encode([
        'proposal_stage' => 'requirements_requested',
        'requirements_requested_at' => gmdate('c'),
        'requirements_submitted_at' => null,
        'updated_at' => gmdate('c'),
    ], JSON_UNESCAPED_SLASHES);

    if (!is_string($eventPayload)) {
        return ['ok' => false, 'error' => 'Unable to prepare the event update payload.'];
    }

    $eventUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/events?id=eq.' . rawurlencode($eventId);
    $eventRes = supabase_request('PATCH', $eventUrl, proposal_requirement_write_headers(), $eventPayload);
    if (!$eventRes['ok']) {
        return ['ok' => false, 'error' => build_error($eventRes['body'] ?? null, (int) ($eventRes['status'] ?? 0), $eventRes['error'] ?? null, 'Failed to update the event requirement state')];
    }

    return [
        'ok' => true,
        'count' => count($requirements),
    ];
}
