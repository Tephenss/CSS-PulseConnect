<?php
declare(strict_types=1);

function supabase_request(string $method, string $url, array $headers, ?string $body = null): array
{
    $ch = curl_init($url);
    if ($ch === false) {
        return ['ok' => false, 'status' => 0, 'body' => null, 'error' => 'Failed to init cURL'];
    }

    $skipSslVerify = defined('SUPABASE_DEV_SKIP_SSL_VERIFY') ? (bool) SUPABASE_DEV_SKIP_SSL_VERIFY : false;

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 60,
        // DEV ONLY: if you see "unable to get local issuer certificate", keep this true.
        // For production, configure a proper CA bundle instead.
        CURLOPT_SSL_VERIFYPEER => $skipSslVerify ? false : true,
        CURLOPT_SSL_VERIFYHOST => $skipSslVerify ? 0 : 2,
    ]);

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
        return 'cURL error: ' . $curlError;
    }

    return extract_supabase_message($body, $httpStatus, $fallback);
}

