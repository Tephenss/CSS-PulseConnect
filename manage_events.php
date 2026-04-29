<?php
declare(strict_types=1);

session_start();

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/supabase.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/event_sessions.php';
require_once __DIR__ . '/includes/proposal_requirements.php';

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
    return $base . '&status=neq.archived&order=created_at.desc';
  }

  // Teacher sees their own events OR any published events.
  return $base . '&or=(created_by.eq.' . $userId . ',status.eq.published)&order=created_at.desc';
}

$eventSelectVariants = [
  'id,title,description,location,start_at,end_at,status,created_by,approved_by,created_at,updated_at,event_type,event_for,grace_time,event_span,event_mode,event_structure,proposal_stage,requirements_requested_at,requirements_submitted_at,users:created_by(first_name,last_name,suffix)',
  'id,title,description,location,start_at,end_at,status,created_by,approved_by,created_at,updated_at,event_type,event_for,grace_time,event_span,event_structure,proposal_stage,requirements_requested_at,requirements_submitted_at,users:created_by(first_name,last_name,suffix)',
  'id,title,description,location,start_at,end_at,status,created_by,approved_by,created_at,updated_at,event_type,event_for,grace_time,event_span,event_mode,proposal_stage,requirements_requested_at,requirements_submitted_at,users:created_by(first_name,last_name,suffix)',
  'id,title,description,location,start_at,end_at,status,created_by,approved_by,created_at,updated_at,event_type,event_for,grace_time,event_span,proposal_stage,requirements_requested_at,requirements_submitted_at,users:created_by(first_name,last_name,suffix)',
  'id,title,description,location,start_at,end_at,status,created_by,approved_by,created_at,updated_at,event_type,event_for,grace_time,event_span,event_mode,event_structure,users:created_by(first_name,last_name,suffix)',
  'id,title,description,location,start_at,end_at,status,created_by,approved_by,created_at,updated_at,event_type,event_for,grace_time,event_span,event_structure,users:created_by(first_name,last_name,suffix)',
  'id,title,description,location,start_at,end_at,status,created_by,approved_by,created_at,updated_at,event_type,event_for,grace_time,event_span,event_mode,users:created_by(first_name,last_name,suffix)',
  'id,title,description,location,start_at,end_at,status,created_by,approved_by,created_at,updated_at,event_type,event_for,grace_time,event_span,users:created_by(first_name,last_name,suffix)',
];

$headers = [
  'Accept: application/json',
  'apikey: ' . SUPABASE_KEY,
  'Authorization: Bearer ' . SUPABASE_KEY,
];

// Auto-finish events that have already ended.
try {
  $nowUtc = gmdate('c');
  $archiveUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/events'
    . '?status=eq.published'
    . '&end_at=lt.' . rawurlencode($nowUtc);
  $archiveHeaders = [
    'Accept: application/json',
    'Content-Type: application/json',
    'Prefer: return=minimal',
    'apikey: ' . SUPABASE_KEY,
    'Authorization: Bearer ' . SUPABASE_KEY,
  ];
  $archivePayload = json_encode(['status' => 'finished'], JSON_UNESCAPED_SLASHES);
  if (is_string($archivePayload)) {
    supabase_request('PATCH', $archiveUrl, $archiveHeaders, $archivePayload);
  }
} catch (Throwable $e) {
  // Best-effort only; keep page rendering even if auto-archive fails.
}

$events = [];
foreach ($eventSelectVariants as $selectColumns) {
  $eventsUrl = build_manage_events_url($selectColumns, $role, $userId);
  $res = supabase_request('GET', $eventsUrl, $headers);

  if ($res['ok']) {
    $decoded = json_decode((string) $res['body'], true);
    $events = is_array($decoded) ? $decoded : [];
    break;
  }

  if (!events_missing_column_error($res)) {
    break;
  }
}
if (!empty($events)) {
  $events = attach_event_sessions_to_events($events, $headers);
}

