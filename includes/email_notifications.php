<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';

function smtp_set_last_error(string $message): void
{
    $GLOBALS['SMTP_LAST_ERROR'] = $message;
}

function smtp_get_last_error(): string
{
    $msg = $GLOBALS['SMTP_LAST_ERROR'] ?? '';
    return is_string($msg) ? $msg : '';
}

function smtp_set_last_reply(string $reply): void
{
    $GLOBALS['SMTP_LAST_REPLY'] = $reply;
}

function smtp_get_last_reply(): string
{
    $msg = $GLOBALS['SMTP_LAST_REPLY'] ?? '';
    return is_string($msg) ? $msg : '';
}

function smtp_error_is_daily_limit(?string $raw = null): bool
{
    $raw = strtolower($raw ?? smtp_get_last_error());
    return str_contains($raw, '5.4.5')
        || str_contains($raw, 'daily user sending limit')
        || str_contains($raw, 'sending limit exceeded')
        || str_contains($raw, 'quota exceeded')
        || str_contains($raw, 'too many messages');
}

function smtp_quota_session_key(string $provider): string
{
    return $provider === 'fallback' ? 'smtp_fallback_quota_until' : 'smtp_primary_quota_until';
}

function smtp_is_quota_blocked(string $provider): bool
{
    $globalKey = $provider === 'fallback' ? 'SMTP_FALLBACK_QUOTA_BLOCKED' : 'SMTP_PRIMARY_QUOTA_BLOCKED';
    if (!empty($GLOBALS[$globalKey])) {
        return true;
    }
    $sessionKey = smtp_quota_session_key($provider);
    return isset($_SESSION[$sessionKey]) && (int) $_SESSION[$sessionKey] > time();
}

function smtp_mark_quota_blocked(string $provider): void
{
    $globalKey = $provider === 'fallback' ? 'SMTP_FALLBACK_QUOTA_BLOCKED' : 'SMTP_PRIMARY_QUOTA_BLOCKED';
    $GLOBALS[$globalKey] = true;
    if (session_status() === PHP_SESSION_ACTIVE) {
        $_SESSION[smtp_quota_session_key($provider)] = time() + 12 * 3600;
    }
}

function pulseconnect_try_smtp(
    string $host,
    int $port,
    string $encryption,
    string $username,
    string $password,
    string $fromAddress,
    string $fromName,
    string $to,
    string $subject,
    string $body
): bool {
    $host = trim($host);
    $username = trim($username);
    $fromAddress = trim($fromAddress);
    if ($host === '' || $username === '' || $password === '' || $fromAddress === '') {
        return false;
    }
    $port = $port > 0 ? $port : 587;
    return smtp_send_mail(
        $host,
        $port,
        strtolower(trim($encryption)),
        $username,
        $password,
        $fromAddress,
        $fromName,
        $to,
        $subject,
        $body
    );
}

function pulseconnect_domain_from_address(): string
{
    $configured = defined('EMAIL_DOMAIN_SENDER') ? trim((string) EMAIL_DOMAIN_SENDER) : '';
    if ($configured !== '' && filter_var($configured, FILTER_VALIDATE_EMAIL)) {
        return $configured;
    }
    $primary = trim((string) EMAIL_SENDER_ADDRESS);
    $at = strrpos($primary, '@');
    $domain = $at !== false ? strtolower(substr($primary, $at + 1)) : '';
    if ($primary !== '' && $domain !== '' && $domain !== 'gmail.com' && $domain !== 'googlemail.com') {
        return $primary;
    }
    return 'noreply@ccspulseconnect.com';
}

function pulseconnect_native_mail(string $to, string $subject, string $body, string $fromAddress): bool
{
    $headers = build_multipart_headers($fromAddress);
    return @mail($to, $subject, $body, implode("\r\n", $headers), '-f ' . $fromAddress);
}

function pulseconnect_deliver_email(
    string $to,
    string $subject,
    string $body,
    string $logContext = 'email'
): bool {
    $fromName = trim((string) SMTP_FROM_NAME);

    // 1) Primary: Hostinger mailbox.
    if (!smtp_is_quota_blocked('primary')) {
        $ok = pulseconnect_try_smtp(
            (string) SMTP_HOST,
            (int) SMTP_PORT,
            (string) SMTP_ENCRYPTION,
            (string) SMTP_USERNAME,
            (string) SMTP_PASSWORD,
            (string) EMAIL_SENDER_ADDRESS,
            $fromName,
            $to,
            $subject,
            $body
        );
        if ($ok) {
            return true;
        }
        $primaryError = smtp_get_last_error();
        error_log('[SMTP] ' . $logContext . ' primary (Hostinger) failed: ' . ($primaryError !== '' ? $primaryError : 'unknown error'));
        if (smtp_error_is_daily_limit($primaryError)) {
            smtp_mark_quota_blocked('primary');
        }
    }

    // 2) Standby: Gmail — only after Hostinger fails or is at limit.
    if (!smtp_is_quota_blocked('fallback')) {
        $fallbackFrom = defined('SMTP_FALLBACK_FROM')
            ? trim((string) SMTP_FALLBACK_FROM)
            : 'ccspulseconnect@gmail.com';
        $ok = pulseconnect_try_smtp(
            defined('SMTP_FALLBACK_HOST') ? (string) SMTP_FALLBACK_HOST : '',
            defined('SMTP_FALLBACK_PORT') ? (int) SMTP_FALLBACK_PORT : 587,
            defined('SMTP_FALLBACK_ENCRYPTION') ? (string) SMTP_FALLBACK_ENCRYPTION : 'tls',
            defined('SMTP_FALLBACK_USERNAME') ? (string) SMTP_FALLBACK_USERNAME : '',
            defined('SMTP_FALLBACK_PASSWORD') ? (string) SMTP_FALLBACK_PASSWORD : '',
            $fallbackFrom,
            $fromName,
            $to,
            $subject,
            $body
        );
        if ($ok) {
            return true;
        }
        $fallbackError = smtp_get_last_error();
        error_log('[SMTP] ' . $logContext . ' fallback (Gmail) failed: ' . ($fallbackError !== '' ? $fallbackError : 'unknown error'));
        if (smtp_error_is_daily_limit($fallbackError)) {
            smtp_mark_quota_blocked('fallback');
        }
    }

    // 3) Last resort on Hostinger: PHP mail() from the domain address.
    $domainFrom = pulseconnect_domain_from_address();
    if (pulseconnect_native_mail($to, $subject, $body, $domainFrom)) {
        return true;
    }

    if (smtp_get_last_error() === '') {
        smtp_set_last_error('All email transports failed (Hostinger SMTP, Gmail standby, mail())');
    }
    return false;
}

