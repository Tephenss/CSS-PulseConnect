<?php
declare(strict_types=1);

session_start();

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/supabase.php';
require_once __DIR__ . '/includes/layout.php';

$user = require_role(['student', 'teacher', 'admin']);
$role = (string) ($user['role'] ?? 'student');

$id = isset($_GET['id']) ? (string) $_GET['id'] : '';
if ($id === '') {
    http_response_code(400);
    echo 'Missing event id';
    exit;
}

// 1. Fetch Event Document
$url = rtrim(SUPABASE_URL, '/') . '/rest/v1/events?select=id,title,description,location,start_at,end_at,status&'
    . 'id=eq.' . rawurlencode($id) . '&limit=1';
$headers = [
    'Accept: application/json',
    'apikey: ' . SUPABASE_KEY,
    'Authorization: Bearer ' . SUPABASE_KEY,
];
$res = supabase_request('GET', $url, $headers);
$rows = $res['ok'] ? json_decode((string) $res['body'], true) : null;
$event = is_array($rows) && isset($rows[0]) ? $rows[0] : null;

if (!is_array($event)) {
    http_response_code(404);
    echo 'Event not found';
    exit;
}

if ($role === 'student' && (string) ($event['status'] ?? '') !== 'published') {
    http_response_code(403);
    echo 'Forbidden';
    exit;
}

// 2. Fetch Participants to compute statistics
$partUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/event_participants?select=id,user_id,status&event_id=eq.' . rawurlencode($id);
$partRes = supabase_request('GET', $partUrl, $headers);
$participants = $partRes['ok'] ? json_decode((string) $partRes['body'], true) : [];

$totalRegistered = count(is_array($participants) ? $participants : []);
$completedCount = 0;
$nonCompletedCount = 0;

if (is_array($participants)) {
    foreach ($participants as $p) {
        if (($p['status'] ?? '') === 'completed') {
            $completedCount++;
        } else {
            $nonCompletedCount++;
        }
    }
}

// Map the Supabase status to the "Allow Registration" UI Toggle 
// If published = ON, if pending/draft/archived = OFF.
$status = (string)($event['status'] ?? '');
$isRegistrationAllowed = ($status === 'published');

$statusColor = match($status) {
    'published' => 'bg-emerald-100 text-emerald-900 border-emerald-200',
    'pending' => 'bg-amber-100 text-amber-900 border-amber-200',
    'approved' => 'bg-sky-100 text-sky-900 border-sky-200',
    default => 'bg-zinc-100 text-zinc-800 border-zinc-200',
};

// Start outputting UI
render_header('Event Details', $user);
?>

