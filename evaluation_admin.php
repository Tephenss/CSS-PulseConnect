<?php
declare(strict_types=1);

session_start();

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/supabase.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/event_sessions.php';
require_once __DIR__ . '/includes/event_tabs.php';

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

$eventUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/events?select=id,title,status,created_by&id=eq.' . rawurlencode($eventId) . '&limit=1';
$eventRes = supabase_request('GET', $eventUrl, $headers);
$eventRows = $eventRes['ok'] ? json_decode((string) $eventRes['body'], true) : [];
$event = is_array($eventRows) && isset($eventRows[0]) ? $eventRows[0] : null;

if (!is_array($event)) {
    http_response_code(404);
    echo 'Event not found';
    exit;
}

if ($role === 'teacher' && (string) ($event['created_by'] ?? '') !== $userId) {
    http_response_code(403);
    echo 'Forbidden';
    exit;
}

$sessions = fetch_event_sessions($eventId, $headers);
$usesSessions = count($sessions) > 0;
$isFinishedEvent = strtolower(trim((string) ($event['status'] ?? ''))) === 'finished';
if ($isFinishedEvent && $tab === 'questions') {
    header('Location: /evaluation_admin.php?event_id=' . rawurlencode($eventId) . '&tab=feedback');
    exit;
}

$statusColor = match((string) ($event['status'] ?? '')) {
    'published' => 'bg-emerald-100 text-emerald-900 border-emerald-200',
    'finished' => 'bg-zinc-200 text-zinc-700 border-zinc-300',
    'pending' => 'bg-amber-100 text-amber-900 border-amber-200',
    'approved' => 'bg-sky-100 text-sky-900 border-sky-200',
    default => 'bg-zinc-100 text-zinc-800 border-zinc-200',
};

function feedback_attendance_counts_as_present(array $row): bool
{
    $status = strtolower(trim((string) ($row['status'] ?? '')));
    $checkInAt = trim((string) ($row['check_in_at'] ?? ''));

    if ($checkInAt !== '') {
        return true;
    }

    return in_array($status, ['present', 'scanned', 'late', 'early'], true);
}

function feedback_ticket_student_map(string $eventId, array $headers): array
{
    $url = rtrim(SUPABASE_URL, '/') . '/rest/v1/event_registrations'
        . '?select=student_id,tickets(id)'
        . '&event_id=eq.' . rawurlencode($eventId);
    $res = supabase_request('GET', $url, $headers);
    if (!$res['ok']) {
        return [];
    }

    $rows = json_decode((string) ($res['body'] ?? ''), true);
    if (!is_array($rows)) {
        return [];
    }

    $ticketToStudent = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }

        $studentId = trim((string) ($row['student_id'] ?? ''));
        if ($studentId === '') {
            continue;
        }

        $tickets = isset($row['tickets']) && is_array($row['tickets']) ? $row['tickets'] : [];
        foreach ($tickets as $ticketRow) {
            if (!is_array($ticketRow)) {
                continue;
            }

            $ticketId = trim((string) ($ticketRow['id'] ?? ''));
            if ($ticketId !== '') {
                $ticketToStudent[$ticketId] = $studentId;
            }
        }
    }

    return $ticketToStudent;
}

function feedback_missing_table(array $response, string $table): bool
{
    $body = strtolower((string) ($response['body'] ?? ''));
    $error = strtolower((string) ($response['error'] ?? ''));
    $needle = strtolower($table);

    return str_contains($body, $needle) && (
        str_contains($body, 'does not exist')
        || str_contains($body, 'schema cache')
        || str_contains($body, 'could not find the table')
        || str_contains($body, '42p01')
    ) || (
        str_contains($error, $needle) && (
            str_contains($error, 'does not exist')
            || str_contains($error, 'schema cache')
            || str_contains($error, 'could not find the table')
            || str_contains($error, '42p01')
        )
    );
}

function feedback_name_map(array $studentIds, array $headers): array
{
    $studentIds = array_values(array_unique(array_filter(array_map(
        static fn ($id): string => trim((string) $id),
        $studentIds
    ))));
    if (count($studentIds) === 0) {
        return [];
    }

    $url = rtrim(SUPABASE_URL, '/') . '/rest/v1/users'
        . '?select=id,first_name,middle_name,last_name,suffix'
        . '&id=in.(' . implode(',', array_map('rawurlencode', $studentIds)) . ')';
    $res = supabase_request('GET', $url, $headers);
    if (!$res['ok']) {
        return [];
    }

    $rows = json_decode((string) $res['body'], true);
    if (!is_array($rows)) {
        return [];
    }

    $map = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }

        $studentId = trim((string) ($row['id'] ?? ''));
        if ($studentId === '') {
            continue;
        }

        $parts = array_values(array_filter([
            trim((string) ($row['first_name'] ?? '')),
            trim((string) ($row['middle_name'] ?? '')),
            trim((string) ($row['last_name'] ?? '')),
        ], static fn (string $value): bool => $value !== ''));
        $name = trim(implode(' ', $parts));
        $suffix = trim((string) ($row['suffix'] ?? ''));
        if ($suffix !== '') {
            $name = $name === '' ? $suffix : $name . ' ' . $suffix;
        }

        $map[$studentId] = $name !== '' ? $name : $studentId;
    }

    return $map;
}

