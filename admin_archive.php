<?php
declare(strict_types=1);

session_start();

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/supabase.php';
require_once __DIR__ . '/includes/layout.php';

$user = require_role(['admin']);
$role = (string) ($user['role'] ?? 'teacher');

$select = 'select=id,title,description,location,start_at,end_at,status,created_by,approved_by,created_at,updated_at';
$url = rtrim(SUPABASE_URL, '/') . '/rest/v1/events?' . $select . '&status=eq.archived&order=created_at.desc';

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

render_header('Archived Events', $user);
?>

<div class="mb-6 flex flex-col sm:flex-row sm:items-center justify-between gap-4">
  <div>
    <h2 class="text-xl font-bold text-zinc-900 mb-1">Archived Events</h2>
    <p class="text-zinc-600 text-sm">Review, recover or permanently manage archived activities.</p>
  </div>
</div>

<!-- Top Nav Tabs (Pages 41 & 42) -->
<div class="flex border-b border-zinc-200 mb-6 gap-6 mt-2 relative z-10 w-full overflow-x-auto">
    <button id="btnTabEvents" class="pb-3 border-b-2 border-orange-500 font-bold text-orange-600 text-[13px] transition-colors whitespace-nowrap px-1 group flex items-center gap-2">
        Events
        <span class="bg-orange-100 text-orange-700 text-[10px] font-black px-2 py-0.5 rounded-full border border-orange-200 group-hover:bg-orange-200 transition-colors"><?= count($events) ?></span>
    </button>
    <button id="btnTabTeachers" class="pb-3 border-b-2 border-transparent font-bold text-zinc-500 hover:text-zinc-800 text-[13px] transition-colors whitespace-nowrap px-1 group flex items-center gap-2">
        Teachers
        <span class="bg-zinc-100 text-zinc-600 border border-zinc-200 text-[10px] font-black px-2 py-0.5 rounded-full group-hover:bg-zinc-200 transition-colors">1</span>
    </button>
    <button id="btnTabStudents" class="pb-3 border-b-2 border-transparent font-bold text-zinc-500 hover:text-zinc-800 text-[13px] transition-colors whitespace-nowrap px-1 group flex items-center gap-2">
        Students
        <span class="bg-zinc-100 text-zinc-600 border border-zinc-200 text-[10px] font-black px-2 py-0.5 rounded-full group-hover:bg-zinc-200 transition-colors">2</span>
    </button>
</div>

<!-- ARCHIVED EVENTS LIST -->
<div id="panelEvents" class="flex flex-col gap-3 pb-10">
  <?php foreach ($events as $e): ?>
    <?php 
       $eid = (string) ($e['id'] ?? ''); 
       $rawDate = (string) ($e['start_at'] ?? '');
       $formattedDate = $rawDate ? (new DateTimeImmutable($rawDate))->format('M d, Y') : 'No Date at 00:00';
       $title = htmlspecialchars((string) ($e['title'] ?? ''));
    ?>
    <div class="group flex items-center justify-between rounded-xl bg-white border border-zinc-200 p-4 hover:border-zinc-300 transition-colors shadow-sm">
        <div class="flex items-center gap-4">
            <div class="w-1.5 h-10 bg-orange-500 rounded-full"></div>
            <div>
                <h4 class="text-[15px] font-bold text-zinc-900 tracking-tight uppercase line-clamp-1 flex items-center gap-2" title="<?= $title ?>">
                    <?= $title ?>
                    <span class="inline-flex py-0.5 px-2 rounded bg-zinc-100 border border-zinc-200 text-[9px] font-bold text-zinc-500 uppercase tracking-widest leading-none">Archived</span>
                </h4>
                <div class="flex items-center gap-1 mt-1 text-[11px] font-medium text-zinc-500">
                    <span>Posted:</span>
                    <span><?= $formattedDate ?></span>
                </div>
            </div>
        </div>
        
        <!-- Menu Button from Manual -->
        <div class="relative group/menu flex-shrink-0">
           <button class="p-2 rounded-lg text-zinc-400 hover:text-orange-600 hover:bg-orange-50 transition-colors">
               <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6.75a.75.75 0 110-1.5.75.75 0 010 1.5zM12 12.75a.75.75 0 110-1.5.75.75 0 010 1.5zM12 18.75a.75.75 0 110-1.5.75.75 0 010 1.5z"/></svg>
           </button>
           <div class="absolute right-0 top-full mt-1 w-32 bg-white rounded-xl shadow-lg border border-zinc-200 opacity-0 invisible group-hover/menu:opacity-100 group-hover/menu:visible transition-all z-20 py-1 overflow-hidden transform origin-top-right translate-y-1 group-hover/menu:translate-y-0">
               <button class="btnRestore w-full text-left px-4 py-2 text-xs font-bold text-zinc-700 hover:bg-emerald-50 hover:text-emerald-700 flex items-center gap-2" data-id="<?= htmlspecialchars($eid) ?>">
                   <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 15L3 9m0 0l6-6M3 9h12a6 6 0 010 12h-3"/></svg>
                   Restore
               </button>
               <button class="w-full text-left px-4 py-2 text-xs font-bold text-red-600 hover:bg-red-50 flex items-center gap-2" onclick="alert('Delete functionality is disabled in mock.')">
                   <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 01-2.244 2.077H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 00-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 013.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 00-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 00-7.5 0"/></svg>
                   Delete
               </button>
           </div>
        </div>
    </div>
  <?php endforeach; ?>

  <?php if (count($events) === 0): ?>
    <div class="rounded-3xl border-2 border-dashed border-zinc-300 bg-zinc-50 py-16 flex flex-col items-center justify-center pointer-events-none">
      <div class="w-16 h-16 rounded-2xl bg-white flex items-center justify-center mb-4 border border-zinc-200 shadow-sm">
        <svg class="w-8 h-8 text-zinc-500" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M20.25 7.5l-.625 10.632a2.25 2.25 0 01-2.247 2.118H6.622a2.25 2.25 0 01-2.247-2.118L3.75 7.5M10 11.25h4M3.375 7.5h17.25c.621 0 1.125-.504 1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125H2.25c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125z"/></svg>
      </div>
      <p class="text-zinc-900 font-bold text-base mb-1">Archive is empty</p>
      <p class="text-zinc-600 text-sm text-center max-w-sm px-4">Deleted events will safely appear here for recovery.</p>
    </div>
  <?php endif; ?>
