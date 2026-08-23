<?php
declare(strict_types=1);

ini_set('display_errors', '0');
ini_set('html_errors', '0');

require_once __DIR__ . '/../includes/session.php';
session_bootstrap();

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/supabase.php';
require_once __DIR__ . '/../includes/json.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/event_sessions.php';
require_once __DIR__ . '/../includes/proposal_requirements.php';
require_once __DIR__ . '/../includes/student_requirements.php';
require_once __DIR__ . '/../includes/registration_access.php';
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

$title = isset($data['title']) ? clean_string((string) $data['title']) : '';
$location = isset($data['location']) ? clean_string((string) $data['location']) : '';
$description = isset($data['description']) ? clean_text((string) $data['description']) : '';
$startAt = isset($data['start_at']) ? (string) $data['start_at'] : '';
$endAt = isset($data['end_at']) ? (string) $data['end_at'] : '';
$eventType = isset($data['event_type']) ? clean_string((string) $data['event_type']) : 'Event';
$eventFor = isset($data['event_for']) ? clean_string((string) $data['event_for']) : 'All';
$graceTime = isset($data['grace_time']) ? clean_string((string) $data['grace_time']) : '30';
$eventSpan = isset($data['event_span']) ? clean_string((string) $data['event_span']) : 'single_day';
$eventMode = normalize_event_mode(isset($data['event_mode']) ? (string) $data['event_mode'] : 'simple');
$sessions = normalize_event_sessions($data['sessions'] ?? null, $location);
$proposalRequirements = is_array($data['proposal_requirements'] ?? null) ? $data['proposal_requirements'] : [];
$studentRequirements = is_array($data['student_requirements'] ?? null) ? $data['student_requirements'] : [];

if ($eventMode === 'seminar_based') {
    if (count($sessions) === 0) {
        json_response(['ok' => false, 'error' => 'At least one seminar is required'], 400);
    }
    validate_non_overlapping_sessions($sessions);
    $window = derive_event_window_from_sessions($sessions);
    $startAt = (string) ($window['start_at'] ?? '');
    $endAt = (string) ($window['end_at'] ?? '');
}

if ((string) ($user['role'] ?? '') === 'teacher' && $proposalRequirements === []) {
    json_response(['ok' => false, 'error' => 'Add the required proposal documents before submitting.'], 400);
}

if ($title === '' || mb_strlen($title) > 150) {
    json_response(['ok' => false, 'error' => 'Invalid title'], 400);
}
if ($startAt === '' || $endAt === '') {
    json_response(['ok' => false, 'error' => 'Start/end required'], 400);
}

try {
    $start = new DateTimeImmutable($startAt);
    $end = new DateTimeImmutable($endAt);
} catch (Throwable $e) {
    json_response(['ok' => false, 'error' => 'Invalid datetime'], 400);
}
if ($end <= $start) {
    json_response(['ok' => false, 'error' => 'End must be after start'], 400);
}

// Block proposals that would collide with an already-published event
// (same time + same place + overlapping target participants).
event_reject_if_published_schedule_conflict(
    $start->format('c'),
    $end->format('c'),
    $location,
    $eventFor
);

$role = (string) ($user['role'] ?? 'student');
$status = $role === 'admin' ? 'approved' : 'pending';
$isFreeEvent = true;
$registrationLimit = null;
$registrationCloseWeeks = null;
$eventFee = null;

