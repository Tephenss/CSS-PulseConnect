<?php
declare(strict_types=1);

/**
 * Mobile QR check-in (teacher + student assistant).
 * Service-role writes — Flutter must not write attendance via anon.
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
require_once __DIR__ . '/../includes/mobile_scan_write.php';
require_once __DIR__ . '/../includes/evaluation_notifications.php';

// Optional: time-out / early-out helpers (deploy includes/event_attendance_windows.php with this API).
$attendanceWindowsPath = __DIR__ . '/../includes/event_attendance_windows.php';
if (is_file($attendanceWindowsPath)) {
    require_once $attendanceWindowsPath;
}

$data = mobile_api_require_post_json();
mobile_api_validate_key($data);
$sessionUser = mobile_api_require_user($data);
$userId = (string) ($sessionUser['id'] ?? '');
$role = strtolower(trim((string) ($sessionUser['role'] ?? 'student')));

$clientIp = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
if (!api_rate_limit_allow('mobile_scan_ticket:' . $userId . ':' . $clientIp, 60, 30)) {
    json_response(['ok' => false, 'error' => 'Too many scan attempts. Please wait.', 'status' => 'error'], 429);
}

$ticketPayload = trim((string) ($data['ticket_payload'] ?? $data['token'] ?? ''));
$dryRun = !empty($data['dry_run']);
$scannedAtRaw = trim((string) ($data['scanned_at'] ?? $data['scanned_at_iso'] ?? ''));
$expectedEventId = trim((string) ($data['expected_event_id'] ?? $data['active_event_id'] ?? ''));

if ($ticketPayload === '') {
    json_response(['ok' => false, 'error' => 'Invalid QR Code Format', 'status' => 'invalid'], 400);
}

// Student event QR is not a ticket — reject early with a clear message.
if (preg_match('/^PULSE-EVENT-/i', $ticketPayload)) {
    json_response([
        'ok' => false,
        'error' => 'Scan a student ticket QR, not the event QR.',
        'status' => 'invalid',
    ], 400);
}

$ticketId = '';
$token = '';
if (stripos($ticketPayload, 'PULSE-') === 0) {
    $ticketId = trim(substr($ticketPayload, 6));
} elseif (preg_match('/^[a-f0-9-]{32,36}$/i', $ticketPayload)) {
    $ticketId = $ticketPayload;
} elseif (preg_match('/^[a-f0-9]{32}$/i', $ticketPayload)) {
    $token = strtolower($ticketPayload);
} else {
    json_response(['ok' => false, 'error' => 'Invalid QR Code Format', 'status' => 'invalid'], 400);
}

if ($ticketId === '' && $token === '') {
    json_response(['ok' => false, 'error' => 'Invalid QR Code Format', 'status' => 'invalid'], 400);
}

$headers = mobile_api_supabase_headers();
$reprHeaders = [
    'Content-Type: application/json',
    'Accept: application/json',
    'apikey: ' . SUPABASE_KEY,
    'Authorization: Bearer ' . SUPABASE_KEY,
    'Prefer: return=representation',
];

function mobile_scan_parse_iso(?string $iso): ?DateTimeImmutable
{
    $iso = trim((string) $iso);
    if ($iso === '') {
        return null;
    }
    try {
        return new DateTimeImmutable($iso);
    } catch (Throwable $e) {
        return null;
    }
}

function mobile_scan_earlier_iso(string $incoming, ?string $recorded): string
{
    $in = mobile_scan_parse_iso($incoming);
    $rec = mobile_scan_parse_iso($recorded);
    if ($in === null) {
        return $recorded !== null && $recorded !== '' ? $recorded : gmdate('c');
    }
    if ($rec === null) {
        return $in->setTimezone(new DateTimeZone('UTC'))->format(DATE_ATOM);
    }
    return ($in <= $rec ? $in : $rec)->setTimezone(new DateTimeZone('UTC'))->format(DATE_ATOM);
}

function mobile_scan_is_present(array $row): bool
{
    if (function_exists('attendance_has_valid_time_in')) {
        return attendance_has_valid_time_in($row);
    }
    $status = strtolower(trim((string) ($row['status'] ?? '')));
    if ($status === 'absent') {
        return false;
    }
    if (in_array($status, ['present', 'checked_in', 'in', 'scanned', 'late', 'early'], true)) {
        return true;
    }
    return trim((string) ($row['check_in_at'] ?? '')) !== '';
}

function mobile_scan_can_scan_event(string $userId, string $role, string $eventId, array $headers): bool
{
    if ($userId === '' || $eventId === '') {
        return false;
    }
    if ($role === 'admin') {
        return true;
    }

    $eventUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/events'
        . '?select=id,created_by&id=eq.' . rawurlencode($eventId) . '&limit=1';
    $eventRes = supabase_request('GET', $eventUrl, $headers);
    $eventRows = json_decode((string) ($eventRes['body'] ?? ''), true);
    $event = is_array($eventRows) && isset($eventRows[0]) ? $eventRows[0] : null;
    if (is_array($event) && (string) ($event['created_by'] ?? '') === $userId) {
        return true;
    }

    if (in_array($role, ['teacher', 'admin'], true) && teacher_can_scan_event($userId, $eventId, $headers)) {
        return true;
    }

    // Student assistants (column is student_id, not user_id).
    $assistUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/event_assistants'
        . '?select=id&event_id=eq.' . rawurlencode($eventId)
        . '&student_id=eq.' . rawurlencode($userId)
        . '&allow_scan=eq.true&limit=1';
    $assistRes = supabase_request('GET', $assistUrl, $headers);
    $assistRows = json_decode((string) ($assistRes['body'] ?? ''), true);
    return is_array($assistRows) && count($assistRows) > 0;
}

function mobile_scan_format_participant_name(array $user): string
{
    $last = trim((string) ($user['last_name'] ?? ''));
    $first = trim((string) ($user['first_name'] ?? ''));
    $middle = trim((string) ($user['middle_name'] ?? ''));
    $suffix = trim((string) ($user['suffix'] ?? ''));
    $given = trim(implode(' ', array_values(array_filter(
        [$first, $middle],
        static fn($p) => $p !== ''
    ))));
    if ($last === '' && $given === '') {
        return $suffix;
    }
    if ($last === '') {
        return $suffix !== '' ? ($given . ' ' . $suffix) : $given;
    }
    $name = $given !== '' ? ($last . ', ' . $given) : $last;
    if ($suffix !== '') {
        $name .= ' ' . $suffix;
    }
    return $name;
}

function mobile_scan_load_ticket(string $ticketId, string $token, array $headers): ?array
{
    $select = 'id,token,registration_id,event_registrations(event_id,student_id,users:student_id(id,student_id,first_name,middle_name,last_name,suffix,photo_url))';
    if ($ticketId !== '') {
        $url = rtrim(SUPABASE_URL, '/') . '/rest/v1/tickets'
            . '?select=' . rawurlencode($select)
            . '&id=eq.' . rawurlencode($ticketId) . '&limit=1';
    } else {
        $url = rtrim(SUPABASE_URL, '/') . '/rest/v1/tickets'
            . '?select=' . rawurlencode($select)
            . '&token=eq.' . rawurlencode($token) . '&limit=1';
    }
    $res = supabase_request('GET', $url, $headers);
    if (!($res['ok'] ?? false)) {
        // Fallback without nested users join.
        $select = 'id,token,registration_id,event_registrations(event_id,student_id)';
        if ($ticketId !== '') {
            $url = rtrim(SUPABASE_URL, '/') . '/rest/v1/tickets'
                . '?select=' . rawurlencode($select)
                . '&id=eq.' . rawurlencode($ticketId) . '&limit=1';
        } else {
            $url = rtrim(SUPABASE_URL, '/') . '/rest/v1/tickets'
                . '?select=' . rawurlencode($select)
                . '&token=eq.' . rawurlencode($token) . '&limit=1';
        }
        $res = supabase_request('GET', $url, $headers);
    }
    $rows = json_decode((string) ($res['body'] ?? ''), true);
    return is_array($rows) && isset($rows[0]) && is_array($rows[0]) ? $rows[0] : null;
}

function mobile_scan_participant_from_ticket(array $ticket, array $headers): array
{
    $name = '';
    $photo = '';
    $userUuid = '';
    $studentNo = '';
    $reg = $ticket['event_registrations'] ?? null;
    if (is_array($reg)) {
        $userUuid = trim((string) ($reg['student_id'] ?? ''));
        $user = $reg['users'] ?? null;
        if (is_array($user)) {
            $name = mobile_scan_format_participant_name($user);
            $photo = trim((string) ($user['photo_url'] ?? ''));
            $studentNo = trim((string) ($user['student_id'] ?? ''));
            if ($userUuid === '') {
                $userUuid = trim((string) ($user['id'] ?? ''));
            }
        }
    }
    if (($name === '' || $photo === '' || $studentNo === '') && $userUuid !== '') {
        $uUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/users'
            . '?select=id,student_id,first_name,middle_name,last_name,suffix,photo_url'
            . '&id=eq.' . rawurlencode($userUuid) . '&limit=1';
        $uRes = supabase_request('GET', $uUrl, $headers);
        $uRows = json_decode((string) ($uRes['body'] ?? ''), true);
        $user = is_array($uRows) && isset($uRows[0]) ? $uRows[0] : null;
        if (is_array($user)) {
            if ($name === '') {
                $name = mobile_scan_format_participant_name($user);
            }
            if ($photo === '') {
                $photo = trim((string) ($user['photo_url'] ?? ''));
            }
            if ($studentNo === '') {
                $studentNo = trim((string) ($user['student_id'] ?? ''));
            }
        }
    }
    return [
        'participant_name' => $name,
        'participant_photo_url' => $photo,
        // School student number for UI; keep UUID only as fallback when no. missing.
        'participant_student_id' => $studentNo !== '' ? $studentNo : $userUuid,
        'participant_student_no' => $studentNo,
        'participant_user_id' => $userUuid,
    ];
}

function mobile_scan_window_message(string $scanStatus, ?array $scanContext = null): string
{
    $fromContext = trim((string) ($scanContext['message'] ?? ''));
    if ($fromContext !== '') {
        return $fromContext;
    }
    return match ($scanStatus) {
        'waiting' => 'Too early to time in. Wait for the scheduled start time.',
        'closed' => 'The time-in grace period has ended for this schedule.',
        'too_early_checkout' => 'Too early to time out. Wait for the scheduled end time, or ask the organizer to enable Early Out.',
        'missing_schedule' => 'No valid schedule found for scanning.',
        'conflict' => 'Schedule conflict detected. Contact admin.',
        'error' => 'Unable to resolve the scan window for this ticket.',
        default => 'Scanning is unavailable for this ticket right now.',
    };
}

function mobile_scan_event_title(string $eventId, array $headers, string $fallback = 'another event'): string
{
    $eventId = trim($eventId);
    if ($eventId === '') {
        return $fallback;
    }
    $url = rtrim(SUPABASE_URL, '/') . '/rest/v1/events'
        . '?select=title&id=eq.' . rawurlencode($eventId) . '&limit=1';
    $res = supabase_request('GET', $url, $headers);
    $rows = json_decode((string) ($res['body'] ?? ''), true);
    $title = is_array($rows) && isset($rows[0]) ? trim((string) ($rows[0]['title'] ?? '')) : '';
    return $title !== '' ? $title : $fallback;
}

/**
 * @param array<string,mixed>|string $body
 * @return array{ok:bool,queued?:bool,body?:mixed,error?:string,status?:string}
 */