</div>

<!-- ARCHIVED TEACHERS LIST -->
<div id="panelTeachers" class="pb-10 hidden">
  <div class="bg-white rounded-2xl border border-zinc-200 shadow-sm overflow-hidden">
    <table class="w-full text-left text-sm text-zinc-600">
        <thead class="bg-zinc-50 border-b border-zinc-200/80">
            <tr>
                <th class="px-6 py-4 font-bold text-zinc-900 w-1/4">Name</th>
                <th class="px-6 py-4 font-bold text-zinc-900 w-1/4">Email</th>
                <th class="px-4 py-4 font-bold text-zinc-900">Contact</th>
                <th class="px-4 py-4 font-bold text-zinc-900">Grade Level</th>
                <th class="px-4 py-4 font-bold text-zinc-900">Section</th>
                <th class="px-6 py-4 font-bold text-zinc-900 text-right">Actions</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-zinc-100">
            <tr class="hover:bg-zinc-50 transition-colors">
                <td class="px-6 py-4 font-bold text-zinc-900">Maria Reyes</td>
                <td class="px-6 py-4">maria.reyes@gmail.com</td>
                <td class="px-4 py-4 text-zinc-500 font-mono text-xs">09171234567</td>
                <td class="px-4 py-4 font-semibold text-zinc-800">Grade 7</td>
                <td class="px-4 py-4 text-zinc-500">Pythagoras</td>
                <td class="px-6 py-4 text-right">
                    <button class="mr-2 text-xs font-bold text-sky-600 hover:text-sky-800 border border-sky-600 hover:bg-sky-50 px-3 py-1.5 rounded-lg transition-colors" onclick="alert('MOCK: Restore Teacher Clicked')">Restore Teacher</button>
                    <button class="p-2 -mr-2 rounded-lg text-zinc-400 hover:text-red-600 hover:bg-red-50 transition-colors" title="Delete Permanently" onclick="alert('MOCK: Permanent Delete Clicked')"><svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg></button>
                </td>
            </tr>
        </tbody>
    </table>
  </div>
</div>

