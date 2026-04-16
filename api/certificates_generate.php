<?php
declare(strict_types=1);

session_start();

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/supabase.php';
require_once __DIR__ . '/../includes/json.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/event_sessions.php';

$user = require_role(['admin']);
$data = require_post_json();
require_csrf_from_json($data);

$eventId = isset($data['event_id']) ? (string) $data['event_id'] : '';
$templateId = trim((string) ($data['template_id'] ?? ''));
$templateScope = strtolower(trim((string) ($data['template_scope'] ?? 'event')));
$templateSessionId = trim((string) ($data['template_session_id'] ?? ''));
$previewOnly = !empty($data['preview_only']);
$sessionTemplateMapInput = $data['session_template_map'] ?? [];
$sessionTemplateMap = [];
if ($eventId === '') {
    json_response(['ok' => false, 'error' => 'event_id required'], 400);
}
if (!in_array($templateScope, ['event', 'session'], true)) {
    $templateScope = 'event';
}
if (is_array($sessionTemplateMapInput)) {
    foreach ($sessionTemplateMapInput as $sessionKey => $row) {
        if (!is_array($row)) {
            continue;
        }
        $sessionId = trim((string) ($row['session_id'] ?? (is_string($sessionKey) ? $sessionKey : '')));
        $mappedTemplateId = trim((string) ($row['template_id'] ?? ''));
        $mappedScope = strtolower(trim((string) ($row['template_scope'] ?? 'event')));
        if ($sessionId === '' || $mappedTemplateId === '') {
            continue;
        }
        if (!in_array($mappedScope, ['event', 'session'], true)) {
            $mappedScope = 'event';
        }
        $sessionTemplateMap[$sessionId] = [
            'template_id' => $mappedTemplateId,
            'template_scope' => $mappedScope,
        ];
    }
}

$headers = [
    'Accept: application/json',
    'apikey: ' . SUPABASE_KEY,
    'Authorization: Bearer ' . SUPABASE_KEY,
];

function fetch_certificate_template(string $templateId, array $headers): ?array
{
    $templateId = trim($templateId);
    if ($templateId === '') {
        return null;
    }

    $url = rtrim(SUPABASE_URL, '/') . '/rest/v1/certificate_templates'
        . '?select=id,title,event_id'
        . '&id=eq.' . rawurlencode($templateId)
        . '&limit=1';
    $res = supabase_request('GET', $url, $headers);
    if (!$res['ok']) {
        throw new RuntimeException(build_error(
            $res['body'] ?? null,
            (int) ($res['status'] ?? 0),
            $res['error'] ?? null,
            'Certificate template lookup failed'
        ));
    }

    $rows = json_decode((string) $res['body'], true);
    return is_array($rows) && isset($rows[0]) && is_array($rows[0]) ? $rows[0] : null;
}

function fetch_session_certificate_template(string $templateId, array $headers): ?array
{
    $templateId = trim($templateId);
    if ($templateId === '') {
        return null;
    }

    $url = rtrim(SUPABASE_URL, '/') . '/rest/v1/event_session_certificate_templates'
        . '?select=id,title,session_id'
        . '&id=eq.' . rawurlencode($templateId)
        . '&limit=1';
    $res = supabase_request('GET', $url, $headers);
    if (!$res['ok']) {
        if (is_missing_postgrest_table($res, 'event_session_certificate_templates')) {
            throw new RuntimeException('Seminar certificate templates are not available yet. Apply `supabase/migrations/009_session_certificate_storage.sql` first.');
        }
        throw new RuntimeException(build_error(
            $res['body'] ?? null,
            (int) ($res['status'] ?? 0),
            $res['error'] ?? null,
            'Seminar certificate template lookup failed'
        ));
    }

    $rows = json_decode((string) $res['body'], true);
    return is_array($rows) && isset($rows[0]) && is_array($rows[0]) ? $rows[0] : null;
}

function parse_iso_datetime(?string $raw): ?DateTimeImmutable
{
    $text = trim((string) $raw);
    if ($text === '') {
        return null;
    }

    try {
        return new DateTimeImmutable($text);
    } catch (Throwable $e) {
        return null;
    }
}

function is_missing_postgrest_table(array $response, string $table): bool
{
    $body = strtolower((string) ($response['body'] ?? ''));
    $table = strtolower(trim($table));
    if ($body === '' || $table === '') {
        return false;
    }

    return str_contains($body, $table)
        && (
            str_contains($body, 'schema cache')
            || str_contains($body, 'does not exist')
            || str_contains($body, 'could not find the table')
            || str_contains($body, '42p01')
        );
}

function is_missing_postgrest_column(array $response, string $column): bool
{
    $body = strtolower((string) ($response['body'] ?? ''));
    $column = strtolower(trim($column));
    if ($body === '' || $column === '') {
        return false;
    }

    return str_contains($body, $column)
        && (
            str_contains($body, 'schema cache')
            || str_contains($body, 'column')
            || str_contains($body, 'does not exist')
            || str_contains($body, '42703')
        );
}

