<?php
declare(strict_types=1);

require_once __DIR__ . '/mobile_api.php';
require_once __DIR__ . '/supabase.php';
require_once __DIR__ . '/api_cache.php';

const MOBILE_SESSION_TTL_DAYS = 30;

/** Skip last_seen PATCH if the session was touched within this window. */
const MOBILE_SESSION_LAST_SEEN_MIN_SECONDS = 300;

/** Reuse session+user lookup so scanner/BFF bursts do not GET mobile_sessions each time. */
const MOBILE_SESSION_LOOKUP_CACHE_SECONDS = 45;

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
    $cacheKey = 'mobile_session:' . $tokenHash;
    $cached = api_cache_read($cacheKey, MOBILE_SESSION_LOOKUP_CACHE_SECONDS);
    if (is_array($cached) && trim((string) ($cached['id'] ?? '')) !== '') {
        $expiresRaw = (string) ($cached['expires_at'] ?? '');
        try {
            $expires = new DateTimeImmutable($expiresRaw);
            $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
            if ($now <= $expires && empty($cached['revoked_at'])) {
                return $cached;
            }
        } catch (Throwable $e) {
            api_cache_delete($cacheKey);
        }
    }

    $userEmbed = 'users(' . mobile_user_public_fields() . ')';
    $selectWithUser = 'id,user_id,expires_at,revoked_at,last_seen_at,' . $userEmbed;
    $selectPlain = 'id,user_id,expires_at,revoked_at,last_seen_at';
    $urlWithUser = rtrim(SUPABASE_URL, '/') . '/rest/v1/mobile_sessions'
        . '?select=' . rawurlencode($selectWithUser)
        . '&token_hash=eq.' . rawurlencode($tokenHash)
        . '&limit=1';

    $res = supabase_request('GET', $urlWithUser, mobile_api_supabase_headers());
    if (!$res['ok']) {
        $urlPlain = rtrim(SUPABASE_URL, '/') . '/rest/v1/mobile_sessions'
            . '?select=' . rawurlencode($selectPlain)
            . '&token_hash=eq.' . rawurlencode($tokenHash)
            . '&limit=1';
        $res = supabase_request('GET', $urlPlain, mobile_api_supabase_headers());
        if (!$res['ok']) {
            return null;
        }
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

    $embedded = $row['users'] ?? null;
    unset($row['users']);
    if (is_array($embedded)) {
        if (isset($embedded[0]) && is_array($embedded[0])) {
            $embedded = $embedded[0];
        }
        if (isset($embedded['id']) && is_string($embedded['id']) && $embedded['id'] !== '') {
            $row['_user'] = mobile_user_strip_secrets($embedded);
        }
    }

    $sessionId = trim((string) ($row['id'] ?? ''));
    $shouldTouch = true;
    $lastSeenRaw = trim((string) ($row['last_seen_at'] ?? ''));
    if ($lastSeenRaw !== '') {
        try {
            $lastSeen = new DateTimeImmutable($lastSeenRaw);
            $age = $now->getTimestamp() - $lastSeen->getTimestamp();
            if ($age >= 0 && $age < MOBILE_SESSION_LAST_SEEN_MIN_SECONDS) {
                $shouldTouch = false;
            }
        } catch (Throwable $e) {
            $shouldTouch = true;
        }
    }

    if ($shouldTouch && $sessionId !== '') {
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
        $row['last_seen_at'] = $now->format(DATE_ATOM);
    }

    $store = $row;
    unset($store['password'], $store['password_hash']);
    api_cache_write($cacheKey, $store);

    return $row;
}

function mobile_session_revoke(string $token): bool
{
    $token = trim($token);
    if ($token === '') {
        return false;
    }

    $tokenHash = mobile_session_hash_token($token);
    api_cache_delete('mobile_session:' . $tokenHash);
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
    // Do NOT treat student_id as identity here — teachers send student_id as
    // the assignee (assistant_assign). Check student_id only for student role.
    $claimedUserId = trim((string) ($data['user_id'] ?? ''));
    if ($claimedUserId !== '' && !hash_equals($userId, $claimedUserId)) {
        json_response(['ok' => false, 'error' => 'user_id does not match mobile session.'], 403);
    }

    $user = null;
    if (isset($session['_user']) && is_array($session['_user'])) {
        $embeddedId = trim((string) ($session['_user']['id'] ?? ''));
        if ($embeddedId !== '' && hash_equals($userId, $embeddedId)) {
            $user = $session['_user'];
        }
    }
    if ($user === null) {
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
    }
    if ($user === null) {
        json_response(['ok' => false, 'error' => 'User not found for session.'], 401);
    }

    $role = strtolower(trim((string) ($user['role'] ?? '')));
    $claimedStudentId = trim((string) ($data['student_id'] ?? ''));
    if (
        $role === 'student'
        && $claimedStudentId !== ''
        && !hash_equals($userId, $claimedStudentId)
    ) {
        json_response(['ok' => false, 'error' => 'user_id does not match mobile session.'], 403);
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
