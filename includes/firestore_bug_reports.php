<?php
declare(strict_types=1);

require_once __DIR__ . '/firestore_catalog.php';

function firestore_bug_report_valid_id(string $id): bool
{
    return preg_match('/^[a-f0-9]{24}$/', $id) === 1;
}

function firestore_bug_report_normalize_ticket(string $ticket): ?string
{
    $ticket = strtoupper(trim($ticket));
    $ticket = preg_replace('/^PC[-\\s]*/', '', $ticket) ?? '';
    $compact = preg_replace('/[-\\s]+/', '', $ticket) ?? '';
    if (preg_match('/^[A-F0-9]{24}$/', $compact) !== 1) {
        return null;
    }
    return strtolower($compact);
}

function firestore_bug_report_ticket_code(string $id): string
{
    if (!firestore_bug_report_valid_id($id)) {
        return '';
    }
    return 'PC-' . implode('-', str_split(strtoupper($id), 4));
}

function firestore_bug_report_create(array $fields): ?string
{
    $id = bin2hex(random_bytes(12));
    $url = firestore_doc_url('bug_reports/' . $id);
    if ($url === null) {
        return null;
    }

    $res = firestore_request('PATCH', $url, [
        'fields' => firestore_encode_fields($fields),
    ]);
    if (!($res['ok'] ?? false)) {
        $detail = trim((string) ($res['error'] ?? ''));
        if ($detail === '' && is_string($res['body'] ?? null)) {
            $decoded = json_decode($res['body'], true);
            $detail = is_array($decoded) ? (string) ($decoded['error']['message'] ?? '') : '';
        }
        $detail = preg_replace('/[\x00-\x1F\x7F]+/', ' ', $detail) ?? '';
        error_log('firestore_bug_report_create failed: HTTP ' . (int) ($res['status'] ?? 0)
            . ($detail !== '' ? '; ' . substr($detail, 0, 200) : ''));
        return null;
    }
    return $id;
}

function firestore_bug_report_list(int $limit = 100): ?array
{
    $baseUrl = firestore_doc_url('bug_reports');
    if ($baseUrl === null) {
        return null;
    }

    $query = [
        'pageSize=' . max(1, min(200, $limit)),
        'orderBy=created_at%20desc',
    ];
    foreach (['id', 'message', 'created_at', 'updated_at', 'status', 'review_note', 'has_image', 'image_mime'] as $field) {
        $query[] = 'mask.fieldPaths=' . rawurlencode($field);
    }

    $res = firestore_request('GET', $baseUrl . '?' . implode('&', $query));
    if (!($res['ok'] ?? false)) {
        return null;
    }

    $decoded = json_decode((string) ($res['body'] ?? ''), true);
    $documents = is_array($decoded) && is_array($decoded['documents'] ?? null)
        ? $decoded['documents']
        : [];
    $reports = [];
    foreach ($documents as $document) {
        if (!is_array($document) || !is_array($document['fields'] ?? null)) {
            continue;
        }
        $report = firestore_decode_fields($document['fields']);
        $name = (string) ($document['name'] ?? '');
        $report['id'] = trim((string) ($report['id'] ?? basename($name)));
        if (firestore_bug_report_valid_id($report['id'])) {
            $reports[] = $report;
        }
    }

    usort($reports, static fn(array $a, array $b): int =>
        strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? ''))
    );
    return $reports;
}

function firestore_bug_report_get(string $id): ?array
{
    if (!firestore_bug_report_valid_id($id)) {
        return null;
    }
    $url = firestore_doc_url('bug_reports/' . $id);
    if ($url === null) {
        return null;
    }

    $res = firestore_request('GET', $url);
    if (!($res['ok'] ?? false)) {
        return null;
    }
    $decoded = json_decode((string) ($res['body'] ?? ''), true);
    if (!is_array($decoded) || !is_array($decoded['fields'] ?? null)) {
        return null;
    }

    $report = firestore_decode_fields($decoded['fields']);
    $report['id'] = $id;
    return $report;
}

function firestore_bug_report_update(string $id, string $status, string $reviewNote, string $publicUpdate): bool
{
    if (!firestore_bug_report_valid_id($id)) {
        return false;
    }
    $url = firestore_doc_url('bug_reports/' . $id);
    if ($url === null) {
        return false;
    }

    $mask = implode('&', array_map(
        static fn(string $field): string => 'updateMask.fieldPaths=' . rawurlencode($field),
        ['status', 'review_note', 'public_update', 'updated_at']
    ));
    $res = firestore_request('PATCH', $url . '?' . $mask, [
        'fields' => firestore_encode_fields([
            'status' => $status,
            'review_note' => $reviewNote,
            'public_update' => $publicUpdate,
            'updated_at' => (new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))->format(DATE_ATOM),
        ]),
    ]);
    return (bool) ($res['ok'] ?? false);
}