$proposalRequirementMap = [];
$proposalSubmissionMap = [];
$proposalVisibleSubmissionMap = [];
$proposalSummaryMap = [];
if (!empty($events)) {
  $eventIds = array_values(array_filter(array_map(
    static fn(array $event): string => trim((string) ($event['id'] ?? '')),
    $events
  )));

  $proposalHeaders = proposal_requirement_headers();
  $proposalRequirementMap = fetch_proposal_requirements_map($eventIds, $proposalHeaders);
  $proposalSubmissionMap = fetch_proposal_submissions_map($eventIds, $proposalHeaders);
  $proposalVisibleSubmissionMap = fetch_proposal_submissions_map($eventIds, $proposalHeaders, true);

  foreach ($eventIds as $eventId) {
    $proposalSummaryMap[$eventId] = build_proposal_requirement_summary(
      $proposalRequirementMap[$eventId] ?? [],
      $proposalSubmissionMap[$eventId] ?? []
    );
  }
}

$teacherAccounts = [];
if ($role === 'admin') {
  $teachersUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/users'
    . '?select=id,first_name,middle_name,last_name,suffix,email'
    . '&role=eq.teacher'
    . '&order=last_name.asc,first_name.asc';
  $teachersRes = supabase_request('GET', $teachersUrl, $headers);
  if ($teachersRes['ok']) {
    $teacherRows = json_decode((string) $teachersRes['body'], true);
    $teacherAccounts = is_array($teacherRows) ? $teacherRows : [];
  }
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
        </div>

        <!-- Step 1: Info -->
        <div id="step1" class="space-y-4">
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
            </div>
            <div>
              <label class="block text-xs text-zinc-600 mb-1.5 font-medium tracking-wide">Target Course</label>
              <select id="target_course" name="target_course"
                class="w-full rounded-xl bg-white border border-zinc-200 py-3 px-[38px] text-sm text-zinc-900 outline-none focus:ring-2 focus:ring-orange-500/30 focus:border-orange-400 transition appearance-none">
                <option value="ALL" selected>All Courses</option>
                <option value="BSIT">BSIT</option>
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
              <input id="grace_time" name="grace_time" type="number" min="0" value="15"
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
              <div class="text-[11px] font-bold uppercase tracking-wide text-zinc-600">Seminar 2</div>
              <div>
                <label class="block text-[11px] text-zinc-600 mb-1 font-medium">Title</label>
                <input id="seminar2_title" type="text"
                  class="w-full rounded-lg border border-zinc-200 px-3 py-2 text-sm text-zinc-900 outline-none focus:ring-2 focus:ring-orange-500/30 focus:border-orange-400"
                  placeholder="Seminar 2 title" />
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
        </div>

        <div id="formMsg" class="text-sm text-amber-800 min-h-0 !mt-0"></div>
      </form>
    </div>

    <!-- Footer -->
    <div class="px-5 sm:px-6 py-4 border-t border-zinc-200 flex items-center justify-between gap-3">
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
?>

<!-- ═══════  HEADER  ═══════ -->
<div class="mb-6 flex items-center justify-between flex-wrap gap-3">
  <div>
    <p class="text-zinc-600 text-sm">
      <?php if ($role === 'admin'): ?>Full control — create, edit, approve and publish events.<?php else: ?>Create
        events (pending). Admin approves & publishes.<?php endif; ?>
    </p>
  </div>
  <button id="btnCreateEvent"
    class="flex items-center gap-2 rounded-xl bg-gradient-to-r from-orange-600 to-red-600 text-white px-5 py-2.5 text-sm font-medium hover:from-orange-500 hover:to-red-500 transition-all shadow-lg shadow-orange-600/25 hover:shadow-orange-500/40 hover:scale-[1.02] active:scale-[0.98]">
    <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
      <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
    </svg>
    Create Event
  </button>
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

  <?php if ($role === 'admin'): ?>
    <!-- Tabs Navigation for Admin (Matches PDF Page 33) -->
    <div class="flex border-b border-zinc-200 mb-5 gap-6 mt-3">
      <button id="tabAll"
        class="pb-3 border-b-[2.5px] border-orange-500 font-bold text-orange-600 text-[13px] transition-colors w-24">All
        Events</button>
      <button id="tabPending"
        class="pb-3 border-b-[2.5px] border-transparent font-semibold text-zinc-500 hover:text-zinc-800 text-[13px] transition-colors flex items-center gap-1.5 px-2">
        Pending Proposals
        <?php $pendingCount = count(array_filter($events, fn($e) => ($e['status'] ?? '') === 'pending')); ?>
        <?php if ($pendingCount > 0): ?>
          <span
            class="bg-red-100 border border-red-200 text-red-700 text-[10px] font-black px-2 py-0.5 rounded-full shadow-sm"><?= $pendingCount ?></span>
        <?php endif; ?>
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
          $types = array_unique(array_filter(array_map(fn($e) => (string) ($e['event_type'] ?? ''), $events)));
          sort($types);
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

    <div id="eventScrollContainer" class="event-scroll-container">
      <div class="space-y-3 pb-24">
        <?php foreach ($events as $e): ?>
          <?php
          $status = (string) ($e['status'] ?? '');
          $description = (string) ($e['description'] ?? '');
          $isRejected = strpos($description, '[REJECT_REASON:') !== false;

          // For teachers: If archived but NOT rejected, skip (it's a manual archive)
          if ($role === 'teacher' && $status === 'archived' && !$isRejected)
            continue;
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
          $proposalSubmissionsVisible = $proposalVisibleSubmissions;
          $proposalSummaryVisible = $proposalSummary;
          $adminWaitingOnFinalSubmit = $role === 'admin'
            && $status === 'pending'
            && $proposalRequirements !== []
            && $proposalStage !== 'under_review'
            && $proposalStage !== 'approved';
          $proposalStageConfig = match ($proposalStage) {
            'requirements_requested' => ['label' => 'Waiting on teacher', 'bg' => 'bg-orange-50', 'text' => 'text-orange-700', 'border' => 'border-orange-200'],
            'under_review' => ['label' => 'Under review', 'bg' => 'bg-violet-50', 'text' => 'text-violet-700', 'border' => 'border-violet-200'],
            'approved' => ['label' => 'Ready for publish', 'bg' => 'bg-emerald-50', 'text' => 'text-emerald-700', 'border' => 'border-emerald-200'],
            default => ['label' => 'Needs requirements', 'bg' => 'bg-amber-50', 'text' => 'text-amber-700', 'border' => 'border-amber-200'],
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
            data-teacher="<?= htmlspecialchars($tName ?? '') ?>" data-status="<?= htmlspecialchars($status) ?>">
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
                        class="text-[10px] font-medium rounded-full border px-2 py-0.5 <?= $statusConfig['bg'] ?> <?= $statusConfig['text'] ?> <?= $statusConfig['border'] ?> flex-shrink-0">
                        <?= ($status === 'archived' && $isRejected) ? 'Rejected' : ucfirst(htmlspecialchars($status)) ?>
                      </span>
                      <?php if (in_array($status, ['pending', 'approved'], true)): ?>
                        <span
                          class="text-[10px] font-bold rounded-full border px-2 py-0.5 <?= $proposalStageConfig['bg'] ?> <?= $proposalStageConfig['text'] ?> <?= $proposalStageConfig['border'] ?> flex-shrink-0">
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
                      <div class="mb-3 p-3 rounded-lg border border-rose-200 bg-rose-50/70 text-rose-900 text-xs shadow-sm">
                        <div class="flex items-center gap-2 font-bold mb-1.5 text-rose-700">
                          <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round"
                              d="M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008h-.008v-.008z" />
                          </svg>
                          Admin Remark:
                        </div>
                        <?php
                        preg_match('/\[REJECT_REASON:\s*(.*?)\]/', $description, $m);
                        echo htmlspecialchars($m[1] ?? 'Proposal review required.');
                        ?>
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
                        Grace: <?= htmlspecialchars((string) ($e['grace_time'] ?? '15')) ?>m
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
                      <div class="mt-3 rounded-xl border border-zinc-200 bg-white/80 px-3 py-2 shadow-sm">
                        <div class="flex flex-wrap items-center justify-between gap-2 text-[11px]">
                          <span class="font-semibold text-zinc-700">
                            Proposal requirements
                          </span>
                          <span class="text-zinc-500">
                            <?= (int) ($proposalSummaryVisible['submitted'] ?? 0) ?>/<?= (int) ($proposalSummaryVisible['total'] ?? 0) ?> uploaded
                          </span>
                        </div>
                        <div class="mt-2 h-2 overflow-hidden rounded-full bg-zinc-200">
                          <div
                            class="h-full rounded-full bg-gradient-to-r from-orange-500 to-emerald-500 transition-all"
                            style="width: <?= max(0, min(100, (int) ($proposalSummaryVisible['percent'] ?? 0))) ?>%"></div>
                        </div>
                        <div class="mt-2 flex flex-wrap gap-1.5">
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
                            <span class="text-[11px] text-zinc-500">Admin has not requested the required documents yet.</span>
                          <?php endif; ?>
                        </div>
                        <?php if ($adminWaitingOnFinalSubmit): ?>
                          <p class="mt-2 text-[11px] font-medium text-zinc-500">
                            Draft progress is shown here. Full file review opens after the teacher submits for review.
                          </p>
                        <?php endif; ?>
                      </div>
                    <?php endif; ?>
                  </div>
                </div>
              </div>

              <!-- Actions -->
              <div class="flex gap-1.5 flex-wrap items-center lg:flex-shrink-0 pl-0 sm:pl-[52px] lg:pl-0">
                <?php if ($role === 'admin'): ?>
                  <?php if ($status === 'pending'): ?>
                    <button
                      class="btnReject rounded-lg border border-red-200 bg-red-50 px-4 py-1.5 text-[13px] text-red-700 hover:bg-red-100 transition font-bold"
                      data-id="<?= htmlspecialchars($eid) ?>"
                      data-title="<?= htmlspecialchars((string) ($e['title'] ?? '')) ?>">Reject</button>
                    <button
                      class="btnRequirements rounded-lg border border-orange-200 bg-orange-50 px-4 py-1.5 text-[13px] font-bold text-orange-700 hover:bg-orange-100 transition shadow-sm"
                      data-id="<?= htmlspecialchars($eid) ?>"
                      data-title="<?= htmlspecialchars((string) ($e['title'] ?? '')) ?>"
                      data-stage="<?= htmlspecialchars($proposalStage) ?>"
                      data-requirements="<?= htmlspecialchars((string) json_encode($proposalRequirements, JSON_UNESCAPED_SLASHES | JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES) ?>"
                      data-submissions="<?= htmlspecialchars((string) json_encode(array_values($proposalSubmissions), JSON_UNESCAPED_SLASHES | JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES) ?>"
                      data-summary="<?= htmlspecialchars((string) json_encode($proposalSummaryVisible, JSON_UNESCAPED_SLASHES | JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES) ?>">
                      <?= $proposalRequirements === []
                        ? 'Send Req'
                        : ($proposalStage === 'under_review' ? 'Review Docs' : 'Edit Req') ?>
                    </button>
                    <?php if ($proposalStage === 'under_review'): ?>
                      <button
                        class="btnApprove rounded-lg bg-emerald-600 text-white px-4 py-1.5 text-[13px] font-bold hover:bg-emerald-500 transition-colors border border-emerald-600 shadow-sm"
                        data-id="<?= htmlspecialchars($eid) ?>" data-status="approved">Approve</button>
                    <?php endif; ?>
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
                    data-title="<?= htmlspecialchars((string) ($e['title'] ?? '')) ?>"
                    data-location="<?= htmlspecialchars((string) ($e['location'] ?? '')) ?>"
                    data-description="<?= htmlspecialchars((string) ($e['description'] ?? '')) ?>"
                    data-start_at="<?= htmlspecialchars((string) ($e['start_at'] ?? '')) ?>"
                    data-end_at="<?= htmlspecialchars((string) ($e['end_at'] ?? '')) ?>"
                    data-event_mode="<?= htmlspecialchars(event_uses_sessions($e) ? 'seminar_based' : 'simple') ?>"
                    data-sessions="<?= htmlspecialchars((string) (json_encode($e['sessions'] ?? [], JSON_UNESCAPED_SLASHES | JSON_HEX_APOS | JSON_HEX_QUOT) ?: '[]'), ENT_QUOTES) ?>"
                    data-event_type="<?= htmlspecialchars((string) ($e['event_type'] ?? 'Event')) ?>"
                    data-event_for="<?= htmlspecialchars((string) ($e['event_for'] ?? 'All')) ?>"
                    data-grace_time="<?= htmlspecialchars((string) ($e['grace_time'] ?? '15')) ?>"
                    data-cover_image_url="<?= htmlspecialchars((string) ($e['cover_image_url'] ?? '')) ?>">View/Edit</button>
                <?php endif; ?>

              </div>

            </div>
          </div>
        <?php endforeach; ?>

        <?php if (count($events) === 0): ?>
          <div class="text-center py-16 text-zinc-600">
            <div
              class="w-16 h-16 rounded-2xl bg-zinc-100 border border-zinc-200 flex items-center justify-center mx-auto mb-4">
              <svg class="w-8 h-8 text-zinc-500" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round"
                  d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 11.25v7.5" />
              </svg>
            </div>
            <h3 class="text-zinc-800 font-medium mb-1">No events yet</h3>
            <p class="text-sm">Click <span class="text-orange-700 font-medium">"Create Event"</span> to get started.</p>
          </div>
        <?php endif; ?>
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
        <h3 id="proposalRequirementsTitle" class="mt-1 text-xl font-bold text-zinc-900">Request proposal documents</h3>
        <p class="mt-1 text-sm text-zinc-500">
          Select the forms the teacher must upload before this proposal can move into final review.
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

      <div class="rounded-2xl border border-zinc-200 bg-zinc-50/80 p-4">
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

      <div class="mt-5 rounded-2xl border border-zinc-200 bg-white p-4">
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

      <div class="mt-5 rounded-2xl border border-zinc-200 bg-white p-4">
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
    </div>

    <div class="flex items-center justify-between gap-3 border-t border-zinc-200 bg-zinc-50 px-5 py-4 sm:px-6">
      <button type="button" id="btnCancelProposalRequirements"
        class="rounded-xl border border-zinc-200 bg-white px-4 py-2.5 text-sm font-semibold text-zinc-700 transition hover:bg-zinc-100">
        Cancel
      </button>
      <button type="button" id="btnSaveProposalRequirements"
        class="rounded-xl bg-gradient-to-r from-orange-600 to-red-600 px-5 py-2.5 text-sm font-bold text-white shadow-lg shadow-orange-600/20 transition hover:from-orange-500 hover:to-red-500">
        Send Requirements
      </button>
    </div>
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
    summary: { total: 0, submitted: 0, percent: 0 }
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
      const fileUrl = String(submission?.file_url || submission?.file_path || '').trim();
      const uploadedAt = String(submission?.updated_at || submission?.uploaded_at || '').trim();
      const uploadedText = uploadedAt ? new Date(uploadedAt).toLocaleString() : '';

      const item = document.createElement('div');
      item.className = 'rounded-2xl border border-zinc-200 bg-zinc-50 px-4 py-3';
      item.innerHTML = `
        <div class="flex flex-wrap items-start justify-between gap-3">
          <div class="min-w-0">
            <div class="flex items-center gap-2">
              <span class="rounded-full border border-zinc-200 bg-white px-2 py-0.5 text-[10px] font-black text-zinc-700">${escapeHtml(code)}</span>
              <div class="text-sm font-semibold text-zinc-900">${escapeHtml(label)}</div>
            </div>
            <div class="mt-1 text-xs text-zinc-500">
              ${fileUrl ? `Uploaded${uploadedText ? ` • ${escapeHtml(uploadedText)}` : ''}` : 'Still waiting for teacher upload'}
            </div>
          </div>
          ${fileUrl
            ? `<a href="${escapeHtml(fileUrl)}" target="_blank" rel="noreferrer" class="rounded-xl border border-emerald-200 bg-emerald-50 px-3 py-2 text-xs font-bold text-emerald-700 transition hover:bg-emerald-100">Open file</a>`
            : `<span class="rounded-xl border border-zinc-200 bg-white px-3 py-2 text-xs font-bold text-zinc-500">Pending upload</span>`}
        </div>
      `;
      proposalRequirementsUploads.appendChild(item);
    });

    proposalRequirementsUploadSummary.textContent = `${proposalRequirementState.summary.submitted || 0}/${proposalRequirementState.summary.total || 0} uploaded`;
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

    if ((proposalRequirementState.requirements || []).length === 0) {
      btnSaveProposalRequirements.textContent = 'Send Requirements';
    } else if (stage === 'under_review') {
      btnSaveProposalRequirements.textContent = 'Update Requirements';
    } else {
      btnSaveProposalRequirements.textContent = 'Save Requirements';
    }
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

  function openProposalRequirementsModal(button) {
    if (!proposalRequirementsModal) return;
    proposalRequirementsEventId.value = button.dataset.id || '';
    proposalRequirementsTitle.textContent = `Proposal documents • ${button.dataset.title || 'Pending proposal'}`;
    proposalRequirementState = {
      stage: button.dataset.stage || 'pending_requirements',
      requirements: safeJsonParse(button.dataset.requirements, []),
      submissions: safeJsonParse(button.dataset.submissions, []),
      summary: safeJsonParse(button.dataset.summary, { total: 0, submitted: 0, percent: 0 })
    };
    populateProposalRequirementEditor(proposalRequirementState.requirements);
    renderProposalProgress();
    renderProposalUploads();
    proposalRequirementsModal.classList.add('active');
    document.body.style.overflow = 'hidden';
  }

  eventModal.addEventListener('click', (e) => { if (e.target === eventModal) closeModal(eventModal); });
  archiveModal.addEventListener('click', (e) => { if (e.target === archiveModal) closeModal(archiveModal); });
  publishTeacherModal?.addEventListener('click', (e) => { if (e.target === publishTeacherModal) closePublishTeacherAssignmentModal(); });
  proposalRequirementsModal?.addEventListener('click', (e) => { if (e.target === proposalRequirementsModal) closeProposalRequirementsModal(); });

  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') {
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
    } else {
      enforceSimpleEndDefaults();
    }

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

  const subtitles = ['Fill in the event info', 'Add a description', 'Set the schedule'];

  function setWizardStep(s) {
    document.getElementById('step1')?.classList.toggle('hidden', s !== 1);
    document.getElementById('step2')?.classList.toggle('hidden', s !== 2);
    document.getElementById('step3')?.classList.toggle('hidden', s !== 3);

    const btnBack = document.getElementById('btnBack');
    const btnNext = document.getElementById('btnNext');
    const btnSubmit = document.getElementById('btnSubmit');

    if (btnBack) btnBack.disabled = s === 1;
    btnNext?.classList.toggle('hidden', s === 3);
    btnSubmit?.classList.toggle('hidden', s !== 3);

    const subtitle = document.getElementById('modalSubtitle');
    if (subtitle) subtitle.textContent = subtitles[s - 1] || '';

    ['ws1', 'ws2', 'ws3'].forEach((id, i) => {
      const el = document.getElementById(id);
      if (!el) return;
      el.classList.remove('active', 'completed');
      if ((i + 1) === s) el.classList.add('active');
      if ((i + 1) < s) el.classList.add('completed');
    });
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
  });
  seminar1StartInput?.addEventListener('change', () => updateEndMin(seminar1StartInput, seminar1EndInput));
  seminar2StartInput?.addEventListener('change', () => updateEndMin(seminar2StartInput, seminar2EndInput));

  startAtInput?.addEventListener('input', () => {
    if ((eventModeInput?.value || 'simple') === 'seminar_based') {
      updateEndMin(startAtInput, endAtInput);
    } else {
      enforceSimpleEndDefaults();
    }
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
  seminar1StartInput?.addEventListener('input', () => updateEndMin(seminar1StartInput, seminar1EndInput));
  seminar2StartInput?.addEventListener('input', () => updateEndMin(seminar2StartInput, seminar2EndInput));

  setStructure(eventModeInput?.value || 'simple', seminarCountInput?.value || '0');
  setWizardStep(1);
  setEndLocked(endAtInput, true);
  setEndLocked(seminar1EndInput, true);
  setEndLocked(seminar2EndInput, true);

  function decodeTargetParticipant(eventForValue) {
    const raw = (eventForValue || 'All').toString().trim().toUpperCase();
    if (!raw || raw === 'ALL' || raw === 'ALL LEVELS' || raw === 'NONE') {
      return { course: 'ALL', years: ['ALL'] };
    }

    const multi = raw.match(/^COURSE\s*=\s*(ALL|BSIT|BSCS)\s*;\s*YEARS\s*=\s*([0-9,\sA-Z]+)$/);
    if (multi) {
      const years = (multi[2] || '')
        .split(',')
        .map((v) => v.trim().toUpperCase())
        .filter((v) => ['ALL', '1', '2', '3', '4'].includes(v));
      const normalizedYears = years.includes('ALL') || years.length === 0
        ? ['ALL']
        : [...new Set(years)];
      return { course: multi[1], years: normalizedYears };
    }

    const pair = raw.match(/^(BSIT|BSCS)\s*[-_|]\s*([1-4])$/);
    if (pair) {
      return { course: pair[1], years: [pair[2]] };
    }

    if (raw === 'BSIT' || raw === 'BSCS') {
      return { course: raw, years: ['ALL'] };
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

  function encodeTargetParticipant(courseValue, yearValues) {
    const course = (courseValue || 'ALL').toString().trim().toUpperCase();
    const years = normalizeTargetYears(yearValues);

    const normalizedCourse = ['ALL', 'BSIT', 'BSCS'].includes(course) ? course : 'ALL';

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

  document.getElementById('btnCreateEvent').addEventListener('click', () => {
    document.getElementById('mode').value = 'create';
    document.getElementById('event_id').value = '';

    document.getElementById('title').value = '';
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

    if (document.getElementById('event_type')) document.getElementById('event_type').value = 'Event';
    if (document.getElementById('target_course')) document.getElementById('target_course').value = 'ALL';
    setSelectedTargetYears(['ALL']);
    if (document.getElementById('grace_time')) document.getElementById('grace_time').value = '15';

    const msg = document.getElementById('formMsg');
    if (msg) {
      msg.className = 'text-sm text-amber-800 min-h-0 !mt-0';
      msg.textContent = '';
    }

    const modalTitle = document.getElementById('modalTitle');
    if (modalTitle) modalTitle.textContent = 'Create Event';

    setStructure('simple', 0);

    const createMinDate = earliestAllowedCreateDateTime();
    setPickerMin(startAtInput, createMinDate);
    setPickerMin(seminar1StartInput, createMinDate);
    setPickerMin(seminar2StartInput, createMinDate);

    updateEndMin(startAtInput, endAtInput);
    updateEndMin(seminar1StartInput, seminar1EndInput);
    updateEndMin(seminar2StartInput, seminar2EndInput);

    step = 1;
    setWizardStep(1);
    openModal(eventModal);
  });

  document.getElementById('btnCloseModal').addEventListener('click', () => closeModal(eventModal));

  document.getElementById('btnNext').addEventListener('click', () => {
    if (step === 1) {
      if (!document.getElementById('title').value.trim()) return;
      step = 2;
    } else if (step === 2) {
      step = 3;
    }
    setWizardStep(step);
  });

  document.getElementById('btnBack').addEventListener('click', () => {
    step = Math.max(1, step - 1);
    setWizardStep(step);
  });

  document.getElementById('btnSubmit').addEventListener('click', () => {
    document.getElementById('eventForm').requestSubmit();
  });

  document.querySelectorAll('.btnEdit').forEach((btn) => {
    btn.addEventListener('click', () => {
      document.getElementById('mode').value = 'edit';
      document.getElementById('event_id').value = btn.dataset.id || '';
      document.getElementById('title').value = btn.dataset.title || '';
      document.getElementById('location').value = btn.dataset.location || '';
      document.getElementById('description').value = btn.dataset.description || '';

      if (document.getElementById('event_type')) document.getElementById('event_type').value = btn.dataset.event_type || 'Event';
      const decodedTarget = decodeTargetParticipant(btn.dataset.event_for || 'All');
      if (document.getElementById('target_course')) document.getElementById('target_course').value = decodedTarget.course;
      setSelectedTargetYears(decodedTarget.years || ['ALL']);
      if (document.getElementById('grace_time')) document.getElementById('grace_time').value = btn.dataset.grace_time || '15';

      setPickerValue(startAtInput, btn.dataset.start_at ? toLocalInput(btn.dataset.start_at) : '');
      setPickerValue(endAtInput, btn.dataset.end_at ? toLocalInput(btn.dataset.end_at) : '');

      let sessions = [];
      try {
        sessions = sanitizeSessions(JSON.parse(btn.dataset.sessions || '[]'));
      } catch (err) {
        sessions = [];
      }

      const dataMode = (btn.dataset.event_mode || '').trim();
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

      const msg = document.getElementById('formMsg');
      if (msg) {
        msg.className = 'text-sm text-amber-800 min-h-0 !mt-0';
        msg.textContent = '';
      }

      const modalTitle = document.getElementById('modalTitle');
      if (modalTitle) modalTitle.textContent = 'Edit Event';

      step = 1;
      setWizardStep(1);
      openModal(eventModal);
    });
  });

  document.getElementById('eventForm').addEventListener('submit', async (e) => {
    e.preventDefault();

    if (step !== 3) {
      document.getElementById('btnNext').click();
      return;
    }

    const mode = document.getElementById('mode').value;
    const msg = document.getElementById('formMsg');
    const submitBtn = document.getElementById('btnSubmit');

    try {
      const title = document.getElementById('title').value.trim();
      const location = document.getElementById('location').value.trim();
      const description = document.getElementById('description').value.trim();
      const eventType = document.getElementById('event_type') ? document.getElementById('event_type').value : 'Event';
      const targetCourse = document.getElementById('target_course') ? document.getElementById('target_course').value : 'ALL';
      const targetYears = getSelectedTargetYears();
      const eventFor = encodeTargetParticipant(targetCourse, targetYears);
      const graceTime = document.getElementById('grace_time') ? document.getElementById('grace_time').value : '15';

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

      if (mode === 'edit') {
        payload.event_id = document.getElementById('event_id').value;
      }

      msg.className = 'text-sm font-bold text-amber-600 mt-2 text-center';
      msg.textContent = mode === 'edit' ? 'Updating event...' : 'Creating event...';
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

      msg.className = 'text-sm font-bold text-emerald-500 mt-2 text-center';
      msg.textContent = 'Success!';
      setTimeout(() => window.location.reload(), 350);
    } catch (err) {
      msg.className = 'text-sm font-bold text-red-500 mt-2 text-center';
      msg.textContent = err?.message || 'Server error encountered.';
      submitBtn.disabled = false;
      submitBtn.classList.remove('opacity-50', 'cursor-not-allowed');
    }
  });
  // ── Approve (Page 34) ──
  document.querySelectorAll('.btnApprove').forEach(btn => {
    btn.addEventListener('click', async () => {
      const event_id = btn.dataset.id;
      const status = btn.dataset.status;
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
        btn.disabled = false;
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
      window.location.reload();
    } catch (e) {
      alert(e.message || 'Failed to publish event.');
      btnConfirmPublishTeachers.disabled = false;
      btnConfirmPublishTeachers.textContent = 'Publish Event';
    }
  });
  // ── Unified Filtering Logic (Tabs + Search + Type) ──
  const tabAll = document.getElementById('tabAll');
  const tabPending = document.getElementById('tabPending');
  const searchInput = document.getElementById('searchEvents');
  const typeFilter = document.getElementById('filterType');
  const eventCards = document.querySelectorAll('.event-card');

  let activeTab = 'all'; // 'all' or 'pending'

  function refreshEventVisibility() {
    const searchTerm = searchInput ? searchInput.value.toLowerCase().trim() : '';
    const selectedType = typeFilter ? typeFilter.value : 'all';

    eventCards.forEach(card => {
      const status = card.dataset.status;
      const title = card.dataset.title.toLowerCase();
      const location = card.dataset.location.toLowerCase();
      const teacher = card.dataset.teacher.toLowerCase();
      const type = card.dataset.type;

      const matchesTab = (activeTab === 'all') || (status === 'pending');
      const matchesSearch = searchTerm === '' ||
        title.includes(searchTerm) ||
        location.includes(searchTerm) ||
        teacher.includes(searchTerm);
      const matchesType = selectedType === 'all' || type === selectedType;

      if (matchesTab && matchesSearch && matchesType) {
        card.style.display = 'block';

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

    // Update gradients after visibility change
    if (typeof window.syncEventListGradients === 'function') {
      setTimeout(window.syncEventListGradients, 50);
    }
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

  // Real-time input listeners
  searchInput?.addEventListener('input', refreshEventVisibility);
  typeFilter?.addEventListener('change', refreshEventVisibility);

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
            previewText.value = "⚠️ Error formatting text:\n" + data.error;
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
            mainAiStatus.innerHTML = '❌ Error: ' + json.error;
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
</script>

<?php render_footer(); ?>
