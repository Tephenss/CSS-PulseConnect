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

$headers = [
    'Accept: application/json',
    'apikey: ' . SUPABASE_KEY,
    'Authorization: Bearer ' . SUPABASE_KEY,
];

$eventsUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/events?select=id,title,status&order=created_at.desc&limit=200';
if ($role === 'teacher') {
    $eventsUrl .= '&created_by=eq.' . rawurlencode($userId);
}

$eventsRes = supabase_request('GET', $eventsUrl, $headers);
$eventsRows = $eventsRes['ok'] ? json_decode((string) $eventsRes['body'], true) : [];
$events = is_array($eventsRows) ? $eventsRows : [];

$eventId = isset($_GET['event_id']) ? (string) $_GET['event_id'] : '';
if ($eventId === '' && isset($events[0]['id'])) {
    $eventId = (string) ($events[0]['id'] ?? '');
}

$questions = [];
if ($eventId !== '') {
    $qUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/evaluation_questions'
        . '?select=id,event_id,question_text,field_type,required,sort_order'
        . '&event_id=eq.' . rawurlencode($eventId)
        . '&order=sort_order.asc';
    $qRes = supabase_request('GET', $qUrl, $headers);
    $qRows = $qRes['ok'] ? json_decode((string) $qRes['body'], true) : [];
    $questions = is_array($qRows) ? $qRows : [];
}

render_header('Evaluation questions', $user);
?>

<div class="flex items-start justify-between gap-4">
  <div>
    <div class="text-sm uppercase tracking-widest text-zinc-400">Evaluation</div>
    <h1 class="text-2xl font-semibold mt-2">Question Management</h1>
    <p class="text-zinc-400 text-sm mt-2">Add/delete questions and toggle the “Required” flag.</p>
  </div>
  <div class="flex gap-2 flex-wrap">
    <a href="/manage_events.php" class="rounded-lg border border-zinc-800 bg-zinc-900/40 px-4 py-2.5 text-sm hover:bg-zinc-900 transition">Manage events</a>
  </div>
</div>

<div class="mt-6 grid grid-cols-1 lg:grid-cols-3 gap-4 items-start">
  <div class="lg:col-span-1 rounded-2xl border border-zinc-800 bg-zinc-900/30 p-5">
    <div class="text-sm font-medium">Event</div>
    <div class="mt-3">
      <label class="block text-xs text-zinc-400 mb-1">Choose event</label>
      <select id="eventSel" class="w-full rounded-lg bg-zinc-950 border border-zinc-800 px-3 py-2.5 text-sm outline-none focus:ring-2 focus:ring-zinc-700">
        <?php foreach ($events as $e): ?>
          <option value="<?= htmlspecialchars((string) ($e['id'] ?? '')) ?>" <?= ((string) ($e['id'] ?? '') === $eventId) ? 'selected' : '' ?>>
            <?= htmlspecialchars((string) ($e['title'] ?? '')) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
  </div>

  <div class="lg:col-span-2 rounded-2xl border border-zinc-800 bg-zinc-900/30 p-5">
    <div class="flex items-center justify-between gap-3">
      <div class="text-sm font-medium">Add question</div>
    </div>

    <form id="qForm" class="mt-4 space-y-3">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string) csrf_ensure_token()) ?>" />
      <input type="hidden" name="event_id" value="<?= htmlspecialchars($eventId) ?>" />

      <div>
        <label class="block text-xs text-zinc-400 mb-1" for="question_text">Question</label>
        <input id="question_text" name="question_text" required class="w-full rounded-lg bg-zinc-950 border border-zinc-800 px-3 py-2.5 text-sm outline-none focus:ring-2 focus:ring-zinc-700" />
      </div>

      <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
        <div>
          <label class="block text-xs text-zinc-400 mb-1" for="field_type">Field type</label>
          <select id="field_type" name="field_type" class="w-full rounded-lg bg-zinc-950 border border-zinc-800 px-3 py-2.5 text-sm outline-none focus:ring-2 focus:ring-zinc-700">
            <option value="text">Text</option>
            <option value="rating">Rating</option>
          </select>
        </div>

        <div>
          <label class="block text-xs text-zinc-400 mb-1" for="sort_order">Sort order</label>
          <input id="sort_order" name="sort_order" type="number" min="0" value="0" class="w-full rounded-lg bg-zinc-950 border border-zinc-800 px-3 py-2.5 text-sm outline-none focus:ring-2 focus:ring-zinc-700" />
        </div>
      </div>

      <div class="flex items-center gap-2">
        <input id="required" name="required" type="checkbox" class="rounded border-zinc-700 bg-zinc-950" />
        <label for="required" class="text-sm text-zinc-300">Required</label>
      </div>

      <button type="submit" class="w-full rounded-lg bg-zinc-100 text-zinc-900 px-4 py-2.5 text-sm font-medium hover:bg-zinc-200 transition">
        Add question
      </button>
      <div id="qMsg" class="text-sm text-zinc-300"></div>
    </form>
  </div>
