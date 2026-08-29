<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/session.php';
session_bootstrap();

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/supabase.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/showcase_lib.php';

$user = require_role(['admin']);

$headers = showcase_service_headers();
$slidesUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/app_showcase_slides'
    . '?select=id,label,image_url,storage_path,sort_order,is_active,updated_at,created_at'
    . '&order=sort_order.asc,created_at.asc';
$slidesRes = supabase_request('GET', $slidesUrl, $headers);
$slides = [];
if ($slidesRes['ok']) {
    $decoded = json_decode((string) ($slidesRes['body'] ?? ''), true);
    if (is_array($decoded)) {
        $slides = array_values(array_filter($decoded, 'is_array'));
    }
}

$activeCount = 0;
$activeSlides = [];
foreach ($slides as $slide) {
    if (($slide['is_active'] ?? false) === true) {
        $activeCount++;
        $imageUrl = trim((string) ($slide['image_url'] ?? ''));
        if ($imageUrl !== '') {
            $activeSlides[] = $slide;
        }
    }
}

render_header('Showcase', $user);
?>

<style>
  .sc-page { max-width: 72rem; margin: 0 auto; }

  .sc-header {
    display: flex;
    flex-wrap: wrap;
    align-items: flex-end;
    justify-content: space-between;
    gap: 1rem;
    margin-bottom: 1.5rem;
  }

  .sc-header h1 {
    font-size: 1.5rem;
    font-weight: 700;
    color: #18181b;
    margin: 0;
  }

  .sc-header p {
    margin: 0.35rem 0 0;
    font-size: 0.875rem;
    color: #71717a;
    max-width: 38rem;
    line-height: 1.5;
  }

  .sc-badge {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.45rem 0.9rem;
    border-radius: 999px;
    border: 1px solid #e4e4e7;
    background: #fff;
    font-size: 0.875rem;
    color: #52525b;
    white-space: nowrap;
  }

  .sc-badge-dot {
    width: 0.5rem;
    height: 0.5rem;
    border-radius: 999px;
    background: #facc15;
  }

  .sc-header-actions {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 0.65rem;
  }

  .sc-card {
    border: 1px solid #e4e4e7;
    border-radius: 1rem;
    background: #fff;
    padding: 1.25rem;
    box-shadow: 0 1px 2px rgba(0, 0, 0, 0.04);
    margin-bottom: 1.5rem;
  }

  .sc-card-title {
    font-size: 1.05rem;
    font-weight: 700;
    color: #18181b;
    margin: 0 0 0.25rem;
  }

  .sc-card-sub {
    font-size: 0.75rem;
    color: #71717a;
    margin: 0 0 1rem;
  }

  .sc-form-row {
    display: flex;
    flex-wrap: wrap;
    gap: 0.75rem;
    align-items: flex-end;
    margin-bottom: 0.75rem;
  }

  .sc-field { flex: 1 1 12rem; min-width: 0; }

  .sc-label {
    display: block;
    font-size: 0.7rem;
    font-weight: 700;
    letter-spacing: 0.04em;
    text-transform: uppercase;
    color: #71717a;
    margin-bottom: 0.35rem;
  }

  .sc-input {
    width: 100%;
    border: 1px solid #d4d4d8;
    border-radius: 0.75rem;
    padding: 0.6rem 0.85rem;
    font-size: 0.875rem;
    color: #18181b;
    background: #fff;
    box-sizing: border-box;
  }

  .sc-input:focus {
    outline: none;
    border-color: #f97316;
    box-shadow: 0 0 0 3px rgba(249, 115, 22, 0.15);
  }

  .sc-drop {
    display: flex;
    align-items: center;
    gap: 1rem;
    border: 2px dashed #d4d4d8;
    border-radius: 0.85rem;
    background: #fff;
    padding: 0.85rem 1rem;
    cursor: pointer;
    transition: border-color 0.15s, background 0.15s;
    min-height: 5.5rem;
  }

  .sc-drop:hover,
  .sc-drop.is-dragover {
    border-color: #f97316;
    background: #fff;
  }

  .sc-drop-icon {
    flex-shrink: 0;
    width: 2.5rem;
    height: 2.5rem;
    border-radius: 0.65rem;
    background: #ffedd5;
    color: #ea580c;
    display: flex;
    align-items: center;
    justify-content: center;
  }

  .sc-drop-text strong {
    display: block;
    font-size: 0.875rem;
    color: #27272a;
    margin-bottom: 0.15rem;
  }

  .sc-drop-text span {
    font-size: 0.75rem;
    color: #71717a;
  }

  .sc-drop-thumb {
    flex-shrink: 0;
    width: 7rem;
    height: 4.375rem;
    border-radius: 0.55rem;
    overflow: hidden;
    background: #e4e4e7;
    border: 1px solid #e4e4e7;
    display: none;
  }

  .sc-drop-thumb.is-visible { display: block; }

  .sc-drop-thumb img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    display: block;
  }

  .sc-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border: none;
    border-radius: 0.75rem;
    padding: 0.65rem 1.1rem;
    font-size: 0.875rem;
    font-weight: 700;
    cursor: pointer;
    transition: background 0.15s, opacity 0.15s;
    white-space: nowrap;
  }

  .sc-btn-primary {
    background: #ea580c;
    color: #fff;
  }

  .sc-btn-primary:hover { background: #c2410c; }
  .sc-btn-primary:disabled { opacity: 0.55; cursor: not-allowed; }

  .sc-btn-dark {
    background: #18181b;
    color: #fff;
  }

  .sc-btn-dark:hover { background: #27272a; }

  .sc-msg { font-size: 0.875rem; margin: 0.5rem 0 0; }
  .sc-msg.ok { color: #059669; }
  .sc-msg.err { color: #dc2626; }

  .sc-slides-head {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: 0.75rem;
    margin-bottom: 1rem;
  }

  .sc-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(17rem, 1fr));
    gap: 1rem;
  }

  .sc-slide {
    border: 1px solid #e4e4e7;
    border-radius: 0.85rem;
    overflow: hidden;
    background: #fff;
    transition: box-shadow 0.15s;
  }

  .sc-slide:hover { box-shadow: 0 8px 24px rgba(0, 0, 0, 0.07); }
  .sc-slide.is-dragging { opacity: 0.5; }

  .sc-slide-media {
    position: relative;
    width: 100%;
    height: 9.5rem;
    background: #e4e4e7;
    cursor: zoom-in;
    overflow: hidden;
  }

  .sc-slide-media img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    display: block;
  }

  .sc-slide-media.is-broken::after {
    content: 'Image unavailable';
    position: absolute;
    inset: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 0.75rem;
    font-weight: 600;
    color: #71717a;
    background: #f4f4f5;
  }

  .sc-slide-order {
    position: absolute;
    top: 0.65rem;
    left: 0.65rem;
    z-index: 2;
    min-width: 1.85rem;
    height: 1.85rem;
    border-radius: 0.5rem;
    background: #ea580c; /* High contrast system orange */
    color: #ffffff;
    font-size: 0.75rem;
    font-weight: 800;
    display: flex;
    align-items: center;
    justify-content: center;
    box-shadow: 0 4px 10px rgba(234, 88, 12, 0.35), 0 2px 4px rgba(0, 0, 0, 0.15);
    border: 1px solid rgba(255, 255, 255, 0.2);
  }

  .sc-slide-drag {
    position: absolute;
    top: 0.65rem;
    right: 0.65rem;
    z-index: 2;
    width: 1.85rem;
    height: 1.85rem;
    border-radius: 0.5rem;
    background: #ffffff; /* Solid white background for visibility */
    color: #4b5563;      /* High contrast dark gray icon */
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: grab;
    box-shadow: 0 4px 10px rgba(0, 0, 0, 0.12), 0 2px 4px rgba(0, 0, 0, 0.08);
    border: 1px solid #e4e4e7;
    transition: color 0.15s, background-color 0.15s, border-color 0.15s;
  }

  .sc-slide-drag:hover {
    background-color: #f4f4f5;
    color: #111827;
    border-color: #d4d4d8;
  }
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: grab;
    box-shadow: 0 1px 4px rgba(0, 0, 0, 0.12);
  }

  .sc-slide-inactive {
    position: absolute;
    inset: 0;
    background: rgba(0, 0, 0, 0.45);
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 1;
  }

  .sc-slide-inactive span {
    background: rgba(255, 255, 255, 0.95);
    color: #3f3f46;
    font-size: 0.68rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    padding: 0.25rem 0.65rem;
    border-radius: 999px;
  }

  .sc-slide-body {
    padding: 0.75rem;
    border-top: 1px solid #f4f4f5;
  }

  .sc-slide-actions {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-top: 0.55rem;
    font-size: 0.875rem;
  }

  /* Custom Toggle Switch */
  .sc-toggle-container {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    cursor: pointer;
    user-select: none;
  }

  .sc-toggle-input {
    position: absolute;
    opacity: 0;
    width: 0;
    height: 0;
  }

  .sc-toggle-slider {
    position: relative;
    display: inline-block;
    width: 2.25rem;
    height: 1.25rem;
    background-color: #e4e4e7;
    border-radius: 999px;
    transition: background-color 0.2s cubic-bezier(0.4, 0, 0.2, 1);
    border: 1px solid #d4d4d8;
  }

  .sc-toggle-slider::before {
    content: "";
    position: absolute;
    height: 0.9rem;
    width: 0.9rem;
    left: 0.1rem;
    bottom: 0.1rem;
    background-color: white;
    border-radius: 50%;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.15);
    transition: transform 0.2s cubic-bezier(0.4, 0, 0.2, 1);
  }

  .sc-toggle-input:checked + .sc-toggle-slider {
    background-color: #ea580c;
    border-color: #ea580c;
  }

  .sc-toggle-input:checked + .sc-toggle-slider::before {
    transform: translateX(1rem);
  }

  .sc-toggle-label {
    font-size: 0.75rem;
    font-weight: 700;
    transition: color 0.15s;
    text-transform: uppercase;
    letter-spacing: 0.04em;
  }

  .sc-toggle-input:checked ~ .sc-toggle-label {
    color: #ea580c;
  }

  .sc-toggle-input:not(:checked) ~ .sc-toggle-label {
    color: #71717a;
  }

  .sc-delete {
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
    border: 1px solid #fecaca;
    background: #fff5f5;
    color: #dc2626;
    font-size: 0.78rem;
    font-weight: 700;
    cursor: pointer;
    padding: 0.45rem 0.75rem;
    border-radius: 0.55rem;
    transition: background 0.15s, border-color 0.15s, color 0.15s;
  }

  .sc-delete:hover {
    background: #dc2626;
    border-color: #dc2626;
    color: #fff;
  }

  .sc-empty {
    border: 1px dashed #d4d4d8;
    border-radius: 0.85rem;
    background: #fafafa;
    padding: 2.5rem 1.5rem;
    text-align: center;
    color: #71717a;
    font-size: 0.875rem;
  }

  .sc-lightbox {
    position: fixed;
    inset: 0;
    z-index: 80;
    background: rgba(0, 0, 0, 0.82);
    display: none;
    align-items: center;
    justify-content: center;
    padding: 1.5rem;
  }

  .sc-lightbox.is-open { display: flex; }

  .sc-lightbox img {
    max-width: min(1100px, 100%);
    max-height: 85vh;
    border-radius: 0.75rem;
    object-fit: contain;
  }

  .sc-lightbox-close {
    position: absolute;
    top: 1rem;
    right: 1rem;
    width: 2.25rem;
    height: 2.25rem;
    border: none;
    border-radius: 999px;
    background: rgba(255, 255, 255, 0.15);
    color: #fff;
    font-size: 1.35rem;
    line-height: 1;
    cursor: pointer;
  }

  .sc-modal-backdrop {
    position: fixed;
    inset: 0;
    z-index: 100;
    background: rgba(15, 23, 42, 0.38);
    backdrop-filter: blur(10px);
    -webkit-backdrop-filter: blur(10px);
    display: flex;
    align-items: flex-end;
    justify-content: center;
    opacity: 0;
    pointer-events: none;
    transition: opacity 0.25s ease;
    padding: 0;
  }

  @media (min-width: 640px) {
    .sc-modal-backdrop {
      align-items: center;
      padding: 1.5rem;
    }
  }

  .sc-modal-backdrop.active {
    opacity: 1;
    pointer-events: auto;
  }

  .sc-modal-panel {
    width: 100%;
    max-width: 32rem;
    border-radius: 1.25rem 1.25rem 0 0;
    border: 1px solid #e4e4e7;
    border-bottom: none;
    background: #fff;
    box-shadow: 0 -8px 40px rgba(15, 23, 42, 0.12);
    transform: translateY(100%);
    transition: transform 0.35s cubic-bezier(0.16, 1, 0.3, 1);
    max-height: 92vh;
    overflow: hidden;
    display: flex;
    flex-direction: column;
  }

  @media (min-width: 640px) {
    .sc-modal-panel {
      border-radius: 1.25rem;
      border-bottom: 1px solid #e4e4e7;
      transform: translateY(24px) scale(0.96);
      max-height: 85vh;
    }
  }

  .sc-modal-backdrop.active .sc-modal-panel {
    transform: translateY(0) scale(1);
  }

  .sc-modal-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 0.75rem;
    padding: 1.1rem 1.25rem;
    border-bottom: 1px solid #e4e4e7;
  }

  .sc-modal-head h2 {
    margin: 0;
    font-size: 1rem;
    font-weight: 700;
    color: #18181b;
  }

  .sc-modal-head p {
    margin: 0.15rem 0 0;
    font-size: 0.72rem;
    color: #71717a;
  }

  .sc-modal-close {
    width: 2.25rem;
    height: 2.25rem;
    border: none;
    border-radius: 0.65rem;
    background: transparent;
    color: #71717a;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
  }

  .sc-modal-close:hover {
    background: #f4f4f5;
    color: #18181b;
  }

  .sc-modal-body {
    padding: 1.1rem 1.25rem 1.25rem;
    overflow-y: auto;
  }

  .sc-modal-foot {
    display: flex;
    gap: 0.65rem;
    justify-content: flex-end;
    padding-top: 0.25rem;
  }

  .sc-btn-secondary {
    background: #fff;
    color: #3f3f46;
    border: 1px solid #d4d4d8;
  }

  .sc-btn-secondary:hover { background: #fafafa; }

  .sc-modal-preview {
    margin-top: 0.75rem;
    width: 100%;
    max-height: 220px;
    border-radius: 0.75rem;
    overflow: hidden;
    background: #fff;
    border: 1px solid #e4e4e7;
    display: none;
    align-items: center;
    justify-content: center;
    padding: 0.4rem;
    box-sizing: border-box;
  }

  .sc-modal-preview.is-visible {
    display: flex;
  }

  .sc-modal-preview img {
    width: 100%;
    height: auto;
    max-height: 200px;
    object-fit: contain;
    object-position: center;
    display: block;
    border-radius: 0.45rem;
  }

  /* --- 3D Collection Surfer Carousel CSS --- */
  .sc-preview-container {
    position: relative;
    width: 100%;
    height: 380px;
    background: linear-gradient(to bottom right, #450a0a, #7f1d1d, #450a0a);
    border: 1px solid rgba(239, 68, 68, 0.3);
    border-radius: 1.25rem;
    overflow: hidden;
    margin-bottom: 1.75rem;
    color: #fff;
    user-select: none;
    box-shadow: 0 20px 25px -5px rgba(69, 10, 10, 0.25), 0 8px 10px -6px rgba(0, 0, 0, 0.15);
    cursor: grab;
  }

  .sc-preview-container::before {
    content: '';
    position: absolute;
    inset: 0;
    border-radius: inherit;
    background-image: url('data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHdpZHRoPSI4IiBoZWlnaHQ9IjgiPgo8cmVjdCB3aWR0aD0iOCIgaGVpZ2h0PSI4IiBmaWxsPSIjZmZmIiBmaWxsLW9wYWNpdHk9IjAuMDUiLz4KPC9zdmc+');
    opacity: 0.2;
    mix-blend-mode: overlay;
    pointer-events: none;
    z-index: 1;
  }

  .sc-preview-container:active {
    cursor: grabbing;
  }

  .sc-scene {
    position: absolute;
    inset: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    perspective: 1500px;
    perspective-origin: 15% 25%;
    overflow: hidden;
    z-index: 2;
  }

  .sc-track {
    position: relative;
    width: 0;
    height: 0;
    transform-style: preserve-3d;
    will-change: transform;
  }

  .sc-preview-card {
    position: absolute;
    width: 270px;
    height: 152px;
    background: #18181b;
    border-radius: 0.85rem;
    overflow: hidden;
    box-shadow: 0 12px 28px rgba(0, 0, 0, 0.45);
    transform-style: preserve-3d;
    border: 1px solid rgba(255, 255, 255, 0.06);
    top: -76px; /* center vertically (height / 2) */
    left: -135px; /* center horizontally (width / 2) */
    /* Do not transition transform in CSS to prevent scrolling fight lag! */
    transition: filter 0.3s ease;
  }

  .sc-preview-card img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    display: block;
    filter: brightness(0.65) contrast(1.05);
    transition: filter 0.3s ease;
  }

  .sc-preview-card:hover img {
    filter: brightness(0.95) contrast(1);
  }

  .sc-preview-empty {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    text-align: center;
    padding: 2rem 1.5rem;
    height: 100%;
    color: #a1a1aa;
  }

  .sc-preview-empty strong {
    display: block;
    color: #f4f4f5;
    font-size: 0.95rem;
    margin-bottom: 0.35rem;
  }

  .sc-preview-head {
    position: relative;
    z-index: 10;
  }

  .sc-preview-head h2 {
    font-size: 1.6rem;
    font-weight: 900;
    line-height: 1.1;
    letter-spacing: -0.03em;
    color: #fff;
    margin: 0;
    text-transform: uppercase;
  }

  .sc-preview-head-kicker {
    font-size: 0.72rem;
    font-weight: 700;
    letter-spacing: 0.18em;
    text-transform: uppercase;
    color: #facc15;
    margin-bottom: 4px;
  }

  .sc-preview-head-meta {
    font-family: monospace;
    font-size: 0.65rem;
    color: #a1a1aa;
    margin-top: 5px;
  }

  .sc-preview-progress-track {
    width: 110px;
    height: 2px;
    background: rgba(255, 255, 255, 0.15);
    border-radius: 99px;
    overflow: hidden;
    position: relative;
  }

  .sc-preview-hint {
    position: absolute;
    bottom: 24px;
    right: 24px;
    z-index: 10;
    font-family: monospace;
    font-size: 0.68rem;
    text-transform: uppercase;
    letter-spacing: 0.08em;
    color: #a1a1aa;
    display: flex;
    align-items: center;
    gap: 8px;
    pointer-events: none;
  }

  @keyframes bounce-horizontal {
    0%, 100% { transform: translateX(0); }
    50% { transform: translateX(4px); }
  }
</style>

<div class="sc-page">
  <div class="sc-header">
    <div>
      <h1>Featured Showcase</h1>
      <p>Manage rotating images on the mobile app home screen and Manage Events banner. Images are compressed on upload.</p>
    </div>
    <div class="sc-header-actions">
      <div class="sc-badge">
        <span class="sc-badge-dot"></span>
        <span><strong><?= (int) $activeCount ?></strong> / <?= SHOWCASE_MAX_ACTIVE_SLIDES ?> active</span>
      </div>
      <button type="button" id="showcaseOpenModalBtn" class="sc-btn sc-btn-primary">+ Add slide</button>
    </div>
  </div>

  <!-- Live 3D Collection Surfer Preview -->
  <?php
    $previewIsDefault = $activeSlides === [];
    $previewSlides = $previewIsDefault ? showcase_default_fallback_slides() : $activeSlides;
    $previewCount = count($previewSlides);
    $duplicatedPreview = array_merge($previewSlides, $previewSlides);
  ?>
  <div id="scPreviewContainer" class="sc-preview-container">
    <!-- Scene -->
    <div class="sc-scene">
      <div id="scTrack" class="sc-track">
        <?php foreach ($duplicatedPreview as $i => $slide): 
            $label = htmlspecialchars((string) ($slide['label'] ?? 'Slide'), ENT_QUOTES, 'UTF-8');
            $imageUrl = htmlspecialchars((string) ($slide['image_url'] ?? ''), ENT_QUOTES, 'UTF-8');
            $slideIndexNum = ($i % $previewCount) + 1;
        ?>
          <div class="sc-preview-card" data-index="<?= $i ?>">
            <div style="position: absolute; top: 12px; left: 12px; z-index: 2; font-family: monospace; font-size: 0.68rem; color: rgba(255,255,255,0.75); background: rgba(0,0,0,0.65); padding: 2px 6px; border-radius: 4px; border: 1px solid rgba(255,255,255,0.1);"><?= str_pad((string) $slideIndexNum, 2, '0', STR_PAD_LEFT) ?></div>
            <img src="<?= $imageUrl ?>" alt="<?= $label ?>" draggable="false" onerror="this.style.display='none';" />
            <div style="position: absolute; inset: 0; background: linear-gradient(to top, rgba(9,9,11,0.9) 0%, rgba(9,9,11,0.2) 60%, transparent 100%); pointer-events: none;"></div>
            <div style="position: absolute; bottom: 0; left: 0; right: 0; padding: 14px; z-index: 2;">
              <div style="font-family: monospace; font-size: 0.62rem; color: #a1a1aa; margin-bottom: 2px; text-transform: uppercase; letter-spacing: 0.05em;">Showcase Slide</div>
              <div style="font-weight: 700; font-size: 0.8rem; color: #fff; line-height: 1.2; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; text-transform: uppercase; letter-spacing: -0.01em;"><?= $label ?></div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="sc-preview-head" style="position: absolute; top: 24px; left: 24px; pointer-events: none;">
      <div class="sc-preview-head-kicker">Live Preview</div>
      <h2>Event Showcase</h2>
      <div class="sc-preview-head-meta">
        <?php if ($previewIsDefault): ?>
          DEFAULT SLIDES (<?= $previewCount ?>) · upload to replace
        <?php else: ?>
          ACTIVE SLIDES (<?= $previewCount ?>)
        <?php endif; ?>
      </div>
    </div>

    <!-- UI Overlay Progress indicator -->
    <div style="position: absolute; bottom: 24px; left: 24px; z-index: 10; display: flex; align-items: center; gap: 10px;">
      <div class="sc-preview-progress-track">
        <div id="scProgressFill" style="position: absolute; top: 0; left: 0; height: 100%; width: 0%; background: #facc15; transition: width 0.05s ease-out;"></div>
      </div>
    </div>

    <!-- UI Overlay Hint -->
    <div class="sc-preview-hint">
      <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24" style="animation: bounce-horizontal 1.5s infinite;">
        <path stroke-linecap="round" stroke-linejoin="round" d="M17.25 8.25L21 12m0 0l-3.75 3.75M21 12H3"/>
      </svg>
      Drag or Scroll to Surf
    </div>
  </div>

  <section class="sc-card" style="margin-bottom:0;">
    <div class="sc-slides-head">
      <div>
        <h2 class="sc-card-title" style="margin-bottom:0.15rem;">Slides</h2>
        <p class="sc-card-sub" style="margin:0;">Drag to reorder · click image to enlarge · inactive slides are hidden from users</p>
      </div>
      <button type="button" id="showcaseSaveOrderBtn" class="sc-btn sc-btn-dark" style="display:none;">Save order</button>
    </div>

    <?php if ($slides === []): ?>
      <div class="sc-empty">
        No custom slides yet. Default bundled images show on the app and Manage Events until you upload here.
        <div style="margin-top:0.85rem;">
          <button type="button" id="showcaseOpenModalBtnEmpty" class="sc-btn sc-btn-primary">+ Add your first slide</button>
        </div>
      </div>
    <?php else: ?>
      <div id="showcaseGrid" class="sc-grid">
        <?php foreach ($slides as $index => $slide):
            $id = htmlspecialchars((string) ($slide['id'] ?? ''), ENT_QUOTES, 'UTF-8');
            $label = htmlspecialchars((string) ($slide['label'] ?? ''), ENT_QUOTES, 'UTF-8');
            $imageUrl = htmlspecialchars((string) ($slide['image_url'] ?? ''), ENT_QUOTES, 'UTF-8');
            $isActive = ($slide['is_active'] ?? false) === true;
            $order = (int) $index + 1;
        ?>
          <article class="sc-slide" draggable="true" data-id="<?= $id ?>">
            <div class="sc-slide-media" data-full="<?= $imageUrl ?>" data-label="<?= $label ?>">
              <span class="sc-slide-order">#<?= $order ?></span>
              <span class="sc-slide-drag" title="Drag to reorder" aria-hidden="true">
                <svg width="12" height="12" fill="currentColor" viewBox="0 0 20 20">
                  <path d="M7 4a1 1 0 110-2 1 1 0 010 2zm6-1a1 1 0 100-2 1 1 0 000 2zM7 10a1 1 0 110-2 1 1 0 010 2zm6-1a1 1 0 100-2 1 1 0 000 2zM7 16a1 1 0 110-2 1 1 0 010 2zm6-1a1 1 0 100-2 1 1 0 000 2z"/>
                </svg>
              </span>
              <?php if ($imageUrl !== ''): ?>
                <img src="<?= $imageUrl ?>" alt="<?= $label ?>" loading="lazy"
                  onerror="this.style.display='none'; this.parentElement.classList.add('is-broken');" />
              <?php endif; ?>
              <?php if (!$isActive): ?>
                <div class="sc-slide-inactive"><span>Inactive</span></div>
              <?php endif; ?>
            </div>
            <div class="sc-slide-body">
              <!-- Input field with pencil icon -->
              <div style="position: relative; margin-bottom: 0.75rem;">
                <span style="position: absolute; left: 10px; top: 50%; transform: translateY(-50%); color: #71717a; pointer-events: none; display: flex; align-items: center; justify-content: center;">
                  <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931zm0 0L19.5 7.125M18 14v4.75A2.25 2.25 0 0115.75 21H5.25A2.25 2.25 0 013 18.75V8.25A2.25 2.25 0 015.25 6H10"/>
                  </svg>
                </span>
                <input type="text" value="<?= $label ?>" maxlength="80"
                  class="sc-input showcase-label" data-id="<?= $id ?>" style="padding-left: 2.15rem; font-weight: 600;" placeholder="Enter slide label..." />
              </div>
              <div class="sc-slide-actions">
                <!-- Custom iOS-style toggle switch -->
                <label class="sc-toggle-container">
                  <input type="checkbox" class="showcase-active sc-toggle-input" data-id="<?= $id ?>" <?= $isActive ? 'checked' : '' ?> />
                  <span class="sc-toggle-slider"></span>
                  <span class="sc-toggle-label"><?= $isActive ? 'Active' : 'Inactive' ?></span>
                </label>
                <!-- Red Trash can delete button -->
                <button type="button" class="sc-delete showcase-delete" data-id="<?= $id ?>" title="Delete slide">
                  <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                  </svg>
                  Delete
                </button>
              </div>
            </div>
          </article>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </section>
</div>

<div id="showcaseAddModal" class="sc-modal-backdrop" aria-hidden="true">
  <div class="sc-modal-panel" role="dialog" aria-modal="true" aria-labelledby="showcaseModalTitle">
    <div class="sc-modal-head">
      <div>
        <h2 id="showcaseModalTitle">Add slide</h2>
        <p>JPEG, PNG, or WebP · max 5 MB · landscape recommended</p>
      </div>
      <button type="button" id="showcaseCloseModalBtn" class="sc-modal-close" aria-label="Close">
        <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
        </svg>
      </button>
    </div>
    <div class="sc-modal-body">
      <form id="showcaseUploadForm" enctype="multipart/form-data">
        <div class="sc-field" style="margin-bottom:0.85rem;">
          <label class="sc-label" for="slideLabel">Label</label>
          <input id="slideLabel" name="label" type="text" maxlength="80" required
            placeholder="e.g. CCS Summit" class="sc-input" />
        </div>

        <label class="sc-label" for="slideFile">Image</label>
        <div id="showcaseUploadDrop" class="sc-drop">
          <div class="sc-drop-icon" aria-hidden="true">
            <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5m-13.5-9L12 3m0 0l4.5 4.5M12 3v13.5"/>
            </svg>
          </div>
          <div class="sc-drop-text">
            <strong id="showcaseDropTitle">Click to choose an image</strong>
            <span id="showcaseDropHint">or drag and drop here</span>
          </div>
          <input id="slideFile" name="slide_file" type="file" accept="image/jpeg,image/png,image/webp" required
            style="position:absolute;width:1px;height:1px;opacity:0;pointer-events:none;" />
        </div>

        <div id="showcaseModalPreview" class="sc-modal-preview" aria-hidden="true">
          <img id="showcaseModalPreviewImg" src="" alt="Selected image preview" />
        </div>

        <p id="showcaseUploadMsg" class="sc-msg" style="display:none;"></p>

        <div class="sc-modal-foot">
          <button type="button" id="showcaseCancelModalBtn" class="sc-btn sc-btn-secondary">Cancel</button>
          <button type="submit" id="showcaseUploadBtn" class="sc-btn sc-btn-primary">Upload slide</button>
        </div>
      </form>
    </div>
  </div>
</div>

<div id="showcaseLightbox" class="sc-lightbox" aria-hidden="true">
  <button type="button" id="showcaseLightboxClose" class="sc-lightbox-close" aria-label="Close">&times;</button>
  <img id="showcaseLightboxImg" src="" alt="" />
</div>

<script>
(function () {
  const csrf = window.CSRF_TOKEN || '';
  const uploadForm = document.getElementById('showcaseUploadForm');
  const uploadBtn = document.getElementById('showcaseUploadBtn');
  const uploadMsg = document.getElementById('showcaseUploadMsg');
  const uploadDrop = document.getElementById('showcaseUploadDrop');
  const dropTitle = document.getElementById('showcaseDropTitle');
  const dropHint = document.getElementById('showcaseDropHint');
  const modalPreview = document.getElementById('showcaseModalPreview');
  const modalPreviewImg = document.getElementById('showcaseModalPreviewImg');
  const fileInput = document.getElementById('slideFile');
  const addModal = document.getElementById('showcaseAddModal');
  const openModalBtn = document.getElementById('showcaseOpenModalBtn');
  const openModalBtnEmpty = document.getElementById('showcaseOpenModalBtnEmpty');
  const closeModalBtn = document.getElementById('showcaseCloseModalBtn');
  const cancelModalBtn = document.getElementById('showcaseCancelModalBtn');
  const slideLabelInput = document.getElementById('slideLabel');
  const grid = document.getElementById('showcaseGrid');
  const saveOrderBtn = document.getElementById('showcaseSaveOrderBtn');
  const lightbox = document.getElementById('showcaseLightbox');
  const lightboxImg = document.getElementById('showcaseLightboxImg');
  const lightboxClose = document.getElementById('showcaseLightboxClose');

  function openAddModal() {
    if (!addModal) return;
    addModal.classList.add('active');
    addModal.setAttribute('aria-hidden', 'false');
    document.body.style.overflow = 'hidden';
    window.setTimeout(() => slideLabelInput?.focus(), 120);
  }

  function closeAddModal() {
    if (!addModal) return;
    addModal.classList.remove('active');
    addModal.setAttribute('aria-hidden', 'true');
    document.body.style.overflow = '';
    resetUploadForm();
  }

  function resetUploadForm() {
    uploadForm?.reset();
    showMsg('', true);
    setUploadPreview(null);
    if (uploadBtn) uploadBtn.disabled = false;
  }

  openModalBtn?.addEventListener('click', openAddModal);
  openModalBtnEmpty?.addEventListener('click', openAddModal);
  closeModalBtn?.addEventListener('click', closeAddModal);
  cancelModalBtn?.addEventListener('click', closeAddModal);
  addModal?.addEventListener('click', (e) => {
    if (e.target === addModal) closeAddModal();
  });
  document.addEventListener('keydown', (e) => {
    if (e.key !== 'Escape') return;
    if (addModal?.classList.contains('active')) {
      closeAddModal();
      return;
    }
    if (lightbox?.classList.contains('is-open')) {
      closeLightbox();
    }
  });

  function showMsg(text, ok) {
    if (!uploadMsg) return;
    uploadMsg.textContent = text;
    uploadMsg.style.display = text ? 'block' : 'none';
    uploadMsg.className = 'sc-msg ' + (ok ? 'ok' : 'err');
  }

  function openFilePicker() {
    fileInput?.click();
  }

  function setUploadPreview(file) {
    if (!file) {
      if (dropTitle) dropTitle.textContent = 'Click to choose an image';
      if (dropHint) {
        dropHint.textContent = 'or drag and drop here';
        dropHint.style.display = '';
      }
      modalPreview?.classList.remove('is-visible');
      if (modalPreviewImg) modalPreviewImg.src = '';
      return;
    }
    const reader = new FileReader();
    reader.onload = () => {
      if (modalPreviewImg) modalPreviewImg.src = String(reader.result);
      modalPreview?.classList.add('is-visible');
    };
    reader.readAsDataURL(file);
    if (dropTitle) dropTitle.textContent = file.name;
    if (dropHint) dropHint.style.display = 'none';
  }

  function handleSelectedFile(file) {
    if (!file) return;
    if (!/^image\/(jpeg|png|webp)$/i.test(file.type)) {
      showMsg('Please choose a JPEG, PNG, or WebP image.', false);
      return;
    }
    if (file.size > <?= SHOWCASE_MAX_UPLOAD_BYTES ?>) {
      showMsg('Image is too large. Maximum size is 5 MB.', false);
      return;
    }
    showMsg('', true);
    setUploadPreview(file);
  }

  uploadDrop?.addEventListener('click', (e) => {
    if (e.target === fileInput) return;
    openFilePicker();
  });

  fileInput?.addEventListener('change', () => {
    handleSelectedFile(fileInput.files && fileInput.files[0] ? fileInput.files[0] : null);
  });

  uploadDrop?.addEventListener('dragover', (e) => {
    e.preventDefault();
    uploadDrop.classList.add('is-dragover');
  });
  uploadDrop?.addEventListener('dragleave', () => uploadDrop.classList.remove('is-dragover'));
  uploadDrop?.addEventListener('drop', (e) => {
    e.preventDefault();
    uploadDrop.classList.remove('is-dragover');
    const file = e.dataTransfer?.files?.[0];
    if (!file || !fileInput) return;
    const dt = new DataTransfer();
    dt.items.add(file);
    fileInput.files = dt.files;
    handleSelectedFile(file);
  });

  uploadForm?.addEventListener('submit', async (e) => {
    e.preventDefault();
    if (!uploadForm || !uploadBtn) return;
    uploadBtn.disabled = true;
    showMsg('Uploading…', true);
    try {
      const fd = new FormData(uploadForm);
      fd.append('csrf_token', csrf);
      const res = await fetch('/api/showcase_slide_upload.php', {
        method: 'POST',
        body: fd,
        credentials: 'same-origin',
      });
      const data = await res.json();
      if (!data.ok) throw new Error(data.error || 'Upload failed.');
      showMsg('Slide uploaded. Reloading…', true);
      window.location.reload();
    } catch (err) {
      showMsg(err.message || 'Upload failed.', false);
      uploadBtn.disabled = false;
    }
  });

  async function patchSlide(id, payload) {
    const res = await fetch('/api/showcase_slides_save.php', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ csrf_token: csrf, action: 'update', id, ...payload }),
    });
    const data = await res.json();
    if (!data.ok) throw new Error(data.error || 'Update failed.');
    return data;
  }

  document.querySelectorAll('.showcase-label').forEach((input) => {
    input.addEventListener('change', async () => {
      const id = input.dataset.id || '';
      try {
        await patchSlide(id, { label: input.value.trim() });
      } catch (err) {
        alert(err.message || 'Unable to save label.');
      }
    });
  });

  document.querySelectorAll('.showcase-active').forEach((checkbox) => {
    checkbox.addEventListener('change', async () => {
      const id = checkbox.dataset.id || '';
      try {
        await patchSlide(id, { is_active: checkbox.checked });
        window.location.reload();
      } catch (err) {
        checkbox.checked = !checkbox.checked;
        alert(err.message || 'Unable to update status.');
      }
    });
  });

  document.querySelectorAll('.showcase-delete').forEach((btn) => {
    btn.addEventListener('click', async () => {
      const id = btn.dataset.id || '';
      if (!id || !confirm('Delete this showcase slide?')) return;
      try {
        const res = await fetch('/api/showcase_slide_delete.php', {
          method: 'POST',
          credentials: 'same-origin',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ csrf_token: csrf, id }),
        });
        const data = await res.json();
        if (!data.ok) throw new Error(data.error || 'Delete failed.');
        window.location.reload();
      } catch (err) {
        alert(err.message || 'Unable to delete slide.');
      }
    });
  });

  document.querySelectorAll('.sc-slide-media').forEach((media) => {
    media.addEventListener('click', () => {
      const src = media.dataset.full || '';
      const label = media.dataset.label || 'Showcase image';
      if (!src || !lightbox || !lightboxImg) return;
      lightboxImg.src = src;
      lightboxImg.alt = label;
      lightbox.classList.add('is-open');
      lightbox.setAttribute('aria-hidden', 'false');
    });
  });

  function closeLightbox() {
    if (!lightbox || !lightboxImg) return;
    lightbox.classList.remove('is-open');
    lightbox.setAttribute('aria-hidden', 'true');
    lightboxImg.src = '';
  }

  lightboxClose?.addEventListener('click', closeLightbox);
  lightbox?.addEventListener('click', (e) => {
    if (e.target === lightbox) closeLightbox();
  });

  function updateOrderBadges() {
    if (!grid) return;
    grid.querySelectorAll('.sc-slide').forEach((card, index) => {
      const badge = card.querySelector('.sc-slide-order');
      if (badge) badge.textContent = '#' + (index + 1);
    });
  }

  if (grid) {
    let dragEl = null;
    grid.querySelectorAll('.sc-slide').forEach((card) => {
      card.addEventListener('dragstart', () => {
        dragEl = card;
        card.classList.add('is-dragging');
      });
      card.addEventListener('dragend', () => {
        card.classList.remove('is-dragging');
        dragEl = null;
        updateOrderBadges();
        if (saveOrderBtn) saveOrderBtn.style.display = '';
      });
      card.addEventListener('dragover', (e) => {
        e.preventDefault();
        if (!dragEl || dragEl === card) return;
        const rect = card.getBoundingClientRect();
        const after = (e.clientY - rect.top) > rect.height / 2;
        grid.insertBefore(dragEl, after ? card.nextSibling : card);
      });
    });

    saveOrderBtn?.addEventListener('click', async () => {
      const order = Array.from(grid.querySelectorAll('.sc-slide'))
        .map((card) => card.dataset.id || '')
        .filter(Boolean);
      try {
        const res = await fetch('/api/showcase_slides_save.php', {
          method: 'POST',
          credentials: 'same-origin',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ csrf_token: csrf, action: 'reorder', order }),
        });
        const data = await res.json();
        if (!data.ok) throw new Error(data.error || 'Reorder failed.');
        saveOrderBtn.style.display = 'none';
      } catch (err) {
        alert(err.message || 'Unable to save order.');
      }
    });
  }

  <?php if ($previewCount > 0): ?>
  // --- 3D Collection Surfer Controller ---
  const previewContainer = document.getElementById('scPreviewContainer');
  const track = document.getElementById('scTrack');
  const progressFill = document.getElementById('scProgressFill');
  const previewCards = document.querySelectorAll('.sc-preview-card');

  const slideCount = <?= (int) $previewCount ?>;
  const scrollPerItem = 400;
  const loopDistance = slideCount * scrollPerItem;
  
  let scrollPosition = 0;        // Current rendered scroll position
  let targetScrollPosition = 0;  // Target scroll position for easing
  let animationFrameId = null;   // RAF tracking id
  let isDragging = false;
  let startX = 0;
  let startScrollPos = 0;
  let autoScrollRaf = null;
  let lastAutoTick = 0;

  // Proportional step vector for wide landscape cards (width 270px)
  const stepX = 230;
  const stepY = -30;
  const stepZ = -170;

  function updateTrack() {
    if (!track) return;
    
    // Normalize scrollPosition within loopDistance range (modulo)
    let loopedProgress = scrollPosition % loopDistance;
    if (loopedProgress < 0) loopedProgress += loopDistance;
    
    // Calculate progress percentage
    const progressPercent = (loopedProgress / loopDistance) * 100;
    if (progressFill) {
      progressFill.style.width = progressPercent + '%';
    }
    
    // Calculate track translation
    const trackX = - (loopedProgress / loopDistance) * (slideCount * stepX);
    const trackY = - (loopedProgress / loopDistance) * (slideCount * stepY);
    const trackZ = - (loopedProgress / loopDistance) * (slideCount * stepZ);
    
    track.style.transform = `translate3d(${trackX}px, ${trackY}px, ${trackZ}px)`;
    
    // Position each duplicated card dynamically
    previewCards.forEach((card, index) => {
      const baseX = index * stepX;
      const baseY = index * stepY;
      const baseZ = index * stepZ;
      
      if (!card.dataset.hoverScale) {
        card.style.transform = `translate3d(${baseX}px, ${baseY}px, ${baseZ}px) rotateY(-45deg) scale(1)`;
      } else {
        const scaleVal = parseFloat(card.dataset.hoverScale);
        const upliftVal = parseFloat(card.dataset.hoverUplift);
        card.style.transform = `translate3d(${baseX}px, ${baseY + upliftVal}px, ${baseZ}px) rotateY(-45deg) scale(${scaleVal})`;
      }
    });
  }

  // Silky-smooth LERP (Linear Interpolation) animation tick for wheel scrolling
  function smoothScrollTick() {
    const diff = targetScrollPosition - scrollPosition;
    
    if (Math.abs(diff) > 0.05) {
      scrollPosition += diff * 0.11; // 0.11 factor gives a luxurious, fluid deceleration
      updateTrack();
      animationFrameId = requestAnimationFrame(smoothScrollTick);
    } else {
      scrollPosition = targetScrollPosition;
      updateTrack();
      animationFrameId = null;
    }
  }

  function triggerSmoothScroll(targetVal) {
    targetScrollPosition = targetVal;
    if (animationFrameId === null) {
      previewCards.forEach(c => c.style.transition = 'none');
      animationFrameId = requestAnimationFrame(smoothScrollTick);
    }
  }

  function autoScrollLoop(timestamp) {
    if (!isDragging && slideCount > 1) {
      if (!lastAutoTick) lastAutoTick = timestamp;
      const elapsed = timestamp - lastAutoTick;
      if (elapsed >= 16) {
        scrollPosition += 1.15;
        targetScrollPosition = scrollPosition;
        updateTrack();
        lastAutoTick = timestamp;
      }
    } else {
      lastAutoTick = timestamp;
    }
    autoScrollRaf = requestAnimationFrame(autoScrollLoop);
  }

  function startAutoPlay() {
    if (slideCount <= 1 || autoScrollRaf) return;
    lastAutoTick = 0;
    autoScrollRaf = requestAnimationFrame(autoScrollLoop);
  }

  function stopAutoPlay() {
    if (!autoScrollRaf) return;
    cancelAnimationFrame(autoScrollRaf);
    autoScrollRaf = null;
    lastAutoTick = 0;
  }

  function handleMouseMove(e) {
    if (!previewContainer || isDragging) return;
    
    const clientX = e.clientX;
    const clientY = e.clientY;
    
    previewCards.forEach((card) => {
      const rect = card.getBoundingClientRect();
      const centerX = rect.left + rect.width / 2;
      const centerY = rect.top + rect.height / 2;
      
      const dx = clientX - centerX;
      const dy = clientY - centerY;
      const distance = Math.sqrt(dx * dx + dy * dy);
      
      const maxDistance = 240; // activation radius
      if (distance < maxDistance) {
        const factor = 1 - (distance / maxDistance); // 0 -> 1
        const scale = 1 + factor * 0.22; // scale up to 1.22
        const uplift = factor * -28;    // move up by 28px
        
        card.dataset.hoverScale = scale;
        card.dataset.hoverUplift = uplift;
      } else {
        delete card.dataset.hoverScale;
        delete card.dataset.hoverUplift;
      }
    });
    
    // Fast springy transitions for responsive hover updates
    previewCards.forEach(c => c.style.transition = 'transform 0.12s cubic-bezier(0.25, 1, 0.5, 1)');
    updateTrack();
  }

  function handleMouseLeave() {
    previewCards.forEach((card) => {
      delete card.dataset.hoverScale;
      delete card.dataset.hoverUplift;
      card.style.transition = 'transform 0.4s cubic-bezier(0.16, 1, 0.3, 1)';
    });
    updateTrack();
  }

  // Mouse Drag surfing
  previewContainer?.addEventListener('mousedown', (e) => {
    isDragging = true;
    stopAutoPlay();
    startX = e.clientX;
    startScrollPos = scrollPosition;
    if (animationFrameId !== null) {
      cancelAnimationFrame(animationFrameId);
      animationFrameId = null;
    }
    previewCards.forEach(c => c.style.transition = 'none');
  });

  window.addEventListener('mousemove', (e) => {
    if (!isDragging) return;
    const dx = e.clientX - startX;
    scrollPosition = startScrollPos - dx * 1.4;
    targetScrollPosition = scrollPosition; // keep in sync during active drag
    updateTrack();
  });

  window.addEventListener('mouseup', () => {
    if (isDragging) {
      isDragging = false;
      previewCards.forEach(c => c.style.transition = 'transform 0.4s cubic-bezier(0.16, 1, 0.3, 1)');
      startAutoPlay();
    }
  });

  // Touch Swipe surfing
  previewContainer?.addEventListener('touchstart', (e) => {
    isDragging = true;
    stopAutoPlay();
    startX = e.touches[0].clientX;
    startScrollPos = scrollPosition;
    if (animationFrameId !== null) {
      cancelAnimationFrame(animationFrameId);
      animationFrameId = null;
    }
    previewCards.forEach(c => c.style.transition = 'none');
  });

  previewContainer?.addEventListener('touchmove', (e) => {
    if (!isDragging) return;
    const dx = e.touches[0].clientX - startX;
    scrollPosition = startScrollPos - dx * 1.4;
    targetScrollPosition = scrollPosition; // keep in sync
    updateTrack();
  });

  previewContainer?.addEventListener('touchend', () => {
    isDragging = false;
    previewCards.forEach(c => c.style.transition = 'transform 0.4s cubic-bezier(0.16, 1, 0.3, 1)');
    startAutoPlay();
  });

  // Wheel Surf (lerps scroll over preview window, prevents page scroll)
  previewContainer?.addEventListener('wheel', (e) => {
    e.preventDefault();
    triggerSmoothScroll(targetScrollPosition + e.deltaY * 0.7);
  }, { passive: false });

  // Initialize
  updateTrack();
  startAutoPlay();
  previewContainer?.addEventListener('mousemove', handleMouseMove);
  previewContainer?.addEventListener('mouseleave', () => {
    handleMouseLeave();
    startAutoPlay();
  });
  <?php endif; ?>
})();
</script>

<?php render_footer(); ?>
