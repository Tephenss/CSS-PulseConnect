<?php
declare(strict_types=1);

session_start();
set_time_limit(25);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/json.php';
require_once __DIR__ . '/../includes/manage_events_live.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_response(['ok' => false, 'error' => 'Method not allowed'], 405);
}

$user = require_role(['admin', 'teacher']);
$lite = isset($_GET['lite']) && (string) $_GET['lite'] === '1';

try {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    $payload = manage_events_live_payload($user, $lite);
    json_response($payload, 200);
} catch (Throwable $e) {
    json_response(['ok' => false, 'error' => 'Failed to load live event updates'], 500);
}