function smtp_user_facing_error(): string
{
    if (smtp_is_quota_blocked('primary') && smtp_is_quota_blocked('fallback')) {
        return 'Email sending is temporarily unavailable (Hostinger and Gmail daily limits). Try again later.';
    }
    $raw = trim(smtp_get_last_error());
    return $raw !== ''
        ? 'Unable to send verification code email. Please try again later.'
        : 'Unable to send verification code email. Please try again.';
}

function smtp_ehlo_hostname(): string
{
    $configured = defined('SMTP_EHLO_HOST') ? strtolower(trim((string) SMTP_EHLO_HOST)) : '';
    if ($configured !== '' && $configured !== 'localhost' && !str_ends_with($configured, '.local')) {
        return $configured;
    }

    $host = strtolower(trim((string) ($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '')));
    $host = (string) preg_replace('/:\\d+$/', '', $host);
    if ($host !== '' && filter_var($host, FILTER_VALIDATE_IP)) {
        return '[' . $host . ']';
    }
    if ($host !== '' && $host !== 'localhost' && str_contains($host, '.') && !str_ends_with($host, '.local')) {
        return $host;
    }

    return 'ccspulseconnect.com';
}

function smtp_fail($socket, string $message): bool
{
    $reply = preg_replace('/\\s+/', ' ', trim(smtp_get_last_reply()));
    $timedOut = false;
    if (is_resource($socket)) {
        $meta = @stream_get_meta_data($socket);
        $timedOut = !empty($meta['timed_out']);
        fclose($socket);
    }
    if ($timedOut && ($reply === null || $reply === '')) {
        $message .= ' (timeout)';
    } elseif (is_string($reply) && $reply !== '') {
        $message .= ': ' . $reply;
    }
    smtp_set_last_error($message);
    return false;
}

function send_student_application_status_email(
    string $recipientEmail,
    string $fullName,
    string $status,
    string $reason = ''
): bool {
    $to = trim($recipientEmail);
    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return false;
    }

    $safeName = trim($fullName) !== '' ? trim($fullName) : 'Student';
    $normalizedStatus = strtolower(trim($status));

    if ($normalizedStatus === 'approved') {
        $subject = 'CCS PulseConnect Application Approved';
        $message = "Hello {$safeName},\n\n"
            . "Great news! Your student application has been approved by the admin.\n"
            . "You may now sign in to the CCS PulseConnect app.\n"
            . "For account security, you will be asked to verify your email code again on your next login.\n\n"
            . "Welcome to CCS PulseConnect.";
        $htmlMessage = build_status_email_html(
            $safeName,
            'Application Approved',
            'Great news! Your student application has been approved by the admin.',
            'You may now sign in to the CCS PulseConnect app. For account security, you will be asked to verify your email code again on your next login.',
            '#059669',
            '#D1FAE5',
            '#065F46'
        );
    } else {
        $subject = 'CCS PulseConnect Application Result';
        $message = "Hello {$safeName},\n\n"
            . "Thank you for your registration.\n"
            . "After review, your student application was not approved by the admin.\n";
        if (trim($reason) !== '') {
            $message .= "Reason provided: {$reason}\n";
        }
        $message .= "\nIf you believe this is an error, please contact your CCS administrator.";
        $reasonHtml = trim($reason) !== ''
            ? '<p style="margin:12px 0 0 0; color:#7F1D1D;"><strong>Reason provided:</strong> ' . htmlspecialchars($reason, ENT_QUOTES, 'UTF-8') . '</p>'
            : '';
        $htmlMessage = build_status_email_html(
            $safeName,
            'Application Not Approved',
            'Thank you for your registration. After review, your student application was not approved by the admin.',
            'If you believe this is an error, please contact your CCS administrator.',
            '#DC2626',
            '#FEE2E2',
            '#7F1D1D',
            $reasonHtml
        );
    }

    $body = build_multipart_body($message, $htmlMessage);
    return pulseconnect_deliver_email($to, $subject, $body, 'send_student_application_status_email');
}

function smtp_ssl_context_options(string $host): array
{
    require_once __DIR__ . '/curl_ssl.php';

    if (defined('SMTP_DEV_SKIP_SSL_VERIFY') && SMTP_DEV_SKIP_SSL_VERIFY) {
        return [
            'verify_peer' => false,
            'verify_peer_name' => false,
            'allow_self_signed' => true,
            'SNI_enabled' => true,
            'peer_name' => $host,
        ];
    }

    $opts = [
        'verify_peer' => true,
        'verify_peer_name' => true,
        'allow_self_signed' => false,
        'SNI_enabled' => true,
        'peer_name' => $host,
    ];

    $ca = curl_ssl_ca_bundle_path();
    if ($ca !== null) {
        $opts['cafile'] = $ca;
    }

    // Prefer TLS 1.2+ when negotiating STARTTLS.
    $cryptoMethod = 0;
    if (defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT')) {
        $cryptoMethod |= STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT;
    }
    if (defined('STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT')) {
        $cryptoMethod |= STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
    }
    if ($cryptoMethod !== 0) {
        $opts['crypto_method'] = $cryptoMethod;
    }

    return $opts;
}

