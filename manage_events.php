<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/session.php';
session_bootstrap();

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/supabase.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/registration_access.php';
require_once __DIR__ . '/includes/event_sessions.php';
require_once __DIR__ . '/includes/proposal_requirements.php';
require_once __DIR__ . '/includes/student_requirements.php';
require_once __DIR__ . '/includes/manage_events_live.php';
require_once __DIR__ . '/includes/api_cache.php';

$user = require_role(['teacher', 'admin']);
$role = (string) ($user['role'] ?? 'teacher');
$userId = (string) ($user['id'] ?? '');

function events_missing_column_error(array $response): bool
{
  $body = strtolower((string) ($response['body'] ?? ''));
  if ($body === '') {
    return false;
  }

  return str_contains($body, 'events')
    && str_contains($body, 'column')
    && (
      str_contains($body, 'does not exist')
      || str_contains($body, 'schema cache')
      || str_contains($body, 'could not find')
    );
}

function build_manage_events_url(string $selectColumns, string $role, string $userId): string
{
  $base = rtrim(SUPABASE_URL, '/') . '/rest/v1/events?select=' . $selectColumns;
  if ($role === 'admin') {
    return $base . '&status=neq.archived&order=created_at.desc&limit=120';
  }

  // Teacher sees their own events OR any published events.
  return $base . '&or=(created_by.eq.' . $userId . ',status.eq.published)&order=created_at.desc&limit=120';
}

/**
 * Production events select (migrations through 045). Do not walk fallbacks —
 * each missing-column GET is a Postgres ERROR on the dashboard.
 */
function manage_events_production_select(): string
{
  return 'id,title,description,location,start_at,end_at,status,created_by,approved_by,created_at,updated_at,event_type,event_for,grace_time,event_span,event_mode,event_structure,is_free_event,event_fee,registration_limit,registration_close_weeks,cover_image_url,proposal_stage,requirements_requested_at,requirements_submitted_at,users:created_by(first_name,last_name,suffix)';
}

/**
 * @param list<array<string, mixed>> $events
 * @return array{0: list<string>, 1: list<string>}
 */
function manage_events_enrichment_ids(array $events, string $role, string $userId): array
{
  $proposalIds = [];
  $studentIds = [];

  foreach ($events as $event) {
    if (!is_array($event)) {
      continue;
    }
    $eventId = trim((string) ($event['id'] ?? ''));
    if ($eventId === '') {
      continue;
    }

    $status = strtolower(trim((string) ($event['status'] ?? '')));
    $createdBy = trim((string) ($event['created_by'] ?? ''));
    $proposalStage = strtolower(trim((string) ($event['proposal_stage'] ?? '')));

    // Proposal docs matter for pending / in-review flows, not every published campus event.
    $needsProposal = $status === 'pending'
      || in_array($proposalStage, ['pending_requirements', 'requirements_requested', 'under_review'], true);
    if ($needsProposal) {
      $proposalIds[] = $eventId;
    }

    // Student registration requirements on the list page: review pipeline only.
    // Skip bulk published campus events (open event_view for those details).
    $owns = $createdBy !== '' && $createdBy === $userId;
    $needsStudent = $owns || ($role === 'admin' && in_array($status, ['pending', 'approved'], true));
    if ($needsStudent && in_array($status, ['pending', 'approved'], true)) {
      $studentIds[] = $eventId;
    }
  }

  return [array_values(array_unique($proposalIds)), array_values(array_unique($studentIds))];
}

$headers = [
  'Accept: application/json',
  'apikey: ' . SUPABASE_KEY,
  'Authorization: Bearer ' . SUPABASE_KEY,
];

// Auto-finish events that have already ended (throttled to reduce DB writes).
pulse_auto_finish_published_events($headers);
pulse_auto_close_registration_windows($headers);

// Short TTL list cache — sidebar revisits should not re-query the full events set every time.
$listGen = api_cache_generation('manage_events');
$listCacheKey = 'manage_events_list_v2_g' . $listGen . '_' . $role . '_' . substr(hash('sha256', $userId), 0, 16);
$listCached = api_cache_remember($listCacheKey, 25, static function () use ($headers, $role, $userId): array {
  $workingSelect = manage_events_production_select();
  $eventsUrl = build_manage_events_url($workingSelect, $role, $userId);
  $res = supabase_request('GET', $eventsUrl, $headers);
  $events = [];
  if ($res['ok']) {
    $decoded = json_decode((string) $res['body'], true);
    $events = is_array($decoded) ? $decoded : [];
  } else {
    error_log('manage_events list select failed: ' . substr((string) ($res['body'] ?? ''), 0, 300));
    $workingSelect = '';
  }

  if (!empty($events)) {
    $events = attach_event_sessions_to_events($events, $headers);
  }

  return [
    'events' => $events,
    'select' => $workingSelect,
  ];
});

$events = is_array($listCached['events'] ?? null) ? $listCached['events'] : [];
$workingSelect = (string) ($listCached['select'] ?? '');

$proposalRequirementMap = [];
$proposalSubmissionMap = [];
$proposalVisibleSubmissionMap = [];
$proposalSummaryMap = [];
$studentRequirementMap = [];
if (!empty($events)) {
  [$proposalEventIds, $studentEventIds] = manage_events_enrichment_ids($events, $role, $userId);
  // Hard cap enrichment fan-out so a large catalog cannot stall the page.
  $proposalEventIds = array_slice($proposalEventIds, 0, 40);
  $studentEventIds = array_slice($studentEventIds, 0, 40);
  $proposalHeaders = proposal_requirement_headers();

  if ($proposalEventIds !== []) {
    $proposalRequirementMap = fetch_proposal_requirements_map($proposalEventIds, $proposalHeaders);
    $proposalSubmissionMap = fetch_proposal_submissions_map($proposalEventIds, $proposalHeaders);
    $proposalVisibleSubmissionMap = filter_visible_proposal_submissions_map($proposalSubmissionMap);
    foreach ($proposalEventIds as $eventId) {
      $proposalSummaryMap[$eventId] = build_proposal_requirement_summary(
        $proposalRequirementMap[$eventId] ?? [],
        $proposalSubmissionMap[$eventId] ?? []
      );
    }
  }

  if ($studentEventIds !== []) {
    $studentRequirementMap = fetch_student_requirements_map($studentEventIds, student_requirement_headers());
  }
}

$teacherAccounts = [];
if ($role === 'admin') {
  $teacherCache = api_cache_remember('manage_events_teachers', 120, static function () use ($headers): array {
    $teachersUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/users'
      . '?select=id,first_name,middle_name,last_name,suffix,email'
      . '&role=eq.teacher'
      . '&order=last_name.asc,first_name.asc';
    $teachersRes = supabase_request('GET', $teachersUrl, $headers);
    if (!$teachersRes['ok']) {
      return ['rows' => []];
    }
    $teacherRows = json_decode((string) $teachersRes['body'], true);
    return ['rows' => is_array($teacherRows) ? $teacherRows : []];
  });
  $teacherAccounts = is_array($teacherCache['rows'] ?? null) ? $teacherCache['rows'] : [];
}

render_header('Manage Events', $user);
?>

<style>
  /* ── Modal System ── */
  .modal-backdrop {
    position: fixed;
    inset: 0;
    z-index: 100;
    background: rgba(15, 23, 42, 0.38);
    backdrop-filter: blur(10px);
    -webkit-backdrop-filter: blur(10px);
    display: flex;
    align-items: flex-end;
    justify-content: center;
    opacity: 0;
    pointer-events: none;
    transition: opacity 0.25s ease;
    padding: 0;
  }

  @media (min-width: 640px) {
    .modal-backdrop {
      align-items: center;
      padding: 1.5rem;
    }
  }

  .modal-backdrop.active {
    opacity: 1;
    pointer-events: auto;
  }

  /* ── Event Wizard Panel ── */
  .modal-panel {
    width: 100%;
    max-width: 520px;
    border-radius: 1.5rem 1.5rem 0 0;
    border: 1px solid #e4e4e7;
    border-bottom: none;
    background: #ffffff;
    box-shadow: 0 -8px 40px rgba(15, 23, 42, 0.12), 0 0 1px rgba(15, 23, 42, 0.06);
    padding: 0;
    transform: translateY(100%);
    transition: transform 0.4s cubic-bezier(0.16, 1, 0.3, 1);
    max-height: 92vh;
    overflow: hidden;
    display: flex;
    flex-direction: column;
  }

  @media (min-width: 640px) {
    .modal-panel {
      border-radius: 1.5rem;
      border-bottom: 1px solid #e4e4e7;
      transform: translateY(30px) scale(0.96);
      max-height: 85vh;
    }
  }

  .modal-backdrop.active .modal-panel {
    transform: translateY(0) scale(1);
  }

  /* ── Confirm Panel ── */
  .confirm-panel {
    width: 100%;
    max-width: 400px;
    border-radius: 1.5rem 1.5rem 0 0;
    border: 1px solid #e4e4e7;
    border-bottom: none;
    background: #ffffff;
    box-shadow: 0 -8px 40px rgba(15, 23, 42, 0.12);
    padding: 2rem 1.5rem;
    transform: translateY(100%);
    transition: transform 0.4s cubic-bezier(0.16, 1, 0.3, 1);
    text-align: center;
  }

  @media (min-width: 640px) {
    .confirm-panel {
      border-radius: 1.5rem;
      border-bottom: 1px solid #e4e4e7;
      transform: translateY(30px) scale(0.96);
    }
  }

  .modal-backdrop.active .confirm-panel {
    transform: translateY(0) scale(1);
  }

  /* ── Stepper ── */
  .wizard-stepper {
    display: flex;
    align-items: center;
    gap: 0;
    width: 100%;
  }

  .wizard-step {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    flex: 1;
    position: relative;
  }

  .wizard-step:not(:last-child)::after {
    content: '';
    flex: 1;
    height: 2px;
    background: #e4e4e7;
    margin: 0 0.5rem;
    border-radius: 1px;
    transition: background 0.3s ease;
  }

  .wizard-step.completed:not(:last-child)::after {
    background: rgba(139, 92, 246, 0.5);
  }

  .step-dot {
    width: 28px;
    height: 28px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 11px;
    font-weight: 600;
    border: 2px solid #d4d4d8;
    background: #fafafa;
    color: #71717a;
    transition: all 0.3s ease;
    flex-shrink: 0;
  }

  .wizard-step.active .step-dot {
    border-color: #7c3aed;
    background: #f5f3ff;
    color: #5b21b6;
    box-shadow: 0 0 0 3px rgba(124, 58, 237, 0.15);
  }

  .wizard-step.completed .step-dot {
    border-color: #7c3aed;
    background: #ede9fe;
    color: #6d28d9;
  }

  .step-label {
    font-size: 11px;
    font-weight: 500;
    color: #a1a1aa;
    transition: color 0.3s ease;
    white-space: nowrap;
  }

  .wizard-step.active .step-label {
    color: #3f3f46;
  }

  .wizard-step.completed .step-label {
    color: #7c3aed;
  }

  /* ── Form field icons ── */
  .field-icon-wrap {
    position: relative;
  }

  .field-icon-wrap .field-icon {
    position: absolute;
    left: 12px;
    top: 50%;
    transform: translateY(-50%);
    width: 16px;
    height: 16px;
    color: #a1a1aa;
    pointer-events: none;
  }

  .field-icon-wrap input,
  .field-icon-wrap textarea {
    padding-left: 2.25rem;
  }

  /* Hide scrollbar but keep scrolling */
  .modal-body {
    overflow-y: auto;
    -ms-overflow-style: none;
    scrollbar-width: none;
  }

  .modal-body::-webkit-scrollbar {
    display: none;
  }

  /* ── Speech-to-Text Mic Button ── */
  .stt-wrapper {
    position: relative;
  }

  .stt-btn {
    position: absolute;
    right: 10px;
    top: 10px;
    width: 36px;
    height: 36px;
    border-radius: 50%;
    border: 1.5px solid #e4e4e7;
    background: #fafafa;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    transition: all 0.2s ease;
    z-index: 5;
    color: #71717a;
  }

  .stt-btn:hover {
    background: #f4f4f5;
    border-color: #d4d4d8;
    color: #3f3f46;
  }

  .stt-btn.recording {
    background: #fef2f2;
    border-color: #fca5a5;
    color: #dc2626;
    animation: stt-pulse 1.5s infinite ease-in-out;
  }

  .stt-btn svg {
    width: 18px;
    height: 18px;
  }

  @keyframes stt-pulse {

    0%,
    100% {
      box-shadow: 0 0 0 0 rgba(239, 68, 68, 0.35);
    }

    50% {
      box-shadow: 0 0 0 8px rgba(239, 68, 68, 0);
    }
  }

  .stt-status {
    font-size: 11px;
    font-weight: 500;
    margin-top: 6px;
    min-height: 16px;
    transition: color 0.2s;
  }

  .stt-status.idle {
    color: #a1a1aa;
  }

  .stt-status.listening {
    color: #dc2626;
  }

  .stt-status.done {
    color: #16a34a;
  }

  /* ── STT Preview Modal ── */
  .stt-preview-panel {
    width: 100%;
    max-width: 520px;
    border-radius: 1.5rem;
    border: 1px solid #e4e4e7;
    background: #fff;
    box-shadow: 0 -8px 40px rgba(15, 23, 42, 0.12);
    padding: 0;
    transform: translateY(30px) scale(0.96);
    transition: transform 0.4s cubic-bezier(0.16, 1, 0.3, 1);
    max-height: 85vh;
    overflow: hidden;
    display: flex;
    flex-direction: column;
  }

  .modal-backdrop.active .stt-preview-panel {
    transform: translateY(0) scale(1);
  }

  .stt-tab {
    padding: 0.5rem 1rem;
    font-size: 12px;
    font-weight: 600;
    border-radius: 0.6rem;
    cursor: pointer;
    transition: all 0.2s;
    border: 1.5px solid transparent;
    color: #71717a;
    background: transparent;
  }

  .stt-tab.active {
    color: #ea580c;
    background: #fff7ed;
    border-color: #fb923c;
  }

  .stt-tab:hover:not(.active) {
    color: #3f3f46;
    background: #f4f4f5;
  }

  .stt-preview-textarea {
    width: 100%;
    min-height: 140px;
    border: 1.5px solid #e4e4e7;
    border-radius: 0.75rem;
    padding: 0.75rem 1rem;
    font-size: 14px;
    line-height: 1.7;
    color: #18181b;
    resize: vertical;
    outline: none;
    transition: border-color 0.2s;
  }

  .stt-preview-textarea:focus {
    border-color: #fb923c;
    box-shadow: 0 0 0 3px rgba(249, 115, 22, 0.12);
  }

  .stt-diff-highlight {
    background: #dcfce7;
    border-radius: 2px;
    padding: 0 1px;
  }

  .stt-improve-badge {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    font-size: 10px;
    font-weight: 600;
    padding: 3px 8px;
    border-radius: 999px;
    background: linear-gradient(135deg, #fff7ed, #fef3c7);
    border: 1px solid #fed7aa;
    color: #c2410c;
  }

  @keyframes wizardStepErrorFlash {
    0%, 100% { transform: translateX(0); }
    20% { transform: translateX(-4px); }
    40% { transform: translateX(4px); }
    60% { transform: translateX(-3px); }
    80% { transform: translateX(3px); }
  }

  #wizardFooterStatus.wizard-step-error-flash {
    animation: wizardStepErrorFlash 0.45s ease-in-out;
  }
</style>



<!-- ═══════════  EVENT WIZARD MODAL  ═══════════ -->
<!-- Custom Date/Time Picker (restored) -->
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
<style>
  .flatpickr-calendar {
    border-radius: 1rem !important;
    border: 1px solid #e4e4e7 !important;
    box-shadow: 0 18px 36px rgba(15, 23, 42, 0.16) !important;
    z-index: 9999 !important;
  }

  .flatpickr-day.selected {
    background: #ea580c !important;
    border-color: #ea580c !important;
  }

  .flatpickr-input[readonly] {
    background-color: #fff !important;
    cursor: pointer;
  }

  .flatpickr-input[disabled] {
    background-color: #f4f4f5 !important;
    cursor: not-allowed;
  }
</style>

<div id="eventModal" class="modal-backdrop">
  <div class="modal-panel">

    <!-- Header -->
    <div class="flex items-center justify-between px-5 sm:px-6 pt-5 sm:pt-6 pb-4 border-b border-zinc-200">
      <div class="flex items-center gap-3">
        <div
          class="w-10 h-10 rounded-xl bg-gradient-to-br from-orange-600/25 to-red-600/25 border border-orange-500/20 flex items-center justify-center">
          <svg class="w-5 h-5 text-orange-400" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
          </svg>
        </div>
        <div>
          <div class="text-base font-semibold text-zinc-900" id="modalTitle">Create Event</div>
          <div class="text-[11px] text-zinc-500" id="modalSubtitle">Fill in the details below</div>
        </div>
      </div>
      <button id="btnCloseModal" class="p-2 rounded-xl text-zinc-500 hover:text-zinc-900 hover:bg-zinc-100 transition">
        <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
        </svg>
      </button>
    </div>

    <!-- Body -->
    <div class="modal-body px-5 sm:px-6 py-5">
      <form id="eventForm" class="space-y-3">
        <input type="hidden" name="mode" id="mode" value="create" />
        <input type="hidden" name="event_id" id="event_id" value="" />
        <input type="hidden" name="event_mode" id="event_mode" value="simple" />
        <input type="hidden" name="seminar_count" id="seminar_count" value="0" />

        <!-- Stepper -->
        <div class="wizard-stepper mb-2">
          <div class="wizard-step active" id="ws1">
            <div class="step-dot">1</div>
            <span class="step-label hidden sm:inline">Info</span>
          </div>
          <div class="wizard-step" id="ws2">
            <div class="step-dot">2</div>
            <span class="step-label hidden sm:inline">Details</span>
          </div>
          <div class="wizard-step" id="ws3">
            <div class="step-dot">3</div>
            <span class="step-label hidden sm:inline">Schedule</span>
          </div>
          <?php if ($role === 'teacher'): ?>
          <div class="wizard-step" id="ws4">
            <div class="step-dot">4</div>
            <span class="step-label hidden sm:inline">Requirements</span>
          </div>
          <?php endif; ?>
        </div>

        <!-- Step 1: Info -->
        <div id="step1" class="space-y-4">
          <div>
            <label class="block text-xs text-zinc-600 mb-1.5 font-medium tracking-wide">Event Cover Image</label>
            <p class="text-[11px] text-zinc-500 mb-2">
              Mobile Event Details header background. Must be <strong>16:9 landscape</strong>
              (e.g. 1600×900) · JPG, PNG, or WEBP · max 5MB.
            </p>
            <input type="hidden" id="cover_image_url" value="" />
            <div id="coverPreviewWrap" class="relative mb-2 hidden overflow-hidden rounded-xl border border-zinc-200 bg-zinc-50 aspect-[16/9]">
              <img id="coverPreviewImg" src="" alt="Cover preview" class="absolute inset-0 h-full w-full object-cover" />
              <div id="coverPreviewLoading" class="absolute inset-0 hidden items-center justify-center bg-zinc-100/90">
                <div class="flex flex-col items-center gap-2">
                  <span class="inline-block h-6 w-6 animate-spin rounded-full border-2 border-orange-400 border-t-transparent"></span>
                  <span class="text-[11px] font-medium text-zinc-600">Checking image…</span>
                </div>
              </div>
              <button type="button" id="btnClearCover" class="absolute right-2 top-2 rounded-lg bg-white/90 px-2.5 py-1 text-[11px] font-semibold text-zinc-700 shadow-sm hover:bg-white">
                Remove
              </button>
            </div>
            <p id="coverAspectHint" class="mb-2 hidden text-[11px] font-medium text-rose-600"></p>
            <label for="cover_file" class="flex cursor-pointer items-center justify-center gap-2 rounded-xl border border-dashed border-zinc-300 bg-white px-4 py-3 text-sm font-medium text-zinc-700 transition hover:border-orange-400 hover:bg-orange-50/40">
              <svg class="h-4 w-4 text-orange-500" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5m-13.5-9L12 3m0 0l4.5 4.5M12 3v13.5" />
              </svg>
              <span id="coverFileLabel">Upload cover image (16:9)</span>
            </label>
            <input id="cover_file" type="file" accept="image/jpeg,image/png,image/webp" class="hidden" />
          </div>
          <div>
            <label class="block text-xs text-zinc-600 mb-1.5 font-medium tracking-wide">Event Title</label>
            <div class="field-icon-wrap">
              <svg class="field-icon" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round"
                  d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931zm0 0L19.5 7.125M18 14v4.75A2.25 2.25 0 0115.75 21H5.25A2.25 2.25 0 013 18.75V8.25A2.25 2.25 0 015.25 6H10" />
              </svg>
              <input id="title" name="title" required
                class="w-full rounded-xl bg-white border border-zinc-200 py-3 text-sm text-zinc-900 outline-none focus:ring-2 focus:ring-orange-500/30 focus:border-orange-400 transition placeholder:text-zinc-400"
                placeholder="e.g. CCS Summit 2026" />
            </div>
          </div>
          <div>
            <label class="block text-xs text-zinc-600 mb-1.5 font-medium tracking-wide">Location</label>
            <div class="field-icon-wrap">
              <svg class="field-icon" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" d="M15 10.5a3 3 0 11-6 0 3 3 0 016 0z" />
                <path stroke-linecap="round" stroke-linejoin="round"
                  d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1115 0z" />
              </svg>
              <input id="location" name="location"
                class="w-full rounded-xl bg-white border border-zinc-200 py-3 text-sm text-zinc-900 outline-none focus:ring-2 focus:ring-orange-500/30 focus:border-orange-400 transition placeholder:text-zinc-400"
                placeholder="e.g. CCS Auditorium" />
            </div>
          </div>

          <!-- NEW: Event Type & Target -->
          <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
            <div>
              <label class="block text-xs text-zinc-600 mb-1.5 font-medium tracking-wide">Event Type</label>
              <select id="event_type" name="event_type"
                class="w-full rounded-xl bg-white border border-zinc-200 py-3 px-[38px] text-sm text-zinc-900 outline-none focus:ring-2 focus:ring-orange-500/30 focus:border-orange-400 transition appearance-none">
                <option value="Event" selected>Event</option>
                <option value="Seminar">Seminar</option>
                <option value="Off-Campus Activity">Off-Campus Activity</option>
                <option value="Sports Event">Sports Event</option>
                <option value="Other">Other</option>
              </select>
              <div id="event_type_other_wrap" class="mt-2 hidden">
                <label class="block text-xs text-zinc-600 mb-1.5 font-medium tracking-wide" for="event_type_other">Specify event type</label>
                <input id="event_type_other" name="event_type_other" type="text" maxlength="80"
                  class="w-full rounded-xl bg-white border border-zinc-200 py-3 px-4 text-sm text-zinc-900 outline-none focus:ring-2 focus:ring-orange-500/30 focus:border-orange-400 transition placeholder:text-zinc-400"
                  placeholder="e.g. Hackathon" autocomplete="off" />
              </div>
            </div>
            <div>
              <label class="block text-xs text-zinc-600 mb-1.5 font-medium tracking-wide">Target Course</label>
              <select id="target_course" name="target_course"
                class="w-full rounded-xl bg-white border border-zinc-200 py-3 px-[38px] text-sm text-zinc-900 outline-none focus:ring-2 focus:ring-orange-500/30 focus:border-orange-400 transition appearance-none">
                <option value="ALL" selected>All Courses</option>
                <option value="BSIT">BSIT (All)</option>
                <option value="BSIT-SD">BSIT-SD</option>
                <option value="BSIT-BA">BSIT-BA</option>
                <option value="BSCS">BSCS</option>
              </select>
            </div>
          </div>
          <div>
            <label class="block text-xs text-zinc-600 mb-1.5 font-medium tracking-wide">Target Year Level</label>
            <div id="target_year_group" class="flex flex-wrap items-center gap-2 rounded-xl border border-zinc-200 bg-white p-2">
              <label class="inline-flex items-center gap-2 rounded-lg px-2.5 py-1.5 hover:bg-zinc-50 cursor-pointer border border-zinc-200 bg-zinc-50">
                <input type="checkbox" class="target-year-checkbox rounded border-zinc-300 text-orange-600 focus:ring-orange-500" value="ALL" checked />
                <span class="text-sm font-semibold text-zinc-700 whitespace-nowrap">All Levels</span>
              </label>
              <label class="inline-flex items-center gap-2 rounded-lg px-2.5 py-1.5 hover:bg-zinc-50 cursor-pointer border border-zinc-200">
                <input type="checkbox" class="target-year-checkbox rounded border-zinc-300 text-orange-600 focus:ring-orange-500" value="1" />
                <span class="text-sm font-semibold text-zinc-700 whitespace-nowrap">1st Year</span>
              </label>
              <label class="inline-flex items-center gap-2 rounded-lg px-2.5 py-1.5 hover:bg-zinc-50 cursor-pointer border border-zinc-200">
                <input type="checkbox" class="target-year-checkbox rounded border-zinc-300 text-orange-600 focus:ring-orange-500" value="2" />
                <span class="text-sm font-semibold text-zinc-700 whitespace-nowrap">2nd Year</span>
              </label>
              <label class="inline-flex items-center gap-2 rounded-lg px-2.5 py-1.5 hover:bg-zinc-50 cursor-pointer border border-zinc-200">
                <input type="checkbox" class="target-year-checkbox rounded border-zinc-300 text-orange-600 focus:ring-orange-500" value="3" />
                <span class="text-sm font-semibold text-zinc-700 whitespace-nowrap">3rd Year</span>
              </label>
              <label class="inline-flex items-center gap-2 rounded-lg px-2.5 py-1.5 hover:bg-zinc-50 cursor-pointer border border-zinc-200">
                <input type="checkbox" class="target-year-checkbox rounded border-zinc-300 text-orange-600 focus:ring-orange-500" value="4" />
                <span class="text-sm font-semibold text-zinc-700 whitespace-nowrap">4th Year</span>
              </label>
            </div>
          </div>

          <div>
            <label class="block text-xs text-zinc-600 mb-1.5 font-medium tracking-wide">Registration Type</label>
            <div class="space-y-2">
              <label class="inline-flex items-center gap-3 rounded-xl border border-zinc-200 bg-white px-4 py-3 cursor-pointer hover:bg-zinc-50 transition w-full">
                <input type="checkbox" id="registration_type_free" class="registration-type-checkbox rounded border-zinc-300 text-orange-600 focus:ring-orange-500" data-value="free" checked />
                <span class="min-w-0">
                  <span class="block text-sm font-semibold text-zinc-800">Free Event</span>
                  <span class="block text-[11px] text-zinc-500 leading-snug">When published, students can register immediately.</span>
                </span>
              </label>
              <label class="inline-flex items-center gap-3 rounded-xl border border-zinc-200 bg-white px-4 py-3 cursor-pointer hover:bg-zinc-50 transition w-full">
                <input type="checkbox" id="registration_type_paid" class="registration-type-checkbox rounded border-zinc-300 text-orange-600 focus:ring-orange-500" data-value="paid" />
                <span class="min-w-0">
                  <span class="block text-sm font-semibold text-zinc-800">Paid Event</span>
                  <span class="block text-[11px] text-zinc-500 leading-snug">Students need payment approval before they can register.</span>
                </span>
              </label>
            </div>

            <div id="event_fee_wrap" class="mt-3 hidden">
              <label for="event_fee" class="block text-xs text-zinc-600 mb-1.5 font-medium tracking-wide">Settlement Amount (₱)</label>
              <div class="relative">
                <span class="absolute left-3 top-1/2 -translate-y-1/2 text-sm font-bold text-zinc-400">₱</span>
                <input type="number" id="event_fee" name="event_fee" min="1" step="0.01" inputmode="decimal"
                  class="w-full rounded-xl bg-white border border-zinc-200 py-3 pl-8 pr-4 text-sm text-zinc-900 outline-none focus:ring-2 focus:ring-orange-500/30 focus:border-orange-400 transition"
                  placeholder="e.g. 250.00" />
              </div>
              <p class="mt-1 text-[11px] text-zinc-500">Full amount students must settle for this event. Shown in the app and used on the Payments tab.</p>
            </div>

            <div class="mt-3">
              <label for="registration_limit" class="block text-xs text-zinc-600 mb-1.5 font-medium tracking-wide">Student Limit</label>
              <input type="number" id="registration_limit" name="registration_limit" min="1" max="9999" step="1" inputmode="numeric" pattern="[0-9]*"
                class="w-full rounded-xl bg-white border border-zinc-200 py-3 px-4 text-sm text-zinc-900 outline-none focus:ring-2 focus:ring-orange-500/30 focus:border-orange-400 transition"
                placeholder="e.g. 50 (max 9999)" />
              <p class="mt-1 text-[11px] text-zinc-500">Registration closes automatically once this number of students have registered. Maximum 4 digits (9999).</p>
            </div>
          </div>

          <div class="pt-2">
            <label class="block text-xs text-zinc-600 mb-2 font-medium tracking-wide">Event Structure</label>
            <div class="grid grid-cols-1 gap-3">
              <button type="button"
                class="structure-option group rounded-2xl border border-orange-300 bg-orange-50/70 p-4 text-left transition-all shadow-sm"
                data-mode="simple" data-seminars="0">
                <div class="flex items-start justify-between gap-3">
                  <div>
                    <div class="text-[13px] font-bold text-zinc-900 group-hover:text-orange-700 transition-colors">
                      Simple Event</div>
                    <p class="mt-1 text-[11px] leading-snug text-zinc-500">One event, one schedule, one attendance flow.
                      This keeps the existing logic intact.</p>
                  </div>
                  <span
                    class="structure-badge rounded-full border border-orange-300 bg-white px-2.5 py-1 text-[10px] font-black uppercase tracking-wide text-orange-700">Default</span>
                </div>
              </button>

              <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                <button type="button"
                  class="structure-option group rounded-2xl border border-zinc-200 bg-white p-4 text-left transition-all shadow-sm hover:border-orange-300 hover:bg-orange-50/40"
                  data-mode="seminar_based" data-seminars="1">
                  <div class="text-[13px] font-bold text-zinc-900 group-hover:text-orange-700 transition-colors">1
                    Seminar</div>
                  <p class="mt-1 text-[11px] leading-snug text-zinc-500">Single seminar session with its own title and
                    schedule.</p>
                </button>

                <button type="button"
                  class="structure-option group rounded-2xl border border-zinc-200 bg-white p-4 text-left transition-all shadow-sm hover:border-orange-300 hover:bg-orange-50/40"
                  data-mode="seminar_based" data-seminars="2">
                  <div class="text-[13px] font-bold text-zinc-900 group-hover:text-orange-700 transition-colors">2
                    Seminars</div>
                  <p class="mt-1 text-[11px] leading-snug text-zinc-500">Two seminar sessions under one event, each with
                    separate attendance tracking.</p>
                </button>
              </div>
            </div>
          </div>
        </div>

        <!-- Step 2: Details -->
        <div id="step2" class="space-y-4 hidden">
          <div>
            <label
              class="block text-xs text-zinc-600 mb-1.5 font-medium tracking-wide flex items-center justify-between">
              <span>Description</span>
              <span class="text-[10px] text-zinc-400 font-normal">Click the mic to dictate</span>
            </label>
            <div class="stt-wrapper">
              <textarea id="description" name="description" rows="5"
                class="w-full rounded-xl bg-white border border-zinc-200 px-4 py-3 pr-14 text-sm text-zinc-900 outline-none focus:ring-2 focus:ring-orange-500/30 focus:border-orange-400 resize-none placeholder:text-zinc-400 transition-all duration-300"
                placeholder="Tell attendees what this event is about..."></textarea>
              <button type="button" id="sttBtn" class="stt-btn" title="Dictate Description">
                <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round"
                    d="M12 18.75a6 6 0 006-6v-1.5m-6 7.5a6 6 0 01-6-6v-1.5m6 7.5v3.75m-3.75 0h7.5M12 15.75a3 3 0 01-3-3V4.5a3 3 0 116 0v8.25a3 3 0 01-3 3z" />
                </svg>
              </button>
            </div>

            <!-- Main Textarea Toolbelt -->
            <div class="flex items-center justify-between mt-1.5 px-1">
              <span id="mainAiStatus" class="hidden text-[11px] text-orange-600 font-medium whitespace-nowrap"></span>
              <div class="flex items-center justify-end gap-3 ml-auto">
                <button type="button" id="mainUndoBtn"
                  class="hidden text-[11px] text-zinc-500 hover:text-zinc-800 font-semibold transition-colors outline-none flex items-center gap-1">
                  ↶ Undo
                </button>
                <button type="button" id="mainExpandBtn"
                  class="text-[11px] text-zinc-500 hover:text-zinc-800 font-semibold transition-colors outline-none flex items-center gap-1">
                  ⤢ Expand
                </button>
                <button type="button" id="mainAiImproveBtn"
                  class="text-[11px] text-orange-600 hover:text-orange-700 font-bold transition-all outline-none flex items-center gap-1.5 bg-gradient-to-r from-orange-50 to-red-50 hover:from-orange-100 hover:to-red-100 px-3 py-1.5 rounded-lg border border-orange-200/60 shadow-sm">
                  ✨ AI Improve
                </button>
              </div>
            </div>
          </div>


        </div>



        <!-- Step 3: Schedule -->
        <div id="step3" class="space-y-4 hidden pb-4">
          <div>
            <label class="block text-xs text-zinc-600 mb-1.5 font-medium tracking-wide">Grace Time (Minutes)</label>
            <div class="field-icon-wrap">
              <svg class="field-icon" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round"
                  d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z" />
              </svg>
              <input id="grace_time" name="grace_time" type="number" min="0" value="30"
                class="w-full rounded-xl bg-white border border-zinc-200 py-3 text-sm text-zinc-900 outline-none focus:ring-2 focus:ring-orange-500/30 focus:border-orange-400 transition" />
            </div>
          </div>

          <div id="simpleScheduleSection" class="space-y-4">
            <div>
              <label class="block text-xs text-zinc-600 mb-1.5 font-medium tracking-wide">Start Date & Time</label>
              <div class="field-icon-wrap">
                <svg class="field-icon" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round"
                    d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 11.25v7.5" />
                </svg>
                <input id="start_at_local" name="start_at_local" type="text" placeholder="Select Start Date & Time..."
                  class="datetime-picker w-full rounded-xl bg-white border border-zinc-200 py-3 text-sm text-zinc-900 outline-none focus:ring-2 focus:ring-orange-500/30 focus:border-orange-400 transition" />
              </div>
            </div>
            <div>
              <label class="block text-xs text-zinc-600 mb-1.5 font-medium tracking-wide">End Date & Time</label>
              <div class="field-icon-wrap">
                <svg class="field-icon" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round"
                    d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
                <input id="end_at_local" name="end_at_local" type="text" placeholder="Select End Date & Time..."
                  class="datetime-picker w-full rounded-xl bg-zinc-50 border border-zinc-200 py-3 text-sm text-zinc-500 outline-none cursor-not-allowed transition" />
              </div>
            </div>
          </div>

          <div id="seminarScheduleSection"
            class="hidden rounded-2xl border border-orange-200 bg-orange-50/60 p-4 space-y-4">
            <div class="flex items-start justify-between gap-4">
              <div>
                <div class="text-xs font-bold uppercase tracking-wide text-orange-700">Seminar Sessions</div>
                <p class="mt-1 text-[11px] leading-snug text-orange-700/80">Each seminar keeps its own attendance and
                  evaluation flow. The parent event window will auto-sync from these schedules.</p>
              </div>
              <span id="seminarSummaryBadge"
                class="inline-flex items-center whitespace-nowrap rounded-full border border-orange-300 bg-white px-3 py-1 text-[11px] leading-none font-black uppercase tracking-wide text-orange-700">1
                Seminar</span>
            </div>

            <div id="seminar1Editor" class="rounded-xl border border-orange-200 bg-white p-4 space-y-3">
              <div class="text-[11px] font-bold uppercase tracking-wide text-zinc-600">Seminar 1</div>
              <div>
                <label class="block text-[11px] text-zinc-600 mb-1 font-medium">Title</label>
                <input id="seminar1_title" type="text"
                  class="w-full rounded-lg border border-zinc-200 px-3 py-2 text-sm text-zinc-900 outline-none focus:ring-2 focus:ring-orange-500/30 focus:border-orange-400"
                  placeholder="Seminar 1 title" />
              </div>
              <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                <div>
                  <label class="block text-[11px] text-zinc-600 mb-1 font-medium">Start Date & Time</label>
                  <input id="seminar1_start_local" type="text" placeholder="Select start date & time..."
                    class="datetime-picker w-full rounded-lg border border-zinc-200 px-3 py-2 text-sm text-zinc-900 outline-none focus:ring-2 focus:ring-orange-500/30 focus:border-orange-400" />
                </div>
                <div>
                  <label class="block text-[11px] text-zinc-600 mb-1 font-medium">End Date & Time</label>
                  <input id="seminar1_end_local" type="text" placeholder="Select end date & time..."
                    class="datetime-picker w-full rounded-lg border border-zinc-200 px-3 py-2 text-sm text-zinc-500 bg-zinc-50 outline-none cursor-not-allowed transition" />
                </div>
              </div>
            </div>

            <div id="seminar2Editor" class="hidden rounded-xl border border-orange-200 bg-white p-4 space-y-3">
              <div class="flex flex-wrap items-center justify-between gap-2">
                <div class="text-[11px] font-bold uppercase tracking-wide text-zinc-600">Seminar 2</div>
                <p id="seminar2LockHint" class="hidden text-[11px] font-medium text-amber-700">
                  Fill Seminar 1 title, start, and end first. Dates before Seminar 1 end are disabled.
                </p>
              </div>
              <div>
                <label class="block text-[11px] text-zinc-600 mb-1 font-medium">Title</label>
                <input id="seminar2_title" type="text"
                  class="w-full rounded-lg border border-zinc-200 px-3 py-2 text-sm text-zinc-900 outline-none focus:ring-2 focus:ring-orange-500/30 focus:border-orange-400"
                  placeholder="Seminar 2 title" disabled />
              </div>
              <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                <div>
                  <label class="block text-[11px] text-zinc-600 mb-1 font-medium">Start Date & Time</label>
                  <input id="seminar2_start_local" type="text" placeholder="Select start date & time..."
                    class="datetime-picker w-full rounded-lg border border-zinc-200 px-3 py-2 text-sm text-zinc-900 outline-none focus:ring-2 focus:ring-orange-500/30 focus:border-orange-400" />
                </div>
                <div>
                  <label class="block text-[11px] text-zinc-600 mb-1 font-medium">End Date & Time</label>
                  <input id="seminar2_end_local" type="text" placeholder="Select end date & time..."
                    class="datetime-picker w-full rounded-lg border border-zinc-200 px-3 py-2 text-sm text-zinc-500 bg-zinc-50 outline-none cursor-not-allowed transition" />
                </div>
              </div>
            </div>
          </div>

          <div>
            <label for="registration_close_weeks" class="block text-xs text-zinc-600 mb-1.5 font-medium tracking-wide">Registration Close Limit</label>
            <select id="registration_close_weeks" name="registration_close_weeks"
              class="w-full rounded-xl bg-white border border-zinc-200 py-3 px-4 text-sm text-zinc-900 outline-none focus:ring-2 focus:ring-orange-500/30 focus:border-orange-400 transition appearance-none">
              <option value="">Select event start date first</option>
            </select>
            <p id="registrationCloseWeeksHint" class="mt-1.5 text-[11px] text-zinc-500 leading-relaxed">
              Options update from the event start date vs today (maximum 4 weeks before start, and never before today).
            </p>
          </div>
        </div>

        <?php if ($role === 'teacher'): ?>
        <div id="step4" class="space-y-5 hidden pb-4">
          <div class="rounded-2xl border border-zinc-200 bg-white p-4 shadow-sm space-y-4">
            <div class="flex items-center gap-2">
              <span class="inline-flex items-center rounded-full border border-orange-200 bg-orange-50 px-3 py-1 text-[11px] font-bold uppercase tracking-wide text-orange-700">Event Req</span>
              <span class="text-[11px] font-semibold text-zinc-500">Required for admin review</span>
            </div>
            <p class="text-[12px] leading-relaxed text-zinc-600">
              Upload the required proposal forms for admin review before your event can be published.
            </p>

          <div id="teacherProposalCreateSection">
          <div id="teacherProposalRequirementsList" class="space-y-3">
            <div class="teacher-proposal-item rounded-2xl border border-zinc-200 bg-zinc-50/70 p-4 shadow-sm"
              data-requirement-code="LU-AA-FO-113"
              data-default-label="LU-AA-FO-113(ACTIVITY PROPOSAL FORM)"
              data-required="1">
              <div class="flex items-start justify-between gap-3">
                <div>
                  <div class="text-sm font-bold text-zinc-900">LU-AA-FO-113(ACTIVITY PROPOSAL FORM)</div>
                  <div class="mt-1 text-[11px] text-zinc-500">Required default document</div>
                </div>
                <span class="rounded-full border border-orange-200 bg-orange-50 px-2.5 py-1 text-[10px] font-bold uppercase tracking-wide text-orange-700">Default</span>
              </div>
              <label class="mt-3 block text-xs font-medium text-zinc-600">Upload Image File</label>
              <input type="file" accept=".pdf,.doc,.docx,image/jpeg,image/png,image/webp"
                class="proposal-file-input mt-1 block w-full rounded-xl border border-zinc-200 bg-white px-3 py-2 text-sm text-zinc-700 file:mr-3 file:rounded-lg file:border-0 file:bg-orange-50 file:px-3 file:py-2 file:text-sm file:font-semibold file:text-orange-700 hover:file:bg-orange-100" />
            </div>

            <div class="teacher-proposal-item rounded-2xl border border-zinc-200 bg-zinc-50/70 p-4 shadow-sm"
              data-requirement-code="LU-AA-FO-108"
              data-default-label="LU-AA-FO-108(ANNUAL PROPOSAL PLAN)"
              data-required="1">
              <div class="flex items-start justify-between gap-3">
                <div>
                  <div class="text-sm font-bold text-zinc-900">LU-AA-FO-108(ANNUAL PROPOSAL PLAN)</div>
                  <div class="mt-1 text-[11px] text-zinc-500">Required default document</div>
                </div>
                <span class="rounded-full border border-orange-200 bg-orange-50 px-2.5 py-1 text-[10px] font-bold uppercase tracking-wide text-orange-700">Default</span>
              </div>
              <label class="mt-3 block text-xs font-medium text-zinc-600">Upload Image File</label>
              <input type="file" accept=".pdf,.doc,.docx,image/jpeg,image/png,image/webp"
                class="proposal-file-input mt-1 block w-full rounded-xl border border-zinc-200 bg-white px-3 py-2 text-sm text-zinc-700 file:mr-3 file:rounded-lg file:border-0 file:bg-orange-50 file:px-3 file:py-2 file:text-sm file:font-semibold file:text-orange-700 hover:file:bg-orange-100" />
            </div>
          </div>

          <button type="button" id="btnAddTeacherProposalRequirement"
            class="inline-flex items-center gap-2 rounded-xl border border-orange-200 bg-orange-50 px-4 py-2.5 text-sm font-bold text-orange-700 hover:bg-orange-100 transition">
            <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
            </svg>
            Add Additional Requirement
          </button>
          </div>

          <div id="teacherProposalEditSection" class="hidden space-y-3">
            <div class="rounded-2xl border border-zinc-200 bg-zinc-50/70 px-4 py-3 text-xs text-zinc-600">
              Existing uploaded documents are restored below. Upload a new file only for requirements you want to replace, then submit again.
            </div>
            <div id="teacherProposalEditRequirementsList" class="space-y-3"></div>
          </div>
          </div>

          <div id="studentRequirementsSection" class="rounded-2xl border border-zinc-200 bg-white p-4 shadow-sm space-y-4">
            <div class="flex flex-wrap items-center gap-2">
              <span class="inline-flex items-center rounded-full border border-sky-200 bg-sky-50 px-3 py-1 text-[11px] font-bold uppercase tracking-wide text-sky-700">Student Req</span>
              <span class="inline-flex items-center rounded-full border border-zinc-200 bg-zinc-100 px-2.5 py-0.5 text-[10px] font-bold uppercase tracking-wide text-zinc-500">Optional</span>
            </div>
            <p class="text-[12px] leading-relaxed text-zinc-600">
              Select documents students must upload on the app before registering. Leave this empty if students can register directly with no document upload.
            </p>

          <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <?php foreach (STUDENT_REQUIREMENT_PRESETS as $code => $label): ?>
            <label class="flex items-start gap-3 rounded-2xl border border-zinc-200 bg-zinc-50/50 p-4 cursor-pointer hover:border-sky-300 transition">
              <input type="checkbox" class="student-req-checkbox mt-0.5 h-4 w-4 rounded border-zinc-300 text-sky-600 focus:ring-sky-500"
                data-code="<?= htmlspecialchars($code) ?>" data-label="<?= htmlspecialchars($label) ?>" />
              <span>
                <span class="block text-sm font-semibold text-zinc-900"><?= htmlspecialchars($label) ?></span>
                <span class="block text-[11px] text-zinc-500 mt-0.5">Students upload this on the app</span>
              </span>
            </label>
            <?php endforeach; ?>
          </div>

          <div class="rounded-2xl border border-zinc-200 bg-zinc-50/50 p-4 space-y-3">
            <div class="flex items-center justify-between gap-3">
              <div>
                <div class="text-sm font-bold text-zinc-900">Other Requirements</div>
                <p class="text-[11px] text-zinc-500 mt-0.5">Add any additional document students must submit.</p>
              </div>
              <button type="button" id="btnAddStudentRequirementOther"
                class="inline-flex items-center gap-1.5 rounded-xl border border-sky-200 bg-sky-50 px-3 py-2 text-xs font-bold text-sky-700 hover:bg-sky-100 transition">
                <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/></svg>
                Add Other
              </button>
            </div>
            <div id="studentRequirementOtherList" class="space-y-2"></div>
          </div>
          </div>
        </div>
        <?php endif; ?>

        <div id="formMsg" class="text-sm text-amber-800 min-h-0 !mt-0"></div>
      </form>
    </div>

    <!-- Footer -->
    <div class="px-5 sm:px-6 py-4 border-t border-zinc-200 space-y-3">
      <div id="wizardFooterStatus" class="hidden rounded-xl border px-3.5 py-2.5 text-sm font-semibold" role="status" aria-live="polite"></div>
      <div class="flex items-center justify-between gap-3">
      <button type="button" id="btnBack"
        class="rounded-xl border border-zinc-200 bg-zinc-50 px-5 py-2.5 text-sm text-zinc-700 hover:bg-zinc-100 hover:text-zinc-900 transition font-medium disabled:opacity-30 disabled:cursor-not-allowed"
        disabled>
        <span class="flex items-center gap-1.5">
          <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5L8.25 12l7.5-7.5" />
          </svg>
          Back
        </span>
      </button>
      <div class="flex items-center gap-2">
        <button type="button" id="btnNext"
          class="rounded-xl bg-gradient-to-r from-orange-600 to-red-600 text-white px-6 py-2.5 text-sm font-semibold hover:from-orange-500 hover:to-red-500 transition-all shadow-lg shadow-orange-600/25 hover:shadow-orange-500/35">
          <span class="flex items-center gap-1.5">
            Next
            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5" />
            </svg>
          </span>
        </button>
        <button type="button" id="btnSubmit"
          class="hidden rounded-xl bg-gradient-to-r from-emerald-600 to-emerald-500 text-white px-6 py-2.5 text-sm font-semibold hover:from-emerald-500 hover:to-emerald-400 transition-all shadow-lg shadow-emerald-600/25">
          <span class="flex items-center gap-1.5">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" />
            </svg>
            Save Event
          </span>
        </button>
      </div>
      </div>
    </div>

  </div>
