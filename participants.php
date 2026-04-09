<?php
declare(strict_types=1);

session_start();

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/supabase.php';
require_once __DIR__ . '/includes/layout.php';

$user = require_role(['admin']);
$role = (string) ($user['role'] ?? 'admin');
$userId = (string) ($user['id'] ?? '');

$eventId = isset($_GET['event_id']) ? (string) $_GET['event_id'] : '';
if ($eventId === '') {
    http_response_code(400);
    echo 'Missing event_id';
    exit;
}

// Load event details (for day tabs + teacher ownership check)
$eventUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/events?select=id,title,start_at,end_at,created_by,status&'
    . 'id=eq.' . rawurlencode($eventId) . '&limit=1';
$headers = [
    'Accept: application/json',
    'apikey: ' . SUPABASE_KEY,
    'Authorization: Bearer ' . SUPABASE_KEY,
];
$eventRes = supabase_request('GET', $eventUrl, $headers);
$eventRows = $eventRes['ok'] ? json_decode((string) $eventRes['body'], true) : null;
$event = is_array($eventRows) && isset($eventRows[0]) ? $eventRows[0] : null;
if (!is_array($event)) {
    http_response_code(404);
    echo 'Event not found';
    exit;
}

if ($role === 'teacher') {
    $isOwner = ((string) ($event['created_by'] ?? '') === $userId);
    $isPublished = ((string) ($event['status'] ?? '') === 'published');
    if (!$isOwner && !$isPublished) {
        http_response_code(403);
        echo 'Forbidden';
        exit;
    }
}

$start = isset($event['start_at']) ? new DateTimeImmutable((string) $event['start_at']) : null;
$end = isset($event['end_at']) ? new DateTimeImmutable((string) $event['end_at']) : null;

$days = [];
$multiDay = false;
if ($start && $end) {
    $d = $start->setTime(0, 0, 0);
    $endDate = $end->setTime(0, 0, 0);
    while ($d <= $endDate) {
        $days[] = $d->format('Y-m-d');
        $d = $d->modify('+1 day');
    }
    $multiDay = count($days) > 1;
}

// Load participants
$pUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/event_registrations'
    . '?select=id,registered_at,users(first_name,middle_name,last_name,suffix,email,student_id,sections(name)),'
    . 'tickets(token,attendance(check_in_at,check_out_at,status))'
    . '&event_id=eq.' . rawurlencode($eventId)
    . '&order=registered_at.desc';

$pRes = supabase_request('GET', $pUrl, $headers);
$participants = [];
if ($pRes['ok']) {
    $decoded = json_decode((string) $pRes['body'], true);
    $participants = is_array($decoded) ? $decoded : [];
}

// Build buckets by day
$buckets = [];
$buckets['all'] = $participants;
foreach ($participants as $r) {
    $ticket = null;
    $tickets = isset($r['tickets']) ? $r['tickets'] : null;
    if (is_array($tickets)) {
        $ticket = isset($tickets[0]) && is_array($tickets[0]) ? $tickets[0] : null;
    }
    $attendance = null;
    if ($ticket && isset($ticket['attendance'])) {
        $atts = $ticket['attendance'];
        if (is_array($atts)) {
            $attendance = isset($atts[0]) && is_array($atts[0]) ? $atts[0] : (is_array($atts) ? $atts : null);
        }
    }
    if (!is_array($attendance)) {
        continue;
    }
    $checkInAt = $attendance['check_in_at'] ?? null;
    if (!$checkInAt) continue;
    try {
        $checkDate = (new DateTimeImmutable((string) $checkInAt))->format('Y-m-d');
        if (!isset($buckets[$checkDate])) $buckets[$checkDate] = [];
        $buckets[$checkDate][] = $r;
    } catch (Throwable $e) {
        // ignore invalid dates
    }
}

