<?php
declare(strict_types=1);

require_once __DIR__ . '/supabase.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/event_sessions.php';

function rollback_draft_proposal_event(string $eventId, string $teacherId, array $headers): array
{
    $eventId = trim($eventId);
    $teacherId = trim($teacherId);
    if ($eventId === '' || $teacherId === '') {
        return ['ok' => false, 'error' => 'Missing event or teacher id.'];
    }

    $eventUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/events'
        . '?id=eq.' . rawurlencode($eventId)
        . '&select=id,status,created_by,proposal_stage'
        . '&limit=1';
    $eventRes = supabase_request('GET', $eventUrl, $headers);
    if (!$eventRes['ok']) {
        return ['ok' => false, 'error' => build_error($eventRes['body'] ?? null, (int) ($eventRes['status'] ?? 0), $eventRes['error'] ?? null, 'Unable to load the proposal event')];
    }

    $rows = json_decode((string) ($eventRes['body'] ?? ''), true);
    $event = is_array($rows) && isset($rows[0]) && is_array($rows[0]) ? $rows[0] : null;
    if (!is_array($event)) {
        return ['ok' => false, 'error' => 'Proposal event not found.'];
    }

    if (trim((string) ($event['created_by'] ?? '')) !== $teacherId) {
        return ['ok' => false, 'error' => 'You can only roll back your own draft proposal.'];
    }

    $status = strtolower(trim((string) ($event['status'] ?? '')));
    if ($status !== 'pending') {
        return ['ok' => false, 'error' => 'Only pending draft proposals can be rolled back.'];
    }

    $stage = strtolower(trim((string) ($event['proposal_stage'] ?? 'pending_requirements')));
    if (!in_array($stage, ['pending_requirements', ''], true)) {
        return ['ok' => false, 'error' => 'This proposal can no longer be rolled back automatically.'];
    }

    $deleteHeaders = [
        'Accept: application/json',
        'apikey: ' . SUPABASE_KEY,
        'Authorization: Bearer ' . SUPABASE_KEY,
    ];

    $tables = [
        'event_proposal_documents',
        'event_proposal_requirements',
        'event_student_requirements',
        'event_student_submissions',
        'event_student_documents',
        'event_registrations',
        'event_sessions',
    ];

    foreach ($tables as $table) {
        $deleteUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/' . $table
            . '?event_id=eq.' . rawurlencode($eventId);
        $deleteRes = supabase_request('DELETE', $deleteUrl, $deleteHeaders);
        if (!$deleteRes['ok']) {
            return ['ok' => false, 'error' => build_error($deleteRes['body'] ?? null, (int) ($deleteRes['status'] ?? 0), $deleteRes['error'] ?? null, 'Failed to roll back related proposal data')];
        }
    }

    $eventDeleteUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/events?id=eq.' . rawurlencode($eventId);
    $eventDeleteRes = supabase_request('DELETE', $eventDeleteUrl, $deleteHeaders);
    if (!$eventDeleteRes['ok']) {
        return ['ok' => false, 'error' => build_error($eventDeleteRes['body'] ?? null, (int) ($eventDeleteRes['status'] ?? 0), $eventDeleteRes['error'] ?? null, 'Failed to roll back the proposal event')];
    }

    return ['ok' => true];
}
