<?php
declare(strict_types=1);

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

    $skipSslVerify = defined('SUPABASE_DEV_SKIP_SSL_VERIFY') ? (bool) SUPABASE_DEV_SKIP_SSL_VERIFY : false;
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
        // DEV ONLY: if you see "unable to get local issuer certificate", keep this true.
        // For production, configure a proper CA bundle instead.
        CURLOPT_SSL_VERIFYPEER => $skipSslVerify ? false : true,
        CURLOPT_SSL_VERIFYHOST => $skipSslVerify ? 0 : 2,
    ];

    if (defined('CURL_IPRESOLVE_V4')) {
        $options[CURLOPT_IPRESOLVE] = CURL_IPRESOLVE_V4;
    }

    curl_setopt_array($ch, $options);

    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }

    $responseBody = curl_exec($ch);
    $curlErr = curl_error($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($responseBody === false) {
        return ['ok' => false, 'status' => $httpCode, 'body' => null, 'error' => $curlErr ?: 'cURL request failed'];
    }

    return [
        'ok' => $httpCode >= 200 && $httpCode < 300,
        'status' => $httpCode,
        'body' => $responseBody,
        'error' => null,
    ];
}

function supabase_request(string $method, string $url, array $headers, ?string $body = null): array
{
    $maxAttempts = strtoupper($method) === 'GET' ? 1 : 2;
    $lastResult = ['ok' => false, 'status' => 0, 'body' => null, 'error' => 'cURL request failed'];

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
