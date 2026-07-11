<?php
declare(strict_types=1);

session_start();

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/supabase.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/event_targeting.php';
require_once __DIR__ . '/includes/event_sessions.php';
require_once __DIR__ . '/includes/event_tabs.php';
require_once __DIR__ . '/includes/student_requirements.php';

$user = require_role(['teacher']);
$role = (string) ($user['role'] ?? 'teacher');
$userId = (string) ($user['id'] ?? '');

$eventId = trim((string) ($_GET['event_id'] ?? ''));
if ($eventId === '') {
    http_response_code(400);
    echo 'Missing event_id';
    exit;
}

$headers = student_requirement_headers();
$eventUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/events'
    . '?select=id,title,status,created_by,start_at,end_at'
    . '&id=eq.' . rawurlencode($eventId)
    . '&limit=1';
$eventRes = supabase_request('GET', $eventUrl, $headers);
$eventRows = $eventRes['ok'] ? json_decode((string) $eventRes['body'], true) : null;
$event = is_array($eventRows) && isset($eventRows[0]) ? $eventRows[0] : null;

if (!is_array($event)) {
    http_response_code(404);
    echo 'Event not found';
    exit;
}

if ((string) ($event['created_by'] ?? '') !== $userId) {
    http_response_code(403);
    echo 'Only the event creator can review student documents.';
    exit;
}

$requirements = fetch_student_requirements_map([$eventId], $headers)[$eventId] ?? [];
if ($requirements === []) {
    http_response_code(404);
    echo 'This event has no student document requirements.';
    exit;
}

$sessions = fetch_event_sessions($eventId, $headers);
$usesSessions = count($sessions) > 0;
$backHref = '/manage_events.php';
$returnTo = $backHref;
$returnToQuery = '&return_to=' . rawurlencode($returnTo);

$submissionsMap = fetch_student_submissions_map([$eventId], $headers)[$eventId] ?? [];
$documentsMap = fetch_student_documents_map([$eventId], $headers)[$eventId] ?? [];

$studentIds = array_keys($submissionsMap);
$studentsById = [];
if ($studentIds !== []) {
    $inList = '(' . implode(',', array_map('rawurlencode', $studentIds)) . ')';
    $studentsUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/users'
        . '?select=id,first_name,middle_name,last_name,suffix,email,student_id,course,sections(name)'
        . '&id=in.' . $inList
        . '&role=eq.student'
        . '&limit=1000';
    $studentsRes = supabase_request('GET', $studentsUrl, $headers);
    $studentRows = $studentsRes['ok'] ? json_decode((string) $studentsRes['body'], true) : [];
    if (is_array($studentRows)) {
        foreach ($studentRows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $sid = trim((string) ($row['id'] ?? ''));
            if ($sid === '') {
                continue;
            }
            $row['display_name'] = compose_student_display_name($row);
            $row['section_name'] = extract_section_name($row['sections'] ?? null);
            $studentsById[$sid] = $row;
        }
    }
}

$reviewRows = [];
foreach ($submissionsMap as $studentId => $submission) {
    if (!is_array($submission)) {
        continue;
    }
    $student = $studentsById[$studentId] ?? null;
    $documents = $documentsMap[$studentId] ?? [];
    $summary = build_student_requirement_summary($requirements, $documents);
    $reviewRows[] = [
        'student_id' => $studentId,
        'student' => $student,
        'submission' => $submission,
        'documents' => $documents,
        'summary' => $summary,
    ];
}

usort($reviewRows, static function (array $a, array $b): int {
    $statusOrder = ['pending_review' => 0, 'declined' => 1, 'approved' => 2];
    $aStatus = strtolower(trim((string) ($a['submission']['status'] ?? '')));
    $bStatus = strtolower(trim((string) ($b['submission']['status'] ?? '')));
    $aRank = $statusOrder[$aStatus] ?? 9;
    $bRank = $statusOrder[$bStatus] ?? 9;
    if ($aRank !== $bRank) {
        return $aRank <=> $bRank;
    }

    $aTime = strtotime((string) ($a['submission']['submitted_at'] ?? '')) ?: 0;
    $bTime = strtotime((string) ($b['submission']['submitted_at'] ?? '')) ?: 0;
    return $bTime <=> $aTime;
});

