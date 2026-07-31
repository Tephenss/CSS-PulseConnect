<?php
declare(strict_types=1);

function api_cache_dir(): string
{
    $dir = rtrim(sys_get_temp_dir(), '\\/') . DIRECTORY_SEPARATOR . 'ccs_pulseconnect_cache';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }

    return $dir;
}

function api_cache_path(string $key): string
{
    return api_cache_dir() . DIRECTORY_SEPARATOR . sha1($key) . '.json';
}

function api_cache_lock_path(string $key): string
{
    return api_cache_dir() . DIRECTORY_SEPARATOR . sha1($key) . '.lock';
}

/**
 * @return array{data: array, age: int}|null
 */
function api_cache_read_entry(string $key): ?array
{
    $path = api_cache_path($key);
    if (!is_file($path)) {
        return null;
    }

    $raw = @file_get_contents($path);
    if (!is_string($raw) || trim($raw) === '') {
        return null;
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return null;
    }

    $age = time() - (int) @filemtime($path);
    if ($age < 0) {
        $age = 0;
    }

    return ['data' => $decoded, 'age' => $age];
}

function api_cache_read(string $key, int $ttlSeconds): ?array
{
    if ($ttlSeconds < 1) {
        return null;
    }

    $entry = api_cache_read_entry($key);
    if ($entry === null) {
        return null;
    }

    if ($entry['age'] > $ttlSeconds) {
        return null;
    }

    return $entry['data'];
}

/**
 * Serve slightly stale data during stampede (TTL + grace).
 */
function api_cache_read_stale(string $key, int $ttlSeconds, int $graceSeconds = 20): ?array
{
    if ($ttlSeconds < 1) {
        return null;
    }

    $entry = api_cache_read_entry($key);
    if ($entry === null) {
        return null;
    }

    if ($entry['age'] > ($ttlSeconds + max(0, $graceSeconds))) {
        return null;
    }

    return $entry['data'];
}

function api_cache_write(string $key, array $data): void
{
    $path = api_cache_path($key);
    $payload = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($payload)) {
        return;
    }

    $tmp = $path . '.' . getmypid() . '.tmp';
    if (@file_put_contents($tmp, $payload, LOCK_EX) === false) {
        @file_put_contents($path, $payload, LOCK_EX);
        return;
    }
    @rename($tmp, $path);
}

/**
 * Try to acquire a short single-flight lock. Returns true if this process should refresh.
 */
function api_cache_try_lock(string $key, int $lockSeconds = 8): bool
{
    $path = api_cache_lock_path($key);
    if (is_file($path)) {
        $age = time() - (int) @filemtime($path);
        if ($age >= 0 && $age < $lockSeconds) {
            return false;
        }
    }

    $fh = @fopen($path, 'c+');
    if ($fh === false) {
        return true;
    }

    $locked = @flock($fh, LOCK_EX | LOCK_NB);
    if (!$locked) {
        fclose($fh);
        return false;
    }

    ftruncate($fh, 0);
    fwrite($fh, (string) time());
    fflush($fh);
    // Keep lock file as a marker; release flock but leave mtime fresh.
    flock($fh, LOCK_UN);
    fclose($fh);
    @touch($path);

    return true;
}

function api_cache_release_lock(string $key): void
{
    $path = api_cache_lock_path($key);
    if (is_file($path)) {
        @unlink($path);
    }
}

/**
 * Cache-aside with stampede control:
 * - fresh hit → return
 * - miss/expired → one refresher; others may get stale while waiting briefly
 *
 * @param callable(): array $loader
 */
function api_cache_remember(string $key, int $ttlSeconds, callable $loader, int $graceSeconds = 20): array
{
    $fresh = api_cache_read($key, $ttlSeconds);
    if (is_array($fresh)) {
        return $fresh;
    }

    $gotLock = api_cache_try_lock($key);
    if (!$gotLock) {
        $stale = api_cache_read_stale($key, $ttlSeconds, $graceSeconds);
        if (is_array($stale)) {
            return $stale;
        }
        // Brief wait for the owner refresh.
        usleep(120000);
        $again = api_cache_read($key, $ttlSeconds);
        if (is_array($again)) {
            return $again;
        }
        $stale2 = api_cache_read_stale($key, $ttlSeconds, $graceSeconds);
        if (is_array($stale2)) {
            return $stale2;
        }
    }

    try {
        $data = $loader();
        if (is_array($data)) {
            api_cache_write($key, $data);
            return $data;
        }
    } finally {
        if ($gotLock) {
            api_cache_release_lock($key);
        }
    }

    $fallback = api_cache_read_stale($key, $ttlSeconds, $graceSeconds * 2);
    return is_array($fallback) ? $fallback : ['ok' => false, 'error' => 'Cache loader failed'];
}

/**
 * Shared generation counter so mutations can invalidate all per-user list caches.
 */
function api_cache_generation(string $namespace): int
{
    $entry = api_cache_read_entry($namespace . '_gen');
    if ($entry === null) {
        return 1;
    }
    return max(1, (int) (($entry['data']['v'] ?? 1)));
}

function api_cache_bump_generation(string $namespace): int
{
    $next = api_cache_generation($namespace) + 1;
    api_cache_write($namespace . '_gen', ['v' => $next]);
    return $next;
}
