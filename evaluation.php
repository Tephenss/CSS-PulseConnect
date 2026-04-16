<?php
declare(strict_types=1);

session_start();

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/supabase.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/event_sessions.php';

$user = require_role(['student']);
$studentId = (string) ($user['id'] ?? '');

$headers = [
    'Accept: application/json',
    'apikey: ' . SUPABASE_KEY,
    'Authorization: Bearer ' . SUPABASE_KEY,
];

function evaluation_page_attendance_counts_as_present(array $row): bool
{
    $status = strtolower(trim((string) ($row['status'] ?? '')));
    $checkInAt = trim((string) ($row['check_in_at'] ?? ''));

    if ($checkInAt !== '') {
        return true;
    }

    return in_array($status, ['present', 'scanned', 'late', 'early'], true);
}

function evaluation_page_fetch_event_questions(string $eventId, array $headers): array
{
    $url = rtrim(SUPABASE_URL, '/') . '/rest/v1/evaluation_questions'
        . '?select=id,event_id,question_text,field_type,required,sort_order'
        . '&event_id=eq.' . rawurlencode($eventId)
        . '&order=sort_order.asc';
    $res = supabase_request('GET', $url, $headers);
    $rows = $res['ok'] ? json_decode((string) $res['body'], true) : [];

    return is_array($rows) ? $rows : [];
}

function evaluation_page_fetch_session_questions(array $sessionIds, array $headers): array
{
    if (count($sessionIds) === 0) {
        return [];
    }

    $url = rtrim(SUPABASE_URL, '/') . '/rest/v1/event_session_evaluation_questions'
        . '?select=id,session_id,question_text,field_type,required,sort_order'
        . '&session_id=in.(' . implode(',', array_map('rawurlencode', $sessionIds)) . ')'
        . '&order=sort_order.asc';
    $res = supabase_request('GET', $url, $headers);
    $rows = $res['ok'] ? json_decode((string) $res['body'], true) : [];

    return is_array($rows) ? $rows : [];
}

function evaluation_page_fetch_event_answers(string $eventId, string $studentId, array $headers): array
{
    $url = rtrim(SUPABASE_URL, '/') . '/rest/v1/evaluation_answers'
        . '?select=question_id,answer_text'
        . '&student_id=eq.' . rawurlencode($studentId)
        . '&event_id=eq.' . rawurlencode($eventId);
    $res = supabase_request('GET', $url, $headers);
    $rows = $res['ok'] ? json_decode((string) $res['body'], true) : [];

    $answers = [];
    if (!is_array($rows)) {
        return $answers;
    }

    foreach ($rows as $row) {
        $questionId = trim((string) ($row['question_id'] ?? ''));
        if ($questionId !== '') {
            $answers[$questionId] = (string) ($row['answer_text'] ?? '');
        }
    }

    return $answers;
}

function evaluation_page_fetch_session_answers(array $sessionIds, string $studentId, array $headers): array
{
    if (count($sessionIds) === 0) {
        return [];
    }

    $url = rtrim(SUPABASE_URL, '/') . '/rest/v1/event_session_evaluation_answers'
        . '?select=question_id,answer_text,session_id'
        . '&student_id=eq.' . rawurlencode($studentId)
        . '&session_id=in.(' . implode(',', array_map('rawurlencode', $sessionIds)) . ')';
    $res = supabase_request('GET', $url, $headers);
    $rows = $res['ok'] ? json_decode((string) $res['body'], true) : [];

    $answers = [];
    if (!is_array($rows)) {
        return $answers;
    }

    foreach ($rows as $row) {
        $sessionId = trim((string) ($row['session_id'] ?? ''));
        $questionId = trim((string) ($row['question_id'] ?? ''));
        if ($sessionId === '' || $questionId === '') {
            continue;
        }

        if (!isset($answers[$sessionId])) {
            $answers[$sessionId] = [];
        }
        $answers[$sessionId][$questionId] = (string) ($row['answer_text'] ?? '');
    }

    return $answers;
}