// Excel export
if (isset($_GET['export']) && $_GET['export'] === 'excel') {
    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    header('Content-Disposition: attachment; filename="Event_Participants_' . preg_replace('/[^A-Za-z0-9_\-]/', '_', (string)($event['title'] ?? '')) . '.xls"');
    
    // Group participants by Section
    $sectionsMap = [];
    foreach ($participants as $r) {
        $u = isset($r['users']) && is_array($r['users']) ? $r['users'] : [];
        $sec = isset($u['sections']) && is_array($u['sections']) ? $u['sections'] : null;
        $secName = is_array($sec) && isset($sec['name']) ? $sec['name'] : 'Unknown Section';
        
        $yearLvl = 'N/A';
        if (preg_match('/-([1-4])[A-Z]$/i', trim($secName), $m)) {
            $yearLvl = $m[1];
        } else if (preg_match('/([1-4])/', trim($secName), $m)) {
            $yearLvl = $m[1];
        }

        if(!isset($sectionsMap[$secName])) {
            $sectionsMap[$secName] = ['year' => $yearLvl, 'participants' => []];
        }
        $sectionsMap[$secName]['participants'][] = $r;
    }
    ksort($sectionsMap);

    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    header('Content-Disposition: attachment; filename="Event_Participants_' . preg_replace('/[^A-Za-z0-9_\-]/', '_', (string)($event['title'] ?? '')) . '.xls"');
    
    // Group participants by Section
    $sectionsMap = [];
    foreach ($participants as $r) {
        $u = isset($r['users']) && is_array($r['users']) ? $r['users'] : [];
        $sec = isset($u['sections']) && is_array($u['sections']) ? $u['sections'] : null;
        $secName = is_array($sec) && isset($sec['name']) ? $sec['name'] : 'Unknown Section';
        
        $yearLvl = 'N/A';
        if (preg_match('/-([1-4])[A-Z]$/i', trim($secName), $m)) {
            $yearLvl = $m[1];
        } else if (preg_match('/([1-4])/', trim($secName), $m)) {
            $yearLvl = $m[1];
        }

        if(!isset($sectionsMap[$secName])) {
            $sectionsMap[$secName] = ['year' => $yearLvl, 'participants' => []];
        }
        $sectionsMap[$secName]['participants'][] = $r;
    }
    ksort($sectionsMap);

    $eventTitle = strtoupper(htmlspecialchars((string)($event['title'] ?? 'UNKNOWN EVENT')));
    $eventDate = ($start ? $start->format('M d, Y') : '') . ($multiDay && $end ? ' - ' . $end->format('M d, Y') : '');

    echo '<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns="http://www.w3.org/TR/REC-html40">';
    echo '<head><meta charset="utf-8"> <style>';
    echo '  .hdr-main { font-family: "Segoe UI", Arial, sans-serif; font-size: 18pt; font-weight: bold; color: #1e293b; text-align: center; }';
    echo '  .hdr-sub  { font-family: "Segoe UI", Arial, sans-serif; font-size: 11pt; color: #64748b; font-weight: normal; text-align: center; }';
    echo '  .gen-on   { font-family: "Segoe UI", Arial, sans-serif; font-size: 9pt; color: #94a3b8; text-align: center; }';
    echo '  .logo-badge { background-color: #ea580c; color: #ffffff; font-family: "Impact", Arial, sans-serif; font-size: 24pt; font-weight: bold; text-align: center; vertical-align: middle; border: 2pt solid #c2410c; }';
    echo '  .event-hdr { background-color: #ea580c; color: #ffffff; font-family: "Segoe UI", Arial, sans-serif; font-size: 14pt; font-weight: bold; padding: 15px; text-align: center; height: 35px; border: 1pt solid #c2410c; }';
    echo '  .event-date { background-color: #fef2f2; color: #991b1b; font-family: "Segoe UI", Arial, sans-serif; font-size: 11pt; font-weight: bold; text-align: center; height: 25px; border-bottom: 2pt solid #ea580c; }';
    echo '  .sec-hdr { background-color: #1e293b; color: #ffffff; font-family: "Segoe UI", Arial, sans-serif; font-size: 11pt; font-weight: bold; padding: 10px; height: 25px; }';
    echo '  .col-hdr { background-color: #f8fafc; border: 1pt solid #cbd5e1; font-family: "Segoe UI", Arial, sans-serif; font-size: 10pt; font-weight: bold; text-align: center; height: 30px; }';
    echo '  .data-cell { border: 0.2pt solid #e2e8f0; font-family: "Segoe UI", Arial, sans-serif; font-size: 10pt; height: 25px; vertical-align: middle; }';
    echo '  .compl { color: #059669; font-weight: bold; text-align: center; background-color: #f0fdf4; }';
    echo '  .pend  { color: #d97706; font-weight: bold; text-align: center; background-color: #fffbeb; }';
    echo ' </style></head>';
    echo '<body>';
    echo '<table border="0" style="border-collapse:collapse;">';
    
    // Top Logo & Header (Merged perfectly)
    echo '<tr><td colspan="6" style="height: 10px;"></td></tr>';
    echo '<tr>';
    echo '  <td colspan="1" rowspan="3" class="logo-badge">CCS</td>'; // Styled badge logo
    echo '  <td colspan="5" class="hdr-main">COLLEGE OF COMPUTER STUDIES</td>';
    echo '</tr>';
    echo '<tr><td colspan="5" class="hdr-sub">PulseConnect Participant Registry Report</td></tr>';
    echo '<tr><td colspan="5" class="gen-on">Generated on ' . date('F j, Y, g:i A') . '</td></tr>';
    
    echo '<tr><td colspan="6" style="height: 15px;"></td></tr>';
    
    // Event Center Banner
    echo '<tr><td colspan="6" class="event-hdr">' . htmlspecialchars($eventTitle) . '</td></tr>';
    echo '<tr><td colspan="6" class="event-date">' . htmlspecialchars($eventDate) . '</td></tr>';
    echo '<tr><td colspan="6" style="height: 10px;"></td></tr>';

    foreach($sectionsMap as $secName => $secData) {
        $secText = 'SECTION: ' . strtoupper(htmlspecialchars($secName)) . '   |   YEAR LEVEL: ' . htmlspecialchars($secData['year']);
        echo '<tr><td colspan="6" class="sec-hdr">' . $secText . '</td></tr>';
        
        echo '<tr>';
        echo ' <th class="col-hdr" style="width:300px;">STUDENT NAME</th>';
        echo ' <th class="col-hdr" style="width:130px;">STUDENT NUMBER</th>';
        echo ' <th class="col-hdr" style="width:80px;">YEAR</th>';
        echo ' <th class="col-hdr" style="width:150px;">SECTION</th>';
        echo ' <th class="col-hdr" style="width:180px;">CHECK IN</th>';
        echo ' <th class="col-hdr" style="width:180px;">CHECK OUT</th>';
        echo ' <th class="col-hdr" style="width:120px;">STATUS</th>';
        echo '</tr>';
        
        foreach($secData['participants'] as $r) {
            $u = isset($r['users']) && is_array($r['users']) ? $r['users'] : [];
            $nameParts = [];
            foreach (['first_name','middle_name','last_name'] as $k) {
                $v = trim((string) ($u[$k] ?? ''));
                if ($v !== '') $nameParts[] = $v;
            }
            $name = implode(' ', $nameParts);
            $suffix = trim((string) ($u['suffix'] ?? ''));
            if ($suffix !== '') $name .= ', ' . $suffix;
            
            $tickets = isset($r['tickets']) && is_array($r['tickets']) ? $r['tickets'] : [];
            $ticket = isset($tickets[0]) && is_array($tickets[0]) ? $tickets[0] : [];
            $attendance = null;
            if (isset($ticket['attendance'])) {
                $atts = $ticket['attendance'];
                if (is_array($atts)) {
                    $attendance = isset($atts[0]) && is_array($atts[0]) ? $atts[0] : (isset($atts) && is_array($atts) ? $atts : null);
                }
            }
            $checkIn = is_array($attendance) ? ($attendance['check_in_at'] ?? '') : '';
            $checkOut = is_array($attendance) ? ($attendance['check_out_at'] ?? '') : '';
            $attStatus = is_array($attendance) ? ($attendance['status'] ?? 'pending') : 'pending';
            
            if($checkIn) $checkIn = (new DateTimeImmutable($checkIn))->format('m/d/Y h:i A');
            if($checkOut) $checkOut = (new DateTimeImmutable($checkOut))->format('m/d/Y h:i A');
            
            $isComp = (strtolower($attStatus) === 'completed');
            $statusStr = $isComp ? 'COMPLETED' : 'PENDING';
            $statusCls = $isComp ? 'compl' : 'pend';
            
            echo '<tr>';
            echo ' <td class="data-cell" style="padding-left: 5px;">' . htmlspecialchars($name) . '</td>';
            echo ' <td class="data-cell" style="text-align:center; font-family: monospace;">' . htmlspecialchars((string)($u['student_id'] ?? 'N/A')) . '</td>';
            echo ' <td class="data-cell" style="text-align:center;">' . htmlspecialchars($secData['year']) . '</td>';
            echo ' <td class="data-cell" style="text-align:center;">' . htmlspecialchars($secName) . '</td>';
            echo ' <td class="data-cell" style="text-align:center;">' . ($checkIn ? htmlspecialchars($checkIn) : '-') . '</td>';
            echo ' <td class="data-cell" style="text-align:center;">' . ($checkOut ? htmlspecialchars($checkOut) : '-') . '</td>';
            echo ' <td class="data-cell ' . $statusCls . '">' . $statusStr . '</td>';
            echo '</tr>';
        }
        echo '<tr><td colspan="6" style="height: 10px;"></td></tr>';
    }
    
    echo '</table></body></html>';
    exit;
}

