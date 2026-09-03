<?php
declare(strict_types=1);

/**
 * Auto-issue certificate for one student after evaluation is complete.
 * Service-role / PHP BFF only — never called from Flutter with anon key.
 *
 * Gates (all required):
 * 1) Student timed out (check_out_at)
 * 2) Evaluation complete for required questions (or no questions)
 * 3) Registrar code available in event/session pool
 * 4) Idempotent — skip if already issued
 */

require_once __DIR__ . '/certificate_code_pool.php';

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
    // Do NOT use resolution=merge-duplicates here.
    // After migration 055, (event_id, student_id) / (session_id, student_id) are
    // partial unique indexes — PostgREST upsert can fail with ON CONFLICT errors,
    // so a fresh INSERT never lands. Idempotency is handled by the SELECT-before-insert.
    return [
        'Content-Type: application/json',
        'Accept: application/json',
        'apikey: ' . SUPABASE_KEY,
        'Authorization: Bearer ' . SUPABASE_KEY,
        'Prefer: return=minimal',
    ];
}

/**
 * True when PostgREST rejected a payload column (missing / schema cache).
 */
function certificate_auto_is_missing_column(array $response, string $column): bool
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

/**
 * POST one certificate row; strip optional columns and retry if schema lags Hostinger.
 *
 * @param array<string,mixed> $payload
 * @param list<string> $optionalKeys
 * @return array{ok:bool,body?:string}
 */
function certificate_auto_insert_certificate_row(string $table, array $payload, array $writeHeaders, array $optionalKeys = []): array
{
    $table = trim($table);
    if ($table === '' || $payload === []) {
        return ['ok' => false, 'body' => ''];
    }
    $url = rtrim(SUPABASE_URL, '/') . '/rest/v1/' . rawurlencode($table);
    $attempt = $payload;
    for ($i = 0; $i < 4; $i++) {
        $postRes = supabase_request(
            'POST',
            $url,
            $writeHeaders,
            json_encode([$attempt], JSON_UNESCAPED_SLASHES)
        );
        if ($postRes['ok'] ?? false) {
            return $postRes;
        }
        $stripped = false;
        foreach ($optionalKeys as $key) {
            if (array_key_exists($key, $attempt) && certificate_auto_is_missing_column($postRes, $key)) {
                unset($attempt[$key]);
                $stripped = true;
            }
        }
        if (!$stripped) {
            return $postRes;
        }
    }
    return $postRes ?? ['ok' => false, 'body' => ''];
}

/**
 * Latest event-scoped certificate_templates row (deterministic active design).
 */
function certificate_auto_resolve_event_template_id(string $eventId, array $headers): string
{
    $eventId = trim($eventId);
    if ($eventId === '') {
        return '';
    }
    $url = rtrim(SUPABASE_URL, '/') . '/rest/v1/certificate_templates'
        . '?select=id'
        . '&event_id=eq.' . rawurlencode($eventId)
        . '&order=updated_at.desc.nullslast,created_at.desc'
        . '&limit=1';
    $res = supabase_request('GET', $url, $headers);
    $rows = $res['ok'] ? json_decode((string) ($res['body'] ?? ''), true) : [];
    return is_array($rows) && isset($rows[0]['id']) ? (string) $rows[0]['id'] : '';
}

/**
 * Latest seminar-scoped design for a session.
 */
