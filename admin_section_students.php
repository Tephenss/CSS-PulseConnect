<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/session.php';
session_bootstrap();

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/supabase.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/student_roster.php';

$user = require_role(['admin']);

$sectionId = $_GET['id'] ?? '';
$sectionId = str_replace(' ', '-', trim($sectionId)); // Defensive fix against spaces replacing hyphens
$sectionName = $_GET['name'] ?? 'Block Student List';
$isIrregularSection = strcasecmp(trim($sectionName), 'IRREGULAR') === 0;

if ($sectionId === '') {
    header('Location: admin_sections.php');
    exit;
}

$headers = [
    'Accept: application/json',
    'apikey: ' . SUPABASE_KEY,
    'Authorization: Bearer ' . SUPABASE_KEY,
];

// Registered students in this section.
$url = rtrim(SUPABASE_URL, '/') . '/rest/v1/users'
    . '?select=id,first_name,middle_name,last_name,email,student_id,archived_at'
    . '&role=eq.student'
    . '&section_id=eq.' . rawurlencode($sectionId)
    . '&order=last_name.asc';
$students = [];
$res = supabase_request('GET', $url, $headers);
if ($res['ok']) {
    $decoded = json_decode((string) $res['body'], true);
    $students = is_array($decoded) ? $decoded : [];
} elseif (str_contains((string) ($res['body'] ?? ''), 'archived_at')) {
    $urlFallback = rtrim(SUPABASE_URL, '/') . '/rest/v1/users'
        . '?select=id,first_name,middle_name,last_name,email,student_id'
        . '&role=eq.student'
        . '&section_id=eq.' . rawurlencode($sectionId)
        . '&order=last_name.asc';
    $resFb = supabase_request('GET', $urlFallback, $headers);
    if ($resFb['ok']) {
        $decoded = json_decode((string) $resFb['body'], true);
        $students = is_array($decoded) ? $decoded : [];
    }
}

// Roster assignments (awaiting signup + linked) — source of truth after Excel import.
$rosterRows = [];
$rosterUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/student_roster'
    . '?select=id,student_no,first_name,middle_name,last_name,user_id,section_id,archived_at'
    . '&section_id=eq.' . rawurlencode($sectionId)
    . '&archived_at=is.null'
    . '&order=last_name.asc'
    . '&limit=2000';
$rosterRes = supabase_request('GET', $rosterUrl, $headers);
if ($rosterRes['ok']) {
    $decodedRoster = json_decode((string) ($rosterRes['body'] ?? ''), true);
    $rosterRows = is_array($decodedRoster) ? $decodedRoster : [];
} elseif (str_contains((string) ($rosterRes['body'] ?? ''), 'archived_at')) {
    $rosterUrlFb = rtrim(SUPABASE_URL, '/') . '/rest/v1/student_roster'
        . '?select=id,student_no,first_name,middle_name,last_name,user_id,section_id'
        . '&section_id=eq.' . rawurlencode($sectionId)
        . '&order=last_name.asc'
        . '&limit=2000';
    $rosterResFb = supabase_request('GET', $rosterUrlFb, $headers);
    if ($rosterResFb['ok']) {
        $decodedRoster = json_decode((string) ($rosterResFb['body'] ?? ''), true);
        $rosterRows = is_array($decodedRoster) ? $decodedRoster : [];
    }
}

$userById = [];
$userByNo = [];
foreach ($students as $s) {
    if (!is_array($s) || !empty($s['archived_at'])) {
        continue;
    }
    $uid = trim((string) ($s['id'] ?? ''));
    $sno = student_roster_normalize_no((string) ($s['student_id'] ?? ''));
    if ($uid !== '') {
        $userById[$uid] = $s;
    }
    if ($sno !== '') {
        $userByNo[$sno] = $s;
    }
}

$directory = [];
$seenUserIds = [];
$seenNos = [];

