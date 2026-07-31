<?php
declare(strict_types=1);

/**
 * Auto-issue certificate for one student after evaluation is complete.
 * Service-role / PHP BFF only — never called from Flutter with anon key.
 *
 * Gates (all required):
 * 1) Student timed out (check_out_at)
 * 2) Evaluation complete for required questions (or no questions)
 * 3) Event/session template exists
 * 4) Idempotent — skip if already issued
 */

function certificate_auto_notify_student(string $studentId, string $title, string $body, array $data = []): void
{
    $studentId = trim($studentId);
    if ($studentId === '') {
        return;
    }
    try {
        require_once __DIR__ . '/user_notifications.php';
        if (function_exists('persist_user_notifications')) {
            persist_user_notifications([$studentId], $title, $body, $data);
        }
    } catch (Throwable $e) {
        // Non-fatal.
    }

    try {
        require_once __DIR__ . '/fcm.php';
        $tokensRes = supabase_request(
            'GET',
            rtrim(SUPABASE_URL, '/') . '/rest/v1/fcm_tokens?select=token&user_id=eq.' . rawurlencode($studentId),
            [
                'Accept: application/json',
                'apikey: ' . SUPABASE_KEY,
                'Authorization: Bearer ' . SUPABASE_KEY,
            ]
        );
        if (!$tokensRes['ok']) {
            return;
        }
        $tokenRows = json_decode((string) ($tokensRes['body'] ?? ''), true);
        $tokens = [];
        if (is_array($tokenRows)) {
            foreach ($tokenRows as $row) {
                $token = trim((string) ($row['token'] ?? ''));
                if ($token !== '') {
                    $tokens[] = $token;
                }
            }
        }
        if ($tokens !== [] && function_exists('send_fcm_notification')) {
            send_fcm_notification($tokens, $title, $body, $data);
        }
    } catch (Throwable $e) {
        // Non-fatal.
    }
}

function certificate_auto_service_headers(): array
{
    return [
        'Accept: application/json',
        'apikey: ' . SUPABASE_KEY,
        'Authorization: Bearer ' . SUPABASE_KEY,
    ];
}

function certificate_auto_write_headers(): array
{
    return [
        'Content-Type: application/json',
        'Accept: application/json',
        'apikey: ' . SUPABASE_KEY,
        'Authorization: Bearer ' . SUPABASE_KEY,
        'Prefer: return=minimal,resolution=merge-duplicates',
    ];
}

function certificate_auto_student_has_checkout_simple(string $eventId, string $studentId, array $headers): bool
{
    $attendanceUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/attendance'
        . '?select=check_out_at,tickets(event_registrations(student_id))'
        . '&tickets.event_registrations.event_id=eq.' . rawurlencode($eventId)
        . '&tickets.event_registrations.student_id=eq.' . rawurlencode($studentId)
        . '&limit=5';
    $attendanceRes = supabase_request('GET', $attendanceUrl, $headers);
    if (!$attendanceRes['ok']) {
        return false;
    }
    $rows = json_decode((string) ($attendanceRes['body'] ?? ''), true);
    if (!is_array($rows)) {
        return false;
    }
    foreach ($rows as $row) {
        if (is_array($row) && trim((string) ($row['check_out_at'] ?? '')) !== '') {
            return true;
        }
    }
    return false;
}

/**
 * @return list<string>
 */
