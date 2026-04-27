<?php
declare(strict_types=1);

session_start();

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/supabase.php';
require_once __DIR__ . '/../includes/json.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/event_sessions.php';

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
$graceTime = isset($data['grace_time']) ? clean_string((string) $data['grace_time']) : '15';
$eventSpan = isset($data['event_span']) ? clean_string((string) $data['event_span']) : 'single_day';
$eventMode = normalize_event_mode(isset($data['event_mode']) ? (string) $data['event_mode'] : 'simple');
$sessions = normalize_event_sessions($data['sessions'] ?? null, $location);

if ($eventMode === 'seminar_based') {
    if (count($sessions) === 0) {
        json_response(['ok' => false, 'error' => 'At least one seminar is required'], 400);
    }
    validate_non_overlapping_sessions($sessions);
    $window = derive_event_window_from_sessions($sessions);
    $startAt = (string) ($window['start_at'] ?? '');
    $endAt = (string) ($window['end_at'] ?? '');
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

$role = (string) ($user['role'] ?? 'student');
$status = $role === 'admin' ? 'approved' : 'pending';

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

json_response(['ok' => true, 'event' => $event], 200);