foreach ($rosterRows as $r) {
    if (!is_array($r) || !empty($r['archived_at'])) {
        continue;
    }
    $sno = student_roster_normalize_no((string) ($r['student_no'] ?? ''));
    $linkedUid = trim((string) ($r['user_id'] ?? ''));
    $appUser = null;
    if ($linkedUid !== '' && isset($userById[$linkedUid])) {
        $appUser = $userById[$linkedUid];
    } elseif ($sno !== '' && isset($userByNo[$sno])) {
        $appUser = $userByNo[$sno];
    }

    if (is_array($appUser)) {
        $uid = trim((string) ($appUser['id'] ?? ''));
        $directory[] = [
            'kind' => 'registered',
            'user_id' => $uid,
            'roster_id' => trim((string) ($r['id'] ?? '')),
            'student_no' => $sno !== '' ? $sno : (string) ($appUser['student_id'] ?? ''),
            'first_name' => (string) ($appUser['first_name'] ?? $r['first_name'] ?? ''),
            'middle_name' => (string) ($appUser['middle_name'] ?? $r['middle_name'] ?? ''),
            'last_name' => (string) ($appUser['last_name'] ?? $r['last_name'] ?? ''),
            'email' => (string) ($appUser['email'] ?? ''),
            'status' => 'Registered',
        ];
        if ($uid !== '') {
            $seenUserIds[$uid] = true;
        }
        if ($sno !== '') {
            $seenNos[$sno] = true;
        }
        continue;
    }

    $directory[] = [
        'kind' => 'roster',
        'user_id' => '',
        'roster_id' => trim((string) ($r['id'] ?? '')),
        'student_no' => $sno,
        'first_name' => (string) ($r['first_name'] ?? ''),
        'middle_name' => (string) ($r['middle_name'] ?? ''),
        'last_name' => (string) ($r['last_name'] ?? ''),
        'email' => '',
        'status' => 'Awaiting signup',
    ];
    if ($sno !== '') {
        $seenNos[$sno] = true;
    }
}

// Users assigned to section but missing from roster (manual / legacy).
foreach ($students as $s) {
    if (!is_array($s) || !empty($s['archived_at'])) {
        continue;
    }
    $uid = trim((string) ($s['id'] ?? ''));
    $sno = student_roster_normalize_no((string) ($s['student_id'] ?? ''));
    if ($uid !== '' && isset($seenUserIds[$uid])) {
        continue;
    }
    if ($sno !== '' && isset($seenNos[$sno])) {
        continue;
    }
    $directory[] = [
        'kind' => 'registered',
        'user_id' => $uid,
        'roster_id' => '',
        'student_no' => $sno,
        'first_name' => (string) ($s['first_name'] ?? ''),
        'middle_name' => (string) ($s['middle_name'] ?? ''),
        'last_name' => (string) ($s['last_name'] ?? ''),
        'email' => (string) ($s['email'] ?? ''),
        'status' => 'Registered',
    ];
}

usort($directory, static function (array $a, array $b): int {
    $ln = strcasecmp((string) ($a['last_name'] ?? ''), (string) ($b['last_name'] ?? ''));
    if ($ln !== 0) {
        return $ln;
    }
    return strcasecmp((string) ($a['first_name'] ?? ''), (string) ($b['first_name'] ?? ''));
});

render_header('Block: ' . htmlspecialchars($sectionName), $user);
?>

<div class="mb-6 flex items-center justify-between gap-3 flex-wrap">
  <div>
    <h2 class="text-xl font-bold text-zinc-900 mb-1"><?= htmlspecialchars($sectionName) ?> Students</h2>
    <p class="text-sm text-zinc-500">Total Enrolled: <?= count($directory) ?></p>
  </div>
  <div class="flex items-center gap-2">
    <?php if (!$isIrregularSection): ?>
      <button type="button" id="btnViewBlockSched" class="text-sm font-semibold text-sky-800 hover:text-sky-950 px-3 py-2 bg-sky-50 hover:bg-sky-100 rounded-lg transition-colors border border-sky-200 inline-flex items-center gap-2">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 11.25v7.5"/></svg>
        View Sched
      </button>
    <?php endif; ?>
    <a href="admin_sections.php" class="text-sm font-semibold text-zinc-600 hover:text-zinc-900 px-3 py-2 bg-zinc-100 hover:bg-zinc-200 rounded-lg transition-colors border border-zinc-200 inline-flex items-center gap-2">
      <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5L3 12m0 0l7.5-7.5M3 12h18"/></svg>
      Back
    </a>
  </div>
