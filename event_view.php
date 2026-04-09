<?php
declare(strict_types=1);

session_start();

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/supabase.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/helpers.php';

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
$childSelect = 'select=id,student_id,tickets(attendance(status))';
$partUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/event_registrations?' . $childSelect . '&event_id=eq.' . rawurlencode($id);
$partRes = supabase_request('GET', $partUrl, $headers);
$participants = $partRes['ok'] ? json_decode((string) $partRes['body'], true) : [];

$totalRegistered = count(is_array($participants) ? $participants : []);
$completedCount = 0;
$nonCompletedCount = 0;

if (is_array($participants)) {
    foreach ($participants as $p) {
        $statusStr = '';
        $tickets = $p['tickets'] ?? null;
        if (is_array($tickets) && isset($tickets[0])) {
            $atts = $tickets[0]['attendance'] ?? null;
            if (is_array($atts)) {
                // attendance can be an array of objects or a single object depending on Supabase version/Prefer header.
                // In our participants filtering we check if it's an array.
                $firstAtt = isset($atts[0]) ? $atts[0] : $atts;
                $statusStr = (string)($firstAtt['status'] ?? '');
            }
        }
        
        if ($statusStr !== '' && $statusStr !== 'unscanned') {
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
$canToggleRegistration = in_array($status, ['draft', 'published'], true);

$statusColor = match($status) {
    'published' => 'bg-emerald-100 text-emerald-900 border-emerald-200',
    'pending' => 'bg-amber-100 text-amber-900 border-amber-200',
    'approved' => 'bg-sky-100 text-sky-900 border-sky-200',
    'draft' => 'bg-orange-100 text-orange-900 border-orange-200',
    default => 'bg-zinc-100 text-zinc-800 border-zinc-200',
};

// Start outputting UI
render_header('Event Details', $user);
?>

<?php
    $referer = $_SERVER['HTTP_REFERER'] ?? '';
    // Make sure we only fallback safely 
    $backUrl = str_contains($referer, 'events.php') ? '/events.php' : '/manage_events.php';
?>
<div class="mb-4">
    <!-- Back Button & Header Row -->
    <div class="flex items-center justify-between flex-wrap gap-4 pb-4 border-b border-zinc-200 mb-6">
        <div class="flex items-center gap-3">
            <a href="<?= htmlspecialchars($backUrl) ?>" class="flex items-center justify-center w-8 h-8 rounded-full bg-white border border-zinc-200 hover:bg-zinc-50 text-zinc-600 transition shadow-sm">
                <svg class="w-4 h-4 mr-0.5" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5L8.25 12l7.5-7.5"/></svg>
            </a>
            <h2 class="text-xl md:text-2xl font-bold text-zinc-900"><?= htmlspecialchars((string) ($event['title'] ?? '')) ?></h2>
            <span class="text-[10px] sm:text-xs font-bold uppercase tracking-widest rounded-md border px-2 py-0.5 <?= $statusColor ?>"><?= htmlspecialchars($status) ?></span>
        </div>
        
        <?php if ($role === 'admin' || $role === 'teacher'): ?>
        <div class="flex items-center gap-2">
            <?php if ($role === 'admin' && $event['status'] === 'pending'): ?>
                <button id="btnRejectProposal" class="rounded-xl border border-red-200 bg-red-50 text-red-700 font-bold px-4 py-2 text-[13px] hover:bg-red-100 transition shadow-sm">
                    Reject Proposal
                </button>
                <button id="btnApproveProposal" data-id="<?= htmlspecialchars($id) ?>" class="rounded-xl border border-emerald-600 bg-emerald-600 text-white font-bold px-4 py-2 text-[13px] hover:bg-emerald-700 transition shadow-sm relative overflow-hidden">
                    <span class="relative z-10">Approve Proposal</span>
                </button>
            <?php else: ?>
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
            <?php endif; ?>
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
                    <a href="/evaluation_admin.php?event_id=<?= htmlspecialchars($id) ?>&tab=feedback" class="border-transparent text-zinc-500 hover:border-zinc-300 hover:text-zinc-700 whitespace-nowrap border-b-2 py-3 px-1 text-sm font-semibold transition">
                        Event Feedback
                    </a>
                    <a href="/evaluation_admin.php?event_id=<?= htmlspecialchars($id) ?>&tab=questions" class="border-transparent text-zinc-500 hover:border-zinc-300 hover:text-zinc-700 whitespace-nowrap border-b-2 py-3 px-1 text-sm font-semibold transition">
                        Evaluation Questions
                    </a>
                </nav>
            </div>

            <!-- Tab Content: Details -->
            <div class="rounded-2xl border border-zinc-200 bg-white shadow-sm relative overflow-hidden mb-6">


                <div class="p-6 md:p-8">
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
                        <div class="text-[15px] font-bold text-zinc-900"><?= htmlspecialchars(format_date_local((string)($event['start_at'] ?? ''), 'F j, Y, g:i A')) ?></div>
                    </div>
                    <div class="rounded-xl bg-zinc-50/50 border border-zinc-200 p-4">
                        <div class="text-xs text-zinc-500 font-bold mb-1">End Date & Time</div>
                        <div class="text-[15px] font-bold text-zinc-900"><?= htmlspecialchars(format_date_local((string)($event['end_at'] ?? ''), 'F j, Y, g:i A')) ?></div>
                    </div>
                    <div class="col-span-1 md:col-span-2 rounded-xl bg-zinc-50/50 border border-zinc-200 p-4">
                        <div class="text-xs text-zinc-500 font-bold mb-1">Location / Venue</div>
                        <div class="text-[15px] font-bold text-zinc-900 flex items-center gap-2">
                           <svg class="w-4 h-4 text-orange-500" fill="currentColor" viewBox="0 0 24 24"><path d="M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7zm0 9.5a2.5 2.5 0 010-5 2.5 2.5 0 010 5z"/></svg> 
                           <?= htmlspecialchars((string) ($event['location'] ?? 'TBA')) ?>
                        </div>
                    </div>
                 </div>

                 <?php if ($role === 'admin' && $event['status'] === 'pending'): ?>
                 <h3 class="text-sm font-black text-zinc-400 uppercase tracking-widest mt-8 mb-4">Attached Proposal Document</h3>
                 <div class="rounded-xl bg-blue-50/50 border border-blue-200 p-4 max-w-3xl flex items-center justify-between group hover:border-blue-300 transition-colors">
                     <div class="flex items-center gap-4">
                         <div class="w-10 h-10 rounded-lg bg-red-100 flex items-center justify-center flex-shrink-0 text-red-600">
                             <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-1 15h-2v-2h2v2zm0-4h-2V7h2v6z"/></svg>
                         </div>
                         <div>
                             <div class="text-[14px] font-bold text-zinc-900 group-hover:text-blue-700 transition-colors">LU-Letter-Request-<?= htmlspecialchars(date('Y', strtotime($event['start_at'] ?? 'now'))) ?>.pdf</div>
                             <div class="text-xs text-zinc-500 font-medium">Uploaded by Event Coordinator • 2.4 MB</div>
                         </div>
                     </div>
                     <button class="px-4 py-2 rounded-lg bg-white border border-zinc-200 text-sm font-bold text-zinc-700 hover:bg-zinc-50 shadow-sm flex items-center gap-2 transition-all">
                         <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5M16.5 12L12 16.5m0 0L7.5 12m4.5 4.5V3"/></svg>
                         View PDF
                     </button>
                 </div>
                 <?php endif; ?>

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
                    <button type="button"
                            id="btnToggleReg"
                            data-can-toggle="<?= $canToggleRegistration ? '1' : '0' ?>"
                            class="relative inline-flex h-6 w-11 flex-shrink-0 rounded-full border-2 border-transparent transition-colors duration-200 ease-in-out focus:outline-none <?= $isRegistrationAllowed ? 'bg-emerald-500' : 'bg-zinc-300' ?> <?= $canToggleRegistration ? 'cursor-pointer' : 'cursor-not-allowed opacity-50' ?>"
                            role="switch"
                            aria-checked="<?= $isRegistrationAllowed ? 'true' : 'false' ?>"
                            aria-disabled="<?= $canToggleRegistration ? 'false' : 'true' ?>">
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
                <button type="button" id="mainUndoBtn" class="hidden text-[11px] text-zinc-500 hover:text-zinc-800 font-semibold transition-colors outline-none flex items-center gap-1">
                  <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 14L4 9m0 0l5-5M4 9h9a7 7 0 110 14h-1"/></svg>
                  Undo
                </button>
                <button type="button" id="mainExpandBtn" class="text-[11px] text-zinc-500 hover:text-zinc-800 font-semibold transition-colors outline-none flex items-center gap-1">
                  <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M8 3H3v5m0-5l6 6M16 21h5v-5m0 5l-6-6"/></svg>
                  Expand
                </button>
                <button type="button" id="mainAiImproveBtn" class="text-[11px] text-orange-600 hover:text-orange-700 font-bold transition-all outline-none flex items-center gap-1.5 bg-gradient-to-r from-orange-50 to-red-50 hover:from-orange-100 hover:to-red-100 px-3 py-1.5 rounded-lg border border-orange-200/60 shadow-sm">
                  <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2.1" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 3l1.8 3.9L18 8.7l-3.2 2.8.9 4.2-3.7-2.1-3.7 2.1.9-4.2L6 8.7l4.2-1.8L12 3z"/></svg>
                  AI Improve
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

<!-- ═══════════  STT PREVIEW MODAL (Aligned with Create Event) ═══════════ -->
<div id="sttPreviewModal" class="fixed inset-0 z-[110] flex items-center justify-center pointer-events-none opacity-0 transition-opacity duration-300">
  <div class="absolute inset-0 bg-black/60 backdrop-blur-sm" id="sttBackdrop"></div>
  <div class="relative w-full max-w-xl mx-4 bg-white border border-zinc-200 rounded-3xl shadow-2xl scale-95 transition-transform duration-300 max-h-[85vh] overflow-hidden flex flex-col" id="sttModalContent">
    <div class="flex items-center justify-between px-5 pt-5 pb-3 border-b border-zinc-200">
      <div class="flex items-center gap-3">
        <div class="w-9 h-9 rounded-xl bg-gradient-to-br from-orange-100 to-red-100 border border-orange-200 flex items-center justify-center">
          <svg class="w-4 h-4 text-orange-600" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 18.75a6 6 0 006-6v-1.5m-6 7.5a6 6 0 01-6-6v-1.5m6 7.5v3.75m-3.75 0h7.5M12 15.75a3 3 0 01-3-3V4.5a3 3 0 116 0v8.25a3 3 0 01-3 3z"/></svg>
        </div>
        <div>
          <div class="text-sm font-semibold text-zinc-900">Voice Transcript Preview</div>
          <div class="text-[10px] text-zinc-500">Review and edit before inserting</div>
        </div>
      </div>
      <button id="sttPreviewClose" class="p-2 rounded-xl text-zinc-500 hover:text-zinc-900 hover:bg-zinc-100 transition">
        <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
      </button>
    </div>
    
    <div class="px-5 pt-4 pb-2 flex items-center gap-2">
      <button type="button" class="w-1/2 py-2 rounded-lg font-bold text-xs bg-zinc-100 text-zinc-800 border border-zinc-200" id="sttTabRaw">📝 Raw Text</button>
      <button type="button" class="w-1/2 py-2 rounded-lg font-bold text-xs text-orange-700 bg-orange-50 border border-orange-200" id="sttTabImproved">AI Improved</button>
    </div>

    <div class="px-5 py-3">
      <div id="sttModalStatus" class="text-xs font-semibold text-red-600 mb-2 hidden items-center gap-1.5"></div>

      <div id="sttSpectrumEffect" class="hidden w-full h-[150px] bg-zinc-900 rounded-xl items-center justify-center gap-2 border border-zinc-800 relative overflow-hidden transition-all duration-300">
        <div class="absolute inset-0 bg-red-500/10 blur-2xl rounded-full scale-[1.5] animate-pulse"></div>
        <div class="w-2 rounded-full bg-gradient-to-t from-red-500 to-red-400 animate-pulse" style="height: 16px; animation-delay: 0.1s;"></div>
        <div class="w-2 rounded-full bg-gradient-to-t from-red-500 to-red-400 animate-pulse" style="height: 32px; animation-delay: 0.3s;"></div>
        <div class="w-2 rounded-full bg-gradient-to-t from-red-500 to-red-400 animate-pulse" style="height: 64px; animation-delay: 0.5s;"></div>
        <div class="w-2 rounded-full bg-gradient-to-t from-red-500 to-red-400 animate-pulse" style="height: 48px; animation-delay: 0.2s;"></div>
        <div class="w-2 rounded-full bg-gradient-to-t from-red-500 to-red-400 animate-pulse" style="height: 24px; animation-delay: 0.4s;"></div>
      </div>

      <textarea id="sttPreviewText" rows="6" class="w-full rounded-xl bg-zinc-50 border border-zinc-200 p-3 text-sm text-zinc-800 outline-none focus:ring-2 focus:ring-orange-500/30 focus:border-orange-400 resize-none font-medium leading-relaxed"></textarea>

      <div class="text-[11px] text-zinc-400 mt-2 flex items-center justify-between">
        <div class="flex items-center gap-3">
          <span id="sttCharCount">0 chars</span>
          <span>•</span>
          <span id="sttWordCount">0 words</span>
        </div>
        <button type="button" id="sttMicToggle" class="flex items-center gap-1.5 rounded-lg bg-red-50 text-red-600 px-3 py-1.5 font-medium border border-red-200 hover:bg-red-100 transition">
          Stop Recording ⏹
        </button>
      </div>
    </div>

    <!-- Actions -->
    <div class="px-5 py-4 border-t border-zinc-200 flex items-center justify-between gap-3 rounded-b-3xl">
       <button id="sttPreviewDiscard" class="rounded-xl border border-zinc-200 bg-zinc-50 px-4 py-2.5 text-sm text-zinc-700 hover:bg-zinc-100 transition font-medium">
         Discard
       </button>
       <div class="flex items-center gap-2">
         <button id="sttPreviewAppend" class="rounded-xl border border-orange-200 bg-orange-50 px-4 py-2.5 text-sm text-orange-800 hover:bg-orange-100 transition font-semibold">Append ↩</button>
         <button id="sttPreviewReplace" class="rounded-xl bg-gradient-to-r from-orange-600 to-red-600 text-white px-5 py-2.5 text-sm font-semibold hover:from-orange-500 hover:to-red-500 shadow-lg shadow-orange-600/25 transition-all">Insert ✓</button>
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

<!-- ═══════════  REJECT PROPOSAL MODAL (Matches Page 34) ═══════════ -->
<div id="rejectModal" class="fixed inset-0 z-[60] flex items-end justify-center sm:items-center bg-zinc-900/60 backdrop-blur-sm opacity-0 hidden transition-opacity duration-300">
  <div class="relative w-full max-w-sm mx-4 bg-white border border-zinc-200 rounded-3xl shadow-xl overflow-hidden scale-95 transition-transform duration-300" id="rejectPanel" style="transform: translateY(100%);">
    <div class="p-6">
      <div class="flex items-center gap-4 mb-4">
         <div class="w-12 h-12 rounded-full bg-red-100 border border-red-200 flex items-center justify-center flex-shrink-0 text-red-600">
           <svg class="w-6 h-6" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
         </div>
         <div>
             <h3 class="text-xl font-bold text-zinc-900 tracking-tight leading-none">Reject Proposal?</h3>
             <p class="text-sm text-zinc-500 mt-1 font-medium">This action cannot be undone.</p>
         </div>
      </div>
      
      <p class="text-[13px] text-zinc-600 mb-3 px-1 leading-relaxed">Are you sure you want to reject the proposal for <span class="font-bold text-zinc-900"><?= htmlspecialchars($event['title'] ?? 'this event') ?></span>? Please provide a reason to notify the event coordinator.</p>
      
      <div class="mt-2">
         <label class="block text-xs font-black text-zinc-500 uppercase tracking-widest mb-1.5 px-1">Reason for refusing</label>
         <textarea id="rejectReason" rows="3" class="w-full rounded-xl bg-zinc-50 border border-zinc-200 px-4 py-3 text-sm text-zinc-900 outline-none focus:ring-2 focus:ring-red-500/30 focus:border-red-400 resize-none transition" placeholder="e.g. Conflicts with midterm examination week..."></textarea>
      </div>
    </div>

    <!-- Actions -->
    <div class="flex border-t border-zinc-200 bg-zinc-50">
       <button id="btnCancelReject" class="flex-1 py-3.5 text-[13px] font-bold text-zinc-600 hover:bg-zinc-100 transition border-r border-zinc-200">Cancel</button>
       <button id="btnConfirmReject" class="flex-1 py-3.5 text-[13px] font-bold text-white bg-red-600 hover:bg-red-700 transition shadow-sm" data-id="<?= htmlspecialchars($id) ?>">Reject Proposal</button>
    </div>
  </div>
</div>

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
const mainDesc = document.getElementById('description');
const mainExpandBtn = document.getElementById('mainExpandBtn');
const mainAiBtn = document.getElementById('mainAiImproveBtn');
const mainUndoBtn = document.getElementById('mainUndoBtn');
const mainAiStatus = document.getElementById('mainAiStatus');
const mainModalPanel = document.getElementById('modalContent');

let mainIsExpanded = false;
let originalMainDesc = '';

function resetMainEditorTools() {
    mainIsExpanded = false;
    originalMainDesc = '';

    if (mainDesc) {
        mainDesc.style.height = '';
    }
    if (mainModalPanel) {
        mainModalPanel.style.width = '';
        mainModalPanel.style.maxWidth = '';
    }
    if (mainExpandBtn) {
        mainExpandBtn.textContent = 'Expand';
    }
    if (mainUndoBtn) {
        mainUndoBtn.classList.add('hidden');
    }
    if (mainAiStatus) {
        mainAiStatus.classList.add('hidden');
        mainAiStatus.textContent = '';
    }
    if (mainAiBtn) {
        mainAiBtn.disabled = false;
        mainAiBtn.style.opacity = '1';
    }
}

function toLocalInput(iso) {
    if (!iso) return '';
    const d = new Date(iso);
    const pad = (n) => String(n).padStart(2, '0');
    return d.getFullYear() + '-' + pad(d.getMonth()+1) + '-' + pad(d.getDate()) + 'T' + pad(d.getHours()) + ':' + pad(d.getMinutes());
}

if (btnEdit) {
  btnEdit.addEventListener('click', () => {
    resetMainEditorTools();
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
    resetMainEditorTools();
    eventModal.classList.add('opacity-0', 'pointer-events-none');
    modalContent.classList.remove('scale-100');
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
        if (btnToggleReg.dataset.canToggle !== '1') {
            alert('Publish the event first before enabling registration.');
            return;
        }
        // Only show modal if turning ON
        if (btnToggleReg.getAttribute('aria-checked') === 'false') {
            publishModal.classList.remove('hidden');
            publishModal.classList.add('flex');
        } else {
            // Turning OFF instantly via API sets status to draft
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
    btnSendCert.addEventListener('click', async () => {
        const completeds = <?= $completedCount ?>;
        if (completeds === 0) {
            alert("No completed participants to send certificates to!");
            return;
        }

        const originalText = btnSendCert.innerHTML;
        btnSendCert.innerHTML = '<span class="relative z-10 flex items-center justify-center gap-2"><svg class="animate-spin h-4 w-4 text-emerald-700" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg> Sending...</span>';
        btnSendCert.disabled = true;

        try {
            const res = await fetch('/api/certificates_generate.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ event_id: '<?= htmlspecialchars($id) ?>', csrf_token: window.CSRF_TOKEN })
            });
            const data = await res.json();
            
            if (!data.ok) throw new Error(data.error || 'Failed to generate certificates');
            
            btnSendCert.innerHTML = originalText;
            btnSendCert.disabled = false;
            
            document.querySelector('#successCertContent p.text-zinc-600').textContent = `Successfully generated and sent ${data.count} certificates to eligible participants via email!`;
            
            certModal.classList.remove('hidden');
            certModal.classList.add('flex');
            setTimeout(() => {
                certModal.classList.remove('opacity-0');
                certContent.classList.remove('scale-95');
                certContent.classList.add('scale-100');
            }, 10);
            
        } catch (err) {
            alert('Error generating certificates: ' + err.message);
            btnSendCert.innerHTML = originalText;
            btnSendCert.disabled = false;
        }
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
// BATCH 6: EVENT APPROVAL LOGIC
// ------------------------------------------------------------------
const btnApproveProposal = document.getElementById('btnApproveProposal');
const btnRejectProposal = document.getElementById('btnRejectProposal');
const rejectModal = document.getElementById('rejectModal');
const rejectPanel = document.getElementById('rejectPanel');
const btnCancelReject = document.getElementById('btnCancelReject');
const btnConfirmReject = document.getElementById('btnConfirmReject');

if (btnApproveProposal) {
    btnApproveProposal.addEventListener('click', async () => {
        const event_id = btnApproveProposal.dataset.id;
        const status = 'approved';
        btnApproveProposal.disabled = true;
        btnApproveProposal.innerHTML = '<span class="relative z-10 flex items-center justify-center gap-1.5"><svg class="animate-spin h-4 w-4 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg> <span class="relative z-10">Approving...</span></span>';
        try {
            const res = await fetch('/api/events_approve.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ event_id, status, csrf_token: window.CSRF_TOKEN })
            });
            const data = await res.json();
            if (!data.ok) throw new Error(data.error || 'Failed to approve');
            window.location.reload();
        } catch (e) {
            alert(e.message || 'Approval Failed');
            btnApproveProposal.disabled = false;
            btnApproveProposal.innerHTML = '<span class="relative z-10">Approve Proposal</span>';
        }
    });
}

if (btnRejectProposal && rejectModal) {
    btnRejectProposal.addEventListener('click', () => {
        rejectModal.classList.remove('hidden');
        rejectModal.classList.add('flex');
        // A little delay for transition
        setTimeout(() => {
            rejectModal.classList.remove('opacity-0');
            rejectPanel.style.transform = 'translateY(0)';
        }, 10);
    });

    const closeReject = () => {
        rejectModal.classList.add('opacity-0');
        rejectPanel.style.transform = 'translateY(100%)';
        setTimeout(() => {
            rejectModal.classList.add('hidden');
            rejectModal.classList.remove('flex');
            document.getElementById('rejectReason').value = '';
        }, 300);
    };

    btnCancelReject.addEventListener('click', closeReject);
    rejectModal.addEventListener('click', (e) => { if (e.target === rejectModal) closeReject(); });

    btnConfirmReject.addEventListener('click', async () => {
        const event_id = btnConfirmReject.dataset.id;
        const reason = document.getElementById('rejectReason').value.trim();
        if (!reason) { alert("Please provide a reason to notify the event coordinator."); return; }

        btnConfirmReject.disabled = true;
        btnConfirmReject.textContent = 'Sending...';
        try {
            const res = await fetch('/api/events_approve.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ event_id, status: 'archived', reason, csrf_token: window.CSRF_TOKEN })
            });
            const data = await res.json();
            if (!data.ok) throw new Error(data.error || 'Failed to reject');
            window.location.reload();
        } catch (e) {
            alert(e.message || 'Failed to reject');
            btnConfirmReject.disabled = false;
            btnConfirmReject.textContent = 'Reject Proposal';
        }
    });
}

// ------------------------------------------------------------------
// AI IMPROVE AND STT LOGIC (Copied from manage_events.php)
// ------------------------------------------------------------------
if (mainUndoBtn && mainDesc) {
    mainUndoBtn.addEventListener('click', () => {
        if (originalMainDesc !== '') {
            mainDesc.value = originalMainDesc;
            if (mainAiStatus) {
                mainAiStatus.textContent = 'Reverted to original text.';
                mainAiStatus.classList.remove('hidden');
                setTimeout(() => mainAiStatus.classList.add('hidden'), 3500);
            }
            mainUndoBtn.classList.add('hidden');
        }
    });
}

if (mainExpandBtn && mainDesc) {
    mainExpandBtn.addEventListener('click', () => {
        mainIsExpanded = !mainIsExpanded;
        if (mainIsExpanded) {
            if (mainModalPanel) {
                mainModalPanel.style.width = '800px';
                mainModalPanel.style.maxWidth = '95vw';
            }
            mainDesc.style.height = 'calc(65vh - 180px)';
            mainExpandBtn.textContent = 'Collapse';
        } else {
            if (mainModalPanel) {
                mainModalPanel.style.width = '';
                mainModalPanel.style.maxWidth = '';
            }
            mainDesc.style.height = '';
            mainExpandBtn.textContent = 'Expand';
        }
    });
}

if (mainAiBtn && mainDesc && mainAiStatus) {
    mainAiBtn.addEventListener('click', async () => {
        const raw = mainDesc.value.trim();
        if (!raw) {
            alert('Please type a description first before AI can improve it.');
            return;
        }

        originalMainDesc = raw;
        mainAiBtn.disabled = true;
        mainAiBtn.style.opacity = '0.5';
        mainAiStatus.classList.remove('hidden');
        mainAiStatus.textContent = 'AI is rewriting your text...';

        try {
            const resp = await fetch('/api/ai_improve.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ raw_text: raw, csrf_token: window.CSRF_TOKEN || '' })
            });
            const data = await resp.json();
            if (data.ok) {
                mainDesc.value = data.improved_text;
                mainAiStatus.textContent = 'Professionally improved.';
                setTimeout(() => mainAiStatus.classList.add('hidden'), 4000);
                if (mainUndoBtn) mainUndoBtn.classList.remove('hidden');
            } else {
                mainAiStatus.textContent = 'Error: ' + (data.error || 'Unknown error');
            }
        } catch (e) {
            mainAiStatus.textContent = 'Network error.';
        }

        mainAiBtn.disabled = false;
        mainAiBtn.style.opacity = '1';
    });
}

// Speech-to-Text: Reused Create Event flow
(function () {
    var sttBtn = document.getElementById('sttBtn');
    var textarea = document.getElementById('description');
    var previewModal = document.getElementById('sttPreviewModal');
    var previewText = document.getElementById('sttPreviewText');
    var tabRaw = document.getElementById('sttTabRaw');
    var tabImproved = document.getElementById('sttTabImproved');
    var charCount = document.getElementById('sttCharCount');
    var wordCount = document.getElementById('sttWordCount');
    var modalStatus = document.getElementById('sttModalStatus');
    var micToggleBtn = document.getElementById('sttMicToggle');
    var btnAppend = document.getElementById('sttPreviewAppend');
    var btnReplace = document.getElementById('sttPreviewReplace');
    var spectrum = document.getElementById('sttSpectrumEffect');

    if (!sttBtn || !textarea || !previewModal) return;

    function openModal(el) {
      el.classList.remove('opacity-0', 'pointer-events-none');
      sttContent.classList.remove('scale-95');
      sttContent.classList.add('scale-100');
      document.body.style.overflow = 'hidden';
    }
    function closeModal(el) {
      el.classList.add('opacity-0', 'pointer-events-none');
      sttContent.classList.remove('scale-100');
      sttContent.classList.add('scale-95');
      document.body.style.overflow = '';
    }
    function setRawTab() {
      tabRaw.className = "w-1/2 py-2 rounded-lg font-bold text-xs bg-zinc-100 text-zinc-800 border border-zinc-200";
      tabImproved.className = "w-1/2 py-2 rounded-lg font-bold text-xs text-zinc-500 hover:bg-zinc-50 border border-transparent";
    }
    function setImprovedTab() {
      tabImproved.className = "w-1/2 py-2 rounded-lg font-bold text-xs text-orange-700 bg-orange-50 border border-orange-200";
      tabRaw.className = "w-1/2 py-2 rounded-lg font-bold text-xs text-zinc-500 hover:bg-zinc-50 border border-transparent";
    }
    function updateCounts() {
      var v = previewText.value;
      charCount.textContent = v.length + ' chars';
      var w = v.trim().split(/\s+/).filter(function(x){ return x.length > 0; });
      wordCount.textContent = w.length + ' word' + (w.length !== 1 ? 's' : '');
    }
    function formatTime(sec) {
      var m = Math.floor(sec / 60).toString().padStart(2, '0');
      var s = (sec % 60).toString().padStart(2, '0');
      return m + ':' + s;
    }

    var isRecording = false;
    var rawTranscript = '';
    var improvedTranscript = '';
    var activeTab = 'raw';
    var mediaRecorder = null;
    var audioChunks = [];
    var recordingTimer = null;
    var recordingSeconds = 0;

    previewText.addEventListener('input', function() {
      if (activeTab === 'raw') {
        rawTranscript = previewText.value;
        improvedTranscript = '';
      } else {
        improvedTranscript = previewText.value;
      }
      updateCounts();
    });

    tabRaw.addEventListener('click', function() {
      if (isRecording) return;
      activeTab = 'raw';
      setRawTab();
      previewText.value = rawTranscript;
      updateCounts();
    });

    tabImproved.addEventListener('click', async function() {
      if (isRecording) return;
      setImprovedTab();
      if (activeTab === 'improved') return;
      activeTab = 'improved';

      if (improvedTranscript) {
        previewText.value = improvedTranscript;
        updateCounts();
        return;
      }

      var currentRaw = rawTranscript.trim();
      if (!currentRaw) {
        previewText.value = '';
        updateCounts();
        return;
      }

      previewText.value = '⏳ AI is processing and formatting your text... Please wait.';
      previewText.readOnly = true;
      btnAppend.disabled = true; btnAppend.style.opacity = '0.5';
      btnReplace.disabled = true; btnReplace.style.opacity = '0.5';

      try {
        var res = await fetch('api/ai_improve.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ raw_text: currentRaw, csrf_token: window.CSRF_TOKEN || "" })
        });
        var data = await res.json();
        if (data.ok) {
          improvedTranscript = data.improved_text;
          if (activeTab === 'improved') previewText.value = improvedTranscript;
        } else {
          improvedTranscript = '';
          if (activeTab === 'improved') previewText.value = '⚠️ Error formatting text:\n' + data.error;
        }
      } catch (err) {
        improvedTranscript = '';
        if (activeTab === 'improved') previewText.value = '⚠️ Network error trying to connect to the backend API.';
      }

      previewText.readOnly = false;
      btnAppend.disabled = false; btnAppend.style.opacity = '1';
      btnReplace.disabled = false; btnReplace.style.opacity = '1';
      updateCounts();
    });

    function finalizeStop() {
      isRecording = false;
      if (recordingTimer) clearInterval(recordingTimer);

      micToggleBtn.innerHTML = '▶ Resume Recording';
      micToggleBtn.className = 'flex items-center gap-1.5 rounded-lg bg-emerald-50 text-emerald-700 px-3 py-1.5 font-medium border border-emerald-200 hover:bg-emerald-100 transition';

      previewText.readOnly = false;
      previewText.classList.remove('hidden');
      if (spectrum) {
        spectrum.classList.add('hidden');
        spectrum.classList.remove('flex');
      }

      modalStatus.classList.add('hidden');
      modalStatus.classList.remove('flex');
      tabImproved.style.opacity = '1';
      tabImproved.style.pointerEvents = 'auto';
      btnAppend.disabled = false; btnAppend.style.opacity = '1';
      btnReplace.disabled = false; btnReplace.style.opacity = '1';
      improvedTranscript = '';
      updateCounts();
    }

    function stopRecording() {
      if (mediaRecorder && mediaRecorder.state !== 'inactive') {
        mediaRecorder.stop();
        mediaRecorder.stream.getTracks().forEach(function(t){ t.stop(); });
      } else {
        finalizeStop();
      }
    }

    async function startRecording(resume) {
      if (!resume) {
        rawTranscript = '';
      }
      activeTab = 'raw';
      setRawTab();
      isRecording = true;

      micToggleBtn.innerHTML = 'Stop Recording ⏹';
      micToggleBtn.className = 'flex items-center gap-1.5 rounded-lg bg-red-50 text-red-600 px-3 py-1.5 font-medium border border-red-200 hover:bg-red-100 transition';

      tabImproved.style.opacity = '0.5';
      tabImproved.style.pointerEvents = 'none';
      previewText.readOnly = true;
      if (!resume) previewText.value = '';
      previewText.classList.add('hidden');
      if (spectrum) {
        spectrum.classList.remove('hidden');
        spectrum.classList.add('flex');
      }

      recordingSeconds = 0;
      modalStatus.classList.remove('hidden');
      modalStatus.classList.add('flex');
      modalStatus.innerHTML = '<span class="relative flex h-2.5 w-2.5"><span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-red-400 opacity-75"></span><span class="relative inline-flex rounded-full h-2.5 w-2.5 bg-red-500"></span></span> <span id="sttTimer">🎙️ Recording... 00:00</span>';
      recordingTimer = setInterval(function() {
        recordingSeconds++;
        var st = document.getElementById('sttTimer');
        if (st) st.textContent = '🎙️ Recording... ' + formatTime(recordingSeconds);
      }, 1000);

      btnAppend.disabled = true; btnAppend.style.opacity = '0.5';
      btnReplace.disabled = true; btnReplace.style.opacity = '0.5';
      openModal(previewModal);
      updateCounts();

      try {
        const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
        mediaRecorder = new MediaRecorder(stream);
        audioChunks = [];
        mediaRecorder.ondataavailable = function(e) {
          if (e.data.size > 0) audioChunks.push(e.data);
        };
        mediaRecorder.onstop = async function() {
          clearInterval(recordingTimer);
          modalStatus.innerHTML = '⏳ Uploading and processing audio... Please wait';
          const audioBlob = new Blob(audioChunks, { type: 'audio/webm' });
          const formData = new FormData();
          formData.append('audio', audioBlob, 'audio.webm');
          formData.append('csrf_token', window.CSRF_TOKEN || '');
          try {
            const res = await fetch('api/speech_to_text.php', { method: 'POST', body: formData });
            const data = await res.json();
            if (data.ok) {
              rawTranscript += (rawTranscript ? ' ' : '') + data.text;
              previewText.value = rawTranscript;
            } else {
              previewText.value = rawTranscript + '\n\n⚠️ STT Error:\n' + data.error;
            }
          } catch (err) {
            previewText.value = rawTranscript + '\n\n⚠️ Network Error trying to reach the Speech API server.';
          }
          finalizeStop();
        };
        mediaRecorder.start();
      } catch (err) {
        clearInterval(recordingTimer);
        modalStatus.textContent = '🚫 Mic blocked or none found — allow access in browser';
        finalizeStop();
      }
    }

    function hideModal() {
      if (isRecording) stopRecording();
      closeModal(previewModal);
    }

    document.getElementById('sttPreviewClose').addEventListener('click', hideModal);
    document.getElementById('sttPreviewDiscard').addEventListener('click', hideModal);
    document.getElementById('sttBackdrop').addEventListener('click', hideModal);

    btnReplace.addEventListener('click', function() {
      textarea.value = previewText.value;
      hideModal();
    });
    btnAppend.addEventListener('click', function() {
      var cur = textarea.value;
      if (cur && !cur.endsWith(' ') && !cur.endsWith('\n')) cur += ' ';
      textarea.value = cur + previewText.value;
      hideModal();
    });
    micToggleBtn.addEventListener('click', function() {
      if (isRecording) stopRecording();
      else startRecording(true);
    });
    sttBtn.addEventListener('click', function(e) {
      e.preventDefault();
      if (isRecording) stopRecording();
      else startRecording(false);
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


