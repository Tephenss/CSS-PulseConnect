<?php
declare(strict_types=1);

session_start();

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/supabase.php';
require_once __DIR__ . '/includes/layout.php';

$user = require_role(['admin']);

$url = rtrim(SUPABASE_URL, '/') . '/rest/v1/events?select=id,title,start_at,end_at,status,location&status=neq.archived&order=start_at.asc';

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

render_header('Event Calendar', $user);

$published = 0; $pending = 0; $approved = 0;
foreach($events as $e) {
  if(($e['status']??'')==='published') $published++;
  if(($e['status']??'')==='pending') $pending++;
  if(($e['status']??'')==='approved') $approved++;
}
?>

<div class="mb-8 flex items-center justify-between">
  <div>
    <h2 class="text-xl font-bold text-zinc-900 mb-1">System Calendar</h2>
    <p class="text-zinc-600 text-sm">Visual overview of all active events across the system.</p>
  </div>
</div>

<div class="grid grid-cols-1 md:grid-cols-3 gap-5 mb-6">
  <div class="rounded-2xl border border-zinc-200 bg-white p-5 border-b-[3px] border-b-emerald-500 shadow-sm flex items-center gap-4 relative overflow-hidden group">
     <div class="absolute -right-8 -top-8 w-24 h-24 bg-emerald-400/15 blur-2xl rounded-full pointer-events-none"></div>
     <div class="w-10 h-10 rounded-xl bg-emerald-100 border border-emerald-200 flex items-center justify-center text-emerald-700 z-10">
        <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
     </div>
     <div class="z-10"><div class="text-2xl font-bold text-zinc-900"><?= $published ?></div><div class="text-[10px] text-zinc-600 uppercase tracking-widest font-bold">Published</div></div>
  </div>
  <div class="rounded-2xl border border-zinc-200 bg-white p-5 border-b-[3px] border-b-amber-500 shadow-sm flex items-center gap-4 relative overflow-hidden group">
     <div class="absolute -right-8 -top-8 w-24 h-24 bg-amber-400/15 blur-2xl rounded-full pointer-events-none"></div>
     <div class="w-10 h-10 rounded-xl bg-amber-100 border border-amber-200 flex items-center justify-center text-amber-800 z-10">
        <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2.25m0 0v2.25m0-2.25h2.25m-2.25 0H9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
     </div>
     <div class="z-10"><div class="text-2xl font-bold text-zinc-900"><?= $pending ?></div><div class="text-[10px] text-zinc-600 uppercase tracking-widest font-bold">Pending</div></div>
  </div>
  <div class="rounded-2xl border border-zinc-200 bg-white p-5 border-b-[3px] border-b-sky-500 shadow-sm flex items-center gap-4 relative overflow-hidden group">
     <div class="absolute -right-8 -top-8 w-24 h-24 bg-sky-400/15 blur-2xl rounded-full pointer-events-none"></div>
     <div class="w-10 h-10 rounded-xl bg-sky-100 border border-sky-200 flex items-center justify-center text-sky-700 z-10">
        <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/></svg>
     </div>
     <div class="z-10"><div class="text-2xl font-bold text-zinc-900"><?= $approved ?></div><div class="text-[10px] text-zinc-600 uppercase tracking-widest font-bold">Approved</div></div>
  </div>
</div>

<div class="rounded-2xl border border-zinc-200 bg-white overflow-hidden shadow-sm">
  <div class="flex items-center justify-between p-5 border-b border-zinc-200 bg-zinc-50/80">
    <div class="flex items-center gap-3">
      <button id="prevMonth" class="p-2.5 rounded-xl bg-white hover:bg-orange-50 text-zinc-600 hover:text-orange-800 transition-colors border border-zinc-200 hover:border-orange-200">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5L8.25 12l7.5-7.5"/></svg>
      </button>
      <h2 id="currentMonthYear" class="text-base font-bold text-zinc-900 min-w-[150px] text-center tracking-tight"></h2>
      <button id="nextMonth" class="p-2.5 rounded-xl bg-white hover:bg-orange-50 text-zinc-600 hover:text-orange-800 transition-colors border border-zinc-200 hover:border-orange-200">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5"/></svg>
      </button>
    </div>
    <button id="todayBtn" class="px-5 py-2.5 font-bold rounded-xl bg-orange-600 text-white border border-orange-600 hover:bg-orange-700 transition-all text-xs tracking-wider uppercase shadow-sm">Today</button>
  </div>

  <div class="w-full bg-zinc-100 border-b border-zinc-200 grid grid-cols-7 gap-px">
    <div class="py-3.5 text-center text-[10px] font-bold text-zinc-600 bg-zinc-100 uppercase tracking-widest">Sun</div>
    <div class="py-3.5 text-center text-[10px] font-bold text-zinc-600 bg-zinc-100 uppercase tracking-widest">Mon</div>
    <div class="py-3.5 text-center text-[10px] font-bold text-zinc-600 bg-zinc-100 uppercase tracking-widest">Tue</div>
    <div class="py-3.5 text-center text-[10px] font-bold text-zinc-600 bg-zinc-100 uppercase tracking-widest">Wed</div>
    <div class="py-3.5 text-center text-[10px] font-bold text-zinc-600 bg-zinc-100 uppercase tracking-widest">Thu</div>
    <div class="py-3.5 text-center text-[10px] font-bold text-zinc-600 bg-zinc-100 uppercase tracking-widest">Fri</div>
    <div class="py-3.5 text-center text-[10px] font-bold text-zinc-600 bg-zinc-100 uppercase tracking-widest">Sat</div>
  </div>
  
  <div id="calendarGrid" class="w-full bg-zinc-200 grid grid-cols-7 gap-px">
    <!-- Days inserted dynamically -->
  </div>
