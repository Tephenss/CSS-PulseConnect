<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/session.php';
session_bootstrap();

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/supabase.php';
require_once __DIR__ . '/../includes/json.php';
require_once __DIR__ . '/../includes/api_rate_limit.php';
require_once __DIR__ . '/../includes/teacher_notification_audience.php';

$user = require_role(['teacher']);
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_response(['ok' => false, 'error' => 'Method not allowed.'], 405);
}

$teacherId = trim((string) ($user['id'] ?? ''));
if ($teacherId === '' || !api_rate_limit_allow('teacher-notification-users:' . $teacherId, 120, 60)) {
    json_response(['ok' => false, 'error' => 'Too many searches. Please wait a moment.'], 429);
}

if (($_GET['action'] ?? '') === 'audiences') {
    try {
        $snapshot = teacher_notification_audience_snapshot();
        unset($snapshot['rows']);
        json_response(['ok' => true] + $snapshot);
    } catch (Throwable $e) {
        error_log('teacher_notification_users: audience summary failed: ' . $e->getMessage());
        json_response(['ok' => false, 'error' => $e->getMessage()], 503);
    }
}

if (($_GET['action'] ?? '') === 'events') {
    try {
        json_response(['ok' => true, 'events' => teacher_notification_events($teacherId)]);
    } catch (Throwable $e) {
        error_log('teacher_notification_users: event list failed: ' . $e->getMessage());
        json_response(['ok' => false, 'error' => $e->getMessage()], 503);
    }
}

if (($_GET['action'] ?? '') === 'event_audience') {
    $eventId = trim((string) ($_GET['event_id'] ?? ''));
    if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/iD', $eventId) !== 1) {
        json_response(['ok' => false, 'error' => 'Choose a valid event.'], 400);
    }
    try {
        $audience = teacher_notification_event_recipients($eventId, $teacherId);
        json_response([
            'ok' => true,
            'event_id' => $eventId,
            'event_title' => $audience['title'],
            'count' => count($audience['recipient_ids']),
        ]);
    } catch (Throwable $e) {
        error_log('teacher_notification_users: event audience failed: ' . $e->getMessage());
        json_response(['ok' => false, 'error' => $e->getMessage()], 503);
    }
}

$rawSearch = trim((string) ($_GET['search'] ?? ''));
$search = preg_replace('/[^\p{L}\p{N}@._+\- ]/u', '', mb_substr($rawSearch, 0, 80)) ?? '';
$limit = max(1, min(50, (int) ($_GET['limit'] ?? 25)));
$offset = max(0, min(20000, (int) ($_GET['offset'] ?? 0)));
$headers = [
    'Accept: application/json',
    'apikey: ' . SUPABASE_KEY,
    'Authorization: Bearer ' . SUPABASE_KEY,
];

$query = '?select=id,first_name,middle_name,last_name,email,student_id,course'
    . '&role=eq.student&order=last_name.asc,first_name.asc'
    . '&limit=' . ($limit + 1) . '&offset=' . $offset;
if ($search !== '') {
    $pattern = '*' . $search . '*';
    $orFilters = [];
    foreach (['first_name', 'middle_name', 'last_name', 'student_id', 'email', 'course'] as $column) {
        $orFilters[] = $column . '.ilike.' . $pattern;
    }
    $query .= '&or=' . rawurlencode('(' . implode(',', $orFilters) . ')');
}

$fetch = static function (bool $filterArchived) use ($query, $headers): array {
    $archiveFilter = $filterArchived ? '&archived_at=is.null' : '';
    return supabase_request(
        'GET',
        rtrim(SUPABASE_URL, '/') . '/rest/v1/' . SUPABASE_TABLE_USERS . $query . $archiveFilter,
        $headers
    );
};

$res = $fetch(true);
if (!($res['ok'] ?? false) && str_contains(strtolower((string) ($res['body'] ?? '')), 'archived_at')) {
    json_response(['ok' => false, 'error' => 'The student archive filter is unavailable. Apply the current student archive database migration before sending notifications.'], 503);
}
if (!($res['ok'] ?? false)) {
    error_log('teacher_notification_users: user search failed: ' . substr((string) ($res['body'] ?? ''), 0, 300));
    json_response(['ok' => false, 'error' => 'Could not load student accounts right now.'], 503);
}

$rows = json_decode((string) ($res['body'] ?? ''), true);
$rows = is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [];
$hasMore = count($rows) > $limit;
if ($hasMore) {
    $rows = array_slice($rows, 0, $limit);
}

$items = [];
foreach ($rows as $row) {
    $id = trim((string) ($row['id'] ?? ''));
    if ($id === '') {
        continue;
    }
    $items[] = [
        'id' => $id,
        'first_name' => trim((string) ($row['first_name'] ?? '')),
        'middle_name' => trim((string) ($row['middle_name'] ?? '')),
        'last_name' => trim((string) ($row['last_name'] ?? '')),
        'email' => trim((string) ($row['email'] ?? '')),
        'student_id' => trim((string) ($row['student_id'] ?? '')),
        'course' => trim((string) ($row['course'] ?? '')),
    ];
}

json_response([
    'ok' => true,
    'items' => $items,
    'has_more' => $hasMore,
    'limit' => $limit,
    'offset' => $offset,
]);
