<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/session.php';
session_bootstrap();

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/supabase.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/event_sessions.php';
require_once __DIR__ . '/includes/event_tabs.php';
require_once __DIR__ . '/includes/registration_access.php';
require_once __DIR__ . '/includes/student_requirements.php';
require_once __DIR__ . '/includes/evaluation_feedback_lib.php';
require_once __DIR__ . '/includes/evaluation_delivery.php';

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

$eventUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/events?select=id,title,status,created_by,is_free_event&id=eq.' . rawurlencode($eventId) . '&limit=1';
$eventRes = supabase_request('GET', $eventUrl, $headers);
$eventRows = $eventRes['ok'] ? json_decode((string) $eventRes['body'], true) : [];
$event = is_array($eventRows) && isset($eventRows[0]) ? $eventRows[0] : null;

if (!is_array($event)) {
    http_response_code(404);
    echo 'Event not found';
    exit;
}

$isEventCreator = (string) ($event['created_by'] ?? '') === $userId;
$canManageEvaluationDelivery = $role === 'admin' || $isEventCreator;
$canSendTeacherNotifications = $role === 'teacher' && $isEventCreator;

// Questions tab is teacher-creator only — remove from admin.
if ($tab === 'questions') {
    if ($role !== 'teacher' || !$isEventCreator) {
        http_response_code(403);
        echo 'Forbidden';
        exit;
    }
} elseif ($role === 'teacher' && !$isEventCreator) {
    http_response_code(403);
    echo 'Forbidden';
    exit;
}

$sessions = fetch_event_sessions($eventId, $headers);
$usesSessions = count($sessions) > 0;
$isFinishedEvent = strtolower(trim((string) ($event['status'] ?? ''))) === 'finished';
if ($isFinishedEvent && $tab === 'questions') {
    header('Location: /evaluation_admin?event_id=' . rawurlencode($eventId) . '&tab=feedback');
    exit;
}

$statusColor = match((string) ($event['status'] ?? '')) {
    'published' => 'bg-emerald-100 text-emerald-900 border-emerald-200',
    'finished' => 'bg-zinc-200 text-zinc-700 border-zinc-300',
    'pending' => 'bg-amber-100 text-amber-900 border-amber-200',
    'approved' => 'bg-sky-100 text-sky-900 border-sky-200',
    default => 'bg-zinc-100 text-zinc-800 border-zinc-200',
};

$eventQuestions = [];
$eventQuestionGroups = [];
$sessionQuestions = [];
$sessionQuestionGroups = [];
$sessionQuestionCounts = [];
$feedbackSections = [];

$eventQuestionsUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/evaluation_questions'
    . '?select=id,event_id,question_text,field_type,required,sort_order'
    . '&event_id=eq.' . rawurlencode($eventId)
    . '&order=sort_order.asc';
$eventQuestionsRes = supabase_request('GET', $eventQuestionsUrl, $headers);
$eventQuestionRows = $eventQuestionsRes['ok'] ? json_decode((string) $eventQuestionsRes['body'], true) : [];
$eventQuestions = is_array($eventQuestionRows) ? $eventQuestionRows : [];
$eventQuestions = evaluation_sort_questions_by_type($eventQuestions);
foreach ($eventQuestions as $question) {
    $isRating = strtolower(trim((string) ($question['field_type'] ?? 'text'))) === 'rating';
    $groupKey = $isRating ? 'Rating (1–5)' : 'Comment / Text';
    if (!isset($eventQuestionGroups[$groupKey])) {
        $eventQuestionGroups[$groupKey] = [];
    }
    $eventQuestionGroups[$groupKey][] = $question;
}

// Persist rating-then-comment order so mobile/web forms stay grouped.
if ($tab === 'questions' && count($eventQuestions) > 0) {
    evaluation_renormalize_question_sort_orders(
        'evaluation_questions',
        'event_id',
        $eventId,
        $headers
    );
}

if ($usesSessions) {
    $sessionIds = [];
    foreach ($sessions as $session) {
        $sid = (string) ($session['id'] ?? '');
        if ($sid !== '') {
            $sessionIds[] = $sid;
        }
    }

    if (count($sessionIds) > 0) {
        $sessionIdList = implode(',', array_map('rawurlencode', $sessionIds));
        $sessionQuestionsUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/event_session_evaluation_questions'
            . '?select=id,session_id,question_text,field_type,required,sort_order'
            . '&session_id=in.(' . $sessionIdList . ')'
            . '&order=sort_order.asc';
        $sessionQuestionsRes = supabase_request('GET', $sessionQuestionsUrl, $headers);
        $sessionQuestionRows = [];
        if ($sessionQuestionsRes['ok']) {
            $sessionQuestionRows = json_decode((string) $sessionQuestionsRes['body'], true);
        } elseif (!feedback_missing_table($sessionQuestionsRes, 'event_session_evaluation_questions')) {
            $sessionQuestionRows = [];
        }
        $sessionQuestions = is_array($sessionQuestionRows) ? $sessionQuestionRows : [];
    }

    $sessionQuestions = evaluation_sort_questions_by_type($sessionQuestions);
    foreach ($sessionQuestions as $question) {
        $sid = (string) ($question['session_id'] ?? '');
        if ($sid === '') {
            continue;
        }
        if (!isset($sessionQuestionGroups[$sid])) {
            $sessionQuestionGroups[$sid] = [];
        }
        $isRating = strtolower(trim((string) ($question['field_type'] ?? 'text'))) === 'rating';
        $groupKey = $isRating ? 'Rating (1–5)' : 'Comment / Text';
        if (!isset($sessionQuestionGroups[$sid][$groupKey])) {
            $sessionQuestionGroups[$sid][$groupKey] = [];
        }
        $sessionQuestionGroups[$sid][$groupKey][] = $question;
    }

    if ($tab === 'questions') {
        $renormSessions = [];
        foreach ($sessionQuestions as $question) {
            $sid = trim((string) ($question['session_id'] ?? ''));
            if ($sid !== '') {
                $renormSessions[$sid] = true;
            }
        }
        foreach (array_keys($renormSessions) as $sid) {
            evaluation_renormalize_question_sort_orders(
                'event_session_evaluation_questions',
                'session_id',
                (string) $sid,
                $headers
            );
        }
    }

    foreach ($sessions as $session) {
        $sid = (string) ($session['id'] ?? '');
        $sessionQuestionCounts[$sid] = isset($sessionQuestionGroups[$sid])
            ? array_reduce($sessionQuestionGroups[$sid], fn ($carry, $items) => $carry + count($items), 0)
            : 0;
    }
}

