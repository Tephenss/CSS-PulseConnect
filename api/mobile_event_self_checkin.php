<?php
declare(strict_types=1);

/**
 * Student Event QR: time-in / time-out (same PULSE-EVENT-{id} payload).
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/supabase.php';
require_once __DIR__ . '/../includes/json.php';
require_once __DIR__ . '/../includes/mobile_api.php';
require_once __DIR__ . '/../includes/mobile_session.php';
require_once __DIR__ . '/../includes/api_rate_limit.php';
require_once __DIR__ . '/../includes/event_sessions.php';
require_once __DIR__ . '/../includes/scan_context.php';
require_once __DIR__ . '/../includes/event_attendance_windows.php';
require_once __DIR__ . '/../includes/mobile_scan_write.php';
require_once __DIR__ . '/../includes/evaluation_notifications.php';

$data = mobile_api_require_post_json();
mobile_api_validate_key($data);
$sessionUser = mobile_api_require_user($data);
$userId = (string) ($sessionUser['id'] ?? '');
$role = strtolower(trim((string) ($sessionUser['role'] ?? 'student')));

if ($role !== 'student') {
    json_response(['ok' => false, 'error' => 'Only students can use event QR check-in.', 'status' => 'forbidden'], 403);
}

$clientIp = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
if (!api_rate_limit_allow('mobile_event_self_checkin:' . $userId . ':' . $clientIp, 60, 30)) {
    json_response(['ok' => false, 'error' => 'Too many check-in attempts. Please wait.', 'status' => 'error'], 429);
}

$payload = trim((string) ($data['event_qr_payload'] ?? $data['ticket_payload'] ?? ''));
$eventId = '';
if (preg_match('/^PULSE-EVENT-(.+)$/i', $payload, $m)) {
    $eventId = trim((string) ($m[1] ?? ''));
} elseif (preg_match('/^[a-f0-9-]{32,36}$/i', $payload)) {
    $eventId = $payload;
}

if ($eventId === '' || !preg_match('/^[a-f0-9-]{32,36}$/i', $eventId)) {
    json_response(['ok' => false, 'error' => 'Invalid event QR code.', 'status' => 'invalid'], 400);
}

$headers = mobile_api_supabase_headers();
$reprHeaders = [
    'Content-Type: application/json',
    'Accept: application/json',
    'apikey: ' . SUPABASE_KEY,
    'Authorization: Bearer ' . SUPABASE_KEY,
    'Prefer: return=representation',
];

// Do not select uses_sessions — column is not present on all DBs; event_uses_sessions()
// derives seminar mode from event_mode / event_structure.
$eventSelectCandidates = [
    'id,title,status,start_at,end_at,location,event_mode,event_structure,grace_time,early_out_enabled_at',
    'id,title,status,start_at,end_at,location,event_mode,event_structure,grace_time',
    'id,title,status,start_at,end_at,location,event_mode,grace_time',
    'id,title,status,start_at,end_at,grace_time',
];
$event = null;
$lastEventRes = null;
foreach ($eventSelectCandidates as $select) {
    $eventUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/events?select=' . rawurlencode($select)
        . '&id=eq.' . rawurlencode($eventId) . '&limit=1';
    $lastEventRes = supabase_request('GET', $eventUrl, $headers);
    if (!($lastEventRes['ok'] ?? false)) {
        continue;
    }
    $eventRows = json_decode((string) ($lastEventRes['body'] ?? ''), true);
    if (is_array($eventRows) && isset($eventRows[0]) && is_array($eventRows[0])) {
        $event = $eventRows[0];
        break;
    }
    // Query succeeded but empty → real 404 (do not keep trying other selects).
    break;
}
if (!is_array($event)) {
    $httpStatus = (int) ($lastEventRes['status'] ?? 0);
    if ($httpStatus >= 400 && $httpStatus !== 404) {
        json_response([
            'ok' => false,
            'error' => 'Could not load event. Please try again.',
            'status' => 'error',
        ], 503);
    }
    json_response(['ok' => false, 'error' => 'Event not found.', 'status' => 'invalid'], 404);
}

$status = strtolower((string) ($event['status'] ?? ''));
if (!in_array($status, ['published', 'approved', 'finished', 'expired'], true)) {
    json_response(['ok' => false, 'error' => 'Attendance is not available for this event.', 'status' => 'error'], 409);
}

$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
$scanAtIso = $now->format(DATE_ATOM);
$nowIso = $scanAtIso;

attendance_lazy_clear_early_out('events', $eventId, $event['early_out_enabled_at'] ?? null, $now, $headers);
if (!attendance_early_out_is_active((string) ($event['early_out_enabled_at'] ?? ''), $now)) {
    $event['early_out_enabled_at'] = null;
}

$regUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/event_registrations'
    . '?select=id,student_id,tickets(id,token)'
    . '&event_id=eq.' . rawurlencode($eventId)
    . '&student_id=eq.' . rawurlencode($userId)
    . '&limit=1';
$regRes = supabase_request('GET', $regUrl, $headers);
$regRows = json_decode((string) ($regRes['body'] ?? ''), true);
$registration = is_array($regRows) && isset($regRows[0]) ? $regRows[0] : null;
if (!is_array($registration) || empty($registration['id'])) {
    json_response(['ok' => false, 'error' => 'You are not registered for this event.', 'status' => 'forbidden'], 403);
}

$registrationId = (string) $registration['id'];
$ticketsRaw = $registration['tickets'] ?? null;
$ticket = null;
if (is_array($ticketsRaw)) {
    if (isset($ticketsRaw[0]) && is_array($ticketsRaw[0])) {
        $ticket = $ticketsRaw[0];
    } elseif (isset($ticketsRaw['id'])) {
        $ticket = $ticketsRaw;
    }
}
if (!is_array($ticket) || empty($ticket['id'])) {
    json_response(['ok' => false, 'error' => 'Ticket not found for your registration.', 'status' => 'invalid'], 404);
}

$ticketId = (string) $ticket['id'];

$selfCheckinWrite = static function (
    string $method,
    string $url,
    array|string $body,
    string $scanKind = 'self_check_in',
    string $subjectExtra = '',
) use ($eventId, $userId, $reprHeaders): array {
    $bodyStr = is_string($body)
        ? $body
        : json_encode($body, JSON_UNESCAPED_SLASHES);
    $kind = in_array($scanKind, ['self_check_in', 'self_check_out'], true)
        ? $scanKind
        : 'self_check_in';
    $subjectKey = $eventId . ':' . $userId . ($subjectExtra !== '' ? (':' . $subjectExtra) : '') . ':' . $kind;
    return mobile_attendance_write_guarded(
        $eventId,
        $kind,
        $subjectKey,
        [
            'type' => 'mobile_' . $kind,
            'method' => strtoupper($method),
            'url' => $url,
            'headers' => $reprHeaders,
            'body' => $bodyStr,
            'meta' => [
                'event_id' => $eventId,
                'scan_kind' => $kind,
            ],
        ],
    );
};

$userUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/users'
    . '?select=id,first_name,middle_name,last_name,suffix,photo_url'
    . '&id=eq.' . rawurlencode($userId) . '&limit=1';
$userRes = supabase_request('GET', $userUrl, $headers);
$userRows = json_decode((string) ($userRes['body'] ?? ''), true);
$userRow = is_array($userRows) && isset($userRows[0]) ? $userRows[0] : null;
$participantName = '';
$participantPhoto = '';
if (is_array($userRow)) {
    $parts = array_filter([
        trim((string) ($userRow['first_name'] ?? '')),
        trim((string) ($userRow['middle_name'] ?? '')),
        trim((string) ($userRow['last_name'] ?? '')),
        trim((string) ($userRow['suffix'] ?? '')),
    ], static fn($p) => $p !== '');
    $participantName = implode(' ', $parts);
    $participantPhoto = trim((string) ($userRow['photo_url'] ?? ''));
}

$respond = static function (
    bool $ok,
    string $responseStatus,
    string $message,
    int $http = 200,
    array $extra = []
) use ($ticketId, $participantName, $participantPhoto, $userId, $eventId, $event): void {
    $eventTitle = trim((string) ($event['title'] ?? ''));
    $eventStart = trim((string) ($event['start_at'] ?? ''));
    $eventEnd = trim((string) ($event['end_at'] ?? ''));
    json_response(array_merge([
        'ok' => $ok,
        'ticket_id' => $ticketId,
        'event_id' => $eventId,
        'event_title' => $eventTitle,
        'event_start_at' => $eventStart,
        'event_end_at' => $eventEnd,
        'status' => $responseStatus,
        'participant_name' => $participantName,
        'participant_photo_url' => $participantPhoto,
        'participant_student_id' => $userId,
        'message' => $message,
        'error' => $ok ? null : $message,
    ], $extra), $http);
};

$isPresent = static function (array $row): bool {
    $statusStr = strtolower(trim((string) ($row['status'] ?? '')));
    if (in_array($statusStr, ['present', 'checked_in', 'in', 'scanned', 'late', 'early'], true)) {
        return true;
    }
    return trim((string) ($row['check_in_at'] ?? '')) !== '';
};

$usesSessions = event_uses_sessions($event);
$sessions = $usesSessions ? fetch_event_sessions($eventId, $headers) : [];

// Enrich sessions with early_out_enabled_at when column exists.
if ($usesSessions && !empty($sessions)) {
    $eoUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/event_sessions'
        . '?select=id,early_out_enabled_at&event_id=eq.' . rawurlencode($eventId);
    $eoRes = supabase_request('GET', $eoUrl, $headers);
    if ($eoRes['ok']) {
        $eoRows = json_decode((string) ($eoRes['body'] ?? ''), true);
        $eoMap = [];
        if (is_array($eoRows)) {
            foreach ($eoRows as $row) {
                if (!is_array($row) || empty($row['id'])) {
                    continue;
                }
                $sid = (string) $row['id'];
                attendance_lazy_clear_early_out('event_sessions', $sid, $row['early_out_enabled_at'] ?? null, $now, $headers);
                $eoMap[$sid] = attendance_early_out_is_active((string) ($row['early_out_enabled_at'] ?? ''), $now)
                    ? (string) $row['early_out_enabled_at']
                    : null;
            }
        }
        foreach ($sessions as &$sessionRow) {
            if (!is_array($sessionRow)) {
                continue;
            }
            $sid = (string) ($sessionRow['id'] ?? '');
            $sessionRow['early_out_enabled_at'] = $eoMap[$sid] ?? null;
        }
        unset($sessionRow);
    }
}

if ($usesSessions) {
    // Load all session attendance for this ticket.
    $attUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/event_session_attendance'
        . '?select=id,session_id,status,check_in_at,check_out_at'
        . '&ticket_id=eq.' . rawurlencode($ticketId);
    $attRes = supabase_request('GET', $attUrl, $headers);
    $attRows = json_decode((string) ($attRes['body'] ?? ''), true);
    $bySession = [];
    if (is_array($attRows)) {
        foreach ($attRows as $row) {
            if (!is_array($row) || empty($row['session_id'])) {
                continue;
            }
            $bySession[(string) $row['session_id']] = $row;
        }
    }

    // Prefer check-out for sessions where student is in and out-window is open.
    $outCandidates = [];
    foreach ($sessions as $session) {
        if (!is_array($session)) {
            continue;
        }
        $sid = (string) ($session['id'] ?? '');
        if ($sid === '') {
            continue;
        }
        $existing = $bySession[$sid] ?? null;
        if (!is_array($existing) || !$isPresent($existing)) {
            continue;
        }
        if (trim((string) ($existing['check_out_at'] ?? '')) !== '') {
            continue;
        }
        $endAt = parse_iso_datetime((string) ($session['end_at'] ?? ''));
        $outWin = attendance_check_out_window($endAt, $session['early_out_enabled_at'] ?? null, $now);
        if (($outWin['open'] ?? false) === true) {
            $outCandidates[] = [
                'session' => $session,
                'attendance' => $existing,
                'window' => $outWin,
            ];
        }
    }

    if (count($outCandidates) > 1) {
        $respond(false, 'conflict', 'Multiple seminars are open for time-out. Contact admin.', 409);
    }

    if (count($outCandidates) === 1) {
        $pick = $outCandidates[0];
        $session = $pick['session'];
        $existing = $pick['attendance'];
        $sessionId = (string) ($session['id'] ?? '');
        $sessionName = trim((string) (build_session_display_name($session) ?: ($session['title'] ?? 'Seminar')));
        if ($sessionName === '') {
            $sessionName = 'Seminar';
        }
        $attId = (string) ($existing['id'] ?? '');
        $patchUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/event_session_attendance?id=eq.' . rawurlencode($attId);
        $writeOutcome = $selfCheckinWrite(
            'PATCH',
            $patchUrl,
            [
                'check_out_at' => $scanAtIso,
                'last_scanned_by' => $userId,
                'last_scanned_at' => $scanAtIso,
                'updated_at' => $nowIso,
            ],
            'self_check_out',
            $sessionId,
        );
        if (($writeOutcome['ok'] ?? false) !== true) {
            $fail = mobile_attendance_require_write($writeOutcome, 'Time-out failed. Please try again.');
            json_response($fail, ($writeOutcome['status'] ?? '') === 'throttled' ? 429 : 500);
        }
        if (empty($writeOutcome['queued'])) {
            notify_student_evaluation_open_after_timeout(
                $userId,
                $eventId,
                (string) ($event['title'] ?? ''),
                $sessionId
            );
        }
        $respond(true, 'checked_out', 'Checked out for ' . $sessionName . '.' . mobile_attendance_queued_suffix($writeOutcome), 200, [
            'session_id' => $sessionId,
            'action' => 'check_out',
        ]);
    }

    // Try time-in for open session.
    $inResolve = attendance_resolve_session_check_in($sessions, $now);
    $inStatus = (string) ($inResolve['status'] ?? 'closed');
    $session = is_array($inResolve['session'] ?? null) ? $inResolve['session'] : null;
    $sessionId = is_array($session) ? (string) ($session['id'] ?? '') : '';
    $sessionName = is_array($session)
        ? trim((string) (build_session_display_name($session) ?: ($session['title'] ?? 'Seminar')))
        : 'Seminar';
    if ($sessionName === '') {
        $sessionName = 'Seminar';
    }

    if ($sessionId !== '' && isset($bySession[$sessionId])) {
        $existing = $bySession[$sessionId];
        if ($isPresent($existing) && trim((string) ($existing['check_out_at'] ?? '')) !== '') {
            $respond(true, 'already_checked_out', 'You already timed out for ' . $sessionName . '.', 200, [
                'session_id' => $sessionId,
                'action' => 'check_out',
            ]);
        }
        if ($isPresent($existing)) {
            // Checked in but out window not open.
            $endAt = parse_iso_datetime((string) ($session['end_at'] ?? ''));
            $outWin = attendance_check_out_window($endAt, $session['early_out_enabled_at'] ?? null, $now);
            $respond(false, (string) ($outWin['status'] ?? 'too_early_checkout'), (string) ($outWin['message'] ?? 'Time-out is not open yet.'), 409, [
                'session_id' => $sessionId,
                'action' => 'check_out',
            ]);
        }
    }

    if ($inStatus !== 'open' || $sessionId === '') {
        // Maybe they need out on another session but window not open.
        foreach ($bySession as $sid => $existing) {
            if (!$isPresent($existing) || trim((string) ($existing['check_out_at'] ?? '')) !== '') {
                continue;
            }
            $sess = null;
            foreach ($sessions as $s) {
                if (is_array($s) && (string) ($s['id'] ?? '') === $sid) {
                    $sess = $s;
                    break;
                }
            }
            if (!is_array($sess)) {
                continue;
            }
            $endAt = parse_iso_datetime((string) ($sess['end_at'] ?? ''));
            $outWin = attendance_check_out_window($endAt, $sess['early_out_enabled_at'] ?? null, $now);
            $respond(false, (string) ($outWin['status'] ?? 'too_early_checkout'), (string) ($outWin['message'] ?? 'Time-out is not open yet.'), 409, [
                'session_id' => $sid,
                'action' => 'check_out',
            ]);
        }

        $msg = match ($inStatus) {
            'waiting' => 'Time-in has not opened yet for this event.',
            'conflict' => 'Schedule conflict detected. Contact admin.',
            'missing_schedule' => 'No valid schedule found.',
            default => 'Time-in window is closed for this event.',
        };
        $waitingSession = is_array($inResolve['session'] ?? null) ? $inResolve['session'] : null;
        $waitingWindow = is_array($inResolve['window'] ?? null) ? $inResolve['window'] : null;
        $respond(false, $inStatus !== '' ? $inStatus : 'closed', $msg, 409, [
            'action' => 'check_in',
            'session_name' => is_array($waitingSession)
                ? trim((string) ($waitingSession['title'] ?? $waitingSession['topic'] ?? ''))
                : '',
            'session_start_at' => is_array($waitingSession)
                ? trim((string) ($waitingSession['start_at'] ?? ''))
                : '',
            'session_end_at' => is_array($waitingSession)
                ? trim((string) ($waitingSession['end_at'] ?? ''))
                : '',
            'opens_at' => is_array($waitingWindow)
                ? ($waitingWindow['opens_at'] ?? null)
                : null,
        ]);
    }

    $existing = $bySession[$sessionId] ?? null;
    if (is_array($existing) && !empty($existing['id'])) {
        $patchUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/event_session_attendance?id=eq.' . rawurlencode((string) $existing['id']);
        $writeOutcome = $selfCheckinWrite(
            'PATCH',
            $patchUrl,
            [
                'status' => 'present',
                'check_in_at' => $scanAtIso,
                'last_scanned_by' => $userId,
                'last_scanned_at' => $scanAtIso,
                'updated_at' => $nowIso,
            ],
            'self_check_in',
            $sessionId,
        );
        if (($writeOutcome['ok'] ?? false) !== true) {
            $fail = mobile_attendance_require_write($writeOutcome, 'Time-in failed. Please try again.');
            json_response($fail, ($writeOutcome['status'] ?? '') === 'throttled' ? 429 : 500);
        }
        $respond(true, 'present', 'Timed in for ' . $sessionName . '.' . mobile_attendance_queued_suffix($writeOutcome), 200, [
            'session_id' => $sessionId,
            'action' => 'check_in',
        ]);
    }

    $writeOutcome = $selfCheckinWrite(
        'POST',
        rtrim(SUPABASE_URL, '/') . '/rest/v1/event_session_attendance',
        [[
            'session_id' => $sessionId,
            'registration_id' => $registrationId,
            'ticket_id' => $ticketId,
            'status' => 'present',
            'check_in_at' => $scanAtIso,
            'last_scanned_by' => $userId,
            'last_scanned_at' => $scanAtIso,
            'updated_at' => $nowIso,
        ]],
        'self_check_in',
        $sessionId,
    );
    if (($writeOutcome['ok'] ?? false) !== true) {
        $fail = mobile_attendance_require_write($writeOutcome, 'Time-in failed. Please try again.');
        json_response($fail, ($writeOutcome['status'] ?? '') === 'throttled' ? 429 : 500);
    }
    $respond(true, 'present', 'Timed in for ' . $sessionName . '.' . mobile_attendance_queued_suffix($writeOutcome), 200, [
        'session_id' => $sessionId,
        'action' => 'check_in',
    ]);
}

// Simple event path.
$attUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/attendance'
    . '?select=id,status,check_in_at,check_out_at'
    . '&ticket_id=eq.' . rawurlencode($ticketId)
    . '&limit=1';
$attRes = supabase_request('GET', $attUrl, $headers);
$attRows = json_decode((string) ($attRes['body'] ?? ''), true);
$att = is_array($attRows) && isset($attRows[0]) ? $attRows[0] : null;
if (!is_array($att) || empty($att['id'])) {
    json_response(['ok' => false, 'error' => 'Attendance record is missing for your ticket.', 'status' => 'invalid'], 404);
}

$attId = (string) $att['id'];
$alreadyIn = $isPresent($att);
$alreadyOut = trim((string) ($att['check_out_at'] ?? '')) !== '';

if ($alreadyOut) {
    $respond(true, 'already_checked_out', 'You already timed out for this event.', 200, ['action' => 'check_out']);
}

if ($alreadyIn) {
    $endAt = parse_iso_datetime((string) ($event['end_at'] ?? ''));
    $outWin = attendance_check_out_window($endAt, $event['early_out_enabled_at'] ?? null, $now);
    if (($outWin['open'] ?? false) !== true) {
        $respond(false, (string) ($outWin['status'] ?? 'too_early_checkout'), (string) ($outWin['message'] ?? 'Time-out is not open yet.'), 409, ['action' => 'check_out']);
    }
    $patchUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/attendance?id=eq.' . rawurlencode($attId);
    $writeOutcome = $selfCheckinWrite(
        'PATCH',
        $patchUrl,
        [
            'check_out_at' => $scanAtIso,
            'last_scanned_by' => $userId,
            'last_scanned_at' => $scanAtIso,
            'updated_at' => $nowIso,
        ],
        'self_check_out',
    );
    if (($writeOutcome['ok'] ?? false) !== true) {
        $fail = mobile_attendance_require_write($writeOutcome, 'Time-out failed. Please try again.');
        json_response($fail, ($writeOutcome['status'] ?? '') === 'throttled' ? 429 : 500);
    }
    if (empty($writeOutcome['queued'])) {
        notify_student_evaluation_open_after_timeout(
            $userId,
            $eventId,
            (string) ($event['title'] ?? ''),
            null
        );
    }
    $respond(true, 'checked_out', 'Checked out successfully!' . mobile_attendance_queued_suffix($writeOutcome), 200, ['action' => 'check_out']);
}

$startAt = parse_iso_datetime((string) ($event['start_at'] ?? ''));
$inWin = attendance_check_in_window_for_start($startAt, simple_event_grace_minutes($event), $now);
if (($inWin['open'] ?? false) !== true) {
    $respond(false, (string) ($inWin['status'] ?? 'closed'), (string) ($inWin['message'] ?? 'Time-in is not open.'), 409, [
        'action' => 'check_in',
        'opens_at' => $inWin['opens_at'] ?? null,
        'closes_at' => $inWin['closes_at'] ?? null,
    ]);
}

$patchUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/attendance?id=eq.' . rawurlencode($attId);
$writeOutcome = $selfCheckinWrite(
    'PATCH',
    $patchUrl,
    [
        'status' => 'present',
        'check_in_at' => $scanAtIso,
        'last_scanned_by' => $userId,
        'last_scanned_at' => $scanAtIso,
        'updated_at' => $nowIso,
    ],
    'self_check_in',
);
if (($writeOutcome['ok'] ?? false) !== true) {
    $fail = mobile_attendance_require_write($writeOutcome, 'Time-in failed. Please try again.');
    json_response($fail, ($writeOutcome['status'] ?? '') === 'throttled' ? 429 : 500);
}

$respond(true, 'present', 'Timed in successfully!' . mobile_attendance_queued_suffix($writeOutcome), 200, ['action' => 'check_in']);
