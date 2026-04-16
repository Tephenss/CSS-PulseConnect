<?php
declare(strict_types=1);

session_start();

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';

$user = require_role(['teacher', 'admin']);

render_header('QR Scanner', $user);
?>

<div class="mb-8 flex items-center justify-between">
  <div>
    <h2 class="text-xl font-bold text-zinc-900 mb-1">Auto-Assigned QR Scanner</h2>
    <p class="text-zinc-600 text-sm">Scanner activates only during your assigned event/seminar scan window.</p>
  </div>
</div>

<div class="grid grid-cols-1 xl:grid-cols-3 gap-6">
  <div class="xl:col-span-2">
    <div class="rounded-2xl border border-zinc-200 bg-white shadow-sm relative overflow-hidden p-1 border-t-[3px] border-t-orange-500">
      <div class="absolute inset-x-0 -top-32 h-64 bg-orange-400/10 blur-3xl pointer-events-none rounded-t-full"></div>

      <div class="p-4 sm:p-5 relative z-10 border-b border-zinc-200 flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div class="flex items-center gap-3">
          <div class="w-10 h-10 rounded-xl bg-orange-100 border border-orange-200 flex items-center justify-center flex-shrink-0">
            <svg class="w-5 h-5 text-orange-700" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6.827 6.175A2.31 2.31 0 015.186 7.23c-.38.054-.757.112-1.134.175C2.999 7.58 2.25 8.507 2.25 9.574V18a2.25 2.25 0 002.25 2.25h15A2.25 2.25 0 0021.75 18V9.574c0-1.067-.75-1.994-1.802-2.169a47.865 47.865 0 00-1.134-.175 2.31 2.31 0 01-1.64-1.055l-.822-1.316a2.192 2.192 0 00-1.736-1.039 48.774 48.774 0 00-5.232 0 2.192 2.192 0 00-1.736 1.039l-.821 1.316z"/><path stroke-linecap="round" stroke-linejoin="round" d="M16.5 12.75a4.5 4.5 0 11-9 0 4.5 4.5 0 019 0z"/></svg>
          </div>
          <div>
            <span class="text-sm font-bold text-zinc-900 block tracking-tight">Camera Feed</span>
            <span class="text-xs text-zinc-500 block">Camera stays off until scanning window opens</span>
          </div>
        </div>

        <div id="cameraModeBadge" class="inline-flex items-center gap-2 rounded-xl border border-zinc-200 bg-zinc-50 px-4 py-2 text-xs font-bold uppercase tracking-widest text-zinc-700">
          <span class="inline-flex h-2 w-2 rounded-full bg-zinc-500"></span>
          Scanner Off
        </div>
      </div>

      <div class="p-4 sm:p-5 relative z-10 w-full flex justify-center">
        <div class="relative rounded-xl overflow-hidden shadow-inner flex items-center justify-center bg-zinc-900 border border-zinc-300 w-full max-w-2xl mx-auto aspect-square sm:aspect-video min-h-[300px]">
           <div id="reader" class="w-full relative [&_video]:w-full [&_video]:h-full [&_video]:object-cover overflow-hidden rounded-lg"></div>
        </div>
      </div>
    </div>
  </div>

  <div class="space-y-6">
    <div class="rounded-2xl border border-zinc-200 bg-white p-6 border-b-[3px] border-b-emerald-500 shadow-sm hover:shadow-md transition-all relative overflow-hidden">
      <div class="absolute -right-8 -top-8 w-32 h-32 bg-emerald-400/10 blur-3xl rounded-full pointer-events-none"></div>

      <div class="flex items-center justify-between gap-2 mb-4 relative z-10">
        <div class="flex items-center gap-3">
          <div class="w-10 h-10 rounded-xl bg-emerald-100 border border-emerald-200 flex items-center justify-center flex-shrink-0">
            <svg class="w-5 h-5 text-emerald-700" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M3 7.5l9-4.5 9 4.5-9 4.5-9-4.5z"/><path stroke-linecap="round" stroke-linejoin="round" d="M3 7.5v9l9 4.5 9-4.5v-9"/></svg>
          </div>
          <div>
            <h3 class="text-sm font-bold text-zinc-900 tracking-tight">Scanner Context</h3>
            <p class="text-[10px] text-zinc-500 tracking-wider uppercase font-semibold">Auto Detection</p>
          </div>
        </div>
        <button id="refreshContextBtn" type="button" class="rounded-lg border border-zinc-200 bg-zinc-50 px-3 py-1.5 text-[11px] font-semibold text-zinc-700 hover:bg-zinc-100 transition">
          Refresh
        </button>
      </div>

      <div class="rounded-xl bg-zinc-50 border border-zinc-200 p-4 space-y-3 relative z-10">
        <div class="flex items-center justify-between gap-3">
          <span class="text-[11px] uppercase tracking-wider font-semibold text-zinc-500">Status</span>
          <span id="scanContextStatusChip" class="inline-flex items-center rounded-full border border-zinc-300 bg-white px-2.5 py-1 text-[11px] font-bold text-zinc-700">Waiting</span>
        </div>
        <div>
          <p class="text-[11px] uppercase tracking-wider font-semibold text-zinc-500 mb-1">Event</p>
          <p id="scanContextEvent" class="text-sm font-bold text-zinc-900">Checking assignment...</p>
        </div>
        <div>
          <p class="text-[11px] uppercase tracking-wider font-semibold text-zinc-500 mb-1">Seminar</p>
          <p id="scanContextSession" class="text-sm font-semibold text-zinc-700">-</p>
        </div>
        <div>
          <p class="text-[11px] uppercase tracking-wider font-semibold text-zinc-500 mb-1">Scan Window</p>
          <p id="scanContextWindow" class="text-sm text-zinc-700">-</p>
        </div>
        <p id="scanContextMessage" class="text-xs text-zinc-600 leading-relaxed"></p>
      </div>
    </div>

    <div class="rounded-2xl border border-zinc-200 bg-white p-6 border-b-[3px] border-b-sky-500 shadow-sm hover:shadow-md transition-all relative overflow-hidden group">
      <div class="absolute -right-8 -top-8 w-32 h-32 bg-sky-400/10 blur-3xl rounded-full pointer-events-none"></div>

      <div class="flex items-center gap-3 mb-5 relative z-10">
        <div class="w-10 h-10 rounded-xl bg-sky-100 border border-sky-200 flex items-center justify-center flex-shrink-0">
          <svg class="w-5 h-5 text-sky-700" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M10.125 2.25h-4.5c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125v-9M10.125 2.25h.375a9 9 0 019 9v.375M10.125 2.25A3.375 3.375 0 0113.5 5.625v1.5c0 .621.504 1.125 1.125 1.125h1.5a3.375 3.375 0 013.375 3.375M9 15l2.25 2.25L15 12"/></svg>
        </div>
        <div>
           <h3 class="text-sm font-bold text-zinc-900 tracking-tight">Scan Status</h3>
           <p class="text-[10px] text-zinc-500 tracking-wider uppercase font-semibold">Live Updates</p>
        </div>
      </div>

      <div class="rounded-xl bg-zinc-50 border border-zinc-200 p-5 relative z-10">
        <div id="status" class="text-sm font-medium text-zinc-800 flex items-center gap-3">
          <span class="relative flex h-3 w-3 flex-shrink-0">
             <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-zinc-500 opacity-75"></span>
             <span class="relative inline-flex rounded-full h-3 w-3 bg-zinc-500"></span>
          </span>
          Checking scanner context...
        </div>
        <div id="details" class="mt-4 pt-4 border-t border-zinc-200 text-xs text-zinc-600 break-all font-mono leading-relaxed hidden"></div>
      </div>
    </div>

    <div class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm">
      <h3 class="text-xs font-bold text-zinc-600 uppercase tracking-widest mb-4 flex items-center gap-2">
        <svg class="w-4 h-4 text-zinc-500" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M11.25 11.25l.041-.02a.75.75 0 011.063.852l-.708 2.836a.75.75 0 001.063.853l.041-.021M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-9-3.75h.008v.008H12V8.25z"/></svg>
        Pro Tips
      </h3>
      <ul class="space-y-4">
        <li class="flex items-start gap-3">
          <div class="w-6 h-6 rounded-full bg-emerald-100 border border-emerald-200 text-emerald-800 flex items-center justify-center flex-shrink-0 mt-0.5"><svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/></svg></div>
          <span class="text-xs text-zinc-600 leading-relaxed font-medium">Scanner opens exactly at schedule start and closes at the configured window end.</span>
        </li>
        <li class="flex items-start gap-3">
          <div class="w-6 h-6 rounded-full bg-amber-100 border border-amber-200 text-amber-900 flex items-center justify-center flex-shrink-0 mt-0.5"><svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M7.5 21L3 16.5m0 0L7.5 12M3 16.5h13.5m0-13.5L21 7.5m0 0L16.5 12M21 7.5H7.5"/></svg></div>
          <span class="text-xs text-zinc-600 leading-relaxed font-medium">Valid scans are marked as <strong>Present</strong>. Late/time-out labels are not used.</span>
        </li>
        <li class="flex items-start gap-3">
          <div class="w-6 h-6 rounded-full bg-sky-100 border border-sky-200 text-sky-800 flex items-center justify-center flex-shrink-0 mt-0.5"><svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 10.5V6.75a4.5 4.5 0 119 0v3.75M3.75 21.75h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H3.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z"/></svg></div>
          <span class="text-xs text-zinc-600 leading-relaxed font-medium">Duplicate scans are throttled automatically to avoid accidental re-reads.</span>
        </li>
      </ul>
    </div>
  </div>