$pendingCount = 0;
$approvedCount = 0;
$declinedCount = 0;
foreach ($reviewRows as $row) {
    $status = strtolower(trim((string) ($row['submission']['status'] ?? '')));
    if ($status === 'pending_review') {
        $pendingCount++;
    } elseif ($status === 'approved') {
        $approvedCount++;
    } elseif ($status === 'declined') {
        $declinedCount++;
    }
}

render_header('Document Review', $user);
?>

<div class="mb-4">
  <div class="flex items-center justify-between flex-wrap gap-4 pb-4 border-b border-zinc-200 mb-6">
    <div class="flex items-center gap-3">
      <a href="<?= htmlspecialchars($backHref) ?>" class="flex items-center justify-center w-8 h-8 rounded-full bg-white border border-zinc-200 hover:bg-zinc-50 text-zinc-600 transition shadow-sm">
        <svg class="w-4 h-4 mr-0.5" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5L8.25 12l7.5-7.5"/></svg>
      </a>
      <div>
        <h2 class="text-xl md:text-2xl font-bold text-zinc-900 leading-tight"><?= htmlspecialchars((string) ($event['title'] ?? 'Event')) ?></h2>
        <p class="text-sm text-zinc-500 mt-1">Review student document submissions before they can register.</p>
      </div>
    </div>
  </div>
</div>

<?php
render_event_tabs([
    'event_id' => $eventId,
    'current_tab' => 'document_review',
    'role' => $role,
    'uses_sessions' => $usesSessions,
    'event_status' => (string) ($event['status'] ?? ''),
    'return_to' => $returnTo,
    'has_student_requirements' => true,
    'is_event_creator' => true,
]);
?>

<?php
$reviewModalData = [];
foreach ($reviewRows as $row) {
    $student = is_array($row['student']) ? $row['student'] : [];
    $submission = is_array($row['submission']) ? $row['submission'] : [];
    $documents = is_array($row['documents']) ? $row['documents'] : [];
    $studentId = (string) ($row['student_id'] ?? '');
    if ($studentId === '') {
        continue;
    }

    $status = strtolower(trim((string) ($submission['status'] ?? '')));
    if ($status === '') {
        $status = 'pending_review';
    }

    $documentsByRequirement = [];
    foreach ($documents as $doc) {
        if (!is_array($doc)) {
            continue;
        }
        $reqId = trim((string) ($doc['requirement_id'] ?? ''));
        if ($reqId !== '') {
            $documentsByRequirement[$reqId] = $doc;
        }
    }

    $docItems = [];
    foreach ($requirements as $requirement) {
        if (!is_array($requirement)) {
            continue;
        }
        $reqId = trim((string) ($requirement['id'] ?? ''));
        $doc = $documentsByRequirement[$reqId] ?? null;
        $docItems[] = [
            'label' => (string) ($requirement['label'] ?? 'Requirement'),
            'file_name' => is_array($doc) ? (string) ($doc['file_name'] ?? 'View document') : '',
            'file_url' => is_array($doc) ? (string) ($doc['file_url'] ?? '') : '',
            'uploaded' => is_array($doc),
        ];
    }

    $reviewModalData[$studentId] = [
        'student_id' => $studentId,
        'display_name' => (string) ($student['display_name'] ?? 'Student'),
        'email' => (string) ($student['email'] ?? ''),
        'student_no' => (string) ($student['student_id'] ?? ''),
        'section_name' => (string) ($student['section_name'] ?? ''),
        'status' => $status,
        'status_label' => match ($status) {
            'approved' => 'Approved',
            'declined' => 'Declined',
            default => 'Pending',
        },
        'submitted_at' => !empty($submission['submitted_at'])
            ? format_date_local((string) $submission['submitted_at'], 'M j, Y g:i A')
            : '',
        'decline_reason' => trim((string) ($submission['decline_reason'] ?? '')),
        'documents' => $docItems,
    ];
}
?>

