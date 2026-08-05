<?php
declare(strict_types=1);

/**
 * Temporary Hostinger diagnostics. Delete after the site is healthy again.
 * Open: https://ccspulseconnect.com/pc_health.php
 */
header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');

echo "PulseConnect health\n";
echo 'PHP ' . PHP_VERSION . "\n";
echo 'time ' . gmdate('c') . "\n\n";

register_shutdown_function(static function (): void {
    $err = error_get_last();
    if (!is_array($err)) {
        return;
    }
    $type = (int) ($err['type'] ?? 0);
    if (!in_array($type, [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        return;
    }
    echo "\nFATAL: " . (string) ($err['message'] ?? '') . "\n";
    echo 'FILE: ' . (string) ($err['file'] ?? '') . ':' . (string) ($err['line'] ?? '') . "\n";
});

$checks = [
    'config.php' => __DIR__ . '/config.php',
    'includes/json.php' => __DIR__ . '/includes/json.php',
    'includes/session.php' => __DIR__ . '/includes/session.php',
    'includes/csrf.php' => __DIR__ . '/includes/csrf.php',
    'includes/helpers.php' => __DIR__ . '/includes/helpers.php',
    'includes/supabase.php' => __DIR__ . '/includes/supabase.php',
    'includes/curl_ssl.php' => __DIR__ . '/includes/curl_ssl.php',
    'includes/device_trust.php' => __DIR__ . '/includes/device_trust.php',
    'includes/auth.php' => __DIR__ . '/includes/auth.php',
    'includes/mobile_api.php' => __DIR__ . '/includes/mobile_api.php',
    'includes/mobile_session.php' => __DIR__ . '/includes/mobile_session.php',
    'includes/certificate_code_pool.php' => __DIR__ . '/includes/certificate_code_pool.php',
    'includes/certificate_auto_issue.php' => __DIR__ . '/includes/certificate_auto_issue.php',
];

foreach ($checks as $label => $path) {
    echo 'CHECK ' . $label . ' ... ';
    if (!is_file($path)) {
        echo "MISSING\n";
        continue;
    }
    if (!is_readable($path)) {
        echo "UNREADABLE\n";
        continue;
    }
    // Isolate parse errors before require.
    $lint = [];
    $code = 0;
    // Hostinger may not allow exec; fall back to require.
    if (function_exists('exec')) {
        @exec('php -l ' . escapeshellarg($path) . ' 2>&1', $lint, $code);
        if ($code !== 0) {
            echo "PARSE_FAIL\n  " . implode("\n  ", $lint) . "\n";
            continue;
        }
    }
    try {
        require_once $path;
        echo "OK\n";
    } catch (Throwable $e) {
        echo 'THROW ' . $e->getMessage() . "\n";
    }
}

echo "\nBootstrap session ... ";
try {
    if (function_exists('session_bootstrap')) {
        session_bootstrap();
        echo "OK (name=" . session_name() . ")\n";
    } else {
        echo "SKIP (session_bootstrap missing)\n";
    }
} catch (Throwable $e) {
    echo 'THROW ' . $e->getMessage() . "\n";
}

echo "\nDONE\n";
