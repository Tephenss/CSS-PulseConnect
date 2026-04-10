<?php
declare(strict_types=1);

session_start();

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/supabase.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/helpers.php';

$user = require_role(['admin']);
$role = (string) ($user['role'] ?? 'admin');

$select = 'select=id,title,description,location,start_at,end_at,status,event_for,event_type';
$base = rtrim(SUPABASE_URL, '/') . '/rest/v1/events?' . $select . '&order=start_at.asc';
$url = $base . '&status=eq.published';

$headers = [
    'Accept: application/json',
    'apikey: ' . SUPABASE_KEY,
    'Authorization: Bearer ' . SUPABASE_KEY,
];

// Auto-archive events that have already ended.
try {
    $nowUtc = gmdate('c');
    $archiveUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/events'
        . '?status=eq.published'
        . '&end_at=lt.' . rawurlencode($nowUtc);
    $archiveHeaders = [
        'Accept: application/json',
        'Content-Type: application/json',
        'Prefer: return=minimal',
        'apikey: ' . SUPABASE_KEY,
        'Authorization: Bearer ' . SUPABASE_KEY,
    ];
    $archivePayload = json_encode(['status' => 'archived'], JSON_UNESCAPED_SLASHES);
    if (is_string($archivePayload)) {
        supabase_request('PATCH', $archiveUrl, $archiveHeaders, $archivePayload);
    }
} catch (Throwable $e) {
    // Best-effort only; keep page rendering even if auto-archive fails.
}

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
          'expired' => ['bg' => 'bg-zinc-200', 'text' => 'text-zinc-600', 'border' => 'border-zinc-300', 'accent' => 'border-b-zinc-500'],
          default => ['bg' => 'bg-zinc-100', 'text' => 'text-zinc-800', 'border' => 'border-zinc-200', 'accent' => 'border-b-zinc-400'],
      };

      // Format date in local timezone for consistency with event details.
      $rawDate = (string) ($e['start_at'] ?? '');
      $formattedDate = $rawDate !== '' ? format_date_local($rawDate, 'M d, Y - g:i A') : 'TBA';
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

          <div class="flex items-center gap-2 pt-3 border-t border-zinc-100 mt-1">
             <?php
                 $for = $e['event_for'] ?? 'all';
                 $targetLabel = 'All Year Levels';
                 if (strtolower($for) === 'none') $targetLabel = 'No Target';
                 elseif ((string)$for === '1') $targetLabel = '1st Year';
                 elseif ((string)$for === '2') $targetLabel = '2nd Year';
                 elseif ((string)$for === '3') $targetLabel = '3rd Year';
                 elseif ((string)$for === '4') $targetLabel = '4th Year';
             ?>
             <span class="inline-flex items-center gap-1.5 text-[10px] font-bold text-zinc-600 bg-zinc-50 border border-zinc-200 px-2 py-1 rounded-md">
                <svg class="w-3 h-3 text-zinc-400" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 002.625.372 9.337 9.337 0 004.121-.952 4.125 4.125 0 00-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 018.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0111.964-3.07M12 6.375a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zm8.25 2.25a2.625 2.625 0 11-5.25 0 2.625 2.625 0 015.25 0z"/></svg> 
                <?= htmlspecialchars($targetLabel) ?>
             </span>
             <?php if (!empty($e['event_type'])): ?>
             <span class="inline-flex items-center gap-1.5 text-[10px] font-bold text-zinc-600 bg-zinc-50 border border-zinc-200 px-2 py-1 rounded-md">
                <svg class="w-3 h-3 text-zinc-400" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9.568 3H5.25A2.25 2.25 0 003 5.25v4.318c0 .597.237 1.17.659 1.591l9.581 9.581c.699.699 1.78.872 2.607.33a18.095 18.095 0 005.223-5.223c.542-.827.369-1.908-.33-2.607L11.16 3.66A2.25 2.25 0 009.568 3z"/><path stroke-linecap="round" stroke-linejoin="round" d="M6 6h.008v.008H6V6z"/></svg>
                <?= htmlspecialchars($e['event_type']) ?>
             </span>
             <?php endif; ?>
          </div>
        </div>
      </div>
    </a>
  <?php endforeach; ?>
</div>

<?php render_footer();
