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
foreach ($eventQuestions as $question) {
    $groupKey = 'Questions';
    if (!isset($eventQuestionGroups[$groupKey])) {
        $eventQuestionGroups[$groupKey] = [];
    }
    $eventQuestionGroups[$groupKey][] = $question;
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

    foreach ($sessionQuestions as $question) {
        $sid = (string) ($question['session_id'] ?? '');
        if ($sid === '') {
            continue;
        }
        if (!isset($sessionQuestionGroups[$sid])) {
            $sessionQuestionGroups[$sid] = [];
        }
        $groupKey = 'Questions';
        if (!isset($sessionQuestionGroups[$sid][$groupKey])) {
            $sessionQuestionGroups[$sid][$groupKey] = [];
        }
        $sessionQuestionGroups[$sid][$groupKey][] = $question;
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
      <button type="button" id="btn-export-feedback-pdf"
        data-export-url="/evaluation_feedback_export.php?event_id=<?= rawurlencode($eventId) ?>&print=1"
        class="inline-flex items-center justify-center gap-2 rounded-xl border border-orange-200 bg-orange-50 px-4 py-2.5 text-[13px] font-bold text-orange-800 shadow-sm hover:bg-orange-100 hover:border-orange-300 transition-colors whitespace-nowrap disabled:opacity-60 disabled:cursor-not-allowed">
        <svg class="w-4 h-4 shrink-0 export-pdf-icon" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m6.75 12H9m1.5-12H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z"/></svg>
        <span class="export-pdf-label">Export as PDF</span>
      </button>
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

          <?php if (count($eventQuestions) === 0): ?>
            <div class="text-center py-10 rounded-2xl border-2 border-dashed border-zinc-200 bg-zinc-50">
              <p class="text-sm font-semibold text-zinc-500">No event-level questions yet.</p>
              <p class="text-xs text-zinc-400 mt-1">Add a question that applies to the whole event.</p>
            </div>
          <?php endif; ?>

          <?php foreach ($eventQuestionGroups as $groupQuestions): ?>
            <div class="rounded-3xl border border-zinc-200 bg-zinc-50/70 p-4 mb-4">
              <div class="mb-4 flex items-center justify-between gap-3">
                <div class="rounded-full bg-white px-3 py-1 text-xs font-bold text-zinc-500 border border-zinc-200">
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

          <div class="newQuestionCard hidden relative bg-emerald-50/50 rounded-3xl border-2 border-emerald-500/30 p-6 shadow-sm" data-target="event">
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

              <?php if (empty($sessionGroups)): ?>
                <div class="text-center py-10 rounded-2xl border-2 border-dashed border-zinc-200 bg-zinc-50">
                  <p class="text-sm font-semibold text-zinc-500">No questions yet for this seminar.</p>
                  <p class="text-xs text-zinc-400 mt-1">Add questions to organize by seminar.</p>
                </div>
              <?php endif; ?>

              <?php foreach ($sessionGroups as $groupQuestions): ?>
                <div class="rounded-3xl border border-zinc-200 bg-zinc-50/70 p-4 mb-4">
                  <div class="mb-4 flex items-center justify-between gap-3">
                    <div class="rounded-full bg-white px-3 py-1 text-xs font-bold text-zinc-500 border border-zinc-200">
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

              <div class="newQuestionCard hidden relative bg-indigo-50/50 rounded-3xl border-2 border-indigo-500/30 p-6 shadow-sm" data-target="<?= htmlspecialchars($sid) ?>">
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
<script>
  document.querySelectorAll('.btnShowAdd').forEach((button) => {
    button.addEventListener('click', () => {
      const target = button.dataset.target;
      const card = document.querySelector(`.newQuestionCard[data-target="${target}"]`);
      if (!card) return;
      card.classList.remove('hidden');
      button.classList.add('hidden');
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
    const exportBtn = document.getElementById('btn-export-feedback-pdf');
    if (!exportBtn) return;

    const label = exportBtn.querySelector('.export-pdf-label');
    const defaultLabel = label ? label.textContent : 'Export as PDF';
    let exportFrame = null;
    let exportTimeout = null;

    function resetExportButton() {
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

    window.addEventListener('message', function (event) {
      if (event.origin !== window.location.origin) return;
      const type = event.data && event.data.type;
      if (type === 'feedback-pdf-done' || type === 'feedback-pdf-error') {
        resetExportButton();
      }
    });

    exportBtn.addEventListener('click', function () {
      if (exportBtn.disabled) return;
      const exportUrl = exportBtn.getAttribute('data-export-url');
      if (!exportUrl) return;

      if (exportTimeout) {
        clearTimeout(exportTimeout);
        exportTimeout = null;
      }
      if (exportFrame) {
        exportFrame.remove();
        exportFrame = null;
      }

      exportBtn.disabled = true;
      if (label) label.textContent = 'Generating PDF...';

      exportFrame = document.createElement('iframe');
      exportFrame.setAttribute('aria-hidden', 'true');
      exportFrame.setAttribute('tabindex', '-1');
      exportFrame.style.cssText = 'position:fixed;left:-10000px;top:0;width:1200px;height:900px;border:0;visibility:hidden';
      exportFrame.src = exportUrl;
      document.body.appendChild(exportFrame);

      exportTimeout = window.setTimeout(resetExportButton, 120000);
    });
  })();
</script>

<?php render_footer(); ?>
