<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/json.php';
require_once __DIR__ . '/../includes/mobile_api.php';
require_once __DIR__ . '/../includes/device_trust.php';

$data = mobile_api_require_post_json();
mobile_api_validate_key($data);

$ip = device_trust_client_ip();
$key = device_trust_ip_key($ip);

json_response([
    'ok' => true,
    'ip' => $ip,
    'device_key' => $key,
]);
