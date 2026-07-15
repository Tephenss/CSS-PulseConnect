<?php
declare(strict_types=1);

require_once __DIR__ . '/api_cache.php';
require_once __DIR__ . '/supabase.php';

function scan_write_queue_dir(): string
{
    $dir = api_cache_dir() . DIRECTORY_SEPARATOR . 'scan_write_queue';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    return $dir;
}

/**
 * @param array{type:string,url:string,method:string,headers:array,body:string,meta?:array} $job
 */
function scan_write_queue_enqueue(array $job): string
{
    $id = bin2hex(random_bytes(12));
    $job['id'] = $id;
    $job['enqueued_at'] = gmdate('c');
    $job['attempts'] = 0;
    $path = scan_write_queue_dir() . DIRECTORY_SEPARATOR . $id . '.json';
    @file_put_contents(
        $path,
        json_encode($job, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        LOCK_EX
    );
    return $id;
}

/**
 * Execute one write job against Supabase.
 *
 * @return array{ok:bool,status?:int,body?:mixed,error?:string}
 */
function scan_write_queue_execute(array $job): array
{
    $method = strtoupper((string) ($job['method'] ?? 'POST'));
    $url = (string) ($job['url'] ?? '');
    $headers = is_array($job['headers'] ?? null) ? $job['headers'] : [];
    $body = isset($job['body']) ? (string) $job['body'] : null;
    if ($url === '') {
        return ['ok' => false, 'error' => 'Missing write URL'];
    }

    $res = supabase_request($method, $url, $headers, $body);
    if (!$res['ok']) {
        return [
            'ok' => false,
            'status' => (int) ($res['status'] ?? 0),
            'error' => build_error(
                $res['body'] ?? null,
                (int) ($res['status'] ?? 0),
                $res['error'] ?? null,
                'Queued scan write failed'
            ),
            'body' => $res['body'] ?? null,
        ];
    }

    $decoded = json_decode((string) ($res['body'] ?? ''), true);
    return [
        'ok' => true,
        'status' => (int) ($res['status'] ?? 200),
        'body' => $decoded,
    ];
}

/**
 * Drain up to $limit pending scan write jobs.
 *
 * @return array{processed:int,failed:int,remaining:int}
 */
function scan_write_queue_drain(int $limit = 5): array
{
    $limit = max(1, min(25, $limit));
    $dir = scan_write_queue_dir();
    $files = glob($dir . DIRECTORY_SEPARATOR . '*.json') ?: [];
    sort($files);

    $processed = 0;
    $failed = 0;
    foreach ($files as $file) {
        if ($processed + $failed >= $limit) {
            break;
        }
        $raw = @file_get_contents($file);
        if (!is_string($raw) || trim($raw) === '') {
            @unlink($file);
            continue;
        }
        $job = json_decode($raw, true);
        if (!is_array($job)) {
            @unlink($file);
            continue;
        }

        $result = scan_write_queue_execute($job);
        if ($result['ok'] === true) {
            @unlink($file);
            $processed++;
            continue;
        }

        $attempts = (int) ($job['attempts'] ?? 0) + 1;
        $job['attempts'] = $attempts;
        $job['last_error'] = (string) ($result['error'] ?? 'write failed');
        $job['last_attempt_at'] = gmdate('c');
        if ($attempts >= 8) {
            @rename($file, $file . '.dead');
            $failed++;
            continue;
        }
        @file_put_contents(
            $file,
            json_encode($job, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            LOCK_EX
        );
        $failed++;
    }

    $remaining = count(glob($dir . DIRECTORY_SEPARATOR . '*.json') ?: []);
    return [
        'processed' => $processed,
        'failed' => $failed,
        'remaining' => $remaining,
    ];
}

/**
 * Try Supabase write immediately; on failure enqueue for background drain.
 *
 * @return array{ok:bool,queued?:bool,body?:mixed,error?:string,queue_id?:string}
 */
function scan_write_attempt_or_queue(array $job): array
{
    $result = scan_write_queue_execute($job);
    if ($result['ok'] === true) {
        // Opportunistically drain a couple of older queued writes.
        scan_write_queue_drain(2);
        return [
            'ok' => true,
            'queued' => false,
            'body' => $result['body'] ?? null,
        ];
    }

    $queueId = scan_write_queue_enqueue($job);
    // Best-effort immediate drain so burst often still settles.
    scan_write_queue_drain(3);

    return [
        'ok' => true,
        'queued' => true,
        'queue_id' => $queueId,
        'error' => (string) ($result['error'] ?? ''),
        'body' => null,
    ];
}
