<?php
declare(strict_types=1);

session_start();

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/supabase.php';
require_once __DIR__ . '/includes/layout.php';

$user = require_role(['teacher', 'admin']);
$role = (string) ($user['role'] ?? 'teacher');
$userId = (string) ($user['id'] ?? '');

$eventId = isset($_GET['event_id']) ? (string) $_GET['event_id'] : '';
if ($eventId === '') {
    http_response_code(400);
    echo 'Missing event_id';
    exit;
}

$tab = isset($_GET['tab']) && $_GET['tab'] === 'feedback' ? 'feedback' : 'questions';

$headers = [
    'Accept: application/json',
    'apikey: ' . SUPABASE_KEY,
    'Authorization: Bearer ' . SUPABASE_KEY,
];

// Fetch Event Details
$eventUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/events?select=id,title,status&id=eq.' . rawurlencode($eventId) . '&limit=1';
$eventRes = supabase_request('GET', $eventUrl, $headers);
$eventRows = $eventRes['ok'] ? json_decode((string) $eventRes['body'], true) : [];
$event = is_array($eventRows) && isset($eventRows[0]) ? $eventRows[0] : null;

if (!is_array($event)) {
    http_response_code(404);
    echo 'Event not found';
    exit;
}

$statusColor = match((string)($event['status'] ?? '')) {
    'published' => 'bg-emerald-100 text-emerald-900 border-emerald-200',
    'pending' => 'bg-amber-100 text-amber-900 border-amber-200',
    'approved' => 'bg-sky-100 text-sky-900 border-sky-200',
    default => 'bg-zinc-100 text-zinc-800 border-zinc-200',
};

// Fetch Questions
$qUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/evaluation_questions?select=id,event_id,question_text,field_type,required,sort_order&event_id=eq.' . rawurlencode($eventId) . '&order=sort_order.asc';
$qRes = supabase_request('GET', $qUrl, $headers);
$questions = $qRes['ok'] ? json_decode((string) $qRes['body'], true) : [];
if(!is_array($questions)) $questions = [];

// Prepare Analytics Data (Only used if tab is feedback)
$analytics = [];
$totalResponses = 0;
$totalParticipants = 0;
$pendingParticipants = 0;

if ($tab === 'feedback') {
    // 0. Get participants for this event.
    $partUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/event_registrations?select=student_id&event_id=eq.' . rawurlencode($eventId);
    $partRes = supabase_request('GET', $partUrl, $headers);
    $partRows = $partRes['ok'] ? json_decode((string) $partRes['body'], true) : [];
    if (!is_array($partRows)) $partRows = [];

    $participantMap = [];
    foreach ($partRows as $pr) {
        $sid = (string) ($pr['student_id'] ?? '');
        if ($sid !== '') {
            $participantMap[$sid] = true;
        }
    }
    $totalParticipants = count($participantMap);

    // 1. Get all submitted answers for this event.
    $ansUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/evaluation_answers?select=student_id,question_id,answer_text&event_id=eq.' . rawurlencode($eventId);
    $ansRes = supabase_request('GET', $ansUrl, $headers);
    $answers = $ansRes['ok'] ? json_decode((string) $ansRes['body'], true) : [];
    if (!is_array($answers)) $answers = [];

    // 2. Compute unique respondents (participants only).
    $respondentMap = [];
    foreach ($answers as $a) {
        $sid = (string) ($a['student_id'] ?? '');
        if ($sid !== '' && isset($participantMap[$sid])) {
            $respondentMap[$sid] = true;
        }
    }
    $totalResponses = count($respondentMap);
    $pendingParticipants = max(0, $totalParticipants - $totalResponses);

    // 3. Aggregate rating answers per question.
    foreach ($questions as $q) {
        $qid = (string) ($q['id'] ?? '');
        if ($qid === '' || (string) ($q['field_type'] ?? '') !== 'rating') continue;

        $dist = ['5' => 0, '4' => 0, '3' => 0, '2' => 0, '1' => 0];
        $sum = 0;
        $count = 0;

        foreach ($answers as $a) {
            $sid = (string) ($a['student_id'] ?? '');
            if ($sid === '' || !isset($participantMap[$sid])) continue;
            if ((string) ($a['question_id'] ?? '') !== $qid) continue;

            $val = intval((string) ($a['answer_text'] ?? ''));
            if ($val >= 1 && $val <= 5) {
                $dist[(string)$val]++;
                $sum += $val;
                $count++;
            }
        }

        $avg = $count > 0 ? round($sum / $count, 1) : 0;
        $analytics[$qid] = [
             'question_text' => $q['question_text'],
             'avg' => $avg,
             'count' => $count,
             'dist' => $dist
        ];
    }
}

