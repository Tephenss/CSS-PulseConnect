<?php
declare(strict_types=1);

session_start();

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';

$user = require_role(['teacher', 'admin']);

render_header('QR Scanner', $user);
?>

<div class="mb-6">
  <p class="text-zinc-400 text-sm">Check-in and check-out using the student's QR e-ticket.</p>
</div>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-5">
  <div class="lg:col-span-2 rounded-2xl glass-card p-5">
    <div class="flex items-center justify-between gap-3 mb-4">
      <div class="flex items-center gap-2.5">
        <div class="w-8 h-8 rounded-lg bg-gradient-to-br from-violet-600/20 to-fuchsia-600/20 border border-violet-500/20 flex items-center justify-center">
          <svg class="w-4 h-4 text-violet-400" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6.827 6.175A2.31 2.31 0 015.186 7.23c-.38.054-.757.112-1.134.175C2.999 7.58 2.25 8.507 2.25 9.574V18a2.25 2.25 0 002.25 2.25h15A2.25 2.25 0 0021.75 18V9.574c0-1.067-.75-1.994-1.802-2.169a47.865 47.865 0 00-1.134-.175 2.31 2.31 0 01-1.64-1.055l-.822-1.316a2.192 2.192 0 00-1.736-1.039 48.774 48.774 0 00-5.232 0 2.192 2.192 0 00-1.736 1.039l-.821 1.316z"/><path stroke-linecap="round" stroke-linejoin="round" d="M16.5 12.75a4.5 4.5 0 11-9 0 4.5 4.5 0 019 0z"/></svg>
        </div>
        <span class="text-sm font-medium text-zinc-200">Camera Feed</span>
      </div>
      <div class="flex gap-2">
        <button id="btnIn" class="rounded-lg bg-gradient-to-r from-emerald-600 to-emerald-500 text-white px-4 py-2 text-sm font-medium hover:from-emerald-500 hover:to-emerald-400 transition-all shadow-lg shadow-emerald-600/20">Check-in</button>
        <button id="btnOut" class="rounded-lg border border-zinc-700 bg-zinc-800/60 px-4 py-2 text-sm text-zinc-300 hover:bg-zinc-700/60 transition">Check-out</button>
      </div>
    </div>

    <div class="rounded-xl overflow-hidden border border-zinc-800/60 bg-zinc-950/60">
      <div id="reader" class="p-1"></div>
    </div>
  </div>

  <div class="space-y-4">
    <div class="rounded-2xl glass-card p-5">
      <div class="flex items-center gap-2.5 mb-4">
        <div class="w-8 h-8 rounded-lg bg-gradient-to-br from-sky-600/20 to-cyan-600/20 border border-sky-500/20 flex items-center justify-center">
          <svg class="w-4 h-4 text-sky-400" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
        </div>
        <span class="text-sm font-medium text-zinc-200">Scan Result</span>
      </div>
      <div id="status" class="text-sm text-zinc-300 flex items-center gap-2">
        <span class="w-2 h-2 rounded-full bg-zinc-600 animate-pulse"></span>
        Ready.
      </div>
      <div id="details" class="mt-3 text-xs text-zinc-500 break-all font-mono"></div>
    </div>

    <div class="rounded-2xl glass-card p-5">
      <div class="text-xs text-zinc-500 mb-2 font-medium uppercase tracking-wider">Quick Tips</div>
      <ul class="space-y-2 text-xs text-zinc-400">
        <li class="flex items-start gap-2">
          <svg class="w-3.5 h-3.5 text-emerald-500 mt-0.5 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M16.704 4.153a.75.75 0 01.143 1.052l-8 10.5a.75.75 0 01-1.127.075l-4.5-4.5a.75.75 0 011.06-1.06l3.894 3.893 7.48-9.817a.75.75 0 011.05-.143z" clip-rule="evenodd"/></svg>
          Point camera at the student's QR e-ticket
        </li>
        <li class="flex items-start gap-2">
          <svg class="w-3.5 h-3.5 text-emerald-500 mt-0.5 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M16.704 4.153a.75.75 0 01.143 1.052l-8 10.5a.75.75 0 01-1.127.075l-4.5-4.5a.75.75 0 011.06-1.06l3.894 3.893 7.48-9.817a.75.75 0 011.05-.143z" clip-rule="evenodd"/></svg>
          Toggle between Check-in and Check-out
        </li>
        <li class="flex items-start gap-2">
          <svg class="w-3.5 h-3.5 text-emerald-500 mt-0.5 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M16.704 4.153a.75.75 0 01.143 1.052l-8 10.5a.75.75 0 01-1.127.075l-4.5-4.5a.75.75 0 011.06-1.06l3.894 3.893 7.48-9.817a.75.75 0 011.05-.143z" clip-rule="evenodd"/></svg>
          Auto-processing on successful scan
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

    function setAction(a) {
      action = a;
      if (a === 'check_in') {
        btnIn.className = 'rounded-lg bg-gradient-to-r from-emerald-600 to-emerald-500 text-white px-4 py-2 text-sm font-medium hover:from-emerald-500 hover:to-emerald-400 transition-all shadow-lg shadow-emerald-600/20';
        btnOut.className = 'rounded-lg border border-zinc-700 bg-zinc-800/60 px-4 py-2 text-sm text-zinc-300 hover:bg-zinc-700/60 transition';
      } else {
        btnOut.className = 'rounded-lg bg-gradient-to-r from-orange-600 to-amber-500 text-white px-4 py-2 text-sm font-medium hover:from-orange-500 hover:to-amber-400 transition-all shadow-lg shadow-orange-600/20';
        btnIn.className = 'rounded-lg border border-zinc-700 bg-zinc-800/60 px-4 py-2 text-sm text-zinc-300 hover:bg-zinc-700/60 transition';
      }
    }

    btnIn.addEventListener('click', () => setAction('check_in'));
    btnOut.addEventListener('click', () => setAction('check_out'));

    if (!window.Html5Qrcode) {
      statusEl.innerHTML = '<span class="w-2 h-2 rounded-full bg-red-500"></span> Scanner library failed to load.';
      return;
    }

    const qr = new Html5Qrcode("reader");
    let lastToken = null;
    let lastAt = 0;

    async function handleToken(token) {
      token = (token || '').trim();
      const now = Date.now();
      if (!token) return;
      if (lastToken === token && (now - lastAt) < 2000) return;
      lastToken = token; lastAt = now;

      statusEl.innerHTML = '<span class="w-2 h-2 rounded-full bg-amber-500 animate-pulse"></span> Processing...';
      detailsEl.textContent = token;

      try {
        const res = await fetch('/api/scan_ticket.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ token, action, csrf_token: window.CSRF_TOKEN })
        });
        const data = await res.json();
        if (!data.ok) throw new Error(data.error || 'Failed');
        statusEl.innerHTML = '<span class="w-2 h-2 rounded-full bg-emerald-500"></span> ' + (data.message || 'OK');
      } catch (e) {
        statusEl.innerHTML = '<span class="w-2 h-2 rounded-full bg-red-500"></span> ' + (e.message || 'Failed');
      }
    }

    async function startScanner() {
      statusEl.innerHTML = '<span class="w-2 h-2 rounded-full bg-amber-500 animate-pulse"></span> Starting camera...';

      if (!window.isSecureContext) {
        statusEl.innerHTML = '<span class="w-2 h-2 rounded-full bg-red-500"></span> Camera requires HTTPS. Localhost is OK.';
      }

      try {
        if (Html5Qrcode.getCameras) {
          const cams = await Html5Qrcode.getCameras();
          if (!cams || cams.length === 0) {
            statusEl.innerHTML = '<span class="w-2 h-2 rounded-full bg-red-500"></span> No camera devices found.';
            return;
          }
        }
        await qr.start(
          { facingMode: "environment" },
          { fps: 10, qrbox: { width: 260, height: 260 }, aspectRatio: 1.0 },
          (decodedText) => handleToken(decodedText),
          (errorMessage) => {
            if ((detailsEl.textContent || '').trim() === '') {
              statusEl.innerHTML = '<span class="w-2 h-2 rounded-full bg-sky-500 animate-pulse"></span> Scanning... Point at a QR e-ticket.';
            }
          }
        );
        statusEl.innerHTML = '<span class="w-2 h-2 rounded-full bg-emerald-500 animate-pulse"></span> Ready. Point at a QR e-ticket.';
      } catch (err) {
        statusEl.innerHTML = '<span class="w-2 h-2 rounded-full bg-red-500"></span> Camera error: ' + (err && err.message ? err.message : String(err));
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

<?php render_footer(); ?>