function smtp_send_mail(
    string $host,
    int $port,
    string $encryption,
    string $username,
    string $password,
    string $fromAddress,
    string $fromName,
    string $toAddress,
    string $subject,
    string $rawBody
): bool {
    smtp_set_last_error('');
    smtp_set_last_reply('');
    // Gmail App Passwords are often displayed with spaces (e.g. "xxxx xxxx xxxx xxxx").
    // Normalize credentials to avoid SMTP AUTH failures caused by whitespace.
    $username = trim($username);
    $password = preg_replace('/\s+/', '', (string) $password);
    $fromAddress = trim($fromAddress);
    $toAddress = trim($toAddress);
    $ehloHost = smtp_ehlo_hostname();
    $timeoutSeconds = 20;

    $transport = $encryption === 'ssl' ? 'ssl://' : '';
    $sslOpts = smtp_ssl_context_options($host);
    $streamContext = stream_context_create(['ssl' => $sslOpts]);

    $socket = @stream_socket_client(
        $transport . $host . ':' . $port,
        $errno,
        $errstr,
        $timeoutSeconds,
        STREAM_CLIENT_CONNECT,
        $streamContext
    );
    if (!$socket) {
        smtp_set_last_error("Connect failed ({$errno}): {$errstr}");
        return false;
    }

    stream_set_timeout($socket, $timeoutSeconds);
    @stream_set_write_buffer($socket, 0);
    if (!smtp_expect($socket, [220])) {
        return smtp_fail($socket, 'No SMTP 220 greeting');
    }

    if (!smtp_command($socket, 'EHLO ' . $ehloHost, [250])) {
        return smtp_fail($socket, 'EHLO rejected');
    }

    if ($encryption === 'tls') {
        if (!smtp_command($socket, 'STARTTLS', [220])) {
            return smtp_fail($socket, 'STARTTLS rejected');
        }

        // Ensure SSL options apply to STARTTLS upgrade (not only initial connect).
        foreach ($sslOpts as $key => $value) {
            @stream_context_set_option($socket, 'ssl', $key, $value);
        }

        $cryptoMethod = (int) ($sslOpts['crypto_method'] ?? 0);
        if ($cryptoMethod === 0) {
            if (defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT')) {
                $cryptoMethod |= STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT;
            }
            if (defined('STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT')) {
                $cryptoMethod |= STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
            }
            if ($cryptoMethod === 0) {
                $cryptoMethod = STREAM_CRYPTO_METHOD_TLS_CLIENT;
            }
        }

        if (!@stream_socket_enable_crypto($socket, true, $cryptoMethod)) {
            // Local Windows/XAMPP OpenSSL often fails peer verify. Reconnect once
            // without verify so OTP mail still works in local dev. Hostinger
            // Linux typically succeeds on the first attempt with cafile.
            $isWindows = PHP_OS_FAMILY === 'Windows';
            $skipAlready = defined('SMTP_DEV_SKIP_SSL_VERIFY') && SMTP_DEV_SKIP_SSL_VERIFY;
            if (!($isWindows && !$skipAlready)) {
                $opensslError = smtp_collect_openssl_errors();
                smtp_set_last_error(
                    $opensslError !== ''
                        ? 'TLS negotiation failed: ' . $opensslError
                        : 'TLS negotiation failed'
                );
                fclose($socket);
                return false;
            }

            fclose($socket);
            $sslOpts = [
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true,
                'SNI_enabled' => true,
                'peer_name' => $host,
                'crypto_method' => $cryptoMethod,
            ];
            $streamContext = stream_context_create(['ssl' => $sslOpts]);
            $socket = @stream_socket_client(
                $transport . $host . ':' . $port,
                $errno,
                $errstr,
                $timeoutSeconds,
                STREAM_CLIENT_CONNECT,
                $streamContext
            );
            if (!$socket) {
                smtp_set_last_error("Connect failed ({$errno}): {$errstr}");
                return false;
            }
            stream_set_timeout($socket, $timeoutSeconds);
            @stream_set_write_buffer($socket, 0);
            if (!smtp_expect($socket, [220])
                || !smtp_command($socket, 'EHLO ' . $ehloHost, [250])
                || !smtp_command($socket, 'STARTTLS', [220])
            ) {
                return smtp_fail($socket, 'TLS negotiation failed');
            }
            foreach ($sslOpts as $key => $value) {
                @stream_context_set_option($socket, 'ssl', $key, $value);
            }
            if (!@stream_socket_enable_crypto($socket, true, $cryptoMethod)) {
                $opensslError = smtp_collect_openssl_errors();
                smtp_set_last_error(
                    $opensslError !== ''
                        ? 'TLS negotiation failed: ' . $opensslError
                        : 'TLS negotiation failed'
                );
                fclose($socket);
                return false;
            }
        }
        if (!smtp_command($socket, 'EHLO ' . $ehloHost, [250])) {
            return smtp_fail($socket, 'EHLO after STARTTLS rejected');
        }
    }

    if (!smtp_authenticate($socket, $username, $password)) {
        return smtp_fail($socket, smtp_get_last_error() !== '' ? smtp_get_last_error() : 'SMTP authentication failed');
    }

    if (!smtp_command($socket, 'MAIL FROM:<' . $fromAddress . '>', [250])) {
        return smtp_fail($socket, 'MAIL FROM rejected');
    }
    if (!smtp_command($socket, 'RCPT TO:<' . $toAddress . '>', [250, 251])) {
        return smtp_fail($socket, 'RCPT TO rejected');
    }
    if (!smtp_command($socket, 'DATA', [354])) {
        return smtp_fail($socket, 'DATA rejected');
    }

    $midHost = trim($ehloHost, '[]');
    if ($midHost === '' || filter_var($midHost, FILTER_VALIDATE_IP)) {
        $midHost = 'ccspulseconnect.com';
    }

    $headers = [];
    $headers[] = 'From: ' . smtp_header_encode_name($fromName) . ' <' . $fromAddress . '>';
    $headers[] = 'To: <' . $toAddress . '>';
    $headers[] = 'Subject: ' . smtp_header_encode_name($subject);
    $headers[] = 'MIME-Version: 1.0';
    $headers[] = 'Content-Type: multipart/alternative; boundary="' . EMAIL_ALT_BOUNDARY . '"';
    $headers[] = 'Date: ' . date(DATE_RFC2822);
    $headers[] = 'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . $midHost . '>';
    $headers[] = '';
    $headers[] = $rawBody;

    $data = implode("\r\n", $headers);
    // Dot-stuffing
    $data = preg_replace('/^\./m', '..', $data);
    if (fwrite($socket, $data . "\r\n.\r\n") === false) {
        return smtp_fail($socket, 'Message body write failed');
    }
    fflush($socket);
    if (!smtp_expect($socket, [250])) {
        return smtp_fail($socket, 'Message body rejected');
    }

    smtp_command($socket, 'QUIT', [221]);
    fclose($socket);
    return true;
}