// Compute response rate
$responseRate = ($totalParticipants > 0) ? round(($totalResponses / $totalParticipants) * 100, 1) : 0;

render_header('Evaluation Management', $user);
?>

<div class="mb-4">
    <!-- Header Row -->
    <div class="flex items-center justify-between flex-wrap gap-4 pb-4 border-b border-zinc-200 mb-6">
        <div class="flex items-center gap-3">
            <a href="/manage_events.php" class="flex items-center justify-center w-8 h-8 rounded-full bg-white border border-zinc-200 hover:bg-zinc-50 text-zinc-600 transition shadow-sm">
                <svg class="w-4 h-4 mr-0.5" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5L8.25 12l7.5-7.5"/></svg>
            </a>
            <h2 class="text-xl md:text-2xl font-bold text-zinc-900"><?= htmlspecialchars((string) ($event['title'] ?? '')) ?></h2>
            <span class="text-[10px] sm:text-xs font-bold uppercase tracking-widest rounded-md border px-2 py-0.5 <?= $statusColor ?>"><?= htmlspecialchars((string) ($event['status'] ?? '')) ?></span>
        </div>
    </div>

    <!-- TABS NAVIGATION -->
    <div class="border-b border-zinc-200 mb-6">
        <nav class="-mb-px flex space-x-6 overflow-x-auto" aria-label="Tabs">
            <a href="/event_view.php?id=<?= htmlspecialchars($eventId) ?>" class="border-transparent text-zinc-500 hover:border-zinc-300 hover:text-zinc-700 whitespace-nowrap border-b-2 py-3 px-1 text-sm font-semibold transition">
                Event Details
            </a>
            <a href="/participants.php?event_id=<?= htmlspecialchars($eventId) ?>" class="border-transparent text-zinc-500 hover:border-zinc-300 hover:text-zinc-700 whitespace-nowrap border-b-2 py-3 px-1 text-sm font-semibold transition">
                Event Participants
            </a>
            <a href="/evaluation_admin.php?event_id=<?= htmlspecialchars($eventId) ?>&tab=feedback" class="<?= $tab==='feedback' ? 'border-orange-500 text-orange-600 font-bold' : 'border-transparent text-zinc-500 hover:border-zinc-300 hover:text-zinc-700 font-semibold' ?> whitespace-nowrap border-b-2 py-3 px-1 text-sm transition">
                Event Feedback
            </a>
            <a href="/evaluation_admin.php?event_id=<?= htmlspecialchars($eventId) ?>&tab=questions" class="<?= $tab==='questions' ? 'border-orange-500 text-orange-600 font-bold' : 'border-transparent text-zinc-500 hover:border-zinc-300 hover:text-zinc-700 font-semibold' ?> whitespace-nowrap border-b-2 py-3 px-1 text-sm transition">
                Evaluation Questions
            </a>
            <?php if ($role === 'admin'): ?>
            <a href="/event_teachers.php?event_id=<?= htmlspecialchars($eventId) ?>" class="border-transparent text-zinc-500 hover:border-zinc-300 hover:text-zinc-700 whitespace-nowrap border-b-2 py-3 px-1 text-sm font-semibold transition">
                QR Scanner Access
            </a>
            <?php endif; ?>
        </nav>
    </div>

    <?php if ($tab === 'questions'): ?>
        
    <!-- ═══════════  TAB 1: EVALUATION QUESTIONS BUILDER  ═══════════ -->
    <div class="max-w-4xl mx-auto">
        <div class="flex items-center justify-between mb-8">
            <div>
                <h3 class="text-xl font-bold text-zinc-900 tracking-tight flex items-center gap-2">
                   <div class="w-8 h-8 rounded-xl bg-orange-100 border border-orange-200 flex items-center justify-center">
                     <svg class="w-4 h-4 text-orange-700" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6A2.25 2.25 0 016 3.75h2.25A2.25 2.25 0 0110.5 6v2.25a2.25 2.25 0 01-2.25 2.25H6a2.25 2.25 0 01-2.25-2.25V6zM3.75 15.75A2.25 2.25 0 016 13.5h2.25a2.25 2.25 0 012.25 2.25V18a2.25 2.25 0 01-2.25 2.25H6A2.25 2.25 0 013.75 18v-2.25zM13.5 6a2.25 2.25 0 012.25-2.25H18A2.25 2.25 0 0120.25 6v2.25A2.25 2.25 0 0118 10.5h-2.25a2.25 2.25 0 01-2.25-2.25V6zM13.5 15.75a2.25 2.25 0 012.25-2.25H18a2.25 2.25 0 012.25 2.25V18A2.25 2.25 0 0118 20.25h-2.25A2.25 2.25 0 0113.5 18v-2.25z"/></svg>
                   </div>
                   Add Event Questions
                </h3>
                <p class="text-zinc-500 text-sm mt-1">Design the evaluation form that students will see after the event.</p>
            </div>
        </div>

        <!-- Questions List Container -->
        <div id="questionsContainer" class="space-y-4 mb-6">
            <?php if (count($questions) === 0): ?>
                <div class="text-center py-12 rounded-2xl border-2 border-dashed border-zinc-200 bg-white">
                    <p class="text-sm font-semibold text-zinc-500">No evaluation questions yet.</p>
                    <p class="text-xs text-zinc-400 mt-1">Click the button below to add your first question.</p>
                </div>
            <?php endif; ?>

            <?php foreach ($questions as $i => $q): ?>
            <div class="relative bg-white rounded-3xl border border-zinc-200 p-6 shadow-sm hover:shadow-md transition-shadow group">
                <!-- Decorative left border glow -->
                <div class="absolute left-0 top-6 bottom-6 w-1 bg-orange-500 rounded-r-md opacity-0 group-hover:opacity-100 transition-opacity"></div>
                
                <div class="flex flex-col md:flex-row gap-5 items-start">
                    
                    <!-- Input Area -->
                    <div class="flex-1 w-full space-y-4">
                        <div>
                            <input type="text" class="w-full text-lg font-bold text-zinc-900 border-none bg-transparent placeholder-zinc-300 outline-none focus:ring-0 px-0 focus:border-b focus:border-orange-500 transition-colors" value="<?= htmlspecialchars((string) ($q['question_text'] ?? '')) ?>" placeholder="Untitled Question" data-qid="<?= $q['id'] ?>" readonly />
                            <div class="h-px bg-zinc-200 w-full mt-1 group-hover:bg-zinc-300"></div>
                        </div>
                        <div class="flex items-center gap-3">
                            <span class="text-[11px] font-black uppercase tracking-widest text-zinc-400">Type:</span>
                            <div class="px-3 py-1.5 rounded-lg bg-zinc-100 text-sm font-semibold text-zinc-700 flex items-center gap-2">
                                <?php if ($q['field_type'] === 'rating'): ?>
                                    <svg class="w-4 h-4 text-orange-500" fill="currentColor" viewBox="0 0 24 24"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/></svg> Likert (1-5 Scale)
                                <?php else: ?>
                                    <svg class="w-4 h-4 text-zinc-500" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25H12"/></svg> Text Comment
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Right Controls (Required Toggle & Delete) -->
                    <div class="flex items-center gap-6 shrink-0 md:border-l md:border-zinc-200 md:pl-6">
                        <div class="flex items-center gap-2.5">
                            <span class="text-xs font-bold text-zinc-500 uppercase tracking-widest">Required</span>
                            <!-- Custom Tailwind Switch -->
                            <button type="button" class="reqToggle relative inline-flex h-6 w-11 flex-shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors duration-200 ease-in-out focus:outline-none <?= $q['required'] ? 'bg-orange-500' : 'bg-zinc-200' ?>" data-qid="<?= $q['id'] ?>" aria-checked="<?= $q['required'] ? 'true':'false' ?>">
                                <span class="pointer-events-none inline-block h-5 w-5 transform rounded-full bg-white shadow ring-0 transition duration-200 ease-in-out <?= $q['required'] ? 'translate-x-5' : 'translate-x-0' ?>"></span>
                            </button>
                        </div>
                        <button class="btnDelete w-9 h-9 flex items-center justify-center rounded-xl bg-red-50 text-red-600 hover:bg-red-100 transition-colors shadow-sm" data-qid="<?= $q['id'] ?>" title="Delete Question">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 01-2.244 2.077H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 00-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 013.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 00-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 00-7.5 0"/></svg>
                        </button>
                    </div>

                </div>
            </div>
            <?php endforeach; ?>

            <!-- Temporary Builder Form (Hidden by default) -->
            <div id="newQuestionCard" class="hidden relative bg-emerald-50/50 rounded-3xl border-2 border-emerald-500/30 p-6 shadow-sm">
                 <form id="qForm" class="flex flex-col md:flex-row gap-5 items-start">
                    <input type="hidden" name="event_id" value="<?= htmlspecialchars($eventId) ?>" />
                    <input type="hidden" id="q_required" name="required" value="true" />
                    <input type="hidden" name="sort_order" value="<?= count($questions) + 1 ?>" />
                    
                    <div class="flex-1 w-full space-y-4">
                        <div>
                            <input type="text" id="question_text" name="question_text" required class="w-full text-lg font-bold text-emerald-900 border-none bg-transparent placeholder-emerald-400 outline-none focus:ring-0 px-0 focus:border-b focus:border-emerald-500 transition-colors" placeholder="Type your new question here..." />
                            <div class="h-px bg-emerald-200 w-full mt-1"></div>
                        </div>
                        <div class="flex items-center gap-3">
                            <span class="text-[11px] font-black uppercase tracking-widest text-emerald-600">Select Type:</span>
                            <select id="field_type" name="field_type" class="px-3 py-1.5 rounded-lg bg-white border border-emerald-200 text-sm font-bold text-emerald-800 outline-none focus:ring-2 focus:ring-emerald-500/30">
                                <option value="rating">Likert (1-5 Scale)</option>
                                <option value="text">Comment / Text</option>
                            </select>
                        </div>
                        <div id="qMsg" class="text-sm font-bold text-emerald-600 hidden">Saving...</div>
                    </div>
                    
                    <div class="flex items-center gap-4 shrink-0 md:border-l md:border-emerald-200 md:pl-6 h-full mt-auto">
                         <button type="button" id="btnCancelAdd" class="py-2.5 px-4 text-sm font-bold text-zinc-500 hover:text-zinc-800 transition-colors">Cancel</button>
                         <button type="submit" class="py-2.5 px-6 bg-emerald-600 hover:bg-emerald-700 text-white text-sm font-bold rounded-xl shadow-lg shadow-emerald-600/20 transition-all flex items-center gap-2">
                             <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/></svg> Save Question
                         </button>
                    </div>
                 </form>
            </div>
        </div>

        <!-- Add Button -->
        <button id="btnShowAdd" class="w-full rounded-2xl border-2 border-dashed border-zinc-200 bg-zinc-50 hover:bg-white hover:border-orange-300 py-6 flex items-center justify-center gap-3 transition-all group">
            <div class="w-8 h-8 rounded-full bg-white border border-zinc-200 flex items-center justify-center group-hover:bg-orange-50 group-hover:text-orange-600 group-hover:border-orange-200 transition-colors">
                <svg class="w-5 h-5 font-bold" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/></svg>
            </div>
            <span class="text-sm font-bold text-zinc-600 group-hover:text-orange-600">Add New Question</span>
        </button>

    </div>

    <?php else: ?>

    <!-- ═══════════  TAB 2: EVENT FEEDBACK ANALYTICS  ═══════════ -->
    <div class="max-w-4xl mx-auto">
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 mb-6">
            <div class="rounded-2xl border border-zinc-200 bg-white p-4 shadow-sm">
                <p class="text-[11px] font-bold uppercase tracking-wider text-zinc-500">Participants</p>
                <p class="text-2xl font-black text-zinc-900 leading-tight mt-1"><?= $totalParticipants ?></p>
            </div>
            <div class="rounded-2xl border border-emerald-200 bg-emerald-50 p-4 shadow-sm">
                <p class="text-[11px] font-bold uppercase tracking-wider text-emerald-700">Answered</p>
                <p class="text-2xl font-black text-emerald-900 leading-tight mt-1"><?= $totalResponses ?></p>
            </div>
            <div class="rounded-2xl border border-amber-200 bg-amber-50 p-4 shadow-sm">
                <p class="text-[11px] font-bold uppercase tracking-wider text-amber-700">Pending</p>
                <p class="text-2xl font-black text-amber-900 leading-tight mt-1"><?= $pendingParticipants ?></p>
            </div>
        </div>
        
        <?php if ($totalResponses === 0): ?>
            <div class="rounded-3xl bg-white border border-zinc-200 p-12 text-center shadow-sm">
                 <div class="w-16 h-16 rounded-full bg-zinc-100 flex items-center justify-center mx-auto mb-4">
                     <svg class="w-8 h-8 text-zinc-400" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 013 19.875v-6.75zM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V8.625zM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V4.125z"/></svg>
                 </div>
                 <h3 class="text-xl font-bold text-zinc-900 mb-1">No Feedback Yet</h3>
                 <p class="text-sm text-zinc-500">No student has submitted feedback yet. Use the Answered/Pending indicators above for quick status.</p>
            </div>
        <?php else: ?>
            <div class="flex items-center justify-between mb-8">
                <div>
                    <h3 class="text-xl font-bold text-zinc-900 tracking-tight flex items-center gap-2">
                       <div class="w-8 h-8 rounded-xl bg-emerald-100 border border-emerald-200 flex items-center justify-center">
                         <svg class="w-4 h-4 text-emerald-700" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M7.5 14.25v2.25m3-4.5v4.5m3-6.75v6.75m3-9v9M6 20.25h12A2.25 2.25 0 0020.25 18V6A2.25 2.25 0 0018 3.75H6A2.25 2.25 0 003.75 6v12A2.25 2.25 0 006 20.25z"/></svg>
                       </div>
                       Feedback Analytics
                    </h3>
                    <p class="text-sm mt-2 text-zinc-600 font-medium">
                        <span class="font-bold text-zinc-900"><?= $totalResponses ?></span>
                        <?php if ($totalParticipants > 0): ?>
                            out of <span class="font-bold text-zinc-900"><?= $totalParticipants ?></span> Responses
                            <span class="ml-2 px-2.5 py-0.5 rounded-full text-xs font-bold <?= $responseRate >= 70 ? 'bg-emerald-100 text-emerald-800 border border-emerald-200' : 'bg-amber-100 text-amber-800 border border-amber-200' ?>">
                                <?= $responseRate ?>%
                            </span>
                        <?php else: ?>
                            Total Response<?= $totalResponses!==1 ? 's':'' ?>
                        <?php endif; ?>
                    </p>
                </div>
            </div>

            <div class="space-y-6">
                 <?php foreach ($analytics as $qid => $data): ?>
                     <div class="bg-white rounded-3xl border border-zinc-200 p-6 sm:p-8 shadow-sm">
                         <!-- Card Header: Question + Response Count -->
                         <div class="flex items-start justify-between gap-4 mb-6">
                             <h4 class="text-[15px] font-bold text-zinc-900 flex gap-3">
                                 <div class="w-1.5 rounded-full bg-emerald-500 shrink-0 mt-1"></div>
                                 <?= htmlspecialchars($data['question_text']) ?>
                             </h4>
                             <div class="shrink-0 text-right">
                                 <div class="text-xs font-bold text-zinc-500"><?= $data['count'] ?> <?php if ($totalParticipants > 0): ?>out of <?= $totalParticipants ?><?php endif; ?> Responses</div>
                                 <?php if ($totalParticipants > 0): ?>
                                     <?php $cardRate = round(($data['count'] / $totalParticipants) * 100, 1); ?>
                                     <div class="text-xs font-bold <?= $cardRate >= 70 ? 'text-emerald-600' : 'text-amber-600' ?>"><?= $cardRate ?>% Response Rate</div>
                                 <?php endif; ?>
                             </div>
                         </div>
                         
                         <div class="flex flex-col md:flex-row gap-8 items-center">
                             <!-- Average Score Circle -->
                             <div class="shrink-0 flex flex-col items-center justify-center">
                                  <div class="text-[40px] font-black text-zinc-900 leading-none tracking-tighter mb-1"><?= number_format((float)$data['avg'], 1) ?></div>
                                  <div class="flex text-yellow-400 text-sm mb-1">
                                      <?php 
                                        $avg = round($data['avg']); 
                                        for($i=1; $i<=5; $i++){
                                            if($i<=$avg) echo '★'; else echo '<span class="text-zinc-200">★</span>';
                                        }
                                      ?>
                                  </div>
                                  <div class="text-[10px] font-bold text-zinc-400 uppercase tracking-widest">Average Rating</div>
                             </div>

                             <!-- Progress Bars -->
                             <div class="flex-1 w-full space-y-2.5">
                                 <?php for($star=5; $star>=1; $star--): ?>
                                     <?php 
                                        $count = $data['dist'][(string)$star]; 
                                        $percent = $data['count'] > 0 ? ($count / $data['count']) * 100 : 0;
                                     ?>
                                     <div class="flex items-center gap-3">
                                         <span class="text-sm font-bold text-zinc-500 w-3 text-right shrink-0"><?= $star ?></span>
                                         <div class="h-3 flex-1 bg-zinc-100 rounded-full overflow-hidden">
                                              <div class="h-full bg-emerald-500 rounded-full" style="width: <?= $percent ?>%"></div>
                                         </div>
                                         <span class="text-xs font-semibold text-zinc-400 w-8 text-right shrink-0"><?= $count ?></span>
                                     </div>
                                 <?php endfor; ?>
                             </div>
                         </div>
                     </div>
                 <?php endforeach; ?>
                 
                 <!-- Informational Note for Text Questions -->
                 <div class="p-4 rounded-xl bg-orange-50 border border-orange-200 flex gap-3">
                     <svg class="w-5 h-5 text-orange-600 shrink-0" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M11.25 11.25l.041-.02a.75.75 0 011.063.852l-.708 2.836a.75.75 0 001.063.853l.041-.021M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-9-3.75h.008v.008H12V8.25z"/></svg>
                     <p class="text-xs font-medium text-orange-800 leading-relaxed">
                         <strong>Note:</strong> Written comments / open-text responses are not aggregated here. You can download the full detailed feedback responses in the <a class="underline cursor-pointer">Export Analytics</a> file later.
                     </p>
                 </div>
            </div>
        <?php endif; ?>
    </div>

    <?php endif; ?>
