<?php
declare(strict_types=1);

require_once __DIR__ . '/supabase.php';

function document_review_access_headers(): array
{
    return [
        'Accept: application/json',
        'apikey: ' . SUPABASE_KEY,
        'Authorization: Bearer ' . SUPABASE_KEY,
    ];
}

function document_review_access_write_headers(): array
{
    return [
        'Content-Type: application/json',
        'Accept: application/json',
        'Prefer: return=representation,resolution=merge-duplicates',
        'apikey: ' . SUPABASE_KEY,
        'Authorization: Bearer ' . SUPABASE_KEY,
    ];
}

function document_review_teacher_display_name(array $row): string
{
    $name = trim(implode(' ', array_filter([
        (string) ($row['first_name'] ?? ''),
        (string) ($row['middle_name'] ?? ''),
        (string) ($row['last_name'] ?? ''),
        (string) ($row['suffix'] ?? ''),
    ], static fn($p) => trim((string) $p) !== '')));

    return $name !== '' ? $name : 'Teacher';
}

function event_teacher_can_review_documents(string $eventId, string $teacherId): bool
{
    $eventId = trim($eventId);
    $teacherId = trim($teacherId);
    if ($eventId === '' || $teacherId === '') {
        return false;
    }

    $url = rtrim(SUPABASE_URL, '/') . '/rest/v1/event_teacher_assignments'
        . '?select=id'
        . '&event_id=eq.' . rawurlencode($eventId)
        . '&teacher_id=eq.' . rawurlencode($teacherId)
        . '&can_review_documents=eq.true'
        . '&limit=1';
    $res = supabase_request('GET', $url, document_review_access_headers());
    if (!($res['ok'] ?? false)) {
        return false;
    }
    $rows = json_decode((string) ($res['body'] ?? ''), true);

    return is_array($rows) && isset($rows[0]);
}

function event_user_can_review_documents(string $eventId, string $userId, string $role, bool $isCreator): bool
{
    $role = strtolower(trim($role));
    if ($role === 'admin' || $isCreator) {
        return true;
    }
    if ($role !== 'teacher') {
        return false;
    }

    return event_teacher_can_review_documents($eventId, $userId);
}

/**
 * @return list<array<string, mixed>>
 */
function event_document_reviewers_list(string $eventId): array
{
    $eventId = trim($eventId);
    if ($eventId === '') {
        return [];
    }

    $url = rtrim(SUPABASE_URL, '/') . '/rest/v1/event_teacher_assignments'
        . '?select=teacher_id,assigned_at,can_review_documents'
        . '&event_id=eq.' . rawurlencode($eventId)
        . '&can_review_documents=eq.true'
        . '&order=assigned_at.asc'
        . '&limit=200';
    $res = supabase_request('GET', $url, document_review_access_headers());
    if (!($res['ok'] ?? false)) {
        return [];
    }
    $rows = json_decode((string) ($res['body'] ?? ''), true);
    if (!is_array($rows) || $rows === []) {
        return [];
    }

    $ids = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $id = trim((string) ($row['teacher_id'] ?? ''));
        if ($id !== '') {
            $ids[] = $id;
        }
    }
    if ($ids === []) {
        return [];
    }

    $profiles = document_review_load_teacher_profiles($ids);
    $out = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $id = trim((string) ($row['teacher_id'] ?? ''));
        if ($id === '' || !isset($profiles[$id])) {
            continue;
        }
        $profile = $profiles[$id];
        $out[] = [
            'teacher_id' => $id,
            'name' => document_review_teacher_display_name($profile),
            'email' => (string) ($profile['email'] ?? ''),
        ];
    }

    return $out;
}

/**
 * @param list<string> $teacherIds
 * @return array<string, array<string, mixed>>
 */