// CSV export
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="participants.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Name', 'StudentNumber', 'Email', 'RegisteredAt', 'Token', 'CheckIn', 'CheckOut', 'AttendanceStatus']);
    foreach ($participants as $r) {
        $u = isset($r['users']) && is_array($r['users']) ? $r['users'] : [];
        $nameParts = [];
        foreach (['first_name','middle_name','last_name'] as $k) {
            $v = trim((string) ($u[$k] ?? ''));
            if ($v !== '') $nameParts[] = $v;
        }
        $name = implode(' ', $nameParts);
        $suffix = trim((string) ($u['suffix'] ?? ''));
        if ($suffix !== '') $name .= ', ' . $suffix;

        $tickets = isset($r['tickets']) && is_array($r['tickets']) ? $r['tickets'] : [];
        $ticket = isset($tickets[0]) && is_array($tickets[0]) ? $tickets[0] : [];
        $token = (string) ($ticket['token'] ?? '');

        $attendance = null;
        if (isset($ticket['attendance'])) {
            $atts = $ticket['attendance'];
            if (is_array($atts)) {
                $attendance = isset($atts[0]) && is_array($atts[0]) ? $atts[0] : (isset($atts) && is_array($atts) ? $atts : null);
            }
        }
        $checkIn = is_array($attendance) ? ($attendance['check_in_at'] ?? '') : '';
        $checkOut = is_array($attendance) ? ($attendance['check_out_at'] ?? '') : '';
        $attStatus = is_array($attendance) ? ($attendance['status'] ?? '') : '';

        fputcsv($out, [
            $name,
            (string) ($u['student_id'] ?? 'N/A'),
            (string) ($u['email'] ?? ''),
            (string) ($r['registered_at'] ?? ''),
            $token,
            is_string($checkIn) ? $checkIn : '',
            is_string($checkOut) ? $checkOut : '',
            (string) $attStatus,
        ]);
    }
    fclose($out);
    exit;
}