function smtp_collect_openssl_errors(): string
{
    $errors = [];
    while (($error = openssl_error_string()) !== false) {
        $errors[] = $error;
    }

    return implode(' | ', $errors);
}

function smtp_authenticate($socket, string $username, string $password): bool
{
    if (!smtp_command($socket, 'AUTH LOGIN', [334])) {
        smtp_set_last_error('AUTH LOGIN rejected');
        return false;
    }
    if (!smtp_command($socket, base64_encode($username), [334])) {
        smtp_set_last_error('SMTP username rejected');
        return false;
    }
    if (!smtp_command($socket, base64_encode($password), [235])) {
        smtp_set_last_error('SMTP password rejected');
        return false;
    }
    return true;
}

function smtp_command($socket, string $command, array $expectedCodes): bool
{
    if (fwrite($socket, $command . "\r\n") === false) {
        smtp_set_last_reply('');
        return false;
    }
    fflush($socket);
    return smtp_expect($socket, $expectedCodes);
}

function smtp_expect($socket, array $expectedCodes): bool
{
    smtp_set_last_reply('');
    $response = '';
    while (($line = fgets($socket, 515)) !== false) {
        $response .= $line;
        $trimmed = rtrim($line, "\r\n");
        $len = strlen($trimmed);
        if ($len < 3 || !ctype_digit(substr($trimmed, 0, 3))) {
            continue;
        }
        // Continuation lines use hyphen after the status code (250-...).
        if ($len >= 4 && $trimmed[3] === '-') {
            continue;
        }
        break;
    }
    smtp_set_last_reply(trim($response));
    if ($response === '') {
        return false;
    }
    $code = (int) substr($response, 0, 3);
    return in_array($code, $expectedCodes, true);
}

function smtp_header_encode_name(string $value): string
{
    if ($value === '') {
        return '';
    }
    if (preg_match('/^[\x20-\x7E]+$/', $value)) {
        return $value;
    }
    return '=?UTF-8?B?' . base64_encode($value) . '?=';
}

const EMAIL_ALT_BOUNDARY = 'pulseconnect_alt_boundary_2026';

function build_multipart_headers(string $fromAddress): array
{
    return [
        'MIME-Version: 1.0',
        'Content-Type: multipart/alternative; boundary="' . EMAIL_ALT_BOUNDARY . '"',
        'From: CCS PulseConnect <' . $fromAddress . '>',
        'Reply-To: ' . $fromAddress,
        'X-Mailer: PHP/' . phpversion(),
    ];
}

function build_multipart_body(string $textPart, string $htmlPart): string
{
    $text = str_replace(["\r\n", "\r"], "\n", $textPart);
    $html = str_replace(["\r\n", "\r"], "\n", $htmlPart);
    return '--' . EMAIL_ALT_BOUNDARY . "\r\n"
        . "Content-Type: text/plain; charset=UTF-8\r\n"
        . "Content-Transfer-Encoding: 8bit\r\n\r\n"
        . $text . "\r\n\r\n"
        . '--' . EMAIL_ALT_BOUNDARY . "\r\n"
        . "Content-Type: text/html; charset=UTF-8\r\n"
        . "Content-Transfer-Encoding: 8bit\r\n\r\n"
        . $html . "\r\n\r\n"
        . '--' . EMAIL_ALT_BOUNDARY . "--\r\n";
}

