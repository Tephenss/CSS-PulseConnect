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

$email = strtolower(trim((string) ($data['email'] ?? '')));
$password = (string) ($data['password'] ?? '');
$expectedRole = strtolower(trim((string) ($data['role'] ?? $data['expected_role'] ?? '')));
$platform = trim((string) ($data['platform'] ?? ''));
$deviceLabel = trim((string) ($data['device_label'] ?? ''));

$clientIp = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
if (!api_rate_limit_allow('mobile_login:' . $clientIp, 20, 60)) {
    json_response(['ok' => false, 'error' => 'Too many login attempts. Please wait a moment.'], 429);
}
if ($email !== '' && !api_rate_limit_allow('mobile_login_email:' . $email, 10, 300)) {
    json_response(['ok' => false, 'error' => 'Too many login attempts for this account. Please wait.'], 429);
}

if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    json_response(['ok' => false, 'error' => 'Please enter a valid email address.'], 400);
}
if ($password === '') {
    json_response(['ok' => false, 'error' => 'Password is required.'], 400);
}
if ($expectedRole !== '' && !in_array($expectedRole, ['student', 'teacher'], true)) {
    json_response(['ok' => false, 'error' => 'Invalid role.'], 400);
}

$url = rtrim(SUPABASE_URL, '/') . '/rest/v1/' . SUPABASE_TABLE_USERS
    . '?select=id,first_name,middle_name,last_name,suffix,email,role,section_id,course,'
    . 'photo_url,email_verified,email_verified_at,account_status,approval_note,'
    . 'registration_source,created_at,updated_at,password'
    . '&email=eq.' . rawurlencode($email)
    . '&limit=1';

$res = supabase_request('GET', $url, mobile_api_supabase_headers());
if (!$res['ok']) {
    json_response(['ok' => false, 'error' => 'Login failed. Please try again.'], 500);
}

$rows = json_decode((string) $res['body'], true);
$user = is_array($rows) && isset($rows[0]) && is_array($rows[0]) ? $rows[0] : null;
if ($user === null) {
    json_response(['ok' => false, 'error' => 'No account found with that email.'], 401);
}

$storedHash = (string) ($user['password'] ?? '');
if (!mobile_password_verify($password, $storedHash)) {
    json_response(['ok' => false, 'error' => 'Incorrect password.'], 401);
}

$role = strtolower(trim((string) ($user['role'] ?? 'student')));
if ($role === 'admin') {
    json_response(['ok' => false, 'error' => 'Admin accounts must use the web dashboard.'], 403);
}
if ($expectedRole !== '' && $role !== $expectedRole) {
    $label = $role === 'teacher' ? 'Teacher' : 'Student';
    json_response([
        'ok' => false,
        'error' => 'This account is registered as a ' . $label . ', not a ' . $expectedRole . '.',
    ], 403);
}

$accountStatus = strtolower(trim((string) ($user['account_status'] ?? '')));
$registrationSource = strtolower(trim((string) ($user['registration_source'] ?? '')));
$emailVerified = !empty($user['email_verified']);
if ($role === 'student') {
    if ($accountStatus === 'pending' && $emailVerified && $registrationSource === 'app') {
        $createdRaw = (string) ($user['created_at'] ?? '');
        $bypass = false;
        if ($createdRaw !== '') {
            try {
                $created = new DateTimeImmutable($createdRaw);
                $ageDays = (int) (((new DateTimeImmutable('now'))->getTimestamp() - $created->getTimestamp()) / 86400);
                $bypass = $ageDays > 21;
            } catch (Throwable $e) {
                $bypass = false;
            }
        }
        if (!$bypass) {
            json_response([
                'ok' => false,
                'error' => 'Your account is under admin review. Please wait for approval email.',
            ], 403);
        }
    }
    if ($accountStatus === 'rejected') {
        $note = trim((string) ($user['approval_note'] ?? ''));
        json_response([
            'ok' => false,
            'error' => $note !== ''
                ? 'Your application was not approved: ' . $note
                : 'Your application was not approved. Please contact admin.',
        ], 403);
    }
}

$session = mobile_session_create(
    (string) $user['id'],
    $platform !== '' ? $platform : null,
    $deviceLabel !== '' ? $deviceLabel : null
);
if ($session === null) {
    json_response(['ok' => false, 'error' => 'Failed to create mobile session. Apply security migration 048.'], 500);
}

$publicUser = mobile_user_strip_secrets($user);

json_response([
    'ok' => true,
    'session_token' => $session['token'],
    'expires_at' => $session['expires_at'],
    'user' => $publicUser,
], 200);
