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
    $fromAddress = EMAIL_SENDER_ADDRESS;

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

    $headers = build_multipart_headers($fromAddress);

    // Prefer SMTP transport when configured, fallback to native mail().
    $smtpHost = trim((string) SMTP_HOST);
    $smtpUser = trim((string) SMTP_USERNAME);
    $smtpPass = (string) SMTP_PASSWORD;
    if ($smtpHost !== '' && $smtpUser !== '' && $smtpPass !== '') {
        $smtpPort = SMTP_PORT > 0 ? SMTP_PORT : 587;
        $smtpEncryption = strtolower(trim((string) SMTP_ENCRYPTION));
        $smtpFromName = trim((string) SMTP_FROM_NAME);
        $smtpResult = smtp_send_mail(
            $smtpHost,
            $smtpPort,
            $smtpEncryption,
            $smtpUser,
            $smtpPass,
            $fromAddress,
            $smtpFromName,
            $to,
            $subject,
            build_multipart_body($message, $htmlMessage)
        );
        if ($smtpResult) {
            return true;
        }
        $smtpError = smtp_get_last_error();
        if ($smtpError !== '') {
            error_log('[SMTP] send_student_application_status_email failed: ' . $smtpError);
        } else {
            error_log('[SMTP] send_student_application_status_email failed: unknown error');
        }
    }

    return @mail(
        $to,
        $subject,
        build_multipart_body($message, $htmlMessage),
        implode("\r\n", $headers),
        '-f ' . $fromAddress
    );
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
    // Gmail App Passwords are often displayed with spaces (e.g. "xxxx xxxx xxxx xxxx").
    // Normalize credentials to avoid SMTP AUTH failures caused by whitespace.
    $username = trim($username);
    $password = preg_replace('/\s+/', '', (string) $password);

    $transport = $encryption === 'ssl' ? 'ssl://' : '';
    $streamContext = null;
    if (defined('SMTP_DEV_SKIP_SSL_VERIFY') && SMTP_DEV_SKIP_SSL_VERIFY) {
        $streamContext = stream_context_create([
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true,
                'SNI_enabled' => true,
                'peer_name' => $host,
            ],
        ]);
    }
    $socket = @stream_socket_client(
        $transport . $host . ':' . $port,
        $errno,
        $errstr,
        12,
        STREAM_CLIENT_CONNECT,
        $streamContext
    );
    if (!$socket) {
        smtp_set_last_error("Connect failed ({$errno}): {$errstr}");
        return false;
    }

    stream_set_timeout($socket, 12);
    if (!smtp_expect($socket, [220])) {
        smtp_set_last_error('No SMTP 220 greeting');
        fclose($socket);
        return false;
    }

    if (!smtp_command($socket, 'EHLO pulseconnect.local', [250])) {
        smtp_set_last_error('EHLO rejected');
        fclose($socket);
        return false;
    }

    if ($encryption === 'tls') {
        if (!smtp_command($socket, 'STARTTLS', [220])) {
            smtp_set_last_error('STARTTLS rejected');
            fclose($socket);
            return false;
        }
        $cryptoMethod = 0;
        // Prefer modern TLS on Windows/PHP builds where available.
        if (defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT')) {
            $cryptoMethod |= STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT;
        }
        if (defined('STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT')) {
            $cryptoMethod |= STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
        }
        if (defined('STREAM_CRYPTO_METHOD_TLSv1_1_CLIENT')) {
            $cryptoMethod |= STREAM_CRYPTO_METHOD_TLSv1_1_CLIENT;
        }
        if (defined('STREAM_CRYPTO_METHOD_TLSv1_0_CLIENT')) {
            $cryptoMethod |= STREAM_CRYPTO_METHOD_TLSv1_0_CLIENT;
        }
        if ($cryptoMethod === 0) {
            $cryptoMethod = STREAM_CRYPTO_METHOD_TLS_CLIENT;
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
        if (!smtp_command($socket, 'EHLO pulseconnect.local', [250])) {
            smtp_set_last_error('EHLO after STARTTLS rejected');
            fclose($socket);
            return false;
        }
    }

    if (!smtp_command($socket, 'AUTH LOGIN', [334])) {
        smtp_set_last_error('AUTH LOGIN rejected');
        fclose($socket);
        return false;
    }
    if (!smtp_command($socket, base64_encode($username), [334])) {
        smtp_set_last_error('SMTP username rejected');
        fclose($socket);
        return false;
    }
    if (!smtp_command($socket, base64_encode($password), [235])) {
        smtp_set_last_error('SMTP password rejected');
        fclose($socket);
        return false;
    }

    if (!smtp_command($socket, 'MAIL FROM:<' . $fromAddress . '>', [250])) {
        smtp_set_last_error('MAIL FROM rejected');
        fclose($socket);
        return false;
    }
    if (!smtp_command($socket, 'RCPT TO:<' . $toAddress . '>', [250, 251])) {
        smtp_set_last_error('RCPT TO rejected');
        fclose($socket);
        return false;
    }
    if (!smtp_command($socket, 'DATA', [354])) {
        smtp_set_last_error('DATA rejected');
        fclose($socket);
        return false;
    }

    $headers = [];
    $headers[] = 'From: ' . smtp_header_encode_name($fromName) . ' <' . $fromAddress . '>';
    $headers[] = 'To: <' . $toAddress . '>';
    $headers[] = 'Subject: ' . smtp_header_encode_name($subject);
    $headers[] = 'MIME-Version: 1.0';
    $headers[] = 'Content-Type: multipart/alternative; boundary="' . EMAIL_ALT_BOUNDARY . '"';
    $headers[] = 'Date: ' . date(DATE_RFC2822);
    $headers[] = '';
    $headers[] = $rawBody;

    $data = implode("\r\n", $headers);
    // Dot-stuffing
    $data = preg_replace('/^\./m', '..', $data);
    fwrite($socket, $data . "\r\n.\r\n");
    if (!smtp_expect($socket, [250])) {
        smtp_set_last_error('Message body rejected');
        fclose($socket);
        return false;
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

function smtp_command($socket, string $command, array $expectedCodes): bool
{
    fwrite($socket, $command . "\r\n");
    return smtp_expect($socket, $expectedCodes);
}

function smtp_expect($socket, array $expectedCodes): bool
{
    $response = '';
    while (($line = fgets($socket, 515)) !== false) {
        $response .= $line;
        if (strlen($line) < 4) {
            continue;
        }
        // Multi-line SMTP responses use hyphen after code.
        if ($line[3] === ' ') {
            break;
        }
    }
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

    $headers = build_multipart_headers(EMAIL_SENDER_ADDRESS);
    $body = build_multipart_body($textMessage, $htmlMessage);

    $smtpHost = trim((string) SMTP_HOST);
    $smtpUser = trim((string) SMTP_USERNAME);
    $smtpPass = (string) SMTP_PASSWORD;
    if ($smtpHost !== '' && $smtpUser !== '' && $smtpPass !== '') {
        $smtpPort = SMTP_PORT > 0 ? SMTP_PORT : 587;
        $smtpEncryption = strtolower(trim((string) SMTP_ENCRYPTION));
        $smtpFromName = trim((string) SMTP_FROM_NAME);
        $smtpResult = smtp_send_mail(
            $smtpHost,
            $smtpPort,
            $smtpEncryption,
            $smtpUser,
            $smtpPass,
            EMAIL_SENDER_ADDRESS,
            $smtpFromName,
            $to,
            $subject,
            $body
        );
        if ($smtpResult) {
            return true;
        }
    }

    return @mail($to, $subject, $body, implode("\r\n", $headers), '-f ' . EMAIL_SENDER_ADDRESS);
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

    $headers = build_multipart_headers(EMAIL_SENDER_ADDRESS);
    $body = build_multipart_body($textMessage, $htmlMessage);

    $smtpHost = trim((string) SMTP_HOST);
    $smtpUser = trim((string) SMTP_USERNAME);
    $smtpPass = (string) SMTP_PASSWORD;
    if ($smtpHost !== '' && $smtpUser !== '' && $smtpPass !== '') {
        $smtpPort = SMTP_PORT > 0 ? SMTP_PORT : 587;
        $smtpEncryption = strtolower(trim((string) SMTP_ENCRYPTION));
        $smtpFromName = trim((string) SMTP_FROM_NAME);
        $smtpResult = smtp_send_mail(
            $smtpHost,
            $smtpPort,
            $smtpEncryption,
            $smtpUser,
            $smtpPass,
            EMAIL_SENDER_ADDRESS,
            $smtpFromName,
            $to,
            $subject,
            $body
        );
        if ($smtpResult) {
            return true;
        }
    }

    return @mail($to, $subject, $body, implode("\r\n", $headers), '-f ' . EMAIL_SENDER_ADDRESS);
}

function send_teacher_account_credentials_email(
    string $recipientEmail,
    string $fullName,
    string $plainPassword
): bool {
    $to = trim($recipientEmail);
    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return false;
    }

    $safeName = trim($fullName) !== '' ? trim($fullName) : 'Teacher';
    $subject = 'PulseCONNECT Teacher Account Credentials';

    $textMessage = "Hello {$safeName},\n\n"
        . "Your teacher account has been created.\n\n"
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
        . '<p style="margin:6px 0 0 0;font-size:12px;color:#D4D4D8;">Teacher Account Credentials</p>'
        . '</td></tr>'
        . '<tr><td style="padding:24px;">'
        . '<p style="margin:0 0 10px 0;font-size:14px;color:#3F3F46;">Hello <strong>' . htmlspecialchars($safeName, ENT_QUOTES, 'UTF-8') . '</strong>,</p>'
        . '<p style="margin:0 0 14px 0;font-size:15px;line-height:1.6;color:#18181B;">Your teacher account has been created. Use the credentials below to log in:</p>'
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

    $headers = build_multipart_headers(EMAIL_SENDER_ADDRESS);
    $body = build_multipart_body($textMessage, $htmlMessage);

    $smtpHost = trim((string) SMTP_HOST);
    $smtpUser = trim((string) SMTP_USERNAME);
    $smtpPass = (string) SMTP_PASSWORD;
    if ($smtpHost !== '' && $smtpUser !== '' && $smtpPass !== '') {
        $smtpPort = SMTP_PORT > 0 ? SMTP_PORT : 587;
        $smtpEncryption = strtolower(trim((string) SMTP_ENCRYPTION));
        $smtpFromName = trim((string) SMTP_FROM_NAME);
        $smtpResult = smtp_send_mail(
            $smtpHost,
            $smtpPort,
            $smtpEncryption,
            $smtpUser,
            $smtpPass,
            EMAIL_SENDER_ADDRESS,
            $smtpFromName,
            $to,
            $subject,
            $body
        );
        if ($smtpResult) {
            return true;
        }
    }

    return @mail($to, $subject, $body, implode("\r\n", $headers), '-f ' . EMAIL_SENDER_ADDRESS);
}

