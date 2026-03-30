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

function safe_count($resBody): int
{
    if (!is_array($resBody)) return 0;
    return count($resBody);
}

// Global counts (best-effort).
$eventsUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/events?select=id,status&limit=10000';
$eventsRes = supabase_request('GET', $eventsUrl, $headers);
$events = $eventsRes['ok'] ? json_decode((string) $eventsRes['body'], true) : [];
$events = is_array($events) ? $events : [];

$pendingEvents = 0;
$publishedEvents = 0;
foreach ($events as $e) {
    $s = (string) ($e['status'] ?? '');
    if ($s === 'pending') $pendingEvents++;
    if ($s === 'published') $publishedEvents++;
}

$regsUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/event_registrations?select=id&limit=100000';
$regsRes = supabase_request('GET', $regsUrl, $headers);
$regs = $regsRes['ok'] ? json_decode((string) $regsRes['body'], true) : [];
$regs = is_array($regs) ? $regs : [];

$attUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/attendance?select=id,status,check_in_at,check_out_at&limit=100000';
$attRes = supabase_request('GET', $attUrl, $headers);
$att = $attRes['ok'] ? json_decode((string) $attRes['body'], true) : [];
$att = is_array($att) ? $att : [];

$checkedIn = 0;
$late = 0;
$checkedOut = 0;
$early = 0;
foreach ($att as $a) {
    $checkInAt = $a['check_in_at'] ?? null;
    $checkOutAt = $a['check_out_at'] ?? null;
    if (!empty($checkInAt)) {
        $checkedIn++;
        if ((string) ($a['status'] ?? '') === 'late') $late++;
        if ((string) ($a['status'] ?? '') === 'early') $early++;
    }
    if (!empty($checkOutAt)) $checkedOut++;
}

$certUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/certificates?select=id&limit=100000';
$certRes = supabase_request('GET', $certUrl, $headers);
$certRows = $certRes['ok'] ? json_decode((string) $certRes['body'], true) : [];
$certs = is_array($certRows) ? count($certRows) : 0;

render_header('Analytics', $user);
?>

<div class="mb-6">
  <p class="text-zinc-400 text-sm">System-wide counts and breakdowns for events, attendance, and certificates.</p>
</div>

<div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-4 mb-6">
  <div class="rounded-xl glass-card p-5">
    <div class="flex items-center gap-3">
      <div class="w-11 h-11 rounded-xl bg-gradient-to-br from-sky-600/20 to-cyan-600/20 border border-sky-500/20 flex items-center justify-center">
        <svg class="w-5 h-5 text-sky-400" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 11.25v7.5"/></svg>
      </div>
      <div>
        <div class="text-2xl font-bold text-zinc-100"><?= htmlspecialchars((string) count($events)) ?></div>
        <div class="text-xs text-zinc-500">Total Events</div>
        <div class="text-[10px] text-zinc-600 mt-0.5">Pending: <?= $pendingEvents ?> · Published: <?= $publishedEvents ?></div>
      </div>
    </div>
  </div>

  <div class="rounded-xl glass-card p-5">
    <div class="flex items-center gap-3">
      <div class="w-11 h-11 rounded-xl bg-gradient-to-br from-violet-600/20 to-fuchsia-600/20 border border-violet-500/20 flex items-center justify-center">
        <svg class="w-5 h-5 text-violet-400" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 002.625.372 9.337 9.337 0 004.121-.952 4.125 4.125 0 00-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 018.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0111.964-3.07M12 6.375a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zm8.25 2.25a2.625 2.625 0 11-5.25 0 2.625 2.625 0 015.25 0z"/></svg>
      </div>
      <div>
        <div class="text-2xl font-bold text-zinc-100"><?= htmlspecialchars((string) count($regs)) ?></div>
        <div class="text-xs text-zinc-500">Registrations</div>
      </div>
    </div>
  </div>

  <div class="rounded-xl glass-card p-5">
    <div class="flex items-center gap-3">
      <div class="w-11 h-11 rounded-xl bg-gradient-to-br from-emerald-600/20 to-teal-600/20 border border-emerald-500/20 flex items-center justify-center">
        <svg class="w-5 h-5 text-emerald-400" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
      </div>
      <div>
        <div class="text-2xl font-bold text-zinc-100"><?= htmlspecialchars((string) $checkedIn) ?></div>
        <div class="text-xs text-zinc-500">Checked-in</div>
        <div class="text-[10px] text-zinc-600 mt-0.5">Late: <?= $late ?> · Early: <?= $early ?></div>
      </div>
    </div>
  </div>

  <div class="rounded-xl glass-card p-5">
    <div class="flex items-center gap-3">
      <div class="w-11 h-11 rounded-xl bg-gradient-to-br from-amber-600/20 to-orange-600/20 border border-amber-500/20 flex items-center justify-center">
        <svg class="w-5 h-5 text-amber-400" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z"/></svg>
      </div>
      <div>
        <div class="text-2xl font-bold text-zinc-100"><?= htmlspecialchars((string) $checkedOut) ?></div>
        <div class="text-xs text-zinc-500">Checked-out</div>
        <div class="text-[10px] text-zinc-600 mt-0.5">Certificates: <?= $certs ?></div>
      </div>
    </div>
  </div>
