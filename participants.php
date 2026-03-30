<?php
declare(strict_types=1);

session_start();

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/supabase.php';
require_once __DIR__ . '/includes/layout.php';

$user = require_role(['admin', 'teacher']);
$role = (string) ($user['role'] ?? 'teacher');
$userId = (string) ($user['id'] ?? '');

$eventId = isset($_GET['event_id']) ? (string) $_GET['event_id'] : '';
if ($eventId === '') {
    http_response_code(400);
    echo 'Missing event_id';
    exit;
}

// Load event details (for day tabs + teacher ownership check)
$eventUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/events?select=id,title,start_at,end_at,created_by&'
    . 'id=eq.' . rawurlencode($eventId) . '&limit=1';
$headers = [
    'Accept: application/json',
    'apikey: ' . SUPABASE_KEY,
    'Authorization: Bearer ' . SUPABASE_KEY,
];
$eventRes = supabase_request('GET', $eventUrl, $headers);
$eventRows = $eventRes['ok'] ? json_decode((string) $eventRes['body'], true) : null;
$event = is_array($eventRows) && isset($eventRows[0]) ? $eventRows[0] : null;
if (!is_array($event)) {
    http_response_code(404);
    echo 'Event not found';
    exit;
}

if ($role === 'teacher' && (string) ($event['created_by'] ?? '') !== $userId) {
    http_response_code(403);
    echo 'Forbidden';
    exit;
}

$start = isset($event['start_at']) ? new DateTimeImmutable((string) $event['start_at']) : null;
$end = isset($event['end_at']) ? new DateTimeImmutable((string) $event['end_at']) : null;

$days = [];
$multiDay = false;
if ($start && $end) {
    $d = $start->setTime(0, 0, 0);
    $endDate = $end->setTime(0, 0, 0);
    while ($d <= $endDate) {
        $days[] = $d->format('Y-m-d');
        $d = $d->modify('+1 day');
    }
    $multiDay = count($days) > 1;
}

// Load participants
$pUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/event_registrations'
    . '?select=id,registered_at,student_id,users(first_name,middle_name,last_name,suffix,email),'
    . 'tickets(token,attendance(check_in_at,check_out_at,status))'
    . '&event_id=eq.' . rawurlencode($eventId)
    . '&order=registered_at.desc';

$pRes = supabase_request('GET', $pUrl, $headers);
$participants = [];
if ($pRes['ok']) {
    $decoded = json_decode((string) $pRes['body'], true);
    $participants = is_array($decoded) ? $decoded : [];
}

// Build buckets by day
$buckets = [];
$buckets['all'] = $participants;
foreach ($participants as $r) {
    $ticket = null;
    $tickets = isset($r['tickets']) ? $r['tickets'] : null;
    if (is_array($tickets)) {
        $ticket = isset($tickets[0]) && is_array($tickets[0]) ? $tickets[0] : null;
    }
    $attendance = null;
    if ($ticket && isset($ticket['attendance'])) {
        $atts = $ticket['attendance'];
        if (is_array($atts)) {
            $attendance = isset($atts[0]) && is_array($atts[0]) ? $atts[0] : (is_array($atts) ? $atts : null);
        }
    }
    if (!is_array($attendance)) {
        continue;
    }
    $checkInAt = $attendance['check_in_at'] ?? null;
    if (!$checkInAt) continue;
    try {
        $checkDate = (new DateTimeImmutable((string) $checkInAt))->format('Y-m-d');
        if (!isset($buckets[$checkDate])) $buckets[$checkDate] = [];
        $buckets[$checkDate][] = $r;
    } catch (Throwable $e) {
        // ignore invalid dates
    }
}

// CSV export
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="participants.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Name', 'Email', 'RegisteredAt', 'Token', 'CheckIn', 'CheckOut', 'AttendanceStatus']);
    foreach ($participants as $r) {
        $u = isset($r['users']) && is_array($r['users']) ? $r['users'] : [];
        $nameParts = [];
        foreach (['first_name','middle_name','last_name'] as $k) {
            $v = trim((string) ($u[$k] ?? ''));
            if ($v !== '') $nameParts[] = $v;
        }
        $name = implode(' ', $nameParts);
        $suffix = trim((string) ($u['suffix'] ?? ''));
        if ($suffix !== '') $name .= ', ' . $suffix;

        $tickets = isset($r['tickets']) && is_array($r['tickets']) ? $r['tickets'] : [];
        $ticket = isset($tickets[0]) && is_array($tickets[0]) ? $tickets[0] : [];
        $token = (string) ($ticket['token'] ?? '');

        $attendance = null;
        if (isset($ticket['attendance'])) {
            $atts = $ticket['attendance'];
            if (is_array($atts)) {
                $attendance = isset($atts[0]) && is_array($atts[0]) ? $atts[0] : (isset($atts) && is_array($atts) ? $atts : null);
            }
        }
        $checkIn = is_array($attendance) ? ($attendance['check_in_at'] ?? '') : '';
        $checkOut = is_array($attendance) ? ($attendance['check_out_at'] ?? '') : '';
        $attStatus = is_array($attendance) ? ($attendance['status'] ?? '') : '';

        fputcsv($out, [
            $name,
            (string) ($u['email'] ?? ''),
            (string) ($r['registered_at'] ?? ''),
            $token,
            is_string($checkIn) ? $checkIn : '',
            is_string($checkOut) ? $checkOut : '',
            (string) $attStatus,
        ]);
    }
    fclose($out);
    exit;
}

