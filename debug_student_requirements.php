<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/supabase.php';
require_once __DIR__ . '/includes/student_requirements.php';

$headers = student_requirement_headers();

$eventsUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/events'
    . '?select=id,title,status,created_at'
    . '&order=created_at.desc'
    . '&limit=10';
$eventsRes = supabase_request('GET', $eventsUrl, $headers);

echo "=== Recent events ===\n";
$events = json_decode((string) ($eventsRes['body'] ?? ''), true);
if (!is_array($events)) {
    echo "Failed to load events: " . ($eventsRes['body'] ?? '') . "\n";
    exit(1);
}

foreach ($events as $event) {
    if (!is_array($event)) {
        continue;
    }
    $eventId = (string) ($event['id'] ?? '');
    $title = (string) ($event['title'] ?? '');
    echo "- {$title} ({$eventId}) status=" . ($event['status'] ?? '') . "\n";
}

$reqUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/event_student_requirements'
    . '?select=id,event_id,code,label,sort_order,created_at'
    . '&order=created_at.desc'
    . '&limit=50';
$reqRes = supabase_request('GET', $reqUrl, $headers);

echo "\n=== event_student_requirements query ===\n";
echo "HTTP " . ($reqRes['status'] ?? 0) . "\n";
echo (string) ($reqRes['body'] ?? '') . "\n";

$anonKey = 'sb_publishable_yCbwKxMvADK8IqylAjnOkw_mGRUgzF2';
$anonHeaders = [
    'Accept: application/json',
    'apikey: ' . $anonKey,
    'Authorization: Bearer ' . $anonKey,
];
$anonUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/event_student_requirements'
    . '?select=id,label&event_id=eq.eb567cc3-e8b5-4a0a-9e79-2c54906cebab';
$anonRes = supabase_request('GET', $anonUrl, $anonHeaders);
echo "\n=== anon direct table read ===\n";
echo 'HTTP ' . ($anonRes['status'] ?? 0) . "\n";
echo (string) ($anonRes['body'] ?? '') . "\n";

$rpcUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/rpc/get_event_student_requirements';
$rpcRes = supabase_request(
    'POST',
    $rpcUrl,
    array_merge($anonHeaders, ['Content-Type: application/json']),
    json_encode(['p_event_id' => 'eb567cc3-e8b5-4a0a-9e79-2c54906cebab'])
);
echo "\n=== anon RPC read ===\n";
echo 'HTTP ' . ($rpcRes['status'] ?? 0) . "\n";
echo (string) ($rpcRes['body'] ?? '') . "\n";