function document_review_load_teacher_profiles(array $teacherIds): array
{
    $ids = [];
    foreach ($teacherIds as $id) {
        $id = trim((string) $id);
        if ($id !== '') {
            $ids[$id] = true;
        }
    }
    if ($ids === []) {
        return [];
    }

    $inList = '(' . implode(',', array_map('rawurlencode', array_keys($ids))) . ')';
    $url = rtrim(SUPABASE_URL, '/') . '/rest/v1/' . SUPABASE_TABLE_USERS
        . '?select=id,first_name,middle_name,last_name,suffix,email,role,archived_at'
        . '&id=in.' . $inList
        . '&role=eq.teacher'
        . '&limit=200';
    $res = supabase_request('GET', $url, document_review_access_headers());
    if (!($res['ok'] ?? false) && str_contains((string) ($res['body'] ?? ''), 'archived_at')) {
        $url = rtrim(SUPABASE_URL, '/') . '/rest/v1/' . SUPABASE_TABLE_USERS
            . '?select=id,first_name,middle_name,last_name,suffix,email,role'
            . '&id=in.' . $inList
            . '&role=eq.teacher'
            . '&limit=200';
        $res = supabase_request('GET', $url, document_review_access_headers());
    }
    $rows = ($res['ok'] ?? false) ? json_decode((string) ($res['body'] ?? ''), true) : [];
    $map = [];
    if (is_array($rows)) {
        foreach ($rows as $row) {
            if (!is_array($row) || !empty($row['archived_at'])) {
                continue;
            }
            $id = trim((string) ($row['id'] ?? ''));
            if ($id !== '') {
                $map[$id] = $row;
            }
        }
    }

    return $map;
}

/**
 * @return list<array<string, mixed>>
 */
function document_review_eligible_teachers(string $eventId, string $creatorId): array
{
    $url = rtrim(SUPABASE_URL, '/') . '/rest/v1/' . SUPABASE_TABLE_USERS
        . '?select=id,first_name,middle_name,last_name,suffix,email,archived_at'
        . '&role=eq.teacher'
        . '&archived_at=is.null'
        . '&order=last_name.asc,first_name.asc'
        . '&limit=500';
    $res = supabase_request('GET', $url, document_review_access_headers());
    if (!($res['ok'] ?? false) && str_contains((string) ($res['body'] ?? ''), 'archived_at')) {
        $url = rtrim(SUPABASE_URL, '/') . '/rest/v1/' . SUPABASE_TABLE_USERS
            . '?select=id,first_name,middle_name,last_name,suffix,email'
            . '&role=eq.teacher'
            . '&order=last_name.asc,first_name.asc'
            . '&limit=500';
        $res = supabase_request('GET', $url, document_review_access_headers());
    }
    $rows = ($res['ok'] ?? false) ? json_decode((string) ($res['body'] ?? ''), true) : [];
    if (!is_array($rows)) {
        return [];
    }

    $grantedIds = [];
    foreach (event_document_reviewers_list($eventId) as $granted) {
        $grantedIds[(string) ($granted['teacher_id'] ?? '')] = true;
    }

    $out = [];
    foreach ($rows as $row) {
        if (!is_array($row) || !empty($row['archived_at'])) {
            continue;
        }
        $id = trim((string) ($row['id'] ?? ''));
        if ($id === '' || $id === $creatorId || isset($grantedIds[$id])) {
            continue;
        }
        $out[] = [
            'id' => $id,
            'name' => document_review_teacher_display_name($row),
            'email' => (string) ($row['email'] ?? ''),
        ];
    }

    return $out;
}

