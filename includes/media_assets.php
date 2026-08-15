<?php
declare(strict_types=1);

/**
 * Hostinger-local media (covers + avatars) to cut Supabase Storage egress.
 *
 * - Covers: public under /uploads/media/covers/ (same visibility as public bucket)
 * - Avatars: files blocked by .htaccess; served only via HMAC URL (api/media_serve.php)
 */

function media_public_base_url(): string
{
    $configured = '';
    if (function_exists('get_env_val')) {
        $configured = trim((string) get_env_val('MEDIA_PUBLIC_BASE_URL', ''));
    } elseif (isset($_ENV['MEDIA_PUBLIC_BASE_URL'])) {
        $configured = trim((string) $_ENV['MEDIA_PUBLIC_BASE_URL']);
    }
    if ($configured !== '') {
        return rtrim($configured, '/');
    }

    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || ((string) ($_SERVER['SERVER_PORT'] ?? '') === '443')
        || (strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https');
    $scheme = $https ? 'https' : 'http';
    $host = trim((string) ($_SERVER['HTTP_HOST'] ?? 'localhost'));
    if ($host === '') {
        $host = 'localhost';
    }
    return $scheme . '://' . $host;
}

function media_signing_secret(): string
{
    $fromEnv = '';
    if (function_exists('get_env_val')) {
        $fromEnv = trim((string) get_env_val('MEDIA_SIGNING_SECRET', ''));
    }
    if ($fromEnv !== '') {
        return $fromEnv;
    }
    $seed = '';
    if (defined('SUPABASE_KEY') && is_string(SUPABASE_KEY) && SUPABASE_KEY !== '') {
        $seed = SUPABASE_KEY;
    } elseif (defined('MOBILE_PUSH_API_KEY') && is_string(MOBILE_PUSH_API_KEY)) {
        $seed = MOBILE_PUSH_API_KEY;
    }
    return hash('sha256', 'pulse-media-v1|' . $seed);
}

function media_root_dir(): string
{
    return dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'media';
}

function media_ensure_dir(string $absDir): bool
{
    if (is_dir($absDir)) {
        return true;
    }
    return @mkdir($absDir, 0755, true);
}

/**
 * Ensure private avatar tree exists with deny-.htaccess (safe after manual folder deletes).
 */
function media_ensure_avatar_protection(): void
{
    $avatarsDir = media_root_dir() . DIRECTORY_SEPARATOR . 'avatars';
    if (!media_ensure_dir($avatarsDir)) {
        return;
    }
    $profilesDir = $avatarsDir . DIRECTORY_SEPARATOR . 'profiles';
    media_ensure_dir($profilesDir);

    $htaccess = $avatarsDir . DIRECTORY_SEPARATOR . '.htaccess';
    if (is_file($htaccess)) {
        return;
    }
    $rules = "# Deny direct HTTP access to private avatars.\n"
        . "# Serve only via api/media_serve.php (HMAC signed).\n"
        . "<IfModule mod_authz_core.c>\n"
        . "  Require all denied\n"
        . "</IfModule>\n"
        . "<IfModule !mod_authz_core.c>\n"
        . "  Deny from all\n"
        . "</IfModule>\n"
        . "Options -Indexes\n";
    @file_put_contents($htaccess, $rules);
}

/**
 * Compress/resize image bytes with GD. Always returns JPEG when successful.
 * If GD is unavailable, returns the original bytes so uploads still work.
 *
 * @return array{ok:bool,bytes?:string,mime?:string,ext?:string,width?:int,height?:int,compressed?:bool,error?:string}
 */
function media_compress_image_bytes(
    string $rawBytes,
    int $maxWidth,
    int $maxHeight,
    int $jpegQuality = 78
): array {
    if ($rawBytes === '') {
        return ['ok' => false, 'error' => 'Empty image.'];
    }

    $info = @getimagesizefromstring($rawBytes);
    $srcW = is_array($info) ? (int) ($info[0] ?? 0) : 0;
    $srcH = is_array($info) ? (int) ($info[1] ?? 0) : 0;
    $sourceMime = is_array($info) ? strtolower(trim((string) ($info['mime'] ?? ''))) : '';

    if (
        !function_exists('imagecreatefromstring')
        || !function_exists('imagejpeg')
        || !function_exists('imagecreatetruecolor')
    ) {
        // Fail-open: keep original so Hostinger without GD still accepts uploads.
        $ext = match ($sourceMime) {
            'image/png' => 'png',
            'image/webp' => 'webp',
            default => 'jpg',
        };
        return [
            'ok' => true,
            'bytes' => $rawBytes,
            'mime' => $sourceMime !== '' ? $sourceMime : 'image/jpeg',
            'ext' => $ext,
            'width' => $srcW,
            'height' => $srcH,
            'compressed' => false,
        ];
    }

    $src = @imagecreatefromstring($rawBytes);
    if ($src === false) {
        return ['ok' => false, 'error' => 'Unable to decode image.'];
    }

    $srcW = imagesx($src);
    $srcH = imagesy($src);
    if ($srcW < 1 || $srcH < 1) {
        imagedestroy($src);
        return ['ok' => false, 'error' => 'Invalid image dimensions.'];
    }

    $maxWidth = max(64, $maxWidth);
    $maxHeight = max(64, $maxHeight);
    $scale = min(1.0, min($maxWidth / $srcW, $maxHeight / $srcH));
    $dstW = max(1, (int) round($srcW * $scale));
    $dstH = max(1, (int) round($srcH * $scale));

    $dst = imagecreatetruecolor($dstW, $dstH);
    if ($dst === false) {
        imagedestroy($src);
        return ['ok' => false, 'error' => 'Unable to allocate image buffer.'];
    }

    $white = imagecolorallocate($dst, 255, 255, 255);
    if ($white !== false) {
        imagefilledrectangle($dst, 0, 0, $dstW, $dstH, $white);
    }
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $dstW, $dstH, $srcW, $srcH);
    imagedestroy($src);

    $jpegQuality = max(40, min(90, $jpegQuality));
    ob_start();
    $ok = imagejpeg($dst, null, $jpegQuality);
    $bytes = ob_get_clean();
    imagedestroy($dst);

    if (!$ok || !is_string($bytes) || $bytes === '') {
        return ['ok' => false, 'error' => 'Unable to compress image.'];
    }

    // Prefer original only when compression did not shrink and no resize happened.
    if (strlen($bytes) >= strlen($rawBytes) && $scale >= 1.0) {
        $ext = match ($sourceMime) {
            'image/png' => 'png',
            'image/webp' => 'webp',
            default => 'jpg',
        };
        return [
            'ok' => true,
            'bytes' => $rawBytes,
            'mime' => $sourceMime !== '' ? $sourceMime : 'image/jpeg',
            'ext' => $ext,
            'width' => $srcW,
            'height' => $srcH,
            'compressed' => false,
        ];
    }

    return [
        'ok' => true,
        'bytes' => $bytes,
        'mime' => 'image/jpeg',
        'ext' => 'jpg',
        'width' => $dstW,
        'height' => $dstH,
        'compressed' => true,
    ];
}

