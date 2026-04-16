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

$url = rtrim(SUPABASE_URL, '/') . '/rest/v1/event_registrations'
    . '?select=id,registered_at,event_id,events(title,location,start_at,end_at),tickets(token)'
    . '&student_id=eq.' . rawurlencode((string) ($user['id'] ?? ''))
    . '&order=registered_at.desc';

$res = supabase_request('GET', $url, $headers);
$rows = $res['ok'] ? json_decode((string) $res['body'], true) : null;
$regs = is_array($rows) ? $rows : [];

render_header('My Tickets', $user);
?>

<div class="mb-8 flex flex-col sm:flex-row sm:items-center justify-between gap-4">
  <div>
    <h2 class="text-xl font-bold text-zinc-900 mb-1">My Tickets</h2>
    <p class="text-zinc-400 text-sm">Present your QR ticket for attendance check-in.</p>
  </div>
</div>

<div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-5 pb-10">
  <?php if (count($regs) === 0): ?>
    <div class="md:col-span-2 xl:col-span-3 rounded-3xl border border-dashed border-zinc-700/60 bg-zinc-900/20 py-16 flex flex-col items-center justify-center pointer-events-none">
        <svg class="w-10 h-10 text-zinc-600 mb-3" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M16.5 6v.75m0 3v.75m0 3v.75m0 3V18m-9-5.25h5.25M7.5 15h3M3.375 5.25c-.621 0-1.125.504-1.125 1.125v3.026a2.999 2.999 0 010 5.198v3.026c0 .621.504 1.125 1.125 1.125h17.25c.621 0 1.125-.504 1.125-1.125v-3.026a2.999 2.999 0 010-5.198V6.375c0-.621-.504-1.125-1.125-1.125H3.375z"/></svg>
        <p class="text-zinc-400 font-bold text-sm">You have no tickets yet</p>
        <a href="/events.php" class="inline-block mt-4 px-4 py-2 rounded-xl bg-orange-600/10 text-orange-400 font-bold text-xs hover:bg-orange-600/20 transition-all pointer-events-auto">Browse events &rarr;</a>
    </div>
  <?php endif; ?>

  <?php foreach ($regs as $r): ?>
    <?php
      $event = isset($r['events']) && is_array($r['events']) ? $r['events'] : [];
      $tickets = isset($r['tickets']) && is_array($r['tickets']) ? $r['tickets'] : [];
      $token = isset($tickets[0]['token']) ? (string) $tickets[0]['token'] : (isset($tickets['token']) ? (string) $tickets['token'] : '');
      
      // Format date
      $startRaw = (string)($event['start_at'] ?? '');
      $dateFormatted = 'TBA';
      $timeFormatted = '';
      if ($startRaw) {
         try {
             $dt = new DateTimeImmutable($startRaw);
             $dateFormatted = $dt->format('M d, Y');
             $timeFormatted = $dt->format('g:i A');
         } catch(Throwable $e) {}
      }
    ?>
    <a href="/ticket.php?token=<?= htmlspecialchars($token) ?>" class="group relative rounded-3xl bg-zinc-900/60 border border-zinc-700/50 p-6 flex flex-col justify-between overflow-hidden hover:-translate-y-1 transition-all duration-300 hover:shadow-[0_8px_30px_-5px_rgba(139,92,246,0.3)] hover:border-orange-500/50 min-h-[220px]">
       
       <!-- Subtle glow effect -->
       <div class="absolute -right-12 -top-12 w-32 h-32 bg-orange-500/10 blur-3xl rounded-full group-hover:bg-orange-500/30 transition-all pointer-events-none"></div>

       <div class="relative z-10 flex items-start gap-4 mb-4 mt-1">
         <div class="w-12 h-12 rounded-2xl bg-gradient-to-br from-orange-600 to-red-600 flex items-center justify-center flex-shrink-0 shadow-lg shadow-orange-900/50">
           <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M16.5 6v.75m0 3v.75m0 3v.75m0 3V18m-9-5.25h5.25M7.5 15h3M3.375 5.25c-.621 0-1.125.504-1.125 1.125v3.026a2.999 2.999 0 010 5.198v3.026c0 .621.504 1.125 1.125 1.125h17.25c.621 0 1.125-.504 1.125-1.125v-3.026a2.999 2.999 0 010-5.198V6.375c0-.621-.504-1.125-1.125-1.125H3.375z"/></svg>
         </div>
         <div class="flex-1 min-w-0 pr-4">
            <h3 class="text-base font-black text-white leading-tight truncate"><?= htmlspecialchars((string)($event['title'] ?? 'Event')) ?></h3>
            <p class="text-xs font-semibold text-zinc-400 mt-1.5 truncate flex items-center gap-1.5">
               <svg class="w-3.5 h-3.5 text-zinc-500 flex-shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M15 10.5a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1115 0z"/></svg>
               <span class="truncate"><?= htmlspecialchars((string)($event['location'] ?? 'TBA')) ?></span>
            </p>
         </div>
       </div>

       <div class="relative z-10 flex flex-col gap-3 mt-auto border-t border-zinc-800/80 pt-5">
          <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-1.5">
             <div class="text-[10px] font-bold text-zinc-500 uppercase tracking-widest">Schedule</div>
             <div class="text-xs font-bold text-zinc-300"><?= htmlspecialchars($dateFormatted) ?><?= $timeFormatted ? ' &middot; ' . htmlspecialchars($timeFormatted) : '' ?></div>
          </div>
          <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-1.5">
             <div class="text-[10px] font-bold text-zinc-500 uppercase tracking-widest">Token</div>
             <div class="text-[11px] font-mono font-bold text-orange-400 px-2 py-0.5 rounded border border-orange-500/20 bg-orange-500/10 truncate max-w-full sm:max-w-[150px]"><?= htmlspecialchars($token) ?></div>
          </div>
       </div>
       
       <!-- Floating arrow on hover -->
       <div class="absolute bottom-6 right-6 w-9 h-9 rounded-full bg-orange-600 text-white flex items-center justify-center opacity-0 transform translate-x-4 group-hover:opacity-100 group-hover:translate-x-0 transition-all duration-300 shadow-lg shadow-orange-900/50 hidden sm:flex">
          <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="3" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5"/></svg>
       </div>
    </a>
  <?php endforeach; ?>
</div>

<?php render_footer(); ?>
