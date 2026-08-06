<?php
declare(strict_types=1);

require_once __DIR__ . '/mobile_api.php';
require_once __DIR__ . '/supabase.php';

const MOBILE_SESSION_TTL_DAYS = 30;

function mobile_session_hash_token(string $token): string
{
    return hash('sha256', $token);
}

function mobile_session_generate_token(): string
{
    return bin2hex(random_bytes(32));
}

/**
 * Columns safe to return to mobile clients (never include password).
 */
function mobile_user_public_fields(): string
{
    return 'id,first_name,middle_name,last_name,suffix,email,role,section_id,course,student_id,'
        . 'photo_url,email_verified,email_verified_at,account_status,approval_note,'
        . 'registration_source,created_at,updated_at';
}

/**
 * @param array<string,mixed> $user
 * @return array<string,mixed>
 */
function mobile_user_strip_secrets(array $user): array
{
    unset($user['password'], $user['password_hash']);
    return $user;
}

function mobile_password_verify(string $password, string $storedHash): bool
{
    $storedHash = trim($storedHash);
    if ($storedHash === '') {
        return false;
    }

    if (str_starts_with($storedHash, '$2y$')
        || str_starts_with($storedHash, '$2b$')
        || str_starts_with($storedHash, '$2a$')
    ) {
        return password_verify($password, $storedHash);
    }

    // Legacy mobile unsalted SHA-256
    return hash_equals($storedHash, hash('sha256', $password));
}

/**
 * @return array{token:string,expires_at:string}|null
 */
function mobile_session_create(string $userId, ?string $platform = null, ?string $deviceLabel = null): ?array
{
    $userId = trim($userId);
    if ($userId === '') {
        return null;
    }

    $token = mobile_session_generate_token();
    $tokenHash = mobile_session_hash_token($token);
    $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $expires = $now->add(new DateInterval('P' . MOBILE_SESSION_TTL_DAYS . 'D'));

    $payload = [
        'user_id' => $userId,
        'token_hash' => $tokenHash,
        'platform' => $platform,
        'device_label' => $deviceLabel,
        'expires_at' => $expires->format(DATE_ATOM),
        'last_seen_at' => $now->format(DATE_ATOM),
        'created_at' => $now->format(DATE_ATOM),
    ];

    $res = supabase_request(
        'POST',
        rtrim(SUPABASE_URL, '/') . '/rest/v1/mobile_sessions',
        mobile_api_supabase_write_headers(),
        json_encode([$payload], JSON_UNESCAPED_SLASHES)
    );

    if (!$res['ok']) {
        return null;
    }

    return [
        'token' => $token,
        'expires_at' => $expires->format(DATE_ATOM),
    ];
}

function mobile_session_extract_token(array $data = []): string
{
    if (isset($_SERVER['HTTP_AUTHORIZATION']) && is_string($_SERVER['HTTP_AUTHORIZATION'])) {
        $auth = trim($_SERVER['HTTP_AUTHORIZATION']);
        if (preg_match('/^Bearer\s+(.+)$/i', $auth, $m)) {
            return trim($m[1]);
        }
    }

    if (isset($_SERVER['HTTP_X_MOBILE_SESSION']) && is_string($_SERVER['HTTP_X_MOBILE_SESSION'])) {
        return trim($_SERVER['HTTP_X_MOBILE_SESSION']);
    }

    return trim((string) ($data['mobile_session'] ?? $data['session_token'] ?? ''));
}

/**
 * @return array<string,mixed>|null session row
 */