</div>

<div class="mb-5 relative w-full sm:w-96">
    <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
        <svg class="w-5 h-5 text-zinc-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path></svg>
    </div>
    <input type="text" id="searchInput" class="block w-full pl-10 pr-4 py-2.5 bg-white border border-zinc-200 rounded-xl text-sm placeholder-zinc-400 focus:ring-2 focus:ring-orange-500/30 focus:border-orange-400 transition-all outline-none" placeholder="Search by name, student no, or email...">
</div>

<div class="bg-white rounded-2xl shadow-sm border border-zinc-200 overflow-hidden">
    <div class="overflow-x-auto">
      <table class="w-full text-left text-sm text-zinc-600 whitespace-nowrap">
        <thead class="bg-[#FAFAFA] text-zinc-800 font-semibold border-b border-zinc-200">
          <tr>
            <th class="px-6 py-4 rounded-tl-xl">Student Number</th>
            <th class="px-6 py-4">Name</th>
            <th class="px-6 py-4">Email</th>
            <th class="px-6 py-4">Status</th>
            <th class="px-6 py-4 rounded-tr-xl text-right">Actions</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-zinc-100">
          <?php if (count($directory) > 0): ?>
            <?php foreach ($directory as $row): ?>
              <?php
                $studentUserId = (string) ($row['user_id'] ?? '');
                $rosterId = (string) ($row['roster_id'] ?? '');
                $studentFullName = trim((string) (($row['last_name'] ?? '') . ', ' . ($row['first_name'] ?? '')));
                $isRegistered = ($row['kind'] ?? '') === 'registered' && $studentUserId !== '';
                $status = (string) ($row['status'] ?? '');
              ?>
              <tr class="hover:bg-zinc-50 transition-colors group">
                <td class="px-6 py-4">
                  <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-semibold bg-zinc-100 text-zinc-700">
                    <?= htmlspecialchars(($row['student_no'] ?? '') !== '' ? (string) $row['student_no'] : 'N/A') ?>
                  </span>
                </td>
                <td class="px-6 py-4">
                  <div class="font-semibold text-zinc-900">
                    <?= htmlspecialchars(($row['last_name'] ?? '') . ', ' . ($row['first_name'] ?? '')) ?>
                  </div>
                </td>
                <td class="px-6 py-4 text-zinc-500">
                  <?= htmlspecialchars(($row['email'] ?? '') !== '' ? (string) $row['email'] : '—') ?>
                </td>
                <td class="px-6 py-4">
                  <?php if ($status === 'Registered'): ?>
                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-bold uppercase tracking-wide bg-emerald-50 text-emerald-700 border border-emerald-200">Registered</span>
                  <?php else: ?>
                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-bold uppercase tracking-wide bg-amber-50 text-amber-700 border border-amber-200">Awaiting signup</span>
                  <?php endif; ?>
                </td>
                <td class="px-6 py-4 text-right">
                  <div class="inline-flex items-center gap-2">
                    <?php if ($isIrregularSection && $isRegistered): ?>
                      <button
                        type="button"
                        class="btnViewStudentSched inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-semibold border border-sky-200 bg-sky-50 text-sky-800 hover:bg-sky-100 transition-colors"
                        data-student-id="<?= htmlspecialchars($studentUserId) ?>"
                        data-student-name="<?= htmlspecialchars($studentFullName !== '' ? $studentFullName : 'this student') ?>"
                      >
                        View Sched
                      </button>
                    <?php endif; ?>
                    <button
                    type="button"
                    class="btnResetOne inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-semibold border border-red-200 bg-red-50 text-red-700 hover:bg-red-100 transition-colors"
                    data-student-id="<?= htmlspecialchars($studentUserId) ?>"
                    data-roster-id="<?= htmlspecialchars($rosterId) ?>"
                    data-student-name="<?= htmlspecialchars($studentFullName !== '' ? $studentFullName : 'this student') ?>"
                  >
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0l3.181 3.183a8.25 8.25 0 0013.803-3.7"/></svg>
                    Reset Student
                  </button>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php else: ?>
            <tr>
              <td colspan="5" class="px-6 py-12 text-center text-zinc-500 bg-white">
                <div class="flex flex-col items-center justify-center">
                  <div class="w-12 h-12 rounded-full bg-zinc-50 border border-zinc-100 flex items-center justify-center mb-3">
                    <svg class="w-6 h-6 text-zinc-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"></path></svg>
                  </div>
                  <p class="font-medium text-zinc-800">No Students Yet</p>
                  <p class="text-xs mt-1 text-zinc-400">Imported or registered students assigned to this block will appear here.</p>
                </div>
              </td>
            </tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
