<?php
declare(strict_types=1);

/**
 * Mobile: exact student-number lookup against school roster (Create Account).
 * Fail-closed: API key + rate limit; no list/search; generic miss message.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/supabase.php';
require_once __DIR__ . '/../includes/json.php';
require_once __DIR__ . '/../includes/mobile_api.php';
require_once __DIR__ . '/../includes/api_rate_limit.php';
require_once __DIR__ . '/../includes/student_roster.php';

$data = mobile_api_require_post_json();
mobile_api_validate_key($data);

$clientIp = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
if (!api_rate_limit_allow('mobile_roster_lookup:' . $clientIp, 20, 300)) {
    json_response(['ok' => false, 'error' => 'Too many lookup attempts. Please wait.'], 429);
}

$studentNo = student_roster_normalize_no((string) ($data['student_no'] ?? $data['student_id'] ?? ''));
if ($studentNo === '' || strlen($studentNo) < 3) {
    json_response(['ok' => false, 'error' => 'Enter a valid student number.'], 400);
}

if (!api_rate_limit_allow('mobile_roster_lookup_no:' . $studentNo, 8, 600)) {
    json_response(['ok' => false, 'error' => 'Too many attempts for this student number.'], 429);
}

$row = student_roster_fetch_by_no($studentNo);
if ($row === null) {
    // Generic — avoid confirming whether the number exists in other ways.
    json_response(['ok' => false, 'error' => 'No matching student record found. Check your student number or contact admin.'], 404);
}

if (trim((string) ($row['user_id'] ?? '')) !== '') {
    json_response(['ok' => false, 'error' => 'This student number already has an account. Please log in.'], 409);
}

json_response([
    'ok' => true,
    'roster' => student_roster_public_preview($row),
]);
