<?php
declare(strict_types=1);

require_once __DIR__ . '/supabase.php';

function device_trust_headers(): array
{
    return [
        'Accept: application/json',
        'apikey: ' . SUPABASE_KEY,
        'Authorization: Bearer ' . SUPABASE_KEY,
    ];
}

function device_trust_write_headers(): array
{
    return [
        'Content-Type: application/json',
        'Accept: application/json',
        'apikey: ' . SUPABASE_KEY,
        'Authorization: Bearer ' . SUPABASE_KEY,
        'Prefer: resolution=merge-duplicates,return=representation',
    ];
}

/**
 * Client public IP used as the trust key (shared across browsers on the same network).
 *
 * Hostinger/LiteSpeed often sets both REMOTE_ADDR and X-Forwarded-For; preferring an
 * unstable XFF chain caused mid-day "reverify" false positives. Prefer the edge
 * connection IP first, then CF, then the left-most public XFF hop.
 */
function device_trust_client_ip(): string
{
    $candidates = [];

    $candidates[] = trim((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
    $candidates[] = trim((string) ($_SERVER['HTTP_CF_CONNECTING_IP'] ?? ''));

    $forwarded = trim((string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''));
    if ($forwarded !== '') {
        foreach (explode(',', $forwarded) as $part) {
            $candidates[] = trim($part);
        }
    }
    $candidates[] = trim((string) ($_SERVER['HTTP_X_REAL_IP'] ?? ''));

    $public = '';
    $any = '';
    foreach ($candidates as $raw) {
        if ($raw === '') {
            continue;
        }
        // Strip optional port (e.g. IPv4:port).
        if (preg_match('/^(\d{1,3}(?:\.\d{1,3}){3}):\d+$/', $raw, $m)) {
            $raw = $m[1];
        }
        // [IPv6]:port
        if (preg_match('/^\[([^\]]+)\]:\d+$/', $raw, $m)) {
            $raw = $m[1];
        }
        if (!filter_var($raw, FILTER_VALIDATE_IP)) {
            continue;
        }
        $raw = strtolower($raw);
        if ($any === '') {
            $any = $raw;
        }
        if (filter_var($raw, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            $public = $raw;
            break;
        }
    }

    return $public !== '' ? $public : $any;
}

/**
 * Normalize an IP into the trusted_devices.device_key value.
 * IPv6 uses /64 so privacy-address rotations on the same network do not force re-OTP.
 */
function device_trust_ip_key(?string $ip = null): string
{
    $ip = strtolower(trim((string) ($ip ?? device_trust_client_ip())));
    if ($ip === '' || !filter_var($ip, FILTER_VALIDATE_IP)) {
        return '';
    }

    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
        $packed = @inet_pton($ip);
        if (is_string($packed) && strlen($packed) === 16) {
            $prefix = substr($packed, 0, 8) . str_repeat("\0", 8);
            $normalized = @inet_ntop($prefix);
            if (is_string($normalized) && $normalized !== '') {
                return 'ip6:' . strtolower($normalized) . '/64';
            }
        }
        return 'ip6:' . $ip;
    }

    return 'ip:' . $ip;
}

/**
 * @deprecated Browser cookie keys are no longer used; trust is IP-based.
 */
function device_trust_ensure_web_key(): string
{
    return device_trust_ip_key();
}

/**
 * Tri-state trust lookup for session revalidation.
 *
 * @return bool|null true = trusted, false = not trusted, null = lookup failed (do not force logout)
 */
function device_trust_status(string $userId, string $deviceKey = ''): ?bool
{
    $userId = trim($userId);
    $deviceKey = strtolower(trim($deviceKey !== '' ? $deviceKey : device_trust_ip_key()));
    if ($userId === '' || $deviceKey === '') {
        return false;
    }

    $res = supabase_request(
        'GET',
        rtrim(SUPABASE_URL, '/') . '/rest/v1/trusted_devices'
            . '?select=id'
            . '&user_id=eq.' . rawurlencode($userId)
            . '&device_key=eq.' . rawurlencode($deviceKey)
            . '&limit=1',
        device_trust_headers()
    );

    if (!$res['ok']) {
        return null;
    }

    $rows = json_decode((string) ($res['body'] ?? ''), true);
    return is_array($rows) && isset($rows[0]) && is_array($rows[0]);
}

function device_trust_is_trusted(string $userId, string $deviceKey = ''): bool
{
    // Login OTP gates stay fail-closed: unknown/error ⇒ not trusted.
    return device_trust_status($userId, $deviceKey) === true;
}

function device_trust_clip(string $value, int $maxLen): string
{
    if ($maxLen < 1) {
        return '';
    }
    if (function_exists('mb_substr')) {
        return (string) mb_substr($value, 0, $maxLen);
    }
    return substr($value, 0, $maxLen);
}

/**
 * @return array{ok:bool,error?:string,missing_table?:bool}
 */
function device_trust_upsert(
    string $userId,
    string $deviceKey = '',
    string $platform = 'web',
    string $label = ''
): array {
    $userId = trim($userId);
    $deviceKey = strtolower(trim($deviceKey !== '' ? $deviceKey : device_trust_ip_key()));
    if ($userId === '' || $deviceKey === '') {
        return ['ok' => false, 'error' => 'Missing user or device key.'];
    }

    $now = gmdate('c');
    $payload = [
        'user_id' => $userId,
        'device_key' => device_trust_clip($deviceKey, 120),
        'platform' => device_trust_clip($platform !== '' ? $platform : 'web', 40),
        'label' => $label !== '' ? device_trust_clip($label, 120) : null,
        'last_seen_at' => $now,
        'trusted_at' => $now,
    ];

    $body = json_encode($payload, JSON_UNESCAPED_SLASHES);
    if (!is_string($body)) {
        return ['ok' => false, 'error' => 'Failed to encode trust payload.'];
    }

    $res = supabase_request(
        'POST',
        rtrim(SUPABASE_URL, '/') . '/rest/v1/trusted_devices?on_conflict=user_id,device_key',
        device_trust_write_headers(),
        $body
    );

    if (($res['ok'] ?? false) === true) {
        return ['ok' => true];
    }

    $message = strtolower((string) ($res['body'] ?? '') . ' ' . (string) ($res['error'] ?? ''));
    $missingTable = str_contains($message, 'trusted_devices')
        && (
            str_contains($message, 'does not exist')
            || str_contains($message, 'schema cache')
            || str_contains($message, 'could not find')
        );

    return [
        'ok' => false,
        'missing_table' => $missingTable,
        'error' => $missingTable
            ? 'Run supabase/migrations/043_trusted_devices.sql in Supabase SQL Editor.'
            : ('Trust upsert failed' . (
                trim((string) ($res['status'] ?? '')) !== ''
                    ? (' (HTTP ' . (string) $res['status'] . ')')
                    : ''
            ) . '.'),
    ];
}

function device_trust_touch(string $userId, string $deviceKey = ''): void
{
    $userId = trim($userId);
    $deviceKey = strtolower(trim($deviceKey !== '' ? $deviceKey : device_trust_ip_key()));
    if ($userId === '' || $deviceKey === '') {
        return;
    }

    supabase_request(
        'PATCH',
        rtrim(SUPABASE_URL, '/') . '/rest/v1/trusted_devices'
            . '?user_id=eq.' . rawurlencode($userId)
            . '&device_key=eq.' . rawurlencode($deviceKey),
        [
            'Content-Type: application/json',
            'Accept: application/json',
            'apikey: ' . SUPABASE_KEY,
            'Authorization: Bearer ' . SUPABASE_KEY,
            'Prefer: return=minimal',
        ],
        json_encode(['last_seen_at' => gmdate('c')], JSON_UNESCAPED_SLASHES)
    );
}
