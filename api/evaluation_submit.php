<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/session.php';
session_bootstrap();

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/supabase.php';
require_once __DIR__ . '/../includes/json.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/event_sessions.php';
require_once __DIR__ . '/../includes/registration_access.php';
require_once __DIR__ . '/../includes/event_session_targeting.php';
require_once __DIR__ . '/../includes/certificate_auto_issue.php';
require_once __DIR__ . '/../includes/evaluation_notifications.php';

$user = require_role(['student']);
$data = require_post_json();
require_csrf_from_json($data);

$eventId = isset($data['event_id']) ? trim((string) $data['event_id']) : '';
$legacySessionId = isset($data['session_id']) ? trim((string) $data['session_id']) : '';
if ($eventId === '') {
    json_response(['ok' => false, 'error' => 'event_id required'], 400);
}

$rawAnswers = [];
if (isset($data['answers']) && is_array($data['answers'])) {
    $rawAnswers = $data['answers'];
} elseif (isset($data['answers_json']) && is_string($data['answers_json'])) {
    $decoded = json_decode($data['answers_json'], true);
    $rawAnswers = is_array($decoded) ? $decoded : [];
}

$headers = [
    'Accept: application/json',
    'apikey: ' . SUPABASE_KEY,
    'Authorization: Bearer ' . SUPABASE_KEY,
];

function evaluation_dedupe_answer_rows(array $rows): array
{
    $byKey = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $questionId = trim((string) ($row['question_id'] ?? ''));
        $studentId = trim((string) ($row['student_id'] ?? ''));
        if ($questionId === '' || $studentId === '') {
            continue;
        }
        $byKey[$questionId . '|' . $studentId] = $row;
    }
    return array_values($byKey);
}

function evaluation_attendance_counts_as_present(array $row): bool
{
    $status = strtolower(trim((string) ($row['status'] ?? '')));
    $checkInAt = trim((string) ($row['check_in_at'] ?? ''));

    if ($checkInAt !== '') {
        return true;
    }

    return in_array($status, ['present', 'scanned', 'late', 'early'], true);
}

function evaluation_attendance_counts_as_checked_out(array $row): bool
{
    return trim((string) ($row['check_out_at'] ?? '')) !== '';
}

function evaluation_fetch_event_questions(string $eventId, array $headers): array
{
    $url = rtrim(SUPABASE_URL, '/') . '/rest/v1/evaluation_questions'
        . '?select=id,required,field_type,question_text'
        . '&event_id=eq.' . rawurlencode($eventId)
        . '&order=sort_order.asc';
    $res = supabase_request('GET', $url, $headers);
    if (!$res['ok']) {
        throw new RuntimeException(build_error(
            $res['body'] ?? null,
            (int) ($res['status'] ?? 0),
            $res['error'] ?? null,
            'Question lookup failed'
        ));
    }

    $rows = json_decode((string) $res['body'], true);
    return is_array($rows) ? $rows : [];
}

function evaluation_fetch_session_questions(string $sessionId, array $headers): array
{
    $url = rtrim(SUPABASE_URL, '/') . '/rest/v1/event_session_evaluation_questions'
        . '?select=id,required,field_type,question_text'
        . '&session_id=eq.' . rawurlencode($sessionId)
        . '&order=sort_order.asc';
    $res = supabase_request('GET', $url, $headers);
    if (!$res['ok']) {
        throw new RuntimeException(build_error(
            $res['body'] ?? null,
            (int) ($res['status'] ?? 0),
            $res['error'] ?? null,
            'Question lookup failed'
        ));
    }

    $rows = json_decode((string) $res['body'], true);
    return is_array($rows) ? $rows : [];
}

function evaluation_has_simple_attendance(string $eventId, string $studentId, array $headers): bool
{
    $attendanceUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/attendance'
        . '?select=status,check_in_at,check_out_at,tickets(registration_id,event_registrations(student_id,event_id))'
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
    if (!is_array($attendanceRows)) {
        return false;
    }

    foreach ($attendanceRows as $row) {
        if (!is_array($row) || !evaluation_attendance_counts_as_present($row)) {
            continue;
        }
        if (!evaluation_attendance_counts_as_checked_out($row)) {
            continue;
        }

        $ticket = isset($row['tickets']) && is_array($row['tickets']) ? $row['tickets'] : [];
        $registration = isset($ticket['event_registrations']) && is_array($ticket['event_registrations'])
            ? $ticket['event_registrations']
            : [];
        if ((string) ($registration['student_id'] ?? '') === $studentId) {
            return true;
        }
    }

    return false;
}