$activeDay = isset($_GET['day']) ? (string) $_GET['day'] : 'all';
if ($activeDay !== 'all' && !isset($buckets[$activeDay])) $activeDay = 'all';
$rows = $buckets[$activeDay] ?? [];

render_header('Participants', $user);
?>

<div class="mb-8 flex flex-col sm:flex-row sm:items-start justify-between gap-4">
  <div>
    <h2 class="text-xl font-bold text-zinc-900 mb-1 leading-tight"><?= htmlspecialchars((string) ($event['title'] ?? 'Event')) ?></h2>
    <p class="text-zinc-600 text-sm">Participant directory and real-time attendance tracking.</p>
  </div>
  <div class="flex flex-wrap items-center gap-2.5">
    <a href="/participants.php?event_id=<?= htmlspecialchars($eventId) ?>&export=excel" class="rounded-xl border border-emerald-200 bg-emerald-600 text-white px-4 py-2 text-sm font-semibold hover:bg-emerald-700 transition shadow-sm flex items-center gap-2 group">
      <svg class="w-4 h-4 group-hover:scale-110 transition-transform" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5M16.5 12L12 16.5m0 0L7.5 12m4.5 4.5V3"/></svg>
      Export Excel
    </a>
    <a href="/manage_events.php" class="rounded-xl border border-zinc-200 bg-white px-4 py-2 text-sm text-zinc-800 hover:bg-zinc-50 transition font-medium flex items-center gap-1.5 shadow-sm">
      <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5L3 12m0 0l7.5-7.5M3 12h18"/></svg>
      Back
    </a>
  </div>