<div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-4">
  <div class="flex flex-wrap items-center gap-2 text-xs font-semibold">
    <span class="inline-flex items-center gap-1.5 rounded-lg border border-amber-200 bg-amber-50 px-2.5 py-1 text-amber-800">
      Pending <span class="rounded bg-amber-200/80 px-1.5 py-0.5 text-[11px] font-bold"><?= (int) $pendingCount ?></span>
    </span>
    <span class="inline-flex items-center gap-1.5 rounded-lg border border-emerald-200 bg-emerald-50 px-2.5 py-1 text-emerald-800">
      Approved <span class="rounded bg-emerald-200/80 px-1.5 py-0.5 text-[11px] font-bold"><?= (int) $approvedCount ?></span>
    </span>
    <span class="inline-flex items-center gap-1.5 rounded-lg border border-red-200 bg-red-50 px-2.5 py-1 text-red-800">
      Declined <span class="rounded bg-red-200/80 px-1.5 py-0.5 text-[11px] font-bold"><?= (int) $declinedCount ?></span>
    </span>
    <span class="text-zinc-400">·</span>
    <span class="text-zinc-500"><?= count($reviewRows) ?> submission<?= count($reviewRows) === 1 ? '' : 's' ?></span>
  </div>
  <?php if ($reviewRows !== []): ?>
  <div class="flex flex-wrap items-center gap-2">
    <input type="search" id="reviewSearch" placeholder="Search name, email, ID…"
      class="w-full sm:w-56 rounded-lg border border-zinc-300 bg-white px-3 py-1.5 text-sm text-zinc-900 outline-none focus:border-sky-400 focus:ring-2 focus:ring-sky-500/20">
    <select id="reviewStatusFilter"
      class="rounded-lg border border-zinc-300 bg-white px-3 py-1.5 text-sm text-zinc-700 outline-none focus:border-sky-400 focus:ring-2 focus:ring-sky-500/20">
      <option value="all">All statuses</option>
      <option value="pending_review" selected>Pending only</option>
      <option value="approved">Approved only</option>
      <option value="declined">Declined only</option>
    </select>
  </div>
  <?php endif; ?>
</div>

<?php if ($reviewRows === []): ?>
  <div class="rounded-xl border border-zinc-200 bg-white px-6 py-10 text-center shadow-sm">
    <div class="text-base font-bold text-zinc-900">No submissions yet</div>
    <p class="mt-1 text-sm text-zinc-500">Student document submissions from the app will appear here for review.</p>
  </div>
