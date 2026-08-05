<?php
declare(strict_types=1);

/**
 * Push + inbox notification when a student completes time-out and can answer
 * the event evaluation form.
 *
 * Multi-seminar (2+ sessions): only after the FINAL seminar time-out
 * (latest session end_at — aligns with the event end date).
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

    // Session-level questions still count when sessionId is provided.
    if ($sessionId !== '') {
        $url = rtrim(SUPABASE_URL, '/') . '/rest/v1/event_session_evaluation_questions'
            . '?select=id'
            . '&session_id=eq.' . rawurlencode($sessionId)
            . '&limit=1';
        $res = supabase_request('GET', $url, $headers);
        if ($res['ok'] ?? false) {
            $rows = json_decode((string) ($res['body'] ?? ''), true);
            if (is_array($rows) && $rows !== []) {
                return true;
            }
        }
    }

    $eventUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/evaluation_questions'
        . '?select=id'
        . '&event_id=eq.' . rawurlencode($eventId)
        . '&limit=1';
    $eventRes = supabase_request('GET', $eventUrl, $headers);
    if (!($eventRes['ok'] ?? false)) {
        return false;
    }
    $eventRows = json_decode((string) ($eventRes['body'] ?? ''), true);
    return is_array($eventRows) && $eventRows !== [];
}

/**
 * Resolve the final seminar for an event (latest end_at).
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
    if (!is_array($sessions) || count($sessions) <= 1) {
        return null; // simple / single seminar — no multi-seminar gate
    }

    $finalId = '';
    $finalEnd = null;
    foreach ($sessions as $session) {
        if (!is_array($session)) {
            continue;
        }
        $sid = trim((string) ($session['id'] ?? ''));
        if ($sid === '') {
            continue;
        }
        $end = null;
        if (function_exists('parse_iso_datetime')) {
            $end = parse_iso_datetime((string) ($session['end_at'] ?? ''))
                ?? parse_iso_datetime((string) ($session['start_at'] ?? ''));
        } else {
            $raw = trim((string) ($session['end_at'] ?? $session['start_at'] ?? ''));
            if ($raw !== '') {
                try {
                    $end = new DateTimeImmutable($raw);
                } catch (Throwable $e) {
                    $end = null;
                }
            }
        }
        if ($end === null) {
            continue;
        }
        if ($finalEnd === null || $end > $finalEnd) {
            $finalEnd = $end;
            $finalId = $sid;
        }
    }

    if ($finalId === '') {
        return null;
    }

    return ['id' => $finalId, 'end_at' => $finalEnd];
}

/**
 * True when this time-out should open evaluation.
 * - Simple / 1 seminar: always true
 * - 2+ seminars: only when the timed-out session is the final seminar
 */
function evaluation_timeout_is_final_for_eval(
    string $eventId,
    ?string $sessionId
): bool {
    $final = evaluation_final_seminar_for_event($eventId);
    if ($final === null) {
        return true;
    }

    $sessionId = trim((string) $sessionId);
    if ($sessionId === '') {
        // Event-level checkout on a multi-seminar event should not open eval
        // early — wait for the final seminar session out.
        return false;
    }

    return $sessionId === (string) ($final['id'] ?? '');
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
        $studentId = trim($studentId);
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