function certificate_auto_resolve_session_template_id(string $sessionId, array $headers): string
{
    $sessionId = trim($sessionId);
    if ($sessionId === '') {
        return '';
    }
    $url = rtrim(SUPABASE_URL, '/') . '/rest/v1/event_session_certificate_templates'
        . '?select=id'
        . '&session_id=eq.' . rawurlencode($sessionId)
        . '&order=updated_at.desc.nullslast,created_at.desc'
        . '&limit=1';
    $res = supabase_request('GET', $url, $headers);
    $rows = $res['ok'] ? json_decode((string) ($res['body'] ?? ''), true) : [];
    return is_array($rows) && isset($rows[0]['id']) ? (string) $rows[0]['id'] : '';
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
 * Whole-event evaluation questions (shared for simple + seminar events).
 *
 * @return array{ok:bool,complete:bool,reason?:string}
 */
function certificate_auto_event_eval_is_complete(string $eventId, string $studentId, array $headers): array
{
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
    return ['ok' => true, 'complete' => true];
}

/**
 * Per-seminar evaluation completeness (only that session).
 *
 * @return array{ok:bool,complete:bool,reason?:string}
 */
function certificate_auto_session_eval_is_complete(string $sessionId, string $studentId, array $headers): array
{
    $sessionId = trim($sessionId);
    $studentId = trim($studentId);
    if ($sessionId === '' || $studentId === '') {
        return ['ok' => true, 'complete' => false, 'reason' => 'Seminar evaluation incomplete'];
    }

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
    return ['ok' => true, 'complete' => true];
}

/**
 * Prefetch checked-out session ids for many students (1–2 queries).
 *
 * @param list<array<string,mixed>> $sessions
 * @param list<string> $studentIds
 * @return array<string,list<string>> student_id => session_ids
 */
function certificate_auto_prefetch_checked_out_sessions(array $sessions, array $studentIds, array $headers): array
{
    $out = [];
    foreach ($studentIds as $sid) {
        $sid = trim((string) $sid);
        if ($sid !== '') {
            $out[$sid] = [];
        }
    }
    $sessionIds = [];
    foreach ($sessions as $session) {
        if (!is_array($session)) {
            continue;
        }
        $id = trim((string) ($session['id'] ?? ''));
        if ($id !== '') {
            $sessionIds[] = $id;
        }
    }
    if ($sessionIds === [] || $out === []) {
        return $out;
    }

    $url = rtrim(SUPABASE_URL, '/') . '/rest/v1/event_session_attendance'
        . '?select=session_id,check_out_at,registration:event_registrations(student_id)'
        . '&session_id=in.(' . implode(',', array_map('rawurlencode', $sessionIds)) . ')'
        . '&check_out_at=not.is.null'
        . '&limit=5000';
    $res = supabase_request('GET', $url, $headers);
    if (!($res['ok'] ?? false)) {
        return $out;
    }
    $rows = json_decode((string) ($res['body'] ?? ''), true);
    if (!is_array($rows)) {
        return $out;
    }

    foreach ($rows as $row) {
        if (!is_array($row) || trim((string) ($row['check_out_at'] ?? '')) === '') {
            continue;
        }
        $reg = $row['registration'] ?? null;
        if (is_array($reg) && isset($reg[0]) && is_array($reg[0])) {
            $reg = $reg[0];
        }
        if (!is_array($reg)) {
            continue;
        }
        $studentId = trim((string) ($reg['student_id'] ?? ''));
        $sessId = trim((string) ($row['session_id'] ?? ''));
        if ($studentId === '' || $sessId === '' || !isset($out[$studentId])) {
            continue;
        }
        if (!in_array($sessId, $out[$studentId], true)) {
            $out[$studentId][] = $sessId;
        }
    }

    return $out;
}

/**
 * Prefetch event-eval completion for many students.
 *
 * @param list<string> $studentIds
 * @return array<string,bool>
 */
function certificate_auto_prefetch_event_eval_complete(string $eventId, array $studentIds, array $headers): array
{
    $result = [];
    foreach ($studentIds as $sid) {
        $sid = trim((string) $sid);
        if ($sid !== '') {
            $result[$sid] = false;
        }
    }
    if ($result === []) {
        return $result;
    }

    $eventQUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/evaluation_questions'
        . '?select=id,required&event_id=eq.' . rawurlencode($eventId);
    $eventQRes = supabase_request('GET', $eventQUrl, $headers);
    $eventQuestions = $eventQRes['ok'] ? json_decode((string) ($eventQRes['body'] ?? ''), true) : [];
    $eventQuestions = is_array($eventQuestions) ? $eventQuestions : [];

    if ($eventQuestions === []) {
        foreach ($result as $sid => $_) {
            $result[$sid] = true;
        }
        return $result;
    }

    /** @var array<string,list<array<string,mixed>>> $answersByStudent */
    $answersByStudent = [];
    $ids = array_keys($result);
    foreach (array_chunk($ids, 80) as $chunk) {
        $url = rtrim(SUPABASE_URL, '/') . '/rest/v1/evaluation_answers'
            . '?select=student_id,question_id,answer_text'
            . '&event_id=eq.' . rawurlencode($eventId)
            . '&student_id=in.(' . implode(',', array_map('rawurlencode', $chunk)) . ')'
            . '&limit=5000';
        $res = supabase_request('GET', $url, $headers);
        $rows = ($res['ok'] ?? false) ? json_decode((string) ($res['body'] ?? ''), true) : [];
        if (!is_array($rows)) {
            continue;
        }
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $sid = trim((string) ($row['student_id'] ?? ''));
            if ($sid === '') {
                continue;
            }
            $answersByStudent[$sid][] = $row;
        }
    }

    foreach ($result as $sid => $_) {
        $result[$sid] = certificate_auto_answers_complete($eventQuestions, $answersByStudent[$sid] ?? []);
    }
    return $result;
}

/**
 * Prefetch per-session eval completion.
 *
 * @param list<string> $sessionIds
 * @param list<string> $studentIds
 * @return array<string,bool> key = studentId|sessionId
 */
function certificate_auto_prefetch_session_eval_complete(array $sessionIds, array $studentIds, array $headers): array
{
    $result = [];
    $sessionIds = array_values(array_filter(array_map('trim', $sessionIds)));
    $studentIds = array_values(array_filter(array_map('trim', $studentIds)));
    if ($sessionIds === [] || $studentIds === []) {
        return $result;
    }

    /** @var array<string,list<array<string,mixed>>> $questionsBySession */
    $questionsBySession = [];
    $qUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/event_session_evaluation_questions'
        . '?select=id,required,session_id'
        . '&session_id=in.(' . implode(',', array_map('rawurlencode', $sessionIds)) . ')'
        . '&limit=5000';
    $qRes = supabase_request('GET', $qUrl, $headers);
    $qRows = ($qRes['ok'] ?? false) ? json_decode((string) ($qRes['body'] ?? ''), true) : [];
    if (is_array($qRows)) {
        foreach ($qRows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $sess = trim((string) ($row['session_id'] ?? ''));
            if ($sess !== '') {
                $questionsBySession[$sess][] = $row;
            }
        }
    }

    /** @var array<string,list<array<string,mixed>>> $answersByKey */
    $answersByKey = [];
    foreach (array_chunk($studentIds, 60) as $chunk) {
        $aUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/event_session_evaluation_answers'
            . '?select=student_id,session_id,question_id,answer_text'
            . '&session_id=in.(' . implode(',', array_map('rawurlencode', $sessionIds)) . ')'
            . '&student_id=in.(' . implode(',', array_map('rawurlencode', $chunk)) . ')'
            . '&limit=5000';
        $aRes = supabase_request('GET', $aUrl, $headers);
        $aRows = ($aRes['ok'] ?? false) ? json_decode((string) ($aRes['body'] ?? ''), true) : [];
        if (!is_array($aRows)) {
            continue;
        }
        foreach ($aRows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $sid = trim((string) ($row['student_id'] ?? ''));
            $sess = trim((string) ($row['session_id'] ?? ''));
            if ($sid === '' || $sess === '') {
                continue;
            }
            $answersByKey[$sid . '|' . $sess][] = $row;
        }
    }

    foreach ($studentIds as $sid) {
        foreach ($sessionIds as $sess) {
            $key = $sid . '|' . $sess;
            $qs = $questionsBySession[$sess] ?? [];
            if ($qs === []) {
                $result[$key] = true;
                continue;
            }
            $result[$key] = certificate_auto_answers_complete($qs, $answersByKey[$key] ?? []);
        }
    }

    return $result;
}

