<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/session.php';
session_bootstrap();

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/supabase.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/firestore_catalog.php';
require_once __DIR__ . '/includes/registration_access.php';

$user = require_role(['admin', 'teacher']);
$role = (string) ($user['role'] ?? 'teacher');

$select = 'select=id,title,description,location,start_at,end_at,status,event_for,event_type,cover_image_url';
$base = rtrim(SUPABASE_URL, '/') . '/rest/v1/events?' . $select . '&order=start_at.asc';
$url = $base . '&or=(status.eq.published,status.eq.finished)&limit=200';

$headers = [
  'Accept: application/json',
  'apikey: ' . SUPABASE_KEY,
  'Authorization: Bearer ' . SUPABASE_KEY,
];

// Keep event status consistent across pages (throttled to reduce DB writes).
pulse_auto_finish_published_events($headers);
pulse_auto_close_registration_windows($headers);

$events = firestore_catalog_list_events(120);
if ($events === []) {
  $res = supabase_request('GET', $url, $headers);
  if ($res['ok']) {
    $decoded = json_decode((string) $res['body'], true);
    $events = is_array($decoded) ? $decoded : [];
  }
} else {
  // Catalog can lag behind Supabase (covers + status/dates after auto-finish).
  $catalogIds = [];
  foreach ($events as $row) {
    if (!is_array($row)) {
      continue;
    }
    $id = trim((string) ($row['id'] ?? ''));
    if ($id !== '') {
      $catalogIds[] = $id;
    }
  }
  $supabaseById = [];
  if ($catalogIds !== []) {
    $inList = implode(',', array_map(
      static fn(string $id): string => '"' . str_replace('"', '', $id) . '"',
      $catalogIds
    ));
    $hydrateUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/events'
      . '?select=id,cover_image_url,status,start_at,end_at'
      . '&id=in.(' . $inList . ')'
      . '&limit=' . count($catalogIds);
    $hydrateRes = supabase_request('GET', $hydrateUrl, $headers);
    if ($hydrateRes['ok']) {
      $hydrateRows = json_decode((string) ($hydrateRes['body'] ?? ''), true);
      if (is_array($hydrateRows)) {
        foreach ($hydrateRows as $hydrateRow) {
          if (!is_array($hydrateRow)) {
            continue;
          }
          $cid = trim((string) ($hydrateRow['id'] ?? ''));
          if ($cid === '') {
            continue;
          }
          $supabaseById[$cid] = $hydrateRow;
        }
      }
    }
  }

  $livePublished = [];
  foreach ($events as $eventRow) {
    if (!is_array($eventRow)) {
      continue;
    }
    $eid = trim((string) ($eventRow['id'] ?? ''));
    if ($eid !== '' && isset($supabaseById[$eid])) {
      $truth = $supabaseById[$eid];
      $truthStatus = strtolower(trim((string) ($truth['status'] ?? '')));
      // Drop stale catalog docs that are already finished/archived in Supabase.
      if ($truthStatus !== 'published') {
        continue;
      }
      $cover = trim((string) ($truth['cover_image_url'] ?? ''));
      if ($cover !== '') {
        $eventRow['cover_image_url'] = $cover;
      }
      if (trim((string) ($truth['start_at'] ?? '')) !== '') {
        $eventRow['start_at'] = $truth['start_at'];
      }
      if (trim((string) ($truth['end_at'] ?? '')) !== '') {
        $eventRow['end_at'] = $truth['end_at'];
      }
      $eventRow['status'] = 'published';
    }
    $livePublished[] = $eventRow;
  }
  $events = $livePublished;

  // Catalog holds published only — append finished from Supabase (dedupe by id).
  $finishedUrl = $base . '&status=eq.finished&limit=80';
  $finishedRes = supabase_request('GET', $finishedUrl, $headers);
  if ($finishedRes['ok']) {
    $finishedRows = json_decode((string) $finishedRes['body'], true);
    if (is_array($finishedRows)) {
      $seenIds = [];
      foreach ($events as $publishedRow) {
        if (!is_array($publishedRow)) {
          continue;
        }
        $pid = trim((string) ($publishedRow['id'] ?? ''));
        if ($pid !== '') {
          $seenIds[$pid] = true;
        }
      }
      foreach ($finishedRows as $finishedRow) {
        if (!is_array($finishedRow)) {
          continue;
        }
        $fid = trim((string) ($finishedRow['id'] ?? ''));
        if ($fid !== '' && isset($seenIds[$fid])) {
          continue;
        }
        $events[] = $finishedRow;
        if ($fid !== '') {
          $seenIds[$fid] = true;
        }
      }
    }
  }
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
    'published' => ['bg' => 'bg-emerald-500/10 text-emerald-700 border-emerald-500/20', 'dot' => '#10b981'],
    'finished'  => ['bg' => 'bg-zinc-500/10 text-zinc-600 border-zinc-500/20',     'dot' => '#71717a'],
    'pending'   => ['bg' => 'bg-amber-500/10 text-amber-700 border-amber-500/20',   'dot' => '#f59e0b'],
    'approved'  => ['bg' => 'bg-sky-500/10 text-sky-700 border-sky-500/20',         'dot' => '#0ea5e9'],
    'expired'   => ['bg' => 'bg-zinc-500/10 text-zinc-500 border-zinc-500/20',     'dot' => '#a1a1aa'],
    default     => ['bg' => 'bg-zinc-500/10 text-zinc-700 border-zinc-500/20',     'dot' => '#a1a1aa'],
  };

  $rawDate       = (string) ($e['start_at'] ?? '');
  $formattedDate = $rawDate !== '' ? format_date_local($rawDate, 'M d, Y - g:i A') : 'TBA';
  $for           = (string) ($e['event_for'] ?? 'All');
  $targetLabel   = format_target_participant($for);
  $coverUrl      = trim((string) ($e['cover_image_url'] ?? ''));
  $eventId       = htmlspecialchars((string) ($e['id'] ?? ''));
  $title         = htmlspecialchars((string) ($e['title'] ?? 'Event'));
  $desc          = htmlspecialchars((string) ($e['description'] ?? 'No description provided for this event.'));
  $location      = htmlspecialchars((string) ($e['location'] ?? 'Location TBA'));
  $evType        = htmlspecialchars((string) ($e['event_type'] ?? ''));
  $statusLabel   = htmlspecialchars($status);
  ?>
  <!-- 21st.dev 3D Card Container -->
  <div class="pc-3d-card-container py-4 flex items-center justify-center">
    <a href="/event_view.php?id=<?= $eventId ?>" class="pc-3d-card-body block relative bg-white border border-black/10 w-full rounded-2xl p-5 no-underline cursor-pointer" data-3d-card>
      
      <!-- CardItem translateZ="50": Status & Title -->
      <div class="pc-3d-item pc-3d-title-box flex items-center justify-between gap-3 mb-1" data-tz="50">
        <h4 class="text-lg font-bold text-neutral-800 line-clamp-1 flex-1 tracking-tight">
          <?= $title ?>
        </h4>
        <span class="inline-flex items-center gap-1.5 text-[10px] font-bold uppercase tracking-wider rounded-full border px-2.5 py-0.5 <?= $statusConfig['bg'] ?>">
          <span class="w-1.5 h-1.5 rounded-full animate-pulse" style="background:<?= $statusConfig['dot'] ?>"></span>
          <?= $statusLabel ?>
        </span>
      </div>

      <!-- CardItem translateZ="100": Image (Floating 100px above card with deep shadow) -->
      <div class="pc-3d-item pc-3d-img-box w-full mt-4 relative overflow-hidden rounded-xl shadow-md" data-tz="100">
        <?php if ($coverUrl !== ''): ?>
          <img src="<?= htmlspecialchars($coverUrl) ?>" alt="<?= $title ?>" class="h-48 w-full object-cover rounded-xl transition-transform duration-500 ease-out" loading="lazy" decoding="async" />
        <?php else: ?>
          <div class="h-48 w-full bg-gradient-to-br from-orange-500/20 via-amber-500/10 to-red-500/20 rounded-xl flex items-center justify-center border border-orange-500/10">
            <span class="text-orange-600 font-bold text-sm tracking-wide">PulseConnect Event</span>
          </div>
        <?php endif; ?>
      </div>

      <!-- CardItem translateZ="40": Date & Location Meta -->
      <div class="pc-3d-item pc-3d-meta-box mt-4 space-y-2 text-xs text-neutral-600" data-tz="40">
        <div class="flex items-center gap-2 font-medium">
          <svg class="w-4 h-4 text-orange-600 shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z" />
          </svg>
          <span><?= htmlspecialchars($formattedDate) ?></span>
        </div>
        <div class="flex items-center gap-2">
          <svg class="w-4 h-4 text-emerald-600 shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" d="M15 10.5a3 3 0 11-6 0 3 3 0 016 0z" />
            <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1115 0z" />
          </svg>
          <span class="truncate"><?= $location ?></span>
        </div>
      </div>

      <!-- CardItem translateZ="20": Bottom Footer Tags -->
      <div class="pc-3d-item pc-3d-footer-box flex items-center justify-between mt-5 pt-3 border-t border-neutral-100" data-tz="20">
        <div class="flex items-center gap-1.5 flex-wrap">
          <span class="inline-flex items-center gap-1 text-[10px] font-semibold text-neutral-600 bg-neutral-100 px-2 py-0.5 rounded-md border border-neutral-200">
            <?= $targetLabel ?>
          </span>
          <?php if ($evType !== ''): ?>
            <span class="inline-flex items-center gap-1 text-[10px] font-semibold text-neutral-600 bg-neutral-100 px-2 py-0.5 rounded-md border border-neutral-200">
              <?= $evType ?>
            </span>
          <?php endif; ?>
        </div>
      </div>

    </a>
  </div>
  <?php
};

