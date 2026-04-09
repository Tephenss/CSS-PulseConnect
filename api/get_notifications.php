<?php
declare(strict_types=1);

session_start();

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/supabase.php';
require_once __DIR__ . '/../includes/json.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_response(['ok' => false, 'error' => 'Method not allowed'], 405);
}

// Only admins can view the proposal notifications for now.
$user = require_role(['admin']);

$url = rtrim(SUPABASE_URL, '/') . '/rest/v1/events?select=id,title,created_at,users!events_created_by_fkey(full_name)&status=eq.pending&order=created_at.desc';

$headers = [
    'Accept: application/json',
    'apikey: ' . SUPABASE_KEY,
    'Authorization: Bearer ' . SUPABASE_KEY,
];

$res = supabase_request('GET', $url, $headers);

if (!$res['ok']) {
    // If the join fails due to foreign key syntax, fallback to fetching users separately
    $urlFallback = rtrim(SUPABASE_URL, '/') . '/rest/v1/events?select=id,title,created_at,created_by&status=eq.pending&order=created_at.desc';
    $res = supabase_request('GET', $urlFallback, $headers);
    if (!$res['ok']) {
        json_response(['ok' => false, 'error' => 'Failed to fetch pending events'], 500);
    }
    
    $events = json_decode((string) $res['body'], true);
    if (!is_array($events)) $events = [];
    
    // Fallback: manually fetch users
    $notifications = [];
    $userIds = array_filter(array_unique(array_column($events, 'created_by')));
    $userMap = [];
    if (!empty($userIds)) {
        $inList = '(' . implode(',', array_map(fn($id) => rawurlencode((string)$id), $userIds)) . ')';
        $userUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/users?select=id,full_name&id=in.' . $inList;
        $uRes = supabase_request('GET', $userUrl, $headers);
        if ($uRes['ok']) {
            $uRows = json_decode((string)$uRes['body'], true);
            if (is_array($uRows)) {
                foreach ($uRows as $u) {
                    $userMap[$u['id']] = $u['full_name'];
                }
            }
        }
    }
    
    foreach ($events as $e) {
        $creatorName = $userMap[$e['created_by'] ?? ''] ?? 'A teacher';
        $timeRaw = $e['created_at'] ?? gmdate('c');
        $notifications[] = [
            'id' => $e['id'],
            'title' => 'New Event Proposal',
            'description' => "{$creatorName} created a new {$e['title']} proposal that is waiting to be reviewed.",
            'created_at' => $timeRaw
        ];
    }
    
    json_response(['ok' => true, 'notifications' => $notifications, 'count' => count($notifications)]);
}

$events = json_decode((string) $res['body'], true);
if (!is_array($events)) $events = [];

$notifications = [];
foreach ($events as $e) {
    // Determine the creator name
    $creatorName = 'A teacher';
    if (isset($e['users']['full_name'])) {
        $creatorName = $e['users']['full_name'];
    } elseif (isset($e['users']) && is_array($e['users'])) {
        // Sometimes array is sequential
         if (isset($e['users'][0]['full_name'])) {
             $creatorName = $e['users'][0]['full_name'];
         }
    }

    $timeRaw = $e['created_at'] ?? gmdate('c');
    
    $notifications[] = [
        'id' => $e['id'],
        'title' => 'New Event Proposal',
        'description' => "{$creatorName} created a new \"{$e['title']}\" proposal that is waiting to be reviewed.",
        'created_at' => $timeRaw,
        'link' => '/manage_events.php' // Link to approval review tab
    ];
}

json_response(['ok' => true, 'notifications' => $notifications, 'count' => count($notifications)]);