<div class="mb-4">
    <!-- Back Button & Header Row -->
    <div class="flex items-center justify-between flex-wrap gap-4 pb-4 border-b border-zinc-200 mb-6">
        <div class="flex items-center gap-3">
            <a href="/manage_events.php" class="flex items-center justify-center w-8 h-8 rounded-full bg-white border border-zinc-200 hover:bg-zinc-50 text-zinc-600 transition shadow-sm">
                <svg class="w-4 h-4 mr-0.5" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5L8.25 12l7.5-7.5"/></svg>
            </a>
            <h2 class="text-xl md:text-2xl font-bold text-zinc-900"><?= htmlspecialchars((string) ($event['title'] ?? '')) ?></h2>
            <span class="text-[10px] sm:text-xs font-bold uppercase tracking-widest rounded-md border px-2 py-0.5 <?= $statusColor ?>"><?= htmlspecialchars($status) ?></span>
        </div>
        
        <?php if ($role === 'admin' || $role === 'teacher'): ?>
        <div class="flex items-center gap-2">
            <button id="btnSendCert" class="rounded-xl border border-emerald-500 bg-emerald-50 text-emerald-700 font-bold px-4 py-2 text-[13px] hover:bg-emerald-100 transition shadow-sm relative overflow-hidden">
                <span class="relative z-10">Send Certificate</span>
            </button>
            <a href="/certificate_admin.php?event_id=<?= htmlspecialchars($id) ?>" class="rounded-xl border border-zinc-300 bg-white text-zinc-700 font-bold px-4 py-2 text-[13px] hover:bg-zinc-50 transition shadow-sm">
                Create Certificate
            </a>
            <button id="btnEditEventTop" class="flex items-center gap-1.5 rounded-xl bg-orange-600 text-white font-bold px-4 py-2 text-[13px] hover:bg-orange-700 transition shadow-sm"
                    data-id="<?= htmlspecialchars((string) ($event['id'] ?? '')) ?>"
                    data-title="<?= htmlspecialchars((string) ($event['title'] ?? '')) ?>"
                    data-location="<?= htmlspecialchars((string) ($event['location'] ?? '')) ?>"
                    data-description="<?= htmlspecialchars((string) ($event['description'] ?? '')) ?>"
                    data-start_at="<?= htmlspecialchars((string) ($event['start_at'] ?? '')) ?>"
                    data-end_at="<?= htmlspecialchars((string) ($event['end_at'] ?? '')) ?>"
            >
                <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L6.832 19.82a4.5 4.5 0 01-1.897 1.13l-2.685.8.8-2.685a4.5 4.5 0 011.13-1.897L16.863 4.487zm0 0L19.5 7.125"/></svg>
                Edit Event
            </button>
        </div>
        <?php endif; ?>
    </div>

    <!-- Layout Grid: Left Sidebar & Main Content -->
    <div class="flex flex-col xl:flex-row gap-6">
        
        <!-- MAIN CONTENT AREA (Tabs) -->
        <div class="flex-1 min-w-0">
            <!-- TABS NAVIGATION -->
            <div class="border-b border-zinc-200 mb-6">
                <nav class="-mb-px flex space-x-6 overflow-x-auto" aria-label="Tabs">
                    <a href="/event_view.php?id=<?= htmlspecialchars($id) ?>" class="border-orange-500 text-orange-600 whitespace-nowrap border-b-2 py-3 px-1 text-sm font-bold">
                        Event Details
                    </a>
                    <a href="/participants.php?event_id=<?= htmlspecialchars($id) ?>" class="border-transparent text-zinc-500 hover:border-zinc-300 hover:text-zinc-700 whitespace-nowrap border-b-2 py-3 px-1 text-sm font-semibold transition">
                        Event Participants
                    </a>
                    <a href="/evaluation_admin.php?event_id=<?= htmlspecialchars($id) ?>" class="border-transparent text-zinc-500 hover:border-zinc-300 hover:text-zinc-700 whitespace-nowrap border-b-2 py-3 px-1 text-sm font-semibold transition">
                        Event Feedback
                    </a>
                    <a href="/evaluation_admin.php?event_id=<?= htmlspecialchars($id) ?>" class="border-transparent text-zinc-500 hover:border-zinc-300 hover:text-zinc-700 whitespace-nowrap border-b-2 py-3 px-1 text-sm font-semibold transition">
                        Evaluation Questions
                    </a>
                </nav>
            </div>

            <!-- Tab Content: Details -->
            <div class="rounded-2xl border border-zinc-200 bg-white p-6 md:p-8 shadow-sm relative overflow-hidden">
                <div class="absolute top-0 right-0 w-64 h-64 bg-emerald-500/5 rounded-full blur-3xl rounded-tl-full pointer-events-none"></div>
                
                <h3 class="text-sm font-black text-zinc-400 uppercase tracking-widest mb-4">Event Description</h3>
                
                <?php if (!empty($event['description'])): ?>
                    <div class="text-[15px] text-zinc-700 leading-relaxed max-w-4xl font-medium">
                        <?= nl2br(htmlspecialchars((string) ($event['description'] ?? ''))) ?>
                    </div>
                <?php else: ?>
                    <div class="text-sm text-zinc-500 italic">No description provided for this event.</div>
                 <?php endif; ?>

                 <h3 class="text-sm font-black text-zinc-400 uppercase tracking-widest mt-8 mb-4">Event Schedule & Info</h3>
                 <div class="grid grid-cols-1 md:grid-cols-2 gap-4 max-w-3xl">
                    <div class="rounded-xl bg-zinc-50/50 border border-zinc-200 p-4">
                        <div class="text-xs text-zinc-500 font-bold mb-1">Start Date & Time</div>
                        <div class="text-[15px] font-bold text-zinc-900"><?= (new DateTimeImmutable((string)($event['start_at'] ?? '')))->format('F j, Y, g:i A') ?></div>
                    </div>
                    <div class="rounded-xl bg-zinc-50/50 border border-zinc-200 p-4">
                        <div class="text-xs text-zinc-500 font-bold mb-1">End Date & Time</div>
                        <div class="text-[15px] font-bold text-zinc-900"><?= (new DateTimeImmutable((string)($event['end_at'] ?? '')))->format('F j, Y, g:i A') ?></div>
                    </div>
                    <div class="col-span-1 md:col-span-2 rounded-xl bg-zinc-50/50 border border-zinc-200 p-4">
                        <div class="text-xs text-zinc-500 font-bold mb-1">Location / Venue</div>
                        <div class="text-[15px] font-bold text-zinc-900 flex items-center gap-2">
                           <svg class="w-4 h-4 text-orange-500" fill="currentColor" viewBox="0 0 24 24"><path d="M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7zm0 9.5a2.5 2.5 0 010-5 2.5 2.5 0 010 5z"/></svg> 
                           <?= htmlspecialchars((string) ($event['location'] ?? 'TBA')) ?>
                        </div>
                    </div>
                 </div>

                 <!-- Student Area -->
                 <?php if ($role === 'student'): ?>
                    <div class="mt-8 pt-6 border-t border-zinc-200 flex flex-wrap gap-3">
                        <?php if ($isRegistrationAllowed): ?>
                            <button id="btnRegister" class="rounded-xl bg-gradient-to-r from-orange-600 to-red-600 text-white px-6 py-3 text-sm font-bold hover:from-orange-500 hover:to-red-500 transition-all shadow-lg shadow-orange-600/20">
                            Register & Get Ticket
                            </button>
                        <?php else: ?>
                            <button class="rounded-xl bg-zinc-200 text-zinc-500 cursor-not-allowed px-6 py-3 text-sm font-bold">
                            Registration Closed
                            </button>
                        <?php endif; ?>
                        <a href="/my_tickets.php" class="rounded-xl border border-zinc-300 bg-zinc-50 px-5 py-3 text-sm font-bold text-zinc-800 hover:bg-white transition shadow-sm">
                            My Tickets
                        </a>
                    </div>
                    <div id="msgStudent" class="mt-4 text-sm font-bold text-emerald-600"></div>
                 <?php endif; ?>
            </div>
        </div>

        <!-- RIGHT SIDEBAR (Stats & Controls) -->
        <?php if ($role === 'admin' || $role === 'teacher'): ?>
        <div class="w-full xl:w-80 flex-shrink-0 flex flex-col gap-4">
            
            <!-- Cards from manual -->
            <div class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm hover:border-emerald-300 transition-all group flex items-center gap-4">
                <div class="w-12 h-12 rounded-xl bg-emerald-100 flex items-center justify-center flex-shrink-0 text-emerald-600">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                </div>
                <div>
                    <div class="text-[11px] font-black text-zinc-500 uppercase tracking-wider mb-0.5">Completed Participants</div>
                    <div class="text-2xl font-bold text-zinc-900"><?= $completedCount ?></div>
                </div>
            </div>

            <div class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm hover:border-amber-300 transition-all group flex items-center gap-4">
                <div class="w-12 h-12 rounded-xl bg-amber-100 flex items-center justify-center flex-shrink-0 text-amber-600">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                </div>
                <div>
                    <div class="text-[11px] font-black text-zinc-500 uppercase tracking-wider mb-0.5">Non-Completed Participants</div>
                    <div class="text-2xl font-bold text-zinc-900"><?= $nonCompletedCount ?></div>
                </div>
            </div>

            <div class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm hover:border-sky-300 transition-all group flex items-center gap-4">
                <div class="w-12 h-12 rounded-xl bg-sky-100 flex items-center justify-center flex-shrink-0 text-sky-600">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.501 20.118a7.5 7.5 0 0114.998 0A17.933 17.933 0 0112 21.75c-2.676 0-5.216-.584-7.499-1.632z"/></svg>
                </div>
                <div>
                    <div class="text-[11px] font-black text-zinc-500 uppercase tracking-wider mb-0.5">Target Participants</div>
                    <div class="text-2xl font-bold text-zinc-900"><?= $totalRegistered ?> <span class="text-sm font-semibold text-zinc-400">Total Registered</span></div>
                </div>
            </div>

            <!-- Registration Toggle (Matches Manual Design) -->
            <div class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm mt-2">
                <div class="flex items-center justify-between mb-2">
                    <div class="text-[13px] font-black text-zinc-800 uppercase tracking-wide">Allow Registration</div>
                    
                    <!-- Tailwind Toggle Switch -->
                    <button type="button" id="btnToggleReg" class="relative inline-flex h-6 w-11 flex-shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors duration-200 ease-in-out focus:outline-none <?= $isRegistrationAllowed ? 'bg-emerald-500' : 'bg-zinc-300' ?>" role="switch" aria-checked="<?= $isRegistrationAllowed ? 'true' : 'false' ?>">
                        <span aria-hidden="true" class="pointer-events-none inline-block h-5 w-5 transform rounded-full bg-white shadow ring-0 transition duration-200 ease-in-out <?= $isRegistrationAllowed ? 'translate-x-5' : 'translate-x-0' ?>"></span>
                    </button>
                </div>
                <p class="text-[11px] text-zinc-500 font-medium">Turn ON to let students register and generate tickets instantly.</p>
            </div>
            
        </div>
        <?php endif; ?>

    </div>