/**
 * @return array{ok:bool,relative_path?:string,public_url?:string,bytes?:int,error?:string}
 */
function media_store_event_cover(string $eventId, string $rawBytes, string $sourceMime = ''): array
{
    $eventId = trim($eventId);
    if ($eventId === '' || !preg_match('/^[0-9a-fA-F-]{8,}$/', $eventId)) {
        return ['ok' => false, 'error' => 'Invalid event id.'];
    }

    // Covers: 1280×720 @ ~75 quality keeps UI sharp while cutting egress.
    $compressed = media_compress_image_bytes($rawBytes, 1280, 720, 75);
    if (!($compressed['ok'] ?? false)) {
        return ['ok' => false, 'error' => (string) ($compressed['error'] ?? 'Compress failed.')];
    }

    $relDir = 'covers/' . $eventId;
    $absDir = media_root_dir() . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relDir);
    if (!media_ensure_dir($absDir)) {
        return ['ok' => false, 'error' => 'Unable to create cover directory.'];
    }

    $ext = strtolower(trim((string) ($compressed['ext'] ?? 'jpg')));
    if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) {
        $ext = 'jpg';
    }
    if ($ext === 'jpeg') {
        $ext = 'jpg';
    }
    $filename = bin2hex(random_bytes(8)) . '.' . $ext;
    $relPath = $relDir . '/' . $filename;
    $absPath = $absDir . DIRECTORY_SEPARATOR . $filename;
    $written = @file_put_contents($absPath, (string) $compressed['bytes']);
    if ($written === false) {
        return ['ok' => false, 'error' => 'Unable to save cover image.'];
    }

    $publicUrl = media_public_base_url() . '/uploads/media/' . $relPath;
    return [
        'ok' => true,
        'relative_path' => $relPath,
        'public_url' => $publicUrl,
        'bytes' => (int) $written,
    ];
}

/**
 * Store compressed avatar. Returns logical path: media/avatars/profiles/{userId}.jpg
 *
 * @return array{ok:bool,path?:string,bytes?:int,error?:string}
 */