function upsert_event_certificates_without_conflict(array $payloads, array $headers): array
{
    $resultRows = [];
    $writeHeaders = [
        'Content-Type: application/json',
        'Accept: application/json',
        'apikey: ' . SUPABASE_KEY,
        'Authorization: Bearer ' . SUPABASE_KEY,
        'Prefer: return=representation',
    ];

    foreach ($payloads as $payload) {
        if (!is_array($payload)) {
            continue;
        }
        $eventId = trim((string) ($payload['event_id'] ?? ''));
        $studentId = trim((string) ($payload['student_id'] ?? ''));
        if ($eventId === '' || $studentId === '') {
            continue;
        }

        $lookupUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/certificates'
            . '?select=id,event_id,student_id,certificate_code'
            . '&event_id=eq.' . rawurlencode($eventId)
            . '&student_id=eq.' . rawurlencode($studentId)
            . '&limit=1';
        $lookupRes = supabase_request('GET', $lookupUrl, $headers);
        if (!$lookupRes['ok']) {
            throw new RuntimeException(build_error(
                $lookupRes['body'] ?? null,
                (int) ($lookupRes['status'] ?? 0),
                $lookupRes['error'] ?? null,
                'Certificate lookup failed'
            ));
        }

        $existingRows = json_decode((string) $lookupRes['body'], true);
        $existing = (is_array($existingRows) && isset($existingRows[0]) && is_array($existingRows[0]))
            ? $existingRows[0]
            : null;

        if (is_array($existing) && trim((string) ($existing['id'] ?? '')) !== '') {
            $updateUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/certificates'
                . '?select=id,event_id,student_id,certificate_code'
                . '&id=eq.' . rawurlencode((string) $existing['id']);
            $updateRes = supabase_request(
                'PATCH',
                $updateUrl,
                $writeHeaders,
                json_encode($payload, JSON_UNESCAPED_SLASHES)
            );
            if (!$updateRes['ok']) {
                throw new RuntimeException(build_error(
                    $updateRes['body'] ?? null,
                    (int) ($updateRes['status'] ?? 0),
                    $updateRes['error'] ?? null,
                    'Certificate update failed'
                ));
            }
            $updatedRows = json_decode((string) $updateRes['body'], true);
            if (is_array($updatedRows) && isset($updatedRows[0]) && is_array($updatedRows[0])) {
                $resultRows[] = $updatedRows[0];
            } else {
                $resultRows[] = $payload;
            }
            continue;
        }

        $insertUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/certificates'
            . '?select=id,event_id,student_id,certificate_code';
        $insertRes = supabase_request(
            'POST',
            $insertUrl,
            $writeHeaders,
            json_encode($payload, JSON_UNESCAPED_SLASHES)
        );
        if (!$insertRes['ok']) {
            throw new RuntimeException(build_error(
                $insertRes['body'] ?? null,
                (int) ($insertRes['status'] ?? 0),
                $insertRes['error'] ?? null,
                'Certificate insert failed'
            ));
        }
        $insertedRows = json_decode((string) $insertRes['body'], true);
        if (is_array($insertedRows) && isset($insertedRows[0]) && is_array($insertedRows[0])) {
            $resultRows[] = $insertedRows[0];
        } else {
            $resultRows[] = $payload;
        }
    }

    return $resultRows;
}

function upsert_session_certificates_without_conflict(array $payloads, array $headers): array
{
    $resultRows = [];
    $writeHeaders = [
        'Content-Type: application/json',
        'Accept: application/json',
        'apikey: ' . SUPABASE_KEY,
        'Authorization: Bearer ' . SUPABASE_KEY,
        'Prefer: return=representation',
    ];

    foreach ($payloads as $payload) {
        if (!is_array($payload)) {
            continue;
        }
        $sessionId = trim((string) ($payload['session_id'] ?? ''));
        $studentId = trim((string) ($payload['student_id'] ?? ''));
        if ($sessionId === '' || $studentId === '') {
            continue;
        }

        $lookupUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/event_session_certificates'
            . '?select=id,session_id,student_id,certificate_code'
            . '&session_id=eq.' . rawurlencode($sessionId)
            . '&student_id=eq.' . rawurlencode($studentId)
            . '&limit=1';
        $lookupRes = supabase_request('GET', $lookupUrl, $headers);
        if (!$lookupRes['ok']) {
            if (is_missing_postgrest_table($lookupRes, 'event_session_certificates')) {
                throw new RuntimeException('Seminar certificate storage is not available yet. Apply `supabase/migrations/009_session_certificate_storage.sql` to Supabase, then refresh the schema cache.');
            }
            throw new RuntimeException(build_error(
                $lookupRes['body'] ?? null,
                (int) ($lookupRes['status'] ?? 0),
                $lookupRes['error'] ?? null,
                'Seminar certificate lookup failed'
            ));
        }

        $existingRows = json_decode((string) $lookupRes['body'], true);
        $existing = (is_array($existingRows) && isset($existingRows[0]) && is_array($existingRows[0]))
            ? $existingRows[0]
            : null;

        if (is_array($existing) && trim((string) ($existing['id'] ?? '')) !== '') {
            $updateUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/event_session_certificates'
                . '?select=id,session_id,student_id,certificate_code'
                . '&id=eq.' . rawurlencode((string) $existing['id']);
            $updateRes = supabase_request(
                'PATCH',
                $updateUrl,
                $writeHeaders,
                json_encode($payload, JSON_UNESCAPED_SLASHES)
            );
            if (!$updateRes['ok']) {
                if (is_missing_postgrest_column($updateRes, 'session_template_id')) {
                    throw new RuntimeException('Seminar template linking is not available yet. Apply `supabase/migrations/011_session_template_certificate_links.sql` to Supabase, then refresh the schema cache.');
                }
                throw new RuntimeException(build_error(
                    $updateRes['body'] ?? null,
                    (int) ($updateRes['status'] ?? 0),
                    $updateRes['error'] ?? null,
                    'Seminar certificate update failed'
                ));
            }
            $updatedRows = json_decode((string) $updateRes['body'], true);
            if (is_array($updatedRows) && isset($updatedRows[0]) && is_array($updatedRows[0])) {
                $resultRows[] = $updatedRows[0];
            } else {
                $resultRows[] = $payload;
            }
            continue;
        }

        $insertUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/event_session_certificates'
            . '?select=id,session_id,student_id,certificate_code';
        $insertRes = supabase_request(
            'POST',
            $insertUrl,
            $writeHeaders,
            json_encode($payload, JSON_UNESCAPED_SLASHES)
        );
        if (!$insertRes['ok']) {
            if (is_missing_postgrest_table($insertRes, 'event_session_certificates')) {
                throw new RuntimeException('Seminar certificate storage is not available yet. Apply `supabase/migrations/009_session_certificate_storage.sql` to Supabase, then refresh the schema cache.');
            }
            if (is_missing_postgrest_column($insertRes, 'session_template_id')) {
                throw new RuntimeException('Seminar template linking is not available yet. Apply `supabase/migrations/011_session_template_certificate_links.sql` to Supabase, then refresh the schema cache.');
            }
            throw new RuntimeException(build_error(
                $insertRes['body'] ?? null,
                (int) ($insertRes['status'] ?? 0),
                $insertRes['error'] ?? null,
                'Seminar certificate insert failed'
            ));
        }
        $insertedRows = json_decode((string) $insertRes['body'], true);
        if (is_array($insertedRows) && isset($insertedRows[0]) && is_array($insertedRows[0])) {
            $resultRows[] = $insertedRows[0];
        } else {
            $resultRows[] = $payload;
        }
    }

    return $resultRows;
}