</div>

<?php if ($role === 'admin' || $role === 'teacher'): ?>
<!-- ═══════════  EDIT EVENT MODAL (With AI & Groq) ═══════════ -->
<div id="eventModal" class="fixed inset-0 z-[100] flex items-center justify-center pointer-events-none opacity-0 transition-opacity duration-300">
  <div class="absolute inset-0 bg-black/60 backdrop-blur-sm" id="modalBackdrop"></div>
  <div class="relative w-full max-w-2xl mx-4 max-h-[90vh] flex flex-col bg-white border border-zinc-200 rounded-3xl shadow-xl scale-95 transition-transform duration-300" id="modalContent">
    
    <div class="px-5 sm:px-6 py-5 border-b border-zinc-200 shrink-0 flex items-center justify-between bg-zinc-50 rounded-t-3xl">
      <div class="flex items-center gap-4">
        <div class="w-10 h-10 rounded-full bg-orange-100 border border-orange-200 flex items-center justify-center flex-shrink-0 text-orange-600">
          <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931zm0 0L19.5 7.125M18 14v4.75A2.25 2.25 0 0115.75 21H5.25A2.25 2.25 0 013 18.75V8.25A2.25 2.25 0 015.25 6H10"/></svg>
        </div>
        <div>
          <h3 id="modalTitle" class="text-xl font-bold text-zinc-900 tracking-tight leading-none">Edit Event Details</h3>
          <p id="modalSubtitle" class="text-[13px] font-medium text-zinc-500 mt-1">Make changes below</p>
        </div>
      </div>
      <button id="btnCloseModal" class="p-2 -mr-2 rounded-xl text-zinc-400 hover:text-zinc-800 hover:bg-zinc-200/50 transition focus:outline-none">
        <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
      </button>
    </div>

    <div class="p-5 sm:p-6 overflow-y-auto">
      <form id="eventForm">
        <input type="hidden" id="event_id" value="">
        <input type="hidden" id="mode" value="edit">

        <div class="space-y-4">
          <div>
            <label class="block text-xs text-zinc-600 mb-1.5 font-medium tracking-wide">Event Title</label>
            <div class="relative">
              <div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-4 text-zinc-400">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 21h16.5M4.5 3h15M5.25 3v18m13.5-18v18M9 6.75h1.5m-1.5 3h1.5m-1.5 3h1.5m3-6H15m-1.5 3H15m-1.5 3H15M9 21v-3.375c0-.621.504-1.125 1.125-1.125h3.75c.621 0 1.125.504 1.125 1.125V21"/></svg>
              </div>
              <input id="title" name="title" type="text" required class="w-full rounded-xl bg-white border border-zinc-200 pl-11 pr-4 py-3 text-sm text-zinc-900 outline-none focus:ring-2 focus:ring-orange-500/30 focus:border-orange-400 transition" placeholder="e.g. CCS Freshmen Orientation 2024" />
            </div>
          </div>
          <div>
            <label class="block text-xs text-zinc-600 mb-1.5 font-medium tracking-wide">Event Location</label>
            <div class="relative">
              <div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-4 text-zinc-400">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M15 10.5a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1115 0z"/></svg>
              </div>
              <input id="location" name="location" type="text" required class="w-full rounded-xl bg-white border border-zinc-200 pl-11 pr-4 py-3 text-sm text-zinc-900 outline-none focus:ring-2 focus:ring-orange-500/30 focus:border-orange-400 transition" placeholder="e.g. Main Hall or TBA" />
            </div>
          </div>
          
          <div class="grid grid-cols-2 gap-4">
             <div>
                <label class="block text-xs text-zinc-600 mb-1.5 font-medium tracking-wide">Start Date & Time</label>
                <div class="relative">
                <div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-4 text-zinc-400">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 11.25v7.5"/></svg>
                </div>
                <input id="start_at_local" name="start_at_local" type="datetime-local" required class="w-full rounded-xl bg-white border border-zinc-200 pl-11 pr-4 py-3 text-sm text-zinc-900 outline-none focus:ring-2 focus:ring-orange-500/30 focus:border-orange-400 transition" />
                </div>
            </div>
            <div>
                <label class="block text-xs text-zinc-600 mb-1.5 font-medium tracking-wide">End Date & Time</label>
                <div class="relative">
                <div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-4 text-zinc-400">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                </div>
                <input id="end_at_local" name="end_at_local" type="datetime-local" required class="w-full rounded-xl bg-white border border-zinc-200 pl-11 pr-4 py-3 text-sm text-zinc-900 outline-none focus:ring-2 focus:ring-orange-500/30 focus:border-orange-400 transition" />
                </div>
            </div>
          </div>

          <div>
            <label class="block text-xs text-zinc-600 mb-1.5 font-medium tracking-wide flex items-center justify-between">
              <span>Description</span>
              <span class="text-[10px] text-zinc-400 font-normal">Click the mic to dictate</span>
            </label>
            <div class="relative">
              <textarea id="description" name="description" rows="5" class="w-full rounded-xl bg-white border border-zinc-200 px-4 py-3 pr-14 text-sm text-zinc-900 outline-none focus:ring-2 focus:ring-orange-500/30 focus:border-orange-400 resize-none transition-all duration-300" placeholder="Tell attendees what this event is about..."></textarea>
              <button type="button" id="sttBtn" class="absolute bottom-3 right-3 p-2 rounded-xl bg-emerald-50 text-emerald-600 hover:bg-emerald-100 border border-emerald-200 shadow-sm transition-colors hover:scale-105" title="Dictate Description">
                <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" class="w-5 h-5">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M12 18.75a6 6 0 006-6v-1.5m-6 7.5a6 6 0 01-6-6v-1.5m6 7.5v3.75m-3.75 0h7.5M12 15.75a3 3 0 01-3-3V4.5a3 3 0 116 0v8.25a3 3 0 01-3 3z"/>
                </svg>
              </button>
            </div>
            
            <div class="flex items-center justify-between mt-1.5 px-1">
              <span id="mainAiStatus" class="hidden text-[11px] text-orange-600 font-medium whitespace-nowrap"></span>
              <div class="flex items-center justify-end gap-3 ml-auto">
                <button type="button" id="mainAiImproveBtn" class="text-[11px] text-orange-600 hover:text-orange-700 font-bold transition-all outline-none flex items-center gap-1.5 bg-gradient-to-r from-orange-50 to-red-50 hover:from-orange-100 hover:to-red-100 px-3 py-1.5 rounded-lg border border-orange-200/60 shadow-sm">
                  ✨ AI Improve
                </button>
              </div>
            </div>
          </div>
        </div>

        <div id="formMsg" class="text-sm font-bold text-emerald-600 mt-2 min-h-0"></div>
      </form>
    </div>

    <!-- Footer -->
    <div class="px-5 sm:px-6 py-4 border-t border-zinc-200 bg-zinc-50 flex items-center justify-end rounded-b-3xl">
        <button type="button" id="btnSubmitForm" class="rounded-xl bg-orange-600 text-white px-6 py-2.5 text-sm font-semibold hover:bg-orange-700 transition-all shadow-sm">
          Save Changes
        </button>
    </div>

  </div>