function evaluation_attended_session_ids(array $sessions, string $studentId, array $headers): array
{
    $sessionIds = [];
    foreach ($sessions as $session) {
        $sid = trim((string) ($session['id'] ?? ''));
        if ($sid !== '') {
            $sessionIds[] = $sid;
        }
    }

    if (count($sessionIds) === 0) {
        return [];
    }

    $attendanceUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/event_session_attendance'
        . '?select=session_id,status,check_in_at,check_out_at,registration:event_registrations(student_id)'
        . '&session_id=in.(' . implode(',', array_map('rawurlencode', $sessionIds)) . ')';
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
    if (!is_array($attendanceRows)) {
        return [];
    }

    $present = [];
    foreach ($attendanceRows as $row) {
        if (!is_array($row) || !evaluation_attendance_counts_as_present($row)) {
            continue;
        }
        if (!evaluation_attendance_counts_as_checked_out($row)) {
            continue;
        }

        $registration = isset($row['registration']) && is_array($row['registration']) ? $row['registration'] : [];
        if ((string) ($registration['student_id'] ?? '') !== $studentId) {
            continue;
        }

        $sid = trim((string) ($row['session_id'] ?? ''));
        if ($sid !== '') {
            $present[$sid] = true;
        }
    }

    return array_values(array_keys($present));
}

function evaluation_normalize_answer_text(mixed $value): string
{
    if ($value === null) {
        return '';
    }

    if (is_bool($value)) {
        return $value ? '1' : '0';
    }

    if (is_scalar($value)) {
        return trim((string) $value);
    }

    return '';
}

function evaluation_group_submissions(array $rawAnswers, string $legacySessionId): array
{
    $sections = [];

    if (array_is_list($rawAnswers)) {
        foreach ($rawAnswers as $item) {
            if (!is_array($item)) {
                continue;
            }

            $questionId = trim((string) ($item['question_id'] ?? ''));
            if ($questionId === '') {
                continue;
            }

            $sessionId = trim((string) ($item['session_id'] ?? ''));
            $scopeKey = $sessionId !== '' ? 'session:' . $sessionId : 'event';
            if (!isset($sections[$scopeKey])) {
                $sections[$scopeKey] = [
                    'scope' => $sessionId !== '' ? 'session' : 'event',
                    'session_id' => $sessionId,
                    'answers' => [],
                ];
            }

            $sections[$scopeKey]['answers'][$questionId] = evaluation_normalize_answer_text($item['answer_text'] ?? '');
        }

        return $sections;
    }

    foreach ($rawAnswers as $questionId => $answerText) {
        $questionId = trim((string) $questionId);
        if ($questionId === '') {
            continue;
        }

        $scopeKey = $legacySessionId !== '' ? 'session:' . $legacySessionId : 'event';
        if (!isset($sections[$scopeKey])) {
            $sections[$scopeKey] = [
                'scope' => $legacySessionId !== '' ? 'session' : 'event',
                'session_id' => $legacySessionId,
                'answers' => [],
            ];
        }

        $sections[$scopeKey]['answers'][$questionId] = evaluation_normalize_answer_text($answerText);
    }

    return $sections;
}

$eventUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/events'
    . '?select=id,event_mode,event_structure,event_for,session_attendance_rule'
    . '&id=eq.' . rawurlencode($eventId)
    . '&limit=1';
$eventRes = supabase_request('GET', $eventUrl, $headers);
if (!$eventRes['ok']) {
    json_response([
        'ok' => false,
        'error' => build_error($eventRes['body'] ?? null, (int) ($eventRes['status'] ?? 0), $eventRes['error'] ?? null, 'Event lookup failed'),
    ], 500);
}

$eventRows = json_decode((string) $eventRes['body'], true);
$event = is_array($eventRows) && isset($eventRows[0]) ? $eventRows[0] : null;
if (!is_array($event)) {
    json_response(['ok' => false, 'error' => 'Event not found'], 404);
}

