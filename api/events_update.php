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
require_once __DIR__ . '/../includes/student_requirements.php';
require_once __DIR__ . '/../includes/event_schedule_conflict.php';

function mode_to_structure(string $eventMode, array $sessions): string
{
    if ($eventMode !== 'seminar_based') {
        return 'simple';
    }

    return count($sessions) > 1 ? 'two_seminars' : 'one_seminar';
}

function is_missing_column_error(array $response, string $column): bool
{
    $body = strtolower((string) ($response['body'] ?? ''));
    return str_contains($body, "'" . strtolower($column) . "'")
        && (
            str_contains($body, 'column')
            || str_contains($body, 'does not exist')
            || str_contains($body, 'schema cache')
        );
}

$user = require_role(['teacher', 'admin']);
$data = require_post_json();
require_csrf_from_json($data);

$eventId = isset($data['event_id']) ? (string) $data['event_id'] : '';
if ($eventId === '') {
    json_response(['ok' => false, 'error' => 'event_id required'], 400);
}

$readHeaders = [
    'Accept: application/json',
    'apikey: ' . SUPABASE_KEY,
    'Authorization: Bearer ' . SUPABASE_KEY,
];

$checkUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/events?id=eq.' . rawurlencode($eventId)
    . '&select=id,status,created_by,title,event_for,start_at,registration_close_weeks,registration_close_extend_days,allow_registration'
    . '&limit=1';
$checkRes = supabase_request('GET', $checkUrl, $readHeaders);
if (!$checkRes['ok']) {
    // Older DBs without extend column — still need weeks for extend math.
    $checkUrlFb = rtrim(SUPABASE_URL, '/') . '/rest/v1/events?id=eq.' . rawurlencode($eventId)
        . '&select=id,status,created_by,title,event_for,start_at,registration_close_weeks,allow_registration'
        . '&limit=1';
    $checkRes = supabase_request('GET', $checkUrlFb, $readHeaders);
}
if (!$checkRes['ok']) {
    json_response(['ok' => false, 'error' => 'Event lookup failed'], 500);
}

$rows = json_decode((string) $checkRes['body'], true);
$currentEvent = is_array($rows) && isset($rows[0]) ? $rows[0] : null;
if (!is_array($currentEvent)) {
    json_response(['ok' => false, 'error' => 'Event not found'], 404);
}

$currentSessions = fetch_event_sessions($eventId, $readHeaders);
$currentEventMode = count($currentSessions) > 0 ? 'seminar_based' : 'simple';
$eventMode = isset($data['event_mode'])
    ? normalize_event_mode((string) $data['event_mode'])
    : $currentEventMode;
$sessionsProvided = array_key_exists('sessions', $data);
$sessions = $sessionsProvided
    ? normalize_event_sessions($data['sessions'] ?? null, isset($data['location']) ? clean_string((string) $data['location']) : '')
    : [];

