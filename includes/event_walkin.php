<?php
declare(strict_types=1);

require_once __DIR__ . '/firestore_catalog.php';
require_once __DIR__ . '/student_roster.php';

function event_walkin_firebase_ready(): bool
{
    $serviceAccount = firestore_service_account();
    return firestore_project_id() !== null
        && is_array($serviceAccount)
        && trim((string) ($serviceAccount['client_email'] ?? '')) !== ''
        && trim((string) ($serviceAccount['private_key'] ?? '')) !== '';
}

function event_walkin_document_id(string $eventId, string $sessionId, string $rosterId): string
{
    return hash('sha256', trim($eventId) . "\0" . trim($sessionId) . "\0" . trim($rosterId));
}

function event_walkin_firestore_document_name(string $eventId, string $documentId): ?string
{
    $projectId = firestore_project_id();
    if ($projectId === null) {
        return null;
    }

    return 'projects/' . $projectId . '/databases/(default)/documents/event_walkin_events/'
        . rawurlencode($eventId) . '/participants/' . rawurlencode($documentId);
}

/** @return array{ok:bool,exists:bool,row:?array,update_time:string,error:string} */
function event_walkin_firestore_get(string $eventId, string $documentId): array
{
    $url = firestore_doc_url(
        'event_walkin_events/' . rawurlencode($eventId) . '/participants/' . rawurlencode($documentId)
    );
    if ($url === null) {
        return ['ok' => false, 'exists' => false, 'row' => null, 'update_time' => '', 'error' => 'Firebase credentials are unavailable.'];
    }

    $res = firestore_request('GET', $url);
    if (!$res['ok'] && (int) ($res['status'] ?? 0) === 404) {
        return ['ok' => true, 'exists' => false, 'row' => null, 'update_time' => '', 'error' => ''];
    }
    if (!$res['ok']) {
        return ['ok' => false, 'exists' => false, 'row' => null, 'update_time' => '', 'error' => 'Could not read walk-in attendance from Firebase.'];
    }

    $doc = json_decode((string) ($res['body'] ?? ''), true);
    if (!is_array($doc) || !isset($doc['fields']) || !is_array($doc['fields'])) {
        return ['ok' => false, 'exists' => false, 'row' => null, 'update_time' => '', 'error' => 'Firebase returned an invalid attendance record.'];
    }

    return [
        'ok' => true,
        'exists' => true,
        'row' => firestore_decode_fields($doc['fields']),
        'update_time' => (string) ($doc['updateTime'] ?? ''),
        'error' => '',
    ];
}

/** Create a time-in record only if its deterministic document does not exist. */
function event_walkin_firestore_create(string $eventId, string $documentId, array $fields): array
{
    $name = event_walkin_firestore_document_name($eventId, $documentId);
    $projectId = firestore_project_id();
    if ($name === null || $projectId === null) {
        return ['ok' => false, 'conflict' => false, 'error' => 'Firebase credentials are unavailable.'];
    }

    $url = 'https://firestore.googleapis.com/v1/projects/' . rawurlencode($projectId)
        . '/databases/(default)/documents:commit';
    $res = firestore_request('POST', $url, [
        'writes' => [[
            'update' => ['name' => $name, 'fields' => firestore_encode_fields($fields)],
            'currentDocument' => ['exists' => false],
        ]],
    ]);
    if ($res['ok']) {
        return ['ok' => true, 'conflict' => false, 'error' => ''];
    }

    $body = strtolower((string) ($res['body'] ?? ''));
    $conflict = (int) ($res['status'] ?? 0) === 409
        || str_contains($body, 'already_exists')
        || str_contains($body, 'precondition_failed');
    return ['ok' => false, 'conflict' => $conflict, 'error' => 'Could not create the Firebase attendance record.'];
}

/** Update selected fields only if the document has not changed since it was read. */
function event_walkin_firestore_update(
    string $eventId,
    string $documentId,
    array $fields,
    string $updateTime
): array {
    $name = event_walkin_firestore_document_name($eventId, $documentId);
    $projectId = firestore_project_id();
    if ($name === null || $projectId === null || $updateTime === '') {
        return ['ok' => false, 'conflict' => false, 'error' => 'Firebase attendance record could not be updated.'];
    }

    $res = firestore_request(
        'POST',
        'https://firestore.googleapis.com/v1/projects/' . rawurlencode($projectId)
            . '/databases/(default)/documents:commit',
        [
            'writes' => [[
                'update' => ['name' => $name, 'fields' => firestore_encode_fields($fields)],
                'updateMask' => ['fieldPaths' => array_keys($fields)],
                'currentDocument' => ['updateTime' => $updateTime],
            ]],
        ]
    );
    if ($res['ok']) {
        return ['ok' => true, 'conflict' => false, 'error' => ''];
    }

    $body = strtolower((string) ($res['body'] ?? ''));
    $conflict = (int) ($res['status'] ?? 0) === 409
        || str_contains($body, 'aborted')
        || str_contains($body, 'failed_precondition');
    return ['ok' => false, 'conflict' => $conflict, 'error' => 'Could not update the Firebase attendance record.'];
}