/**
 * Prefetch simple (non-session) checkout presence.
 *
 * @param list<string> $studentIds
 * @return array<string,bool>
 */
function certificate_auto_prefetch_simple_checkouts(string $eventId, array $studentIds, array $headers): array
{
    $result = [];
    foreach ($studentIds as $sid) {
        $sid = trim((string) $sid);
        if ($sid !== '') {
            $result[$sid] = false;
        }
    }
    if ($result === []) {
        return $result;
    }

    // Match certificate_auto_student_has_checkout_simple: attendance via tickets/registrations.
    $regUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/event_registrations'
        . '?select=id,student_id'
        . '&event_id=eq.' . rawurlencode($eventId)
        . '&student_id=in.(' . implode(',', array_map('rawurlencode', array_keys($result))) . ')'
        . '&limit=2000';
    $regRes = supabase_request('GET', $regUrl, $headers);
    $regRows = ($regRes['ok'] ?? false) ? json_decode((string) ($regRes['body'] ?? ''), true) : [];
    if (!is_array($regRows) || $regRows === []) {
        return $result;
    }

    $regToStudent = [];
    foreach ($regRows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $rid = trim((string) ($row['id'] ?? ''));
        $sid = trim((string) ($row['student_id'] ?? ''));
        if ($rid !== '' && $sid !== '') {
            $regToStudent[$rid] = $sid;
        }
    }
    if ($regToStudent === []) {
        return $result;
    }

    $ticketUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/tickets'
        . '?select=id,registration_id'
        . '&registration_id=in.(' . implode(',', array_map('rawurlencode', array_keys($regToStudent))) . ')'
        . '&limit=2000';
    $ticketRes = supabase_request('GET', $ticketUrl, $headers);
    $ticketRows = ($ticketRes['ok'] ?? false) ? json_decode((string) ($ticketRes['body'] ?? ''), true) : [];
    if (!is_array($ticketRows) || $ticketRows === []) {
        return $result;
    }

    $ticketToStudent = [];
    foreach ($ticketRows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $tid = trim((string) ($row['id'] ?? ''));
        $rid = trim((string) ($row['registration_id'] ?? ''));
        $sid = $regToStudent[$rid] ?? '';
        if ($tid !== '' && $sid !== '') {
            $ticketToStudent[$tid] = $sid;
        }
    }
    if ($ticketToStudent === []) {
        return $result;
    }

    $attUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/attendance'
        . '?select=ticket_id,check_out_at'
        . '&ticket_id=in.(' . implode(',', array_map('rawurlencode', array_keys($ticketToStudent))) . ')'
        . '&check_out_at=not.is.null'
        . '&limit=2000';
    $attRes = supabase_request('GET', $attUrl, $headers);
    $attRows = ($attRes['ok'] ?? false) ? json_decode((string) ($attRes['body'] ?? ''), true) : [];
    if (!is_array($attRows)) {
        return $result;
    }
    foreach ($attRows as $row) {
        if (!is_array($row) || trim((string) ($row['check_out_at'] ?? '')) === '') {
            continue;
        }
        $tid = trim((string) ($row['ticket_id'] ?? ''));
        $sid = $ticketToStudent[$tid] ?? '';
        if ($sid !== '' && isset($result[$sid])) {
            $result[$sid] = true;
        }
    }

    return $result;
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
    $eventEval = certificate_auto_event_eval_is_complete($eventId, $studentId, $headers);
    if (($eventEval['complete'] ?? false) !== true) {
        return $eventEval;
    }

    if (!$usesSessions) {
        return ['ok' => true, 'complete' => true];
    }

    foreach ($checkedOutSessionIds as $sessionId) {
        $sessionEval = certificate_auto_session_eval_is_complete((string) $sessionId, $studentId, $headers);
        if (($sessionEval['complete'] ?? false) !== true) {
            return $sessionEval;
        }
    }

    return ['ok' => true, 'complete' => true];
}

/**
 * True when this event is set up for certificates (linked template and/or code pool).
 * Events without any cert setup should not mention certificates after evaluation.
 *
 * @param list<array<string, mixed>> $sessions
 */