function evaluation_page_has_simple_attendance(string $eventId, string $studentId, array $headers): bool
{
    $attendanceUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/attendance'
        . '?select=status,check_in_at,tickets(registration_id,event_registrations(student_id,event_id))'
        . '&tickets.event_registrations.event_id=eq.' . rawurlencode($eventId);
    $attendanceRes = supabase_request('GET', $attendanceUrl, $headers);
    $attendanceRows = $attendanceRes['ok'] ? json_decode((string) $attendanceRes['body'], true) : [];

    if (!is_array($attendanceRows)) {
        return false;
    }

    foreach ($attendanceRows as $row) {
        if (!is_array($row) || !evaluation_page_attendance_counts_as_present($row)) {
            continue;
        }

        $ticket = isset($row['tickets']) && is_array($row['tickets']) ? $row['tickets'] : [];
        $registration = isset($ticket['event_registrations']) && is_array($ticket['event_registrations'])
            ? $ticket['event_registrations']
            : [];
        if ((string) ($registration['student_id'] ?? '') === $studentId) {
            return true;
        }
    }

    return false;
}

function evaluation_page_attended_session_ids(array $sessions, string $studentId, array $headers): array
{
    $sessionIds = [];
    foreach ($sessions as $session) {
        $sid = trim((string) ($session['id'] ?? ''));
        if ($sid !== '') {
            $sessionIds[] = $sid;
        }
    }

    if (count($sessionIds) === 0) {
        return [];
    }

    $attendanceUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/event_session_attendance'
        . '?select=session_id,status,check_in_at,registration:event_registrations(student_id)'
        . '&session_id=in.(' . implode(',', array_map('rawurlencode', $sessionIds)) . ')';
    $attendanceRes = supabase_request('GET', $attendanceUrl, $headers);
    $attendanceRows = $attendanceRes['ok'] ? json_decode((string) $attendanceRes['body'], true) : [];

    if (!is_array($attendanceRows)) {
        return [];
    }

    $attended = [];
    foreach ($attendanceRows as $row) {
        if (!is_array($row) || !evaluation_page_attendance_counts_as_present($row)) {
            continue;
        }

        $registration = isset($row['registration']) && is_array($row['registration']) ? $row['registration'] : [];
        if ((string) ($registration['student_id'] ?? '') !== $studentId) {
            continue;
        }

        $sid = trim((string) ($row['session_id'] ?? ''));
        if ($sid !== '') {
            $attended[$sid] = true;
        }
    }

    return array_values(array_keys($attended));
}

$regUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/event_registrations'
    . '?select=event_id&student_id=eq.' . rawurlencode($studentId)
    . '&limit=2000';
$regRes = supabase_request('GET', $regUrl, $headers);
$regRows = $regRes['ok'] ? json_decode((string) $regRes['body'], true) : null;
$eventIds = [];
if (is_array($regRows)) {
    foreach ($regRows as $row) {
        $eventId = (string) ($row['event_id'] ?? '');
        if ($eventId !== '') {
            $eventIds[$eventId] = true;
        }
    }
}
$eventIds = array_values(array_keys($eventIds));

if (count($eventIds) === 0) {
    render_header('Evaluation', $user);
    echo '<div class="mt-6 rounded-2xl border border-zinc-800 bg-zinc-900/30 p-6 text-zinc-300 text-sm">No events found for your account.</div>';
    render_footer();
    exit;
}

$eventFilter = implode(',', array_map(static fn (string $id): string => '"' . $id . '"', $eventIds));
$eventsUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/events'
    . '?select=id,title,start_at,end_at,event_mode,event_structure,uses_sessions&order=created_at.desc&id=in.(' . $eventFilter . ')';
$eventsRes = supabase_request('GET', $eventsUrl, $headers);
$eventsRows = $eventsRes['ok'] ? json_decode((string) $eventsRes['body'], true) : null;
$events = is_array($eventsRows) ? $eventsRows : [];
$events = attach_event_sessions_to_events($events, $headers);

if (count($events) === 0) {
    render_header('Evaluation', $user);
    echo '<div class="mt-6 rounded-2xl border border-zinc-800 bg-zinc-900/30 p-6 text-zinc-300 text-sm">No events found for your account.</div>';
    render_footer();
    exit;
}

