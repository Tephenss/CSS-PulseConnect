<?php
declare(strict_types=1);

session_start();

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/supabase.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/csrf.php';

// If already logged in, go to dashboard.
if (isset($_SESSION['user']) && is_array($_SESSION['user'])) {
    header('Location: home.php');
    return;
}

// CSRF token (basic protection).
csrf_ensure_token();

$error = null;
$old = [
    'email' => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $csrfToken = isset($_POST['csrf_token']) ? (string) $_POST['csrf_token'] : null;
        $expected = isset($_SESSION['csrf_token']) && is_string($_SESSION['csrf_token']) ? $_SESSION['csrf_token'] : '';
        if ($expected === '' || $csrfToken === null || $csrfToken === '' || !hash_equals($expected, $csrfToken)) {
            $error = 'Invalid request. Please refresh and try again.';
            throw new RuntimeException('Invalid CSRF token');
        }

        $email = isset($_POST['email']) ? strtolower(clean_string((string) $_POST['email'])) : '';
        $password = isset($_POST['password']) ? (string) $_POST['password'] : '';

        $old['email'] = $email;

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Invalid email or password.';
        } elseif ($password === '') {
            $error = 'Invalid email or password.';
        } else {
            // Query user by email.
            // Supabase PostgREST filter: email=eq.<value>
            $filterEmail = rawurlencode($email);
            $url = rtrim(SUPABASE_URL, '/') . '/rest/v1/' . SUPABASE_TABLE_USERS
                . '?select=id,first_name,middle_name,last_name,suffix,email,role,password&email=eq.' . $filterEmail
                . '&limit=1';

            $headers = [
                'Accept: application/json',
                'apikey: ' . SUPABASE_KEY,
                'Authorization: Bearer ' . SUPABASE_KEY,
            ];

            $res = supabase_request('GET', $url, $headers);

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
                        // Prevent session fixation.
                        session_regenerate_id(true);
                        $first = (string) ($user['first_name'] ?? '');
                        $middle = (string) ($user['middle_name'] ?? '');
                        $last = (string) ($user['last_name'] ?? '');
                        $suffix = (string) ($user['suffix'] ?? '');
                        $fullName = build_display_name($first, $middle, $last, $suffix);
                        $role = isset($user['role']) ? (string) $user['role'] : 'student';
                        $_SESSION['user'] = [
                            'id' => isset($user['id']) ? (string) $user['id'] : '',
                            'full_name' => $fullName,
                            'email' => (string) ($user['email'] ?? $email),
                            'role' => $role,
                        ];

                        header('Location: home.php');
                        exit;
                    }
                }
            }
        }
    } catch (Throwable $e) {
        $error = 'Login failed. Please try again.';
    }
}
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
                    <div class="text-xs tracking-[0.35em] uppercase text-zinc-400">CCS PulseConnect</div>
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
                    <div class="text-xs tracking-[0.35em] uppercase text-zinc-400">Welcome back</div>
                    <h2 class="text-3xl font-semibold mt-3">Log in</h2>
                    <p class="text-zinc-400 mt-2 text-sm">Use your email and password to continue.</p>
                </div>

                <?php if ($error): ?>
                    <div class="mb-4 rounded-xl border border-red-900/50 bg-red-950/30 px-4 py-3 text-sm text-red-200">
                        <?= htmlspecialchars($error) ?>
                    </div>
                <?php endif; ?>

                <form method="POST" class="rounded-2xl border border-zinc-800 bg-zinc-900/30 p-6">
                    <input type="hidden" name="csrf_token"
                        value="<?= htmlspecialchars((string) $_SESSION['csrf_token']) ?>" />

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

                    <div class="flex items-center justify-between text-xs text-zinc-400 mt-4">
                        <a class="text-zinc-200 hover:underline" href="/forgot_password.php">Forgot password?</a>
                        <a class="text-zinc-200 hover:underline" href="/register.php">Create account</a>
                    </div>

                </form>

                <div class="text-center text-xs text-zinc-500 mt-6">
                    © <?= htmlspecialchars((string) date('Y')) ?> CCS PulseConnect
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