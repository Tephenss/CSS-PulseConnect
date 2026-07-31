<?php
declare(strict_types=1);

require_once __DIR__ . '/api_cache.php';

/**
 * File-backed sliding-window rate limiter (no Redis).
 * Returns true when the request is allowed.
 */
function api_rate_limit_allow(string $bucketKey, int $maxHits, int $windowSeconds): bool
{
    $maxHits = max(1, $maxHits);
    $windowSeconds = max(1, $windowSeconds);
    $path = api_cache_dir() . DIRECTORY_SEPARATOR . 'rl_' . sha1($bucketKey) . '.json';
    $now = time();
    $hits = [];

    $fh = @fopen($path, 'c+');
    // Fail closed: if we cannot track hits, deny the request.
    if ($fh === false) {
        return false;
    }

    if (!@flock($fh, LOCK_EX)) {
        fclose($fh);
        return false;
    }

    $raw = stream_get_contents($fh);
    if (is_string($raw) && trim($raw) !== '') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            foreach ($decoded as $ts) {
                $t = (int) $ts;
                if ($t > 0 && ($now - $t) < $windowSeconds) {
                    $hits[] = $t;
                }
            }
        }
    }

    if (count($hits) >= $maxHits) {
        flock($fh, LOCK_UN);
        fclose($fh);
        return false;
    }

    $hits[] = $now;
    ftruncate($fh, 0);
    rewind($fh);
    fwrite($fh, json_encode($hits));
    fflush($fh);
    flock($fh, LOCK_UN);
    fclose($fh);

    return true;
}

/**
 * Deduplicate identical write fingerprints briefly (e.g. same ticket scan spam).
 * Returns true if this is the first occurrence in the window.
 */
function api_request_dedupe_first(string $fingerprint, int $ttlSeconds = 3): bool
{
    $ttlSeconds = max(1, $ttlSeconds);
    $path = api_cache_dir() . DIRECTORY_SEPARATOR . 'dd_' . sha1($fingerprint) . '.flag';
    if (is_file($path)) {
        $age = time() - (int) @filemtime($path);
        if ($age >= 0 && $age < $ttlSeconds) {
            return false;
        }
    }

    @file_put_contents($path, (string) time(), LOCK_EX);
    return true;
}