function certificate_auto_event_has_setup(
    string $eventId,
    array $headers,
    bool $usesSessions,
    array $sessions
): bool {
    $eventId = trim($eventId);
    if ($eventId === '') {
        return false;
    }

    if (certificate_auto_resolve_event_template_id($eventId, $headers) !== '') {
        return true;
    }

    if ($usesSessions) {
        foreach ($sessions as $session) {
            if (!is_array($session)) {
                continue;
            }
            $sid = trim((string) ($session['id'] ?? ''));
            if ($sid !== '' && certificate_auto_resolve_session_template_id($sid, $headers) !== '') {
                return true;
            }
        }
    }

    if (certificate_pool_has_any_code($eventId, null)) {
        return true;
    }

    if ($usesSessions) {
        foreach ($sessions as $session) {
            if (!is_array($session)) {
                continue;
            }
            $sid = trim((string) ($session['id'] ?? ''));
            if ($sid !== '' && certificate_pool_has_any_code($eventId, $sid)) {
                return true;
            }
        }
    }

    return false;
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

    // No linked certificate / code pool — eval-only event; skip cert messaging.
    if (!certificate_auto_event_has_setup($eventId, $headers, $usesSessions, $sessions)) {
        return ['ok' => true, 'issued' => 0, 'notified' => false, 'skipped' => 'not_configured'];
    }

    $writeHeaders = certificate_auto_write_headers();
    $issued = 0;
    $issuedBy = trim((string) ($event['created_by'] ?? '')) ?: null;
    $nowIso = gmdate('c');

    if ($usesSessions) {
        $checkedOutSessions = certificate_auto_checked_out_session_ids($sessions, $studentId, $headers);
        if ($checkedOutSessions === []) {
            return ['ok' => true, 'issued' => 0, 'notified' => false, 'skipped' => 'checkout_required'];
        }

        // Whole-event questions (if any) must be done once; each seminar issues independently.
        $eventEval = certificate_auto_event_eval_is_complete($eventId, $studentId, $headers);
        if (($eventEval['complete'] ?? false) !== true) {
            return [
                'ok' => true,
                'issued' => 0,
                'notified' => false,
                'skipped' => 'eval_incomplete',
                'error' => (string) ($eventEval['reason'] ?? 'Evaluation incomplete'),
            ];
        }

        $eventTemplateId = certificate_auto_resolve_event_template_id($eventId, $headers);
        $eventTitleSnap = trim((string) ($event['title'] ?? 'Event'));
        $sessionsById = [];
        foreach ($sessions as $s) {
            if (!is_array($s)) {
                continue;
            }
            $sid = trim((string) ($s['id'] ?? ''));
            if ($sid !== '') {
                $sessionsById[$sid] = $s;
            }
        }

        $alreadyIssued = 0;
        $evalIncompleteSessions = 0;
        $poolMisses = 0;
        $insertFails = 0;

        foreach ($checkedOutSessions as $sessionId) {
            $existingUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/event_session_certificates'
                . '?select=id&session_id=eq.' . rawurlencode($sessionId)
                . '&student_id=eq.' . rawurlencode($studentId) . '&limit=1';
            $existingRes = supabase_request('GET', $existingUrl, $headers);
            $existingRows = $existingRes['ok'] ? json_decode((string) ($existingRes['body'] ?? ''), true) : [];
            if (is_array($existingRows) && count($existingRows) > 0) {
                $alreadyIssued++;
                continue;
            }

            $sessionEval = certificate_auto_session_eval_is_complete($sessionId, $studentId, $headers);
            if (($sessionEval['complete'] ?? false) !== true) {
                $evalIncompleteSessions++;
                continue;
            }

            $sessionTemplateId = certificate_auto_resolve_session_template_id($sessionId, $headers);

            $claimed = certificate_pool_claim_next($eventId, $studentId, $sessionId);
            if ($claimed === null || $claimed === '') {
                // Session-scoped mint only — do not fall back to event-level pool
                // (that reuses seminar 1's …01.* counter for seminar 2).
                $claimed = certificate_pool_mint_next_sequential($eventId, $studentId, $sessionId);
            }
            if ($claimed === null || $claimed === '') {
                $poolMisses++;
                continue;
            }

            $sessionRow = $sessionsById[$sessionId] ?? [];
            $sessionTitleSnap = function_exists('build_session_display_name')
                ? build_session_display_name(is_array($sessionRow) ? $sessionRow : [])
                : trim((string) (($sessionRow['topic'] ?? '') ?: ($sessionRow['title'] ?? '')));

            $payload = [
                'session_id' => $sessionId,
                'event_id' => $eventId,
                'student_id' => $studentId,
                'certificate_code' => $claimed,
                'issued_at' => $nowIso,
                'event_title' => $eventTitleSnap,
                'session_title' => $sessionTitleSnap,
            ];
            if ($issuedBy) {
                $payload['issued_by'] = $issuedBy;
            }
            if ($sessionTemplateId !== '') {
                $payload['session_template_id'] = $sessionTemplateId;
            } elseif ($eventTemplateId !== '') {
                $payload['template_id'] = $eventTemplateId;
            }

            $postRes = certificate_auto_insert_certificate_row(
                'event_session_certificates',
                $payload,
                $writeHeaders,
                ['session_template_id', 'template_id', 'event_id', 'event_title', 'session_title', 'issued_by']
            );
            if ($postRes['ok']) {
                $issued++;
            } else {
                $insertFails++;
                // Don't burn FIFO codes when insert fails — but never release a code
                // already written onto another certificate row (double-submit race).
                if (!certificate_pool_code_already_on_certificate($claimed, $studentId)) {
                    certificate_pool_release($eventId, $studentId, $claimed, null);
                }
            }
        }

        if ($issued === 0) {
            if ($alreadyIssued > 0 && $poolMisses === 0 && $evalIncompleteSessions === 0 && $insertFails === 0) {
                return ['ok' => true, 'issued' => 0, 'notified' => false, 'skipped' => 'already_issued'];
            }
            if ($evalIncompleteSessions > 0 && $poolMisses === 0 && $insertFails === 0 && $alreadyIssued === 0) {
                return [
                    'ok' => true,
                    'issued' => 0,
                    'notified' => false,
                    'skipped' => 'eval_incomplete',
                    'error' => 'Seminar evaluation incomplete',
                ];
            }
            if ($poolMisses > 0) {
                return ['ok' => true, 'issued' => 0, 'notified' => false, 'skipped' => 'no_pool_codes'];
            }
            if ($insertFails > 0) {
                return ['ok' => true, 'issued' => 0, 'notified' => false, 'skipped' => 'insert_failed'];
            }
            return ['ok' => true, 'issued' => 0, 'notified' => false, 'skipped' => 'no_pool_codes'];
        }
    } else {
        if (!certificate_auto_student_has_checkout_simple($eventId, $studentId, $headers)) {
            return ['ok' => true, 'issued' => 0, 'notified' => false, 'skipped' => 'checkout_required'];
        }

        $eval = certificate_auto_event_eval_is_complete($eventId, $studentId, $headers);
        if (($eval['complete'] ?? false) !== true) {
            return [
                'ok' => true,
                'issued' => 0,
                'notified' => false,
                'skipped' => 'eval_incomplete',
                'error' => (string) ($eval['reason'] ?? 'Evaluation incomplete'),
            ];
        }

        $templateId = certificate_auto_resolve_event_template_id($eventId, $headers);

        $existingUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/certificates'
            . '?select=id&event_id=eq.' . rawurlencode($eventId)
            . '&student_id=eq.' . rawurlencode($studentId) . '&limit=1';
        $existingRes = supabase_request('GET', $existingUrl, $headers);
        $existingRows = $existingRes['ok'] ? json_decode((string) $existingRes['body'], true) : [];
        if (is_array($existingRows) && count($existingRows) > 0) {
            return ['ok' => true, 'issued' => 0, 'notified' => false, 'skipped' => 'already_issued'];
        }

        $claimed = certificate_pool_claim_next($eventId, $studentId, null);
        if ($claimed === null || $claimed === '') {
            $claimed = certificate_pool_mint_next_sequential($eventId, $studentId, null);
        }
        if ($claimed === null || $claimed === '') {
            return ['ok' => true, 'issued' => 0, 'notified' => false, 'skipped' => 'no_pool_codes'];
        }

        $payload = [
            'event_id' => $eventId,
            'student_id' => $studentId,
            'certificate_code' => $claimed,
            'issued_at' => $nowIso,
            'event_title' => trim((string) ($event['title'] ?? 'Event')),
        ];
        if ($templateId !== '') {
            $payload['template_id'] = $templateId;
        }
        if ($issuedBy) {
            $payload['issued_by'] = $issuedBy;
        }
        $postRes = certificate_auto_insert_certificate_row(
            'certificates',
            $payload,
            $writeHeaders,
            ['template_id', 'event_title', 'issued_by']
        );
        if ($postRes['ok']) {
            $issued++;
        } else {
            if (!certificate_pool_code_already_on_certificate($claimed, $studentId)) {
                certificate_pool_release($eventId, $studentId, $claimed, null);
            }
            return ['ok' => true, 'issued' => 0, 'notified' => false, 'skipped' => 'insert_failed'];
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

/**
 * Teacher dashboard: who already received auto-certs vs who finished eval but still missing.
 *
 * @return array{
 *   ok:bool,
 *   uses_sessions:bool,
 *   received:list<array<string,mixed>>,
 *   missing:list<array<string,mixed>>,
 *   error?:string
 * }
 */
function certificate_auto_issue_status_for_event(string $eventId, ?array $headers = null, int $limit = 300): array
{
    $eventId = trim($eventId);
    if ($eventId === '') {
        return ['ok' => false, 'uses_sessions' => false, 'received' => [], 'missing' => [], 'error' => 'Missing event_id'];
    }

    $headers = $headers ?? certificate_auto_service_headers();
    $limit = max(1, min(500, $limit));

    if (!function_exists('fetch_event_sessions')) {
        require_once __DIR__ . '/event_sessions.php';
    }

    $eventUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/events'
        . '?select=id,title,event_mode,event_structure'
        . '&id=eq.' . rawurlencode($eventId) . '&limit=1';
    $eventRes = supabase_request('GET', $eventUrl, $headers);
    $eventRows = $eventRes['ok'] ? json_decode((string) ($eventRes['body'] ?? ''), true) : [];
    $event = is_array($eventRows) && isset($eventRows[0]) ? $eventRows[0] : null;
    if (!is_array($event)) {
        return ['ok' => false, 'uses_sessions' => false, 'received' => [], 'missing' => [], 'error' => 'Event not found'];
    }

    $sessions = fetch_event_sessions($eventId, $headers);
    $usesSessions = event_uses_sessions(array_merge($event, ['sessions' => $sessions]));

    $regUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/event_registrations'
        . '?select=student_id,registered_at'
        . '&event_id=eq.' . rawurlencode($eventId)
        . '&order=registered_at.asc'
        . '&limit=' . $limit;
    $regRes = supabase_request('GET', $regUrl, $headers);
    if (!$regRes['ok']) {
        $regUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/event_registrations'
            . '?select=student_id'
            . '&event_id=eq.' . rawurlencode($eventId)
            . '&limit=' . $limit;
        $regRes = supabase_request('GET', $regUrl, $headers);
    }
    if (!$regRes['ok']) {
        return ['ok' => false, 'uses_sessions' => $usesSessions, 'received' => [], 'missing' => [], 'error' => 'Failed to load registrations'];
    }
    $regRows = json_decode((string) ($regRes['body'] ?? ''), true);
    if (!is_array($regRows)) {
        $regRows = [];
    }

    $studentIds = [];
    foreach ($regRows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $sid = trim((string) ($row['student_id'] ?? ''));
        if ($sid !== '' && !isset($studentIds[$sid])) {
            $studentIds[$sid] = true;
        }
    }
    $ids = array_keys($studentIds);
    if ($ids === []) {
        return ['ok' => true, 'uses_sessions' => $usesSessions, 'received' => [], 'missing' => []];
    }

    // Names
    $namesById = [];
    $chunkSize = 80;
    for ($i = 0; $i < count($ids); $i += $chunkSize) {
        $chunk = array_slice($ids, $i, $chunkSize);
        $usersUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/users'
            . '?select=id,first_name,middle_name,last_name,suffix,email'
            . '&id=in.(' . implode(',', array_map('rawurlencode', $chunk)) . ')';
        $usersRes = supabase_request('GET', $usersUrl, $headers);
        $userRows = $usersRes['ok'] ? json_decode((string) ($usersRes['body'] ?? ''), true) : [];
        if (!is_array($userRows)) {
            continue;
        }
        foreach ($userRows as $u) {
            if (!is_array($u)) {
                continue;
            }
            $uid = trim((string) ($u['id'] ?? ''));
            if ($uid === '') {
                continue;
            }
            $parts = array_filter([
                trim((string) ($u['first_name'] ?? '')),
                trim((string) ($u['middle_name'] ?? '')),
                trim((string) ($u['last_name'] ?? '')),
                trim((string) ($u['suffix'] ?? '')),
            ], static fn ($p) => $p !== '');
            $name = trim(implode(' ', $parts));
            if ($name === '') {
                $name = trim((string) ($u['email'] ?? 'Student'));
            }
            $namesById[$uid] = [
                'name' => $name,
                'email' => trim((string) ($u['email'] ?? '')),
            ];
        }
    }

    $received = [];
    $missing = [];

    if ($usesSessions) {
        // Load all session certs for this event's sessions.
        $sessionIds = [];
        $sessionTitles = [];
        $sessionOrder = [];
        foreach ($sessions as $s) {
            if (!is_array($s)) {
                continue;
            }
            $sid = trim((string) ($s['id'] ?? ''));
            if ($sid === '') {
                continue;
            }
            $sessionIds[] = $sid;
            $sessionOrder[$sid] = count($sessionIds);
            $sessionTitles[$sid] = function_exists('build_session_display_name')
                ? build_session_display_name($s)
                : trim((string) (($s['topic'] ?? '') ?: ($s['title'] ?? 'Seminar')));
        }

        $certsByStudentSession = [];
        if ($sessionIds !== []) {
            $certUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/event_session_certificates'
                . '?select=student_id,session_id,certificate_code,issued_at'
                . '&session_id=in.(' . implode(',', array_map('rawurlencode', $sessionIds)) . ')'
                . '&limit=2000';
            $certRes = supabase_request('GET', $certUrl, $headers);
            $certRows = $certRes['ok'] ? json_decode((string) ($certRes['body'] ?? ''), true) : [];
            if (is_array($certRows)) {
                foreach ($certRows as $c) {
                    if (!is_array($c)) {
                        continue;
                    }
                    $st = trim((string) ($c['student_id'] ?? ''));
                    $sess = trim((string) ($c['session_id'] ?? ''));
                    if ($st === '' || $sess === '') {
                        continue;
                    }
                    $certsByStudentSession[$st][$sess] = $c;
                }
            }
        }

        $checkedOutByStudent = certificate_auto_prefetch_checked_out_sessions($sessions, $ids, $headers);
        $eventEvalByStudent = certificate_auto_prefetch_event_eval_complete($eventId, $ids, $headers);
        $sessionEvalByKey = certificate_auto_prefetch_session_eval_complete($sessionIds, $ids, $headers);

        foreach ($ids as $studentId) {
            $checkedOut = $checkedOutByStudent[$studentId] ?? [];
            $eventEvalOk = ($eventEvalByStudent[$studentId] ?? false) === true;

            $hasAnyCert = false;
            $missingSessions = [];
            $receivedSessions = [];

            foreach ($checkedOut as $sessId) {
                $sessEvalOk = ($sessionEvalByKey[$studentId . '|' . $sessId] ?? false) === true;
                $hasCert = isset($certsByStudentSession[$studentId][$sessId]);
                if ($hasCert) {
                    $hasAnyCert = true;
                    $c = $certsByStudentSession[$studentId][$sessId];
                    $receivedSessions[] = [
                        'session_id' => $sessId,
                        'session_title' => $sessionTitles[$sessId] ?? 'Seminar',
                        'sort_order' => $sessionOrder[$sessId] ?? 999,
                        'certificate_code' => (string) ($c['certificate_code'] ?? ''),
                        'issued_at' => (string) ($c['issued_at'] ?? ''),
                    ];
                } elseif ($eventEvalOk && $sessEvalOk) {
                    $missingSessions[] = [
                        'session_id' => $sessId,
                        'session_title' => $sessionTitles[$sessId] ?? 'Seminar',
                        'sort_order' => $sessionOrder[$sessId] ?? 999,
                    ];
                }
            }

            // Keep seminar order (Seminar 1 then Seminar 2) so codes read …05.01/…06.01.
            $bySeminarOrder = static fn (array $a, array $b): int => ((int) ($a['sort_order'] ?? 999)) <=> ((int) ($b['sort_order'] ?? 999));
            usort($receivedSessions, $bySeminarOrder);
            usort($missingSessions, $bySeminarOrder);

            $profile = $namesById[$studentId] ?? ['name' => 'Student', 'email' => ''];
            if ($receivedSessions !== []) {
                $received[] = [
                    'student_id' => $studentId,
                    'name' => $profile['name'],
                    'email' => $profile['email'],
                    'status' => 'received',
                    'sessions' => $receivedSessions,
                    'certificate_code' => (string) ($receivedSessions[0]['certificate_code'] ?? ''),
                ];
            }
            if ($missingSessions !== []) {
                $missing[] = [
                    'student_id' => $studentId,
                    'name' => $profile['name'],
                    'email' => $profile['email'],
                    'status' => 'missing',
                    'reason' => 'Evaluation complete — certificate not issued yet',
                    'sessions' => $missingSessions,
                ];
            } elseif ($checkedOut !== [] && $eventEvalOk && !$hasAnyCert) {
                // Checked out + event eval done but session eval incomplete for all — skip missing list.
            }
        }
    } else {
        $certUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/certificates'
            . '?select=student_id,certificate_code,issued_at'
            . '&event_id=eq.' . rawurlencode($eventId)
            . '&limit=2000';
        $certRes = supabase_request('GET', $certUrl, $headers);
        $certRows = $certRes['ok'] ? json_decode((string) ($certRes['body'] ?? ''), true) : [];
        $certsByStudent = [];
        if (is_array($certRows)) {
            foreach ($certRows as $c) {
                if (!is_array($c)) {
                    continue;
                }
                $st = trim((string) ($c['student_id'] ?? ''));
                if ($st !== '') {
                    $certsByStudent[$st] = $c;
                }
            }
        }

        $checkoutByStudent = certificate_auto_prefetch_simple_checkouts($eventId, $ids, $headers);
        $eventEvalByStudent = certificate_auto_prefetch_event_eval_complete($eventId, $ids, $headers);

        foreach ($ids as $studentId) {
            $profile = $namesById[$studentId] ?? ['name' => 'Student', 'email' => ''];
            if (isset($certsByStudent[$studentId])) {
                $c = $certsByStudent[$studentId];
                $received[] = [
                    'student_id' => $studentId,
                    'name' => $profile['name'],
                    'email' => $profile['email'],
                    'status' => 'received',
                    'certificate_code' => (string) ($c['certificate_code'] ?? ''),
                    'issued_at' => (string) ($c['issued_at'] ?? ''),
                ];
                continue;
            }

            if (!($checkoutByStudent[$studentId] ?? false)) {
                continue;
            }
            if (($eventEvalByStudent[$studentId] ?? false) !== true) {
                continue;
            }
            $missing[] = [
                'student_id' => $studentId,
                'name' => $profile['name'],
                'email' => $profile['email'],
                'status' => 'missing',
                'reason' => 'Evaluation complete — certificate not issued yet',
            ];
        }
    }

    usort($received, static fn ($a, $b) => strcasecmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? '')));
    usort($missing, static fn ($a, $b) => strcasecmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? '')));

    $poolReady = certificate_pool_has_any_code($eventId, null);
    if (!$poolReady && $usesSessions && is_array($sessions)) {
        foreach ($sessions as $sess) {
            if (!is_array($sess)) {
                continue;
            }
            $sid = trim((string) ($sess['id'] ?? ''));
            if ($sid !== '' && certificate_pool_has_any_code($eventId, $sid)) {
                $poolReady = true;
                break;
            }
        }
    }
    if (!$poolReady) {
        // Canvas may still hold a seed even if Import never wrote the pool.
        $fromCanvas = certificate_pool_read_seed_from_linked_template($eventId, null);
        $poolReady = trim((string) ($fromCanvas['code'] ?? '')) !== '';
    }
    if (!$poolReady && $usesSessions && is_array($sessions)) {
        foreach ($sessions as $sess) {
            if (!is_array($sess)) {
                continue;
            }
            $sid = trim((string) ($sess['id'] ?? ''));
            if ($sid === '') {
                continue;
            }
            $sessCanvas = certificate_pool_read_seed_from_linked_template($eventId, $sid);
            if (trim((string) ($sessCanvas['code'] ?? '')) !== '') {
                $poolReady = true;
                break;
            }
        }
    }

    $missingReason = $poolReady
        ? 'Evaluation complete — certificate not issued yet'
        : 'No certificate seed — Import a PPTX or enter a seed (e.g. LU-AA-FO-180-01) under Import Certificate, then Send';

    if (!$poolReady) {
        foreach ($missing as &$missRow) {
            if (is_array($missRow)) {
                $missRow['reason'] = $missingReason;
                $missRow['skip_code'] = 'no_pool_codes';
            }
        }
        unset($missRow);
    }

    return [
        'ok' => true,
        'uses_sessions' => $usesSessions,
        'received' => $received,
        'missing' => $missing,
        'received_count' => count($received),
        'missing_count' => count($missing),
        'pool_ready' => $poolReady,
    ];
}

