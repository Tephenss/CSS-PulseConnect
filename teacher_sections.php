<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/session.php';
session_bootstrap();

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/supabase.php';
require_once __DIR__ . '/includes/layout.php';

$user = require_role(['teacher']);

$url = rtrim(SUPABASE_URL, '/') . '/rest/v1/sections?select=id,name&status=eq.active&order=name.asc';
$headers = [
    'Accept: application/json',
    'apikey: ' . SUPABASE_KEY,
    'Authorization: Bearer ' . SUPABASE_KEY,
];

$sections = [];
$res = supabase_request('GET', $url, $headers);
if ($res['ok']) {
    $decoded = json_decode((string) $res['body'], true);
    $sections = is_array($decoded) ? $decoded : [];
}

$extractProgram = static function (string $rawName): string {
    if (strcasecmp(trim($rawName), 'IRREGULAR') === 0) {
        return 'IRREGULAR';
    }
    if (preg_match('/^(BSIT SD|BSIT BA|BSCS|BSIT)\b/i', trim($rawName), $m)) {
        $program = strtoupper(trim((string) $m[1]));
        return $program === 'BSIT' ? 'BSIT SD' : $program;
    }
    if (str_contains($rawName, '-')) {
        $parts = explode('-', $rawName, 2);
        $tail = trim((string) ($parts[1] ?? ''));
        if (preg_match('/^(BSIT SD|BSIT BA|BSCS|BSIT)\b/i', $tail, $m2)) {
            $program = strtoupper(trim((string) $m2[1]));
            return $program === 'BSIT' ? 'BSIT SD' : $program;
        }
    }
    return 'OTHER';
};

$courseOrder = ['BSIT SD', 'BSIT BA', 'BSCS'];
$courseSeen = [];
foreach ($sections as $secRow) {
    $program = $extractProgram(trim((string) ($secRow['name'] ?? '')));
    $courseSeen[$program] = true;
}
$courseTabs = $courseOrder;
if (isset($courseSeen['IRREGULAR'])) {
    $courseTabs[] = 'IRREGULAR';
}
if (isset($courseSeen['OTHER'])) {
    $courseTabs[] = 'OTHER';
}

$sectionDisplayById = [];
foreach ($sections as $sectionRow) {
    $sid = (string) ($sectionRow['id'] ?? '');
    $rawName = trim((string) ($sectionRow['name'] ?? ''));
    $programLabel = $extractProgram($rawName);
    $yearKey = 'OTHER';
    $sectionName = $rawName;
    $blockCode = $rawName;

    if (preg_match('/^(BSIT SD|BSIT BA|BSCS|BSIT)\s*([1-4])\s*([A-Z])$/i', $rawName, $m)) {
        $programLabel = strtoupper(trim((string) $m[1]));
        if ($programLabel === 'BSIT') {
            $programLabel = 'BSIT SD';
        }
        $yearKey = (string) $m[2];
        $blockCode = $yearKey . strtoupper((string) $m[3]);
        $sectionName = $programLabel . ' ' . $blockCode;
    } elseif (str_contains($rawName, '-')) {
        [$legacyYear, $legacyName] = array_pad(explode('-', $rawName, 2), 2, '');
        $sectionName = trim($legacyName);
        $programLabel = $extractProgram($sectionName);
        if (preg_match('/([1-4])(?:st|nd|rd|th)?\s*year/i', trim($legacyYear), $yearMatch)) {
            $yearKey = (string) $yearMatch[1];
        }
        if (preg_match('/\b([1-4])\s*([A-Z])$/i', $sectionName, $blockMatch)) {
            $yearKey = (string) $blockMatch[1];
            $blockCode = $yearKey . strtoupper((string) $blockMatch[2]);
        } else {
            $blockCode = $sectionName;
        }
    } elseif (preg_match('/\b([1-4])\s*([A-Z])$/i', $rawName, $blockMatch)) {
        $yearKey = (string) $blockMatch[1];
        $blockCode = $yearKey . strtoupper((string) $blockMatch[2]);
    }

    $sectionDisplayById[$sid] = [
        'year_key' => $yearKey,
        'block_code' => $blockCode,
        'section_name' => $sectionName,
        'program' => $programLabel,
    ];
}

render_header('Blocks', $user);
?>

<div class="mb-6">
  <h2 class="text-xl font-bold text-zinc-900 mb-1">Class Blocks</h2>
  <p class="text-zinc-600 text-sm">View active blocks synced by Admin. Registered student accounts appear in each block list.</p>
