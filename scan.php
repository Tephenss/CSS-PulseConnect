<?php
declare(strict_types=1);

session_start();

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';

$user = require_role(['admin']);

render_header('QR Scanner', $user);
?>

<div class="mb-8 flex items-center justify-between">
  <div>
    <h2 class="text-xl font-bold text-zinc-900 mb-1">Check-in Scanner</h2>
    <p class="text-zinc-600 text-sm">Scan student QR e-tickets to instantly record attendance.</p>
  </div>
</div>

<div class="grid grid-cols-1 xl:grid-cols-3 gap-6">
  <!-- Left Column: Camera Feed -->
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
            <span class="text-xs text-zinc-500 block">Align QR code within the frame</span>
          </div>
        </div>
        
        <div class="flex items-center p-1 bg-zinc-100 rounded-xl border border-zinc-200 relative z-10">
          <button id="btnIn" class="rounded-lg bg-white border border-emerald-200 text-emerald-800 px-5 py-2 text-sm font-semibold transition-all shadow-sm flex-1 min-w-[110px]">Check-in</button>
          <button id="btnOut" class="rounded-lg border border-transparent px-5 py-2 text-sm font-semibold text-zinc-500 hover:text-zinc-800 transition-all flex-1 min-w-[110px]">Check-out</button>
        </div>
      </div>

      <div class="p-4 sm:p-5 relative z-10 w-full flex justify-center">
        <div class="relative rounded-xl overflow-hidden shadow-inner flex items-center justify-center bg-zinc-900 border border-zinc-300 w-full max-w-2xl mx-auto aspect-square sm:aspect-video min-h-[300px]">
           <div id="reader" class="w-full relative [&_video]:w-full [&_video]:h-full [&_video]:object-cover overflow-hidden rounded-lg"></div>
        </div>
      </div>
    </div>
  </div>

  <!-- Right Column: Status & Tips -->
  <div class="space-y-6">
    <!-- Result Card -->
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
          Initializing...
        </div>
        <div id="details" class="mt-4 pt-4 border-t border-zinc-200 text-xs text-zinc-600 break-all font-mono leading-relaxed hidden"></div>
      </div>
    </div>

    <!-- Tips Card -->
    <div class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm">
      <h3 class="text-xs font-bold text-zinc-600 uppercase tracking-widest mb-4 flex items-center gap-2">
        <svg class="w-4 h-4 text-zinc-500" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M11.25 11.25l.041-.02a.75.75 0 011.063.852l-.708 2.836a.75.75 0 001.063.853l.041-.021M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-9-3.75h.008v.008H12V8.25z"/></svg>
        Pro Tips
      </h3>
      <ul class="space-y-4">
        <li class="flex items-start gap-3">
          <div class="w-6 h-6 rounded-full bg-emerald-100 border border-emerald-200 text-emerald-800 flex items-center justify-center flex-shrink-0 mt-0.5"><svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/></svg></div>
          <span class="text-xs text-zinc-600 leading-relaxed font-medium">Ensure the QR code is well-lit and fits entirely within the camera feed.</span>
        </li>
        <li class="flex items-start gap-3">
          <div class="w-6 h-6 rounded-full bg-amber-100 border border-amber-200 text-amber-900 flex items-center justify-center flex-shrink-0 mt-0.5"><svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M7.5 21L3 16.5m0 0L7.5 12M3 16.5h13.5m0-13.5L21 7.5m0 0L16.5 12M21 7.5H7.5"/></svg></div>
          <span class="text-xs text-zinc-600 leading-relaxed font-medium">Switch modes to track departures accurately using the Check-out toggle.</span>
        </li>
        <li class="flex items-start gap-3">
          <div class="w-6 h-6 rounded-full bg-sky-100 border border-sky-200 text-sky-800 flex items-center justify-center flex-shrink-0 mt-0.5"><svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 10.5V6.75a4.5 4.5 0 119 0v3.75M3.75 21.75h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H3.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z"/></svg></div>
          <span class="text-xs text-zinc-600 leading-relaxed font-medium">Auto-processing occurs securely upon a successful scan. Pauses 2 seconds between reads to prevent duplicates.</span>
        </li>
      </ul>
    </div>
  </div>
</div>