function build_status_email_html(
    string $safeName,
    string $title,
    string $headline,
    string $supportText,
    string $accentColor,
    string $badgeBg,
    string $badgeText,
    string $extraHtml = ''
): string {
    $name = htmlspecialchars($safeName, ENT_QUOTES, 'UTF-8');
    $titleSafe = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
    $headlineSafe = htmlspecialchars($headline, ENT_QUOTES, 'UTF-8');
    $supportSafe = htmlspecialchars($supportText, ENT_QUOTES, 'UTF-8');

    return '<!doctype html><html><body style="margin:0;padding:0;background:#F4F4F5;font-family:Arial,sans-serif;color:#111827;">'
        . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="padding:24px 0;">'
        . '<tr><td align="center">'
        . '<table role="presentation" width="560" cellspacing="0" cellpadding="0" style="max-width:560px;background:#FFFFFF;border-radius:14px;overflow:hidden;border:1px solid #E4E4E7;">'
        . '<tr><td style="background:#111827;padding:20px 24px;">'
        . '<h1 style="margin:0;font-size:20px;color:#FFFFFF;">CCS PulseConnect</h1>'
        . '<p style="margin:6px 0 0 0;font-size:12px;color:#D4D4D8;">Student Application Status</p>'
        . '</td></tr>'
        . '<tr><td style="padding:24px;">'
        . '<span style="display:inline-block;padding:6px 10px;border-radius:999px;font-size:12px;font-weight:700;background:' . $badgeBg . ';color:' . $badgeText . ';">' . $titleSafe . '</span>'
        . '<p style="margin:16px 0 0 0;font-size:14px;color:#3F3F46;">Hello <strong>' . $name . '</strong>,</p>'
        . '<p style="margin:10px 0 0 0;font-size:15px;line-height:1.6;color:#18181B;">' . $headlineSafe . '</p>'
        . '<p style="margin:10px 0 0 0;font-size:14px;line-height:1.6;color:#52525B;">' . $supportSafe . '</p>'
        . $extraHtml
        . '<div style="margin-top:18px;height:2px;background:' . $accentColor . ';border-radius:4px;"></div>'
        . '<p style="margin:16px 0 0 0;font-size:12px;color:#71717A;">This is an automated message from CCS PulseConnect.</p>'
        . '</td></tr>'
        . '</table>'
        . '</td></tr></table>'
        . '</body></html>';
}

function send_admin_login_verification_email(
    string $recipientEmail,
    string $fullName,
    string $code
): bool {
    $to = trim($recipientEmail);
    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return false;
    }

    $safeName = trim($fullName) !== '' ? trim($fullName) : 'Administrator';
    $subject = 'CCS PulseConnect Admin Login Verification Code';
    $textMessage = "Hello {$safeName},\n\n"
        . "Use this verification code to complete your admin login: {$code}\n\n"
        . "This code expires in 5 minutes.\n"
        . "If you did not attempt to log in, please ignore this email.";

    $htmlMessage = '<!doctype html><html><body style="margin:0;padding:0;background:#F4F4F5;font-family:Arial,sans-serif;color:#111827;">'
        . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="padding:24px 0;">'
        . '<tr><td align="center">'
        . '<table role="presentation" width="560" cellspacing="0" cellpadding="0" style="max-width:560px;background:#FFFFFF;border-radius:14px;overflow:hidden;border:1px solid #E4E4E7;">'
        . '<tr><td style="background:#111827;padding:20px 24px;">'
        . '<h1 style="margin:0;font-size:20px;color:#FFFFFF;">CCS PulseConnect</h1>'
        . '<p style="margin:6px 0 0 0;font-size:12px;color:#D4D4D8;">Admin Login Verification</p>'
        . '</td></tr>'
        . '<tr><td style="padding:24px;">'
        . '<p style="margin:0 0 10px 0;font-size:14px;color:#3F3F46;">Hello <strong>' . htmlspecialchars($safeName, ENT_QUOTES, 'UTF-8') . '</strong>,</p>'
        . '<p style="margin:0 0 10px 0;font-size:15px;line-height:1.6;color:#18181B;">Use the code below to complete your admin login:</p>'
        . '<div style="display:inline-block;padding:10px 16px;border-radius:10px;background:#111827;color:#FFFFFF;font-weight:700;font-size:24px;letter-spacing:4px;">' . htmlspecialchars($code, ENT_QUOTES, 'UTF-8') . '</div>'
        . '<p style="margin:14px 0 0 0;font-size:13px;color:#52525B;">This code expires in <strong>5 minutes</strong>.</p>'
        . '<p style="margin:8px 0 0 0;font-size:12px;color:#71717A;">If you did not attempt to log in, please ignore this email.</p>'
        . '</td></tr></table>'
        . '</td></tr></table>'
        . '</body></html>';

    $body = build_multipart_body($textMessage, $htmlMessage);
    return pulseconnect_deliver_email($to, $subject, $body, 'send_admin_login_verification_email');
}

function send_teacher_login_verification_email(
    string $recipientEmail,
    string $fullName,
    string $code
): bool {
    $to = trim($recipientEmail);
    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return false;
    }

    $safeName = trim($fullName) !== '' ? trim($fullName) : 'Teacher';
    $subject = 'CCS PulseConnect Teacher Login Verification Code';
    $textMessage = "Hello {$safeName},\n\n"
        . "Use this verification code to complete your teacher login: {$code}\n\n"
        . "This code expires in 5 minutes.\n"
        . "If you did not attempt to log in, please ignore this email.";

    $htmlMessage = '<!doctype html><html><body style="margin:0;padding:0;background:#F4F4F5;font-family:Arial,sans-serif;color:#111827;">'
        . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="padding:24px 0;">'
        . '<tr><td align="center">'
        . '<table role="presentation" width="560" cellspacing="0" cellpadding="0" style="max-width:560px;background:#FFFFFF;border-radius:14px;overflow:hidden;border:1px solid #E4E4E7;">'
        . '<tr><td style="background:#111827;padding:20px 24px;">'
        . '<h1 style="margin:0;font-size:20px;color:#FFFFFF;">CCS PulseConnect</h1>'
        . '<p style="margin:6px 0 0 0;font-size:12px;color:#D4D4D8;">Teacher Login Verification</p>'
        . '</td></tr>'
        . '<tr><td style="padding:24px;">'
        . '<p style="margin:0 0 10px 0;font-size:14px;color:#3F3F46;">Hello <strong>' . htmlspecialchars($safeName, ENT_QUOTES, 'UTF-8') . '</strong>,</p>'
        . '<p style="margin:0 0 10px 0;font-size:15px;line-height:1.6;color:#18181B;">Use the code below to complete your teacher login:</p>'
        . '<div style="display:inline-block;padding:10px 16px;border-radius:10px;background:#111827;color:#FFFFFF;font-weight:700;font-size:24px;letter-spacing:4px;">' . htmlspecialchars($code, ENT_QUOTES, 'UTF-8') . '</div>'
        . '<p style="margin:14px 0 0 0;font-size:13px;color:#52525B;">This code expires in <strong>5 minutes</strong>.</p>'
        . '<p style="margin:8px 0 0 0;font-size:12px;color:#71717A;">If you did not attempt to log in, please ignore this email.</p>'
        . '</td></tr></table>'
        . '</td></tr></table>'
        . '</body></html>';

    $body = build_multipart_body($textMessage, $htmlMessage);
    return pulseconnect_deliver_email($to, $subject, $body, 'send_teacher_login_verification_email');
}