</div>

<div id="schedModal" class="hidden fixed inset-0 z-50 p-4 sm:p-6">
  <div id="schedModalBackdrop" class="absolute inset-0 bg-zinc-900/50"></div>
  <div class="sched-modal-card relative z-10 w-full bg-white rounded-2xl border border-zinc-200 shadow-2xl">
    <div class="sched-modal-header px-5 py-4 border-b border-zinc-200 flex items-start justify-between gap-3">
      <div class="min-w-0">
        <h3 id="schedModalTitle" class="text-base font-bold text-zinc-900 truncate">Class schedule</h3>
        <p id="schedModalSub" class="text-xs text-zinc-500 mt-0.5"></p>
      </div>
      <div class="flex items-center gap-2 shrink-0">
        <button type="button" id="schedModalStatusBtn" class="hidden text-sm font-semibold px-3 py-1.5 rounded-lg border transition-colors">Status</button>
        <button type="button" id="schedModalClose" class="text-zinc-500 hover:text-zinc-900 hover:bg-zinc-100 rounded-lg text-sm font-semibold px-3 py-1.5 transition-colors">Close</button>
      </div>
    </div>
    <div id="schedModalBody" class="sched-modal-body px-5 py-4 text-sm text-zinc-700"></div>
  </div>
</div>

<div id="schedStatusModal" class="hidden fixed inset-0 z-50 p-4 sm:p-6">
  <div id="schedStatusBackdrop" class="absolute inset-0 bg-zinc-900/50"></div>
  <div class="sched-modal-card relative z-10 w-full bg-white rounded-2xl border border-zinc-200 shadow-2xl">
    <div class="sched-modal-header px-5 py-4 border-b border-zinc-200 flex items-start justify-between gap-3">
      <div class="min-w-0">
        <h3 id="schedStatusTitle" class="text-base font-bold text-zinc-900 truncate">Schedule status</h3>
        <p id="schedStatusSub" class="text-xs text-zinc-500 mt-0.5"></p>
      </div>
      <button type="button" id="schedStatusClose" class="shrink-0 text-zinc-500 hover:text-zinc-900 hover:bg-zinc-100 rounded-lg text-sm font-semibold px-3 py-1.5 transition-colors">Close</button>
    </div>
    <div id="schedStatusBody" class="sched-modal-body px-5 py-4 text-sm text-zinc-700"></div>
  </div>
</div>
<style>
  #schedModal:not(.hidden),
  #schedStatusModal:not(.hidden) {
    display: flex;
    align-items: center;
    justify-content: center;
    overflow: auto;
  }
  #schedStatusModal {
    z-index: 60;
  }
  .sched-modal-card {
    max-width: 48rem;
    max-height: min(90vh, 800px);
    display: flex;
    flex-direction: column;
    overflow: hidden;
  }
  .sched-modal-header {
    flex-shrink: 0;
  }
  .sched-modal-body {
    flex: 1 1 auto;
    min-height: 0;
    overflow-y: auto;
  }
</style>

