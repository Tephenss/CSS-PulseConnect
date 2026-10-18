<?php
declare(strict_types=1);

require_once __DIR__ . '/registration_access.php';
require_once __DIR__ . '/student_requirements.php';
require_once __DIR__ . '/event_sessions.php';
require_once __DIR__ . '/event_session_targeting.php';

function event_registration_service_headers(): array
{
    return [
        'Content-Type: application/json',
        'Accept: application/json',
        'apikey: ' . SUPABASE_KEY,
        'Authorization: Bearer ' . SUPABASE_KEY,
        'Prefer: return=representation',
    ];
}

function event_registration_read_headers(): array
{
    return [
        'Accept: application/json',
        'apikey: ' . SUPABASE_KEY,
        'Authorization: Bearer ' . SUPABASE_KEY,
    ];
}

function event_registration_extract_ticket(mixed $tickets): ?array
{
    if (!is_array($tickets)) {
        return null;
    }

    if (isset($tickets[0]) && is_array($tickets[0])) {
        return $tickets[0];
    }

    if (isset($tickets['token'])) {
        return $tickets;
    }

    return null;
}

function fetch_existing_event_registration(string $eventId, string $studentId, array $headers): ?array
{
    $url = rtrim(SUPABASE_URL, '/') . '/rest/v1/event_registrations'
        . '?select=id,event_id,student_id,tickets(id,token,registration_id)'
        . '&event_id=eq.' . rawurlencode($eventId)
        . '&student_id=eq.' . rawurlencode($studentId)
        . '&limit=1';

    $res = supabase_request('GET', $url, event_registration_read_headers());
    if (!$res['ok']) {
        return null;
    }

    $rows = json_decode((string) $res['body'], true);
    if (!is_array($rows) || !isset($rows[0]) || !is_array($rows[0])) {
        return null;
    }

    return $rows[0];
}

