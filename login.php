<?php
declare(strict_types=1);

session_start();

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/supabase.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/email_notifications.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

// If already logged in, go to dashboard.
if (isset($_SESSION['user']) && is_array($_SESSION['user'])) {
    header('Location: home.php');
    return;
}

// CSRF token (basic protection).
csrf_ensure_token();

$error = null;
$info = null;
$old = [
    'email' => '',
];
$challenge = (isset($_SESSION['admin_login_challenge']) && is_array($_SESSION['admin_login_challenge']))
    ? $_SESSION['admin_login_challenge']
    : null;
$isVerificationStep = isset($_GET['step']) && (string) $_GET['step'] === 'verify';

function admin_login_headers(): array
{
    return [
        'Accept: application/json',
        'apikey: ' . SUPABASE_KEY,
        'Authorization: Bearer ' . SUPABASE_KEY,
    ];
}

function admin_login_generate_code(): string
{
    return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
}

function admin_login_store_code(string $userId, string $code): bool
{
    $now = gmdate('c');
    $expiresAt = gmdate('c', time() + 300);
    $payload = [
        'user_id' => $userId,
        'code' => $code,
        'expires_at' => $expiresAt,
        'created_at' => $now,
        'last_sent_at' => $now,
    ];

    $res = supabase_request(
        'POST',
        rtrim(SUPABASE_URL, '/') . '/rest/v1/email_verification_codes',
        [
            'Content-Type: application/json',
            'Accept: application/json',
            'apikey: ' . SUPABASE_KEY,
            'Authorization: Bearer ' . SUPABASE_KEY,
            'Prefer: resolution=merge-duplicates,return=representation',
        ],
        json_encode($payload, JSON_UNESCAPED_SLASHES)
    );

    return $res['ok'] === true;
}

function admin_login_fetch_code_row(string $userId): ?array
{
    $res = supabase_request(
        'GET',
        rtrim(SUPABASE_URL, '/') . '/rest/v1/email_verification_codes'
            . '?select=user_id,code,expires_at'
            . '&user_id=eq.' . rawurlencode($userId)
            . '&limit=1',
        admin_login_headers()
    );

    if (!$res['ok']) {
        return null;
    }

    $rows = json_decode((string) $res['body'], true);
    return (is_array($rows) && isset($rows[0]) && is_array($rows[0])) ? $rows[0] : null;
}

function admin_login_delete_code(string $userId): void
{
    supabase_request(
        'DELETE',
        rtrim(SUPABASE_URL, '/') . '/rest/v1/email_verification_codes'
            . '?user_id=eq.' . rawurlencode($userId),
        admin_login_headers()
    );
}

