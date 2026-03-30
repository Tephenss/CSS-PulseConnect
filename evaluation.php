<?php
declare(strict_types=1);

session_start();

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/supabase.php';
require_once __DIR__ . '/includes/layout.php';

$user = require_role(['student']);
$studentId = (string) ($user['id'] ?? '');

$headers = [
    'Accept: application/json',
    'apikey: ' . SUPABASE_KEY,
    'Authorization: Bearer ' . SUPABASE_KEY,
];

// Find events where the student is registered.
$regUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/event_registrations'
    . '?select=event_id&student_id=eq.' . rawurlencode($studentId) . '&limit=2000';
$regRes = supabase_request('GET', $regUrl, $headers);
$regRows = $regRes['ok'] ? json_decode((string) $regRes['body'], true) : null;
$eventIds = [];
if (is_array($regRows)) {
    foreach ($regRows as $r) {
        $eid = (string) ($r['event_id'] ?? '');
        if ($eid !== '') $eventIds[$eid] = true;
    }
}
$eventIds = array_keys($eventIds);

if (count($eventIds) === 0) {
    render_header('Evaluation', $user);
    echo '<div class="mt-6 rounded-2xl border border-zinc-800 bg-zinc-900/30 p-6 text-zinc-300 text-sm">No events found for your account.</div>';
    render_footer();
    exit;
}

$in = implode(',', $eventIds);

// Load all questions for those events so we can build the event dropdown.
$qUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/evaluation_questions'
    . '?select=id,event_id,question_text,field_type,required,sort_order'
    . '&event_id=in.(' . $in . ')'
    . '&order=sort_order.asc';
$qRes = supabase_request('GET', $qUrl, $headers);
$qRows = $qRes['ok'] ? json_decode((string) $qRes['body'], true) : [];
$questionsByEvent = [];
foreach ((is_array($qRows) ? $qRows : []) as $q) {
    $eid = (string) ($q['event_id'] ?? '');
    if ($eid === '') continue;
    if (!isset($questionsByEvent[$eid])) $questionsByEvent[$eid] = [];
    $questionsByEvent[$eid][] = $q;
}
$eventOptions = array_keys($questionsByEvent);
sort($eventOptions);

$activeEventId = isset($_GET['event_id']) ? (string) $_GET['event_id'] : '';
if ($activeEventId === '' || !isset($questionsByEvent[$activeEventId])) {
    $activeEventId = $eventOptions[0] ?? '';
}
if ($activeEventId === '') {
    render_header('Evaluation', $user);
    echo '<div class="mt-6 rounded-2xl border border-zinc-800 bg-zinc-900/30 p-6 text-zinc-300 text-sm">No evaluation questions found.</div>';
    render_footer();
    exit;
}

// Load event titles for dropdown.
$eventsUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/events'
    . '?select=id,title&order=title.asc&event_id=in.(' . $in . ')';
// Some PostgREST versions don\\\'t allow event_id on events; fallback to id filter.
$eventsUrl2 = rtrim(SUPABASE_URL, '/') . '/rest/v1/events'
    . '?select=id,title&order=created_at.desc&id=in.(' . $in . ')';
$eventsRes = supabase_request('GET', $eventsUrl2, $headers);
$eventsRows = $eventsRes['ok'] ? json_decode((string) $eventsRes['body'], true) : [];
$eventsById = [];
if (is_array($eventsRows)) {
    foreach ($eventsRows as $e) {
        $eventsById[(string) ($e['id'] ?? '')] = $e;
    }
}

// Load existing answers (if any).
$ansUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/evaluation_answers'
    . '?select=question_id,answer_text,event_id,submitted_at'
    . '&student_id=eq.' . rawurlencode($studentId)
    . '&event_id=eq.' . rawurlencode($activeEventId);
$ansRes = supabase_request('GET', $ansUrl, $headers);
$ansRows = $ansRes['ok'] ? json_decode((string) $ansRes['body'], true) : [];
$answers = [];
if (is_array($ansRows)) {
    foreach ($ansRows as $a) {
        $qid = (string) ($a['question_id'] ?? '');
        if ($qid === '') continue;
        $answers[$qid] = (string) ($a['answer_text'] ?? '');
    }
}

$questions = $questionsByEvent[$activeEventId] ?? [];
render_header('Evaluation', $user);
?>

<div class="flex items-start justify-between gap-4">
  <div>
    <div class="text-sm uppercase tracking-widest text-zinc-400">Evaluation</div>
    <h1 class="text-2xl font-semibold mt-2">Answer the questions</h1>
    <p class="text-zinc-400 text-sm mt-2">Required items must be answered to submit.</p>
  </div>

  <form method="GET" class="flex items-center gap-2">
    <input type="hidden" name="event_id" value="" />
  </form>
</div>

