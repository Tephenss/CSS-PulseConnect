<?php
declare(strict_types=1);

/**
 * Serve Hostinger-local private avatars via short-lived HMAC URLs.
 * Direct filesystem access under uploads/media/avatars is denied by .htaccess.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/media_assets.php';

$path = trim((string) ($_GET['p'] ?? ''));
$exp = (int) ($_GET['e'] ?? 0);
$sig = trim((string) ($_GET['s'] ?? ''));

if (!media_verify_avatar_request($path, $exp, $sig)) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Forbidden';
    exit;
}

$abs = media_local_avatar_abs_path($path);
if ($abs === '' || !is_file($abs)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Not found';
    exit;
}

$size = filesize($abs);
$mtime = filemtime($abs) ?: time();
header('Content-Type: image/jpeg');
header('Content-Length: ' . (string) ($size !== false ? $size : 0));
header('Cache-Control: private, max-age=3600');
header('X-Content-Type-Options: nosniff');
header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $mtime) . ' GMT');
readfile($abs);
exit;