function submit_student_event_registration(string $eventId, string $studentId, array $headers): array
{
    $eventId = trim($eventId);
    $studentId = trim($studentId);
    if ($eventId === '' || $studentId === '') {
        return ['ok' => false, 'error' => 'event_id and student_id are required.', 'status' => 400];
    }

    $event = fetch_event_with_registration_settings($eventId, $headers);
    if (!is_array($event)) {
        return ['ok' => false, 'error' => 'Event not found.', 'status' => 404];
    }

    $studentRow = fetch_student_profile_by_id($studentId, $headers);
    if (!is_array($studentRow)) {
        return ['ok' => false, 'error' => 'Student profile not found.', 'status' => 404];
    }

    $access = resolve_student_registration_access($event, $studentRow, $headers);
    if (!(bool) ($access['allowed'] ?? false)) {
        return [
            'ok' => false,
            'error' => (string) ($access['message'] ?? 'Registration is not allowed for this event.'),
            'status' => 403,
        ];
    }

    $sessions = fetch_event_sessions($eventId, event_registration_read_headers());
    $isSessionEvent = $sessions !== [] || event_uses_sessions($event);
    $eligibleSessions = $isSessionEvent
        ? event_matching_sessions_for_student($event, $sessions, $studentRow)
        : [];
    if ($isSessionEvent && $eligibleSessions === []) {
        return [
            'ok' => false,
            'error' => 'You are not assigned to any seminar session in this event.',
            'status' => 403,
        ];
    }
    $sessionCapacity = $isSessionEvent
        ? event_session_registration_capacity($eligibleSessions, event_registration_limit($event), event_registration_read_headers())
        : null;
    $registrationCount = is_array($sessionCapacity)
        ? (int) $sessionCapacity['registration_count']
        : fetch_event_registration_count($eventId, $headers);
    $isFull = is_array($sessionCapacity)
        ? (bool) $sessionCapacity['is_full']
        : event_registration_is_full($eventId, $event, $headers);

    $existing = fetch_existing_event_registration($eventId, $studentId, $headers);
    if (is_array($existing)) {
        return [
            'ok' => true,
            'already_registered' => true,
            'ticket' => event_registration_extract_ticket($existing['tickets'] ?? null),
            'eligible_sessions' => $eligibleSessions,
            'session_attendance_rule' => normalize_session_attendance_rule($event['session_attendance_rule'] ?? 'all'),
            'registration_count' => $registrationCount,
            'registration_limit' => event_registration_limit($event),
            'is_full' => $isFull,
            'session_scoped' => $isSessionEvent,
            'session_capacity' => $sessionCapacity,
        ];
    }

    if ($isFull) {
        return [
            'ok' => false,
            'error' => $isSessionEvent
                ? 'Registration is full for your assigned seminar.'
                : 'Registration is full for this event.',
            'status' => 403,
            'registration_count' => $registrationCount,
            'registration_limit' => event_registration_limit($event),
            'is_full' => true,
            'session_scoped' => $isSessionEvent,
            'session_capacity' => $sessionCapacity,
        ];
    }

    $writeHeaders = event_registration_service_headers();
    $regUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/event_registrations?select=id,event_id,student_id';
    $regRes = supabase_request(
        'POST',
        $regUrl,
        $writeHeaders,
        json_encode([
            [
                'event_id' => $eventId,
                'student_id' => $studentId,
            ],
        ], JSON_UNESCAPED_SLASHES)
    );

    if (!$regRes['ok']) {
        $errorBody = strtolower((string) ($regRes['body'] ?? ''));
        if (str_contains($errorBody, 'registration is full')) {
            $capacity = $isSessionEvent
                ? event_session_registration_capacity($eligibleSessions, event_registration_limit($event), event_registration_read_headers())
                : null;
            return [
                'ok' => false,
                'error' => $isSessionEvent
                    ? 'Registration is full for your assigned seminar.'
                    : 'Registration is full for this event.',
                'status' => 403,
                'registration_count' => is_array($capacity)
                    ? (int) $capacity['registration_count']
                    : fetch_event_registration_count($eventId, $headers),
                'registration_limit' => event_registration_limit($event),
                'is_full' => true,
                'session_scoped' => $isSessionEvent,
                'session_capacity' => $capacity,
            ];
        }

        return [
            'ok' => false,
            'error' => build_error($regRes['body'] ?? null, (int) ($regRes['status'] ?? 0), $regRes['error'] ?? null, 'Registration failed'),
            'status' => 500,
        ];
    }

    $regRows = json_decode((string) $regRes['body'], true);
    $reg = is_array($regRows) && isset($regRows[0]) ? $regRows[0] : null;
    if (!is_array($reg) || empty($reg['id'])) {
        return ['ok' => false, 'error' => 'Registration failed.', 'status' => 500];
    }

    $token = bin2hex(random_bytes(16));
    $ticketUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/tickets?select=id,token,registration_id';
    $ticketRes = supabase_request(
        'POST',
        $ticketUrl,
        $writeHeaders,
        json_encode([
            [
                'registration_id' => (string) ($reg['id'] ?? ''),
                'token' => $token,
            ],
        ], JSON_UNESCAPED_SLASHES)
    );

    if (!$ticketRes['ok']) {
        return [
            'ok' => false,
            'error' => build_error($ticketRes['body'] ?? null, (int) ($ticketRes['status'] ?? 0), $ticketRes['error'] ?? null, 'Ticket issue failed'),
            'status' => 500,
        ];
    }

    $ticketRows = json_decode((string) $ticketRes['body'], true);
    $ticket = is_array($ticketRows) && isset($ticketRows[0]) ? $ticketRows[0] : null;
    if (!is_array($ticket) || empty($ticket['id'])) {
        return ['ok' => false, 'error' => 'Ticket issue failed.', 'status' => 500];
    }

    $attUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/attendance?select=id,status';
    supabase_request(
        'POST',
        $attUrl,
        $writeHeaders,
        json_encode([
            [
                'ticket_id' => (string) ($ticket['id'] ?? ''),
                'status' => 'unscanned',
            ],
        ], JSON_UNESCAPED_SLASHES)
    );

    maybe_close_event_registration_at_capacity($eventId, $event, $headers);

    $sessionCapacity = $isSessionEvent
        ? event_session_registration_capacity($eligibleSessions, event_registration_limit($event), event_registration_read_headers())
        : null;
    $registrationCount = is_array($sessionCapacity)
        ? (int) $sessionCapacity['registration_count']
        : fetch_event_registration_count($eventId, $headers);
    $registrationLimit = event_registration_limit($event);

    return [
        'ok' => true,
        'ticket' => $ticket,
        'eligible_sessions' => $eligibleSessions,
        'session_attendance_rule' => normalize_session_attendance_rule($event['session_attendance_rule'] ?? 'all'),
        'registration_count' => $registrationCount,
        'registration_limit' => $registrationLimit,
        'is_full' => is_array($sessionCapacity)
            ? (bool) $sessionCapacity['is_full']
            : ($registrationLimit !== null && $registrationCount >= $registrationLimit),
        'session_scoped' => $isSessionEvent,
        'session_capacity' => $sessionCapacity,
    ];
}