function session_effective_end(array $session): ?DateTimeImmutable
{
    $endAt = parse_iso_datetime((string) ($session['end_at'] ?? ''));
    if ($endAt instanceof DateTimeImmutable) {
        return $endAt;
    }

    $startAt = parse_iso_datetime((string) ($session['start_at'] ?? ''));
    if ($startAt instanceof DateTimeImmutable) {
        return $startAt->modify('+1 hour');
    }

    return null;
}

function event_effective_end(array $event, array $sessions): ?DateTimeImmutable
{
    $endAt = parse_iso_datetime((string) ($event['end_at'] ?? ''));
    foreach ($sessions as $session) {
        $sessionEnd = session_effective_end($session);
        if (!$sessionEnd instanceof DateTimeImmutable) {
            continue;
        }
        if (!$endAt instanceof DateTimeImmutable || $sessionEnd > $endAt) {
            $endAt = $sessionEnd;
        }
    }

    return $endAt;
}

function attendance_counts_as_present(array $row): bool
{
    $status = strtolower(trim((string) ($row['status'] ?? '')));
    $checkInAt = trim((string) ($row['check_in_at'] ?? ''));

    if ($checkInAt !== '') {
        return true;
    }

    return in_array($status, ['present', 'scanned', 'late', 'early'], true);
}

function build_student_name_map(array $studentIds, array $headers): array
{
    $studentIds = array_values(array_unique(array_filter(array_map(
        static fn ($id): string => trim((string) $id),
        $studentIds
    ))));
    if (count($studentIds) === 0) {
        return [];
    }

    $url = rtrim(SUPABASE_URL, '/') . '/rest/v1/users'
        . '?select=id,first_name,middle_name,last_name,suffix'
        . '&id=in.(' . implode(',', array_map('rawurlencode', $studentIds)) . ')';
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
        $studentId = trim((string) ($row['id'] ?? ''));
        if ($studentId === '') {
            continue;
        }

        $parts = array_values(array_filter([
            trim((string) ($row['first_name'] ?? '')),
            trim((string) ($row['middle_name'] ?? '')),
            trim((string) ($row['last_name'] ?? '')),
        ], static fn (string $value): bool => $value !== ''));

        $name = trim(implode(' ', $parts));
        $suffix = trim((string) ($row['suffix'] ?? ''));
        if ($suffix !== '') {
            $name = $name === '' ? $suffix : $name . ' ' . $suffix;
        }

        $map[$studentId] = $name !== '' ? $name : $studentId;
    }

    return $map;
}

function send_notification_to_users(array $userIds, string $title, string $body, array $data = []): array
{
    $userIds = array_values(array_unique(array_filter(array_map(
        static fn ($id): string => trim((string) $id),
        $userIds
    ))));
    if (count($userIds) === 0) {
        return [
            'attempted_users' => 0,
            'resolved_tokens' => 0,
            'sent' => false,
        ];
    }

    require_once __DIR__ . '/../includes/fcm.php';

    $inList = '(' . implode(',', array_map('rawurlencode', $userIds)) . ')';
    $tokensRes = supabase_request('GET',
        rtrim(SUPABASE_URL, '/') . '/rest/v1/fcm_tokens?select=token&user_id=in.' . $inList,
        [
            'Accept: application/json',
            'apikey: ' . SUPABASE_KEY,
            'Authorization: Bearer ' . SUPABASE_KEY,
        ]
    );

    if (!$tokensRes['ok']) {
        return [
            'attempted_users' => count($userIds),
            'resolved_tokens' => 0,
            'sent' => false,
        ];
    }

    $tokenRows = json_decode((string) $tokensRes['body'], true);
    $tokens = [];
    if (is_array($tokenRows)) {
        foreach ($tokenRows as $row) {
            $token = trim((string) ($row['token'] ?? ''));
            if ($token !== '') {
                $tokens[$token] = true;
            }
        }
    }

    if (count($tokens) === 0) {
        return [
            'attempted_users' => count($userIds),
            'resolved_tokens' => 0,
            'sent' => false,
        ];
    }

    $sent = send_fcm_notification(array_keys($tokens), $title, $body, $data) === true;
    return [
        'attempted_users' => count($userIds),
        'resolved_tokens' => count($tokens),
        'sent' => $sent,
    ];
}