<?php else: ?>
  <div class="rounded-xl border border-zinc-200 bg-white shadow-sm overflow-hidden">
    <div class="overflow-x-auto">
      <table class="min-w-full divide-y divide-zinc-200 text-sm" id="reviewTable">
        <thead class="bg-zinc-50">
          <tr>
            <th class="px-3 py-2 text-left text-[11px] font-bold uppercase tracking-wider text-zinc-500 w-10">#</th>
            <th class="px-3 py-2 text-left text-[11px] font-bold uppercase tracking-wider text-zinc-500 min-w-[180px]">Student</th>
            <th class="px-3 py-2 text-left text-[11px] font-bold uppercase tracking-wider text-zinc-500 hidden md:table-cell">ID / Section</th>
            <th class="px-3 py-2 text-left text-[11px] font-bold uppercase tracking-wider text-zinc-500">Status</th>
            <th class="px-3 py-2 text-left text-[11px] font-bold uppercase tracking-wider text-zinc-500 hidden lg:table-cell">Submitted</th>
            <th class="px-3 py-2 text-center text-[11px] font-bold uppercase tracking-wider text-zinc-500 w-16">Action</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-zinc-100">
          <?php foreach ($reviewRows as $index => $row): ?>
            <?php
              $student = is_array($row['student']) ? $row['student'] : [];
              $submission = is_array($row['submission']) ? $row['submission'] : [];
              $documents = is_array($row['documents']) ? $row['documents'] : [];
              $studentId = (string) ($row['student_id'] ?? '');
              $status = strtolower(trim((string) ($submission['status'] ?? '')));
              if ($status === '') {
                  $status = 'pending_review';
              }
              $statusClass = match ($status) {
                  'approved' => 'border-emerald-200 bg-emerald-50 text-emerald-700',
                  'declined' => 'border-red-200 bg-red-50 text-red-700',
                  default => 'border-amber-200 bg-amber-50 text-amber-700',
              };
              $statusLabel = match ($status) {
                  'approved' => 'Approved',
                  'declined' => 'Declined',
                  default => 'Pending',
              };
              $documentsByRequirement = [];
              foreach ($documents as $doc) {
                  if (!is_array($doc)) {
                      continue;
                  }
                  $reqId = trim((string) ($doc['requirement_id'] ?? ''));
                  if ($reqId !== '') {
                      $documentsByRequirement[$reqId] = $doc;
                  }
              }
              $displayName = (string) ($student['display_name'] ?? 'Student');
              $email = (string) ($student['email'] ?? '');
              $studentNo = (string) ($student['student_id'] ?? '');
              $sectionName = (string) ($student['section_name'] ?? '');
              $submittedLabel = !empty($submission['submitted_at'])
                  ? format_date_local((string) $submission['submitted_at'], 'M j, g:i A')
                  : '—';
              $searchBlob = strtolower(trim($displayName . ' ' . $email . ' ' . $studentNo . ' ' . $sectionName));
              $declineReason = trim((string) ($submission['decline_reason'] ?? ''));
            ?>
            <tr class="review-row hover:bg-zinc-50/80" data-status="<?= htmlspecialchars($status) ?>" data-search="<?= htmlspecialchars($searchBlob) ?>">
              <td class="px-3 py-2 text-xs text-zinc-400 font-medium"><?= (int) ($index + 1) ?></td>
              <td class="px-3 py-2 min-w-0">
                <div class="font-semibold text-zinc-900 truncate max-w-[220px]" title="<?= htmlspecialchars($displayName) ?>"><?= htmlspecialchars($displayName) ?></div>
                <div class="text-xs text-zinc-500 truncate max-w-[220px]" title="<?= htmlspecialchars($email) ?>"><?= htmlspecialchars($email !== '' ? $email : '—') ?></div>
                <div class="mt-0.5 text-[11px] text-zinc-400 md:hidden">
                  <?= htmlspecialchars($studentNo !== '' ? $studentNo : '—') ?>
                  <?php if ($sectionName !== ''): ?> · <?= htmlspecialchars($sectionName) ?><?php endif; ?>
                </div>
              </td>
              <td class="px-3 py-2 text-xs text-zinc-600 hidden md:table-cell whitespace-nowrap">
                <div><?= htmlspecialchars($studentNo !== '' ? $studentNo : '—') ?></div>
                <div class="text-zinc-400"><?= htmlspecialchars($sectionName !== '' ? $sectionName : '—') ?></div>
              </td>
              <td class="px-3 py-2">
                <span class="inline-flex rounded-full border px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide whitespace-nowrap <?= $statusClass ?>"
                  <?php if ($status === 'declined' && $declineReason !== ''): ?>title="<?= htmlspecialchars($declineReason) ?>"<?php endif; ?>>
                  <?= htmlspecialchars($statusLabel) ?>
                </span>
                <?php if ($status === 'declined' && $declineReason !== ''): ?>
                  <div class="mt-1 max-w-[140px] truncate text-[11px] text-red-600" title="<?= htmlspecialchars($declineReason) ?>"><?= htmlspecialchars($declineReason) ?></div>
                <?php endif; ?>
              </td>
              <td class="px-3 py-2 text-xs text-zinc-500 whitespace-nowrap hidden lg:table-cell"><?= htmlspecialchars($submittedLabel) ?></td>
              <td class="px-3 py-2 text-center">
                <button type="button"
                  class="btn-view-review inline-flex items-center justify-center w-8 h-8 rounded-lg border border-zinc-200 bg-white text-zinc-600 hover:bg-sky-50 hover:border-sky-200 hover:text-sky-700 transition"
                  data-student-id="<?= htmlspecialchars($studentId) ?>"
                  title="View submission">
                  <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z"/><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                </button>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <div id="reviewEmptyState" class="hidden px-4 py-8 text-center text-sm text-zinc-500">
      No submissions match your search or filter.
    </div>
  </div>