if ($tab === 'feedback') {
    $feedbackSections = evaluation_feedback_load_sections(
        $eventId,
        $headers,
        $sessions,
        $usesSessions,
        $eventQuestions,
        $sessionQuestionGroups
    );
}

$pendingEvaluationRecipients = [];
$pendingEvaluationEligibilityVerified = false;
$eventFeedbackSection = $feedbackSections[0] ?? [];
if ($tab === 'feedback' && !empty($eventFeedbackSection['has_questions'])) {
    $eligibility = evaluation_delivery_eligible_students($eventId, $event, $headers);
    if (!empty($eligibility['ok'])) {
        $eligibleIdSet = array_fill_keys(
            array_map('strval', $eligibility['student_ids'] ?? []),
            true
        );
        foreach (($eventFeedbackSection['pending_recipients'] ?? []) as $pendingRecipient) {
            if (!is_array($pendingRecipient)) {
                continue;
            }
            $pendingId = trim((string) ($pendingRecipient['id'] ?? ''));
            if ($pendingId === '' || !isset($eligibleIdSet[$pendingId])) {
                continue;
            }
            $pendingEvaluationRecipients[$pendingId] = [
                'id' => $pendingId,
                'name' => (string) ($pendingRecipient['name'] ?? $pendingId),
            ];
        }
        $pendingEvaluationRecipients = array_values($pendingEvaluationRecipients);
        $pendingEvaluationEligibilityVerified = true;
    }
}
$pendingEvaluationRecipientsJson = count($pendingEvaluationRecipients) <= 250
    ? json_encode(
        $pendingEvaluationRecipients,
        JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE
    )
    : '[]';

render_header('Evaluation Management', $user);
?>