function fetch_event_questions(string $eventId, array $headers): array
{
    $url = rtrim(SUPABASE_URL, '/') . '/rest/v1/evaluation_questions'
        . '?select=id,required'
        . '&event_id=eq.' . rawurlencode($eventId);
    $res = supabase_request('GET', $url, $headers);
    if (!$res['ok']) {
        throw new RuntimeException(build_error(
            $res['body'] ?? null,
            (int) ($res['status'] ?? 0),
            $res['error'] ?? null,
            'Event evaluation lookup failed'
        ));
    }

    $rows = json_decode((string) $res['body'], true);
    return is_array($rows) ? $rows : [];
}

function fetch_session_questions(array $sessionIds, array $headers): array
{
    if (count($sessionIds) === 0) {
        return [];
    }

    $url = rtrim(SUPABASE_URL, '/') . '/rest/v1/event_session_evaluation_questions'
        . '?select=id,session_id,required'
        . '&session_id=in.(' . implode(',', array_map('rawurlencode', $sessionIds)) . ')';
    $res = supabase_request('GET', $url, $headers);
    if (!$res['ok']) {
        if (is_missing_postgrest_table($res, 'event_session_evaluation_questions')) {
            return [];
        }
        throw new RuntimeException(build_error(
            $res['body'] ?? null,
            (int) ($res['status'] ?? 0),
            $res['error'] ?? null,
            'Seminar evaluation lookup failed'
        ));
    }

    $rows = json_decode((string) $res['body'], true);
    return is_array($rows) ? $rows : [];
}

function build_completion_map(array $questions, array $answers): array
{
    $requiredIds = [];
    foreach ($questions as $question) {
        $questionId = trim((string) ($question['id'] ?? ''));
        if ($questionId === '') {
            continue;
        }
        if (!empty($question['required'])) {
            $requiredIds[$questionId] = true;
        }
    }

    $answeredByStudent = [];
    foreach ($answers as $answer) {
        if (!is_array($answer)) {
            continue;
        }
        $studentId = trim((string) ($answer['student_id'] ?? ''));
        $questionId = trim((string) ($answer['question_id'] ?? ''));
        $answerText = trim((string) ($answer['answer_text'] ?? ''));
        if ($studentId === '' || $questionId === '' || $answerText === '') {
            continue;
        }
        if (!isset($answeredByStudent[$studentId])) {
            $answeredByStudent[$studentId] = [];
        }
        $answeredByStudent[$studentId][$questionId] = true;
    }

    $completion = [];
    foreach ($answeredByStudent as $studentId => $answeredIds) {
        $completed = false;
        if (count($questions) === 0) {
            $completed = true;
        } elseif (count($requiredIds) > 0) {
            $completed = true;
            foreach ($requiredIds as $requiredId => $_) {
                if (!isset($answeredIds[$requiredId])) {
                    $completed = false;
                    break;
                }
            }
        } else {
            $completed = count($answeredIds) > 0;
        }

        $completion[$studentId] = [
            'completed' => $completed,
            'answered_count' => count($answeredIds),
        ];
    }

    return $completion;
}

function build_simple_certificate_preview(
    string $eventId,
    array $eventQuestions,
    array $headers
): array {
    $attendanceUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/attendance'
        . '?select=check_in_at,status,tickets(registration_id,event_registrations(student_id,event_id))'
        . '&tickets.event_registrations.event_id=eq.' . rawurlencode($eventId);
    $attendanceRes = supabase_request('GET', $attendanceUrl, $headers);
    if (!$attendanceRes['ok']) {
        throw new RuntimeException(build_error(
            $attendanceRes['body'] ?? null,
            (int) ($attendanceRes['status'] ?? 0),
            $attendanceRes['error'] ?? null,
            'Attendance lookup failed'
        ));
    }

    $attendanceRows = json_decode((string) $attendanceRes['body'], true);
    $attendanceRows = is_array($attendanceRows) ? $attendanceRows : [];

    $presentStudentIds = [];
    foreach ($attendanceRows as $row) {
        if (!is_array($row) || !attendance_counts_as_present($row)) {
            continue;
        }
        $ticket = isset($row['tickets']) && is_array($row['tickets']) ? $row['tickets'] : null;
        if (!$ticket || empty($ticket['event_registrations']) || !is_array($ticket['event_registrations'])) {
            continue;
        }
        $studentId = trim((string) ($ticket['event_registrations']['student_id'] ?? ''));
        if ($studentId !== '') {
            $presentStudentIds[$studentId] = true;
        }
    }

    $presentStudentIds = array_values(array_keys($presentStudentIds));
    $nameMap = build_student_name_map($presentStudentIds, $headers);

    $eventAnswers = [];
    if (count($eventQuestions) > 0 && count($presentStudentIds) > 0) {
        $eventAnswersUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/evaluation_answers'
            . '?select=student_id,question_id,answer_text'
            . '&event_id=eq.' . rawurlencode($eventId)
            . '&student_id=in.(' . implode(',', array_map('rawurlencode', $presentStudentIds)) . ')';
        $eventAnswersRes = supabase_request('GET', $eventAnswersUrl, $headers);
        if (!$eventAnswersRes['ok']) {
            throw new RuntimeException(build_error(
                $eventAnswersRes['body'] ?? null,
                (int) ($eventAnswersRes['status'] ?? 0),
                $eventAnswersRes['error'] ?? null,
                'Evaluation answer lookup failed'
            ));
        }
        $decoded = json_decode((string) $eventAnswersRes['body'], true);
        $eventAnswers = is_array($decoded) ? $decoded : [];
    }

    $completionMap = build_completion_map($eventQuestions, $eventAnswers);
    $eligibleRecipients = [];
    $pendingStudents = [];

    foreach ($presentStudentIds as $studentId) {
        $eventComplete = count($eventQuestions) === 0
            ? true
            : (($completionMap[$studentId]['completed'] ?? false) === true);

        $name = $nameMap[$studentId] ?? $studentId;
        if ($eventComplete) {
            $eligibleRecipients[] = [
                'student_id' => $studentId,
                'name' => $name,
                'label' => $name,
            ];
            continue;
        }

        $pendingStudents[] = [
            'student_id' => $studentId,
            'name' => $name,
            'label' => $name,
            'reasons' => ['Event evaluation incomplete'],
        ];
    }

    return [
        'mode' => 'simple',
        'participants_count' => count($presentStudentIds),
        'eligible_recipients' => $eligibleRecipients,
        'pending_students' => $pendingStudents,
    ];
}

