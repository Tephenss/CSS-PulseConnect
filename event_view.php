<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/session.php';
session_bootstrap();

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/supabase.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/event_sessions.php';
require_once __DIR__ . '/includes/event_tabs.php';
require_once __DIR__ . '/includes/registration_access.php';
require_once __DIR__ . '/includes/student_requirements.php';
require_once __DIR__ . '/includes/certificate_code_pool.php';

$user = require_role(['student', 'teacher', 'admin']);
$role = (string) ($user['role'] ?? 'student');
$userId = (string) ($user['id'] ?? '');

// Linked certs / saved templates are rendered inline, so a cached page would show
// a stale Import/Link modal after editing a design.
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$id = isset($_GET['id']) ? (string) $_GET['id'] : '';
if ($id === '') {
    http_response_code(400);
    echo 'Missing event id';
    exit;
}

$headers = [
    'Accept: application/json',
    'apikey: ' . SUPABASE_KEY,
    'Authorization: Bearer ' . SUPABASE_KEY,
];
$event = fetch_event_with_registration_settings($id, $headers);

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

$sessions = fetch_event_sessions($id, $headers);
$sessionsJsonForAttr = htmlspecialchars(
    (string) (json_encode($sessions, JSON_UNESCAPED_SLASHES | JSON_HEX_APOS | JSON_HEX_QUOT) ?: '[]'),
    ENT_QUOTES
);

$effectiveEndAt = null;
try {
    $effectiveEndAt = !empty($event['end_at']) ? new DateTimeImmutable((string) $event['end_at']) : null;
} catch (Throwable $e) {
    $effectiveEndAt = null;
}
foreach ($sessions as $session) {
    try {
        $sessionEnd = !empty($session['end_at'])
            ? new DateTimeImmutable((string) $session['end_at'])
            : (!empty($session['start_at']) ? (new DateTimeImmutable((string) $session['start_at']))->modify('+1 hour') : null);
    } catch (Throwable $e) {
        $sessionEnd = null;
    }

    if ($sessionEnd instanceof DateTimeImmutable && (!$effectiveEndAt instanceof DateTimeImmutable || $sessionEnd > $effectiveEndAt)) {
        $effectiveEndAt = $sessionEnd;
    }
}
$eventFinishedForCertificates = $effectiveEndAt instanceof DateTimeImmutable
    ? $effectiveEndAt <= new DateTimeImmutable('now')
    : false;

// Keep detail badge aligned with list behavior:
// if this published event has already ended, mark it finished.
if (strtolower(trim((string) ($event['status'] ?? ''))) === 'published'
    && $eventFinishedForCertificates) {
    try {
        $finishUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/events'
            . '?id=eq.' . rawurlencode($id)
            . '&status=eq.published';
        $finishHeaders = [
            'Accept: application/json',
            'Content-Type: application/json',
            'Prefer: return=minimal',
            'apikey: ' . SUPABASE_KEY,
            'Authorization: Bearer ' . SUPABASE_KEY,
        ];
        $finishPayload = json_encode(['status' => 'finished'], JSON_UNESCAPED_SLASHES);
        if (is_string($finishPayload)) {
            supabase_request('PATCH', $finishUrl, $finishHeaders, $finishPayload);
            $event['status'] = 'finished';
        }
    } catch (Throwable $e) {
        // Keep rendering if status sync fails.
    }
}

// 2. Registration / attendance stats (counts only — no full participant dump).
$totalRegistered = fetch_event_registration_count($id, $headers, is_array($event) ? $event : null);
$completedCount = supabase_exact_count(
    'attendance',
    $headers,
    'status=neq.unscanned&tickets.event_registrations.event_id=eq.' . rawurlencode($id)
);
// If embed filter is unsupported, fall back to a minimal status-only download.
if ($totalRegistered > 0 && $completedCount === 0) {
    $probeUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/event_registrations'
        . '?select=' . rawurlencode('tickets(attendance(status))')
        . '&event_id=eq.' . rawurlencode($id)
        . '&limit=5000';
    $probeRes = supabase_request('GET', $probeUrl, $headers);
    $probeRows = $probeRes['ok'] ? json_decode((string) $probeRes['body'], true) : null;
    if (is_array($probeRows)) {
        $completedCount = 0;
        foreach ($probeRows as $p) {
            if (!is_array($p)) {
                continue;
            }
            $statusStr = '';
            $tickets = $p['tickets'] ?? null;
            if (is_array($tickets) && isset($tickets[0]) && is_array($tickets[0])) {
                $atts = $tickets[0]['attendance'] ?? null;
                if (is_array($atts)) {
                    $firstAtt = isset($atts[0]) && is_array($atts[0]) ? $atts[0] : $atts;
                    $statusStr = (string) ($firstAtt['status'] ?? '');
                }
            }
            if ($statusStr !== '' && $statusStr !== 'unscanned') {
                $completedCount++;
            }
        }
    }
}
$nonCompletedCount = max(0, $totalRegistered - $completedCount);
$studentRegistrationAccess = null;

if ($role === 'student') {
    $studentProfile = fetch_student_profile_by_id((string) ($user['id'] ?? ''), $headers);
    if (is_array($studentProfile)) {
        $studentRegistrationAccess = resolve_student_registration_access($event, $studentProfile, $headers);
    }
}

$certificateTemplates = [];
$isEventCreatorTeacherEarly = $role === 'teacher' && (string) ($event['created_by'] ?? '') === $userId;
if ($isEventCreatorTeacherEarly) {
    $sessionLookup = [];
    foreach ($sessions as $sessionRow) {
        if (!is_array($sessionRow)) {
            continue;
        }
        $sid = (string) ($sessionRow['id'] ?? '');
        if ($sid === '') {
            continue;
        }
        $sessionLookup[$sid] = $sessionRow;
    }

    $eventTemplateRows = null;
    $eventTemplateSelects = [
        '?select=id,title,thumbnail_url,event_id,created_by,created_at,updated_at&order=updated_at.desc.nullslast&or=(created_by.eq.' . rawurlencode($userId) . ',event_id.eq.' . rawurlencode($id) . ')',
        '?select=id,title,thumbnail_url,event_id,created_by,created_at,updated_at&created_by=eq.' . rawurlencode($userId) . '&order=updated_at.desc.nullslast',
        '?select=id,title,thumbnail_url,event_id,created_by,created_at&order=created_at.desc&or=(created_by.eq.' . rawurlencode($userId) . ',event_id.eq.' . rawurlencode($id) . ')',
        '?select=id,title,thumbnail_url,event_id,created_by,created_at&created_by=eq.' . rawurlencode($userId) . '&order=created_at.desc',
        '?select=id,title,event_id,created_at&order=created_at.desc',
        '?select=id,title,event_id&order=id.desc',
    ];
    foreach ($eventTemplateSelects as $selectQuery) {
        $eventTemplateUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/certificate_templates' . $selectQuery;
        $eventTemplateRes = supabase_request('GET', $eventTemplateUrl, $headers);
        if (!$eventTemplateRes['ok']) {
            continue;
        }
        $decodedRows = json_decode((string) $eventTemplateRes['body'], true);
        if (is_array($decodedRows)) {
            $eventTemplateRows = $decodedRows;
            break;
        }
    }
    if (is_array($eventTemplateRows)) {
        foreach ($eventTemplateRows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $rowEventId = (string) ($row['event_id'] ?? '');
            $rowOwner = (string) ($row['created_by'] ?? '');
            // Prefer teacher-owned library + templates already on this event.
            if ($rowOwner !== '' && $rowOwner !== $userId && $rowEventId !== $id) {
                continue;
            }
            $row['thumbnail_url'] = (string) ($row['thumbnail_url'] ?? '');
            $row['created_at'] = (string) ($row['created_at'] ?? '');
            $row['updated_at'] = (string) ($row['updated_at'] ?? $row['created_at'] ?? '');
            $certificateTemplates[] = [
                ...$row,
                'template_scope' => 'event',
                'scope_session_id' => '',
                'scope_label' => $rowEventId === $id ? 'Linked to event' : 'Saved template',
                'linked_event_label' => $rowEventId === $id
                    ? (string) ($event['title'] ?? 'Current Event')
                    : 'Template Library',
            ];
        }
    }

    $sessionIds = array_values(array_filter(array_map(
        static fn (array $session): string => (string) ($session['id'] ?? ''),
        $sessions
    )));
    if (count($sessionIds) > 0) {
        $sessionTemplateRows = null;
        $sessionSelects = [
            ['query' => '?select=id,title,thumbnail_url,session_id,created_at', 'order' => '&order=created_at.desc'],
            ['query' => '?select=id,title,session_id,created_at', 'order' => '&order=created_at.desc'],
            ['query' => '?select=id,title,session_id', 'order' => '&order=id.desc'],
        ];
        foreach ($sessionSelects as $selectConfig) {
            $sessionTemplateUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/event_session_certificate_templates'
                . (string) ($selectConfig['query'] ?? '')
                . '&session_id=in.(' . implode(',', array_map('rawurlencode', $sessionIds)) . ')'
                . (string) ($selectConfig['order'] ?? '');
            $sessionTemplateRes = supabase_request('GET', $sessionTemplateUrl, $headers);
            if (!$sessionTemplateRes['ok']) {
                continue;
            }
            $decodedRows = json_decode((string) $sessionTemplateRes['body'], true);
            if (is_array($decodedRows)) {
                $sessionTemplateRows = $decodedRows;
                break;
            }
        }
        if (is_array($sessionTemplateRows)) {
            foreach ($sessionTemplateRows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $row['thumbnail_url'] = (string) ($row['thumbnail_url'] ?? '');
                $row['created_at'] = (string) ($row['created_at'] ?? '');
                $sessionMeta = $sessionLookup[(string) ($row['session_id'] ?? '')] ?? [];
                $certificateTemplates[] = [
                    ...$row,
                    'template_scope' => 'session',
                    'scope_session_id' => (string) ($row['session_id'] ?? ''),
                    'scope_label' => build_session_display_name($sessionMeta),
                    'linked_event_label' => (string) ($event['title'] ?? 'Current Event'),
                ];
            }
        }
    }

    usort($certificateTemplates, static function (array $a, array $b): int {
        $au = (string) ($a['updated_at'] ?? $a['created_at'] ?? '');
        $bu = (string) ($b['updated_at'] ?? $b['created_at'] ?? '');
        return strcmp($bu, $au);
    });
}

// Registration openness is now separate from publish status.
$status = (string)($event['status'] ?? '');
$isFinishedEvent = strtolower(trim($status)) === 'finished';
$isRegistrationAllowed = event_allows_open_registration($event);
$canToggleRegistration = $status === 'published';
$isPaidEvent = !event_is_free_registration_event($event);
$eventStatusLower = strtolower(trim($status));
$showEventQr = in_array($role, ['admin', 'teacher'], true)
    && in_array($eventStatusLower, ['published', 'finished', 'expired'], true);
$eventQrPayload = 'PULSE-EVENT-' . $id;

$statusColor = match($status) {
    'published' => 'bg-emerald-100 text-emerald-900 border-emerald-200',
    'finished' => 'bg-zinc-200 text-zinc-700 border-zinc-300',
    'pending' => 'bg-amber-100 text-amber-900 border-amber-200',
    'approved' => 'bg-sky-100 text-sky-900 border-sky-200',
    'draft' => 'bg-orange-100 text-orange-900 border-orange-200',
    default => 'bg-zinc-100 text-zinc-800 border-zinc-200',
};

$isEventCreatorTeacher = $role === 'teacher' && (string) ($event['created_by'] ?? '') === $userId;
$canManageCertificates = $isEventCreatorTeacher;
$hasStudentRequirements = event_has_student_requirements($id, $headers);
// Document Review uses the dedicated page tab — no overlay modal from Event Details.
$showDocumentReviewModal = false;

$studentCanRegister = is_array($studentRegistrationAccess)
    ? (bool) ($studentRegistrationAccess['allowed'] ?? false)
    : false;
$studentTargetAllowed = is_array($studentRegistrationAccess)
    ? (bool) ($studentRegistrationAccess['target_allowed'] ?? false)
    : false;
$studentNeedsApproval = is_array($studentRegistrationAccess)
    ? (bool) ($studentRegistrationAccess['approval_required'] ?? false)
    : false;
$studentRegistrationMessage = is_array($studentRegistrationAccess)
    ? trim((string) ($studentRegistrationAccess['message'] ?? ''))
    : '';
$studentButtonLabel = $studentCanRegister
    ? 'Register & Get Ticket'
    : (!$studentTargetAllowed
        ? 'Not Eligible for Registration'
        : ($studentNeedsApproval ? 'Settle Payment First' : 'Registration Closed'));
$studentButtonClasses = $studentCanRegister
    ? 'rounded-xl bg-gradient-to-r from-orange-600 to-red-600 text-white px-6 py-3 text-sm font-bold hover:from-orange-500 hover:to-red-500 transition-all shadow-lg shadow-orange-600/20'
    : 'rounded-xl bg-zinc-200 text-zinc-500 cursor-not-allowed px-6 py-3 text-sm font-bold';

// Start outputting UI
render_header('Event Details', $user);
?>

<?php
    $backUrl = event_management_return_to(
        $role === 'student' ? 'admin' : $role,
        // Event Details steps back to the Events list — never to Manage Events.
        isset($_GET['return_to']) ? (string) $_GET['return_to'] : '/events'
    );
    $returnTo = $backUrl;
?>
<div class="mb-4">
    <!-- Back Button & Header Row -->
    <div class="flex items-center justify-between flex-wrap gap-4 pb-4 border-b border-zinc-200 mb-6">
        <div class="flex items-center gap-3">
            <?= render_event_back_button($backUrl, $role === 'student' ? 'Back' : 'Back to Events') ?>
            <h2 class="text-xl md:text-2xl font-bold text-zinc-900"><?= htmlspecialchars((string) ($event['title'] ?? '')) ?></h2>
            <span class="text-[10px] sm:text-xs font-bold uppercase tracking-widest rounded-md border px-2 py-0.5 <?= $statusColor ?>"><?= htmlspecialchars($status) ?></span>
        </div>
        
        <?php if ($role === 'admin'): ?>
        <div class="flex items-center gap-2">
            <?php if ($event['status'] === 'pending'): ?>
                <button id="btnRejectProposal" class="rounded-xl border border-red-200 bg-red-50 text-red-700 font-bold px-4 py-2 text-[13px] hover:bg-red-100 transition shadow-sm">
                    Reject Proposal
                </button>
                <button id="btnApproveProposal" data-id="<?= htmlspecialchars($id) ?>" class="rounded-xl border border-emerald-600 bg-emerald-600 text-white font-bold px-4 py-2 text-[13px] hover:bg-emerald-700 transition shadow-sm relative overflow-hidden">
                    <span class="relative z-10">Approve Proposal</span>
                </button>
            <?php elseif (!$isFinishedEvent): ?>
                <button id="btnEditEventTop" class="flex items-center gap-1.5 rounded-xl bg-orange-600 text-white font-bold px-4 py-2 text-[13px] hover:bg-orange-700 transition shadow-sm"
                        data-id="<?= htmlspecialchars((string) ($event['id'] ?? '')) ?>"
                        data-title="<?= htmlspecialchars((string) ($event['title'] ?? '')) ?>"
                        data-location="<?= htmlspecialchars((string) ($event['location'] ?? '')) ?>"
                        data-description="<?= htmlspecialchars((string) ($event['description'] ?? '')) ?>"
                        data-start_at="<?= htmlspecialchars((string) ($event['start_at'] ?? '')) ?>"
                        data-end_at="<?= htmlspecialchars((string) ($event['end_at'] ?? '')) ?>"
                        data-event_mode="<?= htmlspecialchars(count($sessions) > 0 ? 'seminar_based' : 'simple') ?>"
                        data-sessions="<?= $sessionsJsonForAttr ?>"
                        data-registration_close_weeks="<?= htmlspecialchars((string) (event_registration_close_weeks($event) ?? '')) ?>"
                        data-registration_close_extend_days="<?= htmlspecialchars((string) event_registration_close_extend_days($event)) ?>"
                >
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L6.832 19.82a4.5 4.5 0 01-1.897 1.13l-2.685.8.8-2.685a4.5 4.5 0 011.13-1.897L16.863 4.487zm0 0L19.5 7.125"/></svg>
                    Edit Event
                </button>
            <?php endif; ?>
        </div>
        <?php elseif ($canManageCertificates): ?>
        <div class="flex items-center gap-2 flex-wrap justify-end">
            <?php if ($eventFinishedForCertificates): ?>
            <button
                id="btnSendCert"
                data-event-finished="1"
                class="rounded-xl border font-bold px-4 py-2 text-[13px] transition shadow-sm relative overflow-hidden border-emerald-500 bg-emerald-50 text-emerald-700 hover:bg-emerald-100"
            >
                <span class="relative z-10">Send Certificate</span>
            </button>
            <?php endif; ?>
            <button type="button" id="btnCertAutoStatus" class="rounded-xl border border-sky-300 bg-sky-50 text-sky-800 font-bold px-4 py-2 text-[13px] hover:bg-sky-100 transition shadow-sm" title="See who received auto certificates and manually send to missing students">
                Cert status
            </button>
            <button type="button" id="btnImportCert" class="rounded-xl border border-zinc-300 bg-white text-zinc-700 font-bold px-4 py-2 text-[13px] hover:bg-zinc-50 transition shadow-sm">
                Import / Link Cert
            </button>
        </div>
        <?php endif; ?>
    </div>

    <?php
    if ($role === 'admin' || $role === 'teacher') {
        render_event_tabs([
            'event_id' => $id,
            'current_tab' => 'details',
            'role' => $role,
            'uses_sessions' => count($sessions) > 0,
            'event_status' => $status,
            'return_to' => $returnTo,
            'has_student_requirements' => $hasStudentRequirements,
            'is_event_creator' => $isEventCreatorTeacher || $role === 'admin',
            'is_paid_event' => $isPaidEvent,
        ]);
    }

    $coverImageUrl = trim((string) ($event['cover_image_url'] ?? ''));
    ?>

    <!-- Layout Grid: Left Sidebar & Main Content -->
    <div class="flex flex-col xl:flex-row gap-6">
        
        <!-- MAIN CONTENT AREA -->
        <div class="flex-1 min-w-0">
            <!-- Tab Content: Details -->
            <div class="pc-event-card border border-zinc-200 bg-white shadow-sm relative overflow-hidden mb-6">
                <?php if ($coverImageUrl !== ''): ?>
                <div class="relative aspect-[16/9] w-full overflow-hidden bg-zinc-100 sm:aspect-[21/9]">
                    <img src="<?= htmlspecialchars($coverImageUrl) ?>"
                        alt="<?= htmlspecialchars((string) ($event['title'] ?? 'Event cover')) ?>"
                        class="h-full w-full object-cover" loading="eager" decoding="async" />
                    <div class="absolute inset-0 bg-gradient-to-t from-black/50 via-black/10 to-transparent"></div>
                    <div class="absolute bottom-0 left-0 right-0 p-5 md:p-6">
                        <h3 class="pc-shiny-title pc-shiny-title--on-dark text-lg md:text-xl font-black tracking-wide line-clamp-2">
                            <?= htmlspecialchars((string) ($event['title'] ?? '')) ?>
                        </h3>
                    </div>
                </div>
                <?php endif; ?>

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
                    <div class="rounded-xl bg-zinc-50/50 border border-zinc-200 p-4">
                        <div class="text-xs text-zinc-500 font-bold mb-1">Location / Venue</div>
                        <div class="text-[15px] font-bold text-zinc-900 flex items-center gap-2">
                           <svg class="w-4 h-4 text-orange-500" fill="currentColor" viewBox="0 0 24 24"><path d="M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7zm0 9.5a2.5 2.5 0 010-5 2.5 2.5 0 010 5z"/></svg> 
                           <?= htmlspecialchars((string) ($event['location'] ?? 'TBA')) ?>
                        </div>
                    </div>
                    <div class="rounded-xl bg-zinc-50/50 border border-zinc-200 p-4">
                        <div class="text-xs text-zinc-500 font-bold mb-1">Event Type</div>
                        <div class="text-[15px] font-bold text-zinc-900 flex items-center gap-2">
                           <svg class="w-4 h-4 text-blue-500" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9.568 3H5.25A2.25 2.25 0 003 5.25v4.318c0 .597.237 1.17.659 1.591l9.581 9.581c.699.699 1.78.872 2.607.33a18.095 18.095 0 005.223-5.223c.542-.827.369-1.908-.33-2.607L11.16 3.66A2.25 2.25 0 009.568 3z"/><path stroke-linecap="round" stroke-linejoin="round" d="M6 6h.008v.008H6V6z"/></svg>
                           <?= htmlspecialchars(!empty($event['event_type']) ? $event['event_type'] : 'General Event') ?>
                        </div>
                    </div>
                    <div class="col-span-1 md:col-span-2 rounded-xl bg-zinc-50/50 border border-zinc-200 p-4">
                        <div class="text-xs text-zinc-500 font-bold mb-1">Target Participants</div>
                        <div class="text-[15px] font-bold text-zinc-900 flex items-center gap-2">
                           <svg class="w-4 h-4 text-emerald-500" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 002.625.372 9.337 9.337 0 004.121-.952 4.125 4.125 0 00-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 018.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0111.964-3.07M12 6.375a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zm8.25 2.25a2.625 2.625 0 11-5.25 0 2.625 2.625 0 015.25 0z"/></svg>
                           <?php
                               $for = $event['event_for'] ?? 'all';
                               $targetLabel = format_target_participant((string)$for);
                               echo htmlspecialchars($targetLabel);
                           ?>
                        </div>
                    </div>
                 </div>

                 <?php if (count($sessions) > 0): ?>
                 <h3 class="text-sm font-black text-zinc-400 uppercase tracking-widest mt-8 mb-4">Seminar Sessions</h3>
                 <div class="grid grid-cols-1 md:grid-cols-2 gap-4 max-w-4xl">
                    <?php foreach ($sessions as $session): ?>
                    <div class="rounded-xl bg-orange-50/50 border border-orange-200 p-4">
                        <div class="text-xs text-orange-700 font-bold mb-1">Seminar</div>
                        <div class="text-[15px] font-bold text-zinc-900"><?= htmlspecialchars(build_session_display_name($session)) ?></div>
                        <div class="mt-3 text-sm text-zinc-600">
                            <div><span class="font-semibold text-zinc-800">Starts:</span> <?= htmlspecialchars(format_date_local((string) ($session['start_at'] ?? ''), 'F j, Y, g:i A')) ?></div>
                            <div class="mt-1"><span class="font-semibold text-zinc-800">Ends:</span> <?= htmlspecialchars(format_date_local((string) ($session['end_at'] ?? ''), 'F j, Y, g:i A')) ?></div>
                            <?php if (!empty($session['location'])): ?>
                            <div class="mt-1"><span class="font-semibold text-zinc-800">Location:</span> <?= htmlspecialchars((string) $session['location']) ?></div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                 </div>
                 <?php endif; ?>

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
                        <button
                            id="btnRegister"
                            class="<?= htmlspecialchars($studentButtonClasses) ?>"
                            <?= $studentCanRegister ? '' : 'disabled' ?>>
                            <?= htmlspecialchars($studentButtonLabel) ?>
                        </button>
                        <a href="/my_tickets.php" class="rounded-xl border border-zinc-300 bg-zinc-50 px-5 py-3 text-sm font-bold text-zinc-800 hover:bg-white transition shadow-sm">
                            My Tickets
                        </a>
                    </div>
                    <?php if ($studentRegistrationMessage !== ''): ?>
                    <div class="mt-4 rounded-2xl border border-amber-200 bg-amber-50/80 px-4 py-3 text-sm font-semibold text-amber-800">
                        <?= htmlspecialchars($studentRegistrationMessage) ?>
                    </div>
                    <?php endif; ?>
                    <div id="msgStudent" class="mt-4 text-sm font-bold text-emerald-600"></div>
                 <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- RIGHT SIDEBAR (Stats & Controls) -->
        <?php if ($role === 'admin' || $role === 'teacher'): ?>
        <div class="w-full xl:w-80 flex-shrink-0 flex flex-col gap-4 xl:mt-16">

            <?php if ($showEventQr): ?>
            <div class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm">
                <div class="text-[11px] font-black text-zinc-500 uppercase tracking-wider mb-3">Event QR Code</div>
                <div class="flex flex-col items-center">
                    <div id="eventQrCode" class="bg-white p-3 rounded-xl border border-zinc-100 flex items-center justify-center min-h-[224px] min-w-[224px]"></div>
                    <p class="text-[11px] text-zinc-500 font-medium text-center mt-3 leading-relaxed">
                        Students scan this for time-in (grace) and time-out (end + 1 hour, or Early Out).
                    </p>
                    <button
                        type="button"
                        id="btnDownloadEventQr"
                        class="mt-4 w-full rounded-xl border border-zinc-300 bg-zinc-50 px-4 py-2.5 text-sm font-bold text-zinc-800 hover:bg-white transition shadow-sm"
                    >
                        Download QR
                    </button>
                </div>
            </div>
            <div class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm">
                <div class="flex items-center justify-between gap-3">
                    <div>
                        <div class="text-[11px] font-black text-zinc-500 uppercase tracking-wider mb-1">Early Out</div>
                        <p id="earlyOutHint" class="text-[11px] text-zinc-500 font-medium leading-relaxed">
                            Available after the grace period ends. Opens time-out for 1 hour.
                        </p>
                    </div>
                    <button
                        type="button"
                        id="btnEarlyOutToggle"
                        disabled
                        aria-disabled="true"
                        data-event-id="<?= htmlspecialchars((string) ($event['id'] ?? ''), ENT_QUOTES) ?>"
                        data-start-at="<?= htmlspecialchars((string) ($event['start_at'] ?? ''), ENT_QUOTES) ?>"
                        data-end-at="<?= htmlspecialchars((string) ($event['end_at'] ?? ''), ENT_QUOTES) ?>"
                        data-grace-minutes="<?= htmlspecialchars((string) max(0, (int) ($event['grace_time'] ?? 30)), ENT_QUOTES) ?>"
                        data-uses-sessions="<?= count($sessions) > 0 ? '1' : '0' ?>"
                        class="rounded-xl bg-sky-600 px-4 py-2.5 text-sm font-bold text-white hover:bg-sky-700 transition shadow-sm disabled:opacity-50 disabled:cursor-not-allowed disabled:pointer-events-none disabled:hover:bg-sky-600 min-w-[7.5rem] inline-flex items-center justify-center gap-2 flex-shrink-0"
                    >
                        Enable
                    </button>
                </div>
            </div>
            <?php endif; ?>

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
                    <div class="text-2xl font-bold text-zinc-900"><?= htmlspecialchars(format_event_registration_total($totalRegistered, $event)) ?> <span class="text-sm font-semibold text-zinc-400"><?= event_registration_limit($event) !== null ? 'Registered' : 'Total Registered' ?></span></div>
                </div>
            </div>

            <?php if ($role === 'admin' && !$isFinishedEvent): ?>
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
                <p class="text-[11px] text-zinc-500 font-medium">
                    <?php if ($isPaidEvent): ?>
                        Keep this OFF for paid events so students register after payment is recorded in the Payments tab. Turn ON only to open free registration to all targeted students.
                    <?php else: ?>
                        Turn ON to let all targeted students register. Keep this OFF to pause new registrations.
                    <?php endif; ?>
                </p>
            </div>
            <?php endif; ?>
            
        </div>
        <?php endif; ?>

    </div>
</div>

<?php if ($role === 'admin'): ?>
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

    <div class="p-5 sm:p-6 overflow-y-auto overflow-x-hidden">
      <form id="eventForm">
        <input type="hidden" id="event_id" value="">
        <input type="hidden" id="mode" value="edit">
        <input type="hidden" id="event_mode" value="simple">

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
          
          <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
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

          <div id="registrationCloseExtendSection" class="rounded-2xl border border-sky-200 bg-sky-50/70 p-4 space-y-3">
            <div>
              <div class="text-xs font-bold uppercase tracking-wide text-sky-800">Extend Registration Close Limit</div>
              <p class="text-[11px] text-sky-800/80 mt-1 leading-relaxed">
                Keeps the original close rule
                (<span id="registrationCloseBaseLabel" class="font-semibold">—</span>).
                If that date is not yet past, extra days are added to it.
                If it is already past, counting starts from <span class="font-semibold">today</span>.
                Absolute maximum: <span class="font-semibold">3 days before event start</span>.
              </p>
            </div>
            <div>
              <label for="registration_close_extend_days" class="block text-xs text-zinc-600 mb-1.5 font-medium tracking-wide">Add days</label>
              <div class="flex items-center gap-2">
                <span class="text-sm font-bold text-zinc-500">+</span>
                <input id="registration_close_extend_days" name="registration_close_extend_days" type="number" min="0" max="60" step="1" value="0"
                  class="w-full rounded-xl bg-white border border-zinc-200 py-3 px-4 text-sm text-zinc-900 outline-none focus:ring-2 focus:ring-orange-500/30 focus:border-orange-400 transition" />
                <span class="text-sm font-semibold text-zinc-500 shrink-0">day(s)</span>
              </div>
              <p id="registrationCloseExtendHint" class="text-[11px] text-zinc-500 mt-1.5"></p>
              <p id="registrationCloseExtendError" class="hidden text-[12px] font-semibold text-red-600 mt-1.5"></p>
            </div>
            <p id="registrationClosePreview" class="text-[12px] font-semibold text-sky-900">Registration closes: —</p>
          </div>

          <div id="seminarEditSection" class="hidden rounded-2xl border border-orange-200 bg-orange-50/60 p-4 space-y-4">
            <div class="flex items-start justify-between gap-4">
              <div>
                <div class="text-xs font-bold uppercase tracking-wide text-orange-700">Seminar Sessions</div>
                <p class="text-[11px] text-orange-700/80 mt-1">For seminar-based events, update each seminar schedule here.</p>
              </div>
              <button type="button" id="btnToggleSeminar2" class="shrink-0 rounded-lg border border-orange-300 bg-white px-3 py-1.5 text-[11px] font-bold text-orange-700 hover:bg-orange-100 transition">
                Add Seminar 2
              </button>
            </div>

            <div id="seminar1Editor" class="rounded-xl border border-orange-200 bg-white p-4 space-y-3">
              <div class="text-[11px] font-bold uppercase tracking-wide text-zinc-600">Seminar 1</div>
              <div>
                <div>
                  <label class="block text-[11px] text-zinc-600 mb-1 font-medium">Title</label>
                  <input id="seminar1_title" type="text" class="w-full rounded-lg border border-zinc-200 px-3 py-2 text-sm text-zinc-900 outline-none focus:ring-2 focus:ring-orange-500/30 focus:border-orange-400" placeholder="Seminar title" />
                </div>
              </div>
              <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                <div>
                  <label class="block text-[11px] text-zinc-600 mb-1 font-medium">Start Date & Time</label>
                  <input id="seminar1_start_local" type="datetime-local" class="w-full rounded-lg border border-zinc-200 px-3 py-2 text-sm text-zinc-900 outline-none focus:ring-2 focus:ring-orange-500/30 focus:border-orange-400" />
                </div>
                <div>
                  <label class="block text-[11px] text-zinc-600 mb-1 font-medium">End Date & Time</label>
                  <input id="seminar1_end_local" type="datetime-local" class="w-full rounded-lg border border-zinc-200 px-3 py-2 text-sm text-zinc-900 outline-none focus:ring-2 focus:ring-orange-500/30 focus:border-orange-400" />
                </div>
              </div>
            </div>

            <div id="seminar2Editor" class="hidden rounded-xl border border-orange-200 bg-white p-4 space-y-3">
              <div class="text-[11px] font-bold uppercase tracking-wide text-zinc-600">Seminar 2</div>
              <div>
                <div>
                  <label class="block text-[11px] text-zinc-600 mb-1 font-medium">Title</label>
                  <input id="seminar2_title" type="text" class="w-full rounded-lg border border-zinc-200 px-3 py-2 text-sm text-zinc-900 outline-none focus:ring-2 focus:ring-orange-500/30 focus:border-orange-400" placeholder="Seminar title" />
                </div>
              </div>
              <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                <div>
                  <label class="block text-[11px] text-zinc-600 mb-1 font-medium">Start Date & Time</label>
                  <input id="seminar2_start_local" type="datetime-local" class="w-full rounded-lg border border-zinc-200 px-3 py-2 text-sm text-zinc-900 outline-none focus:ring-2 focus:ring-orange-500/30 focus:border-orange-400" />
                </div>
                <div>
                  <label class="block text-[11px] text-zinc-600 mb-1 font-medium">End Date & Time</label>
                  <input id="seminar2_end_local" type="datetime-local" class="w-full rounded-lg border border-zinc-200 px-3 py-2 text-sm text-zinc-900 outline-none focus:ring-2 focus:ring-orange-500/30 focus:border-orange-400" />
                </div>
              </div>
            </div>

            <p class="text-[11px] text-orange-700/80">Main event start/end will auto-sync with seminar schedules when this event is seminar-based.</p>
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

<?php endif; ?>

<?php if ($canManageCertificates): ?>
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
        <p id="successCertMessage" class="text-sm font-semibold text-emerald-700 leading-relaxed">Certificates sent to <?= $completedCount ?> participants.</p>
    </div>
  </div>
