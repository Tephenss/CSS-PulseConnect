<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/json.php';
require_once __DIR__ . '/../includes/mobile_api.php';
require_once __DIR__ . '/../includes/mobile_session.php';
require_once __DIR__ . '/../includes/device_trust.php';

$data = mobile_api_require_post_json();
mobile_api_validate_key($data);

$sessionUser = mobile_api_require_user($data);
$userId = (string) ($sessionUser['id'] ?? '');

$deviceKey = trim((string) ($data['device_key'] ?? ''));
if ($deviceKey === '') {
    $deviceKey = device_trust_ip_key();
}

$trusted = $deviceKey !== '' && device_trust_is_trusted($userId, $deviceKey);

json_response([
    'ok' => true,
    'trusted' => $trusted,
    'device_key' => $deviceKey,
], 200);