function build_session_certificate_preview(
    string $eventId,
    array $sessions,
    array $eventQuestions,
    array $headers
): array {
    $sessionIds = [];
    foreach ($sessions as $session) {
        $sessionId = trim((string) ($session['id'] ?? ''));
        if ($sessionId !== '') {
            $sessionIds[] = $sessionId;
        }
    }

    if (count($sessionIds) === 0) {
        throw new RuntimeException('No seminars found for this event');
    }

    $sessionFilter = implode(',', array_map('rawurlencode', $sessionIds));
    $attendanceRows = [];

    $attendanceUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/event_session_attendance'
        . '?select=session_id,check_in_at,status,registration:event_registrations(student_id)'
        . '&session_id=in.(' . $sessionFilter . ')';
    $attendanceRes = supabase_request('GET', $attendanceUrl, $headers);
    if ($attendanceRes['ok']) {
        $decodedRows = json_decode((string) $attendanceRes['body'], true);
        $attendanceRows = is_array($decodedRows) ? $decodedRows : [];
    } else {
        if (!is_missing_postgrest_table($attendanceRes, 'event_session_attendance')) {
            throw new RuntimeException(build_error(
                $attendanceRes['body'] ?? null,
                (int) ($attendanceRes['status'] ?? 0),
                $attendanceRes['error'] ?? null,
                'Seminar attendance lookup failed'
            ));
        }

        // Fallback for deployments still using legacy attendance table.
        $registrationUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/event_registrations'
            . '?select=student_id,tickets(id)'
            . '&event_id=eq.' . rawurlencode($eventId);
        $registrationRes = supabase_request('GET', $registrationUrl, $headers);
        if (!$registrationRes['ok']) {
            throw new RuntimeException(build_error(
                $registrationRes['body'] ?? null,
                (int) ($registrationRes['status'] ?? 0),
                $registrationRes['error'] ?? null,
                'Seminar attendance fallback mapping lookup failed'
            ));
        }

        $registrationRows = json_decode((string) $registrationRes['body'], true);
        $registrationRows = is_array($registrationRows) ? $registrationRows : [];
        $ticketToStudent = [];
        foreach ($registrationRows as $registrationRow) {
            if (!is_array($registrationRow)) {
                continue;
            }
            $studentId = trim((string) ($registrationRow['student_id'] ?? ''));
            if ($studentId === '') {
                continue;
            }
            $tickets = isset($registrationRow['tickets']) && is_array($registrationRow['tickets'])
                ? $registrationRow['tickets']
                : [];
            foreach ($tickets as $ticketRow) {
                if (!is_array($ticketRow)) {
                    continue;
                }
                $ticketId = trim((string) ($ticketRow['id'] ?? ''));
                if ($ticketId !== '') {
                    $ticketToStudent[$ticketId] = $studentId;
                }
            }
        }

        $legacyAttendanceUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/attendance'
            . '?select=session_id,ticket_id,check_in_at,status'
            . '&session_id=in.(' . $sessionFilter . ')';
        $legacyAttendanceRes = supabase_request('GET', $legacyAttendanceUrl, $headers);
        if (!$legacyAttendanceRes['ok']) {
            throw new RuntimeException(build_error(
                $legacyAttendanceRes['body'] ?? null,
                (int) ($legacyAttendanceRes['status'] ?? 0),
                $legacyAttendanceRes['error'] ?? null,
                'Seminar attendance fallback lookup failed'
            ));
        }

        $legacyRows = json_decode((string) $legacyAttendanceRes['body'], true);
        $legacyRows = is_array($legacyRows) ? $legacyRows : [];
        foreach ($legacyRows as $legacyRow) {
            if (!is_array($legacyRow)) {
                continue;
            }
            $ticketId = trim((string) ($legacyRow['ticket_id'] ?? ''));
            $studentId = $ticketId !== '' ? trim((string) ($ticketToStudent[$ticketId] ?? '')) : '';
            if ($studentId === '') {
                continue;
            }
            $attendanceRows[] = [
                'session_id' => $legacyRow['session_id'] ?? null,
                'status' => $legacyRow['status'] ?? null,
                'check_in_at' => $legacyRow['check_in_at'] ?? null,
                'student_id' => $studentId,
            ];
        }
    }

    $presentBySession = [];
    $allPresentStudentIds = [];
    foreach ($attendanceRows as $row) {
        if (!is_array($row) || !attendance_counts_as_present($row)) {
            continue;
        }
        $sessionId = trim((string) ($row['session_id'] ?? ''));
        if ($sessionId === '') {
            continue;
        }
        $registration = isset($row['registration']) && is_array($row['registration']) ? $row['registration'] : [];
        $studentId = trim((string) ($row['student_id'] ?? ''));
        if ($studentId === '') {
            $studentId = trim((string) ($registration['student_id'] ?? ''));
        }
        if ($studentId === '') {
            continue;
        }
        if (!isset($presentBySession[$sessionId])) {
            $presentBySession[$sessionId] = [];
        }
        $presentBySession[$sessionId][$studentId] = true;
        $allPresentStudentIds[$studentId] = true;
    }

    $allPresentStudentIds = array_values(array_keys($allPresentStudentIds));
    $nameMap = build_student_name_map($allPresentStudentIds, $headers);

    $eventCompletionMap = [];
    if (count($eventQuestions) > 0 && count($allPresentStudentIds) > 0) {
        $eventAnswersUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/evaluation_answers'
            . '?select=student_id,question_id,answer_text'
            . '&event_id=eq.' . rawurlencode($eventId)
            . '&student_id=in.(' . implode(',', array_map('rawurlencode', $allPresentStudentIds)) . ')';
        $eventAnswersRes = supabase_request('GET', $eventAnswersUrl, $headers);
        if (!$eventAnswersRes['ok']) {
            throw new RuntimeException(build_error(
                $eventAnswersRes['body'] ?? null,
                (int) ($eventAnswersRes['status'] ?? 0),
                $eventAnswersRes['error'] ?? null,
                'Event evaluation answer lookup failed'
            ));
        }
        $eventAnswers = json_decode((string) $eventAnswersRes['body'], true);
        $eventCompletionMap = build_completion_map(
            $eventQuestions,
            is_array($eventAnswers) ? $eventAnswers : []
        );
    }

    $sessionQuestionRows = fetch_session_questions($sessionIds, $headers);
    $questionsBySession = [];
    foreach ($sessionQuestionRows as $row) {
        $sessionId = trim((string) ($row['session_id'] ?? ''));
        if ($sessionId === '') {
            continue;
        }
        if (!isset($questionsBySession[$sessionId])) {
            $questionsBySession[$sessionId] = [];
        }
        $questionsBySession[$sessionId][] = $row;
    }

    $sessionAnswerRows = [];
    if (count($sessionQuestionRows) > 0) {
        $sessionAnswersUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/event_session_evaluation_answers'
            . '?select=session_id,student_id,question_id,answer_text'
            . '&session_id=in.(' . implode(',', array_map('rawurlencode', $sessionIds)) . ')';
        $sessionAnswersRes = supabase_request('GET', $sessionAnswersUrl, $headers);
        if (!$sessionAnswersRes['ok']) {
            if (!is_missing_postgrest_table($sessionAnswersRes, 'event_session_evaluation_answers')) {
                throw new RuntimeException(build_error(
                    $sessionAnswersRes['body'] ?? null,
                    (int) ($sessionAnswersRes['status'] ?? 0),
                    $sessionAnswersRes['error'] ?? null,
                    'Seminar evaluation answer lookup failed'
                ));
            }
        } else {
            $decoded = json_decode((string) $sessionAnswersRes['body'], true);
            $sessionAnswerRows = is_array($decoded) ? $decoded : [];
        }
    }

    $answersBySession = [];
    foreach ($sessionAnswerRows as $row) {
        $sessionId = trim((string) ($row['session_id'] ?? ''));
        if ($sessionId === '') {
            continue;
        }
        if (!isset($answersBySession[$sessionId])) {
            $answersBySession[$sessionId] = [];
        }
        $answersBySession[$sessionId][] = $row;
    }

    $eligibleRecipients = [];
    $pendingStudents = [];
    $sessionSummary = [];

    foreach ($sessions as $session) {
        $sessionId = trim((string) ($session['id'] ?? ''));
        if ($sessionId === '') {
            continue;
        }

        $participants = array_values(array_keys($presentBySession[$sessionId] ?? []));
        $sessionQuestions = $questionsBySession[$sessionId] ?? [];
        $sessionCompletionMap = build_completion_map(
            $sessionQuestions,
            $answersBySession[$sessionId] ?? []
        );
        $sessionName = build_session_display_name($session);
        $eligibleCount = 0;
        $pendingCount = 0;

        foreach ($participants as $studentId) {
            $eventComplete = count($eventQuestions) === 0
                ? true
                : (($eventCompletionMap[$studentId]['completed'] ?? false) === true);
            $sessionComplete = count($sessionQuestions) === 0
                ? true
                : (($sessionCompletionMap[$studentId]['completed'] ?? false) === true);

            $name = $nameMap[$studentId] ?? $studentId;
            if ($eventComplete && $sessionComplete) {
                $eligibleRecipients[] = [
                    'student_id' => $studentId,
                    'name' => $name,
                    'session_id' => $sessionId,
                    'session_title' => $sessionName,
                    'label' => $name . ' - ' . $sessionName,
                ];
                $eligibleCount++;
                continue;
            }

            $reasons = [];
            if (!$eventComplete) {
                $reasons[] = 'Event evaluation incomplete';
            }
            if (!$sessionComplete) {
                $reasons[] = $sessionName . ' evaluation incomplete';
            }

            $pendingStudents[] = [
                'student_id' => $studentId,
                'name' => $name,
                'session_id' => $sessionId,
                'session_title' => $sessionName,
                'label' => $name . ' - ' . $sessionName,
                'reasons' => $reasons,
            ];
            $pendingCount++;
        }

        $sessionSummary[] = [
            'session_id' => $sessionId,
            'session_title' => $sessionName,
            'eligible_count' => $eligibleCount,
            'pending_count' => $pendingCount,
        ];
    }

    return [
        'mode' => 'seminar_based',
        'participants_count' => count($allPresentStudentIds),
        'eligible_recipients' => $eligibleRecipients,
        'pending_students' => $pendingStudents,
        'session_summary' => $sessionSummary,
    ];
}

$eventUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/events'
    // Keep this select schema-safe across old/new event migrations.
    // Seminar/simple behavior is inferred from fetched sessions below.
    . '?select=id,title,start_at,end_at,status'
    . '&id=eq.' . rawurlencode($eventId)
    . '&limit=1';
$eventRes = supabase_request('GET', $eventUrl, $headers);
if (!$eventRes['ok']) {
    json_response(['ok' => false, 'error' => build_error($eventRes['body'] ?? null, (int) ($eventRes['status'] ?? 0), $eventRes['error'] ?? null, 'Event lookup failed')], 500);
}
$eventRows = json_decode((string) $eventRes['body'], true);
$event = is_array($eventRows) && isset($eventRows[0]) ? $eventRows[0] : null;
if (!is_array($event)) {
    json_response(['ok' => false, 'error' => 'Event not found'], 404);
}

$sessions = fetch_event_sessions($eventId, $headers);
$effectiveEnd = event_effective_end($event, $sessions);
if (!$effectiveEnd instanceof DateTimeImmutable || $effectiveEnd > new DateTimeImmutable('now')) {
    json_response([
        'ok' => false,
        'error' => 'Certificates can only be sent after the event has finished.',
        'event_finished' => false,
    ], 409);
}

try {
    $eventQuestions = fetch_event_questions($eventId, $headers);
    $preview = event_uses_sessions(array_merge($event, ['sessions' => $sessions]))
        ? build_session_certificate_preview($eventId, $sessions, $eventQuestions, $headers)
        : build_simple_certificate_preview($eventId, $eventQuestions, $headers);
} catch (Throwable $e) {
    json_response([
        'ok' => false,
        'error' => $e->getMessage() !== '' ? $e->getMessage() : 'Certificate preview failed',
    ], 500);
}

