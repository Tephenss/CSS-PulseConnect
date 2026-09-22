<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/session.php';
session_bootstrap();

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/supabase.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/event_targeting.php';
require_once __DIR__ . '/includes/event_sessions.php';
require_once __DIR__ . '/includes/event_tabs.php';
require_once __DIR__ . '/includes/student_requirements.php';
require_once __DIR__ . '/includes/registration_access.php';
require_once __DIR__ . '/includes/document_review_access.php';
require_once __DIR__ . '/includes/document_review_claim.php';

$user = require_role(['admin', 'teacher']);
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
    . '?select=id,title,status,created_by,start_at,end_at,is_free_event,event_fee,allow_registration'
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

$isCreator = (string) ($event['created_by'] ?? '') === $userId;
if (!event_user_can_review_documents($eventId, $userId, $role, $isCreator)) {
    http_response_code(403);
    echo 'You do not have Document Review access for this event.';
    exit;
}
$canManageReviewers = $role === 'admin' || $isCreator;

$requirements = fetch_student_requirements_map([$eventId], $headers)[$eventId] ?? [];
if ($requirements === []) {
    http_response_code(404);
    echo 'This event has no student document requirements.';
    exit;
}

$sessions = fetch_event_sessions($eventId, $headers);
$usesSessions = count($sessions) > 0;
$backHref = event_management_return_to($role, isset($_GET['return_to']) ? (string) $_GET['return_to'] : null);
$returnTo = $backHref;
$returnToQuery = '&return_to=' . rawurlencode($returnTo);
$isEventCreator = $role === 'admin' || $isCreator;
$isPaidEvent = !event_is_free_registration_event($event);

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

$reviewerIds = [];
foreach ($reviewRows as $row) {
    $rid = trim((string) ($row['submission']['reviewed_by'] ?? ''));
    if ($rid !== '') {
        $reviewerIds[] = $rid;
    }
    $cid = trim((string) ($row['submission']['review_claimed_by'] ?? ''));
    if ($cid !== '') {
        $reviewerIds[] = $cid;
    }
}
$reviewerProfiles = document_review_load_user_profiles($reviewerIds);
$grantedReviewers = $canManageReviewers ? event_document_reviewers_list($eventId) : [];
$eligibleTeachers = $canManageReviewers
    ? document_review_eligible_teachers($eventId, (string) ($event['created_by'] ?? ''))
    : [];

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

$shareAccessButtonHtml = '';
if ($canManageReviewers) {
    $grantedCount = count($grantedReviewers);
    $shareAccessButtonHtml = '<button type="button" id="btnOpenReviewAccess"'
        . ' class="rounded-xl border border-sky-200 bg-sky-600 text-white px-4 py-2 text-sm font-semibold hover:bg-sky-700 transition shadow-sm inline-flex items-center gap-2">'
        . '<svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M18 18.72a9.094 9.094 0 003.741-.479 3 3 0 00-4.682-2.72m.94 3.198l.001.031c0 .225-.012.447-.037.666A11.944 11.944 0 0112 21c-2.17 0-4.207-.576-5.963-1.584A6.062 6.062 0 016 18.719m12 0a5.971 5.971 0 00-.941-3.197m0 0A5.995 5.995 0 0012 12.75a5.995 5.995 0 00-5.058 2.772m0 0a3 3 0 00-4.681 2.72 8.986 8.986 0 003.74.477m.94-3.197a5.971 5.971 0 00-.94 3.197M15 6.75a3 3 0 11-6 0 3 3 0 016 0zm6 3a2.25 2.25 0 11-4.5 0 2.25 2.25 0 014.5 0zm-13.5 0a2.25 2.25 0 11-4.5 0 2.25 2.25 0 014.5 0z"/></svg>'
        . 'Share access'
        . '<span data-share-count class="rounded-full bg-white/20 px-1.5 py-0.5 text-[11px] font-bold leading-none' . ($grantedCount > 0 ? '' : ' hidden') . '">' . (int) $grantedCount . '</span>'
        . '</button>';
}