// Allow title/location/description/start_at/end_at updates.
$fields = [];
if (isset($data['title'])) {
    $t = clean_string((string) $data['title']);
    if ($t !== '' && mb_strlen($t) <= 150) $fields['title'] = $t;
}
if (isset($data['location'])) {
    $loc = clean_string((string) $data['location']);
    if ($loc !== '') $fields['location'] = $loc;
}
if (isset($data['description'])) {
    $desc = clean_text((string) $data['description']);
    $descriptionError = validate_event_description_words($desc);
    if ($descriptionError !== null) {
        json_response(['ok' => false, 'error' => $descriptionError], 400);
    }
    $fields['description'] = $desc !== '' ? $desc : null;
}
if (isset($data['start_at']) && isset($data['end_at']) && $data['start_at'] !== '' && $data['end_at'] !== '') {
    $fields['start_at'] = (new DateTimeImmutable((string)$data['start_at']))->format('c');
    $fields['end_at'] = (new DateTimeImmutable((string)$data['end_at']))->format('c');
}
if (isset($data['event_type'])) {
    $et = clean_string((string) $data['event_type']);
    if ($et !== '') $fields['event_type'] = $et;
}
if (isset($data['event_for'])) {
    $ef = clean_string((string) $data['event_for']);
    if ($ef !== '') $fields['event_for'] = $ef;
}
if (isset($data['grace_time'])) {
    $gt = clean_string((string) $data['grace_time']);
    if ($gt !== '') $fields['grace_time'] = $gt;
}
if (isset($data['is_free_event'])) {
    $fields['is_free_event'] = normalize_registration_bool($data['is_free_event']);
}
if (array_key_exists('event_fee', $data)) {
    $isFree = array_key_exists('is_free_event', $fields)
        ? (bool) $fields['is_free_event']
        : null;
    if ($data['event_fee'] === null || $data['event_fee'] === '') {
        $fields['event_fee'] = null;
    } else {
        $fee = normalize_event_fee($data['event_fee']);
        if ($fee === null || $fee <= 0) {
            json_response(['ok' => false, 'error' => 'Settlement amount must be between 1 and ' . (int) EVENT_FEE_MAX . '.'], 400);
        }
        $fields['event_fee'] = $fee;
    }
    if ($isFree === false && (!isset($fields['event_fee']) || $fields['event_fee'] === null)) {
        json_response(['ok' => false, 'error' => 'Paid events require a settlement amount for students.'], 400);
    }
    if ($isFree === true) {
        $fields['event_fee'] = null;
    }
}
if (array_key_exists('registration_limit', $data)) {
    $registrationLimit = normalize_registration_limit($data['registration_limit']);
    if ($data['registration_limit'] !== null && $data['registration_limit'] !== '' && $registrationLimit === null) {
        json_response(['ok' => false, 'error' => 'Student limit must be between 1 and 9999.'], 400);
    }
    $fields['registration_limit'] = $registrationLimit;
}
if (array_key_exists('registration_close_weeks', $data)) {
    $registrationCloseWeeks = normalize_registration_close_weeks($data['registration_close_weeks']);
    if ($data['registration_close_weeks'] !== null && $data['registration_close_weeks'] !== '' && $registrationCloseWeeks === null) {
        json_response(['ok' => false, 'error' => 'Registration close limit must be between 1 and 4 weeks.'], 400);
    }
    if ($registrationCloseWeeks !== null) {
        $startForClose = null;
        if (isset($fields['start_at'])) {
            try {
                $startForClose = new DateTimeImmutable((string) $fields['start_at']);
            } catch (Throwable $e) {
                $startForClose = null;
            }
        }
        if ($startForClose === null) {
            $existingStart = trim((string) ($currentEvent['start_at'] ?? ''));
            if ($existingStart !== '') {
                try {
                    $startForClose = new DateTimeImmutable($existingStart);
                } catch (Throwable $e) {
                    $startForClose = null;
                }
            }
        }
        if ($startForClose !== null) {
            $maxCloseWeeks = max_registration_close_weeks_for_start($startForClose);
            if ($maxCloseWeeks < 1) {
                json_response([
                    'ok' => false,
                    'error' => 'Registration close limit is not available when the event starts in less than 1 week.',
                ], 400);
            }
            if ($registrationCloseWeeks > $maxCloseWeeks) {
                json_response([
                    'ok' => false,
                    'error' => 'Registration close limit cannot be more than '
                        . $maxCloseWeeks . ' week' . ($maxCloseWeeks === 1 ? '' : 's')
                        . ' before this event start (based on today’s date).',
                ], 400);
            }
        }
    }
    $fields['registration_close_weeks'] = $registrationCloseWeeks;
}
$registrationExtendNotify = null;
if (array_key_exists('registration_close_extend_days', $data)) {
    // Client sends user-facing days from anchor (base close, or today if already past).
    $eventForExtend = $currentEvent;
    if (isset($fields['start_at'])) {
        $eventForExtend['start_at'] = $fields['start_at'];
    }
    if (array_key_exists('registration_close_weeks', $fields)) {
        $eventForExtend['registration_close_weeks'] = $fields['registration_close_weeks'];
    }
    $resolved = resolve_registration_close_extend_request(
        $eventForExtend,
        $data['registration_close_extend_days']
    );
    if (($resolved['ok'] ?? false) !== true) {
        json_response([
            'ok' => false,
            'error' => (string) ($resolved['error'] ?? 'Invalid registration close extension.'),
        ], 400);
    }
    $previousExtendDays = event_registration_close_extend_days($currentEvent);
    $newExtendDays = (int) ($resolved['extend_days'] ?? 0);
    $fields['registration_close_extend_days'] = $newExtendDays;

    // Extending the close window after auto-close must turn Allow Registration
    // back ON — otherwise students still see "registration closed".
    $eventForExtend['registration_close_extend_days'] = $newExtendDays;
    $windowOpenAfterExtend = !is_event_registration_window_closed($eventForExtend)
        && event_registration_close_weeks($eventForExtend) !== null;
    if ($windowOpenAfterExtend) {
        $fields['allow_registration'] = true;
    }

    // Notify target students when the close deadline is pushed further.
    if (
        $newExtendDays > $previousExtendDays
        && $windowOpenAfterExtend
        && strtolower(trim((string) ($currentEvent['status'] ?? ''))) === 'published'
    ) {
        $registrationExtendNotify = [
            'last_day' => (string) ($resolved['last_day'] ?? ''),
            'title' => trim((string) ($fields['title'] ?? $currentEvent['title'] ?? 'this event')),
            'event_for' => (string) ($fields['event_for'] ?? $currentEvent['event_for'] ?? 'All'),
        ];
    }
}
$shouldUpdateMode = isset($data['event_mode']) || $eventMode !== $currentEventMode;
if ($shouldUpdateMode) {
    $fields['event_mode'] = $eventMode;
}