/**
 * Staff (teacher creator / admin) records payment and directly registers the student.
 * Skips document-requirement gate so docs can be submitted after the slot is secured.
 */
function submit_staff_paid_event_registration(
    string $eventId,
    string $studentId,
    float $amountPaid,
    string $staffUserId,
    array $headers,
    string $paymentNote = ''
): array {
    $eventId = trim($eventId);
    $studentId = trim($studentId);
    $staffUserId = trim($staffUserId);

    if ($eventId === '' || $studentId === '') {
        return ['ok' => false, 'error' => 'event_id and student_id are required.', 'status' => 400];
    }
    if ($amountPaid < 0) {
        return ['ok' => false, 'error' => 'Amount paid cannot be negative.', 'status' => 400];
    }

    $event = fetch_event_with_registration_settings($eventId, $headers);
    if (!is_array($event)) {
        return ['ok' => false, 'error' => 'Event not found.', 'status' => 404];
    }

    if (strtolower(trim((string) ($event['status'] ?? ''))) !== 'published') {
        return ['ok' => false, 'error' => 'Event must be published first.', 'status' => 403];
    }

    if (event_is_free_registration_event($event)) {
        return ['ok' => false, 'error' => 'This tool is only for paid events.', 'status' => 400];
    }

    $studentRow = fetch_student_profile_by_id($studentId, $headers);
    if (!is_array($studentRow)) {
        return ['ok' => false, 'error' => 'Student profile not found.', 'status' => 404];
    }

    if (!student_matches_event_target($studentRow, (string) ($event['event_for'] ?? 'All'))) {
        return ['ok' => false, 'error' => 'Student is not in this event\'s target participants.', 'status' => 403];
    }

    $sessions = fetch_event_sessions($eventId, event_registration_read_headers());
    $isSessionEvent = $sessions !== [] || event_uses_sessions($event);
    $eligibleSessions = $isSessionEvent
        ? event_matching_sessions_for_student($event, $sessions, $studentRow)
        : [];
    if ($isSessionEvent && $eligibleSessions === []) {
        return ['ok' => false, 'error' => 'Student is not assigned to a seminar session in this event.', 'status' => 403];
    }
    $sessionCapacity = $isSessionEvent
        ? event_session_registration_capacity($eligibleSessions, event_registration_limit($event), event_registration_read_headers())
        : null;
    $registrationCount = is_array($sessionCapacity)
        ? (int) $sessionCapacity['registration_count']
        : fetch_event_registration_count($eventId, $headers);
    $isFull = is_array($sessionCapacity)
        ? (bool) $sessionCapacity['is_full']
        : event_registration_is_full($eventId, $event, $headers);

    if (is_event_registration_window_closed($event)) {
        return ['ok' => false, 'error' => 'Registration window is closed for this event.', 'status' => 403];
    }

    $existing = fetch_existing_event_registration($eventId, $studentId, $headers);
    $alreadyRegistered = is_array($existing);

    if (!$alreadyRegistered && $isFull) {
        return [
            'ok' => false,
            'error' => $isSessionEvent
                ? 'Registration is full for the student\'s assigned seminar.'
                : 'Registration is full for this event.',
            'status' => 403,
            'registration_count' => $registrationCount,
            'registration_limit' => event_registration_limit($event),
            'is_full' => true,
            'session_scoped' => $isSessionEvent,
            'session_capacity' => $sessionCapacity,
        ];
    }

    $now = gmdate('c');
    $accessPayload = [
        'event_id' => $eventId,
        'student_id' => $studentId,
        'payment_status' => 'paid',
        'approved' => true,
        'amount_paid' => round($amountPaid, 2),
        'payment_note' => $paymentNote !== '' ? mb_substr($paymentNote, 0, 240) : null,
        'imported_at' => $now,
        'imported_by' => $staffUserId !== '' ? $staffUserId : null,
        'updated_at' => $now,
    ];

    $accessRes = supabase_request(
        'POST',
        rtrim(SUPABASE_URL, '/') . '/rest/v1/event_registration_access?on_conflict=event_id,student_id',
        [
            'Content-Type: application/json',
            'Accept: application/json',
            'apikey: ' . SUPABASE_KEY,
            'Authorization: Bearer ' . SUPABASE_KEY,
            'Prefer: resolution=merge-duplicates,return=representation',
        ],
        json_encode($accessPayload, JSON_UNESCAPED_SLASHES)
    );

    if (!$accessRes['ok']) {
        $message = (string) ($accessRes['body'] ?? '') . ' ' . (string) ($accessRes['error'] ?? '');
        if (registration_access_missing_column_message($message, 'amount_paid')) {
            unset($accessPayload['amount_paid']);
            $accessRes = supabase_request(
                'POST',
                rtrim(SUPABASE_URL, '/') . '/rest/v1/event_registration_access?on_conflict=event_id,student_id',
                [
                    'Content-Type: application/json',
                    'Accept: application/json',
                    'apikey: ' . SUPABASE_KEY,
                    'Authorization: Bearer ' . SUPABASE_KEY,
                    'Prefer: resolution=merge-duplicates,return=representation',
                ],
                json_encode($accessPayload, JSON_UNESCAPED_SLASHES)
            );
        }
    }

    if (!$accessRes['ok']) {
        return [
            'ok' => false,
            'error' => build_error($accessRes['body'] ?? null, (int) ($accessRes['status'] ?? 0), $accessRes['error'] ?? null, 'Failed to save payment'),
            'status' => 500,
        ];
    }

    if ($alreadyRegistered) {
        return [
            'ok' => true,
            'already_registered' => true,
            'amount_paid' => round($amountPaid, 2),
            'ticket' => event_registration_extract_ticket($existing['tickets'] ?? null),
            'registration_count' => $registrationCount,
            'registration_limit' => event_registration_limit($event),
            'is_full' => $isFull,
            'session_scoped' => $isSessionEvent,
            'session_capacity' => $sessionCapacity,
            'message' => 'Payment updated. Student already has a secured slot.',
        ];
    }

    $writeHeaders = event_registration_service_headers();
    $regUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/event_registrations?select=id,event_id,student_id';
    $regRes = supabase_request(
        'POST',
        $regUrl,
        $writeHeaders,
        json_encode([['event_id' => $eventId, 'student_id' => $studentId]], JSON_UNESCAPED_SLASHES)
    );

    if (!$regRes['ok']) {
        $errorBody = strtolower((string) ($regRes['body'] ?? ''));
        if (str_contains($errorBody, 'registration is full')) {
            $capacity = $isSessionEvent
                ? event_session_registration_capacity($eligibleSessions, event_registration_limit($event), event_registration_read_headers())
                : null;
            return [
                'ok' => false,
                'error' => $isSessionEvent
                    ? 'Registration is full for the student\'s assigned seminar.'
                    : 'Registration is full for this event.',
                'status' => 403,
                'is_full' => true,
                'registration_count' => is_array($capacity)
                    ? (int) $capacity['registration_count']
                    : fetch_event_registration_count($eventId, $headers),
                'registration_limit' => event_registration_limit($event),
                'session_scoped' => $isSessionEvent,
                'session_capacity' => $capacity,
            ];
        }
        return [
            'ok' => false,
            'error' => build_error($regRes['body'] ?? null, (int) ($regRes['status'] ?? 0), $regRes['error'] ?? null, 'Registration failed'),
            'status' => 500,
        ];
    }

    $regRows = json_decode((string) $regRes['body'], true);
    $reg = is_array($regRows) && isset($regRows[0]) ? $regRows[0] : null;
    if (!is_array($reg) || empty($reg['id'])) {
        return ['ok' => false, 'error' => 'Registration failed.', 'status' => 500];
    }

    $token = bin2hex(random_bytes(16));
    $ticketRes = supabase_request(
        'POST',
        rtrim(SUPABASE_URL, '/') . '/rest/v1/tickets?select=id,token,registration_id',
        $writeHeaders,
        json_encode([['registration_id' => (string) $reg['id'], 'token' => $token]], JSON_UNESCAPED_SLASHES)
    );
    if (!$ticketRes['ok']) {
        return [
            'ok' => false,
            'error' => build_error($ticketRes['body'] ?? null, (int) ($ticketRes['status'] ?? 0), $ticketRes['error'] ?? null, 'Ticket issue failed'),
            'status' => 500,
        ];
    }

    $ticketRows = json_decode((string) $ticketRes['body'], true);
    $ticket = is_array($ticketRows) && isset($ticketRows[0]) ? $ticketRows[0] : null;
    if (!is_array($ticket) || empty($ticket['id'])) {
        return ['ok' => false, 'error' => 'Ticket issue failed.', 'status' => 500];
    }

    supabase_request(
        'POST',
        rtrim(SUPABASE_URL, '/') . '/rest/v1/attendance?select=id,status',
        $writeHeaders,
        json_encode([['ticket_id' => (string) $ticket['id'], 'status' => 'unscanned']], JSON_UNESCAPED_SLASHES)
    );

    maybe_close_event_registration_at_capacity($eventId, $event, $headers);

    try {
        notify_users_for_registration_access(
            [$studentId],
            'Payment recorded — slot secured',
            'Your payment for "' . (string) ($event['title'] ?? 'this event') . '" was recorded. Your slot is secured.',
            ['event_id' => $eventId, 'type' => 'payment_registered']
        );
    } catch (Throwable $e) {
        // Non-fatal.
    }

    $sessionCapacity = $isSessionEvent
        ? event_session_registration_capacity($eligibleSessions, event_registration_limit($event), event_registration_read_headers())
        : null;
    $registrationCount = is_array($sessionCapacity)
        ? (int) $sessionCapacity['registration_count']
        : fetch_event_registration_count($eventId, $headers);
    $registrationLimit = event_registration_limit($event);

    return [
        'ok' => true,
        'already_registered' => false,
        'amount_paid' => round($amountPaid, 2),
        'ticket' => $ticket,
        'registration_count' => $registrationCount,
        'registration_limit' => $registrationLimit,
        'is_full' => is_array($sessionCapacity)
            ? (bool) $sessionCapacity['is_full']
            : ($registrationLimit !== null && $registrationCount >= $registrationLimit),
        'session_scoped' => $isSessionEvent,
        'session_capacity' => $sessionCapacity,
        'message' => 'Payment saved and student registered.',
    ];
}