/**
 * Manually send (same auto-issue path: linked template + sequential code) to selected students.
 *
 * @param list<string> $studentIds
 * @return array{ok:bool,attempted:int,issued_total:int,students:list<array<string,mixed>>,error?:string}
 */
function certificate_auto_issue_manual_for_students(string $eventId, array $studentIds, ?array $headers = null): array
{
    $eventId = trim($eventId);
    $headers = $headers ?? certificate_auto_service_headers();
    if ($eventId === '') {
        return ['ok' => false, 'attempted' => 0, 'issued_total' => 0, 'students' => [], 'error' => 'Missing event_id'];
    }

    $seen = [];
    $attempted = 0;
    $issuedTotal = 0;
    $out = [];
    foreach ($studentIds as $rawId) {
        $studentId = trim((string) $rawId);
        if ($studentId === '' || isset($seen[$studentId])) {
            continue;
        }
        $seen[$studentId] = true;
        $attempted++;
        $result = certificate_auto_issue_for_student($eventId, $studentId, $headers);
        $n = (int) ($result['issued'] ?? 0);
        $issuedTotal += $n;
        $out[] = [
            'student_id' => $studentId,
            'issued' => $n,
            'skipped' => (string) ($result['skipped'] ?? ''),
            'error' => (string) ($result['error'] ?? ''),
        ];
    }

    return [
        'ok' => true,
        'attempted' => $attempted,
        'issued_total' => $issuedTotal,
        'students' => $out,
    ];
}