<div class="mb-4">
  <div class="flex items-center justify-between flex-wrap gap-4 pb-4 border-b border-zinc-200 mb-6">
    <div class="flex items-center gap-3">
      <?= render_event_back_button(event_management_return_to($role, isset($_GET['return_to']) ? (string) $_GET['return_to'] : null)) ?>
      <h2 class="text-xl md:text-2xl font-bold text-zinc-900"><?= htmlspecialchars((string) ($event['title'] ?? '')) ?></h2>
      <span class="text-[10px] sm:text-xs font-bold uppercase tracking-widest rounded-md border px-2 py-0.5 <?= $statusColor ?>"><?= htmlspecialchars((string) ($event['status'] ?? '')) ?></span>
    </div>
    <?php if ($tab === 'feedback'): ?>
      <div class="flex items-center gap-2 flex-wrap">
        <?php if ($canManageEvaluationDelivery): ?>
          <button type="button" id="btnEvalDeliveryStatus"
            class="inline-flex items-center justify-center gap-2 rounded-xl border border-sky-200 bg-sky-50 px-4 py-2.5 text-[13px] font-bold text-sky-800 shadow-sm hover:bg-sky-100 transition-colors whitespace-nowrap">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4.5-5.82V5a1.5 1.5 0 00-3 0v.18A6.002 6.002 0 006 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/></svg>
            Evaluation Delivery
          </button>
        <?php endif; ?>
        <?php if ($canSendTeacherNotifications && $pendingEvaluationEligibilityVerified && count($pendingEvaluationRecipients) > 0): ?>
          <button type="button" id="btnNotifyEvaluationPending"
            class="js-notify-evaluation-pending inline-flex items-center justify-center gap-2 rounded-xl border border-amber-200 bg-amber-50 px-4 py-2.5 text-[13px] font-bold text-amber-900 shadow-sm hover:bg-amber-100 transition-colors whitespace-nowrap disabled:cursor-not-allowed disabled:opacity-60"
            data-event-id="<?= htmlspecialchars($eventId, ENT_QUOTES, 'UTF-8') ?>"
            data-section-label="the event evaluation"
            data-recipients="<?= htmlspecialchars(is_string($pendingEvaluationRecipientsJson) ? $pendingEvaluationRecipientsJson : '[]', ENT_QUOTES, 'UTF-8') ?>"
            <?= count($pendingEvaluationRecipients) > 250 ? 'disabled title="Individual notifications are limited to 250 recipients."' : '' ?>>
            <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4.5-5.82V5a1.5 1.5 0 00-3 0v.18A6.002 6.002 0 006 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/></svg>
            Notify pending (<?= count($pendingEvaluationRecipients) ?>)
          </button>
          <?php if (count($pendingEvaluationRecipients) > 250): ?>
            <span class="text-xs text-amber-800">Individual notifications are limited to 250 recipients.</span>
          <?php endif; ?>
        <?php elseif ($canSendTeacherNotifications && !empty($eventFeedbackSection['has_questions']) && (int) ($eventFeedbackSection['pending_participants'] ?? 0) > 0 && !$pendingEvaluationEligibilityVerified): ?>
          <span class="text-xs text-amber-800">Could not verify completed event time-outs; pending recipients are unavailable.</span>
        <?php elseif ($canSendTeacherNotifications && $pendingEvaluationEligibilityVerified && !empty($eventFeedbackSection['has_questions']) && (int) ($eventFeedbackSection['pending_participants'] ?? 0) > 0): ?>
          <span class="text-xs text-zinc-500">No attendees with a completed event time-out are still pending.</span>
        <?php endif; ?>
        <button type="button" id="btn-export-feedback-pdf"
          data-export-url="/evaluation_feedback_export.php?event_id=<?= rawurlencode($eventId) ?>&print=1"
          class="inline-flex items-center justify-center gap-2 rounded-xl border border-orange-200 bg-orange-50 px-4 py-2.5 text-[13px] font-bold text-orange-800 shadow-sm hover:bg-orange-100 hover:border-orange-300 transition-colors whitespace-nowrap disabled:opacity-60 disabled:cursor-not-allowed">
          <svg class="w-4 h-4 shrink-0 export-pdf-icon" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m6.75 12H9m1.5-12H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z"/></svg>
          <span class="export-pdf-label">Export as PDF</span>
        </button>
      </div>
    <?php endif; ?>
  </div>

  <?php
  render_event_tabs([
      'event_id' => $eventId,
      'current_tab' => $tab === 'questions' ? 'questions' : 'feedback',
      'role' => $role,
      'uses_sessions' => $usesSessions,
      'event_status' => (string) ($event['status'] ?? ''),
      'return_to' => event_management_return_to($role, isset($_GET['return_to']) ? (string) $_GET['return_to'] : null),
      'has_student_requirements' => event_has_student_requirements($eventId, $headers),
      'is_event_creator' => $role === 'admin' || ((string) ($event['created_by'] ?? '') === (string) ($user['id'] ?? '')),
      'is_paid_event' => !event_is_free_registration_event($event),
  ]);
  ?>

  <?php if ($tab === 'questions'): ?>
    <div class="max-w-4xl mx-auto">
      <div class="flex items-center justify-between mb-8">
        <div>
          <h3 class="text-xl font-bold text-zinc-900 tracking-tight">Evaluation Questions</h3>
          <p class="text-zinc-500 text-sm mt-1">
            <?= $usesSessions ? 'Add general questions for the whole event, then add seminar-specific questions below.' : 'Questions are scoped to this event.' ?>
          </p>
        </div>
      </div>

      <div id="questionsContainer" class="space-y-6 mb-6">
        <div class="rounded-3xl border border-zinc-200 bg-white p-5 shadow-sm">
          <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4 mb-5">
            <div>
              <div class="text-[11px] font-black uppercase tracking-widest text-emerald-600">Evaluation Questions</div>
              <div class="text-lg font-bold text-zinc-900">Event Questions</div>
              <p class="text-xs text-zinc-500 mt-1">General questions not tied to a specific seminar.</p>
            </div>
            <button type="button" class="btnShowAdd inline-flex items-center gap-2 rounded-full border border-emerald-200 bg-emerald-50 px-4 py-2 text-xs font-bold text-emerald-700 hover:bg-emerald-100 transition" data-target="event">
              <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/></svg>
              Add Question
            </button>
          </div>

          <div class="newQuestionCard hidden relative bg-emerald-50/50 rounded-3xl border-2 border-emerald-500/30 p-6 shadow-sm mb-4" data-target="event">
            <form class="qForm flex flex-col md:flex-row gap-5 items-start" data-session-id="">
              <input type="hidden" name="event_id" value="<?= htmlspecialchars($eventId) ?>" />
              <input type="hidden" name="session_id" value="" />
              <input type="hidden" name="required" value="true" />
              <input type="hidden" name="sort_order" value="<?= count($eventQuestions) + 1 ?>" />

              <div class="flex-1 w-full space-y-4">
                <div>
                  <input type="text" name="question_text" required class="w-full text-lg font-bold text-emerald-900 border-none bg-transparent placeholder-emerald-400 outline-none focus:ring-0 px-0" placeholder="Type your new question here..." />
                  <div class="h-px bg-emerald-200 w-full mt-1"></div>
                </div>
                <div class="flex items-center gap-3">
                  <span class="text-[11px] font-black uppercase tracking-widest text-emerald-600">Select Type:</span>
                  <select name="field_type" class="px-3 py-1.5 rounded-lg bg-white border border-emerald-200 text-sm font-bold text-emerald-800 outline-none focus:ring-2 focus:ring-emerald-500/30">
                    <option value="rating">Likert (1-5 Scale)</option>
                    <option value="text">Comment / Text</option>
                  </select>
                </div>
                <div class="qMsg text-sm font-bold text-emerald-600 hidden">Saving...</div>
              </div>

              <div class="flex items-center gap-4 shrink-0 md:border-l md:border-emerald-200 md:pl-6 h-full mt-auto">
                <button type="button" class="btnCancelAdd py-2.5 px-4 text-sm font-bold text-zinc-500 hover:text-zinc-800 transition-colors">Cancel</button>
                <button type="submit" class="py-2.5 px-6 bg-emerald-600 hover:bg-emerald-700 text-white text-sm font-bold rounded-xl shadow-lg shadow-emerald-600/20 transition-all">
                  Save Question
                </button>
              </div>
            </form>
          </div>

          <?php if (count($eventQuestions) === 0): ?>
            <div class="text-center py-10 rounded-2xl border-2 border-dashed border-zinc-200 bg-zinc-50">
              <p class="text-sm font-semibold text-zinc-500">No event-level questions yet.</p>
              <p class="text-xs text-zinc-400 mt-1">Add a question that applies to the whole event.</p>
            </div>
          <?php endif; ?>

          <?php foreach ($eventQuestionGroups as $groupLabel => $groupQuestions): ?>
            <div class="rounded-3xl border border-zinc-200 bg-zinc-50/70 p-4 mb-4">
              <div class="mb-4 flex items-center justify-between gap-3">
                <div class="rounded-full bg-white px-3 py-1 text-xs font-bold text-zinc-700 border border-zinc-200">
                  <?= htmlspecialchars((string) $groupLabel) ?>
                  <span class="text-zinc-400 font-semibold">·</span>
                  <?= count($groupQuestions) ?> question<?= count($groupQuestions) === 1 ? '' : 's' ?>
                </div>
              </div>

              <div class="space-y-4">
                <?php foreach ($groupQuestions as $q): ?>
                  <div class="relative bg-white rounded-3xl border border-zinc-200 p-6 shadow-sm">
                    <div class="flex flex-col md:flex-row gap-5 items-start">
                      <div class="flex-1 w-full space-y-4">
                        <div>
                          <input type="text" class="w-full text-lg font-bold text-zinc-900 border-none bg-transparent placeholder-zinc-300 outline-none focus:ring-0 px-0" value="<?= htmlspecialchars((string) ($q['question_text'] ?? '')) ?>" readonly />
                          <div class="h-px bg-zinc-200 w-full mt-1"></div>
                        </div>
                        <div class="flex flex-wrap items-center gap-3">
                          <div class="px-3 py-1.5 rounded-lg bg-zinc-100 text-sm font-semibold text-zinc-700">
                            <?= (string) ($q['field_type'] ?? 'text') === 'rating' ? 'Likert (1-5 Scale)' : 'Comment / Text' ?>
                          </div>
                        </div>
                      </div>

                      <div class="flex items-center gap-6 shrink-0 md:border-l md:border-zinc-200 md:pl-6">
                        <div class="flex items-center gap-2.5">
                          <span class="text-xs font-bold text-zinc-500 uppercase tracking-widest">Required</span>
                          <button type="button" class="reqToggle relative inline-flex h-6 w-11 flex-shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors duration-200 ease-in-out focus:outline-none <?= !empty($q['required']) ? 'bg-orange-500' : 'bg-zinc-200' ?>" data-qid="<?= htmlspecialchars((string) ($q['id'] ?? '')) ?>" data-session-id="" aria-checked="<?= !empty($q['required']) ? 'true' : 'false' ?>">
                            <span class="pointer-events-none inline-block h-5 w-5 transform rounded-full bg-white shadow ring-0 transition duration-200 ease-in-out <?= !empty($q['required']) ? 'translate-x-5' : 'translate-x-0' ?>"></span>
                          </button>
                        </div>
                        <button class="btnDelete w-9 h-9 flex items-center justify-center rounded-xl bg-red-50 text-red-600 hover:bg-red-100 transition-colors shadow-sm" data-qid="<?= htmlspecialchars((string) ($q['id'] ?? '')) ?>" data-session-id="" title="Delete Question">
                          <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 01-2.244 2.077H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 00-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 013.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 00-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 00-7.5 0"/></svg>
                        </button>
                      </div>
                    </div>
                  </div>
                <?php endforeach; ?>
              </div>
            </div>
          <?php endforeach; ?>
        </div>

        <?php if ($usesSessions): ?>
          <?php foreach ($sessions as $session): ?>
            <?php $sid = (string) ($session['id'] ?? ''); ?>
            <?php $sessionGroups = $sessionQuestionGroups[$sid] ?? []; ?>
            <div class="rounded-3xl border border-zinc-200 bg-white p-5 shadow-sm">
              <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4 mb-5">
                <div>
                  <div class="text-[11px] font-black uppercase tracking-widest text-indigo-600">Seminar Questions</div>
                  <div class="text-lg font-bold text-zinc-900"><?= htmlspecialchars(build_session_display_name($session)) ?></div>
                  <p class="text-xs text-zinc-500 mt-1">Questions here apply only to this seminar.</p>
                </div>
                <button type="button" class="btnShowAdd inline-flex items-center gap-2 rounded-full border border-indigo-200 bg-indigo-50 px-4 py-2 text-xs font-bold text-indigo-700 hover:bg-indigo-100 transition" data-target="<?= htmlspecialchars($sid) ?>">
                  <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/></svg>
                  Add Question
                </button>
              </div>

              <div class="newQuestionCard hidden relative bg-indigo-50/50 rounded-3xl border-2 border-indigo-500/30 p-6 shadow-sm mb-4" data-target="<?= htmlspecialchars($sid) ?>">
                <form class="qForm flex flex-col md:flex-row gap-5 items-start" data-session-id="<?= htmlspecialchars($sid) ?>">
                  <input type="hidden" name="event_id" value="<?= htmlspecialchars($eventId) ?>" />
                  <input type="hidden" name="session_id" value="<?= htmlspecialchars($sid) ?>" />
                  <input type="hidden" name="required" value="true" />
                  <input type="hidden" name="sort_order" value="<?= (int) ($sessionQuestionCounts[$sid] ?? 0) + 1 ?>" />

                  <div class="flex-1 w-full space-y-4">
                    <div>
                      <input type="text" name="question_text" required class="w-full text-lg font-bold text-indigo-900 border-none bg-transparent placeholder-indigo-400 outline-none focus:ring-0 px-0" placeholder="Type your new question here..." />
                      <div class="h-px bg-indigo-200 w-full mt-1"></div>
                    </div>
                    <div class="flex items-center gap-3">
                      <span class="text-[11px] font-black uppercase tracking-widest text-indigo-600">Select Type:</span>
                      <select name="field_type" class="px-3 py-1.5 rounded-lg bg-white border border-indigo-200 text-sm font-bold text-indigo-800 outline-none focus:ring-2 focus:ring-indigo-500/30">
                        <option value="rating">Likert (1-5 Scale)</option>
                        <option value="text">Comment / Text</option>
                      </select>
                    </div>
                    <div class="qMsg text-sm font-bold text-indigo-600 hidden">Saving...</div>
                  </div>

                  <div class="flex items-center gap-4 shrink-0 md:border-l md:border-indigo-200 md:pl-6 h-full mt-auto">
                    <button type="button" class="btnCancelAdd py-2.5 px-4 text-sm font-bold text-zinc-500 hover:text-zinc-800 transition-colors">Cancel</button>
                    <button type="submit" class="py-2.5 px-6 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-bold rounded-xl shadow-lg shadow-indigo-600/20 transition-all">
                      Save Question
                    </button>
                  </div>
                </form>
              </div>

              <?php if (empty($sessionGroups)): ?>
                <div class="text-center py-10 rounded-2xl border-2 border-dashed border-zinc-200 bg-zinc-50">
                  <p class="text-sm font-semibold text-zinc-500">No questions yet for this seminar.</p>
                  <p class="text-xs text-zinc-400 mt-1">Add questions to organize by seminar.</p>
                </div>
              <?php endif; ?>

              <?php foreach ($sessionGroups as $groupLabel => $groupQuestions): ?>
                <div class="rounded-3xl border border-zinc-200 bg-zinc-50/70 p-4 mb-4">
                  <div class="mb-4 flex items-center justify-between gap-3">
                    <div class="rounded-full bg-white px-3 py-1 text-xs font-bold text-zinc-700 border border-zinc-200">
                      <?= htmlspecialchars((string) $groupLabel) ?>
                      <span class="text-zinc-400 font-semibold">·</span>
                      <?= count($groupQuestions) ?> question<?= count($groupQuestions) === 1 ? '' : 's' ?>
                    </div>
                  </div>

                  <div class="space-y-4">
                    <?php foreach ($groupQuestions as $q): ?>
                      <div class="relative bg-white rounded-3xl border border-zinc-200 p-6 shadow-sm">
                        <div class="flex flex-col md:flex-row gap-5 items-start">
                          <div class="flex-1 w-full space-y-4">
                            <div>
                              <input type="text" class="w-full text-lg font-bold text-zinc-900 border-none bg-transparent placeholder-zinc-300 outline-none focus:ring-0 px-0" value="<?= htmlspecialchars((string) ($q['question_text'] ?? '')) ?>" readonly />
                              <div class="h-px bg-zinc-200 w-full mt-1"></div>
                            </div>
                            <div class="flex flex-wrap items-center gap-3">
                              <div class="px-3 py-1.5 rounded-lg bg-zinc-100 text-sm font-semibold text-zinc-700">
                                <?= (string) ($q['field_type'] ?? 'text') === 'rating' ? 'Likert (1-5 Scale)' : 'Comment / Text' ?>
                              </div>
                            </div>
                          </div>

                          <div class="flex items-center gap-6 shrink-0 md:border-l md:border-zinc-200 md:pl-6">
                            <div class="flex items-center gap-2.5">
                              <span class="text-xs font-bold text-zinc-500 uppercase tracking-widest">Required</span>
                              <button type="button" class="reqToggle relative inline-flex h-6 w-11 flex-shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors duration-200 ease-in-out focus:outline-none <?= !empty($q['required']) ? 'bg-orange-500' : 'bg-zinc-200' ?>" data-qid="<?= htmlspecialchars((string) ($q['id'] ?? '')) ?>" data-session-id="<?= htmlspecialchars($sid) ?>" aria-checked="<?= !empty($q['required']) ? 'true' : 'false' ?>">
                                <span class="pointer-events-none inline-block h-5 w-5 transform rounded-full bg-white shadow ring-0 transition duration-200 ease-in-out <?= !empty($q['required']) ? 'translate-x-5' : 'translate-x-0' ?>"></span>
                              </button>
                            </div>
                            <button class="btnDelete w-9 h-9 flex items-center justify-center rounded-xl bg-red-50 text-red-600 hover:bg-red-100 transition-colors shadow-sm" data-qid="<?= htmlspecialchars((string) ($q['id'] ?? '')) ?>" data-session-id="<?= htmlspecialchars($sid) ?>" title="Delete Question">
                              <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 01-2.244 2.077H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 00-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 013.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 00-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 00-7.5 0"/></svg>
                            </button>
                          </div>
                        </div>
                      </div>
                    <?php endforeach; ?>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>
  <?php else: ?>
    <div class="max-w-4xl mx-auto space-y-8">
      <?php foreach ($feedbackSections as $sectionIndex => $section): ?>
        <?php
          extract(evaluation_feedback_section_view_data($section), EXTR_SKIP);
          require __DIR__ . '/includes/evaluation_feedback_section_render.php';
        ?>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

