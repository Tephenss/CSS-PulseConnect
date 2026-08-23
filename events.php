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
      . '?select=id,cover_image_url,status,start_at,end_at,early_out_enabled_at'
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
      // Empty is authoritative too (e.g. a broken/deleted cover was cleared).
      $eventRow['cover_image_url'] = $cover;
      if (trim((string) ($truth['start_at'] ?? '')) !== '') {
        $eventRow['start_at'] = $truth['start_at'];
      }
      if (trim((string) ($truth['end_at'] ?? '')) !== '') {
        $eventRow['end_at'] = $truth['end_at'];
      }
      if (array_key_exists('early_out_enabled_at', $truth)) {
        $eventRow['early_out_enabled_at'] = $truth['early_out_enabled_at'];
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
      if (!function_exists('attendance_event_is_past_lifecycle')) {
        require_once __DIR__ . '/includes/event_attendance_windows.php';
      }
      $eventEnd = new DateTimeImmutable($endSource);
      $earlyOut = isset($eventRow['early_out_enabled_at'])
        ? (string) $eventRow['early_out_enabled_at']
        : null;
      // Early Out → leave Published at early_out+1h; else end_at+1h.
      $isFinished = attendance_event_is_past_lifecycle($eventEnd, $earlyOut, $now);
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

<!-- Include GSAP, ScrollTrigger, and Lenis CDN for 100% accurate Osmo Parallax Animation -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/gsap/3.12.5/gsap.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/gsap/3.12.5/ScrollTrigger.min.js"></script>
<script src="https://unpkg.com/lenis@1.1.14/dist/lenis.min.js"></script>

<style>
  /* Full-bleed against dashboard header + sidebar (no white/black frame gap). */
  main.content-area {
    padding: 0 !important;
    background: transparent;
  }

  /* ── 100% Accurate Osmo Parallax Component CSS ── */
  .parallax {
    width: 100%;
    position: relative;
    background-color: #ffffff;
    border-radius: 0;
    overflow: hidden;
    box-shadow: none;
    margin: 0;
    min-height: calc(100vh - 4rem);
  }

  .parallax__header {
    position: relative;
    z-index: 1;
    width: 100%;
    height: 78vh;
    min-height: 480px;
    max-height: 820px;
    overflow: hidden;
  }

  .parallax__visuals {
    width: 100%;
    height: 100%;
    position: absolute;
    top: 0;
    left: 0;
    overflow: hidden;
  }

  .parallax__black-line-overflow {
    display: none;
  }

  .parallax__layers {
    width: 100%;
    height: 100%;
    position: relative;
  }

  [data-parallax-layer="1"] {
    z-index: 1;
  }

  [data-parallax-layer="2"] {
    z-index: 2;
  }

  .parallax__layer-title,
  [data-parallax-layer="3"] {
    position: absolute;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    display: flex;
    justify-content: center;
    align-items: center;
    z-index: 3;
    will-change: transform;
    pointer-events: none;
  }

  [data-parallax-layer="4"] {
    z-index: 4;
  }

  .parallax__layer-img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    position: absolute;
    top: 0;
    left: 0;
    will-change: transform;
    pointer-events: none;
  }

  .parallax__title {
    color: #ffffff;
    font-family: system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
    font-size: clamp(4.5rem, 14vw, 11rem);
    font-weight: 900;
    line-height: 0.85;
    letter-spacing: -0.04em;
    text-transform: uppercase;
    margin: 0;
    padding: 0;
    text-align: center;
    pointer-events: none;
    user-select: none;
  }

  .parallax__fade {
    width: 100%;
    height: 25vh;
    background-image: linear-gradient(to bottom, rgba(255, 255, 255, 0), #ffffff);
    position: absolute;
    bottom: 0;
    left: 0;
    z-index: 5;
    pointer-events: none;
  }

  .parallax__content {
    position: relative;
    z-index: 6;
    background-color: #ffffff;
    padding: 2.5rem clamp(1.25rem, 3vw, 2.5rem) 4rem;
    border-top: none;
  }

  /* Keep page footer matching the white events surface. */
  #main-wrapper > footer {
    border-top-color: #e4e4e7;
    background: #ffffff;
    color: #71717a;
  }
  #main-wrapper > footer span {
    color: #71717a;
  }

  /* 21st.dev 3D Card Effect Styling */
  .pc-3d-card-container {
    perspective: 1000px;
    padding: 0.5rem 0;
  }

  .pc-3d-card-body {
    transform-style: preserve-3d;
    will-change: transform;
    background-color: #ffffff;
    border: 1px solid #e4e4e7;
    box-shadow: 0 10px 28px rgba(15, 23, 42, 0.08), 0 2px 8px rgba(15, 23, 42, 0.04);
    transition: box-shadow 0.4s cubic-bezier(0.2, 0.8, 0.2, 1), border-color 0.4s cubic-bezier(0.2, 0.8, 0.2, 1);
  }

  .pc-3d-card-body:hover {
    border-color: rgba(249, 115, 22, 0.55) !important;
    box-shadow: 0 18px 36px rgba(15, 23, 42, 0.12), 0 8px 18px rgba(249, 115, 22, 0.18) !important;
  }

  .pc-3d-item {
    transform-style: preserve-3d;
    transition: transform 0.4s cubic-bezier(0.2, 0.8, 0.2, 1), box-shadow 0.4s cubic-bezier(0.2, 0.8, 0.2, 1), filter 0.4s cubic-bezier(0.2, 0.8, 0.2, 1);
    will-change: transform, box-shadow, filter;
  }

  .pc-3d-card-body:hover .pc-3d-img-box {
    box-shadow: 0 25px 45px -12px rgba(0, 0, 0, 0.65), 0 10px 20px -5px rgba(0, 0, 0, 0.4) !important;
  }

  .pc-3d-card-body:hover .pc-3d-img-box img {
    transform: scale(1.05);
  }

  .pc-3d-card-body:hover .pc-3d-title-box {
    filter: drop-shadow(0 10px 8px rgba(0, 0, 0, 0.25));
  }

  .pc-3d-card-body:hover .pc-3d-btn {
    box-shadow: 0 10px 20px -5px rgba(234, 88, 12, 0.55) !important;
  }