<script>
document.getElementById('searchInput').addEventListener('input', function(e) {
    const term = e.target.value.toLowerCase();
    const rows = document.querySelectorAll('tbody tr.group');

    let visibleCount = 0;
    rows.forEach(row => {
        const text = row.textContent.toLowerCase();
        if (text.includes(term)) {
            row.style.display = '';
            visibleCount++;
        } else {
            row.style.display = 'none';
        }
    });

    const emptyRow = document.getElementById('emptySearchRow');
    if (visibleCount === 0 && rows.length > 0) {
        if (!emptyRow) {
            const tbody = document.querySelector('tbody');
            const tr = document.createElement('tr');
            tr.id = 'emptySearchRow';
            tr.innerHTML = `<td colspan="5" class="px-6 py-8 text-center text-zinc-500 bg-white">No students match your search.</td>`;
            tbody.appendChild(tr);
        } else {
            emptyRow.style.display = '';
        }
    } else if (emptyRow) {
        emptyRow.style.display = 'none';
    }
});

document.querySelectorAll('.btnResetOne').forEach(btn => {
    btn.addEventListener('click', async () => {
        const studentId = btn.dataset.studentId || '';
        const rosterId = btn.dataset.rosterId || '';
        const studentName = btn.dataset.studentName || 'this student';

        if (!studentId && !rosterId) {
            alert('Student id is missing.');
            return;
        }

        const confirmed = confirm(
            `Reset block for ${studentName}?\n\n` +
            'This will clear this student\'s block assignment.'
        );
        if (!confirmed) return;

        const originalHtml = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = 'Resetting...';

        try {
            const res = await fetch('/api/sections_reset.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    csrf_token: window.CSRF_TOKEN || '',
                    student_id: studentId,
                    roster_id: rosterId
                })
            });
            const data = await res.json();
            if (!data.ok) throw new Error(data.error || 'Failed to reset student block');

            alert(`Block reset successful for ${studentName}.`);
            window.location.reload();
        } catch (err) {
            alert(err.message || 'Failed to reset student block.');
            btn.disabled = false;
            btn.innerHTML = originalHtml;
        }
    });
});

const schedModal = document.getElementById('schedModal');
const schedModalTitle = document.getElementById('schedModalTitle');
const schedModalSub = document.getElementById('schedModalSub');
const schedModalBody = document.getElementById('schedModalBody');
const schedModalStatusBtn = document.getElementById('schedModalStatusBtn');
const schedStatusModal = document.getElementById('schedStatusModal');
const schedStatusTitle = document.getElementById('schedStatusTitle');
const schedStatusSub = document.getElementById('schedStatusSub');
const schedStatusBody = document.getElementById('schedStatusBody');
const sectionId = <?= json_encode($sectionId) ?>;
const sectionName = <?= json_encode($sectionName) ?>;
let lastBlockSchedData = null;

function closeSchedModal() {
    if (schedModal) schedModal.classList.add('hidden');
    closeSchedStatusModal();
    lastBlockSchedData = null;
    if (schedModalStatusBtn) schedModalStatusBtn.classList.add('hidden');
}
function closeSchedStatusModal() {
    if (schedStatusModal) schedStatusModal.classList.add('hidden');
}
document.getElementById('schedModalClose')?.addEventListener('click', closeSchedModal);
document.getElementById('schedModalBackdrop')?.addEventListener('click', closeSchedModal);
document.getElementById('schedStatusClose')?.addEventListener('click', closeSchedStatusModal);
document.getElementById('schedStatusBackdrop')?.addEventListener('click', closeSchedStatusModal);

function escapeHtml(value) {
    return String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}