</div>

<!-- ═══════════  STT PREVIEW MODAL (Imported logic) ═══════════ -->
<div id="sttPreviewModal" class="fixed inset-0 z-[110] flex items-center justify-center pointer-events-none opacity-0 transition-opacity duration-300">
  <div class="absolute inset-0 bg-black/60 backdrop-blur-sm" id="sttBackdrop"></div>
  <div class="relative w-full max-w-xl mx-4 bg-white border border-zinc-200 rounded-3xl shadow-2xl scale-95 transition-transform duration-300" id="sttModalContent">
    <div class="flex items-center justify-between px-5 pt-5 pb-3 border-b border-zinc-200">
      <div class="flex items-center gap-3">
        <div class="w-9 h-9 rounded-xl bg-gradient-to-br from-orange-100 to-red-100 border border-orange-200 flex items-center justify-center">
          <svg class="w-4 h-4 text-orange-600" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 18.75a6 6 0 006-6v-1.5m-6 7.5a6 6 0 01-6-6v-1.5m6 7.5v3.75m-3.75 0h7.5M12 15.75a3 3 0 01-3-3V4.5a3 3 0 116 0v8.25a3 3 0 01-3 3z"/></svg>
        </div>
        <div>
          <div class="text-sm font-semibold text-zinc-900">Voice Summary</div>
          <div class="text-[10px] text-zinc-500">Edit before saving</div>
        </div>
      </div>
      <button id="sttPreviewClose" class="p-2 rounded-xl text-zinc-500 hover:text-zinc-900 hover:bg-zinc-100 transition">
        <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
      </button>
    </div>
    
    <div class="px-5 pt-4 pb-2 flex items-center gap-2">
      <button type="button" class="w-1/2 py-2 rounded-lg font-bold text-xs bg-zinc-100 text-zinc-800" id="sttTabRaw">📝 Raw Text</button>
      <button type="button" class="w-1/2 py-2 rounded-lg font-bold text-xs text-zinc-500 hover:bg-zinc-50" id="sttTabImproved">✨ AI Improved</button>
    </div>

    <div class="px-5 py-3">
      <div id="sttModalStatus" class="text-xs font-semibold text-red-600 mb-2 hidden items-center flex-col justify-center gap-2"></div>
      
      <div class="relative">
        <textarea id="sttPreviewText" rows="6" class="w-full rounded-xl bg-zinc-50 border border-zinc-200 p-3 text-sm text-zinc-800 outline-none focus:ring-2 focus:ring-orange-500/30 focus:border-orange-400 resize-none font-medium leading-relaxed"></textarea>
      </div>

      <div class="flex items-center justify-between mt-3 text-[10px] font-bold text-zinc-500 uppercase tracking-widest px-1">
         <span id="sttCharCount">0 chars</span>
         <span id="sttWordCount">0 words</span>
      </div>
    </div>

    <!-- Actions -->
    <div class="px-5 py-4 border-t border-zinc-200 bg-zinc-50 flex items-center justify-between rounded-b-3xl gap-2">
       <button id="sttMicToggle" class="flex-shrink-0 flex items-center gap-1.5 rounded-lg bg-emerald-50 text-emerald-700 px-3 py-1.5 font-bold text-xs border border-emerald-200 shadow-sm hover:bg-emerald-100 transition">
         Stop Recording ⏹
       </button>
       <div class="flex items-center gap-2 ml-auto">
         <button id="sttPreviewDiscard" class="rounded-lg border border-zinc-200 bg-white px-3 py-1.5 text-xs text-zinc-700 hover:bg-zinc-50 focus:outline-none font-bold">Discard</button>
         <button id="sttPreviewAppend" class="rounded-lg border border-orange-200 bg-orange-50 text-orange-700 px-3 py-1.5 text-xs hover:bg-orange-100 focus:outline-none font-bold">Append</button>
         <button id="sttPreviewReplace" class="rounded-lg bg-orange-600 text-white px-4 py-1.5 text-xs hover:bg-orange-700 border border-orange-600 shadow-sm focus:outline-none font-bold">Replace Text</button>
       </div>
    </div>
  </div>
