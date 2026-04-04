<?php
declare(strict_types=1);

session_start();

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/supabase.php';
require_once __DIR__ . '/includes/layout.php';

$user = require_role(['admin']);

// Fetch only published events
$url = rtrim(SUPABASE_URL, '/') . '/rest/v1/events?select=id,title,start_at,end_at,status,location&status=eq.published&order=start_at.asc';

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
?>

<div class="mb-8 flex items-center justify-between">
  <div>
    <h2 class="text-xl font-bold text-zinc-900 mb-1">Student Calendar</h2>
    <p class="text-zinc-600 text-sm">View and track all upcoming published school events.</p>
  </div>
</div>

<!-- Upcoming Events Summary -->
<?php
  $upcoming = array_filter($events, function($e) {
    return isset($e['start_at']) && strtotime($e['start_at']) >= time();
  });
  $upcoming = array_slice(array_values($upcoming), 0, 3);
?>
<?php if (count($upcoming) > 0): ?>
<div class="mb-5 flex items-center justify-between">
  <h3 class="text-sm font-semibold text-zinc-800">Upcoming Soon</h3>
</div>
<div class="grid grid-cols-1 md:grid-cols-3 gap-5 mb-8">
  <?php foreach ($upcoming as $i => $ue): ?>
    <?php
       // Format Date properly
       $rawDate = (string) ($ue['start_at'] ?? '');
       $formattedDate = $rawDate ? (new DateTimeImmutable($rawDate))->format('M d, Y · g:i A') : 'TBA';
       $colors = [
           ['bg' => 'bg-orange-100', 'border' => 'border-orange-200', 'text' => 'text-orange-800', 'accent' => 'border-b-orange-500'],
           ['bg' => 'bg-emerald-100', 'border' => 'border-emerald-200', 'text' => 'text-emerald-800', 'accent' => 'border-b-emerald-500'],
           ['bg' => 'bg-sky-100', 'border' => 'border-sky-200', 'text' => 'text-sky-800', 'accent' => 'border-b-sky-500']
       ];
       $c = $colors[$i % 3];
    ?>
    <a href="/events.php" class="relative group block rounded-2xl border border-zinc-200 bg-white p-5 border-b-[3px] shadow-sm <?= $c['accent'] ?> hover:-translate-y-0.5 hover:shadow-md transition-all duration-300">
      <div class="flex items-start gap-3.5">
        <div class="w-10 h-10 rounded-xl <?= $c['bg'] ?> <?= $c['border'] ?> border flex items-center justify-center flex-shrink-0">
          <svg class="w-5 h-5 <?= $c['text'] ?>" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
        </div>
        <div class="min-w-0">
          <div class="text-sm font-bold text-zinc-900 truncate group-hover:text-orange-900 transition-colors"><?= htmlspecialchars($ue['title'] ?? '') ?></div>
          <div class="text-[11px] font-medium text-zinc-600 mt-1"><?= htmlspecialchars($formattedDate) ?></div>
        </div>
      </div>
    </a>
  <?php endforeach; ?>
</div>
<?php endif; ?>

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
    // Only published events are loaded for students, so everything is usually green
    return 'bg-emerald-100 text-emerald-900 border-emerald-200 hover:border-emerald-300 hover:bg-emerald-50';
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
      eventContainer.className = 'flex flex-col gap-1.5 px-0.5 overflow-y-auto no-scrollbar pb-1 max-h-[80px]';
      
      dayEvents.forEach(evt => {
        const evEl = document.createElement('div');
        evEl.className = `text-[10px] sm:text-xs font-semibold px-2 py-1.5 rounded-lg border flex items-center gap-1.5 cursor-pointer truncate transition-all duration-200 ${getStatusStyle(evt.status)}`;
        evEl.innerHTML = `<span class="w-1.5 h-1.5 rounded-full bg-current flex-shrink-0"></span> <span class="truncate">${evt.title}</span>`;
        
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
           tooltip.style.left = Math.min(rect.left + window.scrollX, window.innerWidth - 300) + 'px';
           tooltip.style.top = (rect.bottom + window.scrollY + 8) + 'px';
           tooltip.classList.remove('opacity-0');
        });
        evEl.addEventListener('mouseleave', () => tooltip.classList.add('opacity-0'));
        
        evEl.onclick = () => window.location.href = `/events.php`; // Students go to global events page
        eventContainer.appendChild(evEl);
      });
      
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
