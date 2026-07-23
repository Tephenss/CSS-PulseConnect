<?php
declare(strict_types=1);

/**
 * Session bootstrap — call this INSTEAD of bare session_start() everywhere.
 *
 * Problems this solves when running on php -S localhost:
 *  - PHP's built-in dev-server shares the default C:\xampp\tmp session store
 *    with XAMPP, so two browser profiles logged into different apps can end up
 *    reading each other's session data.
 *  - session_regenerate_id() during login rewrites the session-ID cookie; on
 *    localhost every tab sees that new ID, so whichever user logged in *last*
 *    becomes the "current user" in all open tabs.
 *
 * Fixes applied:
 *  1. Dedicated save-path  → sessions are stored in <project>/sessions/, not
 *     the shared XAMPP tmp dir.
 *  2. Custom session name  → "PCSS" instead of the default "PHPSESSID", so
 *     cookies don't collide with any other app running on localhost.
 *  3. SameSite=Strict      → browser won't forward the cookie cross-context.
 *  4. HttpOnly             → JS can't read the session ID.
 */
function session_bootstrap(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return; // already started — nothing to do
    }

    // 1. Isolated save path inside the project directory.
    $savePath = __DIR__ . '/../sessions';
    if (!is_dir($savePath)) {
        mkdir($savePath, 0750, true);
    }
    session_save_path(realpath($savePath));

    // 2. Unique cookie name so we don't collide with XAMPP or other apps on localhost.
    session_name('PCSS');

    // 3. Secure cookie flags.
    session_set_cookie_params([
        'lifetime' => 0,         // session cookie (expires when browser closes)
        'path'     => '/',
        'domain'   => '',        // current host only
        'secure'   => false,     // keep false for http://localhost dev
        'httponly' => true,
        'samesite' => 'Strict',
    ]);

    session_start();
}