</div>

<!-- ═══════════  IMPORT / LINK CERT  ═══════════ -->
<?php
  // Import picker: reusable Library designs only — do not list event-linked clones
  // (those grow on every Save & link and confuse "reuse").
  $importSidebarTemplates = array_values(array_filter(
      $certificateTemplates,
      static function ($t) use ($id): bool {
          if (!is_array($t)) {
              return false;
          }
          if ((string) ($t['template_scope'] ?? '') !== 'event') {
              return false;
          }
          $rowEventId = trim((string) ($t['event_id'] ?? ''));
          // Only library rows (not scoped to this — or any — event copy).
          return $rowEventId === '';
      }
  ));
  // Dedupe by id (same library row should not appear twice).
  $seenImportTpl = [];
  $importSidebarTemplates = array_values(array_filter(
      $importSidebarTemplates,
      static function ($t) use (&$seenImportTpl): bool {
          $tid = (string) ($t['id'] ?? '');
          if ($tid === '' || isset($seenImportTpl[$tid])) {
              return false;
          }
          $seenImportTpl[$tid] = true;
          return true;
      }
  ));
  $linkedImportTemplate = null;
  foreach ($certificateTemplates as $tplRow) {
      if (!is_array($tplRow)) {
          continue;
      }
      if ((string) ($tplRow['template_scope'] ?? '') !== 'event') {
          continue;
      }
      if ((string) ($tplRow['event_id'] ?? '') === $id) {
          // List is newest-updated first — first match is the live linked design.
          $linkedImportTemplate = $tplRow;
          break;
      }
  }
  $importIsMulti = count($sessions) > 0;
  $importSlots = [];
  if ($importIsMulti) {
      foreach ($sessions as $idx => $srow) {
          if (!is_array($srow)) {
              continue;
          }
          $sid = (string) ($srow['id'] ?? '');
          if ($sid === '') {
              continue;
          }
          $importSlots[] = [
              'session_id' => $sid,
              'label' => 'Seminar ' . ((int) $idx + 1),
              'title' => trim((string) ($srow['title'] ?? ('Seminar ' . ((int) $idx + 1)))),
          ];
      }
  } else {
      $importSlots[] = [
          'session_id' => '',
          'label' => 'Certificate',
          'title' => 'Event certificate',
      ];
  }

  // Per-seminar linked designs (from event_session_certificate_templates).
  $linkedSeminarCerts = [];
  if ($importIsMulti) {
      foreach ($importSlots as $slot) {
          $sid = (string) ($slot['session_id'] ?? '');
          if ($sid === '') {
              continue;
          }
          $found = null;
          foreach ($certificateTemplates as $tplRow) {
              if (!is_array($tplRow)) {
                  continue;
              }
              if ((string) ($tplRow['template_scope'] ?? '') !== 'session') {
                  continue;
              }
              if ((string) ($tplRow['scope_session_id'] ?? '') !== $sid) {
                  continue;
              }
              $found = $tplRow;
              break; // list already newest-first
          }
          if (!$found) {
              continue;
          }
          $linkedSeminarCerts[] = [
              'session_id' => $sid,
              'label' => (string) ($slot['label'] ?? 'Seminar'),
              'session_title' => (string) ($slot['title'] ?? ''),
              'id' => (string) ($found['id'] ?? ''),
              'title' => (string) ($found['title'] ?? 'Certificate'),
              'thumb' => (string) ($found['thumbnail_url'] ?? ''),
          ];
      }
  }

  $hasLinkedCerts = $importIsMulti
      ? count($linkedSeminarCerts) > 0
      : (is_array($linkedImportTemplate) && (string) ($linkedImportTemplate['id'] ?? '') !== '');

  $linkedImportJson = [
      'multi' => $importIsMulti,
      'id' => (string) ($linkedImportTemplate['id'] ?? ''),
      'title' => (string) ($linkedImportTemplate['title'] ?? 'Certificate'),
      'thumb' => (string) ($linkedImportTemplate['thumbnail_url'] ?? ''),
      'items' => $importIsMulti ? $linkedSeminarCerts : (
          $hasLinkedCerts ? [[
              'session_id' => '',
              'label' => 'Certificate',
              'session_title' => 'Event certificate',
              'id' => (string) ($linkedImportTemplate['id'] ?? ''),
              'title' => (string) ($linkedImportTemplate['title'] ?? 'Certificate'),
              'thumb' => (string) ($linkedImportTemplate['thumbnail_url'] ?? ''),
          ]] : []
      ),
  ];
?>
<div id="certAutoStatusModal" class="fixed inset-0 z-[154] hidden items-center justify-center p-3 sm:p-4 bg-zinc-900/60 backdrop-blur-sm">
  <div class="cert-status-panel w-full max-w-4xl max-h-[90vh] flex flex-col rounded-3xl bg-white border border-zinc-200 shadow-2xl overflow-hidden">
    <div class="px-5 py-4 border-b border-zinc-200 flex items-start justify-between gap-3 shrink-0">
      <div class="min-w-0">
        <h3 class="text-lg font-bold text-zinc-900 tracking-tight">Certificate auto-send status</h3>
        <p class="text-sm text-zinc-500 mt-0.5">Who already received a certificate, and who finished evaluation but still needs one.</p>
      </div>
      <button type="button" id="btnCloseCertAutoStatus" class="text-zinc-400 hover:text-zinc-700 p-1 shrink-0" aria-label="Close">
        <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
      </button>
    </div>
    <div class="px-5 py-3 border-b border-zinc-100 flex flex-wrap items-center gap-2 text-[11px] font-semibold shrink-0">
      <span class="inline-flex items-center gap-1 rounded-full border border-emerald-200 bg-emerald-50 px-2.5 py-1 text-emerald-800">
        Received <strong id="certStatusReceivedCount">0</strong>
      </span>
      <span class="inline-flex items-center gap-1 rounded-full border border-amber-200 bg-amber-50 px-2.5 py-1 text-amber-900">
        Missing <strong id="certStatusMissingCount">0</strong>
      </span>
      <button type="button" id="btnRefreshCertAutoStatus" class="ml-auto rounded-lg border border-zinc-200 bg-white px-2.5 py-1 text-zinc-600 hover:bg-zinc-50">Refresh</button>
    </div>
    <div class="cert-status-grid flex-1">
      <section class="cert-status-col">
        <header class="px-5 py-3 border-b border-zinc-100 bg-zinc-50 shrink-0">
          <h4 class="text-sm font-bold text-zinc-900">Received (auto-send success)</h4>
          <p class="text-[11px] text-zinc-500 mt-0.5">Seminar events show every seminar code (…05.01/…06.01).</p>
        </header>
        <div id="certStatusReceivedList" class="cert-status-scroll p-4 space-y-2">
          <div class="text-sm text-zinc-400 font-semibold py-4 text-center">Loading…</div>
        </div>
      </section>
      <section class="cert-status-col">
        <header class="px-5 py-3 border-b border-zinc-100 bg-zinc-50 shrink-0">
          <div class="flex items-center justify-between gap-2">
            <h4 class="text-sm font-bold text-zinc-900">Eval done — no certificate yet</h4>
            <div class="flex items-center gap-2 shrink-0">
              <button type="button" id="btnSelectAllMissingCerts" class="text-[11px] font-bold text-sky-700 hover:underline">Select all</button>
              <button type="button" id="btnSendSelectedMissingCerts" class="rounded-lg bg-sky-600 text-white text-[11px] font-bold px-3 py-1.5 hover:bg-sky-700 disabled:opacity-50" disabled>Send selected</button>
            </div>
          </div>
          <p class="text-[11px] text-zinc-500 mt-0.5">Manual send uses the same linked template and auto-count code (…-01 → …-02).</p>
        </header>
        <div id="certStatusMissingList" class="cert-status-scroll p-4 space-y-2">
          <div class="text-sm text-zinc-400 font-semibold py-4 text-center">Loading…</div>
        </div>
      </section>
    </div>
    <div id="certAutoStatusFooter" class="px-5 py-3 border-t border-zinc-200 bg-zinc-50 text-xs font-semibold text-zinc-500 shrink-0"></div>
  </div>
</div>

<div id="importCertModal" class="fixed inset-0 z-[153] hidden items-center justify-center p-3 sm:p-4 bg-zinc-900/60 backdrop-blur-sm" data-has-linked="<?= $hasLinkedCerts ? '1' : '0' ?>">
  <div id="importCertModalPanel" class="w-full max-w-6xl h-[min(92vh,820px)] flex flex-col rounded-3xl bg-white border border-zinc-200 shadow-2xl overflow-hidden transition-[max-width,box-shadow] duration-300 ease-out">
    <!-- Header -->
    <div class="px-5 py-4 border-b border-zinc-200 flex items-start justify-between gap-3 shrink-0">
      <div class="flex items-start gap-3 min-w-0">
        <div class="hidden sm:flex w-10 h-10 rounded-2xl bg-orange-50 border border-orange-100 items-center justify-center text-orange-600 shrink-0">
          <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m2.25 0H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z"/></svg>
        </div>
        <div class="min-w-0">
          <h3 id="importCertModalTitle" class="text-lg font-bold text-zinc-900 tracking-tight">Import / Link Certificate</h3>
          <p id="importCertModalSubtitle" class="text-sm text-zinc-500 mt-0.5 leading-snug">Upload a registrar PPTX to scan codes and link a template.</p>
        </div>
      </div>
      <button type="button" id="btnCloseImportCert" class="text-zinc-400 hover:text-zinc-700 p-1 shrink-0" aria-label="Close">
        <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
      </button>
    </div>

    <!-- LINKED VIEW (show only) — after Save & link -->
    <div id="importCertLinkedView" class="hidden flex-1 min-h-0 flex-col bg-zinc-100/80 overflow-hidden opacity-0" data-import-pane="linked">
      <div class="flex-1 min-h-0 overflow-y-auto overscroll-contain p-4 sm:p-6">
        <div class="w-full max-w-5xl mx-auto space-y-3">
          <div class="flex items-center justify-between gap-3 shrink-0">
            <div class="min-w-0">
              <div class="flex items-center gap-2 flex-wrap">
                <h4 id="importLinkedTitle" class="text-base sm:text-lg font-bold text-zinc-900 truncate">
                  <?= $importIsMulti ? 'Linked seminar certificates' : htmlspecialchars((string) ($linkedImportTemplate['title'] ?? 'Certificate')) ?>
                </h4>
                <span class="inline-flex items-center rounded-full border border-emerald-200 bg-emerald-50 px-2.5 py-0.5 text-[10px] font-black uppercase tracking-wider text-emerald-700 shrink-0">Linked</span>
              </div>
              <p id="importLinkedSubtitle" class="text-xs sm:text-sm text-zinc-500 mt-1">
                <?= $importIsMulti
                  ? 'Each seminar uses its own certificate design. Linked seed code auto-counts (…-01 → …-02) after each eval.'
                  : 'Same linked design for everyone — seed code auto-counts (…-01 → …-02 → …-100) after each evaluation submit.' ?>
              </p>
            </div>
          </div>
          <div id="importLinkedList" class="<?= ($importIsMulti && count($linkedSeminarCerts) > 1) ? 'grid grid-cols-1 md:grid-cols-2 gap-3' : 'space-y-3' ?>">
            <?php if ($importIsMulti && count($linkedSeminarCerts) > 0): ?>
              <?php foreach ($linkedSeminarCerts as $lc): ?>
                <div class="rounded-2xl border border-zinc-200 bg-white shadow-sm overflow-hidden flex flex-col min-h-0">
                  <div class="px-3 py-2.5 border-b border-zinc-100 bg-zinc-50 flex items-center justify-between gap-2 shrink-0">
                    <div class="min-w-0">
                      <div class="text-[10px] font-black uppercase tracking-widest text-zinc-500"><?= htmlspecialchars((string) ($lc['label'] ?? '')) ?></div>
                      <div class="text-sm font-bold text-zinc-900 truncate"><?= htmlspecialchars((string) ($lc['session_title'] ?? '')) ?></div>
                      <div class="text-xs text-orange-600 font-semibold truncate mt-0.5"><?= htmlspecialchars((string) ($lc['title'] ?? 'Certificate')) ?></div>
                    </div>
                    <span class="inline-flex items-center rounded-full border border-emerald-200 bg-emerald-50 px-2 py-0.5 text-[9px] font-black uppercase tracking-wider text-emerald-700 shrink-0">Linked</span>
                  </div>
                  <div class="bg-zinc-50 p-2.5 flex-1 min-h-0">
                    <div class="w-full rounded-xl border border-zinc-200 bg-white overflow-hidden flex items-center justify-center h-[min(28vh,200px)]">
                      <?php if (!empty($lc['thumb'])): ?>
                        <img src="<?= htmlspecialchars((string) $lc['thumb']) ?>" alt="" class="max-w-full max-h-full object-contain">
                      <?php else: ?>
                        <div class="text-sm text-zinc-400 font-semibold px-4 text-center py-8">No preview available</div>
                      <?php endif; ?>
                    </div>
                  </div>
                </div>
              <?php endforeach; ?>
            <?php else: ?>
              <div class="rounded-2xl border border-zinc-200 bg-white shadow-sm overflow-hidden" id="importLinkedSingleCard">
                <div class="bg-zinc-50 p-3 sm:p-4">
                  <div class="w-full rounded-xl border border-zinc-200 bg-white overflow-hidden flex items-center justify-center h-[min(42vh,320px)]" id="importLinkedPreviewWrap">
                    <?php
                      $linkedThumb = trim((string) ($linkedImportTemplate['thumbnail_url'] ?? ''));
                      $hasLinkedThumb = $linkedThumb !== '';
                    ?>
                    <img id="importLinkedPreviewImg" src="<?= htmlspecialchars($linkedThumb) ?>" alt="Linked certificate preview" class="max-w-full max-h-full object-contain <?= $hasLinkedThumb ? '' : 'hidden' ?>">
                    <div id="importLinkedPreviewEmpty" class="<?= $hasLinkedThumb ? 'hidden' : '' ?> text-sm text-zinc-400 font-semibold px-4 text-center py-12">No linked preview yet</div>
                  </div>
                </div>
              </div>
            <?php endif; ?>
          </div>
        </div>
      </div>
      <div class="shrink-0 border-t border-zinc-200 bg-white px-5 py-4 flex flex-col sm:flex-row items-stretch sm:items-center justify-between gap-3">
        <p class="text-xs text-zinc-500 font-medium text-center sm:text-left">Want a different design? Change it anytime.</p>
        <button type="button" id="btnChangeLinkedCert" class="inline-flex items-center justify-center gap-2 rounded-xl bg-zinc-900 text-white px-5 py-2.5 text-sm font-bold hover:bg-zinc-800 transition shrink-0 overflow-visible">
          <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M7.5 21 3 16.5m0 0L7.5 12M3 16.5h13.5m0-13.5L21 7.5m0 0L16.5 12M21 7.5H7.5"/></svg>
          Change certificate
        </button>
      </div>
    </div>

    <!-- EDIT / LINK VIEW -->
    <div id="importCertEditView" class="flex flex-1 min-h-0 flex-col opacity-100" data-import-pane="edit">
      <div id="importLinkedBanner" class="shrink-0 px-4 py-2.5 border-b border-emerald-100 bg-emerald-50/80 flex flex-wrap items-center justify-between gap-2 <?= $hasLinkedCerts ? '' : 'hidden' ?>">
        <p class="text-xs text-emerald-800 font-semibold">
          <span id="importLinkedBannerLabel"><?php if ($importIsMulti && $hasLinkedCerts): ?>
            Linked: <span class="font-bold"><?= count($linkedSeminarCerts) ?> seminar certificate(s)</span>
          <?php elseif ($hasLinkedCerts): ?>
            Currently linked: <span class="font-bold"><?= htmlspecialchars((string) ($linkedImportTemplate['title'] ?? 'Certificate')) ?></span>
          <?php else: ?>
            Currently linked: <span class="font-bold">Certificate</span>
          <?php endif; ?></span>
        </p>
        <button type="button" id="btnViewLinkedCert" class="text-xs font-bold text-emerald-700 hover:text-emerald-900 underline underline-offset-2">
          View linked cert<?= $importIsMulti ? 's' : '' ?>
        </button>
      </div>
    <!-- 3 columns: templates | preview | codes -->
    <div class="import-cert-layout flex-1 min-h-0 grid grid-cols-1 lg:grid-cols-[210px_minmax(0,1fr)_270px] divide-y lg:divide-y-0 lg:divide-x divide-zinc-200">

      <!-- 1) Saved templates -->
      <aside class="bg-zinc-50/80 p-4 min-h-0 max-h-[28vh] lg:max-h-none lg:h-full flex flex-col">
        <div class="flex items-center justify-between gap-2 mb-3">
          <div class="text-[10px] font-black uppercase tracking-widest text-zinc-500">Saved templates</div>
          <span class="inline-flex items-center justify-center min-w-[1.25rem] h-5 px-1.5 rounded-full bg-zinc-200 text-[10px] font-black text-zinc-700"><?= count($importSidebarTemplates) ?></span>
        </div>
        <p id="importTemplateLockHint" class="hidden mb-3 rounded-xl border border-amber-200 bg-amber-50 px-3 py-2 text-[11px] font-semibold text-amber-800">
          Every seminar already has a certificate. Tap the ✕ on a seminar first to change its template.
        </p>
        <div id="importCertTemplateList" class="space-y-2 flex-1 min-h-0 overflow-y-auto pr-1 overscroll-contain">
          <?php if (count($importSidebarTemplates) === 0): ?>
            <p class="text-xs text-zinc-500 leading-relaxed">No saved designs yet.</p>
            <a href="/certificates_library" class="inline-flex mt-1 text-xs font-bold text-orange-600 hover:text-orange-700">Open Cert Templates →</a>
          <?php else: ?>
            <?php foreach ($importSidebarTemplates as $tpl): ?>
              <?php
                $tid = htmlspecialchars((string) ($tpl['id'] ?? ''));
                $ttitle = htmlspecialchars((string) ($tpl['title'] ?? 'Untitled'));
                $tthumb = htmlspecialchars((string) ($tpl['thumbnail_url'] ?? ''));
                $tscope = htmlspecialchars((string) ($tpl['scope_label'] ?? 'Saved template'));
              ?>
              <button
                type="button"
                class="import-cert-template group w-full text-left rounded-xl border border-zinc-200 bg-white p-2 hover:border-orange-300 transition focus:outline-none"
                data-template-id="<?= $tid ?>"
                data-template-title="<?= $ttitle ?>"
                data-template-thumb="<?= $tthumb ?>"
                data-template-scope="<?= $tscope ?>"
              >
                <div class="aspect-[4/3] rounded-lg bg-zinc-100 overflow-hidden mb-2 border border-zinc-100">
                  <?php if ($tthumb !== ''): ?>
                    <img src="<?= $tthumb ?>" alt="" class="w-full h-full object-contain bg-white">
                  <?php else: ?>
                    <div class="w-full h-full flex items-center justify-center text-[10px] font-bold text-zinc-400 uppercase tracking-wider">No preview</div>
                  <?php endif; ?>
                </div>
                <div class="text-[11px] font-bold text-zinc-800 truncate leading-tight"><?= $ttitle ?></div>
                <div class="mt-1 inline-flex rounded-full border border-orange-200 bg-orange-50 px-2 py-0.5 text-[9px] font-black uppercase tracking-wider text-orange-700"><?= $tscope ?></div>
              </button>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
      </aside>

      <!-- 2) Upload + preview -->
      <section class="p-4 overflow-y-auto min-h-0 bg-white space-y-4">
        <?php foreach ($importSlots as $slotIdx => $slot): ?>
          <?php
            $slotId = htmlspecialchars((string) $slot['session_id']);
            $slotKey = $slot['session_id'] !== '' ? $slot['session_id'] : '__event__';
            $slotKeyAttr = htmlspecialchars($slotKey);
            $isMultiSlot = $importIsMulti && count($importSlots) > 1;
          ?>
          <div class="import-cert-slot rounded-2xl border border-zinc-200 overflow-hidden" data-session-id="<?= $slotId ?>" data-slot-key="<?= $slotKeyAttr ?>" data-original-title="<?= htmlspecialchars((string) $slot['title']) ?>" data-slot-label="<?= htmlspecialchars((string) $slot['label']) ?>" data-multi="<?= $isMultiSlot ? '1' : '0' ?>">
            <div class="px-4 py-3 border-b border-zinc-200 bg-zinc-50 flex flex-wrap items-center justify-between gap-2">
              <div class="min-w-0">
                <?php if ($importIsMulti): ?>
                  <div class="text-[10px] font-black uppercase tracking-widest text-zinc-500"><?= htmlspecialchars((string) $slot['label']) ?></div>
                  <div class="import-slot-title text-sm font-bold text-zinc-900 truncate"><?= htmlspecialchars((string) $slot['title']) ?></div>
                  <div class="import-slot-template-label text-[11px] text-orange-600 font-semibold truncate mt-0.5 hidden"></div>
                <?php else: ?>
                  <div class="import-slot-title text-sm font-bold text-zinc-900">Certificate preview</div>
                  <div class="import-slot-scope text-[11px] text-zinc-500">Upload a PPTX or pick a saved template</div>
                <?php endif; ?>
              </div>
              <div class="flex items-center gap-2 shrink-0">
                <button type="button" class="import-slot-clear hidden inline-flex items-center justify-center w-9 h-9 rounded-xl border border-zinc-200 bg-white text-zinc-500 hover:text-red-600 hover:border-red-200 transition" title="Clear preview" aria-label="Clear preview">
                  <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
                <label class="import-slot-upload inline-flex items-center gap-1.5 cursor-pointer rounded-xl bg-gradient-to-r from-orange-500 to-red-500 text-white px-3.5 py-2 text-xs font-bold shadow-sm shadow-orange-500/20 hover:opacity-95 transition">
                  <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5m-13.5-9L12 3m0 0l4.5 4.5M12 3v13.5"/></svg>
                  Upload PPTX
                  <input type="file" class="import-slot-file hidden" accept=".pptx,application/vnd.openxmlformats-officedocument.presentationml.presentation">
                </label>
              </div>
            </div>
            <div class="import-cert-preview-box">
              <img src="" alt="" class="import-slot-preview-img hidden">
              <div class="import-slot-preview-empty text-center px-6 py-8 relative z-[1]">
                <div class="mx-auto mb-3 w-12 h-12 rounded-2xl bg-white border border-zinc-200 shadow-sm flex items-center justify-center text-zinc-400">
                  <svg class="w-6 h-6" fill="none" stroke="currentColor" stroke-width="1.75" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m2.25 0H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z"/></svg>
                </div>
                <p class="text-sm font-semibold text-zinc-700">No certificate selected</p>
                <p class="text-xs text-zinc-500 mt-1 max-w-xs mx-auto">Upload a PPTX or choose a template on the left.</p>
              </div>
              <div class="import-slot-loading-overlay hidden absolute inset-0 z-10 flex-col items-center justify-center gap-2 bg-white/90">
                <svg class="w-7 h-7 text-orange-500" viewBox="0 0 24 24" fill="none" aria-hidden="true" style="animation:importSpin .7s linear infinite">
                  <circle opacity=".25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3"></circle>
                  <path opacity=".95" fill="currentColor" d="M4 12a8 8 0 018-8v3a5 5 0 00-5 5H4z"></path>
                </svg>
                <p class="import-slot-loading-text text-xs font-bold text-zinc-600">Loading preview…</p>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
      </section>

      <!-- 3) Scanned / linked registrar codes (read-only) -->
      <aside class="import-codes-pane bg-zinc-50/50 p-4 gap-3">
        <div class="shrink-0">
          <div class="text-[10px] font-black uppercase tracking-widest text-zinc-500"><?= $importIsMulti ? 'Linked codes' : 'Scanned codes' ?></div>
          <?php if ($importIsMulti): ?>
            <p id="importLinkedCodesSummary" class="mt-1 text-[11px] font-mono font-semibold text-zinc-600 break-all"></p>
          <?php endif; ?>
        </div>

        <div class="import-codes-scroll space-y-3">
          <?php foreach ($importSlots as $slot): ?>
            <?php
              $slotId = htmlspecialchars((string) $slot['session_id']);
              $slotKey = $slot['session_id'] !== '' ? $slot['session_id'] : '__event__';
              $slotKeyAttr = htmlspecialchars($slotKey);
            ?>
            <div class="import-code-slot space-y-1.5" data-session-id="<?= $slotId ?>" data-slot-key="<?= $slotKeyAttr ?>">
              <?php if ($importIsMulti): ?>
                <div class="text-[11px] font-bold text-zinc-700"><?= htmlspecialchars((string) $slot['label']) ?> — <?= htmlspecialchars((string) $slot['title']) ?></div>
              <?php endif; ?>
              <div class="import-slot-list-wrap rounded-xl border border-zinc-200 bg-white p-3">
                <ul class="import-slot-list space-y-1.5 text-xs font-mono text-zinc-700">
                  <li class="import-slot-empty text-zinc-400 font-sans text-xs">No codes scanned yet.</li>
                </ul>
              </div>
            </div>
          <?php endforeach; ?>
        </div>

        <div class="shrink-0 space-y-2 pt-1 border-t border-zinc-200">
          <button type="button" id="btnImportCertSubmit" class="w-full inline-flex items-center justify-center rounded-xl bg-gradient-to-r from-orange-500 to-red-500 text-white font-bold px-4 py-3 text-sm shadow-sm shadow-orange-500/20 hover:opacity-95 transition">
            Save &amp; link
          </button>
          <p id="importCertStatus" class="text-xs text-zinc-500 font-semibold text-center min-h-[1rem]"></p>
        </div>
      </aside>
    </div>
    </div>
  </div>
</div>
<script>
window.IMPORT_LINKED_CERT = <?= json_encode($linkedImportJson, JSON_UNESCAPED_SLASHES) ?>;
</script>

<!-- ═══════════  REGISTRATION CONFIRM MODAL  ═══════════ -->
<div id="pendingCertModal" class="fixed inset-0 z-[151] hidden items-center justify-center p-4 bg-zinc-900/60 backdrop-blur-sm transition-opacity duration-300">
  <div class="w-full max-w-2xl max-h-[95vh] flex flex-col rounded-3xl bg-white border border-zinc-200 shadow-2xl overflow-hidden scale-95 transition-transform duration-300" id="pendingCertContent">
    <div class="p-6 border-b border-zinc-200 shrink-0">
      <div class="flex items-start justify-between gap-4">
        <div>
          <h3 class="text-xl font-bold text-zinc-900 tracking-tight">Pending evaluation before certificate sending</h3>
          <p class="text-sm text-zinc-500 mt-1">Only eligible attendees will receive certificates right now.</p>
        </div>
        <button id="btnClosePendingCertModal" class="text-zinc-500 hover:text-zinc-800 focus:outline-none">
          <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
        </button>
      </div>
    </div>
    <div class="p-6 overflow-y-auto flex-1">
      <div class="rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900 mb-4">
        Are you sure these students still have not answered their required evaluation?
      </div>
      <div id="pendingCertList" class="max-h-80 overflow-y-auto space-y-3"></div>
    </div>
    <div class="flex items-center justify-between gap-3 px-6 py-4 border-t border-zinc-200 bg-zinc-50 shrink-0">
      <button id="btnCancelPendingCert" class="rounded-xl border border-zinc-200 bg-white px-4 py-2.5 text-sm font-bold text-zinc-700 hover:bg-zinc-100 transition">Cancel</button>
      <button id="btnConfirmPendingCert" class="rounded-xl bg-emerald-600 px-5 py-2.5 text-sm font-bold text-white hover:bg-emerald-700 transition">Send Eligible Certificates</button>
    </div>
  </div>
</div>