/**
 * Sweep eligible registrants and auto-issue any missing FIFO certs.
 * Idempotent — safe to re-run after pool refill.
 *
 * @return array{ok:bool,attempted:int,issued_total:int,students:list<array<string,mixed>>,error?:string}
 */
function certificate_auto_issue_pending_for_event(string $eventId, ?array $headers = null, int $limit = 200): array
{
    $eventId = trim($eventId);
    if ($eventId === '') {
        return ['ok' => false, 'attempted' => 0, 'issued_total' => 0, 'students' => [], 'error' => 'Missing event_id'];
    }

    $headers = $headers ?? certificate_auto_service_headers();
    $limit = max(1, min(500, $limit));

    $regUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/event_registrations'
        . '?select=student_id,registered_at'
        . '&event_id=eq.' . rawurlencode($eventId)
        . '&order=registered_at.asc'
        . '&limit=' . $limit;
    $regRes = supabase_request('GET', $regUrl, $headers);
    if (!$regRes['ok']) {
        // Fallback without order if schema differs.
        $regUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/event_registrations'
            . '?select=student_id'
            . '&event_id=eq.' . rawurlencode($eventId)
            . '&limit=' . $limit;
        $regRes = supabase_request('GET', $regUrl, $headers);
    }
    if (!$regRes['ok']) {
        $detail = trim((string) ($regRes['body'] ?? $regRes['error'] ?? ''));
        return [
            'ok' => false,
            'attempted' => 0,
            'issued_total' => 0,
            'students' => [],
            'error' => 'Failed to load registrations' . ($detail !== '' ? ': ' . mb_substr($detail, 0, 180) : ''),
        ];
    }
    $rows = json_decode((string) ($regRes['body'] ?? ''), true);
    if (!is_array($rows)) {
        $rows = [];
    }

    $seen = [];
    $students = [];
    $issuedTotal = 0;
    $attempted = 0;

    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $studentId = trim((string) ($row['student_id'] ?? ''));
        if ($studentId === '' || isset($seen[$studentId])) {
            continue;
        }
        $seen[$studentId] = true;
        $attempted++;
        $result = certificate_auto_issue_for_student($eventId, $studentId, $headers);
        $n = (int) ($result['issued'] ?? 0);
        $issuedTotal += $n;
        $students[] = [
            'student_id' => $studentId,
            'issued' => $n,
            'skipped' => (string) ($result['skipped'] ?? ''),
            'error' => (string) ($result['error'] ?? ''),
        ];
    }

    return [
        'ok' => true,
        'attempted' => $attempted,
        'issued_total' => $issuedTotal,
        'students' => $students,
    ];
}

