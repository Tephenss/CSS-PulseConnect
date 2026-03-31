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

<div class="mb-6 flex items-center justify-between">
  <div>
    <h2 class="text-xl font-bold text-zinc-900 mb-1">Explore Events</h2>
    <p class="text-zinc-600 text-sm">Browse available events and register to get your QR e-ticket.</p>
  </div>
</div>

<div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-5">
  <?php if (count($events) === 0): ?>
    <div class="md:col-span-2 lg:col-span-3 rounded-2xl border border-dashed border-zinc-300 bg-zinc-50/80 p-12 text-center">
      <div class="w-16 h-16 rounded-full bg-white border border-zinc-200 flex items-center justify-center mx-auto mb-4 shadow-sm">
        <svg class="w-8 h-8 text-zinc-400" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M15.182 15.182a4.5 4.5 0 01-6.364 0M21 12a9 9 0 11-18 0 9 9 0 0118 0zM9.75 9.75c0 .414-.168.75-.375.75S9 10.164 9 9.75 9.168 9 9.375 9s.375.336.375.75zm3.625 0c0 .414-.168.75-.375.75s-.375-.336-.375-.75.168-.75.375-.75.375.336.375.75z"/></svg>
      </div>
      <h3 class="text-lg font-medium text-zinc-800 mb-1">No events found</h3>
      <p class="text-sm text-zinc-600 max-w-md mx-auto">There are currently no active events available for registration. Check back later.</p>
    </div>
  <?php endif; ?>

  <?php foreach ($events as $e): ?>
    <?php
      $status = (string)($e['status'] ?? '');
      $statusConfig = match($status) {
          'published' => ['bg' => 'bg-emerald-100', 'text' => 'text-emerald-900', 'border' => 'border-emerald-200', 'accent' => 'border-b-emerald-500'],
          'pending' => ['bg' => 'bg-amber-100', 'text' => 'text-amber-900', 'border' => 'border-amber-200', 'accent' => 'border-b-amber-500'],
          'approved' => ['bg' => 'bg-sky-100', 'text' => 'text-sky-900', 'border' => 'border-sky-200', 'accent' => 'border-b-sky-500'],
          default => ['bg' => 'bg-zinc-100', 'text' => 'text-zinc-800', 'border' => 'border-zinc-200', 'accent' => 'border-b-zinc-400'],
      };

      // Format Date properly
      $rawDate = (string) ($e['start_at'] ?? '');
      $formattedDate = $rawDate ? (new DateTimeImmutable($rawDate))->format('M d, Y · g:i A') : 'TBA';
    ?>
    <a href="/event_view.php?id=<?= htmlspecialchars((string) ($e['id'] ?? '')) ?>"
       class="group relative block rounded-2xl border border-zinc-200 bg-white p-6 border-b-[3px] shadow-sm hover:shadow-md hover:-translate-y-0.5 transition-all duration-300 <?= $statusConfig['accent'] ?>">

      <div class="relative z-10 flex flex-col h-full">
        <div>
          <div class="flex items-start justify-between gap-4 mb-4">
            <h4 class="text-sm font-bold tracking-tight text-zinc-900 group-hover:text-orange-900 transition-colors line-clamp-2"><?= htmlspecialchars((string) ($e['title'] ?? 'Event')) ?></h4>
            <span class="text-[10px] uppercase tracking-wider font-bold rounded-full border px-2.5 py-1 flex-shrink-0 <?= $statusConfig['bg'] ?> <?= $statusConfig['text'] ?> <?= $statusConfig['border'] ?>">
              <?= htmlspecialchars($status) ?>
            </span>
          </div>

          <p class="text-xs text-zinc-600 line-clamp-2 mb-5 min-h-[32px] leading-relaxed">
            <?= htmlspecialchars((string) ($e['description'] ?? 'No description provided for this event.')) ?>
          </p>
        </div>

        <div class="mt-auto space-y-2.5">
          <div class="flex items-center gap-2.5 text-xs font-medium text-zinc-800">
            <div class="w-7 h-7 rounded-full bg-orange-50 flex items-center justify-center flex-shrink-0 border border-orange-100">
              <svg class="w-3.5 h-3.5 text-orange-700" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            </div>
            <?= htmlspecialchars($formattedDate) ?>
          </div>
          <div class="flex items-center gap-2.5 text-xs text-zinc-600">
            <div class="w-7 h-7 rounded-full bg-emerald-50 flex items-center justify-center flex-shrink-0 border border-emerald-100">
              <svg class="w-3.5 h-3.5 text-emerald-700" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M15 10.5a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1115 0z"/></svg>
            </div>
            <span class="truncate"><?= htmlspecialchars((string) ($e['location'] ?? 'Location TBA')) ?></span>
          </div>
        </div>
      </div>
    </a>
  <?php endforeach; ?>
</div>

<?php render_footer();
