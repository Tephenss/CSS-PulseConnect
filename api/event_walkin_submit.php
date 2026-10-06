<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/session.php';
session_bootstrap();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/supabase.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/student_roster.php';
require_once __DIR__ . '/../includes/registration_access.php';
require_once __DIR__ . '/../includes/event_attendance_windows.php';
require_once __DIR__ . '/../includes/event_walkin.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('X-Content-Type-Options: nosniff');

function event_walkin_json(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function event_walkin_get_schedule(array $event, ?array $session): array
{
    $startRaw = (string) ($session['start_at'] ?? $event['start_at'] ?? '');
    $endRaw = (string) ($session['end_at'] ?? $event['end_at'] ?? '');
    try { $startAt = $startRaw !== '' ? new DateTimeImmutable($startRaw) : null; } catch (Throwable $e) { $startAt = null; }
    try { $endAt = $endRaw !== '' ? new DateTimeImmutable($endRaw) : null; } catch (Throwable $e) { $endAt = null; }
    $grace = max(1, (int) ($session['scan_window_minutes'] ?? $event['grace_time'] ?? 30));
    // Seminar sessions own their Early Out window; never inherit an event-level
    // timestamp when a specific session was selected.
    $earlyOut = trim((string) ($session !== null
        ? ($session['early_out_enabled_at'] ?? '')
        : ($event['early_out_enabled_at'] ?? '')));
    return [$startAt, $endAt, $grace, $earlyOut !== '' ? $earlyOut : null];
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    event_walkin_json(['ok' => false, 'error' => 'POST required.'], 405);
}

$user = current_user();
if ($user === null) {
    event_walkin_json(['ok' => false, 'error' => 'Sign in with a teacher or admin account to record walk-in attendance.'], 401);
}
$role = strtolower((string) ($user['role'] ?? ''));
if (!in_array($role, ['admin', 'teacher'], true)) {
    event_walkin_json(['ok' => false, 'error' => 'Only teachers and administrators can record walk-in attendance.'], 403);
}
auth_enforce_daily_web_verification($user);

$rawBody = file_get_contents('php://input');
if (!is_string($rawBody) || strlen($rawBody) > 5000) {
    event_walkin_json(['ok' => false, 'error' => 'Invalid request.'], 413);
}
$input = json_decode($rawBody, true);
if (!is_array($input)) {
    event_walkin_json(['ok' => false, 'error' => 'Invalid request.'], 400);
}

csrf_validate(is_string($input['csrf_token'] ?? null) ? $input['csrf_token'] : null);
$eventId = trim((string) ($input['event_id'] ?? ''));
$studentNo = student_roster_normalize_no((string) ($input['student_no'] ?? ''));
$sessionId = trim((string) ($input['session_id'] ?? ''));
if (!preg_match('/^[0-9a-f-]{36}$/i', $eventId)) {
    event_walkin_json(['ok' => false, 'error' => 'This event link is invalid. Ask the event organizer for a new link.'], 404);
}
if ($studentNo === '' || strlen($studentNo) > 32) {
    event_walkin_json(['ok' => false, 'error' => 'Enter a valid student ID.'], 422);
}

$headers = [
    'Accept: application/json',
    'apikey: ' . SUPABASE_KEY,
    'Authorization: Bearer ' . SUPABASE_KEY,
];
$event = fetch_event_with_registration_settings($eventId, $headers);
if (!is_array($event) || (string) ($event['status'] ?? '') !== 'published') {
    event_walkin_json(['ok' => false, 'error' => 'This event is not accepting walk-in attendance right now.'], 403);
}
$isEventOwner = (string) ($event['created_by'] ?? '') !== ''
    && hash_equals((string) $event['created_by'], (string) ($user['id'] ?? ''));
if ($role !== 'admin' && !$isEventOwner) {
    event_walkin_json(['ok' => false, 'error' => "Only this event's teacher or an administrator can log walk-in attendance."], 403);
}
if (!event_is_free_registration_event($event)) {
    event_walkin_json(['ok' => false, 'error' => 'Walk-in attendance is disabled for paid events. Please ask the event organizer.'], 403);
}

$sessionSelect = 'id,event_id,title,topic,start_at,end_at,scan_window_minutes,early_out_enabled_at,sort_order';
$sessionUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/event_sessions?select=' . rawurlencode($sessionSelect)
    . '&event_id=eq.' . rawurlencode($eventId) . '&order=sort_order.asc,start_at.asc&limit=100';
$sessionRes = supabase_request('GET', $sessionUrl, $headers);
if (!($sessionRes['ok'] ?? false)) {
    $fallbackUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/event_sessions?select=id,event_id,title,topic,start_at,end_at,scan_window_minutes,sort_order'
        . '&event_id=eq.' . rawurlencode($eventId) . '&order=sort_order.asc,start_at.asc&limit=100';
    $sessionRes = supabase_request('GET', $fallbackUrl, $headers);
}
if (!($sessionRes['ok'] ?? false)) {
    event_walkin_json(['ok' => false, 'error' => 'Could not load this event schedule. Please ask the organizer.'], 503);
}
$allSessions = json_decode((string) ($sessionRes['body'] ?? ''), true);
$allSessions = is_array($allSessions) ? $allSessions : [];
$selectedSession = null;
if ($allSessions !== []) {
    foreach ($allSessions as $session) {
        if (is_array($session) && (string) ($session['id'] ?? '') === $sessionId) {
            $selectedSession = $session;
            break;
        }
    }
    if ($selectedSession === null) {
        event_walkin_json(['ok' => false, 'error' => 'Choose a valid seminar session.'], 422);
    }
} elseif ($sessionId !== '') {
    event_walkin_json(['ok' => false, 'error' => 'This event has no seminar sessions.'], 422);
}

$roster = student_roster_fetch_by_no($studentNo);
if (!is_array($roster)) {
    $bucketKey = 'walkin_failed_lookup_' . $eventId;
    $bucket = $_SESSION[$bucketKey] ?? ['count' => 0, 'started_at' => time()];
    if (!is_array($bucket) || time() - (int) ($bucket['started_at'] ?? 0) > 600) {
        $bucket = ['count' => 0, 'started_at' => time()];
    }
    $bucket['count'] = (int) ($bucket['count'] ?? 0) + 1;
    $_SESSION[$bucketKey] = $bucket;
    if ($bucket['count'] > 10) {
        event_walkin_json(['ok' => false, 'error' => 'Too many unrecognized IDs. Please ask the event staff to verify your school ID.'], 429);
    }
    event_walkin_json(['ok' => false, 'error' => 'Student ID was not found on the active school roster. Please show your school ID to event staff.'], 404);
}
unset($_SESSION['walkin_failed_lookup_' . $eventId]);

$targetRow = [
    'student_id' => (string) ($roster['student_no'] ?? $studentNo),
    'course' => (string) ($roster['program_label'] ?? $roster['course_code'] ?? ''),
    'year_level' => (string) ($roster['year_level'] ?? ''),
    'is_irregular' => (bool) ($roster['is_irregular'] ?? false),
    'sections' => ['name' => ''],
];
$targetRow = event_targeting_apply_roster($targetRow, $roster);
if (!student_matches_event_target($targetRow, (string) ($event['event_for'] ?? 'All'))) {
    event_walkin_json(['ok' => false, 'error' => 'This student ID is not in the target group for this event. Please ask the organizer.'], 403);
}

$rosterId = trim((string) ($roster['id'] ?? ''));
if ($rosterId === '') {
    event_walkin_json(['ok' => false, 'error' => 'The roster record is incomplete. Please ask event staff for help.'], 503);
}
$nowUtc = new DateTimeImmutable('now', new DateTimeZone('UTC'));
$nowIso = $nowUtc->format('c');
[$startAt, $endAt, $graceMinutes, $earlyOutAt] = event_walkin_get_schedule($event, $selectedSession);
$documentId = event_walkin_document_id($eventId, $sessionId, $rosterId);
$existingResult = event_walkin_firestore_get($eventId, $documentId);
if (!$existingResult['ok']) {
    error_log('Walk-in Firestore read failed for event ' . $eventId);
    event_walkin_json(['ok' => false, 'error' => 'Firebase attendance is temporarily unavailable. Please ask the event staff.'], 503);
}
$attendance = $existingResult['row'];
$checkInAt = trim((string) ($attendance['check_in_at'] ?? ''));
$checkOutAt = trim((string) ($attendance['check_out_at'] ?? ''));

if ($checkOutAt !== '') {
    event_walkin_json(['ok' => false, 'error' => 'Time-in and time-out are already recorded for this student and session.'], 409);
}

if (!$existingResult['exists'] || $checkInAt === '') {
    // Registered students must start attendance with the app QR. If a walk-in
    // record already has a time-in, allow that same record to finish at time-out.
    $registrationUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/event_registrations'
        . '?select=id,users!inner(student_id)'
        . '&event_id=eq.' . rawurlencode($eventId)
        . '&users.student_id=eq.' . rawurlencode($studentNo)
        . '&limit=1';
    $registrationRes = supabase_request('GET', $registrationUrl, $headers);
    if (!($registrationRes['ok'] ?? false)) {
        error_log('Walk-in registration eligibility lookup failed for event ' . $eventId);
        event_walkin_json([
            'ok' => false,
            'error' => 'Could not verify this student\'s event registration. Please use the regular app check-in or ask an administrator.',
        ], 503);
    }
    $eventRegistrationRows = json_decode((string) ($registrationRes['body'] ?? ''), true);
    if (!is_array($eventRegistrationRows)) {
        error_log('Walk-in registration eligibility response was invalid for event ' . $eventId);
        event_walkin_json([
            'ok' => false,
            'error' => 'Could not verify this student\'s event registration. Please ask an administrator.',
        ], 503);
    }
    if ($eventRegistrationRows !== []) {
        event_walkin_json([
            'ok' => false,
            'error' => 'This student is already registered for the event. Please use the regular PulseConnect app check-in.',
        ], 409);
    }

    $checkInWindow = attendance_check_in_window_for_start($startAt, $graceMinutes, $nowUtc);
    if (empty($checkInWindow['open'])) {
        event_walkin_json(['ok' => false, 'error' => (string) ($checkInWindow['message'] ?? 'Time-in is not open.')], 409);
    }

    $fields = [
        'event_id' => $eventId,
        'session_id' => $sessionId,
        'roster_student_id' => $rosterId,
        'student_no' => (string) ($roster['student_no'] ?? $studentNo),
        'first_name' => (string) ($roster['first_name'] ?? ''),
        'middle_name' => (string) ($roster['middle_name'] ?? ''),
        'last_name' => (string) ($roster['last_name'] ?? ''),
        'suffix' => (string) ($roster['suffix'] ?? ''),
        'course_code' => (string) ($roster['course_code'] ?? ''),
        'program_label' => (string) ($roster['program_label'] ?? ''),
        'year_level' => (string) ($roster['year_level'] ?? ''),
        'block' => (string) ($roster['block'] ?? ''),
        'section_name' => '',
        'is_irregular' => (bool) ($roster['is_irregular'] ?? false),
        'check_in_at' => $nowIso,
        'check_out_at' => '',
        'status' => 'present',
        'created_at' => $nowIso,
        'updated_at' => $nowIso,
    ];
    $written = $existingResult['exists']
        ? event_walkin_firestore_update($eventId, $documentId, $fields, $existingResult['update_time'])
        : event_walkin_firestore_create($eventId, $documentId, $fields);

    if (!($written['ok'] ?? false)) {
        $reread = event_walkin_firestore_get($eventId, $documentId);
        $saved = is_array($reread['row'] ?? null) ? $reread['row'] : null;
        if (is_array($saved) && trim((string) ($saved['check_in_at'] ?? '')) !== '') {
            event_walkin_json(['ok' => false, 'error' => 'Time-in was already recorded. Return during the time-out window and submit the same ID again.'], 409);
        }
        error_log('Walk-in Firestore time-in write failed for event ' . $eventId);
        event_walkin_json(['ok' => false, 'error' => 'Could not save time-in to Firebase. Please ask the event staff to verify the attendance log.'], 503);
    }

    event_walkin_json([
        'ok' => true,
        'action' => 'time_in',
        'message' => 'Time-in recorded. Return during the time-out window and submit the same student ID again.',
    ]);
}

if ($selectedSession === null) {
    // The shared registration-settings query intentionally omits this optional
    // column, so read the live event toggle directly before evaluating timeout.
    $earlyOutUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/events?select=early_out_enabled_at'
        . '&id=eq.' . rawurlencode($eventId) . '&limit=1';
    $earlyOutRes = supabase_request('GET', $earlyOutUrl, $headers);
    if (!($earlyOutRes['ok'] ?? false)) {
        $earlyOutError = strtolower((string) ($earlyOutRes['body'] ?? '') . ' ' . (string) ($earlyOutRes['error'] ?? ''));
        $columnUnavailable = str_contains($earlyOutError, 'early_out_enabled_at')
            && (str_contains($earlyOutError, 'schema cache')
                || str_contains($earlyOutError, 'does not exist')
                || str_contains($earlyOutError, 'not found'));
        if (!$columnUnavailable) {
            error_log('Walk-in Early Out lookup failed for event ' . $eventId);
            event_walkin_json([
                'ok' => false,
                'error' => 'Could not verify the event time-out window. Please retry or ask the event staff.',
            ], 503);
        }
        $earlyOutAt = null;
    } else {
        $earlyOutRows = json_decode((string) ($earlyOutRes['body'] ?? ''), true);
        if (!is_array($earlyOutRows) || !isset($earlyOutRows[0]) || !is_array($earlyOutRows[0])) {
            error_log('Walk-in Early Out lookup returned an invalid response for event ' . $eventId);
            event_walkin_json([
                'ok' => false,
                'error' => 'Could not verify the event time-out window. Please retry or ask the event staff.',
            ], 503);
        }
        $earlyOutAt = trim((string) ($earlyOutRows[0]['early_out_enabled_at'] ?? ''));
        if ($earlyOutAt === '') {
            $earlyOutAt = null;
        }
    }
}

$checkOutWindow = attendance_check_out_window($endAt, $earlyOutAt, $nowUtc, $startAt, $graceMinutes);
if (empty($checkOutWindow['open'])) {
    event_walkin_json([
        'ok' => false,
        'error' => 'Time-in is already recorded. ' . (string) ($checkOutWindow['message'] ?? 'Time-out is not open yet.'),
    ], 409);
}

$checkedOut = event_walkin_firestore_update($eventId, $documentId, [
    'check_out_at' => $nowIso,
    'updated_at' => $nowIso,
], $existingResult['update_time']);
if (!($checkedOut['ok'] ?? false)) {
    $reread = event_walkin_firestore_get($eventId, $documentId);
    $saved = is_array($reread['row'] ?? null) ? $reread['row'] : null;
    if (is_array($saved) && trim((string) ($saved['check_out_at'] ?? '')) !== '') {
        event_walkin_json(['ok' => false, 'error' => 'Time-out was already recorded for this student and session.'], 409);
    }
    error_log('Walk-in Firestore time-out write failed for event ' . $eventId);
    event_walkin_json(['ok' => false, 'error' => 'Could not save time-out to Firebase. Please ask the event staff to verify the attendance log.'], 503);
}

event_walkin_json(['ok' => true, 'action' => 'time_out', 'message' => 'Time-out recorded successfully. Thank you for attending.']);
