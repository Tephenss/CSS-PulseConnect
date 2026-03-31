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

$select = 'select=id,title,description,location,start_at,end_at,status,created_by,approved_by,created_at,updated_at';
$url = rtrim(SUPABASE_URL, '/') . '/rest/v1/events?' . $select . '&status=neq.archived&order=created_at.desc';
if ($role === 'teacher') {
    // Teacher sees their own events OR any published events
    $url .= '&or=(created_by.eq.' . $userId . ',status.eq.published)';
}

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

render_header('Manage Events', $user);
?>

<style>
  /* ── Modal System ── */
  .modal-backdrop {
    position: fixed; inset: 0; z-index: 100;
    background: rgba(15,23,42,0.38);
    backdrop-filter: blur(10px);
    -webkit-backdrop-filter: blur(10px);
    display: flex; align-items: flex-end; justify-content: center;
    opacity: 0; pointer-events: none;
    transition: opacity 0.25s ease;
    padding: 0;
  }
  @media (min-width: 640px) {
    .modal-backdrop { align-items: center; padding: 1.5rem; }
  }
  .modal-backdrop.active { opacity: 1; pointer-events: auto; }

  /* ── Event Wizard Panel ── */
  .modal-panel {
    width: 100%; max-width: 520px;
    border-radius: 1.5rem 1.5rem 0 0;
    border: 1px solid #e4e4e7;
    border-bottom: none;
    background: #ffffff;
    box-shadow: 0 -8px 40px rgba(15,23,42,0.12), 0 0 1px rgba(15,23,42,0.06);
    padding: 0;
    transform: translateY(100%);
    transition: transform 0.4s cubic-bezier(0.16, 1, 0.3, 1);
    max-height: 92vh;
    overflow: hidden;
    display: flex; flex-direction: column;
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
    width: 100%; max-width: 400px;
    border-radius: 1.5rem 1.5rem 0 0;
    border: 1px solid #e4e4e7;
    border-bottom: none;
    background: #ffffff;
    box-shadow: 0 -8px 40px rgba(15,23,42,0.12);
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
  .wizard-stepper { display: flex; align-items: center; gap: 0; width: 100%; }
  .wizard-step {
    display: flex; align-items: center; gap: 0.5rem;
    flex: 1; position: relative;
  }
  .wizard-step:not(:last-child)::after {
    content: ''; flex: 1; height: 2px;
    background: #e4e4e7;
    margin: 0 0.5rem;
    border-radius: 1px;
    transition: background 0.3s ease;
  }
  .wizard-step.completed:not(:last-child)::after { background: rgba(139,92,246,0.5); }
  .step-dot {
    width: 28px; height: 28px; border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    font-size: 11px; font-weight: 600;
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
    box-shadow: 0 0 0 3px rgba(124,58,237,0.15);
  }
  .wizard-step.completed .step-dot {
    border-color: #7c3aed;
    background: #ede9fe;
    color: #6d28d9;
  }
  .step-label {
    font-size: 11px; font-weight: 500;
    color: #a1a1aa;
    transition: color 0.3s ease;
    white-space: nowrap;
  }
  .wizard-step.active .step-label { color: #3f3f46; }
  .wizard-step.completed .step-label { color: #7c3aed; }

  /* ── Form field icons ── */
  .field-icon-wrap {
    position: relative;
  }
  .field-icon-wrap .field-icon {
    position: absolute; left: 12px; top: 50%; transform: translateY(-50%);
    width: 16px; height: 16px; color: #a1a1aa;
    pointer-events: none;
  }
  .field-icon-wrap input,
  .field-icon-wrap textarea { padding-left: 2.25rem; }

  /* Hide scrollbar but keep scrolling */
  .modal-body { overflow-y: auto; -ms-overflow-style: none; scrollbar-width: none; }
  .modal-body::-webkit-scrollbar { display: none; }

  /* ── Speech-to-Text Mic Button ── */
  .stt-wrapper { position: relative; }
  .stt-btn {
    position: absolute; right: 10px; top: 10px;
    width: 36px; height: 36px;
    border-radius: 50%; border: 1.5px solid #e4e4e7;
    background: #fafafa;
    display: flex; align-items: center; justify-content: center;
    cursor: pointer;
    transition: all 0.2s ease;
    z-index: 5;
    color: #71717a;
  }
  .stt-btn:hover { background: #f4f4f5; border-color: #d4d4d8; color: #3f3f46; }
  .stt-btn.recording {
    background: #fef2f2; border-color: #fca5a5; color: #dc2626;
    animation: stt-pulse 1.5s infinite ease-in-out;
  }
  .stt-btn svg { width: 18px; height: 18px; }
  @keyframes stt-pulse {
    0%, 100% { box-shadow: 0 0 0 0 rgba(239,68,68,0.35); }
    50% { box-shadow: 0 0 0 8px rgba(239,68,68,0); }
  }
  .stt-status {
    font-size: 11px; font-weight: 500;
    margin-top: 6px;
    min-height: 16px;
    transition: color 0.2s;
  }
  .stt-status.idle { color: #a1a1aa; }
  .stt-status.listening { color: #dc2626; }
  .stt-status.done { color: #16a34a; }

  /* ── STT Preview Modal ── */
  .stt-preview-panel {
    width: 100%; max-width: 520px;
    border-radius: 1.5rem;
    border: 1px solid #e4e4e7;
    background: #fff;
    box-shadow: 0 -8px 40px rgba(15,23,42,0.12);
    padding: 0;
    transform: translateY(30px) scale(0.96);
    transition: transform 0.4s cubic-bezier(0.16, 1, 0.3, 1);
    max-height: 85vh;
    overflow: hidden;
    display: flex; flex-direction: column;
  }
  .modal-backdrop.active .stt-preview-panel {
    transform: translateY(0) scale(1);
  }
  .stt-tab {
    padding: 0.5rem 1rem;
    font-size: 12px; font-weight: 600;
    border-radius: 0.6rem;
    cursor: pointer;
    transition: all 0.2s;
    border: 1.5px solid transparent;
    color: #71717a; background: transparent;
  }
  .stt-tab.active {
    color: #ea580c;
    background: #fff7ed;
    border-color: #fb923c;
  }
  .stt-tab:hover:not(.active) {
    color: #3f3f46; background: #f4f4f5;
  }
  .stt-preview-textarea {
    width: 100%; min-height: 140px;
    border: 1.5px solid #e4e4e7;
    border-radius: 0.75rem; padding: 0.75rem 1rem;
    font-size: 14px; line-height: 1.7;
    color: #18181b; resize: vertical;
    outline: none;
    transition: border-color 0.2s;
  }
  .stt-preview-textarea:focus {
    border-color: #fb923c;
    box-shadow: 0 0 0 3px rgba(249,115,22,0.12);
  }
  .stt-diff-highlight { background: #dcfce7; border-radius: 2px; padding: 0 1px; }
  .stt-improve-badge {
    display: inline-flex; align-items: center; gap: 4px;
    font-size: 10px; font-weight: 600;
    padding: 3px 8px; border-radius: 999px;
    background: linear-gradient(135deg, #fff7ed, #fef3c7);
    border: 1px solid #fed7aa;
    color: #c2410c;
  }
</style>



<!-- ═══════════  EVENT WIZARD MODAL  ═══════════ -->
<div id="eventModal" class="modal-backdrop">
  <div class="modal-panel">

    <!-- Header -->
    <div class="flex items-center justify-between px-5 sm:px-6 pt-5 sm:pt-6 pb-4 border-b border-zinc-200">
      <div class="flex items-center gap-3">
        <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-orange-600/25 to-red-600/25 border border-orange-500/20 flex items-center justify-center">
          <svg class="w-5 h-5 text-orange-400" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/></svg>
        </div>
        <div>
          <div class="text-base font-semibold text-zinc-900" id="modalTitle">Create Event</div>
          <div class="text-[11px] text-zinc-500" id="modalSubtitle">Fill in the details below</div>
        </div>
      </div>
      <button id="btnCloseModal" class="p-2 rounded-xl text-zinc-500 hover:text-zinc-900 hover:bg-zinc-100 transition">
        <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
      </button>
    </div>

    <!-- Body -->
    <div class="modal-body px-5 sm:px-6 py-5">
      <form id="eventForm" class="space-y-3">
        <input type="hidden" name="mode" id="mode" value="create" />
        <input type="hidden" name="event_id" id="event_id" value="" />

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
              <svg class="field-icon" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931zm0 0L19.5 7.125M18 14v4.75A2.25 2.25 0 0115.75 21H5.25A2.25 2.25 0 013 18.75V8.25A2.25 2.25 0 015.25 6H10"/></svg>
              <input id="title" name="title" required class="w-full rounded-xl bg-white border border-zinc-200 py-3 text-sm text-zinc-900 outline-none focus:ring-2 focus:ring-orange-500/30 focus:border-orange-400 transition placeholder:text-zinc-400" placeholder="e.g. CCS Summit 2026" />
            </div>
          </div>
          <div>
            <label class="block text-xs text-zinc-600 mb-1.5 font-medium tracking-wide">Location</label>
            <div class="field-icon-wrap">
              <svg class="field-icon" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M15 10.5a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1115 0z"/></svg>
              <input id="location" name="location" class="w-full rounded-xl bg-white border border-zinc-200 py-3 text-sm text-zinc-900 outline-none focus:ring-2 focus:ring-orange-500/30 focus:border-orange-400 transition placeholder:text-zinc-400" placeholder="e.g. CCS Auditorium" />
            </div>
          </div>
        </div>

        <!-- Step 2: Details -->
        <div id="step2" class="space-y-4 hidden">
          <div>
            <label class="block text-xs text-zinc-600 mb-1.5 font-medium tracking-wide flex items-center justify-between">
              <span>Description</span>
              <span class="text-[10px] text-zinc-400 font-normal">Click the mic to dictate</span>
            </label>
            <div class="stt-wrapper">
              <textarea id="description" name="description" rows="5" class="w-full rounded-xl bg-white border border-zinc-200 px-4 py-3 pr-14 text-sm text-zinc-900 outline-none focus:ring-2 focus:ring-orange-500/30 focus:border-orange-400 resize-none placeholder:text-zinc-400 transition-all duration-300" placeholder="Tell attendees what this event is about..."></textarea>
              <button type="button" id="sttBtn" class="stt-btn" title="Dictate Description">
                <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M12 18.75a6 6 0 006-6v-1.5m-6 7.5a6 6 0 01-6-6v-1.5m6 7.5v3.75m-3.75 0h7.5M12 15.75a3 3 0 01-3-3V4.5a3 3 0 116 0v8.25a3 3 0 01-3 3z"/>
                </svg>
              </button>
            </div>
            
            <!-- Main Textarea Toolbelt -->
            <div class="flex items-center justify-between mt-1.5 px-1">
              <span id="mainAiStatus" class="hidden text-[11px] text-orange-600 font-medium whitespace-nowrap"></span>
              <div class="flex items-center justify-end gap-3 ml-auto">
                <button type="button" id="mainExpandBtn" class="text-[11px] text-zinc-500 hover:text-zinc-800 font-semibold transition-colors outline-none flex items-center gap-1">
                  ⤢ Expand
                </button>
                <button type="button" id="mainAiImproveBtn" class="text-[11px] text-orange-600 hover:text-orange-700 font-bold transition-all outline-none flex items-center gap-1.5 bg-gradient-to-r from-orange-50 to-red-50 hover:from-orange-100 hover:to-red-100 px-3 py-1.5 rounded-lg border border-orange-200/60 shadow-sm">
                  ✨ AI Improve
                </button>
              </div>
            </div>
          </div>
        </div>

        <!-- Step 3: Schedule -->
        <div id="step3" class="space-y-4 hidden">
          <div>
            <label class="block text-xs text-zinc-600 mb-1.5 font-medium tracking-wide">Start Date & Time</label>
            <div class="field-icon-wrap">
              <svg class="field-icon" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 11.25v7.5"/></svg>
              <input id="start_at_local" name="start_at_local" type="datetime-local" required class="w-full rounded-xl bg-white border border-zinc-200 py-3 text-sm text-zinc-900 outline-none focus:ring-2 focus:ring-orange-500/30 focus:border-orange-400 transition" />
            </div>
          </div>
          <div>
            <label class="block text-xs text-zinc-600 mb-1.5 font-medium tracking-wide">End Date & Time</label>
            <div class="field-icon-wrap">
              <svg class="field-icon" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
              <input id="end_at_local" name="end_at_local" type="datetime-local" required class="w-full rounded-xl bg-white border border-zinc-200 py-3 text-sm text-zinc-900 outline-none focus:ring-2 focus:ring-orange-500/30 focus:border-orange-400 transition" />
            </div>
          </div>
        </div>

        <div id="formMsg" class="text-sm text-amber-800 min-h-0 !mt-0"></div>
      </form>
    </div>

    <!-- Footer -->
    <div class="px-5 sm:px-6 py-4 border-t border-zinc-200 flex items-center justify-between gap-3">
      <button type="button" id="btnBack" class="rounded-xl border border-zinc-200 bg-zinc-50 px-5 py-2.5 text-sm text-zinc-700 hover:bg-zinc-100 hover:text-zinc-900 transition font-medium disabled:opacity-30 disabled:cursor-not-allowed" disabled>
        <span class="flex items-center gap-1.5">
          <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5L8.25 12l7.5-7.5"/></svg>
          Back
        </span>
      </button>
      <div class="flex items-center gap-2">
        <button type="button" id="btnNext" class="rounded-xl bg-gradient-to-r from-orange-600 to-red-600 text-white px-6 py-2.5 text-sm font-semibold hover:from-orange-500 hover:to-red-500 transition-all shadow-lg shadow-orange-600/25 hover:shadow-orange-500/35">
          <span class="flex items-center gap-1.5">
            Next
            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5"/></svg>
          </span>
        </button>
        <button type="button" id="btnSubmit" class="hidden rounded-xl bg-gradient-to-r from-emerald-600 to-emerald-500 text-white px-6 py-2.5 text-sm font-semibold hover:from-emerald-500 hover:to-emerald-400 transition-all shadow-lg shadow-emerald-600/25">
          <span class="flex items-center gap-1.5">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/></svg>
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
    <div class="w-12 h-12 rounded-full bg-red-500/15 border border-red-500/25 flex items-center justify-center mx-auto mb-4">
      <svg class="w-6 h-6 text-red-400" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M20.25 7.5l-.625 10.632a2.25 2.25 0 01-2.247 2.118H6.622a2.25 2.25 0 01-2.247-2.118L3.75 7.5M10 11.25h4M3.375 7.5h17.25c.621 0 1.125-.504 1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125H2.25c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125z"/></svg>
    </div>
    <h3 class="text-lg font-semibold text-zinc-900 mb-1">Archive Event</h3>
    <p class="text-sm text-zinc-600 mb-5">Are you sure you want to archive <span id="archiveEventName" class="text-zinc-900 font-medium"></span>? You can restore it later from the Archive page.</p>
    <input type="hidden" id="archiveEventId" value="" />
    <div class="flex gap-3">
      <button id="btnCancelArchive" class="flex-1 rounded-lg border border-zinc-200 bg-white px-4 py-2.5 text-sm text-zinc-700 hover:bg-zinc-50 transition font-medium">Cancel</button>
      <button id="btnConfirmArchive" class="flex-1 rounded-lg bg-gradient-to-r from-red-600 to-red-500 text-white px-4 py-2.5 text-sm font-medium hover:from-red-500 hover:to-red-400 transition-all shadow-lg shadow-red-600/20">Archive</button>
    </div>
  </div>
</div>

<!-- ═══════════  STT PREVIEW MODAL  ═══════════ -->
<div id="sttPreviewModal" class="modal-backdrop">
  <div class="stt-preview-panel">
    <!-- Header -->
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
          0%, 100% { transform: scaleY(0.4); opacity: 0.5; }
          50% { transform: scaleY(1.2); opacity: 1; }
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
      <div id="sttSpectrumEffect" class="hidden w-full h-[150px] bg-zinc-900 rounded-xl items-center justify-center gap-2 border border-zinc-800 relative overflow-hidden transition-all duration-300">
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
          <button type="button" id="sttExpandToggle" class="text-orange-600 hover:text-orange-700 font-bold flex items-center gap-1 transition-colors outline-none">
            ⤢ Expand View
          </button>
        </div>
        <button type="button" id="sttMicToggle" class="flex items-center gap-1.5 rounded-lg bg-red-50 text-red-600 px-3 py-1.5 font-medium border border-red-200 hover:bg-red-100 transition">
          Stop Recording ⏹
        </button>
      </div>
    </div>

    <!-- Footer -->
    <div class="px-5 py-4 border-t border-zinc-200 flex items-center justify-between gap-3">
      <button type="button" id="sttPreviewDiscard" class="rounded-xl border border-zinc-200 bg-zinc-50 px-4 py-2.5 text-sm text-zinc-700 hover:bg-zinc-100 transition font-medium">
        Discard
      </button>
      <div class="flex items-center gap-2">
        <button type="button" id="sttPreviewAppend" class="rounded-xl border border-orange-200 bg-orange-50 px-4 py-2.5 text-sm text-orange-800 hover:bg-orange-100 transition font-semibold">
          Append ↩
        </button>
        <button type="button" id="sttPreviewReplace" class="rounded-xl bg-gradient-to-r from-orange-600 to-red-600 text-white px-5 py-2.5 text-sm font-semibold hover:from-orange-500 hover:to-red-500 shadow-lg shadow-orange-600/25 transition-all">
          Insert ✓
        </button>
      </div>
    </div>
  </div>
</div>

<?php
  // Compute stats
  $publishedCount = 0; $pendingCount = 0; $approvedCount = 0; $draftCount = 0;
  foreach ($events as $ev) {
    $s = (string)($ev['status'] ?? '');
    if ($s === 'published') $publishedCount++;
    elseif ($s === 'pending') $pendingCount++;
    elseif ($s === 'approved') $approvedCount++;
    elseif ($s === 'draft') $draftCount++;
  }
?>

<!-- ═══════  HEADER  ═══════ -->
<div class="mb-6 flex items-center justify-between flex-wrap gap-3">
  <div>
    <p class="text-zinc-600 text-sm">
      <?php if ($role === 'admin'): ?>Full control — create, edit, approve and publish events.<?php else: ?>Create events (pending). Admin approves & publishes.<?php endif; ?>
    </p>
  </div>
  <button id="btnCreateEvent" class="flex items-center gap-2 rounded-xl bg-gradient-to-r from-orange-600 to-red-600 text-white px-5 py-2.5 text-sm font-medium hover:from-orange-500 hover:to-red-500 transition-all shadow-lg shadow-orange-600/25 hover:shadow-orange-500/40 hover:scale-[1.02] active:scale-[0.98]">
    <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/></svg>
    Create Event
  </button>
</div>

<!-- ═══════  STAT CARDS  ═══════ -->
<div class="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-6">
  <div class="rounded-xl border border-zinc-200 bg-white p-4 shadow-sm group hover:border-emerald-300 transition-colors">
    <div class="flex items-center gap-3">
      <div class="w-10 h-10 rounded-xl bg-emerald-100 border border-emerald-200 flex items-center justify-center">
        <svg class="w-5 h-5 text-emerald-700" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
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
        <svg class="w-5 h-5 text-amber-800" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
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
        <svg class="w-5 h-5 text-sky-700" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/></svg>
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
        <svg class="w-5 h-5 text-orange-700" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 11.25v7.5"/></svg>
      </div>
      <div>
        <div class="text-2xl font-bold text-zinc-900"><?= count($events) ?></div>
        <div class="text-[11px] text-zinc-600 font-medium">Total Active</div>
      </div>
    </div>
  </div>
</div>

<!-- ═══════  FEATURED 3D SAMPLE EVENT  ═══════ -->
<div class="mb-14 lg:mb-16 w-full relative overflow-visible mt-10 rounded-[1.5rem] bg-gradient-to-br from-[#0f172a] via-[#1e1b4b] to-[#0f172a] px-8 lg:px-14 py-6 lg:py-0 shadow-xl border border-indigo-500/20 flex flex-col lg:flex-row items-center justify-between lg:h-[260px]">
  <div class="absolute inset-0 bg-[url('data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHdpZHRoPSI4IiBoZWlnaHQ9IjgiPgo8cmVjdCB3aWR0aD0iOCIgaGVpZ2h0PSI4IiBmaWxsPSIjZmZmIiBmaWxsLW9wYWNpdHk9IjAuMDUiLz4KPC9zdmc+')] opacity-20 rounded-[1.5rem] mix-blend-overlay pointer-events-none"></div>
  
  <!-- LEFT: Text Content -->
  <div class="relative z-20 flex-1 w-full text-center lg:text-left my-6 lg:my-0 pointer-events-none">
    <div class="flex items-center justify-center lg:justify-start gap-3 mb-3">
      <span class="flex h-3 w-3 relative">
        <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-emerald-400 opacity-75"></span>
        <span class="relative inline-flex rounded-full h-3 w-3 bg-emerald-500"></span>
      </span>
      <span class="text-[10px] font-bold tracking-[0.2em] text-emerald-400 uppercase">Interactive Sample</span>
    </div>
    <h2 class="text-3xl sm:text-4xl lg:text-[2rem] font-extrabold text-white tracking-tight leading-none whitespace-nowrap">CSS Summit Featured Showcase</h2>
  </div>

  <!-- RIGHT: 3D Laptop Container -->
  <style>
    .laptop-scale-container {
       position: relative; z-index: 10;
       width: 100%; lg:width: 50%;
       display: flex; justify-content: center; align-items: center;
       height: 0px; /* Collapse natural height */
    }
    .laptop-wrapper { 
       transform: scale(0.65); transform-origin: center center; 
       margin-bottom: 25px; /* Adjust optical center */
    }
    @media (min-width: 1024px) { 
      .laptop-wrapper { transform-origin: right center; right: 20px; position: absolute; margin-bottom: 0px; top: 50%; transform: translateY(-50%) scale(0.75); } 
    }
    @media (max-width: 768px) { 
       .laptop-wrapper { transform: scale(0.45); } 
    }
    
    .laptop { transform: scale(0.8); }
    .screen {
      border-radius: 20px;
      box-shadow: inset 0 0 0 2px #c8cacb, inset 0 0 0 10px #000;
      height: 318px; width: 518px; margin: 0 auto;
      padding: 9px 9px 23px 9px;
      position: relative;
      display: flex; align-items: center; justify-content: center;
      background-image: linear-gradient(15deg, #3f51b1 0%, #5a55ae 13%, #7b5fac 25%, #8f6aae 38%, #a86aa4 50%, #cc6b8e 62%, #f18271 75%, #f3a469 87%, #f7c978 100%);
      transform-style: preserve-3d;
      transform: perspective(1900px) rotateX(-88.5deg);
      transform-origin: 50% 100%;
      animation: openLaptop 4s cubic-bezier(0.4, 0.0, 0.2, 1) infinite alternate;
      z-index: 5;
    }
    
    @keyframes openLaptop {
      0% { transform: perspective(1900px) rotateX(-89deg); }
      100% { transform: perspective(1000px) rotateX(0deg); }
    }

    @keyframes swapImage {
      0% { background-image: url('assets/sample summit/image1.jpg'); }
      49.9% { background-image: url('assets/sample summit/image1.jpg'); }
      50% { background-image: url('assets/sample summit/image2.jpg'); }
      100% { background-image: url('assets/sample summit/image2.jpg'); }
    }

    .screen-bg {
      position: absolute; top: 9px; left: 9px; right: 9px; bottom: 23px;
      border-radius: 12px;
      background-size: cover; background-position: center;
      animation: swapImage 16s infinite;
      z-index: 10; 
      box-shadow: inset 0 0 40px rgba(0,0,0,0.6);
    }
    
    .screen-bg::after {
      content: ""; position: absolute; inset: 0; background: rgba(0,0,0,0.4); border-radius: 12px;
    }

    .screen::before {
      content: ""; width: 518px; height: 12px; position: absolute;
      background: linear-gradient(#979899, transparent);
      top: -3px; transform: rotateX(90deg); border-radius: 5px 5px; z-index: 20;
    }

    .laptop-text {
      font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
      color: #fff; letter-spacing: 2px;
      text-shadow: 0 4px 10px rgba(0,0,0,0.8), 0 0 20px rgba(255,100,100,0.4);
      font-size: 32px; font-weight: 800;
      z-index: 30; text-transform: uppercase;
    }

    .header-cam {
      width: 100px; height: 12px; position: absolute;
      background-color: #000; top: 10px; left: 50%;
      transform: translate(-50%, -0%); border-radius: 0 0 6px 6px; z-index: 30;
    }

    .screen::after {
      background: linear-gradient(to bottom, #272727, #0d0d0d);
      border-radius: 0 0 20px 20px; bottom: 2px;
      content: ""; height: 24px; left: 2px; position: absolute; width: 514px; z-index: 20;
    }

    .keyboard {
      background: radial-gradient(circle at center, #e2e3e4 85%, #a9abac 100%);
      border: solid #a0a3a7; border-radius: 2px 2px 12px 12px; border-width: 1px 2px 0 2px;
      box-shadow: inset 0 -2px 8px 0 #6c7074, 0 30px 60px rgba(0,0,0,0.8);
      height: 24px; margin-top: -10px; position: relative; width: 620px; z-index: 9;
      margin: -10px auto 0 auto;
    }

    .keyboard::after {
      background: #e2e3e4; border-radius: 0 0 10px 10px;
      box-shadow: inset 0 0 4px 2px #babdbf; content: "";
      height: 10px; left: 50%; margin-left: -60px; position: absolute; top: 0; width: 120px;
    }

    .keyboard::before {
      background: 0 0; border-radius: 0 0 3px 3px; bottom: -2px;
      box-shadow: -270px 0 #272727, 250px 0 #272727; content: "";
      height: 2px; left: 50%; margin-left: -10px; position: absolute; width: 40px;
    }
  </style>

  <div class="laptop-scale-container">
    <div class="laptop-wrapper">
      <div class="laptop">
        <div class="screen">
          <div class="screen-bg"></div>
          <div class="header-cam"></div>
          <div class="laptop-text animate-pulse">CCS Summit Event</div>
        </div>
        <div class="keyboard"></div>
      </div>
    </div>
  </div>
</div>

<!-- ═══════  EVENTS TABLE  ═══════ -->
<div class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm">
  <div class="flex items-center justify-between gap-3 mb-1">
    <div class="flex items-center gap-2.5">
      <div class="w-8 h-8 rounded-lg bg-sky-100 border border-sky-200 flex items-center justify-center">
        <svg class="w-4 h-4 text-sky-700" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 6.75h12M8.25 12h12m-12 5.25h12M3.75 6.75h.007v.008H3.75V6.75zm.375 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zM3.75 12h.007v.008H3.75V12zm.375 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm-.375 5.25h.007v.008H3.75v-.008zm.375 0a.375.375 0 11-.75 0 .375.375 0 01.75 0z"/></svg>
      </div>
      <span class="text-sm font-medium text-zinc-900">Events List</span>
      <span class="text-[10px] bg-zinc-100 text-zinc-700 border border-zinc-200 px-2 py-0.5 rounded-full font-medium"><?= count($events) ?></span>
    </div>
    <div class="text-xs text-zinc-500">
      <?php if ($role === 'admin'): ?>Admin controls<?php else: ?>Your events<?php endif; ?>
    </div>
  </div>

  <?php if ($role === 'admin'): ?>
  <!-- Tabs Navigation for Admin (Matches PDF Page 33) -->
  <div class="flex border-b border-zinc-200 mb-5 gap-6 mt-3">
      <button id="tabAll" class="pb-3 border-b-[2.5px] border-orange-500 font-bold text-orange-600 text-[13px] transition-colors w-24">All Events</button>
      <button id="tabPending" class="pb-3 border-b-[2.5px] border-transparent font-semibold text-zinc-500 hover:text-zinc-800 text-[13px] transition-colors flex items-center gap-1.5 px-2">
          Pending Proposals
          <?php $pendingCount = count(array_filter($events, fn($e) => ($e['status'] ?? '') === 'pending' || ($e['status'] ?? '') === 'draft')); ?>
          <?php if ($pendingCount > 0): ?>
             <span class="bg-red-100 border border-red-200 text-red-700 text-[10px] font-black px-2 py-0.5 rounded-full shadow-sm"><?= $pendingCount ?></span>
          <?php endif; ?>
      </button>
  </div>
  <?php else: ?>
  <div class="mb-5"></div>
  <?php endif; ?>

  <div class="space-y-2">
    <?php foreach ($events as $e): ?>
      <?php
        $eid = (string) ($e['id'] ?? '');
        $createdBy = (string) ($e['created_by'] ?? '');
        $canEdit = $role === 'admin' || ($role === 'teacher' && $createdBy === $userId && (string) ($e['status'] ?? '') === 'pending');
        $status = (string)($e['status'] ?? '');

        $statusConfig = match($status) {
            'published' => ['bg' => 'bg-emerald-100', 'text' => 'text-emerald-900', 'border' => 'border-emerald-200', 'accent' => 'border-l-emerald-500', 'icon' => '<path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>'],
            'pending' => ['bg' => 'bg-amber-100', 'text' => 'text-amber-900', 'border' => 'border-amber-200', 'accent' => 'border-l-amber-500', 'icon' => '<path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z"/>'],
            'approved' => ['bg' => 'bg-sky-100', 'text' => 'text-sky-900', 'border' => 'border-sky-200', 'accent' => 'border-l-sky-500', 'icon' => '<path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/>'],
            default => ['bg' => 'bg-zinc-100', 'text' => 'text-zinc-800', 'border' => 'border-zinc-200', 'accent' => 'border-l-zinc-400', 'icon' => '<path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m2.25 0H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z"/>'],
        };

        // Format date
        $rawDate = (string) ($e['start_at'] ?? '');
        $formattedDate = $rawDate;
        if ($rawDate) {
            try {
                $dt = new DateTimeImmutable($rawDate);
                $formattedDate = $dt->format('M d, Y · g:i A');
            } catch (Throwable $ex) {}
        }
      ?>
      <div class="rounded-xl border border-zinc-200 bg-zinc-50/90 hover:bg-white hover:border-zinc-300 transition-all group border-l-[3px] shadow-sm <?= $statusConfig['accent'] ?>">
        <div class="flex flex-col lg:flex-row lg:items-center gap-3 p-4">

          <!-- Event Info -->
          <div class="flex-1 min-w-0">
            <div class="flex items-start gap-3">
              <div class="hidden sm:flex w-10 h-10 rounded-xl <?= $statusConfig['bg'] ?> border <?= $statusConfig['border'] ?> items-center justify-center flex-shrink-0 mt-0.5">
                <svg class="w-5 h-5 <?= $statusConfig['text'] ?>" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><?= $statusConfig['icon'] ?></svg>
              </div>
              <div class="min-w-0 flex-1">
                <div class="flex items-center gap-2 mb-1">
                  <h3 class="text-sm font-semibold text-zinc-900 truncate"><?= htmlspecialchars((string) ($e['title'] ?? '')) ?></h3>
                  <span class="text-[10px] font-medium rounded-full border px-2 py-0.5 <?= $statusConfig['bg'] ?> <?= $statusConfig['text'] ?> <?= $statusConfig['border'] ?> flex-shrink-0 capitalize"><?= htmlspecialchars($status) ?></span>
                </div>
                <div class="flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-zinc-600">
                  <span class="flex items-center gap-1">
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 11.25v7.5"/></svg>
                    <?= htmlspecialchars($formattedDate) ?>
                  </span>
                  <?php if (!empty($e['location'])): ?>
                  <span class="flex items-center gap-1">
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M15 10.5a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1115 0z"/></svg>
                    <?= htmlspecialchars((string) ($e['location'] ?? '')) ?>
                  </span>
                  <?php endif; ?>
                </div>
              </div>
            </div>
          </div>

          <!-- Actions -->
          <div class="flex gap-1.5 flex-wrap items-center lg:flex-shrink-0 pl-0 sm:pl-[52px] lg:pl-0">
            <?php if ($role === 'admin'): ?>
              <?php if ($status === 'pending' || $status === 'draft'): ?>
                <button class="btnReject rounded-lg border border-red-200 bg-red-50 px-4 py-1.5 text-[13px] text-red-700 hover:bg-red-100 transition font-bold"
                        data-id="<?= htmlspecialchars($eid) ?>" data-title="<?= htmlspecialchars((string) ($e['title'] ?? '')) ?>">Reject</button>
                <button class="btnApprove rounded-lg bg-emerald-600 text-white px-4 py-1.5 text-[13px] font-bold hover:bg-emerald-500 transition-colors border border-emerald-600 shadow-sm"
                        data-id="<?= htmlspecialchars($eid) ?>" data-status="approved">Approve</button>
              <?php endif; ?>
              <button class="btnArchive rounded-lg border border-red-200 bg-red-50 px-3 py-1.5 text-xs font-semibold text-red-800 hover:bg-red-100 transition"
                      data-id="<?= htmlspecialchars($eid) ?>" data-title="<?= htmlspecialchars((string) ($e['title'] ?? '')) ?>">Archive</button>
            <?php endif; ?>
            <?php if ($canEdit): ?>
              <button class="btnEdit rounded-lg border border-zinc-200 bg-white px-3 py-1.5 text-xs text-zinc-800 hover:bg-zinc-50 transition font-medium"
                      data-id="<?= htmlspecialchars($eid) ?>"
                      data-title="<?= htmlspecialchars((string) ($e['title'] ?? '')) ?>"
                      data-location="<?= htmlspecialchars((string) ($e['location'] ?? '')) ?>"
                      data-description="<?= htmlspecialchars((string) ($e['description'] ?? '')) ?>"
                      data-start_at="<?= htmlspecialchars((string) ($e['start_at'] ?? '')) ?>"
                      data-end_at="<?= htmlspecialchars((string) ($e['end_at'] ?? '')) ?>"
              >Edit</button>
            <?php endif; ?>
            <a href="/participants.php?event_id=<?= htmlspecialchars($eid) ?>" class="rounded-lg border border-zinc-200 bg-white px-3 py-1.5 text-xs text-zinc-800 hover:bg-zinc-50 transition font-medium">Participants</a>
          </div>

        </div>
      </div>
    <?php endforeach; ?>

    <?php if (count($events) === 0): ?>
      <div class="text-center py-16 text-zinc-600">
        <div class="w-16 h-16 rounded-2xl bg-zinc-100 border border-zinc-200 flex items-center justify-center mx-auto mb-4">
          <svg class="w-8 h-8 text-zinc-500" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 11.25v7.5"/></svg>
        </div>
        <h3 class="text-zinc-800 font-medium mb-1">No events yet</h3>
        <p class="text-sm">Click <span class="text-orange-700 font-medium">"Create Event"</span> to get started.</p>
      </div>
    <?php endif; ?>
  </div>
</div>

<!-- ═══════════  REJECT PROPOSAL MODAL (Matches Page 34) ═══════════ -->
<div id="rejectModal" class="modal-backdrop">
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
      
      <p class="text-[13px] text-zinc-600 mb-3 px-1 leading-relaxed">Are you sure you want to reject the proposal for <span id="rejectEventName" class="font-bold text-zinc-900"></span>? Please provide a reason to notify the event coordinator.</p>
      
      <div class="mt-2">
         <label class="block text-xs font-black text-zinc-500 uppercase tracking-widest mb-1.5 px-1">Reason for refusing</label>
         <textarea id="rejectReason" rows="3" class="w-full rounded-xl bg-zinc-50 border border-zinc-200 px-4 py-3 text-sm text-zinc-900 outline-none focus:ring-2 focus:ring-red-500/30 focus:border-red-400 resize-none transition" placeholder="e.g. Conflicts with midterm examination week..."></textarea>
      </div>
      <input type="hidden" id="rejectEventId" value="" />
    </div>

    <!-- Actions -->
    <div class="flex border-t border-zinc-200 bg-zinc-50">
       <button id="btnCancelReject" class="flex-1 py-3.5 text-[13px] font-bold text-zinc-600 hover:bg-zinc-100 transition border-r border-zinc-200">Cancel</button>
       <button id="btnConfirmReject" class="flex-1 py-3.5 text-[13px] font-bold text-white bg-red-600 hover:bg-red-700 transition shadow-sm">Reject Proposal</button>
    </div>
  </div>
</div>

<script>
  // ── Modal helpers ──
  const eventModal = document.getElementById('eventModal');
  const archiveModal = document.getElementById('archiveModal');

  function openModal(el) { el.classList.add('active'); document.body.style.overflow = 'hidden'; }
  function closeModal(el) { el.classList.remove('active'); document.body.style.overflow = ''; }

  eventModal.addEventListener('click', (e) => { if (e.target === eventModal) closeModal(eventModal); });
  archiveModal.addEventListener('click', (e) => { if (e.target === archiveModal) closeModal(archiveModal); });

  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') { closeModal(eventModal); closeModal(archiveModal); }
  });

  // ── Create Event button ──
  document.getElementById('btnCreateEvent').addEventListener('click', () => {
    document.getElementById('mode').value = 'create';
    document.getElementById('event_id').value = '';
    document.getElementById('title').value = '';
    document.getElementById('location').value = '';
    document.getElementById('description').value = '';
    document.getElementById('start_at_local').value = '';
    document.getElementById('end_at_local').value = '';
    document.getElementById('formMsg').textContent = '';
    document.getElementById('modalTitle').textContent = 'Create Event';
    document.getElementById('modalSubtitle').textContent = 'Fill in the details below';
    step = 1;
    setWizardStep(1);
    openModal(eventModal);
  });

  document.getElementById('btnCloseModal').addEventListener('click', () => closeModal(eventModal));

  // ── Wizard step logic ──
  function toLocalInput(iso) {
    if (!iso) return '';
    const d = new Date(iso);
    const pad = (n) => String(n).padStart(2, '0');
    return d.getFullYear() + '-' + pad(d.getMonth()+1) + '-' + pad(d.getDate()) + 'T' + pad(d.getHours()) + ':' + pad(d.getMinutes());
  }

  const subtitles = ['Fill in the event info', 'Add a description', 'Set the schedule'];

  function setWizardStep(s) {
    document.getElementById('step1').classList.toggle('hidden', s !== 1);
    document.getElementById('step2').classList.toggle('hidden', s !== 2);
    document.getElementById('step3').classList.toggle('hidden', s !== 3);
    document.getElementById('btnBack').disabled = s === 1;
    document.getElementById('btnNext').classList.toggle('hidden', s === 3);
    document.getElementById('btnSubmit').classList.toggle('hidden', s !== 3);
    document.getElementById('modalSubtitle').textContent = subtitles[s - 1] || '';

    // Update stepper
    ['ws1','ws2','ws3'].forEach((id, i) => {
      const el = document.getElementById(id);
      el.classList.remove('active', 'completed');
      if ((i + 1) === s) el.classList.add('active');
      else if ((i + 1) < s) el.classList.add('completed');
    });
  }

  let step = 1;

  document.getElementById('btnNext').addEventListener('click', () => {
    if (step === 1) {
      if (!document.getElementById('title').value.trim()) return;
      step = 2;
    } else if (step === 2) {
      // Step 2 is now Details — no required validation, just proceed
      step = 3;
    }
    setWizardStep(step);
  });

  document.getElementById('btnBack').addEventListener('click', () => {
    step = Math.max(1, step - 1);
    setWizardStep(step);
  });

  // Submit button is outside <form>, so we trigger submit manually
  document.getElementById('btnSubmit').addEventListener('click', () => {
    document.getElementById('eventForm').requestSubmit();
  });

  // ── Edit buttons → open modal ──
  document.querySelectorAll('.btnEdit').forEach(btn => {
    btn.addEventListener('click', () => {
      document.getElementById('mode').value = 'edit';
      document.getElementById('event_id').value = btn.dataset.id || '';
      document.getElementById('title').value = btn.dataset.title || '';
      document.getElementById('location').value = btn.dataset.location || '';
      document.getElementById('description').value = btn.dataset.description || '';
      document.getElementById('start_at_local').value = toLocalInput(btn.dataset.start_at);
      document.getElementById('end_at_local').value = toLocalInput(btn.dataset.end_at);
      document.getElementById('formMsg').textContent = '';
      document.getElementById('modalTitle').textContent = 'Edit Event';
      document.getElementById('modalSubtitle').textContent = 'Update the event details';
      step = 1;
      setWizardStep(1);
      openModal(eventModal);
    });
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

  // ── Tabs Filtering (Admin) ──
  const tabAll = document.getElementById('tabAll');
  const tabPending = document.getElementById('tabPending');
  
  if (tabAll && tabPending) {
      const allCards = document.querySelectorAll('.group.border-l-\\[3px\\]');
      
      tabAll.addEventListener('click', () => {
          tabAll.classList.replace('border-transparent','border-orange-500');
          tabAll.classList.replace('text-zinc-500','text-orange-600');
          
          tabPending.classList.replace('border-orange-500','border-transparent');
          tabPending.classList.replace('text-orange-600','text-zinc-500');
          
          allCards.forEach(c => c.style.display = 'block');
      });
      
      tabPending.addEventListener('click', () => {
          tabPending.classList.replace('border-transparent','border-orange-500');
          tabPending.classList.replace('text-zinc-500','text-orange-600');
          
          tabAll.classList.replace('border-orange-500','border-transparent');
          tabAll.classList.replace('text-orange-600','text-zinc-500');
          
          allCards.forEach(c => {
              if (c.classList.contains('border-l-amber-500')) c.style.display = 'block'; // 'pending' status accent
              else c.style.display = 'none';
          });
      });
  }

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
  rejectModal?.addEventListener('click', (e) => { if(e.target === rejectModal) closeReject(); });
  
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
          // Archiving/Drafting as rejection
          body: JSON.stringify({ event_id, status: 'draft', csrf_token: window.CSRF_TOKEN })
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

  // ── Form submit (Create / Edit) ──
  document.getElementById('eventForm').addEventListener('submit', async (e) => {
    e.preventDefault();
    const mode = document.getElementById('mode').value;
    const msg = document.getElementById('formMsg');
    const payload = {
      title: document.getElementById('title').value.trim(),
      location: document.getElementById('location').value.trim(),
      description: document.getElementById('description').value.trim(),
      start_at_local: document.getElementById('start_at_local').value,
      end_at_local: document.getElementById('end_at_local').value,
      csrf_token: window.CSRF_TOKEN
    };
    if (!payload.start_at_local || !payload.end_at_local) {
      msg.textContent = 'Start/end are required.';
      return;
    }
    const startDate = new Date(payload.start_at_local);
    const endDate = new Date(payload.end_at_local);
    if (Number.isNaN(startDate.getTime()) || Number.isNaN(endDate.getTime())) {
      msg.textContent = 'Invalid datetime.';
      return;
    }
    if (endDate <= startDate) {
      msg.textContent = 'End must be after start.';
      return;
    }
    payload.start_at = startDate.toISOString();
    payload.end_at = endDate.toISOString();
    delete payload.start_at_local;
    delete payload.end_at_local;
    msg.textContent = mode === 'edit' ? 'Updating...' : 'Creating...';

    const event_id = document.getElementById('event_id').value;
    const url = mode === 'edit' ? '/api/events_update.php' : '/api/events_create.php';
    try {
      const res = await fetch(url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(mode === 'edit' ? { event_id, ...payload } : payload)
      });
      const data = await res.json();
      if (!data.ok) throw new Error(data.error || 'Failed');
      window.location.reload();
    } catch (err) {
      msg.textContent = err.message || 'Failed';
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
        sttDebug.innerHTML += '<div><span class="text-zinc-500">['+ts+']</span> ' + msg + '</div>';
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
      var w = v.trim().split(/\s+/).filter(function(x){return x.length>0;});
      wordCount.textContent = w.length + ' word' + (w.length !== 1 ? 's' : '');
    }

    previewText.addEventListener('input', function() {
      if (activeTab === 'raw') {
        rawTranscript = previewText.value;
        improvedTranscript = ''; // Invalidate AI cache if user manually edits raw text
      }
      if (activeTab === 'improved') {
        improvedTranscript = previewText.value;
      }
      updateCounts();
    });

    tabRaw.addEventListener('click', function() {
      if (isRecording) return; // Disallow tab switch while dictating
      activeTab = 'raw';
      tabRaw.classList.add('active'); tabImproved.classList.remove('active');
      previewText.value = rawTranscript; updateCounts();
    });

    tabImproved.addEventListener('click', async function() {
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
          body: JSON.stringify({ raw_text: currentRaw })
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
            previewText.value = "⚠️ Error formatting text:\n" + data.error + "\n\n(Did you forget to put your API Key in config.php?)";
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
          
          mediaRecorder.ondataavailable = function(e) {
              if (e.data.size > 0) audioChunks.push(e.data);
          };
          
          mediaRecorder.onstop = async function() {
              logDebug("MediaRecorder onstop triggered, sending audio to Groq API...");
              clearInterval(recordingTimer);
              modalStatus.innerHTML = '⏳ Uploading and processing audio... Please wait';
              
              const audioBlob = new Blob(audioChunks, { type: 'audio/webm' });
              const formData = new FormData();
              formData.append('audio', audioBlob, 'audio.webm');
              
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
              } catch(err) {
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

    micToggleBtn.addEventListener('click', function() { 
      if (isRecording) {
        stopRecording('manual'); 
      } else {
        startRecording(true); // Resume recording without clearing text
      }
    });

    sttBtn.addEventListener('click', function(e) {
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
      sttExpandToggle.addEventListener('click', function() {
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
    const mainAiStatus = document.getElementById('mainAiStatus');
    const mainModalPanel = document.querySelector('#eventModal .modal-panel');
    
    let mainIsExpanded = false;
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
            
            // UI Loading state
            mainAiBtn.disabled = true;
            mainAiBtn.style.opacity = '0.5';
            mainAiStatus.classList.remove('hidden');
            mainAiStatus.innerHTML = '⏳ AI is rewriting your text...';
            
            try {
                const res = await fetch('api/ai_improve.php', { 
                    method: 'POST', 
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ raw_text: raw }) 
                });
                const json = await res.json();
                if (json.ok) {
                    mainDesc.value = json.improved_text;
                    mainAiStatus.innerHTML = '✅ Professionally Improved!';
                    setTimeout(() => mainAiStatus.classList.add('hidden'), 4000);
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

    logDebug("STT Script fully loaded & event listeners attached.");
  })();
</script>

<?php render_footer(); ?>