/** @return array{ok:bool,rows:array,error:string} */
function event_walkin_fetch_rows(string $eventId, array $headers = [], ?string $sessionId = null): array
{
    $eventId = trim($eventId);
    $baseUrl = firestore_doc_url('event_walkin_events/' . rawurlencode($eventId) . '/participants');
    if ($eventId === '' || $baseUrl === null) {
        return ['ok' => false, 'rows' => [], 'error' => 'Firebase credentials or event ID are missing.'];
    }

    $rows = [];
    $pageToken = '';
    do {
        $query = ['pageSize' => 1000];
        if ($pageToken !== '') {
            $query['pageToken'] = $pageToken;
        }
        $res = firestore_request('GET', $baseUrl . '?' . http_build_query($query));
        if (!$res['ok']) {
            return ['ok' => false, 'rows' => [], 'error' => 'Could not load walk-in attendance from Firebase.'];
        }
        $payload = json_decode((string) ($res['body'] ?? ''), true);
        if (!is_array($payload)) {
            return ['ok' => false, 'rows' => [], 'error' => 'Firebase returned an invalid walk-in attendance list.'];
        }
        foreach (($payload['documents'] ?? []) as $doc) {
            if (!is_array($doc) || !is_array($doc['fields'] ?? null)) {
                continue;
            }
            $row = firestore_decode_fields($doc['fields']);
            $name = (string) ($doc['name'] ?? '');
            $row['id'] = basename($name);
            if ($sessionId !== null && trim((string) ($row['session_id'] ?? '')) !== trim($sessionId)) {
                continue;
            }
            $rows[] = $row;
        }
        $pageToken = trim((string) ($payload['nextPageToken'] ?? ''));
    } while ($pageToken !== '');

    usort($rows, static fn(array $a, array $b): int => strcmp(
        (string) ($b['check_in_at'] ?? ''),
        (string) ($a['check_in_at'] ?? '')
    ));
    return ['ok' => true, 'rows' => $rows, 'error' => ''];
}

/** Convert a walk-in record to the shape used by the existing participant table. */
function event_walkin_participant_row(array $row): array
{
    $studentNo = trim((string) ($row['student_no'] ?? ''));
    $firstName = trim((string) ($row['first_name'] ?? ''));
    $middleName = trim((string) ($row['middle_name'] ?? ''));
    $lastName = trim((string) ($row['last_name'] ?? ''));
    $suffix = trim((string) ($row['suffix'] ?? ''));
    $sectionName = trim((string) ($row['section_name'] ?? ''));
    $program = trim((string) ($row['program_label'] ?? $row['course_code'] ?? ''));
    $year = trim((string) ($row['year_level'] ?? ''));
    $block = trim((string) ($row['block'] ?? ''));
    $yearNumber = student_roster_parse_year_level($year, $block);
    if (!empty($row['is_irregular'])) {
        $yearLabel = $yearNumber !== null
            ? student_roster_format_year_label($yearNumber) . ' Year'
            : $year;
        $sectionName = 'IRREGULAR' . ($yearLabel !== '' ? ' · ' . $yearLabel : '');
    } elseif ($program !== '') {
        $blockLabel = student_roster_format_block_label($yearNumber, $block, false);
        if ($blockLabel !== '—') {
            // A block such as "4C" already includes the year; don't render "4 Year 4C".
            $sectionName = $program . ' ' . $blockLabel;
        } elseif ($sectionName === '' && $yearNumber !== null) {
            $sectionName = $program . ' ' . student_roster_format_year_label($yearNumber) . ' Year';
        }
    }

    $id = trim((string) ($row['id'] ?? ''));
    $attendance = [[
        'id' => $id,
        'session_id' => (string) ($row['session_id'] ?? ''),
        'check_in_at' => (string) ($row['check_in_at'] ?? ''),
        'check_out_at' => (string) ($row['check_out_at'] ?? ''),
        'status' => (string) ($row['status'] ?? 'present'),
    ]];

    return [
        'id' => 'walkin:' . $id,
        'walkin_id' => $id,
        'session_id' => (string) ($row['session_id'] ?? ''),
        'student_id' => '',
        'student_number' => $studentNo,
        'registered_at' => (string) ($row['created_at'] ?? $row['check_in_at'] ?? ''),
        'users' => [
            'first_name' => $firstName,
            'middle_name' => $middleName,
            'last_name' => $lastName,
            'suffix' => $suffix,
            'email' => '',
            'student_id' => $studentNo,
            'photo_url' => '',
            'sections' => ['name' => $sectionName],
        ],
        'tickets' => [[
            'id' => '',
            'token' => '',
            'attendance' => $attendance,
        ]],
    ];
}
