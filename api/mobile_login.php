<?php
declare(strict_types=1);

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

// Accept student number (students) or email (teachers / legacy). Prefer explicit fields, then `email`/`identifier`.
$identifier = trim((string) ($data['identifier'] ?? $data['login'] ?? ''));
$emailRaw = strtolower(trim((string) ($data['email'] ?? '')));
$studentNoRaw = trim((string) ($data['student_no'] ?? $data['student_id'] ?? ''));
$password = (string) ($data['password'] ?? '');
$expectedRole = strtolower(trim((string) ($data['role'] ?? $data['expected_role'] ?? '')));
$platform = trim((string) ($data['platform'] ?? ''));
$deviceLabel = trim((string) ($data['device_label'] ?? ''));

if ($identifier === '') {
    $identifier = $emailRaw !== '' ? $emailRaw : $studentNoRaw;
}
if ($identifier === '' && $emailRaw !== '') {
    $identifier = $emailRaw;
}

$clientIp = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
if (!api_rate_limit_allow('mobile_login:' . $clientIp, 20, 60)) {
    json_response(['ok' => false, 'error' => 'Too many login attempts. Please wait a moment.'], 429);
}

$rateKey = strtolower($identifier);
if ($rateKey !== '' && !api_rate_limit_allow('mobile_login_id:' . $rateKey, 10, 300)) {
    json_response(['ok' => false, 'error' => 'Too many login attempts for this account. Please wait.'], 429);
}

if ($identifier === '') {
    json_response([
        'ok' => false,
        'error' => $expectedRole === 'student'
            ? 'Enter your student number.'
            : 'Enter your student number or email.',
    ], 400);
}
if ($password === '') {
    json_response(['ok' => false, 'error' => 'Password is required.'], 400);
}
if ($expectedRole !== '' && !in_array($expectedRole, ['student', 'teacher'], true)) {
    json_response(['ok' => false, 'error' => 'Invalid role.'], 400);
}

$useEmail = str_contains($identifier, '@');
if ($expectedRole === 'student' && $useEmail) {
    json_response(['ok' => false, 'error' => 'Students must log in with student number.'], 400);
}
$select = 'id,first_name,middle_name,last_name,suffix,email,role,section_id,course,'
    . 'photo_url,email_verified,email_verified_at,account_status,approval_note,'
    . 'registration_source,created_at,updated_at,password,student_id,archived_at';

if ($useEmail) {
    $email = strtolower($identifier);
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        json_response(['ok' => false, 'error' => 'Please enter a valid email address.'], 400);
    }
    $url = rtrim(SUPABASE_URL, '/') . '/rest/v1/' . SUPABASE_TABLE_USERS
        . '?select=' . rawurlencode($select)
        . '&email=eq.' . rawurlencode($email)
        . '&limit=1';
} else {
    // Teachers still use email; student number login is for students.
    if ($expectedRole === 'teacher') {
        json_response(['ok' => false, 'error' => 'Teachers must log in with email.'], 400);
    }
    $studentNo = student_roster_normalize_no($identifier);
    if ($studentNo === '') {
        json_response(['ok' => false, 'error' => 'Enter a valid student number.'], 400);
    }
    $url = rtrim(SUPABASE_URL, '/') . '/rest/v1/' . SUPABASE_TABLE_USERS
        . '?select=' . rawurlencode($select)
        . '&student_id=eq.' . rawurlencode($studentNo)
        . '&role=eq.student'
        . '&limit=1';
}

$res = supabase_request('GET', $url, mobile_api_supabase_headers());
if (!$res['ok']) {
    $errBody = (string) ($res['body'] ?? '');
    if (str_contains($errBody, 'archived_at')) {
        $selectFallback = str_replace(',archived_at', '', $select);
        $url = str_replace(rawurlencode($select), rawurlencode($selectFallback), $url);
        $res = supabase_request('GET', $url, mobile_api_supabase_headers());
    }
}
if (!$res['ok']) {
    json_response(['ok' => false, 'error' => 'Login failed. Please try again.'], 500);
}

$rows = json_decode((string) $res['body'], true);
$user = is_array($rows) && isset($rows[0]) && is_array($rows[0]) ? $rows[0] : null;
if ($user === null) {
    json_response([
        'ok' => false,
        'error' => $useEmail ? 'No account found with that email.' : 'No account found with that student number.',
    ], 401);
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
if (!empty($user['archived_at'])) {
    json_response(['ok' => false, 'error' => 'This account has been archived. Contact your administrator.'], 403);
}
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
    'user' => $publicUser,
    'session_token' => $session['token'],
    'session_expires_at' => $session['expires_at'],
]);