<?php endif; ?>

<div id="reviewModal" class="fixed inset-0 z-[60] hidden items-center justify-center p-4 bg-black/50 backdrop-blur-sm">
  <div class="w-full max-w-lg max-h-[90vh] flex flex-col rounded-2xl bg-white shadow-2xl overflow-hidden">
    <div class="flex items-start justify-between gap-3 px-5 py-4 border-b border-zinc-200 shrink-0">
      <div class="min-w-0">
        <h3 id="reviewModalName" class="text-lg font-bold text-zinc-900 truncate"></h3>
        <p id="reviewModalMeta" class="mt-1 text-sm text-zinc-500"></p>
        <div class="mt-2 flex flex-wrap items-center gap-2">
          <span id="reviewModalStatus" class="inline-flex rounded-full border px-2.5 py-0.5 text-[10px] font-bold uppercase tracking-wide"></span>
          <span id="reviewModalSubmitted" class="text-xs text-zinc-400"></span>
        </div>
      </div>
      <button type="button" id="btnCloseReviewModal" class="shrink-0 flex items-center justify-center w-8 h-8 rounded-lg border border-zinc-200 text-zinc-500 hover:bg-zinc-50 transition" title="Close">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
      </button>
    </div>

    <div class="flex-1 overflow-y-auto px-5 py-4">
      <div id="reviewModalDeclineReason" class="hidden mb-4 rounded-xl border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-800"></div>
      <div id="reviewModalDocuments" class="space-y-2"></div>

      <div id="reviewDeclineForm" class="hidden mt-4 rounded-xl border border-red-200 bg-red-50/50 p-4">
        <label for="reviewDeclineReason" class="block text-sm font-semibold text-red-800">Decline reason</label>
        <p class="mt-1 text-xs text-red-600">The student will see this in the app.</p>
        <textarea id="reviewDeclineReason" rows="3" class="mt-2 w-full rounded-lg border border-red-200 bg-white px-3 py-2 text-sm text-zinc-900 outline-none focus:ring-2 focus:ring-red-500/30 focus:border-red-400" placeholder="Explain what needs to be fixed…"></textarea>
      </div>
    </div>

    <div id="reviewModalActions" class="shrink-0 flex items-center justify-end gap-2 px-5 py-4 border-t border-zinc-200 bg-zinc-50">
      <button type="button" id="btnReviewCancelDecline" class="hidden rounded-lg border border-zinc-200 bg-white px-4 py-2 text-sm font-semibold text-zinc-700 hover:bg-zinc-50">Back</button>
      <button type="button" id="btnReviewModalApprove" class="rounded-lg border border-emerald-600 bg-emerald-600 px-4 py-2 text-sm font-bold text-white hover:bg-emerald-700 transition">Approve</button>
      <button type="button" id="btnReviewModalDecline" class="rounded-lg border border-red-200 bg-red-50 px-4 py-2 text-sm font-bold text-red-700 hover:bg-red-100 transition">Decline</button>
      <button type="button" id="btnReviewConfirmDecline" class="hidden rounded-lg border border-red-600 bg-red-600 px-4 py-2 text-sm font-bold text-white hover:bg-red-700 transition">Confirm Decline</button>
    </div>
  </div>
</div>

