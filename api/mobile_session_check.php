<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/json.php';
require_once __DIR__ . '/../includes/mobile_api.php';
require_once __DIR__ . '/../includes/mobile_session.php';

$data = mobile_api_require_post_json();
mobile_api_validate_key($data);

$user = mobile_api_require_user($data);
unset($user['_session_token'], $user['_session_id']);

json_response([
    'ok' => true,
    'user' => $user,
], 200);
