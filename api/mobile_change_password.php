<?php
declare(strict_types=1);

/**
 * Logged-in mobile password change via email OTP.
 * Session required. Never trust client user_id/email alone. Never expose codes.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/supabase.php';
require_once __DIR__ . '/../includes/json.php';
require_once __DIR__ . '/../includes/email_notifications.php';
require_once __DIR__ . '/../includes/mobile_api.php';
require_once __DIR__ . '/../includes/mobile_session.php';
require_once __DIR__ . '/../includes/api_rate_limit.php';

$data = mobile_api_require_post_json();
mobile_api_validate_key($data);
$sessionUser = mobile_api_require_user($data);
$userId = (string) ($sessionUser['id'] ?? '');
$email = strtolower(trim((string) ($sessionUser['email'] ?? '')));
$action = strtolower(trim((string) ($data['action'] ?? 'update')));
if ($action === '') {
    $action = 'update';
}

$clientIp = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');

if ($userId === '' || $email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    json_response(['ok' => false, 'error' => 'Account email is required to change password.'], 400);
}

function mobile_change_password_mask_email(string $email): string
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
    if (!api_rate_limit_allow('mobile_change_pw_send:' . $userId . ':' . $clientIp, 6, 300)) {
        json_response(['ok' => false, 'error' => 'Too many code requests. Please wait.'], 429);
    }

    $resendCooldownSeconds = 60;
    $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $headers = mobile_api_supabase_headers();
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
                    'skipped' => true,
                    'cooldown_seconds' => $remaining,
                    'email_masked' => mobile_change_password_mask_email($email),
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
        mobile_api_supabase_write_headers(),
        json_encode($payload, JSON_UNESCAPED_SLASHES)
    );
    if (!($saveRes['ok'] ?? false)) {
        json_response(['ok' => false, 'error' => 'Failed to generate verification code.'], 500);
    }

    $fullName = build_display_name(
        (string) ($sessionUser['first_name'] ?? ''),
        (string) ($sessionUser['middle_name'] ?? ''),
        (string) ($sessionUser['last_name'] ?? ''),
        (string) ($sessionUser['suffix'] ?? '')
    );
    $sent = send_password_reset_code_email($email, $fullName, $code);
    if (!$sent) {
        $smtpDebug = smtp_get_last_error();
        json_response([
            'ok' => false,
            'error' => $smtpDebug !== ''
                ? 'Unable to send verification email: ' . $smtpDebug
                : 'Unable to send verification email. Please try again.',
        ], 500);
    }

    json_response([
        'ok' => true,
        'message' => 'Verification code sent to your email.',
        'email_masked' => mobile_change_password_mask_email($email),
        'expires_at' => $expiresAt->format(DATE_ATOM),
        'cooldown_seconds' => $resendCooldownSeconds,
    ], 200);
}

if ($action === 'verify_otp') {
    if (!api_rate_limit_allow('mobile_change_pw_verify:' . $userId . ':' . $clientIp, 12, 300)) {
        json_response(['ok' => false, 'error' => 'Too many verification attempts. Please wait.'], 429);
    }

    $code = trim((string) ($data['code'] ?? ''));
    if (!preg_match('/^\d{6}$/', $code)) {
        json_response(['ok' => false, 'error' => 'Verification code must be 6 digits.'], 400);
    }

    $headers = mobile_api_supabase_headers();
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
        mobile_api_supabase_write_headers(),
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
        'change_token' => $changeToken,
        'message' => 'Code verified. You can now set a new password.',
    ], 200);
}

if ($action !== 'update') {
    json_response(['ok' => false, 'error' => 'Invalid action.'], 400);
}

if (!api_rate_limit_allow('mobile_change_password:' . $userId . ':' . $clientIp, 8, 300)) {
    json_response(['ok' => false, 'error' => 'Too many password change attempts. Please wait.'], 429);
}

$changeToken = trim((string) ($data['change_token'] ?? $data['reset_token'] ?? ''));
$newPassword = (string) ($data['new_password'] ?? '');
if ($changeToken === '' || $newPassword === '') {
    json_response(['ok' => false, 'error' => 'Missing verification token or new password.'], 400);
}
if (mb_strlen($newPassword) < 8) {
    json_response(['ok' => false, 'error' => 'Password must be at least 8 characters.'], 400);
}

$headers = mobile_api_supabase_headers();
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
    mobile_api_supabase_write_headers(),
    json_encode([
        'password' => $newHash,
        'updated_at' => $now->format(DATE_ATOM),
    ], JSON_UNESCAPED_SLASHES)
);
if (!($updateResult['ok'] ?? false)) {
    json_response(['ok' => false, 'error' => 'Failed to update password.'], 500);
}

supabase_request(
    'DELETE',
    rtrim(SUPABASE_URL, '/') . '/rest/v1/password_reset_codes?user_id=eq.' . rawurlencode($userId),
    $headers
);

json_response(['ok' => true, 'message' => 'Password changed successfully.'], 200);
