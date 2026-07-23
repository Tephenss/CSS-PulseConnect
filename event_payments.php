<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/session.php';
session_bootstrap();

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/supabase.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/event_tabs.php';
require_once __DIR__ . '/includes/registration_access.php';
require_once __DIR__ . '/includes/event_registration_submit.php';
require_once __DIR__ . '/includes/student_requirements.php';

$user = require_role(['admin', 'teacher']);
$role = (string) ($user['role'] ?? '');
$userId = (string) ($user['id'] ?? '');

$eventId = trim((string) ($_GET['event_id'] ?? $_GET['id'] ?? ''));
if ($eventId === '') {
    http_response_code(400);
    echo 'Missing event_id';
    exit;
}

$headers = [
    'Accept: application/json',
    'apikey: ' . SUPABASE_KEY,
    'Authorization: Bearer ' . SUPABASE_KEY,
];

$event = fetch_event_with_registration_settings($eventId, $headers);
if (!is_array($event)) {
    http_response_code(404);
    echo 'Event not found';
    exit;
}

$isCreator = (string) ($event['created_by'] ?? '') === $userId;
if ($role === 'teacher' && !$isCreator) {
    http_response_code(403);
    echo 'Only the event creator can manage payments.';
    exit;
}

if (event_is_free_registration_event($event)) {
    header('Location: /event_view.php?id=' . rawurlencode($eventId));
    exit;
}

$status = (string) ($event['status'] ?? '');
$hasStudentRequirements = event_has_student_requirements($eventId, $headers);
$backHref = event_management_return_to($role, isset($_GET['return_to']) ? (string) $_GET['return_to'] : null);
$returnTo = $backHref;
$isEventCreatorFlag = $role === 'admin' || $isCreator;
$groups = build_paid_event_payment_roster($event, $headers);
$csrfToken = csrf_ensure_token();
$registrationCount = fetch_event_registration_count($eventId, $headers, $event);

$selectedGroupKey = trim((string) ($_GET['g'] ?? ''));
$selectedBlockLabel = trim((string) ($_GET['b'] ?? ''));
$isBlockPage = $selectedGroupKey !== '' && $selectedBlockLabel !== '';

$activeGroup = null;
$activeBlock = null;
if ($isBlockPage) {
    foreach ($groups as $group) {
        if ((string) ($group['group_key'] ?? '') !== $selectedGroupKey) {
            continue;
        }
        $activeGroup = $group;
        foreach (($group['blocks'] ?? []) as $block) {
            if ((string) ($block['block_label'] ?? '') === $selectedBlockLabel) {
                $activeBlock = $block;
                break;
            }
        }
        break;
    }

    if ($activeGroup === null || $activeBlock === null) {
        header('Location: /event_payments.php?event_id=' . rawurlencode($eventId));
        exit;
    }
}

$overviewUrl = '/event_payments.php?event_id=' . rawurlencode($eventId)
    . '&return_to=' . rawurlencode($returnTo);
$eventFee = event_settlement_fee($event);
$eventFeeLabel = format_event_fee_php($eventFee);
$pageTitle = $isBlockPage
    ? ((string) ($activeGroup['group_label'] ?? 'Group') . ' · ' . (string) ($activeBlock['block_label'] ?? 'Block'))
    : 'Event Payments';

$yearFilterLabels = [
    '1' => '1st Year',
    '2' => '2nd Year',
    '3' => '3rd Year',
    '4' => '4th Year',
];
$overviewYearOptions = [];
$overviewCourseOptions = [];
foreach ($groups as $groupRow) {
    $yearKey = (string) ((int) ($groupRow['sort_year'] ?? 99));
    if ($yearKey !== '99' && !isset($overviewYearOptions[$yearKey])) {
        $overviewYearOptions[$yearKey] = $yearFilterLabels[$yearKey]
            ?? ($yearKey . ' Year');
    }
    $courseKey = trim((string) ($groupRow['course_label'] ?? ''));
    if ($courseKey !== '' && !isset($overviewCourseOptions[$courseKey])) {
        $overviewCourseOptions[$courseKey] = $courseKey;
    }
}
ksort($overviewYearOptions, SORT_NUMERIC);
ksort($overviewCourseOptions, SORT_NATURAL | SORT_FLAG_CASE);

