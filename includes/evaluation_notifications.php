<?php
declare(strict_types=1);

/**
 * Push + inbox notification when a student completes time-out and can answer
 * the event evaluation form.
 *
 * Multi-seminar (2+ sessions): only after the LAST seminar time-out
 * (Seminar 2 in program order). Seminar 1 time-out does not open the form.
 * Simple / 1-seminar: after that single time-out (unchanged).
 */

function evaluation_notify_headers(): array
{
    return [
        'Accept: application/json',
        'apikey: ' . SUPABASE_KEY,
        'Authorization: Bearer ' . SUPABASE_KEY,
    ];
}

function evaluation_event_has_open_questions(
    string $eventId,
    ?string $sessionId = null
): bool {
    $eventId = trim($eventId);
    $sessionId = trim((string) $sessionId);
    if ($eventId === '') {
        return false;
    }

    $headers = evaluation_notify_headers();

    // Event-level questions.
    $eventUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/evaluation_questions'
        . '?select=id'
        . '&event_id=eq.' . rawurlencode($eventId)
        . '&limit=1';
    $eventRes = supabase_request('GET', $eventUrl, $headers);
    if ($eventRes['ok'] ?? false) {
        $eventRows = json_decode((string) ($eventRes['body'] ?? ''), true);
        if (is_array($eventRows) && $eventRows !== []) {
            return true;
        }
    }

    // Multi-seminar: questions may live on seminar 1 while the final timeout is
    // seminar 2 — check any session of this event, not only the timed-out one.
    if (!function_exists('fetch_event_sessions')) {
        require_once __DIR__ . '/event_sessions.php';
    }
    $sessions = fetch_event_sessions($eventId, $headers);
    $sessionIds = [];
    if (is_array($sessions)) {
        foreach ($sessions as $session) {
            if (!is_array($session)) {
                continue;
            }
            $sid = trim((string) ($session['id'] ?? ''));
            if ($sid !== '') {
                $sessionIds[] = $sid;
            }
        }
    }
    if ($sessionId !== '' && !in_array($sessionId, $sessionIds, true)) {
        $sessionIds[] = $sessionId;
    }
    if ($sessionIds === []) {
        return false;
    }

    $inList = '(' . implode(',', array_map(
        static fn (string $id): string => rawurlencode($id),
        $sessionIds
    )) . ')';
    $sessUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/event_session_evaluation_questions'
        . '?select=id'
        . '&session_id=in.' . $inList
        . '&limit=1';
    $sessRes = supabase_request('GET', $sessUrl, $headers);
    if (!($sessRes['ok'] ?? false)) {
        return false;
    }
    $sessRows = json_decode((string) ($sessRes['body'] ?? ''), true);
    return is_array($sessRows) && $sessRows !== [];
}

/**
 * True when the event is configured as two seminars (even if session fetch is short).
 */
function evaluation_event_expects_two_seminars(string $eventId): bool
{
    $eventId = trim($eventId);
    if ($eventId === '') {
        return false;
    }

    $url = rtrim(SUPABASE_URL, '/') . '/rest/v1/events'
        . '?select=event_structure,event_mode,session_count'
        . '&id=eq.' . rawurlencode($eventId)
        . '&limit=1';
    $res = supabase_request('GET', $url, evaluation_notify_headers());
    if (!($res['ok'] ?? false)) {
        return false;
    }
    $rows = json_decode((string) ($res['body'] ?? ''), true);
    $event = is_array($rows) && isset($rows[0]) && is_array($rows[0]) ? $rows[0] : null;
    if (!is_array($event)) {
        return false;
    }

    $structure = strtolower(trim((string) ($event['event_structure'] ?? '')));
    if ($structure === 'two_seminars') {
        return true;
    }
    $count = (int) ($event['session_count'] ?? 0);
    return $count >= 2;
}

/**
 * Resolve the last seminar in program order (Seminar 2), not "latest end_at".
 *
 * @return array{id:string,end_at:?DateTimeImmutable}|null
 */
function evaluation_final_seminar_for_event(string $eventId): ?array
{
    $eventId = trim($eventId);
    if ($eventId === '') {
        return null;
    }

    if (!function_exists('fetch_event_sessions')) {
        require_once __DIR__ . '/event_sessions.php';
    }

    $sessions = fetch_event_sessions($eventId, evaluation_notify_headers());
    $valid = [];
    if (is_array($sessions)) {
        foreach ($sessions as $session) {
            if (!is_array($session)) {
                continue;
            }
            if (trim((string) ($session['id'] ?? '')) === '') {
                continue;
            }
            $valid[] = $session;
        }
    }
    if (count($valid) <= 1) {
        return null; // simple / single seminar — no multi-seminar gate
    }

    $last = function_exists('event_sessions_last') ? event_sessions_last($valid) : null;
    if (!is_array($last)) {
        return null;
    }
    $finalId = trim((string) ($last['id'] ?? ''));
    if ($finalId === '') {
        return null;
    }

    $finalEnd = event_session_parse_program_time($last['end_at'] ?? null)
        ?? event_session_parse_program_time($last['start_at'] ?? null);

    return ['id' => $finalId, 'end_at' => $finalEnd];
}