$activeEventId = isset($_GET['event_id']) ? (string) $_GET['event_id'] : '';
$activeEvent = null;
foreach ($events as $event) {
    if ((string) ($event['id'] ?? '') === $activeEventId) {
        $activeEvent = $event;
        break;
    }
}
if (!is_array($activeEvent)) {
    $activeEvent = $events[0];
    $activeEventId = (string) ($activeEvent['id'] ?? '');
}

$sessions = isset($activeEvent['sessions']) && is_array($activeEvent['sessions']) ? $activeEvent['sessions'] : [];
$usesSessions = event_uses_sessions($activeEvent);
$sections = [];
$hasAttendance = false;
$sidebarHint = 'Required items must be answered to submit.';
$emptyMessage = 'No evaluation questions are available for this event yet.';

if ($usesSessions) {
    if (count($sessions) === 0) {
        $emptyMessage = 'No seminar sessions are configured for this event yet.';
        $sidebarHint = 'Seminar-based events will show one event feedback section plus the seminars you actually attended.';
    } else {
        $attendedSessionIds = evaluation_page_attended_session_ids($sessions, $studentId, $headers);
        $hasAttendance = count($attendedSessionIds) > 0;

        if ($hasAttendance) {
            $eventQuestions = evaluation_page_fetch_event_questions($activeEventId, $headers);
            if (count($eventQuestions) > 0) {
                $sections[] = [
                    'scope' => 'event',
                    'scope_id' => $activeEventId,
                    'title' => 'Event Feedback',
                    'subtitle' => 'Applies to the whole event.',
                    'questions' => $eventQuestions,
                    'answers' => evaluation_page_fetch_event_answers($activeEventId, $studentId, $headers),
                ];
            }

            $sessionQuestionsRows = evaluation_page_fetch_session_questions($attendedSessionIds, $headers);
            $sessionQuestions = [];
            foreach ($sessionQuestionsRows as $row) {
                $sid = trim((string) ($row['session_id'] ?? ''));
                if ($sid === '') {
                    continue;
                }
                if (!isset($sessionQuestions[$sid])) {
                    $sessionQuestions[$sid] = [];
                }
                $sessionQuestions[$sid][] = $row;
            }

            $sessionAnswers = evaluation_page_fetch_session_answers($attendedSessionIds, $studentId, $headers);
            foreach ($sessions as $session) {
                $sid = trim((string) ($session['id'] ?? ''));
                if ($sid === '' || !in_array($sid, $attendedSessionIds, true)) {
                    continue;
                }

                $questions = $sessionQuestions[$sid] ?? [];
                if (count($questions) === 0) {
                    continue;
                }

                $startLabel = '';
                $endLabel = '';
                try {
                    $startLabel = (new DateTimeImmutable((string) ($session['start_at'] ?? '')))->format('M j, Y g:i A');
                } catch (Throwable $e) {
                    $startLabel = '';
                }
                try {
                    $endLabel = (new DateTimeImmutable((string) ($session['end_at'] ?? '')))->format('g:i A');
                } catch (Throwable $e) {
                    $endLabel = '';
                }

                $subtitle = 'Applies only to the seminar you attended.';
                if ($startLabel !== '') {
                    $subtitle .= ' Schedule: ' . $startLabel . ($endLabel !== '' ? ' - ' . $endLabel : '');
                }

                $sections[] = [
                    'scope' => 'session',
                    'scope_id' => $sid,
                    'title' => build_session_display_name($session, 'Seminar'),
                    'subtitle' => $subtitle,
                    'questions' => $questions,
                    'answers' => $sessionAnswers[$sid] ?? [],
                ];
            }

            $sidebarHint = 'Answer the whole-event feedback plus the seminar sections you actually attended.';
            $emptyMessage = 'No evaluation questions are available for your attended seminar sections yet.';
        } else {
            $sidebarHint = 'You only receive evaluation forms for seminars where your attendance was recorded.';
            $emptyMessage = 'Only attended seminars can be evaluated for this event.';
        }
    }
} else {
    $hasAttendance = evaluation_page_has_simple_attendance($activeEventId, $studentId, $headers);
    if ($hasAttendance) {
        $eventQuestions = evaluation_page_fetch_event_questions($activeEventId, $headers);
        if (count($eventQuestions) > 0) {
            $sections[] = [
                'scope' => 'event',
                'scope_id' => $activeEventId,
                'title' => 'Event Feedback',
                'subtitle' => 'Applies to the whole event.',
                'questions' => $eventQuestions,
                'answers' => evaluation_page_fetch_event_answers($activeEventId, $studentId, $headers),
            ];
        }
    } else {
        $emptyMessage = 'Only attendees can answer evaluation for this event.';
    }
}

