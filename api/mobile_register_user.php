<?php
declare(strict_types=1);

/**
 * Mobile student register — must claim an unclaimed school roster row.
 * Identity fields (name/course/section) come from roster, not the client.
 *
 * Account row may be written as account_status=preverify before OTP, but Create
 * Account is only "complete" after email OTP. Incomplete (OTP never finished)
 * rows are resumable: update credentials + schedule and return the same user
 * for verification again — do not 409 "already has an account."
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/supabase.php';
require_once __DIR__ . '/../includes/json.php';
require_once __DIR__ . '/../includes/mobile_api.php';
require_once __DIR__ . '/../includes/mobile_session.php';
require_once __DIR__ . '/../includes/api_rate_limit.php';
require_once __DIR__ . '/../includes/student_roster.php';
require_once __DIR__ . '/../includes/registration_form_parse.php';
require_once __DIR__ . '/../includes/student_class_schedules.php';

mobile_api_install_json_error_trap();
$fields = mobile_api_require_post_fields();
mobile_api_validate_key($fields);

$clientIp = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
if (!api_rate_limit_allow('mobile_register_user:' . $clientIp, 8, 600)) {
    json_response(['ok' => false, 'error' => 'Too many registration attempts. Please wait.'], 429);
}

$email = strtolower(trim((string) ($fields['email'] ?? '')));
$password = (string) ($fields['password'] ?? '');
$idNumber = student_roster_normalize_no((string) ($fields['student_id'] ?? $fields['student_no'] ?? $fields['id_number'] ?? ''));

if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    json_response(['ok' => false, 'error' => 'Please enter a valid email address.'], 400);
}
if (mb_strlen($password) < 8) {
    json_response(['ok' => false, 'error' => 'Password must be at least 8 characters.'], 400);
}
if ($idNumber === '') {
    json_response(['ok' => false, 'error' => 'Student number is required.'], 400);
}

$upload = registration_form_accept_upload();
if (!$upload['ok']) {
    json_response(['ok' => false, 'error' => $upload['error'] !== '' ? $upload['error'] : 'Upload your LU registration form PDF to continue.'], 400);
}
$pdfBinary = (string) file_get_contents($upload['path']);
registration_form_discard($upload['path']);
$parsedSchedule = registration_form_parse_pdf_bytes($pdfBinary);
unset($pdfBinary);
if (!($parsedSchedule['ok'] ?? false)) {
    json_response(['ok' => false, 'error' => (string) ($parsedSchedule['error'] ?? 'Could not read that PDF.')], 400);
}
$scheduleSubjects = $parsedSchedule['subjects'] ?? [];
if (!is_array($scheduleSubjects) || $scheduleSubjects === []) {
    json_response(['ok' => false, 'error' => 'Could not read subjects from that PDF.'], 400);
}

$roster = student_roster_fetch_by_no($idNumber);
if ($roster === null) {
    json_response(['ok' => false, 'error' => 'No matching student record found. Check your student number or contact admin.'], 404);
}

$headers = mobile_api_supabase_headers();
$writeHeaders = [
    'Content-Type: application/json',
    'Accept: application/json',
    'apikey: ' . SUPABASE_KEY,
    'Authorization: Bearer ' . SUPABASE_KEY,
    'Prefer: return=representation',
];

$claimedUserId = trim((string) ($roster['user_id'] ?? ''));
$resumeUser = null;
if ($claimedUserId !== '') {
    $claimedUser = student_roster_fetch_signup_user($claimedUserId);
    if ($claimedUser !== null && student_user_is_incomplete_signup($claimedUser)) {
        $resumeUser = $claimedUser;
    } else {
        json_response(['ok' => false, 'error' => 'This student number already has an account. Please log in.'], 409);
    }
} else {
    $orphan = student_roster_find_incomplete_by_student_no($idNumber);
    if ($orphan !== null) {
        $resumeUser = $orphan;
    }
}

// Email uniqueness (ignore the incomplete row being resumed).
$existingUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/' . SUPABASE_TABLE_USERS
    . '?select=id&email=eq.' . rawurlencode($email) . '&limit=1';
$existingRes = supabase_request('GET', $existingUrl, $headers);
if (!$existingRes['ok']) {
    json_response(['ok' => false, 'error' => 'Registration failed. Please try again.'], 500);
}
$existingRows = json_decode((string) $existingRes['body'], true);
if (is_array($existingRows) && isset($existingRows[0]) && is_array($existingRows[0])) {
    $emailOwnerId = trim((string) ($existingRows[0]['id'] ?? ''));
    $resumeId = trim((string) ($resumeUser['id'] ?? ''));
    if ($emailOwnerId !== '' && ($resumeId === '' || !hash_equals($resumeId, $emailOwnerId))) {
        json_response(['ok' => false, 'error' => 'An account with this email already exists.'], 409);
    }
}

// Finished accounts with this student number (not incomplete) still block.
if ($resumeUser === null) {
    $dupIdUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/' . SUPABASE_TABLE_USERS
        . '?select=id,account_status,email_verified&student_id=eq.' . rawurlencode($idNumber)
        . '&role=eq.student&limit=5';
    $dupIdRes = supabase_request('GET', $dupIdUrl, $headers);
    $dupIdRows = $dupIdRes['ok'] ? json_decode((string) ($dupIdRes['body'] ?? ''), true) : [];
    if (is_array($dupIdRows)) {
        foreach ($dupIdRows as $dup) {
            if (!is_array($dup)) {
                continue;
            }
            if (!student_user_is_incomplete_signup($dup)) {
                json_response(['ok' => false, 'error' => 'This student number already has an account. Please log in.'], 409);
            }
        }
    }
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

$payloadBase = [
    'first_name' => $firstName,
    'middle_name' => $middleName !== '' ? $middleName : null,
    'last_name' => $lastName,
    'suffix' => $suffix !== '' ? $suffix : null,
    'student_id' => $idNumber,
    'course' => $course,
    'email' => $email,
    'password' => $passwordHash,
    'email_verified' => false,
    'email_verified_at' => null,
    'account_status' => 'preverify',
    'registration_source' => 'app',
    'section_id' => $sectionId !== '' ? $sectionId : null,
    'role' => 'student',
    'updated_at' => $now,
];

$user = null;
$newUserId = '';
$resumed = false;

if ($resumeUser !== null) {
    $resumed = true;
    $newUserId = trim((string) ($resumeUser['id'] ?? ''));
    if ($newUserId === '') {
        json_response(['ok' => false, 'error' => 'Registration failed. Please try again.'], 500);
    }

    $patch = $payloadBase;
    $updateUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/' . SUPABASE_TABLE_USERS
        . '?id=eq.' . rawurlencode($newUserId)
        . '&select=' . rawurlencode(mobile_user_public_fields());
    $updateRes = supabase_request('PATCH', $updateUrl, $writeHeaders, json_encode($patch, JSON_UNESCAPED_SLASHES));

    if (!$updateRes['ok']) {
        $body = strtolower((string) ($updateRes['body'] ?? ''));
        if (str_contains($body, 'users_account_status_check') && str_contains($body, 'preverify')) {
            $patch['account_status'] = 'pending';
            $updateRes = supabase_request('PATCH', $updateUrl, $writeHeaders, json_encode($patch, JSON_UNESCAPED_SLASHES));
        }
    }
    if (!$updateRes['ok']) {
        json_response(['ok' => false, 'error' => 'Could not update incomplete signup. Please try again.'], 500);
    }
    $rows = json_decode((string) $updateRes['body'], true);
    $user = is_array($rows) && isset($rows[0]) && is_array($rows[0])
        ? mobile_user_strip_secrets($rows[0])
        : null;
} else {
    $payload = $payloadBase;
    $payload['created_at'] = $now;
    $insertUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/' . SUPABASE_TABLE_USERS
        . '?select=' . rawurlencode(mobile_user_public_fields());
    $insertRes = supabase_request('POST', $insertUrl, $writeHeaders, json_encode($payload, JSON_UNESCAPED_SLASHES));

    if (!$insertRes['ok']) {
        $body = strtolower((string) ($insertRes['body'] ?? ''));
        if (str_contains($body, 'users_account_status_check') && str_contains($body, 'preverify')) {
            $payload['account_status'] = 'pending';
            $insertRes = supabase_request('POST', $insertUrl, $writeHeaders, json_encode($payload, JSON_UNESCAPED_SLASHES));
        }
    }

    if (!$insertRes['ok']) {
        json_response(['ok' => false, 'error' => 'Registration failed. Please try again.'], 500);
    }

    $rows = json_decode((string) $insertRes['body'], true);
    $user = is_array($rows) && isset($rows[0]) && is_array($rows[0])
        ? mobile_user_strip_secrets($rows[0])
        : null;
    $newUserId = trim((string) ($user['id'] ?? ''));
}

if ($user === null || $newUserId === '') {
    json_response(['ok' => false, 'error' => 'Registration failed. Please try again.'], 500);
}

$rosterId = trim((string) ($roster['id'] ?? ''));
if ($newUserId !== '' && $rosterId !== '') {
    if ($claimedUserId === '') {
        $claimRes = supabase_request(
            'PATCH',
            rtrim(SUPABASE_URL, '/') . '/rest/v1/student_roster'
                . '?id=eq.' . rawurlencode($rosterId)
                . '&user_id=is.null',
            $writeHeaders,
            json_encode(['user_id' => $newUserId, 'updated_at' => $now], JSON_UNESCAPED_SLASHES)
        );
        if (!$claimRes['ok']) {
            if (!$resumed) {
                supabase_request(
                    'DELETE',
                    rtrim(SUPABASE_URL, '/') . '/rest/v1/' . SUPABASE_TABLE_USERS . '?id=eq.' . rawurlencode($newUserId),
                    $headers
                );
            }
            json_response(['ok' => false, 'error' => 'Could not claim student record. Please try again.'], 409);
        }
        $claimed = json_decode((string) ($claimRes['body'] ?? ''), true);
        if (!is_array($claimed) || $claimed === []) {
            $again = student_roster_fetch_by_no($idNumber);
            if ($again === null || trim((string) ($again['user_id'] ?? '')) !== $newUserId) {
                if (!$resumed) {
                    supabase_request(
                        'DELETE',
                        rtrim(SUPABASE_URL, '/') . '/rest/v1/' . SUPABASE_TABLE_USERS . '?id=eq.' . rawurlencode($newUserId),
                        $headers
                    );
                }
                json_response(['ok' => false, 'error' => 'Student number was just claimed. Please log in or contact admin.'], 409);
            }
        }
    } elseif ($resumeUser !== null && hash_equals($claimedUserId, $newUserId)) {
        // Already claimed by this incomplete user — refresh timestamp best-effort.
        supabase_request(
            'PATCH',
            rtrim(SUPABASE_URL, '/') . '/rest/v1/student_roster'
                . '?id=eq.' . rawurlencode($rosterId)
                . '&user_id=eq.' . rawurlencode($newUserId),
            $writeHeaders,
            json_encode(['updated_at' => $now], JSON_UNESCAPED_SLASHES)
        );
    } else {
        // Orphan incomplete user + roster still pointing elsewhere should not happen
        // after the guards above; re-bind only when roster was empty path handled.
        supabase_request(
            'PATCH',
            rtrim(SUPABASE_URL, '/') . '/rest/v1/student_roster'
                . '?id=eq.' . rawurlencode($rosterId),
            $writeHeaders,
            json_encode(['user_id' => $newUserId, 'updated_at' => $now], JSON_UNESCAPED_SLASHES)
        );
    }
}

if ($newUserId !== '' && !student_class_schedules_replace($newUserId, $idNumber, $scheduleSubjects)) {
    if (!$resumed) {
        supabase_request(
            'DELETE',
            rtrim(SUPABASE_URL, '/') . '/rest/v1/' . SUPABASE_TABLE_USERS . '?id=eq.' . rawurlencode($newUserId),
            $headers
        );
        if ($rosterId !== '') {
            supabase_request(
                'PATCH',
                rtrim(SUPABASE_URL, '/') . '/rest/v1/student_roster?id=eq.' . rawurlencode($rosterId),
                $writeHeaders,
                json_encode(['user_id' => null, 'updated_at' => $now], JSON_UNESCAPED_SLASHES)
            );
        }
    }
    json_response(['ok' => false, 'error' => 'Account was created but class schedule could not be saved. Please try again.'], 500);
}

json_response([
    'ok' => true,
    'resumed' => $resumed,
    'user' => $user,
    'subjects' => student_class_schedules_public_rows($scheduleSubjects),
    'message' => $resumed
        ? 'Previous signup was incomplete. Continue with email verification.'
        : null,
], 200);