render_event_page_header([
    'back_href' => $backHref,
    'title' => (string) ($event['title'] ?? 'Event'),
    'subtitle' => 'Review student document submissions before they can register.',
    'actions_html' => $shareAccessButtonHtml,
]);

render_event_tabs([
    'event_id' => $eventId,
    'current_tab' => 'document_review',
    'role' => $role,
    'uses_sessions' => $usesSessions,
    'event_status' => (string) ($event['status'] ?? ''),
    'return_to' => $returnTo,
    'has_student_requirements' => true,
    'is_event_creator' => $isEventCreator,
    'is_paid_event' => $isPaidEvent,
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
        $mime = is_array($doc) ? strtolower(trim((string) ($doc['mime_type'] ?? ''))) : '';
        $fileUrl = is_array($doc) ? (string) ($doc['file_url'] ?? '') : '';
        if ($mime === '' && $fileUrl !== '') {
            $path = strtolower((string) parse_url($fileUrl, PHP_URL_PATH));
            if (str_ends_with($path, '.pdf')) {
                $mime = 'application/pdf';
            } elseif (preg_match('/\.(jpe?g|png|webp|gif)$/', $path)) {
                $mime = 'image/' . (str_ends_with($path, '.jpg') || str_ends_with($path, '.jpeg') ? 'jpeg' : (str_ends_with($path, '.png') ? 'png' : (str_ends_with($path, '.webp') ? 'webp' : 'gif')));
            }
        }
        $docItems[] = [
            'label' => (string) ($requirement['label'] ?? 'Requirement'),
            'file_name' => is_array($doc) ? (string) ($doc['file_name'] ?? 'View document') : '',
            'file_url' => $fileUrl,
            'mime_type' => $mime,
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
        'reviewed_by_name' => (static function () use ($submission, $reviewerProfiles): string {
            $rid = trim((string) ($submission['reviewed_by'] ?? ''));
            if ($rid === '' || !isset($reviewerProfiles[$rid])) {
                return '';
            }
            return document_review_teacher_display_name($reviewerProfiles[$rid]);
        })(),
        'claim_active' => document_review_claim_is_active($submission),
        'claimed_by_me' => document_review_claim_held_by($submission, $userId),
        'claimed_by_name' => (static function () use ($submission, $reviewerProfiles): string {
            if (!document_review_claim_is_active($submission)) {
                return '';
            }
            $cid = trim((string) ($submission['review_claimed_by'] ?? ''));
            if ($cid === '' || !isset($reviewerProfiles[$cid])) {
                return '';
            }
            return document_review_teacher_display_name($reviewerProfiles[$cid]);
        })(),
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
      <option value="in_review">In review now</option>
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
            <th class="px-3 py-2 text-left text-[11px] font-bold uppercase tracking-wider text-zinc-500 hidden xl:table-cell">Reviewed by</th>
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
              $reviewedById = trim((string) ($submission['reviewed_by'] ?? ''));
              $reviewedByName = ($reviewedById !== '' && isset($reviewerProfiles[$reviewedById]))
                  ? document_review_teacher_display_name($reviewerProfiles[$reviewedById])
                  : '';
              $claimActive = $status === 'pending_review' && document_review_claim_is_active($submission);
              $claimedByMe = $claimActive && document_review_claim_held_by($submission, $userId);
              $claimedById = trim((string) ($submission['review_claimed_by'] ?? ''));
              $claimedByName = ($claimActive && $claimedById !== '' && isset($reviewerProfiles[$claimedById]))
                  ? document_review_teacher_display_name($reviewerProfiles[$claimedById])
                  : '';
            ?>
            <tr class="review-row hover:bg-zinc-50/80" data-status="<?= htmlspecialchars($status) ?>" data-search="<?= htmlspecialchars($searchBlob) ?>" data-in-review="<?= $claimActive ? '1' : '0' ?>">
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
                <?php if ($claimActive): ?>
                  <div class="mt-1 text-[11px] font-semibold <?= $claimedByMe ? 'text-sky-700' : 'text-violet-700' ?>" title="<?= htmlspecialchars($claimedByName !== '' ? $claimedByName : 'Another teacher') ?>">
                    <?= $claimedByMe ? 'You are reviewing' : 'In review' ?>
                    <?php if (!$claimedByMe && $claimedByName !== ''): ?>
                      · <?= htmlspecialchars($claimedByName) ?>
                    <?php endif; ?>
                  </div>
                <?php endif; ?>
                <?php if ($status === 'declined' && $declineReason !== ''): ?>
                  <div class="mt-1 max-w-[140px] truncate text-[11px] text-red-600" title="<?= htmlspecialchars($declineReason) ?>"><?= htmlspecialchars($declineReason) ?></div>
                <?php endif; ?>
              </td>
              <td class="px-3 py-2 text-xs text-zinc-500 hidden xl:table-cell">
                <?= $reviewedByName !== '' ? htmlspecialchars($reviewedByName) : '—' ?>
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

<?php if ($canManageReviewers): ?>
<div id="reviewAccessModal" class="fixed inset-0 z-[60] hidden items-center justify-center p-4 bg-black/50 backdrop-blur-sm">
  <div class="w-full max-w-md max-h-[90vh] flex flex-col rounded-2xl bg-white shadow-2xl overflow-hidden">
    <div class="flex items-start justify-between gap-3 px-5 py-4 border-b border-zinc-200 shrink-0">
      <div class="min-w-0">
        <h3 class="text-lg font-bold text-zinc-900">Share Document Review</h3>
        <p class="mt-1 text-sm text-zinc-500">Give other teachers access so they can help review submissions.</p>
      </div>
      <button type="button" id="btnCloseReviewAccess" class="shrink-0 flex items-center justify-center w-8 h-8 rounded-lg border border-zinc-200 text-zinc-500 hover:bg-zinc-50 transition" title="Close">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
      </button>
    </div>
    <div class="flex-1 overflow-y-auto px-5 py-4 space-y-4">
      <div class="rounded-xl border border-zinc-100 bg-zinc-50 px-3 py-2.5 text-xs text-zinc-600 leading-relaxed">
        First Approve or Decline that saves wins. While a teacher has a student open, others will see <span class="font-semibold text-zinc-800">In review</span> and cannot take the same student.
      </div>
      <div>
        <div class="flex items-center justify-between gap-2">
          <label class="text-xs font-bold uppercase tracking-wide text-zinc-500">Add teachers</label>
          <button type="button" id="btnSelectAllEligible" class="text-[11px] font-semibold text-sky-700 hover:text-sky-800">Select all</button>
        </div>
        <input type="search" id="reviewAccessSearch" placeholder="Search name or email…"
          class="mt-2 w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900 outline-none focus:border-sky-400 focus:ring-2 focus:ring-sky-500/20">
        <div id="reviewEligibleList" class="mt-2 max-h-48 overflow-y-auto rounded-xl border border-zinc-200 divide-y divide-zinc-100">
          <?php if ($eligibleTeachers === []): ?>
            <p id="reviewEligibleEmpty" class="px-3 py-6 text-center text-sm text-zinc-400">All eligible teachers already have access.</p>
          <?php else: ?>
            <p id="reviewEligibleEmpty" class="hidden px-3 py-6 text-center text-sm text-zinc-400">All eligible teachers already have access.</p>
            <?php foreach ($eligibleTeachers as $teacher): ?>
              <?php
                $tid = (string) ($teacher['id'] ?? '');
                $tname = (string) ($teacher['name'] ?? 'Teacher');
                $temail = (string) ($teacher['email'] ?? '');
              ?>
              <label class="review-eligible-row flex items-start gap-3 bg-white px-3 py-2.5 cursor-pointer hover:bg-zinc-50"
                data-teacher-id="<?= htmlspecialchars($tid) ?>"
                data-search="<?= htmlspecialchars(strtolower(trim($tname . ' ' . $temail))) ?>">
                <input type="checkbox" class="review-eligible-check mt-1 h-4 w-4 rounded border-zinc-300 text-sky-600 focus:ring-sky-500"
                  value="<?= htmlspecialchars($tid) ?>">
                <span class="min-w-0">
                  <span class="block truncate text-sm font-semibold text-zinc-800"><?= htmlspecialchars($tname) ?></span>
                  <span class="block truncate text-xs text-zinc-500"><?= htmlspecialchars($temail !== '' ? $temail : '—') ?></span>
                </span>
              </label>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
        <p id="reviewEligibleFilterEmpty" class="hidden mt-2 text-xs text-zinc-400">No teachers match your search.</p>
        <button type="button" id="btnGrantReviewAccess" class="mt-3 w-full rounded-lg border border-sky-600 bg-sky-600 px-4 py-2.5 text-sm font-bold text-white hover:bg-sky-700 transition disabled:opacity-50">Grant selected</button>
      </div>
      <div>
        <div class="text-xs font-bold uppercase tracking-wide text-zinc-500">Teachers with access</div>
        <p id="reviewGrantedEmpty" class="mt-2 rounded-xl border border-dashed border-zinc-200 px-3 py-6 text-center text-sm text-zinc-400<?= $grantedReviewers === [] ? '' : ' hidden' ?>">No other teachers have Document Review access yet.</p>
        <ul id="reviewGrantedList" class="mt-2 divide-y divide-zinc-100 rounded-xl border border-zinc-200 overflow-hidden<?= $grantedReviewers === [] ? ' hidden' : '' ?>">
          <?php foreach ($grantedReviewers as $granted): ?>
            <li class="review-granted-row flex items-center justify-between gap-3 bg-white px-3 py-2.5" data-teacher-id="<?= htmlspecialchars((string) ($granted['teacher_id'] ?? '')) ?>">
              <div class="min-w-0">
                <div class="truncate text-sm font-semibold text-zinc-800"><?= htmlspecialchars((string) ($granted['name'] ?? 'Teacher')) ?></div>
                <div class="truncate text-xs text-zinc-500"><?= htmlspecialchars((string) ($granted['email'] ?? '')) ?></div>
              </div>
              <button type="button"
                class="btn-revoke-review-access shrink-0 rounded-lg border border-zinc-200 bg-white px-2.5 py-1 text-xs font-semibold text-zinc-600 hover:border-red-200 hover:bg-red-50 hover:text-red-700"
                data-teacher-id="<?= htmlspecialchars((string) ($granted['teacher_id'] ?? '')) ?>">
                Remove
              </button>
            </li>
          <?php endforeach; ?>
        </ul>
      </div>
    </div>
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
          <span id="reviewModalReviewer" class="text-xs text-zinc-500"></span>
          <span id="reviewModalLock" class="hidden text-xs font-semibold text-sky-700"></span>
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

<div id="docFilePreviewModal" class="fixed inset-0 z-[70] hidden items-center justify-center p-3 sm:p-6 bg-black/70 backdrop-blur-sm">
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
  const EVENT_ID = <?= json_encode($eventId, JSON_UNESCAPED_SLASHES) ?>;
  const REVIEW_DATA = <?= json_encode($reviewModalData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
  let activeReviewStudentId = '';
  let reviewClaimTimer = null;
  let reviewClaimHeld = false;

  async function reviewClaimRequest(studentId, action) {
    const res = await fetch('/api/student_requirements_review_claim.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        event_id: EVENT_ID,
        student_id: studentId,
        action,
        csrf_token: window.CSRF_TOKEN,
      }),
    });
    let data = {};
    try {
      data = await res.json();
    } catch (e) {
      data = {};
    }
    if (!data.ok) {
      const err = new Error(data.error || 'Unable to lock this student for review.');
      err.conflict = !!data.conflict || res.status === 409;
      throw err;
    }
    return data;
  }

  function stopReviewClaimHeartbeat() {
    if (reviewClaimTimer) {
      clearInterval(reviewClaimTimer);
      reviewClaimTimer = null;
    }
  }

  function startReviewClaimHeartbeat(studentId) {
    stopReviewClaimHeartbeat();
    reviewClaimTimer = setInterval(() => {
      reviewClaimRequest(studentId, 'heartbeat').catch((error) => {
        stopReviewClaimHeartbeat();
        alert(error.message || 'Another teacher took this student. Closing…');
        window.location.reload();
      });
    }, 45000);
  }

  async function releaseReviewClaim(studentId) {
    if (!studentId || !reviewClaimHeld) return;
    reviewClaimHeld = false;
    stopReviewClaimHeartbeat();
    try {
      await reviewClaimRequest(studentId, 'release');
    } catch (e) {
      // Closing should still succeed even if the lock already expired.
    }
  }

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
      const err = new Error(data.error || 'Review failed.');
      err.conflict = !!data.conflict || res.status === 409;
      throw err;
    }
    return data;
  }

  function handleReviewConflict(error, btn) {
    const message = error?.message || 'Review failed.';
    alert(message);
    if (error?.conflict || /^Already /i.test(message)) {
      window.location.reload();
      return;
    }
    if (btn) btn.disabled = false;
  }

  async function setReviewAccess(teacherIds, enabled) {
    const ids = Array.isArray(teacherIds) ? teacherIds : [teacherIds];
    const res = await fetch('/api/event_document_review_access.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        event_id: EVENT_ID,
        teacher_ids: ids,
        enabled,
        csrf_token: window.CSRF_TOKEN,
      }),
    });
    const data = await res.json();
    if (!data.ok) {
      throw new Error(data.error || 'Failed to update access.');
    }
    return data;
  }

  const reviewModal = document.getElementById('reviewModal');
  const reviewModalName = document.getElementById('reviewModalName');
  const reviewModalMeta = document.getElementById('reviewModalMeta');
  const reviewModalStatus = document.getElementById('reviewModalStatus');
  const reviewModalReviewer = document.getElementById('reviewModalReviewer');
  const reviewModalLock = document.getElementById('reviewModalLock');
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

    reviewModalDocuments.innerHTML = documents.map((doc, index) => {
      const label = escapeHtml(doc.label || 'Requirement');
      if (doc.uploaded && doc.file_url) {
        const fileName = escapeHtml(doc.file_name || 'View document');
        return `
          <button type="button" class="btn-preview-doc w-full text-left flex items-center gap-3 rounded-xl border border-zinc-200 bg-zinc-50 px-3 py-2.5 hover:bg-sky-50 hover:border-sky-200 transition" data-doc-index="${index}">
            <span class="flex items-center justify-center w-8 h-8 rounded-lg border border-sky-200 bg-sky-100 text-sky-700 shrink-0">
              <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z"/><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
            </span>
            <span class="min-w-0">
              <span class="block text-[11px] font-bold uppercase tracking-wide text-zinc-500">${label}</span>
              <span class="block text-sm font-semibold text-sky-700 truncate">${fileName}</span>
              <span class="block text-[11px] text-zinc-400">Tap to preview</span>
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

    reviewModalDocuments.querySelectorAll('.btn-preview-doc').forEach((btn) => {
      btn.addEventListener('click', () => {
        const idx = Number(btn.dataset.docIndex || -1);
        const doc = documents[idx];
        if (doc) openFilePreview(doc);
      });
    });
  }

  const fileModal = document.getElementById('docFilePreviewModal');
  const fileBody = document.getElementById('docFilePreviewBody');
  const fileLabel = document.getElementById('docFilePreviewLabel');
  const fileNameEl = document.getElementById('docFilePreviewName');
  const fileOpenTab = document.getElementById('docFilePreviewOpenTab');

  function openFilePreview(doc) {
    const url = String(doc?.file_url || '').trim();
    if (!url || !fileModal || !fileBody) return;
    const mime = String(doc?.mime_type || '').toLowerCase();
    const isPdf = mime === 'application/pdf' || /\.pdf(\?|$)/i.test(url);
    const isImage = mime.startsWith('image/') || /\.(png|jpe?g|webp|gif)(\?|$)/i.test(url);

    if (fileLabel) fileLabel.textContent = doc.label || 'Document';
    if (fileNameEl) fileNameEl.textContent = doc.file_name || 'Preview';
    if (fileOpenTab) fileOpenTab.href = url;

    if (isImage) {
      fileBody.innerHTML = `<img src="${escapeHtml(url)}" alt="${escapeHtml(doc.file_name || 'Document')}" class="max-h-[75vh] max-w-full object-contain rounded-lg shadow-sm bg-white" />`;
    } else if (isPdf) {
      fileBody.innerHTML = `<iframe src="${escapeHtml(url)}" title="${escapeHtml(doc.file_name || 'PDF')}" class="w-full h-[75vh] rounded-lg bg-white border border-zinc-200"></iframe>`;
    } else {
      fileBody.innerHTML = `<div class="text-center p-8"><p class="text-sm text-zinc-600 mb-3">Preview not available for this file type.</p><a href="${escapeHtml(url)}" target="_blank" rel="noopener" class="inline-flex rounded-lg bg-sky-600 px-4 py-2 text-sm font-bold text-white hover:bg-sky-700">Open file</a></div>`;
    }
    fileModal.classList.remove('hidden');
    fileModal.classList.add('flex');
  }

  function closeFilePreview() {
    if (!fileModal) return;
    fileModal.classList.add('hidden');
    fileModal.classList.remove('flex');
    if (fileBody) fileBody.innerHTML = '';
  }

  document.getElementById('btnCloseDocFilePreview')?.addEventListener('click', closeFilePreview);
  fileModal?.addEventListener('click', (event) => {
    if (event.target === fileModal) closeFilePreview();
  });

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
    if (reviewModalReviewer) {
      reviewModalReviewer.textContent = data.reviewed_by_name ? `Reviewed by ${data.reviewed_by_name}` : '';
    }
    if (reviewModalLock) {
      if (status === 'pending_review') {
        reviewModalLock.textContent = 'Locked for you — close this panel so another teacher can take the next student.';
        reviewModalLock.classList.remove('hidden');
      } else {
        reviewModalLock.textContent = '';
        reviewModalLock.classList.add('hidden');
      }
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

  async function closeReviewModal(opts = {}) {
    const keepLock = !!opts.keepLock;
    const claimedStudentId = activeReviewStudentId;
    if (!reviewModal) return;
    reviewModal.classList.add('hidden');
    reviewModal.classList.remove('flex');
    if (!keepLock) {
      await releaseReviewClaim(claimedStudentId);
    } else {
      stopReviewClaimHeartbeat();
      reviewClaimHeld = false;
    }
    activeReviewStudentId = '';
    resetReviewDeclineForm();
  }

  document.querySelectorAll('.btn-view-review').forEach((button) => {
    button.addEventListener('click', async () => {
      const studentId = button.dataset.studentId || '';
      const data = REVIEW_DATA[studentId];
      if (!studentId || !data) return;

      if (activeReviewStudentId && activeReviewStudentId !== studentId) {
        await releaseReviewClaim(activeReviewStudentId);
      }

      if ((data.status || 'pending_review') === 'pending_review') {
        button.disabled = true;
        try {
          await reviewClaimRequest(studentId, 'claim');
          reviewClaimHeld = true;
        } catch (error) {
          button.disabled = false;
          alert(error.message || 'Another teacher is already reviewing this student.');
          if (error.conflict) {
            window.location.reload();
          }
          return;
        }
        button.disabled = false;
        startReviewClaimHeartbeat(studentId);
      }

      openReviewModal(studentId);
    });
  });

  document.getElementById('btnCloseReviewModal')?.addEventListener('click', () => {
    closeReviewModal();
  });
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
      reviewClaimHeld = false;
      stopReviewClaimHeartbeat();
      window.location.reload();
    } catch (error) {
      handleReviewConflict(error, btnReviewModalApprove);
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
      reviewClaimHeld = false;
      stopReviewClaimHeartbeat();
      window.location.reload();
    } catch (error) {
      handleReviewConflict(error, btnReviewConfirmDecline);
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
      const inReview = row.dataset.inReview === '1';
      const statusMatch = status === 'all'
        || (status === 'in_review' && inReview)
        || (status !== 'in_review' && rowStatus === status);
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

  const reviewAccessModal = document.getElementById('reviewAccessModal');
  const reviewEligibleList = document.getElementById('reviewEligibleList');
  const reviewEligibleEmpty = document.getElementById('reviewEligibleEmpty');
  const reviewEligibleFilterEmpty = document.getElementById('reviewEligibleFilterEmpty');
  const reviewGrantedList = document.getElementById('reviewGrantedList');
  const reviewGrantedEmpty = document.getElementById('reviewGrantedEmpty');
  const reviewAccessSearch = document.getElementById('reviewAccessSearch');

  function openReviewAccessModal() {
    if (!reviewAccessModal) return;
    reviewAccessModal.classList.remove('hidden');
    reviewAccessModal.classList.add('flex');
  }
  function closeReviewAccessModal() {
    if (!reviewAccessModal) return;
    reviewAccessModal.classList.add('hidden');
    reviewAccessModal.classList.remove('flex');
  }

  function updateShareAccessCount() {
    const count = document.querySelectorAll('.review-granted-row').length;
    const badge = document.querySelector('[data-share-count]');
    if (!badge) return;
    badge.textContent = String(count);
    badge.classList.toggle('hidden', count === 0);
  }

  function refreshEligibleEmpty() {
    const rows = document.querySelectorAll('.review-eligible-row');
    if (reviewEligibleEmpty) {
      reviewEligibleEmpty.classList.toggle('hidden', rows.length > 0);
    }
  }

  function refreshGrantedEmpty() {
    const hasGranted = document.querySelectorAll('.review-granted-row').length > 0;
    reviewGrantedEmpty?.classList.toggle('hidden', hasGranted);
    reviewGrantedList?.classList.toggle('hidden', !hasGranted);
    updateShareAccessCount();
  }

  function applyEligibleSearch() {
    const query = (reviewAccessSearch?.value || '').trim().toLowerCase();
    const rows = Array.from(document.querySelectorAll('.review-eligible-row'));
    let visible = 0;
    rows.forEach((row) => {
      const match = query === '' || (row.dataset.search || '').includes(query);
      row.classList.toggle('hidden', !match);
      if (match) visible++;
    });
    const hasRows = rows.length > 0;
    reviewEligibleFilterEmpty?.classList.toggle('hidden', !hasRows || visible > 0 || query === '');
  }

  function eligibleRowHtml(teacher) {
    const id = escapeHtml(teacher.teacher_id || teacher.id || '');
    const name = escapeHtml(teacher.name || 'Teacher');
    const email = escapeHtml(teacher.email || '');
    const search = escapeHtml(String((teacher.name || '') + ' ' + (teacher.email || '')).toLowerCase());
    return `<label class="review-eligible-row flex items-start gap-3 bg-white px-3 py-2.5 cursor-pointer hover:bg-zinc-50" data-teacher-id="${id}" data-search="${search}">
      <input type="checkbox" class="review-eligible-check mt-1 h-4 w-4 rounded border-zinc-300 text-sky-600 focus:ring-sky-500" value="${id}">
      <span class="min-w-0">
        <span class="block truncate text-sm font-semibold text-zinc-800">${name}</span>
        <span class="block truncate text-xs text-zinc-500">${email || '—'}</span>
      </span>
    </label>`;
  }

  function grantedRowHtml(teacher) {
    const id = escapeHtml(teacher.teacher_id || teacher.id || '');
    const name = escapeHtml(teacher.name || 'Teacher');
    const email = escapeHtml(teacher.email || '');
    return `<li class="review-granted-row flex items-center justify-between gap-3 bg-white px-3 py-2.5" data-teacher-id="${id}">
      <div class="min-w-0">
        <div class="truncate text-sm font-semibold text-zinc-800">${name}</div>
        <div class="truncate text-xs text-zinc-500">${email}</div>
      </div>
      <button type="button" class="btn-revoke-review-access shrink-0 rounded-lg border border-zinc-200 bg-white px-2.5 py-1 text-xs font-semibold text-zinc-600 hover:border-red-200 hover:bg-red-50 hover:text-red-700" data-teacher-id="${id}">Remove</button>
    </li>`;
  }

  function bindRevokeButtons(scope) {
    (scope || document).querySelectorAll('.btn-revoke-review-access').forEach((button) => {
      if (button.dataset.bound === '1') return;
      button.dataset.bound = '1';
      button.addEventListener('click', async () => {
        const teacherId = String(button.dataset.teacherId || '').trim();
        if (!teacherId) return;
        if (!confirm('Remove Document Review access for this teacher?')) return;
        button.disabled = true;
        try {
          const data = await setReviewAccess([teacherId], false);
          const teacher = (data.teachers && data.teachers[0]) || { teacher_id: teacherId, name: 'Teacher', email: '' };
          button.closest('.review-granted-row')?.remove();
          if (reviewEligibleList) {
            reviewEligibleEmpty?.classList.add('hidden');
            reviewEligibleList.insertAdjacentHTML('beforeend', eligibleRowHtml(teacher));
          }
          refreshGrantedEmpty();
          refreshEligibleEmpty();
          applyEligibleSearch();
        } catch (error) {
          alert(error.message || 'Failed to remove access.');
          button.disabled = false;
        }
      });
    });
  }

  document.getElementById('btnOpenReviewAccess')?.addEventListener('click', openReviewAccessModal);
  document.getElementById('btnCloseReviewAccess')?.addEventListener('click', closeReviewAccessModal);
  reviewAccessModal?.addEventListener('click', (event) => {
    if (event.target === reviewAccessModal) closeReviewAccessModal();
  });
  reviewAccessSearch?.addEventListener('input', applyEligibleSearch);

  document.getElementById('btnSelectAllEligible')?.addEventListener('click', () => {
    const visible = Array.from(document.querySelectorAll('.review-eligible-row')).filter((row) => !row.classList.contains('hidden'));
    const checks = visible.map((row) => row.querySelector('.review-eligible-check')).filter(Boolean);
    const allOn = checks.length > 0 && checks.every((box) => box.checked);
    checks.forEach((box) => { box.checked = !allOn; });
  });

  document.getElementById('btnGrantReviewAccess')?.addEventListener('click', async () => {
    const selected = Array.from(document.querySelectorAll('.review-eligible-check:checked'))
      .map((box) => String(box.value || '').trim())
      .filter(Boolean);
    if (selected.length === 0) {
      alert('Check at least one teacher.');
      return;
    }
    const btn = document.getElementById('btnGrantReviewAccess');
    if (btn) btn.disabled = true;
    try {
      const data = await setReviewAccess(selected, true);
      const granted = Array.isArray(data.teachers) ? data.teachers : [];
      granted.forEach((teacher) => {
        const id = String(teacher.teacher_id || '');
        document.querySelector('.review-eligible-row[data-teacher-id="' + CSS.escape(id) + '"]')?.remove();
        if (reviewGrantedList) {
          reviewGrantedList.insertAdjacentHTML('beforeend', grantedRowHtml(teacher));
        }
      });
      bindRevokeButtons(reviewGrantedList);
      refreshGrantedEmpty();
      refreshEligibleEmpty();
      applyEligibleSearch();
      if (reviewAccessSearch) reviewAccessSearch.value = '';
      applyEligibleSearch();
    } catch (error) {
      alert(error.message || 'Failed to grant access.');
    } finally {
      if (btn) btn.disabled = false;
    }
  });

  bindRevokeButtons(reviewGrantedList);

  window.addEventListener('pagehide', () => {
    if (!reviewClaimHeld || !activeReviewStudentId) return;
    const body = JSON.stringify({
      event_id: EVENT_ID,
      student_id: activeReviewStudentId,
      action: 'release',
      csrf_token: window.CSRF_TOKEN,
    });
    fetch('/api/student_requirements_review_claim.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body,
      keepalive: true,
    }).catch(() => {});
  });
</script>

<?php render_footer(); ?>