function feedback_section_summary(
    string $label,
    string $description,
    array $questions,
    array $participantIds,
    array $answerRows,
    array $nameMap
): array {
    $participantIds = array_values(array_unique(array_filter(array_map(
        static fn ($id): string => trim((string) $id),
        $participantIds
    ))));
    $participantMap = array_fill_keys($participantIds, true);

    $respondentMap = [];
    foreach ($answerRows as $row) {
        $studentId = trim((string) ($row['student_id'] ?? ''));
        if ($studentId !== '' && isset($participantMap[$studentId])) {
            $respondentMap[$studentId] = true;
        }
    }

    $totalParticipants = count($participantIds);
    $totalResponses = count($respondentMap);
    $pendingParticipants = max(0, $totalParticipants - $totalResponses);
    $responseRate = $totalParticipants > 0 ? round(($totalResponses / $totalParticipants) * 100, 1) : 0.0;

    $ratingAnalytics = [];
    $textFeedback = [];

    foreach ($questions as $question) {
        $questionId = trim((string) ($question['id'] ?? ''));
        if ($questionId === '') {
            continue;
        }

        $fieldType = (string) ($question['field_type'] ?? 'text');
        if ($fieldType === 'rating') {
            $distribution = ['5' => 0, '4' => 0, '3' => 0, '2' => 0, '1' => 0];
            $sum = 0;
            $count = 0;

            foreach ($answerRows as $row) {
                $studentId = trim((string) ($row['student_id'] ?? ''));
                if ($studentId === '' || !isset($participantMap[$studentId])) {
                    continue;
                }
                if ((string) ($row['question_id'] ?? '') !== $questionId) {
                    continue;
                }

                $value = (int) ((string) ($row['answer_text'] ?? ''));
                if ($value < 1 || $value > 5) {
                    continue;
                }

                $distribution[(string) $value]++;
                $sum += $value;
                $count++;
            }

            $ratingAnalytics[] = [
                'question_text' => (string) ($question['question_text'] ?? ''),
                'avg' => $count > 0 ? round($sum / $count, 1) : 0,
                'count' => $count,
                'dist' => $distribution,
            ];
            continue;
        }

        $responses = [];
        foreach ($answerRows as $row) {
            $studentId = trim((string) ($row['student_id'] ?? ''));
            if ($studentId === '' || !isset($participantMap[$studentId])) {
                continue;
            }
            if ((string) ($row['question_id'] ?? '') !== $questionId) {
                continue;
            }

            $answerText = trim((string) ($row['answer_text'] ?? ''));
            if ($answerText === '') {
                continue;
            }

            $responses[] = [
                'student_name' => $nameMap[$studentId] ?? $studentId,
                'answer_text' => $answerText,
            ];
        }

        if (count($responses) > 0) {
            $textFeedback[] = [
                'question_text' => (string) ($question['question_text'] ?? ''),
                'responses' => $responses,
            ];
        }
    }

    return [
        'label' => $label,
        'description' => $description,
        'total_participants' => $totalParticipants,
        'total_responses' => $totalResponses,
        'pending_participants' => $pendingParticipants,
        'response_rate' => $responseRate,
        'rating_analytics' => $ratingAnalytics,
        'text_feedback' => $textFeedback,
        'has_feedback' => $totalResponses > 0,
    ];
}

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
    if ($usesSessions) {
        $sessionIds = [];
        foreach ($sessions as $session) {
            $sid = (string) ($session['id'] ?? '');
            if ($sid !== '') {
                $sessionIds[] = $sid;
            }
        }

        $attendanceRows = [];
        if (count($sessionIds) > 0) {
            $ticketToStudent = feedback_ticket_student_map($eventId, $headers);
            $sessionFilter = implode(',', array_map('rawurlencode', $sessionIds));

            $attendanceUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/event_session_attendance'
                . '?select=session_id,status,check_in_at,registration:event_registrations(student_id)'
                . '&session_id=in.(' . $sessionFilter . ')';
            $attendanceRes = supabase_request('GET', $attendanceUrl, $headers);
            $attendanceRows = $attendanceRes['ok'] ? json_decode((string) $attendanceRes['body'], true) : [];
            $attendanceRows = is_array($attendanceRows) ? $attendanceRows : [];

            $legacyAttendanceUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/attendance'
                . '?select=session_id,ticket_id,status,check_in_at'
                . '&session_id=in.(' . $sessionFilter . ')';
            $legacyAttendanceRes = supabase_request('GET', $legacyAttendanceUrl, $headers);
            $legacyAttendanceRows = $legacyAttendanceRes['ok'] ? json_decode((string) $legacyAttendanceRes['body'], true) : [];
            $legacyAttendanceRows = is_array($legacyAttendanceRows) ? $legacyAttendanceRows : [];

            foreach ($legacyAttendanceRows as $legacyRow) {
                if (!is_array($legacyRow)) {
                    continue;
                }

                $ticketId = trim((string) ($legacyRow['ticket_id'] ?? ''));
                $studentId = $ticketId !== '' ? trim((string) ($ticketToStudent[$ticketId] ?? '')) : '';
                if ($studentId === '') {
                    continue;
                }

                $attendanceRows[] = [
                    'session_id' => $legacyRow['session_id'] ?? null,
                    'status' => $legacyRow['status'] ?? null,
                    'check_in_at' => $legacyRow['check_in_at'] ?? null,
                    'student_id' => $studentId,
                ];
            }
        }

        $presentBySession = [];
        $eventParticipantIds = [];
        foreach ($attendanceRows as $row) {
            if (!is_array($row) || !feedback_attendance_counts_as_present($row)) {
                continue;
            }

            $sid = trim((string) ($row['session_id'] ?? ''));
            $registration = isset($row['registration']) && is_array($row['registration']) ? $row['registration'] : [];
            $studentId = trim((string) ($row['student_id'] ?? ''));
            if ($studentId === '') {
                $studentId = trim((string) ($registration['student_id'] ?? ''));
            }
            if ($sid === '' || $studentId === '') {
                continue;
            }

            if (!isset($presentBySession[$sid])) {
                $presentBySession[$sid] = [];
            }
            $presentBySession[$sid][$studentId] = true;
            $eventParticipantIds[$studentId] = true;
        }

        $eventParticipantIds = array_values(array_keys($eventParticipantIds));
        $nameMap = feedback_name_map($eventParticipantIds, $headers);

        $eventAnswerRows = [];
        if (count($eventQuestions) > 0) {
            $eventAnswersUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/evaluation_answers'
                . '?select=student_id,question_id,answer_text'
                . '&event_id=eq.' . rawurlencode($eventId);
            $eventAnswersRes = supabase_request('GET', $eventAnswersUrl, $headers);
            $eventAnswerRows = $eventAnswersRes['ok'] ? json_decode((string) $eventAnswersRes['body'], true) : [];
            $eventAnswerRows = is_array($eventAnswerRows) ? $eventAnswerRows : [];
        }

        $feedbackSections[] = feedback_section_summary(
            'Event Feedback',
            count($eventQuestions) > 0
                ? 'Responses to the whole-event evaluation.'
                : 'No event-level questions have been created yet.',
            $eventQuestions,
            $eventParticipantIds,
            $eventAnswerRows,
            $nameMap
        );

        $sessionAnswerRows = [];
        if (count($sessionIds) > 0) {
            $sessionAnswersUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/event_session_evaluation_answers'
                . '?select=session_id,student_id,question_id,answer_text'
                . '&session_id=in.(' . implode(',', array_map('rawurlencode', $sessionIds)) . ')';
            $sessionAnswersRes = supabase_request('GET', $sessionAnswersUrl, $headers);
            if ($sessionAnswersRes['ok']) {
                $sessionAnswerRows = json_decode((string) $sessionAnswersRes['body'], true);
            } elseif (feedback_missing_table($sessionAnswersRes, 'event_session_evaluation_answers')) {
                $sessionAnswerRows = [];
            }
            $sessionAnswerRows = is_array($sessionAnswerRows) ? $sessionAnswerRows : [];
        }

        $answersBySession = [];
        foreach ($sessionAnswerRows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $sid = trim((string) ($row['session_id'] ?? ''));
            if ($sid === '') {
                continue;
            }
            if (!isset($answersBySession[$sid])) {
                $answersBySession[$sid] = [];
            }
            $answersBySession[$sid][] = $row;
        }

        foreach ($sessions as $session) {
            $sid = (string) ($session['id'] ?? '');
            $questionGroups = $sessionQuestionGroups[$sid] ?? [];
            $sessionQuestionList = [];
            foreach ($questionGroups as $items) {
                foreach ($items as $item) {
                    $sessionQuestionList[] = $item;
                }
            }

            $feedbackSections[] = feedback_section_summary(
                build_session_display_name($session),
                count($sessionQuestionList) > 0
                    ? 'Responses for this seminar only.'
                    : 'No seminar questions have been created yet for this session.',
                $sessionQuestionList,
                array_values(array_keys($presentBySession[$sid] ?? [])),
                $answersBySession[$sid] ?? [],
                $nameMap
            );
        }
    } else {
        $attendanceUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/attendance'
            . '?select=status,check_in_at,tickets(registration_id,event_registrations(student_id,event_id))'
            . '&tickets.event_registrations.event_id=eq.' . rawurlencode($eventId);
        $attendanceRes = supabase_request('GET', $attendanceUrl, $headers);
        $attendanceRows = $attendanceRes['ok'] ? json_decode((string) $attendanceRes['body'], true) : [];
        $attendanceRows = is_array($attendanceRows) ? $attendanceRows : [];

        $participantIds = [];
        foreach ($attendanceRows as $row) {
            if (!is_array($row) || !feedback_attendance_counts_as_present($row)) {
                continue;
            }

            $ticket = isset($row['tickets']) && is_array($row['tickets']) ? $row['tickets'] : [];
            $registration = isset($ticket['event_registrations']) && is_array($ticket['event_registrations'])
                ? $ticket['event_registrations']
                : [];
            $studentId = trim((string) ($registration['student_id'] ?? ''));
            if ($studentId !== '') {
                $participantIds[$studentId] = true;
            }
        }

        $participantIds = array_values(array_keys($participantIds));
        $nameMap = feedback_name_map($participantIds, $headers);

        $answerRows = [];
        if (count($eventQuestions) > 0) {
            $answersUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/evaluation_answers'
                . '?select=student_id,question_id,answer_text'
                . '&event_id=eq.' . rawurlencode($eventId);
            $answersRes = supabase_request('GET', $answersUrl, $headers);
            $answerRows = $answersRes['ok'] ? json_decode((string) $answersRes['body'], true) : [];
            $answerRows = is_array($answerRows) ? $answerRows : [];
        }

        $feedbackSections[] = feedback_section_summary(
            'Event Feedback',
            count($eventQuestions) > 0
                ? 'Responses to the whole-event evaluation.'
                : 'No event-level questions have been created yet.',
            $eventQuestions,
            $participantIds,
            $answerRows,
            $nameMap
        );
    }
}