render_header('Evaluation', $user);
?>

<div class="flex items-start justify-between gap-4">
  <div>
    <div class="text-sm uppercase tracking-widest text-zinc-400">Evaluation</div>
    <h1 class="text-2xl font-semibold mt-2">Answer the questions</h1>
    <p class="text-zinc-400 text-sm mt-2"><?= htmlspecialchars($sidebarHint) ?></p>
  </div>
</div>

<div class="mt-6 grid grid-cols-1 lg:grid-cols-3 gap-4 items-start">
  <div class="lg:col-span-2 rounded-2xl border border-zinc-800 bg-zinc-900/30 p-6">
    <div class="flex flex-col gap-3">
      <div class="flex items-center gap-3">
        <label class="text-sm text-zinc-400 min-w-16">Event</label>
        <select id="eventSel" class="flex-1 rounded-lg bg-zinc-950 border border-zinc-800 px-3 py-2.5 text-sm outline-none focus:ring-2 focus:ring-zinc-700">
          <?php foreach ($events as $event): ?>
            <?php $eventId = (string) ($event['id'] ?? ''); ?>
            <option value="<?= htmlspecialchars($eventId) ?>" <?= $eventId === $activeEventId ? 'selected' : '' ?>>
              <?= htmlspecialchars((string) ($event['title'] ?? $eventId)) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>

    <?php if (!$hasAttendance): ?>
      <div class="mt-5 rounded-xl border border-zinc-800 bg-zinc-950/20 p-4 text-sm text-zinc-300">
        <?= htmlspecialchars($emptyMessage) ?>
      </div>
    <?php elseif (count($sections) === 0): ?>
      <div class="mt-5 rounded-xl border border-zinc-800 bg-zinc-950/20 p-4 text-sm text-zinc-300">
        <?= htmlspecialchars($emptyMessage) ?>
      </div>
    <?php else: ?>
      <form id="evalForm" class="mt-5 space-y-5">
        <input type="hidden" id="event_id" name="event_id" value="<?= htmlspecialchars($activeEventId) ?>" />

        <div class="space-y-5">
          <?php foreach ($sections as $section): ?>
            <?php
              $scopeId = (string) ($section['scope_id'] ?? '');
              $sectionAnswers = isset($section['answers']) && is_array($section['answers']) ? $section['answers'] : [];
              $questions = isset($section['questions']) && is_array($section['questions']) ? $section['questions'] : [];
            ?>
            <div class="rounded-2xl border border-zinc-800 bg-zinc-950/30 p-4">
              <div class="mb-4">
                <div class="text-base font-semibold text-zinc-100"><?= htmlspecialchars((string) ($section['title'] ?? 'Evaluation Section')) ?></div>
                <div class="text-sm text-zinc-400 mt-1"><?= htmlspecialchars((string) ($section['subtitle'] ?? '')) ?></div>
              </div>

              <div class="space-y-4">
                <?php foreach ($questions as $question): ?>
                  <?php
                    $questionId = trim((string) ($question['id'] ?? ''));
                    $questionText = (string) ($question['question_text'] ?? '');
                    $fieldType = (string) ($question['field_type'] ?? 'text');
                    $required = !empty($question['required']);
                    $value = isset($sectionAnswers[$questionId]) ? (string) $sectionAnswers[$questionId] : '';
                  ?>
                  <div class="rounded-xl border border-zinc-800 bg-zinc-950/20 p-4">
                    <div class="flex items-start justify-between gap-3">
                      <div class="text-sm font-medium text-zinc-100"><?= htmlspecialchars($questionText) ?></div>
                      <?php if ($required): ?>
                        <div class="text-xs px-2 py-1 rounded-full bg-zinc-900 border border-zinc-700 text-zinc-200">Required</div>
                      <?php endif; ?>
                    </div>

                    <div class="mt-3">
                      <?php if ($fieldType === 'rating'): ?>
                        <select
                          class="w-full rounded-lg bg-zinc-950 border border-zinc-800 px-3 py-2.5 text-sm outline-none focus:ring-2 focus:ring-zinc-700"
                          data-question-id="<?= htmlspecialchars($questionId) ?>"
                          data-session-id="<?= htmlspecialchars($section['scope'] === 'session' ? $scopeId : '') ?>"
                          name="answer_<?= htmlspecialchars($questionId) ?>"
                        >
                          <option value="">Select rating</option>
                          <?php for ($i = 1; $i <= 5; $i++): ?>
                            <option value="<?= $i ?>" <?= (string) $i === $value ? 'selected' : '' ?>><?= $i ?></option>
                          <?php endfor; ?>
                        </select>
                      <?php else: ?>
                        <textarea
                          rows="3"
                          class="w-full rounded-lg bg-zinc-950 border border-zinc-800 px-3 py-2.5 text-sm outline-none focus:ring-2 focus:ring-zinc-700"
                          data-question-id="<?= htmlspecialchars($questionId) ?>"
                          data-session-id="<?= htmlspecialchars($section['scope'] === 'session' ? $scopeId : '') ?>"
                          name="answer_<?= htmlspecialchars($questionId) ?>"
                        ><?= htmlspecialchars($value) ?></textarea>
                      <?php endif; ?>
                    </div>
                  </div>
                <?php endforeach; ?>
              </div>
            </div>
          <?php endforeach; ?>
        </div>

        <div class="flex items-center gap-3">
          <button type="submit" class="rounded-lg bg-zinc-100 text-zinc-900 px-4 py-2.5 text-sm font-medium hover:bg-zinc-200 transition">
            Submit evaluation
          </button>
          <div id="evalMsg" class="text-sm text-zinc-300"></div>
        </div>
      </form>
    <?php endif; ?>
  </div>

  <div class="rounded-2xl border border-zinc-800 bg-zinc-900/30 p-6">
    <div class="text-sm font-medium">How it works</div>
    <div class="mt-3 text-sm text-zinc-400 leading-relaxed">
      Simple events show one event feedback form. Seminar-based events automatically show the whole-event questions plus only the seminar sections where your attendance was recorded.
    </div>
  </div>
</div>

<script>
  const eventSel = document.getElementById('eventSel');

  eventSel?.addEventListener('change', () => {
    const params = new URLSearchParams();
    params.set('event_id', eventSel.value);
    window.location.href = '/evaluation.php?' + params.toString();
  });

  document.getElementById('evalForm')?.addEventListener('submit', async (e) => {
    e.preventDefault();
    const event_id = document.getElementById('event_id').value;
    const msg = document.getElementById('evalMsg');
    msg.textContent = 'Submitting...';

    const answers = [];
    document.querySelectorAll('[data-question-id]').forEach((el) => {
      const answer = {
        question_id: el.dataset.questionId,
        answer_text: el.value || ''
      };
      if (el.dataset.sessionId) {
        answer.session_id = el.dataset.sessionId;
      }
      answers.push(answer);
    });

    try {
      const res = await fetch('/api/evaluation_submit.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          event_id,
          answers,
          csrf_token: window.CSRF_TOKEN
        })
      });
      const data = await res.json();
      if (!data.ok) throw new Error(data.error || 'Failed');
      msg.textContent = 'Submitted successfully.';
    } catch (err) {
      msg.textContent = err.message || 'Failed';
    }
  });
</script>

<?php render_footer(); ?>
