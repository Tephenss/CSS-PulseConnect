<?php
declare(strict_types=1);

function api_cache_path(string $key): string
{
    $dir = rtrim(sys_get_temp_dir(), '\\/') . DIRECTORY_SEPARATOR . 'ccs_pulseconnect_cache';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }

    return $dir . DIRECTORY_SEPARATOR . sha1($key) . '.json';
}

function api_cache_read(string $key, int $ttlSeconds): ?array
{
    if ($ttlSeconds < 1) {
        return null;
    }

    $path = api_cache_path($key);
    if (!is_file($path)) {
        return null;
    }

    $age = time() - (int) filemtime($path);
    if ($age > $ttlSeconds) {
        return null;
    }

    $raw = file_get_contents($path);
    if (!is_string($raw) || trim($raw) === '') {
        return null;
    }

    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : null;
}

function api_cache_write(string $key, array $data): void
{
    $path = api_cache_path($key);
    file_put_contents($path, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}