</div>

<!-- ═══════════  SUCCESS: BROADCAST CERTIFICATES MODAL (Page 32) ═══════════ -->
<div id="successCertModal" class="fixed inset-0 z-[150] hidden items-center justify-center p-4 bg-zinc-900/60 backdrop-blur-sm transition-opacity duration-300">
  <div class="w-full max-w-sm rounded-3xl bg-[#f0fdf4] border border-emerald-200 shadow-2xl overflow-hidden scale-95 transition-transform duration-300" id="successCertContent">
    <div class="p-8 text-center relative">
        <button id="btnCloseCertModal" class="absolute top-4 right-4 text-emerald-600 hover:text-emerald-800 focus:outline-none">
             <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
        </button>
        <div class="w-20 h-20 rounded-full bg-emerald-500 flex flex-col items-center justify-center mx-auto mb-5 shadow-lg shadow-emerald-500/30 ring-4 ring-emerald-100">
            <svg class="w-10 h-10 text-white" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M21.75 6.75v10.5a2.25 2.25 0 01-2.25 2.25h-15a2.25 2.25 0 01-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25m19.5 0v.243a2.25 2.25 0 01-1.07 1.916l-7.5 4.615a2.25 2.25 0 01-2.36 0L3.32 8.91a2.25 2.25 0 01-1.07-1.916V6.75"/></svg>
        </div>
        <h3 class="text-xl font-bold text-emerald-900 tracking-tight leading-none mb-2">Certificates Sent Successfully</h3>
        <p class="text-sm font-semibold text-emerald-700 leading-relaxed">Certificates sent to <?= $completedCount ?> participants.</p>
    </div>
  </div>