</style>

<!-- ── PARALLAX SCROLLING COMPONENT (Exact Osmo Supply Template) ── -->
<div class="parallax" id="parallaxRef">
  <section class="parallax__header">
    <div class="parallax__visuals">
      <div class="parallax__black-line-overflow"></div>
      <div data-parallax-layers class="parallax__layers">
        
        <!-- Layer 1: Sky & Distant Mountain Background -->
        <img src="https://cdn.prod.website-files.com/671752cd4027f01b1b8f1c7f/6717795be09b462b2e8ebf71_osmo-parallax-layer-3.webp" 
             loading="eager" width="800" data-parallax-layer="1" alt="" 
             class="parallax__layer-img" />
        
        <!-- Layer 2: Midground Mountain Ridge -->
        <img src="https://cdn.prod.website-files.com/671752cd4027f01b1b8f1c7f/6717795b4d5ac529e7d3a562_osmo-parallax-layer-2.webp" 
             loading="eager" width="800" data-parallax-layer="2" alt="" 
             class="parallax__layer-img" />
        
        <!-- Layer 3: Title (Slides down behind Layer 4) -->
        <div data-parallax-layer="3" class="parallax__layer-title">
          <h2 class="parallax__title">Events</h2>
        </div>
        
        <!-- Layer 4: Foreground Rock & Person Standing (Sits in front of Title) -->
        <img src="assets\2fa12b03-f80c-4e75-a35f-a78808cf99f1.png" 
             loading="eager" width="800" data-parallax-layer="4" alt="" 
             class="parallax__layer-img" />
      </div>
      
      <div class="parallax__fade"></div>
    </div>
  </section>

  <!-- PARALLAX CONTENT SECTION (Events List Revealed on Scroll) -->
  <section class="parallax__content">
    
    <!-- White High-Visibility Tab Switcher Bar -->
    <div class="mb-8 flex flex-wrap items-center gap-2 rounded-2xl border border-zinc-200 bg-white p-2 shadow-lg w-fit">
      <button type="button" id="tabPublished"
        class="event-tab-btn rounded-xl bg-orange-600 px-5 py-2.5 text-sm font-bold text-white shadow-md transition-all duration-300 flex items-center gap-2">
        Published
        <span id="badgePublished" class="ml-1.5 rounded-full bg-white/20 px-2.5 py-0.5 text-[11px] font-extrabold text-white"><?= count($publishedEvents) ?></span>
      </button>
      <button type="button" id="tabFinished"
        class="event-tab-btn rounded-xl px-5 py-2.5 text-sm font-bold text-zinc-700 hover:bg-zinc-100 transition-all duration-300 flex items-center gap-2">
        Finished
        <span id="badgeFinished" class="ml-1.5 rounded-full bg-zinc-200 px-2.5 py-0.5 text-[11px] font-bold text-zinc-700"><?= count($finishedEvents) ?></span>
      </button>
    </div>

    <!-- Published Panel -->
    <section id="publishedPanel">
      <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
        <?php if (count($publishedEvents) === 0): ?>
          <div class="md:col-span-2 lg:col-span-3 rounded-2xl border border-dashed border-zinc-300 bg-zinc-50 p-12 text-center">
            <div class="w-16 h-16 rounded-full bg-white border border-zinc-200 flex items-center justify-center mx-auto mb-4 shadow-sm">
              <svg class="w-8 h-8 text-zinc-400" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" d="M15.182 15.182a4.5 4.5 0 01-6.364 0M21 12a9 9 0 11-18 0 9 9 0 0118 0zM9.75 9.75c0 .414-.168.75-.375.75S9 10.164 9 9.75 9.168 9 9.375 9s.375.336.375.75zm3.625 0c0 .414-.168.75-.375.75s-.375-.336-.375-.75.168-.75.375-.75.375.336.375.75z" />
              </svg>
            </div>
            <h3 class="text-lg font-medium text-zinc-800 mb-1">No published events found</h3>
            <p class="text-sm text-zinc-500 max-w-md mx-auto">There are currently no upcoming or ongoing published events available in this list.</p>
          </div>
        <?php endif; ?>

        <?php foreach ($publishedEvents as $e): ?>
          <?php $renderEventCard($e, false); ?>
        <?php endforeach; ?>
      </div>
    </section>

    <!-- Finished Panel -->
    <section id="finishedPanel" class="hidden">
      <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
        <?php if (count($finishedEvents) === 0): ?>
          <div class="md:col-span-2 lg:col-span-3 rounded-2xl border border-dashed border-zinc-300 bg-zinc-50 p-12 text-center">
            <div class="w-16 h-16 rounded-full bg-white border border-zinc-200 flex items-center justify-center mx-auto mb-4 shadow-sm">
              <svg class="w-8 h-8 text-zinc-400" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z" />
              </svg>
            </div>
            <h3 class="text-lg font-medium text-zinc-800 mb-1">No finished events yet</h3>
            <p class="text-sm text-zinc-500 max-w-md mx-auto">Completed events will stay here instead of being auto-archived right away.</p>
          </div>
        <?php endif; ?>

        <?php foreach ($finishedEvents as $e): ?>
          <?php $renderEventCard($e, true); ?>
        <?php endforeach; ?>
      </div>
    </section>

  </section>