<div id="templateCertModal" class="fixed inset-0 z-[152] hidden items-center justify-center p-4 bg-zinc-900/60 backdrop-blur-sm transition-opacity duration-300">
  <div class="w-full max-w-6xl max-h-[95vh] flex flex-col rounded-3xl bg-white border border-zinc-200 shadow-2xl overflow-hidden scale-95 transition-transform duration-300" id="templateCertContent">
    <div class="p-6 border-b border-zinc-200 shrink-0">
      <div class="flex items-start justify-between gap-4">
        <div class="min-w-0">
          <h3 class="text-xl font-bold text-zinc-900 tracking-tight">Preview Certificate Sending</h3>
          <p class="text-sm text-zinc-500 mt-1">Choose the exact saved template to use before certificates are sent.</p>
        </div>
        <div class="flex items-start gap-3 flex-shrink-0">
          <div class="rounded-2xl border border-zinc-200 bg-zinc-50 px-4 py-3 min-w-[96px]">
            <div class="text-[10px] font-black text-zinc-500 uppercase tracking-widest mb-1">Eligible</div>
            <div id="templateCertEligibleCount" class="text-2xl font-black text-zinc-900 leading-none">0</div>
            <div class="text-[11px] text-zinc-500 mt-1">ready</div>
          </div>
          <div class="rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 min-w-[96px]">
            <div class="text-[10px] font-black text-amber-700 uppercase tracking-widest mb-1">Pending</div>
            <div id="templateCertPendingCount" class="text-2xl font-black text-amber-800 leading-none">0</div>
            <div class="text-[11px] text-amber-700 mt-1">incomplete</div>
          </div>
          <button id="btnCloseTemplateCertModal" class="text-zinc-500 hover:text-zinc-800 focus:outline-none mt-1">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
          </button>
        </div>
      </div>
    </div>
    <div class="p-6 overflow-y-auto flex-1">
      <div class="grid grid-cols-1 xl:grid-cols-[280px,minmax(0,1fr)] gap-5 items-start">
        <div class="rounded-2xl border border-zinc-200 bg-zinc-50 p-4 space-y-3 xl:sticky xl:top-0">
          <div class="flex items-center justify-between gap-3">
            <div>
              <div class="text-sm font-black text-zinc-900 uppercase tracking-widest">Saved Templates</div>
              <div class="text-xs text-zinc-500 mt-1">Drag from here, or click then assign on center board.</div>
            </div>
            <a href="/certificate_editor?event_id=<?= htmlspecialchars($id) ?>" class="rounded-xl border border-zinc-300 bg-white px-3 py-2 text-[11px] font-bold text-zinc-700 hover:bg-zinc-50 transition">Editor</a>
          </div>

          <div id="templateCertGrid" class="max-h-[64vh] overflow-y-auto pr-1 grid grid-cols-1 gap-3">
          <?php if (count($certificateTemplates) === 0): ?>
            <div class="rounded-2xl border border-dashed border-zinc-300 bg-white px-5 py-8 text-center">
              <div class="text-sm font-bold text-zinc-800">No saved templates yet</div>
              <div class="text-xs text-zinc-500 mt-1">Create at least one built-in certificate template before sending.</div>
            </div>
          <?php endif; ?>

          <?php foreach ($certificateTemplates as $template): ?>
            <?php
              $templateId = (string) ($template['id'] ?? '');
              $templateTitle = (string) ($template['title'] ?? 'Certificate Template');
              $templateScope = (string) ($template['template_scope'] ?? 'event');
              $templateSessionId = (string) ($template['scope_session_id'] ?? '');
              $templateScopeLabel = (string) ($template['scope_label'] ?? ($templateScope === 'session' ? 'Seminar' : 'Whole Event'));
              $templateEventTitle = $templateScope === 'session'
                  ? $templateScopeLabel
                  : 'Whole Event';
              $templateThumb = trim((string) ($template['thumbnail_url'] ?? ''));
              $badgeClasses = $templateScope === 'session'
                  ? 'bg-emerald-100 text-emerald-800 border-emerald-200'
                  : 'bg-emerald-100 text-emerald-800 border-emerald-200';
            ?>
            <button
              type="button"
              class="template-send-card group text-left rounded-2xl border border-zinc-200 bg-white overflow-hidden shadow-sm hover:border-emerald-400 hover:shadow-md transition-all duration-200 hover:-translate-y-0.5"
              draggable="true"
              data-template-id="<?= htmlspecialchars($templateId) ?>"
              data-template-title="<?= htmlspecialchars($templateTitle, ENT_QUOTES) ?>"
              data-template-event="<?= htmlspecialchars($templateEventTitle, ENT_QUOTES) ?>"
              data-template-scope="<?= htmlspecialchars($templateScope) ?>"
              data-template-session-id="<?= htmlspecialchars($templateSessionId) ?>"
              data-template-scope-label="<?= htmlspecialchars($templateScopeLabel, ENT_QUOTES) ?>"
              data-template-thumb="<?= htmlspecialchars($templateThumb, ENT_QUOTES) ?>"
              data-template-linked-event="<?= htmlspecialchars((string) ($template['linked_event_label'] ?? ''), ENT_QUOTES) ?>"
            >
              <div class="h-32 bg-zinc-100 border-b border-zinc-200 overflow-hidden relative">
                <?php if ($templateThumb !== ''): ?>
                  <img src="<?= htmlspecialchars($templateThumb) ?>" alt="<?= htmlspecialchars($templateTitle) ?>" class="w-full h-full object-cover">
                <?php else: ?>
                  <div class="w-full h-full flex items-center justify-center text-zinc-300">
                    <svg class="w-12 h-12" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M4.75 19.25h14.5a1.5 1.5 0 001.5-1.5V6.25a1.5 1.5 0 00-1.5-1.5H4.75a1.5 1.5 0 00-1.5 1.5v11.5a1.5 1.5 0 001.5 1.5z"/><path stroke-linecap="round" stroke-linejoin="round" d="M8 12h8M8 15h5M8 9h8"/></svg>
                  </div>
                <?php endif; ?>
                <span class="absolute top-3 left-3 rounded-full border px-2.5 py-1 text-[10px] font-black uppercase tracking-widest shadow-sm <?= htmlspecialchars($badgeClasses) ?>">
                  <?= htmlspecialchars($templateScopeLabel) ?>
                </span>
              </div>
              <div class="p-4">
                <div class="text-sm font-black text-zinc-900 truncate"><?= htmlspecialchars($templateTitle) ?></div>
                <div class="text-xs text-zinc-500 mt-1 truncate"><?= htmlspecialchars($templateEventTitle) ?></div>
              </div>
            </button>
          <?php endforeach; ?>
          </div>
        </div>

        <div id="seminarTemplateAssignWrap" class="hidden rounded-2xl border border-amber-200 bg-amber-50/70 px-6 py-6 space-y-4 min-h-[64vh]">
          <div class="flex items-center gap-3">
            <div class="w-10 h-10 rounded-xl bg-amber-100 border border-amber-200 flex items-center justify-center flex-shrink-0">
              <svg class="w-5 h-5 text-amber-600" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6A2.25 2.25 0 016 3.75h2.25A2.25 2.25 0 0110.5 6v2.25a2.25 2.25 0 01-2.25 2.25H6a2.25 2.25 0 01-2.25-2.25V6zM3.75 15.75A2.25 2.25 0 016 13.5h2.25a2.25 2.25 0 012.25 2.25V18a2.25 2.25 0 01-2.25 2.25H6A2.25 2.25 0 013.75 18v-2.25zM13.5 6a2.25 2.25 0 012.25-2.25H18A2.25 2.25 0 0120.25 6v2.25A2.25 2.25 0 0118 10.5h-2.25a2.25 2.25 0 01-2.25-2.25V6zM13.5 15.75a2.25 2.25 0 012.25-2.25H18a2.25 2.25 0 012.25 2.25V18A2.25 2.25 0 0118 20.25h-2.25A2.25 2.25 0 0113.5 18v-2.25z"/></svg>
            </div>
            <div>
              <div class="text-base font-black text-amber-900">Assignment Center</div>
              <div id="templateModeLabel" class="text-xs text-amber-700 mt-0.5">Mode: Simple (Whole Event)</div>
            </div>
          </div>
          <div class="rounded-xl border border-amber-200 bg-white/90 px-3 py-2 text-[11px] font-semibold text-amber-700">
            Drop template cards here. You can also click a template and then click a target.
          </div>
          <div id="seminarTemplateAssignRows" class="space-y-3"></div>
        </div>

        <div id="templateCertSidebar" class="space-y-4 xl:col-start-2">
          <div id="templateCertSelectedWrap" class="rounded-2xl border border-amber-200 bg-amber-50 px-4 py-4">
            <div class="text-[11px] font-black text-amber-700 uppercase tracking-widest mb-2">Selected Template</div>
            <div id="templateCertSelectedLabel" class="text-sm font-black text-amber-900 leading-snug">None selected yet</div>
            <div class="text-xs text-amber-700 mt-1">Only the chosen template will be sent.</div>
          </div>

          <div id="templateCertSinglePreviewWrap" class="rounded-2xl border border-zinc-200 bg-zinc-50 p-4">
            <div class="text-[11px] font-black text-zinc-500 uppercase tracking-widest mb-3">Preview</div>
            <div class="rounded-2xl border border-zinc-200 bg-white overflow-hidden shadow-sm">
              <div id="templateCertPreviewThumbWrap" class="aspect-[4/3] bg-zinc-100 flex items-center justify-center overflow-hidden">
                <img id="templateCertPreviewThumb" src="" alt="" class="hidden w-full h-full object-cover">
                <div id="templateCertPreviewEmpty" class="text-center px-4">
                  <div class="text-sm font-bold text-zinc-700">No template selected yet</div>
                  <div class="text-xs text-zinc-500 mt-1">Pick a saved template to preview.</div>
                </div>
              </div>
            </div>
            <div class="rounded-2xl border border-zinc-200 bg-white p-4 shadow-sm mt-3">
              <div id="templateCertPreviewTitle" class="text-base font-black text-zinc-900">No template selected yet</div>
              <div id="templateCertPreviewScope" class="mt-2 inline-flex rounded-full border border-zinc-200 bg-zinc-50 px-2.5 py-1 text-[11px] font-black uppercase tracking-widest text-zinc-600">Choose a template</div>
              <div id="templateCertPreviewEvent" class="mt-2 text-xs font-semibold text-zinc-500"></div>
            </div>
          </div>

          <div id="templateCertPendingWrap" class="hidden rounded-2xl border border-amber-200 bg-amber-50 px-4 py-4">
            <div class="text-sm font-bold text-amber-900 mb-3">Pending evaluation participants (excluded from sending).</div>
            <div id="templateCertPendingList" class="max-h-36 overflow-y-auto space-y-3"></div>
          </div>
        </div>
      </div>
    </div>
    <div class="flex items-center justify-between gap-3 px-6 py-4 border-t border-zinc-200 bg-zinc-50 shrink-0">
      <button id="btnCancelTemplateCert" class="rounded-xl border border-zinc-200 bg-white px-4 py-2.5 text-sm font-bold text-zinc-700 hover:bg-zinc-100 transition">Cancel</button>
      <button id="btnConfirmTemplateCert" class="rounded-xl bg-emerald-600 px-5 py-2.5 text-sm font-bold text-white hover:bg-emerald-700 transition">Send Using Selected Template</button>
    </div>
  </div>
</div>

<?php endif; ?>

<?php if ($role === 'admin'): ?>
<div id="confirmRegModal" class="fixed inset-0 z-[100] hidden items-center justify-center p-4 bg-zinc-900/50 backdrop-blur-sm">
  <div class="w-full max-w-sm rounded-3xl bg-white border border-zinc-200 shadow-xl overflow-hidden">
    <div class="p-6 pb-5 text-center">
        <div class="w-16 h-16 rounded-full bg-emerald-50 border border-emerald-100 flex items-center justify-center mx-auto mb-4 text-emerald-500 text-3xl font-black">?</div>
        <h3 class="text-xl font-bold text-zinc-900 tracking-tight leading-none mb-2">Open Registration?</h3>
        <p class="text-sm font-medium text-zinc-500 leading-relaxed">
          <?php if (is_event_registration_window_closed($event)): ?>
            The registration close date already passed. Re-opening will turn Allow Registration back on and <span class="font-semibold text-zinc-700">will no longer follow the previous close-limit schedule</span>.
          <?php else: ?>
            Are you sure you want to let all targeted students register instantly for this event?
          <?php endif; ?>
        </p>
    </div>
    <div class="flex border-t border-zinc-200">
        <button id="btnCancelReg" class="flex-1 py-3 text-sm font-bold text-zinc-600 hover:bg-zinc-50 border-r border-zinc-200 transition">Cancel</button>
        <button id="btnConfirmReg" class="flex-1 py-3 text-sm font-bold text-emerald-600 bg-emerald-50 hover:bg-emerald-100 hover:text-emerald-700 transition">Open Registration</button>
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

<style>
@keyframes assignmentDropzonePop {
  0% { transform: scale(1); box-shadow: 0 0 0 rgba(245, 158, 11, 0); }
  40% { transform: scale(1.015); box-shadow: 0 0 0 6px rgba(245, 158, 11, 0.18); }
  100% { transform: scale(1); box-shadow: 0 0 0 rgba(245, 158, 11, 0); }
}

.assignment-dropzone-pop {
  animation: assignmentDropzonePop 380ms ease-out;
}

@keyframes importSpin { to { transform: rotate(360deg); } }

/* Auto-send status: side-by-side columns that scroll on their own. Hand-written
   because the compiled Tailwind build has no divide-x / arbitrary height utilities,
   and no z-[154] — without this the sticky page header paints over the modal. */
#certAutoStatusModal { z-index: 154; }

.cert-status-panel { height: min(88vh, 700px); }

.cert-status-grid {
  display: grid;
  grid-template-columns: 1fr;
  min-height: 0;
}

.cert-status-col {
  display: flex;
  flex-direction: column;
  min-height: 0;
  border-top: 1px solid #e4e4e7;
}

.cert-status-col:first-child { border-top: 0; }

.cert-status-scroll {
  flex: 1 1 auto;
  min-height: 0;
  overflow-y: auto;
}

@media (min-width: 768px) {
  .cert-status-grid { grid-template-columns: 1fr 1fr; }
  .cert-status-col { border-top: 0; border-left: 1px solid #e4e4e7; }
  .cert-status-col:first-child { border-left: 0; }
}

/* Import / Link modal: contain previews so they never bleed into the codes column,
   and keep both seminar code lists independently scrollable. */
#importCertModalPanel {
  height: min(92vh, 820px);
}
#importCertModal .import-cert-preview-box {
  position: relative;
  overflow: hidden;
  display: flex;
  align-items: center;
  justify-content: center;
  background: #f4f4f5;
}
#importCertModal .import-cert-slot[data-multi="1"] .import-cert-preview-box {
  height: 180px;
}
@media (min-width: 1024px) {
  #importCertModal .import-cert-slot[data-multi="1"] .import-cert-preview-box {
    height: 200px;
  }
}
#importCertModal .import-cert-slot:not([data-multi="1"]) .import-cert-preview-box {
  height: min(42vh, 340px);
}
@media (min-width: 1024px) {
  #importCertModal .import-cert-slot:not([data-multi="1"]) .import-cert-preview-box {
    height: min(48vh, 380px);
  }
}
#importCertModal .import-slot-preview-img {
  position: absolute;
  inset: 0;
  width: 100%;
  height: 100%;
  object-fit: contain;
  background: #fff;
}
#importCertModal .import-codes-pane {
  display: flex;
  flex-direction: column;
  min-height: 0;
  overflow: hidden;
}
#importCertModal .import-codes-scroll {
  flex: 1 1 auto;
  min-height: 0;
  overflow-y: auto;
}
#importCertModal .import-slot-list-wrap {
  min-height: 96px;
  max-height: min(28vh, 220px);
  overflow-y: auto;
}

/* Locked saved-template card (clear a seminar with ✕ first). Own class because the
   compiled Tailwind build has no opacity-40/grayscale utilities. */
.import-locked-card {
  opacity: 0.4;
  filter: grayscale(100%);
  pointer-events: none;
  cursor: not-allowed;
}
</style>

<script>
<?php if ($role === 'admin'): ?>
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
const eventModeInput = document.getElementById('event_mode');
const seminarEditSection = document.getElementById('seminarEditSection');
const seminar2Editor = document.getElementById('seminar2Editor');
const btnToggleSeminar2 = document.getElementById('btnToggleSeminar2');
const registrationCloseExtendInput = document.getElementById('registration_close_extend_days');
const registrationCloseBaseLabel = document.getElementById('registrationCloseBaseLabel');
const registrationClosePreview = document.getElementById('registrationClosePreview');
const registrationCloseExtendSection = document.getElementById('registrationCloseExtendSection');
const registrationCloseExtendHint = document.getElementById('registrationCloseExtendHint');
const registrationCloseExtendError = document.getElementById('registrationCloseExtendError');
let editRegistrationCloseWeeks = null;
let editRegistrationCloseExtendValid = true;

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

function formatManilaDateLabel(dateObj) {
    if (!(dateObj instanceof Date) || Number.isNaN(dateObj.getTime())) return '—';
    return dateObj.toLocaleDateString('en-US', {
        month: 'short',
        day: 'numeric',
        year: 'numeric',
        timeZone: 'Asia/Manila',
    });
}

function manilaTodayUtcDate() {
    const parts = new Intl.DateTimeFormat('en-CA', {
        timeZone: 'Asia/Manila',
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
    }).formatToParts(new Date());
    const y = Number(parts.find((p) => p.type === 'year')?.value);
    const m = Number(parts.find((p) => p.type === 'month')?.value);
    const d = Number(parts.find((p) => p.type === 'day')?.value);
    return new Date(Date.UTC(y, m - 1, d));
}

function parseLocalDateUtc(startLocalValue) {
    const datePart = String(startLocalValue || '').slice(0, 10);
    const parts = datePart.split('-').map((n) => Number(n));
    if (parts.length !== 3 || parts.some((n) => !Number.isFinite(n))) return null;
    const [year, month, day] = parts;
    return new Date(Date.UTC(year, month - 1, day));
}

function daysBetweenUtc(a, b) {
    return Math.round((b.getTime() - a.getTime()) / 86400000);
}

/** End datetime-local selection must start at (and not precede) start. */
function syncEndMinFromStart(startInput, endInput) {
    if (!startInput || !endInput) return;
    const startValue = String(startInput.value || '').trim();
    if (!startValue) {
        endInput.removeAttribute('min');
        return;
    }
    endInput.min = startValue;
    const endValue = String(endInput.value || '').trim();
    if (endValue && endValue < startValue) {
        endInput.value = startValue;
    }
}

function syncMainEventEndMin() {
    syncEndMinFromStart(
        document.getElementById('start_at_local'),
        document.getElementById('end_at_local'),
    );
}

function syncSeminarEndMin(prefix) {
    syncEndMinFromStart(
        document.getElementById(`${prefix}_start_local`),
        document.getElementById(`${prefix}_end_local`),
    );
}

/** Resolve user-facing +N days from anchor → stored offset from base + preview. */
function resolveRegistrationCloseExtend(startLocalValue, weeks, requestedFromAnchor) {
    const startDate = parseLocalDateUtc(startLocalValue);
    if (!startDate || !weeks || weeks < 1) {
        return { ok: false, error: 'Set a start date to preview the registration close date.' };
    }
    const base = new Date(startDate.getTime());
    base.setUTCDate(base.getUTCDate() - (weeks * 7));
    const maxLast = new Date(startDate.getTime());
    maxLast.setUTCDate(maxLast.getUTCDate() - 3);

    const today = manilaTodayUtcDate();
    const anchor = today.getTime() > base.getTime() ? today : base;
    const requested = Number(requestedFromAnchor);
    if (!Number.isFinite(requested) || !Number.isInteger(requested)) {
        return { ok: false, error: 'Extension days must be a whole number (0 or greater).' };
    }
    if (requested < 0) {
        return { ok: false, error: 'Extension days cannot be negative.' };
    }
    if (requested > 60) {
        return { ok: false, error: 'Extension days cannot be more than 60.' };
    }

    const proposed = new Date(anchor.getTime());
    proposed.setUTCDate(proposed.getUTCDate() + requested);

    if (proposed.getTime() > maxLast.getTime()) {
        const maxFromAnchor = daysBetweenUtc(anchor, maxLast);
        if (maxFromAnchor < 0) {
            return {
                ok: false,
                error: `Registration can only stay open until 3 days before the event start (${formatManilaDateLabel(maxLast)}). That date has already passed for this schedule.`,
                base,
                anchor,
                maxLast,
            };
        }
        return {
            ok: false,
            error: `Too many extension days. Maximum close date is 3 days before start (${formatManilaDateLabel(maxLast)}). From ${formatManilaDateLabel(anchor)} you can add at most +${maxFromAnchor} day${maxFromAnchor === 1 ? '' : 's'}.`,
            base,
            anchor,
            maxLast,
            maxFromAnchor,
        };
    }

    let stored = daysBetweenUtc(base, proposed);
    if (stored < 0) stored = 0;
    return {
        ok: true,
        storedDays: stored,
        lastDay: proposed,
        base,
        anchor,
        maxLast,
        requested,
    };
}

function displayExtendDaysFromStored(startLocalValue, weeks, storedExtend) {
    const startDate = parseLocalDateUtc(startLocalValue);
    if (!startDate || !weeks || weeks < 1) return 0;
    const base = new Date(startDate.getTime());
    base.setUTCDate(base.getUTCDate() - (weeks * 7));
    const last = new Date(base.getTime());
    const stored = Math.max(0, Number(storedExtend) || 0);
    last.setUTCDate(last.getUTCDate() + stored);
    const today = manilaTodayUtcDate();
    const anchor = today.getTime() > base.getTime() ? today : base;
    const n = daysBetweenUtc(anchor, last);
    return n > 0 ? n : 0;
}

function setRegistrationCloseExtendError(message) {
    if (!registrationCloseExtendError) return;
    if (!message) {
        registrationCloseExtendError.textContent = '';
        registrationCloseExtendError.classList.add('hidden');
        registrationCloseExtendInput?.classList.remove('border-red-400', 'ring-2', 'ring-red-200');
        return;
    }
    registrationCloseExtendError.textContent = message;
    registrationCloseExtendError.classList.remove('hidden');
    registrationCloseExtendInput?.classList.add('border-red-400', 'ring-2', 'ring-red-200');
}

function refreshRegistrationClosePreview() {
    if (!registrationClosePreview) return;
    const startLocal = document.getElementById('start_at_local')?.value || '';
    const weeks = editRegistrationCloseWeeks;
    const requestedRaw = registrationCloseExtendInput?.value;
    if (!weeks) {
        registrationCloseExtendSection?.classList.add('hidden');
        registrationClosePreview.textContent = 'Registration closes: no close limit set for this event.';
        editRegistrationCloseExtendValid = true;
        setRegistrationCloseExtendError('');
        if (registrationCloseExtendHint) registrationCloseExtendHint.textContent = '';
        return;
    }
    registrationCloseExtendSection?.classList.remove('hidden');
    if (registrationCloseBaseLabel) {
        registrationCloseBaseLabel.textContent = `${weeks} week${weeks === 1 ? '' : 's'} before event start`;
    }

    const resolved = resolveRegistrationCloseExtend(startLocal, weeks, requestedRaw === '' ? 0 : requestedRaw);
    if (!startLocal) {
        registrationClosePreview.textContent = 'Registration closes: set a start date to preview.';
        editRegistrationCloseExtendValid = false;
        setRegistrationCloseExtendError('');
        if (registrationCloseExtendHint) registrationCloseExtendHint.textContent = '';
        return;
    }

    if (registrationCloseExtendHint && resolved.anchor && resolved.maxLast) {
        const maxFromAnchor = daysBetweenUtc(resolved.anchor, resolved.maxLast);
        registrationCloseExtendHint.textContent = maxFromAnchor < 0
            ? `Anchor: ${formatManilaDateLabel(resolved.anchor)} · Max close already passed (${formatManilaDateLabel(resolved.maxLast)}).`
            : `Counting from ${formatManilaDateLabel(resolved.anchor)} · Max +${maxFromAnchor} day${maxFromAnchor === 1 ? '' : 's'} (until ${formatManilaDateLabel(resolved.maxLast)}).`;
    }

    if (!resolved.ok) {
        editRegistrationCloseExtendValid = false;
        setRegistrationCloseExtendError(resolved.error || 'Invalid extension.');
        registrationClosePreview.textContent = 'Registration closes: fix the extension to continue.';
        registrationClosePreview.classList.remove('text-sky-900');
        registrationClosePreview.classList.add('text-red-600');
        return;
    }

    editRegistrationCloseExtendValid = true;
    setRegistrationCloseExtendError('');
    registrationClosePreview.classList.remove('text-red-600');
    registrationClosePreview.classList.add('text-sky-900');
    const extendNote = resolved.requested > 0
        ? ` ( +${resolved.requested} day${resolved.requested === 1 ? '' : 's'} from ${formatManilaDateLabel(resolved.anchor)} )`
        : '';
    registrationClosePreview.textContent = `Registration closes: ${formatManilaDateLabel(resolved.lastDay)}${extendNote}`;
}

function clearSeminarEditor(prefix) {
    document.getElementById(`${prefix}_title`).value = '';
    document.getElementById(`${prefix}_start_local`).value = '';
    document.getElementById(`${prefix}_end_local`).value = '';
}

function fillSeminarEditor(prefix, session) {
    document.getElementById(`${prefix}_title`).value = session?.title || '';
    document.getElementById(`${prefix}_start_local`).value = toLocalInput(session?.start_at || '');
    document.getElementById(`${prefix}_end_local`).value = toLocalInput(session?.end_at || '');
    syncSeminarEndMin(prefix);
}

function setSeminar2Visible(visible) {
    seminar2Editor?.classList.toggle('hidden', !visible);
    if (btnToggleSeminar2) {
        btnToggleSeminar2.textContent = visible ? 'Remove Seminar 2' : 'Add Seminar 2';
    }
}

function collectSeminar(prefix) {
    const title = document.getElementById(`${prefix}_title`).value.trim();
    const startLocal = document.getElementById(`${prefix}_start_local`).value;
    const endLocal = document.getElementById(`${prefix}_end_local`).value;

    if (!title && !startLocal && !endLocal) {
        return null;
    }
    if (!startLocal || !endLocal) {
        throw new Error('Please complete seminar start and end date/time.');
    }

    const start = new Date(startLocal);
    const end = new Date(endLocal);
    if (!(start instanceof Date) || Number.isNaN(start.getTime()) || !(end instanceof Date) || Number.isNaN(end.getTime())) {
        throw new Error('Invalid seminar date/time.');
    }
    if (end <= start) {
        throw new Error('Each seminar end time must be after its start time.');
    }

    return {
        title: title || 'Seminar',
        start_at: start.toISOString(),
        end_at: end.toISOString()
    };
}

btnToggleSeminar2?.addEventListener('click', () => {
    const willShow = seminar2Editor?.classList.contains('hidden') ?? true;
    setSeminar2Visible(willShow);
    if (!willShow) {
        clearSeminarEditor('seminar2');
    }
});

if (btnEdit) {
  btnEdit.addEventListener('click', () => {
    resetMainEditorTools();
    document.getElementById('event_id').value = btnEdit.dataset.id;
    document.getElementById('title').value = btnEdit.dataset.title;
    document.getElementById('location').value = btnEdit.dataset.location;
    document.getElementById('description').value = btnEdit.dataset.description;
    
    document.getElementById('start_at_local').value = toLocalInput(btnEdit.dataset.start_at);
    document.getElementById('end_at_local').value = toLocalInput(btnEdit.dataset.end_at);
    syncMainEventEndMin();

    const weeksRaw = String(btnEdit.dataset.registration_close_weeks || '').trim();
    const weeksParsed = Number.parseInt(weeksRaw, 10);
    editRegistrationCloseWeeks = Number.isFinite(weeksParsed) && weeksParsed >= 1 && weeksParsed <= 4
        ? weeksParsed
        : null;
    if (registrationCloseExtendInput) {
        const extendRaw = String(btnEdit.dataset.registration_close_extend_days || '0').trim();
        const storedExtend = Number.parseInt(extendRaw, 10);
        const startLocal = document.getElementById('start_at_local').value;
        const weeks = editRegistrationCloseWeeks;
        registrationCloseExtendInput.value = String(
            displayExtendDaysFromStored(
                startLocal,
                weeks,
                Number.isFinite(storedExtend) ? storedExtend : 0,
            ),
        );
    }
    refreshRegistrationClosePreview();

    let sessions = [];
    try {
        sessions = JSON.parse(btnEdit.dataset.sessions || '[]');
    } catch (_) {
        sessions = [];
    }
    const isSeminarBased = (btnEdit.dataset.event_mode || 'simple') === 'seminar_based' || sessions.length > 0;
    eventModeInput.value = isSeminarBased ? 'seminar_based' : 'simple';
    seminarEditSection?.classList.toggle('hidden', !isSeminarBased);
    clearSeminarEditor('seminar1');
    clearSeminarEditor('seminar2');
    setSeminar2Visible(false);
    if (isSeminarBased) {
        if (sessions[0]) {
            fillSeminarEditor('seminar1', sessions[0]);
        } else {
            document.getElementById('seminar1_title').value = 'Seminar 1';
            document.getElementById('seminar1_start_local').value = toLocalInput(btnEdit.dataset.start_at);
            document.getElementById('seminar1_end_local').value = toLocalInput(btnEdit.dataset.end_at);
        }
        if (sessions[1]) {
            fillSeminarEditor('seminar2', sessions[1]);
            setSeminar2Visible(true);
        }
    }

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

  const startAtLocal = document.getElementById('start_at_local');
  const endAtLocal = document.getElementById('end_at_local');
  startAtLocal?.addEventListener('change', () => {
    syncMainEventEndMin();
    refreshRegistrationClosePreview();
  });
  startAtLocal?.addEventListener('input', () => {
    syncMainEventEndMin();
    refreshRegistrationClosePreview();
  });
  endAtLocal?.addEventListener('change', syncMainEventEndMin);
  endAtLocal?.addEventListener('focus', syncMainEventEndMin);
  registrationCloseExtendInput?.addEventListener('change', refreshRegistrationClosePreview);
  registrationCloseExtendInput?.addEventListener('input', refreshRegistrationClosePreview);

  ['seminar1', 'seminar2'].forEach((prefix) => {
    const sStart = document.getElementById(`${prefix}_start_local`);
    const sEnd = document.getElementById(`${prefix}_end_local`);
    sStart?.addEventListener('change', () => syncSeminarEndMin(prefix));
    sStart?.addEventListener('input', () => syncSeminarEndMin(prefix));
    sEnd?.addEventListener('change', () => syncSeminarEndMin(prefix));
    sEnd?.addEventListener('focus', () => syncSeminarEndMin(prefix));
  });
}

// Save Edit
document.getElementById('btnSubmitForm')?.addEventListener('click', async () => {
    const msg = document.getElementById('formMsg');
    syncMainEventEndMin();
    const start_local = document.getElementById('start_at_local').value;
    const end_local = document.getElementById('end_at_local').value;

    if (!start_local || !end_local) { msg.textContent = 'Please fill all fields.'; return; }

    if (end_local < start_local) {
        msg.textContent = 'End date/time must be on or after the start date/time.';
        return;
    }

    refreshRegistrationClosePreview();
    if (editRegistrationCloseWeeks && !editRegistrationCloseExtendValid) {
        msg.textContent = registrationCloseExtendError?.textContent
            || 'Fix the registration close extension before saving.';
        return;
    }

    const sd = new Date(start_local);
    const ed = new Date(end_local);
    if (!(ed > sd)) {
        msg.textContent = 'End date/time must be after the start date/time.';
        return;
    }

    const payload = {
      event_id: document.getElementById('event_id').value,
      title: document.getElementById('title').value.trim(),
      location: document.getElementById('location').value.trim(),
      description: document.getElementById('description').value.trim(),
      start_at: sd.toISOString(),
      end_at: ed.toISOString(),
      event_mode: eventModeInput?.value || 'simple',
      // User-facing days from anchor; API converts to stored base offset.
      registration_close_extend_days: Number(registrationCloseExtendInput?.value || 0),
      csrf_token: window.CSRF_TOKEN
    };

    if ((eventModeInput?.value || 'simple') === 'seminar_based') {
      try {
        const seminar1 = collectSeminar('seminar1');
        if (!seminar1) {
          throw new Error('Seminar 1 schedule is required for seminar-based events.');
        }
        const seminar2Visible = !(seminar2Editor?.classList.contains('hidden'));
        const seminar2 = seminar2Visible ? collectSeminar('seminar2') : null;
        payload.sessions = seminar2 ? [seminar1, seminar2] : [seminar1];
      } catch (seminarErr) {
        msg.textContent = seminarErr.message || 'Invalid seminar schedule.';
        return;
      }
    }
    
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

// Registration Access Toggle Logic
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
        if (btnToggleReg.getAttribute('aria-checked') === 'false') {
            publishModal.classList.remove('hidden');
            publishModal.classList.add('flex');
        } else {
            triggerRegistrationAccessUpdate(false);
        }
    });

    btnCancelReg.addEventListener('click', () => {
        publishModal.classList.add('hidden');
        publishModal.classList.remove('flex');
    });

    btnConfirmReg.addEventListener('click', () => {
        publishModal.classList.add('hidden');
        publishModal.classList.remove('flex');
        triggerRegistrationAccessUpdate(true);
    });
}

async function triggerRegistrationAccessUpdate(allowRegistration) {
    try {
        const res = await fetch('/api/event_registration_access_toggle.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({
            event_id: <?= json_encode($id) ?>,
            allow_registration: allowRegistration,
            csrf_token: window.CSRF_TOKEN
          })
        });
        const data = await res.json();
        if (data.ok) {
          window.location.reload();
          return;
        }
        alert(data.error || 'Failed to update registration access.');
    } catch(err) { alert('Network Error'); }
}

<?php endif; ?>

<?php if ($canManageCertificates): ?>
// ------------------------------------------------------------------
// BROADCAST CERTIFICATES LOGIC (Page 32 Simulation)
// ------------------------------------------------------------------
const btnSendCert = document.getElementById('btnSendCert');
const btnImportCert = document.getElementById('btnImportCert');
const btnCertAutoStatus = document.getElementById('btnCertAutoStatus');
const certAutoStatusModal = document.getElementById('certAutoStatusModal');
const btnCloseCertAutoStatus = document.getElementById('btnCloseCertAutoStatus');
const btnRefreshCertAutoStatus = document.getElementById('btnRefreshCertAutoStatus');
const btnSelectAllMissingCerts = document.getElementById('btnSelectAllMissingCerts');
const btnSendSelectedMissingCerts = document.getElementById('btnSendSelectedMissingCerts');
const importCertModal = document.getElementById('importCertModal');
const btnCloseImportCert = document.getElementById('btnCloseImportCert');
const importCertStatus = document.getElementById('importCertStatus');
const btnImportCertSubmit = document.getElementById('btnImportCertSubmit');

function escapeCertHtml(value) {
  return String(value ?? '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;');
}

function updateMissingSendButtonState() {
  if (!btnSendSelectedMissingCerts) return;
  const n = document.querySelectorAll('#certStatusMissingList input.cert-missing-check:checked').length;
  btnSendSelectedMissingCerts.disabled = n === 0;
  btnSendSelectedMissingCerts.textContent = n > 0 ? `Send selected (${n})` : 'Send selected';
}

function renderCertAutoStatus(data) {
  const received = Array.isArray(data?.received) ? data.received : [];
  const missing = Array.isArray(data?.missing) ? data.missing : [];
  const receivedEl = document.getElementById('certStatusReceivedList');
  const missingEl = document.getElementById('certStatusMissingList');
  const receivedCount = document.getElementById('certStatusReceivedCount');
  const missingCount = document.getElementById('certStatusMissingCount');
  const footer = document.getElementById('certAutoStatusFooter');
  if (receivedCount) receivedCount.textContent = String(Number(data?.received_count ?? received.length));
  if (missingCount) missingCount.textContent = String(Number(data?.missing_count ?? missing.length));

  if (receivedEl) {
    if (received.length === 0) {
      receivedEl.innerHTML = '<div class="text-sm text-zinc-400 font-semibold py-4 text-center">No certificates received yet.</div>';
    } else {
      receivedEl.innerHTML = received.map((row) => {
        const sessions = Array.isArray(row.sessions) ? row.sessions : [];
        const codes = sessions
          .map((s) => String(s?.certificate_code || '').trim())
          .filter(Boolean);
        if (codes.length === 0) {
          const single = String(row.certificate_code || '').trim();
          if (single) codes.push(single);
        }
        const code = escapeCertHtml(codes.join('/'));
        const perSeminar = sessions
          .map((s) => `${String(s?.session_title || 'Seminar')}: ${String(s?.certificate_code || '—')}`)
          .join('\n');
        const name = escapeCertHtml(row.name || 'Student');
        const email = escapeCertHtml(row.email || '');
        const seminarLine = sessions.length > 1
          ? `<div class="text-[10px] font-bold text-emerald-700 mt-0.5">${sessions.length} seminar codes</div>`
          : '';
        return `<div class="rounded-xl border border-emerald-100 bg-emerald-50/60 px-3 py-2.5"${perSeminar ? ` title="${escapeCertHtml(perSeminar)}"` : ''}>
          <div class="flex items-start justify-between gap-2">
            <div class="min-w-0">
              <div class="text-sm font-bold text-zinc-900 truncate">${name}</div>
              <div class="text-xs text-zinc-500 truncate">${email}</div>
            </div>
            <div class="text-[10px] font-black uppercase tracking-wider text-emerald-700 shrink-0">Received</div>
          </div>
          <div class="text-xs font-semibold text-zinc-700 font-mono mt-1.5 break-all">${code || '—'}</div>
          ${seminarLine}
        </div>`;
      }).join('');
    }
  }

  if (missingEl) {
    if (missing.length === 0) {
      missingEl.innerHTML = '<div class="text-sm text-zinc-400 font-semibold py-4 text-center">Everyone who finished evaluation already has a certificate.</div>';
    } else {
      missingEl.innerHTML = missing.map((row) => {
        const id = escapeCertHtml(row.student_id || '');
        const name = escapeCertHtml(row.name || 'Student');
        const email = escapeCertHtml(row.email || '');
        const reason = escapeCertHtml(row.reason || 'Missing certificate');
        const sessions = Array.isArray(row.sessions) ? row.sessions : [];
        const seminarLine = sessions.length > 0
          ? `<div class="text-[10px] font-bold text-amber-700 mt-0.5 truncate">${escapeCertHtml(sessions.map((s) => String(s?.session_title || 'Seminar')).join(' • '))}</div>`
          : '';
        return `<label class="rounded-xl border border-amber-100 bg-amber-50/50 px-3 py-2.5 flex items-start gap-2 cursor-pointer hover:border-amber-200">
          <input type="checkbox" class="cert-missing-check mt-1 rounded border-zinc-300 text-sky-600 focus:ring-sky-500" value="${id}">
          <div class="min-w-0 flex-1">
            <div class="text-sm font-bold text-zinc-900 truncate">${name}</div>
            <div class="text-xs text-zinc-500 truncate">${email}</div>
            <div class="text-[11px] text-amber-800 mt-0.5">${reason}</div>
            ${seminarLine}
          </div>
          <button type="button" class="cert-missing-send-one shrink-0 rounded-lg bg-sky-600 text-white text-[11px] font-bold px-2.5 py-1.5 hover:bg-sky-700" data-student-id="${id}">Send</button>
        </label>`;
      }).join('');
      missingEl.querySelectorAll('.cert-missing-check').forEach((el) => {
        el.addEventListener('change', updateMissingSendButtonState);
      });
      missingEl.querySelectorAll('.cert-missing-send-one').forEach((btn) => {
        btn.addEventListener('click', (e) => {
          e.preventDefault();
          e.stopPropagation();
          const sid = btn.getAttribute('data-student-id') || '';
          if (sid) void sendMissingCertificates([sid], btn);
        });
      });
    }
  }
  updateMissingSendButtonState();
  if (footer) {
    const poolReady = data?.pool_ready !== false;
    if (missing.length === 0) {
      footer.textContent = 'Auto-send is up to date for evaluation completers.';
    } else if (!poolReady) {
      footer.textContent = 'Missing seed codes — open Import Certificate, add a PPTX/seed (e.g. LU-AA-FO-180-01), Save & link, then Send.';
    } else {
      footer.textContent = 'Select students and click Send — same linked template + next auto-count code.';
    }
  }
}