$activeDay = isset($_GET['day']) ? (string) $_GET['day'] : 'all';
if ($activeDay !== 'all' && !isset($buckets[$activeDay])) $activeDay = 'all';
$rows = $buckets[$activeDay] ?? [];

render_header('Participants', $user);
?>

<div class="mb-6 flex items-start justify-between gap-4 flex-wrap">
  <div>
    <h2 class="text-lg font-semibold text-zinc-100"><?= htmlspecialchars((string) ($event['title'] ?? 'Event')) ?></h2>
    <p class="text-zinc-400 text-sm mt-1">Participant list and attendance tracking.</p>
  </div>
  <div class="flex gap-2 flex-wrap">
    <a href="/participants.php?event_id=<?= htmlspecialchars($eventId) ?>&export=csv" class="rounded-lg bg-gradient-to-r from-emerald-600 to-emerald-500 text-white px-4 py-2 text-sm font-medium hover:from-emerald-500 hover:to-emerald-400 transition-all shadow-sm flex items-center gap-1.5">
      <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5M16.5 12L12 16.5m0 0L7.5 12m4.5 4.5V3"/></svg>
      Export CSV
    </a>
    <a href="/manage_events.php" class="rounded-lg border border-zinc-700 bg-zinc-800/40 px-4 py-2 text-sm text-zinc-300 hover:bg-zinc-700/60 transition">
      ← Back
    </a>
  </div>
</div>