</div>

<div class="border-b border-zinc-200 mb-6 pt-1">
  <nav class="-mb-px flex space-x-6 overflow-x-auto" aria-label="Course Tabs">
    <?php foreach ($courseTabs as $idx => $courseCode): ?>
      <?php
        $activeTab = $idx === 0;
        $tabClass = $activeTab
          ? 'course-tab border-orange-500 text-orange-600 font-bold'
          : 'course-tab border-transparent text-zinc-500 hover:border-zinc-300 hover:text-zinc-700 font-semibold';
        $tabLabel = $courseCode === 'OTHER'
            ? 'Other Courses'
            : ($courseCode === 'IRREGULAR' ? 'Irregular' : $courseCode);
      ?>
      <button
        type="button"
        class="<?= htmlspecialchars($tabClass) ?> whitespace-nowrap border-b-2 py-3 px-1 text-sm transition"
        data-course-tab="<?= htmlspecialchars($courseCode) ?>"
      >
        <?= htmlspecialchars($tabLabel) ?>
      </button>
    <?php endforeach; ?>
  </nav>
</div>

<div id="sectionsByYear" class="mb-10">
  <?php foreach ($sections as $s): ?>
    <?php
      $sid = (string) ($s['id'] ?? '');
      $rawName = trim((string) ($s['name'] ?? ''));
      $meta = $sectionDisplayById[$sid] ?? [
          'year_key' => 'OTHER',
          'block_code' => $rawName,
          'section_name' => $rawName,
          'program' => $extractProgram($rawName),
      ];
      $programLabel = (string) $meta['program'];
    ?>
    <div
      class="relative group section-card"
      data-course="<?= htmlspecialchars($programLabel) ?>"
      data-year="<?= htmlspecialchars((string) $meta['year_key']) ?>"
      data-block="<?= htmlspecialchars((string) $meta['block_code']) ?>"
    >
      <a
        href="/teacher_section_students?id=<?= urlencode($sid) ?>&name=<?= urlencode((string) $meta['section_name']) ?>"
        class="block bg-white rounded-2xl shadow-sm border border-zinc-200 p-5 hover:-translate-y-1 hover:shadow-md hover:border-orange-500/40 transition-all duration-300 flex flex-col h-full min-h-[140px]"
      >
        <div class="mt-auto">
          <h3 class="text-xl font-bold text-zinc-900 leading-tight"><?= htmlspecialchars((string) $meta['block_code']) ?></h3>
          <p class="text-xs font-semibold tracking-wide text-zinc-500 mt-1 uppercase">Class Block</p>
        </div>
        <div class="absolute bottom-0 right-0 w-16 h-16 bg-gradient-to-tl from-zinc-100 to-transparent rounded-tl-[40px] rounded-br-[15px] pointer-events-none opacity-50 group-hover:from-orange-100 transition-colors"></div>
      </a>
    </div>
  <?php endforeach; ?>

  <?php if (count($sections) === 0): ?>
    <div class="col-span-full bg-white rounded-3xl border border-dashed border-zinc-300 py-16 flex flex-col items-center justify-center text-center">
      <div class="w-16 h-16 rounded-full bg-zinc-50 border border-zinc-200 flex items-center justify-center mb-5 shadow-sm">
        <svg class="w-7 h-7 text-zinc-400" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6A2.25 2.25 0 016 3.75h2.25A2.25 2.25 0 0110.5 6v2.25a2.25 2.25 0 01-2.25 2.25H6a2.25 2.25 0 01-2.25-2.25V6zM3.75 15.75A2.25 2.25 0 016 13.5h2.25a2.25 2.25 0 012.25 2.25V18a2.25 2.25 0 01-2.25 2.25H6A2.25 2.25 0 013.75 18v-2.25zM13.5 6a2.25 2.25 0 012.25-2.25H18A2.25 2.25 0 0120.25 6v2.25A2.25 2.25 0 0118 10.5h-2.25a2.25 2.25 0 01-2.25-2.25V6zM13.5 15.75a2.25 2.25 0 012.25-2.25H18a2.25 2.25 0 012.25 2.25V18A2.25 2.25 0 0118 20.25h-2.25A2.25 2.25 0 0113.5 18v-2.25z"/></svg>
      </div>
      <p class="text-zinc-800 font-bold text-lg mb-1">No blocks exist</p>
      <p class="text-zinc-500 text-sm max-w-sm">Blocks added by the Admin will appear here.</p>
    </div>
  <?php endif; ?>
