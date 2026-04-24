<?php
declare(strict_types=1);

session_start();

require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/auth.php';

// If logged in already, go home.
if (current_user() !== null) {
    header('Location: home.php');
    exit;
}

render_header('Forgot password', null);
?>

<div class="max-w-md mx-auto">
  <div class="rounded-2xl border border-zinc-800 bg-zinc-900/30 p-6">
    <div class="text-xs tracking-[0.35em] uppercase text-zinc-400">CCS PulseConnect</div>
    <h1 class="text-3xl font-semibold mt-3">Forgot Password</h1>
    <p class="text-zinc-400 text-sm mt-2">Enter your email, confirm the reset code, then set a new password.</p>

    <div id="fpError" class="hidden mt-4 rounded-lg border border-red-900/50 bg-red-950/30 px-4 py-3 text-sm text-red-200"></div>
    <div id="fpSuccess" class="hidden mt-4 rounded-lg border border-emerald-900/50 bg-emerald-950/30 px-4 py-3 text-sm text-emerald-200"></div>

    <input type="hidden" id="fpCsrfToken" value="<?= htmlspecialchars((string) csrf_ensure_token()) ?>" />

    <div class="mt-6 space-y-5">
      <div id="stepEmail" class="space-y-4">
        <div>
          <label class="block text-xs text-zinc-400 mb-1" for="fpEmail">Email Address</label>
          <input
            id="fpEmail"
            type="email"
            required
            class="w-full rounded-xl bg-zinc-950 border border-zinc-800 px-3 py-3 text-sm outline-none focus:ring-2 focus:ring-zinc-700"
            placeholder="name@email.com"
          />
        </div>
        <button id="btnSendCode" class="w-full rounded-xl bg-zinc-100 text-zinc-900 px-4 py-3 text-sm font-medium hover:bg-zinc-200 transition" type="button">
          Send confirmation code
        </button>
      </div>

      <div id="stepCode" class="space-y-4 hidden">
        <div>
          <label class="block text-xs text-zinc-400 mb-1" for="fpCode">Confirmation Code</label>
          <input
            id="fpCode"
            type="text"
            maxlength="6"
            class="w-full rounded-xl bg-zinc-950 border border-zinc-800 px-3 py-3 text-sm outline-none focus:ring-2 focus:ring-zinc-700"
            placeholder="6-digit code"
          />
        </div>
        <button id="btnVerifyCode" class="w-full rounded-xl bg-zinc-100 text-zinc-900 px-4 py-3 text-sm font-medium hover:bg-zinc-200 transition" type="button">
          Verify code
        </button>
      </div>

      <div id="stepPassword" class="space-y-4 hidden">
        <div>
          <label class="block text-xs text-zinc-400 mb-1" for="fpNewPassword">New Password</label>
          <input
            id="fpNewPassword"
            type="password"
            class="w-full rounded-xl bg-zinc-950 border border-zinc-800 px-3 py-3 text-sm outline-none focus:ring-2 focus:ring-zinc-700"
            placeholder="At least 8 characters"
          />
        </div>
        <div>
          <label class="block text-xs text-zinc-400 mb-1" for="fpConfirmPassword">Confirm New Password</label>
          <input
            id="fpConfirmPassword"
            type="password"
            class="w-full rounded-xl bg-zinc-950 border border-zinc-800 px-3 py-3 text-sm outline-none focus:ring-2 focus:ring-zinc-700"
            placeholder="Repeat password"
          />
        </div>
        <button id="btnResetPassword" class="w-full rounded-xl bg-zinc-100 text-zinc-900 px-4 py-3 text-sm font-medium hover:bg-zinc-200 transition" type="button">
          Change password
        </button>
      </div>

      <div class="text-center text-xs text-zinc-400">
        Remembered your password?
        <a class="text-zinc-200 hover:underline" href="/login.php">Login</a>
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
</script>

<?php render_footer(); ?>