$eligibleRecipients = $preview['eligible_recipients'] ?? [];
$pendingStudents = $preview['pending_students'] ?? [];

if ($previewOnly) {
    json_response([
        'ok' => true,
        'preview_only' => true,
        'event_finished' => true,
        'mode' => $preview['mode'] ?? 'simple',
        'participants_count' => (int) ($preview['participants_count'] ?? 0),
        'eligible_count' => count($eligibleRecipients),
        'pending_count' => count($pendingStudents),
        'pending_students' => $pendingStudents,
        'session_summary' => is_array($preview['session_summary'] ?? null) ? $preview['session_summary'] : [],
    ], 200);
}

if (($preview['mode'] ?? 'simple') !== 'seminar_based' && $templateId === '') {
    json_response([
        'ok' => false,
        'error' => 'Please select a saved certificate template before sending.',
        'event_finished' => true,
    ], 400);
}

$selectedTemplate = null;
$selectedSessionTemplate = null;
if (($preview['mode'] ?? 'simple') !== 'seminar_based' || count($sessionTemplateMap) === 0) {
    if ($templateId === '') {
        json_response([
            'ok' => false,
            'error' => 'Please select a saved certificate template before sending.',
            'event_finished' => true,
        ], 400);
    }
    try {
        if ($templateScope === 'session') {
            $selectedSessionTemplate = fetch_session_certificate_template($templateId, $headers);
        } else {
            $selectedTemplate = fetch_certificate_template($templateId, $headers);
        }
    } catch (Throwable $e) {
        json_response([
            'ok' => false,
            'error' => $e->getMessage() !== '' ? $e->getMessage() : 'Certificate template lookup failed',
        ], 500);
    }

    if ($templateScope === 'session' && !is_array($selectedSessionTemplate)) {
        json_response([
            'ok' => false,
            'error' => 'The selected seminar certificate template could not be found.',
        ], 404);
    }
    if ($templateScope === 'event' && !is_array($selectedTemplate)) {
        json_response([
            'ok' => false,
            'error' => 'The selected certificate template could not be found.',
        ], 404);
    }
    if (($preview['mode'] ?? 'simple') !== 'seminar_based' && $templateScope === 'session') {
        json_response([
            'ok' => false,
            'error' => 'A seminar template can only be used for seminar-based events.',
        ], 409);
    }
}

if (count($eligibleRecipients) === 0) {
    $error = count($pendingStudents) > 0
        ? 'No certificates were sent because the remaining present participants still have incomplete evaluations.'
        : 'No eligible participants found for certificate sending.';
    json_response([
        'ok' => false,
        'error' => $error,
        'event_finished' => true,
        'pending_count' => count($pendingStudents),
        'pending_students' => $pendingStudents,
    ], 409);
}

