<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/session.php';
session_bootstrap();
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/supabase.php';
require_once __DIR__ . '/includes/registration_access.php';
require_once __DIR__ . '/includes/event_sessions.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/event_walkin.php';

$user = require_role(['admin', 'teacher']);

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Robots-Tag: noindex, nofollow');
header('Referrer-Policy: no-referrer');

$eventId = trim((string) ($_GET['event_id'] ?? ''));
if (!preg_match('/^[0-9a-f-]{36}$/i', $eventId)) {
    http_response_code(404);
    $pageError = 'This event link is invalid. Please ask the event organizer for the correct link.';
    $event = null;
    $sessions = [];
} else {
    $headers = [
        'Accept: application/json',
        'apikey: ' . SUPABASE_KEY,
        'Authorization: Bearer ' . SUPABASE_KEY,
    ];
    $event = fetch_event_with_registration_settings($eventId, $headers);
    $pageError = '';
    $sessions = [];

    if (!is_array($event)) {
        http_response_code(404);
        $pageError = 'Event not found.';
    } elseif (strtolower((string) ($user['role'] ?? '')) !== 'admin'
        && (string) ($event['created_by'] ?? '') !== (string) ($user['id'] ?? '')) {
        http_response_code(403);
        $pageError = "Only this event's teacher or an administrator can access the walk-in attendance page.";
    } elseif ((string) ($event['status'] ?? '') !== 'published') {
        $pageError = 'Walk-in attendance is available only while the event is published and its attendance window is open.';
    } elseif (!event_is_free_registration_event($event)) {
        $pageError = 'Walk-in attendance is not enabled for paid events. Please contact the event organizer.';
    } else {
        $sessions = fetch_event_sessions($eventId, $headers);
    }
}

