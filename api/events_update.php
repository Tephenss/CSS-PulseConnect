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

$eventId = isset($data['event_id']) ? (string) $data['event_id'] : '';
if ($eventId === '') {
    json_response(['ok' => false, 'error' => 'event_id required'], 400);
}

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


if (count($fields) === 0) {
    json_response(['ok' => false, 'error' => 'No fields to update'], 400);
}

// Teacher can only update pending events they created.
$role = (string) ($user['role'] ?? 'student');
if ($role === 'teacher') {
    $checkUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/events?id=eq.' . rawurlencode($eventId) . '&select=id,status,created_by';
    $headers = [
        'Accept: application/json',
        'apikey: ' . SUPABASE_KEY,
        'Authorization: Bearer ' . SUPABASE_KEY,
    ];
    $checkRes = supabase_request('GET', $checkUrl, $headers);
    if (!$checkRes['ok']) {
        json_response(['ok' => false, 'error' => 'Event lookup failed'], 500);
    }
    $rows = json_decode((string) $checkRes['body'], true);
    $ev = is_array($rows) && isset($rows[0]) ? $rows[0] : null;
    if (!is_array($ev)) json_response(['ok' => false, 'error' => 'Event not found'], 404);
    if ((string) ($ev['created_by'] ?? '') !== (string) ($user['id'] ?? '')) {
        json_response(['ok' => false, 'error' => 'Forbidden'], 403);
    }
    
    $currentStatus = (string) ($ev['status'] ?? '');
    if (!in_array($currentStatus, ['pending', 'archived'], true)) {
        json_response(['ok' => false, 'error' => 'Only pending or rejected events can be edited'], 409);
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
if (!$res['ok']) {
    json_response(['ok' => false, 'error' => build_error($res['body'] ?? null, (int) ($res['status'] ?? 0), $res['error'] ?? null, 'Update failed')], 500);
}

$rows = json_decode((string) $res['body'], true);
$event = is_array($rows) && isset($rows[0]) ? $rows[0] : null;
json_response(['ok' => true, 'event' => $event], 200);