function build_paid_event_payment_roster(array $event, array $headers): array
{
    $students = fetch_target_students_for_event($event, $headers);
    $accessMap = build_event_registration_access_map(
        fetch_event_registration_access_rows((string) ($event['id'] ?? ''), $headers)
    );

    $registeredIds = [];
    $eventId = trim((string) ($event['id'] ?? ''));
    if ($eventId !== '') {
        $regRes = supabase_request(
            'GET',
            rtrim(SUPABASE_URL, '/') . '/rest/v1/event_registrations'
                . '?select=student_id'
                . '&event_id=eq.' . rawurlencode($eventId)
                . '&limit=100000',
            $headers
        );
        if ($regRes['ok']) {
            $regRows = json_decode((string) $regRes['body'], true);
            if (is_array($regRows)) {
                foreach ($regRows as $row) {
                    if (!is_array($row)) {
                        continue;
                    }
                    $sid = trim((string) ($row['student_id'] ?? ''));
                    if ($sid !== '') {
                        $registeredIds[$sid] = true;
                    }
                }
            }
        }
    }

    $groups = [];
    foreach ($students as $student) {
        if (!is_array($student)) {
            continue;
        }
        $meta = student_payment_group_key($student);
        $sid = trim((string) ($student['id'] ?? ''));
        $access = $sid !== '' && isset($accessMap[$sid]) && is_array($accessMap[$sid])
            ? $accessMap[$sid]
            : null;

        $amountPaid = null;
        if (is_array($access) && array_key_exists('amount_paid', $access) && $access['amount_paid'] !== null && $access['amount_paid'] !== '') {
            $amountPaid = (float) $access['amount_paid'];
        }

        $paymentStatus = is_array($access)
            ? normalize_registration_payment_status($access['payment_status'] ?? 'pending')
            : 'pending';
        $approved = is_array($access) && registration_access_row_allows($access);
        $isRegistered = $sid !== '' && isset($registeredIds[$sid]);

        $entry = [
            'id' => $sid,
            'display_name' => (string) ($student['display_name'] ?? 'Student'),
            'student_id' => (string) ($student['student_id'] ?? ''),
            'email' => (string) ($student['email'] ?? ''),
            'section_name' => (string) ($student['section_name'] ?? ''),
            'amount_paid' => $amountPaid,
            'payment_note' => is_array($access) ? (string) ($access['payment_note'] ?? '') : '',
            'payment_status' => $paymentStatus,
            'approved' => $approved,
            'registered' => $isRegistered,
        ];

        $gk = $meta['group_key'];
        if (!isset($groups[$gk])) {
            $groups[$gk] = [
                'group_key' => $gk,
                'group_label' => $meta['group_label'],
                'sort_year' => $meta['sort_year'],
                'course_label' => $meta['course_label'],
                'blocks' => [],
            ];
        }

        $bk = $meta['block_label'];
        if (!isset($groups[$gk]['blocks'][$bk])) {
            $groups[$gk]['blocks'][$bk] = [
                'block_label' => $bk,
                'sort_block' => $meta['sort_block'],
                'students' => [],
            ];
        }
        $groups[$gk]['blocks'][$bk]['students'][] = $entry;
    }

    $orderedGroups = array_values($groups);
    usort($orderedGroups, static function (array $a, array $b): int {
        $ya = (int) ($a['sort_year'] ?? 99);
        $yb = (int) ($b['sort_year'] ?? 99);
        if ($ya !== $yb) {
            return $ya <=> $yb;
        }
        return strcmp((string) ($a['course_label'] ?? ''), (string) ($b['course_label'] ?? ''));
    });

    foreach ($orderedGroups as &$group) {
        $blocks = array_values($group['blocks']);
        usort($blocks, static function (array $a, array $b): int {
            return strcmp((string) ($a['sort_block'] ?? 'ZZ'), (string) ($b['sort_block'] ?? 'ZZ'));
        });
        $group['blocks'] = $blocks;
    }
    unset($group);

    return $orderedGroups;
}