<?php if ($tab === 'feedback' && $canSendTeacherNotifications): ?>
  <script>
    document.querySelectorAll('.js-notify-evaluation-pending').forEach((button) => {
      button.addEventListener('click', () => {
        let recipients = [];
        try {
          recipients = JSON.parse(button.dataset.recipients || '[]');
        } catch (_) {
          window.alert('Could not prepare the pending evaluation recipients. Refresh the page and try again.');
          return;
        }
        if (!Array.isArray(recipients) || recipients.length === 0) {
          window.alert('There are no pending evaluation recipients in this section.');
          return;
        }
        if (recipients.length > 250) {
          window.alert('Individual notifications are limited to 250 recipients. No recipients were selected.');
          return;
        }
        try {
          sessionStorage.setItem('pc_teacher_notification_prefill', JSON.stringify({
            source: 'evaluation_pending',
            event_id: button.dataset.eventId || '',
            section_label: button.dataset.sectionLabel || 'Evaluation',
            recipients,
          }));
        } catch (_) {
          window.alert('Could not prepare the recipient list in this browser. Please allow session storage and try again.');
          return;
        }
        window.location.href = '/teacher_notifications?prefill=evaluation_pending';
      });
    });
  </script>
<?php endif; ?>

<?php if ($tab === 'feedback' && $canManageEvaluationDelivery): ?>
  <div id="evalDeliveryModal" class="hidden fixed inset-0 z-[120] items-center justify-center bg-zinc-950/50 p-3 sm:p-6" style="background-color: rgba(24, 24, 27, 0.48); backdrop-filter: blur(6px); -webkit-backdrop-filter: blur(6px);" role="dialog" aria-modal="true" aria-labelledby="evalDeliveryTitle">
    <div class="w-full max-w-5xl max-h-[92vh] overflow-hidden rounded-3xl bg-white shadow-2xl flex flex-col">
      <div class="flex items-start justify-between gap-4 border-b border-zinc-200 px-5 py-4 sm:px-6">
        <div>
          <h3 id="evalDeliveryTitle" class="text-lg sm:text-xl font-bold text-zinc-900">Evaluation Delivery Status</h3>
          <p class="mt-1 text-sm text-zinc-500">See which eligible attendees have an evaluation notice in their app inbox.</p>
        </div>
        <button type="button" id="btnCloseEvalDelivery" class="rounded-lg px-3 py-2 text-xl leading-none text-zinc-500 hover:bg-zinc-100" aria-label="Close">&times;</button>
      </div>

      <div class="flex-1 overflow-y-auto px-5 py-4 sm:px-6">
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 mb-4">
          <div class="rounded-2xl border border-zinc-200 bg-white p-4"><div class="text-[11px] font-bold uppercase tracking-wider text-zinc-500">Eligible attendees</div><div id="evalDeliveryEligibleCount" class="mt-1 text-2xl font-black text-zinc-900">—</div></div>
          <div class="rounded-2xl border border-emerald-200 bg-emerald-50 p-4"><div class="text-[11px] font-bold uppercase tracking-wider text-emerald-700">In-app notice recorded</div><div id="evalDeliveryReceivedCount" class="mt-1 text-2xl font-black text-emerald-900">—</div></div>
          <div class="rounded-2xl border border-amber-200 bg-amber-50 p-4"><div class="text-[11px] font-bold uppercase tracking-wider text-amber-700">Not sent yet</div><div id="evalDeliveryMissingCount" class="mt-1 text-2xl font-black text-amber-900">—</div></div>
        </div>

        <div class="mb-4 rounded-xl border border-sky-200 bg-sky-50 px-4 py-3 text-xs leading-relaxed text-sky-900">
          Eligibility follows the existing evaluation rule: the attendee must have a time-out (for multi-seminar events, from the final seminar). “In-app notice recorded” confirms an inbox entry; it cannot confirm that the student opened it or that a device displayed a push notification.
        </div>

        <div class="flex flex-col sm:flex-row gap-2 mb-4">
          <input id="evalDeliverySearch" type="search" class="min-w-0 flex-1 rounded-xl border border-zinc-300 px-3.5 py-2.5 text-sm outline-none focus:ring-2 focus:ring-sky-500/20 focus:border-sky-500" placeholder="Search attendee name or student number" aria-label="Search evaluation delivery recipients">
          <button type="button" id="btnRefreshEvalDelivery" class="rounded-xl border border-zinc-300 bg-white px-4 py-2.5 text-sm font-bold text-zinc-700 hover:bg-zinc-50">Refresh</button>
          <button type="button" id="btnSelectAllEvalMissing" class="rounded-xl border border-amber-200 bg-amber-50 px-4 py-2.5 text-sm font-bold text-amber-800 hover:bg-amber-100">Select not sent</button>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
          <section class="rounded-2xl border border-emerald-200 overflow-hidden">
            <div class="flex items-center justify-between bg-emerald-50 px-4 py-3"><h4 class="font-bold text-emerald-900">Notice in app inbox</h4><span id="evalDeliveryReceivedBadge" class="rounded-full bg-white px-2.5 py-1 text-xs font-black text-emerald-800">0</span></div>
            <div id="evalDeliveryReceivedList" class="max-h-[42vh] overflow-y-auto p-3 space-y-2"><div class="py-6 text-center text-sm font-semibold text-zinc-400">Open this panel to load status.</div></div>
          </section>
          <section class="rounded-2xl border border-amber-200 overflow-hidden">
            <div class="flex items-center justify-between bg-amber-50 px-4 py-3"><h4 class="font-bold text-amber-900">No in-app notice found</h4><span id="evalDeliveryMissingBadge" class="rounded-full bg-white px-2.5 py-1 text-xs font-black text-amber-800">0</span></div>
            <div id="evalDeliveryMissingList" class="max-h-[42vh] overflow-y-auto p-3 space-y-2"><div class="py-6 text-center text-sm font-semibold text-zinc-400">Open this panel to load status.</div></div>
          </section>
        </div>
      </div>

      <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 border-t border-zinc-200 bg-zinc-50 px-5 py-4 sm:px-6">
        <p id="evalDeliveryMessage" class="text-xs text-zinc-500" aria-live="polite">Only eligible attendees without an in-app notice can be selected for resend.</p>
        <button type="button" id="btnResendEvalSelected" class="rounded-xl bg-sky-700 px-5 py-2.5 text-sm font-bold text-white shadow-sm hover:bg-sky-800 disabled:cursor-not-allowed disabled:opacity-50">Resend selected (0)</button>
      </div>
    </div>
  </div>