render_header('Events', $user);
?>

<style>
  /* 21st.dev 3D Card Effect Styling */
  .pc-3d-card-container {
    perspective: 1000px;
    padding: 1rem 0;
  }

  .pc-3d-card-body {
    transform-style: preserve-3d;
    will-change: transform;
    transition: box-shadow 0.4s cubic-bezier(0.2, 0.8, 0.2, 1), border-color 0.4s cubic-bezier(0.2, 0.8, 0.2, 1);
  }

  .pc-3d-card-body:hover {
    border-color: rgba(234, 88, 12, 0.35) !important;
    box-shadow: 0 30px 60px -15px rgba(0, 0, 0, 0.2), 0 15px 30px -10px rgba(234, 88, 12, 0.15) !important;
  }

  .pc-3d-item {
    transform-style: preserve-3d;
    transition: transform 0.4s cubic-bezier(0.2, 0.8, 0.2, 1), box-shadow 0.4s cubic-bezier(0.2, 0.8, 0.2, 1), filter 0.4s cubic-bezier(0.2, 0.8, 0.2, 1);
    will-change: transform, box-shadow, filter;
  }

  /* 3D Floating Shadows when Card is Hovered */
  .pc-3d-card-body:hover .pc-3d-img-box {
    box-shadow: 0 25px 45px -12px rgba(0, 0, 0, 0.45), 0 10px 20px -5px rgba(0, 0, 0, 0.2) !important;
  }

  .pc-3d-card-body:hover .pc-3d-img-box img {
    transform: scale(1.04);
  }

  .pc-3d-card-body:hover .pc-3d-title-box {
    filter: drop-shadow(0 10px 8px rgba(0, 0, 0, 0.15));
  }

  .pc-3d-card-body:hover .pc-3d-meta-box {
    filter: drop-shadow(0 6px 6px rgba(0, 0, 0, 0.12));
  }

  .pc-3d-card-body:hover .pc-3d-btn {
    box-shadow: 0 10px 20px -5px rgba(234, 88, 12, 0.45) !important;
  }

  .events-hero {
    position: relative;
    overflow: hidden;
    border-radius: 1.25rem;
    border: 1px solid rgba(120, 53, 15, 0.12);
    background:
      radial-gradient(ellipse 80% 70% at 90% 20%, rgba(249, 115, 22, 0.14), transparent 55%),
      radial-gradient(ellipse 60% 50% at 10% 90%, rgba(127, 29, 29, 0.1), transparent 50%),
      linear-gradient(145deg, #fffaf5 0%, #ffffff 45%, #faf7f4 100%);
    padding: 1.5rem 1.5rem 1.35rem;
    margin-bottom: 1.25rem;
  }

  .events-hero-copy {
    position: relative;
    z-index: 1;
    max-width: 36rem;
  }

  .events-cube-wrap {
    display: none;
  }

  @media (min-width: 900px) {
    .events-hero {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 2rem;
      padding: 1.75rem 2rem;
      min-height: 220px;
    }

    .events-cube-wrap {
      display: flex;
      align-items: center;
      justify-content: center;
      flex-shrink: 0;
      width: 220px;
      height: 220px;
      perspective: 800px;
    }
  }

  .events-cube-container {
    width: 140px;
    height: 140px;
    perspective: 800px;
    transition: transform 0.7s ease-out;
  }

  .events-cube-container:hover {
    transform: scale(1.18);
  }

  .events-cube {
    position: relative;
    width: 100%;
    height: 100%;
    transform-style: preserve-3d;
    animation: eventsCubeSpin 10s infinite linear;
  }

  .events-cube-face {
    --pulse-edge: linear-gradient(
        115deg,
        #7f1d1d,
        #ea580c,
        #fbbf24,
        #ea580c,
        #7f1d1d
      )
      1;
    position: absolute;
    display: flex;
    align-items: center;
    justify-content: center;
    width: 140px;
    height: 140px;
    padding: 0.5rem;
    color: #fff7ed;
    font-size: 12px;
    font-weight: 700;
    letter-spacing: 0.04em;
    text-align: center;
    line-height: 1.25;
    background: #1c0a0acc;
    border: 2px solid;
    border-image: var(--pulse-edge);
    backface-visibility: hidden;
  }

  .events-cube-face span {
    max-width: 100%;
  }

  .events-cube-front {
    transform: translateZ(70px);
  }

  .events-cube-back {
    transform: rotateY(180deg) translateZ(70px);
  }

  .events-cube-right {
    transform: rotateY(90deg) translateZ(70px);
  }

  .events-cube-left {
    transform: rotateY(-90deg) translateZ(70px);
  }

  .events-cube-top {
    transform: rotateX(90deg) translateZ(70px);
  }

  .events-cube-bottom {
    transform: rotateX(-90deg) translateZ(70px);
  }

  @keyframes eventsCubeSpin {
    0% {
      transform: rotateX(0) rotateY(0) rotateZ(0);
    }

    100% {
      transform: rotateX(360deg) rotateY(360deg) rotateZ(360deg);
    }
  }

  @media (prefers-reduced-motion: reduce) {
    .events-cube {
      animation: none;
      transform: rotateX(-18deg) rotateY(32deg);
    }

    .events-cube-container:hover {
      transform: none;
    }
  }
</style>

<div class="events-hero">
  <div class="events-hero-copy">
    <p class="mb-1 text-[11px] font-bold uppercase tracking-[0.18em] text-orange-700/80">PulseConnect · CCS</p>
    <h2 class="text-2xl font-bold text-zinc-900 mb-2 tracking-tight">Explore Events</h2>
    <p class="text-zinc-600 text-sm leading-relaxed max-w-lg">Browse published campus events and review finished ones
      without sending them straight to archive.</p>
  </div>
  <div class="events-cube-wrap" aria-hidden="true">
    <div class="events-cube-container">
      <div class="events-cube">
        <div class="events-cube-face events-cube-front"><span>PulseConnect</span></div>
        <div class="events-cube-face events-cube-back"><span>Stay Linked</span></div>
        <div class="events-cube-face events-cube-right"><span>CCS Events</span></div>
        <div class="events-cube-face events-cube-left"><span>Register</span></div>
        <div class="events-cube-face events-cube-top"><span>Attend</span></div>
        <div class="events-cube-face events-cube-bottom"><span>Engage</span></div>
      </div>
    </div>
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
  /* ── Tab switcher ── */
  const publishedBtn   = document.getElementById('tabPublished');
  const finishedBtn    = document.getElementById('tabFinished');
  const publishedPanel = document.getElementById('publishedPanel');
  const finishedPanel  = document.getElementById('finishedPanel');

  function setEventTab(tab) {
    const showPublished = tab === 'published';
    publishedPanel.classList.toggle('hidden', !showPublished);
    finishedPanel.classList.toggle('hidden',   showPublished);

    publishedBtn.classList.toggle('bg-orange-600',   showPublished);
    publishedBtn.classList.toggle('text-white',       showPublished);
    publishedBtn.classList.toggle('shadow-sm',        showPublished);
    publishedBtn.classList.toggle('text-zinc-600',   !showPublished);
    publishedBtn.classList.toggle('hover:bg-zinc-100',!showPublished);

    finishedBtn.classList.toggle('bg-orange-600',   !showPublished);
    finishedBtn.classList.toggle('text-white',       !showPublished);
    finishedBtn.classList.toggle('shadow-sm',        !showPublished);
    finishedBtn.classList.toggle('text-zinc-600',    showPublished);
    finishedBtn.classList.toggle('hover:bg-zinc-100', showPublished);
  }

  publishedBtn?.addEventListener('click', () => setEventTab('published'));
  finishedBtn?.addEventListener('click',  () => setEventTab('finished'));

  /* ── 3-D Card Effect (Smooth lerp + rAF animation) ── */
  (function () {
    function init3DCard(cardBody) {
      const items = cardBody.querySelectorAll('[data-tz]');
      let rafId = null;
      let isHovered = false;

      let targetX = 0;
      let targetY = 0;
      let currentX = 0;
      let currentY = 0;

      function updateAnimation() {
        // Lerp for 60fps/120fps butter smooth rotational tracking
        currentX += (targetX - currentX) * 0.1;
        currentY += (targetY - currentY) * 0.1;

        cardBody.style.transform = `rotateY(${currentX.toFixed(2)}deg) rotateX(${currentY.toFixed(2)}deg)`;

        // Continue animation loop while hovered or while still resetting back to 0
        if (isHovered || Math.abs(targetX - currentX) > 0.01 || Math.abs(targetY - currentY) > 0.01) {
          rafId = requestAnimationFrame(updateAnimation);
        } else {
          cardBody.style.transform = 'rotateY(0deg) rotateX(0deg)';
          rafId = null;
        }
      }

      function startLoop() {
        if (!rafId) {
          rafId = requestAnimationFrame(updateAnimation);
        }
      }

      cardBody.addEventListener('mouseenter', () => {
        isHovered = true;
        items.forEach((item) => {
          const tz = item.getAttribute('data-tz') || 0;
          const tx = item.getAttribute('data-tx') || 0;
          const ty = item.getAttribute('data-ty') || 0;
          item.style.transform = `translateX(${tx}px) translateY(${ty}px) translateZ(${tz}px)`;
        });
        startLoop();
      });

      cardBody.addEventListener('mousemove', (e) => {
        const rect = cardBody.getBoundingClientRect();
        targetX = (e.clientX - rect.left - rect.width / 2) / 20;
        targetY = -(e.clientY - rect.top - rect.height / 2) / 20;
        startLoop();
      });

      cardBody.addEventListener('mouseleave', () => {
        isHovered = false;
        targetX = 0;
        targetY = 0;
        items.forEach((item) => {
          item.style.transform = `translateX(0px) translateY(0px) translateZ(0px) rotateX(0deg) rotateY(0deg) rotateZ(0deg)`;
        });
        startLoop();
      });
    }

    document.querySelectorAll('[data-3d-card]').forEach(init3DCard);
  })();
</script>

<?php render_footer();
