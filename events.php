<?php
declare(strict_types=1);

session_start();

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/supabase.php';
require_once __DIR__ . '/includes/layout.php';

$user = require_role(['student', 'teacher', 'admin']);
$role = (string) ($user['role'] ?? 'student');

$select = 'select=id,title,description,location,start_at,end_at,status';
$base = rtrim(SUPABASE_URL, '/') . '/rest/v1/events?' . $select . '&order=start_at.asc';
$url = $role === 'student' ? $base . '&status=eq.published' : $base;

$headers = [
    'Accept: application/json',
    'apikey: ' . SUPABASE_KEY,
    'Authorization: Bearer ' . SUPABASE_KEY,
];

$events = [];
$res = supabase_request('GET', $url, $headers);
if ($res['ok']) {
    $decoded = json_decode((string) $res['body'], true);
    $events = is_array($decoded) ? $decoded : [];
}

render_header('Events', $user);
?>

<div class="mb-6">
  <p class="text-zinc-400 text-sm">Browse events and register to get your QR e-ticket.</p>
</div>

<div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4">
  <?php if (count($events) === 0): ?>
    <div class="md:col-span-2 xl:col-span-3 rounded-xl glass-card p-8 text-center">
      <div class="w-12 h-12 rounded-full bg-zinc-800/60 flex items-center justify-center mx-auto mb-3">
        <svg class="w-6 h-6 text-zinc-500" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 11.25v7.5"/></svg>
      </div>
      <div class="text-sm text-zinc-400">No events found.</div>
    </div>
  <?php endif; ?>

  <?php foreach ($events as $e): ?>
    <?php
      $status = (string)($e['status'] ?? '');
      $statusColor = match($status) {
          'published' => 'bg-emerald-500/15 text-emerald-400 border-emerald-500/20',
          'pending' => 'bg-amber-500/15 text-amber-400 border-amber-500/20',
          'approved' => 'bg-sky-500/15 text-sky-400 border-sky-500/20',
          default => 'bg-zinc-500/15 text-zinc-400 border-zinc-500/20',
      };
    ?>
    <a href="/event_view.php?id=<?= htmlspecialchars((string) ($e['id'] ?? '')) ?>"
       class="group block rounded-xl glass-card p-5 hover:border-zinc-600/60 transition-all duration-200">
      <div class="flex items-start justify-between gap-3">
        <div class="font-medium text-zinc-200 group-hover:text-white transition"><?= htmlspecialchars((string) ($e['title'] ?? 'Event')) ?></div>
        <span class="text-[10px] font-medium rounded-full border px-2 py-0.5 flex-shrink-0 <?= $statusColor ?>"><?= htmlspecialchars($status) ?></span>
      </div>
      <div class="flex items-center gap-1.5 text-sm text-zinc-500 mt-2">
        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M15 10.5a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1115 0z"/></svg>
        <?= htmlspecialchars((string) ($e['location'] ?? 'TBA')) ?>
      </div>
      <div class="flex items-center gap-1.5 text-xs text-zinc-600 mt-2">
        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
        <?= htmlspecialchars((string) ($e['start_at'] ?? '')) ?>
      </div>
    </a>
  <?php endforeach; ?>
</div>

<?php render_footer(); ?>