</div>

<div class="mt-4 rounded-2xl border border-zinc-800 bg-zinc-900/30 p-5">
  <div class="flex items-center justify-between gap-3">
    <div class="text-sm font-medium">Existing questions</div>
  </div>

  <div class="mt-4 overflow-x-auto">
    <table class="min-w-full text-sm">
      <thead class="text-zinc-400">
        <tr>
          <th class="text-left py-2 pr-3">Question</th>
          <th class="text-left py-2 pr-3">Type</th>
          <th class="text-left py-2 pr-3">Required</th>
          <th class="text-left py-2 pr-3">Actions</th>
        </tr>
      </thead>
      <tbody class="text-zinc-200">
        <?php if (count($questions) === 0): ?>
          <tr><td colspan="4" class="py-3 text-zinc-400">No questions yet.</td></tr>
        <?php endif; ?>
        <?php foreach ($questions as $q): ?>
          <?php $qid = (string) ($q['id'] ?? ''); ?>
          <tr class="border-t border-zinc-800">
            <td class="py-2 pr-3"><?= htmlspecialchars((string) ($q['question_text'] ?? '')) ?></td>
            <td class="py-2 pr-3 text-zinc-400"><?= htmlspecialchars((string) ($q['field_type'] ?? 'text')) ?></td>
            <td class="py-2 pr-3">
              <input type="checkbox" class="reqToggle" data-qid="<?= htmlspecialchars($qid) ?>" <?= !empty($q['required']) ? 'checked' : '' ?> />
            </td>
            <td class="py-2 pr-3">
              <button class="btnDelete rounded-lg border border-zinc-800 bg-zinc-900/40 px-3 py-1.5 text-xs hover:bg-zinc-900" data-qid="<?= htmlspecialchars($qid) ?>">
                Delete
              </button>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <div id="listMsg" class="mt-3 text-sm text-zinc-300"></div>
</div>

<script>
  const eventSel = document.getElementById('eventSel');
  eventSel.addEventListener('change', () => {
    const eid = eventSel.value;
    window.location.href = '/evaluation_admin.php?event_id=' + encodeURIComponent(eid);
  });

  const qForm = document.getElementById('qForm');
  const qMsg = document.getElementById('qMsg');
  qForm.addEventListener('submit', async (e) => {
    e.preventDefault();
    qMsg.textContent = 'Saving...';
    const fd = new FormData(qForm);
    const required = document.getElementById('required').checked;
    const payload = Object.fromEntries(fd.entries());
    payload.required = required;
    payload.csrf_token = window.CSRF_TOKEN;

    try {
      const res = await fetch('/api/evaluation_questions_save.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
      });
      const data = await res.json();
      if (!data.ok) throw new Error(data.error || 'Failed');
      qMsg.textContent = 'Added.';
      setTimeout(() => window.location.reload(), 800);
    } catch (err) {
      qMsg.textContent = err.message || 'Failed';
    }
  });

  document.querySelectorAll('.btnDelete').forEach(btn => {
    btn.addEventListener('click', async () => {
      if (!confirm('Delete this question?')) return;
      const qid = btn.dataset.qid;
      btn.disabled = true;
      const listMsg = document.getElementById('listMsg');
      listMsg.textContent = 'Deleting...';
      try {
        const res = await fetch('/api/evaluation_questions_delete.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ question_id: qid, csrf_token: window.CSRF_TOKEN })
        });
        const data = await res.json();
        if (!data.ok) throw new Error(data.error || 'Failed');
        window.location.reload();
      } catch (err) {
        listMsg.textContent = err.message || 'Failed';
      } finally {
        btn.disabled = false;
      }
    });
  });

  document.querySelectorAll('.reqToggle').forEach(chk => {
    chk.addEventListener('change', async () => {
      const qid = chk.dataset.qid;
      const required = chk.checked;
      const listMsg = document.getElementById('listMsg');
      listMsg.textContent = 'Updating...';
      try {
        const res = await fetch('/api/evaluation_questions_set_required.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ question_id: qid, required, csrf_token: window.CSRF_TOKEN })
        });
        const data = await res.json();
        if (!data.ok) throw new Error(data.error || 'Failed');
        listMsg.textContent = 'Updated.';
      } catch (err) {
        listMsg.textContent = err.message || 'Failed';
      }
    });
  });
</script>

<?php render_footer(); ?>