render_header('Evaluation Management', $user);
?>

<div class="mb-4">
  <div class="flex items-center justify-between flex-wrap gap-4 pb-4 border-b border-zinc-200 mb-6">
    <div class="flex items-center gap-3">
      <a href="/manage_events.php" class="flex items-center justify-center w-8 h-8 rounded-full bg-white border border-zinc-200 hover:bg-zinc-50 text-zinc-600 transition shadow-sm">
        <svg class="w-4 h-4 mr-0.5" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5L8.25 12l7.5-7.5"/></svg>
      </a>
      <h2 class="text-xl md:text-2xl font-bold text-zinc-900"><?= htmlspecialchars((string) ($event['title'] ?? '')) ?></h2>
      <span class="text-[10px] sm:text-xs font-bold uppercase tracking-widest rounded-md border px-2 py-0.5 <?= $statusColor ?>"><?= htmlspecialchars((string) ($event['status'] ?? '')) ?></span>
    </div>
  </div>

  <?php
  render_event_tabs([
      'event_id' => $eventId,
      'current_tab' => $tab === 'questions' ? 'questions' : 'feedback',
      'role' => $role,
      'uses_sessions' => $usesSessions,
      'event_status' => (string) ($event['status'] ?? ''),
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
    <div class="max-w-5xl mx-auto space-y-6">
      <?php foreach ($feedbackSections as $section): ?>
        <div class="rounded-3xl border border-zinc-200 bg-white p-6 shadow-sm">
          <div class="mb-5">
            <h3 class="text-xl font-bold text-zinc-900 tracking-tight"><?= htmlspecialchars((string) ($section['label'] ?? 'Feedback')) ?></h3>
            <p class="text-sm mt-1 text-zinc-500"><?= htmlspecialchars((string) ($section['description'] ?? '')) ?></p>
          </div>

          <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 mb-6">
            <div class="rounded-2xl border border-zinc-200 bg-white p-4 shadow-sm">
              <p class="text-[11px] font-bold uppercase tracking-wider text-zinc-500">Participants</p>
              <p class="text-2xl font-black text-zinc-900 leading-tight mt-1"><?= (int) ($section['total_participants'] ?? 0) ?></p>
            </div>
            <div class="rounded-2xl border border-emerald-200 bg-emerald-50 p-4 shadow-sm">
              <p class="text-[11px] font-bold uppercase tracking-wider text-emerald-700">Answered</p>
              <p class="text-2xl font-black text-emerald-900 leading-tight mt-1"><?= (int) ($section['total_responses'] ?? 0) ?></p>
            </div>
            <div class="rounded-2xl border border-amber-200 bg-amber-50 p-4 shadow-sm">
              <p class="text-[11px] font-bold uppercase tracking-wider text-amber-700">Pending</p>
              <p class="text-2xl font-black text-amber-900 leading-tight mt-1"><?= (int) ($section['pending_participants'] ?? 0) ?></p>
            </div>
          </div>

          <?php if (empty($section['has_feedback'])): ?>
            <div class="rounded-3xl bg-zinc-50 border border-zinc-200 p-10 text-center">
              <h4 class="text-lg font-bold text-zinc-900 mb-1">No Feedback Yet</h4>
              <p class="text-sm text-zinc-500">No attendee has submitted feedback for this section yet.</p>
            </div>
          <?php else: ?>
            <div class="mb-6">
              <p class="text-sm text-zinc-600 font-medium">
                <span class="font-bold text-zinc-900"><?= (int) ($section['total_responses'] ?? 0) ?></span> out of
                <span class="font-bold text-zinc-900"><?= (int) ($section['total_participants'] ?? 0) ?></span> responses
                <span class="ml-2 px-2.5 py-0.5 rounded-full text-xs font-bold <?= ((float) ($section['response_rate'] ?? 0)) >= 70 ? 'bg-emerald-100 text-emerald-800 border border-emerald-200' : 'bg-amber-100 text-amber-800 border border-amber-200' ?>">
                  <?= htmlspecialchars((string) ($section['response_rate'] ?? 0)) ?>%
                </span>
              </p>
            </div>

            <?php if (!empty($section['rating_analytics'])): ?>
              <div class="space-y-4 mb-6">
                <?php foreach (($section['rating_analytics'] ?? []) as $item): ?>
                  <div class="rounded-2xl border border-zinc-200 bg-zinc-50 p-5">
                    <div class="flex items-center justify-between gap-4 mb-4">
                      <div class="text-base font-semibold text-zinc-900"><?= htmlspecialchars((string) ($item['question_text'] ?? '')) ?></div>
                      <div class="text-sm font-bold text-emerald-700">Avg. <?= htmlspecialchars((string) ($item['avg'] ?? 0)) ?>/5</div>
                    </div>
                    <div class="grid grid-cols-5 gap-3">
                      <?php foreach (($item['dist'] ?? []) as $rating => $count): ?>
                        <div class="rounded-xl border border-zinc-200 bg-white p-3 text-center">
                          <div class="text-xs font-bold uppercase tracking-wider text-zinc-500"><?= htmlspecialchars((string) $rating) ?> Star</div>
                          <div class="text-lg font-black text-zinc-900 mt-1"><?= htmlspecialchars((string) $count) ?></div>
                        </div>
                      <?php endforeach; ?>
                    </div>
                  </div>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>

            <?php if (!empty($section['text_feedback'])): ?>
              <div class="space-y-4">
                <?php foreach (($section['text_feedback'] ?? []) as $item): ?>
                  <div class="rounded-2xl border border-zinc-200 bg-zinc-50 p-5">
                    <div class="text-base font-semibold text-zinc-900 mb-4"><?= htmlspecialchars((string) ($item['question_text'] ?? '')) ?></div>
                    <div class="space-y-3">
                      <?php foreach (($item['responses'] ?? []) as $response): ?>
                        <div class="rounded-2xl border border-zinc-200 bg-white p-4">
                          <div class="text-xs font-bold uppercase tracking-widest text-zinc-500 mb-2"><?= htmlspecialchars((string) ($response['student_name'] ?? 'Student')) ?></div>
                          <div class="text-sm text-zinc-700 leading-relaxed whitespace-pre-line"><?= htmlspecialchars((string) ($response['answer_text'] ?? '')) ?></div>
                        </div>
                      <?php endforeach; ?>
                    </div>
                  </div>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          <?php endif; ?>
        </div>
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
</script>

<?php render_footer(); ?>