</div>

<div class="rounded-2xl glass-card p-6">
  <div class="flex items-center gap-2.5 mb-4">
    <div class="w-8 h-8 rounded-lg bg-gradient-to-br from-sky-600/20 to-cyan-600/20 border border-sky-500/20 flex items-center justify-center">
      <svg class="w-4 h-4 text-sky-400" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 013 19.875v-6.75zM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V8.625zM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V4.125z"/></svg>
    </div>
    <span class="text-sm font-medium text-zinc-200">Attendance Status Breakdown</span>
  </div>

  <div class="overflow-x-auto">
    <table class="min-w-full text-sm">
      <thead>
        <tr class="text-xs text-zinc-500 uppercase tracking-wider">
          <th class="text-left py-2.5 pr-3 font-medium">Status</th>
          <th class="text-left py-2.5 pr-3 font-medium">Count</th>
          <th class="text-left py-2.5 pr-3 font-medium">Visual</th>
        </tr>
      </thead>
      <tbody class="text-zinc-200">
        <?php
          $counts = [];
          foreach ($att as $a) {
              $s = (string) ($a['status'] ?? 'unscanned');
              if (!isset($counts[$s])) $counts[$s] = 0;
              $counts[$s]++;
          }
          $totalAtt = max(1, count($att));
          $statusColors = [
              'unscanned' => 'bg-zinc-500',
              'early' => 'bg-sky-500',
              'present' => 'bg-emerald-500',
              'late' => 'bg-amber-500',
              'invalid' => 'bg-red-500',
          ];
          $order = ['unscanned','early','present','late','invalid'];
          foreach ($order as $s) {
              if (!isset($counts[$s])) continue;
              $pct = round($counts[$s] / $totalAtt * 100);
              $color = $statusColors[$s] ?? 'bg-zinc-500';
              echo '<tr class="border-t border-zinc-800/50">';
              echo '<td class="py-3 pr-3 capitalize">' . htmlspecialchars($s) . '</td>';
              echo '<td class="py-3 pr-3 font-mono">' . htmlspecialchars((string)$counts[$s]) . '</td>';
              echo '<td class="py-3 pr-3 w-48"><div class="h-2 rounded-full bg-zinc-800 overflow-hidden"><div class="h-full rounded-full ' . $color . '" style="width:' . $pct . '%"></div></div></td>';
              echo '</tr>';
          }
          foreach ($counts as $s => $c) {
              if (in_array($s, $order, true)) continue;
              $pct = round($c / $totalAtt * 100);
              echo '<tr class="border-t border-zinc-800/50">';
              echo '<td class="py-3 pr-3 capitalize">' . htmlspecialchars($s) . '</td>';
              echo '<td class="py-3 pr-3 font-mono">' . htmlspecialchars((string)$c) . '</td>';
              echo '<td class="py-3 pr-3 w-48"><div class="h-2 rounded-full bg-zinc-800 overflow-hidden"><div class="h-full rounded-full bg-zinc-500" style="width:' . $pct . '%"></div></div></td>';
              echo '</tr>';
          }
        ?>
      </tbody>
    </table>
  </div>
</div>

<?php render_footer(); ?>
