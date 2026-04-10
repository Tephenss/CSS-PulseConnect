<?php
declare(strict_types=1);

session_start();

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/supabase.php';
require_once __DIR__ . '/../includes/json.php';
require_once __DIR__ . '/../includes/csrf.php';

$user = require_role(['admin']);
$data = require_post_json();
require_csrf_from_json($data);

$eventId = isset($data['event_id']) ? (string) $data['event_id'] : '';
if ($eventId === '') {
    json_response(['ok' => false, 'error' => 'event_id required'], 400);
}

$headers = [
    'Accept: application/json',
    'apikey: ' . SUPABASE_KEY,
    'Authorization: Bearer ' . SUPABASE_KEY,
];

// Eligible attendees: check_in_at + check_out_at not null
$eligibleUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/attendance'
    . '?select=id,check_in_at,check_out_at,tickets(registration_id,event_registrations(student_id,event_id))'
    . '&tickets.event_registrations.event_id=eq.' . rawurlencode($eventId);

$eligibleRes = supabase_request('GET', $eligibleUrl, $headers);
if (!$eligibleRes['ok']) {
    json_response(['ok' => false, 'error' => build_error($eligibleRes['body'] ?? null, (int) ($eligibleRes['status'] ?? 0), $eligibleRes['error'] ?? null, 'Eligibility lookup failed')], 500);
}

$rows = json_decode((string) $eligibleRes['body'], true);
$rows = is_array($rows) ? $rows : [];

$studentIds = [];
foreach ($rows as $r) {
    if (empty($r['check_in_at']) || empty($r['check_out_at'])) continue;
    $tickets = isset($r['tickets']) && is_array($r['tickets']) ? $r['tickets'] : null;
    if (!$tickets || empty($tickets['event_registrations'])) continue;
    $ereg = $tickets['event_registrations'];
    if (!is_array($ereg)) continue;
    $sid = (string) ($ereg['student_id'] ?? '');
    if ($sid !== '') $studentIds[$sid] = true;
}

$studentIds = array_keys($studentIds);
if (count($studentIds) === 0) {
    json_response(['ok' => false, 'error' => 'No eligible participants'], 409);
}

// Require evaluation completion before certificate generation.
$evaluatedMap = [];
$evalUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/evaluation_answers'
    . '?select=student_id&event_id=eq.' . rawurlencode($eventId)
    . '&student_id=in.(' . implode(',', array_map(static fn(string $id): string => rawurlencode($id), $studentIds)) . ')';
$evalRes = supabase_request('GET', $evalUrl, $headers);
if (!$evalRes['ok']) {
    json_response(['ok' => false, 'error' => build_error($evalRes['body'] ?? null, (int) ($evalRes['status'] ?? 0), $evalRes['error'] ?? null, 'Evaluation lookup failed')], 500);
}

$evalRows = json_decode((string) $evalRes['body'], true);
if (is_array($evalRows)) {
    foreach ($evalRows as $row) {
        $sid = (string) ($row['student_id'] ?? '');
        if ($sid !== '') {
            $evaluatedMap[$sid] = true;
        }
    }
}

$studentIds = array_values(array_filter(
    $studentIds,
    static fn(string $sid): bool => isset($evaluatedMap[$sid])
));
if (count($studentIds) === 0) {
    json_response(['ok' => false, 'error' => 'No eligible participants have completed evaluation yet'], 409);
}

$payloads = [];
foreach ($studentIds as $sid) {
    $payloads[] = [
        'event_id' => $eventId,
        'student_id' => $sid,
        'certificate_code' => bin2hex(random_bytes(8)),
        'issued_by' => (string) ($user['id'] ?? ''),
        'issued_at' => gmdate('c'),
    ];
}

$postUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/certificates?select=id,event_id,student_id,certificate_code';
$postHeaders = [
    'Content-Type: application/json',
    'Accept: application/json',
    'apikey: ' . SUPABASE_KEY,
    'Authorization: Bearer ' . SUPABASE_KEY,
    'Prefer: return=representation,resolution=merge-duplicates',
];

$postRes = supabase_request('POST', $postUrl, $postHeaders, json_encode($payloads, JSON_UNESCAPED_SLASHES));
if (!$postRes['ok']) {
    json_response(['ok' => false, 'error' => build_error($postRes['body'] ?? null, (int) ($postRes['status'] ?? 0), $postRes['error'] ?? null, 'Generate failed')], 500);
}

json_response(['ok' => true, 'count' => is_array(json_decode((string)$postRes['body'], true)) ? count(json_decode((string)$postRes['body'], true)) : count($payloads)], 200);