async function loadCertAutoStatus() {
  const receivedEl = document.getElementById('certStatusReceivedList');
  const missingEl = document.getElementById('certStatusMissingList');
  if (receivedEl) receivedEl.innerHTML = '<div class="text-sm text-zinc-400 font-semibold py-4 text-center">Loading…</div>';
  if (missingEl) missingEl.innerHTML = '<div class="text-sm text-zinc-400 font-semibold py-4 text-center">Loading…</div>';
  const res = await fetch('/api/certificates_manual_status.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
    body: JSON.stringify({
      action: 'status',
      event_id: <?= json_encode($id) ?>,
      csrf_token: window.CSRF_TOKEN || '',
    }),
  });
  const data = await res.json();
  if (!data.ok) throw new Error(data.error || 'Failed to load certificate status');
  renderCertAutoStatus(data);
  return data;
}

async function sendMissingCertificates(studentIds, triggerBtn) {
  const ids = (studentIds || []).map((x) => String(x || '').trim()).filter(Boolean);
  if (ids.length === 0) return;
  const buttons = [btnSendSelectedMissingCerts, triggerBtn].filter(Boolean);
  buttons.forEach((b) => { b.disabled = true; });
  if (triggerBtn) triggerBtn.textContent = 'Sending…';
  if (btnSendSelectedMissingCerts) btnSendSelectedMissingCerts.textContent = 'Sending…';
  try {
    const res = await fetch('/api/certificates_manual_status.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
      body: JSON.stringify({
        action: 'send',
        event_id: <?= json_encode($id) ?>,
        student_ids: ids,
        csrf_token: window.CSRF_TOKEN || '',
      }),
    });
    const data = await res.json();
    if (!data.ok) throw new Error(data.error || 'Send failed');
    renderCertAutoStatus(data);
    const issued = Number(data.issued_total || 0);
    const footer = document.getElementById('certAutoStatusFooter');
    if (footer) {
      if (issued > 0) {
        footer.textContent = `Sent ${issued} certificate(s) with next auto-count code(s).`;
      } else {
        const students = Array.isArray(data.students) ? data.students : [];
        const skips = students.map((s) => String(s?.skipped || '')).filter(Boolean);
        if (skips.includes('no_pool_codes')) {
          footer.textContent = 'No certificate seed in pool — Import PPTX or enter a seed (e.g. LU-AA-FO-180-01), Save & link, then Send again.';
        } else if (skips.includes('checkout_required')) {
          footer.textContent = 'Student must time out (check out) before a certificate can issue.';
        } else if (skips.includes('eval_incomplete')) {
          footer.textContent = 'Evaluation still incomplete for those students.';
        } else if (skips.includes('insert_failed')) {
          footer.textContent = 'Certificate insert failed — try again or check server logs.';
        } else if (skips.includes('already_issued')) {
          footer.textContent = 'Already issued for those students.';
        } else {
          footer.textContent = 'No new certificates issued — check timeout/eval gates or seed pool.';
        }
      }
    }
  } catch (err) {
    window.alert(err.message || 'Send failed');
    updateMissingSendButtonState();
  } finally {
    if (triggerBtn) {
      triggerBtn.disabled = false;
      triggerBtn.textContent = 'Send';
    }
  }
}

function openCertAutoStatusModal() {
  if (!certAutoStatusModal) return;
  certAutoStatusModal.classList.remove('hidden');
  certAutoStatusModal.classList.add('flex');
  void loadCertAutoStatus().catch((err) => {
    const footer = document.getElementById('certAutoStatusFooter');
    if (footer) footer.textContent = err.message || 'Failed to load status';
    const receivedEl = document.getElementById('certStatusReceivedList');
    const missingEl = document.getElementById('certStatusMissingList');
    if (receivedEl) receivedEl.innerHTML = `<div class="text-sm text-red-600 font-semibold py-4 text-center">${escapeCertHtml(err.message || 'Failed')}</div>`;
    if (missingEl) missingEl.innerHTML = '';
  });
}

function closeCertAutoStatusModal() {
  if (!certAutoStatusModal) return;
  certAutoStatusModal.classList.add('hidden');
  certAutoStatusModal.classList.remove('flex');
}

btnCertAutoStatus?.addEventListener('click', openCertAutoStatusModal);
btnCloseCertAutoStatus?.addEventListener('click', closeCertAutoStatusModal);
btnRefreshCertAutoStatus?.addEventListener('click', () => {
  void loadCertAutoStatus().catch((err) => window.alert(err.message || 'Refresh failed'));
});
btnSelectAllMissingCerts?.addEventListener('click', () => {
  document.querySelectorAll('#certStatusMissingList input.cert-missing-check').forEach((el) => {
    el.checked = true;
  });
  updateMissingSendButtonState();
});
btnSendSelectedMissingCerts?.addEventListener('click', () => {
  const ids = Array.from(document.querySelectorAll('#certStatusMissingList input.cert-missing-check:checked'))
    .map((el) => el.value);
  void sendMissingCertificates(ids);
});
certAutoStatusModal?.addEventListener('click', (e) => {
  if (e.target === certAutoStatusModal) closeCertAutoStatusModal();
});

let importSelectedTemplateId = '';
let importActiveSlotKey = '';
/** @type {Record<string, string>} template id per seminar slot */
const importSlotTemplates = {};
/** @type {Record<string, number>} async request generation per slot (ignore stale) */
const importSlotLoadGen = {};
/** @type {Record<string, File|null>} */
const importPendingFiles = {};
/** @type {Record<string, string[]>} */
const importScannedCodes = {};
/** @type {Record<string, object|null>} */
const importScannedLayouts = {};
/** @type {Record<string, string>} template id the PPT layout is bridged to (preview/Save only for this id) */
const importLayoutBoundTemplateId = {};
/** @type {Record<string, object|null>} exact canvas_state last shown in the import preview (WYSIWYG for Save) */
const importSlotPreviewStates = {};
let importLinkedCert = window.IMPORT_LINKED_CERT && (
  (window.IMPORT_LINKED_CERT.id) ||
  (Array.isArray(window.IMPORT_LINKED_CERT.items) && window.IMPORT_LINKED_CERT.items.length > 0)
)
  ? { ...window.IMPORT_LINKED_CERT, items: Array.isArray(window.IMPORT_LINKED_CERT.items) ? window.IMPORT_LINKED_CERT.items.slice() : [] }
  : null;

const importCertLinkedView = document.getElementById('importCertLinkedView');
const importCertEditView = document.getElementById('importCertEditView');
const importCertModalTitle = document.getElementById('importCertModalTitle');
const importCertModalSubtitle = document.getElementById('importCertModalSubtitle');
const importCertModalPanel = document.getElementById('importCertModalPanel');
const btnChangeLinkedCert = document.getElementById('btnChangeLinkedCert');
const btnViewLinkedCert = document.getElementById('btnViewLinkedCert');

function updateImportCertButtonLabel() {
  if (!btnImportCert) return;
  const n = Array.isArray(importLinkedCert?.items) ? importLinkedCert.items.length : 0;
  const has = !!(importLinkedCert?.id || n > 0);
  btnImportCert.textContent = has
    ? (n > 1 ? 'View Linked Certs' : 'View Linked Cert')
    : 'Import / Link Cert';
}

let importModalMode = 'edit';
let importModalAnimating = false;

function setImportModalMode(mode, opts = {}) {
  const linked = mode === 'linked';
  const animate = opts.animate !== false;
  const nextMode = linked ? 'linked' : 'edit';
  if (importModalAnimating) return;
  if (nextMode === importModalMode && opts.force !== true) {
    // Still refresh linked list if needed.
    if (linked && importLinkedCert) renderLinkedCertsList(importLinkedCert.items || []);
    return;
  }

  if (linked && importLinkedCert) {
    renderLinkedCertsList(importLinkedCert.items || []);
  }

  if (importCertModalTitle) {
    importCertModalTitle.textContent = linked ? 'Linked Certificate' : 'Import / Link Certificate';
  }
  if (importCertModalSubtitle) {
    const multi = !!(importLinkedCert?.multi || (importLinkedCert?.items?.length > 1));
    importCertModalSubtitle.textContent = linked
      ? (multi ? 'Each seminar has its own linked certificate.' : 'This template is ready for this event.')
      : 'Upload a registrar PPTX to scan codes and link a template.';
  }
  if (importCertModalPanel) {
    const multiLinked = linked && (importLinkedCert?.multi || (importLinkedCert?.items?.length > 1));
    importCertModalPanel.classList.remove('max-w-6xl', 'max-w-5xl', 'max-w-3xl', 'h-[min(88vh,720px)]');
    importCertModalPanel.classList.add(
      !linked ? 'max-w-6xl' : (multiLinked ? 'max-w-5xl' : 'max-w-3xl'),
      'h-[min(92vh,820px)]'
    );
  }

  const incoming = linked ? importCertLinkedView : importCertEditView;
  const outgoing = linked ? importCertEditView : importCertLinkedView;
  if (!incoming || !outgoing) {
    importModalMode = nextMode;
    return;
  }

  const applyVisible = (el, visible) => {
    el.classList.toggle('hidden', !visible);
    el.classList.toggle('flex', visible);
  };

  if (!animate) {
    applyVisible(outgoing, false);
    outgoing.style.opacity = '';
    outgoing.style.transform = '';
    applyVisible(incoming, true);
    incoming.style.opacity = '1';
    incoming.style.transform = 'translateX(0)';
    importModalMode = nextMode;
    return;
  }

  importModalAnimating = true;
  const outX = linked ? '-14px' : '14px';
  const inX = linked ? '14px' : '-14px';

  outgoing.style.transition = 'opacity 180ms ease, transform 180ms ease';
  outgoing.style.opacity = '0';
  outgoing.style.transform = `translateX(${outX})`;

  window.setTimeout(() => {
    applyVisible(outgoing, false);
    outgoing.style.transition = '';
    outgoing.style.opacity = '';
    outgoing.style.transform = '';

    applyVisible(incoming, true);
    incoming.style.transition = 'none';
    incoming.style.opacity = '0';
    incoming.style.transform = `translateX(${inX})`;

    requestAnimationFrame(() => {
      requestAnimationFrame(() => {
        incoming.style.transition = 'opacity 220ms ease, transform 220ms ease';
        incoming.style.opacity = '1';
        incoming.style.transform = 'translateX(0)';
        window.setTimeout(() => {
          incoming.style.transition = '';
          importModalAnimating = false;
          importModalMode = nextMode;
        }, 230);
      });
    });
  }, 180);
}

function escapeLinkedHtml(s) {
  return String(s || '').replace(/[&<>"']/g, (ch) => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
  }[ch] || ch));
}

function renderLinkedCertsList(items) {
  const list = document.getElementById('importLinkedList');
  const titleEl = document.getElementById('importLinkedTitle');
  const subtitleEl = document.getElementById('importLinkedSubtitle');
  if (!list) return;
  const rows = Array.isArray(items) ? items.filter((x) => x && (x.id || x.title || x.thumb)) : [];
  const multi = rows.length > 1 || !!(importLinkedCert?.multi);

  if (titleEl) {
    titleEl.textContent = multi
      ? `Linked seminar certificates (${rows.length})`
      : (rows[0]?.title || importLinkedCert?.title || 'Certificate');
  }
  if (subtitleEl) {
    subtitleEl.textContent = multi
      ? 'Each seminar uses its own certificate design and code pool.'
      : 'This template is ready for this event.';
  }

  list.className = multi ? 'grid grid-cols-1 md:grid-cols-2 gap-3' : 'space-y-3';

  if (rows.length === 0) {
    list.innerHTML = '<p class="text-sm text-zinc-500 font-semibold text-center py-8">No linked certificates yet.</p>';
    return;
  }

  const cardHtml = (item, tall) => {
    const thumb = String(item.thumb || '').trim();
    // Landscape certs need width-driven height so object-contain doesn't crop / invent scrollbars.
    const h = tall ? 'min-h-[220px] max-h-[min(52vh,420px)] h-auto aspect-[1123/794]' : 'min-h-[140px] max-h-[220px] h-auto aspect-[1123/794]';
    const img = thumb
      ? `<img src="${escapeLinkedHtml(thumb)}" alt="" class="w-full h-full object-contain" data-linked-preview-img>`
      : `<div class="text-sm text-zinc-400 font-semibold px-4 text-center py-12" data-linked-preview-empty>Loading linked design…</div>`;
    return (
      `<div class="w-full rounded-xl border border-zinc-200 bg-white overflow-hidden flex items-center justify-center ${h}" data-linked-preview-wrap="${escapeLinkedHtml(item.id || '')}">` +
      img +
      `</div>`
    );
  };

  if (!multi && rows.length === 1) {
    const item = rows[0];
    list.innerHTML =
      `<div class="rounded-2xl border border-zinc-200 bg-white shadow-sm overflow-hidden">` +
      `<div class="bg-zinc-50 p-3 sm:p-4">${cardHtml(item, true)}</div></div>`;
    // Always re-render from canvas_state — saved JPEG thumbs are often stale vs real positions.
    void refreshLinkedPreviewsFromCanvas(rows);
    return;
  }

  list.innerHTML = rows.map((item) => (
    `<div class="rounded-2xl border border-zinc-200 bg-white shadow-sm overflow-hidden flex flex-col min-h-0">` +
    `<div class="px-3 py-2.5 border-b border-zinc-100 bg-zinc-50 flex items-center justify-between gap-2 shrink-0">` +
    `<div class="min-w-0">` +
    `<div class="text-[10px] font-black uppercase tracking-widest text-zinc-500">${escapeLinkedHtml(item.label || 'Seminar')}</div>` +
    `<div class="text-sm font-bold text-zinc-900 truncate">${escapeLinkedHtml(item.session_title || '')}</div>` +
    `<div class="text-xs text-orange-600 font-semibold truncate mt-0.5">${escapeLinkedHtml(item.title || 'Certificate')}</div>` +
    `</div>` +
    `<span class="inline-flex items-center rounded-full border border-emerald-200 bg-emerald-50 px-2 py-0.5 text-[9px] font-black uppercase tracking-wider text-emerald-700 shrink-0">Linked</span>` +
    `</div>` +
    `<div class="bg-zinc-50 p-2.5 flex-1 min-h-0">${cardHtml(item, false)}</div></div>`
  )).join('');
  void refreshLinkedPreviewsFromCanvas(rows);
}

/**
 * Linked view must match canvas_state (same source as Import preview), not a stale JPEG thumb.
 * Shows existing thumb first (if any), then replaces with a fresh Fabric render and persists it.
 */
async function refreshLinkedPreviewsFromCanvas(items) {
  const rows = Array.isArray(items) ? items : (importLinkedCert?.items || []);
  for (const item of rows) {
    const id = String(item?.id || '').trim();
    if (!id) continue;
    const wrap = document.querySelector(`[data-linked-preview-wrap="${CSS.escape(id)}"]`);
    try {
      const sharp = await renderAndPersistLinkedPreview(id, {
        canvasState: null,
        persist: true,
      });
      if (!sharp) continue;
      item.thumb = sharp;
      if (importLinkedCert && String(importLinkedCert.id) === id) importLinkedCert.thumb = sharp;
      if (wrap) {
        wrap.innerHTML = `<img src="${escapeLinkedHtml(sharp)}" alt="" class="w-full h-full object-contain" data-linked-preview-img>`;
      }
      const legacy = document.getElementById('importLinkedPreviewImg');
      if (legacy && !importLinkedCert?.multi) {
        legacy.src = sharp;
        legacy.classList.remove('hidden');
        document.getElementById('importLinkedPreviewEmpty')?.classList.add('hidden');
      }
    } catch (_) {}
  }
}

/** @deprecated alias */
async function ensureLinkedPreviewThumb(items) {
  return refreshLinkedPreviewsFromCanvas(items);
}
async function refreshLinkedCertsSharpPreviews(items) {
  return refreshLinkedPreviewsFromCanvas(items);
}

/**
 * Render a linked template from canvas_state and optionally persist that exact frame as thumbnail.
 * @returns {Promise<string>} data URL or ''
 */
async function renderAndPersistLinkedPreview(templateId, opts = {}) {
  const id = String(templateId || '').trim();
  if (!id) return '';
  let state = opts.canvasState || null;
  if (!state) {
    try {
      const res = await fetch('/api/certificate_template_preview.php?template_id=' + encodeURIComponent(id) + '&_=' + Date.now(), { cache: 'no-store' });
      const data = await res.json().catch(() => null);
      if (data && data.ok) state = data.canvas_state || null;
    } catch (_) {
      state = null;
    }
  }
  if (!state) return '';
  let sharp = '';
  try {
    sharp = await Promise.race([
      renderCanvasStatePreview(state, 1.35),
      new Promise((resolve) => setTimeout(() => resolve(''), 10000)),
    ]);
  } catch (_) {
    sharp = '';
  }
  if (!sharp) return '';
  if (opts.persist !== false) {
    await persistTemplateCanvasAndThumb(id, state, sharp);
  }
  return sharp;
}

/** Persist the exact previewed canvas + matching thumbnail onto the linked template row. */
async function persistTemplateCanvasAndThumb(templateId, canvasState, dataUrl) {
  if (!templateId || !canvasState || typeof canvasState !== 'object') return;
  const thumb = (dataUrl && String(dataUrl).startsWith('data:image/')) ? dataUrl : undefined;
  try {
    await fetch('/api/certificate_template_update.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        template_id: templateId,
        canvas_state: canvasState,
        thumbnail_url: thumb,
        csrf_token: window.CSRF_TOKEN || '',
      }),
    });
  } catch (_) {}
}

function updateLinkedCertView(cert) {
  const items = Array.isArray(cert?.items) ? cert.items : (
    cert?.id ? [{
      session_id: '',
      label: 'Certificate',
      session_title: 'Event certificate',
      id: cert.id,
      title: cert.title || 'Certificate',
      thumb: cert.thumb || '',
    }] : []
  );
  importLinkedCert = items.length
    ? {
        multi: !!(cert?.multi || items.length > 1),
        id: items[0]?.id || cert?.id || '',
        title: items[0]?.title || cert?.title || 'Certificate',
        thumb: items[0]?.thumb || cert?.thumb || '',
        items,
      }
    : null;
  window.IMPORT_LINKED_CERT = importLinkedCert;
  renderLinkedCertsList(items);
  updateImportCertButtonLabel();
  updateImportLinkedBanner();
}

function updateImportLinkedBanner() {
  const banner = document.getElementById('importLinkedBanner');
  const label = document.getElementById('importLinkedBannerLabel');
  const viewBtn = document.getElementById('btnViewLinkedCert');
  const has = !!(importLinkedCert?.id || (Array.isArray(importLinkedCert?.items) && importLinkedCert.items.length > 0));
  if (banner) banner.classList.toggle('hidden', !has);
  if (!has) return;
  const multi = !!(importLinkedCert?.multi || (importLinkedCert?.items?.length > 1));
  if (label) {
    if (multi) {
      const n = importLinkedCert.items.length;
      label.innerHTML = `Linked: <span class="font-bold">${n} seminar certificate(s)</span>`;
    } else {
      const title = escapeLinkedHtml(importLinkedCert.title || importLinkedCert.items?.[0]?.title || 'Certificate');
      label.innerHTML = `Currently linked: <span class="font-bold">${title}</span>`;
    }
  }
  if (viewBtn) {
    viewBtn.textContent = multi ? 'View linked certs' : 'View linked cert';
  }
}

/** Paint the codes list UI without marking codes as pending Save & link inserts. */
function paintScannedCodesList(slotKey, codes, emptyMsg) {
  const list = document.querySelector(`.import-code-slot[data-slot-key="${CSS.escape(slotKey)}"] .import-slot-list`);
  if (!list) return [];
  const clean = collapseCodesToSeed((Array.isArray(codes) ? codes : [])
    .map((c) => (typeof c === 'string' ? c : String(c?.code || '')).trim())
    .filter(Boolean));
  if (clean.length === 0) {
    list.innerHTML = `<li class="import-slot-empty text-zinc-400 font-sans text-xs">${escapeLinkedHtml(emptyMsg || 'No codes scanned yet. Upload a PPTX to scan.')}</li>`;
    updateImportLinkedCodesSummary();
    return [];
  }
  list.innerHTML = clean.map((code) =>
    `<li class="flex items-center gap-2"><span class="inline-block w-2 h-2 rounded-full bg-emerald-500 shrink-0"></span><span>${String(code).replace(/[<>&]/g, '')}</span></li>`
  ).join('');
  updateImportLinkedCodesSummary();
  return clean;
}

/** Multi-seminar: show seed1/seed2 above the per-seminar lists. */
function updateImportLinkedCodesSummary() {
  const summary = document.getElementById('importLinkedCodesSummary');
  if (!summary) return;
  const slots = Array.from(document.querySelectorAll('.import-code-slot'));
  const seeds = slots.map((slot) => {
    if (slot.querySelector('.import-slot-empty, .import-slot-loading')) return '';
    const li = slot.querySelector('.import-slot-list li span:last-child');
    const text = (li?.textContent || '').trim();
    return text;
  }).filter(Boolean);
  summary.textContent = seeds.length > 1 ? seeds.join('/') : (seeds[0] || '');
}

function upsertImportSidebarTemplate(tpl) {
  if (!tpl || !tpl.id) return;
  const list = document.getElementById('importCertTemplateList');
  if (!list) return;
  const id = String(tpl.id);
  let btn = list.querySelector(`.import-cert-template[data-template-id="${CSS.escape(id)}"]`);
  const title = tpl.title || 'Certificate';
  const thumb = tpl.thumb || '';
  const scope = tpl.scope_label || 'Linked to event';
  if (btn) {
    btn.setAttribute('data-template-title', title);
    if (thumb) {
      btn.setAttribute('data-template-thumb', thumb);
      const img = btn.querySelector('img');
      if (img) img.src = thumb;
    }
    btn.setAttribute('data-template-scope', scope);
    const scopeEl = btn.querySelector('.mt-1.inline-flex, [data-scope-badge]') || btn.querySelector('div.mt-1');
    if (scopeEl && scopeEl.classList.contains('inline-flex')) scopeEl.textContent = scope;
    return;
  }
  const empty = list.querySelector('p.text-xs');
  if (empty) list.innerHTML = '';
  btn = document.createElement('button');
  btn.type = 'button';
  btn.className = 'import-cert-template group w-full text-left rounded-xl border border-zinc-200 bg-white p-2 hover:border-orange-300 transition focus:outline-none';
  btn.setAttribute('data-template-id', id);
  btn.setAttribute('data-template-title', title);
  btn.setAttribute('data-template-thumb', thumb);
  btn.setAttribute('data-template-scope', scope);
  btn.innerHTML =
    `<div class="aspect-[4/3] rounded-lg bg-zinc-100 overflow-hidden mb-2 border border-zinc-100">` +
    (thumb
      ? `<img src="${escapeLinkedHtml(thumb)}" alt="" class="w-full h-full object-contain bg-white">`
      : `<div class="w-full h-full flex items-center justify-center text-[10px] font-bold text-zinc-400 uppercase tracking-wider">No preview</div>`) +
    `</div>` +
    `<div class="text-[11px] font-bold text-zinc-800 truncate leading-tight">${escapeLinkedHtml(title)}</div>` +
    `<div class="mt-1 inline-flex rounded-full border border-emerald-200 bg-emerald-50 px-2 py-0.5 text-[9px] font-black uppercase tracking-wider text-emerald-700" data-scope-badge>${escapeLinkedHtml(scope)}</div>`;
  list.prepend(btn);
  btn.addEventListener('click', () => { void selectImportTemplate(btn); });
  const countEl = list.closest('aside')?.querySelector('span.rounded-full');
  if (countEl) {
    const n = list.querySelectorAll('.import-cert-template').length;
    countEl.textContent = String(n);
  }
  updateImportSlotLocks();
}

async function refreshLinkedCertPreview(templateId, titleFallback) {
  if (!templateId) return;
  const btn = document.querySelector(`.import-cert-template[data-template-id="${CSS.escape(templateId)}"]`);
  let title = titleFallback || btn?.getAttribute('data-template-title') || 'Certificate';
  let thumb = btn?.getAttribute('data-template-thumb') || '';
  try {
    const res = await fetch('/api/certificate_template_preview.php?template_id=' + encodeURIComponent(templateId) + '&_=' + Date.now(), { cache: 'no-store' });
    const data = await res.json();
    if (data.ok) {
      if (data.preview_data_url) thumb = data.preview_data_url;
      if (data.title) title = data.title;
    }
  } catch (_) {}
  updateLinkedCertView({ id: templateId, title, thumb, items: [{ id: templateId, title, thumb, label: 'Certificate', session_title: 'Event certificate' }] });
}

function buildLinkedItemsFromSlots() {
  const items = [];
  document.querySelectorAll('.import-cert-slot').forEach((slot) => {
    const key = slot.getAttribute('data-slot-key') || '';
    const sessionId = slot.getAttribute('data-session-id') || '';
    const templateId = importSlotTemplates[key] || '';
    if (!templateId) return;
    const btn = document.querySelector(`.import-cert-template[data-template-id="${CSS.escape(templateId)}"]`);
    const img = slot.querySelector('.import-slot-preview-img');
    const thumb = (img && !img.classList.contains('hidden') && img.getAttribute('src'))
      || btn?.getAttribute('data-template-thumb')
      || '';
    items.push({
      session_id: sessionId,
      label: slot.getAttribute('data-slot-label') || 'Seminar',
      session_title: slot.getAttribute('data-original-title') || '',
      id: templateId,
      title: btn?.getAttribute('data-template-title') || 'Certificate',
      thumb,
    });
  });
  return items;
}

function openImportCertModal() {
  if (!importCertModal) return;
  importCertModal.classList.remove('hidden');
  importCertModal.classList.add('flex');
  const hasLinked = !!(importLinkedCert?.id || (importLinkedCert?.items?.length > 0));
  // Instant mode on open — animate only when switching Change / View linked.
  setImportModalMode(hasLinked ? 'linked' : 'edit', { animate: false, force: true });
  const first = importCertModal.querySelector('.import-cert-slot');
  if (first) {
    importActiveSlotKey = first.getAttribute('data-slot-key') || '__event__';
    highlightActiveSlot(importActiveSlotKey);
  }
  if (importCertStatus && !hasLinked) {
    const multi = document.querySelectorAll('.import-cert-slot').length > 1;
    importCertStatus.textContent = multi
      ? 'Click a saved template — it goes to the first empty seminar automatically.'
      : 'Pick a saved template or upload a PPTX.';
    importCertStatus.className = 'text-xs text-zinc-600 font-semibold text-center min-h-[1rem]';
  }
  updateImportSlotLocks();
  // Rebuild sidebar thumbs from real canvas_state (stale JPEGs show wrong positions).
  void refreshSavedTemplateSidebarThumbs();
}
function closeImportCertModal() {
  if (!importCertModal) return;
  importCertModal.classList.add('hidden');
  importCertModal.classList.remove('flex');
}
btnImportCert?.addEventListener('click', openImportCertModal);
btnCloseImportCert?.addEventListener('click', closeImportCertModal);
importCertModal?.addEventListener('click', (e) => {
  if (e.target === importCertModal) closeImportCertModal();
});
btnChangeLinkedCert?.addEventListener('click', () => {
  setImportModalMode('edit', { animate: true });
  // Linked slots first; the sidebar thumb rebuild is heavy and used to delay them.
  void hydrateImportEditFromLinked().finally(() => {
    void refreshSavedTemplateSidebarThumbs();
  });
});
btnViewLinkedCert?.addEventListener('click', () => {
  if (!(importLinkedCert?.id || importLinkedCert?.items?.length)) return;
  setImportModalMode('linked', { animate: true });
});
updateImportCertButtonLabel();
updateImportLinkedBanner();

/** > 0 while a slot preview is loading; pauses background sidebar rendering. */
let importPreviewPriority = 0;

async function hydrateImportEditFromLinked() {
  const items = Array.isArray(importLinkedCert?.items) ? importLinkedCert.items : [];
  if (!items.length) return;
  const slots = Array.from(document.querySelectorAll('.import-cert-slot'));

  // Paint what we already know (name + saved thumb) before any request, so the
  // slots never sit on "No certificate selected" while previews are fetched.
  const jobs = [];
  for (const item of items) {
    const sessionId = String(item.session_id || '');
    const key = sessionId || '__event__';
    // Never fall back to slots[0] — that painted every seminar onto Seminar 1
    // and hid Seminar 2's preview/codes when session ids failed to match.
    const slot = slots.find((s) => (s.getAttribute('data-slot-key') || '') === key);
    const templateId = String(item.id || '');
    if (!slot || !templateId) continue;
    importSlotTemplates[key] = templateId;
    importSelectedTemplateId = templateId;
    importPendingFiles[key] = null;
    importScannedLayouts[key] = null;
    importLayoutBoundTemplateId[key] = '';
    importSlotPreviewStates[key] = null;
    importScannedCodes[key] = [];
    highlightImportTemplateById(templateId);

    const title = item.title || 'Certificate';
    setSlotPreview(slot, {
      title,
      templateName: title,
      scope: 'Loading linked design…',
      thumb: item.thumb || '',
    });
    setSlotLoading(slot, 'Loading linked design…');
    setScannedCodesLoading(key, 'Loading codes…');
    jobs.push({ key, slot, templateId, title });
  }
  if (!jobs.length) return;

  if (importCertStatus) {
    importCertStatus.textContent = 'Loading linked certificate…';
    importCertStatus.className = 'text-xs text-zinc-600 font-semibold text-center min-h-[1rem]';
  }

  importPreviewPriority += 1;
  try {
    // Fetch every seminar canvas at once — one-by-one awaits doubled the wait per seminar.
    const canvasStates = await Promise.all(jobs.map((job) => (
      fetch('/api/certificate_template_preview.php?template_id=' + encodeURIComponent(job.templateId) + '&_=' + Date.now(), { cache: 'no-store' })
        .then((r) => r.json())
        .then((res) => ((res && res.ok) ? (res.canvas_state || null) : null))
        .catch(() => null)
    )));

    for (let i = 0; i < jobs.length; i++) {
      const job = jobs[i];
      const canvasState = canvasStates[i];
      const fromTemplate = extractVisibleTemplateCodes(canvasState);
      paintScannedCodesList(
        job.key,
        fromTemplate,
        'No code on this template. Upload a PPTX to scan a seed.'
      );
      await showSyncedTemplatePreview(job.slot, job.templateId, job.title, canvasState, null, {
        sampleCode: fromTemplate[0] || '',
      });
    }
  } finally {
    importPreviewPriority -= 1;
    jobs.forEach((job) => setSlotLoading(job.slot, null));
  }

  const firstKey = slots[0]?.getAttribute('data-slot-key') || '__event__';
  importActiveSlotKey = firstKey;
  highlightActiveSlot(firstKey);
  updateImportSlotLocks();
  updateImportLinkedCodesSummary();
  if (importCertStatus) {
    importCertStatus.textContent = 'Linked design loaded. Tap ✕ on a seminar to change its template or upload a new PPTX.';
    importCertStatus.className = 'text-xs text-emerald-700 font-semibold text-center min-h-[1rem]';
  }
}