function mobile_scan_attendance_write(
    string $eventId,
    string $ticketId,
    string $method,
    string $url,
    array $headers,
    array|string $body,
): array {
    $bodyStr = is_string($body)
        ? $body
        : json_encode($body, JSON_UNESCAPED_SLASHES);
    return mobile_attendance_write_guarded(
        $eventId,
        'ticket_scan',
        $ticketId,
        [
            'type' => 'mobile_ticket_scan',
            'method' => strtoupper($method),
            'url' => $url,
            'headers' => $headers,
            'body' => $bodyStr,
            'meta' => [
                'event_id' => $eventId,
                'ticket_id' => $ticketId,
            ],
        ],
    );
}

try {
$ticket = mobile_scan_load_ticket($ticketId, $token, $headers);
if (!is_array($ticket) || empty($ticket['id'])) {
    json_response([
        'ok' => false,
        'error' => 'Ticket is not recognized for this scanner. If this student was removed or re-registered, open their new ticket QR.',
        'status' => 'invalid',
    ], 404);
}

$ticketId = (string) $ticket['id'];
$registrationId = (string) ($ticket['registration_id'] ?? '');
$eventId = '';
$reg = $ticket['event_registrations'] ?? null;
// PostgREST may return the embed as object or single-element list.
if (is_array($reg) && isset($reg[0]) && is_array($reg[0]) && !isset($reg['event_id'])) {
    $reg = $reg[0];
    $ticket['event_registrations'] = $reg;
}
if (is_array($reg)) {
    $eventId = (string) ($reg['event_id'] ?? '');
}
if ($registrationId === '' || $eventId === '') {
    json_response(['ok' => false, 'error' => 'Unable to resolve event for this ticket.', 'status' => 'invalid'], 409);
}

// Teacher/assistant scanner is pinned to an active event — reject tickets from other events clearly.
if ($expectedEventId !== '' && strcasecmp($expectedEventId, $eventId) !== 0) {
    $activeTitle = mobile_scan_event_title($expectedEventId, $headers, 'your active event');
    $ticketTitle = mobile_scan_event_title($eventId, $headers, 'a different event');
    json_response([
        'ok' => false,
        'error' => 'Wrong event ticket. This QR is for "' . $ticketTitle . '", but your scanner is open for "' . $activeTitle . '".',
        'status' => 'wrong_event',
        'ticket_event_id' => $eventId,
        'ticket_event_title' => $ticketTitle,
        'expected_event_id' => $expectedEventId,
        'expected_event_title' => $activeTitle,
    ], 409);
}

if (!mobile_scan_can_scan_event($userId, $role, $eventId, $headers)) {
    $ticketTitle = mobile_scan_event_title($eventId, $headers, 'this event');
    json_response([
        'ok' => false,
        'error' => 'You are not assigned to scan "' . $ticketTitle . '". Open the correct event scanner assignment.',
        'status' => 'forbidden',
        'ticket_event_id' => $eventId,
        'ticket_event_title' => $ticketTitle,
    ], 403);
}

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
    break;
}
if (!is_array($event)) {
    $httpStatus = (int) ($lastEventRes['status'] ?? 0);
    if ($httpStatus >= 400 && $httpStatus !== 404) {
        json_response([
            'ok' => false,
            'error' => 'Could not load event for this ticket. Please try again.',
            'status' => 'error',
        ], 503);
    }
    json_response(['ok' => false, 'error' => 'Event lookup failed', 'status' => 'error'], 500);
}