<script>
  const EVENT_ID = <?= json_encode($eventId, JSON_UNESCAPED_SLASHES) ?>;
  const REVIEW_DATA = <?= json_encode($reviewModalData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
  let activeReviewStudentId = '';

  async function reviewSubmission(studentId, action, reason = '') {
    const res = await fetch('/api/student_requirements_review.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        event_id: EVENT_ID,
        student_id: studentId,
        action,
        reason,
        csrf_token: window.CSRF_TOKEN,
      }),
    });
    const data = await res.json();
    if (!data.ok) {
      throw new Error(data.error || 'Review failed.');
    }
    return data;
  }

  const reviewModal = document.getElementById('reviewModal');
  const reviewModalName = document.getElementById('reviewModalName');
  const reviewModalMeta = document.getElementById('reviewModalMeta');
  const reviewModalStatus = document.getElementById('reviewModalStatus');
  const reviewModalSubmitted = document.getElementById('reviewModalSubmitted');
  const reviewModalDeclineReason = document.getElementById('reviewModalDeclineReason');
  const reviewModalDocuments = document.getElementById('reviewModalDocuments');
  const reviewDeclineForm = document.getElementById('reviewDeclineForm');
  const reviewDeclineReason = document.getElementById('reviewDeclineReason');
  const reviewModalActions = document.getElementById('reviewModalActions');
  const btnReviewModalApprove = document.getElementById('btnReviewModalApprove');
  const btnReviewModalDecline = document.getElementById('btnReviewModalDecline');
  const btnReviewConfirmDecline = document.getElementById('btnReviewConfirmDecline');
  const btnReviewCancelDecline = document.getElementById('btnReviewCancelDecline');

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

  function resetReviewDeclineForm() {
    if (reviewDeclineForm) reviewDeclineForm.classList.add('hidden');
    if (reviewDeclineReason) reviewDeclineReason.value = '';
    btnReviewModalApprove?.classList.remove('hidden');
    btnReviewModalDecline?.classList.remove('hidden');
    btnReviewConfirmDecline?.classList.add('hidden');
    btnReviewCancelDecline?.classList.add('hidden');
  }

  function renderReviewDocuments(documents) {
    if (!reviewModalDocuments) return;
    if (!Array.isArray(documents) || documents.length === 0) {
      reviewModalDocuments.innerHTML = '<p class="text-sm text-zinc-500">No documents uploaded.</p>';
      return;
    }

    reviewModalDocuments.innerHTML = documents.map((doc) => {
      const label = escapeHtml(doc.label || 'Requirement');
      if (doc.uploaded && doc.file_url) {
        const fileName = escapeHtml(doc.file_name || 'View document');
        return `
          <a href="${escapeHtml(doc.file_url)}" target="_blank" rel="noopener"
            class="flex items-center gap-3 rounded-xl border border-zinc-200 bg-zinc-50 px-3 py-2.5 hover:bg-sky-50 hover:border-sky-200 transition">
            <span class="flex items-center justify-center w-8 h-8 rounded-lg border border-sky-200 bg-sky-100 text-sky-700 shrink-0">
              <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m2.25 0H13.5m-3 7.5v7.5m3-7.5H18"/></svg>
            </span>
            <span class="min-w-0">
              <span class="block text-[11px] font-bold uppercase tracking-wide text-zinc-500">${label}</span>
              <span class="block text-sm font-semibold text-sky-700 truncate">${fileName}</span>
            </span>
          </a>`;
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
  }

  function openReviewModal(studentId) {
    const data = REVIEW_DATA[studentId];
    if (!data || !reviewModal) return;

    activeReviewStudentId = studentId;
    resetReviewDeclineForm();

    const status = data.status || 'pending_review';
    const metaParts = [data.email, data.student_no, data.section_name].filter(Boolean);

    if (reviewModalName) reviewModalName.textContent = data.display_name || 'Student';
    if (reviewModalMeta) reviewModalMeta.textContent = metaParts.join(' · ') || '—';
    if (reviewModalStatus) {
      reviewModalStatus.textContent = data.status_label || 'Pending';
      reviewModalStatus.className = 'inline-flex rounded-full border px-2.5 py-0.5 text-[10px] font-bold uppercase tracking-wide ' + (statusClasses[status] || statusClasses.pending_review);
    }
    if (reviewModalSubmitted) {
      reviewModalSubmitted.textContent = data.submitted_at ? `Submitted ${data.submitted_at}` : '';
    }

    if (reviewModalDeclineReason) {
      if (status === 'declined' && data.decline_reason) {
        reviewModalDeclineReason.textContent = `Decline reason: ${data.decline_reason}`;
        reviewModalDeclineReason.classList.remove('hidden');
      } else {
        reviewModalDeclineReason.textContent = '';
        reviewModalDeclineReason.classList.add('hidden');
      }
    }

    renderReviewDocuments(data.documents || []);

    const isPending = status === 'pending_review';
    if (reviewModalActions) {
      reviewModalActions.classList.toggle('hidden', !isPending);
    }
    if (!isPending && reviewModalActions) {
      reviewModalActions.classList.add('hidden');
    } else if (isPending && reviewModalActions) {
      reviewModalActions.classList.remove('hidden');
    }

    reviewModal.classList.remove('hidden');
    reviewModal.classList.add('flex');
  }

  function closeReviewModal() {
    if (!reviewModal) return;
    reviewModal.classList.add('hidden');
    reviewModal.classList.remove('flex');
    activeReviewStudentId = '';
    resetReviewDeclineForm();
  }

  document.querySelectorAll('.btn-view-review').forEach((button) => {
    button.addEventListener('click', () => {
      openReviewModal(button.dataset.studentId || '');
    });
  });

  document.getElementById('btnCloseReviewModal')?.addEventListener('click', closeReviewModal);
  reviewModal?.addEventListener('click', (event) => {
    if (event.target === reviewModal) closeReviewModal();
  });

  btnReviewModalApprove?.addEventListener('click', async () => {
    if (!activeReviewStudentId) return;
    const data = REVIEW_DATA[activeReviewStudentId];
    const studentName = data?.display_name || 'this student';
    if (!confirm(`Approve documents for ${studentName}?`)) return;
    btnReviewModalApprove.disabled = true;
    try {
      await reviewSubmission(activeReviewStudentId, 'approve');
      window.location.reload();
    } catch (error) {
      alert(error.message || 'Failed to approve.');
      btnReviewModalApprove.disabled = false;
    }
  });

  btnReviewModalDecline?.addEventListener('click', () => {
    reviewDeclineForm?.classList.remove('hidden');
    btnReviewModalApprove?.classList.add('hidden');
    btnReviewModalDecline?.classList.add('hidden');
    btnReviewConfirmDecline?.classList.remove('hidden');
    btnReviewCancelDecline?.classList.remove('hidden');
    reviewDeclineReason?.focus();
  });

  btnReviewCancelDecline?.addEventListener('click', resetReviewDeclineForm);

  btnReviewConfirmDecline?.addEventListener('click', async () => {
    if (!activeReviewStudentId) return;
    const reason = (reviewDeclineReason?.value || '').trim();
    if (!reason) {
      alert('Please enter a decline reason.');
      return;
    }
    btnReviewConfirmDecline.disabled = true;
    try {
      await reviewSubmission(activeReviewStudentId, 'decline', reason);
      window.location.reload();
    } catch (error) {
      alert(error.message || 'Failed to decline.');
      btnReviewConfirmDecline.disabled = false;
    }
  });

  const reviewSearch = document.getElementById('reviewSearch');
  const reviewStatusFilter = document.getElementById('reviewStatusFilter');
  const reviewRows = Array.from(document.querySelectorAll('.review-row'));
  const reviewEmptyState = document.getElementById('reviewEmptyState');
  const reviewTable = document.getElementById('reviewTable');

  function applyReviewFilters() {
    const query = (reviewSearch?.value || '').trim().toLowerCase();
    const status = reviewStatusFilter?.value || 'all';
    let visibleCount = 0;

    reviewRows.forEach((row) => {
      const rowStatus = row.dataset.status || '';
      const rowSearch = row.dataset.search || '';
      const statusMatch = status === 'all' || rowStatus === status;
      const searchMatch = query === '' || rowSearch.includes(query);
      const show = statusMatch && searchMatch;
      row.classList.toggle('hidden', !show);
      if (show) visibleCount++;
    });

    if (reviewEmptyState) {
      reviewEmptyState.classList.toggle('hidden', visibleCount > 0);
    }
    if (reviewTable) {
      reviewTable.classList.toggle('hidden', visibleCount === 0);
    }
  }

  reviewSearch?.addEventListener('input', applyReviewFilters);
  reviewStatusFilter?.addEventListener('change', applyReviewFilters);
  applyReviewFilters();
</script>

<?php render_footer(); ?>