function highlightActiveSlot(slotKey) {
  document.querySelectorAll('.import-cert-slot').forEach((el) => {
    const active = (el.getAttribute('data-slot-key') || '') === slotKey;
    el.classList.toggle('ring-2', active);
    el.classList.toggle('ring-orange-400/40', active);
    el.classList.toggle('border-orange-300', active);
  });
  const activeTpl = importSlotTemplates[slotKey] || '';
  if (activeTpl) {
    importSelectedTemplateId = activeTpl;
    highlightImportTemplateById(activeTpl);
  } else {
    highlightImportTemplateById('');
  }
}

function setSlotPreview(slotEl, { title, scope, thumb, templateName }) {
  if (!slotEl) return;
  const isMulti = document.querySelectorAll('.import-cert-slot').length > 1;
  const titleEl = slotEl.querySelector('.import-slot-title');
  const scopeEl = slotEl.querySelector('.import-slot-scope');
  const tplLabel = slotEl.querySelector('.import-slot-template-label');
  const img = slotEl.querySelector('.import-slot-preview-img');
  const empty = slotEl.querySelector('.import-slot-preview-empty');
  const clearBtn = slotEl.querySelector('.import-slot-clear');

  // Multi-seminar: keep seminar name; show template under it.
  // Single event: show the template name as the preview title.
  if (isMulti && titleEl) {
    const original = slotEl.getAttribute('data-original-title') || '';
    if (original) titleEl.textContent = original;
  } else if (titleEl && (templateName || title)) {
    titleEl.textContent = templateName || title;
  }

  if (scopeEl) {
    scopeEl.textContent = scope || (isMulti ? '' : 'Saved template preview');
  }

  if (tplLabel) {
    const name = templateName || title || '';
    if (name && thumb) {
      tplLabel.textContent = 'Template: ' + name;
      tplLabel.classList.remove('hidden');
    } else if (name && scope && scope !== 'Loading preview…' && scope !== 'Rendering aligned preview…') {
      tplLabel.textContent = 'Template: ' + name;
      tplLabel.classList.remove('hidden');
    } else if (!thumb) {
      tplLabel.textContent = '';
      tplLabel.classList.add('hidden');
    }
  }

  if (img && empty) {
    if (thumb) {
      img.onerror = () => {
        img.onerror = null;
        img.src = '';
        img.classList.add('hidden');
        empty.classList.remove('hidden');
        if (clearBtn) clearBtn.classList.add('hidden');
      };
      img.src = thumb;
      img.alt = title || 'Certificate preview';
      img.classList.remove('hidden');
      empty.classList.add('hidden');
      if (clearBtn) clearBtn.classList.remove('hidden');
    } else {
      img.onerror = null;
      img.src = '';
      img.classList.add('hidden');
      empty.classList.remove('hidden');
      if (clearBtn) clearBtn.classList.add('hidden');
    }
  }

  updateImportSlotLocks();
}

/**
 * A filled seminar is locked: its Upload PPTX is off, and the saved-template list
 * only unlocks once at least one seminar is cleared with the ✕.
 */
function updateImportSlotLocks() {
  const slots = Array.from(document.querySelectorAll('.import-cert-slot'));
  let anyEmpty = false;

  slots.forEach((slot) => {
    const filled = importSlotIsFilled(slot);
    if (!filled) anyEmpty = true;

    // Filled seminar: the Upload PPTX button is removed entirely, not just dimmed.
    const upload = slot.querySelector('.import-slot-upload');
    const fileInput = slot.querySelector('.import-slot-file');
    if (fileInput) fileInput.disabled = filled;
    if (upload) upload.classList.toggle('hidden', filled);
    const clearBtn = slot.querySelector('.import-slot-clear');
    if (clearBtn) clearBtn.classList.toggle('hidden', !filled);
  });

  const locked = slots.length > 0 && !anyEmpty;
  document.querySelectorAll('#importCertTemplateList .import-cert-template').forEach((btn) => {
    btn.disabled = locked;
    btn.classList.toggle('import-locked-card', locked);
    btn.title = locked ? 'Tap ✕ on a seminar first to change its template' : '';
  });
  const hint = document.getElementById('importTemplateLockHint');
  if (hint) hint.classList.toggle('hidden', !locked);
}

/** Spinner over a seminar slot; pass null to hide it. */
function setSlotLoading(slotEl, message) {
  if (!slotEl) return;
  const overlay = slotEl.querySelector('.import-slot-loading-overlay');
  if (!overlay) return;
  if (message) {
    const text = overlay.querySelector('.import-slot-loading-text');
    if (text) text.textContent = message;
    overlay.classList.remove('hidden');
    overlay.classList.add('flex');
  } else {
    overlay.classList.add('hidden');
    overlay.classList.remove('flex');
  }
}

function clearImportSlot(slotEl) {
  if (!slotEl) return;
  const key = slotEl.getAttribute('data-slot-key') || '__event__';
  importPendingFiles[key] = null;
  importScannedLayouts[key] = null;
  importLayoutBoundTemplateId[key] = '';
  importSlotPreviewStates[key] = null;
  importScannedCodes[key] = [];
  delete importSlotTemplates[key];
  importSlotLoadGen[key] = (importSlotLoadGen[key] || 0) + 1;
  const fileInput = slotEl.querySelector('.import-slot-file');
  if (fileInput) fileInput.value = '';
  setSlotLoading(slotEl, null);
  const original = slotEl.getAttribute('data-original-title') || 'Certificate preview';
  setSlotPreview(slotEl, {
    title: original,
    scope: 'Upload a PPTX or pick a saved template',
    thumb: '',
    templateName: '',
  });
  const tplLabel = slotEl.querySelector('.import-slot-template-label');
  if (tplLabel) {
    tplLabel.textContent = '';
    tplLabel.classList.add('hidden');
  }
  renderScannedCodes(key, []);
  const anyTpl = Object.values(importSlotTemplates).some(Boolean);
  if (!anyTpl) {
    importSelectedTemplateId = '';
    highlightImportTemplateById('');
  } else if (importActiveSlotKey === key) {
    highlightImportTemplateById('');
  }
  if (importCertStatus) {
    importCertStatus.textContent = '';
    importCertStatus.className = 'text-xs text-zinc-500 font-semibold text-center min-h-[1rem]';
  }
}

function renderScannedCodes(slotKey, codes) {
  // One seed per seminar — never show …01 + …02 from expand/OCR runs.
  importScannedCodes[slotKey] = paintScannedCodesList(slotKey, codes);
}

