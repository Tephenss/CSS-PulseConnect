<?php
declare(strict_types=1);

/**
 * Logged-in web password change via email OTP.
 * PHP session + CSRF required. Never trust client email/user_id alone.
 */
require_once __DIR__ . '/../includes/session.php';
session_bootstrap();

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/supabase.php';
require_once __DIR__ . '/../includes/json.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/email_notifications.php';
require_once __DIR__ . '/../includes/api_rate_limit.php';

$data = require_post_json();
require_csrf_from_json($data);

$userId = isset($_SESSION['user']['id']) ? (string) $_SESSION['user']['id'] : '';
if ($userId === '') {
    json_response(['ok' => false, 'error' => 'Unauthorized. Please login.'], 401);
}

$action = strtolower(trim((string) ($data['action'] ?? 'update')));
if ($action === '') {
    $action = 'update';
}

$clientIp = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
$email = strtolower(trim((string) ($_SESSION['user']['email'] ?? '')));

$headers = [
    'Accept: application/json',
    'apikey: ' . SUPABASE_KEY,
    'Authorization: Bearer ' . SUPABASE_KEY,
];
$writeHeaders = [
    'Content-Type: application/json',
    'Accept: application/json',
    'apikey: ' . SUPABASE_KEY,
    'Authorization: Bearer ' . SUPABASE_KEY,
    'Prefer: resolution=merge-duplicates,return=minimal',
];

if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $lookupUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/' . SUPABASE_TABLE_USERS
        . '?select=id,email,first_name,last_name'
        . '&id=eq.' . rawurlencode($userId)
        . '&limit=1';
    $lookupRes = supabase_request('GET', $lookupUrl, $headers);
    $rows = $lookupRes['ok'] ? json_decode((string) ($lookupRes['body'] ?? ''), true) : null;
    $row = is_array($rows) && isset($rows[0]) && is_array($rows[0]) ? $rows[0] : null;
    $email = strtolower(trim((string) ($row['email'] ?? '')));
}

if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    json_response(['ok' => false, 'error' => 'Account email is required to change password.'], 400);
}

function web_change_password_mask_email(string $email): string
{
    $parts = explode('@', $email, 2);
    if (count($parts) !== 2) {
        return '***';
    }
    $local = $parts[0];
    $domain = $parts[1];
    $keep = min(2, max(1, (int) floor(mb_strlen($local) / 3)));
    return mb_substr($local, 0, $keep) . '***@' . $domain;
}

if ($action === 'send_otp') {
    if (!api_rate_limit_allow('web_change_pw_send:' . $userId . ':' . $clientIp, 6, 300)) {
        json_response(['ok' => false, 'error' => 'Too many code requests. Please wait.'], 429);
    }

    $resendCooldownSeconds = 60;
    $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $existingUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/password_reset_codes'
        . '?select=updated_at,expires_at'
        . '&user_id=eq.' . rawurlencode($userId)
        . '&limit=1';
    $existingRes = supabase_request('GET', $existingUrl, $headers);
    if ($existingRes['ok']) {
        $existingRows = json_decode((string) ($existingRes['body'] ?? ''), true);
        $existing = is_array($existingRows) && isset($existingRows[0]) && is_array($existingRows[0])
            ? $existingRows[0]
            : null;
        $lastSentRaw = is_array($existing) ? trim((string) ($existing['updated_at'] ?? '')) : '';
        $lastSentAt = false;
        if ($lastSentRaw !== '') {
            try {
                $lastSentAt = (new DateTimeImmutable($lastSentRaw))->setTimezone(new DateTimeZone('UTC'));
            } catch (Throwable $e) {
                $lastSentAt = false;
            }
        }
        if ($lastSentAt instanceof DateTimeImmutable) {
            $elapsed = $now->getTimestamp() - $lastSentAt->getTimestamp();
            if ($elapsed >= 0 && $elapsed < $resendCooldownSeconds) {
                $remaining = $resendCooldownSeconds - $elapsed;
                json_response([
                    'ok' => true,
                    'success' => true,
                    'skipped' => true,
                    'cooldown_seconds' => $remaining,
                    'email_masked' => web_change_password_mask_email($email),
                    'message' => 'Verification code already sent. Please check your email.',
                    'expires_at' => (string) ($existing['expires_at'] ?? ''),
                ], 200);
            }
        }
    }

    $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $expiresAt = $now->add(new DateInterval('PT10M'));
    $payload = [
        'user_id' => $userId,
        'code' => $code,
        'expires_at' => $expiresAt->format(DATE_ATOM),
        'verified_at' => null,
        'reset_token' => null,
        'token_expires_at' => null,
        'updated_at' => $now->format(DATE_ATOM),
    ];
    $saveRes = supabase_request(
        'POST',
        rtrim(SUPABASE_URL, '/') . '/rest/v1/password_reset_codes',
        $writeHeaders,
        json_encode($payload, JSON_UNESCAPED_SLASHES)
    );
    if (!($saveRes['ok'] ?? false)) {
        json_response([
            'ok' => false,
            'error' => build_error($saveRes['body'] ?? null, (int) ($saveRes['status'] ?? 0), $saveRes['error'] ?? null, 'Failed to generate verification code.'),
        ], 500);
    }

    $fullName = trim((string) ($_SESSION['user']['full_name'] ?? ''));
    $sent = send_password_reset_code_email($email, $fullName !== '' ? $fullName : 'User', $code);
    if (!$sent) {
        $smtpDebug = function_exists('smtp_get_last_error') ? smtp_get_last_error() : '';
        json_response([
            'ok' => false,
            'error' => $smtpDebug !== ''
                ? 'Unable to send verification email: ' . $smtpDebug
                : 'Unable to send verification email. Please try again.',
        ], 500);
    }

    json_response([
        'ok' => true,
        'success' => true,
        'message' => 'Verification code sent to your email.',
        'email_masked' => web_change_password_mask_email($email),
        'expires_at' => $expiresAt->format(DATE_ATOM),
        'cooldown_seconds' => $resendCooldownSeconds,
    ], 200);
}