render_header($pageTitle, $user);
?>

<?php
$headerBackHref = $isBlockPage
    ? $overviewUrl
    : $backHref;
if ($isBlockPage) {
    echo '<div class="mb-4"><div class="flex items-center justify-between flex-wrap gap-4 pb-4 border-b border-zinc-200 mb-6"><div class="flex items-center gap-3">';
    echo render_event_back_button($headerBackHref, 'Back to blocks');
    echo '<div>';
    echo '<div class="text-xs font-semibold text-zinc-500 mb-0.5">'
        . htmlspecialchars((string) ($event['title'] ?? 'Event')) . ' · Payments</div>';
    echo '<h2 class="text-xl md:text-2xl font-bold text-zinc-900">'
        . htmlspecialchars((string) ($activeBlock['block_label'] ?? 'Block')) . '</h2>';
    echo '<p class="text-sm text-zinc-500 mt-0.5">'
        . htmlspecialchars((string) ($activeGroup['group_label'] ?? ''))
        . ' · ' . count($activeBlock['students'] ?? []) . ' student'
        . (count($activeBlock['students'] ?? []) === 1 ? '' : 's')
        . '</p>';
    echo '</div></div></div></div>';
} else {
    $paymentsSubtitle = 'Paid event payments · '
        . format_event_registration_total($registrationCount, $event)
        . ' slots used';
    if ($eventFeeLabel !== '') {
        $paymentsSubtitle .= ' · Full fee ' . $eventFeeLabel;
    }
    render_event_page_header([
        'back_href' => $headerBackHref,
        'title' => (string) ($event['title'] ?? 'Event'),
        'subtitle' => $paymentsSubtitle,
        'back_title' => 'Back',
    ]);
}
?>

  <?php if (!$isBlockPage): ?>
    <?php
    render_event_tabs([
        'event_id' => $eventId,
        'current_tab' => 'payments',
        'role' => $role,
        'event_status' => $status,
        'return_to' => $returnTo,
        'has_student_requirements' => $hasStudentRequirements,
        'is_event_creator' => $isEventCreatorFlag,
        'is_paid_event' => true,
    ]);
    ?>

    <div class="rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900 mb-4">
      Choose a block to open its student list.
      <?php if ($eventFeeLabel !== ''): ?>
        Full settlement amount is <strong><?= htmlspecialchars($eventFeeLabel) ?></strong>.
      <?php endif; ?>
      Use <strong>Full</strong> or <strong>Partial</strong> to secure a slot.
    </div>

    <?php if ($groups === []): ?>
      <div class="rounded-2xl border border-dashed border-zinc-300 bg-white py-16 text-center text-zinc-500">
        No students match this event’s target participants yet.
      </div>
    <?php else: ?>
      <div class="flex flex-col lg:flex-row lg:items-center gap-2 mb-4">
        <div class="relative flex-1">
          <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none text-zinc-400">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z"/></svg>
          </div>
          <input type="search" id="paymentsBlockSearch" placeholder="Search block, year, or course…"
                 class="w-full rounded-xl border border-zinc-200 bg-white pl-10 pr-3 py-2.5 text-sm text-zinc-900 outline-none focus:border-orange-400 focus:ring-2 focus:ring-orange-500/20 shadow-sm">
        </div>
        <div class="flex flex-wrap items-center gap-2">
          <select id="paymentsYearFilter"
                  class="rounded-xl border border-zinc-200 bg-white px-3 py-2.5 text-sm text-zinc-700 outline-none focus:border-orange-400 focus:ring-2 focus:ring-orange-500/20 shadow-sm">
            <option value="all">All years</option>
            <?php foreach ($overviewYearOptions as $yearValue => $yearLabel): ?>
              <option value="<?= htmlspecialchars((string) $yearValue) ?>"><?= htmlspecialchars($yearLabel) ?></option>
            <?php endforeach; ?>
          </select>
          <select id="paymentsCourseFilter"
                  class="rounded-xl border border-zinc-200 bg-white px-3 py-2.5 text-sm text-zinc-700 outline-none focus:border-orange-400 focus:ring-2 focus:ring-orange-500/20 shadow-sm">
            <option value="all">All courses</option>
            <?php foreach ($overviewCourseOptions as $courseValue => $courseLabel): ?>
              <option value="<?= htmlspecialchars((string) $courseValue) ?>"><?= htmlspecialchars($courseLabel) ?></option>
            <?php endforeach; ?>
          </select>
          <select id="paymentsBlockStatusFilter"
                  class="rounded-xl border border-zinc-200 bg-white px-3 py-2.5 text-sm text-zinc-700 outline-none focus:border-orange-400 focus:ring-2 focus:ring-orange-500/20 shadow-sm">
            <option value="all">All payment status</option>
            <option value="pending">Has pending</option>
            <option value="partial">Partially saved</option>
            <option value="complete">Fully saved</option>
          </select>
        </div>
      </div>

      <div id="paymentsBlockEmpty" class="hidden rounded-2xl border border-dashed border-zinc-300 bg-white py-16 text-center text-zinc-500">
        No blocks match your search or filter.
      </div>

      <div id="paymentsBlockList" class="space-y-6">
        <?php foreach ($groups as $group): ?>
          <?php
            $studentTotal = 0;
            $savedTotal = 0;
            foreach (($group['blocks'] ?? []) as $b) {
                $blockStudents = $b['students'] ?? [];
                $studentTotal += count($blockStudents);
                foreach ($blockStudents as $st) {
                    if (!empty($st['registered'])) {
                        $savedTotal++;
                    }
                }
            }
            $groupYear = (string) ((int) ($group['sort_year'] ?? 99));
            $groupCourse = (string) ($group['course_label'] ?? '');
            $groupSearch = strtolower(trim(
                (string) ($group['group_label'] ?? '') . ' ' . $groupCourse
            ));
          ?>
          <section class="payment-group-section rounded-2xl border border-zinc-200 bg-white shadow-sm overflow-hidden"
                   data-year="<?= htmlspecialchars($groupYear) ?>"
                   data-course="<?= htmlspecialchars($groupCourse) ?>"
                   data-search="<?= htmlspecialchars($groupSearch) ?>">
            <div class="px-5 py-3 border-b border-zinc-100 bg-zinc-50 flex items-center justify-between gap-3">
              <h3 class="text-sm font-black uppercase tracking-widest text-zinc-700">
                <?= htmlspecialchars((string) ($group['group_label'] ?? 'Group')) ?>
              </h3>
              <span class="text-xs font-semibold text-zinc-500 shrink-0">
                <?= $studentTotal ?> student<?= $studentTotal === 1 ? '' : 's' ?>
              </span>
            </div>
            <div class="p-4 grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-3">
              <?php foreach (($group['blocks'] ?? []) as $block): ?>
                <?php
                  $blockStudents = $block['students'] ?? [];
                  $blockCount = count($blockStudents);
                  $savedCount = 0;
                  foreach ($blockStudents as $st) {
                      if (!empty($st['registered'])) {
                          $savedCount++;
                      }
                  }
                  $blockStatus = 'pending';
                  if ($blockCount > 0 && $savedCount >= $blockCount) {
                      $blockStatus = 'complete';
                  } elseif ($savedCount > 0) {
                      $blockStatus = 'partial';
                  }
                  $blockHref = $overviewUrl
                      . '&g=' . rawurlencode((string) ($group['group_key'] ?? ''))
                      . '&b=' . rawurlencode((string) ($block['block_label'] ?? ''));
                  $blockSearch = strtolower(trim(
                      (string) ($block['block_label'] ?? '') . ' '
                      . (string) ($group['group_label'] ?? '') . ' '
                      . $groupCourse
                  ));
                ?>
                <a href="<?= htmlspecialchars($blockHref) ?>"
                   class="payment-block-card rounded-xl border border-zinc-200 bg-zinc-50 hover:bg-orange-50 hover:border-orange-300 transition p-4 group block"
                   data-search="<?= htmlspecialchars($blockSearch) ?>"
                   data-status="<?= htmlspecialchars($blockStatus) ?>"
                   data-year="<?= htmlspecialchars($groupYear) ?>"
                   data-course="<?= htmlspecialchars($groupCourse) ?>">
                  <div class="flex items-start justify-between gap-2">
                    <div>
                      <div class="text-base font-bold text-zinc-900 group-hover:text-orange-700">
                        <?= htmlspecialchars((string) ($block['block_label'] ?? 'Block')) ?>
                      </div>
                      <div class="text-xs text-zinc-500 mt-1">
                        <?= $blockCount ?> enrolled<?= $savedCount > 0 ? ' · ' . $savedCount . ' saved' : '' ?>
                      </div>
                    </div>
                    <svg class="w-4 h-4 text-zinc-400 group-hover:text-orange-500 mt-1 shrink-0" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5"/></svg>
                  </div>
                </a>
              <?php endforeach; ?>
            </div>
          </section>
        <?php endforeach; ?>
      </div>

      <script>
      (() => {
        const searchInput = document.getElementById('paymentsBlockSearch');
        const yearFilter = document.getElementById('paymentsYearFilter');
        const courseFilter = document.getElementById('paymentsCourseFilter');
        const statusFilter = document.getElementById('paymentsBlockStatusFilter');
        const emptyState = document.getElementById('paymentsBlockEmpty');
        const groups = Array.from(document.querySelectorAll('.payment-group-section'));

        function applyFilters() {
          const query = (searchInput?.value || '').trim().toLowerCase();
          const year = yearFilter?.value || 'all';
          const course = courseFilter?.value || 'all';
          const status = statusFilter?.value || 'all';
          let visibleBlocks = 0;

          groups.forEach((section) => {
            const cards = Array.from(section.querySelectorAll('.payment-block-card'));
            let sectionVisible = 0;

            cards.forEach((card) => {
              const searchBlob = card.dataset.search || '';
              const cardYear = card.dataset.year || '';
              const cardCourse = card.dataset.course || '';
              const cardStatus = card.dataset.status || 'pending';

              const matchesSearch = query === '' || searchBlob.includes(query);
              const matchesYear = year === 'all' || cardYear === year;
              const matchesCourse = course === 'all' || cardCourse === course;
              const matchesStatus = status === 'all'
                || (status === 'pending' && (cardStatus === 'pending' || cardStatus === 'partial'))
                || cardStatus === status;

              const show = matchesSearch && matchesYear && matchesCourse && matchesStatus;
              card.classList.toggle('hidden', !show);
              if (show) sectionVisible += 1;
            });

            section.classList.toggle('hidden', sectionVisible === 0);
            visibleBlocks += sectionVisible;
          });

          if (emptyState) {
            emptyState.classList.toggle('hidden', visibleBlocks > 0);
          }
        }

        [searchInput, yearFilter, courseFilter, statusFilter].forEach((el) => {
          if (!el) return;
          el.addEventListener('input', applyFilters);
          el.addEventListener('change', applyFilters);
        });
      })();
      </script>
    <?php endif; ?>

  <?php else: ?>
    <!-- Dedicated block page: students only -->
    <div class="rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900 mb-4">
      <?php if ($eventFeeLabel !== ''): ?>
        Full event fee: <strong><?= htmlspecialchars($eventFeeLabel) ?></strong>.
      <?php endif; ?>
      Tap <strong>Full</strong> to mark as fully paid, or <strong>Partial</strong> to enter an amount toward the full fee.
    </div>

    <?php $students = $activeBlock['students'] ?? []; ?>
    <?php if ($students === []): ?>
      <div class="rounded-2xl border border-dashed border-zinc-300 bg-white py-16 text-center text-zinc-500">
        No students in this block.
      </div>
    <?php else: ?>
      <div class="flex flex-col sm:flex-row sm:items-center gap-2 mb-4">
        <div class="relative flex-1">
          <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none text-zinc-400">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z"/></svg>
          </div>
          <input type="search" id="paymentsStudentSearch" placeholder="Search name, student ID, or email…"
                 class="w-full rounded-xl border border-zinc-200 bg-white pl-10 pr-3 py-2.5 text-sm text-zinc-900 outline-none focus:border-orange-400 focus:ring-2 focus:ring-orange-500/20 shadow-sm">
        </div>
        <select id="paymentsStudentStatusFilter"
                class="rounded-xl border border-zinc-200 bg-white px-3 py-2.5 text-sm text-zinc-700 outline-none focus:border-orange-400 focus:ring-2 focus:ring-orange-500/20 shadow-sm">
          <option value="all">All statuses</option>
          <option value="pending">Pending</option>
          <option value="partial">Partial</option>
          <option value="full">Fully paid</option>
        </select>
      </div>

      <div id="paymentsStudentEmpty" class="hidden rounded-2xl border border-dashed border-zinc-300 bg-white py-16 text-center text-zinc-500 mb-4">
        No students match your search or filter.
      </div>

      <div id="paymentsStudentGrid" class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-3">
        <?php foreach ($students as $student): ?>
          <?php
            $sid = (string) ($student['id'] ?? '');
            $registered = !empty($student['registered']);
            $amount = $student['amount_paid'];
            $amountDisplay = $amount === null ? '' : (string) $amount;
            $note = strtolower(trim((string) ($student['payment_note'] ?? '')));
            $paidAmount = $amount !== null ? (float) $amount : 0.0;
            $isFullPaid = $registered && (
                str_contains($note, 'full')
                || ($eventFee !== null && $eventFee > 0 && $paidAmount >= $eventFee)
            );
            $progressPct = 0;
            if ($eventFee !== null && $eventFee > 0 && $paidAmount > 0) {
                $progressPct = (int) min(100, round(($paidAmount / $eventFee) * 100));
            } elseif ($isFullPaid) {
                $progressPct = 100;
            }
            $payStatus = !$registered ? 'pending' : ($isFullPaid ? 'full' : 'partial');
            $searchBlob = strtolower(trim(
                (string) ($student['display_name'] ?? '') . ' '
                . (string) ($student['student_id'] ?? '') . ' '
                . (string) ($student['email'] ?? '')
            ));
          ?>
          <div class="rounded-xl border border-zinc-200 bg-white p-4 payment-student-card shadow-sm"
               data-student-id="<?= htmlspecialchars($sid) ?>"
               data-registered="<?= $registered ? '1' : '0' ?>"
               data-status="<?= htmlspecialchars($payStatus) ?>"
               data-search="<?= htmlspecialchars($searchBlob) ?>">
            <div class="flex items-start justify-between gap-2 mb-3">
              <div class="min-w-0">
                <div class="text-sm font-bold text-zinc-900 truncate"><?= htmlspecialchars((string) ($student['display_name'] ?? 'Student')) ?></div>
                <div class="payment-student-meta text-[11px] text-zinc-500 truncate mt-0.5">
                  <?= htmlspecialchars(trim((string) ($student['student_id'] ?? '') !== '' ? (string) $student['student_id'] : (string) ($student['email'] ?? ''))) ?>
                </div>
              </div>
              <?php if ($registered && $isFullPaid): ?>
                <span class="shrink-0 text-[10px] font-bold uppercase tracking-wide rounded-md bg-emerald-100 text-emerald-800 border border-emerald-200 px-1.5 py-0.5">Fully paid</span>
              <?php elseif ($registered): ?>
                <span class="shrink-0 text-[10px] font-bold uppercase tracking-wide rounded-md bg-amber-100 text-amber-800 border border-amber-200 px-1.5 py-0.5">Partial</span>
              <?php else: ?>
                <span class="shrink-0 text-[10px] font-bold uppercase tracking-wide rounded-md bg-zinc-100 text-zinc-600 border border-zinc-200 px-1.5 py-0.5">Pending</span>
              <?php endif; ?>
            </div>

            <?php if ($eventFee !== null && $eventFee > 0): ?>
              <div class="mb-3">
                <div class="flex items-center justify-between text-[11px] font-semibold text-zinc-600 mb-1">
                  <span class="payment-progress-label">
                    <?php if ($registered && $paidAmount > 0): ?>
                      ₱<?= htmlspecialchars(number_format($paidAmount, 2)) ?> / <?= htmlspecialchars($eventFeeLabel) ?>
                    <?php elseif ($isFullPaid): ?>
                      <?= htmlspecialchars($eventFeeLabel) ?> / <?= htmlspecialchars($eventFeeLabel) ?>
                    <?php else: ?>
                      ₱0.00 / <?= htmlspecialchars($eventFeeLabel) ?>
                    <?php endif; ?>
                  </span>
                  <span class="payment-progress-pct"><?= $progressPct ?>%</span>
                </div>
                <div class="h-1.5 rounded-full bg-zinc-100 overflow-hidden">
                  <div class="payment-progress-bar h-full rounded-full <?= $progressPct >= 100 ? 'bg-emerald-500' : 'bg-orange-500' ?>" style="width: <?= $progressPct ?>%"></div>
                </div>
              </div>
            <?php endif; ?>

            <div class="flex flex-wrap items-center gap-2 payment-actions">
              <button type="button"
                      class="btn-pay-full rounded-lg bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-bold px-3.5 py-2 transition disabled:opacity-50">
                Full
              </button>
              <button type="button"
                      class="btn-pay-partial rounded-lg border border-orange-300 bg-orange-50 hover:bg-orange-100 text-orange-800 text-xs font-bold px-3.5 py-2 transition">
                Partial
              </button>
            </div>

            <div class="payment-partial-panel mt-3 hidden">
              <div class="flex items-center gap-2">
                <div class="relative flex-1">
                  <span class="absolute left-2.5 top-1/2 -translate-y-1/2 text-xs font-bold text-zinc-400">₱</span>
                  <input type="number" min="0" step="0.01"<?= $eventFee !== null ? ' max="' . htmlspecialchars((string) $eventFee) . '"' : '' ?>
                         class="payment-amount w-full rounded-lg border border-zinc-200 bg-white pl-6 pr-2 py-2 text-sm text-zinc-900 outline-none focus:ring-2 focus:ring-orange-500/30 focus:border-orange-400"
                         placeholder="Partial amount"
                         value="<?= htmlspecialchars($amountDisplay) ?>" />
                </div>
                <button type="button"
                        class="btn-payment-register shrink-0 rounded-lg bg-orange-600 hover:bg-orange-700 text-white text-xs font-bold px-3 py-2 transition disabled:opacity-50 disabled:cursor-not-allowed">
                  <?= $registered ? 'Update' : 'Register' ?>
                </button>
                <button type="button"
                        class="btn-partial-cancel shrink-0 rounded-lg border border-zinc-200 bg-white text-zinc-600 text-xs font-bold px-2.5 py-2 hover:bg-zinc-50 transition">
                  Cancel
                </button>
              </div>
            </div>

            <div class="payment-feedback mt-2 text-[11px] hidden"></div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <script>
    (() => {
      const eventId = <?= json_encode($eventId) ?>;
      const csrfToken = <?= json_encode($csrfToken) ?>;
      const eventFee = <?= json_encode($eventFee) ?>;
      const searchInput = document.getElementById('paymentsStudentSearch');
      const statusFilter = document.getElementById('paymentsStudentStatusFilter');
      const emptyState = document.getElementById('paymentsStudentEmpty');

      function applyStudentFilters() {
        const query = (searchInput?.value || '').trim().toLowerCase();
        const status = statusFilter?.value || 'all';
        const cards = Array.from(document.querySelectorAll('.payment-student-card'));
        let visible = 0;

        cards.forEach((card) => {
          const searchBlob = card.dataset.search || '';
          const cardStatus = card.dataset.status || 'pending';
          const matchesSearch = query === '' || searchBlob.includes(query);
          const matchesStatus = status === 'all' || cardStatus === status;
          const show = matchesSearch && matchesStatus;
          card.classList.toggle('hidden', !show);
          if (show) visible += 1;
        });

        if (emptyState) {
          emptyState.classList.toggle('hidden', visible > 0 || cards.length === 0);
        }
      }

      [searchInput, statusFilter].forEach((el) => {
        if (!el) return;
        el.addEventListener('input', applyStudentFilters);
        el.addEventListener('change', applyStudentFilters);
      });

      function setBusy(card, busy) {
        card.querySelectorAll('button').forEach((b) => { b.disabled = !!busy; });
      }

      function showFeedback(card, message, ok) {
        const feedback = card.querySelector('.payment-feedback');
        if (!feedback) return;
        feedback.classList.remove('hidden', 'text-emerald-600', 'text-red-600');
        feedback.classList.add(ok ? 'text-emerald-600' : 'text-red-600');
        feedback.textContent = message;
      }

      function updateProgress(card, paidAmount, mode) {
        const fee = (eventFee != null && Number(eventFee) > 0) ? Number(eventFee) : null;
        let paid = Number(paidAmount) || 0;
        if (mode === 'full' && fee != null) paid = fee;

        const badge = card.querySelector('span.shrink-0');
        const isFull = mode === 'full' || (fee != null && paid >= fee);
        if (badge) {
          if (isFull) {
            badge.className = 'shrink-0 text-[10px] font-bold uppercase tracking-wide rounded-md bg-emerald-100 text-emerald-800 border border-emerald-200 px-1.5 py-0.5';
            badge.textContent = 'Fully paid';
          } else {
            badge.className = 'shrink-0 text-[10px] font-bold uppercase tracking-wide rounded-md bg-amber-100 text-amber-800 border border-amber-200 px-1.5 py-0.5';
            badge.textContent = 'Partial';
          }
        }

        const label = card.querySelector('.payment-progress-label');
        const pctEl = card.querySelector('.payment-progress-pct');
        const bar = card.querySelector('.payment-progress-bar');
        if (fee != null && label && pctEl && bar) {
          const pct = Math.min(100, Math.round((paid / fee) * 100));
          label.textContent = '₱' + paid.toFixed(2) + ' / ₱' + fee.toFixed(2);
          pctEl.textContent = pct + '%';
          bar.style.width = pct + '%';
          bar.classList.toggle('bg-emerald-500', pct >= 100);
          bar.classList.toggle('bg-orange-500', pct < 100);
        }

        card.dataset.registered = '1';
        card.dataset.status = isFull ? 'full' : 'partial';
        const regBtn = card.querySelector('.btn-payment-register');
        if (regBtn) regBtn.textContent = 'Update';
        applyStudentFilters();
      }

      async function submitPayment(card, { mode, amount }) {
        const studentId = card.dataset.studentId || '';
        if (!studentId) return;

        setBusy(card, true);
        try {
          const payload = {
            csrf_token: csrfToken,
            event_id: eventId,
            student_id: studentId,
            payment_mode: mode,
          };
          if (mode === 'partial') {
            payload.amount_paid = Number(amount);
          }

          const res = await fetch('/api/event_payment_register.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
            body: JSON.stringify(payload),
          });
          const data = await res.json().catch(() => ({}));
          if (!res.ok || !data.ok) {
            throw new Error(data.error || 'Failed to save payment.');
          }

          const paid = mode === 'full'
            ? (data.amount_paid ?? eventFee ?? 0)
            : Number(amount);
          updateProgress(card, paid, mode);

          const panel = card.querySelector('.payment-partial-panel');
          if (panel) panel.classList.add('hidden');

          showFeedback(card, data.message || 'Payment saved and slot secured.', true);
        } catch (err) {
          showFeedback(card, err.message || 'Failed to save payment.', false);
        } finally {
          setBusy(card, false);
        }
      }

      document.querySelectorAll('.payment-student-card').forEach((card) => {
        const fullBtn = card.querySelector('.btn-pay-full');
        const partialBtn = card.querySelector('.btn-pay-partial');
        const cancelBtn = card.querySelector('.btn-partial-cancel');
        const registerBtn = card.querySelector('.btn-payment-register');
        const panel = card.querySelector('.payment-partial-panel');
        const amountInput = card.querySelector('.payment-amount');

        if (fullBtn) {
          fullBtn.addEventListener('click', () => {
            submitPayment(card, { mode: 'full' });
          });
        }

        if (partialBtn && panel) {
          partialBtn.addEventListener('click', () => {
            panel.classList.remove('hidden');
            if (amountInput) amountInput.focus();
          });
        }

        if (cancelBtn && panel) {
          cancelBtn.addEventListener('click', () => {
            panel.classList.add('hidden');
          });
        }

        if (registerBtn) {
          registerBtn.addEventListener('click', () => {
            const amount = amountInput ? amountInput.value.trim() : '';
            if (amount === '' || Number(amount) <= 0 || Number.isNaN(Number(amount))) {
              showFeedback(card, 'Enter a valid partial amount.', false);
              return;
            }
            if (eventFee != null && Number(amount) > Number(eventFee)) {
              showFeedback(card, 'Partial amount cannot exceed the full fee.', false);
              return;
            }
            submitPayment(card, { mode: 'partial', amount });
          });
        }
      });
    })();
    </script>
  <?php endif; ?>
</div>

<?php render_footer(); ?>