</div>

<!-- TABS NAVIGATION (Cloned from event_view.php) -->
<div class="border-b border-zinc-200 mb-6">
    <nav class="-mb-px flex space-x-6 overflow-x-auto" aria-label="Tabs">
        <a href="/event_view.php?id=<?= htmlspecialchars($eventId) ?>" class="border-transparent text-zinc-500 hover:border-zinc-300 hover:text-zinc-700 whitespace-nowrap border-b-2 py-3 px-1 text-sm font-semibold transition">
            Event Details
        </a>
        <a href="/participants.php?event_id=<?= htmlspecialchars($eventId) ?>" class="border-orange-500 text-orange-600 whitespace-nowrap border-b-2 py-3 px-1 text-sm font-bold">
            Event Participants
        </a>
        <a href="/evaluation_admin.php?event_id=<?= htmlspecialchars($eventId) ?>&tab=feedback" class="border-transparent text-zinc-500 hover:border-zinc-300 hover:text-zinc-700 whitespace-nowrap border-b-2 py-3 px-1 text-sm font-semibold transition">
            Event Feedback
        </a>
        <a href="/evaluation_admin.php?event_id=<?= htmlspecialchars($eventId) ?>&tab=questions" class="border-transparent text-zinc-500 hover:border-zinc-300 hover:text-zinc-700 whitespace-nowrap border-b-2 py-3 px-1 text-sm font-semibold transition">
            Evaluation Questions
        </a>
    </nav>
</div>

<?php if ($multiDay): ?>
  <div class="mb-6 flex gap-2 flex-wrap bg-zinc-100 p-1.5 rounded-2xl border border-zinc-200 w-full sm:w-fit">
    <a class="px-4 py-2 rounded-xl text-xs font-bold transition-all <?= $activeDay === 'all' ? 'bg-orange-600 text-white shadow-sm' : 'text-zinc-600 hover:bg-white' ?>"
       href="/participants.php?event_id=<?= htmlspecialchars($eventId) ?>&day=all">All Days</a>
    <?php foreach ($days as $day): ?>
      <a class="px-4 py-2 rounded-xl text-xs font-bold transition-all <?= $activeDay === $day ? 'bg-orange-600 text-white shadow-sm' : 'text-zinc-600 hover:bg-white' ?>"
         href="/participants.php?event_id=<?= htmlspecialchars($eventId) ?>&day=<?= htmlspecialchars($day) ?>"><?= htmlspecialchars((new DateTimeImmutable($day))->format('M d, Y')) ?></a>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-5">
  <div class="flex items-center gap-4">
    <h3 class="text-lg font-bold text-zinc-900 tracking-tight flex items-center gap-2">
       <div class="w-8 h-8 rounded-xl bg-orange-100 border border-orange-200 flex items-center justify-center">
         <svg class="w-4 h-4 text-orange-700" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 002.625.372 9.337 9.337 0 004.121-.952 4.125 4.125 0 00-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 018.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0111.964-3.07M12 6.375a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zm8.25 2.25a2.625 2.625 0 11-5.25 0 2.625 2.625 0 015.25 0z"/></svg>
       </div>
       Registered Attendees
    </h3>
    <div class="px-3.5 py-1.5 rounded-xl bg-zinc-100 border border-zinc-200 flex items-center gap-2">
       <span class="text-[11px] font-bold text-zinc-600 uppercase tracking-wider">Total</span>
       <span id="totalCount" class="text-base font-bold text-zinc-900 leading-none"><?= count($rows) ?></span>
    </div>
  </div>

  <div class="relative w-full sm:w-80 group">
    <div class="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none text-zinc-400 group-focus-within:text-orange-500 transition-colors">
      <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z"/></svg>
    </div>
    <input type="text" id="participantSearch" placeholder="Search name, email, or section..." 
      class="block w-full pl-10 pr-4 py-2.5 bg-white border border-zinc-200 rounded-xl text-sm placeholder:text-zinc-400 focus:outline-none focus:ring-2 focus:ring-orange-500/20 focus:border-orange-500 transition shadow-sm">
  </div>
