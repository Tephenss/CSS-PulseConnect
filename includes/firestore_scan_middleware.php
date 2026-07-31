<?php
declare(strict_types=1);

/**
 * Firestore scan ingress middleware (assist layer — no PII).
 *
 * Records minimal scan ingress in Firestore before Supabase writes so burst
 * traffic is buffered/signaled without exposing user data to clients.
 * Supabase remains the authoritative attendance store.
 */
require_once __DIR__ . '/firestore_catalog.php';

/** Max pending ingress signals per event before temporary throttle. */
const FIRESTORE_SCAN_PENDING_THROTTLE = 80;

function firestore_scan_middleware_enabled(): bool
{
    return firestore_access_token() !== null && firestore_project_id() !== null;
}

function firestore_scan_subject_hash(string $subjectKey): string
{
    $key = trim($subjectKey);
    if ($key === '') {
        return hash('sha256', 'empty');
    }
    return hash('sha256', $key);
}

/**
 * @return array{pending_count:int,revision:int,last_ingress_at?:string}|null
 */
function firestore_scan_read_signal(string $eventId): ?array
{
    $eventId = trim($eventId);
    if ($eventId === '' || !firestore_scan_middleware_enabled()) {
        return null;
    }

    $url = firestore_doc_url('scan_ingress_signals/' . rawurlencode($eventId));
    if ($url === null) {
        return null;
    }

    $res = firestore_request('GET', $url);
    if (($res['ok'] ?? false) !== true) {
        return ['pending_count' => 0, 'revision' => 0];
    }

    $decoded = json_decode((string) ($res['body'] ?? ''), true);
    if (!is_array($decoded) || !isset($decoded['fields']) || !is_array($decoded['fields'])) {
        return ['pending_count' => 0, 'revision' => 0];
    }

    $fields = firestore_decode_fields($decoded['fields']);
    return [
        'pending_count' => max(0, (int) ($fields['pending_count'] ?? 0)),
        'revision' => max(0, (int) ($fields['revision'] ?? 0)),
        'last_ingress_at' => isset($fields['last_ingress_at']) ? (string) $fields['last_ingress_at'] : '',
    ];
}

function firestore_scan_should_throttle(string $eventId): bool
{
    $signal = firestore_scan_read_signal($eventId);
    if ($signal === null) {
        return false;
    }
    return (int) ($signal['pending_count'] ?? 0) >= FIRESTORE_SCAN_PENDING_THROTTLE;
}

function firestore_scan_bump_signal(string $eventId, int $pendingDelta = 0, ?string $status = null): void
{
    $eventId = trim($eventId);
    if ($eventId === '' || !firestore_scan_middleware_enabled()) {
        return;
    }

    $url = firestore_doc_url('scan_ingress_signals/' . rawurlencode($eventId));
    if ($url === null) {
        return;
    }

    $existing = ['pending_count' => 0, 'revision' => 0];
    $documentExists = false;
    $readRes = firestore_request('GET', $url);
    if (($readRes['ok'] ?? false) === true) {
        $decoded = json_decode((string) ($readRes['body'] ?? ''), true);
        if (is_array($decoded) && isset($decoded['fields']) && is_array($decoded['fields'])) {
            $documentExists = true;
            $fieldsDecoded = firestore_decode_fields($decoded['fields']);
            $existing = [
                'pending_count' => max(0, (int) ($fieldsDecoded['pending_count'] ?? 0)),
                'revision' => max(0, (int) ($fieldsDecoded['revision'] ?? 0)),
            ];
        }
    }

    $pending = max(0, (int) ($existing['pending_count'] ?? 0) + $pendingDelta);
    $revision = (int) ($existing['revision'] ?? 0) + 1;
    $now = gmdate('c');

    $fields = [
        'event_id' => $eventId,
        'pending_count' => $pending,
        'revision' => $revision,
        'updated_at' => $now,
    ];
    if ($pendingDelta > 0) {
        $fields['last_ingress_at'] = $now;
    }
    if ($status !== null && $status !== '') {
        $fields['last_status'] = $status;
        $fields['last_status_at'] = $now;
    }

    $mask = http_build_query([
        'updateMask.fieldPaths' => array_keys($fields),
    ]);
    if (!$documentExists) {
        $mask .= '&currentDocument.exists=false';
    }

    try {
        firestore_request('PATCH', $url . '?' . $mask, [
            'fields' => firestore_encode_fields($fields),
        ]);
    } catch (Throwable $e) {
        error_log('firestore_scan_bump_signal exception: ' . $e->getMessage());
    }
}

/**
 * Record scan ingress in Firestore before Supabase write (no names/tokens).
 */
function firestore_scan_record_ingress(string $eventId, string $scanKind, string $subjectHash): ?string
{
    $eventId = trim($eventId);
    $scanKind = trim($scanKind);
    if ($eventId === '' || $scanKind === '' || !firestore_scan_middleware_enabled()) {
        return null;
    }

    $jobId = bin2hex(random_bytes(12));
    $url = firestore_doc_url('scan_ingress_jobs/' . $jobId);
    if ($url === null) {
        return null;
    }

    $now = gmdate('c');
    $fields = [
        'event_id' => $eventId,
        'scan_kind' => $scanKind,
        'subject_hash' => $subjectHash,
        'status' => 'pending',
        'created_at' => $now,
        'updated_at' => $now,
    ];

    try {
        $res = firestore_request('PATCH', $url . '?updateMask.fieldPaths=' . implode(
            '&updateMask.fieldPaths=',
            array_map('rawurlencode', array_keys($fields))
        ) . '&currentDocument.exists=false', [
            'fields' => firestore_encode_fields($fields),
        ]);
        if (($res['ok'] ?? false) !== true) {
            error_log('firestore_scan_record_ingress failed: HTTP ' . (int) ($res['status'] ?? 0));
            return null;
        }
        firestore_scan_bump_signal($eventId, 1, 'ingress');
        return $jobId;
    } catch (Throwable $e) {
        error_log('firestore_scan_record_ingress exception: ' . $e->getMessage());
        return null;
    }
}

function firestore_scan_complete_ingress(?string $jobId, string $eventId, string $status): void
{
    $eventId = trim($eventId);
    $status = trim($status);
    if ($eventId === '' || $status === '' || !firestore_scan_middleware_enabled()) {
        return;
    }

    if ($jobId !== null && $jobId !== '') {
        $url = firestore_doc_url('scan_ingress_jobs/' . rawurlencode($jobId));
        if ($url !== null) {
            $now = gmdate('c');
            try {
                firestore_request('PATCH', $url . '?' . http_build_query([
                    'updateMask.fieldPaths' => ['status', 'updated_at'],
                ]), [
                    'fields' => firestore_encode_fields([
                        'status' => $status,
                        'updated_at' => $now,
                    ]),
                ]);
            } catch (Throwable $e) {
                error_log('firestore_scan_complete_ingress job exception: ' . $e->getMessage());
            }
        }
    }

    firestore_scan_bump_signal($eventId, -1, $status);
}