if ($eventMode === 'seminar_based') {
    if (!$sessionsProvided) {
        $sessions = $currentSessions;
    }
    if (count($sessions) === 0) {
        json_response(['ok' => false, 'error' => 'At least one seminar is required'], 400);
    }
    validate_non_overlapping_sessions($sessions);
    $window = derive_event_window_from_sessions($sessions);
    $fields['start_at'] = (string) ($window['start_at'] ?? '');
    $fields['end_at'] = (string) ($window['end_at'] ?? '');
}

if (count($fields) === 0) {
    json_response(['ok' => false, 'error' => 'No fields to update'], 400);
}

// Teacher can only update events they created.
$role = (string) ($user['role'] ?? 'student');
if ($role === 'teacher') {
    if ((string) ($currentEvent['created_by'] ?? '') !== (string) ($user['id'] ?? '')) {
        json_response(['ok' => false, 'error' => 'Forbidden'], 403);
    }

    $currentStatus = (string) ($currentEvent['status'] ?? '');
    // pending/archived: full edit for resubmit.
    // published: allow event_view edits (details + registration close extend).
    if (!in_array($currentStatus, ['pending', 'archived', 'published'], true)) {
        json_response(['ok' => false, 'error' => 'Only pending, published, or rejected events can be edited'], 409);
    }

    // If it was archived (rejected), move it back to pending for review
    if ($currentStatus === 'archived') {
        $fields['status'] = 'pending';
        // Optional: clear the [REJECT_REASON] if they are updating the description
        if (isset($fields['description'])) {
            $fields['description'] = trim(preg_replace('/\[REJECT_REASON:.*?\]/', '', (string)$fields['description']));
        }
    }
}

$mergedStart = (string) ($fields['start_at'] ?? $currentEvent['start_at'] ?? '');
$mergedEnd = (string) ($fields['end_at'] ?? $currentEvent['end_at'] ?? '');
$mergedLocation = (string) ($fields['location'] ?? $currentEvent['location'] ?? '');
$mergedEventFor = (string) ($fields['event_for'] ?? $currentEvent['event_for'] ?? 'All');
event_reject_if_published_schedule_conflict(
    $mergedStart,
    $mergedEnd,
    $mergedLocation,
    $mergedEventFor,
    $eventId
);