</div>

<div class="pb-10 relative">
  <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-4">
    <?php if (count($rows) === 0): ?>
      <div class="col-span-full rounded-3xl border border-dashed border-zinc-300 bg-zinc-50 py-16 flex flex-col items-center justify-center pointer-events-none">
        <svg class="w-10 h-10 text-zinc-400 mb-3" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 002.625.372 9.337 9.337 0 004.121-.952 4.125 4.125 0 00-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 018.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0111.964-3.07M12 6.375a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zm8.25 2.25a2.625 2.625 0 11-5.25 0 2.625 2.625 0 015.25 0z"/></svg>
        <p class="text-zinc-700 font-semibold text-sm">No participants found</p>
      </div>
    <?php endif; ?>

    <?php foreach ($rows as $r): ?>
      <?php
        $u = isset($r['users']) && is_array($r['users']) ? $r['users'] : [];
        $nameParts = [];
        foreach (['first_name','middle_name','last_name'] as $k) {
            $v = trim((string) ($u[$k] ?? ''));
            if ($v !== '') $nameParts[] = $v;
        }
        $name = implode(' ', $nameParts);
        $suffix = trim((string) ($u['suffix'] ?? ''));
        if ($suffix !== '') $name .= ', ' . $suffix;

        $tickets = isset($r['tickets']) && is_array($r['tickets']) ? $r['tickets'] : [];
        $ticket = isset($tickets[0]) && is_array($tickets[0]) ? $tickets[0] : [];
        $token = (string) ($ticket['token'] ?? '');

        $attendance = null;
        if (isset($ticket['attendance'])) {
            $atts = $ticket['attendance'];
            if (is_array($atts)) {
                $attendance = isset($atts[0]) && is_array($atts[0]) ? $atts[0] : $atts;
            }
        }
        
        $checkInRaw = is_array($attendance) ? ($attendance['check_in_at'] ?? '') : '';
        $checkOutRaw = is_array($attendance) ? ($attendance['check_out_at'] ?? '') : '';
        $attStatus = is_array($attendance) ? ($attendance['status'] ?? '') : '';
        $registrationId = (string) ($r['id'] ?? '');

        // Generate Initials
        $initials = '';
        foreach ($nameParts as $p) { $initials .= mb_strtoupper(mb_substr($p, 0, 1)); if (mb_strlen($initials) >= 2) break; }
        if (mb_strlen($initials)===0) $initials = '?';

        // Format times
        $checkInFormat = '—';
        if ($checkInRaw) {
           try { $checkInFormat = (new DateTimeImmutable($checkInRaw))->format('M d, g:i A'); } catch (Throwable $e) {}
        }
        $checkOutFormat = '—';
        if ($checkOutRaw) {
           try { $checkOutFormat = (new DateTimeImmutable($checkOutRaw))->format('M d, g:i A'); } catch (Throwable $e) {}
        }

        $sec = isset($u['sections']) && is_array($u['sections']) ? $u['sections'] : null;
        $secName = is_array($sec) && isset($sec['name']) ? $sec['name'] : 'N/A';
        $yearLvl = 'N/A';
        if (preg_match('/-([1-4])[A-Z]$/i', trim($secName), $m)) {
            $yearLvl = $m[1] . (match($m[1]){'1'=>'st','2'=>'nd','3'=>'rd','4'=>'th',default=>''}) . ' Year';
        } else if (preg_match('/([1-4])/', trim($secName), $m)) {
            $yearLvl = $m[1] . (match($m[1]){'1'=>'st','2'=>'nd','3'=>'rd','4'=>'th',default=>''}) . ' Year';
        }

        $attStatusColor = match((string)$attStatus) {
            'present' => 'bg-emerald-100 text-emerald-900 border-emerald-200',
            'late' => 'bg-amber-100 text-amber-900 border-amber-200',
            'early' => 'bg-sky-100 text-sky-900 border-sky-200',
            default => 'bg-zinc-100 text-zinc-800 border-zinc-200',
        };
      ?>
      <div class="participant-card group relative rounded-2xl bg-white border border-zinc-200 p-5 shadow-sm hover:border-orange-200 hover:shadow-md transition-all flex flex-col justify-between"
           data-search="<?= htmlspecialchars(strtolower($name . ' ' . (string)($u['email'] ?? '') . ' ' . (string)($u['student_id'] ?? '') . ' ' . $secName)) ?>">
        
        <div class="flex items-start gap-4 mb-5 relative z-10 w-full overflow-hidden">
          <div class="w-12 h-12 rounded-2xl bg-orange-100 border border-orange-200 flex items-center justify-center text-orange-800 text-base font-bold flex-shrink-0">
             <?= htmlspecialchars($initials) ?>
          </div>
          <div class="min-w-0 flex-1">
            <h4 class="text-base font-bold text-zinc-900 tracking-wide truncate pr-2" title="<?= htmlspecialchars($name) ?>"><?= htmlspecialchars($name) ?></h4>
            <p class="text-[11px] font-medium text-zinc-600 truncate mt-0.5 mb-1" title="<?= htmlspecialchars((string)($u['email'] ?? '')) ?>"><?= htmlspecialchars((string)($u['email'] ?? '')) ?></p>
            
            <div class="flex flex-wrap items-center gap-1.5 mb-2.5">
                <p class="text-[10px] font-mono font-bold text-orange-600 truncate bg-orange-50 w-fit px-1.5 py-0.5 rounded-md border border-orange-100">#<?= htmlspecialchars((string)($u['student_id'] ?? 'N/A')) ?></p>
                <div class="flex items-center gap-1 px-1.5 py-0.5 rounded-md bg-zinc-50 border border-zinc-100 text-[10px] font-bold text-zinc-600">
                    <svg class="w-3 h-3 text-zinc-400" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 21h16.5M4.5 3h15M5.25 3v18m13.5-18v18M9 6.75h1.5m-1.5 3h1.5m-1.5 3h1.5m3-6H15m-1.5 3H15m-1.5 3H15M9 21v-3.375c0-.621.504-1.125 1.125-1.125h3.75c.621 0 1.125.504 1.125 1.125V21"/></svg>
                    <?= htmlspecialchars($secName) ?>
                </div>
                <div class="flex items-center gap-1 px-1.5 py-0.5 rounded-md bg-blue-50 border border-blue-100 text-[10px] font-bold text-blue-600">
                    <svg class="w-3 h-3 text-blue-400" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M4.26 10.147a60.436 60.436 0 00-.491 6.347A48.627 48.627 0 0112 20.904a48.627 48.627 0 018.232-4.41 60.46 60.46 0 00-.491-6.347m-15.482 0a50.57 50.57 0 00-2.658-.813A59.905 59.905 0 0112 3.493a59.902 59.902 0 0110.399 5.84c-.896.248-1.783.52-2.658.814m-15.482 0A50.697 50.697 0 0112 13.489a50.702 50.702 0 017.74-3.342M6.75 15a.75.75 0 100-1.5.75.75 0 000 1.5zm0 0v-3.675A55.378 55.378 0 0112 8.443m-7.007 11.55A5.981 5.981 0 006.75 15.75v-1.5"/></svg>
                    <?= htmlspecialchars($yearLvl) ?>
                </div>
            </div>
            
            <?php if ((string)$attStatus !== ''): ?>
              <div class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-md border <?= $attStatusColor ?>">
                 <span class="text-[10px] font-bold uppercase tracking-wider truncate"><?= htmlspecialchars((string) $attStatus) ?></span>
              </div>
            <?php else: ?>
               <div class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-md border border-zinc-200 bg-zinc-50">
                 <span class="text-[10px] font-bold text-zinc-600 uppercase tracking-wider truncate">Unscanned</span>
               </div>
            <?php endif; ?>
          </div>
        </div>

        <div class="mt-auto pt-4 border-t border-zinc-200 flex flex-col gap-2.5 relative z-10">
           <div class="flex flex-col sm:flex-row sm:justify-between sm:items-center gap-1">
              <span class="text-[10px] font-bold text-zinc-500 uppercase tracking-widest flex items-center gap-1.5">
                 <div class="w-2 h-2 rounded-full bg-sky-500"></div> Check-In
              </span>
              <span class="text-xs font-semibold text-zinc-900"><?= $checkInFormat ?></span>
           </div>
           <div class="flex flex-col sm:flex-row sm:justify-between sm:items-center gap-1">
              <span class="text-[10px] font-bold text-zinc-500 uppercase tracking-widest flex items-center gap-1.5">
                 <div class="w-2 h-2 rounded-full bg-red-500"></div> Check-Out
              </span>
              <span class="text-xs font-semibold text-zinc-900"><?= $checkOutFormat ?></span>
           </div>
           <div class="flex flex-col sm:flex-row sm:justify-between sm:items-center gap-1 mt-2 border-t border-zinc-100 pt-3">
              <span class="text-[10px] font-bold text-zinc-500 uppercase tracking-widest flex items-center gap-1.5">
                 <svg class="w-3.5 h-3.5 text-zinc-400" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M16.5 6v.75m0 3v.75m0 3v.75m0 3V18m-9-5.25h5.25M7.5 15h3M3.375 5.25c-.621 0-1.125.504-1.125 1.125v3.026a2.999 2.999 0 010 5.198v3.026c0 .621.504 1.125 1.125 1.125h17.25c.621 0 1.125-.504 1.125-1.125v-3.026a2.999 2.999 0 010-5.198V6.375c0-.621-.504-1.125-1.125-1.125H3.375z"/></svg> Token
              </span>
              <span class="font-mono text-[11px] text-zinc-600 truncate max-w-[150px]"><?= htmlspecialchars($token) ?></span>
           </div>
            
           <?php if ($role === 'admin'): ?>
              <button class="btnRemove w-full mt-3 rounded-xl border border-red-200 bg-red-50 px-3 py-2 text-xs font-semibold text-red-800 hover:bg-red-100 transition" data-id="<?= htmlspecialchars($registrationId) ?>">
                 Remove Participant
              </button>
           <?php endif; ?>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
