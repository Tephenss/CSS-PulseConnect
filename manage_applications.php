<?php
declare(strict_types=1);

session_start();

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/supabase.php';
require_once __DIR__ . '/includes/layout.php';

$user = require_role(['admin']);

$headers = [
    'Accept: application/json',
    'apikey: ' . SUPABASE_KEY,
    'Authorization: Bearer ' . SUPABASE_KEY,
];

$url = rtrim(SUPABASE_URL, '/') . '/rest/v1/' . SUPABASE_TABLE_USERS
    . '?select=id,first_name,middle_name,last_name,suffix,email,student_id,course,account_status,approval_note,email_verified,created_at,reviewed_at'
    . '&role=eq.student'
    . '&registration_source=eq.app'
    . '&account_status=in.(pending,approved,rejected)'
    . '&order=created_at.desc'
    . '&limit=500';

$res = supabase_request('GET', $url, $headers);
$rows = $res['ok'] ? json_decode((string) $res['body'], true) : [];
$students = is_array($rows) ? $rows : [];

$pending = [];
$reviewed = [];
foreach ($students as $student) {
    $status = strtolower((string) ($student['account_status'] ?? 'pending'));
    if ($status === 'pending') {
        $pending[] = $student;
    } else {
        $reviewed[] = $student;
    }
}

function format_course_label($courseValue): string
{
    $course = strtoupper(trim((string) $courseValue));
    return match ($course) {
        'CS', 'BSCS' => 'BSCS',
        'IT', 'BSIT' => 'BSIT',
        default => ($course !== '' ? $course : 'N/A'),
    };
}

render_header('Manage Application', $user);
?>

<div class="mb-8 flex items-start justify-between gap-4">
  <div>
    <h2 class="text-xl font-bold text-zinc-900 mb-1">Manage Application</h2>
    <p class="text-zinc-600 text-sm">Review student app registrations and approve or reject account access.</p>
  </div>
  <div class="px-3 py-2 rounded-xl bg-zinc-100 border border-zinc-200 text-xs font-semibold text-zinc-700">
    Pending: <?= count($pending) ?>
  </div>
</div>

<div id="msg" class="fixed bottom-6 inset-x-0 mx-auto w-max z-50 px-5 py-3 rounded-xl shadow-2xl transition-all duration-300 transform translate-y-20 opacity-0 pointer-events-none font-bold text-sm"></div>