</div>

<script>
// Toggle Add Form Visibility
const btnShowAdd = document.getElementById('btnShowAdd');
const btnCancelAdd = document.getElementById('btnCancelAdd');
const newQuestionCard = document.getElementById('newQuestionCard');

if(btnShowAdd && btnCancelAdd && newQuestionCard) {
    btnShowAdd.addEventListener('click', () => {
        btnShowAdd.classList.add('hidden');
        newQuestionCard.classList.remove('hidden');
    });
    btnCancelAdd.addEventListener('click', () => {
        btnShowAdd.classList.remove('hidden');
        newQuestionCard.classList.add('hidden');
    });
}

// Add Question Logic
const qForm = document.getElementById('qForm');
if(qForm) {
    qForm.addEventListener('submit', async (e) => {
        e.preventDefault();
        document.getElementById('qMsg').classList.remove('hidden');
        document.querySelector('button[type="submit"]').disabled = true;
        
        const fd = new FormData(qForm);
        const payload = Object.fromEntries(fd.entries());
        payload.required = document.getElementById('q_required').value === 'true'; // Hidden input value logic
        // We will default to required=true for new questions since UI requires simplification!
        payload.csrf_token = window.CSRF_TOKEN || ''; 
        
        try {
            const res = await fetch('/api/evaluation_questions_save.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            });
            const data = await res.json();
            if(!data.ok) throw new Error(data.error);
            window.location.reload();
        } catch(err) {
            document.getElementById('qMsg').textContent = 'Error: ' + err.message;
            document.querySelector('button[type="submit"]').disabled = false;
        }
    });
}

// Toggle Required
document.querySelectorAll('.reqToggle').forEach(chk => {
    chk.addEventListener('click', async () => {
        const qid = chk.dataset.qid;
        const currentChecked = chk.getAttribute('aria-checked') === 'true';
        const newRequiredState = !currentChecked;
        
        // Optimistic UI Update
        chk.setAttribute('aria-checked', newRequiredState ? 'true' : 'false');
        if (newRequiredState) {
            chk.classList.remove('bg-zinc-200'); chk.classList.add('bg-orange-500');
            chk.children[0].classList.remove('translate-x-0'); chk.children[0].classList.add('translate-x-5');
        } else {
            chk.classList.add('bg-zinc-200'); chk.classList.remove('bg-orange-500');
            chk.children[0].classList.add('translate-x-0'); chk.children[0].classList.remove('translate-x-5');
        }

        try {
            await fetch('/api/evaluation_questions_set_required.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ question_id: qid, required: newRequiredState, csrf_token: window.CSRF_TOKEN || '' })
            });
        } catch(e) { console.error('Failed to update required state natively.', e); }
    });
});

// Delete Question
document.querySelectorAll('.btnDelete').forEach(btn => {
    btn.addEventListener('click', async () => {
        if(!confirm('Permanently delete this question?')) return;
        btn.disabled = true;
        btn.innerHTML = '...';
        try {
            const res = await fetch('/api/evaluation_questions_delete.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ question_id: btn.dataset.qid, csrf_token: window.CSRF_TOKEN || '' })
            });
            const data = await res.json();
            if(!data.ok) throw new Error(data.error);
            window.location.reload();
        } catch(err) {
            alert('Error deleting: ' + err.message);
            btn.disabled = false;
        }
    });
});
</script>

<?php render_footer(); ?>