</div>

<!-- ═══════════  REGISTRATION CONFIRM MODAL  ═══════════ -->
<div id="confirmRegModal" class="fixed inset-0 z-[100] hidden items-center justify-center p-4 bg-zinc-900/50 backdrop-blur-sm">
  <div class="w-full max-w-sm rounded-3xl bg-white border border-zinc-200 shadow-xl overflow-hidden">
    <div class="p-6 pb-5 text-center">
        <div class="w-16 h-16 rounded-full bg-emerald-50 border border-emerald-100 flex items-center justify-center mx-auto mb-4 text-emerald-500 text-3xl font-black">?</div>
        <h3 class="text-xl font-bold text-zinc-900 tracking-tight leading-none mb-2">Publish Event?</h3>
        <p class="text-sm font-medium text-zinc-500 leading-relaxed">Are you sure you want to allow registration for this event? Students will be able to enroll immediately.</p>
    </div>
    <div class="flex border-t border-zinc-200">
        <button id="btnCancelReg" class="flex-1 py-3 text-sm font-bold text-zinc-600 hover:bg-zinc-50 border-r border-zinc-200 transition">Cancel</button>
        <button id="btnConfirmReg" class="flex-1 py-3 text-sm font-bold text-emerald-600 bg-emerald-50 hover:bg-emerald-100 hover:text-emerald-700 transition">Allow Registration</button>
    </div>
  </div>
</div>
<?php endif; ?>

<script>
<?php if ($role === 'admin' || $role === 'teacher'): ?>
// ------------------------------------------------------------------
// MODAL LOGIC (EDIT EVENT & REGISTRATION TOGGLE)
// ------------------------------------------------------------------
const eventModal = document.getElementById('eventModal');
const modalContent = document.getElementById('modalContent');
const btnEdit = document.getElementById('btnEditEventTop');
const btnClose = document.getElementById('btnCloseModal');
const backdrop = document.getElementById('modalBackdrop');

const sttModal = document.getElementById('sttPreviewModal');
const sttContent = document.getElementById('sttModalContent');

function toLocalInput(iso) {
    if (!iso) return '';
    const d = new Date(iso);
    const pad = (n) => String(n).padStart(2, '0');
    return d.getFullYear() + '-' + pad(d.getMonth()+1) + '-' + pad(d.getDate()) + 'T' + pad(d.getHours()) + ':' + pad(d.getMinutes());
}

if (btnEdit) {
  btnEdit.addEventListener('click', () => {
    document.getElementById('event_id').value = btnEdit.dataset.id;
    document.getElementById('title').value = btnEdit.dataset.title;
    document.getElementById('location').value = btnEdit.dataset.location;
    document.getElementById('description').value = btnEdit.dataset.description;
    
    document.getElementById('start_at_local').value = toLocalInput(btnEdit.dataset.start_at);
    document.getElementById('end_at_local').value = toLocalInput(btnEdit.dataset.end_at);

    eventModal.classList.remove('opacity-0', 'pointer-events-none');
    modalContent.classList.remove('scale-95');
    modalContent.classList.add('scale-100');
    document.body.style.overflow = 'hidden';
  });

  const closeIt = () => {
    eventModal.classList.add('opacity-0', 'pointer-events-none');
    modalContent.classList.remote('scale-100');
    modalContent.classList.add('scale-95');
    document.body.style.overflow = '';
  };

  btnClose.addEventListener('click', closeIt);
  backdrop.addEventListener('click', closeIt);
}

// Save Edit
document.getElementById('btnSubmitForm')?.addEventListener('click', async () => {
    const msg = document.getElementById('formMsg');
    const start_local = document.getElementById('start_at_local').value;
    const end_local = document.getElementById('end_at_local').value;

    if (!start_local || !end_local) { msg.textContent = 'Please fill all fields.'; return; }

    const sd = new Date(start_local);
    const ed = new Date(end_local);

    const payload = {
      event_id: document.getElementById('event_id').value,
      title: document.getElementById('title').value.trim(),
      location: document.getElementById('location').value.trim(),
      description: document.getElementById('description').value.trim(),
      start_at: sd.toISOString(),
      end_at: ed.toISOString(),
      csrf_token: window.CSRF_TOKEN
    };
    
    document.getElementById('btnSubmitForm').textContent = 'Saving...';
    try {
      const res = await fetch('/api/events_update.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
      });
      const data = await res.json();
      if (!data.ok) throw new Error(data.error);
      window.location.reload();
    } catch(err) {
      msg.textContent = err.message || 'Failed';
      document.getElementById('btnSubmitForm').textContent = 'Save Changes';
    }
});

// Registration Toggle Logic Map ("Published" status in our schema)
const btnToggleReg = document.getElementById('btnToggleReg');
const publishModal = document.getElementById('confirmRegModal');
const btnConfirmReg = document.getElementById('btnConfirmReg');
const btnCancelReg = document.getElementById('btnCancelReg');