/**
 * Student self-heal: try auto-issue for one event, or every registered event (capped).
 *
 * @return array{ok:bool,events:list<array<string,mixed>>,issued_total:int}
 */
function certificate_auto_issue_pending_for_student(string $studentId, ?string $eventId = null, ?array $headers = null): array
{
    $studentId = trim($studentId);
    $eventId = $eventId !== null ? trim($eventId) : '';
    $headers = $headers ?? certificate_auto_service_headers();
    if ($studentId === '') {
        return ['ok' => false, 'events' => [], 'issued_total' => 0];
    }

    $eventIds = [];
    if ($eventId !== '') {
        $eventIds[] = $eventId;
    } else {
        $regUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/event_registrations'
            . '?select=event_id'
            . '&student_id=eq.' . rawurlencode($studentId)
            . '&order=registered_at.desc'
            . '&limit=30';
        $regRes = supabase_request('GET', $regUrl, $headers);
        if (!$regRes['ok']) {
            $regUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/event_registrations'
                . '?select=event_id'
                . '&student_id=eq.' . rawurlencode($studentId)
                . '&limit=30';
            $regRes = supabase_request('GET', $regUrl, $headers);
        }
        $rows = $regRes['ok'] ? json_decode((string) ($regRes['body'] ?? ''), true) : [];
        if (is_array($rows)) {
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $eid = trim((string) ($row['event_id'] ?? ''));
                if ($eid !== '' && !in_array($eid, $eventIds, true)) {
                    $eventIds[] = $eid;
                }
            }
        }
    }

    $events = [];
    $issuedTotal = 0;
    foreach ($eventIds as $eid) {
        $result = certificate_auto_issue_for_student($eid, $studentId, $headers);
        $n = (int) ($result['issued'] ?? 0);
        $issuedTotal += $n;
        $events[] = [
            'event_id' => $eid,
            'issued' => $n,
            'skipped' => (string) ($result['skipped'] ?? ''),
            'error' => (string) ($result['error'] ?? ''),
        ];
    }

    return ['ok' => true, 'events' => $events, 'issued_total' => $issuedTotal];
}
