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

$error = null;
$success = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = isset($_POST['email']) ? strtolower(trim((string) $_POST['email'])) : '';
    $csrf = isset($_POST['csrf_token']) ? (string) $_POST['csrf_token'] : null;
    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } elseif (!function_exists('csrf_validate')) {
        $error = 'CSRF missing.';
    } else {
        // Validate CSRF (HTML form)
        csrf_validate($csrf);
        // Web-only placeholder (admin/manual reset for now).
        $success = 'If this email exists, an admin reset will be prepared. For now, password resets are handled manually.';
    }
}

render_header('Forgot password', null);
?>

<div class="max-w-md mx-auto">
  <div class="rounded-2xl border border-zinc-800 bg-zinc-900/30 p-6">
    <div class="text-xs tracking-[0.35em] uppercase text-zinc-400">CCS PulseConnect</div>
    <h1 class="text-3xl font-semibold mt-3">Forgot Password</h1>
    <p class="text-zinc-400 text-sm mt-2">Enter your email to request a password reset.</p>

    <?php if ($error): ?>
      <div class="mt-4 rounded-lg border border-red-900/50 bg-red-950/30 px-4 py-3 text-sm text-red-200">
        <?= htmlspecialchars($error) ?>
      </div>
    <?php endif; ?>

    <?php if ($success): ?>
      <div class="mt-4 rounded-lg border border-emerald-900/50 bg-emerald-950/30 px-4 py-3 text-sm text-emerald-200">
        <?= htmlspecialchars($success) ?>
      </div>
    <?php endif; ?>

    <form method="POST" class="mt-6 space-y-4">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string) csrf_ensure_token()) ?>" />

      <div>
        <label class="block text-xs text-zinc-400 mb-1" for="email">Email</label>
        <input
          id="email"
          name="email"
          type="email"
          required
          class="w-full rounded-xl bg-zinc-950 border border-zinc-800 px-3 py-3 text-sm outline-none focus:ring-2 focus:ring-zinc-700"
          placeholder="name@email.com"
        />
      </div>

      <button class="w-full rounded-xl bg-zinc-100 text-zinc-900 px-4 py-3 text-sm font-medium hover:bg-zinc-200 transition" type="submit">
        Request reset
      </button>

      <div class="text-center text-xs text-zinc-400">
        Remembered your password?
        <a class="text-zinc-200 hover:underline" href="/login.php">Login</a>
      </div>
    </form>
  </div>
</div>

<?php render_footer(); ?>