if ($role === 'teacher') {
    if (array_key_exists('is_free_event', $data)) {
        $isFreeEvent = normalize_registration_bool($data['is_free_event']);
    }
    if (array_key_exists('registration_limit', $data)) {
        $registrationLimit = normalize_registration_limit($data['registration_limit']);
        if ($data['registration_limit'] !== null && $data['registration_limit'] !== '' && $registrationLimit === null) {
            json_response(['ok' => false, 'error' => 'Student limit must be between 1 and 9999.'], 400);
        }
    }
    if (array_key_exists('registration_close_weeks', $data)) {
        $registrationCloseWeeks = normalize_registration_close_weeks($data['registration_close_weeks']);
        if ($data['registration_close_weeks'] !== null && $data['registration_close_weeks'] !== '' && $registrationCloseWeeks === null) {
            json_response(['ok' => false, 'error' => 'Registration close limit must be between 1 and 4 weeks.'], 400);
        }
    }
    if ($registrationCloseWeeks !== null) {
        $maxCloseWeeks = max_registration_close_weeks_for_start($start);
        if ($maxCloseWeeks < 1) {
            json_response([
                'ok' => false,
                'error' => 'Registration close limit is not available when the event starts in less than 1 week. Move the start date later, or leave the close limit unset.',
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
    if (array_key_exists('event_fee', $data)) {
        if ($data['event_fee'] !== null && $data['event_fee'] !== '') {
            $eventFee = normalize_event_fee($data['event_fee']);
            if ($eventFee === null || $eventFee <= 0) {
                json_response(['ok' => false, 'error' => 'Enter a valid event fee greater than 0.'], 400);
            }
        }
    }
    if (!$isFreeEvent && ($eventFee === null || $eventFee <= 0)) {
        json_response(['ok' => false, 'error' => 'Paid events require a settlement amount for students.'], 400);
    }
    if ($isFreeEvent) {
        $eventFee = null;
    }
}

$payload = [
    'title' => $title,
    'description' => $description !== '' ? $description : null,
    'location' => $location !== '' ? $location : null,
    'start_at' => $start->format('c'),
    'end_at' => $end->format('c'),
    'created_by' => (string) ($user['id'] ?? ''),
    'status' => $status,
    'event_type' => $eventType,
    'event_for' => $eventFor,
    'grace_time' => $graceTime,
    'event_span' => $eventSpan,
];

if ($role === 'teacher') {
    $payload['is_free_event'] = $isFreeEvent;
    $payload['event_fee'] = $eventFee;
    if ($registrationLimit !== null) {
        $payload['registration_limit'] = $registrationLimit;
    }
    if ($registrationCloseWeeks !== null) {
        $payload['registration_close_weeks'] = $registrationCloseWeeks;
    }
}

$url = rtrim(SUPABASE_URL, '/') . '/rest/v1/events?select=id,title,status,start_at,end_at';
$headers = [
    'Content-Type: application/json',
    'Accept: application/json',
    'apikey: ' . SUPABASE_KEY,
    'Authorization: Bearer ' . SUPABASE_KEY,
    'Prefer: return=representation',
];

$payloadWithMode = $payload;
$payloadWithMode['event_mode'] = $eventMode;
$res = supabase_request('POST', $url, $headers, json_encode([$payloadWithMode], JSON_UNESCAPED_SLASHES));
if (!$res['ok'] && is_missing_column_error($res, 'event_mode')) {
    $payloadWithStructure = $payload;
    $payloadWithStructure['event_structure'] = mode_to_structure($eventMode, $sessions);
    $res = supabase_request('POST', $url, $headers, json_encode([$payloadWithStructure], JSON_UNESCAPED_SLASHES));
}
if (!$res['ok'] && (is_missing_column_error($res, 'is_free_event') || is_missing_column_error($res, 'registration_limit') || is_missing_column_error($res, 'registration_close_weeks') || is_missing_column_error($res, 'event_fee'))) {
    if ($role === 'teacher' && $registrationLimit !== null && is_missing_column_error($res, 'registration_limit')) {
        json_response([
            'ok' => false,
            'error' => 'Student limit could not be saved. Run supabase/APPLY_REGISTRATION_FIXES.sql in Supabase SQL Editor first.',
        ], 500);
    }
    if ($role === 'teacher' && $registrationCloseWeeks !== null && is_missing_column_error($res, 'registration_close_weeks')) {
        json_response([
            'ok' => false,
            'error' => 'Registration close limit could not be saved. Run supabase/APPLY_REGISTRATION_FIXES.sql in Supabase SQL Editor first.',
        ], 500);
    }
    if ($role === 'teacher' && $eventFee !== null && is_missing_column_error($res, 'event_fee')) {
        json_response([
            'ok' => false,
            'error' => 'Event fee could not be saved. Run supabase/migrations/045_event_fee.sql in Supabase SQL Editor first.',
        ], 500);
    }

    $retryPayload = $payloadWithMode;
    unset($retryPayload['is_free_event'], $retryPayload['registration_limit'], $retryPayload['registration_close_weeks'], $retryPayload['event_fee']);
    if (!isset($retryPayload['event_mode'])) {
        $retryPayload['event_structure'] = mode_to_structure($eventMode, $sessions);
    }
    $res = supabase_request('POST', $url, $headers, json_encode([$retryPayload], JSON_UNESCAPED_SLASHES));
}

if (!$res['ok']) {
    json_response(['ok' => false, 'error' => build_error($res['body'] ?? null, (int) ($res['status'] ?? 0), $res['error'] ?? null, 'Create failed')], 500);
}

$rows = json_decode((string) $res['body'], true);
$event = is_array($rows) && isset($rows[0]) ? $rows[0] : null;

if ($eventMode === 'seminar_based' && is_array($event) && !empty($event['id'])) {
    try {
        replace_event_sessions((string) $event['id'], $sessions, [
            'Accept: application/json',
            'apikey: ' . SUPABASE_KEY,
            'Authorization: Bearer ' . SUPABASE_KEY,
        ]);
    } catch (RuntimeException $e) {
        $cleanupUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/events?id=eq.' . rawurlencode((string) $event['id']);
        supabase_request('DELETE', $cleanupUrl, [
            'Accept: application/json',
            'apikey: ' . SUPABASE_KEY,
            'Authorization: Bearer ' . SUPABASE_KEY,
        ]);
        json_response(['ok' => false, 'error' => $e->getMessage()], 500);
    }

    $event['sessions'] = fetch_event_sessions((string) $event['id'], [
        'Accept: application/json',
        'apikey: ' . SUPABASE_KEY,
        'Authorization: Bearer ' . SUPABASE_KEY,
    ]);
}

if ($role === 'teacher' && is_array($event) && !empty($event['id'])) {
    $proposalSave = save_proposal_requirements(
        (string) $event['id'],
        $proposalRequirements,
        (string) ($user['id'] ?? ''),
        proposal_requirement_headers(),
        [
            'skip_event_stage_update' => true,
            'include_requirements' => true,
        ]
    );

    if (!($proposalSave['ok'] ?? false)) {
        $cleanupUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/events?id=eq.' . rawurlencode((string) $event['id']);
        supabase_request('DELETE', $cleanupUrl, [
            'Accept: application/json',
            'apikey: ' . SUPABASE_KEY,
            'Authorization: Bearer ' . SUPABASE_KEY,
        ]);
        json_response(['ok' => false, 'error' => (string) ($proposalSave['error'] ?? 'Failed to save proposal requirements.')], 500);
    }

    $event['proposal_requirements'] = $proposalSave['requirements'] ?? [];

    if ($studentRequirements !== []) {
        $studentSave = save_student_requirements(
            (string) $event['id'],
            $studentRequirements,
            (string) ($user['id'] ?? ''),
            proposal_requirement_headers()
        );

        if (!($studentSave['ok'] ?? false)) {
            $cleanupUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/events?id=eq.' . rawurlencode((string) $event['id']);
            supabase_request('DELETE', $cleanupUrl, [
                'Accept: application/json',
                'apikey: ' . SUPABASE_KEY,
                'Authorization: Bearer ' . SUPABASE_KEY,
            ]);
            json_response(['ok' => false, 'error' => (string) ($studentSave['error'] ?? 'Failed to save student requirements.')], 500);
        }

        $event['student_requirements'] = $studentSave['requirements'] ?? [];
    }
}

require_once __DIR__ . '/../includes/api_cache.php';
api_cache_bump_generation('manage_events');

json_response(['ok' => true, 'event' => $event], 200);