$csrfToken = csrf_ensure_token();
$eventTitle = is_array($event) ? (string) ($event['title'] ?? 'Event Attendance') : 'Walk-in Attendance';
$eventDate = '';
if (is_array($event) && !empty($event['start_at'])) {
    try {
        $eventDate = (new DateTimeImmutable((string) $event['start_at']))
            ->setTimezone(new DateTimeZone('Asia/Manila'))
            ->format('M j, Y · g:i A');
    } catch (Throwable $e) {
        $eventDate = '';
    }
}
?>
<!doctype html>
<html lang="en" class="dark">
<head>
  <meta charset="utf-8" />
  <?php require_once __DIR__ . '/includes/favicon.php'; render_favicon_tags(); ?>
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <meta name="theme-color" content="#09090b" />
  <title>Walk-in Attendance | PulseConnect</title>
  <link rel="stylesheet" href="/assets/css/tailwind.css?v=<?= (int) @filemtime(__DIR__ . '/assets/css/tailwind.css') ?>" />
  <link rel="stylesheet" href="/assets/css/app.css?v=<?= (int) @filemtime(__DIR__ . '/assets/css/app.css') ?>" />
  <link rel="stylesheet" href="/assets/css/auth.css?v=<?= (int) @filemtime(__DIR__ . '/assets/css/auth.css') ?>" />
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
          <p class="text-zinc-400 mt-4 text-sm leading-relaxed">Record event attendance and keep participants in sync with PulseConnect.</p>
        </div>
      </div>
    </div>

    <div class="flex items-center justify-center p-6">
      <div class="w-full max-w-md">
        <div class="mb-6">
          <div class="text-xs tracking-[0.35em] uppercase text-zinc-400">Event attendance</div>
          <h2 class="text-3xl font-semibold mt-3">Log form for WALK IN</h2>
          <p class="text-zinc-400 mt-2 text-sm"><?= htmlspecialchars($eventTitle, ENT_QUOTES) ?><?= $eventDate !== '' ? ' · ' . htmlspecialchars($eventDate, ENT_QUOTES) : '' ?></p>
        </div>

        <?php if ($pageError !== ''): ?>
          <div class="rounded-2xl border border-red-900/50 bg-red-950/30 px-4 py-3 text-sm text-red-200" role="status">
            <?= htmlspecialchars($pageError, ENT_QUOTES) ?>
          </div>
        <?php else: ?>
          <form id="walkinForm" novalidate class="rounded-2xl border border-zinc-800 bg-zinc-900/30 p-6">
            <input type="hidden" name="event_id" value="<?= htmlspecialchars($eventId, ENT_QUOTES) ?>" />
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES) ?>" />

            <div class="mb-4 rounded-xl border border-zinc-800 bg-zinc-950/70 px-4 py-3 text-xs leading-relaxed text-zinc-300">
            For students who don't have an account yet and those who haven't registered for this event.
            </div>

            <?php if (count($sessions) > 0): ?>
              <label class="block text-xs text-zinc-400 mb-1" for="session_id">Seminar / session</label>
              <select id="session_id" name="session_id" required class="w-full rounded-xl bg-zinc-950 border border-zinc-800 px-3 py-3 text-sm text-zinc-100 outline-none focus:ring-2 focus:ring-zinc-700">
                <option value="">Choose the session</option>
                <?php foreach ($sessions as $session): ?>
                  <?php
                    $sessionLabel = trim((string) ($session['title'] ?? 'Session'));
                    if (trim((string) ($session['topic'] ?? '')) !== '') $sessionLabel .= ' · ' . trim((string) $session['topic']);
                    try { $sessionLabel .= ' — ' . (new DateTimeImmutable((string) ($session['start_at'] ?? '')))->setTimezone(new DateTimeZone('Asia/Manila'))->format('M j, g:i A'); } catch (Throwable $e) {}
                  ?>
                  <option value="<?= htmlspecialchars((string) ($session['id'] ?? ''), ENT_QUOTES) ?>"><?= htmlspecialchars($sessionLabel, ENT_QUOTES) ?></option>
                <?php endforeach; ?>
              </select>
              <div class="h-4"></div>
            <?php endif; ?>

            <label class="block text-xs text-zinc-400 mb-1" for="student_no">Student ID</label>
            <input id="student_no" name="student_no" type="text" inputmode="text" autocomplete="off" autocapitalize="characters" maxlength="32" placeholder="e.g. 261-1234" required class="w-full rounded-xl bg-zinc-950 border border-zinc-800 px-3 py-3 text-sm text-zinc-100 outline-none focus:ring-2 focus:ring-zinc-700" />

            <div class="h-4"></div>
            <button type="submit" id="submitButton" class="w-full rounded-xl bg-zinc-100 text-zinc-900 px-4 py-3 font-medium hover:bg-zinc-200 transition disabled:opacity-50 disabled:cursor-wait">
              <span>Log attendance</span>
            </button>
            <div id="formMessage" class="hidden mt-4 rounded-xl px-4 py-3 text-sm" role="status" aria-live="polite"></div>
          </form>
        <?php endif; ?>

        <div class="text-center text-xs text-zinc-500 mt-6">
          © <?= htmlspecialchars((string) date('Y')) ?> PulseCONNECT
        </div>
      </div>
    </div>
  </div>
  <script>
    (() => {
      const background = document.querySelector('.interactive-bg');
      if (!background) return;

      let hideTimer;
      let targetX = window.innerWidth / 2;
      let targetY = window.innerHeight / 2;
      let currentX = targetX;
      let currentY = targetY;

      document.addEventListener('mousemove', (event) => {
        targetX = event.clientX;
        targetY = event.clientY;
        background.style.opacity = '0.85';
        window.clearTimeout(hideTimer);
        hideTimer = window.setTimeout(() => {
          background.style.opacity = '0';
        }, 500);
      });

      document.addEventListener('mouseleave', () => {
        background.style.opacity = '0';
      });

      const animateBackground = () => {
        currentX += (targetX - currentX) * 0.06;
        currentY += (targetY - currentY) * 0.06;
        background.style.setProperty('--mouse-x', `${currentX}px`);
        background.style.setProperty('--mouse-y', `${currentY}px`);
        window.requestAnimationFrame(animateBackground);
      };

      animateBackground();
    })();
  </script>
  <?php if ($pageError === ''): ?>
  <script>
    (() => {
      const form = document.getElementById('walkinForm');
      const button = document.getElementById('submitButton');
      const message = document.getElementById('formMessage');
      const showMessage = (text, kind) => {
        message.textContent = text;
        message.className = kind === 'success'
          ? 'mt-4 rounded-xl border border-emerald-900/40 bg-emerald-950/25 px-4 py-3 text-sm text-emerald-200'
          : 'mt-4 rounded-xl border border-red-900/50 bg-red-950/30 px-4 py-3 text-sm text-red-200';
      };
      form.addEventListener('submit', async (event) => {
        event.preventDefault();
        message.className = 'hidden mt-4 rounded-xl px-4 py-3 text-sm';
        const studentNo = String(form.elements.student_no.value || '').trim();
        if (!studentNo) return showMessage('Enter your student ID.', 'error');
        if (form.elements.session_id && !form.elements.session_id.value) return showMessage('Choose the session first.', 'error');
        button.disabled = true;
        button.querySelector('span').textContent = 'Checking roster…';
        try {
          const response = await fetch('/api/event_walkin_submit.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {'Content-Type': 'application/json', 'Accept': 'application/json'},
            body: JSON.stringify({
              event_id: form.elements.event_id.value,
              csrf_token: form.elements.csrf_token.value,
              student_no: studentNo,
              session_id: form.elements.session_id ? form.elements.session_id.value : ''
            })
          });
          const data = await response.json().catch(() => ({}));
          if (!response.ok || !data.ok) throw new Error(data.error || 'Could not log attendance. Please ask the event staff.');
          showMessage(data.message || 'Attendance recorded successfully.', 'success');
          form.elements.student_no.value = '';
          form.elements.student_no.focus();
        } catch (error) {
          showMessage(error.message || 'Connection problem. Please ask the event staff to check the log.', 'error');
        } finally {
          button.disabled = false;
          button.querySelector('span').textContent = 'Log attendance';
        }
      });
    })();
  </script>
  <?php endif; ?>
</body>
</html>
