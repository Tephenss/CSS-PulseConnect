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

$attUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/attendance?select=id,status,check_in_at&limit=100000';
$attRes = supabase_request('GET', $attUrl, $headers);
$att = $attRes['ok'] ? json_decode((string) $attRes['body'], true) : [];
$att = is_array($att) ? $att : [];

$checkedIn = 0;
$late = 0;
$early = 0;
foreach ($att as $a) {
    $checkInAt = $a['check_in_at'] ?? null;
    if (!empty($checkInAt)) {
        $checkedIn++;
        if ((string) ($a['status'] ?? '') === 'late') $late++;
        if ((string) ($a['status'] ?? '') === 'early') $early++;
    }
}

$certUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/certificates?select=id&limit=100000';
$certRes = supabase_request('GET', $certUrl, $headers);
$certRows = $certRes['ok'] ? json_decode((string) $certRes['body'], true) : [];
$certs = is_array($certRows) ? count($certRows) : 0;

render_header('Analytics', $user);
?>

<div class="mb-8 flex flex-col sm:flex-row sm:items-center justify-between gap-4">
  <div>
    <h2 class="text-xl font-bold text-zinc-900 mb-1">System Analytics</h2>
    <p class="text-zinc-600 text-sm">System-wide performance, attendance logs, and global metrics.</p>
  </div>
</div>

<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-5 mb-8">
  <div class="rounded-2xl border border-zinc-200 bg-white p-5 border-b-[3px] border-b-sky-500 shadow-sm flex items-center gap-4 relative overflow-hidden group hover:-translate-y-0.5 transition-transform">
     <div class="absolute -right-8 -top-8 w-24 h-24 bg-sky-400/10 blur-2xl rounded-full pointer-events-none"></div>
     <div class="w-12 h-12 rounded-xl bg-sky-100 border border-sky-200 flex items-center justify-center text-sky-700 z-10 flex-shrink-0">
        <svg class="w-6 h-6" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 11.25v7.5"/></svg>
     </div>
     <div class="z-10 min-w-0">
        <div class="text-3xl font-bold text-zinc-900"><?= htmlspecialchars((string) count($events)) ?></div>
        <div class="text-[11px] text-zinc-600 uppercase tracking-widest font-bold truncate">Total Events</div>
        <div class="text-[10px] text-zinc-600 mt-1 font-medium truncate"><span class="text-amber-800"><?= $pendingEvents ?> Pending</span> · <span class="text-emerald-800"><?= $publishedEvents ?> Active</span></div>
     </div>
  </div>

  <div class="rounded-2xl border border-zinc-200 bg-white p-5 border-b-[3px] border-b-orange-500 shadow-sm flex items-center gap-4 relative overflow-hidden group hover:-translate-y-0.5 transition-transform">
     <div class="absolute -right-8 -top-8 w-24 h-24 bg-orange-400/10 blur-2xl rounded-full pointer-events-none"></div>
     <div class="w-12 h-12 rounded-xl bg-orange-100 border border-orange-200 flex items-center justify-center text-orange-700 z-10 flex-shrink-0">
        <svg class="w-6 h-6" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 002.625.372 9.337 9.337 0 004.121-.952 4.125 4.125 0 00-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 018.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0111.964-3.07M12 6.375a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zm8.25 2.25a2.625 2.625 0 11-5.25 0 2.625 2.625 0 015.25 0z"/></svg>
     </div>
     <div class="z-10 min-w-0">
        <div class="text-3xl font-bold text-zinc-900"><?= htmlspecialchars((string) count($regs)) ?></div>
        <div class="text-[11px] text-zinc-600 uppercase tracking-widest font-bold truncate">Registrations</div>
        <div class="text-[10px] text-zinc-600 mt-1 font-medium truncate">All-time issued tickets</div>
     </div>
  </div>

  <div class="rounded-2xl border border-zinc-200 bg-white p-5 border-b-[3px] border-b-emerald-500 shadow-sm flex items-center gap-4 relative overflow-hidden group hover:-translate-y-0.5 transition-transform">
     <div class="absolute -right-8 -top-8 w-24 h-24 bg-emerald-400/10 blur-2xl rounded-full pointer-events-none"></div>
     <div class="w-12 h-12 rounded-xl bg-emerald-100 border border-emerald-200 flex items-center justify-center text-emerald-700 z-10 flex-shrink-0">
        <svg class="w-6 h-6" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
     </div>
     <div class="z-10 min-w-0">
        <div class="text-3xl font-bold text-zinc-900"><?= htmlspecialchars((string) $checkedIn) ?></div>
        <div class="text-[11px] text-zinc-600 uppercase tracking-widest font-bold truncate">Arrivals</div>
        <div class="text-[10px] text-zinc-600 mt-1 font-medium truncate"><span class="text-sky-800"><?= $early ?> Early</span> · <span class="text-amber-800"><?= $late ?> Late</span></div>
     </div>
  </div>

  <div class="rounded-2xl border border-zinc-200 bg-white p-5 border-b-[3px] border-b-orange-500 shadow-sm flex items-center gap-4 relative overflow-hidden group hover:-translate-y-0.5 transition-transform">
     <div class="absolute -right-8 -top-8 w-24 h-24 bg-orange-400/10 blur-2xl rounded-full pointer-events-none"></div>
     <div class="w-12 h-12 rounded-xl bg-orange-100 border border-orange-200 flex items-center justify-center text-orange-800 z-10 flex-shrink-0">
        <svg class="w-6 h-6" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z"/></svg>
     </div>
     <div class="z-10 min-w-0">
        <div class="text-3xl font-bold text-zinc-900"><?= htmlspecialchars((string) $certs) ?></div>
        <div class="text-[11px] text-zinc-600 uppercase tracking-widest font-bold truncate">Certificates</div>
        <div class="text-[10px] text-zinc-600 mt-1 font-medium truncate"><span class="text-orange-800">Generated records</span></div>
     </div>
  </div>
