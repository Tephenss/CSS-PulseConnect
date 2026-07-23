<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/session.php';
session_bootstrap();

require_once __DIR__ . '/includes/csrf.php';

if (isset($_SESSION['user']) && is_array($_SESSION['user'])) {
    header('Location: home.php');
    exit;
}

csrf_ensure_token();
?>

<!doctype html>
<html lang="en" class="dark">

<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>CCS PulseConnect — Forgot Password</title>
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
                    <p class="text-zinc-400 mt-4 text-sm leading-relaxed">
                        Reset your admin password securely with a one-time code sent to your email.
                    </p>
                </div>
            </div>
        </div>

        <div class="flex items-center justify-center p-6">
            <div class="w-full max-w-md">
                <div class="mb-6">
                    <div class="text-xs tracking-[0.35em] uppercase text-zinc-400">Account recovery</div>
                    <h2 class="text-3xl font-semibold mt-3">Forgot password</h2>
                    <p class="text-zinc-400 mt-2 text-sm">
                        Enter your email, confirm the reset code, then set a new password.
                    </p>
                </div>

                <div id="fpError" class="hidden mb-4 rounded-xl border border-red-900/50 bg-red-950/30 px-4 py-3 text-sm text-red-200"></div>
                <div id="fpSuccess" class="hidden mb-4 rounded-xl border border-emerald-900/40 bg-emerald-950/25 px-4 py-3 text-sm text-emerald-200"></div>

                <input type="hidden" id="fpCsrfToken" value="<?= htmlspecialchars((string) $_SESSION['csrf_token']) ?>" />

                <div class="rounded-2xl border border-zinc-800 bg-zinc-900/30 p-6">
                    <div id="stepEmail" class="space-y-4">
                        <label class="block text-xs text-zinc-400 mb-1" for="fpEmail">Email</label>
                        <input
                            id="fpEmail"
                            type="email"
                            required
                            class="w-full rounded-xl bg-zinc-950 border border-zinc-800 px-3 py-3 text-sm outline-none focus:ring-2 focus:ring-zinc-700"
                            placeholder="Enter Email"
                            autocomplete="email"
                        />
                        <button id="btnSendCode" class="w-full rounded-xl bg-zinc-100 text-zinc-900 px-4 py-3 font-medium hover:bg-zinc-200 transition" type="button">
                            Send confirmation code
                        </button>
                    </div>

                    <div id="stepCode" class="space-y-4 hidden">
                        <label class="block text-xs text-zinc-400 mb-1" for="fpCode">Verification Code</label>
                        <input
                            id="fpCode"
                            type="text"
                            inputmode="numeric"
                            pattern="[0-9]*"
                            maxlength="6"
                            class="w-full rounded-xl bg-zinc-950 border border-zinc-800 px-3 py-3 text-center text-lg tracking-[0.45em] font-semibold outline-none focus:ring-2 focus:ring-zinc-700"
                            placeholder="000000"
                            autocomplete="one-time-code"
                        />
                        <button id="btnVerifyCode" class="w-full rounded-xl bg-zinc-100 text-zinc-900 px-4 py-3 font-medium hover:bg-zinc-200 transition" type="button">
                            Verify code
                        </button>
                    </div>

                    <div id="stepPassword" class="space-y-4 hidden">
                        <label class="block text-xs text-zinc-400 mb-1" for="fpNewPassword">New Password</label>
                        <input
                            id="fpNewPassword"
                            type="password"
                            class="w-full rounded-xl bg-zinc-950 border border-zinc-800 px-3 py-3 text-sm outline-none focus:ring-2 focus:ring-zinc-700"
                            placeholder="At least 8 characters"
                            autocomplete="new-password"
                        />

                        <div class="h-4"></div>

                        <label class="block text-xs text-zinc-400 mb-1" for="fpConfirmPassword">Confirm New Password</label>
                        <input
                            id="fpConfirmPassword"
                            type="password"
                            class="w-full rounded-xl bg-zinc-950 border border-zinc-800 px-3 py-3 text-sm outline-none focus:ring-2 focus:ring-zinc-700"
                            placeholder="Repeat password"
                            autocomplete="new-password"
                        />

                        <div class="h-3"></div>

                        <button id="btnResetPassword" class="w-full rounded-xl bg-zinc-100 text-zinc-900 px-4 py-3 font-medium hover:bg-zinc-200 transition" type="button">
                            Change password
                        </button>
                    </div>
                </div>

                <div class="auth-foot mt-4">
                    Remembered your password?
                    <a href="login.php">Back to login</a>
                </div>

                <div class="text-center text-xs text-zinc-500 mt-6">
                    © <?= htmlspecialchars((string) date('Y')) ?> PulseCONNECT
                </div>
            </div>
        </div>
    </div>

    <script>
        const fpState = {
            email: '',
            resetToken: '',
        };

        const fpError = document.getElementById('fpError');
        const fpSuccess = document.getElementById('fpSuccess');
        const stepEmail = document.getElementById('stepEmail');
        const stepCode = document.getElementById('stepCode');
        const stepPassword = document.getElementById('stepPassword');

        function showError(msg) {
            fpSuccess.classList.add('hidden');
            fpError.textContent = msg;
            fpError.classList.remove('hidden');
        }

        function showSuccess(msg) {
            fpError.classList.add('hidden');
            fpSuccess.textContent = msg;
            fpSuccess.classList.remove('hidden');
        }

        function hideMessages() {
            fpError.classList.add('hidden');
            fpSuccess.classList.add('hidden');
        }

        function showStep(step) {
            stepEmail.classList.toggle('hidden', step !== 'email');
            stepCode.classList.toggle('hidden', step !== 'code');
            stepPassword.classList.toggle('hidden', step !== 'password');
        }

        async function postJson(url, body) {
            const res = await fetch(url, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    csrf_token: document.getElementById('fpCsrfToken').value,
                    ...body,
                }),
            });
            const data = await res.json();
            if (!res.ok || !data.ok) {
                throw new Error(data.error || 'Request failed');
            }
            return data;
        }

        document.getElementById('btnSendCode').addEventListener('click', async () => {
            hideMessages();
            const email = (document.getElementById('fpEmail').value || '').trim().toLowerCase();
            if (!email || !email.includes('@')) {
                showError('Please enter a valid email address.');
                return;
            }
            try {
                await postJson('/api/password_reset_send_code.php', { email });
                fpState.email = email;
                showSuccess('Confirmation code sent. Please check your email.');
                showStep('code');
            } catch (e) {
                showError(e.message || 'Unable to send code.');
            }
        });

        document.getElementById('btnVerifyCode').addEventListener('click', async () => {
            hideMessages();
            const code = (document.getElementById('fpCode').value || '').trim();
            if (!/^\d{6}$/.test(code)) {
                showError('Please enter the 6-digit code.');
                return;
            }
            try {
                const data = await postJson('/api/password_reset_verify_code.php', {
                    email: fpState.email,
                    code,
                });
                fpState.resetToken = data.reset_token || '';
                showSuccess('Code confirmed. You can now set a new password.');
                showStep('password');
            } catch (e) {
                showError(e.message || 'Invalid code.');
            }
        });

        document.getElementById('btnResetPassword').addEventListener('click', async () => {
            hideMessages();
            const newPassword = (document.getElementById('fpNewPassword').value || '');
            const confirmPassword = (document.getElementById('fpConfirmPassword').value || '');
            if (newPassword.length < 8) {
                showError('Password must be at least 8 characters.');
                return;
            }
            if (newPassword !== confirmPassword) {
                showError('Passwords do not match.');
                return;
            }
            try {
                await postJson('/api/password_reset_update.php', {
                    email: fpState.email,
                    reset_token: fpState.resetToken,
                    new_password: newPassword,
                });
                showSuccess('Password changed successfully. You can now login.');
                setTimeout(() => {
                    window.location.href = '/login.php';
                }, 1200);
            } catch (e) {
                showError(e.message || 'Unable to change password.');
            }
        });

        let mouseTimeout;
        let targetX = typeof window !== 'undefined' ? window.innerWidth / 2 : 0;
        let targetY = typeof window !== 'undefined' ? window.innerHeight / 2 : 0;
        let currentX = targetX;
        let currentY = targetY;

        document.addEventListener('mousemove', function (e) {
            targetX = e.clientX;
            targetY = e.clientY;

            const bg = document.querySelector('.interactive-bg');
            if (bg) {
                bg.style.opacity = '0.85';
                clearTimeout(mouseTimeout);
                mouseTimeout = setTimeout(() => {
                    bg.style.opacity = '0';
                }, 500);
            }
        });

        document.addEventListener('mouseleave', function () {
            const bg = document.querySelector('.interactive-bg');
            if (bg) {
                bg.style.opacity = '0';
            }
        });

        function animateBg() {
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
