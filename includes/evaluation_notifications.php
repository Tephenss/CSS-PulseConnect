<?php
declare(strict_types=1);

/**
 * Push + inbox notification when a student completes time-out and can answer
 * the event evaluation form.
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

        if (!evaluation_event_has_open_questions($eventId, $sessionId)) {
            return;
        }

        $title = trim($eventTitle);
        if ($title === '') {
            $title = 'this event';
        }

        $notifTitle = 'Evaluation Open';
        $notifBody = 'You timed out of "' . $title . '". You can now answer the evaluation form.';
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
