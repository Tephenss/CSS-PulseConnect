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
 * Resolve a user photo_url (public URL, storage path, or signed URL) to a
 * short-lived signed URL for the private avatars bucket.
 */
function storage_resolve_avatar_url(string $photoUrlOrPath, int $expiresInSeconds = 3600): string
{
    $raw = trim($photoUrlOrPath);
    if ($raw === '') {
        return '';
    }

    if ((str_starts_with($raw, 'http://') || str_starts_with($raw, 'https://'))
        && !str_contains($raw, '/storage/v1/object/')) {
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

    $signed = storage_create_signed_url('avatars', $path, $expiresInSeconds);
    return $signed ?? '';
}