<script id="qrlib" src="https://cdn.jsdelivr.net/npm/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>
<script>
  function initScanner() {
    let action = 'check_in';
    const statusEl = document.getElementById('status');
    const detailsEl = document.getElementById('details');
    const btnIn = document.getElementById('btnIn');
    const btnOut = document.getElementById('btnOut');

    // Helper for styled status text
    function updateStatus(msg, colorTailwindStr) {
       statusEl.innerHTML = `
          <span class="relative flex h-3 w-3 flex-shrink-0">
             <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-${colorTailwindStr} opacity-75"></span>
             <span class="relative inline-flex rounded-full h-3 w-3 bg-${colorTailwindStr}"></span>
          </span>
          ${msg}
       `;
    }

    function setAction(a) {
      action = a;
      if (a === 'check_in') {
        btnIn.className = 'rounded-lg bg-white border border-emerald-200 text-emerald-800 px-5 py-2 text-sm font-semibold transition-all shadow-sm flex-1 min-w-[110px]';
        btnOut.className = 'rounded-lg border border-transparent px-5 py-2 text-sm font-semibold text-zinc-500 hover:text-zinc-800 hover:bg-white/80 transition-all flex-1 min-w-[110px]';
      } else {
        btnOut.className = 'rounded-lg bg-white border border-amber-200 text-amber-900 px-5 py-2 text-sm font-semibold transition-all shadow-sm flex-1 min-w-[110px]';
        btnIn.className = 'rounded-lg border border-transparent px-5 py-2 text-sm font-semibold text-zinc-500 hover:text-zinc-800 hover:bg-white/80 transition-all flex-1 min-w-[110px]';
      }
    }

    btnIn.addEventListener('click', () => setAction('check_in'));
    btnOut.addEventListener('click', () => setAction('check_out'));

    if (!window.Html5Qrcode) {
      updateStatus('Scanner library failed to load.', 'red-500');
      return;
    }

    // Use Html5Qrcode directly for more control over UI container
    const qr = new Html5Qrcode("reader");
    let lastToken = null;
    let lastAt = 0;

    async function handleToken(token) {
      token = (token || '').trim();
      const now = Date.now();
      if (!token) return;
      if (lastToken === token && (now - lastAt) < 2000) return;
      lastToken = token; lastAt = now;

      updateStatus('Processing...', 'amber-500');
      detailsEl.textContent = "Token: " + token.substring(0, 8) + "...";
      detailsEl.classList.remove('hidden');

      try {
        const res = await fetch('/api/scan_ticket.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ token, action, csrf_token: window.CSRF_TOKEN })
        });
        const data = await res.json();
        if (!data.ok) throw new Error(data.error || 'Failed');
        updateStatus(data.message || 'Verification Successful', 'emerald-500');
        
        // Show success detail
        detailsEl.textContent = `Success: ${data.message}`;
        detailsEl.className = "mt-4 pt-4 border-t border-emerald-200 text-xs text-emerald-800 break-all font-mono leading-relaxed";
      } catch (e) {
        updateStatus(e.message || 'Verification Failed', 'red-500');
        detailsEl.textContent = `Error: ${e.message}`;
        detailsEl.className = "mt-4 pt-4 border-t border-red-200 text-xs text-red-700 break-all font-mono leading-relaxed";
      }
      
      // Reset scan message after 3 seconds
      setTimeout(() => {
        updateStatus('Ready. Point at a QR e-ticket.', 'sky-500');
        detailsEl.classList.add('hidden');
      }, 3000);
    }

    async function startScanner() {
      updateStatus('Starting camera...', 'amber-500');

      if (!window.isSecureContext) {
        updateStatus('Camera requires HTTPS. Localhost is OK.', 'red-500');
      }

      try {
        if (Html5Qrcode.getCameras) {
           // We do not need output from this, it just requests permissions if needed
          const cams = await Html5Qrcode.getCameras();
          if (!cams || cams.length === 0) {
            updateStatus('No camera devices found.', 'red-500');
            return;
          }
        }
        
        // Ensure UI styling overrides for html5-qrcode
        document.getElementById('reader').style.border = 'none';
        
        await qr.start(
          { facingMode: "environment" },
          { fps: 10, qrbox: { width: 280, height: 280 }, aspectRatio: 1.0 },
          (decodedText) => handleToken(decodedText),
          (errorMessage) => {
            // Ignore routine scanning errors so UI doesn't flicker
          }
        );
        updateStatus('Ready. Point at a QR e-ticket.', 'sky-500');
      } catch (err) {
        updateStatus('Camera error: ' + (err && err.message ? err.message : String(err)), 'red-500');
      }
    }

    startScanner();
  }

  const qrLibScript = document.getElementById('qrlib');
  if (window.Html5Qrcode) {
    initScanner();
  } else {
    qrLibScript.addEventListener('load', initScanner);
    qrLibScript.addEventListener('error', function() {
      document.getElementById('status').innerHTML = '<span class="w-2 h-2 rounded-full bg-red-500"></span> Failed to load scanner library.';
    });
  }
</script>

<!-- Add some scoped CSS to override Html5Qrcode's injected styles to look better -->
<style>
  #reader { background: transparent !important; }
  #reader__dashboard_section_csr span { color: #71717a !important; }
  #reader__dashboard_section_swaplink { color: #6d28d9 !important; text-decoration: none !important; margin-top: 10px; display: inline-block; }
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