if (btnToggleReg && publishModal) {
    btnToggleReg.addEventListener('click', () => {
        // Only show modal if turning ON
        if (btnToggleReg.getAttribute('aria-checked') === 'false') {
            publishModal.classList.remove('hidden');
            publishModal.classList.add('flex');
        } else {
            // Turning OFF instantly via API Archive or draft
            triggerStatusUpdate('draft');
        }
    });

    btnCancelReg.addEventListener('click', () => {
        publishModal.classList.add('hidden');
        publishModal.classList.remove('flex');
    });

    btnConfirmReg.addEventListener('click', () => {
        publishModal.classList.add('hidden');
        triggerStatusUpdate('published');
    });
}

async function triggerStatusUpdate(newStatus) {
    try {
        const res = await fetch('/api/events_approve.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ event_id: <?= json_encode($id) ?>, status: newStatus, csrf_token: window.CSRF_TOKEN })
        });
        const data = await res.json();
        if(data.ok) window.location.reload();
        else alert('Failed to update status.');
    } catch(err) { alert('Network Error'); }
}

// ------------------------------------------------------------------
// BROADCAST CERTIFICATES LOGIC (Page 32 Simulation)
// ------------------------------------------------------------------
const btnSendCert = document.getElementById('btnSendCert');
const certModal = document.getElementById('successCertModal');
const certContent = document.getElementById('successCertContent');
const btnCloseCertModal = document.getElementById('btnCloseCertModal');

if (btnSendCert) {
    btnSendCert.addEventListener('click', () => {
        const completeds = <?= $completedCount ?>;
        if (completeds === 0) {
            alert("No completed participants to send certificates to!");
            return;
        }

        // Loading simulation
        const originalText = btnSendCert.innerHTML;
        btnSendCert.innerHTML = '<span class="relative z-10 flex items-center justify-center gap-2"><svg class="animate-spin h-4 w-4 text-emerald-700" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg> Sending...</span>';
        btnSendCert.disabled = true;

        setTimeout(() => {
            btnSendCert.innerHTML = originalText;
            btnSendCert.disabled = false;
            
            certModal.classList.remove('hidden');
            certModal.classList.add('flex');
            setTimeout(() => {
                certModal.classList.remove('opacity-0');
                certContent.classList.remove('scale-95');
                certContent.classList.add('scale-100');
            }, 10);
        }, 1200);
    });

    const closeCertModal = () => {
        certModal.classList.add('opacity-0');
        certContent.classList.remove('scale-100');
        certContent.classList.add('scale-95');
        setTimeout(() => {
            certModal.classList.add('hidden');
            certModal.classList.remove('flex');
        }, 300);
    };

    btnCloseCertModal.addEventListener('click', closeCertModal);
}

// ------------------------------------------------------------------
// AI IMPROVE AND STT LOGIC (Copied from manage_events.php)
// ------------------------------------------------------------------
const aiBtn = document.getElementById('mainAiImproveBtn');
if (aiBtn) {
  aiBtn.addEventListener('click', async () => {
    const ta = document.getElementById('description');
    const raw = ta.value.trim();
    if(!raw) return;
    
    aiBtn.innerHTML = '⏳ Loading...';
    aiBtn.disabled = true;
    try {
      const resp = await fetch('/api/ai_improve.php', {
        method: 'POST', headers: {'Content-Type':'application/json'},
        body: JSON.stringify({ raw_text: raw })
      });
      const d = await resp.json();
      if(d.ok) ta.value = d.improved_text;
      else alert('AI Error: ' + d.error);
    } catch(e) { alert('Network Error'); }
    aiBtn.innerHTML = '✨ AI Improve';
    aiBtn.disabled = false;
  });
}