$fields['updated_at'] = gmdate('c');

$url = rtrim(SUPABASE_URL, '/') . '/rest/v1/events?id=eq.' . rawurlencode($eventId) . '&select=id,title,status,start_at,end_at';
$headers = [
    'Content-Type: application/json',
    'Accept: application/json',
    'apikey: ' . SUPABASE_KEY,
    'Authorization: Bearer ' . SUPABASE_KEY,
    'Prefer: return=representation',
];

$res = supabase_request('PATCH', $url, $headers, json_encode($fields, JSON_UNESCAPED_SLASHES));
if (!$res['ok'] && (is_missing_column_error($res, 'event_mode') || is_missing_column_error($res, 'is_free_event') || is_missing_column_error($res, 'registration_limit') || is_missing_column_error($res, 'registration_close_weeks') || is_missing_column_error($res, 'registration_close_extend_days') || is_missing_column_error($res, 'event_fee'))) {
    if ($role === 'teacher' && array_key_exists('registration_limit', $fields) && $fields['registration_limit'] !== null && is_missing_column_error($res, 'registration_limit')) {
        json_response([
            'ok' => false,
            'error' => 'Student limit could not be saved. Run supabase/APPLY_REGISTRATION_FIXES.sql in Supabase SQL Editor first.',
        ], 500);
    }
    if ($role === 'teacher' && array_key_exists('registration_close_weeks', $fields) && is_missing_column_error($res, 'registration_close_weeks')) {
        json_response([
            'ok' => false,
            'error' => 'Registration close limit could not be saved. Run supabase/APPLY_REGISTRATION_FIXES.sql in Supabase SQL Editor first.',
        ], 500);
    }
    if (array_key_exists('registration_close_extend_days', $fields) && is_missing_column_error($res, 'registration_close_extend_days')) {
        json_response([
            'ok' => false,
            'error' => 'Registration close extension could not be saved. Run supabase/migrations/047_registration_close_extend_days.sql (and 054_registration_close_extend_days_widen.sql if extend > 3) in Supabase SQL Editor first.',
        ], 500);
    }
    if ($role === 'teacher' && array_key_exists('event_fee', $fields) && $fields['event_fee'] !== null && is_missing_column_error($res, 'event_fee')) {
        json_response([
            'ok' => false,
            'error' => 'Event fee could not be saved. Run supabase/migrations/045_event_fee.sql in Supabase SQL Editor first.',
        ], 500);
    }

    $retryFields = $fields;
    if (is_missing_column_error($res, 'event_mode')) {
        unset($retryFields['event_mode']);
        if ($shouldUpdateMode) {
            $retryFields['event_structure'] = mode_to_structure($eventMode, $eventMode === 'seminar_based' ? $sessions : []);
        }
    }
    if (is_missing_column_error($res, 'is_free_event')) {
        unset($retryFields['is_free_event']);
    }
    if (is_missing_column_error($res, 'registration_limit')) {
        unset($retryFields['registration_limit']);
    }
    if (is_missing_column_error($res, 'registration_close_weeks')) {
        unset($retryFields['registration_close_weeks']);
    }
    if (is_missing_column_error($res, 'registration_close_extend_days')) {
        unset($retryFields['registration_close_extend_days']);
    }
    if (is_missing_column_error($res, 'event_fee')) {
        unset($retryFields['event_fee']);
    }
    $res = supabase_request('PATCH', $url, $headers, json_encode($retryFields, JSON_UNESCAPED_SLASHES));
}
if (!$res['ok'] && is_missing_column_error($res, 'event_mode')) {
    $retryFields = $fields;
    unset($retryFields['event_mode']);
    if ($shouldUpdateMode) {
        $retryFields['event_structure'] = mode_to_structure($eventMode, $eventMode === 'seminar_based' ? $sessions : []);
    }
    $res = supabase_request('PATCH', $url, $headers, json_encode($retryFields, JSON_UNESCAPED_SLASHES));
}

