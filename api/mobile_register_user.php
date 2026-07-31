<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/supabase.php';
require_once __DIR__ . '/../includes/json.php';
require_once __DIR__ . '/../includes/mobile_api.php';
require_once __DIR__ . '/../includes/mobile_session.php';
require_once __DIR__ . '/../includes/api_rate_limit.php';

$data = mobile_api_require_post_json();
mobile_api_validate_key($data);

$clientIp = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
if (!api_rate_limit_allow('mobile_register_user:' . $clientIp, 8, 600)) {
    json_response(['ok' => false, 'error' => 'Too many registration attempts. Please wait.'], 429);
}

$email = strtolower(trim((string) ($data['email'] ?? '')));
$password = (string) ($data['password'] ?? '');
$firstName = trim((string) ($data['first_name'] ?? ''));
$middleName = trim((string) ($data['middle_name'] ?? ''));
$lastName = trim((string) ($data['last_name'] ?? ''));
$suffix = trim((string) ($data['suffix'] ?? ''));
$idNumber = trim((string) ($data['student_id'] ?? $data['id_number'] ?? ''));
$course = strtoupper(trim((string) ($data['course'] ?? '')));

if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    json_response(['ok' => false, 'error' => 'Please enter a valid email address.'], 400);
}
if (mb_strlen($password) < 8) {
    json_response(['ok' => false, 'error' => 'Password must be at least 8 characters.'], 400);
}
if ($firstName === '' || $lastName === '') {
    json_response(['ok' => false, 'error' => 'First and last name are required.'], 400);
}
if ($course !== 'IT' && $course !== 'CS') {
    json_response(['ok' => false, 'error' => 'Please select a valid course (IT or CS).'], 400);
}

$headers = mobile_api_supabase_headers();
$existingUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/' . SUPABASE_TABLE_USERS
    . '?select=id&email=eq.' . rawurlencode($email) . '&limit=1';
$existingRes = supabase_request('GET', $existingUrl, $headers);
if (!$existingRes['ok']) {
    json_response(['ok' => false, 'error' => 'Registration failed. Please try again.'], 500);
}
$existingRows = json_decode((string) $existingRes['body'], true);
if (is_array($existingRows) && count($existingRows) > 0) {
    json_response(['ok' => false, 'error' => 'An account with this email already exists.'], 409);
}

$passwordHash = password_hash($password, PASSWORD_DEFAULT);
$now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DATE_ATOM);
$payload = [
    'first_name' => $firstName,
    'middle_name' => $middleName !== '' ? $middleName : null,
    'last_name' => $lastName,
    'suffix' => $suffix !== '' ? $suffix : null,
    'student_id' => $idNumber !== '' ? $idNumber : null,
    'course' => $course,
    'email' => $email,
    'password' => $passwordHash,
    'email_verified' => false,
    'account_status' => 'preverify',
    'registration_source' => 'app',
    'section_id' => null,
    'role' => 'student',
    'created_at' => $now,
    'updated_at' => $now,
];

$insertHeaders = [
    'Content-Type: application/json',
    'Accept: application/json',
    'apikey: ' . SUPABASE_KEY,
    'Authorization: Bearer ' . SUPABASE_KEY,
    'Prefer: return=representation',
];
$insertUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/' . SUPABASE_TABLE_USERS
    . '?select=' . rawurlencode(mobile_user_public_fields());
$insertRes = supabase_request('POST', $insertUrl, $insertHeaders, json_encode($payload, JSON_UNESCAPED_SLASHES));

if (!$insertRes['ok']) {
    $body = strtolower((string) ($insertRes['body'] ?? ''));
    if (str_contains($body, 'users_account_status_check') && str_contains($body, 'preverify')) {
        $payload['account_status'] = 'pending';
        $insertRes = supabase_request('POST', $insertUrl, $insertHeaders, json_encode($payload, JSON_UNESCAPED_SLASHES));
    }
}

if (!$insertRes['ok']) {
    json_response(['ok' => false, 'error' => 'Registration failed. Please try again.'], 500);
}

$rows = json_decode((string) $insertRes['body'], true);
$user = is_array($rows) && isset($rows[0]) && is_array($rows[0])
    ? mobile_user_strip_secrets($rows[0])
    : null;
if ($user === null) {
    json_response(['ok' => false, 'error' => 'Registration failed. Please try again.'], 500);
}

json_response(['ok' => true, 'user' => $user], 200);
