<?php
declare(strict_types=1);

session_start();

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/supabase.php';
require_once __DIR__ . '/includes/layout.php';

$user = require_role(['student']);
$headers = [
    'Accept: application/json',
    'apikey: ' . SUPABASE_KEY,
    'Authorization: Bearer ' . SUPABASE_KEY,
];

$url = rtrim(SUPABASE_URL, '/') . '/rest/v1/event_registrations'
    . '?select=id,registered_at,event_id,events(title,location,start_at,end_at),tickets(token)'
    . '&student_id=eq.' . rawurlencode((string) ($user['id'] ?? ''))
    . '&order=registered_at.desc';

$res = supabase_request('GET', $url, $headers);
$rows = $res['ok'] ? json_decode((string) $res['body'], true) : null;
$regs = is_array($rows) ? $rows : [];

render_header('My Tickets', $user);
?>

<div class="mb-6">
  <p class="text-zinc-400 text-sm">Show your QR code to the scanner for attendance check-in and check-out.</p>
</div>

<div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4">
  <?php if (count($regs) === 0): ?>
    <div class="md:col-span-2 xl:col-span-3 rounded-xl glass-card p-8 text-center">
      <div class="w-12 h-12 rounded-full bg-zinc-800/60 flex items-center justify-center mx-auto mb-3">
        <svg class="w-6 h-6 text-zinc-500" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M16.5 6v.75m0 3v.75m0 3v.75m0 3V18m-9-5.25h5.25M7.5 15h3M3.375 5.25c-.621 0-1.125.504-1.125 1.125v3.026a2.999 2.999 0 010 5.198v3.026c0 .621.504 1.125 1.125 1.125h17.25c.621 0 1.125-.504 1.125-1.125v-3.026a2.999 2.999 0 010-5.198V6.375c0-.621-.504-1.125-1.125-1.125H3.375z"/></svg>
      </div>
      <div class="text-sm text-zinc-400">You have no tickets yet.</div>
      <a href="/events.php" class="inline-block mt-3 text-xs text-violet-400 hover:text-violet-300 transition">Browse events →</a>
    </div>
  <?php endif; ?>

  <?php foreach ($regs as $r): ?>
    <?php
      $event = isset($r['events']) && is_array($r['events']) ? $r['events'] : [];
      $tickets = isset($r['tickets']) && is_array($r['tickets']) ? $r['tickets'] : [];
      $token = isset($tickets[0]['token']) ? (string) $tickets[0]['token'] : (isset($tickets['token']) ? (string) $tickets['token'] : '');
    ?>
    <a href="/ticket.php?token=<?= htmlspecialchars($token) ?>"
       class="group block rounded-xl glass-card p-5 hover:border-zinc-600/60 transition-all duration-200">
      <div class="flex items-start gap-3">
        <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-violet-600/20 to-fuchsia-600/20 border border-violet-500/20 flex items-center justify-center flex-shrink-0">
          <svg class="w-5 h-5 text-violet-400" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M16.5 6v.75m0 3v.75m0 3v.75m0 3V18m-9-5.25h5.25M7.5 15h3M3.375 5.25c-.621 0-1.125.504-1.125 1.125v3.026a2.999 2.999 0 010 5.198v3.026c0 .621.504 1.125 1.125 1.125h17.25c.621 0 1.125-.504 1.125-1.125v-3.026a2.999 2.999 0 010-5.198V6.375c0-.621-.504-1.125-1.125-1.125H3.375z"/></svg>
        </div>
        <div class="min-w-0">
          <div class="font-medium text-zinc-200 group-hover:text-white transition"><?= htmlspecialchars((string) ($event['title'] ?? 'Event')) ?></div>
          <div class="text-xs text-zinc-500 mt-1"><?= htmlspecialchars((string) ($event['location'] ?? 'TBA')) ?></div>
        </div>
      </div>
      <div class="mt-3 text-[10px] text-zinc-600 font-mono break-all bg-zinc-900/40 rounded-lg px-2.5 py-1.5"><?= htmlspecialchars($token) ?></div>
    </a>
  <?php endforeach; ?>
</div>

<?php render_footer(); ?>