</div>

<script>
  /* ── 100% Exact Osmo Parallax Animation & Lenis Integration ── */
  document.addEventListener('DOMContentLoaded', () => {
    if (typeof gsap !== 'undefined' && typeof ScrollTrigger !== 'undefined') {
      gsap.registerPlugin(ScrollTrigger);

      const triggerElement = document.querySelector('[data-parallax-layers]');

      if (triggerElement) {
        const tl = gsap.timeline({
          scrollTrigger: {
            trigger: triggerElement,
            start: "0% 0%",
            end: "100% 0%",
            scrub: 0
          }
        });

        const layers = [
          { layer: "1", yPercent: 70 },
          { layer: "2", yPercent: 55 },
          { layer: "3", yPercent: 40 },
          { layer: "4", yPercent: 10 }
        ];

        layers.forEach((layerObj, idx) => {
          tl.to(
            triggerElement.querySelectorAll(`[data-parallax-layer="${layerObj.layer}"]`),
            {
              yPercent: layerObj.yPercent,
              ease: "none"
            },
            idx === 0 ? undefined : "<"
          );
        });
      }
    }

    if (typeof Lenis !== 'undefined') {
      const lenis = new Lenis({
        duration: 1.2,
        easing: (t) => Math.min(1, 1.001 - Math.pow(2, -10 * t)),
        smoothWheel: true,
      });
      lenis.on('scroll', ScrollTrigger.update);
      gsap.ticker.add((time) => { lenis.raf(time * 1000); });
      gsap.ticker.lagSmoothing(0);
    }
  });

  /* ── High Visibility Tab Switcher ── */
  const publishedBtn   = document.getElementById('tabPublished');
  const finishedBtn    = document.getElementById('tabFinished');
  const badgePublished = document.getElementById('badgePublished');
  const badgeFinished  = document.getElementById('badgeFinished');
  const publishedPanel = document.getElementById('publishedPanel');
  const finishedPanel  = document.getElementById('finishedPanel');

  function setEventTab(tab) {
    const showPublished = tab === 'published';
    publishedPanel.classList.toggle('hidden', !showPublished);
    finishedPanel.classList.toggle('hidden',   showPublished);

    publishedBtn.classList.toggle('bg-orange-600',   showPublished);
    publishedBtn.classList.toggle('text-white',       showPublished);
    publishedBtn.classList.toggle('shadow-md',        showPublished);
    publishedBtn.classList.toggle('text-zinc-700',    !showPublished);
    publishedBtn.classList.toggle('hover:bg-zinc-100',!showPublished);

    if (badgePublished) {
      badgePublished.className = showPublished
        ? 'ml-1.5 rounded-full bg-white/20 px-2.5 py-0.5 text-[11px] font-extrabold text-white'
        : 'ml-1.5 rounded-full bg-zinc-200 px-2.5 py-0.5 text-[11px] font-bold text-zinc-700';
    }

    finishedBtn.classList.toggle('bg-orange-600',   !showPublished);
    finishedBtn.classList.toggle('text-white',       !showPublished);
    finishedBtn.classList.toggle('shadow-md',        !showPublished);
    finishedBtn.classList.toggle('text-zinc-700',    showPublished);
    finishedBtn.classList.toggle('hover:bg-zinc-100', showPublished);

    if (badgeFinished) {
      badgeFinished.className = !showPublished
        ? 'ml-1.5 rounded-full bg-white/20 px-2.5 py-0.5 text-[11px] font-extrabold text-white'
        : 'ml-1.5 rounded-full bg-zinc-200 px-2.5 py-0.5 text-[11px] font-bold text-zinc-700';
    }
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
        currentX += (targetX - currentX) * 0.1;
        currentY += (targetY - currentY) * 0.1;

        cardBody.style.transform = `rotateY(${currentX.toFixed(2)}deg) rotateX(${currentY.toFixed(2)}deg)`;

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

