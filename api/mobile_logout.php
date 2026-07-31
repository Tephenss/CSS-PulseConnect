<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/json.php';
require_once __DIR__ . '/../includes/mobile_api.php';
require_once __DIR__ . '/../includes/mobile_session.php';

$data = mobile_api_require_post_json();
mobile_api_validate_key($data);

$token = mobile_session_extract_token($data);
if ($token === '') {
    json_response(['ok' => true, 'message' => 'Already logged out.'], 200);
}

mobile_session_revoke($token);
json_response(['ok' => true, 'message' => 'Logged out.'], 200);