$status = strtolower((string) ($event['status'] ?? 'draft'));
if (!in_array($status, ['published', 'approved'], true)) {
    json_response(['ok' => false, 'error' => 'Scanning is only allowed for published events', 'status' => 'error'], 409);
}

$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
$scanAt = mobile_scan_parse_iso($scannedAtRaw) ?? $now;
$scanAtIso = $scanAt->setTimezone(new DateTimeZone('UTC'))->format(DATE_ATOM);
$nowIso = $now->format(DATE_ATOM);

if (function_exists('attendance_lazy_clear_early_out')) {
    try {
        attendance_lazy_clear_early_out('events', $eventId, $event['early_out_enabled_at'] ?? null, $now, $headers);
    } catch (Throwable $e) {
        error_log('attendance_lazy_clear_early_out: ' . $e->getMessage());
    }
}
if (function_exists('attendance_early_out_is_active')) {
    try {
        if (!attendance_early_out_is_active((string) ($event['early_out_enabled_at'] ?? ''), $now)) {
            $event['early_out_enabled_at'] = null;
        }
    } catch (Throwable $e) {
        $event['early_out_enabled_at'] = null;
    }
}

try {
    $scanContext = resolve_event_scan_context($event, $scanAt, $headers);
} catch (Throwable $e) {
    error_log('resolve_event_scan_context: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    // Prefer a usable window status over a generic hard-fail when helpers recover.
    if (function_exists('resolve_simple_event_scan_context') && !event_uses_sessions($event)) {
        try {
            $scanContext = resolve_simple_event_scan_context($event, $scanAt);
        } catch (Throwable $e2) {
            error_log('resolve_simple_event_scan_context fallback: ' . $e2->getMessage());
            $scanContext = [
                'status' => 'error',
                'source' => 'event',
                'session' => null,
                'message' => 'Unable to resolve scan window.',
            ];
        }
    } else {
        $scanContext = [
            'status' => 'error',
            'source' => 'event',
            'session' => null,
            'message' => 'Unable to resolve scan window.',
        ];
    }
}
$scanStatus = (string) ($scanContext['status'] ?? 'closed');
$scanMode = strtolower(trim((string) ($scanContext['scan_mode'] ?? 'check_in')));
$timeInOpen = $scanStatus === 'open' && $scanMode !== 'check_out';
$source = (string) ($scanContext['source'] ?? 'event');
$sessionContext = is_array($scanContext['session'] ?? null) ? $scanContext['session'] : [];
$sessionId = (string) ($sessionContext['id'] ?? '');
$sessionName = trim((string) ($sessionContext['display_name'] ?? $sessionContext['title'] ?? 'Seminar'));
if ($sessionName === '') {
    $sessionName = 'Seminar';
}

$participant = mobile_scan_participant_from_ticket($ticket, $headers);

$success = static function (
    string $responseStatus,
    string $message,
    ?string $checkInAt = null,
    ?string $checkOutAt = null,
) use ($ticketId, $participant, $dryRun): array {
    $payload = [
        'ok' => true,
        'ticket_id' => $ticketId,
        'status' => $responseStatus,
        'participant_name' => $participant['participant_name'],
        'participant_photo_url' => $participant['participant_photo_url'],
        'participant_student_id' => $participant['participant_student_id'],
        'participant_student_no' => $participant['participant_student_no'] ?? '',
        'message' => $message,
        'dry_run' => $dryRun,
    ];
    $checkInAt = trim((string) $checkInAt);
    $checkOutAt = trim((string) $checkOutAt);
    if ($checkInAt !== '') {
        $payload['check_in_at'] = $checkInAt;
    }
    if ($checkOutAt !== '') {
        $payload['check_out_at'] = $checkOutAt;
    }
    return $payload;
};

$already = static function (string $message, ?string $checkInAt = null) use ($ticketId, $participant): array {
    $payload = [
        'ok' => false,
        'error' => $message,
        'status' => 'already_checked_in',
        'ticket_id' => $ticketId,
        'participant_name' => $participant['participant_name'],
        'participant_photo_url' => $participant['participant_photo_url'],
        'participant_student_id' => $participant['participant_student_id'],
        'participant_student_no' => $participant['participant_student_no'] ?? '',
    ];
    $checkInAt = trim((string) $checkInAt);
    if ($checkInAt !== '') {
        $payload['check_in_at'] = $checkInAt;
    }
    return $payload;
};

if ($source === 'session') {
    if ($sessionId === '') {
        json_response(['ok' => false, 'error' => 'Active seminar context is missing.', 'status' => 'error'], 500);
    }

    $attUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/event_session_attendance'
        . '?select=id,status,check_in_at,check_out_at'
        . '&session_id=eq.' . rawurlencode($sessionId)
        . '&ticket_id=eq.' . rawurlencode($ticketId)
        . '&limit=1';
    $attRes = supabase_request('GET', $attUrl, $headers);
    if (!($attRes['ok'] ?? false)) {
        // Older DBs may lack check_out_at on seminar attendance.
        $attUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/event_session_attendance'
            . '?select=id,status,check_in_at'
            . '&session_id=eq.' . rawurlencode($sessionId)
            . '&ticket_id=eq.' . rawurlencode($ticketId)
            . '&limit=1';
        $attRes = supabase_request('GET', $attUrl, $headers);
    }
    $attRows = json_decode((string) ($attRes['body'] ?? ''), true);
    $existing = is_array($attRows) && isset($attRows[0]) ? $attRows[0] : null;

    $sessionOutWin = null;
    $sessionOutOpen = false;
    if (function_exists('attendance_check_out_window')) {
        $sessionEndAt = function_exists('parse_iso_datetime')
            ? parse_iso_datetime((string) ($sessionContext['end_at'] ?? ''))
            : mobile_scan_parse_iso((string) ($sessionContext['end_at'] ?? ''));
        $sessionEarlyOut = isset($sessionContext['early_out_enabled_at'])
            ? (string) $sessionContext['early_out_enabled_at']
            : null;
        try {
            $sessionOutWin = attendance_check_out_window($sessionEndAt, $sessionEarlyOut, $scanAt);
            $sessionOutOpen = ($sessionOutWin['open'] ?? false) === true;
        } catch (Throwable $e) {
            error_log('session attendance_check_out_window: ' . $e->getMessage());
        }
    }

    $alreadySessionOut = is_array($existing) && trim((string) ($existing['check_out_at'] ?? '')) !== '';
    $alreadySessionIn = is_array($existing) && !empty($existing['id']) && mobile_scan_is_present($existing);

    // Seminar time-out (Early Out or scheduled end + 1 hour).
    if ($scanMode === 'check_out' || ($alreadySessionIn && $sessionOutOpen && !$timeInOpen)) {
        if ($alreadySessionOut) {
            json_response([
                'ok' => false,
                'error' => 'Already timed out for ' . $sessionName . '.',
                'status' => 'already_checked_out',
                'ticket_id' => $ticketId,
                'participant_name' => $participant['participant_name'],
                'participant_photo_url' => $participant['participant_photo_url'],
                'participant_student_id' => $participant['participant_student_id'],
                'participant_student_no' => $participant['participant_student_no'] ?? '',
                'check_in_at' => (string) ($existing['check_in_at'] ?? ''),
                'check_out_at' => (string) ($existing['check_out_at'] ?? ''),
                'action' => 'check_out',
                'session_id' => $sessionId,
            ], 409);
        }
        if (!$alreadySessionIn) {
            json_response([
                'ok' => false,
                'error' => 'Cannot time out — this student has no time-in (marked absent) for ' . $sessionName . '.',
                'status' => 'absent_no_time_in',
                'ticket_id' => $ticketId,
                'participant_name' => $participant['participant_name'],
                'participant_photo_url' => $participant['participant_photo_url'],
                'participant_student_id' => $participant['participant_student_id'],
                'participant_student_no' => $participant['participant_student_no'] ?? '',
                'action' => 'check_out',
                'session_id' => $sessionId,
            ], 409);
        }
        if (!$sessionOutOpen) {
            $statusMessage = is_array($sessionOutWin)
                ? trim((string) ($sessionOutWin['message'] ?? ''))
                : '';
            if ($statusMessage === '') {
                $statusMessage = mobile_scan_window_message($scanStatus, $scanContext);
            }
            json_response([
                'ok' => false,
                'error' => $statusMessage,
                'status' => is_array($sessionOutWin)
                    ? (string) ($sessionOutWin['status'] ?? 'too_early_checkout')
                    : ($scanStatus !== '' ? $scanStatus : 'closed'),
                'action' => 'check_out',
                'session_id' => $sessionId,
            ], 409);
        }
        if ($dryRun) {
            json_response($success('ready_for_confirmation', 'Review participant, then confirm time-out for ' . $sessionName . '.'));
        }
        $patchUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/event_session_attendance?id=eq.' . rawurlencode((string) $existing['id']);
        $writeOutcome = mobile_scan_attendance_write(
            $eventId,
            $ticketId,
            'PATCH',
            $patchUrl,
            $reprHeaders,
            [
                'check_out_at' => $scanAtIso,
                'last_scanned_by' => $userId,
                'last_scanned_at' => $scanAtIso,
                'updated_at' => $nowIso,
            ],
        );
        if (($writeOutcome['ok'] ?? false) !== true) {
            $fail = mobile_attendance_require_write($writeOutcome, 'Time-out failed. Please try again.');
            json_response($fail, ($writeOutcome['status'] ?? '') === 'throttled' ? 429 : 500);
        }
        // Notify even when the attendance write is queued — eligibility is
        // re-checked when the student opens the form. Skipping here caused
        // missing eval pushes after offline/sync timeouts.
        notify_student_evaluation_open_after_timeout(
            (string) ($participant['participant_user_id'] ?? ''),
            $eventId,
            (string) ($event['title'] ?? ''),
            $sessionId
        );
        $payload = $success(
            'checked_out',
            'Timed out for ' . $sessionName . '.' . mobile_attendance_queued_suffix($writeOutcome),
            (string) ($existing['check_in_at'] ?? ''),
            $scanAtIso,
        );
        $payload['action'] = 'check_out';
        $payload['session_id'] = $sessionId;
        json_response($payload);
    }

    if (!$timeInOpen) {
        $statusMessage = mobile_scan_window_message($scanStatus, $scanContext);
        json_response(['ok' => false, 'error' => $statusMessage, 'status' => $scanStatus !== '' ? $scanStatus : 'error'], 409);
    }

    if ($alreadySessionIn) {
        $earlier = mobile_scan_earlier_iso($scanAtIso, (string) ($existing['check_in_at'] ?? ''));
        $recorded = (string) ($existing['check_in_at'] ?? '');
        if (!$dryRun && $earlier !== '' && $recorded !== '' && $earlier !== $recorded) {
            $patchUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/event_session_attendance?id=eq.' . rawurlencode((string) $existing['id']);
            mobile_scan_attendance_write(
                $eventId,
                $ticketId,
                'PATCH',
                $patchUrl,
                $reprHeaders,
                [
                    'status' => 'present',
                    'check_in_at' => $earlier,
                    'updated_at' => $nowIso,
                ],
            );
            json_response($success('present', 'Already checked in.', $earlier !== '' ? $earlier : $recorded));
        }
        json_response($already('This ticket is already recorded for the active seminar.', $recorded), 409);
    }

    if ($dryRun) {
        json_response($success('ready_for_confirmation', 'Review participant, then confirm check-in for ' . $sessionName . '.'));
    }

    if (is_array($existing) && !empty($existing['id'])) {
        $patchUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/event_session_attendance?id=eq.' . rawurlencode((string) $existing['id']);
        $writeOutcome = mobile_scan_attendance_write(
            $eventId,
            $ticketId,
            'PATCH',
            $patchUrl,
            $reprHeaders,
            [
                'status' => 'present',
                'check_in_at' => $scanAtIso,
                'last_scanned_by' => $userId,
                'last_scanned_at' => $scanAtIso,
                'updated_at' => $nowIso,
            ],
        );
        if (($writeOutcome['ok'] ?? false) !== true) {
            $fail = mobile_attendance_require_write($writeOutcome, 'Seminar scan update failed.');
            json_response($fail, 500);
        }
        json_response($success(
            'present',
            'Checked in for ' . $sessionName . '.' . mobile_attendance_queued_suffix($writeOutcome),
            $scanAtIso,
        ));
    }

    $writeOutcome = mobile_scan_attendance_write(
        $eventId,
        $ticketId,
        'POST',
        rtrim(SUPABASE_URL, '/') . '/rest/v1/event_session_attendance',
        $reprHeaders,
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
    );
    if (($writeOutcome['ok'] ?? false) !== true) {
        // Unique race → treat as already checked in / sync earliest.
        $attRes2 = supabase_request('GET', $attUrl, $headers);
        $attRows2 = json_decode((string) ($attRes2['body'] ?? ''), true);
        $existing2 = is_array($attRows2) && isset($attRows2[0]) ? $attRows2[0] : null;
        if (is_array($existing2) && !empty($existing2['id'])) {
            $earlier = mobile_scan_earlier_iso($scanAtIso, (string) ($existing2['check_in_at'] ?? ''));
            $patchUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/event_session_attendance?id=eq.' . rawurlencode((string) $existing2['id']);
            mobile_scan_attendance_write(
                $eventId,
                $ticketId,
                'PATCH',
                $patchUrl,
                $reprHeaders,
                [
                    'status' => 'present',
                    'check_in_at' => $earlier,
                    'updated_at' => $nowIso,
                ],
            );
            json_response($success(
                'present',
                'Checked in for ' . $sessionName . '.',
                $earlier !== '' ? $earlier : (string) ($existing2['check_in_at'] ?? $scanAtIso),
            ));
        }
        $fail = mobile_attendance_require_write($writeOutcome, 'Seminar scan update failed.');
        json_response($fail, ($writeOutcome['status'] ?? '') === 'throttled' ? 429 : 500);
    }
    json_response($success(
        'present',
        'Checked in for ' . $sessionName . '.' . mobile_attendance_queued_suffix($writeOutcome),
        $scanAtIso,
    ));
}

// Simple event attendance
$attUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/attendance'
    . '?select=id,status,check_in_at,check_out_at,last_scanned_at'
    . '&ticket_id=eq.' . rawurlencode($ticketId)
    . '&limit=1';
$attRes = supabase_request('GET', $attUrl, $headers);
if (!($attRes['ok'] ?? false)) {
    $attUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/attendance'
        . '?select=id,status,check_in_at,last_scanned_at'
        . '&ticket_id=eq.' . rawurlencode($ticketId)
        . '&limit=1';
    $attRes = supabase_request('GET', $attUrl, $headers);
}
$attRows = json_decode((string) ($attRes['body'] ?? ''), true);
$att = is_array($attRows) && isset($attRows[0]) ? $attRows[0] : null;

// Recreate missing attendance so reset/delete + rescan still works.
if (!is_array($att) || empty($att['id'])) {
    $createPayload = [[
        'ticket_id' => $ticketId,
        'status' => 'unscanned',
        'check_in_at' => null,
        'check_out_at' => null,
        'last_scanned_at' => null,
        'last_scanned_by' => null,
    ]];
    $createUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/attendance?select=id,status,check_in_at,check_out_at,last_scanned_at';
    $createRes = supabase_request('POST', $createUrl, $reprHeaders, json_encode($createPayload, JSON_UNESCAPED_SLASHES));
    if (!($createRes['ok'] ?? false)) {
        $createPayload = [[
            'ticket_id' => $ticketId,
            'status' => 'unscanned',
            'check_in_at' => null,
            'last_scanned_at' => null,
            'last_scanned_by' => null,
        ]];
        $createUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/attendance?select=id,status,check_in_at,last_scanned_at';
        $createRes = supabase_request('POST', $createUrl, $reprHeaders, json_encode($createPayload, JSON_UNESCAPED_SLASHES));
    }
    $createdRows = json_decode((string) ($createRes['body'] ?? ''), true);
    $att = is_array($createdRows) && isset($createdRows[0]) ? $createdRows[0] : null;
}
if (!is_array($att) || empty($att['id'])) {
    json_response(['ok' => false, 'error' => 'Attendance record is missing for this ticket.', 'status' => 'invalid'], 404);
}

$isPresent = mobile_scan_is_present($att);
$alreadyOut = trim((string) ($att['check_out_at'] ?? '')) !== '';

// Stale timeout after attendance reset (check_in cleared but check_out left behind).
if ($alreadyOut && !$isPresent) {
    $clearUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/attendance?id=eq.' . rawurlencode((string) $att['id']);
    mobile_scan_attendance_write(
        $eventId,
        $ticketId,
        'PATCH',
        $clearUrl,
        $reprHeaders,
        [
            'check_out_at' => null,
            'status' => 'unscanned',
            'updated_at' => $nowIso,
        ],
    );
    $att['check_out_at'] = null;
    $alreadyOut = false;
}

$outWinOpen = false;
$outWin = null;
if (function_exists('attendance_check_out_window')) {
    try {
        $endAt = function_exists('parse_iso_datetime')
            ? parse_iso_datetime((string) ($event['end_at'] ?? ''))
            : mobile_scan_parse_iso((string) ($event['end_at'] ?? ''));
        $outWin = attendance_check_out_window($endAt, isset($event['early_out_enabled_at']) ? (string) $event['early_out_enabled_at'] : null, $scanAt);
        $outWinOpen = ($outWin['open'] ?? false) === true;
    } catch (Throwable $e) {
        error_log('attendance_check_out_window: ' . $e->getMessage());
        $outWinOpen = false;
        $outWin = null;
    }
}

if ($alreadyOut) {
    json_response([
        'ok' => false,
        'error' => $outWinOpen
            ? 'Already timed out. On Participants, use Reset Time-Out first, then scan again.'
            : 'Ticket already timed out.',
        'status' => 'already_checked_out',
        'ticket_id' => $ticketId,
        'participant_name' => $participant['participant_name'],
        'participant_photo_url' => $participant['participant_photo_url'],
        'participant_student_id' => $participant['participant_student_id'],
        'participant_student_no' => $participant['participant_student_no'] ?? '',
        'check_in_at' => (string) ($att['check_in_at'] ?? ''),
        'check_out_at' => (string) ($att['check_out_at'] ?? ''),
        'action' => 'check_out',
    ], 409);
}

$wantsCheckOut = ($outWinOpen && !$timeInOpen)
    || ($scanStatus === 'open' && $scanMode === 'check_out');
if ($wantsCheckOut && !$isPresent) {
    json_response([
        'ok' => false,
        'error' => 'Cannot time out — this student has no time-in (marked absent).',
        'status' => 'absent_no_time_in',
        'ticket_id' => $ticketId,
        'participant_name' => $participant['participant_name'],
        'participant_photo_url' => $participant['participant_photo_url'],
        'participant_student_id' => $participant['participant_student_id'],
        'participant_student_no' => $participant['participant_student_no'] ?? '',
        'action' => 'check_out',
    ], 409);
}

    if ($isPresent) {
    if ($outWinOpen) {
        if ($dryRun) {
            json_response($success('ready_for_confirmation', 'Review participant, then confirm time-out.'));
        }
        $patchUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/attendance?id=eq.' . rawurlencode((string) $att['id']);
        $writeOutcome = mobile_scan_attendance_write(
            $eventId,
            $ticketId,
            'PATCH',
            $patchUrl,
            $reprHeaders,
            [
                'check_out_at' => $scanAtIso,
                'last_scanned_by' => $userId,
                'last_scanned_at' => $scanAtIso,
            ],
        );
        if (($writeOutcome['ok'] ?? false) !== true) {
            $fail = mobile_attendance_require_write($writeOutcome, 'Time-out failed. Please try again.');
            json_response($fail, ($writeOutcome['status'] ?? '') === 'throttled' ? 429 : 500);
        }
        notify_student_evaluation_open_after_timeout(
            (string) ($participant['participant_user_id'] ?? ''),
            $eventId,
            (string) ($event['title'] ?? ''),
            null
        );
        $payload = $success(
            'checked_out',
            'Timed out successfully!' . mobile_attendance_queued_suffix($writeOutcome),
            (string) ($att['check_in_at'] ?? ''),
            $scanAtIso,
        );
        $payload['action'] = 'check_out';
        json_response($payload);
    }

    // Already timed in, but time-out window is not open yet (or already ended).
    if (isset($outWin) && is_array($outWin)) {
        $outStatus = (string) ($outWin['status'] ?? 'too_early_checkout');
        $outMessage = trim((string) ($outWin['message'] ?? ''));
        if ($outMessage === '') {
            $outMessage = $outStatus === 'too_early_checkout'
                ? 'Too early to time out. Early Out is not enabled — wait for the scheduled end time.'
                : 'Time-out is not available for this ticket right now.';
        }
        json_response([
            'ok' => false,
            'error' => $outMessage,
            'status' => $outStatus !== '' ? $outStatus : 'too_early_checkout',
            'ticket_id' => $ticketId,
            'participant_name' => $participant['participant_name'],
            'participant_photo_url' => $participant['participant_photo_url'],
            'participant_student_id' => $participant['participant_student_id'],
            'participant_student_no' => $participant['participant_student_no'] ?? '',
            'check_in_at' => (string) ($att['check_in_at'] ?? ''),
            'action' => 'check_out',
        ], 409);
    }

    $earlier = mobile_scan_earlier_iso($scanAtIso, (string) ($att['check_in_at'] ?? ''));
    $recorded = (string) ($att['check_in_at'] ?? '');
    if ($timeInOpen && !$dryRun && $earlier !== '' && $recorded !== '' && $earlier !== $recorded) {
        $patchUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/attendance?id=eq.' . rawurlencode((string) $att['id']);
        mobile_scan_attendance_write(
            $eventId,
            $ticketId,
            'PATCH',
            $patchUrl,
            $reprHeaders,
            [
                'status' => 'present',
                'check_in_at' => $earlier,
            ],
        );
        json_response($success('present', 'Already checked in.', $earlier));
    }
    json_response($already('Ticket already checked in.', $recorded), 409);
}

// Not present yet — check-in only while the time-in window is open.
if (!$timeInOpen) {
    $statusMessage = mobile_scan_window_message($scanStatus, $scanContext);
    if (isset($outWin) && is_array($outWin) && trim((string) ($outWin['message'] ?? '')) !== '') {
        // Prefer checkout-specific copy when grace already ended.
        $outStatus = (string) ($outWin['status'] ?? '');
        if (in_array($outStatus, ['too_early_checkout', 'closed'], true)) {
            $statusMessage = (string) $outWin['message'];
            $scanStatus = $outStatus !== '' ? $outStatus : $scanStatus;
        }
    }
    json_response(['ok' => false, 'error' => $statusMessage, 'status' => $scanStatus !== '' ? $scanStatus : 'error'], 409);
}

if ($dryRun) {
    json_response($success('ready_for_confirmation', 'Review participant, then confirm check-in.'));
}

$patchUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/attendance?id=eq.' . rawurlencode((string) $att['id']);
$writeOutcome = mobile_scan_attendance_write(
    $eventId,
    $ticketId,
    'PATCH',
    $patchUrl,
    $reprHeaders,
    [
        'status' => 'present',
        'check_in_at' => $scanAtIso,
        'check_out_at' => null,
        'last_scanned_by' => $userId,
        'last_scanned_at' => $scanAtIso,
    ],
);
if (($writeOutcome['ok'] ?? false) !== true) {
    $fail = mobile_attendance_require_write($writeOutcome, 'Check-in failed. Please try again.');
    json_response($fail, ($writeOutcome['status'] ?? '') === 'throttled' ? 429 : 500);
}

$checkInMessage = 'Check-in successful!';
$payload = $success(
    'present',
    $checkInMessage . mobile_attendance_queued_suffix($writeOutcome),
    $scanAtIso,
);
$payload['action'] = 'check_in';
json_response($payload);

} catch (Throwable $e) {
    error_log('mobile_scan_ticket exception: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    $detail = trim($e->getMessage());
    // Keep client message short and free of filesystem paths / secrets.
    $detail = preg_replace('/[A-Za-z]:\\\\[^\s]+/', '[path]', $detail) ?? $detail;
    $detail = preg_replace('/\/(?:var|home|usr)\/[^\s]+/', '[path]', $detail) ?? $detail;
    if (strlen($detail) > 160) {
        $detail = substr($detail, 0, 157) . '...';
    }
    json_response([
        'ok' => false,
        'error' => $detail !== ''
            ? ('Scan failed: ' . $detail)
            : 'Scan failed due to a server error. Please try again.',
        'status' => 'error',
        'error_class' => $e::class,
    ], 500);
}