function admin_login_issue_challenge(array $user, ?string &$error, ?string &$info): bool
{
    $userId = trim((string) ($user['id'] ?? ''));
    $email = trim((string) ($user['email'] ?? ''));
    $fullName = trim((string) ($user['full_name'] ?? ''));
    if ($fullName === '') {
        $fullName = build_display_name(
            (string) ($user['first_name'] ?? ''),
            (string) ($user['middle_name'] ?? ''),
            (string) ($user['last_name'] ?? ''),
            (string) ($user['suffix'] ?? '')
        );
    }

    if ($userId === '' || $email === '') {
        $error = 'Missing admin account details. Please try again.';
        return false;
    }

    $code = admin_login_generate_code();
    if (!admin_login_store_code($userId, $code)) {
        $error = 'Unable to prepare the verification code. Please try again.';
        return false;
    }

    $sent = send_admin_login_verification_email($email, $fullName, $code);
    if (!$sent) {
        $smtpDebug = function_exists('smtp_get_last_error') ? smtp_get_last_error() : '';
        $error = $smtpDebug !== ''
            ? 'Unable to send verification code email: ' . $smtpDebug
            : 'Unable to send verification code email. Please try again.';
        return false;
    }

    $_SESSION['admin_login_challenge'] = [
        'id' => $userId,
        'full_name' => $fullName !== '' ? $fullName : 'Admin',
        'email' => $email,
        'role' => 'admin',
        'issued_at' => time(),
    ];

    $maskedEmail = preg_replace('/(^.).*(@.*$)/', '$1••••$2', $email) ?: $email;
    $info = 'A 6-digit verification code was sent to ' . $maskedEmail . '.';
    return true;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    if ($challenge !== null && !$isVerificationStep) {
        admin_login_delete_code((string) ($challenge['id'] ?? ''));
        unset($_SESSION['admin_login_challenge']);
        $challenge = null;
        if (!empty($_GET)) {
            header('Location: login.php');
            exit;
        }
    } elseif ($challenge === null && $isVerificationStep) {
        header('Location: login.php');
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $csrfToken = isset($_POST['csrf_token']) ? (string) $_POST['csrf_token'] : null;
        $expected = isset($_SESSION['csrf_token']) && is_string($_SESSION['csrf_token']) ? $_SESSION['csrf_token'] : '';
        if ($expected === '' || $csrfToken === null || $csrfToken === '' || !hash_equals($expected, $csrfToken)) {
            $error = 'Invalid request. Please refresh and try again.';
            throw new RuntimeException('Invalid CSRF token');
        }

        $authStep = strtolower(trim((string) ($_POST['auth_step'] ?? 'credentials')));

        if ($authStep === 'resend_code') {
            if ($challenge === null) {
                $error = 'Verification session expired. Please log in again.';
            } else {
                admin_login_issue_challenge([
                    'id' => $challenge['id'] ?? '',
                    'first_name' => '',
                    'middle_name' => '',
                    'last_name' => $challenge['full_name'] ?? '',
                    'suffix' => '',
                    'email' => $challenge['email'] ?? '',
                ], $error, $info);
                $challenge = $_SESSION['admin_login_challenge'] ?? $challenge;
                if ($error === null) {
                    header('Location: login.php?step=verify');
                    exit;
                }
            }
        } elseif ($authStep === 'verify_code') {
            if ($challenge === null) {
                $error = 'Verification session expired. Please log in again.';
            } else {
                $enteredCode = trim((string) ($_POST['verification_code'] ?? ''));
                if ($enteredCode === '' || !preg_match('/^\d{6}$/', $enteredCode)) {
                    $error = 'Enter the 6-digit verification code.';
                } else {
                    $row = admin_login_fetch_code_row((string) ($challenge['id'] ?? ''));
                    if ($row === null) {
                        $error = 'Verification code not found. Please resend the code.';
                    } else {
                        $storedCode = trim((string) ($row['code'] ?? ''));
                        $expiresAt = isset($row['expires_at']) ? strtotime((string) $row['expires_at']) : false;
                        if ($expiresAt === false || time() > $expiresAt) {
                            $error = 'Verification code expired. Please resend the code.';
                        } elseif (!hash_equals($storedCode, $enteredCode)) {
                            $error = 'Invalid verification code.';
                        } else {
                            admin_login_delete_code((string) ($challenge['id'] ?? ''));
                            session_regenerate_id(true);
                            $_SESSION['user'] = [
                                'id' => (string) ($challenge['id'] ?? ''),
                                'full_name' => (string) ($challenge['full_name'] ?? 'Admin'),
                                'email' => (string) ($challenge['email'] ?? ''),
                                'role' => 'admin',
                            ];
                            unset($_SESSION['admin_login_challenge']);
                            header('Location: home.php');
                            exit;
                        }
                    }
                }
            }
        } else {
            $email = isset($_POST['email']) ? strtolower(clean_string((string) $_POST['email'])) : '';
            $password = isset($_POST['password']) ? (string) $_POST['password'] : '';

            $old['email'] = $email;

            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $error = 'Invalid email or password.';
            } elseif ($password === '') {
                $error = 'Invalid email or password.';
            } else {
                $filterEmail = rawurlencode($email);
                $url = rtrim(SUPABASE_URL, '/') . '/rest/v1/' . SUPABASE_TABLE_USERS
                    . '?select=id,first_name,middle_name,last_name,suffix,email,role,password&email=eq.' . $filterEmail
                    . '&limit=1';

                $res = supabase_request('GET', $url, admin_login_headers());

                if (!$res['ok']) {
                    $error = build_error(
                        $res['body'] ?? null,
                        (int) ($res['status'] ?? 0),
                        $res['error'] ?? null,
                        'Login failed'
                    );
                } else {
                    $decoded = is_string($res['body']) ? json_decode($res['body'], true) : null;
                    $rows = is_array($decoded) ? $decoded : [];

                    if (count($rows) < 1 || !isset($rows[0]['password'])) {
                        $error = 'Invalid email or password.';
                    } else {
                        $user = $rows[0];
                        $storedHash = (string) $user['password'];

                        if (!password_verify($password, $storedHash)) {
                            $error = 'Invalid email or password.';
                        } else {
                            $role = isset($user['role']) ? (string) $user['role'] : 'student';

                            if ($role !== 'admin') {
                                $error = 'Students and Teachers must use the new mobile app to login.';
                            } else {
                                if (admin_login_issue_challenge($user, $error, $info)) {
                                    header('Location: login.php?step=verify');
                                    exit;
                                }
                            }
                        }
                    }
                }
            }
        }
    } catch (Throwable $e) {
        $error = 'Login failed. Please try again.';
    }
}
$isVerificationMode = $challenge !== null && $isVerificationStep;
?>