</div>

<?php if ($role === 'admin'): ?>
<script>
  document.querySelectorAll('.btnRemove').forEach(btn => {
    btn.addEventListener('click', async () => {
      const ok = confirm('Remove this participant?');
      if (!ok) return;
      btn.disabled = true;
      try {
        const registration_id = btn.dataset.id;
        const res = await fetch('/api/participant_remove.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ registration_id, csrf_token: window.CSRF_TOKEN })
        });
        const data = await res.json();
        if (!data.ok) throw new Error(data.error || 'Failed');
        window.location.reload();
      } catch (e) {
        alert(e.message || 'Failed');
      } finally {
        btn.disabled = false;
      }
    });
  });

  // Client-side live search
  const searchInput = document.getElementById('participantSearch');
  const totalCountEl = document.getElementById('totalCount');
  const cards = document.querySelectorAll('.participant-card');
  const emptyState = document.querySelector('.pointer-events-none');

  if (searchInput) {
    searchInput.addEventListener('input', (e) => {
      const term = e.target.value.toLowerCase().trim();
      let visibleCount = 0;

      cards.forEach(card => {
        const searchable = card.dataset.search;
        if (searchable.includes(term)) {
          card.classList.remove('hidden');
          visibleCount++;
        } else {
          card.classList.add('hidden');
        }
      });

      if (totalCountEl) totalCountEl.textContent = visibleCount;
      
      if (emptyState) {
        if (visibleCount === 0 && cards.length > 0) {
          emptyState.classList.remove('hidden');
          emptyState.querySelector('p').textContent = 'No results match your search';
        } else if (cards.length === 0) {
          emptyState.classList.remove('hidden');
          emptyState.querySelector('p').textContent = 'No participants found';
        } else {
          emptyState.classList.add('hidden');
        }
      }
    });
  }
</script>
<?php endif; ?>

<?php render_footer(); ?>