function media_store_user_avatar(string $userId, string $rawBytes): array
{
    $userId = strtolower(trim($userId));
    if ($userId === '' || !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $userId)) {
        return ['ok' => false, 'error' => 'Invalid user id.'];
    }

    // Avatars: small circle UI only — keep under ~100KB typically.
    $compressed = media_compress_image_bytes($rawBytes, 384, 384, 72);
    if (!($compressed['ok'] ?? false)) {
        return ['ok' => false, 'error' => (string) ($compressed['error'] ?? 'Compress failed.')];
    }

    media_ensure_avatar_protection();

    $relDir = 'avatars/profiles';
    $absDir = media_root_dir() . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relDir);
    if (!media_ensure_dir($absDir)) {
        return ['ok' => false, 'error' => 'Unable to create avatar directory.'];
    }

    // Remove previous extensions for this user.
    foreach (['jpg', 'jpeg', 'png', 'webp'] as $oldExt) {
        $old = $absDir . DIRECTORY_SEPARATOR . $userId . '.' . $oldExt;
        if (is_file($old)) {
            @unlink($old);
        }
    }

    $filename = $userId . '.jpg';
    $relPath = $relDir . '/' . $filename;
    $absPath = $absDir . DIRECTORY_SEPARATOR . $filename;
    $written = @file_put_contents($absPath, (string) $compressed['bytes']);
    if ($written === false) {
        return ['ok' => false, 'error' => 'Unable to save avatar.'];
    }

    return [
        'ok' => true,
        'path' => 'media/avatars/' . $relPath,
        'bytes' => (int) $written,
    ];
}

/**
 * Compress image uploads destined for Supabase Storage (proposal/student docs).
 * Non-images are returned unchanged.
 *
 * @return array{bytes:string,mime:string,compressed:bool}
 */
function media_optimize_if_image(string $rawBytes, string $mimeType): array
{
    $mimeType = strtolower(trim($mimeType));
    if ($rawBytes === '' || !str_starts_with($mimeType, 'image/')) {
        return [
            'bytes' => $rawBytes,
            'mime' => $mimeType,
            'compressed' => false,
        ];
    }

    // Document photos can be taller than covers — cap longest edge around 1600.
    $compressed = media_compress_image_bytes($rawBytes, 1600, 1600, 78);
    if (!($compressed['ok'] ?? false)) {
        return [
            'bytes' => $rawBytes,
            'mime' => $mimeType,
            'compressed' => false,
        ];
    }

    return [
        'bytes' => (string) ($compressed['bytes'] ?? $rawBytes),
        'mime' => (string) ($compressed['mime'] ?? $mimeType),
        'compressed' => (bool) ($compressed['compressed'] ?? false),
    ];
}

function media_is_local_avatar_path(string $photoUrlOrPath): bool
{
    $raw = trim($photoUrlOrPath);
    if ($raw === '') {
        return false;
    }
    if (str_starts_with($raw, 'media/avatars/')) {
        return true;
    }
    if (str_contains($raw, '/uploads/media/avatars/')) {
        return true;
    }
    if (str_contains($raw, '/api/media_serve.php')) {
        return true;
    }
    return false;
}

function media_normalize_local_avatar_path(string $photoUrlOrPath): string
{
    $raw = trim($photoUrlOrPath);
    if ($raw === '') {
        return '';
    }

    if (preg_match('#/uploads/media/avatars/([^?]+)#', $raw, $m)) {
        $path = 'media/avatars/' . ltrim(rawurldecode((string) $m[1]), '/');
        return str_contains($path, '..') ? '' : $path;
    }

    if (preg_match('#[?&]p=([^&]+)#', $raw, $m)) {
        $decoded = rawurldecode((string) $m[1]);
        if (str_starts_with($decoded, 'media/avatars/') && !str_contains($decoded, '..')) {
            return $decoded;
        }
    }

    $normalized = ltrim(str_replace('\\', '/', $raw), '/');
    if (str_starts_with($normalized, 'media/avatars/') && !str_contains($normalized, '..')) {
        return $normalized;
    }

    return '';
}

function media_local_avatar_abs_path(string $logicalPath): string
{
    $path = media_normalize_local_avatar_path($logicalPath);
    if ($path === '' || !str_starts_with($path, 'media/avatars/')) {
        return '';
    }
    $rel = substr($path, strlen('media/'));
    return media_root_dir() . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
}

function media_avatar_signed_url(string $logicalPath, int $expiresInSeconds = 3600): string
{
    $path = media_normalize_local_avatar_path($logicalPath);
    if ($path === '') {
        return '';
    }
    $abs = media_local_avatar_abs_path($path);
    if ($abs === '' || !is_file($abs)) {
        return '';
    }

    $expiresInSeconds = max(60, min(86400, $expiresInSeconds));
    $exp = time() + $expiresInSeconds;
    $payload = $path . '|' . $exp;
    $sig = hash_hmac('sha256', $payload, media_signing_secret());
    return media_public_base_url() . '/api/media_serve.php'
        . '?p=' . rawurlencode($path)
        . '&e=' . $exp
        . '&s=' . rawurlencode($sig);
}

function media_verify_avatar_request(string $logicalPath, int $exp, string $sig): bool
{
    $path = media_normalize_local_avatar_path($logicalPath);
    if ($path === '' || $exp < time() || $sig === '') {
        return false;
    }
    $payload = $path . '|' . $exp;
    $expected = hash_hmac('sha256', $payload, media_signing_secret());
    return hash_equals($expected, $sig);
}
