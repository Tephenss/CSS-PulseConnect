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
$url = $base . '&or=(status.eq.published,status.eq.finished)';

$headers = [
  'Accept: application/json',
  'apikey: ' . SUPABASE_KEY,
  'Authorization: Bearer ' . SUPABASE_KEY,
];

// Keep event status consistent across pages:
// if a published event already ended, mark it finished in DB.
try {
  $nowUtc = gmdate('c');
  $finishUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/events'
    . '?status=eq.published'
    . '&end_at=lt.' . rawurlencode($nowUtc);
  $finishHeaders = [
    'Accept: application/json',
    'Content-Type: application/json',
    'Prefer: return=minimal',
    'apikey: ' . SUPABASE_KEY,
    'Authorization: Bearer ' . SUPABASE_KEY,
  ];
  $finishPayload = json_encode(['status' => 'finished'], JSON_UNESCAPED_SLASHES);
  if (is_string($finishPayload)) {
    supabase_request('PATCH', $finishUrl, $finishHeaders, $finishPayload);
  }
} catch (Throwable $e) {
  // Best-effort only. Listing should still render.
}

$events = [];
$res = supabase_request('GET', $url, $headers);
if ($res['ok']) {
  $decoded = json_decode((string) $res['body'], true);
  $events = is_array($decoded) ? $decoded : [];
}

$now = new DateTimeImmutable('now');
$publishedEvents = [];
$finishedEvents = [];

foreach ($events as $eventRow) {
  $statusValue = strtolower(trim((string) ($eventRow['status'] ?? '')));
  if ($statusValue === 'finished') {
    $finishedEvents[] = $eventRow;
    continue;
  }

  $endSource = (string) ($eventRow['end_at'] ?? $eventRow['start_at'] ?? '');
  $isFinished = false;
  if ($endSource !== '') {
    try {
      $eventEnd = new DateTimeImmutable($endSource);
      $isFinished = $eventEnd <= $now;
    } catch (Throwable $e) {
      $isFinished = false;
    }
  }

  if ($isFinished) {
    $finishedEvents[] = $eventRow;
  } else {
    $publishedEvents[] = $eventRow;
  }
}

usort($publishedEvents, static function (array $a, array $b): int {
  return strcmp((string) ($a['start_at'] ?? ''), (string) ($b['start_at'] ?? ''));
});

usort($finishedEvents, static function (array $a, array $b): int {
  return strcmp((string) ($b['end_at'] ?? $b['start_at'] ?? ''), (string) ($a['end_at'] ?? $a['start_at'] ?? ''));
});