<div class="mt-6 grid grid-cols-1 lg:grid-cols-3 gap-4 items-start">
  <div class="lg:col-span-2 rounded-2xl border border-zinc-800 bg-zinc-900/30 p-6">
    <div class="flex items-center gap-3">
      <label class="text-sm text-zinc-400">Event</label>
      <select id="eventSel" class="flex-1 rounded-lg bg-zinc-950 border border-zinc-800 px-3 py-2.5 text-sm outline-none focus:ring-2 focus:ring-zinc-700">
        <?php foreach ($eventOptions as $eid): ?>
          <option value="<?= htmlspecialchars($eid) ?>" <?= $eid === $activeEventId ? 'selected' : '' ?>>
            <?= htmlspecialchars((string) ($eventsById[$eid]['title'] ?? $eid)) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>

    <form id="evalForm" class="mt-5 space-y-5">
      <input type="hidden" id="event_id" name="event_id" value="<?= htmlspecialchars($activeEventId) ?>" />
      <div id="questions">
        <?php foreach ($questions as $q): ?>
          <?php
            $qid = (string) ($q['id'] ?? '');
            $qt = (string) ($q['question_text'] ?? '');
            $ft = (string) ($q['field_type'] ?? 'text');
            $req = !empty($q['required']);
            $val = isset($answers[$qid]) ? (string) $answers[$qid] : '';
          ?>
          <div class="rounded-xl border border-zinc-800 bg-zinc-950/20 p-4">
            <div class="flex items-start justify-between gap-3">
              <div class="text-sm font-medium"><?= htmlspecialchars($qt) ?></div>
              <?php if ($req): ?>
                <div class="text-xs px-2 py-1 rounded-full bg-zinc-900 border border-zinc-700 text-zinc-200">Required</div>
              <?php endif; ?>
            </div>

            <div class="mt-3">
              <?php if ($ft === 'rating'): ?>
                <select
                  class="w-full rounded-lg bg-zinc-950 border border-zinc-800 px-3 py-2.5 text-sm outline-none focus:ring-2 focus:ring-zinc-700"
                  data-question-id="<?= htmlspecialchars($qid) ?>"
                  name="answer_<?= htmlspecialchars($qid) ?>"
                >
                  <?php for ($i=1; $i<=5; $i++): ?>
                    <option value="<?= $i ?>" <?= (string)$i === (string)$val ? 'selected' : '' ?>><?= $i ?></option>
                  <?php endfor; ?>
                </select>
              <?php else: ?>
                <textarea
                  rows="3"
                  class="w-full rounded-lg bg-zinc-950 border border-zinc-800 px-3 py-2.5 text-sm outline-none focus:ring-2 focus:ring-zinc-700"
                  data-question-id="<?= htmlspecialchars($qid) ?>"
                  name="answer_<?= htmlspecialchars($qid) ?>"
                ><?= htmlspecialchars($val) ?></textarea>
              <?php endif; ?>
            </div>
          </div>
        <?php endforeach; ?>
      </div>

      <div class="flex items-center gap-3">
        <button type="submit" class="rounded-lg bg-zinc-100 text-zinc-900 px-4 py-2.5 text-sm font-medium hover:bg-zinc-200 transition">
          Submit evaluation
        </button>
        <div id="evalMsg" class="text-sm text-zinc-300"></div>
      </div>
    </form>
  </div>

  <div class="rounded-2xl border border-zinc-800 bg-zinc-900/30 p-6">
    <div class="text-sm font-medium">How it works</div>
    <div class="mt-3 text-sm text-zinc-400 leading-relaxed">
      This page saves your answers into the system.
      Required questions cannot be empty.
    </div>
  </div>
</div>

<script>
  const eventSel = document.getElementById('eventSel');
  eventSel.addEventListener('change', () => {
    const eid = eventSel.value;
    window.location.href = '/evaluation.php?event_id=' + encodeURIComponent(eid);
  });

  document.getElementById('evalForm').addEventListener('submit', async (e) => {
    e.preventDefault();
    const event_id = document.getElementById('event_id').value;
    const msg = document.getElementById('evalMsg');
    msg.textContent = 'Submitting...';

    const inputs = document.querySelectorAll('[data-question-id]');
    const answers = {};
    inputs.forEach(el => {
      const qid = el.dataset.questionId;
      let v = '';
      if (el.tagName === 'TEXTAREA') v = el.value || '';
      else v = el.value || '';
      answers[qid] = v;
    });

    try {
      const res = await fetch('/api/evaluation_submit.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ event_id, answers, csrf_token: window.CSRF_TOKEN })
      });
      const data = await res.json();
      if (!data.ok) throw new Error(data.error || 'Failed');
      msg.textContent = 'Submitted successfully.';
    } catch (err) {
      msg.textContent = err.message || 'Failed';
    }
  });
</script>

<?php render_footer(); ?>

