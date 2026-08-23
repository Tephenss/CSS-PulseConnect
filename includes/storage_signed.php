<?php
declare(strict_types=1);

/**
 * Extract a Storage object path from a public/signed URL, or pass through a raw path.
 */
function storage_object_path_from_url(string $urlOrPath, ?string $bucket = null): string
{
    $raw = trim($urlOrPath);
    if ($raw === '') {
        return '';
    }

    if (!str_contains($raw, '://')) {
        $path = ltrim(str_replace('\\', '/', $raw), '/');
        if ($bucket !== null && $bucket !== '' && str_starts_with($path, $bucket . '/')) {
            return substr($path, strlen($bucket) + 1);
        }
        return $path;
    }

    $uri = parse_url($raw);
    $path = (string) ($uri['path'] ?? '');
    $markers = [];
    if ($bucket !== null && $bucket !== '') {
        $markers[] = '/storage/v1/object/public/' . $bucket . '/';
        $markers[] = '/storage/v1/object/sign/' . $bucket . '/';
        $markers[] = '/storage/v1/object/' . $bucket . '/';
    } else {
        $markers = [
            '/storage/v1/object/public/',
            '/storage/v1/object/sign/',
            '/storage/v1/object/',
        ];
    }

    foreach ($markers as $marker) {
        $pos = strpos($path, $marker);
        if ($pos === false) {
            continue;
        }
        $rest = substr($path, $pos + strlen($marker));
        if ($bucket === null || $bucket === '') {
            // Strip first segment (bucket name) when marker is generic.
            $parts = explode('/', $rest, 2);
            if (count($parts) === 2 && in_array($parts[0], ['proposal-documents', 'student-documents', 'avatars'], true)) {
                return rawurldecode($parts[1]);
            }
        }
        return rawurldecode($rest);
    }

    return '';
}

/**
 * Create a short-lived signed URL for a private Storage object.
 */
function storage_create_signed_url(string $bucket, string $objectPath, int $expiresInSeconds = 3600): ?string
{
    $bucket = trim($bucket);
    $objectPath = ltrim(trim($objectPath), '/');
    if ($bucket === '' || $objectPath === '' || !defined('SUPABASE_URL') || !defined('SUPABASE_KEY')) {
        return null;
    }

    $expiresInSeconds = max(60, min(86400, $expiresInSeconds));
    $url = rtrim(SUPABASE_URL, '/') . '/storage/v1/object/sign/' . rawurlencode($bucket) . '/'
        . implode('/', array_map('rawurlencode', explode('/', $objectPath)));

    $headers = [
        'Content-Type: application/json',
        'Accept: application/json',
        'apikey: ' . SUPABASE_KEY,
        'Authorization: Bearer ' . SUPABASE_KEY,
    ];

    $payload = json_encode(['expiresIn' => $expiresInSeconds]);
    $res = supabase_request('POST', $url, $headers, $payload);
    if (!($res['ok'] ?? false)) {
        return null;
    }

    $decoded = json_decode((string) ($res['body'] ?? ''), true);
    $signed = is_array($decoded) ? (string) ($decoded['signedURL'] ?? $decoded['signedUrl'] ?? '') : '';
    if ($signed === '') {
        return null;
    }

    if (str_starts_with($signed, 'http://') || str_starts_with($signed, 'https://')) {
        return $signed;
    }

    return rtrim(SUPABASE_URL, '/') . '/storage/v1' . (str_starts_with($signed, '/') ? $signed : '/' . $signed);
}

/**
 * Download a private avatars-bucket object with the service role key.
 */
function storage_download_avatar_object(string $objectPath): string
{
    $objectPath = ltrim(trim($objectPath), '/');
    if ($objectPath === '' || str_contains($objectPath, '..')
        || !defined('SUPABASE_URL') || !defined('SUPABASE_KEY')) {
        return '';
    }

    $url = rtrim(SUPABASE_URL, '/') . '/storage/v1/object/avatars/'
        . implode('/', array_map('rawurlencode', explode('/', $objectPath)));
    $res = supabase_request('GET', $url, [
        'Accept: */*',
        'apikey: ' . SUPABASE_KEY,
        'Authorization: Bearer ' . SUPABASE_KEY,
    ], null);

    if (!($res['ok'] ?? false)) {
        return '';
    }
    $body = (string) ($res['body'] ?? '');
    return @getimagesizefromstring($body) === false ? '' : $body;
}

/**
 * Resolve an avatars-bucket object path to a URL, preferring the Hostinger copy.
 *
 * When the local file is gone (e.g. uploads folder wiped) the Supabase copy is
 * downloaded once and re-cached locally so later views cost no Storage egress.
 */
function storage_avatar_url_from_bucket(string $objectPath, int $expiresInSeconds = 3600): string
{
    $objectPath = ltrim(trim($objectPath), '/');
    if ($objectPath === '' || str_contains($objectPath, '..')) {
        return '';
    }

    if (!function_exists('media_avatar_signed_url')) {
        require_once __DIR__ . '/media_assets.php';
    }

    $localUrl = media_avatar_signed_url('media/avatars/' . $objectPath, $expiresInSeconds);
    if ($localUrl !== '') {
        return $localUrl;
    }

    $signed = storage_create_signed_url('avatars', $objectPath, $expiresInSeconds);
    if ($signed === null && preg_match('#^profiles/([0-9a-fA-F-]{36})\.#', $objectPath, $m)) {
        // Stored extension may differ from the recorded one (jpg vs png/webp).
        $found = storage_find_user_avatar_path(strtolower((string) $m[1]));
        if ($found !== '' && $found !== $objectPath) {
            $objectPath = $found;
            $localUrl = media_avatar_signed_url('media/avatars/' . $objectPath, $expiresInSeconds);
            if ($localUrl !== '') {
                return $localUrl;
            }
            $signed = storage_create_signed_url('avatars', $objectPath, $expiresInSeconds);
        }
    }
    if ($signed === null) {
        return '';
    }

    $bytes = storage_download_avatar_object($objectPath);
    if ($bytes !== '' && media_write_local_avatar_bytes('media/avatars/' . $objectPath, $bytes)) {
        $localUrl = media_avatar_signed_url('media/avatars/' . $objectPath, $expiresInSeconds);
        if ($localUrl !== '') {
            return $localUrl;
        }
    }

    return $signed;
}

