<?php
declare(strict_types=1);

/**
 * Resolve a CA certificate bundle so HTTPS (Supabase, FCM, etc.) verifies
 * without disabling SSL. Prefer project certs/cacert.pem or CURL_CA_BUNDLE.
 */
function curl_ssl_ca_bundle_path(): ?string
{
    static $resolved = false;
    static $path = null;
    if ($resolved) {
        return $path;
    }
    $resolved = true;

    $candidates = [];

    // 1) Explicit override from .env / config (highest priority).
    if (defined('CURL_CA_BUNDLE') && is_string(CURL_CA_BUNDLE) && CURL_CA_BUNDLE !== '') {
        $candidates[] = CURL_CA_BUNDLE;
    }
    $envBundle = getenv('CURL_CA_BUNDLE') ?: ($_ENV['CURL_CA_BUNDLE'] ?? '');
    if (is_string($envBundle) && trim($envBundle) !== '') {
        $candidates[] = trim($envBundle);
    }

    // 2) Project-shipped Mozilla CA bundle (preferred over broken XAMPP defaults).
    $root = dirname(__DIR__);
    $candidates[] = $root . DIRECTORY_SEPARATOR . 'certs' . DIRECTORY_SEPARATOR . 'cacert.pem';
    $candidates[] = $root . DIRECTORY_SEPARATOR . 'cacert.pem';

    // 3) Other env / php.ini fallbacks.
    $envSsl = getenv('SSL_CERT_FILE') ?: ($_ENV['SSL_CERT_FILE'] ?? '');
    if (is_string($envSsl) && trim($envSsl) !== '') {
        $candidates[] = trim($envSsl);
    }
    $iniCa = (string) ini_get('curl.cainfo');
    if ($iniCa !== '') {
        $candidates[] = $iniCa;
    }
    $iniOpenssl = (string) ini_get('openssl.cafile');
    if ($iniOpenssl !== '') {
        $candidates[] = $iniOpenssl;
    }

    foreach ($candidates as $candidate) {
        $candidate = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, (string) $candidate);
        if ($candidate !== '' && is_file($candidate) && is_readable($candidate)) {
            $path = $candidate;
            return $path;
        }
    }

    return null;
}

/**
 * Apply SSL verify policy to a cURL handle.
 * Uses project/system CA bundle when available. Skip only if explicitly enabled.
 *
 * @param resource|\CurlHandle $ch
 */
function apply_curl_ssl_policy($ch): void
{
    $skipSslVerify = defined('SUPABASE_DEV_SKIP_SSL_VERIFY') && SUPABASE_DEV_SKIP_SSL_VERIFY;
    if ($skipSslVerify) {
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        return;
    }

    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);

    // Windows + OpenSSL: prefer OS trust store (includes AV / system roots).
    // Mozilla cacert alone often fails on XAMPP when antivirus does HTTPS scan.
    if (defined('CURLSSLOPT_NATIVE_CA')
        && defined('CURLOPT_SSL_OPTIONS')
        && PHP_OS_FAMILY === 'Windows'
    ) {
        $curlVer = curl_version()['version'] ?? '0';
        if (version_compare($curlVer, '7.71', '>=')) {
            curl_setopt($ch, CURLOPT_SSL_OPTIONS, CURLSSLOPT_NATIVE_CA);
        }
    }

    $ca = curl_ssl_ca_bundle_path();
    if ($ca !== null) {
        curl_setopt($ch, CURLOPT_CAINFO, $ca);
    }
}