$renderEventCard = static function (array $e, bool $isFinished): void {
  $status = $isFinished ? 'finished' : (string) ($e['status'] ?? '');

  $statusConfig = match ($status) {
    'published' => ['bg' => 'bg-emerald-100', 'text' => 'text-emerald-900', 'border' => 'border-emerald-200', 'accent' => 'border-b-emerald-500'],
    'finished' => ['bg' => 'bg-zinc-200', 'text' => 'text-zinc-700', 'border' => 'border-zinc-300', 'accent' => 'border-b-zinc-500'],
    'pending' => ['bg' => 'bg-amber-100', 'text' => 'text-amber-900', 'border' => 'border-amber-200', 'accent' => 'border-b-amber-500'],
    'approved' => ['bg' => 'bg-sky-100', 'text' => 'text-sky-900', 'border' => 'border-sky-200', 'accent' => 'border-b-sky-500'],
    'expired' => ['bg' => 'bg-zinc-200', 'text' => 'text-zinc-600', 'border' => 'border-zinc-300', 'accent' => 'border-b-zinc-500'],
    default => ['bg' => 'bg-zinc-100', 'text' => 'text-zinc-800', 'border' => 'border-zinc-200', 'accent' => 'border-b-zinc-400'],
  };

  $rawDate = (string) ($e['start_at'] ?? '');
  $formattedDate = $rawDate !== '' ? format_date_local($rawDate, 'M d, Y - g:i A') : 'TBA';

  $for = (string) ($e['event_for'] ?? 'All');
  $targetLabel = format_target_participant($for);
  ?>
  <a href="/event_view.php?id=<?= htmlspecialchars((string) ($e['id'] ?? '')) ?>"
    class="group relative block rounded-2xl border border-zinc-200 bg-white p-6 border-b-[3px] shadow-sm hover:shadow-md hover:-translate-y-0.5 transition-all duration-300 <?= $statusConfig['accent'] ?>">
    <div class="relative z-10 flex flex-col h-full">
      <div>
        <div class="flex items-start justify-between gap-4 mb-4">
          <h4
            class="text-sm font-bold tracking-tight text-zinc-900 group-hover:text-orange-900 transition-colors line-clamp-2">
            <?= htmlspecialchars((string) ($e['title'] ?? 'Event')) ?></h4>
          <span
            class="text-[10px] uppercase tracking-wider font-bold rounded-full border px-2.5 py-1 flex-shrink-0 <?= $statusConfig['bg'] ?> <?= $statusConfig['text'] ?> <?= $statusConfig['border'] ?>">
            <?= htmlspecialchars($status) ?>
          </span>
        </div>

        <p class="text-xs text-zinc-600 line-clamp-2 mb-5 min-h-[32px] leading-relaxed">
          <?= htmlspecialchars((string) ($e['description'] ?? 'No description provided for this event.')) ?>
        </p>
      </div>

      <div class="mt-auto space-y-2.5">
        <div class="flex items-center gap-2.5 text-xs font-medium text-zinc-800">
          <div
            class="w-7 h-7 rounded-full bg-orange-50 flex items-center justify-center flex-shrink-0 border border-orange-100">
            <svg class="w-3.5 h-3.5 text-orange-700" fill="none" stroke="currentColor" stroke-width="2.5"
              viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z" />
            </svg>
          </div>
          <?= htmlspecialchars($formattedDate) ?>
        </div>
        <div class="flex items-center gap-2.5 text-xs text-zinc-600">
          <div
            class="w-7 h-7 rounded-full bg-emerald-50 flex items-center justify-center flex-shrink-0 border border-emerald-100">
            <svg class="w-3.5 h-3.5 text-emerald-700" fill="none" stroke="currentColor" stroke-width="2"
              viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" d="M15 10.5a3 3 0 11-6 0 3 3 0 016 0z" />
              <path stroke-linecap="round" stroke-linejoin="round"
                d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1115 0z" />
            </svg>
          </div>
          <span class="truncate"><?= htmlspecialchars((string) ($e['location'] ?? 'Location TBA')) ?></span>
        </div>

        <div class="flex items-center gap-2 pt-3 border-t border-zinc-100 mt-1">
          <span
            class="inline-flex items-center gap-1.5 text-[10px] font-bold text-zinc-600 bg-zinc-50 border border-zinc-200 px-2 py-1 rounded-md">
            <svg class="w-3 h-3 text-zinc-400" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round"
                d="M15 19.128a9.38 9.38 0 002.625.372 9.337 9.337 0 004.121-.952 4.125 4.125 0 00-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 018.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0111.964-3.07M12 6.375a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zm8.25 2.25a2.625 2.625 0 11-5.25 0 2.625 2.625 0 015.25 0z" />
            </svg>
            <?= htmlspecialchars($targetLabel) ?>
          </span>
          <?php if (!empty($e['event_type'])): ?>
            <span
              class="inline-flex items-center gap-1.5 text-[10px] font-bold text-zinc-600 bg-zinc-50 border border-zinc-200 px-2 py-1 rounded-md">
              <svg class="w-3 h-3 text-zinc-400" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round"
                  d="M9.568 3H5.25A2.25 2.25 0 003 5.25v4.318c0 .597.237 1.17.659 1.591l9.581 9.581c.699.699 1.78.872 2.607.33a18.095 18.095 0 005.223-5.223c.542-.827.369-1.908-.33-2.607L11.16 3.66A2.25 2.25 0 009.568 3z" />
                <path stroke-linecap="round" stroke-linejoin="round" d="M6 6h.008v.008H6V6z" />
              </svg>
              <?= htmlspecialchars((string) $e['event_type']) ?>
            </span>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </a>
  <?php
};

render_header('Events', $user);
?>

<div class="mb-6 flex items-center justify-between">
  <div>
    <h2 class="text-xl font-bold text-zinc-900 mb-1">Explore Events</h2>
    <p class="text-zinc-600 text-sm">Browse published events and review finished ones without sending them straight to
      archive.</p>
  </div>