</div>

<script>
  const sectionCards = Array.from(document.querySelectorAll('.section-card'));
  const courseTabButtons = Array.from(document.querySelectorAll('.course-tab'));
  const sectionsByYear = document.getElementById('sectionsByYear');
  const yearLabels = {
    '1': '1st Year',
    '2': '2nd Year',
    '3': '3rd Year',
    '4': '4th Year',
    'OTHER': 'Other Blocks',
  };
  const yearOrder = ['1', '2', '3', '4', 'OTHER'];

  if (sectionsByYear && sectionCards.length > 0) {
    sectionsByYear.innerHTML = '';

    courseTabButtons.forEach((tab) => {
      const course = (tab.dataset.courseTab || '').toUpperCase();
      const panel = document.createElement('section');
      panel.className = 'course-panel space-y-8 hidden';
      panel.dataset.coursePanel = course;

      const courseCards = sectionCards.filter(
        (card) => (card.dataset.course || '').toUpperCase() === course,
      );

      yearOrder.forEach((year) => {
        const cards = courseCards
          .filter((card) => (card.dataset.year || 'OTHER').toUpperCase() === year)
          .sort((a, b) =>
            (a.dataset.block || '').localeCompare(
              b.dataset.block || '',
              undefined,
              { numeric: true, sensitivity: 'base' },
            ),
          );
        if (cards.length === 0) return;

        const group = document.createElement('div');
        group.className = 'year-group';

        const heading = document.createElement('div');
        heading.className = 'mb-3 flex items-center gap-3';

        const title = document.createElement('h3');
        title.className = 'text-sm font-extrabold uppercase tracking-wider text-zinc-800';
        title.textContent = yearLabels[year] || yearLabels.OTHER;

        const divider = document.createElement('div');
        divider.className = 'h-px flex-1 bg-zinc-200';

        const count = document.createElement('span');
        count.className = 'rounded-full bg-orange-50 px-2.5 py-1 text-[10px] font-bold text-orange-600';
        count.textContent = `${cards.length} block${cards.length === 1 ? '' : 's'}`;

        heading.append(title, divider, count);

        const scroller = document.createElement('div');
        scroller.className = 'overflow-x-auto overscroll-x-contain pb-2';

        const row = document.createElement('div');
        row.className = 'grid gap-4';
        row.style.gridTemplateColumns = `repeat(${cards.length}, minmax(145px, 1fr))`;
        row.style.minWidth = 'max-content';

        cards.forEach((card) => {
          card.classList.add('min-w-[145px]');
          row.appendChild(card);
        });
        scroller.appendChild(row);
        group.append(heading, scroller);
        panel.appendChild(group);
      });

      if (courseCards.length === 0) {
        const empty = document.createElement('div');
        empty.className = 'rounded-3xl border border-dashed border-zinc-300 bg-white py-14 text-center';
        empty.innerHTML =
          '<p class="text-lg font-bold text-zinc-800">No blocks in this program</p>' +
          '<p class="mt-1 text-sm text-zinc-500">Blocks added by the Admin will appear here.</p>';
        panel.appendChild(empty);
      }

      sectionsByYear.appendChild(panel);
    });
  }

  function applyCourseFilter(selectedCourse) {
    document.querySelectorAll('.course-panel').forEach((panel) => {
      const panelCourse = (panel.dataset.coursePanel || '').toUpperCase();
      panel.classList.toggle('hidden', panelCourse !== selectedCourse);
    });
    courseTabButtons.forEach((btn) => {
      const isActive = (btn.dataset.courseTab || '').toUpperCase() === selectedCourse;
      btn.classList.toggle('border-orange-500', isActive);
      btn.classList.toggle('text-orange-600', isActive);
      btn.classList.toggle('font-bold', isActive);
      btn.classList.toggle('border-transparent', !isActive);
      btn.classList.toggle('text-zinc-500', !isActive);
      btn.classList.toggle('font-semibold', !isActive);
    });
  }

  if (courseTabButtons.length > 0) {
    courseTabButtons.forEach((btn) => {
      btn.addEventListener('click', () => {
        const selected = (btn.dataset.courseTab || '').toUpperCase();
        if (selected) applyCourseFilter(selected);
      });
    });
    const initialCourse = (courseTabButtons[0].dataset.courseTab || '').toUpperCase();
    if (initialCourse) applyCourseFilter(initialCourse);
  }
</script>

<?php
render_footer();