function event_session_capacity_include_registered_student(array $capacity, array $eligibleSessions): array
{
    $eligibleIds = [];
    foreach ($eligibleSessions as $session) {
        if (!is_array($session)) {
            continue;
        }
        $sessionId = trim((string) ($session['id'] ?? ''));
        if ($sessionId !== '') {
            $eligibleIds[$sessionId] = true;
        }
    }

    $sessionCounts = is_array($capacity['session_counts'] ?? null)
        ? $capacity['session_counts']
        : [];
    $registeredCount = 0;
    $isFull = false;
    foreach ($sessionCounts as &$sessionCount) {
        if (!is_array($sessionCount)) {
            continue;
        }
        $sessionId = trim((string) ($sessionCount['session_id'] ?? ''));
        if (!isset($eligibleIds[$sessionId])) {
            continue;
        }

        // A confirmed event registration must never appear as zero in its assigned session.
        $sessionCount['registration_count'] = max(1, (int) ($sessionCount['registration_count'] ?? 0));
        $limit = $sessionCount['registration_limit'] ?? null;
        $sessionCount['is_full'] = $limit !== null
            && $sessionCount['registration_count'] >= (int) $limit;
        $registeredCount = max($registeredCount, $sessionCount['registration_count']);
        $isFull = $isFull || (bool) $sessionCount['is_full'];
    }
    unset($sessionCount);

    $capacity['session_counts'] = $sessionCounts;
    $capacity['registration_count'] = max(
        (int) ($capacity['registration_count'] ?? 0),
        $registeredCount
    );
    $capacity['is_full'] = $isFull;
    return $capacity;
}