if (!$res['ok']) {
    json_response(['ok' => false, 'error' => build_error($res['body'] ?? null, (int) ($res['status'] ?? 0), $res['error'] ?? null, 'Update failed')], 500);
}

$rows = json_decode((string) $res['body'], true);
$event = is_array($rows) && isset($rows[0]) ? $rows[0] : null;

try {
    if ($eventMode === 'seminar_based') {
        replace_event_sessions($eventId, $sessions, $readHeaders);
    } elseif ($currentEventMode === 'seminar_based' || $sessionsProvided) {
        replace_event_sessions($eventId, [], $readHeaders);
    }
} catch (RuntimeException $e) {
    json_response(['ok' => false, 'error' => $e->getMessage()], 500);
}

if (is_array($event) && $eventMode === 'seminar_based') {
    $event['sessions'] = fetch_event_sessions($eventId, $readHeaders);
}

$studentRequirementsProvided = array_key_exists('student_requirements', $data);
if ($role === 'teacher' && $studentRequirementsProvided) {
    $studentRequirements = is_array($data['student_requirements'] ?? null)
        ? $data['student_requirements']
        : [];

    $studentSave = save_student_requirements(
        $eventId,
        $studentRequirements,
        (string) ($user['id'] ?? ''),
        student_requirement_headers()
    );

    if (!($studentSave['ok'] ?? false)) {
        json_response([
            'ok' => false,
            'error' => (string) ($studentSave['error'] ?? 'Failed to save student requirements.'),
        ], 500);
    }

    if (is_array($event)) {
        $event['student_requirements'] = $studentSave['requirements'] ?? [];
    }
}

require_once __DIR__ . '/../includes/api_cache.php';
api_cache_bump_generation('manage_events');

$push = null;
if (is_array($registrationExtendNotify)) {
    try {
        $notifyEvent = [
            'id' => $eventId,
            'title' => (string) ($registrationExtendNotify['title'] ?? 'Event'),
            'event_for' => (string) ($registrationExtendNotify['event_for'] ?? 'All'),
        ];
        $targetStudents = fetch_target_students_for_event($notifyEvent, $readHeaders);
        $targetIds = array_values(array_filter(array_map(
            static fn (array $row): string => trim((string) ($row['id'] ?? '')),
            $targetStudents
        )));
        if ($targetIds !== []) {
            $eventTitle = trim((string) ($notifyEvent['title'] ?? 'this event'));
            if ($eventTitle === '') {
                $eventTitle = 'this event';
            }
            $lastDayRaw = trim((string) ($registrationExtendNotify['last_day'] ?? ''));
            $untilText = '';
            if ($lastDayRaw !== '') {
                try {
                    $untilText = (new DateTimeImmutable($lastDayRaw . ' 00:00:00', new DateTimeZone('Asia/Manila')))
                        ->format('M j, Y');
                } catch (Throwable $e) {
                    $untilText = $lastDayRaw;
                }
            }
            $body = $untilText !== ''
                ? 'Registration for "' . $eventTitle . '" was extended. You can still register until ' . $untilText . '.'
                : 'Registration for "' . $eventTitle . '" was extended. You can register again.';
            notify_users_for_registration_access(
                $targetIds,
                'Registration Extended',
                $body,
                [
                    'event_id' => $eventId,
                    'type' => 'reg_extended',
                ]
            );
            $push = [
                'type' => 'reg_extended',
                'targets' => count($targetIds),
            ];
        }
    } catch (Throwable $e) {
        error_log('events_update registration extend push: ' . $e->getMessage());
    }
}

json_response(['ok' => true, 'event' => $event, 'push' => $push], 200);

