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

// Separate regular archive from rejected proposals
$rejectedEvents = [];
$regularArchived = [];
foreach ($events as $e) {
    if (str_contains((string)($e['description'] ?? ''), '[REJECT_REASON:')) {
        $rejectedEvents[] = $e;
    } else {
        $regularArchived[] = $e;
    }
}

$urlSec = rtrim(SUPABASE_URL, '/') . '/rest/v1/sections?select=id,name&status=eq.archived&order=name.asc';
$sections = [];
$resSec = supabase_request('GET', $urlSec, $headers);
if ($resSec['ok']) {
    $decodedSec = json_decode((string) $resSec['body'], true);
    $sections = is_array($decodedSec) ? $decodedSec : [];
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
        <span class="bg-orange-100 text-orange-700 text-[10px] font-black px-2 py-0.5 rounded-full border border-orange-200 group-hover:bg-orange-200 transition-colors"><?= count($regularArchived) ?></span>
    </button>
    <button id="btnTabRejected" class="pb-3 border-b-2 border-transparent font-bold text-zinc-500 hover:text-zinc-800 text-[13px] transition-colors whitespace-nowrap px-1 group flex items-center gap-2">
        Rejected
        <span class="bg-zinc-100 text-zinc-600 border border-zinc-200 text-[10px] font-black px-2 py-0.5 rounded-full group-hover:bg-zinc-200 transition-colors"><?= count($rejectedEvents) ?></span>
    </button>
    <button id="btnTabTeachers" class="pb-3 border-b-2 border-transparent font-bold text-zinc-500 hover:text-zinc-800 text-[13px] transition-colors whitespace-nowrap px-1 group flex items-center gap-2">
        Teachers
        <span class="bg-zinc-100 text-zinc-600 border border-zinc-200 text-[10px] font-black px-2 py-0.5 rounded-full group-hover:bg-zinc-200 transition-colors">1</span>
    </button>
    <button id="btnTabStudents" class="pb-3 border-b-2 border-transparent font-bold text-zinc-500 hover:text-zinc-800 text-[13px] transition-colors whitespace-nowrap px-1 group flex items-center gap-2">
        Students
        <span class="bg-zinc-100 text-zinc-600 border border-zinc-200 text-[10px] font-black px-2 py-0.5 rounded-full group-hover:bg-zinc-200 transition-colors">2</span>
    </button>
    <button id="btnTabSections" class="pb-3 border-b-2 border-transparent font-bold text-zinc-500 hover:text-zinc-800 text-[13px] transition-colors whitespace-nowrap px-1 group flex items-center gap-2">
        Sections
        <span class="bg-zinc-100 text-zinc-600 border border-zinc-200 text-[10px] font-black px-2 py-0.5 rounded-full group-hover:bg-zinc-200 transition-colors"><?= count($sections) ?></span>
    </button>
</div>

<!-- ARCHIVED EVENTS LIST -->
<div id="panelEvents" class="flex flex-col gap-3 pb-10">
  <?php foreach ($regularArchived as $e): ?>
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

  <?php if (count($regularArchived) === 0): ?>
    <div class="rounded-3xl border-2 border-dashed border-zinc-300 bg-zinc-50 py-16 flex flex-col items-center justify-center pointer-events-none">
      <div class="w-16 h-16 rounded-2xl bg-white flex items-center justify-center mb-4 border border-zinc-200 shadow-sm">
        <svg class="w-8 h-8 text-zinc-500" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M20.25 7.5l-.625 10.632a2.25 2.25 0 01-2.247 2.118H6.622a2.25 2.25 0 01-2.247-2.118L3.75 7.5M10 11.25h4M3.375 7.5h17.25c.621 0 1.125-.504 1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125H2.25c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125z"/></svg>
      </div>
      <p class="text-zinc-900 font-bold text-base mb-1">Archive is empty</p>
      <p class="text-zinc-600 text-sm text-center max-w-sm px-4">Deleted events will safely appear here for recovery.</p>
    </div>
  <?php endif; ?>
</div>

<!-- REJECTED PROPOSALS LIST -->
<div id="panelRejected" class="flex flex-col gap-3 pb-10 hidden">
  <?php foreach ($rejectedEvents as $e): ?>
    <?php 
       $eid = (string) ($e['id'] ?? ''); 
       $rawDate = (string) ($e['start_at'] ?? '');
       $formattedDate = $rawDate ? (new DateTimeImmutable($rawDate))->format('M d, Y') : 'No Date';
       $title = htmlspecialchars((string) ($e['title'] ?? ''));
       
       $desc = (string)($e['description'] ?? '');
       $reason = 'No reason provided';
       if (preg_match('/\[REJECT_REASON: (.*?)\]/', $desc, $matches)) {
           $reason = htmlspecialchars($matches[1]);
       }
    ?>
    <div class="group flex flex-col rounded-xl bg-white border border-zinc-200 overflow-hidden hover:border-red-200 transition-colors shadow-sm">
        <div class="flex items-center justify-between p-4 bg-zinc-50/50 border-b border-zinc-100">
            <div class="flex items-center gap-3">
                <div class="w-1.5 h-10 bg-red-500 rounded-full"></div>
                <div>
                    <h4 class="text-[15px] font-bold text-zinc-900 tracking-tight uppercase line-clamp-1" title="<?= $title ?>"><?= $title ?></h4>
                    <div class="flex items-center gap-1.5 text-[10px] font-bold text-red-600 uppercase tracking-wider mt-0.5">
                        <svg class="w-3 h-3" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                        Rejected Proposal
                    </div>
                </div>
            </div>
            
            <button class="btnRestore p-2 rounded-lg text-zinc-400 hover:text-emerald-600 hover:bg-emerald-50 transition-colors flex items-center gap-1.5 text-[11px] font-bold" data-id="<?= htmlspecialchars($eid) ?>">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 15L3 9m0 0l6-6M3 9h12a6 6 0 010 12h-3"/></svg>
                Restore
            </button>
        </div>
        <div class="p-4">
            <div class="text-[11px] font-black text-zinc-400 uppercase tracking-widest mb-1.5">Admin Remark</div>
            <div class="bg-red-50 border border-red-100 rounded-lg p-3 text-sm text-red-800 leading-relaxed italic">
                "<?= $reason ?>"
            </div>
            <div class="mt-3 flex items-center gap-3 text-[11px] text-zinc-500 font-medium">
                <span class="flex items-center gap-1"><svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z"/></svg> Event: <?= $formattedDate ?></span>
                <span class="flex items-center gap-1"><svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M15 10.5a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1115 0z"/></svg> <?= htmlspecialchars((string)($e['location'] ?? 'N/A')) ?></span>
            </div>
        </div>
    </div>
  <?php endforeach; ?>

  <?php if (count($rejectedEvents) === 0): ?>
    <div class="rounded-3xl border-2 border-dashed border-zinc-300 bg-zinc-50 py-16 flex flex-col items-center justify-center pointer-events-none">
      <div class="w-16 h-16 rounded-2xl bg-white flex items-center justify-center mb-4 border border-zinc-200 shadow-sm">
        <svg class="w-8 h-8 text-zinc-400" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
      </div>
      <p class="text-zinc-900 font-bold text-base mb-1">No rejected proposals</p>
      <p class="text-zinc-600 text-sm text-center max-w-sm px-4">Events you reject will appear here for reference or recovery.</p>
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

<!-- ARCHIVED SECTIONS LIST -->
<div id="panelSections" class="pb-10 hidden">
  <div class="bg-white rounded-2xl border border-zinc-200 shadow-sm overflow-hidden">
    <table class="w-full text-left text-sm text-zinc-600">
        <thead class="bg-zinc-50 border-b border-zinc-200/80">
            <tr>
                <th class="px-6 py-4 font-bold text-zinc-900 w-1/4">Program</th>
                <th class="px-4 py-4 font-bold text-zinc-900 w-1/4">Block</th>
                <th class="px-4 py-4 font-bold text-zinc-900 w-1/4">Year Level</th>
                <th class="px-6 py-4 font-bold text-zinc-900 text-right w-1/4">Actions</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-zinc-100">
            <?php foreach ($sections as $s): ?>
                <?php
                $sid = (string)($s['id'] ?? '');
                $rawName = trim((string)($s['name'] ?? ''));
                $yearLevel = 'N/A';
                $program = 'N/A';
                $block = 'N/A';

                if (preg_match('/^(BSIT SD|BSIT BA|BSCS|BSIT)\s*(\d)([A-Z])$/i', $rawName, $m)) {
                    $program = strtoupper($m[1]);
                    $lvl = $m[2];
                    $suffix = ($lvl == '1') ? 'st' : (($lvl == '2') ? 'nd' : (($lvl == '3') ? 'rd' : 'th'));
                    $yearLevel = $lvl . $suffix . ' Year';
                    $block = 'Block ' . strtoupper($m[3]);
                } else {
                    $program = $rawName;
                }
                ?>
                <tr class="hover:bg-zinc-50 transition-colors" id="archSec-<?= htmlspecialchars($sid) ?>">
                    <td class="px-6 py-4 font-bold text-zinc-900"><?= htmlspecialchars($program) ?></td>
                    <td class="px-4 py-4 font-semibold text-zinc-800"><?= htmlspecialchars($block) ?></td>
                    <td class="px-4 py-4 text-zinc-500"><?= htmlspecialchars($yearLevel) ?></td>
                    <td class="px-6 py-4 text-right flex items-center justify-end">
                        <button class="btnRestoreSection mr-2 text-xs font-bold text-sky-600 hover:text-sky-800 border border-sky-600 hover:bg-sky-50 px-3 py-1.5 rounded-lg transition-colors" data-id="<?= htmlspecialchars($sid) ?>">Restore Section</button>
                        <button class="btnHardDeleteSection p-2 -mr-2 rounded-lg text-zinc-400 hover:text-red-600 hover:bg-red-50 transition-colors" title="Delete Permanently" data-id="<?= htmlspecialchars($sid) ?>"><svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg></button>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (count($sections) === 0): ?>
            <tr>
                <td colspan="4" class="px-6 py-8 text-center text-zinc-500">No archived sections available.</td>
            </tr>
            <?php endif; ?>
        </tbody>
    </table>
  </div>
</div>

<script>
  // Tab Navigation JS
  const btnE = document.getElementById('btnTabEvents');
  const btnRejected = document.getElementById('btnTabRejected');
  const btnT = document.getElementById('btnTabTeachers');
  const btnS = document.getElementById('btnTabStudents');
  const btnSec = document.getElementById('btnTabSections');
  
  const spanE = btnE.querySelector('span');
  const spanRejected = btnRejected.querySelector('span');
  const spanT = btnT.querySelector('span');
  const spanS = btnS.querySelector('span');
  const spanSec = btnSec.querySelector('span');
  
  const panE = document.getElementById('panelEvents');
  const panRejected = document.getElementById('panelRejected');
  const panT = document.getElementById('panelTeachers');
  const panS = document.getElementById('panelStudents');
  const panSec = document.getElementById('panelSections');
  
  function resetTabs() {
      [btnE, btnRejected, btnT, btnS, btnSec].forEach(b => {
         b.classList.replace('border-orange-500', 'border-transparent');
         b.classList.replace('text-orange-600', 'text-zinc-500');
      });
      [spanE, spanRejected, spanT, spanS, spanSec].forEach(s => {
         s.classList.replace('bg-orange-100', 'bg-zinc-100');
         s.classList.replace('text-orange-700', 'text-zinc-600');
      });
      [panE, panRejected, panT, panS, panSec].forEach(p => p.classList.add('hidden'));
  }
  
  btnE.addEventListener('click', () => {
      resetTabs();
      btnE.classList.replace('border-transparent', 'border-orange-500');
      btnE.classList.replace('text-zinc-500', 'text-orange-600');
      spanE.classList.replace('bg-zinc-100', 'bg-orange-100');
      spanE.classList.replace('text-zinc-600', 'text-orange-700');
      panE.classList.remove('hidden');
  });

  btnRejected.addEventListener('click', () => {
      resetTabs();
      btnRejected.classList.replace('border-transparent', 'border-orange-500');
      btnRejected.classList.replace('text-zinc-500', 'text-orange-600');
      spanRejected.classList.replace('bg-zinc-100', 'bg-orange-100');
      spanRejected.classList.replace('text-zinc-600', 'text-orange-700');
      panRejected.classList.remove('hidden');
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
  
  btnSec.addEventListener('click', () => {
      resetTabs();
      btnSec.classList.replace('border-transparent', 'border-orange-500');
      btnSec.classList.replace('text-zinc-500', 'text-orange-600');
      spanSec.classList.replace('bg-zinc-100', 'bg-orange-100');
      spanSec.classList.replace('text-zinc-600', 'text-orange-700');
      panSec.classList.remove('hidden');
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

  // Sections Restore/Hard Delete Script
  document.querySelectorAll('.btnRestoreSection').forEach(btn => {
    btn.addEventListener('click', async () => {
      if(!confirm('Restore this section to the active list?')) return;
      const id = btn.dataset.id;
      btn.disabled = true;
      btn.textContent = 'Restoring...';
      try {
        const res = await fetch('/api/sections_manage.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ id, action: 'restore', csrf_token: window.CSRF_TOKEN })
        });
        const data = await res.json();
        if (!data.ok) throw new Error(data.error || 'Failed');
        const countSpan = document.querySelector('#btnTabSections span');
        if (countSpan) countSpan.textContent = Math.max(0, parseInt(countSpan.textContent) - 1);
        btn.closest('tr').remove();
      } catch (e) {
        alert(e.message || 'Failed to restore section');
        btn.textContent = 'Restore Section';
      } finally {
        btn.disabled = false;
      }
    });
  });

  document.querySelectorAll('.btnHardDeleteSection').forEach(btn => {
    btn.addEventListener('click', async () => {
      if(!confirm('Permanently delete this section? This cannot be undone and may fail if it is in use.')) return;
      const id = btn.dataset.id;
      btn.disabled = true;
      try {
        const res = await fetch('/api/sections_manage.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ id, action: 'hard_delete', csrf_token: window.CSRF_TOKEN })
        });
        const data = await res.json();
        if (!data.ok) throw new Error(data.error || 'Failed');
        const countSpan = document.querySelector('#btnTabSections span');
        if (countSpan) countSpan.textContent = Math.max(0, parseInt(countSpan.textContent) - 1);
        btn.closest('tr').remove();
      } catch (e) {
        alert(e.message || 'Failed to delete section');
      } finally {
        btn.disabled = false;
      }
    });
  });
</script>

<?php render_footer(); ?>