// Media Recorder Injection
(function(){
    var sttBtn = document.getElementById('sttBtn');
    if (!sttBtn) return;
    
    var mediaRecorder = null;
    var audioChunks = [];
    var recTimer = null;
    var seconds = 0;
    var rawText = '';
    
    const openSttModal = () => {
       sttModal.classList.remove('opacity-0', 'pointer-events-none');
       sttContent.classList.remove('scale-95');
       sttContent.classList.add('scale-100');
       document.getElementById('sttTabRaw').className = "w-1/2 py-2 rounded-lg font-bold text-xs bg-zinc-100 text-zinc-800";
       document.getElementById('sttTabImproved').className = "w-1/2 py-2 rounded-lg font-bold text-xs text-zinc-500 hover:bg-zinc-50";
    };

    const closeSttModal = () => {
       sttModal.classList.add('opacity-0', 'pointer-events-none');
       sttContent.classList.remove('scale-100');
       sttContent.classList.add('scale-95');
    };

    document.getElementById('sttPreviewClose').addEventListener('click', closeSttModal);
    document.getElementById('sttPreviewDiscard').addEventListener('click', closeSttModal);

    const ptext = document.getElementById('sttPreviewText');
    const updateC = () => {
        document.getElementById('sttCharCount').textContent = ptext.value.length + " chars";
        document.getElementById('sttWordCount').textContent = ptext.value.trim().split(/\s+/).filter(x=>x.length>0).length + " words";
    };

    sttBtn.addEventListener('click', async () => {
       openSttModal();
       ptext.value = ''; rawText = ''; seconds = 0;
       updateC();
       
       const st = document.getElementById('sttModalStatus');
       st.classList.remove('hidden'); st.classList.add('flex');
       st.innerHTML = '<span class="relative flex h-3 w-3"><span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-red-400 opacity-75"></span><span class="relative inline-flex rounded-full h-3 w-3 bg-red-500"></span></span> <span id="sttTimerCount">🎙️ Recording... 00:00</span>';
       
       document.getElementById('sttMicToggle').innerHTML = 'Stop Recording ⏹';
       document.getElementById('sttMicToggle').className = 'flex-shrink-0 flex items-center gap-1.5 rounded-lg bg-red-50 text-red-600 px-3 py-1.5 font-bold text-xs border border-red-200 hover:bg-red-100 transition';
       
       recTimer = setInterval(()=>{
           seconds++;
           let m = Math.floor(seconds/60).toString().padStart(2,'0');
           let s = (seconds%60).toString().padStart(2,'0');
           document.getElementById('sttTimerCount').textContent = `🎙️ Recording... ${m}:${s}`;
       }, 1000);

       try {
           const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
           mediaRecorder = new MediaRecorder(stream);
           audioChunks = [];
           mediaRecorder.ondataavailable = (e) => { if(e.data.size>0) audioChunks.push(e.data); };
           mediaRecorder.onstop = async () => {
               clearInterval(recTimer);
               st.innerHTML = '⏳ Uploading audio to Groq API...';
               const blob = new Blob(audioChunks, {type:'audio/webm'});
               const fd = new FormData(); fd.append('audio', blob, 'x.webm');
               try {
                   const r = await fetch('api/speech_to_text.php', {method:'POST', body:fd});
                   const d = await r.json();
                   if(d.ok) { rawText += (rawText?' ':'') + d.text; ptext.value = rawText; updateC(); st.classList.add('hidden'); }
                   else st.innerHTML = '⚠️ STT Error: ' + d.error;
               } catch(e) { st.innerHTML = 'Network Error reaching Speech API'; }
               document.getElementById('sttMicToggle').innerHTML = 'Start Recording ▶️';
               document.getElementById('sttMicToggle').className = 'flex-shrink-0 flex items-center gap-1.5 rounded-lg bg-emerald-50 text-emerald-700 px-3 py-1.5 font-bold text-xs border border-emerald-200 shadow-sm hover:bg-emerald-100 transition';
           };
           mediaRecorder.start();
       } catch(e) {
           clearInterval(recTimer);
           st.innerHTML = '🚫 Mic blocked by browser';
       }
    });

    document.getElementById('sttMicToggle').addEventListener('click', () => {
        if(mediaRecorder && mediaRecorder.state === 'recording') {
            mediaRecorder.stop();
            mediaRecorder.stream.getTracks().forEach(t=>t.stop());
        }
    });

    document.getElementById('sttTabImproved').addEventListener('click', async () => {
       if(!ptext.value.trim()) return;
       document.getElementById('sttTabImproved').className = "w-1/2 py-2 rounded-lg font-bold text-xs bg-zinc-100 text-zinc-800";
       document.getElementById('sttTabRaw').className = "w-1/2 py-2 rounded-lg font-bold text-xs text-zinc-500 hover:bg-zinc-50";
       const rt = ptext.value; ptext.value = '⏳ AI is processing text...';
       try {
           const r = await fetch('/api/ai_improve.php', { method:'POST', body:JSON.stringify({raw_text:rt})});
           const d = await r.json();
           if(d.ok) { ptext.value = d.improved_text; updateC(); }
           else ptext.value = "Error: " + d.error;
       } catch(e) { ptext.value = "Network Error loading AI."; }
    });

    document.getElementById('sttTabRaw').addEventListener('click', () => {
       document.getElementById('sttTabRaw').className = "w-1/2 py-2 rounded-lg font-bold text-xs bg-zinc-100 text-zinc-800";
       document.getElementById('sttTabImproved').className = "w-1/2 py-2 rounded-lg font-bold text-xs text-zinc-500 hover:bg-zinc-50";
       ptext.value = rawText; updateC();
    });

    document.getElementById('sttPreviewReplace').addEventListener('click', () => {
        document.getElementById('description').value = ptext.value;
        closeSttModal();
    });
    document.getElementById('sttPreviewAppend').addEventListener('click', () => {
        document.getElementById('description').value += " " + ptext.value;
        closeSttModal();
    });

})();

<?php endif; ?>

<?php if ($role === 'student'): ?>
document.getElementById('btnRegister')?.addEventListener('click', async () => {
    const btn = document.getElementById('btnRegister');
    const msg = document.getElementById('msgStudent');
    btn.disabled = true; msg.textContent = 'Registering...';
    try {
      const res = await fetch('/api/register_event.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ event_id: <?= json_encode($id) ?>, csrf_token: window.CSRF_TOKEN })
      });
      const data = await res.json();
      if (!data.ok) throw new Error(data.error);
      msg.innerHTML = 'Registered! <a class="underline" href="/ticket.php?token=' + encodeURIComponent(data.ticket.token) + '">View ticket</a>';
      btn.style.display = 'none';
      setTimeout(()=>window.location.reload(), 1500);
    } catch (err) { msg.textContent = err.message || 'Failed'; btn.disabled = false; }
});
<?php endif; ?>
</script>

<?php render_footer(); ?>