</div>

<!-- ═══════════  ARCHIVE CONFIRM MODAL  ═══════════ -->
<div id="archiveModal" class="modal-backdrop">
  <div class="confirm-panel">
    <div
      class="w-12 h-12 rounded-full bg-red-500/15 border border-red-500/25 flex items-center justify-center mx-auto mb-4">
      <svg class="w-6 h-6 text-red-400" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round"
          d="M20.25 7.5l-.625 10.632a2.25 2.25 0 01-2.247 2.118H6.622a2.25 2.25 0 01-2.247-2.118L3.75 7.5M10 11.25h4M3.375 7.5h17.25c.621 0 1.125-.504 1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125H2.25c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125z" />
      </svg>
    </div>
    <h3 class="text-lg font-semibold text-zinc-900 mb-1">Archive Event</h3>
    <p class="text-sm text-zinc-600 mb-5">Are you sure you want to archive <span id="archiveEventName"
        class="text-zinc-900 font-medium"></span>? You can restore it later from the Archive page.</p>
    <input type="hidden" id="archiveEventId" value="" />
    <div class="flex gap-3">
      <button id="btnCancelArchive"
        class="flex-1 rounded-lg border border-zinc-200 bg-white px-4 py-2.5 text-sm text-zinc-700 hover:bg-zinc-50 transition font-medium">Cancel</button>
      <button id="btnConfirmArchive"
        class="flex-1 rounded-lg bg-gradient-to-r from-red-600 to-red-500 text-white px-4 py-2.5 text-sm font-medium hover:from-red-500 hover:to-red-400 transition-all shadow-lg shadow-red-600/20">Archive</button>
    </div>
  </div>
</div>

