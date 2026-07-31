<?php
declare(strict_types=1);

// curl_ssl.php may be missing on Hostinger if deploy skipped untracked files.
// Never fatal the whole site — provide a minimal SSL helper fallback.
$__pulseCurlSsl = __DIR__ . DIRECTORY_SEPARATOR . 'curl_ssl.php';
if (is_file($__pulseCurlSsl)) {
    require_once $__pulseCurlSsl;
} elseif (!function_exists('apply_curl_ssl_policy')) {
    /**
     * @param resource|\CurlHandle $ch
     */
    function apply_curl_ssl_policy($ch): void
    {
        $skip = defined('SUPABASE_DEV_SKIP_SSL_VERIFY') && SUPABASE_DEV_SKIP_SSL_VERIFY;
        if ($skip) {
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
            return;
        }
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        $ca = __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'certs' . DIRECTORY_SEPARATOR . 'cacert.pem';
        if (is_file($ca) && is_readable($ca)) {
            curl_setopt($ch, CURLOPT_CAINFO, $ca);
        }
    }
}
unset($__pulseCurlSsl);

function supabase_is_retryable_curl_error(?string $error): bool
{
    if (!is_string($error) || trim($error) === '') {
        return false;
    }

    $lower = strtolower($error);
    foreach ([
        'timeout',
        'timed out',
        'connection reset',
        'could not resolve',
        'ssl',
        'recv failure',
        'failed to connect',
        'empty reply from server',
    ] as $needle) {
        if (str_contains($lower, $needle)) {
            return true;
        }
    }

    return false;
}

function supabase_request_once(string $method, string $url, array $headers, ?string $body = null): array
{
    $ch = curl_init($url);
    if ($ch === false) {
        return ['ok' => false, 'status' => 0, 'body' => null, 'error' => 'Failed to init cURL'];
    }

    $hasLargeBody = is_string($body) && strlen($body) > 100000;
    $isGet = strtoupper($method) === 'GET';
    $connectTimeout = $hasLargeBody ? 15 : ($isGet ? 6 : 10);
    $timeout = $hasLargeBody ? 90 : ($isGet ? 25 : 30);

    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_CONNECTTIMEOUT => $connectTimeout,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_NOSIGNAL => true,
        CURLOPT_TCP_KEEPALIVE => 1,
    ];

    if (defined('CURL_IPRESOLVE_V4')) {
        $options[CURLOPT_IPRESOLVE] = CURL_IPRESOLVE_V4;
    }

    curl_setopt_array($ch, $options);
    apply_curl_ssl_policy($ch);

    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }

    $responseHeaders = [];
    curl_setopt($ch, CURLOPT_HEADERFUNCTION, static function ($ch, string $headerLine) use (&$responseHeaders): int {
        $len = strlen($headerLine);
        $parts = explode(':', $headerLine, 2);
        if (count($parts) === 2) {
            $name = strtolower(trim($parts[0]));
            $responseHeaders[$name] = trim($parts[1]);
        }
        return $len;
    });

    $responseBody = curl_exec($ch);
    $curlErr = curl_error($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($responseBody === false) {
        return ['ok' => false, 'status' => $httpCode, 'body' => null, 'error' => $curlErr ?: 'cURL request failed', 'headers' => $responseHeaders];
    }

    return [
        'ok' => $httpCode >= 200 && $httpCode < 300,
        'status' => $httpCode,
        'body' => $responseBody,
        'error' => null,
        'headers' => $responseHeaders,
    ];
}

function supabase_request(string $method, string $url, array $headers, ?string $body = null): array
{
    $maxAttempts = strtoupper($method) === 'GET' ? 1 : 2;
    $lastResult = ['ok' => false, 'status' => 0, 'body' => null, 'error' => 'cURL request failed', 'headers' => []];

    for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
        $lastResult = supabase_request_once($method, $url, $headers, $body);
        if (($lastResult['ok'] ?? false) === true) {
            return $lastResult;
        }

        $curlError = (string) ($lastResult['error'] ?? '');
        if (!supabase_is_retryable_curl_error($curlError) || $attempt === $maxAttempts) {
            return $lastResult;
        }

        usleep(300000 * $attempt);
    }

    return $lastResult;
}

/**
 * Exact row count via PostgREST Prefer: count=exact (no row download).
 */
function supabase_exact_count(string $tableOrPath, array $headers, string $query = ''): int
{
    $path = ltrim($tableOrPath, '/');
    if (!str_contains($path, '?') && $query !== '') {
        $path .= (str_starts_with($query, '?') ? $query : '?' . ltrim($query, '&'));
    } elseif ($query !== '' && str_contains($path, '?')) {
        $path .= '&' . ltrim($query, '&?');
    }

    // Ensure a cheap select when caller passed only filters.
    if (!str_contains($path, 'select=')) {
        $path .= (str_contains($path, '?') ? '&' : '?') . 'select=id';
    }

    $url = rtrim(SUPABASE_URL, '/') . '/rest/v1/' . $path;
    $countHeaders = array_merge($headers, [
        'Prefer: count=exact',
        'Range: 0-0',
    ]);

    $res = supabase_request('GET', $url, $countHeaders);
    if (!($res['ok'] ?? false) && (int) ($res['status'] ?? 0) !== 206) {
        // PostgREST often returns 206 Partial Content for ranged count requests.
        $status = (int) ($res['status'] ?? 0);
        if ($status < 200 || $status >= 300) {
            return 0;
        }
    }

    $contentRange = (string) (($res['headers']['content-range'] ?? '') ?: '');
    if (preg_match('/\/(\d+)\s*$/', $contentRange, $m) === 1) {
        return (int) $m[1];
    }

    // Fallback: empty range with total */0
    if (str_ends_with($contentRange, '/*') || preg_match('/\/\*\s*$/', $contentRange) === 1) {
        return 0;
    }

    return 0;
}

function extract_supabase_message($body, int $httpStatus, string $fallback): string
{
    if (!is_string($body) || trim($body) === '') {
        return $fallback . ' (HTTP ' . $httpStatus . ')';
    }

    $decoded = json_decode($body, true);
    $msg = null;

    if (is_array($decoded)) {
        if (isset($decoded[0]['message']) && is_string($decoded[0]['message'])) {
            $msg = $decoded[0]['message'];
        } elseif (isset($decoded['message']) && is_string($decoded['message'])) {
            $msg = $decoded['message'];
        } elseif (isset($decoded['details']) && is_string($decoded['details'])) {
            $msg = $decoded['details'];
        }
    }

    if (!is_string($msg) || $msg === '') {
        return $fallback . ' (HTTP ' . $httpStatus . ')';
    }

    return $msg;
}

function build_error($body, int $httpStatus, ?string $curlError, string $fallback): string
{
    if (is_string($curlError) && trim($curlError) !== '') {
        $lower = strtolower($curlError);
        if (str_contains($lower, 'ssl connection timeout') || str_contains($lower, 'connection timed out')) {
            return 'Could not reach the server. Check your internet connection or turn off VPN, then try again.';
        }

        return 'cURL error: ' . $curlError;
    }

    return extract_supabase_message($body, $httpStatus, $fallback);
}