</div>

<div class="rounded-3xl border border-zinc-200 bg-white p-6 shadow-sm">
  <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-8">
    <div class="flex items-center gap-3.5">
      <div class="w-10 h-10 rounded-xl bg-indigo-100 border border-indigo-200 flex items-center justify-center">
        <svg class="w-5 h-5 text-indigo-700" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 013 19.875v-6.75zM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V8.625zM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V4.125z"/></svg>
      </div>
      <div>
         <h3 class="text-lg font-bold text-zinc-900 tracking-tight">Attendance Log Flow</h3>
         <p class="text-[11px] font-semibold text-zinc-500 uppercase tracking-widest mt-0.5">Scanned Tickets Status</p>
      </div>
    </div>
    <div class="px-5 py-2.5 rounded-2xl bg-zinc-100 border border-zinc-200 flex items-center gap-3">
       <span class="text-xs font-bold text-zinc-600 uppercase tracking-wider">Total Logs</span>
       <span class="text-xl font-bold text-zinc-900 leading-none"><?= count($att) ?></span>
    </div>
  </div>

  <div class="grid grid-cols-1 lg:grid-cols-2 gap-x-16 gap-y-7 px-2">
    <?php
      $counts = [];
      foreach ($att as $a) {
          $s = (string) ($a['status'] ?? 'unscanned');
          if (!isset($counts[$s])) $counts[$s] = 0;
          $counts[$s]++;
      }
      $totalAtt = max(1, count($att));
      
      $statusGradients = [
          'early' => 'from-sky-500 to-blue-600',
          'present' => 'from-emerald-500 to-emerald-700',
          'late' => 'from-amber-500 to-orange-600',
          'invalid' => 'from-red-500 to-red-700',
          'unscanned' => 'from-zinc-400 to-zinc-600',
      ];
      $statusColors = [
          'early' => 'text-sky-800',
          'present' => 'text-emerald-800',
          'late' => 'text-amber-900',
          'invalid' => 'text-red-800',
          'unscanned' => 'text-zinc-700',
      ];
      $statusDots = [
          'early' => 'bg-sky-500',
          'present' => 'bg-emerald-500',
          'late' => 'bg-amber-500',
          'invalid' => 'bg-red-500',
          'unscanned' => 'bg-zinc-500',
      ];
      
      $order = ['present','early','late','unscanned','invalid'];
      foreach ($order as $s) {
          if (!isset($counts[$s]) && $s !== 'present') continue; 
          $c = $counts[$s] ?? 0;
          $pct = round($c / $totalAtt * 100);
          $grad = $statusGradients[$s] ?? 'from-zinc-500 to-zinc-600';
          $color = $statusColors[$s] ?? 'text-zinc-700';
          $dot = $statusDots[$s] ?? 'bg-zinc-500';
          
          echo '<div class="group">';
          echo '  <div class="flex items-center justify-between mb-2.5">';
          echo '    <span class="text-sm font-bold text-zinc-800 capitalize flex items-center gap-2">';
          echo '       <span class="w-1.5 h-1.5 rounded-full ' . $dot . '"></span>' . htmlspecialchars($s);
          echo '    </span>';
          echo '    <div class="flex items-baseline gap-2.5">';
          echo '       <span class="text-lg font-bold text-zinc-900">' . htmlspecialchars((string)$c) . '</span>';
          echo '       <span class="text-xs font-bold text-zinc-600 w-8 text-right">' . $pct . '%</span>';
          echo '    </div>';
          echo '  </div>';
          echo '  <div class="h-3 w-full rounded-full bg-zinc-200 overflow-hidden p-[2px]">';
          echo '    <div class="h-full rounded-full bg-gradient-to-r ' . $grad . ' transition-all duration-[1200ms] ease-out w-0 stat-bar" data-width="' . $pct . '"></div>';
          echo '  </div>';
          echo '</div>';
          unset($counts[$s]); // mark processed
      }
      
      // Irregular statuses
      foreach ($counts as $s => $c) {
          $pct = round($c / $totalAtt * 100);
          echo '<div class="group">';
          echo '  <div class="flex items-center justify-between mb-2.5">';
          echo '    <span class="text-sm font-bold text-zinc-800 capitalize flex items-center gap-2"><span class="w-1.5 h-1.5 rounded-full bg-zinc-500"></span>' . htmlspecialchars($s) . '</span>';
          echo '    <div class="flex items-baseline gap-2.5">';
          echo '       <span class="text-lg font-bold text-zinc-900">' . htmlspecialchars((string)$c) . '</span>';
          echo '       <span class="text-xs font-bold text-zinc-600 w-8 text-right">' . $pct . '%</span>';
          echo '    </div>';
          echo '  </div>';
          echo '  <div class="h-3 w-full rounded-full bg-zinc-200 overflow-hidden p-[2px]">';
          echo '    <div class="h-full rounded-full bg-gradient-to-r from-zinc-500 to-zinc-600 transition-all duration-[1200ms] ease-out w-0 stat-bar" data-width="' . $pct . '"></div>';
          echo '  </div>';
          echo '</div>';
      }
    ?>
  </div>
</div>

<script>
  // Animate stat bars on load
  setTimeout(() => {
    document.querySelectorAll('.stat-bar').forEach(bar => {
      bar.style.width = bar.dataset.width + '%';
    });
  }, 100);
</script>

<?php render_footer(); ?>