<!-- ═══════════  STT PREVIEW MODAL  ═══════════ -->
<div id="sttPreviewModal" class="modal-backdrop">
  <div class="stt-preview-panel">
    <!-- Header -->
    <div class="flex items-center justify-between px-5 pt-5 pb-3 border-b border-zinc-200">
      <div class="flex items-center gap-3">
        <div
          class="w-9 h-9 rounded-xl bg-gradient-to-br from-orange-100 to-red-100 border border-orange-200 flex items-center justify-center">
          <svg class="w-4 h-4 text-orange-600" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round"
              d="M12 18.75a6 6 0 006-6v-1.5m-6 7.5a6 6 0 01-6-6v-1.5m6 7.5v3.75m-3.75 0h7.5M12 15.75a3 3 0 01-3-3V4.5a3 3 0 116 0v8.25a3 3 0 01-3 3z" />
          </svg>
        </div>
        <div>
          <div class="text-sm font-semibold text-zinc-900">Voice Transcript Preview</div>
          <div class="text-[10px] text-zinc-500">Review and edit before inserting</div>
        </div>
      </div>
      <button id="sttPreviewClose"
        class="p-2 rounded-xl text-zinc-500 hover:text-zinc-900 hover:bg-zinc-100 transition">
        <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
        </svg>
      </button>
    </div>

    <!-- Tabs -->
    <div class="px-5 pt-4 pb-2 flex items-center gap-2">
      <button type="button" class="stt-tab" id="sttTabRaw">📝 Raw Text</button>
      <button type="button" class="stt-tab active" id="sttTabImproved">✨ AI Improved</button>
    </div>

    <!-- Content -->
    <div class="px-5 py-3">
      <div id="sttModalStatus" class="text-xs font-semibold text-red-600 mb-2 hidden items-center gap-1.5"></div>

      <!-- Voice Recording Spectrum Animation -->
      <style>
        @keyframes stt-bar-bounce {

          0%,
          100% {
            transform: scaleY(0.4);
            opacity: 0.5;
          }

          50% {
            transform: scaleY(1.2);
            opacity: 1;
          }
        }

        .stt-bar {
          width: 8px;
          background: linear-gradient(to top, #ef4444, #f87171);
          border-radius: 999px;
          animation: stt-bar-bounce 1s ease-in-out infinite;
          z-index: 10;
          box-shadow: 0 0 10px rgba(239, 68, 68, 0.4);
        }
      </style>
      <div id="sttSpectrumEffect"
        class="hidden w-full h-[150px] bg-zinc-900 rounded-xl items-center justify-center gap-2 border border-zinc-800 relative overflow-hidden transition-all duration-300">
        <div class="absolute inset-0 bg-red-500/10 blur-2xl rounded-full scale-[1.5] animate-pulse"></div>
        <div class="stt-bar" style="animation-delay: 0.1s; height: 16px;"></div>
        <div class="stt-bar" style="animation-delay: 0.3s; height: 32px;"></div>
        <div class="stt-bar" style="animation-delay: 0.5s; height: 64px;"></div>
        <div class="stt-bar" style="animation-delay: 0.2s; height: 48px;"></div>
        <div class="stt-bar" style="animation-delay: 0.4s; height: 24px;"></div>
      </div>

      <textarea id="sttPreviewText" class="stt-preview-textarea transition-all" spellcheck="true"></textarea>

      <div class="text-[11px] text-zinc-400 mt-2 flex items-center justify-between">
        <div class="flex items-center gap-3">
          <span id="sttCharCount">0 characters</span>
          <span>•</span>
          <span id="sttWordCount">0 words</span>
          <span>•</span>
          <button type="button" id="sttExpandToggle"
            class="text-orange-600 hover:text-orange-700 font-bold flex items-center gap-1 transition-colors outline-none">
            ⤢ Expand View
          </button>
        </div>
        <button type="button" id="sttMicToggle"
          class="flex items-center gap-1.5 rounded-lg bg-red-50 text-red-600 px-3 py-1.5 font-medium border border-red-200 hover:bg-red-100 transition">
          Stop Recording ⏹
        </button>
      </div>
    </div>

    <!-- Footer -->
    <div class="px-5 py-4 border-t border-zinc-200 flex items-center justify-between gap-3">
      <button type="button" id="sttPreviewDiscard"
        class="rounded-xl border border-zinc-200 bg-zinc-50 px-4 py-2.5 text-sm text-zinc-700 hover:bg-zinc-100 transition font-medium">
        Discard
      </button>
      <div class="flex items-center gap-2">
        <button type="button" id="sttPreviewAppend"
          class="rounded-xl border border-orange-200 bg-orange-50 px-4 py-2.5 text-sm text-orange-800 hover:bg-orange-100 transition font-semibold">
          Append ↩
        </button>
        <button type="button" id="sttPreviewReplace"
          class="rounded-xl bg-gradient-to-r from-orange-600 to-red-600 text-white px-5 py-2.5 text-sm font-semibold hover:from-orange-500 hover:to-red-500 shadow-lg shadow-orange-600/25 transition-all">
          Insert ✓
        </button>
      </div>
    </div>
  </div>
</div>

<?php
// Compute stats
$publishedCount = 0;
$pendingCount = 0;
$approvedCount = 0;
$draftCount = 0;
foreach ($events as $ev) {
  $s = (string) ($ev['status'] ?? '');
  if ($s === 'published')
    $publishedCount++;
  elseif ($s === 'pending')
    $pendingCount++;
  elseif ($s === 'approved')
    $approvedCount++;
  elseif ($s === 'draft')
    $draftCount++;
}
$liveListHash = manage_events_live_list_hash($user, $events);
?>

<!-- ═══════  HEADER  ═══════ -->
<div class="mb-6 flex items-center justify-between flex-wrap gap-3">
  <div>
    <p class="text-zinc-600 text-sm">
      <?php if ($role === 'admin'): ?>Review teacher proposals — send requirements, approve documents, and publish events.<?php else: ?>Create
        events (pending). Admin approves & publishes.<?php endif; ?>
    </p>
  </div>
  <?php if ($role === 'teacher'): ?>
  <button id="btnCreateEvent"
    class="flex items-center gap-2 rounded-xl bg-gradient-to-r from-orange-600 to-red-600 text-white px-5 py-2.5 text-sm font-medium hover:from-orange-500 hover:to-red-500 transition-all shadow-lg shadow-orange-600/25 hover:shadow-orange-500/40 hover:scale-[1.02] active:scale-[0.98]">
    <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
      <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
    </svg>
    Create Event
  </button>
  <?php endif; ?>
</div>

<!-- ═══════  STAT CARDS  ═══════ -->
<div class="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-6">
  <div
    class="rounded-xl border border-zinc-200 bg-white p-4 shadow-sm group hover:border-emerald-300 transition-colors">
    <div class="flex items-center gap-3">
      <div class="w-10 h-10 rounded-xl bg-emerald-100 border border-emerald-200 flex items-center justify-center">
        <svg class="w-5 h-5 text-emerald-700" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round"
            d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
        </svg>
      </div>
      <div>
        <div class="text-2xl font-bold text-zinc-900"><?= $publishedCount ?></div>
        <div class="text-[11px] text-zinc-600 font-medium">Published</div>
      </div>
    </div>
  </div>
  <div class="rounded-xl border border-zinc-200 bg-white p-4 shadow-sm group hover:border-amber-300 transition-colors">
    <div class="flex items-center gap-3">
      <div class="w-10 h-10 rounded-xl bg-amber-100 border border-amber-200 flex items-center justify-center">
        <svg class="w-5 h-5 text-amber-800" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z" />
        </svg>
      </div>
      <div>
        <div class="text-2xl font-bold text-zinc-900"><?= $pendingCount ?></div>
        <div class="text-[11px] text-zinc-600 font-medium">Pending</div>
      </div>
    </div>
  </div>
  <div class="rounded-xl border border-zinc-200 bg-white p-4 shadow-sm group hover:border-sky-300 transition-colors">
    <div class="flex items-center gap-3">
      <div class="w-10 h-10 rounded-xl bg-sky-100 border border-sky-200 flex items-center justify-center">
        <svg class="w-5 h-5 text-sky-700" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" />
        </svg>
      </div>
      <div>
        <div class="text-2xl font-bold text-zinc-900"><?= $approvedCount ?></div>
        <div class="text-[11px] text-zinc-600 font-medium">Approved</div>
      </div>
    </div>
  </div>
  <div class="rounded-xl border border-zinc-200 bg-white p-4 shadow-sm group hover:border-orange-300 transition-colors">
    <div class="flex items-center gap-3">
      <div class="w-10 h-10 rounded-xl bg-orange-100 border border-orange-200 flex items-center justify-center">
        <svg class="w-5 h-5 text-orange-700" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round"
            d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 11.25v7.5" />
        </svg>
      </div>
      <div>
        <div class="text-2xl font-bold text-zinc-900"><?= count($events) ?></div>
        <div class="text-[11px] text-zinc-600 font-medium">Total Active</div>
      </div>
    </div>
  </div>
</div>

<!-- ═══════  FEATURED 3D SAMPLE EVENT  ═══════ -->
<div
  class="mb-14 lg:mb-16 w-full relative overflow-visible mt-10 rounded-[1.5rem] bg-gradient-to-br from-[#450a0a] via-[#7f1d1d] to-[#450a0a] px-8 lg:px-14 py-6 lg:py-0 shadow-xl border border-red-500/30 flex flex-col lg:flex-row items-center justify-between lg:h-[260px]">
  <div
    class="absolute inset-0 bg-[url('data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHdpZHRoPSI4IiBoZWlnaHQ9IjgiPgo8cmVjdCB3aWR0aD0iOCIgaGVpZ2h0PSI4IiBmaWxsPSIjZmZmIiBmaWxsLW9wYWNpdHk9IjAuMDUiLz4KPC9zdmc+')] opacity-20 rounded-[1.5rem] mix-blend-overlay pointer-events-none">
  </div>

  <!-- LEFT: Text Content -->
  <div class="relative z-20 flex-1 w-full text-center lg:text-left my-6 lg:my-0 pointer-events-none">
    <div class="flex items-center justify-center lg:justify-start gap-3 mb-3">
      <span class="flex h-3 w-3 relative">
        <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-emerald-400 opacity-75"></span>
        <span class="relative inline-flex rounded-full h-3 w-3 bg-emerald-500"></span>
      </span>
      <span class="text-[10px] font-bold tracking-[0.2em] text-emerald-400 uppercase">Interactive Sample</span>
    </div>
    <h2
      class="text-3xl sm:text-4xl lg:text-[2rem] font-extrabold text-white tracking-tight leading-none whitespace-nowrap">
      CSS Event Featured Showcase</h2>
  </div>

  <!-- RIGHT: 3D Laptop Container -->
  <style>
    .laptop-scale-container {
      position: relative;
      z-index: 10;
      width: 100%;
      lg: width: 50%;
      display: flex;
      justify-content: center;
      align-items: center;
      height: 0px;
      /* Collapse natural height */
    }

    .laptop-wrapper {
      transform: scale(0.65);
      transform-origin: center center;
      margin-bottom: 25px;
      /* Adjust optical center */
    }

    @media (min-width: 1024px) {
      .laptop-wrapper {
        transform-origin: right center;
        right: 20px;
        position: absolute;
        margin-bottom: 0px;
        top: 50%;
        transform: translateY(-50%) scale(0.75);
      }
    }

    @media (max-width: 768px) {
      .laptop-wrapper {
        transform: scale(0.45);
      }
    }

    .laptop {
      transform: scale(0.8);
    }

    .screen {
      border-radius: 20px;
      box-shadow: inset 0 0 0 2px #c8cacb, inset 0 0 0 10px #000;
      height: 318px;
      width: 518px;
      margin: 0 auto;
      padding: 9px 9px 23px 9px;
      position: relative;
      display: flex;
      align-items: center;
      justify-content: center;
      background-image: linear-gradient(15deg, #7c2d12 0%, #9a3412 13%, #c2410c 25%, #ea580c 38%, #f97316 50%, #fb923c 62%, #fdba74 75%, #fed7aa 87%, #ffedd5 100%);
      transform-style: preserve-3d;
      transform: perspective(1900px) rotateX(-88.5deg);
      transform-origin: 50% 100%;
      animation: openLaptop 4s cubic-bezier(0.4, 0.0, 0.2, 1) infinite alternate;
      z-index: 5;
    }

    @keyframes openLaptop {
      0% {
        transform: perspective(1900px) rotateX(-89deg);
      }

      100% {
        transform: perspective(1000px) rotateX(0deg);
      }
    }

    .screen-bg {
      position: absolute;
      top: 9px;
      left: 9px;
      right: 9px;
      bottom: 23px;
      border-radius: 12px;
      background-size: cover;
      background-position: center;
      background-image: url('assets/sample summit/image1.jpg');
      z-index: 10;
      box-shadow: inset 0 0 40px rgba(0, 0, 0, 0.6);
      transition: none;
    }

    .screen-bg::after {
      content: "";
      position: absolute;
      inset: 0;
      background: rgba(0, 0, 0, 0.4);
      border-radius: 12px;
    }

    .screen::before {
      content: "";
      width: 518px;
      height: 12px;
      position: absolute;
      background: linear-gradient(#979899, transparent);
      top: -3px;
      transform: rotateX(90deg);
      border-radius: 5px 5px;
      z-index: 20;
    }

    .laptop-text {
      font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
      color: #fff;
      letter-spacing: 2px;
      text-shadow: 0 4px 10px rgba(0, 0, 0, 0.8), 0 0 20px rgba(255, 100, 100, 0.4);
      font-size: 32px;
      font-weight: 800;
      z-index: 30;
      text-transform: uppercase;
    }

    .header-cam {
      width: 100px;
      height: 12px;
      position: absolute;
      background-color: #000;
      top: 10px;
      left: 50%;
      transform: translate(-50%, -0%);
      border-radius: 0 0 6px 6px;
      z-index: 30;
    }

    .screen::after {
      background: linear-gradient(to bottom, #272727, #0d0d0d);
      border-radius: 0 0 20px 20px;
      bottom: 2px;
      content: "";
      height: 24px;
      left: 2px;
      position: absolute;
      width: 514px;
      z-index: 20;
    }

    .keyboard {
      background: radial-gradient(circle at center, #e2e3e4 85%, #a9abac 100%);
      border: solid #a0a3a7;
      border-radius: 2px 2px 12px 12px;
      border-width: 1px 2px 0 2px;
      box-shadow: inset 0 -2px 8px 0 #6c7074, 0 30px 60px rgba(0, 0, 0, 0.8);
      height: 24px;
      margin-top: -10px;
      position: relative;
      width: 620px;
      z-index: 9;
      margin: -10px auto 0 auto;
    }

    .keyboard::after {
      background: #e2e3e4;
      border-radius: 0 0 10px 10px;
      box-shadow: inset 0 0 4px 2px #babdbf;
      content: "";
      height: 10px;
      left: 50%;
      margin-left: -60px;
      position: absolute;
      top: 0;
      width: 120px;
    }

    .keyboard::before {
      background: 0 0;
      border-radius: 0 0 3px 3px;
      bottom: -2px;
      box-shadow: -270px 0 #272727, 250px 0 #272727;
      content: "";
      height: 2px;
      left: 50%;
      margin-left: -10px;
      position: absolute;
      width: 40px;
    }
  </style>

  <div class="laptop-scale-container">
    <div class="laptop-wrapper">
      <div class="laptop">
        <div class="screen">
          <div class="screen-bg"></div>
          <div class="header-cam"></div>
          <div class="laptop-text animate-pulse" id="laptopLabel">CCS Summit</div>
        </div>
        <div class="keyboard"></div>
      </div>
    </div>
  </div>
</div>

<script>
  (function () {
    const showcaseSlides = [
      { img: 'assets/sample summit/image1.jpg', label: 'CCS Summit' },
      { img: 'assets/sample GA/image1.jpg', label: 'General Assembly' },
      { img: 'assets/sample exhibit/image1.jpg', label: 'CCS Exhibit' },
      { img: 'assets/sample CV/image1.jpg', label: 'Company Visit' }
    ];
    let currentSlide = 0;
    const screenBg = document.querySelector('.screen-bg');
    const laptopLabel = document.getElementById('laptopLabel');
    const screenEl = document.querySelector('.screen');
    if (!screenBg || !laptopLabel || !screenEl) return;

    // The laptop animation is 4s alternate, so one full open+close = 8s.
    // Swap the image every 8s (when laptop is closed).
    setInterval(() => {
      currentSlide = (currentSlide + 1) % showcaseSlides.length;
      screenBg.style.backgroundImage = "url('" + showcaseSlides[currentSlide].img + "')";
      laptopLabel.textContent = showcaseSlides[currentSlide].label;
    }, 8000);
  })();
</script>

<style>
  /* Animated List Styles */
  .event-scroll-container {
    max-height: 650px;
    overflow-y: auto;
    padding: 1rem;
    scrollbar-width: thin;
    scrollbar-color: #e4e4e7 transparent;
    scroll-behavior: smooth;
  }

  .event-scroll-container::-webkit-scrollbar {
    width: 6px;
  }

  .event-scroll-container::-webkit-scrollbar-track {
    background: transparent;
  }

  .event-scroll-container::-webkit-scrollbar-thumb {
    background: #e4e4e7;
    border-radius: 10px;
  }

  .event-card-animated {
    opacity: 0;
    transform: scale(0.7) translateY(10px);
    transition: all 0.5s cubic-bezier(0.22, 1, 0.36, 1);
    will-change: transform, opacity;
  }

  .event-card-animated.in-view {
    opacity: 1;
    transform: scale(1) translateY(0);
  }

  .requirements-row input {
    min-width: 0;
  }

  .proposal-progress-track {
    height: 10px;
    border-radius: 999px;
    background: #e4e4e7;
    overflow: hidden;
  }

  .proposal-progress-bar {
    height: 100%;
    border-radius: 999px;
    background: linear-gradient(90deg, #f97316 0%, #10b981 100%);
    transition: width 0.25s ease;
  }

  .event-card-live-update {
    animation: eventLivePulse 1.8s ease;
    box-shadow: 0 0 0 2px rgba(249, 115, 22, 0.35);
  }

  @keyframes eventLivePulse {
    0% { box-shadow: 0 0 0 0 rgba(249, 115, 22, 0.45); }
    40% { box-shadow: 0 0 0 6px rgba(249, 115, 22, 0.12); }
    100% { box-shadow: 0 0 0 0 rgba(249, 115, 22, 0); }
  }
</style>

<!-- ═══════  EVENTS TABLE  ═══════ -->
<div class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm">
  <div class="flex items-center justify-between gap-3 mb-1">
    <div class="flex items-center gap-2.5">
      <div class="w-8 h-8 rounded-lg bg-sky-100 border border-sky-200 flex items-center justify-center">
        <svg class="w-4 h-4 text-sky-700" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round"
            d="M8.25 6.75h12M8.25 12h12m-12 5.25h12M3.75 6.75h.007v.008H3.75V6.75zm.375 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zM3.75 12h.007v.008H3.75V12zm.375 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm-.375 5.25h.007v.008H3.75v-.008zm.375 0a.375.375 0 11-.75 0 .375.375 0 01.75 0z" />
        </svg>
      </div>
      <span class="text-sm font-medium text-zinc-900">Events List</span>
      <span
        class="text-[10px] bg-zinc-100 text-zinc-700 border border-zinc-200 px-2 py-0.5 rounded-full font-medium"><?= count($events) ?></span>
    </div>
    <div class="text-xs text-zinc-500">
      <?php if ($role === 'admin'): ?>Admin controls<?php else: ?>Your events<?php endif; ?>
    </div>
  </div>

  <?php if ($role === 'teacher'): ?>
    <div class="flex border-b border-zinc-200 mb-5 gap-6 mt-3">
      <button id="tabActive"
        class="pb-3 border-b-[2.5px] border-sky-500 font-bold text-sky-600 text-[13px] transition-colors">Active</button>
      <button id="tabApproval"
        class="pb-3 border-b-[2.5px] border-transparent font-semibold text-zinc-500 hover:text-zinc-800 text-[13px] transition-colors flex items-center gap-1.5">
        Approval
        <?php
        $approvalCount = 0;
        foreach ($events as $eventRow) {
          $statusRow = strtolower(trim((string) ($eventRow['status'] ?? '')));
          if (in_array($statusRow, ['pending', 'approved', 'rejected'], true)) {
            $approvalCount++;
            continue;
          }
          $descRow = (string) ($eventRow['description'] ?? '');
          $isRejectedRow = str_contains($descRow, '[REJECT_REASON:');
          if ($statusRow === 'archived' && $isRejectedRow) {
            $approvalCount++;
          }
        }
        ?>
        <span id="teacherApprovalTabBadge"
          class="bg-sky-100 border border-sky-200 text-sky-700 text-[10px] font-black px-2 py-0.5 rounded-full shadow-sm<?= $approvalCount > 0 ? '' : ' hidden' ?>"><?= $approvalCount ?></span>
      </button>
      <button id="tabExpired"
        class="pb-3 border-b-[2.5px] border-transparent font-semibold text-zinc-500 hover:text-zinc-800 text-[13px] transition-colors">Expired</button>
    </div>
  <?php elseif ($role === 'admin'): ?>
    <!-- Tabs Navigation for Admin (existing behavior) -->
    <div class="flex border-b border-zinc-200 mb-5 gap-6 mt-3">
      <button id="tabAll"
        class="pb-3 border-b-[2.5px] border-orange-500 font-bold text-orange-600 text-[13px] transition-colors w-24">All
        Events</button>
      <button id="tabPending"
        class="pb-3 border-b-[2.5px] border-transparent font-semibold text-zinc-500 hover:text-zinc-800 text-[13px] transition-colors flex items-center gap-1.5 px-2">
        Pending Proposals
        <?php $pendingCount = count(array_filter($events, static function (array $event): bool {
          if (($event['status'] ?? '') !== 'pending') {
            return false;
          }
          $stage = strtolower(trim((string) ($event['proposal_stage'] ?? 'pending_requirements')));
          return manage_events_admin_pending_visible('pending', $stage);
        })); ?>
        <span id="pendingProposalsTabBadge"
          class="bg-red-100 border border-red-200 text-red-700 text-[10px] font-black px-2 py-0.5 rounded-full shadow-sm<?= $pendingCount > 0 ? '' : ' hidden' ?>"><?= $pendingCount ?></span>
      </button>
    </div>
  <?php else: ?>
    <div class="mb-5"></div>
  <?php endif; ?>

  <!-- Filter & Search Row -->
  <div class="flex flex-col md:flex-row gap-3 mb-6 bg-zinc-50/50 p-3 rounded-2xl border border-zinc-100">
    <div class="relative flex-1">
      <div class="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none text-zinc-400">
        <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round"
            d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z" />
        </svg>
      </div>
      <input type="text" id="searchEvents" placeholder="Search events by title, location or teacher..."
        class="block w-full pl-10 pr-4 py-2.5 border border-zinc-200 rounded-xl text-[13px] placeholder-zinc-400 focus:outline-none focus:ring-2 focus:ring-orange-500/10 focus:border-orange-500 transition-all bg-white shadow-sm ring-inset">
    </div>
    <div class="w-full md:w-56">
      <div class="relative">
        <select id="filterType"
          class="appearance-none block w-full px-4 py-2.5 pr-10 border border-zinc-200 rounded-xl text-[13px] text-zinc-700 focus:outline-none focus:ring-2 focus:ring-orange-500/10 focus:border-orange-500 transition-all bg-white shadow-sm cursor-pointer ring-inset font-medium">
          <option value="all">All Event Types</option>
          <?php
          // Always show the create-form types (admin + teacher), plus any legacy values in data.
          $knownEventTypes = [
            'Event',
            'Seminar',
            'Off-Campus Activity',
            'Sports Event',
            'Other',
          ];
          $typesFromEvents = array_values(array_unique(array_filter(array_map(
            static fn($e) => trim((string) ($e['event_type'] ?? '')),
            $events
          ))));
          $types = [];
          foreach (array_merge($knownEventTypes, $typesFromEvents) as $type) {
            $type = trim((string) $type);
            if ($type === '' || in_array($type, $types, true)) {
              continue;
            }
            $types[] = $type;
          }
          foreach ($types as $type):
            ?>
            <option value="<?= htmlspecialchars($type) ?>"><?= htmlspecialchars($type) ?></option>
          <?php endforeach; ?>
        </select>
        <div class="absolute inset-y-0 right-0 pr-3 flex items-center pointer-events-none text-zinc-400">
          <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5" />
          </svg>
        </div>
      </div>
    </div>
  </div>

  <div class="relative overflow-hidden rounded-xl border border-zinc-100 bg-zinc-50/10 mt-4">
    <!-- Edge Gradients -->
    <div id="topEventGrad"
      class="absolute top-0 left-0 right-0 h-16 bg-gradient-to-b from-white via-white/80 to-transparent pointer-events-none opacity-0 transition-opacity duration-300 z-10">
    </div>
    <div id="bottomEventGrad"
      class="absolute bottom-0 left-0 right-0 h-20 bg-gradient-to-t from-white via-white/80 to-transparent pointer-events-none opacity-100 transition-opacity duration-300 z-10">
    </div>

    <div id="eventScrollContainer" class="event-scroll-container" data-live-list-hash="<?= htmlspecialchars($liveListHash) ?>">
      <div class="space-y-3 pb-24">
        <?php foreach ($events as $e): ?>
          <?php
          $status = (string) ($e['status'] ?? '');
          $description = (string) ($e['description'] ?? '');
          $isRejected = strpos($description, '[REJECT_REASON:') !== false;

          // For teachers: If archived but NOT rejected, skip (it's a manual archive)
          if ($role === 'teacher' && $status === 'archived' && !$isRejected)
            continue;

          if ($role === 'admin' && $status === 'pending') {
            $rawProposalStage = strtolower(trim((string) ($e['proposal_stage'] ?? 'pending_requirements')));
            if (!manage_events_admin_pending_visible($status, $rawProposalStage)) {
              continue;
            }
          }
          ?>
          <?php
          $eid = (string) ($e['id'] ?? '');
          $createdBy = (string) ($e['created_by'] ?? '');
          $canEdit = $role === 'admin' || ($role === 'teacher' && $createdBy === $userId && ($status === 'pending' || ($status === 'archived' && $isRejected)));
          $proposalStage = strtolower(trim((string) ($e['proposal_stage'] ?? 'pending_requirements')));
          if ($status === 'approved' || $status === 'published') {
            $proposalStage = 'approved';
          }
          $proposalRequirements = $proposalRequirementMap[$eid] ?? [];
          $proposalSubmissions = $proposalSubmissionMap[$eid] ?? [];
          $proposalVisibleSubmissions = $proposalVisibleSubmissionMap[$eid] ?? [];
          $proposalSummary = $proposalSummaryMap[$eid] ?? ['total' => 0, 'submitted' => 0, 'complete' => false, 'percent' => 0];
          $studentRequirements = $studentRequirementMap[$eid] ?? [];
          $studentRequirementsPayload = array_values(array_map(
            static function (array $row): array {
              return [
                'code' => (string) ($row['code'] ?? ''),
                'label' => (string) ($row['label'] ?? ''),
              ];
            },
            array_filter($studentRequirements, 'is_array')
          ));
          $proposalSubmissionsVisible = $proposalVisibleSubmissions;
          $proposalSummaryVisible = $proposalSummary;
          $proposalStage = manage_events_live_effective_stage($status, $proposalStage, $proposalRequirements);
          $adminWaitingOnFinalSubmit = $role === 'admin'
            && $status === 'pending'
            && $proposalRequirements !== []
            && $proposalStage !== 'under_review'
            && $proposalStage !== 'approved';
          $proposalDisplaySummary = ($role === 'admin' && $adminWaitingOnFinalSubmit)
            ? build_proposal_requirement_summary($proposalRequirements, $proposalSubmissions)
            : $proposalSummaryVisible;
          $liveRequirementItems = [];
          foreach ($proposalRequirements as $requirement) {
            if (!is_array($requirement)) {
              continue;
            }
            $requirementId = trim((string) ($requirement['id'] ?? ''));
            $liveRequirementItems[] = [
              'id' => $requirementId,
              'uploaded' => $requirementId !== '' && isset($proposalSubmissions[$requirementId]),
            ];
          }
          $liveRevision = manage_events_live_revision(
            [
              'status' => $status,
              'proposal_stage' => $proposalStage,
              'updated_at' => (string) ($e['updated_at'] ?? ''),
              'requirements_submitted_at' => (string) ($e['requirements_submitted_at'] ?? ''),
              'description' => $description,
            ],
            $proposalDisplaySummary,
            $liveRequirementItems
          );
          $proposalStageConfig = match ($proposalStage) {
            'requirements_requested' => $role === 'teacher'
              ? ['label' => 'Upload documents', 'bg' => 'bg-orange-50', 'text' => 'text-orange-700', 'border' => 'border-orange-200']
              : ['label' => 'Waiting on teacher', 'bg' => 'bg-orange-50', 'text' => 'text-orange-700', 'border' => 'border-orange-200'],
            'under_review' => $role === 'teacher'
              ? ['label' => 'Waiting for admin', 'bg' => 'bg-violet-50', 'text' => 'text-violet-700', 'border' => 'border-violet-200']
              : ['label' => 'Under review', 'bg' => 'bg-violet-50', 'text' => 'text-violet-700', 'border' => 'border-violet-200'],
            'approved' => $role === 'teacher'
              ? ['label' => 'Approved', 'bg' => 'bg-emerald-50', 'text' => 'text-emerald-700', 'border' => 'border-emerald-200']
              : ['label' => 'Ready for publish', 'bg' => 'bg-emerald-50', 'text' => 'text-emerald-700', 'border' => 'border-emerald-200'],
            default => $role === 'teacher'
              ? ['label' => 'Waiting for requirements', 'bg' => 'bg-amber-50', 'text' => 'text-amber-700', 'border' => 'border-amber-200']
              : ['label' => 'Needs requirements', 'bg' => 'bg-amber-50', 'text' => 'text-amber-700', 'border' => 'border-amber-200'],
          };

          $statusConfig = match ($status) {
            'published' => ['bg' => 'bg-emerald-100', 'text' => 'text-emerald-900', 'border' => 'border-emerald-200', 'accent' => 'border-l-emerald-500', 'icon' => '<path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>'],
            'pending' => ['bg' => 'bg-amber-100', 'text' => 'text-amber-900', 'border' => 'border-amber-200', 'accent' => 'border-l-amber-500', 'icon' => '<path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z"/>'],
            'approved' => ['bg' => 'bg-sky-100', 'text' => 'text-sky-900', 'border' => 'border-sky-200', 'accent' => 'border-l-sky-500', 'icon' => '<path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/>'],
            'expired' => ['bg' => 'bg-zinc-200', 'text' => 'text-zinc-600', 'border' => 'border-zinc-300', 'accent' => 'border-l-zinc-500', 'icon' => '<path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z"/>'],
            'archived' => ['bg' => 'bg-rose-100', 'text' => 'text-rose-900', 'border' => 'border-rose-200', 'accent' => 'border-l-rose-500', 'icon' => '<path stroke-linecap="round" stroke-linejoin="round" d="M9.75 9.75l4.5 4.5m0-4.5l-4.5 4.5M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>'],
            'draft' => ['bg' => 'bg-orange-100', 'text' => 'text-orange-900', 'border' => 'border-orange-200', 'accent' => 'border-l-orange-500', 'icon' => '<path stroke-linecap="round" stroke-linejoin="round" d="M5.25 14.25h13.5m-13.5 0a3 3 0 01-3-3m3 3a3 3 0 100 6h13.5a3 3 0 100-6m-16.5-3a3 3 0 013-3h13.5a3 3 0 013 3m-19.5 0a4.5 4.5 0 01.9-2.7L5.737 5.1a3.375 3.375 0 012.7-1.35h7.126c1.062 0 2.062.5 2.7 1.35l2.587 3.45a4.5 4.5 0 01.9 2.7m0 0a3 3 0 01-3 3m0 3h.008v.008h-.008v-.008zm0-6h.008v.008h-.008v-.008zm-3 6h.008v.008h-.008v-.008zm0-6h.008v.008h-.008v-.008z"/>'],
            default => ['bg' => 'bg-zinc-100', 'text' => 'text-zinc-800', 'border' => 'border-zinc-200', 'accent' => 'border-l-zinc-400', 'icon' => '<path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m2.25 0H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125h-12.75V11.25a9 9 0 00-9-9z"/>'],
          };

          // Format date
          $rawDate = (string) ($e['start_at'] ?? '');
          $formattedDate = format_date_local($rawDate);
          ?>
          <div
            class="event-card event-card-animated rounded-xl border border-zinc-200 bg-zinc-50/90 hover:bg-white hover:border-zinc-300 transition-all group border-l-[3px] shadow-sm <?= $statusConfig['accent'] ?>"
            data-title="<?= htmlspecialchars((string) ($e['title'] ?? '')) ?>"
            data-type="<?= htmlspecialchars((string) ($e['event_type'] ?? 'Event')) ?>"
            data-location="<?= htmlspecialchars((string) ($e['location'] ?? '')) ?>"
            data-teacher="<?= htmlspecialchars($tName ?? '') ?>" data-status="<?= htmlspecialchars($status) ?>"
            data-start-at="<?= htmlspecialchars((string) ($e['start_at'] ?? '')) ?>"
            data-end-at="<?= htmlspecialchars((string) ($e['end_at'] ?? '')) ?>"
            data-is-rejected="<?= $isRejected ? '1' : '0' ?>"
            data-id="<?= htmlspecialchars($eid) ?>"
            data-location-full="<?= htmlspecialchars((string) ($e['location'] ?? '')) ?>"
            data-description="<?= htmlspecialchars((string) ($e['description'] ?? '')) ?>"
            data-start_at="<?= htmlspecialchars((string) ($e['start_at'] ?? '')) ?>"
            data-end_at="<?= htmlspecialchars((string) ($e['end_at'] ?? '')) ?>"
            data-event_mode="<?= htmlspecialchars(event_uses_sessions($e) ? 'seminar_based' : 'simple') ?>"
            data-sessions="<?= htmlspecialchars((string) (json_encode($e['sessions'] ?? [], JSON_UNESCAPED_SLASHES | JSON_HEX_APOS | JSON_HEX_QUOT) ?: '[]'), ENT_QUOTES) ?>"
            data-event_type="<?= htmlspecialchars((string) ($e['event_type'] ?? 'Event')) ?>"
            data-event_for="<?= htmlspecialchars((string) ($e['event_for'] ?? 'All')) ?>"
            data-grace_time="<?= htmlspecialchars((string) ($e['grace_time'] ?? '30')) ?>"
            data-is_free_event="<?= (($e['is_free_event'] ?? true) ? '1' : '0') ?>"
            data-event_fee="<?= htmlspecialchars((string) ($e['event_fee'] ?? '')) ?>"
            data-registration_limit="<?= htmlspecialchars((string) ($e['registration_limit'] ?? '')) ?>"
            data-registration_close_weeks="<?= htmlspecialchars((string) ($e['registration_close_weeks'] ?? '1')) ?>"
            data-cover_image_url="<?= htmlspecialchars((string) ($e['cover_image_url'] ?? '')) ?>"
            data-student_requirements="<?= htmlspecialchars((string) (json_encode($studentRequirementsPayload, JSON_UNESCAPED_SLASHES | JSON_HEX_APOS | JSON_HEX_QUOT) ?: '[]'), ENT_QUOTES) ?>"
            data-can_edit="<?= $canEdit ? '1' : '0' ?>"
            data-proposal-stage="<?= htmlspecialchars($proposalStage) ?>"
            data-proposal-revision="<?= htmlspecialchars($liveRevision) ?>"
            data-live-updated-at="<?= htmlspecialchars((string) ($e['updated_at'] ?? '')) ?>">
            <div class="flex flex-col lg:flex-row lg:items-center gap-3 p-4">

              <!-- Event Info -->
              <div class="flex-1 min-w-0">
                <div class="flex items-start gap-3">
                  <div
                    class="hidden sm:flex w-10 h-10 rounded-xl <?= $statusConfig['bg'] ?> border <?= $statusConfig['border'] ?> items-center justify-center flex-shrink-0 mt-0.5">
                    <svg class="w-5 h-5 <?= $statusConfig['text'] ?>" fill="none" stroke="currentColor" stroke-width="1.8"
                      viewBox="0 0 24 24"><?= $statusConfig['icon'] ?></svg>
                  </div>
                  <div class="min-w-0 flex-1">
                    <div class="flex items-center gap-2 mb-1">
                      <h3 class="text-sm font-semibold text-zinc-900 truncate">
                        <?= htmlspecialchars((string) ($e['title'] ?? '')) ?></h3>
                      <span
                        class="event-status-badge text-[10px] font-medium rounded-full border px-2 py-0.5 <?= $statusConfig['bg'] ?> <?= $statusConfig['text'] ?> <?= $statusConfig['border'] ?> flex-shrink-0">
                        <?= ($status === 'archived' && $isRejected) ? 'Rejected' : ucfirst(htmlspecialchars($status)) ?>
                      </span>
                      <?php if (in_array($status, ['pending', 'approved'], true)): ?>
                        <span
                          class="proposal-stage-badge text-[10px] font-bold rounded-full border px-2 py-0.5 <?= $proposalStageConfig['bg'] ?> <?= $proposalStageConfig['text'] ?> <?= $proposalStageConfig['border'] ?> flex-shrink-0">
                          <?= htmlspecialchars($proposalStageConfig['label']) ?>
                        </span>
                      <?php endif; ?>
                      <?php if ($role === 'admin' && !empty($e['users'])): ?>
                        <?php
                        $u = $e['users'];
                        $tName = trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? '') . ' ' . ($u['suffix'] ?? ''));
                        ?>
                        <?php if ($tName !== ''): ?>
                          <span
                            class="text-[10px] font-bold text-zinc-500 bg-zinc-100 px-2 py-0.5 rounded-full border border-zinc-200">
                            By: <?= htmlspecialchars($tName) ?>
                          </span>
                        <?php endif; ?>
                      <?php endif; ?>
                    </div>

                    <?php if ($status === 'archived' && $isRejected): ?>
                      <div class="proposal-reject-remark mb-3 p-3 rounded-lg border border-rose-200 bg-rose-50/70 text-rose-900 text-xs shadow-sm">
                        <div class="flex items-center gap-2 font-bold mb-1.5 text-rose-700">
                          <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round"
                              d="M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008h-.008v-.008z" />
                          </svg>
                          Admin Remark:
                        </div>
                        <p class="proposal-reject-remark-text">
                        <?php
                        preg_match('/\[REJECT_REASON:\s*(.*?)\]/', $description, $m);
                        echo htmlspecialchars($m[1] ?? 'Proposal review required.');
                        ?>
                        </p>
                      </div>
                    <?php endif; ?>

                    <div class="flex flex-wrap items-center gap-x-3 gap-y-1.5 text-xs text-zinc-600 mt-1">
                      <span
                        class="flex items-center gap-1 font-semibold text-orange-700 bg-orange-50 px-2 py-0.5 rounded border border-orange-100">
                        <?= htmlspecialchars((string) ($e['event_type'] ?? 'Event')) ?>
                      </span>
                      <span class="flex items-center gap-1 bg-zinc-100 px-2 py-0.5 rounded font-medium border border-zinc-200">
                        Target: <?= htmlspecialchars(format_target_participant((string) ($e['event_for'] ?? 'All'))) ?>
                      </span>
                      <span
                        class="flex items-center gap-1 text-emerald-700 bg-emerald-50 px-2 py-0.5 rounded font-medium border border-emerald-100">
                        Grace: <?= htmlspecialchars((string) ($e['grace_time'] ?? '30')) ?>m
                      </span>
                      <span class="flex items-center gap-1">
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                          <path stroke-linecap="round" stroke-linejoin="round"
                            d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 11.25v7.5" />
                        </svg>
                        <?= htmlspecialchars($formattedDate) ?>
                      </span>
                      <?php if (!empty($e['location'])): ?>
                        <span class="flex items-center gap-1">
                          <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M15 10.5a3 3 0 11-6 0 3 3 0 016 0z" />
                            <path stroke-linecap="round" stroke-linejoin="round"
                              d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1115 0z" />
                          </svg>
                          <?= htmlspecialchars((string) ($e['location'] ?? '')) ?>
                        </span>
                      <?php endif; ?>
                    </div>
                    <?php if (!empty($e['created_at'])): ?>
                      <p class="text-[11px] text-zinc-400 mt-2 font-medium">
                        Submitted: <?= (new DateTimeImmutable((string) $e['created_at']))->format('F d, Y') ?>
                      </p>
                    <?php endif; ?>
                    <?php if ($status === 'pending'): ?>
                      <div class="proposal-progress-section mt-3 rounded-xl border border-zinc-200 bg-white/80 px-3 py-2 shadow-sm">
                        <div class="flex flex-wrap items-center justify-between gap-2 text-[11px]">
                          <span class="font-semibold text-zinc-700">
                            Proposal requirements
                          </span>
                          <span class="proposal-upload-count text-zinc-500">
                            <?= (int) ($proposalDisplaySummary['submitted'] ?? 0) ?>/<?= (int) ($proposalDisplaySummary['total'] ?? 0) ?> uploaded
                          </span>
                        </div>
                        <div class="mt-2 h-2 overflow-hidden rounded-full bg-zinc-200 proposal-progress-track">
                          <div
                            class="proposal-progress-fill h-full rounded-full bg-gradient-to-r from-orange-500 to-emerald-500 transition-all"
                            style="width: <?= max(0, min(100, (int) ($proposalDisplaySummary['percent'] ?? 0))) ?>%"></div>
                        </div>
                        <div class="proposal-req-tags mt-2 flex flex-wrap gap-1.5">
                          <?php if ($proposalRequirements !== []): ?>
                            <?php foreach ($proposalRequirements as $requirement): ?>
                              <?php
                              $requirementId = trim((string) ($requirement['id'] ?? ''));
                              $isUploaded = $requirementId !== '' && isset($proposalSubmissions[$requirementId]);
                              ?>
                              <span
                                class="inline-flex items-center gap-1 rounded-full border px-2 py-0.5 text-[10px] font-bold <?= $isUploaded ? 'border-emerald-200 bg-emerald-50 text-emerald-700' : 'border-zinc-200 bg-zinc-50 text-zinc-500' ?>">
                                <?= $isUploaded ? 'Uploaded' : 'Pending' ?>
                                <?= htmlspecialchars((string) ($requirement['code'] ?? $requirement['label'] ?? 'DOC')) ?>
                              </span>
                            <?php endforeach; ?>
                          <?php else: ?>
                            <span class="text-[11px] text-zinc-500"><?= $role === 'teacher'
                              ? 'Waiting for admin to send document requirements.'
                              : 'Admin has not requested the required documents yet.' ?></span>
                          <?php endif; ?>
                        </div>
                        <?php if ($adminWaitingOnFinalSubmit): ?>
                          <p class="proposal-waiting-note mt-2 text-[11px] font-medium text-zinc-500">
                            Draft progress is shown here (<?= (int) ($proposalDisplaySummary['submitted'] ?? 0) ?>/<?= (int) ($proposalDisplaySummary['total'] ?? 0) ?> uploaded). Full file review opens after the teacher submits for review.
                          </p>
                        <?php elseif ($role === 'teacher' && $status === 'pending' && $proposalStage === 'requirements_requested'): ?>
                          <p class="proposal-waiting-note mt-2 text-[11px] font-medium text-orange-700">
                            Open View/Edit to upload the requested proposal documents.
                          </p>
                        <?php elseif ($role === 'teacher' && $status === 'pending' && $proposalStage === 'under_review'): ?>
                          <p class="proposal-waiting-note mt-2 text-[11px] font-medium text-violet-700">
                            Documents submitted. Waiting for admin approval.
                          </p>
                        <?php else: ?>
                          <p class="proposal-waiting-note mt-2 text-[11px] font-medium text-zinc-500 hidden"></p>
                        <?php endif; ?>
                      </div>
                    <?php endif; ?>
                  </div>
                </div>
              </div>

              <!-- Actions -->
              <div class="event-admin-actions flex gap-1.5 flex-wrap items-center lg:flex-shrink-0 pl-0 sm:pl-[52px] lg:pl-0">
                <?php if ($role === 'admin'): ?>
                  <?php if ($status === 'pending'): ?>
                    <?php $approveReady = $proposalStage === 'under_review'; ?>
                    <button
                      class="btnRequirements rounded-lg border border-orange-200 bg-orange-50 px-4 py-1.5 text-[13px] font-bold text-orange-700 hover:bg-orange-100 transition shadow-sm"
                      data-id="<?= htmlspecialchars($eid) ?>"
                      data-title="<?= htmlspecialchars((string) ($e['title'] ?? '')) ?>"
                      data-stage="<?= htmlspecialchars($proposalStage) ?>"
                      data-approve-ready="<?= $approveReady ? '1' : '0' ?>"
                      data-requirements="<?= htmlspecialchars((string) json_encode($proposalRequirements, JSON_UNESCAPED_SLASHES | JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES) ?>"
                      data-submissions="<?= htmlspecialchars((string) json_encode(array_values($proposalSubmissions), JSON_UNESCAPED_SLASHES | JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES) ?>"
                      data-summary="<?= htmlspecialchars((string) json_encode($proposalSummaryVisible, JSON_UNESCAPED_SLASHES | JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES) ?>"
                      data-student-requirements="<?= htmlspecialchars((string) (json_encode($studentRequirementsPayload, JSON_UNESCAPED_SLASHES | JSON_HEX_APOS | JSON_HEX_QUOT) ?: '[]'), ENT_QUOTES) ?>">
                      <?= $proposalRequirements === []
                        ? 'Send Req'
                        : ($proposalStage === 'under_review' ? 'Review Docs' : 'View') ?>
                    </button>
                  <?php endif; ?>

                  <?php if ($status === 'approved'): ?>
                    <button
                      class="btnPublishEvent rounded-lg bg-emerald-600 text-white px-4 py-1.5 text-[13px] font-bold hover:bg-emerald-500 transition-colors border border-emerald-600 shadow-sm"
                      data-id="<?= htmlspecialchars($eid) ?>"
                      data-title="<?= htmlspecialchars((string) ($e['title'] ?? '')) ?>"
                      data-created_by="<?= htmlspecialchars($createdBy) ?>">
                      Publish
                    </button>
                  <?php endif; ?>

                  <?php if ($status !== 'pending'): ?>
                    <button
                      class="btnArchive rounded-lg border border-red-200 bg-red-50 px-3 py-1.5 text-xs font-semibold text-red-800 hover:bg-red-100 transition"
                      data-id="<?= htmlspecialchars($eid) ?>"
                      data-title="<?= htmlspecialchars((string) ($e['title'] ?? '')) ?>">Archive</button>
                  <?php endif; ?>
                <?php endif; ?>
                <?php if ($canEdit): ?>
                  <button
                    class="btnEdit rounded-lg border border-zinc-200 bg-white px-3 py-1.5 text-xs text-zinc-800 hover:bg-zinc-50 transition font-medium"
                    data-id="<?= htmlspecialchars($eid) ?>"
                    data-status="<?= htmlspecialchars($status) ?>"
                    data-title="<?= htmlspecialchars((string) ($e['title'] ?? '')) ?>"
                    data-location="<?= htmlspecialchars((string) ($e['location'] ?? '')) ?>"
                    data-location-full="<?= htmlspecialchars((string) ($e['location'] ?? '')) ?>"
                    data-description="<?= htmlspecialchars((string) ($e['description'] ?? '')) ?>"
                    data-start_at="<?= htmlspecialchars((string) ($e['start_at'] ?? '')) ?>"
                    data-end_at="<?= htmlspecialchars((string) ($e['end_at'] ?? '')) ?>"
                    data-event_mode="<?= htmlspecialchars(event_uses_sessions($e) ? 'seminar_based' : 'simple') ?>"
                    data-sessions="<?= htmlspecialchars((string) (json_encode($e['sessions'] ?? [], JSON_UNESCAPED_SLASHES | JSON_HEX_APOS | JSON_HEX_QUOT) ?: '[]'), ENT_QUOTES) ?>"
                    data-event_type="<?= htmlspecialchars((string) ($e['event_type'] ?? 'Event')) ?>"
                    data-event_for="<?= htmlspecialchars((string) ($e['event_for'] ?? 'All')) ?>"
                    data-grace_time="<?= htmlspecialchars((string) ($e['grace_time'] ?? '30')) ?>"
                    data-is_free_event="<?= (($e['is_free_event'] ?? true) ? '1' : '0') ?>"
                    data-event_fee="<?= htmlspecialchars((string) ($e['event_fee'] ?? '')) ?>"
                    data-registration_limit="<?= htmlspecialchars((string) ($e['registration_limit'] ?? '')) ?>"
                    data-registration_close_weeks="<?= htmlspecialchars((string) ($e['registration_close_weeks'] ?? '1')) ?>"
                    data-proposal_stage="<?= htmlspecialchars($proposalStage) ?>"
                    data-proposal_requirements="<?= htmlspecialchars((string) json_encode($proposalRequirements, JSON_UNESCAPED_SLASHES | JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES) ?>"
                    data-proposal_submissions="<?= htmlspecialchars((string) json_encode(array_values($proposalSubmissions), JSON_UNESCAPED_SLASHES | JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES) ?>"
                    data-cover_image_url="<?= htmlspecialchars((string) ($e['cover_image_url'] ?? '')) ?>"
                    data-student_requirements="<?= htmlspecialchars((string) (json_encode($studentRequirementsPayload, JSON_UNESCAPED_SLASHES | JSON_HEX_APOS | JSON_HEX_QUOT) ?: '[]'), ENT_QUOTES) ?>">View/Edit</button>
                <?php endif; ?>

              </div>

            </div>
          </div>
        <?php endforeach; ?>

        <div id="eventListEmptyState" class="text-center py-16 text-zinc-600 <?= count($events) === 0 ? '' : 'hidden' ?>">
            <div
              class="w-16 h-16 rounded-2xl bg-zinc-100 border border-zinc-200 flex items-center justify-center mx-auto mb-4">
              <svg class="w-8 h-8 text-zinc-500" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round"
                  d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 11.25v7.5" />
              </svg>
            </div>
            <h3 class="text-zinc-800 font-medium mb-1">No events yet</h3>
            <?php if ($role === 'teacher'): ?>
            <p class="text-sm">Click <span class="text-orange-700 font-medium">"Create Event"</span> to get started.</p>
            <?php else: ?>
            <p class="text-sm">Teacher proposals will appear here once submitted.</p>
            <?php endif; ?>
        </div>
      </div>
    </div>
  </div>
</div>

<?php if ($role === 'admin'): ?>
  <div id="publishTeacherModal" class="modal-backdrop">
    <div
      class="relative w-full max-w-2xl mx-4 bg-white border border-zinc-200 rounded-3xl shadow-xl overflow-hidden scale-95 transition-transform duration-300"
      id="publishTeacherPanel" style="transform: translateY(100%);">
      <div class="px-6 py-5 border-b border-zinc-200 bg-zinc-50">
        <div class="flex items-start justify-between gap-4">
          <div>
            <div class="flex items-center gap-3 mb-1">
              <div
                class="w-10 h-10 rounded-2xl bg-emerald-100 border border-emerald-200 flex items-center justify-center text-emerald-700">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2.4" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" />
                </svg>
              </div>
              <div>
                <h3 class="text-xl font-bold text-zinc-900 tracking-tight">Assign Event Teachers</h3>
                <p class="text-sm text-zinc-500">Pick the teachers included in this event or batch before publishing.</p>
              </div>
            </div>
          </div>
          <button type="button" id="btnClosePublishTeacherModal"
            class="p-2 rounded-xl text-zinc-400 hover:text-zinc-700 hover:bg-zinc-100 transition">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2.4" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
            </svg>
          </button>
        </div>
      </div>

      <div class="px-6 py-5 max-h-[65vh] overflow-y-auto">
        <input type="hidden" id="publishTeacherEventId" value="">
        <input type="hidden" id="publishTeacherCreatorId" value="">

        <div class="rounded-2xl border border-orange-100 bg-orange-50/80 px-4 py-4 mb-5 text-sm text-orange-900">
          <div class="font-bold mb-1">Publishing <span id="publishTeacherEventTitle">this event</span></div>
          <div>Selected teachers will be part of this specific event or batch. QR scanner assignment will be managed
            separately after publishing.</div>
        </div>

        <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
          <div class="text-sm text-zinc-500">
            <span id="publishTeacherCount" class="font-bold text-zinc-900">0</span> teacher(s) selected
          </div>
          <div class="flex items-center gap-2">
            <button type="button" id="btnPublishSelectAllTeachers"
              class="rounded-xl border border-sky-200 bg-sky-50 px-4 py-2 text-sm font-bold text-sky-700 hover:bg-sky-100 transition">All
              Teachers</button>
            <button type="button" id="btnPublishClearTeachers"
              class="rounded-xl border border-zinc-200 bg-white px-4 py-2 text-sm font-bold text-zinc-700 hover:bg-zinc-50 transition">Clear</button>
          </div>
        </div>

        <?php if (empty($teacherAccounts)): ?>
          <div class="rounded-2xl border-2 border-dashed border-zinc-200 bg-zinc-50 px-6 py-12 text-center text-zinc-500">
            No teacher accounts found yet.
          </div>
        <?php else: ?>
          <div class="space-y-3" id="publishTeacherList">
            <?php foreach ($teacherAccounts as $teacher): ?>
              <?php
              $teacherId = (string) ($teacher['id'] ?? '');
              $fullName = trim((string) (($teacher['first_name'] ?? '') . ' ' . ($teacher['last_name'] ?? '') . ' ' . ($teacher['suffix'] ?? '')));
              $email = (string) ($teacher['email'] ?? '');
              $initialsParts = preg_split('/\s+/', trim($fullName)) ?: [];
              $initials = '';
              foreach ($initialsParts as $part) {
                if ($part !== '') {
                  $initials .= strtoupper($part[0]);
                }
                if (strlen($initials) >= 2) {
                  break;
                }
              }
              if ($initials === '')
                $initials = 'T';
              ?>
              <label
                class="publish-teacher-card flex items-center gap-4 rounded-2xl border border-zinc-200 bg-white px-4 py-4 hover:border-zinc-300 transition">
                <input type="checkbox" value="<?= htmlspecialchars($teacherId) ?>"
                  class="publish-teacher-checkbox h-5 w-5 rounded border-zinc-300 text-orange-600 focus:ring-orange-500">
                <div
                  class="w-12 h-12 rounded-2xl bg-gradient-to-br from-orange-500 to-red-600 text-white flex items-center justify-center font-black text-sm shadow-sm">
                  <?= htmlspecialchars($initials) ?>
                </div>
                <div class="min-w-0 flex-1">
                  <div class="flex items-center gap-2 flex-wrap">
                    <div class="text-sm font-bold text-zinc-900 truncate">
                      <?= htmlspecialchars($fullName !== '' ? $fullName : 'Unnamed Teacher') ?></div>
                    <span
                      class="creator-badge hidden text-[10px] font-bold uppercase tracking-widest rounded-full border border-emerald-200 bg-emerald-50 px-2 py-0.5 text-emerald-700">
                      Creator
                    </span>
                  </div>
                  <div class="text-xs text-zinc-500 mt-1"><?= htmlspecialchars($email) ?></div>
                </div>
              </label>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>

      <div
        class="px-6 py-5 border-t border-zinc-200 bg-white flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
        <p class="text-xs text-zinc-500">Students and selected teachers will receive notifications once publishing
          succeeds.</p>
        <div class="flex items-center gap-2">
          <button type="button" id="btnCancelPublishTeachers"
            class="rounded-xl border border-zinc-200 bg-white px-4 py-2 text-sm font-bold text-zinc-700 hover:bg-zinc-50 transition">Cancel</button>
          <button type="button" id="btnConfirmPublishTeachers"
            class="rounded-xl border border-emerald-600 bg-emerald-600 px-5 py-2 text-sm font-bold text-white hover:bg-emerald-700 transition shadow-sm">Publish
            Event</button>
        </div>
      </div>
    </div>
  </div>
<?php endif; ?>

<!-- ═══════════  PROPOSAL REQUIREMENTS MODAL  ═══════════ -->
<div id="proposalRequirementsModal" class="modal-backdrop">
  <div class="modal-panel max-w-3xl">
    <div class="flex items-start justify-between gap-4 border-b border-zinc-200 px-5 py-5 sm:px-6">
      <div>
        <div class="text-xs font-black uppercase tracking-[0.24em] text-orange-600">Proposal requirements</div>
        <h3 id="proposalRequirementsTitle" class="mt-1 text-xl font-bold text-zinc-900">Submitted proposal documents</h3>
        <p class="mt-1 text-sm text-zinc-500">
          Review the requirement package and uploaded files submitted by the teacher.
        </p>
      </div>
      <button type="button" id="btnCloseProposalRequirements"
        class="rounded-xl p-2 text-zinc-400 transition hover:bg-zinc-100 hover:text-zinc-700">
        <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
        </svg>
      </button>
    </div>

    <div class="modal-body px-5 py-5 sm:px-6">
      <input type="hidden" id="proposalRequirementsEventId" value="" />
      <input type="hidden" id="proposalRequirementsEventTitle" value="" />
      <input type="hidden" id="proposalRequirementsApproveReady" value="0" />

      <div class="hidden rounded-2xl border border-zinc-200 bg-zinc-50/80 p-4">
        <div class="flex flex-wrap items-center justify-between gap-3">
          <div>
            <div class="text-sm font-semibold text-zinc-900">Requirement progress</div>
            <div class="text-xs text-zinc-500">Teachers must complete every requested upload before final review.</div>
          </div>
          <div id="proposalRequirementsStageBadge"
            class="rounded-full border border-orange-200 bg-orange-50 px-3 py-1 text-[11px] font-bold text-orange-700">
            Needs requirements
          </div>
        </div>
        <div class="mt-4 proposal-progress-track">
          <div id="proposalRequirementsProgressBar" class="proposal-progress-bar" style="width: 0%"></div>
        </div>
        <div class="mt-2 flex items-center justify-between text-xs text-zinc-500">
          <span id="proposalRequirementsProgressText">0 of 0 uploaded</span>
          <span id="proposalRequirementsProgressPercent">0%</span>
        </div>
      </div>

      <div id="proposalRequirementsEditorSection" class="mt-5 hidden rounded-2xl border border-zinc-200 bg-white p-4">
        <div class="flex items-center justify-between gap-3">
          <div>
            <div class="text-sm font-semibold text-zinc-900">Common document presets</div>
            <div class="text-xs text-zinc-500">Tick the standard forms that usually apply to this proposal.</div>
          </div>
        </div>
        <div class="mt-4 grid gap-3 sm:grid-cols-2">
          <label class="flex items-start gap-3 rounded-2xl border border-zinc-200 bg-zinc-50 px-4 py-3">
            <input type="checkbox" id="presetAPP" class="mt-1 h-4 w-4 rounded border-zinc-300 text-orange-600 focus:ring-orange-500" />
            <span>
              <span class="block text-sm font-bold text-zinc-900">APP</span>
              <span class="block text-xs text-zinc-500">Annual Project Plan Form</span>
            </span>
          </label>
          <label class="flex items-start gap-3 rounded-2xl border border-zinc-200 bg-zinc-50 px-4 py-3">
            <input type="checkbox" id="presetAPF" class="mt-1 h-4 w-4 rounded border-zinc-300 text-orange-600 focus:ring-orange-500" />
            <span>
              <span class="block text-sm font-bold text-zinc-900">APF</span>
              <span class="block text-xs text-zinc-500">Activity Proposal Form</span>
            </span>
          </label>
        </div>
      </div>

      <div id="proposalRequirementsAdditionalSection" class="mt-5 hidden rounded-2xl border border-zinc-200 bg-white p-4">
        <div class="flex items-center justify-between gap-3">
          <div>
            <div class="text-sm font-semibold text-zinc-900">Additional requirements</div>
            <div class="text-xs text-zinc-500">Add any other documents this proposal needs before final approval.</div>
          </div>
          <button type="button" id="btnAddProposalRequirement"
            class="rounded-xl border border-zinc-200 bg-zinc-50 px-3 py-2 text-xs font-bold text-zinc-700 transition hover:bg-zinc-100">
            Add requirement
          </button>
        </div>
        <div id="proposalRequirementsList" class="mt-4 space-y-3"></div>
      </div>

      <div class="mt-5 rounded-2xl border border-zinc-200 bg-white p-4">
        <div class="flex items-center justify-between gap-3">
          <div>
            <div class="text-sm font-semibold text-zinc-900">Uploaded files</div>
            <div class="text-xs text-zinc-500">Review which requirements already have a teacher upload attached.</div>
          </div>
          <div id="proposalRequirementsUploadSummary"
            class="rounded-full border border-zinc-200 bg-zinc-50 px-3 py-1 text-[11px] font-bold text-zinc-600">
            No uploads yet
          </div>
        </div>
        <div id="proposalRequirementsUploads" class="mt-4 space-y-3"></div>
      </div>

      <div class="mt-5 rounded-2xl border border-sky-200 bg-sky-50/40 p-4">
        <div class="flex items-center justify-between gap-3">
          <div>
            <div class="flex flex-wrap items-center gap-2">
              <div class="text-sm font-semibold text-zinc-900">Student requirements</div>
              <span class="rounded-full border border-sky-200 bg-sky-50 px-2.5 py-0.5 text-[10px] font-bold uppercase tracking-wide text-sky-700">For students</span>
            </div>
            <div class="mt-1 text-xs text-zinc-500">Documents demanded from students before they can register for this event.</div>
          </div>
          <div id="proposalStudentRequirementsSummary"
            class="rounded-full border border-sky-200 bg-white px-3 py-1 text-[11px] font-bold text-sky-700">
            None
          </div>
        </div>
        <div id="proposalStudentRequirementsList" class="mt-4 space-y-2"></div>
      </div>
    </div>

    <div class="flex items-center justify-between gap-3 border-t border-zinc-200 bg-zinc-50 px-5 py-4 sm:px-6">
      <button type="button" id="btnCancelProposalRequirements"
        class="rounded-xl border border-zinc-200 bg-white px-4 py-2.5 text-sm font-semibold text-zinc-700 transition hover:bg-zinc-100">
        Close
      </button>
      <div class="flex items-center gap-2">
        <div id="proposalReviewActions" class="hidden items-center gap-2">
          <button type="button" id="btnRejectFromReview"
            class="rounded-xl border border-red-200 bg-red-50 px-4 py-2.5 text-sm font-bold text-red-700 transition hover:bg-red-100">
            Reject
          </button>
          <button type="button" id="btnApproveFromReview"
            class="rounded-xl bg-emerald-600 px-5 py-2.5 text-sm font-bold text-white shadow-sm transition hover:bg-emerald-500 disabled:opacity-50 disabled:cursor-not-allowed"
            disabled title="Waiting for the teacher to submit documents for review.">
            Approve
          </button>
        </div>
        <button type="button" id="btnSaveProposalRequirements"
          hidden
          class="rounded-xl bg-gradient-to-r from-orange-600 to-red-600 px-5 py-2.5 text-sm font-bold text-white shadow-lg shadow-orange-600/20 transition hover:from-orange-500 hover:to-red-500">
          Send Requirements
        </button>
      </div>
    </div>
  </div>
</div>

<!-- ═══════════  PROPOSAL FILE PREVIEW MODAL  ═══════════ -->
<div id="proposalFilePreviewModal" class="fixed inset-0 z-[120] hidden items-center justify-center p-3 sm:p-6 bg-black/70 backdrop-blur-sm">
  <div class="w-full max-w-4xl max-h-[92vh] flex flex-col rounded-2xl bg-white shadow-2xl overflow-hidden">
    <div class="flex items-center justify-between gap-3 px-4 py-3 border-b border-zinc-200 shrink-0 bg-zinc-50">
      <div class="min-w-0">
        <div id="proposalFilePreviewLabel" class="text-[11px] font-bold uppercase tracking-wide text-zinc-500 truncate"></div>
        <div id="proposalFilePreviewName" class="text-sm font-bold text-zinc-900 truncate"></div>
      </div>
      <div class="flex items-center gap-2 shrink-0">
        <a id="proposalFilePreviewOpenTab" href="#" target="_blank" rel="noopener"
          class="rounded-lg border border-zinc-200 bg-white px-3 py-1.5 text-xs font-semibold text-zinc-700 hover:bg-zinc-50">Open tab</a>
        <button type="button" id="btnCloseProposalFilePreview"
          class="flex items-center justify-center w-8 h-8 rounded-lg border border-zinc-200 text-zinc-500 hover:bg-white" title="Close">
          <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
          </svg>
        </button>
      </div>
    </div>
    <div id="proposalFilePreviewBody" class="flex-1 overflow-auto bg-zinc-100 min-h-[50vh] flex items-center justify-center p-2"></div>
  </div>
</div>

<!-- ═══════════  REJECT PROPOSAL MODAL (Matches Page 34) ═══════════ -->
<div id="rejectModal" class="modal-backdrop">
  <div
    class="relative w-full max-w-sm mx-4 bg-white border border-zinc-200 rounded-3xl shadow-xl overflow-hidden scale-95 transition-transform duration-300"
    id="rejectPanel" style="transform: translateY(100%);">
    <div class="p-6">
      <div class="flex items-center gap-4 mb-4">
        <div
          class="w-12 h-12 rounded-full bg-red-100 border border-red-200 flex items-center justify-center flex-shrink-0 text-red-600">
          <svg class="w-6 h-6" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round"
              d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
          </svg>
        </div>
        <div>
          <h3 class="text-xl font-bold text-zinc-900 tracking-tight leading-none">Reject Proposal?</h3>
          <p class="text-sm text-zinc-500 mt-1 font-medium">This action cannot be undone.</p>
        </div>
      </div>

      <p class="text-[13px] text-zinc-600 mb-3 px-1 leading-relaxed">Are you sure you want to reject the proposal for
        <span id="rejectEventName" class="font-bold text-zinc-900"></span>? Please provide a reason to notify the event
        coordinator.</p>

      <div class="mt-2">
        <label class="block text-xs font-black text-zinc-500 uppercase tracking-widest mb-1.5 px-1">Reason for
          refusing</label>
        <textarea id="rejectReason" rows="3"
          class="w-full rounded-xl bg-zinc-50 border border-zinc-200 px-4 py-3 text-sm text-zinc-900 outline-none focus:ring-2 focus:ring-red-500/30 focus:border-red-400 resize-none transition"
          placeholder="e.g. Conflicts with midterm examination week..."></textarea>
      </div>
      <input type="hidden" id="rejectEventId" value="" />
    </div>

    <!-- Actions -->
    <div class="flex border-t border-zinc-200 bg-zinc-50">
      <button id="btnCancelReject"
        class="flex-1 py-3.5 text-[13px] font-bold text-zinc-600 hover:bg-zinc-100 transition border-r border-zinc-200">Cancel</button>
      <button id="btnConfirmReject"
        class="flex-1 py-3.5 text-[13px] font-bold text-white bg-red-600 hover:bg-red-700 transition shadow-sm">Reject
        Proposal</button>
    </div>
  </div>
</div>

<script>
  // ── Modal helpers ──
  const eventModal = document.getElementById('eventModal');
  const eventForm = document.getElementById('eventForm');
  const archiveModal = document.getElementById('archiveModal');
  const publishTeacherModal = document.getElementById('publishTeacherModal');
  const publishTeacherPanel = document.getElementById('publishTeacherPanel');
  const proposalRequirementsModal = document.getElementById('proposalRequirementsModal');
  const proposalRequirementsTitle = document.getElementById('proposalRequirementsTitle');
  const proposalRequirementsEventId = document.getElementById('proposalRequirementsEventId');
  const proposalRequirementsStageBadge = document.getElementById('proposalRequirementsStageBadge');
  const proposalRequirementsProgressBar = document.getElementById('proposalRequirementsProgressBar');
  const proposalRequirementsProgressText = document.getElementById('proposalRequirementsProgressText');
  const proposalRequirementsProgressPercent = document.getElementById('proposalRequirementsProgressPercent');
  const proposalRequirementsUploadSummary = document.getElementById('proposalRequirementsUploadSummary');
  const proposalRequirementsUploads = document.getElementById('proposalRequirementsUploads');
  const proposalStudentRequirementsList = document.getElementById('proposalStudentRequirementsList');
  const proposalStudentRequirementsSummary = document.getElementById('proposalStudentRequirementsSummary');
  const proposalRequirementsEditorSection = document.getElementById('proposalRequirementsEditorSection');
  const proposalRequirementsAdditionalSection = document.getElementById('proposalRequirementsAdditionalSection');
  const proposalRequirementsList = document.getElementById('proposalRequirementsList');
  const btnAddProposalRequirement = document.getElementById('btnAddProposalRequirement');
  const btnSaveProposalRequirements = document.getElementById('btnSaveProposalRequirements');
  const presetAPP = document.getElementById('presetAPP');
  const presetAPF = document.getElementById('presetAPF');

  const proposalStageStyles = {
    pending_requirements: {
      label: 'Needs requirements',
      className: 'rounded-full border border-amber-200 bg-amber-50 px-3 py-1 text-[11px] font-bold text-amber-700'
    },
    requirements_requested: {
      label: 'Waiting on teacher',
      className: 'rounded-full border border-orange-200 bg-orange-50 px-3 py-1 text-[11px] font-bold text-orange-700'
    },
    under_review: {
      label: 'Under review',
      className: 'rounded-full border border-violet-200 bg-violet-50 px-3 py-1 text-[11px] font-bold text-violet-700'
    },
    approved: {
      label: 'Ready for publish',
      className: 'rounded-full border border-emerald-200 bg-emerald-50 px-3 py-1 text-[11px] font-bold text-emerald-700'
    }
  };

  let proposalRequirementState = {
    stage: 'pending_requirements',
    requirements: [],
    submissions: [],
    summary: { total: 0, submitted: 0, percent: 0 },
    studentRequirements: []
  };

  function openModal(el) { el.classList.add('active'); document.body.style.overflow = 'hidden'; }
  function closeModal(el) { el.classList.remove('active'); document.body.style.overflow = ''; }
  function closePublishTeacherAssignmentModal() {
    if (!publishTeacherModal || !publishTeacherPanel) return;
    publishTeacherModal.classList.remove('active');
    publishTeacherPanel.style.transform = 'translateY(100%)';
    document.body.style.overflow = '';
  }
  function closeProposalRequirementsModal() {
    if (!proposalRequirementsModal) return;
    proposalRequirementsModal.classList.remove('active');
    document.body.style.overflow = '';
  }

  function safeJsonParse(raw, fallback) {
    try {
      return JSON.parse(raw || '');
    } catch (_) {
      return fallback;
    }
  }

  function escapeHtml(value) {
    return String(value ?? '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  const proposalFilePreviewModal = document.getElementById('proposalFilePreviewModal');
  const proposalFilePreviewBody = document.getElementById('proposalFilePreviewBody');
  const proposalFilePreviewLabel = document.getElementById('proposalFilePreviewLabel');
  const proposalFilePreviewName = document.getElementById('proposalFilePreviewName');
  const proposalFilePreviewOpenTab = document.getElementById('proposalFilePreviewOpenTab');

  async function resolvePrivateStorageUrl({ url = '', path = '', bucket = 'proposal-documents' } = {}) {
    const fileUrl = String(url || '').trim();
    const objectPath = String(path || '').trim();
    if (!fileUrl && !objectPath) return '';
    try {
      const res = await fetch('/api/storage_signed_url.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
        body: JSON.stringify({
          bucket: bucket || 'proposal-documents',
          path: objectPath,
          url: fileUrl,
          expires_in: 3600,
          csrf_token: window.CSRF_TOKEN || '',
        }),
      });
      const data = await res.json().catch(() => ({}));
      if (res.ok && data?.ok && data?.signed_url) {
        return String(data.signed_url);
      }
      console.warn('storage_signed_url failed', data?.error || res.status);
    } catch (err) {
      console.warn('storage_signed_url error', err);
    }
    return fileUrl;
  }

  async function openProposalFilePreview({ url, path = '', bucket = 'proposal-documents', label = '', fileName = '', mimeType = '' } = {}) {
    const rawUrl = String(url || '').trim();
    const objectPath = String(path || '').trim();
    if ((!rawUrl && !objectPath) || !proposalFilePreviewModal || !proposalFilePreviewBody) return;

    proposalFilePreviewBody.innerHTML = `
      <div class="text-sm font-medium text-zinc-500 py-10">Loading secure preview…</div>
    `;
    proposalFilePreviewModal.classList.remove('hidden');
    proposalFilePreviewModal.classList.add('flex');

    const fileUrl = await resolvePrivateStorageUrl({
      url: rawUrl,
      path: objectPath,
      bucket: bucket || 'proposal-documents',
    });
    if (!fileUrl) {
      proposalFilePreviewBody.innerHTML = `
        <div class="text-center p-8">
          <p class="text-sm text-red-600 font-semibold mb-2">Unable to open file</p>
          <p class="text-xs text-zinc-500">Confirm the proposal-documents bucket exists in Supabase Storage.</p>
        </div>
      `;
      return;
    }

    const mime = String(mimeType || '').toLowerCase();
    const isPdf = mime === 'application/pdf' || /\.pdf(\?|$)/i.test(fileUrl) || /\.pdf(\?|$)/i.test(fileName);
    const isImage = mime.startsWith('image/') || /\.(png|jpe?g|webp|gif)(\?|$)/i.test(fileUrl) || /\.(png|jpe?g|webp|gif)(\?|$)/i.test(fileName);
    const isDoc = /\.(docx?|DOCX?)(\?|$)/i.test(fileUrl)
      || /\.(docx?)(\?|$)/i.test(fileName)
      || mime.includes('msword')
      || mime.includes('officedocument.wordprocessingml');

    if (proposalFilePreviewLabel) proposalFilePreviewLabel.textContent = label || 'Proposal document';
    if (proposalFilePreviewName) {
      proposalFilePreviewName.textContent = fileName || (isPdf ? 'PDF preview' : (isImage ? 'Image preview' : 'File preview'));
    }
    if (proposalFilePreviewOpenTab) proposalFilePreviewOpenTab.href = fileUrl;

    if (isImage) {
      proposalFilePreviewBody.innerHTML = `<img src="${escapeHtml(fileUrl)}" alt="${escapeHtml(fileName || label || 'Document')}" class="max-h-[75vh] max-w-full object-contain rounded-lg shadow-sm bg-white" />`;
    } else if (isPdf) {
      proposalFilePreviewBody.innerHTML = `<iframe src="${escapeHtml(fileUrl)}" title="${escapeHtml(fileName || 'PDF')}" class="w-full h-[75vh] rounded-lg bg-white border border-zinc-200"></iframe>`;
    } else if (isDoc) {
      // Office Online cannot reach private signed URLs reliably — prefer Open tab.
      proposalFilePreviewBody.innerHTML = `
        <div class="text-center p-8">
          <p class="text-sm text-zinc-600 mb-3">Word files open best in a new tab (private storage).</p>
          <a href="${escapeHtml(fileUrl)}" target="_blank" rel="noopener"
            class="inline-flex rounded-lg bg-emerald-600 px-4 py-2 text-sm font-bold text-white hover:bg-emerald-700">Open in new tab</a>
        </div>`;
    } else {
      proposalFilePreviewBody.innerHTML = `
        <div class="text-center p-8">
          <p class="text-sm text-zinc-600 mb-3">Inline preview is not available for this file type.</p>
          <a href="${escapeHtml(fileUrl)}" target="_blank" rel="noopener"
            class="inline-flex rounded-lg bg-emerald-600 px-4 py-2 text-sm font-bold text-white hover:bg-emerald-700">Open in new tab</a>
        </div>`;
    }
  }

  function closeProposalFilePreview() {
    if (!proposalFilePreviewModal) return;
    proposalFilePreviewModal.classList.add('hidden');
    proposalFilePreviewModal.classList.remove('flex');
    if (proposalFilePreviewBody) proposalFilePreviewBody.innerHTML = '';
    if (proposalFilePreviewOpenTab) proposalFilePreviewOpenTab.href = '#';
  }

  document.getElementById('btnCloseProposalFilePreview')?.addEventListener('click', closeProposalFilePreview);
  proposalFilePreviewModal?.addEventListener('click', (e) => {
    if (e.target === proposalFilePreviewModal) closeProposalFilePreview();
  });
  document.addEventListener('click', (e) => {
    const btn = e.target.closest('[data-proposal-file-preview]');
    if (!btn) return;
    e.preventDefault();
    openProposalFilePreview({
      url: btn.getAttribute('data-file-url') || '',
      path: btn.getAttribute('data-file-path') || '',
      bucket: btn.getAttribute('data-file-bucket') || 'proposal-documents',
      label: btn.getAttribute('data-file-label') || '',
      fileName: btn.getAttribute('data-file-name') || '',
      mimeType: btn.getAttribute('data-file-mime') || '',
    });
  });

  function proposalRequirementCodeFromLabel(label, index) {
    const parts = String(label || '').trim().split(/\s+/).filter(Boolean);
    const acronym = parts.map((part) => part[0]?.toUpperCase() || '').join('').slice(0, 6);
    if (acronym.length >= 2) return acronym;
    return `DOC${index + 1}`;
  }

  function createProposalRequirementRow(label = '') {
    const row = document.createElement('div');
    row.className = 'requirements-row flex items-center gap-2';
    row.innerHTML = `
      <div class="flex-1 rounded-2xl border border-zinc-200 bg-zinc-50 px-4 py-3">
        <div class="text-[10px] font-black uppercase tracking-[0.18em] text-zinc-400">Requirement name</div>
        <input type="text" value="${escapeHtml(label)}"
          class="proposal-requirement-input mt-1 w-full border-0 bg-transparent px-0 text-sm font-semibold text-zinc-900 outline-none focus:ring-0"
          placeholder="e.g. Budget Request Form">
      </div>
      <button type="button"
        class="btnRemoveProposalRequirement rounded-xl border border-red-200 bg-red-50 px-3 py-3 text-xs font-bold text-red-700 transition hover:bg-red-100">
        Remove
      </button>
    `;
    row.querySelector('.btnRemoveProposalRequirement')?.addEventListener('click', () => row.remove());
    return row;
  }

  function renderProposalUploads() {
    if (!proposalRequirementsUploads || !proposalRequirementsUploadSummary) return;
    proposalRequirementsUploads.innerHTML = '';

    const submissionsByRequirement = {};
    (proposalRequirementState.submissions || []).forEach((submission) => {
      const requirementId = String(submission?.requirement_id || '').trim();
      if (requirementId) submissionsByRequirement[requirementId] = submission;
    });

    const requirements = proposalRequirementState.requirements || [];
    if (!requirements.length) {
      proposalRequirementsUploads.innerHTML = `
        <div class="rounded-2xl border border-dashed border-zinc-200 bg-zinc-50 px-4 py-6 text-center text-sm text-zinc-500">
          No document requirements have been sent yet.
        </div>
      `;
      proposalRequirementsUploadSummary.textContent = 'No uploads yet';
      return;
    }

    requirements.forEach((requirement) => {
      const requirementId = String(requirement?.id || '').trim();
      const code = String(requirement?.code || 'DOC').trim() || 'DOC';
      const label = String(requirement?.label || code).trim() || code;
      const submission = requirementId ? submissionsByRequirement[requirementId] : null;
      const fileUrl = String(submission?.file_url || '').trim();
      const filePath = String(submission?.file_path || '').trim();
      const fileName = String(submission?.file_name || '').trim();
      const mimeType = String(submission?.mime_type || '').trim();
      const uploadedAt = String(submission?.updated_at || submission?.uploaded_at || '').trim();
      const uploadedText = uploadedAt ? new Date(uploadedAt).toLocaleString() : '';
      const hasFile = fileUrl !== '' || filePath !== '';

      const item = document.createElement('div');
      item.className = 'rounded-2xl border border-zinc-200 bg-zinc-50 px-4 py-3';
      item.innerHTML = `
        <div class="min-w-0">
          <div class="flex items-center gap-2">
            <span class="rounded-full border border-zinc-200 bg-white px-2 py-0.5 text-[10px] font-black text-zinc-700">${escapeHtml(code)}</span>
            <div class="text-sm font-semibold text-zinc-900">${escapeHtml(label)}</div>
          </div>
          <div class="mt-1 text-xs text-zinc-500">
            ${hasFile ? `Uploaded${uploadedText ? ` • ${escapeHtml(uploadedText)}` : ''}` : 'Still waiting for teacher upload'}
          </div>
          <div class="mt-3">
            ${hasFile
              ? `<button type="button"
                  data-proposal-file-preview
                  data-file-url="${escapeHtml(fileUrl)}"
                  data-file-path="${escapeHtml(filePath)}"
                  data-file-bucket="proposal-documents"
                  data-file-label="${escapeHtml(label)}"
                  data-file-name="${escapeHtml(fileName || label)}"
                  data-file-mime="${escapeHtml(mimeType)}"
                  class="inline-flex rounded-xl border border-emerald-200 bg-emerald-50 px-3 py-2 text-xs font-bold text-emerald-700 transition hover:bg-emerald-100">Open file</button>`
              : `<span class="inline-flex rounded-xl border border-zinc-200 bg-white px-3 py-2 text-xs font-bold text-zinc-500">Pending upload</span>`}
          </div>
        </div>
      `;
      proposalRequirementsUploads.appendChild(item);
    });

    proposalRequirementsUploadSummary.textContent = `${proposalRequirementState.summary.submitted || 0}/${proposalRequirementState.summary.total || 0} uploaded`;
  }

  function renderProposalStudentRequirements() {
    if (!proposalStudentRequirementsList || !proposalStudentRequirementsSummary) return;
    proposalStudentRequirementsList.innerHTML = '';

    const items = Array.isArray(proposalRequirementState.studentRequirements)
      ? proposalRequirementState.studentRequirements
      : [];
    const cleaned = items
      .map((item) => {
        if (!item || typeof item !== 'object') return null;
        const code = String(item.code || '').trim();
        const label = String(item.label || code || '').trim();
        if (!label) return null;
        return { code, label };
      })
      .filter(Boolean);

    if (!cleaned.length) {
      proposalStudentRequirementsSummary.textContent = 'None';
      proposalStudentRequirementsList.innerHTML = `
        <div class="rounded-2xl border border-dashed border-sky-200 bg-white px-4 py-5 text-center text-sm text-zinc-500">
          No student documents were demanded for this event. Students can register without uploading docs.
        </div>
      `;
      return;
    }

    proposalStudentRequirementsSummary.textContent = `${cleaned.length} required`;
    cleaned.forEach((item) => {
      const row = document.createElement('div');
      row.className = 'flex items-start gap-3 rounded-2xl border border-sky-200 bg-white px-4 py-3';
      row.innerHTML = `
        <span class="mt-0.5 inline-flex h-6 w-6 shrink-0 items-center justify-center rounded-full border border-sky-200 bg-sky-50 text-[11px] font-black text-sky-700">✓</span>
        <div class="min-w-0">
          <div class="text-sm font-semibold text-zinc-900">${escapeHtml(item.label)}</div>
          <div class="mt-0.5 text-xs text-zinc-500">
            ${item.code && item.code !== 'OTHER'
              ? `Preset • ${escapeHtml(item.code)}`
              : 'Custom requirement students must upload on the app'}
          </div>
        </div>
      `;
      proposalStudentRequirementsList.appendChild(row);
    });
  }

  function renderProposalProgress() {
    const stage = proposalRequirementState.stage || 'pending_requirements';
    const summary = proposalRequirementState.summary || { total: 0, submitted: 0, percent: 0 };
    const style = proposalStageStyles[stage] || proposalStageStyles.pending_requirements;
    proposalRequirementsStageBadge.className = style.className;
    proposalRequirementsStageBadge.textContent = style.label;
    proposalRequirementsProgressBar.style.width = `${Math.max(0, Math.min(100, Number(summary.percent || 0)))}%`;
    proposalRequirementsProgressText.textContent = `${summary.submitted || 0} of ${summary.total || 0} uploaded`;
    proposalRequirementsProgressPercent.textContent = `${summary.percent || 0}%`;
  }

  function populateProposalRequirementEditor(requirements) {
    if (!proposalRequirementsList) return;
    proposalRequirementsList.innerHTML = '';

    const remaining = [];
    let hasAPP = false;
    let hasAPF = false;
    (requirements || []).forEach((requirement) => {
      const code = String(requirement?.code || '').trim().toUpperCase();
      const label = String(requirement?.label || '').trim();
      if (code === 'APP' || /annual project plan/i.test(label)) {
        hasAPP = true;
        return;
      }
      if (code === 'APF' || /activity proposal/i.test(label)) {
        hasAPF = true;
        return;
      }
      remaining.push(label || code);
    });

    if (presetAPP) presetAPP.checked = hasAPP;
    if (presetAPF) presetAPF.checked = hasAPF;

    remaining.forEach((label) => proposalRequirementsList.appendChild(createProposalRequirementRow(label)));
    if (!remaining.length) {
      proposalRequirementsList.appendChild(createProposalRequirementRow(''));
    }
  }

  function collectProposalRequirements() {
    const requirements = [];
    if (presetAPP?.checked) requirements.push({ code: 'APP', label: 'Annual Project Plan Form' });
    if (presetAPF?.checked) requirements.push({ code: 'APF', label: 'Activity Proposal Form' });

    const inputs = proposalRequirementsList?.querySelectorAll('.proposal-requirement-input') || [];
    Array.from(inputs).forEach((input, index) => {
      const label = String(input.value || '').trim();
      if (!label) return;
      requirements.push({ code: proposalRequirementCodeFromLabel(label, index), label });
    });
    return requirements;
  }

  function syncProposalReviewActions() {
    const actions = document.getElementById('proposalReviewActions');
    const approveBtn = document.getElementById('btnApproveFromReview');
    const rejectBtn = document.getElementById('btnRejectFromReview');
    if (!actions) return;

    const saving = btnSaveProposalRequirements && !btnSaveProposalRequirements.hidden;
    // While composing a new requirements request, hide approve/reject.
    if (saving) {
      actions.classList.add('hidden');
      actions.classList.remove('flex');
      return;
    }

    actions.classList.remove('hidden');
    actions.classList.add('flex');

    const ready = (document.getElementById('proposalRequirementsApproveReady')?.value || '0') === '1'
      || String(proposalRequirementState.stage || '').toLowerCase() === 'under_review';
    if (approveBtn) {
      approveBtn.disabled = !ready;
      approveBtn.title = ready
        ? 'Approve this proposal'
        : 'Waiting for the teacher to submit documents for review.';
      approveBtn.classList.toggle('opacity-50', !ready);
      approveBtn.classList.toggle('cursor-not-allowed', !ready);
    }
    if (rejectBtn) rejectBtn.disabled = false;
  }

  function openProposalRequirementsModal(button) {
    if (!proposalRequirementsModal) return;
    proposalRequirementsEventId.value = button.dataset.id || '';
    const title = button.dataset.title || 'Pending proposal';
    const titleInput = document.getElementById('proposalRequirementsEventTitle');
    if (titleInput) titleInput.value = title;
    proposalRequirementsTitle.textContent = `Proposal documents • ${title}`;
    proposalRequirementsEditorSection?.classList.add('hidden');
    proposalRequirementsAdditionalSection?.classList.add('hidden');
    if (btnSaveProposalRequirements) btnSaveProposalRequirements.hidden = true;
    proposalRequirementState = {
      stage: button.dataset.stage || 'pending_requirements',
      requirements: safeJsonParse(button.dataset.requirements, []),
      submissions: safeJsonParse(button.dataset.submissions, []),
      summary: safeJsonParse(button.dataset.summary, { total: 0, submitted: 0, percent: 0 }),
      studentRequirements: (() => {
        const fromBtn = safeJsonParse(button.dataset.studentRequirements, null);
        if (Array.isArray(fromBtn)) return fromBtn;
        const card = button.closest('.event-card');
        return safeJsonParse(card?.dataset?.student_requirements || card?.dataset?.studentRequirements || '[]', []);
      })()
    };
    const approveReadyInput = document.getElementById('proposalRequirementsApproveReady');
    if (approveReadyInput) {
      approveReadyInput.value = (button.dataset.approveReady === '1'
        || String(proposalRequirementState.stage || '').toLowerCase() === 'under_review')
        ? '1'
        : '0';
    }
    populateProposalRequirementEditor(proposalRequirementState.requirements);
    renderProposalProgress();
    renderProposalUploads();
    renderProposalStudentRequirements();
    syncProposalReviewActions();
    proposalRequirementsModal.classList.add('active');
    document.body.style.overflow = 'hidden';
  }

  eventModal.addEventListener('click', (e) => { if (e.target === eventModal) closeModal(eventModal); });
  archiveModal.addEventListener('click', (e) => { if (e.target === archiveModal) closeModal(archiveModal); });
  publishTeacherModal?.addEventListener('click', (e) => { if (e.target === publishTeacherModal) closePublishTeacherAssignmentModal(); });
  proposalRequirementsModal?.addEventListener('click', (e) => { if (e.target === proposalRequirementsModal) closeProposalRequirementsModal(); });

  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') {
      if (proposalFilePreviewModal && !proposalFilePreviewModal.classList.contains('hidden')) {
        closeProposalFilePreview();
        return;
      }
      closeModal(eventModal);
      closeModal(archiveModal);
      closePublishTeacherAssignmentModal();
      closeProposalRequirementsModal();
    }
  });

  document.getElementById('btnCloseProposalRequirements')?.addEventListener('click', closeProposalRequirementsModal);
  document.getElementById('btnCancelProposalRequirements')?.addEventListener('click', closeProposalRequirementsModal);
  btnAddProposalRequirement?.addEventListener('click', () => {
    proposalRequirementsList?.appendChild(createProposalRequirementRow(''));
  });
  document.querySelectorAll('.btnRequirements').forEach((button) => {
    button.addEventListener('click', () => openProposalRequirementsModal(button));
  });
  btnSaveProposalRequirements?.addEventListener('click', async () => {
    const event_id = proposalRequirementsEventId?.value || '';
    const requirements = collectProposalRequirements();
    if (!event_id) {
      alert('Missing event id.');
      return;
    }
    if (!requirements.length) {
      alert('Add at least one required document before sending the request.');
      return;
    }

    btnSaveProposalRequirements.disabled = true;
    const originalText = btnSaveProposalRequirements.textContent;
    btnSaveProposalRequirements.textContent = 'Saving...';
    try {
      const res = await fetch('/api/event_proposal_requirements_save.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ event_id, requirements, csrf_token: window.CSRF_TOKEN })
      });
      const data = await res.json();
      if (!data.ok) throw new Error(data.error || 'Failed to save proposal requirements.');
      window.location.reload();
    } catch (e) {
      alert(e.message || 'Failed to save proposal requirements.');
    } finally {
      btnSaveProposalRequirements.disabled = false;
      btnSaveProposalRequirements.textContent = originalText;
    }
  });

  // Wizard initialization (simple + seminar based)
  let step = 1;
  let eventModalReadOnly = false;
  const teacherProposalMode = <?= $role === 'teacher' ? 'true' : 'false' ?>;
  const maxWizardStep = teacherProposalMode ? 4 : 3;
  let teacherProposalEditState = {
    status: '',
    stage: '',
    requirements: [],
    submissions: [],
  };

  const structureOptions = Array.from(document.querySelectorAll('.structure-option'));
  const eventModeInput = document.getElementById('event_mode');
  const seminarCountInput = document.getElementById('seminar_count');
  const simpleScheduleSection = document.getElementById('simpleScheduleSection');
  const seminarScheduleSection = document.getElementById('seminarScheduleSection');
  const seminar2Editor = document.getElementById('seminar2Editor');
  const seminarSummaryBadge = document.getElementById('seminarSummaryBadge');

  const startAtInput = document.getElementById('start_at_local');
  const endAtInput = document.getElementById('end_at_local');
  const seminar1StartInput = document.getElementById('seminar1_start_local');
  const seminar1EndInput = document.getElementById('seminar1_end_local');
  const seminar2StartInput = document.getElementById('seminar2_start_local');
  const seminar2EndInput = document.getElementById('seminar2_end_local');
  const registrationCloseWeeksSelect = document.getElementById('registration_close_weeks');
  const registrationCloseWeeksHint = document.getElementById('registrationCloseWeeksHint');
  const teacherProposalRequirementsList = document.getElementById('teacherProposalRequirementsList');
  const btnAddTeacherProposalRequirement = document.getElementById('btnAddTeacherProposalRequirement');
  const teacherProposalCreateSection = document.getElementById('teacherProposalCreateSection');
  const teacherProposalEditSection = document.getElementById('teacherProposalEditSection');
  const teacherProposalEditRequirementsList = document.getElementById('teacherProposalEditRequirementsList');
  let isApplyingSimpleEndDefault = false;

  const flatpickrConfig = {
    enableTime: true,
    noCalendar: false,
    dateFormat: 'Y-m-d H:i',
    altInput: true,
    altFormat: 'F j, Y - h:i K',
    minuteIncrement: 30,
    defaultHour: 7,
    defaultMinute: 0,
    minTime: '07:00',
    position: 'auto center',
    disableMobile: true,
    allowInput: false
  };

  function keepPickerVisible(instance) {
    if (!instance || !instance.calendarContainer || !eventModal) return;
    const modalBody = eventModal.querySelector('.modal-body');
    if (!modalBody) return;

    requestAnimationFrame(() => {
      const calRect = instance.calendarContainer.getBoundingClientRect();
      const bodyRect = modalBody.getBoundingClientRect();
      const pad = 12;

      if (calRect.bottom > bodyRect.bottom - pad) {
        modalBody.scrollTop += calRect.bottom - (bodyRect.bottom - pad);
      } else if (calRect.top < bodyRect.top + pad) {
        modalBody.scrollTop -= (bodyRect.top + pad) - calRect.top;
      }
    });
  }

  function formatLocalForPicker(d) {
    if (!d || Number.isNaN(d.getTime())) return '';
    const pad = (n) => String(n).padStart(2, '0');
    return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())} ${pad(d.getHours())}:${pad(d.getMinutes())}`;
  }

  function toLocalInput(iso) {
    if (!iso) return '';
    const d = new Date(iso);
    return formatLocalForPicker(d);
  }

  function parseLocalDate(value) {
    if (!value) return null;
    const raw = String(value).trim();
    if (!raw) return null;

    const m = raw.match(/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})$/);
    if (m) {
      const d = new Date(
        Number(m[1]),
        Number(m[2]) - 1,
        Number(m[3]),
        Number(m[4]),
        Number(m[5]),
        0,
        0
      );
      return Number.isNaN(d.getTime()) ? null : d;
    }

    const d = new Date(raw);
    return Number.isNaN(d.getTime()) ? null : d;
  }

  function nowLocalDate() {
    const d = new Date();
    d.setSeconds(0, 0);
    return d;
  }

  function earliestAllowedCreateDateTime() {
    const d = new Date();
    d.setHours(0, 0, 0, 0);
    d.setDate(d.getDate() + 1);
    d.setHours(7, 0, 0, 0);
    return d;
  }

  function getEventStartForCloseLimit() {
    const mode = eventModeInput?.value || 'simple';
    if (mode === 'seminar_based') {
      return parseLocalDate((seminar1StartInput?.value || '').trim());
    }
    return parseLocalDate((startAtInput?.value || '').trim());
  }

  function maxRegistrationCloseWeeksFromStart(startDate) {
    if (!startDate || Number.isNaN(startDate.getTime())) return null;
    const startDay = new Date(startDate.getFullYear(), startDate.getMonth(), startDate.getDate());
    const now = new Date();
    const today = new Date(now.getFullYear(), now.getMonth(), now.getDate());
    const diffDays = Math.floor((startDay.getTime() - today.getTime()) / 86400000);
    if (diffDays < 7) return 0;
    return Math.min(4, Math.floor(diffDays / 7));
  }

  function refreshRegistrationCloseOptions(preferredValue) {
    if (!registrationCloseWeeksSelect) return;

    const start = getEventStartForCloseLimit();
    const prevRaw = preferredValue != null
      ? String(preferredValue)
      : String(registrationCloseWeeksSelect.value || '');
    const preferred = Number.parseInt(prevRaw, 10);
    const maxWeeks = start ? maxRegistrationCloseWeeksFromStart(start) : null;

    registrationCloseWeeksSelect.innerHTML = '';

    if (maxWeeks === null) {
      const opt = document.createElement('option');
      opt.value = '';
      opt.textContent = 'Select event start date first';
      registrationCloseWeeksSelect.appendChild(opt);
      registrationCloseWeeksSelect.disabled = true;
      if (registrationCloseWeeksHint) {
        registrationCloseWeeksHint.textContent =
          'Options update after you set the event start date (max 4 weeks before start, and never before today).';
      }
      return;
    }

    if (maxWeeks < 1) {
      const opt = document.createElement('option');
      opt.value = '';
      opt.textContent = 'Not available for this start date';
      registrationCloseWeeksSelect.appendChild(opt);
      registrationCloseWeeksSelect.disabled = true;
      if (registrationCloseWeeksHint) {
        registrationCloseWeeksHint.textContent =
          'Start date is less than 1 week away, so a weeks-based close limit cannot be used. Move the start later, or leave this unset.';
      }
      return;
    }

    registrationCloseWeeksSelect.disabled = false;
    for (let weeks = 1; weeks <= maxWeeks; weeks += 1) {
      const opt = document.createElement('option');
      opt.value = String(weeks);
      opt.textContent = `${weeks} week${weeks === 1 ? '' : 's'} before event`;
      registrationCloseWeeksSelect.appendChild(opt);
    }

    if (Number.isFinite(preferred) && preferred >= 1 && preferred <= maxWeeks) {
      registrationCloseWeeksSelect.value = String(preferred);
    } else {
      registrationCloseWeeksSelect.value = '1';
    }

    if (registrationCloseWeeksHint) {
      registrationCloseWeeksHint.textContent = maxWeeks < 4
        ? `Based on today’s date and the event start, only up to ${maxWeeks} week${maxWeeks === 1 ? '' : 's'} before the event is available (maximum 4).`
        : 'Registration can close up to 4 weeks before the event start.';
    }
  }

  function isBeforeAllowedScheduleTime(date) {
    if (!(date instanceof Date) || Number.isNaN(date.getTime())) return true;
    const hour = date.getHours();
    const minute = date.getMinutes();
    return hour < 7 || (minute !== 0 && minute !== 30);
  }

  function setPickerMin(input, minDate) {
    if (!input) return;
    if (input._flatpickr) {
      input._flatpickr.set('minDate', minDate || null);
    }
    if (minDate) {
      const minDateObj = minDate instanceof Date ? minDate : parseLocalDate(minDate);
      input.min = minDateObj ? formatLocalForPicker(minDateObj) : String(minDate);
    } else {
      input.removeAttribute('min');
    }
  }

  function setPickerMax(input, maxDate) {
    if (!input) return;
    if (input._flatpickr) {
      input._flatpickr.set('maxDate', maxDate || null);
    }
    if (maxDate) {
      const maxDateObj = maxDate instanceof Date ? maxDate : parseLocalDate(maxDate);
      input.max = maxDateObj ? formatLocalForPicker(maxDateObj) : String(maxDate);
    } else {
      input.removeAttribute('max');
    }
  }

  function setPickerValue(input, value) {
    if (!input) return;
    const normalized = (value || '').toString().trim();
    if (input._flatpickr) {
      if (normalized) {
        input._flatpickr.setDate(normalized, true, 'Y-m-d H:i');
      } else {
        input._flatpickr.clear();
      }
      return;
    }
    input.value = normalized;
  }

  function setPickerDisabled(input, disabled) {
    if (!input) return;
    input.disabled = disabled;
    input.classList.toggle('bg-zinc-50', disabled);
    input.classList.toggle('text-zinc-500', disabled);
    input.classList.toggle('cursor-not-allowed', disabled);
    input.classList.toggle('bg-white', !disabled);
    input.classList.toggle('text-zinc-900', !disabled);

    if (input._flatpickr) {
      input._flatpickr.set('clickOpens', !disabled);
      if (input._flatpickr.altInput) {
        input._flatpickr.altInput.disabled = disabled;
        input._flatpickr.altInput.classList.toggle('bg-zinc-50', disabled);
        input._flatpickr.altInput.classList.toggle('text-zinc-500', disabled);
        input._flatpickr.altInput.classList.toggle('cursor-not-allowed', disabled);
        input._flatpickr.altInput.classList.toggle('bg-white', !disabled);
        input._flatpickr.altInput.classList.toggle('text-zinc-900', !disabled);
      }
    }
  }

  function setEndLocked(endInput, locked, clearOnLock = true) {
    if (!endInput) return;
    setPickerDisabled(endInput, locked);
    if (locked && clearOnLock) {
      if (endInput._flatpickr) endInput._flatpickr.clear();
      endInput.value = '';
    }
  }

  function updateEndMin(startInput, endInput) {
    if (!startInput || !endInput) return;

    const startValue = (startInput.value || '').trim();
    const startDate = parseLocalDate(startValue);
    if (startDate) {
      setEndLocked(endInput, false);
      setPickerMin(endInput, startDate);
      setPickerMax(endInput, null);

      const endDate = parseLocalDate(endInput.value);
      if (endDate && endDate < startDate) {
        if (endInput._flatpickr) {
          endInput._flatpickr.setDate(startDate, true, 'Y-m-d H:i');
        } else {
          endInput.value = formatLocalForPicker(startDate);
        }
      }
    } else {
      setEndLocked(endInput, true);
      setPickerMin(endInput, null);
      setPickerMax(endInput, null);
    }
  }

  function isSeminar1Complete() {
    const title = (document.getElementById('seminar1_title')?.value || '').trim();
    const start = parseLocalDate((seminar1StartInput?.value || '').trim());
    const end = parseLocalDate((seminar1EndInput?.value || '').trim());
    return title !== '' && !!start && !!end && end > start;
  }

  function syncSeminar2Gate(clearWhenLocked = true) {
    const seminar2Title = document.getElementById('seminar2_title');
    const lockHint = document.getElementById('seminar2LockHint');
    const isTwoSeminars = (eventModeInput?.value || 'simple') === 'seminar_based'
      && (Number.parseInt(seminarCountInput?.value || '0', 10) || 0) === 2;

    if (!isTwoSeminars) {
      lockHint?.classList.add('hidden');
      return;
    }

    const ready = isSeminar1Complete();
    const seminar1End = parseLocalDate((seminar1EndInput?.value || '').trim());

    if (seminar2Title) {
      seminar2Title.disabled = !ready;
      seminar2Title.classList.toggle('bg-zinc-50', !ready);
      seminar2Title.classList.toggle('text-zinc-500', !ready);
      seminar2Title.classList.toggle('cursor-not-allowed', !ready);
      seminar2Title.classList.toggle('bg-white', ready);
      seminar2Title.classList.toggle('text-zinc-900', ready);
      if (!ready && clearWhenLocked) seminar2Title.value = '';
    }

    seminar2Editor?.classList.toggle('opacity-70', !ready);
    lockHint?.classList.toggle('hidden', ready);

    if (!ready) {
      setPickerDisabled(seminar2StartInput, true);
      setEndLocked(seminar2EndInput, true, clearWhenLocked);
      if (clearWhenLocked) setPickerValue(seminar2StartInput, '');
      setPickerMin(seminar2StartInput, null);
      return;
    }

    // Seminar 2 cannot start before Seminar 1 ends (past dates/times disabled in picker).
    const createFloor = earliestAllowedCreateDateTime();
    const mode = document.getElementById('mode')?.value || 'create';
    let minStart = seminar1End;
    if (mode === 'create' && createFloor && seminar1End && createFloor > seminar1End) {
      minStart = createFloor;
    } else if (mode === 'create' && createFloor && !seminar1End) {
      minStart = createFloor;
    }
    setPickerMin(seminar2StartInput, minStart);
    setPickerDisabled(seminar2StartInput, false);

    const s2Start = parseLocalDate((seminar2StartInput?.value || '').trim());
    if (s2Start && minStart && s2Start < minStart) {
      setPickerValue(seminar2StartInput, '');
      setEndLocked(seminar2EndInput, true, true);
    } else {
      updateEndMin(seminar2StartInput, seminar2EndInput);
    }
  }

  function enforceSimpleEndDefaults() {
    if (isApplyingSimpleEndDefault) return;
    if (!startAtInput || !endAtInput) return;
    isApplyingSimpleEndDefault = true;
    try {
      const startRaw = (startAtInput.value || '').trim();
      const startDate = parseLocalDate(startRaw);
      if (!startDate) {
        setEndLocked(endAtInput, true);
        setPickerMin(endAtInput, null);
        setPickerMax(endAtInput, null);
        return;
      }

      const fixedEnd = new Date(
        startDate.getFullYear(),
        startDate.getMonth(),
        startDate.getDate(),
        17,
        0,
        0,
        0
      );

      setPickerValue(endAtInput, formatLocalForPicker(fixedEnd));
      setPickerMin(endAtInput, fixedEnd);
      setPickerMax(endAtInput, fixedEnd);
      setEndLocked(endAtInput, false, false);
    } finally {
      isApplyingSimpleEndDefault = false;
    }
  }

  if (typeof flatpickr === 'function') {
    [startAtInput, endAtInput, seminar1StartInput, seminar1EndInput, seminar2StartInput, seminar2EndInput]
      .filter(Boolean)
      .forEach((input) => {
        flatpickr(input, {
          ...flatpickrConfig,
          onOpen: (_selectedDates, _dateStr, instance) => keepPickerVisible(instance),
          onMonthChange: (_selectedDates, _dateStr, instance) => keepPickerVisible(instance),
          onYearChange: (_selectedDates, _dateStr, instance) => keepPickerVisible(instance),
          onChange: () => {
            if (input === startAtInput || input === seminar1StartInput) {
              refreshRegistrationCloseOptions();
            }
            if (input === seminar1StartInput || input === seminar1EndInput) {
              if (input === seminar1StartInput) {
                updateEndMin(seminar1StartInput, seminar1EndInput);
              }
              syncSeminar2Gate(true);
            }
            if (input === seminar2StartInput) {
              updateEndMin(seminar2StartInput, seminar2EndInput);
            }
          },
        });
      });
  }

  function updateStructureOptionUI() {
    const activeMode = eventModeInput?.value || 'simple';
    const activeSeminars = Number.parseInt(seminarCountInput?.value || '0', 10) || 0;

    structureOptions.forEach((option) => {
      const mode = option.dataset.mode || 'simple';
      const seminars = Number.parseInt(option.dataset.seminars || '0', 10) || 0;
      const isActive = mode === activeMode && seminars === activeSeminars;

      option.classList.toggle('border-orange-300', isActive);
      option.classList.toggle('bg-orange-50/70', isActive);
      option.classList.toggle('shadow-md', isActive);

      if (!isActive) {
        option.classList.remove('bg-orange-50/70', 'shadow-md');
        option.classList.add('border-zinc-200', 'bg-white');
      } else {
        option.classList.remove('border-zinc-200', 'bg-white');
      }
    });
  }

  function setStructure(mode, seminarCount) {
    const normalizedMode = mode === 'seminar_based' ? 'seminar_based' : 'simple';
    const normalizedCount = normalizedMode === 'seminar_based'
      ? Math.min(2, Math.max(1, Number.parseInt(String(seminarCount || 1), 10) || 1))
      : 0;

    if (eventModeInput) eventModeInput.value = normalizedMode;
    if (seminarCountInput) seminarCountInput.value = String(normalizedCount);

    const isSeminar = normalizedMode === 'seminar_based';
    simpleScheduleSection?.classList.toggle('hidden', isSeminar);
    seminarScheduleSection?.classList.toggle('hidden', !isSeminar);
    seminar2Editor?.classList.toggle('hidden', !(isSeminar && normalizedCount === 2));

    const seminar1Title = document.getElementById('seminar1_title');
    const seminar2Title = document.getElementById('seminar2_title');

    if (startAtInput) startAtInput.required = !isSeminar;
    if (endAtInput) endAtInput.required = !isSeminar;
    if (seminar1Title) seminar1Title.required = isSeminar;
    if (seminar1StartInput) seminar1StartInput.required = isSeminar;
    if (seminar1EndInput) seminar1EndInput.required = isSeminar;
    if (seminar2Title) seminar2Title.required = isSeminar && normalizedCount === 2;
    if (seminar2StartInput) seminar2StartInput.required = isSeminar && normalizedCount === 2;
    if (seminar2EndInput) seminar2EndInput.required = isSeminar && normalizedCount === 2;

    if (seminarSummaryBadge) {
      seminarSummaryBadge.textContent = isSeminar
        ? (normalizedCount === 2 ? '2 Seminars' : '1 Seminar')
        : 'Simple Event';
    }

    if (isSeminar) {
      setEndLocked(endAtInput, false, false);
      setPickerMax(endAtInput, null);
      updateEndMin(startAtInput, endAtInput);
      updateEndMin(seminar1StartInput, seminar1EndInput);
      syncSeminar2Gate(false);
    } else {
      enforceSimpleEndDefaults();
    }
    refreshRegistrationCloseOptions();

    updateStructureOptionUI();
  }

  function collectSeminarPayload(index) {
    const titleInput = document.getElementById(`seminar${index}_title`);
    const startInput = document.getElementById(`seminar${index}_start_local`);
    const endInput = document.getElementById(`seminar${index}_end_local`);

    const title = (titleInput?.value || '').trim();
    const startRaw = (startInput?.value || '').trim();
    const endRaw = (endInput?.value || '').trim();

    if (!title || !startRaw || !endRaw) {
      throw new Error(`Seminar ${index} requires title, start, and end time.`);
    }

    const start = parseLocalDate(startRaw);
    const end = parseLocalDate(endRaw);
    if (!start || !end) {
      throw new Error(`Seminar ${index} has an invalid schedule.`);
    }
    if (end <= start) {
      throw new Error(`Seminar ${index} end time must be after start time.`);
    }

    return {
      title,
      start_at: start.toISOString(),
      end_at: end.toISOString(),
    };
  }

  function deriveWindowFromSessions(sessions) {
    if (!Array.isArray(sessions) || sessions.length === 0) {
      return null;
    }

    let minStart = null;
    let maxEnd = null;

    sessions.forEach((session) => {
      const s = new Date(session.start_at);
      const e = new Date(session.end_at);
      if (Number.isNaN(s.getTime()) || Number.isNaN(e.getTime())) return;
      if (!minStart || s < minStart) minStart = s;
      if (!maxEnd || e > maxEnd) maxEnd = e;
    });

    if (!minStart || !maxEnd) return null;
    return { start: minStart, end: maxEnd };
  }

  function sanitizeSessions(rawSessions) {
    if (!Array.isArray(rawSessions)) return [];
    return rawSessions
      .map((session) => {
        if (!session || typeof session !== 'object') return null;
        const title = (session.title || '').toString().trim();
        const startAt = (session.start_at || '').toString().trim();
        const endAt = (session.end_at || '').toString().trim();
        if (!title || !startAt || !endAt) return null;
        return { title, start_at: startAt, end_at: endAt };
      })
      .filter(Boolean);
  }

  function createTeacherProposalRequirementRow() {
    const row = document.createElement('div');
    row.className = 'teacher-proposal-item rounded-2xl border border-zinc-200 bg-white p-4 shadow-sm';
    row.dataset.requirementCode = 'ADDITIONAL';
    row.dataset.required = '0';
    row.innerHTML = `
      <div class="flex items-start justify-between gap-3">
        <div class="flex-1">
          <label class="block text-xs font-medium text-zinc-600 mb-1.5">Requirement Title</label>
          <input type="text" class="proposal-label-input w-full rounded-xl border border-zinc-200 px-3 py-2.5 text-sm text-zinc-900 outline-none focus:ring-2 focus:ring-orange-500/30 focus:border-orange-400" placeholder="e.g. Department endorsement letter" />
        </div>
        <button type="button" class="proposal-remove-btn rounded-xl border border-red-200 bg-red-50 px-3 py-2 text-xs font-bold text-red-700 hover:bg-red-100 transition">Remove</button>
      </div>
      <label class="mt-3 block text-xs font-medium text-zinc-600">Upload Image File</label>
      <input type="file" accept=".pdf,.doc,.docx,image/jpeg,image/png,image/webp"
        class="proposal-file-input mt-1 block w-full rounded-xl border border-zinc-200 bg-white px-3 py-2 text-sm text-zinc-700 file:mr-3 file:rounded-lg file:border-0 file:bg-orange-50 file:px-3 file:py-2 file:text-sm file:font-semibold file:text-orange-700 hover:file:bg-orange-100" />
    `;
    row.querySelector('.proposal-remove-btn')?.addEventListener('click', () => row.remove());
    return row;
  }

  function resetTeacherProposalRequirements() {
    if (!teacherProposalRequirementsList) return;
    teacherProposalEditState = {
      status: '',
      stage: '',
      requirements: [],
      submissions: [],
    };
    teacherProposalRequirementsList.querySelectorAll('.teacher-proposal-item').forEach((item) => {
      const labelInput = item.querySelector('.proposal-label-input');
      if (labelInput) labelInput.value = '';
      const fileInput = item.querySelector('.proposal-file-input');
      if (fileInput) fileInput.value = '';
    });
    teacherProposalRequirementsList.querySelectorAll('.teacher-proposal-item[data-required="0"]').forEach((item) => item.remove());
    if (teacherProposalEditRequirementsList) teacherProposalEditRequirementsList.innerHTML = '';
    teacherProposalCreateSection?.classList.remove('hidden');
    teacherProposalEditSection?.classList.add('hidden');
  }

  function renderTeacherProposalEditRequirements(requirements, submissions) {
    if (!teacherProposalEditRequirementsList) return;
    teacherProposalEditRequirementsList.innerHTML = '';

    const submissionsByRequirement = {};
    (submissions || []).forEach((submission) => {
      const requirementId = String(submission?.requirement_id || '').trim();
      if (requirementId) submissionsByRequirement[requirementId] = submission;
    });

    (requirements || []).forEach((requirement, index) => {
      const requirementId = String(requirement?.id || '').trim();
      const code = String(requirement?.code || `DOC${index + 1}`).trim() || `DOC${index + 1}`;
      const label = String(requirement?.label || code).trim() || code;
      const submission = requirementId ? submissionsByRequirement[requirementId] : null;
      const fileUrl = String(submission?.file_url || '').trim();
      const filePath = String(submission?.file_path || '').trim();
      const fileName = String(submission?.file_name || '').trim();
      const mimeType = String(submission?.mime_type || '').trim();
      const hasFile = fileUrl !== '' || filePath !== '';

      const row = document.createElement('div');
      row.className = 'rounded-2xl border border-zinc-200 bg-white p-4 shadow-sm';
      row.dataset.requirementId = requirementId;
      row.innerHTML = `
        <div class="flex items-start justify-between gap-3">
          <div class="min-w-0">
            <div class="flex items-center gap-2">
              <span class="rounded-full border border-zinc-200 bg-zinc-50 px-2 py-0.5 text-[10px] font-bold text-zinc-700">${escapeHtml(code)}</span>
              <div class="text-sm font-bold text-zinc-900">${escapeHtml(label)}</div>
            </div>
            <div class="mt-1 text-xs text-zinc-500">
              ${hasFile
                ? `Current file: ${escapeHtml(fileName || 'Uploaded document')}`
                : 'No uploaded file found. Please upload one to continue.'}
            </div>
          </div>
          ${hasFile
            ? `<button type="button"
                data-proposal-file-preview
                data-file-url="${escapeHtml(fileUrl)}"
                data-file-path="${escapeHtml(filePath)}"
                data-file-bucket="proposal-documents"
                data-file-label="${escapeHtml(label)}"
                data-file-name="${escapeHtml(fileName || label)}"
                data-file-mime="${escapeHtml(mimeType)}"
                class="rounded-xl border border-emerald-200 bg-emerald-50 px-3 py-2 text-xs font-bold text-emerald-700 transition hover:bg-emerald-100">Open file</button>`
            : `<span class="rounded-xl border border-zinc-200 bg-zinc-50 px-3 py-2 text-xs font-bold text-zinc-500">Missing upload</span>`}
        </div>
        <label class="mt-3 block text-xs font-medium text-zinc-600">Replace file (optional)</label>
        <input type="file" accept=".pdf,.doc,.docx,image/jpeg,image/png,image/webp"
          class="proposal-edit-file-input mt-1 block w-full rounded-xl border border-zinc-200 bg-white px-3 py-2 text-sm text-zinc-700 file:mr-3 file:rounded-lg file:border-0 file:bg-orange-50 file:px-3 file:py-2 file:text-sm file:font-semibold file:text-orange-700 hover:file:bg-orange-100" />
      `;
      teacherProposalEditRequirementsList.appendChild(row);
    });
  }

  function setTeacherProposalStepMode(mode, source = null) {
    if (!teacherProposalMode) return;
    if (mode === 'edit' && source) {
      const requirements = safeJsonParse(source.dataset.proposal_requirements || '[]', []);
      const submissions = safeJsonParse(source.dataset.proposal_submissions || '[]', []);
      teacherProposalEditState = {
        status: String(source.dataset.status || '').trim().toLowerCase(),
        stage: String(source.dataset.proposal_stage || '').trim().toLowerCase(),
        requirements: Array.isArray(requirements) ? requirements : [],
        submissions: Array.isArray(submissions) ? submissions : [],
      };
      teacherProposalCreateSection?.classList.add('hidden');
      teacherProposalEditSection?.classList.remove('hidden');
      renderTeacherProposalEditRequirements(teacherProposalEditState.requirements, teacherProposalEditState.submissions);
      return;
    }

    setTeacherProposalStepMode('create');
    teacherProposalCreateSection?.classList.remove('hidden');
    teacherProposalEditSection?.classList.add('hidden');
  }

  function collectTeacherProposalRequirements() {
    const items = Array.from(document.querySelectorAll('#teacherProposalRequirementsList .teacher-proposal-item'));
    const requirements = [];
    const files = [];

    items.forEach((item, index) => {
      const labelInput = item.querySelector('.proposal-label-input');
      const label = ((labelInput?.value) || item.dataset.defaultLabel || '').trim();
      const code = (item.dataset.requirementCode || 'ADDITIONAL').trim().toUpperCase();
      const required = item.dataset.required === '1';
      const fileInput = item.querySelector('.proposal-file-input');
      const file = fileInput?.files?.[0] || null;

      if (!label) {
        throw new Error('Enter a title for every additional requirement.');
      }
      if (!file) {
        throw new Error(`Upload the file for "${label}".`);
      }
      validateProposalFile(file, label);

      requirements.push({
        code: code === 'ADDITIONAL' ? `DOC${index + 1}` : code,
        label,
      });
      files.push(file);
    });

    if (!requirements.length && teacherProposalMode) {
      throw new Error('Add the required proposal documents before submitting.');
    }

    return { requirements, files };
  }

  function collectTeacherProposalEditReplacements() {
    const rows = Array.from(document.querySelectorAll('#teacherProposalEditRequirementsList [data-requirement-id]'));
    const replacements = [];
    rows.forEach((row) => {
      const requirementId = String(row.dataset.requirementId || '').trim();
      const input = row.querySelector('.proposal-edit-file-input');
      const file = input?.files?.[0] || null;
      if (requirementId && file) {
        const label = String(row.querySelector('.text-sm.font-bold')?.textContent || 'requirement').trim();
        validateProposalFile(file, label);
        replacements.push({ requirementId, file });
      }
    });
    return replacements;
  }

  btnAddTeacherProposalRequirement?.addEventListener('click', () => {
    teacherProposalRequirementsList?.appendChild(createTeacherProposalRequirementRow());
  });

  const subtitles = teacherProposalMode
    ? ['Fill in the event info', 'Add a description', 'Set the schedule', 'Event & student requirements']
    : ['Fill in the event info', 'Add a description', 'Set the schedule'];

  function collectStudentRequirements() {
    const items = [];
    document.querySelectorAll('#studentRequirementsSection .student-req-checkbox:checked').forEach((checkbox) => {
      items.push({
        code: String(checkbox.dataset.code || '').trim(),
        label: String(checkbox.dataset.label || '').trim(),
      });
    });
    document.querySelectorAll('#studentRequirementOtherList .student-req-other-item').forEach((row) => {
      const label = String(row.querySelector('input')?.value || '').trim();
      if (label) {
        items.push({ code: 'OTHER', label });
      }
    });
    return items;
  }

  function resetStudentRequirementsForm() {
    document.querySelectorAll('#studentRequirementsSection .student-req-checkbox').forEach((checkbox) => {
      checkbox.checked = false;
    });
    const otherList = document.getElementById('studentRequirementOtherList');
    if (otherList) otherList.innerHTML = '';
  }

  function applyStudentRequirementsForm(requirements) {
    resetStudentRequirementsForm();
    const rows = Array.isArray(requirements) ? requirements : [];
    const otherList = document.getElementById('studentRequirementOtherList');

    rows.forEach((item) => {
      const code = String(item?.code || '').trim().toUpperCase();
      const label = String(item?.label || '').trim();
      if (!code && !label) return;

      if (code === 'OTHER' || !code) {
        if (!label || !otherList) return;
        otherList.appendChild(createStudentRequirementOtherRow(label));
        return;
      }

      const checkbox = document.querySelector(
        `#studentRequirementsSection .student-req-checkbox[data-code="${CSS.escape(code)}"]`
      );
      if (checkbox) {
        checkbox.checked = true;
        return;
      }

      // Unknown preset → treat as custom "other"
      if (label && otherList) {
        otherList.appendChild(createStudentRequirementOtherRow(label));
      }
    });
  }

  function createStudentRequirementOtherRow(value = '') {
    const row = document.createElement('div');
    row.className = 'student-req-other-item flex items-center gap-2';
    row.innerHTML = `
      <input type="text" value="${value.replace(/"/g, '&quot;')}" maxlength="120"
        class="flex-1 rounded-xl border border-zinc-200 px-3 py-2 text-sm text-zinc-900 outline-none focus:ring-2 focus:ring-sky-500/30 focus:border-sky-400"
        placeholder="e.g. Barangay Clearance" />
      <button type="button" class="rounded-lg border border-zinc-200 px-2.5 py-2 text-xs font-semibold text-zinc-600 hover:bg-zinc-50">Remove</button>
    `;
    row.querySelector('button')?.addEventListener('click', () => row.remove());
    return row;
  }

  document.getElementById('btnAddStudentRequirementOther')?.addEventListener('click', () => {
    const list = document.getElementById('studentRequirementOtherList');
    if (!list) return;
    list.appendChild(createStudentRequirementOtherRow());
    list.lastElementChild?.querySelector('input')?.focus();
  });

  function setWizardStep(s) {
    document.getElementById('step1')?.classList.toggle('hidden', s !== 1);
    document.getElementById('step2')?.classList.toggle('hidden', s !== 2);
    document.getElementById('step3')?.classList.toggle('hidden', s !== 3);
    document.getElementById('step4')?.classList.toggle('hidden', s !== 4);

    const btnBack = document.getElementById('btnBack');
    const btnNext = document.getElementById('btnNext');
    const btnSubmit = document.getElementById('btnSubmit');

    if (btnBack) btnBack.disabled = s === 1;
    btnNext?.classList.toggle('hidden', s === maxWizardStep);
    btnSubmit?.classList.toggle('hidden', s !== maxWizardStep || eventModalReadOnly);

    const subtitle = document.getElementById('modalSubtitle');
    if (subtitle) subtitle.textContent = subtitles[s - 1] || '';

    ['ws1', 'ws2', 'ws3', 'ws4'].forEach((id, i) => {
      const el = document.getElementById(id);
      if (!el) return;
      el.classList.remove('active', 'completed');
      if ((i + 1) === s) el.classList.add('active');
      if ((i + 1) < s) el.classList.add('completed');
    });
  }

  function setEventModalReadOnly(readOnly) {
    eventModalReadOnly = !!readOnly;

    const interactiveSelectors = [
      'input:not([type="hidden"])',
      'select',
      'textarea',
      'button.structure-option',
      '#btnClearCover',
      '.target-year-checkbox',
      '#sttBtn',
      '#mainUndoBtn',
      '#mainExpandBtn',
      '#mainAiImproveBtn'
    ];

    eventForm?.querySelectorAll(interactiveSelectors.join(', ')).forEach((element) => {
      const isPicker = element.classList?.contains('datetime-picker');
      if (isPicker) {
        setPickerDisabled(element, eventModalReadOnly);
        return;
      }

      if ('disabled' in element) {
        element.disabled = eventModalReadOnly;
      }

      if (element.matches('input, textarea, select')) {
        element.classList.toggle('bg-zinc-50', eventModalReadOnly);
        element.classList.toggle('text-zinc-500', eventModalReadOnly);
        element.classList.toggle('cursor-not-allowed', eventModalReadOnly);
      }
    });

    const coverLabel = document.querySelector('label[for="cover_file"]');
    if (coverLabel) {
      coverLabel.classList.toggle('pointer-events-none', eventModalReadOnly);
      coverLabel.classList.toggle('opacity-60', eventModalReadOnly);
    }
  }

  const coverFileInput = document.getElementById('cover_file');
  const coverImageUrlInput = document.getElementById('cover_image_url');
  const coverPreviewWrap = document.getElementById('coverPreviewWrap');
  const coverPreviewImg = document.getElementById('coverPreviewImg');
  const coverPreviewLoading = document.getElementById('coverPreviewLoading');
  const coverFileLabel = document.getElementById('coverFileLabel');
  const coverAspectHint = document.getElementById('coverAspectHint');
  let coverFilePending = null;
  let coverObjectUrl = '';
  // Matches mobile Event Details header (~full width × 220px) ≈ 16:9
  const COVER_TARGET_RATIO = 16 / 9;
  const COVER_RATIO_TOLERANCE = 0.08;

  function revokeCoverObjectUrl() {
    if (coverObjectUrl) {
      URL.revokeObjectURL(coverObjectUrl);
      coverObjectUrl = '';
    }
  }

  function setCoverLoading(isLoading) {
    if (!coverPreviewLoading) return;
    coverPreviewLoading.classList.toggle('hidden', !isLoading);
    coverPreviewLoading.classList.toggle('flex', !!isLoading);
  }

  function setCoverAspectHint(message) {
    if (!coverAspectHint) return;
    const text = String(message || '').trim();
    coverAspectHint.textContent = text;
    coverAspectHint.classList.toggle('hidden', text === '');
  }

  function setCoverPreview(url, { fromFile = false } = {}) {
    const next = String(url || '').trim();
    if (!coverPreviewWrap || !coverPreviewImg) return;
    if (!next) {
      coverPreviewWrap.classList.add('hidden');
      coverPreviewImg.removeAttribute('src');
      setCoverLoading(false);
      if (coverFileLabel) coverFileLabel.textContent = 'Upload cover image (16:9)';
      return;
    }
    coverPreviewWrap.classList.remove('hidden');
    coverPreviewImg.src = next;
    if (coverFileLabel) {
      coverFileLabel.textContent = fromFile ? 'Replace cover image' : 'Change cover image';
    }
  }

  function resetCoverPicker() {
    revokeCoverObjectUrl();
    coverFilePending = null;
    if (coverFileInput) coverFileInput.value = '';
    if (coverImageUrlInput) coverImageUrlInput.value = '';
    setCoverAspectHint('');
    setCoverLoading(false);
    setCoverPreview('');
  }

  function readImageDimensions(file) {
    return new Promise((resolve, reject) => {
      const objectUrl = URL.createObjectURL(file);
      const img = new Image();
      img.onload = () => {
        const width = Number(img.naturalWidth || 0);
        const height = Number(img.naturalHeight || 0);
        URL.revokeObjectURL(objectUrl);
        if (!width || !height) {
          reject(new Error('Unable to read image dimensions.'));
          return;
        }
        resolve({ width, height });
      };
      img.onerror = () => {
        URL.revokeObjectURL(objectUrl);
        reject(new Error('Unable to read the selected image.'));
      };
      img.src = objectUrl;
    });
  }

  function validateCoverAspectRatio(width, height) {
    const ratio = width / height;
    const delta = Math.abs(ratio - COVER_TARGET_RATIO);
    if (delta > COVER_RATIO_TOLERANCE) {
      return {
        ok: false,
        message: `Cover must be 16:9 landscape (≈1.78:1). Yours is ${width}×${height} (${ratio.toFixed(2)}:1). Crop to 16:9 (e.g. 1600×900) so it fits the app header.`,
      };
    }
    return { ok: true, message: '' };
  }

  async function uploadEventCover(eventId) {
    const id = String(eventId || '').trim();
    if (!id || !coverFilePending) return '';

    const formData = new FormData();
    formData.append('event_id', id);
    formData.append('csrf_token', window.CSRF_TOKEN || '');
    formData.append('cover_file', coverFilePending);

    const uploadRes = await fetch('/api/event_cover_upload.php', {
      method: 'POST',
      body: formData,
    });
    const uploadData = await uploadRes.json();
    if (!uploadData.ok) {
      throw new Error(uploadData.error || 'Failed to upload cover image.');
    }
    const uploadedUrl = String(uploadData.cover_image_url || '').trim();
    if (coverImageUrlInput) coverImageUrlInput.value = uploadedUrl;
    coverFilePending = null;
    if (coverFileInput) coverFileInput.value = '';
    revokeCoverObjectUrl();
    setCoverPreview(uploadedUrl);
    return uploadedUrl;
  }

  coverFileInput?.addEventListener('change', async () => {
    const file = coverFileInput.files && coverFileInput.files[0] ? coverFileInput.files[0] : null;
    if (!file) return;

    const allowed = ['image/jpeg', 'image/png', 'image/webp'];
    if (!allowed.includes(file.type)) {
      alert('Cover image must be JPG, PNG, or WEBP.');
      coverFileInput.value = '';
      return;
    }
    if (file.size > 5 * 1024 * 1024) {
      alert('Cover image must be 5MB or smaller.');
      coverFileInput.value = '';
      return;
    }

    setCoverAspectHint('');
    revokeCoverObjectUrl();
    coverFilePending = null;
    coverPreviewWrap?.classList.remove('hidden');
    setCoverLoading(true);

    try {
      const { width, height } = await readImageDimensions(file);
      const aspect = validateCoverAspectRatio(width, height);
      if (!aspect.ok) {
        setCoverLoading(false);
        setCoverPreview('');
        coverFileInput.value = '';
        setCoverAspectHint(aspect.message);
        alert(aspect.message);
        return;
      }

      coverFilePending = file;
      coverObjectUrl = URL.createObjectURL(file);
      setCoverPreview(coverObjectUrl, { fromFile: true });
      setCoverLoading(false);
      setCoverAspectHint('');
    } catch (err) {
      setCoverLoading(false);
      setCoverPreview('');
      coverFileInput.value = '';
      const message = err?.message || 'Unable to validate the cover image.';
      setCoverAspectHint(message);
      alert(message);
    }
  });

  document.getElementById('btnClearCover')?.addEventListener('click', () => {
    resetCoverPicker();
  });

  function openEventModalFromDataset(source, readOnly = false) {
    if (!source) return;

    // Prefer button dataset; fall back to parent event card for older markup.
    const card = source.closest?.('.event-card') || null;
    const ds = (key) => {
      const fromBtn = source.dataset?.[key];
      if (fromBtn != null && String(fromBtn).trim() !== '') return fromBtn;
      const fromCard = card?.dataset?.[key];
      return fromCard != null ? fromCard : '';
    };

    document.getElementById('mode').value = 'edit';
    document.getElementById('event_id').value = ds('id') || '';
    document.getElementById('title').value = ds('title') || '';
    resetCoverPicker();
    const existingCover = String(ds('cover_image_url') || '').trim();
    if (coverImageUrlInput) coverImageUrlInput.value = existingCover;
    setCoverPreview(existingCover);
    let existingStudentRequirements = [];
    try {
      existingStudentRequirements = JSON.parse(ds('student_requirements') || '[]');
    } catch (err) {
      existingStudentRequirements = [];
    }
    applyStudentRequirementsForm(existingStudentRequirements);
    document.getElementById('location').value = ds('locationFull') || ds('location') || '';
    const rawDescription = ds('description') || '';
    const cleanedDescription = String(rawDescription)
      .replace(/\[REJECT_REASON:[^\]]*\]\s*/gi, '')
      .trim();
    document.getElementById('description').value = cleanedDescription;

    setEventTypeValue(ds('event_type') || 'Event');
    const decodedTarget = decodeTargetParticipant(ds('event_for') || 'All');
    if (document.getElementById('target_course')) document.getElementById('target_course').value = decodedTarget.course;
    setSelectedTargetYears(decodedTarget.years || ['ALL']);
    if (document.getElementById('grace_time')) document.getElementById('grace_time').value = ds('grace_time') || '30';
    setRegistrationType((ds('is_free_event') || '1') !== '0' ? 'free' : 'paid');
    const feeInput = document.getElementById('event_fee');
    if (feeInput) {
      feeInput.value = String(ds('event_fee') || '').trim();
    }
    syncEventFeeVisibility();
    const registrationLimitInput = document.getElementById('registration_limit');
    if (registrationLimitInput) {
      registrationLimitInput.value = ds('registration_limit') || '';
    }
    const preferredCloseWeeks = ds('registration_close_weeks') || '1';

    setPickerValue(startAtInput, ds('start_at') ? toLocalInput(ds('start_at')) : '');
    setPickerValue(endAtInput, ds('end_at') ? toLocalInput(ds('end_at')) : '');

    let sessions = [];
    try {
      sessions = sanitizeSessions(JSON.parse(ds('sessions') || '[]'));
    } catch (err) {
      sessions = [];
    }

    const dataMode = (ds('event_mode') || '').trim();
    const isSeminar = dataMode === 'seminar_based' || sessions.length > 0;

    if (isSeminar) {
      const seminarCount = sessions.length > 1 ? 2 : 1;
      setStructure('seminar_based', seminarCount);

      const s1 = sessions[0] || null;
      const s2 = sessions[1] || null;

      document.getElementById('seminar1_title').value = s1?.title || '';
      setPickerValue(document.getElementById('seminar1_start_local'), s1?.start_at ? toLocalInput(s1.start_at) : '');
      setPickerValue(document.getElementById('seminar1_end_local'), s1?.end_at ? toLocalInput(s1.end_at) : '');

      document.getElementById('seminar2_title').value = s2?.title || '';
      setPickerValue(document.getElementById('seminar2_start_local'), s2?.start_at ? toLocalInput(s2.start_at) : '');
      setPickerValue(document.getElementById('seminar2_end_local'), s2?.end_at ? toLocalInput(s2.end_at) : '');
    } else {
      setStructure('simple', 0);

      document.getElementById('seminar1_title').value = '';
      setPickerValue(document.getElementById('seminar1_start_local'), '');
      setPickerValue(document.getElementById('seminar1_end_local'), '');
      document.getElementById('seminar2_title').value = '';
      setPickerValue(document.getElementById('seminar2_start_local'), '');
      setPickerValue(document.getElementById('seminar2_end_local'), '');
    }

    setPickerMin(startAtInput, null);
    setPickerMin(seminar1StartInput, null);
    setPickerMin(seminar2StartInput, null);

    updateEndMin(startAtInput, endAtInput);
    updateEndMin(seminar1StartInput, seminar1EndInput);
    updateEndMin(seminar2StartInput, seminar2EndInput);
    refreshRegistrationCloseOptions(preferredCloseWeeks);
    syncSeminar2Gate(false);

    const msg = document.getElementById('formMsg');
    if (msg) {
      msg.className = 'text-sm text-amber-800 min-h-0 !mt-0';
      msg.textContent = '';
    }
    clearWizardStepError();

    const modalTitle = document.getElementById('modalTitle');
    if (modalTitle) modalTitle.textContent = readOnly ? 'Event Details' : 'Edit Event';

    step = 1;
    setWizardStep(1);
    setEventModalReadOnly(readOnly);

    const subtitle = document.getElementById('modalSubtitle');
    if (subtitle) {
      subtitle.textContent = readOnly
        ? 'Review Info → Details → Schedule (use Next)'
        : 'Update Info → Details → Schedule (use Next for full fields)';
    }

    if (!readOnly) {
      // Keep proposal metadata on the original button so edit-mode helpers still work.
      setTeacherProposalStepMode('edit', source);
      const submitLabel = document.querySelector('#btnSubmit span:last-child');
      if (submitLabel) {
        const sourceStatus = String(ds('status') || '').trim().toLowerCase();
        submitLabel.textContent = teacherProposalMode && sourceStatus === 'archived'
          ? 'Resubmit for Review'
          : (teacherProposalMode && String(ds('proposal_stage') || '').trim().toLowerCase() === 'pending_requirements'
            ? 'Submit for Review'
            : 'Save Event');
      }
    }

    openModal(eventModal);
  }

  structureOptions.forEach((option) => {
    option.addEventListener('click', () => {
      const mode = option.dataset.mode || 'simple';
      const seminars = Number.parseInt(option.dataset.seminars || '0', 10) || 0;
      setStructure(mode, seminars);
    });
  });

  startAtInput?.addEventListener('change', () => {
    if ((eventModeInput?.value || 'simple') === 'seminar_based') {
      updateEndMin(startAtInput, endAtInput);
    } else {
      enforceSimpleEndDefaults();
    }
    refreshRegistrationCloseOptions();
  });
  seminar1StartInput?.addEventListener('change', () => {
    updateEndMin(seminar1StartInput, seminar1EndInput);
    refreshRegistrationCloseOptions();
    syncSeminar2Gate(true);
  });
  seminar1EndInput?.addEventListener('change', () => syncSeminar2Gate(true));
  seminar2StartInput?.addEventListener('change', () => updateEndMin(seminar2StartInput, seminar2EndInput));

  startAtInput?.addEventListener('input', () => {
    if ((eventModeInput?.value || 'simple') === 'seminar_based') {
      updateEndMin(startAtInput, endAtInput);
    } else {
      enforceSimpleEndDefaults();
    }
    refreshRegistrationCloseOptions();
  });
  endAtInput?.addEventListener('change', () => {
    if ((eventModeInput?.value || 'simple') !== 'seminar_based') {
      enforceSimpleEndDefaults();
    }
  });
  endAtInput?.addEventListener('input', () => {
    if ((eventModeInput?.value || 'simple') !== 'seminar_based') {
      enforceSimpleEndDefaults();
    }
  });
  seminar1StartInput?.addEventListener('input', () => {
    updateEndMin(seminar1StartInput, seminar1EndInput);
    refreshRegistrationCloseOptions();
    syncSeminar2Gate(true);
  });
  seminar1EndInput?.addEventListener('input', () => syncSeminar2Gate(true));
  document.getElementById('seminar1_title')?.addEventListener('input', () => syncSeminar2Gate(true));
  document.getElementById('seminar1_title')?.addEventListener('change', () => syncSeminar2Gate(true));
  seminar2StartInput?.addEventListener('input', () => updateEndMin(seminar2StartInput, seminar2EndInput));

  setStructure(eventModeInput?.value || 'simple', seminarCountInput?.value || '0');
  refreshRegistrationCloseOptions();
  syncSeminar2Gate(false);
  setWizardStep(1);
  setEndLocked(endAtInput, true);
  setEndLocked(seminar1EndInput, true);
  setEndLocked(seminar2EndInput, true);

  function decodeTargetParticipant(eventForValue) {
    const raw = (eventForValue || 'All').toString().trim().toUpperCase();
    if (!raw || raw === 'ALL' || raw === 'ALL LEVELS' || raw === 'NONE') {
      return { course: 'ALL', years: ['ALL'] };
    }

    const multi = raw.match(/^COURSE\s*=\s*(ALL|BSIT-SD|BSIT-BA|BSIT|BSCS)\s*;\s*YEARS\s*=\s*([0-9,\sA-Z]+)$/);
    if (multi) {
      const years = (multi[2] || '')
        .split(',')
        .map((v) => v.trim().toUpperCase())
        .filter((v) => ['ALL', '1', '2', '3', '4'].includes(v));
      const normalizedYears = years.includes('ALL') || years.length === 0
        ? ['ALL']
        : [...new Set(years)];
      return { course: normalizeTargetCourse(multi[1]), years: normalizedYears };
    }

    const pair = raw.match(/^(BSIT|BSCS)\s*[-_|]\s*([1-4])$/);
    if (pair) {
      return { course: pair[1], years: [pair[2]] };
    }

    const standalone = normalizeTargetCourse(raw);
    if (['BSIT', 'BSIT-SD', 'BSIT-BA', 'BSCS'].includes(standalone)) {
      return { course: standalone, years: ['ALL'] };
    }

    if (['1', '2', '3', '4'].includes(raw)) {
      return { course: 'ALL', years: [raw] };
    }

    return { course: 'ALL', years: ['ALL'] };
  }

  function normalizeTargetYears(yearValues) {
    const source = Array.isArray(yearValues) ? yearValues : [yearValues];
    const cleaned = source
      .map((v) => (v || '').toString().trim().toUpperCase())
      .filter((v) => ['ALL', '1', '2', '3', '4'].includes(v));
    if (cleaned.includes('ALL') || cleaned.length === 0) return ['ALL'];
    return [...new Set(cleaned)];
  }

  function getSelectedTargetYears() {
    const checkboxes = Array.from(document.querySelectorAll('.target-year-checkbox'));
    if (checkboxes.length === 0) return ['ALL'];
    const checked = checkboxes.filter((cb) => cb.checked).map((cb) => cb.value);
    return normalizeTargetYears(checked);
  }

  function setSelectedTargetYears(years) {
    const checkboxes = Array.from(document.querySelectorAll('.target-year-checkbox'));
    if (checkboxes.length === 0) return;
    const normalized = normalizeTargetYears(years);
    checkboxes.forEach((cb) => {
      cb.checked = normalized.includes(cb.value);
    });
  }

  function normalizeTargetCourse(value) {
    const raw = (value || '').toString().trim().toUpperCase();
    const compact = raw.replace(/[^A-Z0-9]/g, '');
    if (compact === 'BSITSD') return 'BSIT-SD';
    if (compact === 'BSITBA') return 'BSIT-BA';
    if (raw === 'BSIT-SD' || raw === 'BSIT_SD') return 'BSIT-SD';
    if (raw === 'BSIT-BA' || raw === 'BSIT_BA') return 'BSIT-BA';
    if (raw === 'BSIT' || raw === 'IT') return 'BSIT';
    if (raw === 'BSCS' || raw === 'CS') return 'BSCS';
    if (raw === 'ALL') return 'ALL';
    return raw;
  }

  function encodeTargetParticipant(courseValue, yearValues) {
    const course = normalizeTargetCourse(courseValue || 'ALL');
    const years = normalizeTargetYears(yearValues);

    const allowedCourses = ['ALL', 'BSIT', 'BSIT-SD', 'BSIT-BA', 'BSCS'];
    const normalizedCourse = allowedCourses.includes(course) ? course : 'ALL';

    if (normalizedCourse === 'ALL' && years.length === 1 && years[0] === 'ALL') return 'All';
    if (normalizedCourse === 'ALL' && years.length === 1) return years[0];
    if (years.length === 1 && years[0] === 'ALL') return normalizedCourse;
    return `COURSE=${normalizedCourse};YEARS=${years.join(',')}`;
  }

  function bindTargetYearCheckboxes() {
    const checkboxes = Array.from(document.querySelectorAll('.target-year-checkbox'));
    checkboxes.forEach((cb) => {
      cb.addEventListener('change', () => {
        const value = (cb.value || '').toUpperCase();
        if (value === 'ALL' && cb.checked) {
          setSelectedTargetYears(['ALL']);
          return;
        }

        if (value !== 'ALL' && cb.checked) {
          const allBox = document.querySelector('.target-year-checkbox[value="ALL"]');
          if (allBox) allBox.checked = false;
        }

        const selected = getSelectedTargetYears();
        if (selected.length === 0) {
          setSelectedTargetYears(['ALL']);
        }
      });
    });
  }
  bindTargetYearCheckboxes();

  function getRegistrationType() {
    const paidInput = document.getElementById('registration_type_paid');
    return paidInput?.checked ? 'paid' : 'free';
  }

  const EVENT_TYPE_PRESETS = ['Event', 'Seminar', 'Off-Campus Activity', 'Sports Event', 'Other'];

  function syncEventTypeOtherVisibility() {
    const typeSelect = document.getElementById('event_type');
    const wrap = document.getElementById('event_type_other_wrap');
    const otherInput = document.getElementById('event_type_other');
    if (!typeSelect || !wrap) return;
    const isOther = typeSelect.value === 'Other';
    wrap.classList.toggle('hidden', !isOther);
    if (!isOther && otherInput) otherInput.value = '';
  }

  function setEventTypeValue(rawType) {
    const typeSelect = document.getElementById('event_type');
    const otherInput = document.getElementById('event_type_other');
    if (!typeSelect) return;
    const value = String(rawType || '').trim() || 'Event';
    if (EVENT_TYPE_PRESETS.includes(value) && value !== 'Other') {
      typeSelect.value = value;
      if (otherInput) otherInput.value = '';
    } else if (value === 'Other') {
      typeSelect.value = 'Other';
      if (otherInput) otherInput.value = '';
    } else {
      typeSelect.value = 'Other';
      if (otherInput) otherInput.value = value.slice(0, 80);
    }
    syncEventTypeOtherVisibility();
  }

  function getEventTypeValue() {
    const typeSelect = document.getElementById('event_type');
    const selected = String(typeSelect?.value || 'Event').trim() || 'Event';
    if (selected !== 'Other') return selected;
    return String(document.getElementById('event_type_other')?.value || '').trim();
  }

  document.getElementById('event_type')?.addEventListener('change', syncEventTypeOtherVisibility);
  syncEventTypeOtherVisibility();

  function focusWizardField(focusId) {
    if (!focusId) return;
    const el = document.getElementById(focusId);
    if (!el) return;
    try {
      el.scrollIntoView({ behavior: 'smooth', block: 'center' });
    } catch (_) {}
    window.setTimeout(() => {
      try {
        if (typeof el.focus === 'function') el.focus({ preventScroll: true });
      } catch (_) {
        try { el.focus(); } catch (__) {}
      }
    }, 180);
  }

  function showWizardFooterStatus(message, tone = 'error', focusId = null) {
    const box = document.getElementById('wizardFooterStatus');
    const formMsg = document.getElementById('formMsg');
    if (formMsg) {
      formMsg.className = 'text-sm text-amber-800 min-h-0 !mt-0';
      formMsg.textContent = '';
    }
    if (!box) {
      if (message) focusWizardField(focusId);
      return;
    }

    const tones = {
      error: 'border-rose-200 bg-rose-50 text-rose-700',
      progress: 'border-amber-200 bg-amber-50 text-amber-800',
      success: 'border-emerald-200 bg-emerald-50 text-emerald-700',
    };
    const toneClass = tones[tone] || tones.error;

    box.className = `rounded-xl border px-3.5 py-2.5 text-sm font-semibold ${toneClass}`;
    if (!message) {
      box.textContent = '';
      box.classList.add('hidden');
      box.removeAttribute('role');
      return;
    }

    box.textContent = message;
    box.classList.remove('hidden');
    box.setAttribute('role', tone === 'error' ? 'alert' : 'status');

    if (tone === 'error') {
      box.classList.remove('wizard-step-error-flash');
      void box.offsetWidth;
      box.classList.add('wizard-step-error-flash');
      focusWizardField(focusId);
    }
  }

  function showWizardStepError(message, focusId) {
    showWizardFooterStatus(message, 'error', focusId);
  }

  function showWizardStepProgress(message) {
    showWizardFooterStatus(message, 'progress');
  }

  function clearWizardStepError() {
    showWizardFooterStatus('');
  }

  function validateWizardStep(currentStep) {
    const mode = (document.getElementById('mode')?.value || 'create').trim();
    const fail = (message, focusId) => ({ message, focusId: focusId || null });

    if (currentStep === 1) {
      const title = (document.getElementById('title')?.value || '').trim();
      if (!title) return fail('Event title is required.', 'title');
      if (title.length > 150) return fail('Event title must be 150 characters or less.', 'title');

      const location = (document.getElementById('location')?.value || '').trim();
      if (!location) return fail('Location is required.', 'location');

      const selectedType = String(document.getElementById('event_type')?.value || '').trim();
      if (selectedType === 'Other') {
        const customType = String(document.getElementById('event_type_other')?.value || '').trim();
        if (!customType) {
          return fail('Specify what this Other event type is.', 'event_type_other');
        }
        if (customType.length > 80) {
          return fail('Custom event type must be 80 characters or less.', 'event_type_other');
        }
      }

      const targetYears = typeof getSelectedTargetYears === 'function'
        ? getSelectedTargetYears()
        : [];
      if (!Array.isArray(targetYears) || targetYears.length === 0) {
        return fail('Select at least one target year level.', 'target_year_group');
      }

      if (teacherProposalMode || document.getElementById('registration_type_paid')) {
        if (getRegistrationType() === 'paid') {
          const feeRaw = String(document.getElementById('event_fee')?.value || '').trim();
          const feeNum = Number.parseFloat(feeRaw);
          if (!Number.isFinite(feeNum) || feeNum <= 0) {
            return fail('Enter the settlement amount for this paid event.', 'event_fee');
          }
        }

        const limitRaw = String(document.getElementById('registration_limit')?.value || '').trim();
        if (limitRaw !== '') {
          const limitNum = Number.parseInt(limitRaw, 10);
          if (!Number.isFinite(limitNum) || limitNum < 1) {
            return fail('Student limit must be a positive whole number.', 'registration_limit');
          }
          if (limitNum > REGISTRATION_LIMIT_MAX) {
            return fail('Student limit cannot exceed 9999.', 'registration_limit');
          }
        }
      }

      return null;
    }

    if (currentStep === 2) {
      const description = (document.getElementById('description')?.value || '').trim();
      if (!description) return fail('Event description is required.', 'description');
      return null;
    }

    if (currentStep === 3) {
      const graceRaw = String(document.getElementById('grace_time')?.value || '').trim();
      const graceNum = Number.parseInt(graceRaw, 10);
      if (!Number.isFinite(graceNum) || graceNum < 0) {
        return fail('Grace time must be 0 or greater.', 'grace_time');
      }

      const eventMode = (eventModeInput?.value || 'simple').trim();
      const seminarCount = Number.parseInt(seminarCountInput?.value || '0', 10) || 0;

      try {
        if (eventMode === 'seminar_based') {
          const s1Title = (document.getElementById('seminar1_title')?.value || '').trim();
          const s1Start = (document.getElementById('seminar1_start_local')?.value || '').trim();
          const s1End = (document.getElementById('seminar1_end_local')?.value || '').trim();
          if (!s1Title) return fail('Seminar 1 title is required.', 'seminar1_title');
          if (!s1Start) return fail('Seminar 1 start schedule is required.', 'seminar1_start_local');
          if (!s1End) return fail('Seminar 1 end schedule is required.', 'seminar1_end_local');
          collectSeminarPayload(1);

          if (seminarCount === 2) {
            if (!isSeminar1Complete()) {
              return fail('Complete Seminar 1 first before filling Seminar 2.', 'seminar1_title');
            }
            const s2Title = (document.getElementById('seminar2_title')?.value || '').trim();
            const s2Start = (document.getElementById('seminar2_start_local')?.value || '').trim();
            const s2End = (document.getElementById('seminar2_end_local')?.value || '').trim();
            if (!s2Title) return fail('Seminar 2 title is required.', 'seminar2_title');
            if (!s2Start) return fail('Seminar 2 start schedule is required.', 'seminar2_start_local');
            if (!s2End) return fail('Seminar 2 end schedule is required.', 'seminar2_end_local');
            const s1EndDate = parseLocalDate(s1End);
            const s2StartDate = parseLocalDate(s2Start);
            if (s1EndDate && s2StartDate && s2StartDate < s1EndDate) {
              return fail('Seminar 2 must start on or after Seminar 1 ends.', 'seminar2_start_local');
            }
            collectSeminarPayload(2);
          }
        } else {
          const startRaw = (startAtInput?.value || '').trim();
          const endRaw = (endAtInput?.value || '').trim();
          if (!startRaw) return fail('Start schedule is required.', 'start_at_local');
          if (!endRaw) return fail('End schedule is required.', 'end_at_local');
          const startDate = parseLocalDate(startRaw);
          const endDate = parseLocalDate(endRaw);
          if (!startDate || !endDate) {
            return fail('Invalid date/time selection.', startDate ? 'end_at_local' : 'start_at_local');
          }
          if (mode === 'create' && startDate < earliestAllowedCreateDateTime()) {
            return fail('Start date/time must be tomorrow or later (starting 7:00 AM).', 'start_at_local');
          }
          if (endDate <= startDate) {
            return fail('End time must be after start time.', 'end_at_local');
          }
        }
      } catch (scheduleErr) {
        const msg = scheduleErr?.message || 'Complete the event schedule.';
        let focusId = 'start_at_local';
        if (/Seminar 2/i.test(msg)) {
          if (/title/i.test(msg)) focusId = 'seminar2_title';
          else if (/end/i.test(msg)) focusId = 'seminar2_end_local';
          else focusId = 'seminar2_start_local';
        } else if (/Seminar 1/i.test(msg)) {
          if (/title/i.test(msg)) focusId = 'seminar1_title';
          else if (/end/i.test(msg)) focusId = 'seminar1_end_local';
          else focusId = 'seminar1_start_local';
        }
        return fail(msg, focusId);
      }

      if (document.getElementById('registration_close_weeks')) {
        refreshRegistrationCloseOptions();
        const startForClose = getEventStartForCloseLimit();
        const maxCloseWeeks = maxRegistrationCloseWeeksFromStart(startForClose);
        if (maxCloseWeeks != null && maxCloseWeeks >= 1) {
          const closeRaw = String(document.getElementById('registration_close_weeks')?.value || '').trim();
          const closeWeeks = Number.parseInt(closeRaw, 10);
          if (!Number.isFinite(closeWeeks) || closeWeeks < 1 || closeWeeks > maxCloseWeeks) {
            return fail(
              `Choose a registration close limit between 1 and ${maxCloseWeeks} week${maxCloseWeeks === 1 ? '' : 's'}.`,
              'registration_close_weeks'
            );
          }
        }
      }

      return null;
    }

    if (currentStep === 4 && teacherProposalMode) {
      try {
        if (mode === 'edit') {
          validateTeacherProposalEditComplete();
        } else {
          collectTeacherProposalRequirements();
        }
      } catch (proposalErr) {
        return fail(
          proposalErr?.message || 'Complete the required proposal documents.',
          mode === 'edit' ? 'teacherProposalEditSection' : 'teacherProposalCreateSection'
        );
      }
      return null;
    }

    return null;
  }

  function syncEventFeeVisibility() {
    const wrap = document.getElementById('event_fee_wrap');
    const feeInput = document.getElementById('event_fee');
    if (!wrap) return;
    const isPaid = getRegistrationType() === 'paid';
    wrap.classList.toggle('hidden', !isPaid);
    if (!isPaid && feeInput) feeInput.value = '';
  }

  function setRegistrationType(type) {
    const freeInput = document.getElementById('registration_type_free');
    const paidInput = document.getElementById('registration_type_paid');
    const isFree = type !== 'paid';
    if (freeInput) freeInput.checked = isFree;
    if (paidInput) paidInput.checked = !isFree;
    syncEventFeeVisibility();
  }

  function bindRegistrationTypeCheckboxes() {
    const freeInput = document.getElementById('registration_type_free');
    const paidInput = document.getElementById('registration_type_paid');
    if (!freeInput || !paidInput) return;

    freeInput.addEventListener('change', () => {
      if (freeInput.checked) {
        paidInput.checked = false;
        syncEventFeeVisibility();
        return;
      }
      if (!paidInput.checked) {
        freeInput.checked = true;
      }
      syncEventFeeVisibility();
    });

    paidInput.addEventListener('change', () => {
      if (paidInput.checked) {
        freeInput.checked = false;
        syncEventFeeVisibility();
        return;
      }
      if (!freeInput.checked) {
        paidInput.checked = true;
      }
      syncEventFeeVisibility();
    });

    syncEventFeeVisibility();
  }
  bindRegistrationTypeCheckboxes();

  const REGISTRATION_LIMIT_MAX = 9999;
  const PROPOSAL_FILE_MAX_BYTES = 10 * 1024 * 1024;

  function validateProposalFile(file, label) {
    if (!file) return;
    if (file.size > PROPOSAL_FILE_MAX_BYTES) {
      throw new Error(`"${label}" exceeds the 10MB file size limit. Choose a smaller file.`);
    }
  }

  async function rollbackCreatedProposal(eventId) {
    const id = String(eventId || '').trim();
    if (!id) return;
    const res = await fetch('/api/events_proposal_rollback.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ event_id: id, csrf_token: window.CSRF_TOKEN || '' }),
    });
    const data = await res.json().catch(() => ({}));
    if (!res.ok || !data.ok) {
      throw new Error(data.error || 'Could not cancel the unfinished proposal.');
    }
  }

  function validateCoverFileBeforeSubmit() {
    if (!coverFilePending) return;
    const allowed = ['image/jpeg', 'image/png', 'image/webp'];
    if (!allowed.includes(coverFilePending.type)) {
      throw new Error('Cover image must be JPG, PNG, or WEBP.');
    }
    if (coverFilePending.size > 5 * 1024 * 1024) {
      throw new Error('Cover image must be 5MB or smaller.');
    }
  }

  function validateTeacherProposalEditComplete() {
    if (!teacherProposalMode) return;

    const stage = String(teacherProposalEditState.stage || '').trim().toLowerCase();
    const status = String(teacherProposalEditState.status || '').trim().toLowerCase();
    if (stage !== 'pending_requirements' && status !== 'archived') {
      return;
    }

    const submissionsByRequirement = {};
    (teacherProposalEditState.submissions || []).forEach((submission) => {
      const requirementId = String(submission?.requirement_id || '').trim();
      if (requirementId) {
        submissionsByRequirement[requirementId] = submission;
      }
    });

    const rows = Array.from(document.querySelectorAll('#teacherProposalEditRequirementsList [data-requirement-id]'));
    rows.forEach((row) => {
      const requirementId = String(row.dataset.requirementId || '').trim();
      const label = String(row.querySelector('.text-sm.font-bold')?.textContent || 'requirement').trim();
      const file = row.querySelector('.proposal-edit-file-input')?.files?.[0] || null;
      const existing = submissionsByRequirement[requirementId];
      const hasExisting = Boolean(
        existing && String(existing.file_url || existing.file_path || '').trim() !== ''
      );

      if (!hasExisting && !file) {
        throw new Error(`Upload the file for "${label}".`);
      }
      if (file) {
        validateProposalFile(file, label);
      }
    });
  }

  const registrationLimitInput = document.getElementById('registration_limit');
  registrationLimitInput?.addEventListener('input', () => {
    const digitsOnly = String(registrationLimitInput.value || '').replace(/\D+/g, '').slice(0, 4);
    registrationLimitInput.value = digitsOnly;
  });

  document.getElementById('btnCreateEvent')?.addEventListener('click', () => {
    document.getElementById('mode').value = 'create';
    document.getElementById('event_id').value = '';

    document.getElementById('title').value = '';
    resetCoverPicker();
    document.getElementById('location').value = '';
    document.getElementById('description').value = '';
    setPickerValue(startAtInput, '');
    setPickerValue(endAtInput, '');

    document.getElementById('seminar1_title').value = '';
    setPickerValue(document.getElementById('seminar1_start_local'), '');
    setPickerValue(document.getElementById('seminar1_end_local'), '');
    document.getElementById('seminar2_title').value = '';
    setPickerValue(document.getElementById('seminar2_start_local'), '');
    setPickerValue(document.getElementById('seminar2_end_local'), '');

    setEventTypeValue('Event');
    if (document.getElementById('target_course')) document.getElementById('target_course').value = 'ALL';
    setSelectedTargetYears(['ALL']);
    if (document.getElementById('grace_time')) document.getElementById('grace_time').value = '30';
    setRegistrationType('free');
    const eventFeeInput = document.getElementById('event_fee');
    if (eventFeeInput) eventFeeInput.value = '';
    const registrationLimitInput = document.getElementById('registration_limit');
    if (registrationLimitInput) registrationLimitInput.value = '';
    refreshRegistrationCloseOptions();
    resetTeacherProposalRequirements();
    resetStudentRequirementsForm();

    const msg = document.getElementById('formMsg');
    if (msg) {
      msg.className = 'text-sm text-amber-800 min-h-0 !mt-0';
      msg.textContent = '';
    }
    clearWizardStepError();

    const modalTitle = document.getElementById('modalTitle');
    if (modalTitle) modalTitle.textContent = 'Create Event';
    const subtitle = document.getElementById('modalSubtitle');
    if (subtitle) subtitle.textContent = 'Fill in the event info';
    const submitLabel = document.querySelector('#btnSubmit span:last-child');
    if (submitLabel) submitLabel.textContent = teacherProposalMode ? 'Submit for Review' : 'Save Event';

    setStructure('simple', 0);

    const createMinDate = earliestAllowedCreateDateTime();
    setPickerMin(startAtInput, createMinDate);
    setPickerMin(seminar1StartInput, createMinDate);
    setPickerMin(seminar2StartInput, createMinDate);

    updateEndMin(startAtInput, endAtInput);
    updateEndMin(seminar1StartInput, seminar1EndInput);
    updateEndMin(seminar2StartInput, seminar2EndInput);
    syncSeminar2Gate(true);

    step = 1;
    setWizardStep(1);
    setEventModalReadOnly(false);
    openModal(eventModal);
  });

  document.getElementById('btnCloseModal').addEventListener('click', () => closeModal(eventModal));

  document.getElementById('btnNext').addEventListener('click', () => {
    if (eventModalReadOnly) {
      step = Math.min(maxWizardStep, step + 1);
      setWizardStep(step);
      return;
    }

    clearWizardStepError();
    const error = validateWizardStep(step);
    if (error) {
      showWizardStepError(error.message, error.focusId);
      return;
    }

    if (step === 1) {
      step = 2;
    } else if (step === 2) {
      step = 3;
    } else if (step === 3 && maxWizardStep >= 4) {
      step = 4;
    }
    setWizardStep(step);
  });

  document.getElementById('btnBack').addEventListener('click', () => {
    clearWizardStepError();
    step = Math.max(1, step - 1);
    setWizardStep(step);
  });

  document.getElementById('btnSubmit').addEventListener('click', () => {
    document.getElementById('eventForm').requestSubmit();
  });

  document.querySelectorAll('.btnEdit').forEach((btn) => {
    btn.addEventListener('click', (event) => {
      event.stopPropagation();
      openEventModalFromDataset(btn, false);
    });
  });

  document.getElementById('eventForm').addEventListener('submit', async (e) => {
    e.preventDefault();

    if (step !== maxWizardStep) {
      document.getElementById('btnNext').click();
      return;
    }

    clearWizardStepError();
    const stepError = validateWizardStep(step);
    if (stepError) {
      showWizardStepError(stepError.message, stepError.focusId);
      return;
    }

    const mode = document.getElementById('mode').value;
    const submitBtn = document.getElementById('btnSubmit');
    let draftEventId = '';

    window.pulseManageEventsBusy = true;

    try {
      validateCoverFileBeforeSubmit();

      const title = document.getElementById('title').value.trim();
      const location = document.getElementById('location').value.trim();
      const description = document.getElementById('description').value.trim();
      const eventType = getEventTypeValue();
      if (!eventType) {
        throw new Error('Specify what this Other event type is.');
      }
      const targetCourse = document.getElementById('target_course') ? document.getElementById('target_course').value : 'ALL';
      const targetYears = getSelectedTargetYears();
      const eventFor = encodeTargetParticipant(targetCourse, targetYears);
      const graceTime = document.getElementById('grace_time') ? document.getElementById('grace_time').value : '30';

      const eventMode = (eventModeInput?.value || 'simple').trim();
      const seminarCount = Number.parseInt(seminarCountInput?.value || '0', 10) || 0;

      if (!title) {
        throw new Error('Event title is required.');
      }

      let startDate = null;
      let endDate = null;
      let sessions = [];

      if (eventMode === 'seminar_based') {
        sessions.push(collectSeminarPayload(1));
        if (seminarCount === 2) {
          sessions.push(collectSeminarPayload(2));
        }

        const hasInvalidSeminarTime = sessions.some((session) => {
          const sessionStart = new Date(session.start_at);
          const sessionEnd = new Date(session.end_at);
          return isBeforeAllowedScheduleTime(sessionStart) || isBeforeAllowedScheduleTime(sessionEnd);
        });
        if (hasInvalidSeminarTime) {
          throw new Error('Seminar time must be 7:00 AM or later, and minutes must be 00 or 30 only.');
        }

        if (mode === 'create') {
          const minAllowed = earliestAllowedCreateDateTime();
          const hasEarlySeminar = sessions.some((session) => new Date(session.start_at) < minAllowed);
          if (hasEarlySeminar) {
            throw new Error('Seminar start date/time must be tomorrow or later (starting 7:00 AM).');
          }
        }

        const windowRange = deriveWindowFromSessions(sessions);
        if (!windowRange) {
          throw new Error('Seminar schedule is invalid.');
        }

        startDate = windowRange.start;
        endDate = windowRange.end;
      } else {
        const startRaw = (startAtInput?.value || '').trim();
        const endRaw = (endAtInput?.value || '').trim();
        if (!startRaw || !endRaw) {
          throw new Error('Start and end schedule are required.');
        }

        startDate = parseLocalDate(startRaw);
        endDate = parseLocalDate(endRaw);
        if (!startDate || !endDate) {
          throw new Error('Invalid date/time selection.');
        }
        if (isBeforeAllowedScheduleTime(startDate) || isBeforeAllowedScheduleTime(endDate)) {
          throw new Error('Event time must be 7:00 AM or later, and minutes must be 00 or 30 only.');
        }
        if (mode === 'create' && startDate < earliestAllowedCreateDateTime()) {
          throw new Error('Start date/time must be tomorrow or later (starting 7:00 AM).');
        }
        endDate = new Date(
          startDate.getFullYear(),
          startDate.getMonth(),
          startDate.getDate(),
          17,
          0,
          0,
          0
        );
        setPickerValue(endAtInput, formatLocalForPicker(endDate));
        if (endDate <= startDate) {
          throw new Error('End time must be after start time.');
        }
      }

      const eventSpan = startDate.toDateString() === endDate.toDateString() ? 'single_day' : 'multi_day';
      let teacherProposalPayload = { requirements: [], files: [] };
      let teacherProposalEditReplacements = [];
      if (teacherProposalMode && mode === 'create') {
        teacherProposalPayload = collectTeacherProposalRequirements();
      } else if (teacherProposalMode && mode === 'edit') {
        teacherProposalEditReplacements = collectTeacherProposalEditReplacements();
      }

      const payload = {
        title,
        location,
        description,
        event_type: eventType,
        event_for: eventFor,
        grace_time: graceTime,
        event_mode: eventMode,
        event_span: eventSpan,
        start_at: startDate.toISOString(),
        end_at: endDate.toISOString(),
        sessions: eventMode === 'seminar_based' ? sessions : [],
        csrf_token: window.CSRF_TOKEN
      };

      if (teacherProposalMode && mode === 'create') {
        payload.proposal_requirements = teacherProposalPayload.requirements;
      }

      // Always save student docs package on create AND edit/resubmit
      if (teacherProposalMode) {
        payload.student_requirements = collectStudentRequirements();
      }

      // Registration package — teachers on create/edit, and admins when editing via View/Edit.
      if (teacherProposalMode || document.getElementById('registration_type_paid')) {
        payload.is_free_event = getRegistrationType() === 'free';
        if (!payload.is_free_event) {
          const feeInput = document.getElementById('event_fee');
          const feeRaw = feeInput ? String(feeInput.value || '').trim() : '';
          const feeNum = Number.parseFloat(feeRaw);
          if (!Number.isFinite(feeNum) || feeNum <= 0) {
            throw new Error('Enter the settlement amount students must pay for this paid event.');
          }
          payload.event_fee = Math.round(feeNum * 100) / 100;
        } else {
          payload.event_fee = null;
        }
        const registrationLimitInput = document.getElementById('registration_limit');
        const limitRaw = registrationLimitInput ? String(registrationLimitInput.value || '').trim() : '';
        if (limitRaw !== '') {
          const limitNum = Number.parseInt(limitRaw, 10);
          if (!Number.isFinite(limitNum) || limitNum < 1) {
            throw new Error('Student limit must be a positive whole number.');
          }
          if (limitNum > REGISTRATION_LIMIT_MAX) {
            throw new Error('Student limit cannot exceed 9999.');
          }
          payload.registration_limit = limitNum;
        } else {
          payload.registration_limit = null;
        }

        const registrationCloseWeeksInput = document.getElementById('registration_close_weeks');
        const maxCloseWeeks = maxRegistrationCloseWeeksFromStart(startDate);
        if (maxCloseWeeks === null || maxCloseWeeks < 1) {
          payload.registration_close_weeks = null;
        } else {
          const closeWeeksRaw = registrationCloseWeeksInput
            ? String(registrationCloseWeeksInput.value || '').trim()
            : '';
          const closeWeeks = Number.parseInt(closeWeeksRaw, 10);
          if (!Number.isFinite(closeWeeks) || closeWeeks < 1 || closeWeeks > maxCloseWeeks) {
            throw new Error(
              `Registration close limit must be between 1 and ${maxCloseWeeks} week${maxCloseWeeks === 1 ? '' : 's'} for this start date.`
            );
          }
          payload.registration_close_weeks = closeWeeks;
        }
      }

      if (teacherProposalMode && mode === 'edit') {
        validateTeacherProposalEditComplete();
      }

      if (mode === 'edit') {
        payload.event_id = document.getElementById('event_id').value;
      }

      showWizardStepProgress(
        mode === 'edit'
          ? 'Updating event...'
          : (teacherProposalMode ? 'Creating proposal and uploading requirements...' : 'Creating event...')
      );
      submitBtn.disabled = true;
      submitBtn.classList.add('opacity-50', 'cursor-not-allowed');

      const url = mode === 'edit' ? '/api/events_update.php' : '/api/events_create.php';
      const res = await fetch(url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
      });

      const data = await res.json();
      if (!data.ok) {
        throw new Error(data.error || 'Request failed.');
      }

      const savedEventId = mode === 'edit'
        ? String(payload.event_id || document.getElementById('event_id').value || '').trim()
        : String((data.event && data.event.id) || '').trim();

      if (teacherProposalMode && mode === 'create' && savedEventId) {
        draftEventId = savedEventId;
      }

      if (coverFilePending && savedEventId) {
        showWizardStepProgress('Uploading cover image...');
        await uploadEventCover(savedEventId);
      }

      if (teacherProposalMode && mode === 'create') {
        const createdEvent = data.event || {};
        const eventId = String(createdEvent.id || draftEventId || '').trim();
        const savedRequirements = Array.isArray(createdEvent.proposal_requirements) ? createdEvent.proposal_requirements : [];
        if (!eventId || savedRequirements.length !== teacherProposalPayload.files.length) {
          throw new Error('Proposal requirements could not be prepared. Nothing was submitted.');
        }

        for (let i = 0; i < savedRequirements.length; i += 1) {
          const requirement = savedRequirements[i];
          const file = teacherProposalPayload.files[i];
          const requirementId = String(requirement?.id || '').trim();
          if (!requirementId || !file) {
            throw new Error('Missing uploaded proposal file data.');
          }

          showWizardStepProgress(`Uploading proposal file ${i + 1} of ${savedRequirements.length}...`);

          const formData = new FormData();
          formData.append('event_id', eventId);
          formData.append('requirement_id', requirementId);
          formData.append('csrf_token', window.CSRF_TOKEN || '');
          formData.append('proposal_file', file);

          const uploadRes = await fetch('/api/event_proposal_document_upload.php', {
            method: 'POST',
            body: formData,
          });
          const uploadData = await uploadRes.json();
          if (!uploadData.ok) {
            throw new Error(uploadData.error || `Failed to upload ${file.name}.`);
          }
        }

        showWizardStepProgress('Submitting proposal for admin review...');
        const reviewRes = await fetch('/api/event_proposal_submit_review.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ event_id: eventId, csrf_token: window.CSRF_TOKEN })
        });
        const reviewData = await reviewRes.json();
        if (!reviewData.ok) {
          throw new Error(reviewData.error || 'Failed to submit the proposal for review.');
        }

        draftEventId = '';
      } else if (teacherProposalMode && mode === 'edit') {
        const eventId = String(payload.event_id || '').trim();
        const shouldSubmitForReview = teacherProposalEditState.status === 'archived'
          || teacherProposalEditState.stage === 'pending_requirements';

        if (shouldSubmitForReview && !eventId) {
          throw new Error('Missing event id for proposal submission.');
        }

        if (teacherProposalEditReplacements.length > 0) {
          for (let i = 0; i < teacherProposalEditReplacements.length; i += 1) {
            const replacement = teacherProposalEditReplacements[i];
            showWizardStepProgress(`Uploading replacement file ${i + 1} of ${teacherProposalEditReplacements.length}...`);
            const formData = new FormData();
            formData.append('event_id', eventId);
            formData.append('requirement_id', replacement.requirementId);
            formData.append('csrf_token', window.CSRF_TOKEN || '');
            formData.append('proposal_file', replacement.file);

            const uploadRes = await fetch('/api/event_proposal_document_upload.php', {
              method: 'POST',
              body: formData,
            });
            const uploadData = await uploadRes.json();
            if (!uploadData.ok) {
              throw new Error(uploadData.error || `Failed to upload ${replacement.file.name}.`);
            }
          }
        }

        if (shouldSubmitForReview) {
          showWizardStepProgress(
            teacherProposalEditState.status === 'archived'
              ? 'Submitting updated proposal for admin review...'
              : 'Submitting proposal for admin review...'
          );
          const reviewRes = await fetch('/api/event_proposal_submit_review.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ event_id: eventId, csrf_token: window.CSRF_TOKEN })
          });
          const reviewData = await reviewRes.json();
          if (!reviewData.ok) {
            throw new Error(reviewData.error || 'Failed to submit the proposal for review.');
          }
        }
      }

      showWizardFooterStatus(
        (teacherProposalMode && mode === 'create')
          ? 'Proposal submitted for admin review!'
          : (teacherProposalMode && mode === 'edit' && (teacherProposalEditState.status === 'archived' || teacherProposalEditState.stage === 'pending_requirements'))
            ? 'Proposal submitted for admin review!'
            : 'Success!',
        'success'
      );
      window.pulseManageEventsSubmitFailedAt = 0;
      setTimeout(() => window.location.reload(), 350);
    } catch (err) {
      let errorMessage = err?.message || 'Server error encountered.';
      if (draftEventId) {
        showWizardStepProgress('Upload failed. Canceling the unfinished proposal...');
        try {
          await rollbackCreatedProposal(draftEventId);
          draftEventId = '';
        } catch (rollbackErr) {
          errorMessage = `${errorMessage} The unfinished proposal could not be removed automatically — please delete it manually from the list.`;
        }
      }
      window.pulseManageEventsSubmitFailedAt = Date.now();
      showWizardStepError(errorMessage);
      submitBtn.disabled = false;
      submitBtn.classList.remove('opacity-50', 'cursor-not-allowed');
    } finally {
      window.pulseManageEventsBusy = false;
    }
  });
  // ── Approve / Reject from Review Docs modal ──
  async function approveProposalFromReview() {
    const event_id = proposalRequirementsEventId?.value || '';
    const approveBtn = document.getElementById('btnApproveFromReview');
    if (!event_id || !approveBtn || approveBtn.disabled) return;

    const original = approveBtn.textContent;
    approveBtn.disabled = true;
    approveBtn.textContent = '...';
    try {
      const res = await fetch('/api/events_approve.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ event_id, status: 'approved', csrf_token: window.CSRF_TOKEN })
      });
      const data = await res.json();
      if (!data.ok) throw new Error(data.error || 'Failed');
      window.location.reload();
    } catch (e) {
      alert(e.message || 'Failed');
      syncProposalReviewActions();
      approveBtn.textContent = original || 'Approve';
    }
  }

  function openRejectFromReview() {
    const event_id = proposalRequirementsEventId?.value || '';
    const title = document.getElementById('proposalRequirementsEventTitle')?.value
      || (proposalRequirementsTitle?.textContent || '').replace(/^Proposal documents\s*[•·]\s*/i, '').trim()
      || 'this proposal';
    if (!event_id) return;

    const rejectModalEl = document.getElementById('rejectModal');
    const rejectPanelEl = document.getElementById('rejectPanel');
    document.getElementById('rejectEventId').value = event_id;
    document.getElementById('rejectEventName').textContent = title;
    closeProposalRequirementsModal();
    if (!rejectModalEl || !rejectPanelEl) return;
    rejectModalEl.classList.add('active');
    rejectPanelEl.style.transform = 'translateY(0)';
    document.body.style.overflow = 'hidden';
  }

  document.getElementById('btnApproveFromReview')?.addEventListener('click', () => {
    void approveProposalFromReview();
  });
  document.getElementById('btnRejectFromReview')?.addEventListener('click', openRejectFromReview);

  // ── Approve (legacy card buttons, if any remain) ──
  document.querySelectorAll('.btnApprove').forEach(btn => {
    btn.addEventListener('click', async () => {
      if (btn.disabled) return;
      const event_id = btn.dataset.id;
      const status = btn.dataset.status;
      const label = btn.dataset.label || 'Approve';
      btn.disabled = true;
      btn.textContent = '...';
      try {
        const res = await fetch('/api/events_approve.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ event_id, status, csrf_token: window.CSRF_TOKEN })
        });
        const data = await res.json();
        if (!data.ok) throw new Error(data.error || 'Failed');
        window.location.reload();
      } catch (e) {
        alert(e.message || 'Failed');
      } finally {
        btn.textContent = label;
        btn.disabled = btn.dataset.approveReady !== '1';
      }
    });
  });

  // ── Publish Modal Teacher Assignment ──
  const publishTeacherEventId = document.getElementById('publishTeacherEventId');
  const publishTeacherCreatorId = document.getElementById('publishTeacherCreatorId');
  const publishTeacherEventTitle = document.getElementById('publishTeacherEventTitle');
  const publishTeacherCount = document.getElementById('publishTeacherCount');
  const publishTeacherCheckboxes = Array.from(document.querySelectorAll('.publish-teacher-checkbox'));
  const publishTeacherCards = Array.from(document.querySelectorAll('.publish-teacher-card'));
  const btnPublishSelectAllTeachers = document.getElementById('btnPublishSelectAllTeachers');
  const btnPublishClearTeachers = document.getElementById('btnPublishClearTeachers');
  const btnClosePublishTeacherModal = document.getElementById('btnClosePublishTeacherModal');
  const btnCancelPublishTeachers = document.getElementById('btnCancelPublishTeachers');
  const btnConfirmPublishTeachers = document.getElementById('btnConfirmPublishTeachers');

  function refreshPublishTeacherSelection() {
    let total = 0;
    publishTeacherCheckboxes.forEach((checkbox, index) => {
      const checked = !!checkbox.checked;
      const card = publishTeacherCards[index];
      if (checked) {
        total += 1;
        card?.classList.remove('border-zinc-200', 'bg-white', 'hover:border-zinc-300');
        card?.classList.add('border-orange-300', 'bg-orange-50/60');
      } else {
        card?.classList.remove('border-orange-300', 'bg-orange-50/60');
        card?.classList.add('border-zinc-200', 'bg-white', 'hover:border-zinc-300');
      }

      const creatorBadge = card?.querySelector('.creator-badge');
      if (creatorBadge) {
        if (checkbox.value === (publishTeacherCreatorId?.value || '')) {
          creatorBadge.classList.remove('hidden');
        } else {
          creatorBadge.classList.add('hidden');
        }
      }
    });

    if (publishTeacherCount) {
      publishTeacherCount.textContent = String(total);
    }
  }

  function openPublishTeacherAssignmentModal(eventId, title, creatorId) {
    if (!publishTeacherModal || !publishTeacherPanel || !publishTeacherEventId || !publishTeacherEventTitle || !publishTeacherCreatorId) {
      return;
    }

    publishTeacherEventId.value = eventId || '';
    publishTeacherCreatorId.value = creatorId || '';
    publishTeacherEventTitle.textContent = title || 'this event';

    publishTeacherCheckboxes.forEach((checkbox) => {
      checkbox.checked = creatorId !== '' && checkbox.value === creatorId;
    });

    refreshPublishTeacherSelection();
    publishTeacherModal.classList.add('active');
    publishTeacherPanel.style.transform = 'translateY(0)';
    document.body.style.overflow = 'hidden';
  }

  document.querySelectorAll('.btnPublishEvent').forEach(btn => {
    btn.addEventListener('click', () => {
      openPublishTeacherAssignmentModal(
        btn.dataset.id || '',
        btn.dataset.title || 'this event',
        btn.dataset.created_by || ''
      );
    });
  });

  publishTeacherCheckboxes.forEach((checkbox) => {
    checkbox.addEventListener('change', refreshPublishTeacherSelection);
  });

  btnPublishSelectAllTeachers?.addEventListener('click', () => {
    publishTeacherCheckboxes.forEach((checkbox) => {
      checkbox.checked = true;
    });
    refreshPublishTeacherSelection();
  });

  btnPublishClearTeachers?.addEventListener('click', () => {
    publishTeacherCheckboxes.forEach((checkbox) => {
      checkbox.checked = false;
    });
    refreshPublishTeacherSelection();
  });

  btnClosePublishTeacherModal?.addEventListener('click', closePublishTeacherAssignmentModal);
  btnCancelPublishTeachers?.addEventListener('click', closePublishTeacherAssignmentModal);

  btnConfirmPublishTeachers?.addEventListener('click', async () => {
    const event_id = publishTeacherEventId?.value || '';
    const teacher_ids = publishTeacherCheckboxes
      .filter((checkbox) => checkbox.checked)
      .map((checkbox) => checkbox.value);

    if (!event_id) {
      alert('Missing event id.');
      return;
    }

    if (teacher_ids.length === 0) {
      alert('Select at least one teacher before publishing this event.');
      return;
    }

    btnConfirmPublishTeachers.disabled = true;
    btnConfirmPublishTeachers.textContent = 'Publishing...';

    try {
      const res = await fetch('/api/events_approve.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          event_id,
          status: 'published',
          teacher_ids,
          csrf_token: window.CSRF_TOKEN
        })
      });
      const data = await res.json();
      if (!data.ok) throw new Error(data.error || 'Failed to publish event.');
      if (data.push) {
        console.log('[publish push]', data.push);
        const sent = Number(data.push.fcm_sent ?? 0);
        const failed = Number(data.push.fcm_failed ?? 0);
        const tokens = Number(data.push.tokens ?? 0);
        const pushError = String(data.push.error || '');
        // No mobile FCM tokens is not a publish failure — inbox still saved.
        const skippedNoTokens = data.push.fcm_skipped === true
          || tokens === 0
          || pushError.includes('no_fcm_tokens');
        // Only alert when devices existed but FCM send actually failed.
        if (data.push.attempted && !data.push.fcm_ok && sent === 0 && !skippedNoTokens) {
          const detail = [
            'Event published, but push notification failed.',
            'targets=' + (data.push.targets ?? 0),
            'tokens=' + tokens,
            'error=' + (data.push.error || 'unknown'),
            data.push.http_status ? ('http=' + data.push.http_status) : null,
            data.push.detail ? ('detail=' + data.push.detail) : null,
          ].filter(Boolean).join('\n');
          alert(detail);
        } else if (data.push.partial || (sent > 0 && failed > 0)) {
          console.warn('[publish push] delivered with some stale tokens cleaned up', { sent, failed });
        }
      }
      window.location.reload();
    } catch (e) {
      alert(e.message || 'Failed to publish event.');
      btnConfirmPublishTeachers.disabled = false;
      btnConfirmPublishTeachers.textContent = 'Publish Event';
    }
  });
  // ── Unified Filtering Logic (Tabs + Search + Type) ──
  const role = '<?= htmlspecialchars($role) ?>';
  const tabAll = document.getElementById('tabAll');
  const tabPending = document.getElementById('tabPending');
  const tabActive = document.getElementById('tabActive');
  const tabApproval = document.getElementById('tabApproval');
  const tabExpired = document.getElementById('tabExpired');
  const searchInput = document.getElementById('searchEvents');
  const typeFilter = document.getElementById('filterType');
  const eventCards = document.querySelectorAll('.event-card');

  let activeTab = role === 'teacher' ? 'active' : 'all';

  function getEventCards() {
    return document.querySelectorAll('.event-card');
  }

  function resolveTeacherBucket(card) {
    const status = ((card.dataset.status || '') + '').toLowerCase();
    const hasRejectRemark = ((card.dataset.isRejected || '') + '') === '1';

    const endRaw = ((card.dataset.endAt || '') + '').trim();
    const endDate = endRaw !== '' ? new Date(endRaw) : null;
    const now = new Date();
    const isPast = endDate instanceof Date && !Number.isNaN(endDate.getTime()) && endDate < now;

    if (((status === 'expired' || status === 'finished' || isPast) && status !== 'archived')) {
      return 'expired';
    }

    if (status === 'published' && !isPast) {
      return 'active';
    }

    if ((status === 'pending' || status === 'approved' || status === 'rejected') && !isPast) {
      return 'approval';
    }

    if (status === 'archived' && hasRejectRemark && !isPast) {
      return 'approval';
    }

    return 'hidden';
  }

  function refreshEventVisibility() {
    const searchTerm = searchInput ? searchInput.value.toLowerCase().trim() : '';
    const selectedType = typeFilter ? typeFilter.value : 'all';
    let visibleCount = 0;

    getEventCards().forEach(card => {
      const status = ((card.dataset.status || '') + '').toLowerCase();
      const title = ((card.dataset.title || '') + '').toLowerCase();
      const location = ((card.dataset.location || '') + '').toLowerCase();
      const teacher = ((card.dataset.teacher || '') + '').toLowerCase();
      const type = (card.dataset.type || '').trim();

      let matchesTab = true;
      if (role === 'teacher') {
        const bucket = resolveTeacherBucket(card);
        matchesTab = bucket === activeTab;
      } else {
        matchesTab = (activeTab === 'all') || (status === 'pending');
      }

      const matchesSearch = searchTerm === '' ||
        title.includes(searchTerm) ||
        location.includes(searchTerm) ||
        teacher.includes(searchTerm);
      const matchesType = selectedType === 'all' || type === selectedType;

      if (matchesTab && matchesSearch && matchesType) {
        card.style.display = 'block';
        visibleCount += 1;

        // Trigger Animation Refresh
        if (card.classList.contains('event-card-animated')) {
          card.classList.remove('in-view');
          // Use global observer if available
          if (typeof window.observer !== 'undefined') {
            window.observer.unobserve(card);
            window.observer.observe(card);
          }
        }
      } else {
        card.style.display = 'none';
      }
    });

    const emptyState = document.getElementById('eventListEmptyState');
    if (emptyState) {
      emptyState.classList.toggle('hidden', visibleCount > 0);
    }

    // Update gradients after visibility change
    if (typeof window.syncEventListGradients === 'function') {
      setTimeout(window.syncEventListGradients, 50);
    }
  }

  function setTeacherTabState(nextTab) {
    activeTab = nextTab;
    const tabs = [
      { el: tabActive, key: 'active' },
      { el: tabApproval, key: 'approval' },
      { el: tabExpired, key: 'expired' },
    ];

    tabs.forEach(({ el, key }) => {
      if (!el) return;
      const active = key === nextTab;
      el.classList.toggle('border-sky-500', active);
      el.classList.toggle('text-sky-600', active);
      el.classList.toggle('font-bold', active);
      el.classList.toggle('border-transparent', !active);
      el.classList.toggle('text-zinc-500', !active);
      el.classList.toggle('font-semibold', !active);
    });
    refreshEventVisibility();
  }

  if (tabAll && tabPending) {
    tabAll.addEventListener('click', () => {
      activeTab = 'all';
      tabAll.classList.add('border-orange-500', 'text-orange-600');
      tabAll.classList.remove('border-transparent', 'text-zinc-500');
      tabPending.classList.remove('border-orange-500', 'text-orange-600');
      tabPending.classList.add('border-transparent', 'text-zinc-500');
      refreshEventVisibility();
    });

    tabPending.addEventListener('click', () => {
      activeTab = 'pending';
      tabPending.classList.add('border-orange-500', 'text-orange-600');
      tabPending.classList.remove('border-transparent', 'text-zinc-500');
      tabAll.classList.remove('border-orange-500', 'text-orange-600');
      tabAll.classList.add('border-transparent', 'text-zinc-500');
      refreshEventVisibility();
    });
  }

  if (tabActive && tabApproval && tabExpired) {
    tabActive.addEventListener('click', () => setTeacherTabState('active'));
    tabApproval.addEventListener('click', () => setTeacherTabState('approval'));
    tabExpired.addEventListener('click', () => setTeacherTabState('expired'));
  }

  // Real-time input listeners
  searchInput?.addEventListener('input', refreshEventVisibility);
  typeFilter?.addEventListener('change', refreshEventVisibility);
  refreshEventVisibility();

  // ── Reject (Page 34 Modal) ──
  const rejectModal = document.getElementById('rejectModal');
  const rejectPanel = document.getElementById('rejectPanel');

  document.querySelectorAll('.btnReject').forEach(btn => {
    btn.addEventListener('click', () => {
      document.getElementById('rejectEventId').value = btn.dataset.id;
      document.getElementById('rejectEventName').textContent = btn.dataset.title;

      rejectModal.classList.add('active');
      rejectPanel.style.transform = 'translateY(0)';
      document.body.style.overflow = 'hidden';
    });
  });

  const closeReject = () => {
    rejectModal.classList.remove('active');
    rejectPanel.style.transform = 'translateY(100%)';
    document.body.style.overflow = '';
    setTimeout(() => document.getElementById('rejectReason').value = '', 300);
  };

  document.getElementById('btnCancelReject')?.addEventListener('click', closeReject);
  rejectModal?.addEventListener('click', (e) => { if (e.target === rejectModal) closeReject(); });

  document.getElementById('btnConfirmReject')?.addEventListener('click', async () => {
    const event_id = document.getElementById('rejectEventId').value;
    const reason = document.getElementById('rejectReason').value.trim();
    if (!reason) { alert("Please provide a reason to notify the event coordinator."); return; }

    const btn = document.getElementById('btnConfirmReject');
    btn.disabled = true; btn.textContent = 'Sending...';
    try {
      const res = await fetch('/api/events_approve.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        // Archiving as rejection
        body: JSON.stringify({ event_id, status: 'archived', reason, csrf_token: window.CSRF_TOKEN })
      });
      const data = await res.json();
      if (!data.ok) throw new Error(data.error || 'Failed');
      window.location.reload();
    } catch (e) {
      alert(e.message || 'Failed');
    } finally {
      btn.disabled = false; btn.textContent = 'Reject Proposal';
    }
  });


  // ── Archive (custom confirm modal) ──
  document.querySelectorAll('.btnArchive').forEach(btn => {
    btn.addEventListener('click', () => {
      document.getElementById('archiveEventId').value = btn.dataset.id;
      document.getElementById('archiveEventName').textContent = btn.dataset.title || 'this event';
      openModal(archiveModal);
    });
  });

  document.getElementById('btnCancelArchive').addEventListener('click', () => closeModal(archiveModal));

  document.getElementById('btnConfirmArchive').addEventListener('click', async () => {
    const event_id = document.getElementById('archiveEventId').value;
    const btn = document.getElementById('btnConfirmArchive');
    btn.disabled = true;
    btn.textContent = 'Archiving...';
    try {
      const res = await fetch('/api/events_archive.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ event_id, action: 'archive', csrf_token: window.CSRF_TOKEN })
      });
      const data = await res.json();
      if (!data.ok) throw new Error(data.error || 'Failed');
      window.location.reload();
    } catch (e) {
      alert(e.message || 'Archive failed');
      btn.disabled = false;
      btn.textContent = 'Archive';
    }
  });

  // ── Speech-to-Text: Live Modal Preview + AI Improve ──
  (function () {
    var sttBtn = document.getElementById('sttBtn');
    var textarea = document.getElementById('description');

    // Modal elements
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
    var sttDebug = document.getElementById('sttDebug'); // Debugger

    if (!sttBtn || !textarea || !previewModal) return;

    // Show debugger (Requested by Developer)
    if (sttDebug) sttDebug.classList.remove('hidden');

    function logDebug(msg) {
      console.log("[STT_DEBUG] " + msg);
      if (sttDebug) {
        var d = new Date();
        var ts = d.getHours() + ':' + d.getMinutes() + ':' + d.getSeconds() + '.' + d.getMilliseconds();
        sttDebug.innerHTML += '<div><span class="text-zinc-500">[' + ts + ']</span> ' + msg + '</div>';
        sttDebug.scrollTop = sttDebug.scrollHeight;
      }
    }

    var SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;
    if (!SpeechRecognition) {
      logDebug("ERROR: SpeechRecognition API not supported in this browser!");
      sttBtn.style.opacity = '0.35'; sttBtn.style.cursor = 'not-allowed';
      sttBtn.title = 'Use Chrome or Edge';
      return;
    } else {
      logDebug("SpeechRecognition API found.");
    }

    var isRecording = false;
    var recognition = null;
    var rawTranscript = '';
    var improvedTranscript = '';
    var interimTranscript = '';
    var activeTab = 'raw';

    // ── Real AI API Fetcher (Google Gemini Integration) ──

    function updateCounts() {
      var v = previewText.value;
      charCount.textContent = v.length + ' chars';
      var w = v.trim().split(/\s+/).filter(function (x) { return x.length > 0; });
      wordCount.textContent = w.length + ' word' + (w.length !== 1 ? 's' : '');
    }

    previewText.addEventListener('input', function () {
      if (activeTab === 'raw') {
        rawTranscript = previewText.value;
        improvedTranscript = ''; // Invalidate AI cache if user manually edits raw text
      }
      if (activeTab === 'improved') {
        improvedTranscript = previewText.value;
      }
      updateCounts();
    });

    tabRaw.addEventListener('click', function () {
      if (isRecording) return; // Disallow tab switch while dictating
      activeTab = 'raw';
      tabRaw.classList.add('active'); tabImproved.classList.remove('active');
      previewText.value = rawTranscript; updateCounts();
    });

    tabImproved.addEventListener('click', async function () {
      if (isRecording) return;
      tabImproved.classList.add('active'); tabRaw.classList.remove('active');

      if (activeTab === 'improved') return; // Already here
      activeTab = 'improved';

      // If already cached, just display it instantly!
      if (improvedTranscript) {
        previewText.value = improvedTranscript;
        updateCounts();
        return;
      }

      var currentRaw = rawTranscript.trim();
      if (!currentRaw) {
        previewText.value = ''; updateCounts(); return;
      }

      // LOADING STATE
      var originalBtnReplace = btnReplace.innerHTML;
      previewText.value = '⏳ AI is processing and formatting your text... Please wait.';
      previewText.readOnly = true;
      btnAppend.disabled = true; btnAppend.style.opacity = '0.5';
      btnReplace.disabled = true; btnReplace.style.opacity = '0.5';

      try {
        logDebug("Sending text to Gemini via api/ai_improve.php...");
        var res = await fetch('api/ai_improve.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ raw_text: currentRaw, csrf_token: window.CSRF_TOKEN || "" })
        });

        var data = await res.json();

        if (data.ok) {
          logDebug("Gemini Response SUCCESS.");
          improvedTranscript = data.improved_text;
          if (activeTab === 'improved') previewText.value = improvedTranscript;
        } else {
          logDebug("Gemini API Error: " + data.error);
          improvedTranscript = ''; // Don't cache error
          if (activeTab === 'improved') {
            previewText.value = "⚠️ AI formatting unavailable.\n\n"
              + (data.error || "Use the Raw Text tab to insert your transcript.");
          }
        }
      } catch (err) {
        logDebug("Network err pinging Gemini: " + err);
        improvedTranscript = '';
        if (activeTab === 'improved') {
          previewText.value = "⚠️ Network error trying to connect to the backend API.";
        }
      }

      previewText.readOnly = false;
      btnAppend.disabled = false; btnAppend.style.opacity = '1';
      btnReplace.disabled = false; btnReplace.style.opacity = '1';
      updateCounts();
    });

    // Modal Actions
    function hideModal() {
      if (isRecording) stopRecording('modal-closed');
      closeModal(previewModal);
    }
    document.getElementById('sttPreviewClose').addEventListener('click', hideModal);
    document.getElementById('sttPreviewDiscard').addEventListener('click', hideModal);

    btnReplace.addEventListener('click', function () {
      textarea.value = previewText.value;
      hideModal();
    });
    btnAppend.addEventListener('click', function () {
      var cur = textarea.value;
      if (cur && !cur.endsWith(' ') && !cur.endsWith('\n')) cur += ' ';
      textarea.value = cur + previewText.value;
      hideModal();
    });

    var mediaRecorder = null;
    var audioChunks = [];
    var recordingTimer = null;
    var recordingSeconds = 0;

    function formatTime(sec) {
      var m = Math.floor(sec / 60).toString().padStart(2, '0');
      var s = (sec % 60).toString().padStart(2, '0');
      return m + ':' + s;
    }

    async function startRecording(resume = false) {
      logDebug("startRecording() triggered. Resume: " + resume);
      if (!resume) {
        rawTranscript = '';
        interimTranscript = '';
      }
      activeTab = 'raw';
      isRecording = true;

      // Update Mic Toggle Button to Stop
      micToggleBtn.innerHTML = 'Stop Recording ⏹';
      micToggleBtn.className = 'flex items-center gap-1.5 rounded-lg bg-red-50 text-red-600 px-3 py-1.5 font-medium border border-red-200 hover:bg-red-100 transition';

      // Reset Modal UI for Recording
      tabRaw.classList.add('active');
      tabImproved.classList.remove('active');
      tabImproved.style.opacity = '0.5';
      tabImproved.style.pointerEvents = 'none'; // Cannot switch to AI while recording

      previewText.readOnly = true;
      if (!resume) previewText.value = '';

      // UI POLISH: Hide Text box during record, show spectrum animation
      previewText.classList.add('hidden');
      if (document.getElementById('sttSpectrumEffect')) {
        document.getElementById('sttSpectrumEffect').classList.remove('hidden');
        document.getElementById('sttSpectrumEffect').classList.add('flex');
      }

      recordingSeconds = 0;
      modalStatus.classList.remove('hidden');
      modalStatus.classList.add('flex');
      modalStatus.innerHTML = '<span class="relative flex h-2.5 w-2.5"><span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-red-400 opacity-75"></span><span class="relative inline-flex rounded-full h-2.5 w-2.5 bg-red-500"></span></span> <span id="sttTimer">🎙️ Recording... 00:00</span>';

      recordingTimer = setInterval(() => {
        recordingSeconds++;
        var st = document.getElementById('sttTimer');
        if (st) st.textContent = '🎙️ Recording... ' + formatTime(recordingSeconds);
      }, 1000);

      btnAppend.disabled = true; btnAppend.style.opacity = '0.5';
      btnReplace.disabled = true; btnReplace.style.opacity = '0.5';

      sttBtn.classList.add('recording');
      updateCounts();

      // Open Modal Immediately (User requested this feature)
      openModal(previewModal);

      try {
        const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
        mediaRecorder = new MediaRecorder(stream);
        audioChunks = [];

        mediaRecorder.ondataavailable = function (e) {
          if (e.data.size > 0) audioChunks.push(e.data);
        };

        mediaRecorder.onstop = async function () {
          logDebug("MediaRecorder onstop triggered, sending audio to Groq API...");
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
              logDebug("Groq STT SUCCESS.");
              // Append perfectly transcribed text
              rawTranscript += (rawTranscript ? ' ' : '') + data.text;
              previewText.value = rawTranscript;
            } else {
              logDebug("Groq STT Error: " + data.error);
              previewText.value = rawTranscript + "\n\n⚠️ STT Error:\n" + data.error + "\n\n(Tip: Did you forget to add your Groq API key in config.php?)";
            }
          } catch (err) {
            logDebug("STT Fetch Network Error: " + err);
            previewText.value = rawTranscript + "\n\n⚠️ Network Error trying to reach the Speech API server.";
          }

          finalizeStop();
        };

        logDebug("Starting MediaRecorder.");
        mediaRecorder.start();
      } catch (err) {
        logDebug("getUserMedia Error (Mic Blocked/Not found): " + err);
        clearInterval(recordingTimer);
        modalStatus.textContent = '🚫 Mic blocked or none found — allow access in browser';
        modalStatus.classList.replace('text-red-600', 'text-amber-600');
        finalizeStop();
      }
    }

    function stopRecording(reason) {
      logDebug("stopRecording() triggered. Reason: " + (reason || 'manual'));
      if (mediaRecorder && mediaRecorder.state !== 'inactive') {
        mediaRecorder.stop();
        mediaRecorder.stream.getTracks().forEach(t => t.stop());
      } else {
        finalizeStop();
      }
    }

    function finalizeStop() {
      isRecording = false;
      sttBtn.classList.remove('recording');
      if (recordingTimer) clearInterval(recordingTimer);

      micToggleBtn.innerHTML = '▶ Resume Recording';
      micToggleBtn.className = 'flex items-center gap-1.5 rounded-lg bg-emerald-50 text-emerald-700 px-3 py-1.5 font-medium border border-emerald-200 hover:bg-emerald-100 transition';

      previewText.readOnly = false;
      previewText.classList.remove('hidden');
      if (document.getElementById('sttSpectrumEffect')) {
        document.getElementById('sttSpectrumEffect').classList.add('hidden');
        document.getElementById('sttSpectrumEffect').classList.remove('flex');
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

    micToggleBtn.addEventListener('click', function () {
      if (isRecording) {
        stopRecording('manual');
      } else {
        startRecording(true); // Resume recording without clearing text
      }
    });

    sttBtn.addEventListener('click', function (e) {
      e.preventDefault();
      logDebug("Main Mic Button Clicked! (Currently recording: " + isRecording + ")");
      if (isRecording) {
        stopRecording('main-button');
      } else {
        startRecording();
      }
    });

    // ── EXPAND VIEW LOGIC ──
    var isExpanded = false;
    var sttExpandToggle = document.getElementById('sttExpandToggle');
    var sttPreviewPanel = document.querySelector('.stt-preview-panel');
    var sttPreviewText = document.getElementById('sttPreviewText');

    if (sttExpandToggle && sttPreviewPanel && sttPreviewText) {
      sttExpandToggle.addEventListener('click', function () {
        isExpanded = !isExpanded;
        if (isExpanded) {
          sttPreviewPanel.style.width = '800px';
          sttPreviewPanel.style.maxWidth = '95vw';
          sttPreviewText.style.height = 'calc(80vh - 240px)';
          sttSpectrumEffect.style.height = 'calc(80vh - 240px)'; // Make spectrum big too
          this.innerHTML = '⤡ Collapse View';
        } else {
          sttPreviewPanel.style.width = '';
          sttPreviewPanel.style.maxWidth = '';
          sttPreviewText.style.height = '';
          sttSpectrumEffect.style.height = '150px';
          this.innerHTML = '⤢ Expand View';
        }
      });
    }

    // ── MAIN TEXTAREA TOOLS LOGIC ──
    const mainDesc = document.getElementById('description');
    const mainExpandBtn = document.getElementById('mainExpandBtn');
    const mainAiBtn = document.getElementById('mainAiImproveBtn');
    const mainUndoBtn = document.getElementById('mainUndoBtn');
    const mainAiStatus = document.getElementById('mainAiStatus');
    const mainModalPanel = document.querySelector('#eventModal .modal-panel');

    let mainIsExpanded = false;
    let originalMainDesc = '';

    if (mainUndoBtn && mainDesc) {
      mainUndoBtn.addEventListener('click', () => {
        if (originalMainDesc !== '') {
          mainDesc.value = originalMainDesc;
          if (mainAiStatus) {
            mainAiStatus.innerHTML = '↶ Reverted to original text.';
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
          mainDesc.style.height = 'calc(65vh - 180px)'; // Safe calculated height
          mainExpandBtn.innerHTML = '⤡ Collapse Box';
        } else {
          if (mainModalPanel) {
            mainModalPanel.style.width = '';
            mainModalPanel.style.maxWidth = '';
          }
          mainDesc.style.height = '';
          mainExpandBtn.innerHTML = '⤢ Expand Box';
        }
      });
    }

    if (mainAiBtn && mainDesc && mainAiStatus) {
      mainAiBtn.addEventListener('click', async () => {
        const raw = mainDesc.value.trim();
        if (!raw) {
          alert("Please type a description first before AI can improve it!");
          return;
        }

        // Save original before overwriting
        originalMainDesc = raw;

        // UI Loading state
        mainAiBtn.disabled = true;
        mainAiBtn.style.opacity = '0.5';
        mainAiStatus.classList.remove('hidden');
        mainAiStatus.innerHTML = '⏳ AI is rewriting your text...';

        try {
          const res = await fetch('api/ai_improve.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ raw_text: raw, csrf_token: window.CSRF_TOKEN || "" })
          });
          const json = await res.json();
          if (json.ok) {
            mainDesc.value = json.improved_text;
            mainAiStatus.innerHTML = '✅ Professionally Improved!';
            setTimeout(() => mainAiStatus.classList.add('hidden'), 4000);
            if (mainUndoBtn) mainUndoBtn.classList.remove('hidden');
          } else {
            mainAiStatus.innerHTML = '❌ ' + (json.error || 'AI formatting unavailable.');
          }
        } catch (err) {
          mainAiStatus.innerHTML = '❌ Network error.';
        }

        mainAiBtn.disabled = false;
        mainAiBtn.style.opacity = '1';
      });
    }

    // ── Intersection Observer for Event Cards ──
    const observerOptions = {
      root: document.getElementById('eventScrollContainer'),
      threshold: 0,
      rootMargin: '100px'
    };

    window.observer = new IntersectionObserver((entries) => {
      entries.forEach(entry => {
        if (entry.isIntersecting) {
          entry.target.classList.add('in-view');
        } else if (entry.boundingClientRect.top > entry.rootBounds.bottom) {
          entry.target.classList.remove('in-view');
        }
      });
    }, observerOptions);

    function reobserveCards() {
      document.querySelectorAll('.event-card-animated').forEach(card => {
        window.observer.observe(card);
      });
    }
    reobserveCards();

    // ── Edge Gradient Sync ──
    const scrollContainer = document.getElementById('eventScrollContainer');
    const topGrad = document.getElementById('topEventGrad');
    const bottomGrad = document.getElementById('bottomEventGrad');

    if (scrollContainer && topGrad && bottomGrad) {
      const syncGradients = () => {
        const { scrollTop, scrollHeight, clientHeight } = scrollContainer;

        // Top gradient opacity
        const tOpacity = Math.min(scrollTop / 50, 1);
        topGrad.style.opacity = tOpacity;

        // Bottom gradient opacity
        const bottomDistance = scrollHeight - (scrollTop + clientHeight);
        const bOpacity = scrollHeight <= clientHeight ? 0 : Math.min(bottomDistance / 50, 1);
        bottomGrad.style.opacity = bOpacity;
      };

      scrollContainer.addEventListener('scroll', syncGradients);
      setTimeout(syncGradients, 100);
      window.syncEventListGradients = syncGradients;
    }

    refreshEventVisibility();

    logDebug("STT Script fully loaded & event listeners attached.");
  })();
  window.refreshEventVisibility = refreshEventVisibility;
