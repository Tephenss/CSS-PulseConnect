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
 */
function device_trust_client_ip(): string
{
    $candidates = [];

    $forwarded = trim((string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''));
    if ($forwarded !== '') {
        foreach (explode(',', $forwarded) as $part) {
            $candidates[] = trim($part);
        }
    }

    $candidates[] = trim((string) ($_SERVER['HTTP_CF_CONNECTING_IP'] ?? ''));
    $candidates[] = trim((string) ($_SERVER['REMOTE_ADDR'] ?? ''));

    foreach ($candidates as $raw) {
        if ($raw === '') {
            continue;
        }
        // Strip optional port (e.g. IPv4:port).
        if (preg_match('/^(\d{1,3}(?:\.\d{1,3}){3}):\d+$/', $raw, $m)) {
            $raw = $m[1];
        }
        if (filter_var($raw, FILTER_VALIDATE_IP)) {
            return strtolower($raw);
        }
    }

    return '';
}

/**
 * Normalize an IP into the trusted_devices.device_key value.
 */
function device_trust_ip_key(?string $ip = null): string
{
    $ip = strtolower(trim((string) ($ip ?? device_trust_client_ip())));
    if ($ip === '' || !filter_var($ip, FILTER_VALIDATE_IP)) {
        return '';
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

function device_trust_is_trusted(string $userId, string $deviceKey = ''): bool
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
        // Fail closed: if table missing / error, treat as untrusted so OTP runs.
        return false;
    }

    $rows = json_decode((string) ($res['body'] ?? ''), true);
    return is_array($rows) && isset($rows[0]) && is_array($rows[0]);
}

function device_trust_upsert(
    string $userId,
    string $deviceKey = '',
    string $platform = 'web',
    string $label = ''
): void {
    $userId = trim($userId);
    $deviceKey = strtolower(trim($deviceKey !== '' ? $deviceKey : device_trust_ip_key()));
    if ($userId === '' || $deviceKey === '') {
        return;
    }

    $now = gmdate('c');
    $payload = [
        'user_id' => $userId,
        'device_key' => $deviceKey,
        'platform' => $platform !== '' ? mb_substr($platform, 0, 40) : 'web',
        'label' => $label !== '' ? mb_substr($label, 0, 120) : null,
        'last_seen_at' => $now,
        'trusted_at' => $now,
    ];

    supabase_request(
        'POST',
        rtrim(SUPABASE_URL, '/') . '/rest/v1/trusted_devices?on_conflict=user_id,device_key',
        device_trust_write_headers(),
        json_encode($payload, JSON_UNESCAPED_SLASHES)
    );
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