if ($action === 'verify_otp') {
    if (!api_rate_limit_allow('web_change_pw_verify:' . $userId . ':' . $clientIp, 12, 300)) {
        json_response(['ok' => false, 'error' => 'Too many verification attempts. Please wait.'], 429);
    }

    $code = trim((string) ($data['code'] ?? ''));
    if (!preg_match('/^\d{6}$/', $code)) {
        json_response(['ok' => false, 'error' => 'Verification code must be 6 digits.'], 400);
    }

    $codeUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/password_reset_codes'
        . '?select=user_id,code,expires_at'
        . '&user_id=eq.' . rawurlencode($userId)
        . '&limit=1';
    $codeRes = supabase_request('GET', $codeUrl, $headers);
    if (!($codeRes['ok'] ?? false)) {
        json_response(['ok' => false, 'error' => 'Failed to verify code.'], 500);
    }
    $codeRows = json_decode((string) ($codeRes['body'] ?? ''), true);
    $row = is_array($codeRows) && isset($codeRows[0]) && is_array($codeRows[0]) ? $codeRows[0] : null;
    if (!$row) {
        json_response(['ok' => false, 'error' => 'No verification code found. Please request a new code.'], 400);
    }

    $storedCode = trim((string) ($row['code'] ?? ''));
    try {
        $expiresAt = new DateTimeImmutable((string) ($row['expires_at'] ?? ''));
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

    $changeToken = bin2hex(random_bytes(24));
    $tokenExpiry = $now->add(new DateInterval('PT15M'));
    $updateRes = supabase_request(
        'PATCH',
        rtrim(SUPABASE_URL, '/') . '/rest/v1/password_reset_codes?user_id=eq.' . rawurlencode($userId),
        $writeHeaders,
        json_encode([
            'verified_at' => $now->format(DATE_ATOM),
            'reset_token' => $changeToken,
            'token_expires_at' => $tokenExpiry->format(DATE_ATOM),
            'updated_at' => $now->format(DATE_ATOM),
        ], JSON_UNESCAPED_SLASHES)
    );
    if (!($updateRes['ok'] ?? false)) {
        json_response(['ok' => false, 'error' => 'Failed to verify code.'], 500);
    }

    json_response([
        'ok' => true,
        'success' => true,
        'change_token' => $changeToken,
        'message' => 'Code verified. You can now set a new password.',
    ], 200);
}

if ($action !== 'update') {
    json_response(['ok' => false, 'error' => 'Invalid action.'], 400);
}

if (!api_rate_limit_allow('web_change_password:' . $userId . ':' . $clientIp, 8, 300)) {
    json_response(['ok' => false, 'error' => 'Too many password change attempts. Please wait.'], 429);
}

$changeToken = trim((string) ($data['change_token'] ?? $data['reset_token'] ?? ''));
$newPassword = (string) ($data['new_password'] ?? '');
if ($changeToken === '' || $newPassword === '') {
    json_response(['ok' => false, 'error' => 'Missing verification token or new password.'], 400);
}
if (!is_strong_password($newPassword)) {
    json_response(['ok' => false, 'error' => password_policy_error()], 400);
}

$codeUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/password_reset_codes'
    . '?select=user_id,reset_token,token_expires_at'
    . '&user_id=eq.' . rawurlencode($userId)
    . '&limit=1';
$codeRes = supabase_request('GET', $codeUrl, $headers);
if (!($codeRes['ok'] ?? false)) {
    json_response(['ok' => false, 'error' => 'Failed to validate verification.'], 500);
}
$rows = json_decode((string) ($codeRes['body'] ?? ''), true);
$row = is_array($rows) && isset($rows[0]) && is_array($rows[0]) ? $rows[0] : null;
if (!$row) {
    json_response(['ok' => false, 'error' => 'Verification expired. Please request a new code.'], 400);
}

$storedToken = trim((string) ($row['reset_token'] ?? ''));
try {
    $tokenExpires = new DateTimeImmutable((string) ($row['token_expires_at'] ?? ''));
} catch (Throwable $e) {
    $tokenExpires = new DateTimeImmutable('1970-01-01T00:00:00Z');
}
$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

if ($storedToken === '' || !hash_equals($storedToken, $changeToken)) {
    json_response(['ok' => false, 'error' => 'Invalid verification. Please verify the code again.'], 400);
}
if ($now > $tokenExpires) {
    json_response(['ok' => false, 'error' => 'Verification expired. Please verify the code again.'], 400);
}

$newHash = password_hash($newPassword, PASSWORD_DEFAULT);
$updateResult = supabase_request(
    'PATCH',
    rtrim(SUPABASE_URL, '/') . '/rest/v1/users?id=eq.' . rawurlencode($userId),
    $writeHeaders,
    json_encode([
        'password' => $newHash,
        'updated_at' => $now->format(DATE_ATOM),
    ], JSON_UNESCAPED_SLASHES)
);
if (!($updateResult['ok'] ?? false)) {
    json_response([
        'ok' => false,
        'error' => build_error($updateResult['body'] ?? null, (int) ($updateResult['status'] ?? 0), $updateResult['error'] ?? null, 'Failed to update database.'),
    ], 500);
}

supabase_request(
    'DELETE',
    rtrim(SUPABASE_URL, '/') . '/rest/v1/password_reset_codes?user_id=eq.' . rawurlencode($userId),
    $headers
);

json_response(['ok' => true, 'success' => true, 'message' => 'Password changed successfully.'], 200);
