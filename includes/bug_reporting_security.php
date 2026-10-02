<?php
declare(strict_types=1);

function bug_reporting_start_session(string $cookieName): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    ini_set('session.use_strict_mode', '1');
    ini_set('session.gc_maxlifetime', '28800');
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https')
        || ((int) ($_SERVER['SERVER_PORT'] ?? 0) === 443);
    session_name($cookieName);
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    if (!session_start() || session_status() !== PHP_SESSION_ACTIVE) {
        throw new RuntimeException('Unable to start the standalone issue desk session.');
    }
}

function bug_report_session_start(): void
{
    bug_reporting_start_session('PCBUGRPT');
}

