<?php
declare(strict_types=1);

/**
 * Mobile student register — must claim an unclaimed school roster row.
 * Identity fields (name/course/section) come from roster, not the client.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/supabase.php';
require_once __DIR__ . '/../includes/json.php';
require_once __DIR__ . '/../includes/mobile_api.php';
require_once __DIR__ . '/../includes/mobile_session.php';
require_once __DIR__ . '/../includes/api_rate_limit.php';
require_once __DIR__ . '/../includes/student_roster.php';

$data = mobile_api_require_post_json();
mobile_api_validate_key($data);

$clientIp = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
if (!api_rate_limit_allow('mobile_register_user:' . $clientIp, 8, 600)) {
    json_response(['ok' => false, 'error' => 'Too many registration attempts. Please wait.'], 429);
}

$email = strtolower(trim((string) ($data['email'] ?? '')));
$password = (string) ($data['password'] ?? '');
$idNumber = student_roster_normalize_no((string) ($data['student_id'] ?? $data['student_no'] ?? $data['id_number'] ?? ''));

if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    json_response(['ok' => false, 'error' => 'Please enter a valid email address.'], 400);
}
if (mb_strlen($password) < 8) {
    json_response(['ok' => false, 'error' => 'Password must be at least 8 characters.'], 400);
}
if ($idNumber === '') {
    json_response(['ok' => false, 'error' => 'Student number is required.'], 400);
}

$roster = student_roster_fetch_by_no($idNumber);
if ($roster === null) {
    json_response(['ok' => false, 'error' => 'No matching student record found. Check your student number or contact admin.'], 404);
}
if (trim((string) ($roster['user_id'] ?? '')) !== '') {
    json_response(['ok' => false, 'error' => 'This student number already has an account. Please log in.'], 409);
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

$dupIdUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/' . SUPABASE_TABLE_USERS
    . '?select=id&student_id=eq.' . rawurlencode($idNumber) . '&role=eq.student&limit=1';
$dupIdRes = supabase_request('GET', $dupIdUrl, $headers);
$dupIdRows = $dupIdRes['ok'] ? json_decode((string) ($dupIdRes['body'] ?? ''), true) : [];
if (is_array($dupIdRows) && count($dupIdRows) > 0) {
    json_response(['ok' => false, 'error' => 'This student number already has an account. Please log in.'], 409);
}

$firstName = trim((string) ($roster['first_name'] ?? ''));
$middleName = trim((string) ($roster['middle_name'] ?? ''));
$lastName = trim((string) ($roster['last_name'] ?? ''));
$suffix = trim((string) ($roster['suffix'] ?? ''));
$course = strtoupper(trim((string) ($roster['course_code'] ?? '')));
$sectionId = trim((string) ($roster['section_id'] ?? ''));
if ($firstName === '') {
    $firstName = 'Student';
}
if ($lastName === '') {
    $lastName = $idNumber;
}
if ($course !== 'IT' && $course !== 'CS') {
    json_response(['ok' => false, 'error' => 'Roster course is invalid. Contact admin.'], 500);
}

$passwordHash = password_hash($password, PASSWORD_DEFAULT);
$now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DATE_ATOM);
$payload = [
    'first_name' => $firstName,
    'middle_name' => $middleName !== '' ? $middleName : null,
    'last_name' => $lastName,
    'suffix' => $suffix !== '' ? $suffix : null,
    'student_id' => $idNumber,
    'course' => $course,
    'email' => $email,
    'password' => $passwordHash,
    'email_verified' => false,
    'account_status' => 'preverify',
    'registration_source' => 'app',
    'section_id' => $sectionId !== '' ? $sectionId : null,
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

$newUserId = trim((string) ($user['id'] ?? ''));
$rosterId = trim((string) ($roster['id'] ?? ''));
if ($newUserId !== '' && $rosterId !== '') {
    $claimRes = supabase_request(
        'PATCH',
        rtrim(SUPABASE_URL, '/') . '/rest/v1/student_roster'
            . '?id=eq.' . rawurlencode($rosterId)
            . '&user_id=is.null',
        $insertHeaders,
        json_encode(['user_id' => $newUserId, 'updated_at' => $now], JSON_UNESCAPED_SLASHES)
    );
    if (!$claimRes['ok']) {
        // Roll back user so the student number stays claimable.
        supabase_request(
            'DELETE',
            rtrim(SUPABASE_URL, '/') . '/rest/v1/' . SUPABASE_TABLE_USERS . '?id=eq.' . rawurlencode($newUserId),
            $headers
        );
        json_response(['ok' => false, 'error' => 'Could not claim student record. Please try again.'], 409);
    }
    $claimed = json_decode((string) ($claimRes['body'] ?? ''), true);
    if (!is_array($claimed) || $claimed === []) {
        // Prefer:return may be empty on some configs — re-check.
        $again = student_roster_fetch_by_no($idNumber);
        if ($again === null || trim((string) ($again['user_id'] ?? '')) !== $newUserId) {
            supabase_request(
                'DELETE',
                rtrim(SUPABASE_URL, '/') . '/rest/v1/' . SUPABASE_TABLE_USERS . '?id=eq.' . rawurlencode($newUserId),
                $headers
            );
            json_response(['ok' => false, 'error' => 'Student number was just claimed. Please log in or contact admin.'], 409);
        }
    }
}

json_response(['ok' => true, 'user' => $user], 200);