</script>

<script>
(function () {
  const liveStageStyles = {
    requirements_requested: { bg: 'bg-orange-50', text: 'text-orange-700', border: 'border-orange-200' },
    under_review: { bg: 'bg-violet-50', text: 'text-violet-700', border: 'border-violet-200' },
    approved: { bg: 'bg-emerald-50', text: 'text-emerald-700', border: 'border-emerald-200' },
    pending_requirements: { bg: 'bg-amber-50', text: 'text-amber-700', border: 'border-amber-200' },
  };
  let manageEventsLiveInFlight = false;
  let manageEventsLiveReloadScheduled = false;
  let manageEventsLiveIntervalId = null;
  let manageEventsDeferredReloadId = null;
  const manageEventsPageLoadedAt = Date.now();
  const MANAGE_EVENTS_RELOAD_GRACE_MS = 2500;
  const MANAGE_EVENTS_RELOAD_DEBOUNCE_MS = 8000;

  function isManageEventsBusy() {
    return window.pulseManageEventsBusy === true;
  }

  function getManageEventsDomIds() {
    return Array.from(document.querySelectorAll('.event-card[data-id]'))
      .map((card) => String(card.dataset.id || '').trim())
      .filter(Boolean);
  }

  function scheduleManageEventsListReload() {
    if (isManageEventsBusy()) return;
    if (window.pulseManageEventsSubmitFailedAt && Date.now() - window.pulseManageEventsSubmitFailedAt < 30000) {
      return;
    }
    if (manageEventsLiveReloadScheduled) return;

    const elapsed = Date.now() - manageEventsPageLoadedAt;
    if (elapsed < MANAGE_EVENTS_RELOAD_GRACE_MS) {
      if (!manageEventsDeferredReloadId) {
        manageEventsDeferredReloadId = window.setTimeout(() => {
          manageEventsDeferredReloadId = null;
          scheduleManageEventsListReload();
        }, MANAGE_EVENTS_RELOAD_GRACE_MS - elapsed + 50);
      }
      return;
    }

    try {
      const lastReload = Number(sessionStorage.getItem('pulseManageEventsReloadAt') || 0);
      if (Date.now() - lastReload < MANAGE_EVENTS_RELOAD_DEBOUNCE_MS) {
        return;
      }
    } catch (e) {
      // Ignore storage errors.
    }

    manageEventsLiveReloadScheduled = true;
    window.setTimeout(() => {
      if (isManageEventsBusy()) {
        manageEventsLiveReloadScheduled = false;
        return;
      }
      try {
        sessionStorage.setItem('pulseManageEventsReloadAt', String(Date.now()));
      } catch (e) {
        // Ignore storage errors.
      }
      window.location.reload();
    }, 400);
  }

  function syncManageEventsListFromLive(data) {
    if (!data || isManageEventsBusy()) return false;

    const container = document.getElementById('eventScrollContainer');
    const nextHash = data.list_hash || '';
    const apiIds = Array.isArray(data.event_ids)
      ? data.event_ids.map((id) => String(id || '').trim()).filter(Boolean)
      : [];
    const domIds = new Set(getManageEventsDomIds());
    const missingInDom = apiIds.some((id) => !domIds.has(id));

    if (missingInDom) {
      scheduleManageEventsListReload();
      return true;
    }

    if (nextHash && container) {
      container.dataset.liveListHash = nextHash;
    }

    return false;
  }

  function escapeLiveHtml(value) {
    return String(value || '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function updatePendingTabBadge(count) {
    const badge = document.getElementById('pendingProposalsTabBadge');
    if (!badge) return;
    const safeCount = Number.isFinite(count) && count > 0 ? count : 0;
    badge.classList.toggle('hidden', safeCount <= 0);
    if (safeCount > 0) badge.textContent = String(safeCount);
  }

  function updateApprovalTabBadge(count) {
    const badge = document.getElementById('teacherApprovalTabBadge');
    if (!badge) return;
    const safeCount = Number.isFinite(count) && count > 0 ? count : 0;
    badge.classList.toggle('hidden', safeCount <= 0);
    if (safeCount > 0) badge.textContent = String(safeCount);
  }

  function formatLiveStatusLabel(status, isRejected) {
    if (isRejected) return 'Rejected';
    const normalized = String(status || '').toLowerCase();
    if (!normalized) return 'Unknown';
    return normalized.charAt(0).toUpperCase() + normalized.slice(1);
  }

  function parseDisplayedUploadTotal(card) {
    const countEl = card.querySelector('.proposal-upload-count');
    if (!countEl) return null;
    const match = String(countEl.textContent || '').match(/\d+\/(\d+)/);
    if (!match) return null;
    const total = Number.parseInt(match[1], 10);
    return Number.isFinite(total) ? total : null;
  }

  function hasLiveEventChanges(card, eventData) {
    if ((card.dataset.proposalRevision || '') !== (eventData.revision || '')) return true;
    if ((card.dataset.proposalStage || '') !== (eventData.proposal_stage || '')) return true;
    if ((card.dataset.status || '') !== (eventData.status || '')) return true;
    if ((card.dataset.liveUpdatedAt || '') !== (eventData.updated_at || '')) return true;

    const nextRejected = eventData.is_rejected ? '1' : '0';
    if ((card.dataset.isRejected || '') !== nextRejected) return true;

    const displayedTotal = parseDisplayedUploadTotal(card);
    const nextTotal = Number((eventData.summary || {}).total || 0);
    if (displayedTotal !== null && displayedTotal !== nextTotal) return true;

    return false;
  }

  function upsertRejectRemark(card, rejectReason) {
    const reason = String(rejectReason || '').trim();
    if (!reason) return;

    let remarkEl = card.querySelector('.proposal-reject-remark');
    if (!remarkEl) {
      remarkEl = document.createElement('div');
      remarkEl.className = 'proposal-reject-remark mb-3 p-3 rounded-lg border border-rose-200 bg-rose-50/70 text-rose-900 text-xs shadow-sm';
      remarkEl.innerHTML = '<div class="flex items-center gap-2 font-bold mb-1.5 text-rose-700">'
        + '<svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">'
        + '<path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008h-.008v-.008z" />'
        + '</svg>Admin Remark:</div>'
        + '<p class="proposal-reject-remark-text"></p>';

      const titleRow = card.querySelector('.flex.items-center.gap-2.mb-1');
      if (titleRow && titleRow.parentElement) {
        titleRow.parentElement.insertBefore(remarkEl, titleRow.nextElementSibling);
      } else {
        card.querySelector('.min-w-0.flex-1')?.prepend(remarkEl);
      }
    }

    const textEl = remarkEl.querySelector('.proposal-reject-remark-text');
    if (textEl) textEl.textContent = reason;
    remarkEl.classList.remove('hidden');
  }

  function applyLiveEventUpdate(card, eventData) {
    if (!card || !eventData || !hasLiveEventChanges(card, eventData)) return false;

    const isRejected = !!eventData.is_rejected;

    card.dataset.proposalStage = eventData.proposal_stage || '';
    card.dataset.proposalRevision = eventData.revision || '';
    card.dataset.liveUpdatedAt = eventData.updated_at || '';
    card.dataset.isRejected = isRejected ? '1' : '0';
    if (eventData.status) {
      card.dataset.status = eventData.status;
    }
    if (typeof eventData.description === 'string') {
      card.dataset.description = eventData.description;
    }

    const statusBadge = card.querySelector('.event-status-badge');
    if (statusBadge && eventData.status) {
      statusBadge.textContent = formatLiveStatusLabel(eventData.status, isRejected);
    }

    if (isRejected && eventData.reject_reason) {
      upsertRejectRemark(card, eventData.reject_reason);
    }

    const stageStyle = liveStageStyles[eventData.proposal_stage] || liveStageStyles.pending_requirements;
    let stageBadge = card.querySelector('.proposal-stage-badge');
    const showStage = ['pending', 'approved'].includes(String(eventData.status || '').toLowerCase());
    if (showStage) {
      if (!stageBadge) {
        stageBadge = document.createElement('span');
        stageBadge.className = 'proposal-stage-badge text-[10px] font-bold rounded-full border px-2 py-0.5 flex-shrink-0';
        const statusBadgeEl = card.querySelector('.event-status-badge');
        statusBadgeEl?.insertAdjacentElement('afterend', stageBadge);
      }
      stageBadge.textContent = eventData.stage_label || 'Needs requirements';
      stageBadge.className = 'proposal-stage-badge text-[10px] font-bold rounded-full border px-2 py-0.5 flex-shrink-0 '
        + stageStyle.bg + ' ' + stageStyle.text + ' ' + stageStyle.border;
      stageBadge.classList.remove('hidden');
    } else if (stageBadge) {
      stageBadge.classList.add('hidden');
    }

    const progressSection = card.querySelector('.proposal-progress-section');
    const isPending = String(eventData.status || '').toLowerCase() === 'pending';
    if (isPending && !progressSection) {
      const host = card.querySelector('.min-w-0.flex-1');
      if (host) {
        const section = document.createElement('div');
        section.className = 'proposal-progress-section mt-3 rounded-xl border border-zinc-200 bg-white/80 px-3 py-2 shadow-sm';
        section.innerHTML = '<div class="flex flex-wrap items-center justify-between gap-2 text-[11px]">'
          + '<span class="font-semibold text-zinc-700">Proposal requirements</span>'
          + '<span class="proposal-upload-count text-zinc-500">0/0 uploaded</span>'
          + '</div>'
          + '<div class="mt-2 h-2 overflow-hidden rounded-full bg-zinc-200 proposal-progress-track">'
          + '<div class="proposal-progress-fill h-full rounded-full bg-gradient-to-r from-orange-500 to-emerald-500 transition-all" style="width:0%"></div>'
          + '</div>'
          + '<div class="proposal-req-tags mt-2 flex flex-wrap gap-1.5"></div>'
          + '<p class="proposal-waiting-note mt-2 text-[11px] font-medium text-zinc-500 hidden"></p>';
        host.appendChild(section);
      }
    }

    const progressEl = card.querySelector('.proposal-progress-section');
    if (progressEl) {
      progressEl.classList.toggle('hidden', !isPending);

      const summary = eventData.summary || { total: 0, submitted: 0, percent: 0 };
      const countEl = progressEl.querySelector('.proposal-upload-count');
      const fillEl = progressEl.querySelector('.proposal-progress-fill');
      const tagsEl = progressEl.querySelector('.proposal-req-tags');
      const noteEl = progressEl.querySelector('.proposal-waiting-note');
      const emptyLabel = eventData.requirements_empty_label
        || 'Admin has not requested the required documents yet.';

      if (countEl) {
        countEl.textContent = `${summary.submitted || 0}/${summary.total || 0} uploaded`;
      }
      if (fillEl) {
        fillEl.style.width = `${Math.max(0, Math.min(100, Number(summary.percent || 0)))}%`;
      }
      if (tagsEl) {
        const requirements = Array.isArray(eventData.requirements) ? eventData.requirements : [];
        tagsEl.innerHTML = requirements.length
          ? requirements.map((requirement) => {
              const uploaded = !!requirement.uploaded;
              const cls = uploaded
                ? 'border-emerald-200 bg-emerald-50 text-emerald-700'
                : 'border-zinc-200 bg-zinc-50 text-zinc-500';
              const label = uploaded ? 'Uploaded' : 'Pending';
              const code = requirement.code || requirement.label || 'DOC';
              return `<span class="inline-flex items-center gap-1 rounded-full border px-2 py-0.5 text-[10px] font-bold ${cls}">${label} ${escapeLiveHtml(code)}</span>`;
            }).join('')
          : `<span class="text-[11px] text-zinc-500">${escapeLiveHtml(emptyLabel)}</span>`;
      }
      if (noteEl) {
        const teacherNote = eventData.teacher_progress_note || '';
        if (eventData.admin_waiting_on_final_submit) {
          noteEl.textContent = `Draft progress is shown here (${summary.submitted || 0}/${summary.total || 0} uploaded). Full file review opens after the teacher submits for review.`;
          noteEl.classList.remove('hidden');
        } else if (teacherNote) {
          noteEl.textContent = teacherNote;
          noteEl.classList.remove('hidden');
        } else {
          noteEl.textContent = '';
          noteEl.classList.add('hidden');
        }
      }
    }

    const reqBtn = card.querySelector('.btnRequirements');
    const ready = !!eventData.approve_ready;
    if (reqBtn) {
      reqBtn.textContent = eventData.requirements_button || 'Send Req';
      reqBtn.dataset.stage = eventData.proposal_stage || '';
      reqBtn.dataset.approveReady = ready ? '1' : '0';
      reqBtn.dataset.requirements = JSON.stringify(eventData.requirements_json || []);
      reqBtn.dataset.submissions = JSON.stringify(eventData.submissions_json || []);
      reqBtn.dataset.summary = JSON.stringify(eventData.summary || {});
    }

    // Keep Review Docs modal approve/reject in sync while open for this event.
    const openEventId = proposalRequirementsEventId?.value || '';
    if (
      openEventId
      && String(openEventId) === String(eventData.id || '')
      && proposalRequirementsModal?.classList.contains('active')
    ) {
      proposalRequirementState.stage = eventData.proposal_stage || proposalRequirementState.stage;
      proposalRequirementState.requirements = eventData.requirements_json || proposalRequirementState.requirements;
      proposalRequirementState.submissions = eventData.submissions_json || proposalRequirementState.submissions;
      proposalRequirementState.summary = eventData.summary || proposalRequirementState.summary;
      const approveReadyInput = document.getElementById('proposalRequirementsApproveReady');
      if (approveReadyInput) approveReadyInput.value = ready ? '1' : '0';
      renderProposalProgress();
      renderProposalUploads();
      syncProposalReviewActions();
    }

    // Legacy card Approve buttons (if any remain elsewhere).
    const approveBtn = card.querySelector('.btnApprove');
    if (approveBtn && eventData.show_approve === false) {
      approveBtn.classList.add('hidden');
    } else if (approveBtn) {
      approveBtn.classList.remove('hidden');
      approveBtn.disabled = !ready;
      approveBtn.dataset.approveReady = ready ? '1' : '0';
      approveBtn.classList.toggle('opacity-50', !ready);
      approveBtn.classList.toggle('cursor-not-allowed', !ready);
      approveBtn.title = ready
        ? ''
        : 'Waiting for the teacher to submit documents for review.';
    }

    card.classList.add('event-card-live-update');
    window.setTimeout(() => card.classList.remove('event-card-live-update'), 1800);
    return true;
  }

  async function refreshManageEventsLive(forceFresh) {
    if (manageEventsLiveInFlight || isManageEventsBusy()) return;
    manageEventsLiveInFlight = true;
    const controller = new AbortController();
    const timeoutId = window.setTimeout(() => controller.abort(), 12000);
    try {
      // Periodic polls use API TTL cache; visibility/resume can force fresh.
      const freshParam = forceFresh ? '&fresh=1' : '';
      const res = await fetch('/api/manage_events_live.php?_=' + Date.now() + freshParam, {
        cache: 'no-store',
        credentials: 'same-origin',
        signal: controller.signal,
        headers: { Accept: 'application/json' },
      });
      if (!res.ok) return;
      const data = await res.json();
      if (!data || data.ok !== true) return;

      updatePendingTabBadge(Number(data.pending_count || 0));
      updateApprovalTabBadge(Number(data.approval_count || 0));

      if (typeof window.updateManageEventsBadgeFromSignals === 'function') {
        window.updateManageEventsBadgeFromSignals(data.signals || []);
      }

      const events = Array.isArray(data.events) ? data.events : [];
      let changed = false;
      events.forEach((eventData) => {
        const card = Array.from(document.querySelectorAll('.event-card[data-id]'))
          .find((node) => (node.dataset.id || '') === (eventData.id || ''));
        if (card && applyLiveEventUpdate(card, eventData)) {
          changed = true;
        }
      });

      if (syncManageEventsListFromLive(data)) {
        return;
      }

      if (changed && typeof window.refreshEventVisibility === 'function') {
        window.refreshEventVisibility();
      }
    } catch (e) {
      // Keep current UI on transient network failures.
    } finally {
      window.clearTimeout(timeoutId);
      manageEventsLiveInFlight = false;
    }
  }

  window.refreshManageEventsLive = refreshManageEventsLive;
  window.syncManageEventsListFromLive = syncManageEventsListFromLive;

  function scheduleManageEventsLivePolling() {
    if (manageEventsLiveIntervalId) {
      window.clearInterval(manageEventsLiveIntervalId);
    }
    const intervalMs = document.visibilityState === 'visible' ? 90000 : 180000;
    manageEventsLiveIntervalId = window.setInterval(refreshManageEventsLive, intervalMs);
  }

  window.setTimeout(function () { refreshManageEventsLive(true); }, 800);
  scheduleManageEventsLivePolling();
  let manageEventsVisibilityRefreshId = null;
  document.addEventListener('visibilitychange', () => {
    scheduleManageEventsLivePolling();
    if (document.visibilityState === 'visible') {
      if (manageEventsVisibilityRefreshId) {
        window.clearTimeout(manageEventsVisibilityRefreshId);
      }
      manageEventsVisibilityRefreshId = window.setTimeout(() => {
        manageEventsVisibilityRefreshId = null;
        refreshManageEventsLive(true);
        if (typeof window.PulseFlushPendingNotifications === 'function') {
          window.PulseFlushPendingNotifications();
        }
      }, 350);
    }
  });
})();
</script>

<?php render_footer(); ?>