</div>
<div class="mb-5 flex flex-wrap items-center gap-2 rounded-2xl border border-zinc-200 bg-white p-2 shadow-sm w-fit">
  <button type="button" id="tabPublished"
    class="event-tab-btn rounded-xl bg-orange-600 px-4 py-2 text-sm font-bold text-white shadow-sm">
    Published
    <span class="ml-1.5 rounded-full bg-white/20 px-2 py-0.5 text-[11px]"><?= count($publishedEvents) ?></span>
  </button>
  <button type="button" id="tabFinished"
    class="event-tab-btn rounded-xl px-4 py-2 text-sm font-semibold text-zinc-600 hover:bg-zinc-100">
    Finished
    <span
      class="ml-1.5 rounded-full bg-zinc-100 px-2 py-0.5 text-[11px] text-zinc-600"><?= count($finishedEvents) ?></span>
  </button>
</div>

<section id="publishedPanel">
  <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-5">
    <?php if (count($publishedEvents) === 0): ?>
      <div
        class="md:col-span-2 lg:col-span-3 rounded-2xl border border-dashed border-zinc-300 bg-zinc-50/80 p-12 text-center">
        <div
          class="w-16 h-16 rounded-full bg-white border border-zinc-200 flex items-center justify-center mx-auto mb-4 shadow-sm">
          <svg class="w-8 h-8 text-zinc-400" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round"
              d="M15.182 15.182a4.5 4.5 0 01-6.364 0M21 12a9 9 0 11-18 0 9 9 0 0118 0zM9.75 9.75c0 .414-.168.75-.375.75S9 10.164 9 9.75 9.168 9 9.375 9s.375.336.375.75zm3.625 0c0 .414-.168.75-.375.75s-.375-.336-.375-.75.168-.75.375-.75.375.336.375.75z" />
          </svg>
        </div>
        <h3 class="text-lg font-medium text-zinc-800 mb-1">No published events found</h3>
        <p class="text-sm text-zinc-600 max-w-md mx-auto">There are currently no upcoming or ongoing published events
          available in this list.</p>
      </div>
    <?php endif; ?>

    <?php foreach ($publishedEvents as $e): ?>
      <?php $renderEventCard($e, false); ?>
    <?php endforeach; ?>
  </div>
</section>

<section id="finishedPanel" class="hidden">
  <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-5">
    <?php if (count($finishedEvents) === 0): ?>
      <div
        class="md:col-span-2 lg:col-span-3 rounded-2xl border border-dashed border-zinc-300 bg-zinc-50/80 p-12 text-center">
        <div
          class="w-16 h-16 rounded-full bg-white border border-zinc-200 flex items-center justify-center mx-auto mb-4 shadow-sm">
          <svg class="w-8 h-8 text-zinc-400" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z" />
          </svg>
        </div>
        <h3 class="text-lg font-medium text-zinc-800 mb-1">No finished events yet</h3>
        <p class="text-sm text-zinc-600 max-w-md mx-auto">Completed events will stay here instead of being auto-archived
          right away.</p>
      </div>
    <?php endif; ?>

    <?php foreach ($finishedEvents as $e): ?>
      <?php $renderEventCard($e, true); ?>
    <?php endforeach; ?>
  </div>
</section>

<script>
  const publishedBtn = document.getElementById('tabPublished');
  const finishedBtn = document.getElementById('tabFinished');
  const publishedPanel = document.getElementById('publishedPanel');
  const finishedPanel = document.getElementById('finishedPanel');

  function setEventTab(tab) {
    const showPublished = tab === 'published';
    publishedPanel.classList.toggle('hidden', !showPublished);
    finishedPanel.classList.toggle('hidden', showPublished);

    publishedBtn.classList.toggle('bg-orange-600', showPublished);
    publishedBtn.classList.toggle('text-white', showPublished);
    publishedBtn.classList.toggle('shadow-sm', showPublished);
    publishedBtn.classList.toggle('text-zinc-600', !showPublished);
    publishedBtn.classList.toggle('hover:bg-zinc-100', !showPublished);

    finishedBtn.classList.toggle('bg-orange-600', !showPublished);
    finishedBtn.classList.toggle('text-white', !showPublished);
    finishedBtn.classList.toggle('shadow-sm', !showPublished);
    finishedBtn.classList.toggle('text-zinc-600', showPublished);
    finishedBtn.classList.toggle('hover:bg-zinc-100', showPublished);
  }

  publishedBtn?.addEventListener('click', () => setEventTab('published'));
  finishedBtn?.addEventListener('click', () => setEventTab('finished'));
</script>

<?php render_footer();