<div class="grid grid-cols-1 xl:grid-cols-2 gap-6">
  <section class="bg-white border border-zinc-200 rounded-2xl shadow-sm overflow-hidden">
    <div class="px-5 py-4 border-b border-zinc-200 flex items-center justify-between">
      <h3 class="font-bold text-zinc-900">Under Review</h3>
      <span class="text-xs font-semibold text-orange-700 bg-orange-100 border border-orange-200 px-2 py-1 rounded-full"><?= count($pending) ?></span>
    </div>
    <div class="overflow-x-auto">
      <table class="w-full text-sm text-zinc-600">
        <thead class="bg-zinc-50 border-b border-zinc-200">
          <tr>
            <th class="px-4 py-3 text-left font-bold text-zinc-900">Student</th>
            <th class="px-4 py-3 text-left font-bold text-zinc-900">Course</th>
            <th class="px-4 py-3 text-left font-bold text-zinc-900">Email Verified</th>
            <th class="px-4 py-3 text-right font-bold text-zinc-900">Action</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-zinc-200">
          <?php foreach ($pending as $s): ?>
            <?php
              $nameParts = [];
              foreach (['first_name', 'middle_name', 'last_name'] as $k) {
                  $v = trim((string) ($s[$k] ?? ''));
                  if ($v !== '') $nameParts[] = $v;
              }
              $displayName = trim(implode(' ', $nameParts));
              $suffix = trim((string) ($s['suffix'] ?? ''));
              if ($suffix !== '') $displayName .= ', ' . $suffix;
              $studentId = trim((string) ($s['student_id'] ?? ''));
              $course = format_course_label($s['course'] ?? '');
              $isEmailVerified = ($s['email_verified'] ?? false) ? 'Yes' : 'No';
            ?>
            <tr class="hover:bg-zinc-50/80 transition-colors">
              <td class="px-4 py-3">
                <div class="font-semibold text-zinc-900"><?= htmlspecialchars($displayName !== '' ? $displayName : 'Unnamed Student') ?></div>
                <div class="text-xs text-zinc-500"><?= htmlspecialchars($studentId !== '' ? $studentId : 'No student ID') ?></div>
              </td>
              <td class="px-4 py-3 font-semibold text-zinc-800"><?= htmlspecialchars($course) ?></td>
              <td class="px-4 py-3">
                <span class="text-xs font-semibold px-2 py-1 rounded-full <?= $isEmailVerified === 'Yes' ? 'bg-emerald-100 text-emerald-700 border border-emerald-200' : 'bg-zinc-100 text-zinc-600 border border-zinc-200' ?>">
                  <?= htmlspecialchars($isEmailVerified) ?>
                </span>
              </td>
              <td class="px-4 py-3 text-right">
                <div class="flex items-center justify-end gap-2">
                  <button class="btnApprove rounded-lg bg-emerald-600 text-white text-xs font-bold px-3 py-2 hover:bg-emerald-700" data-id="<?= htmlspecialchars((string) $s['id']) ?>">
                    Approve
                  </button>
                  <button class="btnReject rounded-lg bg-red-600 text-white text-xs font-bold px-3 py-2 hover:bg-red-700" data-id="<?= htmlspecialchars((string) $s['id']) ?>" data-name="<?= htmlspecialchars($displayName) ?>">
                    Reject
                  </button>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php if (count($pending) === 0): ?>
            <tr><td colspan="4" class="px-4 py-10 text-center text-zinc-500">No pending applications.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </section>

  <section class="bg-white border border-zinc-200 rounded-2xl shadow-sm overflow-hidden">
    <div class="px-5 py-4 border-b border-zinc-200 flex items-center justify-between">
      <h3 class="font-bold text-zinc-900">Reviewed Applications</h3>
      <span class="text-xs font-semibold text-zinc-700 bg-zinc-100 border border-zinc-200 px-2 py-1 rounded-full"><?= count($reviewed) ?></span>
    </div>
    <div class="overflow-x-auto max-h-[560px]">
      <table class="w-full text-sm text-zinc-600">
        <thead class="bg-zinc-50 border-b border-zinc-200 sticky top-0">
          <tr>
            <th class="px-4 py-3 text-left font-bold text-zinc-900">Student</th>
            <th class="px-4 py-3 text-left font-bold text-zinc-900">Status</th>
            <th class="px-4 py-3 text-left font-bold text-zinc-900">Note</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-zinc-200">
          <?php foreach ($reviewed as $s): ?>
            <?php
              $nameParts = [];
              foreach (['first_name', 'middle_name', 'last_name'] as $k) {
                  $v = trim((string) ($s[$k] ?? ''));
                  if ($v !== '') $nameParts[] = $v;
              }
              $displayName = trim(implode(' ', $nameParts));
              $status = strtolower((string) ($s['account_status'] ?? 'pending'));
              $note = trim((string) ($s['approval_note'] ?? ''));
              $badgeClass = $status === 'approved'
                  ? 'bg-emerald-100 text-emerald-700 border border-emerald-200'
                  : 'bg-red-100 text-red-700 border border-red-200';
            ?>
            <tr class="hover:bg-zinc-50/80 transition-colors">
              <td class="px-4 py-3 font-semibold text-zinc-900"><?= htmlspecialchars($displayName !== '' ? $displayName : 'Unnamed Student') ?></td>
              <td class="px-4 py-3">
                <span class="text-xs font-semibold px-2 py-1 rounded-full <?= $badgeClass ?>">
                  <?= htmlspecialchars(strtoupper($status)) ?>
                </span>
              </td>
              <td class="px-4 py-3 text-zinc-500"><?= htmlspecialchars($note !== '' ? $note : '-') ?></td>
            </tr>
          <?php endforeach; ?>
          <?php if (count($reviewed) === 0): ?>
            <tr><td colspan="3" class="px-4 py-10 text-center text-zinc-500">No reviewed applications yet.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </section>
</div>

<div id="rejectModal" class="fixed inset-0 z-[70] hidden items-center justify-center p-4 bg-black/50 backdrop-blur-sm">
  <div class="w-full max-w-md rounded-2xl bg-white border border-zinc-200 shadow-xl overflow-hidden">
    <div class="px-5 py-4 border-b border-zinc-200 flex items-center justify-between">
      <h4 class="text-lg font-bold text-zinc-900">Reject Application</h4>
      <button id="btnCloseReject" class="rounded-lg p-2 text-zinc-500 hover:bg-zinc-100">✕</button>
    </div>
    <div class="p-5 space-y-3">
      <p id="rejectLabel" class="text-sm text-zinc-700"></p>
      <textarea id="rejectReason" rows="4" class="w-full rounded-xl border border-zinc-300 px-3 py-2.5 text-sm text-zinc-900 outline-none focus:ring-2 focus:ring-red-500/30 focus:border-red-400" placeholder="Enter rejection reason..."></textarea>
      <div class="flex justify-end gap-2">
        <button id="btnCancelReject" class="rounded-lg border border-zinc-300 bg-white text-zinc-700 text-sm font-bold px-4 py-2">Cancel</button>
        <button id="btnSubmitReject" class="rounded-lg bg-red-600 text-white text-sm font-bold px-4 py-2 hover:bg-red-700">Reject</button>
      </div>
    </div>
  </div>