function certificate_auto_checked_out_session_ids(array $sessions, string $studentId, array $headers): array
{
    $sessionIds = [];
    foreach ($sessions as $session) {
        $sid = trim((string) ($session['id'] ?? ''));
        if ($sid !== '') {
            $sessionIds[] = $sid;
        }
    }
    if ($sessionIds === []) {
        return [];
    }

    $attendanceUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/event_session_attendance'
        . '?select=session_id,check_out_at,registration:event_registrations!inner(student_id)'
        . '&session_id=in.(' . implode(',', array_map('rawurlencode', $sessionIds)) . ')'
        . '&registration.student_id=eq.' . rawurlencode($studentId);
    $attendanceRes = supabase_request('GET', $attendanceUrl, $headers);
    if (!$attendanceRes['ok']) {
        // Fallback without !inner if PostgREST rejects embed filter.
        $attendanceUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/event_session_attendance'
            . '?select=session_id,check_out_at,registration:event_registrations(student_id)'
            . '&session_id=in.(' . implode(',', array_map('rawurlencode', $sessionIds)) . ')';
        $attendanceRes = supabase_request('GET', $attendanceUrl, $headers);
    }
    if (!$attendanceRes['ok']) {
        return [];
    }
    $rows = json_decode((string) ($attendanceRes['body'] ?? ''), true);
    if (!is_array($rows)) {
        return [];
    }
    $out = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $registration = isset($row['registration']) && is_array($row['registration']) ? $row['registration'] : [];
        if ((string) ($registration['student_id'] ?? '') !== $studentId) {
            continue;
        }
        if (trim((string) ($row['check_out_at'] ?? '')) === '') {
            continue;
        }
        $sid = trim((string) ($row['session_id'] ?? ''));
        if ($sid !== '') {
            $out[$sid] = true;
        }
    }
    return array_keys($out);
}

/**
 * @param list<array<string,mixed>> $questions
 * @param list<array<string,mixed>> $answers
 */
function certificate_auto_answers_complete(array $questions, array $answers): bool
{
    if ($questions === []) {
        return true;
    }

    $byQuestion = [];
    foreach ($answers as $row) {
        if (!is_array($row)) {
            continue;
        }
        $qid = trim((string) ($row['question_id'] ?? ''));
        $text = trim((string) ($row['answer_text'] ?? ''));
        if ($qid !== '' && $text !== '') {
            $byQuestion[$qid] = true;
        }
    }

    $hasRequired = false;
    foreach ($questions as $q) {
        if (!is_array($q)) {
            continue;
        }
        $qid = trim((string) ($q['id'] ?? ''));
        if ($qid === '') {
            continue;
        }
        $required = !empty($q['required']);
        if ($required) {
            $hasRequired = true;
            if (!isset($byQuestion[$qid])) {
                return false;
            }
        }
    }

    // No required flags → any non-empty answer for every question, or at least one answer if questions exist.
    if (!$hasRequired) {
        if ($byQuestion === []) {
            return false;
        }
        foreach ($questions as $q) {
            if (!is_array($q)) {
                continue;
            }
            $qid = trim((string) ($q['id'] ?? ''));
            if ($qid !== '' && !isset($byQuestion[$qid])) {
                return false;
            }
        }
    }

    return true;
}

/**
 * @return array{ok:bool,complete:bool,reason?:string}
 */
function certificate_auto_eval_is_complete(
    string $eventId,
    string $studentId,
    bool $usesSessions,
    array $sessions,
    array $checkedOutSessionIds,
    array $headers
): array {
    $eventQUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/evaluation_questions'
        . '?select=id,required&event_id=eq.' . rawurlencode($eventId);
    $eventQRes = supabase_request('GET', $eventQUrl, $headers);
    $eventQuestions = $eventQRes['ok'] ? json_decode((string) ($eventQRes['body'] ?? ''), true) : [];
    $eventQuestions = is_array($eventQuestions) ? $eventQuestions : [];

    $eventAUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/evaluation_answers'
        . '?select=question_id,answer_text'
        . '&event_id=eq.' . rawurlencode($eventId)
        . '&student_id=eq.' . rawurlencode($studentId);
    $eventARes = supabase_request('GET', $eventAUrl, $headers);
    $eventAnswers = $eventARes['ok'] ? json_decode((string) ($eventARes['body'] ?? ''), true) : [];
    $eventAnswers = is_array($eventAnswers) ? $eventAnswers : [];

    if (!certificate_auto_answers_complete($eventQuestions, $eventAnswers)) {
        return ['ok' => true, 'complete' => false, 'reason' => 'Event evaluation incomplete'];
    }

    if (!$usesSessions) {
        return ['ok' => true, 'complete' => true];
    }

    foreach ($checkedOutSessionIds as $sessionId) {
        $sqUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/event_session_evaluation_questions'
            . '?select=id,required&session_id=eq.' . rawurlencode($sessionId);
        $sqRes = supabase_request('GET', $sqUrl, $headers);
        $sessionQuestions = $sqRes['ok'] ? json_decode((string) ($sqRes['body'] ?? ''), true) : [];
        $sessionQuestions = is_array($sessionQuestions) ? $sessionQuestions : [];

        $saUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/event_session_evaluation_answers'
            . '?select=question_id,answer_text'
            . '&session_id=eq.' . rawurlencode($sessionId)
            . '&student_id=eq.' . rawurlencode($studentId);
        $saRes = supabase_request('GET', $saUrl, $headers);
        $sessionAnswers = $saRes['ok'] ? json_decode((string) ($saRes['body'] ?? ''), true) : [];
        $sessionAnswers = is_array($sessionAnswers) ? $sessionAnswers : [];

        if (!certificate_auto_answers_complete($sessionQuestions, $sessionAnswers)) {
            return ['ok' => true, 'complete' => false, 'reason' => 'Seminar evaluation incomplete'];
        }
    }

    return ['ok' => true, 'complete' => true];
}