<!doctype html>
<html lang="en" class="dark">

<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>CCS PulseConnect — Login</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="/assets/css/app.css" />
    <link rel="stylesheet" href="/assets/css/auth.css" />
</head>

<body class="min-h-screen bg-zinc-950 text-zinc-100 auth-login-bg">
    <div class="interactive-bg"></div>
    <div class="min-h-screen grid grid-cols-1 lg:grid-cols-2 relative z-10">
        <div class="hidden lg:flex items-center justify-center p-10">
            <div class="max-w-md w-full">
                <div class="logo-collision-wrapper">
                    <img src="/assets/BSIT.png" alt="BSIT" class="logo-bsit" />
                    <img src="/assets/CS.png" alt="CS" class="logo-cs" />
                    <div class="collision-flash"></div>
                    <!-- Lightning & Spark Effects -->
                    <div class="lightning-strike"></div>
                    <div class="spark spark-1"></div>
                    <div class="spark spark-2"></div>
                    <div class="spark spark-3"></div>
                    <div class="spark spark-4"></div>
                    <img src="/assets/CCS.png" alt="CCS" class="logo-ccs" />
                </div>
                <div class="text-center mt-6">
                    <div class="text-xs tracking-[0.35em] uppercase text-zinc-400 font-bold">PulseCONNECT</div>
                    <h1 class="text-3xl font-semibold mt-2 leading-tight">Event Management System</h1>
                    <p class="text-zinc-400 mt-4 text-sm leading-relaxed min-h-[48px]">
                        <span
                            id="loginDescTyped"
                            data-full-text="Register for events, get your QR e-ticket, scan attendance, and download certificates."></span>
                        <span id="loginDescCaret" class="inline-block w-2 text-zinc-400 align-baseline"></span>
                        <noscript>Register for events, get your QR e-ticket, scan attendance, and download certificates.</noscript>
                    </p>
                </div>
            </div>
        </div>

        <div class="flex items-center justify-center p-6">
            <div class="w-full max-w-md">
                <div class="mb-6">
                    <div class="text-xs tracking-[0.35em] uppercase text-zinc-400">
                        <?= $isVerificationMode ? 'Verification required' : 'Welcome back' ?>
                    </div>
                    <h2 class="text-3xl font-semibold mt-3">
                        <?= $isVerificationMode ? 'Verify admin login' : 'Log in' ?>
                    </h2>
                    <p class="text-zinc-400 mt-2 text-sm">
                        <?= $isVerificationMode
                            ? 'Enter the 6-digit code sent to your admin email to continue.'
                            : 'Use your email and password to continue.' ?>
                    </p>
                </div>

                <?php if ($info): ?>
                    <div class="mb-4 rounded-xl border border-emerald-900/40 bg-emerald-950/25 px-4 py-3 text-sm text-emerald-200">
                        <?= htmlspecialchars($info) ?>
                    </div>
                <?php endif; ?>

                <?php if ($error): ?>
                    <div class="mb-4 rounded-xl border border-red-900/50 bg-red-950/30 px-4 py-3 text-sm text-red-200">
                        <?= htmlspecialchars($error) ?>
                    </div>
                <?php endif; ?>

                <form method="POST" class="rounded-2xl border border-zinc-800 bg-zinc-900/30 p-6">
                    <input type="hidden" name="csrf_token"
                        value="<?= htmlspecialchars((string) $_SESSION['csrf_token']) ?>" />
                    <?php if ($isVerificationMode): ?>
                        <input type="hidden" name="auth_step" value="verify_code" />
                        <div class="mb-4 rounded-xl border border-zinc-800 bg-zinc-950/70 px-4 py-3">
                            <div class="text-[11px] uppercase tracking-[0.26em] text-zinc-500">Admin email</div>
                            <div class="mt-1 text-sm font-semibold text-zinc-100">
                                <?= htmlspecialchars((string) ($challenge['email'] ?? '')) ?>
                            </div>
                        </div>

                        <label class="block text-xs text-zinc-400 mb-1" for="verification_code">Verification Code</label>
                        <input id="verification_code" name="verification_code" type="text" inputmode="numeric"
                            pattern="[0-9]*" maxlength="6" required
                            class="w-full rounded-xl bg-zinc-950 border border-zinc-800 px-3 py-3 text-center text-lg tracking-[0.45em] font-semibold outline-none focus:ring-2 focus:ring-zinc-700"
                            placeholder="000000" autocomplete="one-time-code" />

                        <div class="h-5"></div>

                        <button type="submit"
                            class="w-full rounded-xl bg-zinc-100 text-zinc-900 px-4 py-3 font-medium hover:bg-zinc-200 transition">
                            Verify and Continue
                        </button>

                        <div class="mt-4">
                            <button type="submit" name="auth_step" value="resend_code"
                                class="w-full rounded-xl border border-zinc-700 bg-zinc-950 px-4 py-3 text-sm font-medium text-zinc-100 hover:bg-zinc-900 transition">
                                Resend Code
                            </button>
                        </div>
                    <?php else: ?>
                        <input type="hidden" name="auth_step" value="credentials" />

                        <label class="block text-xs text-zinc-400 mb-1" for="email">Email</label>
                        <input id="email" name="email" type="email" value="<?= htmlspecialchars((string) $old['email']) ?>"
                            required
                            class="w-full rounded-xl bg-zinc-950 border border-zinc-800 px-3 py-3 text-sm outline-none focus:ring-2 focus:ring-zinc-700"
                            placeholder="Enter Email" autocomplete="email" />

                        <div class="h-4"></div>

                        <label class="block text-xs text-zinc-400 mb-1" for="password">Password</label>
                        <input id="password" name="password" type="password" required
                            class="w-full rounded-xl bg-zinc-950 border border-zinc-800 px-3 py-3 text-sm outline-none focus:ring-2 focus:ring-zinc-700"
                            placeholder="Your password" autocomplete="current-password" />

                        <div class="h-5"></div>

                        <button type="submit"
                            class="w-full rounded-xl bg-zinc-100 text-zinc-900 px-4 py-3 font-medium hover:bg-zinc-200 transition">
                            Login
                        </button>
                    <?php endif; ?>

                </form>

                <div class="text-center text-xs text-zinc-500 mt-6">
                    © <?= htmlspecialchars((string) date('Y')) ?> PulseCONNECT
                </div>
            </div>
        </div>
    </div>
    <script>
        // Typewriter effect for the login description (PC/desktop).
        (function () {
            var el = document.getElementById('loginDescTyped');
            var caret = document.getElementById('loginDescCaret');
            if (!el || !caret) return;

            var full = el.getAttribute('data-full-text') || '';
            var i = 0;
            var pauseAfterCompleteMs = 3000; // restart 3 seconds after typing finishes
            var caretOn = true;

            function render() {
                caret.textContent = caretOn ? '|' : '';
                el.textContent = full.slice(0, i);
            }

            // Typing speed per character (ms).
            // Keeps the typing readable; the 3s restart is handled after completion.
            var typingSpeedMs = 22;
            var typingTimer = null;
            var restartTimeout = null;

            function start() {
                // Reset and start typing again.
                i = 0;
                el.textContent = '';
                caret.textContent = caretOn ? '|' : '';

                if (typingTimer) window.clearInterval(typingTimer);
                if (restartTimeout) window.clearTimeout(restartTimeout);
                typingTimer = window.setInterval(function () {
                    i++;
                    render();
                    if (i >= full.length) {
                        window.clearInterval(typingTimer);
                        typingTimer = null;
                        // Wait 3 seconds after completion before restarting.
                        restartTimeout = window.setTimeout(function () {
                            start();
                        }, pauseAfterCompleteMs);
                    }
                }, typingSpeedMs);
            }

            // Blinking caret (independent of typing).
            window.setInterval(function () {
                caretOn = !caretOn;
                render();
            }, 520);

            start();
        })();

        let mouseTimeout;
        let targetX = typeof window !== 'undefined' ? window.innerWidth / 2 : 0;
        let targetY = typeof window !== 'undefined' ? window.innerHeight / 2 : 0;
        let currentX = targetX, currentY = targetY;

        document.addEventListener('mousemove', function (e) {
            targetX = e.clientX;
            targetY = e.clientY;

            const bg = document.querySelector('.interactive-bg');
            if (bg) {
                bg.style.opacity = '0.85'; // Show on move

                clearTimeout(mouseTimeout);
                mouseTimeout = setTimeout(() => {
                    bg.style.opacity = '0'; // Hide after stop
                }, 500);
            }
        });

        document.addEventListener('mouseleave', function () {
            const bg = document.querySelector('.interactive-bg');
            if (bg) bg.style.opacity = '0';
        });

        function animateBg() {
            // Linear Interpolation for smooth trailing delay
            currentX += (targetX - currentX) * 0.06;
            currentY += (targetY - currentY) * 0.06;

            const bg = document.querySelector('.interactive-bg');
            if (bg) {
                bg.style.setProperty('--mouse-x', currentX + 'px');
                bg.style.setProperty('--mouse-y', currentY + 'px');
            }

            requestAnimationFrame(animateBg);
        }
        animateBg();
    </script>
</body>

</html>