/** Keep only the lowest seed when codes are a contiguous …01, …02 run. */
function collapseCodesToSeed(codes) {
  const list = (Array.isArray(codes) ? codes : []).map((c) => String(c || '').trim()).filter(Boolean);
  if (list.length <= 1) return list;
  const parsed = [];
  for (const code of list) {
    const m = code.match(/^(.*[-_\/.#:])(\d+)$/);
    if (!m) return list;
    parsed.push({ code, prefix: m[1], number: parseInt(m[2], 10), width: m[2].length });
  }
  const prefix = parsed[0].prefix;
  const width = parsed[0].width;
  if (parsed.some((p) => p.prefix !== prefix || p.width !== width)) return list;
  parsed.sort((a, b) => a.number - b.number);
  for (let i = 1; i < parsed.length; i++) {
    if (parsed[i].number !== parsed[0].number + i) return list;
  }
  return [parsed[0].code];
}

function setScannedCodesLoading(slotKey, message) {
  const list = document.querySelector(`.import-code-slot[data-slot-key="${CSS.escape(slotKey)}"] .import-slot-list`);
  if (!list) return;
  const label = String(message || 'Loading codes…').replace(/[<>&]/g, '');
  list.innerHTML =
    `<li class="import-slot-loading flex items-center gap-2 text-zinc-500 font-sans text-xs py-1">` +
    `<svg class="w-3.5 h-3.5 shrink-0 text-orange-500" viewBox="0 0 24 24" fill="none" aria-hidden="true" style="animation:importSpin .7s linear infinite">` +
    `<circle opacity=".25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3"></circle>` +
    `<path opacity=".95" fill="currentColor" d="M4 12a8 8 0 018-8v3a5 5 0 00-5 5H4z"></path>` +
    `</svg>` +
    `<span>${label}</span>` +
    `</li>`;
  updateImportLinkedCodesSummary();
  if (!document.getElementById('importSpinStyle')) {
    const style = document.createElement('style');
    style.id = 'importSpinStyle';
    style.textContent = '@keyframes importSpin{to{transform:rotate(360deg)}}';
    document.head.appendChild(style);
  }
}

function getActivePreviewSlot() {
  const key = importActiveSlotKey || '__event__';
  return document.querySelector(`.import-cert-slot[data-slot-key="${CSS.escape(key)}"]`)
    || document.querySelector('.import-cert-slot');
}

/** True when this seminar already has a template assigned. */
function importSlotIsFilled(slotEl) {
  if (!slotEl) return false;
  const key = slotEl.getAttribute('data-slot-key') || '';
  if (importSlotTemplates[key]) return true;
  const img = slotEl.querySelector('.import-slot-preview-img');
  return !!(img && !img.classList.contains('hidden') && img.getAttribute('src'));
}

/**
 * Auto-target: first empty seminar (1 → 2 → …).
 * If Seminar 1 is cleared but 2 is filled, next click goes back to Seminar 1.
 * If all filled, use the currently active seminar (replace).
 */
function getAutoTargetImportSlot() {
  const slots = Array.from(document.querySelectorAll('.import-cert-slot'));
  if (!slots.length) return null;
  const empty = slots.find((s) => !importSlotIsFilled(s));
  if (empty) return empty;
  return getActivePreviewSlot() || slots[0];
}

async function loadFabricLib() {
  if (window.fabric) return window.fabric;
  await new Promise((resolve, reject) => {
    const s = document.createElement('script');
    s.src = 'https://cdnjs.cloudflare.com/ajax/libs/fabric.js/5.3.1/fabric.min.js';
    s.async = true;
    s.onload = () => resolve();
    s.onerror = () => reject(new Error('Failed to load Fabric.js'));
    document.head.appendChild(s);
  });
  if (!window.fabric) throw new Error('Fabric.js unavailable');
  return window.fabric;
}

/**
 * Sharp full-canvas preview from Fabric canvas_state (not a stretched low-res thumb).
 */
async function renderCanvasStatePreview(canvasState, multiplier = 2) {
  if (!canvasState || typeof canvasState !== 'object') return '';
  const fabric = await loadFabricLib();
  const w = Math.max(100, Number(canvasState.width) || 1123);
  const h = Math.max(100, Number(canvasState.height) || 794);
  const el = document.createElement('canvas');
  el.width = w;
  el.height = h;
  el.style.position = 'fixed';
  el.style.left = '-99999px';
  el.style.top = '0';
  document.body.appendChild(el);
  const c = new fabric.StaticCanvas(el, {
    width: w,
    height: h,
    renderOnAddRemove: false,
    enableRetinaScaling: false,
  });
  try {
    await new Promise((resolve, reject) => {
      const timer = window.setTimeout(() => reject(new Error('Canvas preview timeout')), 10000);
      try {
        c.loadFromJSON(canvasState, async () => {
          try {
            c.setWidth(w);
            c.setHeight(h);
            const withTimeout = (p) => Promise.race([
              p,
              new Promise((done) => setTimeout(done, 2500)),
            ]);
            const pending = c.getObjects()
              .filter((o) => {
                const t = String(o?.type || '').toLowerCase();
                return t === 'image' && o._element && !o._element.complete;
              })
              .map((o) => withTimeout(new Promise((done) => {
                const elImg = o._element;
                elImg.onload = () => done();
                elImg.onerror = () => done();
              })));
            if (c.backgroundImage && c.backgroundImage._element && !c.backgroundImage._element.complete) {
              pending.push(withTimeout(new Promise((done) => {
                c.backgroundImage._element.onload = () => done();
                c.backgroundImage._element.onerror = () => done();
              })));
            }
            if (pending.length) await Promise.all(pending);
            await new Promise((r) => requestAnimationFrame(() => requestAnimationFrame(r)));
            c.requestRenderAll();
            window.clearTimeout(timer);
            resolve();
          } catch (e) {
            window.clearTimeout(timer);
            reject(e);
          }
        });
      } catch (e) {
        window.clearTimeout(timer);
        reject(e);
      }
    });
    await new Promise((r) => setTimeout(r, 120));
    c.requestRenderAll();
    return c.toDataURL({ format: 'jpeg', quality: 0.92, multiplier: Math.max(1.5, multiplier) });
  } finally {
    try { c.dispose(); } catch (_) {}
    try { el.remove(); } catch (_) {}
  }
}

async function persistTemplateThumbnail(templateId, dataUrl) {
  if (!templateId || !dataUrl || !dataUrl.startsWith('data:image/')) return;
  try {
    const prev = await fetch('/api/certificate_template_preview.php?template_id=' + encodeURIComponent(templateId) + '&_=' + Date.now(), { cache: 'no-store' });
    const prevData = await prev.json();
    const canvasState = prevData.canvas_state;
    if (!canvasState) return;
    await persistTemplateCanvasAndThumb(templateId, canvasState, dataUrl);
  } catch (_) {}
}

function updateSidebarTemplateThumb(templateId, dataUrl) {
  if (!templateId || !dataUrl) return;
  const btn = document.querySelector(`.import-cert-template[data-template-id="${CSS.escape(templateId)}"]`);
  if (!btn) return;
  btn.setAttribute('data-template-thumb', dataUrl);
  let img = btn.querySelector('img');
  if (!img) {
    const box = btn.querySelector('.aspect-\\[4\\/3\\], [class*="aspect-"]') || btn.firstElementChild;
    if (box) {
      box.innerHTML = `<img src="${escapeLinkedHtml(dataUrl)}" alt="" class="w-full h-full object-contain bg-white">`;
      img = box.querySelector('img');
    }
  }
  if (img) {
    img.src = dataUrl;
    img.classList.add('object-contain', 'bg-white');
    img.classList.remove('object-cover');
  }
}

/**
 * Step 1: Saved Templates sidebar thumbs must match each template's canvas_state
 * (stale thumbnail_url JPEGs often show wrong code/signature positions).
 */
async function refreshSavedTemplateSidebarThumbs() {
  const buttons = Array.from(document.querySelectorAll('#importCertTemplateList .import-cert-template'));
  for (const btn of buttons) {
    // Slot previews the teacher is waiting on get the network/CPU first.
    while (importPreviewPriority > 0) {
      await new Promise((r) => setTimeout(r, 120));
    }
    const id = String(btn.getAttribute('data-template-id') || '').trim();
    if (!id) continue;
    try {
      const res = await fetch('/api/certificate_template_preview.php?template_id=' + encodeURIComponent(id) + '&_=' + Date.now(), { cache: 'no-store' });
      const data = await res.json().catch(() => null);
      if (!data || !data.ok || !data.canvas_state) continue;
      const sharp = await Promise.race([
        renderCanvasStatePreview(data.canvas_state, 1.1),
        new Promise((resolve) => setTimeout(() => resolve(''), 8000)),
      ]);
      if (!sharp) continue;
      updateSidebarTemplateThumb(id, sharp);
      // DOM-only refresh here. Persisting every library card on open rewrote
      // templates whose seed is already linked and caused noisy 409s.
    } catch (_) {}
  }
}

async function showSyncedTemplatePreview(slotEl, templateId, titleFallback, canvasState, loadGen, opts = {}) {
  if (!slotEl || !templateId) return;
  const key = slotEl.getAttribute('data-slot-key') || '__event__';
  const title = titleFallback || 'Saved template';
  const stillCurrent = () => loadGen == null || importSlotLoadGen[key] === loadGen;
  // PPT layout bridges ONLY the bound template ↔ that upload — never other library templates.
  const boundId = String(importLayoutBoundTemplateId[key] || '').trim();
  const layoutApplies = !!boundId && boundId === String(templateId);
  const layout = layoutApplies
    ? (opts.layout || importScannedLayouts[key] || null)
    : null;
  // Server scan already applied layout in-memory — do not re-apply (causes header pile-up).
  const layoutAlreadyApplied = layoutApplies && !!opts.layoutAlreadyApplied;
  const sampleCode = layoutApplies ? String(opts.sampleCode || '').trim() : String(opts.sampleCode || '').trim();

  // Keep any thumb already on screen and cover it with a spinner — blanking to
  // "No certificate selected" made every load look like nothing was linked.
  const existingImg = slotEl.querySelector('.import-slot-preview-img');
  const shownThumb = (existingImg && !existingImg.classList.contains('hidden'))
    ? (existingImg.getAttribute('src') || '')
    : '';
  setSlotPreview(slotEl, {
    title,
    scope: layoutApplies ? 'Rendering aligned preview…' : 'Loading preview…',
    thumb: shownThumb,
    templateName: title,
  });
  setSlotLoading(slotEl, layoutApplies ? 'Rendering aligned preview…' : 'Loading preview…');
  let sharp = '';
  try {
    let stateForPreview = canvasState;
    if (stateForPreview) {
      const codesFromState = extractCodesForSeminarSlot(stateForPreview);
      // Never fall back to canvas glyph / cleared-import seeds when browsing the library.
      const codeToShow = layoutApplies
        ? (sampleCode
          || String((Array.isArray(importScannedCodes[key]) && importScannedCodes[key][0]) || '')
          || codesFromState[0]
          || '')
        : sampleCode;
      if (layoutApplies || codeToShow) {
        stateForPreview = stampImportPreviewCode(stateForPreview, codeToShow, layout, {
          skipLayoutApply: layoutAlreadyApplied || !layout,
          // Unbound templates: show their own design; do not invent SAMPLE-CODE / PPT geometry.
          allowInjectCode: layoutApplies,
        });
      }
      // Cache exact pixels the teacher sees — Save & link must write this onto the linked copy.
      try {
        importSlotPreviewStates[key] = JSON.parse(JSON.stringify(stateForPreview));
      } catch (_) {
        importSlotPreviewStates[key] = stateForPreview;
      }
      sharp = await renderCanvasStatePreview(stateForPreview, 2);
    }
  } catch (_) {
    sharp = '';
  }
  if (!stillCurrent()) return;
  if (!sharp) {
    try {
      const res = await fetch('/api/certificate_template_preview.php?template_id=' + encodeURIComponent(templateId) + '&_=' + Date.now(), { cache: 'no-store' });
      const data = await res.json();
      if (data.ok && data.canvas_state) {
        const codesFromState = extractCodesForSeminarSlot(data.canvas_state);
        const codeToShow = layoutApplies
          ? (sampleCode
            || String((Array.isArray(importScannedCodes[key]) && importScannedCodes[key][0]) || '')
            || codesFromState[0]
            || '')
          : sampleCode;
        let state = data.canvas_state;
        if (layoutApplies || codeToShow) {
          state = stampImportPreviewCode(state, codeToShow, layout, {
            skipLayoutApply: !layout,
            allowInjectCode: layoutApplies,
          });
        }
        try {
          importSlotPreviewStates[key] = JSON.parse(JSON.stringify(state));
        } catch (_) {
          importSlotPreviewStates[key] = state;
        }
        sharp = await renderCanvasStatePreview(state, 2);
      } else if (data.ok && data.preview_data_url) {
        sharp = data.preview_data_url;
      }
    } catch (_) {}
  }
  if (!stillCurrent()) return;
  setSlotLoading(slotEl, null);
  if (sharp) {
    setSlotPreview(slotEl, {
      title,
      scope: layoutApplies ? 'Code position shown on preview' : 'Saved template preview',
      thumb: sharp,
      templateName: title,
    });
    // Library card JPEG is often stale vs canvas — refresh + persist the clicked card
    // only when NOT applying a PPT bridge (PPT must not rewrite library cards).
    if (!layoutApplies) {
      updateSidebarTemplateThumb(templateId, sharp);
      void persistTemplateThumbnail(templateId, sharp);
    }
  } else {
    setSlotPreview(slotEl, {
      title,
      scope: 'Template updated — preview unavailable',
      thumb: '',
      templateName: title,
    });
  }
}

async function refreshTemplatePreview(slotEl, templateId, titleFallback) {
  if (!slotEl || !templateId) return;
  const btn = document.querySelector(`.import-cert-template[data-template-id="${CSS.escape(templateId)}"]`);
  const title = titleFallback || btn?.getAttribute('data-template-title') || 'Saved template';
  await showSyncedTemplatePreview(slotEl, templateId, title, null);
}

function highlightImportTemplateById(templateId) {
  document.querySelectorAll('.import-cert-template').forEach((el) => {
    const active = (el.getAttribute('data-template-id') || '') === templateId;
    el.classList.toggle('border-orange-400', active);
    el.classList.toggle('ring-2', active);
    el.classList.toggle('ring-orange-400/30', active);
  });
}

/** Sample registrar code stamped on the Certificate Code object (not {{placeholder}}). */
function extractSampleCodeFromCanvas(canvasState) {
  if (!canvasState || !Array.isArray(canvasState.objects)) return '';
  const codeRe = /\b[A-Z0-9]{1,8}(?:[-:.][A-Z0-9]{1,8}){2,6}\b/i;
  let hasCodeSlot = false;
  let codeSlotValue = '';
  let fallback = '';
  for (const obj of canvasState.objects) {
    if (!obj || typeof obj !== 'object') continue;
    const type = String(obj.type || '').toLowerCase();
    if (type !== 'i-text' && type !== 'text' && type !== 'textbox') continue;
    const text = String(obj.text || '').trim();
    const id = String(obj.id || '').toLowerCase();
    const name = String(obj.name || '').toLowerCase();
    const isCodeObj = id === 'certificate_code' || name === 'certificate code';
    if (isCodeObj) {
      hasCodeSlot = true;
      if (!text || text.includes('{{') || /^\{\{\s*certificate_code\s*\}\}$/i.test(text)) {
        continue; // placeholder / empty = no code on this template
      }
      const m = text.match(codeRe);
      if (m && m[0] && /\d/.test(m[0])) codeSlotValue = m[0];
      else if (/\d/.test(text)) codeSlotValue = text;
      continue;
    }
    if (!text || text.includes('{{')) continue;
    const m = text.match(codeRe);
    if (!m || !m[0] || !/\d/.test(m[0])) continue;
    if (!fallback && text.length < 64) fallback = m[0];
  }
  // If a Certificate Code object exists, trust it only (even when empty).
  if (hasCodeSlot) return codeSlotValue;
  return fallback;
}

/**
 * Map a PPT layout box into canvas pixel space (uniform scale — no stretch).
 */
function mapLayoutBoxToCanvas(layout, canvasW, canvasH, box) {
  if (!box || typeof box !== 'object') return null;
  const lw = Number(layout && layout.canvas_width) || 0;
  const lh = Number(layout && layout.canvas_height) || 0;
  let sx = 1;
  let sy = 1;
  let ox = 0;
  let oy = 0;
  if (lw > 10 && lh > 10 && canvasW > 10 && canvasH > 10) {
    const aspectL = lw / lh;
    const aspectC = canvasW / canvasH;
    if (Math.abs(aspectL - aspectC) / Math.max(aspectC, 0.01) > 0.02) {
      const uni = Math.min(canvasW / lw, canvasH / lh);
      sx = uni;
      sy = uni;
      ox = (canvasW - lw * uni) / 2;
      oy = (canvasH - lh * uni) / 2;
    } else {
      sx = Math.abs(lw - canvasW) / canvasW > 0.01 ? (canvasW / lw) : 1;
      sy = Math.abs(lh - canvasH) / canvasH > 0.01 ? (canvasH / lh) : 1;
      if (Math.abs(sx - sy) / Math.max(sx, sy, 0.01) > 0.01) {
        const uni = Math.min(sx, sy);
        sx = uni;
        sy = uni;
        ox = (canvasW - lw * uni) / 2;
        oy = (canvasH - lh * uni) / 2;
      }
    }
  }
  const fontScale = (sx + sy) / 2;
  return {
    left: Number(box.left || 0) * sx + ox,
    top: Number(box.top || 0) * sy + oy,
    width: Math.max(1, Number(box.width || 1) * sx),
    height: Math.max(1, Number(box.height || 1) * sy),
    fontSize: box.fontSize != null && Number(box.fontSize) >= 6
      ? Number(box.fontSize) * fontScale
      : null,
    textAlign: box.textAlign || null,
    fontWeight: box.fontWeight || null,
    fontFamily: box.fontFamily || null,
    text: box.text != null ? String(box.text) : '',
    id: box.id || '',
    kind: box.kind || '',
  };
}

function looksLikeSignatoryName(text) {
  let t = String(text || '').replace(/\s+/g, ' ').trim();
  if (!t || t.includes('{{') || t.length > 48) return false;
  if (/^authorized\s+signature$/i.test(t)) return false;
  const codeRe = /\b[A-Z0-9]{1,8}(?:[-:.][A-Z0-9]{1,8}){2,6}\b/i;
  if (codeRe.test(t) && t.length < 48) return false;
  if (/\b(of|the|a|an|for|is|to|and|with|during|organized|session|theme|given|certificate|appreciation|apppreciation|presented|proudly|attending|college|computing|studies|summit)\b/i.test(t)) {
    return false;
  }
  const words = t.split(/\s+/).filter(Boolean);
  if (words.length < 2 || words.length > 5) return false;
  // Title Case proper names only (Juan Dela Cruz) — rejects "of Apppreciation".
  return words.every((w) => /^\p{Lu}[\p{L}.\-']*$/u.test(w));
}

function safeSignatureLabel(text) {
  const raw = String(text || '').replace(/\r\n/g, '\n').replace(/\r/g, '\n').trim();
  if (!raw) return '';
  const lines = raw.split('\n').map((l) => l.trim()).filter(Boolean);
  if (!lines.length || !looksLikeSignatoryName(lines[0])) return '';
  return lines.join('\n');
}

function setFabricSignatureLabel(obj, labelText) {
  if (!obj || typeof obj !== 'object') return;
  const label = safeSignatureLabel(labelText);
  if (!label) return;
  const text = String(obj.text || '').replace(/\r\n/g, '\n').replace(/\r/g, '\n');
  const trimmed = text.trim();
  if (trimmed.toLowerCase() === label.toLowerCase()) return;
  if (/^authorized\s+signature$/i.test(trimmed) || !/^([_\u2014\u2013\-]{6,})/u.test(trimmed)) {
    obj.text = label;
    return;
  }
  const m = trimmed.match(/^([_\u2014\u2013\-]{6,})\s*\n+/u);
  obj.text = m ? (m[1] + '\n' + label) : label;
}

function findFabricSignatureIndex(objects) {
  if (!Array.isArray(objects)) return -1;
  let plainAuth = -1;
  for (let i = 0; i < objects.length; i++) {
    const obj = objects[i];
    if (!obj || typeof obj !== 'object') continue;
    const type = String(obj.type || '').toLowerCase();
    if (type !== 'i-text' && type !== 'text' && type !== 'textbox') continue;
    const name = String(obj.name || '').trim().toLowerCase();
    const text = String(obj.text || '').replace(/\r\n/g, '\n').replace(/\r/g, '\n').trim();
    if (name === 'signature' || name === 'signature label') return i;
    if (/^([_\u2014\u2013\-]{6,})\s*\n+.+$/u.test(text)) return i;
    if (/^authorized\s+signature$/i.test(text)) plainAuth = i;
  }
  return plainAuth;
}

/**
 * Apply full PPT layout onto a canvas_state clone (preview / client sync).
 * Mirrors PHP certificate_pptx_sync_apply_layout for text + code.
 */
function applyImportLayoutToCanvas(canvasState, layout) {
  if (!canvasState || typeof canvasState !== 'object' || !layout || !Array.isArray(layout.items)) {
    return canvasState;
  }
  let state;
  try {
    state = JSON.parse(JSON.stringify(canvasState));
  } catch (_) {
    return canvasState;
  }
  if (!Array.isArray(state.objects)) state.objects = [];

  const canvasW = Math.max(100, Number(state.width) || 1123);
  const canvasH = Math.max(100, Number(state.height) || 794);
  const lw = Number(layout.canvas_width) || 0;
  const lh = Number(layout.canvas_height) || 0;

  // Match PHP: if aspect differs a lot, adopt PPT slide size.
  if (lw > 10 && lh > 10) {
    const aspectL = lw / lh;
    const aspectC = canvasW / canvasH;
    if (Math.abs(aspectL - aspectC) / Math.max(aspectC, 0.01) > 0.02) {
      const fit = Math.min(lw / canvasW, lh / canvasH);
      const ox = (lw - canvasW * fit) / 2;
      const oy = (lh - canvasH * fit) / 2;
      state.objects.forEach((obj) => {
        if (!obj || typeof obj !== 'object') return;
        obj.left = Number(obj.left || 0) * fit + ox;
        obj.top = Number(obj.top || 0) * fit + oy;
        obj.scaleX = Number(obj.scaleX || 1) * fit;
        obj.scaleY = Number(obj.scaleY || 1) * fit;
        if (obj.fontSize != null) obj.fontSize = Math.max(6, Number(obj.fontSize) * fit);
        delete obj.aCoords;
        delete obj.oCoords;
      });
      state.width = lw;
      state.height = lh;
    }
  }

  const byId = {};
  const byText = {};
  state.objects.forEach((obj, i) => {
    if (!obj || typeof obj !== 'object') return;
    const id = String(obj.id || '').trim();
    if (id) byId[id] = i;
    const name = String(obj.name || '').trim().toLowerCase();
    if (id === 'certificate_code' || name === 'certificate code') byId.certificate_code = i;
    const t = String(obj.type || '').toLowerCase();
    if (t === 'i-text' || t === 'text' || t === 'textbox') {
      const key = String(obj.text || '').trim().toLowerCase();
      if (key) {
        if (!byText[key]) byText[key] = [];
        byText[key].push(i);
      }
    }
  });

  const cw = Math.max(100, Number(state.width) || 1123);
  const ch = Math.max(100, Number(state.height) || 794);
  const used = {};

  function objectCenter(obj) {
    const sx = Math.max(0.01, Number(obj.scaleX || 1));
    const sy = Math.max(0.01, Number(obj.scaleY || 1));
    const w = Math.max(1, Number(obj.width || 1) * sx);
    const h = Math.max(1, Number(obj.height || 1) * sy);
    let l = Number(obj.left || 0);
    let t = Number(obj.top || 0);
    const ox = String(obj.originX || 'left').toLowerCase();
    const oy = String(obj.originY || 'top').toLowerCase();
    if (ox === 'center') l -= w / 2;
    else if (ox === 'right') l -= w;
    if (oy === 'center') t -= h / 2;
    else if (oy === 'bottom') t -= h;
    return { cx: l + w / 2, cy: t + h / 2, w, h };
  }

  function matchByPosition(box, prefer) {
    const cx = box.left + box.width / 2;
    const cy = box.top + box.height / 2;
    const maxDist = Math.hypot(cw, ch) * 0.14;
    let best = null;
    let bestScore = Infinity;
    state.objects.forEach((obj, i) => {
      if (!obj || typeof obj !== 'object' || used[i]) return;
      const type = String(obj.type || '').toLowerCase();
      const isText = type === 'i-text' || type === 'text' || type === 'textbox';
      const isImage = type === 'image';
      if (prefer === 'text' && !isText) return;
      if (prefer === 'image' && !isImage) return;
      const oid = String(obj.id || '').trim();
      const oname = String(obj.name || '').trim().toLowerCase();
      if (oid === 'certificate_code' || oname === 'certificate code') return;
      const txt = String(obj.text || '').trim();
      if (isText && (/^([_\u2014\u2013\-]{6,})\s*\n+/u.test(txt) || /^authorized\s+signature$/i.test(txt))) return;
      const c = objectCenter(obj);
      const dist = Math.hypot(c.cx - cx, c.cy - cy);
      if (dist > maxDist) return;
      const sizeRatio = Math.max(box.width, c.w) / Math.max(1, Math.min(box.width, c.w));
      const sizeRatioH = Math.max(box.height, c.h) / Math.max(1, Math.min(box.height, c.h));
      if (sizeRatio > 4 || sizeRatioH > 4) return;
      const score = dist + (sizeRatio + sizeRatioH - 2) * 20;
      if (score < bestScore) {
        bestScore = score;
        best = i;
      }
    });
    return best;
  }

  layout.items.forEach((raw) => {
    if (!raw || typeof raw !== 'object') return;
    const kind = String(raw.kind || '');
    if (kind === 'background') return;
    const mapped = mapLayoutBoxToCanvas(layout, cw, ch, raw);
    if (!mapped) return;

    const itemId = String(raw.id || '').trim();
    const itemText = String(raw.text || '').trim();

    if (kind === 'certificate_code' || itemId === 'certificate_code') {
      let idx = byId.certificate_code;
      const codeText = itemText
        && !/CERTIFICATE-CODE/i.test(itemText)
        && !/^\{\{\s*certificate_code\s*\}\}$/i.test(itemText)
        ? itemText
        : '';
      if (idx == null) {
        state.objects.push({
          type: 'textbox',
          id: 'certificate_code',
          name: 'Certificate Code',
          text: codeText || '{{certificate_code}}',
          left: mapped.left,
          top: mapped.top,
          width: Math.max(40, mapped.width),
          height: Math.max(14, mapped.height),
          scaleX: 1,
          scaleY: 1,
          originX: 'left',
          originY: 'top',
          fontSize: mapped.fontSize != null ? mapped.fontSize : Math.max(10, mapped.height * 0.55),
          fontFamily: mapped.fontFamily || 'Arial',
          fontWeight: mapped.fontWeight || 'bold',
          fill: '#111827',
          textAlign: mapped.textAlign || 'left',
        });
        byId.certificate_code = state.objects.length - 1;
        used[state.objects.length - 1] = true;
      } else {
        const obj = state.objects[idx];
        obj.id = 'certificate_code';
        obj.name = 'Certificate Code';
        obj.originX = 'left';
        obj.originY = 'top';
        obj.left = mapped.left;
        obj.top = mapped.top;
        obj.width = Math.max(40, mapped.width);
        obj.height = Math.max(14, mapped.height);
        obj.scaleX = 1;
        obj.scaleY = 1;
        obj.angle = 0;
        if (mapped.fontSize != null) obj.fontSize = mapped.fontSize;
        if (mapped.textAlign) obj.textAlign = mapped.textAlign;
        if (mapped.fontWeight) obj.fontWeight = mapped.fontWeight;
        if (mapped.fontFamily) obj.fontFamily = mapped.fontFamily;
        if (codeText) obj.text = codeText;
        delete obj.aCoords;
        delete obj.oCoords;
        used[idx] = true;
      }
      return;
    }

    if (kind === 'signature') {
      const sigIdx = findFabricSignatureIndex(state.objects);
      if (sigIdx < 0) return;
      const obj = state.objects[sigIdx];
      const ox = String(obj.originX || 'left').toLowerCase();
      const oy = String(obj.originY || 'top').toLowerCase();
      const vw = Math.max(1, Number(obj.width || 1) * Number(obj.scaleX || 1));
      const vh = Math.max(1, Number(obj.height || 1) * Number(obj.scaleY || 1));
      let fl = mapped.left;
      let ft = mapped.top;
      if (ox === 'center') fl += vw / 2;
      else if (ox === 'right') fl += vw;
      if (oy === 'center') ft += vh / 2;
      else if (oy === 'bottom') ft += vh;
      if (mapped.top > ch * 0.45) {
        obj.left = fl;
        obj.top = ft;
      }
      const safe = safeSignatureLabel(itemText);
      if (safe) setFabricSignatureLabel(obj, safe);
      delete obj.aCoords;
      delete obj.oCoords;
      used[sigIdx] = true;
      return;
    }

    // 1:1 compare — id → unique text → person-name@bottom → spatial nearest
    let idx = (itemId && byId[itemId] != null && !used[byId[itemId]]) ? byId[itemId] : null;
    if (idx == null && itemText) {
      if (/^authorized\s+signature$/i.test(itemText)) return;
      const key = itemText.toLowerCase();
      if (byText[key] && byText[key].length === 1 && !used[byText[key][0]]) {
        idx = byText[key][0];
      }
    }

    // Bottom-region person name → signature BEFORE spatial (avoids snapping to Overall Chair).
    if (idx == null && itemText && mapped.top > ch * 0.55 && looksLikeSignatoryName(itemText)) {
      const sigIdx = findFabricSignatureIndex(state.objects);
      if (sigIdx >= 0) {
        setFabricSignatureLabel(state.objects[sigIdx], itemText);
        delete state.objects[sigIdx].aCoords;
        delete state.objects[sigIdx].oCoords;
        used[sigIdx] = true;
      }
      return;
    }

    if (idx == null) {
      let prefer = itemText || kind === 'text_fallback' ? 'text' : 'image';
      idx = matchByPosition(mapped, prefer);
      if (idx == null && prefer === 'image') idx = matchByPosition(mapped, 'any');
    }

    if (idx == null) return;

    const obj = state.objects[idx];
    const type = String(obj.type || '').toLowerCase();
    const isText = type === 'i-text' || type === 'text' || type === 'textbox';
    const isSig = isText && (
      /^([_\u2014\u2013\-]{6,})\s*\n+/u.test(String(obj.text || '').trim())
      || /^authorized\s+signature$/i.test(String(obj.text || '').trim())
    );
    if (isSig) {
      const ox = String(obj.originX || 'left').toLowerCase();
      const oy = String(obj.originY || 'top').toLowerCase();
      const vw = Math.max(1, Number(obj.width || 1) * Number(obj.scaleX || 1));
      const vh = Math.max(1, Number(obj.height || 1) * Number(obj.scaleY || 1));
      let fl = mapped.left;
      let ft = mapped.top;
      if (ox === 'center') fl += vw / 2;
      else if (ox === 'right') fl += vw;
      if (oy === 'center') ft += vh / 2;
      else if (oy === 'bottom') ft += vh;
      if (mapped.top > ch * 0.45) {
        obj.left = fl;
        obj.top = ft;
      }
      const safe = safeSignatureLabel(itemText);
      if (safe) setFabricSignatureLabel(obj, safe);
      delete obj.aCoords;
      delete obj.oCoords;
      used[idx] = true;
      return;
    }

    if (isText) {
      const ox = String(obj.originX || 'left').toLowerCase();
      const oy = String(obj.originY || 'top').toLowerCase();
      obj.width = Math.max(1, mapped.width);
      obj.height = Math.max(1, mapped.height);
      obj.scaleX = 1;
      obj.scaleY = 1;
      let fl = mapped.left;
      let ft = mapped.top;
      if (ox === 'center') fl += mapped.width / 2;
      else if (ox === 'right') fl += mapped.width;
      if (oy === 'center') ft += mapped.height / 2;
      else if (oy === 'bottom') ft += mapped.height;
      obj.left = fl;
      obj.top = ft;
      if (mapped.fontSize != null) obj.fontSize = mapped.fontSize;
      if (mapped.textAlign) obj.textAlign = mapped.textAlign;
      if (mapped.fontWeight) obj.fontWeight = mapped.fontWeight;
      if (mapped.fontFamily) obj.fontFamily = mapped.fontFamily;
      delete obj.aCoords;
      delete obj.oCoords;
      used[idx] = true;
    } else {
      const baseW = Math.max(1, Number(obj.width || mapped.width));
      const baseH = Math.max(1, Number(obj.height || mapped.height));
      const newSx = Math.max(0.05, mapped.width / baseW);
      const newSy = type === 'line' ? Number(obj.scaleY || 1) : Math.max(0.05, mapped.height / baseH);
      obj.scaleX = newSx;
      obj.scaleY = newSy;
      const vw = baseW * newSx;
      const vh = baseH * newSy;
      const ox = String(obj.originX || 'left').toLowerCase();
      const oy = String(obj.originY || 'top').toLowerCase();
      let fl = mapped.left;
      let ft = mapped.top;
      if (ox === 'center') fl += vw / 2;
      else if (ox === 'right') fl += vw;
      if (oy === 'center') ft += vh / 2;
      else if (oy === 'bottom') ft += vh;
      obj.left = fl;
      obj.top = ft;
      delete obj.aCoords;
      delete obj.oCoords;
      used[idx] = true;
    }
  });

  return state;
}

/**
 * Ensure import preview shows the registrar code at its real position
 * (so teachers can see where the code sits on the certificate).
 */
function stampImportPreviewCode(canvasState, sampleCode, layout, stampOpts = {}) {
  if (!canvasState || typeof canvasState !== 'object') return canvasState;
  const skipLayoutApply = !!(stampOpts && stampOpts.skipLayoutApply);
  const allowInjectCode = stampOpts && Object.prototype.hasOwnProperty.call(stampOpts, 'allowInjectCode')
    ? !!stampOpts.allowInjectCode
    : true;
  // First align ALL matched PPT elements — unless server already did (avoids double-apply pile-up).
  let state = skipLayoutApply ? canvasState : applyImportLayoutToCanvas(canvasState, layout);
  try {
    state = JSON.parse(JSON.stringify(state));
  } catch (_) {
    return canvasState;
  }
  if (!Array.isArray(state.objects)) state.objects = [];

  // When layout was skipped, still soft-bind signatory name from layout items (text only).
  if (skipLayoutApply && layout && Array.isArray(layout.items)) {
    const sigIdx = findFabricSignatureIndex(state.objects);
    if (sigIdx >= 0) {
      for (const raw of layout.items) {
        if (!raw || typeof raw !== 'object') continue;
        const kind = String(raw.kind || '');
        const text = String(raw.text || '').trim();
        if (kind === 'signature' && text) {
          setFabricSignatureLabel(state.objects[sigIdx], text);
          break;
        }
        if (looksLikeSignatoryName(text)) {
          setFabricSignatureLabel(state.objects[sigIdx], text);
          break;
        }
      }
    }
  }
  const codeRe = /\b[A-Z0-9]{1,8}(?:[-:.][A-Z0-9]{1,8}){2,6}\b/i;
  let code = String(sampleCode || '').trim();
  if (!code || /^\{\{\s*certificate_code\s*\}\}$/i.test(code) || /CERTIFICATE-CODE/i.test(code)) {
    code = extractSampleCodeFromCanvas(state);
  }
  if (!code) {
    if (!allowInjectCode) return state;
    code = 'SAMPLE-CODE-01';
  }
  const m = code.match(codeRe);
  if (m && m[0]) code = m[0];

  let layoutCode = null;
  if (layout && Array.isArray(layout.items)) {
    layoutCode = layout.items.find((it) => {
      if (!it || typeof it !== 'object') return false;
      const kind = String(it.kind || '').toLowerCase();
      const id = String(it.id || '').toLowerCase();
      return kind === 'certificate_code' || id === 'certificate_code';
    }) || null;
  }

  let idx = state.objects.findIndex((o) => {
    if (!o || typeof o !== 'object') return false;
    const id = String(o.id || '').toLowerCase();
    const name = String(o.name || '').toLowerCase();
    return id === 'certificate_code' || name === 'certificate code';
  });

  if (idx < 0) {
    idx = state.objects.findIndex((o) => {
      if (!o || typeof o !== 'object') return false;
      const t = String(o.type || '').toLowerCase();
      if (t !== 'i-text' && t !== 'text' && t !== 'textbox') return false;
      const text = String(o.text || '').trim();
      return /CERTIFICATE-CODE-HERE/i.test(text)
        || /^\{\{\s*certificate_code\s*\}\}$/i.test(text)
        || (codeRe.test(text) && /\d/.test(text) && text.length < 48);
    });
  }

  const canvasW = Math.max(100, Number(state.width) || 1123);
  const canvasH = Math.max(100, Number(state.height) || 794);
  const mappedCode = layoutCode ? mapLayoutBoxToCanvas(layout, canvasW, canvasH, layoutCode) : null;

  if (idx < 0) {
    if (!allowInjectCode) return state;
    const left = mappedCode ? mappedCode.left : canvasW * 0.05;
    const top = mappedCode ? mappedCode.top : canvasH * 0.88;
    const width = mappedCode ? Math.max(60, mappedCode.width) : Math.min(320, canvasW * 0.35);
    const height = mappedCode ? Math.max(16, mappedCode.height) : 22;
    const fontSize = mappedCode && mappedCode.fontSize != null
      ? Math.max(8, Math.min(72, mappedCode.fontSize))
      : Math.max(10, Math.min(28, height * 0.55));
    state.objects.push({
      type: 'textbox',
      id: 'certificate_code',
      name: 'Certificate Code',
      text: code,
      left,
      top,
      width,
      height,
      scaleX: 1,
      scaleY: 1,
      originX: 'left',
      originY: 'top',
      fontSize,
      fontFamily: (mappedCode && mappedCode.fontFamily) || 'Arial',
      fontWeight: (mappedCode && mappedCode.fontWeight) || 'bold',
      fill: '#111827',
      textAlign: (mappedCode && mappedCode.textAlign) || 'left',
      selectable: false,
      evented: false,
    });
  } else {
    const obj = state.objects[idx];
    obj.id = 'certificate_code';
    obj.name = 'Certificate Code';
    obj.text = code;
    obj.fontWeight = obj.fontWeight || 'bold';
    if (!obj.fill || String(obj.fill).toLowerCase() === 'transparent') {
      obj.fill = '#111827';
    }
    // Geometry already applied by applyImportLayoutToCanvas when layout had a code item.
    // Only re-pin if we have a mapped box (keeps scale-correct coords).
    if (mappedCode) {
      obj.originX = 'left';
      obj.originY = 'top';
      obj.left = mappedCode.left;
      obj.top = mappedCode.top;
      obj.width = Math.max(40, mappedCode.width);
      obj.height = Math.max(14, mappedCode.height);
      obj.scaleX = 1;
      obj.scaleY = 1;
      obj.angle = 0;
      if (mappedCode.fontSize != null) {
        obj.fontSize = Math.max(8, Math.min(72, mappedCode.fontSize));
      }
      if (mappedCode.textAlign) obj.textAlign = mappedCode.textAlign;
      if (mappedCode.fontWeight) obj.fontWeight = mappedCode.fontWeight;
      if (mappedCode.fontFamily) obj.fontFamily = mappedCode.fontFamily;
      delete obj.aCoords;
      delete obj.oCoords;
    } else if (!obj.fontSize || Number(obj.fontSize) < 10) {
      obj.fontSize = 14;
    }
    state.objects[idx] = obj;
  }
  return state;
}

/** Codes typed in the editor Text panel (pending_registrar_codes on canvas_state). */
function extractPendingRegistrarCodes(canvasState) {
  if (!canvasState || typeof canvasState !== 'object') return [];
  const raw = canvasState.pending_registrar_codes;
  if (!Array.isArray(raw)) return [];
  const out = [];
  const seen = new Set();
  for (const item of raw) {
    const c = String(item || '').trim();
    const key = c.toUpperCase().replace(/[^A-Z0-9]/g, '');
    if (!c || /^\{\{\s*certificate_code\s*\}\}$/i.test(c) || !key || seen.has(key)) continue;
    seen.add(key);
    out.push(c);
  }
  return out;
}

/**
 * Code visible on the template design only (certificate_code / registrar glyph).
 * Does NOT use pending_registrar_codes — that can linger after the glyph was removed.
 */
function extractVisibleTemplateCodes(canvasState) {
  const sample = extractSampleCodeFromCanvas(canvasState);
  return sample ? [sample] : [];
}

/**
 * One seminar ↔ one template ↔ one sample code (the Certificate Code on the design).
 * Do not dump the full pending list — that stacks codes when swapping templates.
 */
function extractCodesForSeminarSlot(canvasState) {
  const sample = extractSampleCodeFromCanvas(canvasState);
  if (sample) return [sample];
  const pending = extractPendingRegistrarCodes(canvasState);
  return pending.length ? [pending[0]] : [];
}

async function fetchEventPoolCodes(sessionId) {
  const codes = [];
  const seen = new Set();
  try {
    let url = '/api/event_certificate_codes.php?event_id=' + encodeURIComponent(<?= json_encode($id) ?>);
    if (sessionId) url += '&session_id=' + encodeURIComponent(sessionId);
    const res = await fetch(url);
    const data = await res.json();
    if (data.ok && Array.isArray(data.codes)) {
      for (const row of data.codes) {
        const c = String(row?.code || '').trim();
        const key = c.toUpperCase();
        if (!c || seen.has(key)) continue;
        seen.add(key);
        codes.push(c);
      }
    }
  } catch (_) {}
  return codes;
}

async function selectImportTemplate(btn) {
  if (!btn) {
    importSelectedTemplateId = '';
    highlightImportTemplateById('');
    return;
  }
  // Auto-fill: empty Seminar 1 first, then Seminar 2, etc. No manual select needed.
  const slot = getAutoTargetImportSlot();
  if (!slot) return;
  const title = btn.getAttribute('data-template-title') || 'Saved template';
  const templateId = btn.getAttribute('data-template-id') || '';
  const key = slot.getAttribute('data-slot-key') || '__event__';
  const slotLabel = slot.getAttribute('data-slot-label') || slot.querySelector('.import-slot-title')?.textContent || 'seminar';

  importActiveSlotKey = key;
  importSlotTemplates[key] = templateId;
  importSelectedTemplateId = templateId;
  highlightActiveSlot(key);
  highlightImportTemplateById(templateId);

  const loadGen = (importSlotLoadGen[key] || 0) + 1;
  importSlotLoadGen[key] = loadGen;

  const hasActivePptEarly = !!(importScannedLayouts[key] || importPendingFiles[key]);
  // Clear previous template's codes immediately so they don't stick while loading.
  if (!hasActivePptEarly) {
    importScannedCodes[key] = [];
    paintScannedCodesList(key, [], 'Loading template…');
  } else {
    setScannedCodesLoading(key, 'Loading codes…');
  }
  if (importCertStatus) {
    importCertStatus.textContent = `Applying to ${slotLabel}…`;
    importCertStatus.className = 'text-xs text-zinc-600 font-semibold text-center min-h-[1rem]';
  }
  setSlotPreview(slot, {
    title,
    scope: 'Loading preview…',
    thumb: '',
    templateName: title,
  });

  let canvasState = null;

  try {
    const previewRes = await fetch('/api/certificate_template_preview.php?template_id=' + encodeURIComponent(templateId) + '&_=' + Date.now(), { cache: 'no-store' })
      .then((r) => r.json())
      .catch(() => null);
    if (importSlotLoadGen[key] !== loadGen) return;
    if (previewRes && previewRes.ok) {
      canvasState = previewRes.canvas_state || null;
    }
  } catch (_) {}

  if (importSlotLoadGen[key] !== loadGen) return;

  // First template pick after an unmatched PPT scan → bind layout to THIS template only.
  if (importScannedLayouts[key] && !String(importLayoutBoundTemplateId[key] || '').trim()) {
    importLayoutBoundTemplateId[key] = templateId;
  }

  // SCANNED CODES:
  // - Active PPT upload → show PPT seed (pending Save)
  // - Otherwise → visible code on THIS template only (clear if none)
  const boundId = String(importLayoutBoundTemplateId[key] || '').trim();
  const layoutApplies = !!boundId && boundId === String(templateId);
  const pendingPptCodes = Array.isArray(importScannedCodes[key]) ? importScannedCodes[key] : [];
  const hasActivePpt = !!(importScannedLayouts[key] || importPendingFiles[key]);
  let shownSeed = '';
  if (hasActivePpt && pendingPptCodes.length) {
    renderScannedCodes(key, pendingPptCodes);
    shownSeed = importScannedCodes[key]?.[0] || '';
  } else if (hasActivePpt) {
    renderScannedCodes(key, []);
  } else {
    importScannedCodes[key] = [];
    // Visible design only — ignore stale pending_registrar_codes.
    const fromTemplate = extractVisibleTemplateCodes(canvasState);
    paintScannedCodesList(
      key,
      fromTemplate,
      'No code on this template. Upload a PPTX to scan a seed.'
    );
    shownSeed = fromTemplate[0] || '';
  }

  const nextEmpty = Array.from(document.querySelectorAll('.import-cert-slot')).find((s) => !importSlotIsFilled(s));
  const nextLabel = nextEmpty?.getAttribute('data-slot-label') || '';
  if (importCertStatus) {
    if (layoutApplies && shownSeed) {
      importCertStatus.textContent = nextLabel
        ? `PPT bridged to “${title}” (${shownSeed}). Next → ${nextLabel}.`
        : `PPT bridged to “${title}”: ${shownSeed}. Other templates stay unchanged until Save & link.`;
      importCertStatus.className = 'text-xs text-emerald-700 font-semibold text-center min-h-[1rem]';
    } else if (shownSeed && hasActivePpt) {
      importCertStatus.textContent = nextLabel
        ? `Scanned ${shownSeed} for ${slotLabel}. Next → ${nextLabel}.`
        : `Scanned ${shownSeed}. Click Save & link to apply.`;
      importCertStatus.className = 'text-xs text-emerald-700 font-semibold text-center min-h-[1rem]';
    } else if (shownSeed) {
      importCertStatus.textContent = nextLabel
        ? `“${title}” has ${shownSeed}. Next → ${nextLabel}.`
        : `“${title}” code: ${shownSeed}. Upload a PPTX to replace the seed, then Save & link.`;
      importCertStatus.className = 'text-xs text-emerald-700 font-semibold text-center min-h-[1rem]';
    } else {
      importCertStatus.textContent = nextLabel
        ? `Template set on ${slotLabel}. Next click → ${nextLabel}.`
        : `Template set on ${slotLabel}. Upload a PPTX to scan a seed code.`;
      importCertStatus.className = 'text-xs text-amber-700 font-semibold text-center min-h-[1rem]';
    }
  }

  await showSyncedTemplatePreview(slot, templateId, title, canvasState, loadGen, {
    // Only stamp import seed when PPT is bridged to this template.
    sampleCode: layoutApplies ? shownSeed : '',
  });
}

document.querySelectorAll('.import-cert-template').forEach((btn) => {
  btn.addEventListener('click', () => { void selectImportTemplate(btn); });
});

document.querySelectorAll('.import-cert-slot').forEach((slot) => {
  slot.addEventListener('click', (e) => {
    if (e.target.closest('label') || e.target.closest('input') || e.target.closest('.import-slot-clear')) return;
    importActiveSlotKey = slot.getAttribute('data-slot-key') || '__event__';
    highlightActiveSlot(importActiveSlotKey);
    const label = slot.getAttribute('data-slot-label') || 'seminar';
    const title = slot.getAttribute('data-original-title') || '';
    if (importCertStatus) {
      importCertStatus.textContent = importSlotIsFilled(slot)
        ? `Selected ${label}${title ? ' — ' + title : ''}. Tap ✕ to change its template or upload a PPTX.`
        : `Selected ${label}${title ? ' — ' + title : ''}. Now click a saved template.`;
      importCertStatus.className = 'text-xs text-orange-700 font-semibold text-center min-h-[1rem]';
    }
  });
  slot.querySelector('.import-slot-clear')?.addEventListener('click', (e) => {
    e.preventDefault();
    e.stopPropagation();
    clearImportSlot(slot);
  });
  const fileInput = slot.querySelector('.import-slot-file');
  fileInput?.addEventListener('change', async () => {
    const file = fileInput.files && fileInput.files[0];
    if (!file) return;
    const key = slot.getAttribute('data-slot-key') || '__event__';
    importActiveSlotKey = key;
    highlightActiveSlot(key);

    importPendingFiles[key] = file;
    importScannedLayouts[key] = null;
    importLayoutBoundTemplateId[key] = '';

    setScannedCodesLoading(key, 'Scanning codes…');
    if (importCertStatus) {
      importCertStatus.textContent = 'Scanning PPTX…';
      importCertStatus.className = 'text-xs text-zinc-600 font-semibold text-center min-h-[1rem]';
    }
    setSlotPreview(slot, {
      title: file.name,
      scope: 'Scanning…',
      thumb: '',
    });

    const fd = new FormData();
    fd.append('event_id', <?= json_encode($id) ?>);
    const sessionId = slot.getAttribute('data-session-id') || '';
    if (sessionId) fd.append('session_id', sessionId);
    fd.append('csrf_token', window.CSRF_TOKEN || '');
    fd.append('file', file);
    try {
      const res = await fetch('/api/event_certificate_scan.php', { method: 'POST', body: fd });
      const data = await res.json();
      if (!data.ok) throw new Error(data.error || 'Scan failed');

      // Preview only — keep layout + codes until Save & link persists them.
      // Bind PPT geometry to the matched template only (never bleed into other library templates).
      importScannedLayouts[key] = data.layout || null;
      importLayoutBoundTemplateId[key] = data.template_id ? String(data.template_id) : '';
      renderScannedCodes(key, data.codes || []);

      const firstCode = Array.isArray(data.codes) && data.codes[0]
        ? String(data.codes[0].code || data.codes[0] || '')
        : '';

      if (data.template_id) {
        importSelectedTemplateId = String(data.template_id);
        importSlotTemplates[key] = importSelectedTemplateId;
        importLayoutBoundTemplateId[key] = importSelectedTemplateId;
        highlightImportTemplateById(importSelectedTemplateId);
        const title = data.template_title || 'Matched template';
        await showSyncedTemplatePreview(slot, importSelectedTemplateId, title, data.canvas_state || null, null, {
          sampleCode: firstCode,
          layout: data.layout || null,
          // Scan API already ran certificate_pptx_sync_apply_layout on this canvas_state.
          layoutAlreadyApplied: !!(data.layout_applied_preview || (data.canvas_state && data.layout_synced)),
        });
      } else if (importSlotTemplates[key] || importSelectedTemplateId) {
        const tid = importSlotTemplates[key] || importSelectedTemplateId;
        importSlotTemplates[key] = tid;
        importLayoutBoundTemplateId[key] = tid;
        await showSyncedTemplatePreview(slot, tid, data.filename || file.name, null, null, {
          sampleCode: firstCode,
          layout: data.layout || null,
          layoutAlreadyApplied: false,
        });
      } else {
        setSlotPreview(slot, {
          title: data.filename || file.name,
          scope: 'No matching template — pick one on the left',
          thumb: '',
        });
      }

      if (importCertStatus) {
        const n = Number(data.scanned || 0);
        if (!data.template_id) {
          importCertStatus.textContent = 'No matching template. Select one, then Save & link to add codes.';
          importCertStatus.className = 'text-xs text-amber-700 font-semibold text-center min-h-[1rem]';
        } else if (n > 0) {
          importCertStatus.textContent = `Matched “${data.template_title || 'template'}” · seed ${data.codes?.[0]?.code || 'code'} ready. Save & link — codes auto-count (…-01, …-02, …) per eval submit.`;
          importCertStatus.className = 'text-xs text-emerald-700 font-semibold text-center min-h-[1rem]';
        } else {
          importCertStatus.textContent = `Matched “${data.template_title || 'template'}”. Click Save & link.`;
          importCertStatus.className = 'text-xs text-emerald-700 font-semibold text-center min-h-[1rem]';
        }
      }
    } catch (err) {
      renderScannedCodes(key, []);
      importScannedLayouts[key] = null;
      importLayoutBoundTemplateId[key] = '';
      // Drop the rejected PPTX so Save & link can't resubmit a duplicate code.
      importPendingFiles[key] = null;
      e.target.value = '';
      if (importCertStatus) {
        importCertStatus.textContent = err.message || 'Scan failed';
        importCertStatus.className = 'text-xs text-red-600 font-semibold text-center min-h-[1rem]';
      }
    }
  });
});

async function postImportPayload({ sessionId, file, codesText, templateId, layout }) {
  // Prefer JSON: scanned codes + layout (no second PPTX parse when possible).
  if (!file || (codesText && layout)) {
    return fetch('/api/event_certificate_import.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        event_id: <?= json_encode($id) ?>,
        session_id: sessionId || undefined,
        template_id: templateId || undefined,
        codes_text: codesText || undefined,
        layout: layout || undefined,
        csrf_token: window.CSRF_TOKEN || '',
      }),
    });
  }
  const fd = new FormData();
  fd.append('event_id', <?= json_encode($id) ?>);
  if (sessionId) fd.append('session_id', sessionId);
  fd.append('csrf_token', window.CSRF_TOKEN || '');
  fd.append('file', file);
  if (templateId) fd.append('template_id', templateId);
  if (layout) fd.append('layout_json', JSON.stringify(layout));
  return fetch('/api/event_certificate_import.php', { method: 'POST', body: fd });
}

async function submitImportCert() {
  const statusEl = importCertStatus;
  const codeSlots = Array.from(document.querySelectorAll('.import-code-slot'));
  const jobs = [];

  for (const codeSlot of codeSlots) {
    const key = codeSlot.getAttribute('data-slot-key') || '__event__';
    const sessionId = codeSlot.getAttribute('data-session-id') || '';
    const codes = importScannedCodes[key] || [];
    const file = importPendingFiles[key] || null;
    const templateId = importSlotTemplates[key] || '';
    const boundId = String(importLayoutBoundTemplateId[key] || '').trim();
    // Only sync PPT layout onto the template it was bridged to — never a different library pick.
    const layout = (boundId && boundId === String(templateId))
      ? (importScannedLayouts[key] || null)
      : null;
    if (!file && codes.length === 0 && !templateId) continue;
    jobs.push({
      key,
      sessionId,
      codesText: codes.join('\n'),
      file,
      layout,
      templateId,
      listEl: codeSlot.querySelector('.import-slot-list'),
    });
  }

  if (jobs.length === 0) {
    if (statusEl) {
      statusEl.textContent = 'Select a seminar, pick a template, then Save & link.';
      statusEl.className = 'text-xs text-red-600 font-semibold text-center min-h-[1rem]';
    }
    return;
  }

  if (!jobs.some((j) => j.templateId)) {
    if (statusEl) {
      statusEl.textContent = 'Select a saved template for each seminar, then Save & link.';
      statusEl.className = 'text-xs text-red-600 font-semibold text-center min-h-[1rem]';
    }
    return;
  }

  if (statusEl) {
    statusEl.textContent = 'Saving & linking…';
    statusEl.className = 'text-xs text-zinc-500 font-semibold text-center min-h-[1rem]';
  }

  let totalInserted = 0;
  let linked = false;
  const linkedFromApi = [];
  const errors = [];

  for (let i = 0; i < jobs.length; i++) {
    const job = jobs[i];
    try {
      const res = await postImportPayload({
        sessionId: job.sessionId,
        file: job.layout ? null : job.file,
        codesText: job.codesText,
        templateId: job.templateId || '',
        layout: job.layout || null,
      });
      const data = await res.json();
      if (!data.ok) throw new Error(data.error || 'Save failed');
      totalInserted += Number(data.inserted || 0);
      linked = linked || !!data.linked_template;
      const linkedId = String(
        (data.session_template && data.session_template.id)
          || data.template_id
          || job.templateId
          || ''
      );
      const linkedTitle = String(
        (data.session_template && data.session_template.title)
          || ''
      );
      const slot = document.querySelector(`.import-cert-slot[data-slot-key="${CSS.escape(job.key)}"]`);
      const btn = document.querySelector(`.import-cert-template[data-template-id="${CSS.escape(job.templateId)}"]`);
      const title = linkedTitle
        || btn?.getAttribute('data-template-title')
        || 'Certificate';
      let thumb = '';
      if (linkedId) {
        // WYSIWYG: write the exact canvas the teacher previewed onto the linked copy.
        // (PHP PPT sync / stale JPEG thumbs previously drifted from Import preview positions.)
        const previewState = importSlotPreviewStates[job.key] || null;
        if (previewState && typeof previewState === 'object') {
          try {
            thumb = await renderCanvasStatePreview(previewState, 1.35);
          } catch (_) {
            thumb = '';
          }
          await persistTemplateCanvasAndThumb(linkedId, previewState, thumb || undefined);
        }
        if (!thumb) {
          thumb = await renderAndPersistLinkedPreview(linkedId, { persist: true });
        }
        if (!thumb) {
          const img = slot?.querySelector('.import-slot-preview-img');
          thumb = (img && !img.classList.contains('hidden') && img.getAttribute('src'))
            || (data.session_template && data.session_template.thumbnail_url)
            || '';
        }
        importSlotTemplates[job.key] = linkedId;
        linkedFromApi.push({
          session_id: (data.session_template && data.session_template.session_id) || job.sessionId || '',
          label: slot?.getAttribute('data-slot-label') || 'Seminar',
          session_title: (data.session_template && data.session_template.session_title)
            || slot?.getAttribute('data-original-title')
            || '',
          id: linkedId,
          title,
          thumb,
        });
        const sourceId = String(data.source_template_id || job.templateId || '');
        if (sourceId) {
          highlightImportTemplateById(sourceId);
          importSelectedTemplateId = sourceId;
        }
      }
      if (Array.isArray(data.codes) && data.codes.length) {
        renderScannedCodes(job.key, data.codes);
      } else if (job.codesText) {
        // Keep the seed that was just saved visible even if API returns empty (dedupe).
        renderScannedCodes(job.key, job.codesText.split(/\n+/));
      }
      importPendingFiles[job.key] = null;
      importScannedLayouts[job.key] = null;
      importLayoutBoundTemplateId[job.key] = '';
      importSlotPreviewStates[job.key] = null;
      // After successful save, codes are in the pool — don't re-submit on next Save.
      importScannedCodes[job.key] = [];
      const previewSlot = document.querySelector(`.import-cert-slot[data-slot-key="${CSS.escape(job.key)}"]`);
      const fileInput = previewSlot?.querySelector('.import-slot-file');
      if (fileInput) fileInput.value = '';
    } catch (err) {
      errors.push(err.message || 'Save failed');
    }
  }

  if (errors.length && totalInserted === 0 && !linked) {
    if (statusEl) {
      statusEl.textContent = errors[0];
      statusEl.className = 'text-xs text-red-600 font-semibold text-center min-h-[1rem]';
    }
    return;
  }

  const items = linkedFromApi.length ? linkedFromApi : buildLinkedItemsFromSlots();
  updateLinkedCertView({
    multi: items.length > 1 || document.querySelectorAll('.import-cert-slot').length > 1,
    id: items[0]?.id || importSelectedTemplateId || '',
    title: items[0]?.title || 'Certificate',
    thumb: items[0]?.thumb || '',
    items,
  });
  setImportModalMode('linked', { animate: true, force: true });
  if (statusEl) {
    if (errors.length) {
      // Partial save (e.g. one seminar reused a code) — never hide it behind the success text.
      statusEl.textContent = `Some seminars were not saved: ${errors[0]}`;
      statusEl.className = 'text-xs text-amber-700 font-semibold text-center min-h-[1rem]';
      return;
    }
    const seedHint = (() => {
      const first = items.map((it) => it.title).filter(Boolean)[0];
      return first ? ` “${first}”` : '';
    })();
    statusEl.textContent = totalInserted > 0 || linked
      ? `Linked${seedHint}. Each eval submit gets the next number (…-01, …-02, …).`
      : (linked ? 'Template linked. Upload/scan a seed code so auto-count can start.' : '');
    statusEl.className = 'text-xs text-emerald-700 font-semibold text-center min-h-[1rem]';
  }
}

btnImportCertSubmit?.addEventListener('click', () => { void submitImportCert(); });

const certModal = document.getElementById('successCertModal');
const certContent = document.getElementById('successCertContent');
const btnCloseCertModal = document.getElementById('btnCloseCertModal');
const successCertMessage = document.getElementById('successCertMessage');
const pendingCertModal = document.getElementById('pendingCertModal');
const pendingCertContent = document.getElementById('pendingCertContent');
const pendingCertList = document.getElementById('pendingCertList');
const btnClosePendingCertModal = document.getElementById('btnClosePendingCertModal');
const btnCancelPendingCert = document.getElementById('btnCancelPendingCert');
const btnConfirmPendingCert = document.getElementById('btnConfirmPendingCert');
const templateCertModal = document.getElementById('templateCertModal');
const templateCertContent = document.getElementById('templateCertContent');
const btnCloseTemplateCertModal = document.getElementById('btnCloseTemplateCertModal');
const btnCancelTemplateCert = document.getElementById('btnCancelTemplateCert');
const btnConfirmTemplateCert = document.getElementById('btnConfirmTemplateCert');
const templateCertEligibleCount = document.getElementById('templateCertEligibleCount');
const templateCertPendingCount = document.getElementById('templateCertPendingCount');
const templateCertSelectedLabel = document.getElementById('templateCertSelectedLabel');
const templateCertPendingWrap = document.getElementById('templateCertPendingWrap');
const templateCertPendingList = document.getElementById('templateCertPendingList');
const templateSendCards = Array.from(document.querySelectorAll('.template-send-card'));
const templateCertPreviewThumb = document.getElementById('templateCertPreviewThumb');
const templateCertPreviewEmpty = document.getElementById('templateCertPreviewEmpty');
const templateCertPreviewTitle = document.getElementById('templateCertPreviewTitle');
const templateCertPreviewScope = document.getElementById('templateCertPreviewScope');
const templateCertPreviewEvent = document.getElementById('templateCertPreviewEvent');
const seminarTemplateAssignWrap = document.getElementById('seminarTemplateAssignWrap');
const seminarTemplateAssignRows = document.getElementById('seminarTemplateAssignRows');
const templateModeLabel = document.getElementById('templateModeLabel');
const templateCertSelectedWrap = document.getElementById('templateCertSelectedWrap');
const templateCertSinglePreviewWrap = document.getElementById('templateCertSinglePreviewWrap');
let selectedCertificateTemplateId = '';
let selectedCertificateTemplateTitle = '';
let selectedCertificateTemplateScope = 'event';
let selectedCertificateTemplateSessionId = '';
let previewMode = 'simple';
let selectedSeminarTemplateMap = {};
let armedAssignmentTemplateId = '';
const modalSessions = <?= json_encode(array_map(static function (array $s): array {
    return [
        'id' => (string) ($s['id'] ?? ''),
        'label' => build_session_display_name($s),
    ];
}, $sessions), JSON_UNESCAPED_SLASHES | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

function openPendingCertModal() {
    pendingCertModal?.classList.remove('hidden');
    pendingCertModal?.classList.add('flex');
    setTimeout(() => {
        pendingCertModal?.classList.remove('opacity-0');
        pendingCertContent?.classList.remove('scale-95');
        pendingCertContent?.classList.add('scale-100');
    }, 10);
}

function closePendingCertModal() {
    pendingCertModal?.classList.add('opacity-0');
    pendingCertContent?.classList.remove('scale-100');
    pendingCertContent?.classList.add('scale-95');
    setTimeout(() => {
        pendingCertModal?.classList.add('hidden');
        pendingCertModal?.classList.remove('flex');
    }, 300);
}

function openTemplateCertModal() {
    templateCertModal?.classList.remove('hidden');
    templateCertModal?.classList.add('flex');
    setTimeout(() => {
        templateCertModal?.classList.remove('opacity-0');
        templateCertContent?.classList.remove('scale-95');
        templateCertContent?.classList.add('scale-100');
    }, 10);
}

function closeTemplateCertModal() {
    templateCertModal?.classList.add('opacity-0');
    templateCertContent?.classList.remove('scale-100');
    templateCertContent?.classList.add('scale-95');
    setTimeout(() => {
        templateCertModal?.classList.add('hidden');
        templateCertModal?.classList.remove('flex');
    }, 300);
}

function renderPendingStudents(items) {
    if (!pendingCertList) return;
    if (!Array.isArray(items) || items.length === 0) {
        pendingCertList.innerHTML = '<div class="rounded-2xl border border-zinc-200 bg-zinc-50 px-4 py-3 text-sm text-zinc-500">All eligible attendees have already completed evaluation.</div>';
        return;
    }

    pendingCertList.innerHTML = items.map((item) => {
        const label = escapeHtml(item?.label || item?.name || 'Student');
        const reasons = Array.isArray(item?.reasons) ? item.reasons : [];
        const reasonsHtml = reasons.length > 0
            ? `<div class="mt-2 flex flex-wrap gap-2">${reasons.map((reason) => `<span class="rounded-full bg-amber-100 px-2.5 py-1 text-[11px] font-bold text-amber-800 border border-amber-200">${escapeHtml(reason)}</span>`).join('')}</div>`
            : '';

        return `
            <div class="rounded-2xl border border-zinc-200 bg-zinc-50 px-4 py-3">
                <div class="text-sm font-bold text-zinc-900">${label}</div>
                ${reasonsHtml}
            </div>
        `;
    }).join('');
}

function renderTemplatePendingStudents(items) {
    if (!templateCertPendingList || !templateCertPendingWrap) return;
    if (!Array.isArray(items) || items.length === 0) {
        templateCertPendingWrap.classList.add('hidden');
        templateCertPendingList.innerHTML = '';
        return;
    }

    templateCertPendingWrap.classList.remove('hidden');
    templateCertPendingList.innerHTML = items.map((item) => {
        const label = escapeHtml(item?.label || item?.name || 'Student');
        const reasons = Array.isArray(item?.reasons) ? item.reasons : [];
        const reasonsHtml = reasons.length > 0
            ? `<div class="mt-2 flex flex-wrap gap-2">${reasons.map((reason) => `<span class="rounded-full bg-amber-100 px-2.5 py-1 text-[11px] font-bold text-amber-800 border border-amber-200">${escapeHtml(reason)}</span>`).join('')}</div>`
            : '';

        return `
            <div class="rounded-2xl border border-zinc-200 bg-white px-4 py-3">
                <div class="text-sm font-bold text-zinc-900">${label}</div>
                ${reasonsHtml}
            </div>
        `;
    }).join('');
}

function updateSelectedTemplateCard() {
    templateSendCards.forEach((card) => {
        const active = card.dataset.templateId === selectedCertificateTemplateId;
        card.classList.toggle('border-amber-500', active);
        card.classList.toggle('ring-2', active);
        card.classList.toggle('ring-amber-200', active);
        card.classList.toggle('bg-amber-50', active);
    });

    if (templateCertSelectedLabel) {
        templateCertSelectedLabel.textContent = selectedCertificateTemplateTitle || 'None selected yet';
    }

    const selectedCard = templateSendCards.find((card) => card.dataset.templateId === selectedCertificateTemplateId);
    if (templateCertPreviewTitle) {
        templateCertPreviewTitle.textContent = selectedCertificateTemplateTitle || 'No template selected yet';
    }
    if (templateCertPreviewScope) {
        templateCertPreviewScope.textContent = selectedCard?.dataset.templateScopeLabel || 'Choose a template';
    }
    if (templateCertPreviewEvent) {
        templateCertPreviewEvent.textContent = selectedCard?.dataset.templateLinkedEvent || '';
    }
    if (templateCertPreviewThumb && templateCertPreviewEmpty) {
        const thumb = selectedCard?.dataset.templateThumb || '';
        if (thumb) {
            templateCertPreviewThumb.src = thumb;
            templateCertPreviewThumb.alt = selectedCertificateTemplateTitle || 'Certificate template preview';
            templateCertPreviewThumb.classList.remove('hidden');
            templateCertPreviewEmpty.classList.add('hidden');
        } else {
            templateCertPreviewThumb.src = '';
            templateCertPreviewThumb.alt = '';
            templateCertPreviewThumb.classList.add('hidden');
            templateCertPreviewEmpty.classList.remove('hidden');
        }
    }
}

function getTemplateCardById(templateId) {
    return templateSendCards.find((card) => (card.dataset.templateId || '') === templateId);
}

function canAssignTemplateToTarget(card, targetKind, targetSessionId) {
    if (!card) return false;
    const scope = card.dataset.templateScope || 'event';
    const cardSessionId = card.dataset.templateSessionId || '';
    if (targetKind === 'event') {
        return scope === 'event';
    }
    if (scope === 'event') {
        return true;
    }
    return scope === 'session' && cardSessionId !== '' && cardSessionId === targetSessionId;
}

function clearDropzoneHighlight(dropzone) {
    if (!dropzone) return;
    dropzone.classList.remove('border-amber-500', 'ring-2', 'ring-amber-200', 'bg-amber-50');
}

function setDropzoneAssignedState(dropzone, isAssigned) {
    if (!dropzone) return;
    dropzone.classList.toggle('border-amber-400', isAssigned);
    dropzone.classList.toggle('bg-amber-50', isAssigned);
    dropzone.classList.toggle('shadow-sm', isAssigned);
}

function assignTemplateToTarget(targetRow, templateId) {
    const targetKind = targetRow?.dataset?.targetKind || '';
    const targetSessionId = targetRow?.dataset?.sessionId || '';
    const card = getTemplateCardById(templateId);
    if (!card) return false;

    if (!canAssignTemplateToTarget(card, targetKind, targetSessionId)) {
        alert(targetKind === 'event'
            ? 'Whole Event target only accepts whole-event templates.'
            : 'This seminar target only accepts its own seminar template or a whole-event template.');
        return false;
    }

    const templateTitle = card.dataset.templateTitle || 'Template';
    const templateScopeLabel = card.dataset.templateScopeLabel || 'Template';
    const templateScope = card.dataset.templateScope || 'event';
    const templateSessionId = card.dataset.templateSessionId || '';
    const templateEventTitle = card.dataset.templateEvent || '';
    const templateThumb = card.dataset.templateThumb || '';
    const assignedLabel = targetRow.querySelector('.assignment-template-label');
    const assignedMeta = targetRow.querySelector('.assignment-template-meta');
    const assignedPreviewImg = targetRow.querySelector('.assignment-template-preview-img');
    const assignedPreviewEmpty = targetRow.querySelector('.assignment-template-preview-empty');
    const clearBtn = targetRow.querySelector('.assignment-clear-btn');
    const dropzone = targetRow.querySelector('.assignment-dropzone');

    if (assignedLabel) {
        assignedLabel.textContent = templateTitle;
    }
    if (assignedMeta) {
        assignedMeta.textContent = `${templateScopeLabel}${templateEventTitle ? ` - ${templateEventTitle}` : ''}`;
    }
    if (assignedPreviewImg && assignedPreviewEmpty) {
        if (templateThumb) {
            assignedPreviewImg.src = templateThumb;
            assignedPreviewImg.alt = templateTitle;
            assignedPreviewImg.classList.remove('hidden');
            assignedPreviewEmpty.classList.add('hidden');
        } else {
            assignedPreviewImg.src = '';
            assignedPreviewImg.alt = '';
            assignedPreviewImg.classList.add('hidden');
            assignedPreviewEmpty.classList.remove('hidden');
        }
    }
    if (clearBtn) {
        clearBtn.classList.remove('hidden');
    }
    setDropzoneAssignedState(dropzone, true);
    if (dropzone) {
        dropzone.classList.remove('assignment-dropzone-pop');
        void dropzone.offsetWidth;
        dropzone.classList.add('assignment-dropzone-pop');
    }

    targetRow.dataset.assignedTemplateId = card.dataset.templateId || '';
    targetRow.dataset.assignedTemplateScope = templateScope;
    targetRow.dataset.assignedTemplateSessionId = templateSessionId;

    if (targetKind === 'event') {
        setSelectedCertificateTemplate(
            card.dataset.templateId || '',
            templateTitle,
            templateEventTitle
        );
    } else if (targetSessionId !== '') {
        selectedSeminarTemplateMap[targetSessionId] = {
            template_id: card.dataset.templateId || '',
            template_scope: templateScope === 'session' ? 'session' : 'event',
        };
    }

    return true;
}

function clearTemplateFromTarget(targetRow) {
    const targetKind = targetRow?.dataset?.targetKind || '';
    const targetSessionId = targetRow?.dataset?.sessionId || '';
    const targetLabel = targetRow?.dataset?.targetLabel || 'target';
    const assignedLabel = targetRow?.querySelector('.assignment-template-label');
    const assignedMeta = targetRow?.querySelector('.assignment-template-meta');
    const assignedPreviewImg = targetRow?.querySelector('.assignment-template-preview-img');
    const assignedPreviewEmpty = targetRow?.querySelector('.assignment-template-preview-empty');
    const clearBtn = targetRow?.querySelector('.assignment-clear-btn');
    const dropzone = targetRow?.querySelector('.assignment-dropzone');

    if (assignedLabel) {
        assignedLabel.textContent = `No template assigned for ${targetLabel}`;
    }
    if (assignedMeta) {
        assignedMeta.textContent = 'Drop or click a template card to assign.';
    }
    if (assignedPreviewImg && assignedPreviewEmpty) {
        assignedPreviewImg.src = '';
        assignedPreviewImg.alt = '';
        assignedPreviewImg.classList.add('hidden');
        assignedPreviewEmpty.classList.remove('hidden');
    }
    if (clearBtn) {
        clearBtn.classList.add('hidden');
    }
    setDropzoneAssignedState(dropzone, false);
    clearDropzoneHighlight(dropzone);

    delete targetRow.dataset.assignedTemplateId;
    delete targetRow.dataset.assignedTemplateScope;
    delete targetRow.dataset.assignedTemplateSessionId;

    if (targetKind === 'event') {
        setSelectedCertificateTemplate('', '', '');
    } else if (targetSessionId !== '') {
        delete selectedSeminarTemplateMap[targetSessionId];
    }
}

function bindAssignmentRowInteractions(targetRow) {
    const dropzone = targetRow.querySelector('.assignment-dropzone');
    const clearBtn = targetRow.querySelector('.assignment-clear-btn');
    if (!dropzone) return;

    const assignFromArmedCard = () => {
        if (!armedAssignmentTemplateId) {
            alert('Click a template card first, then click this target to assign.');
            return;
        }
        assignTemplateToTarget(targetRow, armedAssignmentTemplateId);
    };

    dropzone.addEventListener('click', assignFromArmedCard);
    dropzone.addEventListener('dragover', (event) => {
        event.preventDefault();
        dropzone.classList.add('border-amber-500', 'ring-2', 'ring-amber-200', 'bg-amber-50');
    });
    dropzone.addEventListener('dragleave', () => {
        clearDropzoneHighlight(dropzone);
    });
    dropzone.addEventListener('drop', (event) => {
        event.preventDefault();
        clearDropzoneHighlight(dropzone);
        const droppedTemplateId = event.dataTransfer?.getData('text/template-id') || '';
        if (!droppedTemplateId) return;
        assignTemplateToTarget(targetRow, droppedTemplateId);
    });

    clearBtn?.addEventListener('click', (event) => {
        event.stopPropagation();
        clearTemplateFromTarget(targetRow);
    });
}

function renderSeminarTemplateAssignments(sessionSummary) {
    if (!seminarTemplateAssignWrap || !seminarTemplateAssignRows) return;
    const sourceSessions = previewMode === 'seminar_based'
        ? (Array.isArray(sessionSummary) && sessionSummary.length > 0 ? sessionSummary : modalSessions)
        : [];

    seminarTemplateAssignWrap.classList.remove('hidden');
    selectedSeminarTemplateMap = {};
    armedAssignmentTemplateId = '';

    const wholeEventRow = `
        <div class="assignment-target-row rounded-2xl border border-amber-200 bg-white p-3 space-y-3" data-target-kind="event" data-session-id="" data-target-label="Whole Event">
            <div class="flex items-center justify-between gap-3">
                <div class="text-sm font-black text-zinc-900">Whole Event</div>
                <span class="rounded-full border border-amber-200 bg-amber-50 px-2.5 py-1 text-[10px] font-black uppercase tracking-widest text-amber-700">Simple Mode Target</span>
            </div>
            <button type="button" class="assignment-dropzone w-full text-left rounded-xl border border-dashed border-amber-300 bg-amber-50/40 px-4 py-3 hover:bg-amber-50 transition-all duration-200">
                <div class="flex items-start gap-3">
                    <div class="w-20 h-14 rounded-lg border border-zinc-200 bg-zinc-100 overflow-hidden flex items-center justify-center flex-shrink-0">
                        <img src="" alt="" class="assignment-template-preview-img hidden w-full h-full object-cover">
                        <div class="assignment-template-preview-empty text-[10px] font-bold text-zinc-400">No Preview</div>
                    </div>
                    <div class="min-w-0">
                        <div class="assignment-template-label text-sm font-bold text-zinc-900">No template assigned for Whole Event</div>
                        <div class="assignment-template-meta mt-1 text-xs text-zinc-500">Drop or click a template card to assign.</div>
                    </div>
                </div>
            </button>
            <div class="flex justify-end">
                <button type="button" class="assignment-clear-btn hidden rounded-lg border border-zinc-300 bg-white px-2.5 py-1.5 text-[11px] font-bold text-zinc-700 hover:bg-zinc-50 transition">Clear</button>
            </div>
        </div>
    `;

    const seminarRows = sourceSessions.map((session) => {
        const sessionId = String(session?.session_id || session?.id || '');
        const sessionLabel = String(session?.session_title || session?.label || 'Seminar');
        const eligibleCount = Number(session?.eligible_count || 0);
        const pendingCount = Number(session?.pending_count || 0);
        return `
            <div class="assignment-target-row rounded-2xl border border-amber-200 bg-white p-3 space-y-3" data-target-kind="session" data-session-id="${sessionId}" data-target-label="${sessionLabel.replace(/"/g, '&quot;')}">
                <div class="flex items-center justify-between gap-3">
                    <div class="text-sm font-black text-zinc-900">${sessionLabel}</div>
                    <div class="text-[11px] font-bold text-zinc-500">Eligible: ${eligibleCount} | Pending: ${pendingCount}</div>
                </div>
                <button type="button" class="assignment-dropzone w-full text-left rounded-xl border border-dashed border-amber-300 bg-amber-50/40 px-4 py-3 hover:bg-amber-50 transition-all duration-200">
                    <div class="flex items-start gap-3">
                        <div class="w-20 h-14 rounded-lg border border-zinc-200 bg-zinc-100 overflow-hidden flex items-center justify-center flex-shrink-0">
                            <img src="" alt="" class="assignment-template-preview-img hidden w-full h-full object-cover">
                            <div class="assignment-template-preview-empty text-[10px] font-bold text-zinc-400">No Preview</div>
                        </div>
                        <div class="min-w-0">
                            <div class="assignment-template-label text-sm font-bold text-zinc-900">No template assigned for ${sessionLabel}</div>
                            <div class="assignment-template-meta mt-1 text-xs text-zinc-500">Drop or click a template card to assign.</div>
                        </div>
                    </div>
                </button>
                <div class="flex justify-end">
                    <button type="button" class="assignment-clear-btn hidden rounded-lg border border-zinc-300 bg-white px-2.5 py-1.5 text-[11px] font-bold text-zinc-700 hover:bg-zinc-50 transition">Clear</button>
                </div>
            </div>
        `;
    }).join('');

    seminarTemplateAssignRows.innerHTML = wholeEventRow + seminarRows;

    seminarTemplateAssignRows.querySelectorAll('.assignment-target-row').forEach((row) => {
        bindAssignmentRowInteractions(row);
    });

    if (previewMode !== 'seminar_based') {
        seminarTemplateAssignRows.querySelectorAll('.assignment-target-row[data-target-kind="session"]').forEach((row) => {
            row.classList.add('hidden');
        });
    } else {
        seminarTemplateAssignRows.querySelectorAll('.assignment-target-row[data-target-kind="event"]').forEach((row) => {
            row.classList.add('hidden');
        });
        setSelectedCertificateTemplate('', '', '');
    }

}

function setSelectedCertificateTemplate(templateId, templateTitle, templateEventTitle) {
    selectedCertificateTemplateId = templateId || '';
    selectedCertificateTemplateTitle = selectedCertificateTemplateId
        ? `${templateTitle}${templateEventTitle ? ` - ${templateEventTitle}` : ''}`
        : '';
    const selectedCard = templateSendCards.find((card) => card.dataset.templateId === selectedCertificateTemplateId);
    selectedCertificateTemplateScope = selectedCard?.dataset.templateScope || 'event';
    selectedCertificateTemplateSessionId = selectedCard?.dataset.templateSessionId || '';
    updateSelectedTemplateCard();
}

function showTemplateSelectionPreview(data) {
    // Always start empty when modal opens.
    setSelectedCertificateTemplate('', '', '');
    selectedSeminarTemplateMap = {};
    armedAssignmentTemplateId = '';

    previewMode = String(data?.mode || 'simple');
    if (templateModeLabel) {
        templateModeLabel.textContent = previewMode === 'seminar_based'
            ? 'Mode: Seminar Based'
            : 'Mode: Simple (Whole Event)';
    }
    if (templateCertEligibleCount) {
        templateCertEligibleCount.textContent = String(data?.eligible_count || 0);
    }
    if (templateCertPendingCount) {
        templateCertPendingCount.textContent = String(data?.pending_count || 0);
    }
    renderTemplatePendingStudents(data?.pending_students || []);
    renderSeminarTemplateAssignments(Array.isArray(data?.session_summary) ? data.session_summary : []);
    updateSelectedTemplateCard();

    if (previewMode === 'seminar_based') {
        templateCertSelectedWrap?.classList.add('hidden');
        templateCertSinglePreviewWrap?.classList.add('hidden');
    } else {
        templateCertSelectedWrap?.classList.remove('hidden');
        templateCertSinglePreviewWrap?.classList.remove('hidden');
    }

    openTemplateCertModal();
}

async function sendCertificates(templateId) {
    const buildLoadingHtml = (label, iconColorClass = 'text-emerald-700') =>
        `<span class="relative z-10 flex items-center justify-center gap-2"><svg class="animate-spin h-4 w-4 ${iconColorClass}" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>${label}</span>`;

    const originalText = btnSendCert.innerHTML;
    const originalConfirmTemplateText = btnConfirmTemplateCert ? btnConfirmTemplateCert.innerHTML : '';
    btnSendCert.innerHTML = buildLoadingHtml('Sending...');
    btnSendCert.disabled = true;
    if (btnConfirmTemplateCert) {
        btnConfirmTemplateCert.innerHTML = buildLoadingHtml('Sending...', 'text-white');
        btnConfirmTemplateCert.disabled = true;
        btnConfirmTemplateCert.classList.add('opacity-80', 'cursor-not-allowed');
    }

    const requestPayload = {
        event_id: '<?= htmlspecialchars($id) ?>',
        csrf_token: window.CSRF_TOKEN,
    };
    if (previewMode === 'seminar_based') {
        requestPayload.session_template_map = selectedSeminarTemplateMap;
    } else {
        requestPayload.template_id = templateId;
        requestPayload.template_scope = selectedCertificateTemplateScope;
        requestPayload.template_session_id = selectedCertificateTemplateSessionId;
    }

    try {
        const res = await fetch('/api/certificates_generate.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(requestPayload)
        });
        const data = await res.json();
        if (!data.ok) {
            throw new Error(data.error || 'Failed to generate certificates');
        }

        if (successCertMessage) {
            const baseText = `Successfully generated ${data.count} certificate${data.count === 1 ? '' : 's'} for eligible participants.`;
            const notif = data?.notification || {};
            const attemptedUsers = Number(notif?.attempted_users || 0);
            const resolvedTokens = Number(notif?.resolved_tokens || 0);
            const sent = notif?.sent === true;
            const notifText = attemptedUsers > 0
                ? (resolvedTokens > 0
                    ? ` Push delivery: ${resolvedTokens} active device token${resolvedTokens === 1 ? '' : 's'}${sent ? ' notified.' : ' attempted (check FCM credentials/logs).'}`
                    : ' Push delivery: no active device token found. Ask student to log in again on the app device.')
                : '';
            successCertMessage.textContent = `${baseText}${notifText}`;
        }
        closeTemplateCertModal();
        certModal.classList.remove('hidden');
        certModal.classList.add('flex');
        setTimeout(() => {
            certModal.classList.remove('opacity-0');
            certContent.classList.remove('scale-95');
            certContent.classList.add('scale-100');
        }, 10);
    } catch (err) {
        alert('Error generating certificates: ' + err.message);
    } finally {
        btnSendCert.innerHTML = originalText;
        btnSendCert.disabled = btnSendCert.dataset.eventFinished !== '1';
        if (btnConfirmTemplateCert) {
            btnConfirmTemplateCert.innerHTML = originalConfirmTemplateText;
            btnConfirmTemplateCert.disabled = false;
            btnConfirmTemplateCert.classList.remove('opacity-80', 'cursor-not-allowed');
        }
    }
}

templateSendCards.forEach((card) => {
    card.addEventListener('dragstart', (event) => {
        const templateId = card.dataset.templateId || '';
        if (!templateId) return;
        armedAssignmentTemplateId = templateId;
        event.dataTransfer?.setData('text/template-id', templateId);
        event.dataTransfer.effectAllowed = 'copy';
    });
    card.addEventListener('click', () => {
        armedAssignmentTemplateId = card.dataset.templateId || '';
        const wholeEventTarget = seminarTemplateAssignRows?.querySelector('.assignment-target-row[data-target-kind="event"]');
        if (wholeEventTarget) {
            assignTemplateToTarget(wholeEventTarget, armedAssignmentTemplateId);
            return;
        }
        setSelectedCertificateTemplate(
            card.dataset.templateId || '',
            card.dataset.templateTitle || 'Template',
            card.dataset.templateEvent || ''
        );
    });
});

if (btnSendCert) {
    btnSendCert.addEventListener('click', async () => {
        if (btnSendCert.dataset.eventFinished !== '1') {
            alert('Certificates can only be sent after the event has finished.');
            return;
        }

        const originalText = btnSendCert.innerHTML;
        btnSendCert.innerHTML = '<span class="relative z-10 flex items-center justify-center gap-2"><svg class="animate-spin h-4 w-4 text-emerald-700" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg> Checking...</span>';
        btnSendCert.disabled = true;

        try {
            const res = await fetch('/api/certificates_generate.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ event_id: '<?= htmlspecialchars($id) ?>', preview_only: true, csrf_token: window.CSRF_TOKEN })
            });
            const data = await res.json();
            if (!data.ok) {
                throw new Error(data.error || 'Failed to preview certificates');
            }

            btnSendCert.innerHTML = originalText;
            btnSendCert.disabled = false;

            if ((data.eligible_count || 0) === 0) {
                alert(data.pending_count > 0
                    ? 'No certificates can be sent yet because the present participants still have incomplete evaluation.'
                    : 'No eligible participants found for certificate sending.');
                return;
            }

            showTemplateSelectionPreview(data);
        } catch (err) {
            alert('Error generating certificates: ' + err.message);
            btnSendCert.innerHTML = originalText;
            btnSendCert.disabled = btnSendCert.dataset.eventFinished !== '1';
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
    btnClosePendingCertModal?.addEventListener('click', closePendingCertModal);
    btnCancelPendingCert?.addEventListener('click', closePendingCertModal);
    btnCloseTemplateCertModal?.addEventListener('click', closeTemplateCertModal);
    btnCancelTemplateCert?.addEventListener('click', closeTemplateCertModal);
    pendingCertModal?.addEventListener('click', (e) => {
        if (e.target === pendingCertModal) {
            closePendingCertModal();
        }
    });
    templateCertModal?.addEventListener('click', (e) => {
        if (e.target === templateCertModal) {
            closeTemplateCertModal();
        }
    });
    btnConfirmPendingCert?.addEventListener('click', async () => {
        closePendingCertModal();
        showTemplateSelectionPreview({
            eligible_count: Number(templateCertEligibleCount?.textContent || '0'),
            pending_count: Number(templateCertPendingCount?.textContent || '0'),
            pending_students: [],
            mode: previewMode,
            session_summary: modalSessions.map((session) => ({
                session_id: session.id,
                session_title: session.label,
                eligible_count: 0,
                pending_count: 0,
            })),
        });
    });
    btnConfirmTemplateCert?.addEventListener('click', async () => {
        if (previewMode === 'seminar_based') {
            const sessionRows = Array.isArray(modalSessions) ? modalSessions : [];
            const missing = sessionRows.filter((session) => {
                const sessionId = String(session?.id || '');
                return sessionId !== '' && !selectedSeminarTemplateMap[sessionId];
            });
            if (missing.length > 0) {
                alert('Please select a template for each seminar before sending.');
                return;
            }
            await sendCertificates('');
            return;
        }
        if (!selectedCertificateTemplateId) {
            alert('Please select a saved certificate template first.');
            return;
        }
        await sendCertificates(selectedCertificateTemplateId);
    });
}

<?php endif; ?>

<?php if ($role === 'admin'): ?>
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
      const ticketToken = data?.ticket?.token || '';
      if (ticketToken) {
        msg.innerHTML = 'Registered! <a class="underline" href="/ticket.php?token=' + encodeURIComponent(ticketToken) + '">View ticket</a>';
      } else if (data.already_registered) {
        msg.textContent = 'You are already registered for this event. Redirecting to your tickets...';
      } else {
        msg.textContent = 'Registered successfully. Refreshing...';
      }
      btn.style.display = 'none';
      setTimeout(()=>window.location.reload(), 1500);
    } catch (err) { msg.textContent = err.message || 'Failed'; btn.disabled = false; }
});
<?php endif; ?>
</script>

<?php if ($showDocumentReviewModal): ?>
<div id="documentReviewShell" class="fixed inset-0 z-[70] hidden items-center justify-center p-3 sm:p-5 bg-black/50 backdrop-blur-sm">
  <div class="w-full max-w-5xl max-h-[92vh] flex flex-col rounded-2xl bg-white shadow-2xl overflow-hidden">
    <div class="flex items-start justify-between gap-3 px-5 py-4 border-b border-zinc-200 shrink-0">
      <div class="min-w-0">
        <h3 class="text-lg font-bold text-zinc-900">Document Review</h3>
        <p id="docReviewSubtitle" class="mt-1 text-sm text-zinc-500 truncate"><?= htmlspecialchars((string) ($event['title'] ?? 'Event')) ?></p>
        <div id="docReviewCounts" class="mt-2 flex flex-wrap items-center gap-2 text-xs font-semibold"></div>
      </div>
      <button type="button" id="btnCloseDocumentReviewShell" class="shrink-0 flex items-center justify-center w-8 h-8 rounded-lg border border-zinc-200 text-zinc-500 hover:bg-zinc-50 transition" title="Close">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
      </button>
    </div>

    <div class="px-5 py-3 border-b border-zinc-100 flex flex-col sm:flex-row gap-2 shrink-0">
      <input type="search" id="docReviewSearch" placeholder="Search name, email, ID…"
        class="w-full sm:w-64 rounded-lg border border-zinc-300 bg-white px-3 py-1.5 text-sm text-zinc-900 outline-none focus:border-sky-400 focus:ring-2 focus:ring-sky-500/20">
      <select id="docReviewStatusFilter"
        class="rounded-lg border border-zinc-300 bg-white px-3 py-1.5 text-sm text-zinc-700 outline-none focus:border-sky-400 focus:ring-2 focus:ring-sky-500/20">
        <option value="all">All statuses</option>
        <option value="pending_review" selected>Pending only</option>
        <option value="approved">Approved only</option>
        <option value="declined">Declined only</option>
      </select>
    </div>

    <div id="docReviewBody" class="flex-1 overflow-y-auto px-5 py-4">
      <div id="docReviewLoading" class="py-12 text-center text-sm text-zinc-500">Loading submissions…</div>
      <div id="docReviewEmpty" class="hidden py-12 text-center text-sm text-zinc-500">No submissions yet.</div>
      <div id="docReviewTableWrap" class="hidden rounded-xl border border-zinc-200 overflow-hidden">
        <table class="min-w-full divide-y divide-zinc-200 text-sm">
          <thead class="bg-zinc-50">
            <tr>
              <th class="px-3 py-2 text-left text-[11px] font-bold uppercase tracking-wider text-zinc-500">Student</th>
              <th class="px-3 py-2 text-left text-[11px] font-bold uppercase tracking-wider text-zinc-500">Status</th>
              <th class="px-3 py-2 text-left text-[11px] font-bold uppercase tracking-wider text-zinc-500 hidden sm:table-cell">Submitted</th>
              <th class="px-3 py-2 text-center text-[11px] font-bold uppercase tracking-wider text-zinc-500 w-16">View</th>
            </tr>
          </thead>
          <tbody id="docReviewTableBody" class="divide-y divide-zinc-100"></tbody>
        </table>
      </div>
      <div id="docReviewFilterEmpty" class="hidden py-8 text-center text-sm text-zinc-500">No submissions match your search or filter.</div>
    </div>
  </div>
</div>

<div id="docReviewDetailModal" class="fixed inset-0 z-[80] hidden items-center justify-center p-4 bg-black/50 backdrop-blur-sm">
  <div class="w-full max-w-lg max-h-[90vh] flex flex-col rounded-2xl bg-white shadow-2xl overflow-hidden">
    <div class="flex items-start justify-between gap-3 px-5 py-4 border-b border-zinc-200 shrink-0">
      <div class="min-w-0">
        <h3 id="docReviewDetailName" class="text-lg font-bold text-zinc-900 truncate"></h3>
        <p id="docReviewDetailMeta" class="mt-1 text-sm text-zinc-500"></p>
        <div class="mt-2 flex flex-wrap items-center gap-2">
          <span id="docReviewDetailStatus" class="inline-flex rounded-full border px-2.5 py-0.5 text-[10px] font-bold uppercase tracking-wide"></span>
          <span id="docReviewDetailSubmitted" class="text-xs text-zinc-400"></span>
        </div>
      </div>
      <button type="button" id="btnCloseDocReviewDetail" class="shrink-0 flex items-center justify-center w-8 h-8 rounded-lg border border-zinc-200 text-zinc-500 hover:bg-zinc-50" title="Close">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
      </button>
    </div>
    <div class="flex-1 overflow-y-auto px-5 py-4">
      <div id="docReviewDetailDeclineReason" class="hidden mb-4 rounded-xl border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-800"></div>
      <div id="docReviewDetailDocuments" class="space-y-2"></div>
      <div id="docReviewDeclineForm" class="hidden mt-4 rounded-xl border border-red-200 bg-red-50/50 p-4">
        <label for="docReviewDeclineReason" class="block text-sm font-semibold text-red-800">Decline reason</label>
        <p class="mt-1 text-xs text-red-600">The student will see this in the app.</p>
        <textarea id="docReviewDeclineReason" rows="3" class="mt-2 w-full rounded-lg border border-red-200 bg-white px-3 py-2 text-sm text-zinc-900 outline-none focus:ring-2 focus:ring-red-500/30 focus:border-red-400" placeholder="Explain what needs to be fixed…"></textarea>
      </div>
    </div>
    <div id="docReviewDetailActions" class="shrink-0 flex items-center justify-end gap-2 px-5 py-4 border-t border-zinc-200 bg-zinc-50">
      <button type="button" id="btnDocReviewCancelDecline" class="hidden rounded-lg border border-zinc-200 bg-white px-4 py-2 text-sm font-semibold text-zinc-700 hover:bg-zinc-50">Back</button>
      <button type="button" id="btnDocReviewApprove" class="rounded-lg border border-emerald-600 bg-emerald-600 px-4 py-2 text-sm font-bold text-white hover:bg-emerald-700">Approve</button>
      <button type="button" id="btnDocReviewDecline" class="rounded-lg border border-red-200 bg-red-50 px-4 py-2 text-sm font-bold text-red-700 hover:bg-red-100">Decline</button>
      <button type="button" id="btnDocReviewConfirmDecline" class="hidden rounded-lg border border-red-600 bg-red-600 px-4 py-2 text-sm font-bold text-white hover:bg-red-700">Confirm Decline</button>
    </div>
  </div>
</div>

<div id="docFilePreviewModal" class="fixed inset-0 z-[90] hidden items-center justify-center p-3 sm:p-6 bg-black/70 backdrop-blur-sm">
  <div class="w-full max-w-4xl max-h-[92vh] flex flex-col rounded-2xl bg-white shadow-2xl overflow-hidden">
    <div class="flex items-center justify-between gap-3 px-4 py-3 border-b border-zinc-200 shrink-0 bg-zinc-50">
      <div class="min-w-0">
        <div id="docFilePreviewLabel" class="text-[11px] font-bold uppercase tracking-wide text-zinc-500 truncate"></div>
        <div id="docFilePreviewName" class="text-sm font-bold text-zinc-900 truncate"></div>
      </div>
      <div class="flex items-center gap-2 shrink-0">
        <a id="docFilePreviewOpenTab" href="#" target="_blank" rel="noopener" class="rounded-lg border border-zinc-200 bg-white px-3 py-1.5 text-xs font-semibold text-zinc-700 hover:bg-zinc-50">Open tab</a>
        <button type="button" id="btnCloseDocFilePreview" class="flex items-center justify-center w-8 h-8 rounded-lg border border-zinc-200 text-zinc-500 hover:bg-white" title="Close">
          <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
        </button>
      </div>
    </div>
    <div id="docFilePreviewBody" class="flex-1 overflow-auto bg-zinc-100 min-h-[50vh] flex items-center justify-center p-2"></div>
  </div>
</div>

<script>
(function () {
  const EVENT_ID = <?= json_encode($id, JSON_UNESCAPED_SLASHES) ?>;
  const shell = document.getElementById('documentReviewShell');
  const detailModal = document.getElementById('docReviewDetailModal');
  const fileModal = document.getElementById('docFilePreviewModal');
  const fileBody = document.getElementById('docFilePreviewBody');
  const fileLabel = document.getElementById('docFilePreviewLabel');
  const fileName = document.getElementById('docFilePreviewName');
  const fileOpenTab = document.getElementById('docFilePreviewOpenTab');

  let reviewData = {};
  let activeStudentId = '';
  let loaded = false;

  const statusClasses = {
    approved: 'border-emerald-200 bg-emerald-50 text-emerald-700',
    declined: 'border-red-200 bg-red-50 text-red-700',
    pending_review: 'border-amber-200 bg-amber-50 text-amber-700',
  };

  function escapeHtml(value) {
    return String(value ?? '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function showEl(el, asFlex = false) {
    if (!el) return;
    el.classList.remove('hidden');
    if (asFlex) el.classList.add('flex');
  }

  function hideEl(el) {
    if (!el) return;
    el.classList.add('hidden');
    el.classList.remove('flex');
  }

  function isPreviewable(doc) {
    const mime = String(doc?.mime_type || '').toLowerCase();
    const url = String(doc?.file_url || '').toLowerCase();
    if (mime.startsWith('image/') || mime === 'application/pdf') return true;
    return /\.(png|jpe?g|webp|gif|pdf)(\?|$)/i.test(url);
  }

  async function openFilePreview(doc) {
    const rawUrl = String(doc?.file_url || '').trim();
    const objectPath = String(doc?.file_path || '').trim();
    if (!rawUrl && !objectPath) return;
    const mime = String(doc?.mime_type || '').toLowerCase();

    if (fileLabel) fileLabel.textContent = doc.label || 'Document';
    if (fileName) fileName.textContent = doc.file_name || 'Preview';
    if (fileBody) {
      fileBody.innerHTML = `<div class="text-sm font-medium text-zinc-500 py-10">Loading secure preview…</div>`;
    }
    showEl(fileModal, true);

    let url = rawUrl;
    try {
      const res = await fetch('/api/storage_signed_url.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
        body: JSON.stringify({
          bucket: 'student-documents',
          path: objectPath,
          url: rawUrl,
          expires_in: 3600,
          csrf_token: window.CSRF_TOKEN || '',
        }),
      });
      const data = await res.json().catch(() => ({}));
      if (res.ok && data?.ok && data?.signed_url) {
        url = String(data.signed_url);
      }
    } catch (_) {}

    if (!url) {
      if (fileBody) {
        fileBody.innerHTML = `<div class="text-center p-8 text-sm text-red-600 font-semibold">Unable to open file (private storage).</div>`;
      }
      return;
    }

    const isPdf = mime === 'application/pdf' || /\.pdf(\?|$)/i.test(url);
    const isImage = mime.startsWith('image/') || /\.(png|jpe?g|webp|gif)(\?|$)/i.test(url);
    if (fileOpenTab) fileOpenTab.href = url;

    if (!fileBody) return;
    if (isImage) {
      fileBody.innerHTML = `<img src="${escapeHtml(url)}" alt="${escapeHtml(doc.file_name || 'Document')}" class="max-h-[75vh] max-w-full object-contain rounded-lg shadow-sm bg-white" />`;
    } else if (isPdf) {
      fileBody.innerHTML = `<iframe src="${escapeHtml(url)}" title="${escapeHtml(doc.file_name || 'PDF')}" class="w-full h-[75vh] rounded-lg bg-white border border-zinc-200"></iframe>`;
    } else {
      fileBody.innerHTML = `<div class="text-center p-8"><p class="text-sm text-zinc-600 mb-3">Preview not available for this file type.</p><a href="${escapeHtml(url)}" target="_blank" rel="noopener" class="inline-flex rounded-lg bg-sky-600 px-4 py-2 text-sm font-bold text-white hover:bg-sky-700">Open file</a></div>`;
    }
  }

  function closeFilePreview() {
    hideEl(fileModal);
    if (fileBody) fileBody.innerHTML = '';
  }

  function renderCounts(counts) {
    const el = document.getElementById('docReviewCounts');
    if (!el) return;
    const pending = Number(counts?.pending || 0);
    const approved = Number(counts?.approved || 0);
    const declined = Number(counts?.declined || 0);
    const total = Number(counts?.total || 0);
    el.innerHTML = `
      <span class="inline-flex items-center gap-1.5 rounded-lg border border-amber-200 bg-amber-50 px-2.5 py-1 text-amber-800">Pending <span class="rounded bg-amber-200/80 px-1.5 py-0.5 text-[11px] font-bold">${pending}</span></span>
      <span class="inline-flex items-center gap-1.5 rounded-lg border border-emerald-200 bg-emerald-50 px-2.5 py-1 text-emerald-800">Approved <span class="rounded bg-emerald-200/80 px-1.5 py-0.5 text-[11px] font-bold">${approved}</span></span>
      <span class="inline-flex items-center gap-1.5 rounded-lg border border-red-200 bg-red-50 px-2.5 py-1 text-red-800">Declined <span class="rounded bg-red-200/80 px-1.5 py-0.5 text-[11px] font-bold">${declined}</span></span>
      <span class="text-zinc-400">·</span>
      <span class="text-zinc-500">${total} submission${total === 1 ? '' : 's'}</span>`;
  }

  function applyFilters() {
    const query = (document.getElementById('docReviewSearch')?.value || '').trim().toLowerCase();
    const status = document.getElementById('docReviewStatusFilter')?.value || 'all';
    const rows = Array.from(document.querySelectorAll('#docReviewTableBody .doc-review-row'));
    let visible = 0;
    rows.forEach((row) => {
      const rowStatus = row.dataset.status || '';
      const rowSearch = row.dataset.search || '';
      const match = (status === 'all' || rowStatus === status) && (!query || rowSearch.includes(query));
      row.classList.toggle('hidden', !match);
      if (match) visible += 1;
    });
    document.getElementById('docReviewFilterEmpty')?.classList.toggle('hidden', visible !== 0 || rows.length === 0);
    document.getElementById('docReviewTableWrap')?.classList.toggle('hidden', rows.length === 0);
  }

  function renderList() {
    const tbody = document.getElementById('docReviewTableBody');
    const loading = document.getElementById('docReviewLoading');
    const empty = document.getElementById('docReviewEmpty');
    const wrap = document.getElementById('docReviewTableWrap');
    if (loading) loading.classList.add('hidden');

    const entries = Object.values(reviewData);
    if (!entries.length) {
      empty?.classList.remove('hidden');
      wrap?.classList.add('hidden');
      return;
    }
    empty?.classList.add('hidden');
    wrap?.classList.remove('hidden');

    tbody.innerHTML = entries.map((item) => {
      const search = [item.display_name, item.email, item.student_no, item.section_name].join(' ').toLowerCase();
      const statusClass = statusClasses[item.status] || statusClasses.pending_review;
      return `
        <tr class="doc-review-row hover:bg-zinc-50/80" data-status="${escapeHtml(item.status)}" data-search="${escapeHtml(search)}" data-student-id="${escapeHtml(item.student_id)}">
          <td class="px-3 py-2 min-w-0">
            <div class="font-semibold text-zinc-900 truncate max-w-[260px]">${escapeHtml(item.display_name || 'Student')}</div>
            <div class="text-xs text-zinc-500 truncate max-w-[260px]">${escapeHtml(item.email || '—')}</div>
          </td>
          <td class="px-3 py-2">
            <span class="inline-flex rounded-full border px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide whitespace-nowrap ${statusClass}">${escapeHtml(item.status_label || 'Pending')}</span>
          </td>
          <td class="px-3 py-2 text-xs text-zinc-500 whitespace-nowrap hidden sm:table-cell">${escapeHtml(item.submitted_at || '—')}</td>
          <td class="px-3 py-2 text-center">
            <button type="button" class="btn-doc-review-view inline-flex items-center justify-center w-8 h-8 rounded-lg border border-zinc-200 bg-white text-zinc-600 hover:bg-sky-50 hover:border-sky-200 hover:text-sky-700 transition" data-student-id="${escapeHtml(item.student_id)}" title="View submission">
              <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z"/><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
            </button>
          </td>
        </tr>`;
    }).join('');

    tbody.querySelectorAll('.btn-doc-review-view').forEach((btn) => {
      btn.addEventListener('click', () => openDetail(btn.dataset.studentId || ''));
    });
    applyFilters();
  }

  async function loadReviewData(force = false) {
    if (loaded && !force) return;
    const loading = document.getElementById('docReviewLoading');
    loading?.classList.remove('hidden');
    document.getElementById('docReviewEmpty')?.classList.add('hidden');
    document.getElementById('docReviewTableWrap')?.classList.add('hidden');

    const res = await fetch('/api/student_requirements_review_list.php?event_id=' + encodeURIComponent(EVENT_ID));
    const data = await res.json();
    if (!data.ok) throw new Error(data.error || 'Failed to load document reviews.');

    reviewData = data.submissions || {};
    renderCounts(data.counts || {});
    if (data.event?.title) {
      const sub = document.getElementById('docReviewSubtitle');
      if (sub) sub.textContent = data.event.title;
    }
    loaded = true;
    renderList();
  }

  function resetDeclineForm() {
    document.getElementById('docReviewDeclineForm')?.classList.add('hidden');
    const reason = document.getElementById('docReviewDeclineReason');
    if (reason) reason.value = '';
    document.getElementById('btnDocReviewApprove')?.classList.remove('hidden');
    document.getElementById('btnDocReviewDecline')?.classList.remove('hidden');
    document.getElementById('btnDocReviewConfirmDecline')?.classList.add('hidden');
    document.getElementById('btnDocReviewCancelDecline')?.classList.add('hidden');
  }

  function openDetail(studentId) {
    const data = reviewData[studentId];
    if (!data) return;
    activeStudentId = studentId;
    resetDeclineForm();

    const status = data.status || 'pending_review';
    document.getElementById('docReviewDetailName').textContent = data.display_name || 'Student';
    document.getElementById('docReviewDetailMeta').textContent = [data.email, data.student_no, data.section_name].filter(Boolean).join(' · ') || '—';
    const statusEl = document.getElementById('docReviewDetailStatus');
    statusEl.textContent = data.status_label || 'Pending';
    statusEl.className = 'inline-flex rounded-full border px-2.5 py-0.5 text-[10px] font-bold uppercase tracking-wide ' + (statusClasses[status] || statusClasses.pending_review);
    document.getElementById('docReviewDetailSubmitted').textContent = data.submitted_at ? `Submitted ${data.submitted_at}` : '';

    const declineBox = document.getElementById('docReviewDetailDeclineReason');
    if (status === 'declined' && data.decline_reason) {
      declineBox.textContent = `Decline reason: ${data.decline_reason}`;
      declineBox.classList.remove('hidden');
    } else {
      declineBox.textContent = '';
      declineBox.classList.add('hidden');
    }

    const docsEl = document.getElementById('docReviewDetailDocuments');
    const docs = Array.isArray(data.documents) ? data.documents : [];
    if (!docs.length) {
      docsEl.innerHTML = '<p class="text-sm text-zinc-500">No documents uploaded.</p>';
    } else {
      docsEl.innerHTML = docs.map((doc, index) => {
        const label = escapeHtml(doc.label || 'Requirement');
        if (doc.uploaded && doc.file_url) {
          const name = escapeHtml(doc.file_name || 'View document');
          return `
            <button type="button" class="btn-preview-doc w-full text-left flex items-center gap-3 rounded-xl border border-zinc-200 bg-zinc-50 px-3 py-2.5 hover:bg-sky-50 hover:border-sky-200 transition" data-doc-index="${index}">
              <span class="flex items-center justify-center w-8 h-8 rounded-lg border border-sky-200 bg-sky-100 text-sky-700 shrink-0">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z"/><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
              </span>
              <span class="min-w-0">
                <span class="block text-[11px] font-bold uppercase tracking-wide text-zinc-500">${label}</span>
                <span class="block text-sm font-semibold text-sky-700 truncate">${name}</span>
                <span class="block text-[11px] text-zinc-400">${isPreviewable(doc) ? 'Tap to preview' : 'Tap to open'}</span>
              </span>
            </button>`;
        }
        return `
          <div class="flex items-center gap-3 rounded-xl border border-zinc-200 bg-zinc-50 px-3 py-2.5 opacity-70">
            <span class="flex items-center justify-center w-8 h-8 rounded-lg border border-zinc-200 bg-white text-zinc-400 shrink-0">—</span>
            <span class="min-w-0">
              <span class="block text-[11px] font-bold uppercase tracking-wide text-zinc-500">${label}</span>
              <span class="block text-sm text-zinc-400">Not uploaded</span>
            </span>
          </div>`;
      }).join('');

      docsEl.querySelectorAll('.btn-preview-doc').forEach((btn) => {
        btn.addEventListener('click', () => {
          const idx = Number(btn.dataset.docIndex || -1);
          const doc = docs[idx];
          if (doc) openFilePreview(doc);
        });
      });
    }

    const actions = document.getElementById('docReviewDetailActions');
    actions?.classList.toggle('hidden', status !== 'pending_review');
    showEl(detailModal, true);
  }

  function closeDetail() {
    hideEl(detailModal);
    activeStudentId = '';
    resetDeclineForm();
  }

  async function openShell() {
    showEl(shell, true);
    try {
      await loadReviewData(true);
    } catch (err) {
      document.getElementById('docReviewLoading').textContent = err.message || 'Failed to load.';
    }
  }

  function closeShell() {
    hideEl(shell);
    closeDetail();
    closeFilePreview();
  }

  async function reviewSubmission(action, reason = '') {
    const res = await fetch('/api/student_requirements_review.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        event_id: EVENT_ID,
        student_id: activeStudentId,
        action,
        reason,
        csrf_token: window.CSRF_TOKEN,
      }),
    });
    const data = await res.json();
    if (!data.ok) throw new Error(data.error || 'Review failed.');
    return data;
  }

  document.querySelectorAll('[data-document-review-modal]').forEach((link) => {
    link.addEventListener('click', (event) => {
      event.preventDefault();
      openShell();
    });
  });

  document.getElementById('btnCloseDocumentReviewShell')?.addEventListener('click', closeShell);
  shell?.addEventListener('click', (event) => {
    if (event.target === shell) closeShell();
  });

  document.getElementById('btnCloseDocReviewDetail')?.addEventListener('click', closeDetail);
  detailModal?.addEventListener('click', (event) => {
    if (event.target === detailModal) closeDetail();
  });

  document.getElementById('btnCloseDocFilePreview')?.addEventListener('click', closeFilePreview);
  fileModal?.addEventListener('click', (event) => {
    if (event.target === fileModal) closeFilePreview();
  });

  document.getElementById('docReviewSearch')?.addEventListener('input', applyFilters);
  document.getElementById('docReviewStatusFilter')?.addEventListener('change', applyFilters);

  document.getElementById('btnDocReviewApprove')?.addEventListener('click', async () => {
    if (!activeStudentId) return;
    const name = reviewData[activeStudentId]?.display_name || 'this student';
    if (!confirm(`Approve documents for ${name}?`)) return;
    const btn = document.getElementById('btnDocReviewApprove');
    btn.disabled = true;
    try {
      await reviewSubmission('approve');
      await loadReviewData(true);
      closeDetail();
    } catch (err) {
      alert(err.message || 'Failed to approve.');
    } finally {
      btn.disabled = false;
    }
  });

  document.getElementById('btnDocReviewDecline')?.addEventListener('click', () => {
    document.getElementById('docReviewDeclineForm')?.classList.remove('hidden');
    document.getElementById('btnDocReviewApprove')?.classList.add('hidden');
    document.getElementById('btnDocReviewDecline')?.classList.add('hidden');
    document.getElementById('btnDocReviewConfirmDecline')?.classList.remove('hidden');
    document.getElementById('btnDocReviewCancelDecline')?.classList.remove('hidden');
    document.getElementById('docReviewDeclineReason')?.focus();
  });

  document.getElementById('btnDocReviewCancelDecline')?.addEventListener('click', resetDeclineForm);

  document.getElementById('btnDocReviewConfirmDecline')?.addEventListener('click', async () => {
    if (!activeStudentId) return;
    const reason = (document.getElementById('docReviewDeclineReason')?.value || '').trim();
    if (!reason) {
      alert('Please enter a decline reason.');
      return;
    }
    const btn = document.getElementById('btnDocReviewConfirmDecline');
    btn.disabled = true;
    try {
      await reviewSubmission('decline', reason);
      await loadReviewData(true);
      closeDetail();
    } catch (err) {
      alert(err.message || 'Failed to decline.');
    } finally {
      btn.disabled = false;
    }
  });

  document.addEventListener('keydown', (event) => {
    if (event.key !== 'Escape') return;
    if (!fileModal?.classList.contains('hidden')) {
      closeFilePreview();
      return;
    }
    if (!detailModal?.classList.contains('hidden')) {
      closeDetail();
      return;
    }
    if (!shell?.classList.contains('hidden')) closeShell();
  });
})();
</script>
<?php endif; ?>

