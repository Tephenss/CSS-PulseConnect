<?php
declare(strict_types=1);

session_start();

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/supabase.php';
require_once __DIR__ . '/includes/layout.php';

$user = require_login();
$role = (string) ($user['role'] ?? 'student');

// Load events to show on homepage (students see published only).
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

$firstName = explode(' ', trim((string) ($user['full_name'] ?? 'User')))[0];

render_header('Dashboard', $user);
?>

<!-- Welcome Banner -->
<div class="rounded-2xl bg-gradient-to-r from-violet-600/10 via-fuchsia-600/10 to-pink-600/10 border border-violet-500/10 p-6 mb-6">
  <div class="flex items-start justify-between gap-4">
    <div>
      <h2 class="text-xl font-semibold text-zinc-100">Welcome back, <?= htmlspecialchars($firstName) ?>! 👋</h2>
      <p class="text-zinc-400 text-sm mt-1.5">
        <?php if ($role === 'student'): ?>
          Register for events, get your QR e-ticket, and track your attendance.
        <?php elseif ($role === 'teacher'): ?>
          Create and manage events, scan QR tickets for attendance.
        <?php else: ?>
          Full system overview — manage events, users, analytics, and certificates.
        <?php endif; ?>
      </p>
    </div>
  </div>
</div>

<!-- Stats Row -->
<div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
  <div class="rounded-xl glass-card p-4">
    <div class="flex items-center gap-3">
      <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-sky-600/20 to-cyan-600/20 border border-sky-500/20 flex items-center justify-center">
        <svg class="w-5 h-5 text-sky-400" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 11.25v7.5"/></svg>
      </div>
      <div>
        <div class="text-2xl font-bold text-zinc-100"><?= count($events) ?></div>
        <div class="text-xs text-zinc-500">Events</div>
      </div>
    </div>
  </div>

  <?php
    $upcoming = 0;
    $now = new DateTime();
    foreach ($events as $e) {
        if (!empty($e['start_at'])) {
            try { if (new DateTime($e['start_at']) > $now) $upcoming++; } catch (Throwable $ex) {}
        }
    }
  ?>
  <div class="rounded-xl glass-card p-4">
    <div class="flex items-center gap-3">
      <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-emerald-600/20 to-teal-600/20 border border-emerald-500/20 flex items-center justify-center">
        <svg class="w-5 h-5 text-emerald-400" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
      </div>
      <div>
        <div class="text-2xl font-bold text-zinc-100"><?= $upcoming ?></div>
        <div class="text-xs text-zinc-500">Upcoming</div>
      </div>
    </div>
  </div>

  <?php
    $published = 0;
    $pending = 0;
    foreach ($events as $e) {
        $s = (string)($e['status'] ?? '');
        if ($s === 'published') $published++;
        if ($s === 'pending') $pending++;
    }
  ?>
  <div class="rounded-xl glass-card p-4">
    <div class="flex items-center gap-3">
      <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-violet-600/20 to-fuchsia-600/20 border border-violet-500/20 flex items-center justify-center">
        <svg class="w-5 h-5 text-violet-400" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
      </div>
      <div>
        <div class="text-2xl font-bold text-zinc-100"><?= $published ?></div>
        <div class="text-xs text-zinc-500">Published</div>
      </div>
    </div>
  </div>

  <div class="rounded-xl glass-card p-4">
    <div class="flex items-center gap-3">
      <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-amber-600/20 to-orange-600/20 border border-amber-500/20 flex items-center justify-center">
        <svg class="w-5 h-5 text-amber-400" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z"/></svg>
      </div>
      <div>
        <div class="text-2xl font-bold text-zinc-100"><?= $pending ?></div>
        <div class="text-xs text-zinc-500">Pending</div>
      </div>
    </div>
  </div>
</div>

<!-- Events Grid -->
<div class="mb-4 flex items-center justify-between">
  <h3 class="text-sm font-semibold text-zinc-200">
    <?php if ($role === 'student'): ?>Available Events<?php else: ?>All Events<?php endif; ?>
  </h3>
  <a href="/events.php" class="text-xs text-violet-400 hover:text-violet-300 transition flex items-center gap-1">
    View all
    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5"/></svg>
  </a>
</div>

<div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4">
  <?php if (count($events) === 0): ?>
    <div class="md:col-span-2 xl:col-span-3 rounded-xl glass-card p-8 text-center">
      <div class="w-12 h-12 rounded-full bg-zinc-800/60 flex items-center justify-center mx-auto mb-3">
        <svg class="w-6 h-6 text-zinc-500" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 11.25v7.5"/></svg>
      </div>
      <div class="text-sm text-zinc-400">No events found yet.</div>
      <div class="text-xs text-zinc-500 mt-1">Ask an organizer to create and publish an event.</div>
    </div>
  <?php endif; ?>

  <?php foreach ($events as $i => $e): ?>
    <?php if ($i >= 6) break; // Show max 6 on dashboard ?>
    <?php
      $status = (string)($e['status'] ?? '');
      $statusColor = match($status) {
          'published' => 'bg-emerald-500/15 text-emerald-400 border-emerald-500/20',
          'pending' => 'bg-amber-500/15 text-amber-400 border-amber-500/20',
          'approved' => 'bg-sky-500/15 text-sky-400 border-sky-500/20',
          default => 'bg-zinc-500/15 text-zinc-400 border-zinc-500/20',
      };
    ?>
    <a class="group block rounded-xl glass-card p-5 hover:border-zinc-600/60 transition-all duration-200"
       href="/event_view.php?id=<?= htmlspecialchars((string) ($e['id'] ?? '')) ?>">
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

<?php
render_footer();