$studentId = (string) ($user['id'] ?? '');
$sessions = fetch_event_sessions($eventId, $headers);
$usesSessions = event_uses_sessions(array_merge($event, ['sessions' => $sessions]));
$studentProfile = fetch_student_profile_by_id($studentId, $headers);
$eligibleSessions = $usesSessions
    ? event_matching_sessions_for_student_registration(
        $event,
        $sessions,
        is_array($studentProfile) ? $studentProfile : null,
        $eventId,
        $studentId,
        $headers
    )
    : [];
$splitAttendance = $usesSessions && event_uses_split_session_attendance(array_merge($event, ['sessions' => $sessions]));
$submittedSections = evaluation_group_submissions($rawAnswers, $legacySessionId);

if (count($submittedSections) === 0) {
    json_response(['ok' => false, 'error' => 'No answers provided'], 400);
}

try {
    $eventEligible = false;
    $attendedSessionIds = [];

    if ($usesSessions && $eligibleSessions === []) {
        json_response([
            'ok' => false,
            'error' => 'This event has no seminar session assigned to your course/year level.',
        ], 403);
    }
    if ($splitAttendance && count($eligibleSessions) !== 1) {
        json_response([
            'ok' => false,
            'error' => 'Your event must assign you to exactly one seminar session. Contact the event organizer.',
        ], 409);
    }

    $simpleOut = function_exists('certificate_auto_student_has_checkout_simple')
        ? certificate_auto_student_has_checkout_simple($eventId, $studentId, $headers)
        : evaluation_has_simple_attendance($eventId, $studentId, $headers);

    if ($usesSessions) {
        $attendedSessionIds = evaluation_attended_session_ids($eligibleSessions, $studentId, $headers);
        $eventEligible = event_student_meets_session_attendance_rule(
            $event,
            $eligibleSessions,
            $attendedSessionIds
        );
    } else {
        $eventEligible = $simpleOut || evaluation_has_simple_attendance($eventId, $studentId, $headers);
    }

    if (!$eventEligible) {
        $msg = $usesSessions
            ? (normalize_session_attendance_rule($event['session_attendance_rule'] ?? 'all') === 'all'
                ? 'Time out of every seminar assigned to you before submitting the evaluation.'
                : 'Time out of at least one seminar assigned to you before submitting the evaluation.')
            : 'Only attendees who timed out can submit evaluation for this event.';
        json_response(['ok' => false, 'error' => $msg], 403);
    }

    $eventPayloads = [];
    $sessionPayloads = [];

    foreach ($submittedSections as $section) {
        $scope = (string) ($section['scope'] ?? 'event');
        $sectionAnswers = isset($section['answers']) && is_array($section['answers']) ? $section['answers'] : [];
        if (count($sectionAnswers) === 0) {
            continue;
        }

        if ($scope === 'session') {
            $sectionSessionId = trim((string) ($section['session_id'] ?? ''));
            if ($sectionSessionId === '') {
                json_response(['ok' => false, 'error' => 'Seminar scope is missing session_id.'], 400);
            }
            $eligibleSessionIds = array_map(
                static fn(array $row): string => trim((string) ($row['id'] ?? '')),
                $eligibleSessions
            );
            if (!in_array($sectionSessionId, $eligibleSessionIds, true)
                || !in_array($sectionSessionId, $attendedSessionIds, true)) {
                json_response(['ok' => false, 'error' => 'You can only answer seminar evaluation for seminars you attended.'], 403);
            }

            $questions = evaluation_fetch_session_questions($sectionSessionId, $headers);
            if (count($questions) === 0) {
                json_response(['ok' => false, 'error' => 'No evaluation questions are available for this seminar yet.'], 409);
            }

            foreach ($questions as $question) {
                if (empty($question['required'])) {
                    continue;
                }

                $questionId = (string) ($question['id'] ?? '');
                if ($questionId === '' || !isset($sectionAnswers[$questionId]) || trim((string) $sectionAnswers[$questionId]) === '') {
                    json_response(['ok' => false, 'error' => 'Please answer all required seminar questions.'], 400);
                }
            }

            foreach ($questions as $question) {
                $questionId = (string) ($question['id'] ?? '');
                if ($questionId === '') {
                    continue;
                }

                $answerText = isset($sectionAnswers[$questionId]) ? trim((string) $sectionAnswers[$questionId]) : '';
                if ($answerText === '') {
                    continue;
                }

                $sessionPayloads[] = [
                    'session_id' => $sectionSessionId,
                    'question_id' => $questionId,
                    'student_id' => $studentId,
                    'answer_text' => $answerText,
                    'submitted_at' => gmdate('c'),
                ];
            }

            continue;
        }

        $questions = evaluation_fetch_event_questions($eventId, $headers);
        if (count($questions) === 0) {
            json_response(['ok' => false, 'error' => 'No event-level evaluation questions are available yet.'], 409);
        }

        foreach ($questions as $question) {
            if (empty($question['required'])) {
                continue;
            }

            $questionId = (string) ($question['id'] ?? '');
            if ($questionId === '' || !isset($sectionAnswers[$questionId]) || trim((string) $sectionAnswers[$questionId]) === '') {
                json_response(['ok' => false, 'error' => 'Please answer all required event questions.'], 400);
            }
        }

        foreach ($questions as $question) {
            $questionId = (string) ($question['id'] ?? '');
            if ($questionId === '') {
                continue;
            }

            $answerText = isset($sectionAnswers[$questionId]) ? trim((string) $sectionAnswers[$questionId]) : '';
            if ($answerText === '') {
                continue;
            }

            $eventPayloads[] = [
                'event_id' => $eventId,
                'question_id' => $questionId,
                'student_id' => $studentId,
                'answer_text' => $answerText,
                'submitted_at' => gmdate('c'),
            ];
        }
    }

    if (count($eventPayloads) === 0 && count($sessionPayloads) === 0) {
        json_response(['ok' => false, 'error' => 'No answers provided'], 400);
    }

    $postHeaders = [
        'Content-Type: application/json',
        'Accept: application/json',
        'apikey: ' . SUPABASE_KEY,
        'Authorization: Bearer ' . SUPABASE_KEY,
    ];

    if (count($eventPayloads) > 0) {
        $eventPayloads = evaluation_dedupe_answer_rows($eventPayloads);
        $eventPostUrl = rtrim(SUPABASE_URL, '/')
            . '/rest/v1/evaluation_answers?on_conflict=question_id,student_id&select=id,question_id';
        $eventPostHeaders = $postHeaders;
        $eventPostHeaders[] = 'Prefer: return=representation,resolution=merge-duplicates';
        $eventPostRes = supabase_request('POST', $eventPostUrl, $eventPostHeaders, json_encode($eventPayloads, JSON_UNESCAPED_SLASHES));
        if (!$eventPostRes['ok']) {
            json_response([
                'ok' => false,
                'error' => build_error($eventPostRes['body'] ?? null, (int) ($eventPostRes['status'] ?? 0), $eventPostRes['error'] ?? null, 'Submit failed'),
            ], 500);
        }
    }

    if (count($sessionPayloads) > 0) {
        $sessionPayloads = evaluation_dedupe_answer_rows($sessionPayloads);
        $sessionPostUrl = rtrim(SUPABASE_URL, '/')
            . '/rest/v1/event_session_evaluation_answers?on_conflict=question_id,student_id&select=id,question_id';
        $sessionPostHeaders = $postHeaders;
        $sessionPostHeaders[] = 'Prefer: return=representation,resolution=merge-duplicates';
        $sessionPostRes = supabase_request('POST', $sessionPostUrl, $sessionPostHeaders, json_encode($sessionPayloads, JSON_UNESCAPED_SLASHES));
        if (!$sessionPostRes['ok']) {
            json_response([
                'ok' => false,
                'error' => build_error($sessionPostRes['body'] ?? null, (int) ($sessionPostRes['status'] ?? 0), $sessionPostRes['error'] ?? null, 'Submit failed'),
            ], 500);
        }
    }

    json_response([
        'ok' => true,
        'event_answers_saved' => count($eventPayloads),
        'session_answers_saved' => count($sessionPayloads),
        'certificate' => certificate_auto_issue_for_student($eventId, $studentId, $headers),
    ], 200);
} catch (RuntimeException $e) {
    json_response(['ok' => false, 'error' => $e->getMessage()], 500);
}
