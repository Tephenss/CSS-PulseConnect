<?php
declare(strict_types=1);

session_start();

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/supabase.php';
require_once __DIR__ . '/includes/layout.php';

$user = require_role(['student']);
$headers = [
    'Accept: application/json',
    'apikey: ' . SUPABASE_KEY,
    'Authorization: Bearer ' . SUPABASE_KEY,
];

$url = rtrim(SUPABASE_URL, '/') . '/rest/v1/certificates'
    . '?select=id,certificate_code,issued_at,event_id,events(title,start_at)&student_id=eq.' . rawurlencode((string) ($user['id'] ?? ''))
    . '&order=issued_at.desc';

$res = supabase_request('GET', $url, $headers);
$rows = $res['ok'] ? json_decode((string) $res['body'], true) : null;
$certs = is_array($rows) ? $rows : [];

render_header('My Certificates', $user);
?>

<div class="mb-8 flex flex-col sm:flex-row sm:items-center justify-between gap-4">
  <div>
    <h2 class="text-xl font-bold text-zinc-900 mb-1">My Certificates</h2>
    <p class="text-zinc-400 text-sm">Printable digital certificates appear after your attendance is fully verified.</p>
  </div>
</div>

<div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-5 pb-10">
  <?php if (count($certs) === 0): ?>
    <div class="md:col-span-2 xl:col-span-3 rounded-3xl border border-dashed border-zinc-700/60 bg-zinc-900/20 py-16 flex flex-col items-center justify-center pointer-events-none">
        <svg class="w-10 h-10 text-zinc-600 mb-3" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M4.26 10.147a60.436 60.436 0 00-.491 6.347A48.627 48.627 0 0112 20.904a48.627 48.627 0 018.232-4.41 60.46 60.46 0 00-.491-6.347m-15.482 0a50.57 50.57 0 00-2.658-.813A59.905 59.905 0 0112 3.493a59.902 59.902 0 0110.399 5.84c-.896.248-1.783.52-2.658.814m-15.482 0A50.697 50.697 0 0112 13.489a50.702 50.702 0 017.74-3.342"/></svg>
        <p class="text-zinc-400 font-bold text-sm">No certificates yet</p>
        <p class="text-xs text-zinc-500 mt-1 max-w-sm text-center px-4">Attend events and complete check-in/out to earn certificates.</p>
        <a href="/events.php" class="inline-block mt-4 px-4 py-2 rounded-xl bg-emerald-600/10 text-emerald-400 font-bold text-xs hover:bg-emerald-600/20 transition-all pointer-events-auto">Browse events &rarr;</a>
    </div>
  <?php endif; ?>

  <?php foreach ($certs as $c): ?>
    <?php
      $event = isset($c['events']) && is_array($c['events']) ? $c['events'] : [];
      
      // Format date
      $issuedRaw = (string)($c['issued_at'] ?? '');
      $dateFormatted = 'TBA';
      $timeFormatted = '';
      if ($issuedRaw) {
         try {
             $dt = new DateTimeImmutable($issuedRaw);
             $dateFormatted = $dt->format('M d, Y');
             $timeFormatted = $dt->format('g:i A');
         } catch(Throwable $e) {}
      }
    ?>
    <a href="/certificate.php?code=<?= htmlspecialchars((string) ($c['certificate_code'] ?? '')) ?>" class="group relative rounded-3xl bg-zinc-900/60 border border-zinc-700/50 p-6 flex flex-col justify-between overflow-hidden hover:-translate-y-1 transition-all duration-300 hover:shadow-[0_8px_30px_-5px_rgba(16,185,129,0.2)] hover:border-emerald-500/50 min-h-[220px]">
       
       <!-- Subtle glow effect -->
       <div class="absolute -right-12 -top-12 w-32 h-32 bg-emerald-500/10 blur-3xl rounded-full group-hover:bg-emerald-500/30 transition-all pointer-events-none"></div>

       <div class="relative z-10 flex items-start gap-4 mb-4 mt-1">
         <div class="w-12 h-12 rounded-2xl bg-gradient-to-br from-emerald-600 to-teal-600 flex items-center justify-center flex-shrink-0 shadow-lg shadow-emerald-900/50">
           <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12c0 1.268-.63 2.39-1.593 3.068a3.745 3.745 0 01-1.043 3.296 3.745 3.745 0 01-3.296 1.043A3.745 3.745 0 0112 21c-1.268 0-2.39-.63-3.068-1.593a3.746 3.746 0 01-3.296-1.043 3.745 3.745 0 01-1.043-3.296A3.745 3.745 0 013 12c0-1.268.63-2.39 1.593-3.068a3.745 3.745 0 011.043-3.296 3.746 3.746 0 013.296-1.043A3.746 3.746 0 0112 3c1.268 0 2.39.63 3.068 1.593a3.746 3.746 0 013.296 1.043 3.746 3.746 0 011.043 3.296A3.745 3.745 0 0121 12z"/></svg>
         </div>
         <div class="flex-1 min-w-0 pr-4">
            <h3 class="text-base font-black text-white leading-tight truncate"><?= htmlspecialchars((string)($event['title'] ?? 'Event')) ?></h3>
            <p class="text-xs font-semibold text-zinc-400 mt-1.5 truncate flex items-center gap-1.5">
               <svg class="w-3.5 h-3.5 text-emerald-400 flex-shrink-0" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/></svg>
               <span class="truncate">Verified Attendance</span>
            </p>
         </div>
       </div>

       <div class="relative z-10 flex flex-col gap-3 mt-auto border-t border-zinc-800/80 pt-5">
          <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-1.5">
             <div class="text-[10px] font-bold text-zinc-500 uppercase tracking-widest">Issued On</div>
             <div class="text-xs font-bold text-zinc-300"><?= htmlspecialchars($dateFormatted) ?><?= $timeFormatted ? ' &middot; ' . htmlspecialchars($timeFormatted) : '' ?></div>
          </div>
          <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-1.5">
             <div class="text-[10px] font-bold text-zinc-500 uppercase tracking-widest">Code</div>
             <div class="text-[11px] font-mono font-bold text-emerald-400 px-2 py-0.5 rounded border border-emerald-500/20 bg-emerald-500/10 truncate max-w-full sm:max-w-[150px]"><?= htmlspecialchars((string)($c['certificate_code'] ?? '')) ?></div>
          </div>
       </div>
       
       <!-- Floating arrow on hover -->
       <div class="absolute bottom-6 right-6 w-9 h-9 rounded-full bg-emerald-600 text-white flex items-center justify-center opacity-0 transform translate-x-4 group-hover:opacity-100 group-hover:translate-x-0 transition-all duration-300 shadow-lg shadow-emerald-900/50 hidden sm:flex">
          <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="3" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5"/></svg>
       </div>
    </a>
  <?php endforeach; ?>
</div>

<?php render_footer(); ?>
