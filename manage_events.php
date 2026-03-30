<?php
declare(strict_types=1);

session_start();

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/supabase.php';
require_once __DIR__ . '/includes/layout.php';

$user = require_role(['teacher', 'admin']);
$role = (string) ($user['role'] ?? 'teacher');
$userId = (string) ($user['id'] ?? '');

$select = 'select=id,title,description,location,start_at,end_at,status,created_by,approved_by,created_at,updated_at';
$url = rtrim(SUPABASE_URL, '/') . '/rest/v1/events?' . $select . '&order=created_at.desc';
if ($role === 'teacher') {
    $url .= '&created_by=eq.' . rawurlencode($userId);
}

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

render_header('Manage Events', $user);
?>

<div class="mb-6">
  <p class="text-zinc-400 text-sm">
    <?php if ($role === 'admin'): ?>Full control — create, edit, approve and publish events.<?php else: ?>Create events (pending). Admin approves & publishes.<?php endif; ?>
  </p>
</div>

<div class="grid grid-cols-1 xl:grid-cols-3 gap-5 items-start">
  <!-- Event Wizard -->
  <div class="xl:col-span-1 rounded-2xl glass-card p-5">
    <div class="flex items-center gap-2.5 mb-4">
      <div class="w-8 h-8 rounded-lg bg-gradient-to-br from-violet-600/20 to-fuchsia-600/20 border border-violet-500/20 flex items-center justify-center">
        <svg class="w-4 h-4 text-violet-400" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/></svg>
      </div>
      <span class="text-sm font-medium text-zinc-200">Event Wizard</span>
    </div>

    <form id="eventForm" class="space-y-3">
      <input type="hidden" name="mode" id="mode" value="create" />
      <input type="hidden" name="event_id" id="event_id" value="" />

      <div class="rounded-xl border border-zinc-800/50 bg-zinc-900/20 p-3">
        <div class="text-[10px] text-zinc-500 mb-2 uppercase tracking-wider font-medium">Progress</div>
        <div class="flex gap-2 text-xs">
          <div id="step1Tag" class="px-3 py-1.5 rounded-full border border-violet-500/30 bg-violet-500/10 text-violet-300 font-medium">1 · Info</div>
          <div id="step2Tag" class="px-3 py-1.5 rounded-full border border-zinc-800 bg-zinc-900/30 text-zinc-500">2 · Time</div>
          <div id="step3Tag" class="px-3 py-1.5 rounded-full border border-zinc-800 bg-zinc-900/30 text-zinc-500">3 · Details</div>
        </div>
      </div>

      <div id="step1" class="space-y-3">
        <div>
          <label class="block text-xs text-zinc-400 mb-1.5 font-medium">Title</label>
          <input id="title" name="title" required class="w-full rounded-lg bg-zinc-950/60 border border-zinc-800/60 px-3 py-2.5 text-sm outline-none focus:ring-2 focus:ring-violet-500/30 focus:border-violet-500/30 transition" placeholder="Event name" />
        </div>
        <div>
          <label class="block text-xs text-zinc-400 mb-1.5 font-medium">Location</label>
          <input id="location" name="location" class="w-full rounded-lg bg-zinc-950/60 border border-zinc-800/60 px-3 py-2.5 text-sm outline-none focus:ring-2 focus:ring-violet-500/30 focus:border-violet-500/30 transition" placeholder="Venue" />
        </div>
      </div>

      <div id="step2" class="space-y-3 hidden">
        <div>
          <label class="block text-xs text-zinc-400 mb-1.5 font-medium">Start</label>
          <input id="start_at_local" name="start_at_local" type="datetime-local" required class="w-full rounded-lg bg-zinc-950/60 border border-zinc-800/60 px-3 py-2.5 text-sm outline-none focus:ring-2 focus:ring-violet-500/30 focus:border-violet-500/30 transition" />
          <div class="text-[10px] text-zinc-600 mt-1">Stored as UTC</div>
        </div>
        <div>
          <label class="block text-xs text-zinc-400 mb-1.5 font-medium">End</label>
          <input id="end_at_local" name="end_at_local" type="datetime-local" required class="w-full rounded-lg bg-zinc-950/60 border border-zinc-800/60 px-3 py-2.5 text-sm outline-none focus:ring-2 focus:ring-violet-500/30 focus:border-violet-500/30 transition" />
        </div>
      </div>

      <div id="step3" class="space-y-3 hidden">
        <div>
          <label class="block text-xs text-zinc-400 mb-1.5 font-medium">Description</label>
          <textarea id="description" name="description" rows="4" class="w-full rounded-lg bg-zinc-950/60 border border-zinc-800/60 px-3 py-2.5 text-sm outline-none focus:ring-2 focus:ring-violet-500/30 focus:border-violet-500/30 transition resize-none" placeholder="Event description..."></textarea>
        </div>
      </div>

      <div class="flex items-center gap-2 pt-1">
        <button type="button" id="btnBack" class="rounded-lg border border-zinc-700 bg-zinc-800/40 px-4 py-2 text-sm text-zinc-400 hover:bg-zinc-700/60 transition" disabled>
          Back
        </button>
        <button type="button" id="btnNext" class="rounded-lg bg-gradient-to-r from-violet-600 to-fuchsia-600 text-white px-4 py-2 text-sm font-medium hover:from-violet-500 hover:to-fuchsia-500 transition-all shadow-lg shadow-violet-600/20">
          Next
        </button>
        <button type="submit" id="btnSubmit" class="hidden rounded-lg bg-gradient-to-r from-emerald-600 to-emerald-500 text-white px-4 py-2 text-sm font-medium hover:from-emerald-500 hover:to-emerald-400 transition-all shadow-lg shadow-emerald-600/20">
          Save
        </button>
      </div>

      <div id="formMsg" class="text-sm text-zinc-300"></div>
    </form>
  </div>

  <!-- Events Table -->
  <div class="xl:col-span-2 rounded-2xl glass-card p-5">
    <div class="flex items-center justify-between gap-3 mb-4">
      <div class="flex items-center gap-2.5">
        <div class="w-8 h-8 rounded-lg bg-gradient-to-br from-sky-600/20 to-cyan-600/20 border border-sky-500/20 flex items-center justify-center">
          <svg class="w-4 h-4 text-sky-400" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 6.75h12M8.25 12h12m-12 5.25h12M3.75 6.75h.007v.008H3.75V6.75zm.375 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zM3.75 12h.007v.008H3.75V12zm.375 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm-.375 5.25h.007v.008H3.75v-.008zm.375 0a.375.375 0 11-.75 0 .375.375 0 01.75 0z"/></svg>
        </div>
        <span class="text-sm font-medium text-zinc-200">Events List</span>
      </div>
      <div class="text-xs text-zinc-500">
        <?php if ($role === 'admin'): ?>Admin controls<?php else: ?>Your events<?php endif; ?>
      </div>
    </div>

    <div class="overflow-x-auto">
      <table class="min-w-full text-sm">
        <thead>
          <tr class="text-xs text-zinc-500 uppercase tracking-wider">
            <th class="text-left py-2.5 pr-3 font-medium">Title</th>
            <th class="text-left py-2.5 pr-3 font-medium">Start</th>
            <th class="text-left py-2.5 pr-3 font-medium">Status</th>
            <th class="text-left py-2.5 pr-3 font-medium">Actions</th>
          </tr>
        </thead>
        <tbody class="text-zinc-200">
          <?php foreach ($events as $e): ?>
            <?php
              $eid = (string) ($e['id'] ?? '');
              $createdBy = (string) ($e['created_by'] ?? '');
              $canEdit = $role === 'admin' || ($role === 'teacher' && $createdBy === $userId && (string) ($e['status'] ?? '') === 'pending');
              $status = (string)($e['status'] ?? '');
              $statusColor = match($status) {
                  'published' => 'bg-emerald-500/15 text-emerald-400 border-emerald-500/20',
                  'pending' => 'bg-amber-500/15 text-amber-400 border-amber-500/20',
                  'approved' => 'bg-sky-500/15 text-sky-400 border-sky-500/20',
                  default => 'bg-zinc-500/15 text-zinc-400 border-zinc-500/20',
              };
            ?>
            <tr class="border-t border-zinc-800/50 hover:bg-zinc-800/20 transition">
              <td class="py-3 pr-3">
                <div class="font-medium"><?= htmlspecialchars((string) ($e['title'] ?? '')) ?></div>
              </td>
              <td class="py-3 pr-3 text-zinc-400 text-xs"><?= htmlspecialchars((string) ($e['start_at'] ?? '')) ?></td>
              <td class="py-3 pr-3">
                <span class="text-[10px] font-medium rounded-full border px-2 py-0.5 <?= $statusColor ?>"><?= htmlspecialchars($status) ?></span>
              </td>
              <td class="py-3 pr-3">
                <div class="flex gap-1.5 flex-wrap items-center">
                  <?php if ($role === 'admin'): ?>
                    <?php if ($status === 'pending'): ?>
                      <button class="btnApprove rounded-lg border border-zinc-700 bg-zinc-800/40 px-2.5 py-1.5 text-xs text-zinc-300 hover:bg-zinc-700/60 transition"
                              data-id="<?= htmlspecialchars($eid) ?>" data-status="approved">Approve</button>
                      <button class="btnApprove rounded-lg bg-gradient-to-r from-emerald-600 to-emerald-500 text-white px-2.5 py-1.5 text-xs font-medium hover:from-emerald-500 hover:to-emerald-400 transition-all shadow-sm"
                              data-id="<?= htmlspecialchars($eid) ?>" data-status="published">Publish</button>
                    <?php endif; ?>
                  <?php endif; ?>
                  <?php if ($canEdit): ?>
                    <button class="btnEdit rounded-lg border border-zinc-700 bg-zinc-800/40 px-2.5 py-1.5 text-xs text-zinc-300 hover:bg-zinc-700/60 transition"
                            data-id="<?= htmlspecialchars($eid) ?>"
                            data-title="<?= htmlspecialchars((string) ($e['title'] ?? '')) ?>"
                            data-location="<?= htmlspecialchars((string) ($e['location'] ?? '')) ?>"
                            data-description="<?= htmlspecialchars((string) ($e['description'] ?? '')) ?>"
                            data-start_at="<?= htmlspecialchars((string) ($e['start_at'] ?? '')) ?>"
                            data-end_at="<?= htmlspecialchars((string) ($e['end_at'] ?? '')) ?>"
                    >Edit</button>
                  <?php endif; ?>
                  <a href="/participants.php?event_id=<?= htmlspecialchars($eid) ?>" class="rounded-lg border border-zinc-700 bg-zinc-800/40 px-2.5 py-1.5 text-xs text-zinc-300 hover:bg-zinc-700/60 transition">Participants</a>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php if (count($events) === 0): ?>
            <tr><td class="py-6 text-zinc-500 text-center" colspan="4">No events yet. Use the wizard to create one.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<script>
  function toLocalInput(iso) {
    if (!iso) return '';
    const d = new Date(iso);
    const pad = (n) => String(n).padStart(2, '0');
    return d.getFullYear() + '-' + pad(d.getMonth()+1) + '-' + pad(d.getDate()) + 'T' + pad(d.getHours()) + ':' + pad(d.getMinutes());
  }

  function setWizardStep(step) {
    const step1 = document.getElementById('step1');
    const step2 = document.getElementById('step2');
    const step3 = document.getElementById('step3');
    const btnBack = document.getElementById('btnBack');
    const btnNext = document.getElementById('btnNext');
    const btnSubmit = document.getElementById('btnSubmit');
    const step1Tag = document.getElementById('step1Tag');
    const step2Tag = document.getElementById('step2Tag');
    const step3Tag = document.getElementById('step3Tag');

    step1.classList.toggle('hidden', step !== 1);
    step2.classList.toggle('hidden', step !== 2);
    step3.classList.toggle('hidden', step !== 3);

    btnBack.disabled = step === 1;
    btnNext.classList.toggle('hidden', step === 3);
    btnSubmit.classList.toggle('hidden', step !== 3);

    const activeClass = 'border-violet-500/30 bg-violet-500/10 text-violet-300 font-medium';
    const inactiveClass = 'border-zinc-800 bg-zinc-900/30 text-zinc-500';

    [step1Tag, step2Tag, step3Tag].forEach((tag, i) => {
      const isActive = (i + 1) === step;
      tag.className = 'px-3 py-1.5 rounded-full border text-xs ' + (isActive ? activeClass : inactiveClass);
    });
  }

  let step = 1;
  setWizardStep(1);

  document.getElementById('btnNext').addEventListener('click', () => {
    if (step === 1) {
      if (!document.getElementById('title').value.trim()) return;
      step = 2;
    } else if (step === 2) {
      if (!document.getElementById('start_at_local').value || !document.getElementById('end_at_local').value) return;
      step = 3;
    } else {
      step = 3;
    }
    setWizardStep(step);
  });

  document.getElementById('btnBack').addEventListener('click', () => {
    step = Math.max(1, step - 1);
    setWizardStep(step);
  });

  document.querySelectorAll('.btnEdit').forEach(btn => {
    btn.addEventListener('click', () => {
      const mode = document.getElementById('mode');
      const event_id = document.getElementById('event_id');
      mode.value = 'edit';
      event_id.value = btn.dataset.id || '';
      document.getElementById('title').value = btn.dataset.title || '';
      document.getElementById('location').value = btn.dataset.location || '';
      document.getElementById('description').value = btn.dataset.description || '';
      document.getElementById('start_at_local').value = toLocalInput(btn.dataset.start_at);
      document.getElementById('end_at_local').value = toLocalInput(btn.dataset.end_at);
      document.getElementById('formMsg').textContent = 'Editing...';
      step = 1;
      setWizardStep(1);
      window.scrollTo({ top: 0, behavior: 'smooth' });
    });
  });

  document.querySelectorAll('.btnApprove').forEach(btn => {
    btn.addEventListener('click', async () => {
      const event_id = btn.dataset.id;
      const status = btn.dataset.status;
      btn.disabled = true;
      try {
        const res = await fetch('/api/events_approve.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ event_id, status, csrf_token: window.CSRF_TOKEN })
        });
        const data = await res.json();
        if (!data.ok) throw new Error(data.error || 'Failed');
        window.location.reload();
      } catch (e) {
        alert(e.message || 'Failed');
      } finally {
        btn.disabled = false;
      }
    });
  });

  document.getElementById('eventForm').addEventListener('submit', async (e) => {
    e.preventDefault();
    const mode = document.getElementById('mode').value;
    const msg = document.getElementById('formMsg');
    const payload = {
      title: document.getElementById('title').value.trim(),
      location: document.getElementById('location').value.trim(),
      description: document.getElementById('description').value.trim(),
      start_at_local: document.getElementById('start_at_local').value,
      end_at_local: document.getElementById('end_at_local').value,
      csrf_token: window.CSRF_TOKEN
    };
    if (!payload.start_at_local || !payload.end_at_local) {
      msg.textContent = 'Start/end are required.';
      return;
    }
    const startDate = new Date(payload.start_at_local);
    const endDate = new Date(payload.end_at_local);
    if (Number.isNaN(startDate.getTime()) || Number.isNaN(endDate.getTime())) {
      msg.textContent = 'Invalid datetime.';
      return;
    }
    if (endDate <= startDate) {
      msg.textContent = 'End must be after start.';
      return;
    }
    payload.start_at = startDate.toISOString();
    payload.end_at = endDate.toISOString();
    delete payload.start_at_local;
    delete payload.end_at_local;
    msg.textContent = mode === 'edit' ? 'Updating...' : 'Creating...';

    const event_id = document.getElementById('event_id').value;
    const url = mode === 'edit' ? '/api/events_update.php' : '/api/events_create.php';
    try {
      const res = await fetch(url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(mode === 'edit' ? { event_id, ...payload } : payload)
      });
      const data = await res.json();
      if (!data.ok) throw new Error(data.error || 'Failed');
      window.location.reload();
    } catch (err) {
      msg.textContent = err.message || 'Failed';
    }
  });
</script>

<?php render_footer(); ?>
