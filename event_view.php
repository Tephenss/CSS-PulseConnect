<?php
declare(strict_types=1);

session_start();

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/supabase.php';
require_once __DIR__ . '/includes/layout.php';

$user = require_role(['student', 'teacher', 'admin']);
$role = (string) ($user['role'] ?? 'student');

$id = isset($_GET['id']) ? (string) $_GET['id'] : '';
if ($id === '') {
    http_response_code(400);
    echo 'Missing event id';
    exit;
}

$url = rtrim(SUPABASE_URL, '/') . '/rest/v1/events?select=id,title,description,location,start_at,end_at,status&'
    . 'id=eq.' . rawurlencode($id) . '&limit=1';
$headers = [
    'Accept: application/json',
    'apikey: ' . SUPABASE_KEY,
    'Authorization: Bearer ' . SUPABASE_KEY,
];
$res = supabase_request('GET', $url, $headers);
$rows = $res['ok'] ? json_decode((string) $res['body'], true) : null;
$event = is_array($rows) && isset($rows[0]) ? $rows[0] : null;

if (!is_array($event)) {
    http_response_code(404);
    echo 'Event not found';
    exit;
}

if ($role === 'student' && (string) ($event['status'] ?? '') !== 'published') {
    http_response_code(403);
    echo 'Forbidden';
    exit;
}

$status = (string)($event['status'] ?? '');
$statusColor = match($status) {
    'published' => 'bg-emerald-500/15 text-emerald-400 border-emerald-500/20',
    'pending' => 'bg-amber-500/15 text-amber-400 border-amber-500/20',
    'approved' => 'bg-sky-500/15 text-sky-400 border-sky-500/20',
    default => 'bg-zinc-500/15 text-zinc-400 border-zinc-500/20',
};

render_header('Event Details', $user);
?>

<div class="max-w-3xl">
  <div class="rounded-2xl glass-card p-6">
    <div class="flex items-start justify-between gap-4">
      <div>
        <h2 class="text-xl font-semibold text-zinc-100"><?= htmlspecialchars((string) ($event['title'] ?? '')) ?></h2>
        <div class="flex items-center gap-1.5 text-sm text-zinc-500 mt-2">
          <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M15 10.5a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1115 0z"/></svg>
          <?= htmlspecialchars((string) ($event['location'] ?? 'TBA')) ?>
        </div>
      </div>
      <span class="text-[10px] font-medium rounded-full border px-2.5 py-1 <?= $statusColor ?>"><?= htmlspecialchars($status) ?></span>
    </div>

    <?php if (!empty($event['description'])): ?>
      <div class="mt-4 text-sm text-zinc-300 leading-relaxed bg-zinc-900/30 rounded-xl p-4 border border-zinc-800/40">
        <?= nl2br(htmlspecialchars((string) ($event['description'] ?? ''))) ?>
      </div>
    <?php endif; ?>

    <div class="mt-5 grid grid-cols-2 gap-3">
      <div class="rounded-xl bg-zinc-900/30 border border-zinc-800/40 p-3">
        <div class="text-[10px] text-zinc-500 uppercase tracking-wider font-medium mb-1">Start</div>
        <div class="text-sm text-zinc-200"><?= htmlspecialchars((string) ($event['start_at'] ?? '')) ?></div>
      </div>
      <div class="rounded-xl bg-zinc-900/30 border border-zinc-800/40 p-3">
        <div class="text-[10px] text-zinc-500 uppercase tracking-wider font-medium mb-1">End</div>
        <div class="text-sm text-zinc-200"><?= htmlspecialchars((string) ($event['end_at'] ?? '')) ?></div>
      </div>
    </div>

    <?php if ($role === 'student'): ?>
      <div class="mt-6 flex flex-wrap gap-3">
        <button id="btnRegister" class="rounded-lg bg-gradient-to-r from-violet-600 to-fuchsia-600 text-white px-5 py-2.5 text-sm font-medium hover:from-violet-500 hover:to-fuchsia-500 transition-all shadow-lg shadow-violet-600/20">
          Register & Get Ticket
        </button>
        <a href="/my_tickets.php" class="rounded-lg border border-zinc-700 bg-zinc-800/40 px-4 py-2.5 text-sm text-zinc-300 hover:bg-zinc-700/60 transition">
          My Tickets
        </a>
      </div>
      <div id="msg" class="mt-4 text-sm text-zinc-300"></div>
    <?php endif; ?>

    <?php if ($role === 'teacher' || $role === 'admin'): ?>
      <div class="mt-6 flex flex-wrap gap-3">
        <a href="/participants.php?event_id=<?= htmlspecialchars($id) ?>" class="rounded-lg bg-gradient-to-r from-violet-600 to-fuchsia-600 text-white px-5 py-2.5 text-sm font-medium hover:from-violet-500 hover:to-fuchsia-500 transition-all shadow-lg shadow-violet-600/20">
          View Participants
        </a>
        <a href="/scan.php" class="rounded-lg border border-zinc-700 bg-zinc-800/40 px-4 py-2.5 text-sm text-zinc-300 hover:bg-zinc-700/60 transition">
          QR Scanner
        </a>
      </div>
    <?php endif; ?>
  </div>
</div>

<?php if ($role === 'student'): ?>
<script>
  const btn = document.getElementById('btnRegister');
  const msg = document.getElementById('msg');
  btn.addEventListener('click', async () => {
    btn.disabled = true;
    msg.textContent = 'Registering...';
    try {
      const res = await fetch('/api/register_event.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ event_id: <?= json_encode($id) ?>, csrf_token: window.CSRF_TOKEN })
      });
      const data = await res.json();
      if (!data.ok) throw new Error(data.error || 'Failed');
      msg.innerHTML = 'Registered! <a class="text-violet-400 hover:text-violet-300 underline" href="/ticket.php?token=' + encodeURIComponent(data.ticket.token) + '">Open ticket →</a>';
    } catch (e) {
      msg.textContent = e.message || 'Failed';
    } finally {
      btn.disabled = false;
    }
  });
</script>
<?php endif; ?>

<?php render_footer(); ?>