<!-- ARCHIVED STUDENTS LIST -->
<div id="panelStudents" class="pb-10 hidden">
  <div class="bg-white rounded-2xl border border-zinc-200 shadow-sm overflow-hidden">
    <table class="w-full text-left text-sm text-zinc-600">
        <thead class="bg-zinc-50 border-b border-zinc-200/80">
            <tr>
                <th class="px-6 py-4 font-bold text-zinc-900 w-1/4">Name</th>
                <th class="px-6 py-4 font-bold text-zinc-900 w-1/4">Email</th>
                <th class="px-4 py-4 font-bold text-zinc-900">Year Level</th>
                <th class="px-4 py-4 font-bold text-zinc-900">Section</th>
                <th class="px-4 py-4 font-bold text-zinc-900">ID Number</th>
                <th class="px-6 py-4 font-bold text-zinc-900 text-right">Actions</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-zinc-100">
            <tr class="hover:bg-zinc-50 transition-colors">
                <td class="px-6 py-4 font-bold text-zinc-900">Dela Cruz, Juan M.</td>
                <td class="px-6 py-4">N/A</td>
                <td class="px-4 py-4 font-semibold text-zinc-800">1</td>
                <td class="px-4 py-4 text-zinc-500">Einstein</td>
                <td class="px-4 py-4 font-mono text-xs text-emerald-600">2018447</td>
                <td class="px-6 py-4 text-right flex items-center justify-end">
                    <button class="mr-2 text-xs font-bold text-sky-600 hover:text-sky-800 border border-sky-600 hover:bg-sky-50 px-3 py-1.5 rounded-lg transition-colors" onclick="alert('MOCK: Restore Student Clicked')">Restore Student</button>
                    <button class="p-2 -mr-2 rounded-lg text-zinc-400 hover:text-red-600 hover:bg-red-50 transition-colors" title="Delete Permanently" onclick="alert('MOCK: Permanent Delete Clicked')"><svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg></button>
                </td>
            </tr>
            <tr class="hover:bg-zinc-50 transition-colors">
                <td class="px-6 py-4 font-bold text-zinc-900">Santos, Mark K.</td>
                <td class="px-6 py-4">N/A</td>
                <td class="px-4 py-4 font-semibold text-zinc-800">1</td>
                <td class="px-4 py-4 text-zinc-500">Euclid</td>
                <td class="px-4 py-4 font-mono text-xs text-emerald-600">2020224</td>
                <td class="px-6 py-4 text-right flex items-center justify-end">
                    <button class="mr-2 text-xs font-bold text-sky-600 hover:text-sky-800 border border-sky-600 hover:bg-sky-50 px-3 py-1.5 rounded-lg transition-colors" onclick="alert('MOCK: Restore Student Clicked')">Restore Student</button>
                    <button class="p-2 -mr-2 rounded-lg text-zinc-400 hover:text-red-600 hover:bg-red-50 transition-colors" title="Delete Permanently" onclick="alert('MOCK: Permanent Delete Clicked')"><svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg></button>
                </td>
            </tr>
        </tbody>
    </table>
  </div>
</div>

<script>
  // Tab Navigation JS
  const btnE = document.getElementById('btnTabEvents');
  const btnT = document.getElementById('btnTabTeachers');
  const btnS = document.getElementById('btnTabStudents');
  
  const spanE = btnE.querySelector('span');
  const spanT = btnT.querySelector('span');
  const spanS = btnS.querySelector('span');
  
  const panE = document.getElementById('panelEvents');
  const panT = document.getElementById('panelTeachers');
  const panS = document.getElementById('panelStudents');
  
  function resetTabs() {
      [btnE, btnT, btnS].forEach(b => {
         b.classList.replace('border-orange-500', 'border-transparent');
         b.classList.replace('text-orange-600', 'text-zinc-500');
      });
      [spanE, spanT, spanS].forEach(s => {
         s.classList.replace('bg-orange-100', 'bg-zinc-100');
         s.classList.replace('text-orange-700', 'text-zinc-600');
      });
      [panE, panT, panS].forEach(p => p.classList.add('hidden'));
  }
  
  btnE.addEventListener('click', () => {
      resetTabs();
      btnE.classList.replace('border-transparent', 'border-orange-500');
      btnE.classList.replace('text-zinc-500', 'text-orange-600');
      spanE.classList.replace('bg-zinc-100', 'bg-orange-100');
      spanE.classList.replace('text-zinc-600', 'text-orange-700');
      panE.classList.remove('hidden');
  });
  
  btnT.addEventListener('click', () => {
      resetTabs();
      btnT.classList.replace('border-transparent', 'border-orange-500');
      btnT.classList.replace('text-zinc-500', 'text-orange-600');
      spanT.classList.replace('bg-zinc-100', 'bg-orange-100');
      spanT.classList.replace('text-zinc-600', 'text-orange-700');
      panT.classList.remove('hidden');
  });
  
  btnS.addEventListener('click', () => {
      resetTabs();
      btnS.classList.replace('border-transparent', 'border-orange-500');
      btnS.classList.replace('text-zinc-500', 'text-orange-600');
      spanS.classList.replace('bg-zinc-100', 'bg-orange-100');
      spanS.classList.replace('text-zinc-600', 'text-orange-700');
      panS.classList.remove('hidden');
  });

  // Events Restore Script
  document.querySelectorAll('.btnRestore').forEach(btn => {
    btn.addEventListener('click', async () => {
      if(!confirm('Restore this event? It will go back to pending status.')) return;
      const event_id = btn.dataset.id;
      btn.disabled = true;
      btn.innerHTML = 'Restoring...';
      try {
        const res = await fetch('/api/events_archive.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ event_id, action: 'restore', csrf_token: window.CSRF_TOKEN })
        });
        const data = await res.json();
        if (!data.ok) throw new Error(data.error || 'Failed');
        
        const card = btn.closest('.group');
        card.style.transform = 'scale(0.95)';
        card.style.opacity = '0';
        setTimeout(() => window.location.reload(), 250);
      } catch (e) {
        alert(e.message || 'Failed');
        btn.innerHTML = '<svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="3" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 15L3 9m0 0l6-6M3 9h12a6 6 0 010 12h-3"/></svg> Restore';
      } finally {
        btn.disabled = false;
      }
    });
  });
</script>

<?php render_footer(); ?>