function mobile_session_lookup(string $token): ?array
{
    $token = trim($token);
    if ($token === '' || strlen($token) < 32) {
        return null;
    }

    $tokenHash = mobile_session_hash_token($token);
    $url = rtrim(SUPABASE_URL, '/') . '/rest/v1/mobile_sessions'
        . '?select=id,user_id,expires_at,revoked_at'
        . '&token_hash=eq.' . rawurlencode($tokenHash)
        . '&limit=1';

    $res = supabase_request('GET', $url, mobile_api_supabase_headers());
    if (!$res['ok']) {
        return null;
    }

    $rows = json_decode((string) $res['body'], true);
    if (!is_array($rows) || !isset($rows[0]) || !is_array($rows[0])) {
        return null;
    }

    $row = $rows[0];
    if (!empty($row['revoked_at'])) {
        return null;
    }

    $expiresRaw = (string) ($row['expires_at'] ?? '');
    try {
        $expires = new DateTimeImmutable($expiresRaw);
    } catch (Throwable $e) {
        return null;
    }

    $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    if ($now > $expires) {
        return null;
    }

    // Touch last_seen (best-effort).
    $sessionId = trim((string) ($row['id'] ?? ''));
    if ($sessionId !== '') {
        supabase_request(
            'PATCH',
            rtrim(SUPABASE_URL, '/') . '/rest/v1/mobile_sessions?id=eq.' . rawurlencode($sessionId),
            [
                'Content-Type: application/json',
                'Accept: application/json',
                'apikey: ' . SUPABASE_KEY,
                'Authorization: Bearer ' . SUPABASE_KEY,
                'Prefer: return=minimal',
            ],
            json_encode(['last_seen_at' => $now->format(DATE_ATOM)], JSON_UNESCAPED_SLASHES)
        );
    }

    return $row;
}

function mobile_session_revoke(string $token): bool
{
    $token = trim($token);
    if ($token === '') {
        return false;
    }

    $tokenHash = mobile_session_hash_token($token);
    $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DATE_ATOM);
    $res = supabase_request(
        'PATCH',
        rtrim(SUPABASE_URL, '/') . '/rest/v1/mobile_sessions?token_hash=eq.' . rawurlencode($tokenHash),
        [
            'Content-Type: application/json',
            'Accept: application/json',
            'apikey: ' . SUPABASE_KEY,
            'Authorization: Bearer ' . SUPABASE_KEY,
            'Prefer: return=minimal',
        ],
        json_encode(['revoked_at' => $now], JSON_UNESCAPED_SLASHES)
    );

    return (bool) ($res['ok'] ?? false);
}

/**
 * @return array<string,mixed> user row without password
 */
function mobile_api_require_user(array $data = []): array
{
    $token = mobile_session_extract_token($data);
    if ($token === '') {
        json_response(['ok' => false, 'error' => 'Mobile session required.'], 401);
    }

    $session = mobile_session_lookup($token);
    if ($session === null) {
        json_response(['ok' => false, 'error' => 'Invalid or expired mobile session.'], 401);
    }

    $userId = trim((string) ($session['user_id'] ?? ''));
    if ($userId === '') {
        json_response(['ok' => false, 'error' => 'Invalid mobile session.'], 401);
    }

    // Client-supplied user_id must match session (prevents IDOR).
    $claimed = trim((string) ($data['user_id'] ?? $data['student_id'] ?? ''));
    if ($claimed !== '' && !hash_equals($userId, $claimed)) {
        json_response(['ok' => false, 'error' => 'user_id does not match mobile session.'], 403);
    }

    $url = rtrim(SUPABASE_URL, '/') . '/rest/v1/' . SUPABASE_TABLE_USERS
        . '?select=' . rawurlencode(mobile_user_public_fields())
        . '&id=eq.' . rawurlencode($userId)
        . '&limit=1';
    $res = supabase_request('GET', $url, mobile_api_supabase_headers());
    if (!$res['ok']) {
        json_response(['ok' => false, 'error' => 'Failed to load user session.'], 500);
    }

    $rows = json_decode((string) $res['body'], true);
    $user = is_array($rows) && isset($rows[0]) && is_array($rows[0]) ? $rows[0] : null;
    if ($user === null) {
        json_response(['ok' => false, 'error' => 'User not found for session.'], 401);
    }

    $user['_session_token'] = $token;
    $user['_session_id'] = (string) ($session['id'] ?? '');
    return mobile_user_strip_secrets($user);
}

/**
 * Resolve session user id for multipart posts (uses $_POST).
 */
function mobile_api_require_user_from_post(): array
{
    return mobile_api_require_user($_POST);
}