<?php if ($multiDay): ?>
  <div class="mb-4 flex gap-2 flex-wrap">
    <a class="px-3 py-1.5 rounded-lg text-xs font-medium border transition <?= $activeDay === 'all' ? 'border-violet-500/30 bg-violet-500/10 text-violet-300' : 'border-zinc-800 bg-zinc-900/30 text-zinc-400 hover:bg-zinc-800/60' ?>"
       href="/participants.php?event_id=<?= htmlspecialchars($eventId) ?>&day=all">All</a>
    <?php foreach ($days as $day): ?>
      <a class="px-3 py-1.5 rounded-lg text-xs font-medium border transition <?= $activeDay === $day ? 'border-violet-500/30 bg-violet-500/10 text-violet-300' : 'border-zinc-800 bg-zinc-900/30 text-zinc-400 hover:bg-zinc-800/60' ?>"
         href="/participants.php?event_id=<?= htmlspecialchars($eventId) ?>&day=<?= htmlspecialchars($day) ?>"><?= htmlspecialchars($day) ?></a>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<div class="rounded-2xl glass-card p-5 overflow-x-auto">
  <div class="flex items-center gap-2.5 mb-4">
    <div class="w-8 h-8 rounded-lg bg-gradient-to-br from-violet-600/20 to-fuchsia-600/20 border border-violet-500/20 flex items-center justify-center">
      <svg class="w-4 h-4 text-violet-400" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 002.625.372 9.337 9.337 0 004.121-.952 4.125 4.125 0 00-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 018.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0111.964-3.07M12 6.375a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zm8.25 2.25a2.625 2.625 0 11-5.25 0 2.625 2.625 0 015.25 0z"/></svg>
    </div>
    <span class="text-sm font-medium text-zinc-200">Registered Participants</span>
    <span class="text-xs text-zinc-600 ml-auto"><?= count($rows) ?> shown</span>
  </div>

  <table class="min-w-full text-sm">
    <thead>
      <tr class="text-xs text-zinc-500 uppercase tracking-wider">
        <th class="text-left py-2.5 pr-3 font-medium">Name</th>
        <th class="text-left py-2.5 pr-3 font-medium">Email</th>
        <th class="text-left py-2.5 pr-3 font-medium">Token</th>
        <th class="text-left py-2.5 pr-3 font-medium">Check-in</th>
        <th class="text-left py-2.5 pr-3 font-medium">Check-out</th>
        <th class="text-left py-2.5 pr-3 font-medium">Status</th>
        <?php if ($role === 'admin'): ?>
          <th class="text-left py-2.5 pr-3 font-medium">Action</th>
        <?php endif; ?>
      </tr>
    </thead>
    <tbody class="text-zinc-200">
      <?php if (count($rows) === 0): ?>
        <tr><td class="py-6 text-zinc-500 text-center" colspan="<?= $role === 'admin' ? 7 : 6 ?>">No participants for selected view.</td></tr>
      <?php endif; ?>

      <?php foreach ($rows as $r): ?>
        <?php
          $u = isset($r['users']) && is_array($r['users']) ? $r['users'] : [];
          $nameParts = [];
          foreach (['first_name','middle_name','last_name'] as $k) {
              $v = trim((string) ($u[$k] ?? ''));
              if ($v !== '') $nameParts[] = $v;
          }
          $name = implode(' ', $nameParts);
          $suffix = trim((string) ($u['suffix'] ?? ''));
          if ($suffix !== '') $name .= ', ' . $suffix;

          $tickets = isset($r['tickets']) && is_array($r['tickets']) ? $r['tickets'] : [];
          $ticket = isset($tickets[0]) && is_array($tickets[0]) ? $tickets[0] : [];
          $token = (string) ($ticket['token'] ?? '');

          $attendance = null;
          if (isset($ticket['attendance'])) {
              $atts = $ticket['attendance'];
              if (is_array($atts)) {
                  $attendance = isset($atts[0]) && is_array($atts[0]) ? $atts[0] : $atts;
              }
          }
          $checkIn = is_array($attendance) ? ($attendance['check_in_at'] ?? '') : '';
          $checkOut = is_array($attendance) ? ($attendance['check_out_at'] ?? '') : '';
          $attStatus = is_array($attendance) ? ($attendance['status'] ?? '') : '';
          $registrationId = (string) ($r['id'] ?? '');

          $attStatusColor = match((string)$attStatus) {
              'present' => 'bg-emerald-500/15 text-emerald-400 border-emerald-500/20',
              'late' => 'bg-amber-500/15 text-amber-400 border-amber-500/20',
              'early' => 'bg-sky-500/15 text-sky-400 border-sky-500/20',
              default => 'bg-zinc-500/15 text-zinc-400 border-zinc-500/20',
          };
        ?>
        <tr class="border-t border-zinc-800/50 hover:bg-zinc-800/20 transition">
          <td class="py-3 pr-3 font-medium"><?= htmlspecialchars($name) ?></td>
          <td class="py-3 pr-3 text-zinc-400 text-xs"><?= htmlspecialchars((string) ($u['email'] ?? '')) ?></td>
          <td class="py-3 pr-3 text-zinc-600 font-mono text-[10px] break-all max-w-[120px]"><?= htmlspecialchars($token) ?></td>
          <td class="py-3 pr-3 text-zinc-400 text-xs"><?= htmlspecialchars(is_string($checkIn) ? $checkIn : '') ?></td>
          <td class="py-3 pr-3 text-zinc-400 text-xs"><?= htmlspecialchars(is_string($checkOut) ? $checkOut : '') ?></td>
          <td class="py-3 pr-3">
            <?php if ((string)$attStatus !== ''): ?>
              <span class="text-[10px] font-medium rounded-full border px-2 py-0.5 <?= $attStatusColor ?>"><?= htmlspecialchars((string) $attStatus) ?></span>
            <?php else: ?>
              <span class="text-xs text-zinc-600">—</span>
            <?php endif; ?>
          </td>
          <?php if ($role === 'admin'): ?>
            <td class="py-3 pr-3">
              <button class="btnRemove rounded-lg border border-red-900/40 bg-red-950/20 px-2.5 py-1.5 text-xs text-red-400 hover:bg-red-900/30 transition"
                      data-id="<?= htmlspecialchars($registrationId) ?>">
                Remove
              </button>
            </td>
          <?php endif; ?>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php if ($role === 'admin'): ?>
<script>
  document.querySelectorAll('.btnRemove').forEach(btn => {
    btn.addEventListener('click', async () => {
      const ok = confirm('Remove this participant?');
      if (!ok) return;
      btn.disabled = true;
      try {
        const registration_id = btn.dataset.id;
        const res = await fetch('/api/participant_remove.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ registration_id, csrf_token: window.CSRF_TOKEN })
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
</script>
<?php endif; ?>

<?php render_footer(); ?>