function renderSubjects(subjects) {
    if (!Array.isArray(subjects) || subjects.length === 0) {
        return '<p class="text-zinc-500">No class schedule uploaded yet.</p>';
    }
    const rows = subjects.map((s) => {
        const code = escapeHtml(s.course_code || '');
        const desc = escapeHtml(s.course_description || '');
        const days = escapeHtml(s.days || '');
        const time = escapeHtml(s.time_label || '');
        const inst = escapeHtml(s.instructor || '');
        return `<tr class="border-b border-zinc-100">
            <td class="py-2 pr-3 font-semibold text-zinc-900 whitespace-nowrap">${code}</td>
            <td class="py-2 pr-3">${desc}</td>
            <td class="py-2 pr-3 whitespace-nowrap">${days}${time ? ' · ' + time : ''}</td>
            <td class="py-2">${inst}</td>
        </tr>`;
    }).join('');
    return `<table class="w-full text-left"><thead><tr class="text-[11px] uppercase tracking-wide text-zinc-500 border-b border-zinc-200">
        <th class="py-2 pr-3">Code</th><th class="py-2 pr-3">Description</th><th class="py-2 pr-3">Day / Time</th><th class="py-2">Instructor</th>
    </tr></thead><tbody>${rows}</tbody></table>`;
}

function setStatusButton(data) {
    if (!schedModalStatusBtn) return;
    const allMatch = !!data.all_match;
    const mismatchCount = Number(data.mismatch_count || 0);
    schedModalStatusBtn.classList.remove('hidden');
    if (allMatch) {
        schedModalStatusBtn.textContent = 'Status · Match';
        schedModalStatusBtn.className = 'text-sm font-semibold px-3 py-1.5 rounded-lg border transition-colors bg-emerald-50 text-emerald-800 border-emerald-200 hover:bg-emerald-100';
    } else {
        schedModalStatusBtn.textContent = 'Status · Mismatch' + (mismatchCount > 0 ? ' (' + mismatchCount + ')' : '');
        schedModalStatusBtn.className = 'text-sm font-semibold px-3 py-1.5 rounded-lg border transition-colors bg-red-50 text-red-800 border-red-200 hover:bg-red-100';
    }
}

function renderStatusModal(data) {
    const studentCount = Number(data.student_count || 0);
    const matchCount = Number(data.match_count || 0);
    const mismatchCount = Number(data.mismatch_count || 0);
    const majorityCount = Number(data.majority_count || 0);
    const uploadedCount = Number(data.uploaded_count || 0);
    const allMatch = !!data.all_match;
    const hasMajority = majorityCount > 0 && Array.isArray(data.subjects) && data.subjects.length > 0;
    const mismatches = Array.isArray(data.mismatches) ? data.mismatches : [];
    const differentCount = mismatches.filter((m) => m && m.reason === 'different').length;
    const noUploadCount = mismatches.filter((m) => m && m.reason === 'no_upload').length;

    let banner = '';
    if (!hasMajority) {
        banner = `<div class="mb-4 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3">
            <p class="text-sm font-bold text-amber-900">Mismatch</p>
            <p class="text-xs text-amber-800 mt-1">No majority schedule yet. Students who have not uploaded or updated their registration form are listed below.</p>
        </div>`;
    } else if (allMatch) {
        banner = `<div class="mb-4 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3">
            <p class="text-sm font-bold text-emerald-900">Match</p>
            <p class="text-xs text-emerald-800 mt-1">All ${studentCount} registered student(s) match the majority block schedule (${majorityCount} of ${uploadedCount} upload${uploadedCount === 1 ? '' : 's'}).</p>
        </div>`;
    } else {
        banner = `<div class="mb-4 rounded-xl border border-red-200 bg-red-50 px-4 py-3">
            <p class="text-sm font-bold text-red-900">Mismatch</p>
            <p class="text-xs text-red-800 mt-1">${matchCount} match · ${mismatchCount} mismatch of ${studentCount} registered. Awaiting signup is not counted. Wrong uploads and registered students who have not updated are mismatch.</p>
        </div>`;
    }

    if (mismatches.length === 0) {
        return banner + '<p class="text-zinc-500">No mismatched students.</p>';
    }

    const rows = mismatches.map((m) => {
        const name = escapeHtml(m.name || 'Unnamed');
        const no = escapeHtml(m.student_no || '—');
        const isDifferent = m.reason === 'different';
        const label = escapeHtml(m.reason_label || (isDifferent ? 'Different schedule' : 'No upload'));
        const badge = isDifferent
            ? 'bg-red-50 text-red-700 border-red-200'
            : 'bg-amber-50 text-amber-800 border-amber-200';
        return `<tr class="border-b border-zinc-100">
            <td class="py-2 pr-3 whitespace-nowrap"><span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-semibold bg-zinc-100 text-zinc-700">${no}</span></td>
            <td class="py-2 pr-3 font-semibold text-zinc-900">${name}</td>
            <td class="py-2 text-right"><span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-bold uppercase tracking-wide border ${badge}">${label}</span></td>
        </tr>`;
    }).join('');
    const split = [];
    if (differentCount > 0) split.push(differentCount + ' different schedule');
    if (noUploadCount > 0) split.push(noUploadCount + ' no upload');
    return banner + `<p class="text-[11px] font-semibold uppercase tracking-wide text-zinc-500 mb-1">Mismatched students</p>
        <p class="text-xs text-zinc-500 mb-2">${escapeHtml(split.join(' · '))}</p>
        <table class="w-full text-left"><thead><tr class="text-[11px] uppercase tracking-wide text-zinc-500 border-b border-zinc-200">
            <th class="py-2 pr-3">Student no</th><th class="py-2 pr-3">Name</th><th class="py-2 text-right">Status</th>
        </tr></thead><tbody>${rows}</tbody></table>`;
}