</div>

<script id="qrlib" src="https://cdn.jsdelivr.net/npm/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>
<script>
  function initScanner() {
    const statusEl = document.getElementById('status');
    const detailsEl = document.getElementById('details');
    const cameraModeBadgeEl = document.getElementById('cameraModeBadge');
    const contextStatusChipEl = document.getElementById('scanContextStatusChip');
    const contextEventEl = document.getElementById('scanContextEvent');
    const contextSessionEl = document.getElementById('scanContextSession');
    const contextWindowEl = document.getElementById('scanContextWindow');
    const contextMessageEl = document.getElementById('scanContextMessage');
    const refreshContextBtn = document.getElementById('refreshContextBtn');

    let qr = null;
    let scannerRunning = false;
    let scannerStarting = false;
    let contextLoading = false;
    let pollTimer = null;
    let latestContext = null;
    let lastToken = null;
    let lastAt = 0;

    function statusDot(colorHex) {
      return `
        <span class="relative flex h-3 w-3 flex-shrink-0">
          <span class="animate-ping absolute inline-flex h-full w-full rounded-full opacity-75" style="background:${colorHex}"></span>
          <span class="relative inline-flex rounded-full h-3 w-3" style="background:${colorHex}"></span>
        </span>
      `;
    }

    function updateStatus(message, tone = 'neutral') {
      const tones = {
        neutral: '#6b7280',
        info: '#0284c7',
        success: '#059669',
        warn: '#d97706',
        danger: '#dc2626',
      };
      const color = tones[tone] || tones.neutral;
      statusEl.innerHTML = `${statusDot(color)} ${message}`;
    }

    function setCameraMode(on) {
      if (on) {
        cameraModeBadgeEl.className = 'inline-flex items-center gap-2 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-2 text-xs font-bold uppercase tracking-widest text-emerald-800';
        cameraModeBadgeEl.innerHTML = '<span class="inline-flex h-2 w-2 rounded-full bg-emerald-500"></span>Scanner On';
        return;
      }
      cameraModeBadgeEl.className = 'inline-flex items-center gap-2 rounded-xl border border-zinc-200 bg-zinc-50 px-4 py-2 text-xs font-bold uppercase tracking-widest text-zinc-700';
      cameraModeBadgeEl.innerHTML = '<span class="inline-flex h-2 w-2 rounded-full bg-zinc-500"></span>Scanner Off';
    }

    function formatLocalDateTime(isoString) {
      if (!isoString) return '-';
      const dt = new Date(isoString);
      if (Number.isNaN(dt.getTime())) return '-';
      return dt.toLocaleString([], {
        month: 'short',
        day: 'numeric',
        year: 'numeric',
        hour: 'numeric',
        minute: '2-digit',
      });
    }

    function renderContext(data) {
      const status = (data && data.status) ? String(data.status) : 'closed';
      const context = data && data.context ? data.context : null;
      const eventInfo = context && context.event ? context.event : null;
      const sessionInfo = context && context.session ? context.session : null;
      const opensAt = context ? context.opens_at : null;
      const closesAt = context ? context.closes_at : null;
      const message = (data && data.message) ? String(data.message) : '';

      let chipText = 'Closed';
      let chipClass = 'inline-flex items-center rounded-full border px-2.5 py-1 text-[11px] font-bold border-zinc-300 bg-white text-zinc-700';
      if (status === 'open') {
        chipText = 'Scanning Open';
        chipClass = 'inline-flex items-center rounded-full border px-2.5 py-1 text-[11px] font-bold border-emerald-200 bg-emerald-50 text-emerald-800';
      } else if (status === 'waiting') {
        chipText = 'Waiting';
        chipClass = 'inline-flex items-center rounded-full border px-2.5 py-1 text-[11px] font-bold border-amber-200 bg-amber-50 text-amber-900';
      } else if (status === 'closed') {
        chipText = 'Closed';
        chipClass = 'inline-flex items-center rounded-full border px-2.5 py-1 text-[11px] font-bold border-zinc-300 bg-zinc-100 text-zinc-700';
      } else if (status === 'no_assignment') {
        chipText = 'No Assignment';
        chipClass = 'inline-flex items-center rounded-full border px-2.5 py-1 text-[11px] font-bold border-red-200 bg-red-50 text-red-700';
      } else if (status === 'conflict') {
        chipText = 'Conflict';
        chipClass = 'inline-flex items-center rounded-full border px-2.5 py-1 text-[11px] font-bold border-orange-200 bg-orange-50 text-orange-700';
      } else if (status === 'missing_schedule') {
        chipText = 'No Schedule';
        chipClass = 'inline-flex items-center rounded-full border px-2.5 py-1 text-[11px] font-bold border-red-200 bg-red-50 text-red-700';
      }

      contextStatusChipEl.className = chipClass;
      contextStatusChipEl.textContent = chipText;
      contextEventEl.textContent = eventInfo && eventInfo.title ? String(eventInfo.title) : 'No active event';
      contextSessionEl.textContent = sessionInfo && (sessionInfo.display_name || sessionInfo.title)
        ? String(sessionInfo.display_name || sessionInfo.title)
        : '-';
      contextWindowEl.textContent = opensAt || closesAt
        ? `${formatLocalDateTime(opensAt)} - ${formatLocalDateTime(closesAt)}`
        : '-';
      contextMessageEl.textContent = message || 'Scanner context will appear here.';
    }

    async function ensureScannerStopped(reason = '') {
      if (qr && scannerRunning) {
        try {
          await qr.stop();
        } catch (e) {
          // ignore stop errors
        }
      }
      scannerRunning = false;
      if (reason) {
        updateStatus(reason, 'warn');
      }
      setCameraMode(false);
    }

    async function handleToken(token) {
      token = (token || '').trim();
      const now = Date.now();
      if (!token) return;
      if (lastToken === token && (now - lastAt) < 2000) return;
      lastToken = token;
      lastAt = now;

      if (!latestContext || !latestContext.scanner_enabled) {
        updateStatus('Scanner is currently closed.', 'warn');
        return;
      }

      updateStatus('Processing scan...', 'warn');
      detailsEl.textContent = 'Token: ' + token.substring(0, 8) + '...';
      detailsEl.className = 'mt-4 pt-4 border-t border-zinc-200 text-xs text-zinc-600 break-all font-mono leading-relaxed';

      try {
        const res = await fetch('/api/scan_ticket.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ token, csrf_token: window.CSRF_TOKEN }),
        });
        const data = await res.json();
        if (!data.ok) throw new Error(data.error || 'Failed to record attendance');

        updateStatus(data.message || 'Attendance recorded', 'success');
        detailsEl.textContent = 'Success: ' + (data.message || 'Attendance recorded');
        detailsEl.className = 'mt-4 pt-4 border-t border-emerald-200 text-xs text-emerald-800 break-all font-mono leading-relaxed';
      } catch (e) {
        updateStatus((e && e.message) ? e.message : 'Scan failed', 'danger');
        detailsEl.textContent = 'Error: ' + ((e && e.message) ? e.message : 'Scan failed');
        detailsEl.className = 'mt-4 pt-4 border-t border-red-200 text-xs text-red-700 break-all font-mono leading-relaxed';
      }

      setTimeout(() => {
        if (latestContext && latestContext.scanner_enabled) {
          updateStatus('Scanning is open. Point at a QR e-ticket.', 'info');
        }
      }, 1800);
    }

    async function ensureScannerStarted() {
      if (scannerRunning || scannerStarting) return;
      if (!window.Html5Qrcode) {
        updateStatus('Scanner library failed to load.', 'danger');
        return;
      }

      scannerStarting = true;
      updateStatus('Starting camera...', 'warn');
      try {
        qr = qr || new Html5Qrcode('reader');
        await qr.start(
          { facingMode: 'environment' },
          { fps: 10, qrbox: { width: 280, height: 280 }, aspectRatio: 1.0 },
          (decodedText) => handleToken(decodedText),
          () => {
            // Ignore routine frame decode errors.
          }
        );
        scannerRunning = true;
        setCameraMode(true);
        updateStatus('Scanning is open. Point at a QR e-ticket.', 'info');
      } catch (err) {
        const msg = (err && err.message) ? err.message : String(err);
        updateStatus('Camera error: ' + msg, 'danger');
        setCameraMode(false);
      } finally {
        scannerStarting = false;
      }
    }

    async function refreshContext() {
      if (contextLoading) return;
      contextLoading = true;

      try {
        const res = await fetch('/api/scan_context.php', {
          method: 'GET',
          headers: { 'Accept': 'application/json' },
          cache: 'no-store',
        });
        const data = await res.json();
        if (!data.ok) {
          throw new Error(data.error || 'Failed to load scanner context');
        }

        latestContext = data;
        renderContext(data);

        if (data.scanner_enabled) {
          await ensureScannerStarted();
        } else {
          await ensureScannerStopped(data.message || 'Scanner is closed right now.');
        }
      } catch (err) {
        const msg = (err && err.message) ? err.message : 'Unable to resolve scanner context';
        latestContext = null;
        renderContext({
          status: 'closed',
          context: null,
          message: msg,
          scanner_enabled: false,
        });
        await ensureScannerStopped('Scanner unavailable.');
        updateStatus(msg, 'danger');
      } finally {
        contextLoading = false;
      }
    }

    refreshContextBtn?.addEventListener('click', () => {
      refreshContext();
    });

    refreshContext();
    pollTimer = setInterval(refreshContext, 15000);

    window.addEventListener('beforeunload', () => {
      if (pollTimer) clearInterval(pollTimer);
      ensureScannerStopped();
    });
  }

  const qrLibScript = document.getElementById('qrlib');
  if (window.Html5Qrcode) {
    initScanner();
  } else {
    qrLibScript.addEventListener('load', initScanner);
    qrLibScript.addEventListener('error', function() {
      const statusEl = document.getElementById('status');
      if (statusEl) {
        statusEl.innerHTML = '<span class="w-2 h-2 rounded-full bg-red-500"></span> Failed to load scanner library.';
      }
    });
  }
</script>

<style>
  #reader { background: transparent !important; }
  #reader__dashboard_section_csr span { color: #71717a !important; }
  #reader__dashboard_section_swaplink {
    color: #6d28d9 !important;
    text-decoration: none !important;
    margin-top: 10px;
    display: inline-block;
  }
  #reader button {
    background: #f5f3ff !important;
    border: 1px solid #ddd6fe !important;
    color: #5b21b6 !important;
    border-radius: 8px !important;
    padding: 6px 16px !important;
    font-size: 14px !important;
    margin-top: 8px !important;
    cursor: pointer !important;
    transition: all 0.2s !important;
  }
  #reader button:hover { background: #ede9fe !important; }
  #reader__camera_selection {
    background: #fafafa !important;
    border: 1px solid #e4e4e7 !important;
    color: #18181b !important;
    border-radius: 6px !important;
    padding: 6px !important;
    margin-bottom: 10px !important;
    outline: none !important;
  }
</style>

<?php render_footer(); ?>