<?php endif; ?>

<script>
  document.querySelectorAll('.btnShowAdd').forEach((button) => {
    button.addEventListener('click', () => {
      const target = button.dataset.target;
      const card = document.querySelector(`.newQuestionCard[data-target="${target}"]`);
      if (!card) return;
      card.classList.remove('hidden');
      button.classList.add('hidden');
      card.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
      const input = card.querySelector('input[name="question_text"]');
      if (input) {
        setTimeout(() => input.focus(), 50);
      }
    });
  });

  document.querySelectorAll('.btnCancelAdd').forEach((button) => {
    button.addEventListener('click', () => {
      const card = button.closest('.newQuestionCard');
      if (!card) return;
      const target = card.dataset.target;
      const showButton = document.querySelector(`.btnShowAdd[data-target="${target}"]`);
      card.classList.add('hidden');
      showButton?.classList.remove('hidden');
      const form = card.querySelector('form');
      form?.reset();
    });
  });

  document.querySelectorAll('.qForm').forEach((form) => {
    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      const msg = form.querySelector('.qMsg');
      msg.classList.remove('hidden');
      msg.textContent = 'Saving...';

      const payload = {
        event_id: form.event_id.value,
        question_text: form.question_text.value,
        field_type: form.field_type.value,
        required: true,
        sort_order: form.sort_order.value,
        csrf_token: window.CSRF_TOKEN
      };
      if (form.session_id && form.session_id.value) {
        payload.session_id = form.session_id.value;
      }

      try {
        const res = await fetch('/api/evaluation_questions_save.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify(payload)
        });
        const data = await res.json();
        if (!data.ok) throw new Error(data.error || 'Failed to save question');
        window.location.reload();
      } catch (err) {
        msg.textContent = err.message || 'Failed to save question';
      }
    });
  });

  document.querySelectorAll('.reqToggle').forEach((button) => {
    button.addEventListener('click', async () => {
      const checked = button.getAttribute('aria-checked') === 'true';
      const payload = {
        event_id: '<?= htmlspecialchars($eventId) ?>',
        question_id: button.dataset.qid,
        required: !checked,
        csrf_token: window.CSRF_TOKEN
      };
      if (button.dataset.sessionId) {
        payload.session_id = button.dataset.sessionId;
      }

      await fetch('/api/evaluation_questions_set_required.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
      });
      window.location.reload();
    });
  });

  document.querySelectorAll('.btnDelete').forEach((button) => {
    button.addEventListener('click', async () => {
      if (!confirm('Delete this question?')) return;
      const payload = {
        event_id: '<?= htmlspecialchars($eventId) ?>',
        question_id: button.dataset.qid,
        csrf_token: window.CSRF_TOKEN
      };
      if (button.dataset.sessionId) {
        payload.session_id = button.dataset.sessionId;
      }

      const res = await fetch('/api/evaluation_questions_delete.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
      });
      const data = await res.json();
      if (!data.ok) {
        alert(data.error || 'Failed to delete question.');
        return;
      }
      window.location.reload();
    });
  });

  (function () {
    const openBtn = document.getElementById('btnEvalDeliveryStatus');
    const modal = document.getElementById('evalDeliveryModal');
    if (!openBtn || !modal) return;

    const closeBtn = document.getElementById('btnCloseEvalDelivery');
    const refreshBtn = document.getElementById('btnRefreshEvalDelivery');
    const selectAllBtn = document.getElementById('btnSelectAllEvalMissing');
    const resendBtn = document.getElementById('btnResendEvalSelected');
    const search = document.getElementById('evalDeliverySearch');
    const message = document.getElementById('evalDeliveryMessage');
    let hasQuestions = false;

    const escapeHtml = (value) => String(value ?? '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');

    const formatDate = (value) => {
      const date = new Date(value);
      return Number.isNaN(date.getTime()) ? '' : date.toLocaleString('en-PH', {
        timeZone: 'Asia/Manila', month: 'short', day: 'numeric', year: 'numeric',
        hour: 'numeric', minute: '2-digit'
      });
    };

    const updateSelected = () => {
      const checked = modal.querySelectorAll('.eval-delivery-check:checked');
      resendBtn.disabled = checked.length === 0 || !hasQuestions;
      resendBtn.textContent = `Resend selected (${checked.length})`;
    };

    const applySearch = () => {
      const term = String(search?.value || '').trim().toLocaleLowerCase();
      modal.querySelectorAll('[data-eval-delivery-row]').forEach((row) => {
        const haystack = String(row.getAttribute('data-search') || '').toLocaleLowerCase();
        row.classList.toggle('hidden', term !== '' && !haystack.includes(term));
      });
    };

    const renderRows = (container, rows, kind) => {
      if (!container) return;
      if (!rows.length) {
        container.innerHTML = `<div class="py-6 text-center text-sm font-semibold text-zinc-400">${kind === 'received' ? 'No in-app evaluation notices recorded yet.' : 'Everyone eligible has an in-app notice.'}</div>`;
        return;
      }
      container.innerHTML = rows.map((row) => {
        const id = escapeHtml(row.student_id || '');
        const name = escapeHtml(row.name || 'Student');
        const studentNo = escapeHtml(row.student_no || 'Student number unavailable');
        const searchable = escapeHtml(`${row.name || ''} ${row.student_no || ''}`);
        if (kind === 'received') {
          const sentAt = escapeHtml(formatDate(row.sent_at || ''));
          return `<div data-eval-delivery-row data-search="${searchable}" class="rounded-xl border border-emerald-100 bg-emerald-50/50 px-3 py-3">
            <div class="flex items-start justify-between gap-3"><div class="min-w-0"><div class="truncate text-sm font-bold text-zinc-900">${name}</div><div class="mt-0.5 text-xs text-zinc-500">${studentNo}</div></div><span class="shrink-0 rounded-full bg-white px-2 py-1 text-[10px] font-black uppercase tracking-wide text-emerald-700">In inbox</span></div>
            ${sentAt ? `<div class="mt-2 text-[11px] text-zinc-500">Recorded ${sentAt}</div>` : ''}
          </div>`;
        }
        return `<div data-eval-delivery-row data-search="${searchable}" class="rounded-xl border border-amber-100 bg-amber-50/50 px-3 py-3">
          <div class="flex items-start gap-2">
            <input type="checkbox" class="eval-delivery-check mt-1 rounded border-zinc-300 text-sky-700 focus:ring-sky-500" value="${id}" aria-label="Select ${name} for evaluation resend">
            <div class="min-w-0 flex-1"><div class="truncate text-sm font-bold text-zinc-900">${name}</div><div class="mt-0.5 text-xs text-zinc-500">${studentNo}</div><div class="mt-1 text-[11px] text-amber-800">No in-app evaluation notice found</div></div>
            <button type="button" class="eval-delivery-send-one shrink-0 rounded-lg bg-sky-700 px-2.5 py-1.5 text-[11px] font-bold text-white hover:bg-sky-800 disabled:cursor-not-allowed disabled:opacity-50" data-student-id="${id}" ${hasQuestions ? '' : 'disabled title="Add evaluation questions before resending"'}>Resend</button>
          </div>
        </div>`;
      }).join('');
      container.querySelectorAll('.eval-delivery-check').forEach((checkbox) => checkbox.addEventListener('change', updateSelected));
      container.querySelectorAll('.eval-delivery-send-one').forEach((button) => button.addEventListener('click', (event) => {
        event.preventDefault();
        event.stopPropagation();
        void resendStudents([button.dataset.studentId || '']);
      }));
    };

    const renderStatus = (data) => {
      const received = Array.isArray(data.received) ? data.received : [];
      const missing = Array.isArray(data.missing) ? data.missing : [];
      hasQuestions = data.has_questions === true;
      document.getElementById('evalDeliveryEligibleCount').textContent = String(Number(data.eligible_count ?? received.length + missing.length));
      document.getElementById('evalDeliveryReceivedCount').textContent = String(Number(data.received_count ?? received.length));
      document.getElementById('evalDeliveryMissingCount').textContent = String(Number(data.missing_count ?? missing.length));
      document.getElementById('evalDeliveryReceivedBadge').textContent = String(received.length);
      document.getElementById('evalDeliveryMissingBadge').textContent = String(missing.length);
      renderRows(document.getElementById('evalDeliveryReceivedList'), received, 'received');
      renderRows(document.getElementById('evalDeliveryMissingList'), missing, 'missing');
      if (!hasQuestions) {
        message.textContent = 'No evaluation questions are configured, so resending is disabled.';
      } else if (received.length + missing.length === 0) {
        message.textContent = 'No eligible checked-out attendees were found for this event yet.';
      } else {
        message.textContent = 'Select attendees with no in-app notice, then resend the evaluation reminder.';
      }
      applySearch();
      updateSelected();
    };

    const postAction = async (action, studentIds = []) => {
      const response = await fetch('/api/evaluation_delivery_status.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
        body: JSON.stringify({
          action,
          event_id: <?= json_encode($eventId) ?>,
          student_ids: studentIds,
          csrf_token: window.CSRF_TOKEN || ''
        })
      });
      const raw = await response.text();
      let data;
      try { data = JSON.parse(raw); } catch (_) { throw new Error(`Server returned an invalid response (HTTP ${response.status}).`); }
      if (!response.ok || !data?.ok) throw new Error(data?.error || `Request failed (HTTP ${response.status}).`);
      return data;
    };

    const loadStatus = async () => {
      const receivedList = document.getElementById('evalDeliveryReceivedList');
      const missingList = document.getElementById('evalDeliveryMissingList');
      receivedList.innerHTML = '<div class="py-6 text-center text-sm font-semibold text-zinc-400">Loading status…</div>';
      missingList.innerHTML = '<div class="py-6 text-center text-sm font-semibold text-zinc-400">Loading status…</div>';
      message.textContent = 'Loading evaluation delivery status…';
      try {
        renderStatus(await postAction('status'));
      } catch (error) {
        const safe = escapeHtml(error?.message || 'Could not load status.');
        receivedList.innerHTML = `<div class="rounded-xl border border-red-200 bg-red-50 p-4 text-sm font-semibold text-red-700">${safe}</div>`;
        missingList.innerHTML = '';
        message.textContent = error?.message || 'Could not load status.';
      }
    };

    const resendStudents = async (rawIds) => {
      const ids = [...new Set((rawIds || []).map((id) => String(id || '').trim()).filter(Boolean))];
      if (!ids.length || !hasQuestions) return;
      if (!window.confirm(`Resend the evaluation reminder to ${ids.length} selected attendee(s)?`)) return;
      resendBtn.disabled = true;
      message.textContent = `Resending evaluation notice to ${ids.length} attendee(s)…`;
      let recorded = 0;
      let pushSent = 0;
      let pushFailed = 0;
      let pushSkipped = false;
      let inboxVerified = true;
      try {
        for (let offset = 0; offset < ids.length; offset += 20) {
          const result = await postAction('resend', ids.slice(offset, offset + 20));
          recorded += Number(result.in_app_recorded || 0);
          pushSent += Number(result.push_sent || 0);
          pushFailed += Number(result.push_failed || 0);
          pushSkipped = pushSkipped || result.push_skipped === true;
          inboxVerified = inboxVerified && result.in_app_verified === true;
        }
        await loadStatus();
        const pushSummary = pushSkipped
          ? 'Some students have no registered push device; the in-app inbox remains available.'
          : `Push tokens sent: ${pushSent}${pushFailed > 0 ? `, failed: ${pushFailed}` : ''}.`;
        message.textContent = inboxVerified
          ? `In-app notice verified for ${recorded} of ${ids.length} selected attendee(s). ${pushSummary}`
          : `Resend was attempted for ${ids.length} attendee(s), but the in-app inbox could not be verified. Refresh to check. ${pushSummary}`;
      } catch (error) {
        await loadStatus();
        message.textContent = `${error?.message || 'Resend failed.'} Refresh status before retrying.`;
      } finally {
        updateSelected();
      }
    };

    const openModal = () => {
      modal.classList.remove('hidden');
      modal.classList.add('flex');
      void loadStatus();
    };
    const closeModal = () => {
      modal.classList.add('hidden');
      modal.classList.remove('flex');
    };

    openBtn.addEventListener('click', openModal);
    closeBtn?.addEventListener('click', closeModal);
    modal.addEventListener('click', (event) => { if (event.target === modal) closeModal(); });
    document.addEventListener('keydown', (event) => { if (event.key === 'Escape' && !modal.classList.contains('hidden')) closeModal(); });
    refreshBtn?.addEventListener('click', () => void loadStatus());
    search?.addEventListener('input', applySearch);
    selectAllBtn?.addEventListener('click', () => {
      modal.querySelectorAll('.eval-delivery-check').forEach((checkbox) => { checkbox.checked = true; });
      updateSelected();
    });
    resendBtn?.addEventListener('click', () => {
      const ids = Array.from(modal.querySelectorAll('.eval-delivery-check:checked')).map((checkbox) => checkbox.value);
      void resendStudents(ids);
    });
  })();

  (function () {
    const exportBtn = document.getElementById('btn-export-feedback-pdf');
    if (!exportBtn) return;

    const label = exportBtn.querySelector('.export-pdf-label');
    const defaultLabel = label ? label.textContent : 'Export as PDF';
    const exportUrl = exportBtn.getAttribute('data-export-url') || '';
    let exportFrame = null;
    let exportTimeout = null;
    let exportStarted = false;
    let warmedUp = false;

    function warmUpExport() {
      if (warmedUp || !exportUrl) return;
      warmedUp = true;

      if (!document.querySelector('link[data-pdf-preload="html2pdf"]')) {
        const scriptPreload = document.createElement('link');
        scriptPreload.rel = 'preload';
        scriptPreload.as = 'script';
        scriptPreload.href = '/assets/js/html2pdf.bundle.min.js';
        scriptPreload.setAttribute('data-pdf-preload', 'html2pdf');
        document.head.appendChild(scriptPreload);
      }

      if (!document.querySelector('link[data-pdf-prefetch="export"]')) {
        const pagePrefetch = document.createElement('link');
        pagePrefetch.rel = 'prefetch';
        pagePrefetch.href = exportUrl;
        pagePrefetch.setAttribute('data-pdf-prefetch', 'export');
        document.head.appendChild(pagePrefetch);
      }
    }

    function resetExportButton() {
      exportStarted = false;
      if (exportTimeout) {
        clearTimeout(exportTimeout);
        exportTimeout = null;
      }
      if (exportFrame) {
        exportFrame.remove();
        exportFrame = null;
      }
      exportBtn.disabled = false;
      if (label) label.textContent = defaultLabel;
    }

    function armExportTimeout(ms) {
      if (exportTimeout) {
        clearTimeout(exportTimeout);
      }
      exportTimeout = window.setTimeout(function () {
        resetExportButton();
        alert('PDF export timed out. Please try again.');
      }, ms);
    }

    function downloadBlobInParent(blob, filename) {
      const url = URL.createObjectURL(blob);
      const anchor = document.createElement('a');
      anchor.href = url;
      anchor.download = filename || 'event-feedback.pdf';
      document.body.appendChild(anchor);
      anchor.click();
      anchor.remove();
      window.setTimeout(function () { URL.revokeObjectURL(url); }, 1000);
    }

    warmUpExport();
    exportBtn.addEventListener('mouseenter', warmUpExport, { once: true });

    window.addEventListener('message', function (event) {
      if (event.origin !== window.location.origin) return;
      const data = event.data || {};
      const type = data.type;

      if (type === 'feedback-pdf-started') {
        exportStarted = true;
        armExportTimeout(300000);
        return;
      }

      if (type === 'feedback-pdf-blob') {
        if (data.blob instanceof Blob) {
          downloadBlobInParent(data.blob, data.filename || 'event-feedback.pdf');
        }
        resetExportButton();
        return;
      }

      if (type === 'feedback-pdf-done') {
        resetExportButton();
        return;
      }

      if (type === 'feedback-pdf-error') {
        resetExportButton();
        const msg = data.message ? String(data.message) : '';
        alert(msg || 'Could not generate PDF. Please try again.');
      }
    });

    exportBtn.addEventListener('click', function () {
      if (exportBtn.disabled) return;
      if (!exportUrl) return;

      warmUpExport();

      if (exportTimeout) {
        clearTimeout(exportTimeout);
        exportTimeout = null;
      }
      if (exportFrame) {
        exportFrame.remove();
        exportFrame = null;
      }

      exportStarted = false;
      exportBtn.disabled = true;
      if (label) label.textContent = 'Generating PDF...';

      exportFrame = document.createElement('iframe');
      exportFrame.setAttribute('title', 'Generating event feedback PDF');
      exportFrame.setAttribute('tabindex', '-1');
      exportFrame.style.cssText = 'position:fixed;inset:0;width:100vw;height:100vh;border:0;background:#fff;z-index:10000';
      exportFrame.src = exportUrl + (exportUrl.indexOf('?') >= 0 ? '&' : '?') + '_ts=' + Date.now();
      document.body.appendChild(exportFrame);

      armExportTimeout(60000);
    });
  })();
</script>

<?php render_footer(); ?>