function openSchedStatusModal() {
    if (!lastBlockSchedData || !schedStatusModal) return;
    const data = lastBlockSchedData;
    const matchCount = Number(data.match_count || 0);
    const studentCount = Number(data.student_count || 0);
    if (schedStatusTitle) schedStatusTitle.textContent = sectionName + ' schedule status';
    if (schedStatusSub) schedStatusSub.textContent = matchCount + ' of ' + studentCount + ' registered match the majority schedule';
    if (schedStatusBody) {
        schedStatusBody.innerHTML = renderStatusModal(data);
        schedStatusBody.scrollTop = 0;
    }
    schedStatusModal.classList.remove('hidden');
}

async function loadSchedule(payload, title) {
    if (!schedModal || !schedModalBody) return;
    lastBlockSchedData = null;
    if (schedModalStatusBtn) schedModalStatusBtn.classList.add('hidden');
    closeSchedStatusModal();
    schedModalTitle.textContent = title;
    schedModalSub.textContent = 'Loading…';
    schedModalBody.innerHTML = '';
    schedModal.classList.remove('hidden');
    try {
        const res = await fetch('/api/admin_section_schedule.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                csrf_token: window.CSRF_TOKEN || '',
                section_id: sectionId,
                ...payload
            })
        });
        const data = await res.json();
        if (!data.ok) throw new Error(data.error || 'Could not load schedule');
        const count = Array.isArray(data.subjects) ? data.subjects.length : 0;
        if (data.mode === 'block') {
            lastBlockSchedData = data;
            const uploaded = Number(data.uploaded_count || 0);
            schedModalSub.textContent = uploaded + ' student upload(s) · ' + count + ' subject(s)';
            setStatusButton(data);
            schedModalBody.innerHTML = renderSubjects(data.subjects || []);
            schedModalBody.scrollTop = 0;
        } else {
            schedModalSub.textContent = count + ' subject(s)';
            schedModalBody.innerHTML = renderSubjects(data.subjects || []);
            schedModalBody.scrollTop = 0;
        }
    } catch (err) {
        schedModalSub.textContent = '';
        schedModalBody.innerHTML = `<p class="text-red-600">${escapeHtml(err.message || 'Failed to load schedule.')}</p>`;
    }
}

schedModalStatusBtn?.addEventListener('click', openSchedStatusModal);
document.getElementById('btnViewBlockSched')?.addEventListener('click', () => {
    loadSchedule({}, <?= json_encode($sectionName . ' block schedule') ?>);
});
document.querySelectorAll('.btnViewStudentSched').forEach((btn) => {
    btn.addEventListener('click', () => {
        loadSchedule(
            { student_id: btn.dataset.studentId || '' },
            (btn.dataset.studentName || 'Student') + ' schedule'
        );
    });
});
</script>

<?php render_footer(); ?>