</div>

<!-- Tooltip element -->
<div id="eventTooltip" class="fixed z-50 bg-white border border-zinc-200 rounded-xl p-4 w-72 shadow-lg pointer-events-none opacity-0 transition-opacity duration-200">
  <div class="text-sm font-bold text-zinc-900 mb-2" id="ttTitle"></div>
  <div class="flex items-center gap-2 text-xs text-zinc-600 mb-2">
    <svg class="w-4 h-4 text-orange-600" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
    <span id="ttDate"></span>
  </div>
  <div class="flex items-center gap-2 text-xs text-zinc-600 hidden" id="ttLocWrap">
    <svg class="w-4 h-4 text-emerald-600" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M15 10.5a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1115 0z"/></svg>
    <span id="ttLoc"></span>
  </div>
</div>

<script>
  const eventsData = <?= json_encode($events) ?>;
  const tooltip = document.getElementById('eventTooltip');
  
  function getStatusStyle(status) {
    switch(status) {
      case 'published': return 'bg-emerald-100 text-emerald-900 border-emerald-200 hover:border-emerald-300 hover:bg-emerald-50';
      case 'pending': return 'bg-amber-100 text-amber-900 border-amber-200 hover:border-amber-300 hover:bg-amber-50';
      case 'approved': return 'bg-sky-100 text-sky-900 border-sky-200 hover:border-sky-300 hover:bg-sky-50';
      default: return 'bg-zinc-100 text-zinc-800 border-zinc-200 hover:border-zinc-300 hover:bg-zinc-50';
    }
  }

  function formatDateRange(startISO, endISO) {
    if (!startISO) return '';
    const d1 = new Date(startISO);
    let str = d1.toLocaleDateString('en-US', {month:'short', day:'numeric'}) + ' · ' + d1.toLocaleTimeString('en-US',{hour:'numeric',minute:'2-digit'});
    if (endISO) {
       const d2 = new Date(endISO);
       if (d1.getDate() === d2.getDate() && d1.getMonth() === d2.getMonth()) {
          str += ' - ' + d2.toLocaleTimeString('en-US',{hour:'numeric',minute:'2-digit'});
       } else {
          str += ' to ' + d2.toLocaleDateString('en-US', {month:'short', day:'numeric'}) + ' ' + d2.toLocaleTimeString('en-US',{hour:'numeric',minute:'2-digit'});
       }
    }
    return str;
  }

  let currentDate = new Date();
  
  function renderCalendar() {
    const year = currentDate.getFullYear();
    const month = currentDate.getMonth();
    
    document.getElementById('currentMonthYear').textContent = new Intl.DateTimeFormat('en-US', { month: 'long', year: 'numeric' }).format(currentDate);
    
    const firstDay = new Date(year, month, 1).getDay();
    const daysInMonth = new Date(year, month + 1, 0).getDate();
    const daysInPrevMonth = new Date(year, month, 0).getDate();
    
    const grid = document.getElementById('calendarGrid');
    grid.innerHTML = '';
    
    // Prev Month Trailing Days
    for (let i = firstDay - 1; i >= 0; i--) {
      const cell = document.createElement('div');
      cell.className = 'min-h-[120px] bg-zinc-50 p-1.5 flex flex-col gap-1 transition-colors';
      cell.innerHTML = `<span class="text-xs font-semibold text-zinc-400 ml-1.5 mt-1 block">${daysInPrevMonth - i}</span>`;
      grid.appendChild(cell);
    }
    
    // Current Month Days
    for (let i = 1; i <= daysInMonth; i++) {
      const cell = document.createElement('div');
      const isToday = i === new Date().getDate() && month === new Date().getMonth() && year === new Date().getFullYear();
      
      cell.className = 'min-h-[120px] bg-white hover:bg-zinc-50 p-1.5 flex flex-col gap-1.5 transition-colors relative group';
      
      const headerClasses = isToday 
        ? 'w-7 h-7 rounded-full bg-orange-600 text-white flex items-center justify-center font-bold shadow-[0_0_12px_-2px_rgba(139,92,246,0.6)] text-xs ml-0.5 mt-0.5' 
        : 'text-xs font-semibold text-zinc-700 ml-2 mt-1 block group-hover:text-zinc-900 transition-colors';
      
      const dayLabel = document.createElement('span');
      dayLabel.className = headerClasses;
      dayLabel.textContent = i;
      cell.appendChild(dayLabel);
      
      const dayEvents = eventsData.filter(e => {
        if(!e.start_at) return false;
        const eDate = new Date(e.start_at);
        return eDate.getDate() === i && eDate.getMonth() === month && eDate.getFullYear() === year;
      });
      
      const eventContainer = document.createElement('div');
      eventContainer.className = 'flex flex-wrap items-center justify-center gap-1.5 px-1 mt-auto pb-2 h-8';
      
      dayEvents.forEach(evt => {
        const evEl = document.createElement('div');
        
        let dotColor = 'bg-zinc-400';
        if(evt.status === 'published') dotColor = 'bg-emerald-500';
        else if(evt.status === 'pending') dotColor = 'bg-amber-500';
        else if(evt.status === 'approved') dotColor = 'bg-sky-500';

        evEl.className = `w-2 h-2 rounded-full ${dotColor} shadow-[0_2px_4px_-1px_rgba(0,0,0,0.1)] cursor-pointer transition-transform hover:scale-150`;
        
        // Tooltip logic
        evEl.addEventListener('mouseenter', (e) => {
           document.getElementById('ttTitle').textContent = evt.title;
           document.getElementById('ttDate').textContent = formatDateRange(evt.start_at, evt.end_at);
           if (evt.location) {
              document.getElementById('ttLoc').textContent = evt.location;
              document.getElementById('ttLocWrap').classList.remove('hidden');
           } else {
              document.getElementById('ttLocWrap').classList.add('hidden');
           }
           const rect = evEl.getBoundingClientRect();
           tooltip.style.left = Math.min(rect.left + window.scrollX - 100, window.innerWidth - 300) + 'px';
           tooltip.style.top = (rect.top + window.scrollY - tooltip.offsetHeight - 10) + 'px';
           tooltip.classList.remove('opacity-0');
           tooltip.classList.add('opacity-100');
        });
        evEl.addEventListener('mouseleave', () => {
           tooltip.classList.add('opacity-0');
           tooltip.classList.remove('opacity-100');
        });
        
        evEl.onclick = (e) => {
             e.stopPropagation();
             window.location.href = `/manage_events.php`;
        };
        eventContainer.appendChild(evEl);
      });
      
      cell.onclick = () => { if(dayEvents.length > 0) window.location.href = `/manage_events.php`; };
      
      cell.appendChild(eventContainer);
      grid.appendChild(cell);
    }
    
    // Next Month Leading Days
    const totalCells = firstDay + daysInMonth;
    const remainingSlots = (7 - (totalCells % 7)) % 7;
    for (let i = 1; i <= remainingSlots; i++) {
      const cell = document.createElement('div');
      cell.className = 'min-h-[120px] bg-zinc-50 p-1.5 flex flex-col gap-1 transition-colors';
      cell.innerHTML = `<span class="text-xs font-semibold text-zinc-400 ml-1.5 mt-1 block">${i}</span>`;
      grid.appendChild(cell);
    }
  }

  document.getElementById('prevMonth').addEventListener('click', () => { currentDate.setMonth(currentDate.getMonth() - 1); renderCalendar(); });
  document.getElementById('nextMonth').addEventListener('click', () => { currentDate.setMonth(currentDate.getMonth() + 1); renderCalendar(); });
  document.getElementById('todayBtn').addEventListener('click', () => { currentDate = new Date(); renderCalendar(); });

  renderCalendar();
</script>

<style>
.no-scrollbar::-webkit-scrollbar { display: none; }
.no-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }
</style>

<?php render_footer(); ?>
