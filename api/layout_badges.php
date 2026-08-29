<?php
declare(strict_types=1);

/**
 * Combined layout badge poll: notifications + manage-events lite + applications count.
 * One HTTP hit per sidebar interval instead of 3 separate polls.
 */

require_once __DIR__ . '/../includes/session.php';
session_bootstrap();
set_time_limit(25);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/json.php';
require_once __DIR__ . '/../includes/supabase.php';
require_once __DIR__ . '/../includes/web_notifications.php';
require_once __DIR__ . '/../includes/manage_events_live.php';
require_once __DIR__ . '/../includes/api_cache.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_response(['ok' => false, 'error' => 'Method not allowed'], 405);
}

$user = require_role(['admin', 'teacher']);
$limit = isset($_GET['limit']) ? max(1, min(100, (int) $_GET['limit'])) : 10;
$skipCache = isset($_GET['fresh']) && (string) $_GET['fresh'] === '1';
$userId = trim((string) ($user['id'] ?? ''));
$role = trim((string) ($user['role'] ?? ''));
$cacheGen = api_cache_generation('manage_events');
$cacheKey = 'layout_badges:g' . $cacheGen . ':' . $role . ':' . $userId . ':' . $limit;

/**
 * @return array{ok:bool,count:int}
 */
function layout_badges_applications_count(): array
{
    $cached = api_cache_read('manage_applications_pending_count', 60);
    if (is_array($cached) && isset($cached['count'])) {
        return [
            'ok' => true,
            'count' => (int) $cached['count'],
        ];
    }

    $headers = [
        'Accept: application/json',
        'apikey: ' . SUPABASE_KEY,
        'Authorization: Bearer ' . SUPABASE_KEY,
    ];
    $query = 'role=eq.student'
        . '&registration_source=eq.app'
        . '&account_status=eq.pending'
        . '&email_verified=eq.true';

    $count = supabase_exact_count(SUPABASE_TABLE_USERS, $headers, $query);
    $payload = ['ok' => true, 'count' => max(0, $count)];
    api_cache_write('manage_applications_pending_count', $payload);
    return $payload;
}

$loader = static function () use ($user, $limit, $role): array {
    $notifications = web_fetch_notifications_for_user($user, $limit);
    $manage = manage_events_live_payload($user, true);
    $applications = ['ok' => true, 'count' => 0];
    if ($role === 'admin') {
        $applications = layout_badges_applications_count();
    }

    return [
        'ok' => true,
        'notifications' => $notifications,
        'count' => count($notifications),
        'manage_events' => $manage,
        'applications' => $applications,
    ];
};

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

if ($skipCache) {
    $payload = $loader();
    api_cache_write($cacheKey, $payload);
    if (is_array($payload['manage_events'] ?? null)) {
        $manageKey = 'manage_events_live:g' . $cacheGen . ':' . $role . ':' . $userId . ':lite';
        api_cache_write($manageKey, $payload['manage_events']);
    }
    api_cache_write('notifications:' . $role . ':' . $userId . ':' . $limit, [
        'ok' => true,
        'notifications' => $payload['notifications'] ?? [],
        'count' => (int) ($payload['count'] ?? 0),
    ]);
    json_response($payload, 200);
}

$payload = api_cache_remember($cacheKey, 70, $loader, 45);
json_response($payload, 200);
