<?php
declare(strict_types=1);

session_start();

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/supabase.php';
require_once __DIR__ . '/includes/layout.php';

$user = require_role(['admin']);

$headers = [
    'Accept: application/json',
    'apikey: ' . SUPABASE_KEY,
    'Authorization: Bearer ' . SUPABASE_KEY,
];

$eventsUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/events?select=id,title,status,start_at&order=created_at.desc&limit=200';
$eventsRes = supabase_request('GET', $eventsUrl, $headers);
$eventsRows = $eventsRes['ok'] ? json_decode((string) $eventsRes['body'], true) : null;
$events = is_array($eventsRows) ? $eventsRows : [];

render_header('Cert Templates', $user);
?>

<div class="mb-6">
  <p class="text-zinc-400 text-sm">Save certificate templates per event, then generate certificates for eligible participants.</p>
</div>

<div class="grid grid-cols-1 lg:grid-cols-2 gap-5">
  <!-- Template Form -->
  <div class="rounded-2xl glass-card p-5">
    <div class="flex items-center gap-2.5 mb-4">
      <div class="w-8 h-8 rounded-lg bg-gradient-to-br from-violet-600/20 to-fuchsia-600/20 border border-violet-500/20 flex items-center justify-center">
        <svg class="w-4 h-4 text-violet-400" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z"/></svg>
      </div>
      <span class="text-sm font-medium text-zinc-200">Template Editor</span>
    </div>

    <div class="mb-3">
      <label class="block text-xs text-zinc-400 mb-1.5 font-medium">Event</label>
      <select id="eventId" class="w-full rounded-lg bg-zinc-950/60 border border-zinc-800/60 px-3 py-2.5 text-sm outline-none focus:ring-2 focus:ring-violet-500/30 transition">
        <option value="">Choose an event</option>
        <?php foreach ($events as $e): ?>
          <option value="<?= htmlspecialchars((string) ($e['id'] ?? '')) ?>">
            <?= htmlspecialchars((string) ($e['title'] ?? '')) ?> (<?= htmlspecialchars((string) ($e['status'] ?? '')) ?>)
          </option>
        <?php endforeach; ?>
      </select>
    </div>

    <form id="tplForm" class="space-y-3">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string) csrf_ensure_token()) ?>" />
      <div>
        <label class="block text-xs text-zinc-400 mb-1.5 font-medium">Title</label>
        <input name="title" value="Certificate of Participation" class="w-full rounded-lg bg-zinc-950/60 border border-zinc-800/60 px-3 py-2.5 text-sm outline-none focus:ring-2 focus:ring-violet-500/30 transition" />
      </div>
      <div>
        <label class="block text-xs text-zinc-400 mb-1.5 font-medium">Body <span class="text-zinc-600">(use &#123;&#123;name&#125;&#125; and &#123;&#123;event&#125;&#125;)</span></label>
        <textarea name="body_text" rows="4" class="w-full rounded-lg bg-zinc-950/60 border border-zinc-800/60 px-3 py-2.5 text-sm outline-none focus:ring-2 focus:ring-violet-500/30 transition resize-none">This certifies that {{name}} participated in {{event}}.</textarea>
      </div>
      <div>
        <label class="block text-xs text-zinc-400 mb-1.5 font-medium">Footer <span class="text-zinc-600">(optional)</span></label>
        <input name="footer_text" class="w-full rounded-lg bg-zinc-950/60 border border-zinc-800/60 px-3 py-2.5 text-sm outline-none focus:ring-2 focus:ring-violet-500/30 transition" placeholder="e.g., Authorized by CSS PulseConnect" />
      </div>
      <button class="w-full rounded-lg bg-gradient-to-r from-violet-600 to-fuchsia-600 text-white px-4 py-2.5 text-sm font-medium hover:from-violet-500 hover:to-fuchsia-500 transition-all shadow-lg shadow-violet-600/20" type="submit">
        Save Template
      </button>
      <div id="tplMsg" class="text-sm text-zinc-300"></div>
    </form>
  </div>

  <!-- Generate -->
  <div class="rounded-2xl glass-card p-5">
    <div class="flex items-center gap-2.5 mb-4">
      <div class="w-8 h-8 rounded-lg bg-gradient-to-br from-emerald-600/20 to-teal-600/20 border border-emerald-500/20 flex items-center justify-center">
        <svg class="w-4 h-4 text-emerald-400" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12c0 1.268-.63 2.39-1.593 3.068a3.745 3.745 0 01-1.043 3.296 3.745 3.745 0 01-3.296 1.043A3.745 3.745 0 0112 21c-1.268 0-2.39-.63-3.068-1.593a3.746 3.746 0 01-3.296-1.043 3.745 3.745 0 01-1.043-3.296A3.745 3.745 0 013 12c0-1.268.63-2.39 1.593-3.068a3.745 3.745 0 011.043-3.296 3.746 3.746 0 013.296-1.043A3.746 3.746 0 0112 3c1.268 0 2.39.63 3.068 1.593a3.746 3.746 0 013.296 1.043 3.746 3.746 0 011.043 3.296A3.745 3.745 0 0121 12z"/></svg>
      </div>
      <span class="text-sm font-medium text-zinc-200">Generate Certificates</span>
    </div>

    <div class="rounded-xl bg-zinc-900/30 border border-zinc-800/40 p-4 mb-4">
      <div class="flex items-start gap-3">
        <svg class="w-5 h-5 text-sky-400 mt-0.5 flex-shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M11.25 11.25l.041-.02a.75.75 0 011.063.852l-.708 2.836a.75.75 0 001.063.853l.041-.021M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-9-3.75h.008v.008H12V8.25z"/></svg>
        <div class="text-xs text-zinc-400 leading-relaxed">
          <strong class="text-zinc-300">Eligibility:</strong> Only participants who have both checked-in and checked-out will receive a certificate. Make sure to save a template for the selected event first.
        </div>
      </div>
    </div>

    <button id="btnGen" class="w-full rounded-lg bg-gradient-to-r from-emerald-600 to-emerald-500 text-white px-4 py-2.5 text-sm font-medium hover:from-emerald-500 hover:to-emerald-400 transition-all shadow-lg shadow-emerald-600/20">
      Generate Certificates
    </button>
    <div id="genMsg" class="mt-3 text-sm text-zinc-300"></div>
  </div>
</div>

<script>
  const eventSel = document.getElementById('eventId');
  const tplForm = document.getElementById('tplForm');
  const tplMsg = document.getElementById('tplMsg');
  const btnGen = document.getElementById('btnGen');
  const genMsg = document.getElementById('genMsg');

  tplForm.addEventListener('submit', async (e) => {
    e.preventDefault();
    const event_id = eventSel.value;
    if (!event_id) { tplMsg.textContent = 'Select an event first.'; return; }
    tplMsg.textContent = 'Saving...';
    const fd = new FormData(tplForm);
    const payload = Object.fromEntries(fd.entries());
    payload.event_id = event_id;
    payload.csrf_token = window.CSRF_TOKEN;
    try {
      const res = await fetch('/api/certificate_template_save.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
      });
      const data = await res.json();
      if (!data.ok) throw new Error(data.error || 'Failed');
      tplMsg.textContent = 'Saved.';
    } catch (err) {
      tplMsg.textContent = err.message || 'Failed';
    }
  });

  btnGen.addEventListener('click', async () => {
    const event_id = eventSel.value;
    if (!event_id) { genMsg.textContent = 'Select an event first.'; return; }
    genMsg.textContent = 'Generating...';
    try {
      const res = await fetch('/api/certificates_generate.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ event_id, csrf_token: window.CSRF_TOKEN })
      });
      const data = await res.json();
      if (!data.ok) throw new Error(data.error || 'Failed');
      genMsg.textContent = 'Generated: ' + (data.count ?? 'OK');
    } catch (err) {
      genMsg.textContent = err.message || 'Failed';
    }
  });
</script>

<?php render_footer(); ?>
