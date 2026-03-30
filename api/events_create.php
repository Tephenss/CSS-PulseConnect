<?php
declare(strict_types=1);

session_start();

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/supabase.php';
require_once __DIR__ . '/../includes/json.php';
require_once __DIR__ . '/../includes/csrf.php';

$user = require_role(['teacher', 'admin']);
$data = require_post_json();
require_csrf_from_json($data);

$title = isset($data['title']) ? clean_string((string) $data['title']) : '';
$location = isset($data['location']) ? clean_string((string) $data['location']) : '';
$description = isset($data['description']) ? clean_string((string) $data['description']) : '';
$startAt = isset($data['start_at']) ? (string) $data['start_at'] : '';
$endAt = isset($data['end_at']) ? (string) $data['end_at'] : '';

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
$status = $role === 'admin' ? 'published' : 'pending';

$payload = [
    'title' => $title,
    'description' => $description !== '' ? $description : null,
    'location' => $location !== '' ? $location : null,
    'start_at' => $start->format('c'),
    'end_at' => $end->format('c'),
    'created_by' => (string) ($user['id'] ?? ''),
    'status' => $status,
];

$url = rtrim(SUPABASE_URL, '/') . '/rest/v1/events?select=id,title,status,start_at,end_at';
$headers = [
    'Content-Type: application/json',
    'Accept: application/json',
    'apikey: ' . SUPABASE_KEY,
    'Authorization: Bearer ' . SUPABASE_KEY,
    'Prefer: return=representation',
];

$res = supabase_request('POST', $url, $headers, json_encode([$payload], JSON_UNESCAPED_SLASHES));
if (!$res['ok']) {
    json_response(['ok' => false, 'error' => build_error($res['body'] ?? null, (int) ($res['status'] ?? 0), $res['error'] ?? null, 'Create failed')], 500);
}

$rows = json_decode((string) $res['body'], true);
$event = is_array($rows) && isset($rows[0]) ? $rows[0] : null;
json_response(['ok' => true, 'event' => $event], 200);