/**
 * @return array{ok:bool,issued:int,notified:bool,error?:string,skipped?:string}
 */
function certificate_auto_issue_for_student(string $eventId, string $studentId, ?array $headers = null): array
{
    $eventId = trim($eventId);
    $studentId = trim($studentId);
    if ($eventId === '' || $studentId === '') {
        return ['ok' => false, 'issued' => 0, 'notified' => false, 'error' => 'Missing ids'];
    }

    $headers = $headers ?? certificate_auto_service_headers();
    if (!function_exists('fetch_event_sessions')) {
        require_once __DIR__ . '/event_sessions.php';
    }

    $eventUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/events'
        . '?select=id,title,created_by,event_mode,event_structure'
        . '&id=eq.' . rawurlencode($eventId) . '&limit=1';
    $eventRes = supabase_request('GET', $eventUrl, $headers);
    $eventRows = $eventRes['ok'] ? json_decode((string) $eventRes['body'], true) : [];
    $event = is_array($eventRows) && isset($eventRows[0]) ? $eventRows[0] : null;
    if (!is_array($event)) {
        return ['ok' => false, 'issued' => 0, 'notified' => false, 'error' => 'Event not found'];
    }

    $sessions = fetch_event_sessions($eventId, $headers);
    $usesSessions = event_uses_sessions(array_merge($event, ['sessions' => $sessions]));
    $writeHeaders = certificate_auto_write_headers();
    $issued = 0;
    $issuedBy = trim((string) ($event['created_by'] ?? '')) ?: null;
    $nowIso = gmdate('c');

    if ($usesSessions) {
        $checkedOutSessions = certificate_auto_checked_out_session_ids($sessions, $studentId, $headers);
        if ($checkedOutSessions === []) {
            return ['ok' => true, 'issued' => 0, 'notified' => false, 'skipped' => 'checkout_required'];
        }

        $eval = certificate_auto_eval_is_complete(
            $eventId,
            $studentId,
            true,
            $sessions,
            $checkedOutSessions,
            $headers
        );
        if (($eval['complete'] ?? false) !== true) {
            return [
                'ok' => true,
                'issued' => 0,
                'notified' => false,
                'skipped' => 'eval_incomplete',
                'error' => (string) ($eval['reason'] ?? 'Evaluation incomplete'),
            ];
        }

        $eventTemplateUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/certificate_templates'
            . '?select=id&event_id=eq.' . rawurlencode($eventId) . '&limit=1';
        $eventTplRes = supabase_request('GET', $eventTemplateUrl, $headers);
        $eventTplRows = $eventTplRes['ok'] ? json_decode((string) $eventTplRes['body'], true) : [];
        $eventTemplateId = is_array($eventTplRows) && isset($eventTplRows[0]['id'])
            ? (string) $eventTplRows[0]['id']
            : '';

        foreach ($checkedOutSessions as $sessionId) {
            $existingUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/event_session_certificates'
                . '?select=id&session_id=eq.' . rawurlencode($sessionId)
                . '&student_id=eq.' . rawurlencode($studentId) . '&limit=1';
            $existingRes = supabase_request('GET', $existingUrl, $headers);
            $existingRows = $existingRes['ok'] ? json_decode((string) ($existingRes['body'] ?? ''), true) : [];
            if (is_array($existingRows) && count($existingRows) > 0) {
                continue;
            }

            $sessionTplUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/event_session_certificate_templates'
                . '?select=id&session_id=eq.' . rawurlencode($sessionId)
                . '&order=created_at.desc&limit=1';
            $sessionTplRes = supabase_request('GET', $sessionTplUrl, $headers);
            $sessionTplRows = $sessionTplRes['ok'] ? json_decode((string) ($sessionTplRes['body'] ?? ''), true) : [];
            $sessionTemplateId = is_array($sessionTplRows) && isset($sessionTplRows[0]['id'])
                ? (string) $sessionTplRows[0]['id']
                : '';

            if ($sessionTemplateId === '' && $eventTemplateId === '') {
                continue;
            }

            $payload = [
                'session_id' => $sessionId,
                'student_id' => $studentId,
                'certificate_code' => bin2hex(random_bytes(8)),
                'issued_at' => $nowIso,
            ];
            if ($issuedBy) {
                $payload['issued_by'] = $issuedBy;
            }
            if ($sessionTemplateId !== '') {
                $payload['session_template_id'] = $sessionTemplateId;
            } else {
                $payload['template_id'] = $eventTemplateId;
            }

            $postUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/event_session_certificates';
            $postRes = supabase_request('POST', $postUrl, $writeHeaders, json_encode([$payload], JSON_UNESCAPED_SLASHES));
            if ($postRes['ok']) {
                $issued++;
            }
        }

        if ($issued === 0 && $eventTemplateId === '') {
            return ['ok' => true, 'issued' => 0, 'notified' => false, 'skipped' => 'no_template'];
        }
    } else {
        if (!certificate_auto_student_has_checkout_simple($eventId, $studentId, $headers)) {
            return ['ok' => true, 'issued' => 0, 'notified' => false, 'skipped' => 'checkout_required'];
        }

        $eval = certificate_auto_eval_is_complete($eventId, $studentId, false, [], [], $headers);
        if (($eval['complete'] ?? false) !== true) {
            return [
                'ok' => true,
                'issued' => 0,
                'notified' => false,
                'skipped' => 'eval_incomplete',
                'error' => (string) ($eval['reason'] ?? 'Evaluation incomplete'),
            ];
        }

        $tplUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/certificate_templates'
            . '?select=id&event_id=eq.' . rawurlencode($eventId) . '&limit=1';
        $tplRes = supabase_request('GET', $tplUrl, $headers);
        $tplRows = $tplRes['ok'] ? json_decode((string) $tplRes['body'], true) : [];
        $templateId = is_array($tplRows) && isset($tplRows[0]['id']) ? (string) $tplRows[0]['id'] : '';
        if ($templateId === '') {
            return ['ok' => true, 'issued' => 0, 'notified' => false, 'skipped' => 'no_template'];
        }

        $existingUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/certificates'
            . '?select=id&event_id=eq.' . rawurlencode($eventId)
            . '&student_id=eq.' . rawurlencode($studentId) . '&limit=1';
        $existingRes = supabase_request('GET', $existingUrl, $headers);
        $existingRows = $existingRes['ok'] ? json_decode((string) $existingRes['body'], true) : [];
        if (is_array($existingRows) && count($existingRows) > 0) {
            return ['ok' => true, 'issued' => 0, 'notified' => false, 'skipped' => 'already_issued'];
        }

        $payload = [
            'event_id' => $eventId,
            'student_id' => $studentId,
            'certificate_code' => bin2hex(random_bytes(8)),
            'template_id' => $templateId,
            'issued_at' => $nowIso,
        ];
        if ($issuedBy) {
            $payload['issued_by'] = $issuedBy;
        }
        $postUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/certificates';
        $postRes = supabase_request('POST', $postUrl, $writeHeaders, json_encode([$payload], JSON_UNESCAPED_SLASHES));
        if ($postRes['ok']) {
            $issued++;
        }
    }

    $notified = false;
    if ($issued > 0) {
        $eventTitle = trim((string) ($event['title'] ?? 'Event'));
        certificate_auto_notify_student(
            $studentId,
            'Certificate Ready',
            'Your certificate for "' . $eventTitle . '" is now available. Open My Certificates to view it.',
            [
                'event_id' => $eventId,
                'type' => 'certificate_ready',
                'route' => 'certificates',
            ]
        );
        $notified = true;
    }

    return ['ok' => true, 'issued' => $issued, 'notified' => $notified];
}