function send_password_reset_code_email(
    string $recipientEmail,
    string $fullName,
    string $code
): bool {
    $to = trim($recipientEmail);
    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return false;
    }
    $safeName = trim($fullName) !== '' ? trim($fullName) : 'User';
    $subject = 'CCS PulseConnect Password Reset Code';
    $textMessage = "Hello {$safeName},\n\n"
        . "Use this code to reset your password: {$code}\n\n"
        . "This code expires in 10 minutes.\n"
        . "If you did not request this, please ignore this email.";

    $htmlMessage = '<!doctype html><html><body style="margin:0;padding:0;background:#F4F4F5;font-family:Arial,sans-serif;color:#111827;">'
        . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="padding:24px 0;">'
        . '<tr><td align="center">'
        . '<table role="presentation" width="560" cellspacing="0" cellpadding="0" style="max-width:560px;background:#FFFFFF;border-radius:14px;overflow:hidden;border:1px solid #E4E4E7;">'
        . '<tr><td style="background:#111827;padding:20px 24px;">'
        . '<h1 style="margin:0;font-size:20px;color:#FFFFFF;">CCS PulseConnect</h1>'
        . '<p style="margin:6px 0 0 0;font-size:12px;color:#D4D4D8;">Password Reset Verification</p>'
        . '</td></tr>'
        . '<tr><td style="padding:24px;">'
        . '<p style="margin:0 0 10px 0;font-size:14px;color:#3F3F46;">Hello <strong>' . htmlspecialchars($safeName, ENT_QUOTES, 'UTF-8') . '</strong>,</p>'
        . '<p style="margin:0 0 10px 0;font-size:15px;line-height:1.6;color:#18181B;">Use the code below to reset your password:</p>'
        . '<div style="display:inline-block;padding:10px 16px;border-radius:10px;background:#111827;color:#FFFFFF;font-weight:700;font-size:24px;letter-spacing:4px;">' . htmlspecialchars($code, ENT_QUOTES, 'UTF-8') . '</div>'
        . '<p style="margin:14px 0 0 0;font-size:13px;color:#52525B;">This code expires in <strong>10 minutes</strong>.</p>'
        . '<p style="margin:8px 0 0 0;font-size:12px;color:#71717A;">If you did not request this, please ignore this email.</p>'
        . '</td></tr></table>'
        . '</td></tr></table>'
        . '</body></html>';

    $body = build_multipart_body($textMessage, $htmlMessage);
    return pulseconnect_deliver_email($to, $subject, $body, 'send_password_reset_code_email');
}

function send_teacher_account_credentials_email(
    string $recipientEmail,
    string $fullName,
    string $plainPassword
): bool {
    return send_staff_account_credentials_email($recipientEmail, $fullName, $plainPassword, 'teacher');
}