function event_document_review_set_access(
    string $eventId,
    string $teacherId,
    bool $enabled,
    string $assignedBy
): array {
    $eventId = trim($eventId);
    $teacherId = trim($teacherId);
    $assignedBy = trim($assignedBy);
    if ($eventId === '' || $teacherId === '') {
        return ['ok' => false, 'error' => 'Missing event or teacher.'];
    }

    $getUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/event_teacher_assignments'
        . '?select=id,can_scan,can_manage_assistants,can_review_documents'
        . '&event_id=eq.' . rawurlencode($eventId)
        . '&teacher_id=eq.' . rawurlencode($teacherId)
        . '&limit=1';
    $getRes = supabase_request('GET', $getUrl, document_review_access_headers());
    if (!($getRes['ok'] ?? false) && str_contains((string) ($getRes['body'] ?? ''), 'can_review_documents')) {
        return ['ok' => false, 'error' => 'Run supabase/migrations/062_event_document_review_access.sql'];
    }
    $rows = ($getRes['ok'] ?? false) ? json_decode((string) ($getRes['body'] ?? ''), true) : [];
    $existing = is_array($rows) && isset($rows[0]) && is_array($rows[0]) ? $rows[0] : null;

    $canScan = is_array($existing) ? !empty($existing['can_scan']) : false;
    $canAssist = is_array($existing) ? !empty($existing['can_manage_assistants']) : false;

    $payload = [
        'event_id' => $eventId,
        'teacher_id' => $teacherId,
        'can_scan' => $canScan,
        'can_manage_assistants' => $canAssist,
        'can_review_documents' => $enabled,
        'assigned_by' => $assignedBy !== '' ? $assignedBy : null,
        'assigned_at' => gmdate('c'),
    ];

    $postUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/event_teacher_assignments'
        . '?on_conflict=event_id,teacher_id';
    $postRes = supabase_request(
        'POST',
        $postUrl,
        document_review_access_write_headers(),
        json_encode($payload, JSON_UNESCAPED_SLASHES)
    );
    if (!($postRes['ok'] ?? false)) {
        $body = strtolower((string) ($postRes['body'] ?? ''));
        if (str_contains($body, 'can_review_documents')) {
            return ['ok' => false, 'error' => 'Run supabase/migrations/062_event_document_review_access.sql'];
        }
        return [
            'ok' => false,
            'error' => build_error(
                $postRes['body'] ?? null,
                (int) ($postRes['status'] ?? 0),
                $postRes['error'] ?? null,
                'Failed to update review access'
            ),
        ];
    }

    return ['ok' => true];
}

function document_review_load_user_profiles(array $userIds): array
{
    $ids = [];
    foreach ($userIds as $id) {
        $id = trim((string) $id);
        if ($id !== '') {
            $ids[$id] = true;
        }
    }
    if ($ids === []) {
        return [];
    }

    $inList = '(' . implode(',', array_map('rawurlencode', array_keys($ids))) . ')';
    $url = rtrim(SUPABASE_URL, '/') . '/rest/v1/' . SUPABASE_TABLE_USERS
        . '?select=id,first_name,middle_name,last_name,suffix,email,role'
        . '&id=in.' . $inList
        . '&limit=200';
    $res = supabase_request('GET', $url, document_review_access_headers());
    $rows = ($res['ok'] ?? false) ? json_decode((string) ($res['body'] ?? ''), true) : [];
    $map = [];
    if (is_array($rows)) {
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = trim((string) ($row['id'] ?? ''));
            if ($id !== '') {
                $map[$id] = $row;
            }
        }
    }

    return $map;
}

function document_review_conflict_message(array $submission): string
{
    $status = strtolower(trim((string) ($submission['status'] ?? '')));
    $label = $status === 'approved' ? 'approved' : ($status === 'declined' ? 'declined' : 'reviewed');
    $reviewerId = trim((string) ($submission['reviewed_by'] ?? ''));
    $who = 'another teacher';
    if ($reviewerId !== '') {
        $profiles = document_review_load_user_profiles([$reviewerId]);
        if (isset($profiles[$reviewerId])) {
            $who = document_review_teacher_display_name($profiles[$reviewerId]);
        }
    }

    return 'Already ' . $label . ' by ' . $who . '. Refresh to see the latest status.';
}
