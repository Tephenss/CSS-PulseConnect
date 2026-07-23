<?php
declare(strict_types=1);

/**
 * Front controller + PHP built-in server router.
 *
 * Usage (local):
 *   php -S localhost:8000 index.php
 *
 * Important: when this file is the router, it MUST serve real files / *.php
 * pages. A bare redirect to /home would create ERR_TOO_MANY_REDIRECTS.
 */

$uriPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$path = is_string($uriPath) ? $uriPath : '/';
$path = rawurldecode($path);

// Normalize
if ($path === '') {
    $path = '/';
}

$root = __DIR__;
$requested = $root . $path;

// Let the built-in server handle real static files and existing scripts.
if ($path !== '/' && is_file($requested)) {
    return false;
}

// Pretty URL: /home → home.php (and /api/foo → api/foo.php)
$phpCandidate = $requested;
if (!str_ends_with($path, '.php')) {
    $phpCandidate = rtrim($requested, '/\\') . '.php';
}
if ($path !== '/' && is_file($phpCandidate)) {
    require $phpCandidate;
    return true;
}

// Site root only
if ($path === '/' || $path === '/index.php') {
    header('Location: /home', true, 302);
    exit;
}

http_response_code(404);
header('Content-Type: text/plain; charset=utf-8');
echo 'Not Found';
return true;
