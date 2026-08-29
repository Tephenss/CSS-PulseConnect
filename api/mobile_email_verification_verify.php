<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/supabase.php';
require_once __DIR__ . '/../includes/json.php';
require_once __DIR__ . '/../includes/mobile_api.php';
require_once __DIR__ . '/../includes/mobile_session.php';
require_once __DIR__ . '/../includes/api_rate_limit.php';
require_once __DIR__ . '/../includes/device_trust.php';

function mobile_email_otp_normalize_purpose(string $raw): string
{
    $p = strtolower(trim($raw));
    if (in_array($p, ['signup', 'register', 'registration', 'create'], true)) {
        return 'signup';
    }
    return 'login';
}

$data = mobile_api_require_post_json();
mobile_api_validate_key($data);

$email = strtolower(trim((string) ($data['email'] ?? '')));
$code = trim((string) ($data['code'] ?? ''));
$userIdClaim = trim((string) ($data['user_id'] ?? ''));
$deviceKey = strtolower(trim((string) ($data['device_key'] ?? '')));
$platform = trim((string) ($data['platform'] ?? 'android'));
$deviceLabel = trim((string) ($data['device_label'] ?? ''));
$purpose = mobile_email_otp_normalize_purpose((string) ($data['purpose'] ?? 'login'));

// Prefer session when present (daily re-verify / login OTP).
// Signup OTP must use claimed user_id only — a leftover session from another
// account would otherwise fail with "user_id does not match mobile session."
$sessionToken = mobile_session_extract_token($data);
$userId = '';
if ($purpose === 'signup') {
    $userId = $userIdClaim;
} elseif ($sessionToken !== '') {
    $sessionUser = mobile_api_require_user($data);
    $userId = (string) ($sessionUser['id'] ?? '');
    $email = strtolower(trim((string) ($sessionUser['email'] ?? $email)));
} else {
    $userId = $userIdClaim;
}

$clientIp = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
if (!api_rate_limit_allow('mobile_email_verify_code:' . $purpose . ':' . $clientIp, 20, 300)) {
    json_response(['ok' => false, 'error' => 'Too many attempts. Please wait.'], 429);
}

if ($userId === '') {
    json_response(['ok' => false, 'error' => 'user_id or mobile session required.'], 400);
}
if (!preg_match('/^\d{6}$/', $code)) {
    json_response(['ok' => false, 'error' => 'Verification code must be 6 digits.'], 400);
}

$headers = mobile_api_supabase_headers();
$codeUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/email_verification_codes'
    . '?select=user_id,code,expires_at,purpose'
    . '&user_id=eq.' . rawurlencode($userId)
    . '&purpose=eq.' . rawurlencode($purpose)
    . '&order=last_sent_at.desc'
    . '&limit=1';
$codeRes = supabase_request('GET', $codeUrl, $headers);
$purposeFilterOk = ($codeRes['ok'] ?? false);
if (!$purposeFilterOk) {
    $codeUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/email_verification_codes'
        . '?select=user_id,code,expires_at'
        . '&user_id=eq.' . rawurlencode($userId)
        . '&order=last_sent_at.desc'
        . '&limit=1';
    $codeRes = supabase_request('GET', $codeUrl, $headers);
}
if (!($codeRes['ok'] ?? false)) {
    json_response(['ok' => false, 'error' => 'Failed to look up verification code.'], 500);
}
$codeRows = json_decode((string) $codeRes['body'], true);
$row = is_array($codeRows) && isset($codeRows[0]) && is_array($codeRows[0]) ? $codeRows[0] : null;
if (!$row) {
    json_response([
        'ok' => false,
        'error' => $purpose === 'signup'
            ? 'No signup verification code found. Please request a new code.'
            : 'No login verification code found. Please request a new code.',
    ], 400);
}

$storedCode = trim((string) ($row['code'] ?? ''));
$expiresAtRaw = (string) ($row['expires_at'] ?? '');
try {
    $expiresAt = new DateTimeImmutable($expiresAtRaw);
} catch (Throwable $e) {
    $expiresAt = new DateTimeImmutable('1970-01-01T00:00:00Z');
}
$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

