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
 *  5. Longer GC lifetime   → Hostinger's default session.gc_maxlifetime is 1440s
 *     (24 min). Idle users then look "logged out" even though they never clicked
 *     Logout. Keep session files for a school day; daily OTP at 12:00 AM Manila
 *     still applies on the next real page load.
 */
function session_gc_maxlifetime_seconds(): int
{
    return 12 * 3600; // 12 hours
}

function session_bootstrap(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return; // already started — nothing to do
    }

    $gcLifetime = session_gc_maxlifetime_seconds();
    @ini_set('session.gc_maxlifetime', (string) $gcLifetime);
    @ini_set('session.cookie_lifetime', '0');

    // 1. Isolated save path inside the project directory (not Hostinger /tmp,
    // which some plans wipe on a short timer regardless of gc_maxlifetime).
    $savePath = __DIR__ . '/../sessions';
    if (!is_dir($savePath)) {
        @mkdir($savePath, 0750, true);
    }
    $resolvedSavePath = realpath($savePath);
    $usePath = $resolvedSavePath !== false ? $resolvedSavePath : $savePath;
    if (is_dir($usePath) && is_writable($usePath)) {
        session_save_path($usePath);
    } else {
        error_log('session_bootstrap: sessions/ is not writable; PHP may use a host tmp dir with a short GC');
    }

    // 2. Unique cookie name so we don't collide with XAMPP or other apps on localhost.
    session_name('PCSS');

    // 3. Secure cookie flags.
    // SESSION_COOKIE_SECURE: auto (HTTPS detected) | true | false
    $secureFlag = false;
    if (defined('SESSION_COOKIE_SECURE')) {
        $mode = strtolower(trim((string) SESSION_COOKIE_SECURE));
        if ($mode === 'true' || $mode === '1') {
            $secureFlag = true;
        } elseif ($mode === 'false' || $mode === '0') {
            $secureFlag = false;
        } else {
            // auto
            $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                || ((int) ($_SERVER['SERVER_PORT'] ?? 0) === 443)
                || (strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https');
            $secureFlag = $https;
        }
    }

    session_set_cookie_params([
        'lifetime' => 0,         // session cookie (expires when browser closes)
        'path'     => '/',
        'domain'   => '',        // current host only
        'secure'   => $secureFlag,
        'httponly' => true,
        'samesite' => 'Strict',
    ]);

    session_start();
}