/**
 * True when this time-out should open evaluation.
 * - Simple / 1 seminar: always true
 * - 2+ seminars: only the last seminar time-out (Seminar 2), never Seminar 1
 */
function evaluation_timeout_is_final_for_eval(
    string $eventId,
    ?string $sessionId
): bool {
    if (!function_exists('fetch_event_sessions')) {
        require_once __DIR__ . '/event_sessions.php';
    }

    $sessions = fetch_event_sessions($eventId, evaluation_notify_headers());
    $valid = [];
    if (is_array($sessions)) {
        foreach ($sessions as $session) {
            if (!is_array($session)) {
                continue;
            }
            if (trim((string) ($session['id'] ?? '')) === '') {
                continue;
            }
            $valid[] = $session;
        }
    }

    if (count($valid) <= 1) {
        // Two-seminar event with a short/failed session list: do not open on Seminar 1.
        return !evaluation_event_expects_two_seminars($eventId);
    }

    $last = function_exists('event_sessions_last') ? event_sessions_last($valid) : null;
    $sessionId = trim((string) $sessionId);
    if (!is_array($last) || $sessionId === '') {
        return false;
    }

    return $sessionId === trim((string) ($last['id'] ?? ''));
}

/**
 * Resolve account UUID for FCM / inbox. Callers sometimes pass school student no.
 */
function evaluation_notify_resolve_user_id(string $studentId): string
{
    $studentId = trim($studentId);
    if ($studentId === '') {
        return '';
    }
    if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $studentId)) {
        return strtolower($studentId);
    }

    $headers = evaluation_notify_headers();
    $url = rtrim(SUPABASE_URL, '/') . '/rest/v1/users'
        . '?select=id'
        . '&student_id=eq.' . rawurlencode($studentId)
        . '&limit=1';
    $res = supabase_request('GET', $url, $headers);
    if (!($res['ok'] ?? false)) {
        return '';
    }
    $rows = json_decode((string) ($res['body'] ?? ''), true);
    if (!is_array($rows) || !isset($rows[0]) || !is_array($rows[0])) {
        return '';
    }
    $id = trim((string) ($rows[0]['id'] ?? ''));
    return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $id)
        ? strtolower($id)
        : '';
}

/**
 * Notify one student that evaluation is available after successful time-out.
 * Non-fatal — never throws to scan callers.
 */
function notify_student_evaluation_open_after_timeout(
    string $studentId,
    string $eventId,
    string $eventTitle = '',
    ?string $sessionId = null
): void {
    try {
        $studentId = evaluation_notify_resolve_user_id($studentId);
        $eventId = trim($eventId);
        if ($studentId === '' || $eventId === '') {
            return;
        }

        // Multi-seminar: wait for final out (last seminar / event end), not the first out.
        if (!evaluation_timeout_is_final_for_eval($eventId, $sessionId)) {
            return;
        }

        if (!evaluation_event_has_open_questions($eventId, $sessionId)) {
            return;
        }

        $title = trim($eventTitle);
        if ($title === '') {
            $title = 'this event';
        }

        $isMultiFinal = evaluation_final_seminar_for_event($eventId) !== null;
        $notifTitle = 'Evaluation Open';
        $notifBody = $isMultiFinal
            ? 'You completed your final time-out for "' . $title . '". You can now answer the evaluation form.'
            : 'You timed out of "' . $title . '". You can now answer the evaluation form.';
        $data = [
            'type' => 'eval_open',
            'event_id' => $eventId,
            'event_title' => $title === 'this event' ? '' : $title,
            'route' => 'evaluation',
        ];
        if (trim((string) $sessionId) !== '') {
            $data['session_id'] = trim((string) $sessionId);
        }

        require_once __DIR__ . '/user_notifications.php';
        require_once __DIR__ . '/fcm.php';

        persist_user_notifications([$studentId], $notifTitle, $notifBody, $data);

        $tokensRes = supabase_request(
            'GET',
            rtrim(SUPABASE_URL, '/') . '/rest/v1/fcm_tokens?select=token&user_id=eq.' . rawurlencode($studentId),
            evaluation_notify_headers()
        );
        if (!($tokensRes['ok'] ?? false)) {
            return;
        }
        $tokenRows = json_decode((string) ($tokensRes['body'] ?? ''), true);
        $tokens = [];
        if (is_array($tokenRows)) {
            foreach ($tokenRows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $token = trim((string) ($row['token'] ?? ''));
                if ($token !== '') {
                    $tokens[] = $token;
                }
            }
        }
        if ($tokens !== [] && function_exists('send_fcm_notification')) {
            send_fcm_notification($tokens, $notifTitle, $notifBody, $data);
        }
    } catch (Throwable $e) {
        error_log('notify_student_evaluation_open_after_timeout: ' . $e->getMessage());
    }
}
