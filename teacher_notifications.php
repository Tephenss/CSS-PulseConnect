<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/session.php';
session_bootstrap();

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';

$user = require_role(['teacher']);
render_header('Send Notification', $user);
?>

<style>
.teacher-notification-grid { display: grid; grid-template-columns: minmax(0, 1fr); align-items: start; gap: 1.25rem; }
@media (min-width: 1280px) { .teacher-notification-grid { grid-template-columns: minmax(0, 1.2fr) minmax(320px, 0.8fr); } }
#recipientList, .recipient-list-state { min-height: 280px; }
#recipientSearch:focus, #programSelect:focus, #yearSelect:focus, #titleChoice:focus, #customTitle:focus, #messageBody:focus { background-color: #fff; border-color: #f97316; outline: 0; box-shadow: 0 0 0 3px rgba(249, 115, 22, 0.14); }
#sendNotificationButton:focus-visible { outline: 0; box-shadow: 0 0 0 4px rgba(249, 115, 22, 0.24); }
.notification-spinner { border-top-color: #ea580c; }
.recipient-mode[aria-pressed="true"] { border-color: #fdba74; background: #fff7ed; color: #c2410c; box-shadow: inset 0 0 0 1px #fed7aa; }
</style>

<section class="mb-6 overflow-hidden rounded-2xl border border-orange-100 bg-gradient-to-r from-orange-50 via-white to-emerald-50 p-6 sm:p-6">
  <div class="flex flex-col gap-5 sm:flex-row sm:items-center sm:justify-between">
    <div class="max-w-2xl">
      <p class="text-xs font-bold uppercase tracking-[0.2em] text-orange-700">Teacher Tools</p>
      <h2 class="mt-2 text-2xl font-extrabold tracking-tight text-zinc-900 sm:text-3xl">Send a student notification</h2>
      <p class="mt-2 text-sm leading-6 text-zinc-600">Send to individual students, a course/year group, or registered participants of a selected event. Messages are saved to the app inbox; a phone push is also attempted for registered devices.</p>
    </div>
    <div class="shrink-0 rounded-2xl border border-emerald-200 bg-white/80 px-4 py-3 text-sm text-emerald-800 shadow-sm">
      <div class="font-bold">App inbox + push</div>
      <div class="mt-1 text-xs text-zinc-500">Individual, course/year, or event audience</div>
    </div>
  </div>
</section>

<div class="teacher-notification-grid">
  <section class="overflow-hidden rounded-2xl border border-zinc-200 bg-white shadow-sm">
    <div class="border-b border-zinc-100 px-5 py-4 sm:px-6">
      <div class="flex items-center justify-between gap-3">
        <div>
          <p class="text-[11px] font-bold uppercase tracking-widest text-orange-600">Step 1</p>
          <h3 class="mt-1 text-lg font-bold text-zinc-900">Choose recipients</h3>
        </div>
        <span id="selectedCount" class="rounded-full bg-orange-50 px-3 py-1 text-xs font-bold text-orange-700">0 selected</span>
      </div>
      <div class="mt-4 grid grid-cols-3 gap-2 rounded-xl bg-zinc-50 p-1.5">
        <button type="button" class="recipient-mode rounded-lg border border-transparent px-2 py-2.5 text-xs font-bold text-zinc-600 transition hover:bg-white" data-mode="individual" aria-pressed="true">Individuals</button>
        <button type="button" class="recipient-mode rounded-lg border border-transparent px-2 py-2.5 text-xs font-bold text-zinc-600 transition hover:bg-white" data-mode="group" aria-pressed="false">Course / year</button>
        <button type="button" class="recipient-mode rounded-lg border border-transparent px-2 py-2.5 text-xs font-bold text-zinc-600 transition hover:bg-white" data-mode="event" aria-pressed="false">Event participants</button>
      </div>

      <div id="individualRecipientPanel" class="mt-4">
        <div id="selectedPreview" class="mb-3 hidden flex flex-wrap gap-1.5"></div>
        <div class="flex flex-col gap-2 sm:flex-row">
          <label class="relative min-w-0 flex-1">
            <span class="sr-only">Search student accounts</span>
            <svg class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-zinc-400" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path stroke-linecap="round" d="m20 20-4-4"/></svg>
            <input id="recipientSearch" type="search" maxlength="80" autocomplete="off" placeholder="Search name, student no., email, or course" class="w-full rounded-xl border border-zinc-200 bg-zinc-50 py-3 pl-10 pr-3 text-sm outline-none transition" />
          </label>
          <button id="selectPageButton" type="button" class="rounded-xl border border-zinc-200 px-4 py-3 text-xs font-bold text-zinc-700 transition hover:border-orange-200 hover:bg-orange-50 disabled:cursor-not-allowed disabled:opacity-50">Select shown</button>
        </div>
        <div class="mt-3 flex items-center justify-between gap-3 text-xs text-zinc-500">
          <span id="resultSummary" aria-live="polite">Loading student accounts…</span>
          <button id="clearSelectionButton" type="button" class="font-semibold text-zinc-500 underline underline-offset-2 hover:text-red-600 disabled:cursor-not-allowed disabled:opacity-40">Clear selection</button>
        </div>
      </div>

      <div id="groupRecipientPanel" class="mt-4 hidden">
        <p class="mb-3 text-xs leading-5 text-zinc-500">Choose a program, then optionally narrow it to a year level. Only active student accounts linked to the student roster are included.</p>
        <div class="grid gap-3 sm:grid-cols-2">
          <label class="block">
            <span class="mb-1.5 block text-xs font-bold text-zinc-700">Program / course</span>
            <select id="programSelect" class="w-full rounded-xl border border-zinc-200 bg-white px-3.5 py-3 text-sm outline-none transition">
              <option value="">All programs</option>
            </select>
          </label>
          <label class="block">
            <span class="mb-1.5 block text-xs font-bold text-zinc-700">Year level</span>
            <select id="yearSelect" class="w-full rounded-xl border border-zinc-200 bg-white px-3.5 py-3 text-sm outline-none transition">
              <option value="">Any year</option>
              <option value="1">1st Year</option>
              <option value="2">2nd Year</option>
              <option value="3">3rd Year</option>
              <option value="4">4th Year</option>
            </select>
          </label>
        </div>
        <div id="groupSummary" class="mt-4 flex items-center gap-3 rounded-xl border border-emerald-100 bg-emerald-50 px-4 py-3" aria-live="polite">
          <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-white text-emerald-700 shadow-sm"><svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M16 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2m16 0v-2a4 4 0 0 0-3-3.87M14 3.13a4 4 0 0 1 0 7.75M10 7a4 4 0 1 1-8 0 4 4 0 0 1 8 0Z"/></svg></span>
          <div class="min-w-0"><div id="groupCount" class="text-sm font-extrabold text-emerald-900">Loading active roster…</div><div id="groupDescription" class="mt-0.5 text-xs text-emerald-800">Checking active student accounts</div></div>
        </div>
        <div id="audienceError" class="mt-3 hidden rounded-xl border border-red-200 bg-red-50 px-3.5 py-3 text-xs leading-5 text-red-800" role="alert"></div>
      </div>

      <div id="eventRecipientPanel" class="mt-4 hidden">
        <p class="mb-3 text-xs leading-5 text-zinc-500">Choose an event to notify its registered participants who have an active app account. Walk-ins are not included.</p>
        <label class="block">
          <span class="mb-1.5 block text-xs font-bold text-zinc-700">Event</span>
          <select id="eventSelect" class="w-full rounded-xl border border-zinc-200 bg-white px-3.5 py-3 text-sm outline-none transition">
            <option value="">Loading accessible events…</option>
          </select>
        </label>
        <div id="eventSummary" class="mt-4 flex items-center gap-3 rounded-xl border border-sky-100 bg-sky-50 px-4 py-3" aria-live="polite">
          <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-white text-sky-700 shadow-sm"><svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M8 2v4m8-4v4M3 10h18M5 4h14a2 2 0 0 1 2 2v14H3V6a2 2 0 0 1 2-2Z"/></svg></span>
          <div class="min-w-0"><div id="eventCount" class="text-sm font-extrabold text-sky-900">Loading accessible events…</div><div id="eventDescription" class="mt-0.5 text-xs text-sky-800">Checking event participants</div></div>
        </div>
        <div id="eventAudienceError" class="mt-3 hidden rounded-xl border border-red-200 bg-red-50 px-3.5 py-3 text-xs leading-5 text-red-800" role="alert"></div>
      </div>
    </div>

    <div id="recipientList" class="divide-y divide-zinc-100" aria-live="polite">
      <div class="recipient-list-state flex items-center justify-center px-6 text-sm text-zinc-400">Loading student accounts…</div>
    </div>

    <div id="individualPagination" class="flex items-center justify-between border-t border-zinc-100 bg-zinc-50/70 px-5 py-3 sm:px-6">
      <button id="previousPageButton" type="button" class="rounded-lg border border-zinc-200 bg-white px-3 py-2 text-xs font-semibold text-zinc-600 hover:bg-zinc-50 disabled:cursor-not-allowed disabled:opacity-40">Previous</button>
      <span id="pageLabel" class="text-xs font-medium text-zinc-500">Page 1</span>
      <button id="nextPageButton" type="button" class="rounded-lg border border-zinc-200 bg-white px-3 py-2 text-xs font-semibold text-zinc-600 hover:bg-zinc-50 disabled:cursor-not-allowed disabled:opacity-40">Next</button>
    </div>
  </section>

  <section class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm sm:p-6">
    <div class="mb-5">
      <p class="text-[11px] font-bold uppercase tracking-widest text-emerald-700">Step 2</p>
      <h3 class="mt-1 text-lg font-bold text-zinc-900">Write your message</h3>
      <p class="mt-1 text-xs leading-6 text-zinc-500">The title and message appear in the selected students’ app inbox.</p>
    </div>

    <div id="sendStatus" class="mb-4 hidden rounded-xl border px-4 py-3 text-sm" role="status" aria-live="polite"></div>

    <label for="titleChoice" class="mb-1.5 block text-xs font-bold text-zinc-700">Notification title <span class="text-red-500">*</span></label>
    <select id="titleChoice" class="mb-3 w-full rounded-xl border border-zinc-200 bg-white px-3.5 py-3 text-sm outline-none transition">
      <option value="Reminder">Reminder</option>
      <option value="Schedule Update">Schedule Update</option>
      <option value="Event Update">Event Update</option>
      <option value="Important Announcement">Important Announcement</option>
      <option value="Deadline">Deadline</option>
      <option value="Other">Other</option>
    </select>
    <div id="customTitleWrap" class="mb-4 hidden">
      <label for="customTitle" class="mb-1.5 block text-xs font-bold text-zinc-700">Custom title <span class="text-red-500">*</span></label>
      <input id="customTitle" type="text" maxlength="100" placeholder="Enter a notification title" class="w-full rounded-xl border border-zinc-200 px-3.5 py-3 text-sm outline-none transition" disabled />
    </div>

    <div class="mb-1.5 flex items-center justify-between gap-3">
      <label for="messageBody" class="block text-xs font-bold text-zinc-700">Message <span class="text-red-500">*</span></label>
      <span id="bodyCount" class="text-[11px] tabular-nums text-zinc-400">0 / 500</span>
    </div>
    <textarea id="messageBody" rows="7" maxlength="500" placeholder="Type the message students should receive…" class="w-full resize-y rounded-xl border border-zinc-200 px-3.5 py-3 text-sm leading-6 outline-none transition"></textarea>

    <div class="mt-4 rounded-xl border border-sky-100 bg-sky-50 px-3.5 py-3 text-xs leading-6 text-sky-800">
      The app inbox is saved first. Phone push delivery depends on the student having a registered device and allowing notifications.
    </div>

    <button id="sendNotificationButton" type="button" class="mt-5 inline-flex w-full items-center justify-center gap-2 rounded-xl bg-orange-600 px-4 py-3.5 text-sm font-bold text-white shadow-sm transition hover:bg-orange-700 focus:outline-none disabled:cursor-not-allowed disabled:opacity-50" disabled>
      <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m22 2-7 20-4-9-9-4 20-7ZM22 2 11 13"/></svg>
      <span>Send notification</span>
    </button>
    <p class="mt-2 text-center text-[11px] text-zinc-400">Individuals are limited to 250; course and event audiences are limited to 3,000 active accounts.</p>
  </section>
</div>

<script>
(() => {
  const PAGE_SIZE = 25;
  const MAX_INDIVIDUAL_RECIPIENTS = 250;
  const selected = new Map();
  let notificationPrefill = null;
  try {
    const prefillSource = new URLSearchParams(window.location.search).get('prefill');
    if (['certificate_missing', 'evaluation_pending'].includes(prefillSource)) {
      const storedPrefill = JSON.parse(sessionStorage.getItem('pc_teacher_notification_prefill') || 'null');
      sessionStorage.removeItem('pc_teacher_notification_prefill');
      if (storedPrefill?.source === prefillSource) {
        notificationPrefill = storedPrefill;
      }
      window.history.replaceState({}, '', window.location.pathname + window.location.hash);
    }
  } catch (error) {
    notificationPrefill = null;
  }
  let mode = 'individual';
  let offset = 0;
  let currentItems = [];
  let hasMore = false;
  let searchTimer = null;
  let activeController = null;
  let loading = false;
  let sending = false;
  let audienceSnapshot = null;
  let audienceLoading = true;
  let audienceErrorText = '';
  let eventListLoading = true;
  let eventListErrorText = '';
  let eventAudienceLoading = false;
  let eventAudienceErrorText = '';
  let eventAudience = null;
  let eventAudienceController = null;

  const searchInput = document.getElementById('recipientSearch');
  const recipientList = document.getElementById('recipientList');
  const summary = document.getElementById('resultSummary');
  const pageLabel = document.getElementById('pageLabel');
  const selectedCount = document.getElementById('selectedCount');
  const sendButton = document.getElementById('sendNotificationButton');
  const sendStatus = document.getElementById('sendStatus');
  const bodyInput = document.getElementById('messageBody');
  const titleChoice = document.getElementById('titleChoice');
  const customTitle = document.getElementById('customTitle');
  const programSelect = document.getElementById('programSelect');
  const yearSelect = document.getElementById('yearSelect');
  const eventSelect = document.getElementById('eventSelect');

  function escapeHtml(value) {
    return String(value || '').replace(/[&<>"']/g, (character) => ({
      '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
    })[character]);
  }

  function fullName(student) {
    return [student.first_name, student.middle_name, student.last_name].filter(Boolean).join(' ') || 'Student';
  }

  function showStatus(message, kind) {
    sendStatus.textContent = message;
    sendStatus.className = 'mb-4 rounded-xl border px-4 py-3 text-sm ' + (kind === 'success'
      ? 'border-emerald-200 bg-emerald-50 text-emerald-800'
      : kind === 'warning'
        ? 'border-amber-200 bg-amber-50 text-amber-900'
        : 'border-red-200 bg-red-50 text-red-800');
    sendStatus.classList.remove('hidden');
  }

  function currentGroupCount() {
    if (!audienceSnapshot) return 0;
    const program = (audienceSnapshot.programs || []).find((item) => item.label === programSelect.value);
    const years = program ? program.years : audienceSnapshot.years;
    if (yearSelect.value) return Number(years?.[yearSelect.value] || 0);
    return program ? Number(program.total || 0) : Number(audienceSnapshot.total || 0);
  }

  function currentEventCount() {
    return eventAudience && eventAudience.event_id === eventSelect.value
      ? Number(eventAudience.count || 0)
      : 0;
  }

  function groupLabel() {
    const program = programSelect.value || 'All programs';
    const year = yearSelect.value ? ` · ${yearSelect.options[yearSelect.selectedIndex].text}` : '';
    return `${program}${year}`;
  }

  function updateGroupUi() {
    const count = currentGroupCount();
    document.getElementById('groupCount').textContent = audienceLoading
      ? 'Loading active roster…'
      : `${count.toLocaleString()} active student account${count === 1 ? '' : 's'}`;
    document.getElementById('groupDescription').textContent = audienceLoading
      ? 'Checking active student accounts'
      : audienceErrorText
        ? 'Group delivery is unavailable; individual selection can still be used.'
        : count > 3000
          ? 'Narrow the audience to 3,000 or fewer active accounts.'
          : count === 0
            ? 'No active roster-linked accounts match this group.'
            : `Audience: ${groupLabel()}`;
    const error = document.getElementById('audienceError');
    error.textContent = audienceErrorText;
    error.classList.toggle('hidden', !audienceErrorText);
    document.getElementById('groupSummary').classList.toggle('border-red-200', Boolean(audienceErrorText));
    document.getElementById('groupSummary').classList.toggle('bg-red-50', Boolean(audienceErrorText));
    document.getElementById('groupSummary').classList.toggle('border-emerald-100', !audienceErrorText);
    document.getElementById('groupSummary').classList.toggle('bg-emerald-50', !audienceErrorText);
  }

  function updateEventUi() {
    const count = currentEventCount();
    document.getElementById('eventCount').textContent = eventListLoading
      ? 'Loading accessible events…'
      : eventAudienceLoading
        ? 'Checking registered participants…'
        : eventListErrorText
          ? 'Event list unavailable'
          : eventAudienceErrorText
            ? 'Could not verify event audience'
            : `${count.toLocaleString()} active app participant${count === 1 ? '' : 's'}`;
    const selectedTitle = eventAudience && eventAudience.event_id === eventSelect.value
      ? eventAudience.event_title
      : '';
    document.getElementById('eventDescription').textContent = eventAudienceLoading
      ? 'Checking event registrations'
      : eventListErrorText || eventAudienceErrorText
        ? 'Try refreshing the page or use another recipient mode.'
        : !eventSelect.value
          ? 'Select an event to preview its recipients.'
          : count > 3000
            ? 'This event is above the 3,000-recipient safety limit.'
          : count === 0
            ? 'No active app accounts are registered for this event.'
            : `Event: ${selectedTitle || eventSelect.options[eventSelect.selectedIndex]?.text || 'Selected event'}`;
    const error = document.getElementById('eventAudienceError');
    error.textContent = eventAudienceErrorText || eventListErrorText;
    error.classList.toggle('hidden', !eventAudienceErrorText && !eventListErrorText);
    eventSelect.disabled = sending || eventListLoading || Boolean(eventListErrorText) || eventSelect.options.length <= 1;
  }

  function updateSelectionUi() {
    const groupCount = currentGroupCount();
    selectedCount.textContent = mode === 'group'
      ? `${groupCount.toLocaleString()} in group`
      : mode === 'event'
        ? `${currentEventCount().toLocaleString()} in event`
        : `${selected.size} selected`;
    document.getElementById('clearSelectionButton').disabled = selected.size === 0 || sending;
    document.getElementById('selectPageButton').disabled = loading || currentItems.length === 0 || sending;
    document.getElementById('previousPageButton').disabled = loading || offset <= 0 || sending;
    document.getElementById('nextPageButton').disabled = loading || !hasMore || sending;
    const titleValid = titleChoice.value !== 'Other' || customTitle.value.trim().length > 0;
    const recipientsValid = mode === 'individual'
      ? selected.size > 0
      : mode === 'group'
        ? (!audienceLoading && !audienceErrorText && groupCount > 0 && groupCount <= 3000)
        : (!eventListLoading && !eventAudienceLoading && !eventListErrorText && !eventAudienceErrorText && Boolean(eventSelect.value) && currentEventCount() > 0 && currentEventCount() <= 3000);
    sendButton.disabled = sending || !recipientsValid || !titleValid || bodyInput.value.trim() === '';
    const preview = document.getElementById('selectedPreview');
    if (preview) {
      const first = Array.from(selected.entries()).slice(0, 4);
      preview.classList.toggle('hidden', mode !== 'individual' || selected.size === 0);
      preview.innerHTML = first.map(([id, student]) => `
        <span class="inline-flex max-w-full items-center gap-1.5 rounded-full border border-orange-100 bg-white px-2.5 py-1 text-[11px] font-medium text-zinc-700">
          <span class="max-w-full truncate">${escapeHtml(fullName(student))}</span>
          <button type="button" data-remove-selected="${escapeHtml(id)}" class="rounded-full px-0.5 text-zinc-400 hover:text-red-600" aria-label="Remove ${escapeHtml(fullName(student))}">&times;</button>
        </span>`).join('') + (selected.size > first.length ? `<span class="my-auto text-[11px] text-zinc-500">+${selected.size - first.length} more</span>` : '');
    }
    updateGroupUi();
    updateEventUi();
  }

  function renderRecipients() {
    if (currentItems.length === 0) {
      recipientList.innerHTML = '<div class="recipient-list-state flex flex-col items-center justify-center px-6 text-center"><div class="mb-3 flex h-12 w-12 items-center justify-center rounded-2xl bg-zinc-100 text-zinc-400"><svg class="h-6 w-6" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><circle cx="9" cy="7" r="4"/><path stroke-linecap="round" d="M3 21v-1a6 6 0 0 1 12 0v1m3-11v6m3-3h-6"/></svg></div><p class="text-sm font-semibold text-zinc-700">No students found</p><p class="mt-1 text-xs text-zinc-400">Try another name, student number, email, or course.</p></div>';
      return;
    }

    recipientList.innerHTML = currentItems.map((student) => {
      const id = String(student.id || '');
      const name = fullName(student);
      const detail = [student.student_id, student.course, student.email].filter(Boolean).join(' · ') || 'Student account';
      return `<label class="flex cursor-pointer items-center gap-3 px-5 py-3.5 transition hover:bg-orange-50/50 sm:px-6">
        <input type="checkbox" data-recipient-checkbox value="${escapeHtml(id)}" class="h-4 w-4 shrink-0 rounded border-zinc-300 text-orange-600 focus:ring-orange-500" ${selected.has(id) ? 'checked' : ''} ${sending ? 'disabled' : ''} />
        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-gradient-to-br from-orange-100 to-amber-50 text-xs font-bold text-orange-700">${escapeHtml(name.split(/\s+/).slice(0, 2).map((part) => part[0] || '').join('').toUpperCase())}</span>
        <span class="min-w-0 flex-1"><span class="block truncate text-sm font-semibold text-zinc-800">${escapeHtml(name)}</span><span class="mt-0.5 block truncate text-xs text-zinc-500">${escapeHtml(detail)}</span></span>
        ${selected.has(id) ? '<span class="rounded-full bg-emerald-50 px-2 py-1 text-[10px] font-bold text-emerald-700">Selected</span>' : ''}
      </label>`;
    }).join('');
  }

  async function loadRecipients() {
    if (activeController) activeController.abort();
    activeController = new AbortController();
    loading = true;
    document.getElementById('selectPageButton').disabled = true;
    recipientList.innerHTML = '<div class="recipient-list-state flex items-center justify-center gap-3 px-6 text-sm text-zinc-400"><span class="notification-spinner h-5 w-5 animate-spin rounded-full border-2 border-orange-200"></span>Loading student accounts…</div>';
    summary.textContent = 'Loading student accounts…';
    try {
      const params = new URLSearchParams({ search: searchInput.value.trim(), limit: String(PAGE_SIZE), offset: String(offset) });
      const response = await fetch('/api/teacher_notification_users.php?' + params.toString(), {
        credentials: 'same-origin', cache: 'no-store', signal: activeController.signal
      });
      const data = await response.json().catch(() => ({}));
      if (!response.ok || !data.ok) throw new Error(data.error || 'Could not load student accounts.');
      currentItems = Array.isArray(data.items) ? data.items : [];
      currentItems.forEach((student) => {
        const id = String(student.id || '');
        if (selected.has(id)) selected.set(id, student);
      });
      hasMore = Boolean(data.has_more);
      renderRecipients();
      summary.textContent = currentItems.length
        ? `Showing ${offset + 1}–${offset + currentItems.length}${hasMore ? '+' : ''} · selection is kept while you search or change pages`
        : 'No matching active student accounts';
      pageLabel.textContent = `Page ${Math.floor(offset / PAGE_SIZE) + 1}`;
      document.getElementById('previousPageButton').disabled = offset <= 0;
      document.getElementById('nextPageButton').disabled = !hasMore;
    } catch (error) {
      if (error.name !== 'AbortError') {
        currentItems = [];
        hasMore = false;
        recipientList.innerHTML = `<div class="recipient-list-state flex items-center justify-center px-6 text-center text-sm text-red-600">${escapeHtml(error.message || 'Could not load student accounts.')}</div>`;
        summary.textContent = 'Student list unavailable';
      }
    } finally {
      loading = false;
      updateSelectionUi();
    }
  }

  async function loadAudiences() {
    audienceLoading = true;
    updateSelectionUi();
    try {
      const response = await fetch('/api/teacher_notification_users.php?action=audiences', {
        credentials: 'same-origin', cache: 'no-store'
      });
      const data = await response.json().catch(() => ({}));
      if (!response.ok || !data.ok) throw new Error(data.error || 'Could not load course/year audience counts.');
      audienceSnapshot = data;
      audienceErrorText = '';
      programSelect.innerHTML = '<option value="">All programs</option>' + (data.programs || []).map((program) =>
        `<option value="${escapeHtml(program.label)}">${escapeHtml(program.label)} (${Number(program.total || 0).toLocaleString()})</option>`
      ).join('');
    } catch (error) {
      audienceSnapshot = null;
      audienceErrorText = error.message || 'Could not load course/year audience counts.';
    } finally {
      audienceLoading = false;
      updateSelectionUi();
    }
  }

  async function loadEvents() {
    eventListLoading = true;
    eventListErrorText = '';
    updateSelectionUi();
    try {
      const response = await fetch('/api/teacher_notification_users.php?action=events', {
        credentials: 'same-origin', cache: 'no-store'
      });
      const data = await response.json().catch(() => ({}));
      if (!response.ok || !data.ok) throw new Error(data.error || 'Could not load accessible events.');
      const events = Array.isArray(data.events) ? data.events : [];
      eventSelect.innerHTML = '<option value="">Select an event</option>' + events.map((event) => {
        const date = event.start_at ? new Date(event.start_at).toLocaleDateString() : '';
        const label = `${event.title}${date ? ` · ${date}` : ''}`;
        return `<option value="${escapeHtml(event.id)}">${escapeHtml(label)}</option>`;
      }).join('');
      if (events.length === 0) eventListErrorText = 'No accessible events were found.';
    } catch (error) {
      eventListErrorText = error.message || 'Could not load accessible events.';
      eventSelect.innerHTML = '<option value="">Events unavailable</option>';
    } finally {
      eventListLoading = false;
      updateSelectionUi();
    }
  }

  async function loadEventAudience() {
    if (eventAudienceController) eventAudienceController.abort();
    const eventId = eventSelect.value;
    eventAudience = null;
    eventAudienceErrorText = '';
    if (!eventId) {
      eventAudienceLoading = false;
      updateSelectionUi();
      return;
    }
    const controller = new AbortController();
    eventAudienceController = controller;
    eventAudienceLoading = true;
    updateSelectionUi();
    try {
      const params = new URLSearchParams({ action: 'event_audience', event_id: eventId });
      const response = await fetch('/api/teacher_notification_users.php?' + params.toString(), {
        credentials: 'same-origin', cache: 'no-store', signal: controller.signal
      });
      const data = await response.json().catch(() => ({}));
      if (!response.ok || !data.ok) throw new Error(data.error || 'Could not verify event participants.');
      if (eventSelect.value !== eventId) return;
      eventAudience = { event_id: eventId, event_title: data.event_title, count: Number(data.count || 0) };
    } catch (error) {
      if (error.name !== 'AbortError' && eventSelect.value === eventId) {
        eventAudienceErrorText = error.message || 'Could not verify event participants.';
      }
    } finally {
      if (eventSelect.value === eventId) {
        eventAudienceLoading = false;
        updateSelectionUi();
      }
    }
  }

  document.querySelectorAll('.recipient-mode').forEach((button) => {
    button.addEventListener('click', () => {
      if (sending) return;
      mode = ['group', 'event'].includes(button.dataset.mode) ? button.dataset.mode : 'individual';
      document.querySelectorAll('.recipient-mode').forEach((item) => item.setAttribute('aria-pressed', String(item === button)));
      document.getElementById('individualRecipientPanel').classList.toggle('hidden', mode !== 'individual');
      document.getElementById('recipientList').classList.toggle('hidden', mode !== 'individual');
      document.getElementById('individualPagination').classList.toggle('hidden', mode !== 'individual');
      document.getElementById('groupRecipientPanel').classList.toggle('hidden', mode !== 'group');
      document.getElementById('eventRecipientPanel').classList.toggle('hidden', mode !== 'event');
      updateSelectionUi();
    });
  });

  searchInput.addEventListener('input', () => {
    window.clearTimeout(searchTimer);
    searchTimer = window.setTimeout(() => { offset = 0; loadRecipients(); }, 280);
  });
  document.getElementById('previousPageButton').addEventListener('click', () => {
    if (offset <= 0 || loading || sending) return;
    offset = Math.max(0, offset - PAGE_SIZE);
    loadRecipients();
  });
  document.getElementById('nextPageButton').addEventListener('click', () => {
    if (!hasMore || loading || sending) return;
    offset += PAGE_SIZE;
    loadRecipients();
  });
  document.getElementById('selectPageButton').addEventListener('click', () => {
    const added = currentItems.filter((student) => !selected.has(String(student.id))).length;
    if (selected.size + added > MAX_INDIVIDUAL_RECIPIENTS) {
      showStatus(`You can select up to ${MAX_INDIVIDUAL_RECIPIENTS} students in individual mode.`, 'warning');
      return;
    }
    currentItems.forEach((student) => selected.set(String(student.id), student));
    renderRecipients();
    updateSelectionUi();
  });
  document.getElementById('clearSelectionButton').addEventListener('click', () => {
    selected.clear();
    renderRecipients();
    updateSelectionUi();
  });
  recipientList.addEventListener('change', (event) => {
    const checkbox = event.target.closest('[data-recipient-checkbox]');
    if (!checkbox) return;
    const id = String(checkbox.value || '');
    const student = currentItems.find((item) => String(item.id) === id);
    if (checkbox.checked && !selected.has(id)) {
      if (selected.size >= MAX_INDIVIDUAL_RECIPIENTS) {
        checkbox.checked = false;
        showStatus(`You can select up to ${MAX_INDIVIDUAL_RECIPIENTS} students in individual mode.`, 'warning');
        return;
      }
      if (student) selected.set(id, student);
    } else if (!checkbox.checked) {
      selected.delete(id);
    }
    renderRecipients();
    updateSelectionUi();
  });
  document.getElementById('selectedPreview').addEventListener('click', (event) => {
    const button = event.target.closest('[data-remove-selected]');
    if (!button) return;
    selected.delete(String(button.dataset.removeSelected || ''));
    renderRecipients();
    updateSelectionUi();
  });

  programSelect.addEventListener('change', updateSelectionUi);
  yearSelect.addEventListener('change', updateSelectionUi);
  eventSelect.addEventListener('change', loadEventAudience);
  titleChoice.addEventListener('change', () => {
    const isOther = titleChoice.value === 'Other';
    document.getElementById('customTitleWrap').classList.toggle('hidden', !isOther);
    customTitle.disabled = !isOther || sending;
    customTitle.required = isOther;
    updateSelectionUi();
    if (isOther) customTitle.focus();
  });
  customTitle.addEventListener('input', updateSelectionUi);
  bodyInput.addEventListener('input', () => {
    document.getElementById('bodyCount').textContent = `${bodyInput.value.length} / 500`;
    updateSelectionUi();
  });

  sendButton.addEventListener('click', async () => {
    const title = titleChoice.value === 'Other' ? customTitle.value.trim() : titleChoice.value;
    const body = bodyInput.value.trim();
    const targets = mode === 'group' ? currentGroupCount() : mode === 'event' ? currentEventCount() : selected.size;
    if (!title || !body || targets === 0) {
      showStatus('Choose recipients, select a title, and write a message before sending.', 'warning');
      return;
    }
    const audience = mode === 'group'
      ? groupLabel()
      : mode === 'event'
        ? `event “${eventAudience?.event_title || 'Selected event'}”`
        : `${selected.size} individually selected student account(s)`;
    if (!window.confirm(`Send “${title}” to ${targets.toLocaleString()} student account(s) in ${audience}? It cannot be recalled.`)) return;

    sending = true;
    sendButton.disabled = true;
    sendButton.querySelector('span').textContent = 'Sending…';
    titleChoice.disabled = true;
    customTitle.disabled = true;
    bodyInput.disabled = true;
    programSelect.disabled = true;
    yearSelect.disabled = true;
    eventSelect.disabled = true;
    document.querySelectorAll('.recipient-mode').forEach((button) => { button.disabled = true; });
    renderRecipients();
    try {
      const payload = {
        csrf_token: window.CSRF_TOKEN || '',
        mode,
        title_choice: titleChoice.value,
        custom_title: customTitle.value.trim(),
        body,
      };
      if (mode === 'individual') {
        payload.recipient_ids = Array.from(selected.keys());
      } else if (mode === 'group') {
        payload.program_label = programSelect.value;
        payload.year_level = yearSelect.value;
        payload.expected_targets = targets;
      } else {
        payload.event_id = eventSelect.value;
        payload.expected_targets = targets;
      }
      const response = await fetch('/api/teacher_notification_send.php', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload),
      });
      const data = await response.json().catch(() => ({}));
      if (!response.ok || !data.ok) {
        if (response.status === 409 && mode === 'group') loadAudiences();
        if (response.status === 409 && mode === 'event') loadEventAudience();
        throw new Error(data.error || 'Notification could not be sent.');
      }

      const inboxCount = Number(data.in_app_saved || 0);
      const pushSent = Number(data.push_sent || 0);
      const pushFailed = Number(data.push_failed || 0);
      const targetLabel = `${inboxCount} student(s)`;
      if (data.push_lookup_partial && Number(data.device_tokens || 0) === 0) {
        showStatus(`Saved to the app inbox for ${targetLabel}, but the server could not check registered push devices.`, 'warning');
      } else if (data.push_skipped || Number(data.device_tokens || 0) === 0) {
        showStatus(`Saved to the app inbox for ${targetLabel}. No registered push devices were found, so there was no phone alert.`, 'success');
      } else if (pushFailed > 0 || data.push_lookup_partial || data.push_error) {
        showStatus(`Saved to the app inbox for ${targetLabel}. Push requests were accepted for ${pushSent} device(s); ${pushFailed} failed or could not be confirmed.`, 'warning');
      } else {
        showStatus(`Sent to ${data.audience || audience}: saved in the app inbox for ${targetLabel}, with push requests accepted for ${pushSent} device(s).`, 'success');
      }
      selected.clear();
      bodyInput.value = '';
      document.getElementById('bodyCount').textContent = '0 / 500';
      titleChoice.value = 'Reminder';
      customTitle.value = '';
      document.getElementById('customTitleWrap').classList.add('hidden');
      customTitle.required = false;
    } catch (error) {
      showStatus(error.message || 'Notification could not be sent.', 'error');
    } finally {
      sending = false;
      sendButton.querySelector('span').textContent = 'Send notification';
      titleChoice.disabled = false;
      customTitle.disabled = titleChoice.value !== 'Other';
      bodyInput.disabled = false;
      programSelect.disabled = false;
      yearSelect.disabled = false;
      eventSelect.disabled = false;
      document.querySelectorAll('.recipient-mode').forEach((button) => { button.disabled = false; });
      renderRecipients();
      updateSelectionUi();
    }
  });

  if (['certificate_missing', 'evaluation_pending'].includes(notificationPrefill?.source) && Array.isArray(notificationPrefill.recipients)) {
    const recipients = notificationPrefill.recipients
      .filter((recipient) => recipient && /^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i.test(String(recipient.id || '')))
      .filter((recipient, index, list) => list.findIndex((item) => item.id === recipient.id) === index);
    if (recipients.length > 0 && recipients.length <= MAX_INDIVIDUAL_RECIPIENTS) {
      recipients.forEach((recipient) => {
        selected.set(String(recipient.id), {
          id: String(recipient.id),
          first_name: String(recipient.name || 'Student'),
          middle_name: '',
          last_name: '',
        });
      });
      const selectionDescription = notificationPrefill.source === 'evaluation_pending'
        ? `${recipients.length} attendees who have not completed the event evaluation are preselected`
        : `${recipients.length} students missing a certificate are preselected`;
      showStatus(`${selectionDescription}. Review the recipients, then write and send your message.`, 'success');
    } else if (recipients.length > MAX_INDIVIDUAL_RECIPIENTS) {
      const recipientType = notificationPrefill.source === 'evaluation_pending' ? 'pending event evaluation attendees' : 'missing students';
      showStatus(`There are ${recipients.length} ${recipientType}. Individual notifications are limited to ${MAX_INDIVIDUAL_RECIPIENTS} recipients, so no one was preselected.`, 'warning');
    }
  }

  loadRecipients();
  loadAudiences();
  loadEvents();
})();
</script>

<?php render_footer(); ?>