<?php if (in_array($role, ['admin', 'teacher'], true)): ?>
<script>
(() => {
  const eventId = <?= json_encode($id) ?>;
  if (!eventId || !window.CSRF_TOKEN) return;
  const run = () => {
    fetch('/api/attendance_backfill_run.php', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
      body: JSON.stringify({ event_id: eventId, csrf_token: window.CSRF_TOKEN })
    }).catch(() => {});
  };
  if ('requestIdleCallback' in window) {
    requestIdleCallback(run, { timeout: 2500 });
  } else {
    setTimeout(run, 1200);
  }
})();
</script>
<?php endif; ?>

<?php if (!empty($showEventQr)): ?>
<script src="https://cdn.jsdelivr.net/npm/qrcodejs@1.0.0/qrcode.min.js"></script>
<script>
(() => {
  const mount = document.getElementById('eventQrCode');
  if (!mount || typeof QRCode === 'undefined') return;

  const payload = <?= json_encode($eventQrPayload) ?>;
  const title = <?= json_encode((string) ($event['title'] ?? 'Event')) ?>;
  const logoUrl = '/assets/CCS.png';
  const qrSize = 200;

  // eslint-disable-next-line no-new
  new QRCode(mount, {
    text: payload,
    width: qrSize,
    height: qrSize,
    correctLevel: QRCode.CorrectLevel.H,
  });

  const drawRoundedRect = (ctx, x, y, width, height, radius) => {
    const r = Math.min(radius, width / 2, height / 2);
    ctx.beginPath();
    ctx.moveTo(x + r, y);
    ctx.arcTo(x + width, y, x + width, y + height, r);
    ctx.arcTo(x + width, y + height, x, y + height, r);
    ctx.arcTo(x, y + height, x, y, r);
    ctx.arcTo(x, y, x + width, y, r);
    ctx.closePath();
  };

  const getQrSource = () => {
    const sourceCanvas = mount.querySelector('canvas');
    if (sourceCanvas && sourceCanvas.width > 0) return { type: 'canvas', el: sourceCanvas };
    const sourceImg = mount.querySelector('img');
    if (sourceImg && sourceImg.complete && sourceImg.naturalWidth > 0) {
      return { type: 'img', el: sourceImg };
    }
    return null;
  };

  const paintWithLogo = (source, logo) => {
    const output = document.createElement('canvas');
    output.width = qrSize;
    output.height = qrSize;
    const ctx = output.getContext('2d');
    if (!ctx) return false;

    ctx.drawImage(source.el, 0, 0, qrSize, qrSize);

    const logoSize = Math.round(qrSize * 0.22);
    const pad = 6;
    const boxSize = logoSize + (pad * 2);
    const x = (qrSize - boxSize) / 2;
    const y = (qrSize - boxSize) / 2;

    ctx.fillStyle = '#ffffff';
    drawRoundedRect(ctx, x, y, boxSize, boxSize, 8);
    ctx.fill();
    ctx.drawImage(logo, x + pad, y + pad, logoSize, logoSize);

    mount.replaceChildren(output);
    mount.dataset.qrDataUrl = output.toDataURL('image/png');
    return true;
  };

  const loadLogo = () => new Promise((resolve, reject) => {
    const logo = new Image();
    logo.decoding = 'async';
    logo.onload = () => resolve(logo);
    logo.onerror = () => reject(new Error('logo'));
    // Cache-bust rarely — path is enough; avoid CORS taint on same-origin.
    logo.src = logoUrl;
  });

  const waitForQrSource = (attempt = 0) => new Promise((resolve) => {
    const source = getQrSource();
    if (source) {
      resolve(source);
      return;
    }
    if (attempt >= 40) {
      resolve(null);
      return;
    }
    window.setTimeout(() => {
      waitForQrSource(attempt + 1).then(resolve);
    }, 50);
  });

  const compositeLogo = async () => {
    const [source, logoResult] = await Promise.all([
      waitForQrSource(),
      loadLogo().then((logo) => ({ ok: true, logo })).catch(() => ({ ok: false, logo: null })),
    ]);
    if (!source) return;

    if (logoResult.ok && logoResult.logo) {
      paintWithLogo(source, logoResult.logo);
      return;
    }

    // Logo failed — keep plain QR downloadable.
    if (source.type === 'canvas') {
      mount.dataset.qrDataUrl = source.el.toDataURL('image/png');
    } else if (source.type === 'img') {
      mount.dataset.qrDataUrl = source.el.src;
    }
  };

  void compositeLogo();

  const btn = document.getElementById('btnDownloadEventQr');
  if (!btn) return;

  btn.addEventListener('click', () => {
    const canvas = mount.querySelector('canvas');
    const dataUrl = mount.dataset.qrDataUrl
      || (canvas ? canvas.toDataURL('image/png') : '');
    if (!dataUrl) return;

    const link = document.createElement('a');
    const safeTitle = String(title).replace(/[^\w\-]+/g, '_').slice(0, 40) || 'event';
    link.download = safeTitle + '_event_qr.png';
    link.href = dataUrl;
    link.click();
  });
})();
</script>
<script>
(() => {
  const btn = document.getElementById('btnEarlyOutToggle');
  const hint = document.getElementById('earlyOutHint');
  if (!btn) return;
  if (!window.CSRF_TOKEN) {
    btn.disabled = true;
    btn.setAttribute('aria-disabled', 'true');
    if (hint) hint.textContent = 'Early Out unavailable (session). Refresh the page.';
    return;
  }
  const eventId = btn.getAttribute('data-event-id') || '';
  if (!eventId) return;

  const usesSessions = btn.getAttribute('data-uses-sessions') === '1';
  const startAt = Date.parse(btn.getAttribute('data-start-at') || '');
  const endAt = Date.parse(btn.getAttribute('data-end-at') || '');
  const graceMinutes = Math.max(0, Number.parseInt(btn.getAttribute('data-grace-minutes') || '30', 10) || 30);
  let enabled = false;
  let expiresAt = null;
  let graceEndsAt = null;
  let sessionName = '';
  let canEnableFromServer = null;
  let loading = false;
  let statusReady = false;

  const graceEndsMs = () => {
    if (!Number.isFinite(startAt)) return NaN;
    return startAt + (graceMinutes * 60 * 1000);
  };

  // Clickable only after grace period ends, until event/seminar end.
  // For seminar events, trust server can_enable (auto-picked seminar).
  const canEnableEarlyOut = () => {
    if (canEnableFromServer === true) return true;
    if (canEnableFromServer === false) return false;
    if (usesSessions) return false;
    if (!Number.isFinite(startAt) || !Number.isFinite(endAt)) return false;
    const now = Date.now();
    const graceEnd = graceEndsMs();
    return Number.isFinite(graceEnd) && now >= graceEnd && now <= endAt;
  };

  const formatClock = (d) => {
    if (!(d instanceof Date) || Number.isNaN(d.getTime())) return '';
    // Always 12-hour (1:15 PM) — hosting/browser locales often default to 24h.
    return d.toLocaleTimeString('en-US', {
      hour: 'numeric',
      minute: '2-digit',
      hour12: true,
    });
  };

  const formatGraceHint = () => {
    const raw = graceEndsAt || (!usesSessions && Number.isFinite(graceEndsMs()) ? new Date(graceEndsMs()).toISOString() : '');
    if (!raw) {
      return 'Available only after the grace period ends.';
    }
    const d = new Date(raw);
    if (Number.isNaN(d.getTime())) {
      return 'Available only after the grace period ends.';
    }
    const clock = formatClock(d);
    if (sessionName) {
      return 'Available after ' + sessionName + ' grace ends (' + clock + ').';
    }
    return 'Available after grace ends (' + clock + ').';
  };

  const setInteractive = (interactive) => {
    btn.disabled = !interactive;
    btn.setAttribute('aria-disabled', interactive ? 'false' : 'true');
    btn.classList.toggle('pointer-events-none', !interactive);
    btn.classList.toggle('opacity-50', !interactive);
    btn.style.pointerEvents = interactive ? '' : 'none';
  };

  const applyUi = () => {
    const canEnable = canEnableEarlyOut();
    const label = enabled ? 'Disable' : 'Enable';
    btn.classList.toggle('bg-sky-600', !enabled);
    btn.classList.toggle('hover:bg-sky-700', !enabled && canEnable);
    btn.classList.toggle('bg-zinc-700', enabled);
    btn.classList.toggle('hover:bg-zinc-800', enabled);

    // Never clickable while loading, before first status, or when Enable is not allowed.
    const interactive = !loading && statusReady && (enabled || canEnable);
    setInteractive(interactive);

    if (loading) {
      btn.innerHTML = '<svg class="animate-spin h-4 w-4 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" aria-hidden="true"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path></svg><span>Saving…</span>';
    } else {
      btn.textContent = label;
    }

    if (!hint) return;
    if (!statusReady) {
      hint.textContent = 'Checking Early Out availability…';
      return;
    }
    if (enabled && expiresAt) {
      const d = new Date(expiresAt);
      const prefix = sessionName ? (sessionName + ' — ') : '';
      hint.textContent = prefix + 'Early Out ON — auto-off at ' + formatClock(d) + ' (1 hour from enable).';
    } else if (!canEnable) {
      hint.textContent = formatGraceHint();
    } else {
      hint.textContent = sessionName
        ? ('Opens time-out for ' + sessionName + ' for 1 hour from enable.')
        : 'Opens time-out for 1 hour from enable (not limited to event end).';
    }
  };

  const paint = (data) => {
    const status = (data && data.early_out) ? data.early_out : (data || {});
    enabled = !!(status && status.enabled);
    expiresAt = status && status.expires_at ? status.expires_at : null;
    graceEndsAt = status && status.grace_ends_at ? status.grace_ends_at : null;
    sessionName = (data && data.session_name) ? String(data.session_name).trim() : sessionName;
    if (status && typeof status.can_enable === 'boolean') {
      canEnableFromServer = status.can_enable;
    }
    statusReady = true;
    applyUi();
  };

  const call = async (payload) => {
    const res = await fetch('api/event_early_out.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        event_id: eventId,
        csrf_token: window.CSRF_TOKEN,
        ...payload,
      }),
    });
    return res.json();
  };

  // Locked until status returns — prevents click during page load.
  applyUi();

  call({ action: 'status' }).then((data) => {
    paint(data && data.ok ? data : {});
  }).catch(() => paint({}));

  window.setInterval(() => {
    if (!loading) {
      call({ action: 'status' }).then((data) => {
        if (data && data.ok) paint(data);
        else applyUi();
      }).catch(() => applyUi());
    }
  }, 30000);

  btn.addEventListener('click', async (e) => {
    e.preventDefault();
    e.stopPropagation();
    if (loading || !statusReady) return;
    if (!enabled && !canEnableEarlyOut()) {
      applyUi();
      return;
    }
    loading = true;
    applyUi();
    try {
      const data = await call({ action: 'set', enabled: !enabled });
      if (data && data.ok) {
        paint(data);
      } else {
        alert((data && data.error) || 'Failed to update Early Out.');
        applyUi();
      }
    } catch (err) {
      alert('Failed to update Early Out.');
      applyUi();
    } finally {
      loading = false;
      applyUi();
    }
  });
})();
</script>
<?php endif; ?>

<?php render_footer(); ?>