/**
 * Resolve a user photo_url (public URL, storage path, or signed URL) to a
 * short-lived signed URL for the private avatars bucket / Hostinger media.
 */
function storage_resolve_avatar_url(string $photoUrlOrPath, int $expiresInSeconds = 3600): string
{
    $raw = trim($photoUrlOrPath);
    if ($raw === '') {
        return '';
    }

    if (!function_exists('media_is_local_avatar_path')) {
        require_once __DIR__ . '/media_assets.php';
    }

    if (media_is_local_avatar_path($raw)) {
        $localLogical = media_normalize_local_avatar_path($raw);
        $local = media_avatar_signed_url($localLogical, $expiresInSeconds);
        if ($local !== '') {
            return $local;
        }
        // Local file missing — fall back to the Supabase copy of the same object.
        if ($localLogical !== '') {
            $recovered = storage_avatar_url_from_bucket(
                substr($localLogical, strlen('media/avatars/')),
                $expiresInSeconds
            );
            if ($recovered !== '') {
                return $recovered;
            }
        }
        return '';
    }

    if ((str_starts_with($raw, 'http://') || str_starts_with($raw, 'https://'))
        && !str_contains($raw, '/storage/v1/object/')
        && !str_contains($raw, '/api/media_serve.php')
        && !str_contains($raw, '/uploads/media/avatars/')) {
        return $raw;
    }

    $path = storage_object_path_from_url($raw, 'avatars');
    if ($path === '') {
        $normalized = ltrim(str_replace('\\', '/', $raw), '/');
        if (str_starts_with($normalized, 'avatars/')) {
            $normalized = substr($normalized, strlen('avatars/'));
        }
        if ($normalized !== '' && !str_contains($normalized, '://')) {
            $path = $normalized;
        }
    }

    if ($path === '' || str_contains($path, '..')) {
        return '';
    }

    return storage_avatar_url_from_bucket($path, $expiresInSeconds);
}

/**
 * Find the logged-in user's avatar object in the private avatars bucket.
 * Upload convention: profiles/{userId}.{jpg|jpeg|png|webp}
 */
function storage_find_user_avatar_path(string $userId): string
{
    $userId = strtolower(trim($userId));
    if ($userId === '' || !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $userId)) {
        return '';
    }
    if (!defined('SUPABASE_URL') || !defined('SUPABASE_KEY')) {
        return '';
    }

    $url = rtrim(SUPABASE_URL, '/') . '/storage/v1/object/list/avatars';
    $headers = [
        'Content-Type: application/json',
        'Accept: application/json',
        'apikey: ' . SUPABASE_KEY,
        'Authorization: Bearer ' . SUPABASE_KEY,
    ];
    $payload = json_encode([
        'prefix' => 'profiles/',
        'search' => $userId,
        'limit' => 20,
    ], JSON_UNESCAPED_SLASHES);
    $res = supabase_request('POST', $url, $headers, $payload);
    if (!($res['ok'] ?? false)) {
        return '';
    }

    $rows = json_decode((string) ($res['body'] ?? ''), true);
    if (!is_array($rows)) {
        return '';
    }

    $allowed = ['jpg', 'jpeg', 'png', 'webp'];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $name = trim((string) ($row['name'] ?? ''));
        if ($name === '' || str_ends_with($name, '/')) {
            continue;
        }

        $normalized = ltrim(str_replace('\\', '/', $name), '/');
        if (str_starts_with($normalized, 'avatars/')) {
            $normalized = substr($normalized, strlen('avatars/'));
        }
        $base = basename($normalized);
        $dot = strrpos($base, '.');
        if ($dot === false) {
            continue;
        }
        $stem = strtolower(substr($base, 0, $dot));
        $ext = strtolower(substr($base, $dot + 1));
        if ($stem !== $userId || !in_array($ext, $allowed, true)) {
            continue;
        }

        if (str_contains($normalized, '/')) {
            return $normalized;
        }
        return 'profiles/' . $base;
    }

    return '';
}

/**
 * Persist avatars as a storage object path (not an expiring signed/public URL).
 */
function storage_normalize_avatar_photo_value(string $photoUrlOrPath): string
{
    $raw = trim($photoUrlOrPath);
    if ($raw === '') {
        return '';
    }

    if (!function_exists('media_normalize_local_avatar_path')) {
        require_once __DIR__ . '/media_assets.php';
    }
    $local = media_normalize_local_avatar_path($raw);
    if ($local !== '') {
        return $local;
    }

    $path = storage_object_path_from_url($raw, 'avatars');
    if ($path === '') {
        $normalized = ltrim(str_replace('\\', '/', $raw), '/');
        if (str_starts_with($normalized, 'avatars/')) {
            $normalized = substr($normalized, strlen('avatars/'));
        }
        if ($normalized !== '' && !str_contains($normalized, '://')) {
            $path = $normalized;
        }
    }

    if ($path === '' || str_contains($path, '..')) {
        return '';
    }

    return $path;
}