function send_staff_account_credentials_email(
    string $recipientEmail,
    string $fullName,
    string $plainPassword,
    string $roleNoun = 'teacher'
): bool {
    $to = trim($recipientEmail);
    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return false;
    }

    $roleKey = strtolower(trim($roleNoun)) === 'admin' ? 'admin' : 'teacher';
    $roleLabel = $roleKey === 'admin' ? 'Admin' : 'Teacher';
    $roleLower = $roleKey === 'admin' ? 'admin' : 'teacher';
    $safeName = trim($fullName) !== '' ? trim($fullName) : $roleLabel;
    $subject = 'PulseCONNECT ' . $roleLabel . ' Account Credentials';

    $textMessage = "Hello {$safeName},\n\n"
        . "Your {$roleLower} account has been created.\n\n"
        . "Login credentials:\n"
        . "Email: {$to}\n"
        . "Temporary Password: {$plainPassword}\n\n"
        . "Important: Please change your password after logging in.\n"
        . "Go to Settings > Change Password to set your own password.\n";

    $htmlMessage = '<!doctype html><html><body style="margin:0;padding:0;background:#F4F4F5;font-family:Arial,sans-serif;color:#111827;">'
        . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="padding:24px 0;">'
        . '<tr><td align="center">'
        . '<table role="presentation" width="560" cellspacing="0" cellpadding="0" style="max-width:560px;background:#FFFFFF;border-radius:14px;overflow:hidden;border:1px solid #E4E4E7;">'
        . '<tr><td style="background:#111827;padding:20px 24px;">'
        . '<h1 style="margin:0;font-size:20px;color:#FFFFFF;">PulseCONNECT</h1>'
        . '<p style="margin:6px 0 0 0;font-size:12px;color:#D4D4D8;">' . htmlspecialchars($roleLabel, ENT_QUOTES, 'UTF-8') . ' Account Credentials</p>'
        . '</td></tr>'
        . '<tr><td style="padding:24px;">'
        . '<p style="margin:0 0 10px 0;font-size:14px;color:#3F3F46;">Hello <strong>' . htmlspecialchars($safeName, ENT_QUOTES, 'UTF-8') . '</strong>,</p>'
        . '<p style="margin:0 0 14px 0;font-size:15px;line-height:1.6;color:#18181B;">Your ' . htmlspecialchars($roleLower, ENT_QUOTES, 'UTF-8') . ' account has been created. Use the credentials below to log in:</p>'
        . '<div style="border:1px solid #E4E4E7;border-radius:12px;padding:14px 16px;background:#FAFAFA;">'
        . '<div style="font-size:12px;color:#71717A;font-weight:700;letter-spacing:0.08em;text-transform:uppercase;margin-bottom:8px;">Login Credentials</div>'
        . '<div style="font-size:14px;color:#18181B;margin:0 0 6px 0;"><strong>Email:</strong> ' . htmlspecialchars($to, ENT_QUOTES, 'UTF-8') . '</div>'
        . '<div style="font-size:14px;color:#18181B;margin:0;"><strong>Temporary Password:</strong> <span style="font-family:Consolas,monospace;font-weight:700;">' . htmlspecialchars($plainPassword, ENT_QUOTES, 'UTF-8') . '</span></div>'
        . '</div>'
        . '<div style="margin-top:14px;padding:12px 14px;border-radius:12px;background:#FFFBEB;border:1px solid #FDE68A;color:#92400E;">'
        . '<div style="font-weight:700;margin-bottom:4px;">Important</div>'
        . '<div style="font-size:13px;line-height:1.5;">Please change your password after logging in. Go to <strong>Settings &gt; Change Password</strong> to set your own password.</div>'
        . '</div>'
        . '<p style="margin:14px 0 0 0;font-size:12px;color:#71717A;">This is an automated message from PulseCONNECT.</p>'
        . '</td></tr></table>'
        . '</td></tr></table>'
        . '</body></html>';

    return send_multipart_email($to, $subject, $textMessage, $htmlMessage);
}

function send_mobile_email_verification_code_email(
    string $recipientEmail,
    string $fullName,
    string $code
): bool {
    $to = trim($recipientEmail);
    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return false;
    }

    $safeName = trim($fullName) !== '' ? trim($fullName) : 'CCS PulseConnect User';
    // Keep the OTP out of the subject — Gmail notifications show subject + body
    // snippet, so putting the code in both made it appear twice.
    $subject = 'CCS PulseConnect Email Verification';
    $textMessage = "Hello {$safeName},\n\n"
        . "Your verification code is: {$code}\n\n"
        . "This code expires in 5 minutes.\n"
        . "If you did not request this, please ignore this email.";

    $htmlMessage = '<!doctype html><html><body style="margin:0;padding:0;background:#F4F4F5;font-family:Arial,sans-serif;color:#111827;">'
        . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="padding:24px 0;">'
        . '<tr><td align="center">'
        . '<table role="presentation" width="560" cellspacing="0" cellpadding="0" style="max-width:560px;background:#FFFFFF;border-radius:14px;overflow:hidden;border:1px solid #E4E4E7;">'
        . '<tr><td style="background:#111827;padding:20px 24px;">'
        . '<h1 style="margin:0;font-size:20px;color:#FFFFFF;">CCS PulseConnect</h1>'
        . '<p style="margin:6px 0 0 0;font-size:12px;color:#D4D4D8;">Email Verification</p>'
        . '</td></tr>'
        . '<tr><td style="padding:24px;">'
        . '<p style="margin:0 0 10px 0;font-size:14px;color:#3F3F46;">Hello <strong>' . htmlspecialchars($safeName, ENT_QUOTES, 'UTF-8') . '</strong>,</p>'
        . '<p style="margin:0 0 10px 0;font-size:15px;line-height:1.6;color:#18181B;">Your verification code is:</p>'
        . '<div style="display:inline-block;padding:10px 16px;border-radius:10px;background:#111827;color:#FFFFFF;font-weight:700;font-size:24px;letter-spacing:4px;">' . htmlspecialchars($code, ENT_QUOTES, 'UTF-8') . '</div>'
        . '<p style="margin:14px 0 0 0;font-size:13px;color:#52525B;">This code expires in <strong>5 minutes</strong>.</p>'
        . '<p style="margin:8px 0 0 0;font-size:12px;color:#71717A;">If you did not request this, please ignore this email.</p>'
        . '</td></tr></table>'
        . '</td></tr></table>'
        . '</body></html>';

    return send_multipart_email($to, $subject, $textMessage, $htmlMessage);
}