$payloads = [];
$usedSeminarMapMode = false;
if (($preview['mode'] ?? 'simple') === 'seminar_based') {
    if (count($sessionTemplateMap) > 0) {
        $usedSeminarMapMode = true;
        $templateCache = [];

        try {
            foreach ($sessionTemplateMap as $sessionId => $choice) {
                $choiceTemplateId = trim((string) ($choice['template_id'] ?? ''));
                $choiceScope = strtolower(trim((string) ($choice['template_scope'] ?? 'event')));
                if ($choiceTemplateId === '') {
                    json_response([
                        'ok' => false,
                        'error' => 'Each seminar must have a selected template before sending.',
                    ], 400);
                }
                if (!in_array($choiceScope, ['event', 'session'], true)) {
                    $choiceScope = 'event';
                }
                $cacheKey = $choiceScope . ':' . $choiceTemplateId;
                if (!array_key_exists($cacheKey, $templateCache)) {
                    $templateCache[$cacheKey] = $choiceScope === 'session'
                        ? fetch_session_certificate_template($choiceTemplateId, $headers)
                        : fetch_certificate_template($choiceTemplateId, $headers);
                }
                $resolvedTemplate = $templateCache[$cacheKey];
                if (!is_array($resolvedTemplate)) {
                    json_response([
                        'ok' => false,
                        'error' => 'A selected seminar template could not be found. Please refresh and select again.',
                    ], 404);
                }
                if ($choiceScope === 'session') {
                    $resolvedSessionId = trim((string) ($resolvedTemplate['session_id'] ?? ''));
                    if ($resolvedSessionId === '' || $resolvedSessionId !== $sessionId) {
                        json_response([
                            'ok' => false,
                            'error' => 'A seminar template does not match the selected seminar. Please reselect templates.',
                        ], 409);
                    }
                }
            }
        } catch (Throwable $e) {
            json_response([
                'ok' => false,
                'error' => $e->getMessage() !== '' ? $e->getMessage() : 'Certificate template lookup failed',
            ], 500);
        }

        foreach ($eligibleRecipients as $recipient) {
            $recipientSessionId = trim((string) ($recipient['session_id'] ?? ''));
            if ($recipientSessionId === '') {
                continue;
            }
            $choice = $sessionTemplateMap[$recipientSessionId] ?? null;
            if (!is_array($choice)) {
                json_response([
                    'ok' => false,
                    'error' => 'Please select a template for each seminar before sending.',
                ], 400);
            }
            $choiceTemplateId = trim((string) ($choice['template_id'] ?? ''));
            $choiceScope = strtolower(trim((string) ($choice['template_scope'] ?? 'event')));

            $payload = [
                'session_id' => $recipientSessionId,
                'student_id' => (string) ($recipient['student_id'] ?? ''),
                'certificate_code' => bin2hex(random_bytes(8)),
                'issued_by' => (string) ($user['id'] ?? ''),
                'issued_at' => gmdate('c'),
            ];

            if ($choiceScope === 'session') {
                $payload['session_template_id'] = $choiceTemplateId;
            } else {
                $payload['template_id'] = $choiceTemplateId;
            }
            $payloads[] = $payload;
        }
    } else {
        if ($templateScope === 'session') {
            $selectedScopeSessionId = trim((string) ($selectedSessionTemplate['session_id'] ?? $templateSessionId));
            if ($selectedScopeSessionId === '') {
                json_response([
                    'ok' => false,
                    'error' => 'The selected seminar template is missing its seminar scope.',
                ], 409);
            }

            $eligibleRecipients = array_values(array_filter(
                $eligibleRecipients,
                static fn (array $recipient): bool => (string) ($recipient['session_id'] ?? '') === $selectedScopeSessionId
            ));
            if (count($eligibleRecipients) === 0) {
                json_response([
                    'ok' => false,
                    'error' => 'No eligible participants were found for the selected seminar template yet.',
                    'event_finished' => true,
                ], 409);
            }
        }

        foreach ($eligibleRecipients as $recipient) {
            $payloads[] = [
                'session_id' => (string) ($recipient['session_id'] ?? ''),
                'student_id' => (string) ($recipient['student_id'] ?? ''),
                'certificate_code' => bin2hex(random_bytes(8)),
                'issued_by' => (string) ($user['id'] ?? ''),
                'issued_at' => gmdate('c'),
            ];
            if ($templateScope === 'session') {
                $payloads[count($payloads) - 1]['session_template_id'] = $templateId;
            } else {
                $payloads[count($payloads) - 1]['template_id'] = $templateId;
            }
        }
    }

    if (count($payloads) === 0) {
        json_response([
            'ok' => false,
            'error' => 'No eligible seminar recipients found for certificate sending.',
            'event_finished' => true,
        ], 409);
    }

} else {
    foreach ($eligibleRecipients as $recipient) {
        $payloads[] = [
            'event_id' => $eventId,
            'student_id' => (string) ($recipient['student_id'] ?? ''),
            'template_id' => $templateId,
            'certificate_code' => bin2hex(random_bytes(8)),
            'issued_by' => (string) ($user['id'] ?? ''),
            'issued_at' => gmdate('c'),
        ];
    }

}
try {
    if (($preview['mode'] ?? 'simple') === 'seminar_based') {
        $generatedRows = upsert_session_certificates_without_conflict($payloads, $headers);
    } else {
        $generatedRows = upsert_event_certificates_without_conflict($payloads, $headers);
    }
} catch (RuntimeException $e) {
    json_response([
        'ok' => false,
        'error' => $e->getMessage(),
    ], 500);
}
$generatedCount = is_array($generatedRows) ? count($generatedRows) : count($payloads);

$notifiedStudentIds = [];
foreach ($payloads as $payload) {
    if (!is_array($payload)) {
        continue;
    }
    $studentId = trim((string) ($payload['student_id'] ?? ''));
    if ($studentId !== '') {
        $notifiedStudentIds[$studentId] = true;
    }
}

$notificationDelivery = [
    'attempted_users' => 0,
    'resolved_tokens' => 0,
    'sent' => false,
];
if (count($notifiedStudentIds) > 0) {
    $eventTitle = trim((string) ($event['title'] ?? 'Event'));
    $certificateBody = (($preview['mode'] ?? 'simple') === 'seminar_based')
        ? 'Your certificate for "' . $eventTitle . '" is now available. Open My Certificates to view it.'
        : 'Your certificate for "' . $eventTitle . '" is now available. Open My Certificates to view it.';

    $notificationDelivery = send_notification_to_users(
        array_keys($notifiedStudentIds),
        'Certificate Ready',
        $certificateBody,
        [
            'event_id' => $eventId,
            'type' => 'certificate_ready',
            'route' => 'certificates',
        ]
    );
}

json_response([
    'ok' => true,
    'count' => $generatedCount,
    'template' => [
        'id' => $usedSeminarMapMode
            ? 'per-session'
            : (string) (($templateScope === 'session' ? $selectedSessionTemplate['id'] ?? '' : $selectedTemplate['id'] ?? '')),
        'title' => $usedSeminarMapMode
            ? 'Per-seminar template assignment'
            : (string) (($templateScope === 'session' ? $selectedSessionTemplate['title'] ?? 'Seminar Template' : $selectedTemplate['title'] ?? 'Certificate Template')),
        'scope' => $usedSeminarMapMode ? 'seminar_map' : $templateScope,
    ],
    'notification' => $notificationDelivery,
    'pending_count' => count($pendingStudents),
    'pending_students' => $pendingStudents,
], 200);