function build_event_registration_info(string $eventId, array $headers, ?string $studentId = null): array
{
    $eventId = trim($eventId);
    if ($eventId === '') {
        return ['ok' => false, 'error' => 'event_id required.', 'status' => 400];
    }

    $event = fetch_event_with_registration_settings($eventId, $headers);
    if (!is_array($event)) {
        return ['ok' => false, 'error' => 'Event not found.', 'status' => 404];
    }

    $studentId = trim((string) $studentId);
    $studentRegistered = $studentId !== ''
        && is_array(fetch_existing_event_registration($eventId, $studentId, event_registration_read_headers()));
    $registrationCount = fetch_event_registration_count($eventId, $headers);
    if ($studentRegistered) {
        $registrationCount = max(1, $registrationCount);
    }
    $registrationLimit = event_registration_limit($event);
    $isFull = $registrationLimit !== null && $registrationCount >= $registrationLimit;

    $payload = [
        'ok' => true,
        'registration_count' => $registrationCount,
        'registration_limit' => $registrationLimit,
        'participant_total' => format_event_registration_total($registrationCount, $event),
        'is_full' => $isFull,
        'allow_registration' => event_allows_open_registration($event),
        'registration_open_to_all' => event_registration_open_to_all($event),
    ];

    if ($studentId !== '') {
        $studentRow = fetch_student_profile_by_id($studentId, $headers);
        if (is_array($studentRow)) {
            $access = resolve_student_registration_access($event, $studentRow, $headers);
            $sessions = fetch_event_sessions($eventId, event_registration_read_headers());
            $isSessionEvent = $sessions !== [] || event_uses_sessions($event);
            $eligibleSessions = $isSessionEvent
                ? event_matching_sessions_for_student($event, $sessions, $studentRow)
                : [];
            $sessionCapacity = $isSessionEvent
                ? event_session_registration_capacity($eligibleSessions, $registrationLimit, event_registration_read_headers())
                : null;
            if ($studentRegistered && is_array($sessionCapacity)) {
                $sessionCapacity = event_session_capacity_include_registered_student(
                    $sessionCapacity,
                    $eligibleSessions
                );
            }
            $sessionTargetAllowed = !$isSessionEvent || $eligibleSessions !== [];
            $sessionIsFull = is_array($sessionCapacity) && (bool) $sessionCapacity['is_full'];
            $accessMessage = (string) ($access['message'] ?? '');
            if (!$sessionTargetAllowed) {
                $accessMessage = 'This event has no seminar session assigned to your course/year level.';
            } elseif ($sessionIsFull) {
                $accessMessage = 'Registration is full for your assigned seminar.';
            }
            $docAccess = resolve_student_document_access($eventId, $studentId, $headers);
            $payload['availability'] = [
                'allowed' => (bool) ($access['allowed'] ?? false) && $sessionTargetAllowed && !$sessionIsFull,
                'target_allowed' => (bool) ($access['target_allowed'] ?? false) && $sessionTargetAllowed,
                'approval_required' => (bool) ($access['approval_required'] ?? false),
                'eligible_sessions' => $eligibleSessions,
                'session_attendance_rule' => normalize_session_attendance_rule($event['session_attendance_rule'] ?? 'all'),
                'session_scoped' => $isSessionEvent,
                'session_registration_count' => is_array($sessionCapacity)
                    ? (int) $sessionCapacity['registration_count']
                    : null,
                'session_registration_limit' => $isSessionEvent && is_array($sessionCapacity)
                    ? $sessionCapacity['registration_limit']
                    : null,
                'session_is_full' => is_array($sessionCapacity)
                    ? (bool) $sessionCapacity['is_full']
                    : false,
                'session_capacity' => $sessionCapacity,
                'requirements_required' => (bool) ($docAccess['required'] ?? false),
                'requirements_complete' => (bool) ($docAccess['complete'] ?? false),
                'requirements_approved' => (bool) ($docAccess['approved'] ?? false),
                'requirements_status' => (string) ($docAccess['status'] ?? ''),
                'requirements_decline_reason' => (string) ($docAccess['decline_reason'] ?? ''),
                'message' => $accessMessage,
            ];
            $payload['is_registered'] = $studentRegistered;
            if (is_array($sessionCapacity)) {
                $payload['registration_count'] = (int) $sessionCapacity['registration_count'];
                $payload['registration_limit'] = $sessionCapacity['registration_limit'];
                $payload['participant_total'] = format_event_registration_total(
                    (int) $sessionCapacity['registration_count'],
                    array_merge($event, ['registered_count' => (int) $sessionCapacity['registration_count']])
                );
                $payload['is_full'] = (bool) $sessionCapacity['is_full'];
            }
            if (($docAccess['required'] ?? false) && !($docAccess['approved'] ?? false) && ($access['message'] ?? '') === '') {
                $payload['availability']['message'] = (string) ($docAccess['message'] ?? '');
            }
            $payload['student_requirements'] = fetch_student_requirements_map([$eventId], $headers)[$eventId] ?? [];
        }
    }

    return $payload;
}