</div>

<script>
  const msg = document.getElementById('msg');
  let rejectUserId = '';

  function toast(text, isError = false) {
    msg.textContent = text;
    msg.className = `fixed bottom-6 inset-x-0 mx-auto w-max z-50 px-5 py-3 rounded-xl shadow-2xl transition-all duration-300 transform font-bold text-sm ${isError ? 'bg-red-500/20 text-red-500 border border-red-500/30' : 'bg-emerald-500/20 text-emerald-400 border border-emerald-500/30'}`;
    msg.style.opacity = '1';
    msg.style.transform = 'translateY(0)';
    setTimeout(() => {
      msg.style.opacity = '0';
      msg.style.transform = 'translateY(20px)';
    }, 2800);
  }

  async function reviewApplication(userId, action, reason = '') {
    const res = await fetch('/api/applications_review.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        csrf_token: window.CSRF_TOKEN || '',
        user_id: userId,
        action: action,
        reason: reason
      }),
    });
    const raw = await res.text();
    let data = null;
    try {
      data = JSON.parse(raw);
    } catch (_) {
      throw new Error('Server returned an invalid response. Please try again.');
    }
    if (!data.ok) throw new Error(data.error || 'Request failed');
    return data;
  }

  document.querySelectorAll('.btnApprove').forEach(btn => {
    btn.addEventListener('click', async () => {
      const uid = btn.dataset.id || '';
      if (!uid) return;
      const prev = btn.textContent;
      btn.disabled = true;
      btn.textContent = '...';
      try {
        const data = await reviewApplication(uid, 'approve', '');
        toast(
          data.email_sent === false
            ? 'Application approved, but the status email could not be sent.'
            : 'Application approved. Student notified via email.',
          false
        );
        setTimeout(() => location.reload(), 350);
      } catch (e) {
        toast(e.message || 'Failed to approve.', true);
      } finally {
        btn.disabled = false;
        btn.textContent = prev;
      }
    });
  });

  const rejectModal = document.getElementById('rejectModal');
  const rejectReason = document.getElementById('rejectReason');
  const rejectLabel = document.getElementById('rejectLabel');
  const btnSubmitReject = document.getElementById('btnSubmitReject');

  function openRejectModal(userId, name) {
    rejectUserId = userId;
    rejectReason.value = '';
    rejectLabel.textContent = `Rejecting: ${name || 'Student'}`;
    rejectModal.classList.remove('hidden');
    rejectModal.classList.add('flex');
    rejectReason.focus();
  }
  function closeRejectModal() {
    rejectModal.classList.add('hidden');
    rejectModal.classList.remove('flex');
    rejectUserId = '';
  }

  document.querySelectorAll('.btnReject').forEach(btn => {
    btn.addEventListener('click', () => openRejectModal(btn.dataset.id || '', btn.dataset.name || 'Student'));
  });
  document.getElementById('btnCloseReject').addEventListener('click', closeRejectModal);
  document.getElementById('btnCancelReject').addEventListener('click', closeRejectModal);
  rejectModal.addEventListener('click', (e) => { if (e.target === rejectModal) closeRejectModal(); });

  btnSubmitReject.addEventListener('click', async () => {
    const reason = (rejectReason.value || '').trim();
    if (!rejectUserId) return;
    if (!reason) {
      toast('Rejection reason is required.', true);
      return;
    }
    const prev = btnSubmitReject.textContent;
    btnSubmitReject.disabled = true;
    btnSubmitReject.textContent = '...';
    try {
      const data = await reviewApplication(rejectUserId, 'reject', reason);
      toast(
        data.email_sent === false
          ? 'Application rejected, but the status email could not be sent.'
          : 'Application rejected. Student notified via email.',
        false
      );
      closeRejectModal();
      setTimeout(() => location.reload(), 350);
    } catch (e) {
      toast(e.message || 'Failed to reject.', true);
    } finally {
      btnSubmitReject.disabled = false;
      btnSubmitReject.textContent = prev;
    }
  });
</script>

<?php render_footer(); ?>