function send_application_under_review_email(
    string $recipientEmail,
    string $fullName
): bool {
    $to = trim($recipientEmail);
    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return false;
    }

    $safeName = trim($fullName) !== '' ? trim($fullName) : 'CCS PulseConnect User';
    $subject = 'CCS PulseConnect Application Under Review';
    $textMessage = "Hello {$safeName},\n\n"
        . "Your student registration has been received.\n"
        . "Your account is now under admin review.\n\n"
        . "Please wait for another email once your application is approved or rejected.";

    $htmlMessage = '<!doctype html><html><body style="margin:0;padding:0;background:#F4F4F5;font-family:Arial,sans-serif;color:#111827;">'
        . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="padding:24px 0;">'
        . '<tr><td align="center">'
        . '<table role="presentation" width="560" cellspacing="0" cellpadding="0" style="max-width:560px;background:#FFFFFF;border-radius:14px;overflow:hidden;border:1px solid #E4E4E7;">'
        . '<tr><td style="background:#111827;padding:20px 24px;">'
        . '<h1 style="margin:0;font-size:20px;color:#FFFFFF;">CCS PulseConnect</h1>'
        . '<p style="margin:6px 0 0 0;font-size:12px;color:#D4D4D8;">Application Status</p>'
        . '</td></tr>'
        . '<tr><td style="padding:24px;">'
        . '<p style="margin:0 0 10px 0;font-size:14px;color:#3F3F46;">Hello <strong>' . htmlspecialchars($safeName, ENT_QUOTES, 'UTF-8') . '</strong>,</p>'
        . '<p style="margin:0 0 10px 0;font-size:15px;line-height:1.6;color:#18181B;">Your student registration has been received.</p>'
        . '<p style="margin:0 0 10px 0;font-size:15px;line-height:1.6;color:#18181B;">Your account is now <strong>under admin review</strong>.</p>'
        . '<p style="margin:0;font-size:14px;color:#52525B;">Please wait for another email once your application is approved or rejected.</p>'
        . '</td></tr></table>'
        . '</td></tr></table>'
        . '</body></html>';

    return send_multipart_email($to, $subject, $textMessage, $htmlMessage);
}

function send_multipart_email(
    string $recipientEmail,
    string $subject,
    string $textMessage,
    string $htmlMessage
): bool {
    $to = trim($recipientEmail);
    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return false;
    }

    $body = build_multipart_body($textMessage, $htmlMessage);
    return pulseconnect_deliver_email($to, $subject, $body, 'send_multipart_email');
}

function send_event_payment_receipt_email(
    string $recipientEmail,
    string $studentName,
    string $eventTitle,
    float $amountPaid,
    string $paymentReference,
    string $paidAt
): bool {
    $to = trim($recipientEmail);
    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return false;
    }

    $safeName = trim($studentName) !== '' ? trim($studentName) : 'Student';
    $safeEventTitle = trim($eventTitle) !== '' ? trim($eventTitle) : 'Event';
    $safeReference = trim($paymentReference);
    try {
        $paidOn = (new DateTimeImmutable($paidAt !== '' ? $paidAt : 'now'))
            ->setTimezone(new DateTimeZone('Asia/Manila'))
            ->format('M j, Y g:i A') . ' PHT';
    } catch (Throwable $e) {
        $paidOn = (new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))
            ->format('M j, Y g:i A') . ' PHT';
    }

    $amountText = 'PHP ' . number_format(max(0, $amountPaid), 2);
    $subject = 'Your CCS PulseConnect payment receipt';
    $textMessage = "Hello {$safeName},\n\n"
        . "Your payment was received and your event registration is confirmed.\n\n"
        . "Event: {$safeEventTitle}\n"
        . "Amount paid: {$amountText}\n"
        . "Payment method: QR Ph via PayMongo\n"
        . "Paid on: {$paidOn}\n"
        . "Payment reference: {$safeReference}\n\n"
        . "Your event ticket is available in the CCS PulseConnect app.\n\n"
        . "Thank you,\nCCS PulseConnect";

    $nameHtml = htmlspecialchars($safeName, ENT_QUOTES, 'UTF-8');
    $eventHtml = htmlspecialchars($safeEventTitle, ENT_QUOTES, 'UTF-8');
    $referenceHtml = htmlspecialchars($safeReference, ENT_QUOTES, 'UTF-8');
    $paidOnHtml = htmlspecialchars($paidOn, ENT_QUOTES, 'UTF-8');
    $amountHtml = htmlspecialchars($amountText, ENT_QUOTES, 'UTF-8');
    $htmlMessage = '<!doctype html><html><body style="margin:0;padding:24px;background:#F4F4F5;font-family:Arial,sans-serif;color:#142A3A;">'
        . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0"><tr><td align="center">'
        . '<table role="presentation" width="560" cellspacing="0" cellpadding="0" style="max-width:560px;background:#FFFFFF;border:1px solid #E2E8F0;border-radius:16px;overflow:hidden;">'
        . '<tr><td style="background:#142A3A;padding:22px 24px;color:#FFFFFF;"><strong style="font-size:20px;">CCS PulseConnect</strong><br><span style="font-size:13px;color:#CBD5E1;">Payment receipt</span></td></tr>'
        . '<tr><td style="padding:24px;">'
        . '<p style="margin:0 0 12px;">Hello <strong>' . $nameHtml . '</strong>,</p>'
        . '<p style="margin:0 0 18px;line-height:1.6;">Your payment was received and your event registration is confirmed.</p>'
        . '<table role="presentation" width="100%" cellspacing="0" cellpadding="8" style="border-collapse:collapse;background:#F8FAFC;">'
        . '<tr><td style="color:#64748B;">Event</td><td align="right"><strong>' . $eventHtml . '</strong></td></tr>'
        . '<tr><td style="color:#64748B;">Amount paid</td><td align="right"><strong>' . $amountHtml . '</strong></td></tr>'
        . '<tr><td style="color:#64748B;">Payment method</td><td align="right">QR Ph via PayMongo</td></tr>'
        . '<tr><td style="color:#64748B;">Paid on</td><td align="right">' . $paidOnHtml . '</td></tr>'
        . '<tr><td style="color:#64748B;">Payment reference</td><td align="right"><code>' . $referenceHtml . '</code></td></tr>'
        . '</table>'
        . '<p style="margin:18px 0 0;line-height:1.6;color:#475569;">Your event ticket is available in the CCS PulseConnect app.</p>'
        . '<p style="margin:22px 0 0;color:#64748B;font-size:12px;">This is an automated payment confirmation from CCS PulseConnect.</p>'
        . '</td></tr></table></td></tr></table></body></html>';

    return send_multipart_email($to, $subject, $textMessage, $htmlMessage);
}