if ($storedCode === '' || !hash_equals($storedCode, $code)) {
    json_response(['ok' => false, 'error' => 'Invalid verification code.'], 400);
}
if ($now > $expiresAt) {
    json_response(['ok' => false, 'error' => 'Verification code expired. Please request a new one.'], 400);
}

$updateHeaders = [
    'Content-Type: application/json',
    'Accept: application/json',
    'apikey: ' . SUPABASE_KEY,
    'Authorization: Bearer ' . SUPABASE_KEY,
    'Prefer: return=representation',
];

$userPatch = [
    'email_verified' => true,
    'email_verified_at' => $now->format(DATE_ATOM),
    'updated_at' => $now->format(DATE_ATOM),
];

$statusUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/' . SUPABASE_TABLE_USERS
    . '?select=account_status&id=eq.' . rawurlencode($userId) . '&limit=1';
$statusRes = supabase_request('GET', $statusUrl, $headers);
$statusRows = json_decode((string) ($statusRes['body'] ?? ''), true);
$currentStatus = '';
if (is_array($statusRows) && isset($statusRows[0]['account_status'])) {
    $currentStatus = strtolower(trim((string) $statusRows[0]['account_status']));
}
if ($currentStatus === 'preverify' || $currentStatus === '') {
    // Roster-claimed students are school-vouched — approve after email OTP.
    // Legacy self-register (no roster link) stays pending for Manage Application.
    $rosterUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/student_roster'
        . '?select=id&user_id=eq.' . rawurlencode($userId) . '&limit=1';
    $rosterRes = supabase_request('GET', $rosterUrl, $headers);
    $rosterRows = json_decode((string) ($rosterRes['body'] ?? ''), true);
    $isRosterClaim = is_array($rosterRows) && isset($rosterRows[0]);
    $userPatch['account_status'] = $isRosterClaim ? 'approved' : 'pending';
}
$userUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/' . SUPABASE_TABLE_USERS
    . '?id=eq.' . rawurlencode($userId)
    . '&select=' . rawurlencode(mobile_user_public_fields());
$userRes = supabase_request('PATCH', $userUrl, $updateHeaders, json_encode($userPatch, JSON_UNESCAPED_SLASHES));
if (!$userRes['ok']) {
    json_response(['ok' => false, 'error' => 'Failed to update verification status.'], 500);
}

// Delete only this purpose's code so signup/login stay independent.
$deleteUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/email_verification_codes'
    . '?user_id=eq.' . rawurlencode($userId);
if ($purposeFilterOk) {
    $deleteUrl .= '&purpose=eq.' . rawurlencode($purpose);
}
supabase_request('DELETE', $deleteUrl, $headers);

$userRows = json_decode((string) $userRes['body'], true);
$user = is_array($userRows) && isset($userRows[0]) && is_array($userRows[0])
    ? mobile_user_strip_secrets($userRows[0])
    : null;

// After a successful OTP, trust this phone install so signup → login does not
// demand a second code on the same device.
$trustedDevice = false;
if ($deviceKey !== '') {
    $isIpKey = str_starts_with($deviceKey, 'ip:');
    $isInstallKey = str_starts_with($deviceKey, 'install:');
    if ($isIpKey || $isInstallKey) {
        $validInstall = true;
        if ($isInstallKey) {
            $installId = substr($deviceKey, strlen('install:'));
            $validInstall = $installId !== '' && (bool) preg_match('/^[a-f0-9-]{16,80}$/', $installId);
        }
        if ($validInstall) {
            $upsert = device_trust_upsert(
                $userId,
                $deviceKey,
                $platform !== '' ? $platform : 'android',
                $deviceLabel !== '' ? $deviceLabel : $deviceKey
            );
            $trustedDevice = (($upsert['ok'] ?? false) === true)
                || (($upsert['missing_table'] ?? false) === true);
        }
    }
}

json_response([
    'ok' => true,
    'message' => 'Email verified.',
    'purpose' => $purpose,
    'user' => $user,
    'device_trusted' => $trustedDevice,
], 200);
