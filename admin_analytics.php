<?php
declare(strict_types=1);

/**
 * Admin Analytics — yearly event repository with year / date-range filters
 * and per-event participant metrics (registrations + check-ins).
 */

require_once __DIR__ . '/includes/session.php';
session_bootstrap();

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/supabase.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/layout.php';

$user = require_role(['admin']);

$headers = [
    'Accept: application/json',
    'apikey: ' . SUPABASE_KEY,
    'Authorization: Bearer ' . SUPABASE_KEY,
];

$tz = new DateTimeZone('Asia/Manila');
$nowManila = new DateTimeImmutable('now', $tz);
$currentYear = (int) $nowManila->format('Y');
// System launched in 2026 — year filter never lists earlier calendars.
$systemStartYear = 2026;

$yearRaw = trim((string) ($_GET['year'] ?? ''));
$dateFromRaw = trim((string) ($_GET['from'] ?? ''));
$dateToRaw = trim((string) ($_GET['to'] ?? ''));
$statusFilter = strtolower(trim((string) ($_GET['status'] ?? 'all')));
$preset = strtolower(trim((string) ($_GET['preset'] ?? '')));

if (!in_array($statusFilter, ['all', 'published', 'finished', 'pending', 'approved', 'archived'], true)) {
    $statusFilter = 'all';
}

// Quick presets override from/to (and clear year when using relative range).
if ($preset === 'last_3_months') {
    $dateFromRaw = $nowManila->modify('-3 months')->format('Y-m-d');
    $dateToRaw = $nowManila->format('Y-m-d');
    $yearRaw = '';
} elseif ($preset === 'last_6_months') {
    $dateFromRaw = $nowManila->modify('-6 months')->format('Y-m-d');
    $dateToRaw = $nowManila->format('Y-m-d');
    $yearRaw = '';
} elseif ($preset === 'last_12_months') {
    $dateFromRaw = $nowManila->modify('-12 months')->format('Y-m-d');
    $dateToRaw = $nowManila->format('Y-m-d');
    $yearRaw = '';
} elseif ($preset === 'this_year') {
    $yearRaw = (string) $currentYear;
    $dateFromRaw = '';
    $dateToRaw = '';
}

$latestYear = max($currentYear, $systemStartYear);
$systemMinBound = sprintf('%04d-01-01', $systemStartYear);
$systemMaxBound = sprintf('%04d-12-31', $latestYear);
$systemMinDt = new DateTimeImmutable($systemMinBound . ' 00:00:00', $tz);
$systemMaxDt = new DateTimeImmutable($systemMaxBound . ' 23:59:59', $tz);

$clampDateRaw = static function (string $raw, DateTimeImmutable $minDt, DateTimeImmutable $maxDt, DateTimeZone $tz): string {
    $raw = trim($raw);
    if ($raw === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
        return '';
    }
    try {
        $d = new DateTimeImmutable($raw . ' 12:00:00', $tz);
    } catch (Throwable $e) {
        return '';
    }
    if ($d < $minDt) {
        return $minDt->format('Y-m-d');
    }
    if ($d > $maxDt) {
        return $maxDt->format('Y-m-d');
    }
    return $d->format('Y-m-d');
};

$selectedYear = null;
if ($yearRaw !== '' && ctype_digit($yearRaw)) {
    $y = (int) $yearRaw;
    if ($y >= $systemStartYear && $y <= $latestYear) {
        $selectedYear = $y;
    }
}

// Date picker bounds: selected year only, or full Year-list window when All.
if ($selectedYear !== null) {
    $dateMinBound = sprintf('%04d-01-01', $selectedYear);
    $dateMaxBound = sprintf('%04d-12-31', $selectedYear);
    $dateMinDt = new DateTimeImmutable($dateMinBound . ' 00:00:00', $tz);
    $dateMaxDt = new DateTimeImmutable($dateMaxBound . ' 23:59:59', $tz);
} else {
    $dateMinBound = $systemMinBound;
    $dateMaxBound = $systemMaxBound;
    $dateMinDt = $systemMinDt;
    $dateMaxDt = $systemMaxDt;
}

$dateFromRaw = $clampDateRaw($dateFromRaw, $dateMinDt, $dateMaxDt, $tz);
$dateToRaw = $clampDateRaw($dateToRaw, $dateMinDt, $dateMaxDt, $tz);

$rangeStart = null;
$rangeEnd = null;
try {
    if ($dateFromRaw !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFromRaw)) {
        $rangeStart = new DateTimeImmutable($dateFromRaw . ' 00:00:00', $tz);
    }
    if ($dateToRaw !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateToRaw)) {
        $rangeEnd = new DateTimeImmutable($dateToRaw . ' 23:59:59', $tz);
    }
} catch (Throwable $e) {
    $rangeStart = null;
    $rangeEnd = null;
}

// No dates → full selected year (or current year default).
if ($rangeStart === null && $rangeEnd === null) {
    if ($selectedYear === null) {
        $selectedYear = min($currentYear, $latestYear);
        if ($selectedYear < $systemStartYear) {
            $selectedYear = $systemStartYear;
        }
        $dateMinBound = sprintf('%04d-01-01', $selectedYear);
        $dateMaxBound = sprintf('%04d-12-31', $selectedYear);
        $dateMinDt = new DateTimeImmutable($dateMinBound . ' 00:00:00', $tz);
        $dateMaxDt = new DateTimeImmutable($dateMaxBound . ' 23:59:59', $tz);
    }
    $rangeStart = new DateTimeImmutable(sprintf('%04d-01-01 00:00:00', $selectedYear), $tz);
    $rangeEnd = new DateTimeImmutable(sprintf('%04d-12-31 23:59:59', $selectedYear), $tz);
} elseif ($rangeStart === null && $rangeEnd instanceof DateTimeImmutable) {
    $rangeStart = $dateMinDt;
} elseif ($rangeEnd === null && $rangeStart instanceof DateTimeImmutable) {
    $rangeEnd = $dateMaxDt;
}

if ($rangeStart instanceof DateTimeImmutable && $rangeStart < $dateMinDt) {
    $rangeStart = $dateMinDt;
}
if ($rangeEnd instanceof DateTimeImmutable && $rangeEnd > $dateMaxDt) {
    $rangeEnd = $dateMaxDt;
}
if ($rangeStart instanceof DateTimeImmutable && $rangeEnd instanceof DateTimeImmutable) {
    $startDay = $rangeStart->setTime(0, 0, 0);
    $endDay = $rangeEnd->setTime(0, 0, 0);
    // To must be after From (same calendar day is not a valid end).
    if ($endDay <= $startDay) {
        $rangeEnd = $startDay->modify('+1 day')->setTime(23, 59, 59);
        if ($rangeEnd > $dateMaxDt) {
            // No later day in scope — keep a single-day window on From.
            $rangeEnd = $startDay->setTime(23, 59, 59);
        }
    }
}
if ($rangeStart instanceof DateTimeImmutable && $rangeStart < $dateMinDt) {
    $rangeStart = $dateMinDt;
}
if ($rangeEnd instanceof DateTimeImmutable && $rangeEnd > $dateMaxDt) {
    $rangeEnd = $dateMaxDt;
}

// Keep Year dropdown on the chosen year even when From/To are filled.
$yearSelectValue = '';
if ($yearRaw !== '' && $selectedYear !== null) {
    $yearSelectValue = (string) $selectedYear;
} elseif ($dateFromRaw === '' && $dateToRaw === '') {
    $yearSelectValue = (string) ($selectedYear ?? $latestYear);
}

/**
 * @return list<array<string, mixed>>
 */
function analytics_load_events(
    array $headers,
    DateTimeImmutable $rangeStart,
    DateTimeImmutable $rangeEnd,
    string $statusFilter
): array {
    $startIso = $rangeStart->setTimezone(new DateTimeZone('UTC'))->format('c');
    $endIso = $rangeEnd->setTimezone(new DateTimeZone('UTC'))->format('c');

    $query = 'select=' . rawurlencode(
        'id,title,status,start_at,end_at,location,event_type,event_for,cover_image_url,created_at'
    )
        . '&start_at=gte.' . rawurlencode($startIso)
        . '&start_at=lte.' . rawurlencode($endIso)
        . '&order=start_at.desc'
        . '&limit=300';

    if ($statusFilter !== 'all') {
        $query .= '&status=eq.' . rawurlencode($statusFilter);
    } else {
        $query .= '&status=neq.archived';
    }

    $url = rtrim(SUPABASE_URL, '/') . '/rest/v1/events?' . $query;
    $res = supabase_request('GET', $url, $headers);
    $rows = ($res['ok'] ?? false) ? json_decode((string) ($res['body'] ?? ''), true) : [];
    return is_array($rows) ? $rows : [];
}

/**
 * Flat batch counts — avoids nested tickets(attendance) embeds.
 *
 * @param list<string> $eventIds
 * @return array{regs: array<string,int>, checked_in: array<string,int>}
 */
function analytics_participant_counts(array $headers, array $eventIds): array
{
    $regs = [];
    $checkedIn = [];
    if ($eventIds === []) {
        return ['regs' => $regs, 'checked_in' => $checkedIn];
    }

    foreach ($eventIds as $id) {
        $regs[$id] = 0;
        $checkedIn[$id] = 0;
    }

    /** @var array<string,string> $regToEvent */
    $regToEvent = [];
    /** @var array<string,true> $checkedRegIds */
    $checkedRegIds = [];

    foreach (array_chunk($eventIds, 40) as $chunk) {
        $inList = implode(',', array_map(
            static fn(string $id): string => '"' . str_replace('"', '', $id) . '"',
            $chunk
        ));
        $url = rtrim(SUPABASE_URL, '/') . '/rest/v1/event_registrations'
            . '?select=id,event_id'
            . '&event_id=in.(' . $inList . ')'
            . '&limit=5000';
        $res = supabase_request('GET', $url, $headers);
        $rows = ($res['ok'] ?? false) ? json_decode((string) ($res['body'] ?? ''), true) : [];
        if (!is_array($rows)) {
            continue;
        }
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $eid = trim((string) ($row['event_id'] ?? ''));
            $rid = trim((string) ($row['id'] ?? ''));
            if ($eid === '' || !isset($regs[$eid])) {
                continue;
            }
            $regs[$eid]++;
            if ($rid !== '') {
                $regToEvent[$rid] = $eid;
            }
        }
    }

    $regIds = array_keys($regToEvent);
    if ($regIds === []) {
        return ['regs' => $regs, 'checked_in' => $checkedIn];
    }

    /** @var array<string,string> $ticketToReg */
    $ticketToReg = [];
    foreach (array_chunk($regIds, 80) as $chunk) {
        $inList = implode(',', array_map(
            static fn(string $id): string => '"' . str_replace('"', '', $id) . '"',
            $chunk
        ));
        $url = rtrim(SUPABASE_URL, '/') . '/rest/v1/tickets'
            . '?select=id,registration_id'
            . '&registration_id=in.(' . $inList . ')'
            . '&limit=5000';
        $res = supabase_request('GET', $url, $headers);
        $rows = ($res['ok'] ?? false) ? json_decode((string) ($res['body'] ?? ''), true) : [];
        if (!is_array($rows)) {
            continue;
        }
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $tid = trim((string) ($row['id'] ?? ''));
            $rid = trim((string) ($row['registration_id'] ?? ''));
            if ($tid !== '' && $rid !== '' && isset($regToEvent[$rid])) {
                $ticketToReg[$tid] = $rid;
            }
        }
    }

    $ticketIds = array_keys($ticketToReg);
    foreach (array_chunk($ticketIds, 80) as $chunk) {
        if ($chunk === []) {
            continue;
        }
        $inList = implode(',', array_map(
            static fn(string $id): string => '"' . str_replace('"', '', $id) . '"',
            $chunk
        ));
        $url = rtrim(SUPABASE_URL, '/') . '/rest/v1/attendance'
            . '?select=ticket_id,check_in_at'
            . '&ticket_id=in.(' . $inList . ')'
            . '&check_in_at=not.is.null'
            . '&limit=5000';
        $res = supabase_request('GET', $url, $headers);
        $rows = ($res['ok'] ?? false) ? json_decode((string) ($res['body'] ?? ''), true) : [];
        if (!is_array($rows)) {
            continue;
        }
        foreach ($rows as $row) {
            if (!is_array($row) || trim((string) ($row['check_in_at'] ?? '')) === '') {
                continue;
            }
            $tid = trim((string) ($row['ticket_id'] ?? ''));
            $rid = $ticketToReg[$tid] ?? '';
            if ($rid === '' || isset($checkedRegIds[$rid])) {
                continue;
            }
            $checkedRegIds[$rid] = true;
            $eid = $regToEvent[$rid] ?? '';
            if ($eid !== '' && isset($checkedIn[$eid])) {
                $checkedIn[$eid]++;
            }
        }
    }

    return ['regs' => $regs, 'checked_in' => $checkedIn];
}

$events = analytics_load_events($headers, $rangeStart, $rangeEnd, $statusFilter);
$eventIds = [];
foreach ($events as $ev) {
    if (!is_array($ev)) {
        continue;
    }
    $id = trim((string) ($ev['id'] ?? ''));
    if ($id !== '') {
        $eventIds[] = $id;
    }
}

$countsBundle = analytics_participant_counts($headers, $eventIds);
$regByEvent = $countsBundle['regs'];
$checkInByEvent = $countsBundle['checked_in'];

$sumRegs = 0;
$sumCheckIns = 0;
$statusBuckets = [
    'published' => 0,
    'finished' => 0,
    'pending' => 0,
    'approved' => 0,
    'other' => 0,
];
foreach ($events as $ev) {
    if (!is_array($ev)) {
        continue;
    }
    $id = trim((string) ($ev['id'] ?? ''));
    $sumRegs += (int) ($regByEvent[$id] ?? 0);
    $sumCheckIns += (int) ($checkInByEvent[$id] ?? 0);
    $st = strtolower(trim((string) ($ev['status'] ?? '')));
    if (isset($statusBuckets[$st])) {
        $statusBuckets[$st]++;
    } else {
        $statusBuckets['other']++;
    }
}

$yearOptions = [];
for ($y = $latestYear; $y >= $systemStartYear; $y--) {
    $yearOptions[] = $y;
}

$displayFrom = $rangeStart->format('Y-m-d');
$displayTo = $rangeEnd->format('Y-m-d');
$rangeLabel = $rangeStart->format('M j, Y') . ' – ' . $rangeEnd->format('M j, Y');

$qsBase = static function (array $overrides) use ($selectedYear, $displayFrom, $displayTo, $statusFilter, $dateFromRaw, $dateToRaw, $yearRaw): string {
    $params = [
        'year' => $yearRaw !== '' ? $yearRaw : ($selectedYear !== null ? (string) $selectedYear : ''),
        'from' => $dateFromRaw !== '' ? $dateFromRaw : '',
        'to' => $dateToRaw !== '' ? $dateToRaw : '',
        'status' => $statusFilter,
    ];
    foreach ($overrides as $k => $v) {
        $params[$k] = $v;
    }
    // Drop empties for cleaner URLs.
    $clean = [];
    foreach ($params as $k => $v) {
        if ($v === null || $v === '') {
            continue;
        }
        $clean[$k] = $v;
    }
    return '/admin_analytics?' . http_build_query($clean);
};

render_header('Analytics', $user);
?>

<div class="mb-6 flex flex-col lg:flex-row lg:items-end justify-between gap-4">
  <div>
    <h2 class="text-xl font-bold text-zinc-900 mb-1">Event Repository</h2>
    <p class="text-zinc-600 text-sm">Browse events by year or date range, with registration and attendance counts.</p>
  </div>
  <div class="text-xs font-bold text-zinc-500 uppercase tracking-wider">
    Showing <span class="text-zinc-900"><?= count($events) ?></span> event<?= count($events) === 1 ? '' : 's' ?>
    · <?= htmlspecialchars($rangeLabel) ?>
  </div>
</div>

<form method="get" action="/admin_analytics" class="rounded-2xl border border-zinc-200 bg-white p-4 sm:p-5 shadow-sm mb-6">
  <div class="flex flex-wrap items-center gap-2 mb-4">
    <span class="text-[11px] font-black uppercase tracking-widest text-zinc-400 mr-1">Quick</span>
    <a href="<?= htmlspecialchars($qsBase(['preset' => 'this_year', 'from' => '', 'to' => '', 'year' => (string) $currentYear])) ?>"
      class="rounded-lg border border-zinc-200 bg-zinc-50 px-2.5 py-1.5 text-[11px] font-bold text-zinc-700 hover:border-orange-300 hover:bg-orange-50 hover:text-orange-800">This year</a>
    <a href="<?= htmlspecialchars($qsBase(['preset' => 'last_3_months', 'year' => '', 'from' => $clampDateRaw($nowManila->modify('-3 months')->format('Y-m-d'), $systemMinDt, $systemMaxDt, $tz), 'to' => $clampDateRaw($nowManila->format('Y-m-d'), $systemMinDt, $systemMaxDt, $tz)])) ?>"
      class="rounded-lg border border-zinc-200 bg-zinc-50 px-2.5 py-1.5 text-[11px] font-bold text-zinc-700 hover:border-orange-300 hover:bg-orange-50 hover:text-orange-800">Last 3 months</a>
    <a href="<?= htmlspecialchars($qsBase(['preset' => 'last_6_months', 'year' => '', 'from' => $clampDateRaw($nowManila->modify('-6 months')->format('Y-m-d'), $systemMinDt, $systemMaxDt, $tz), 'to' => $clampDateRaw($nowManila->format('Y-m-d'), $systemMinDt, $systemMaxDt, $tz)])) ?>"
      class="rounded-lg border border-zinc-200 bg-zinc-50 px-2.5 py-1.5 text-[11px] font-bold text-zinc-700 hover:border-orange-300 hover:bg-orange-50 hover:text-orange-800">Last 6 months</a>
    <a href="<?= htmlspecialchars($qsBase(['preset' => 'last_12_months', 'year' => '', 'from' => $clampDateRaw($nowManila->modify('-12 months')->format('Y-m-d'), $systemMinDt, $systemMaxDt, $tz), 'to' => $clampDateRaw($nowManila->format('Y-m-d'), $systemMinDt, $systemMaxDt, $tz)])) ?>"
      class="rounded-lg border border-zinc-200 bg-zinc-50 px-2.5 py-1.5 text-[11px] font-bold text-zinc-700 hover:border-orange-300 hover:bg-orange-50 hover:text-orange-800">Last 12 months</a>
  </div>

  <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-3 items-end">
    <div>
      <label for="year" class="block text-[11px] font-bold uppercase tracking-wider text-zinc-500 mb-1.5">Year</label>
      <select id="year" name="year"
        class="w-full rounded-xl border border-zinc-200 bg-zinc-50 px-3 py-2.5 text-sm font-medium text-zinc-900 focus:outline-none focus:ring-2 focus:ring-orange-300 focus:border-orange-400">
        <option value="" <?= $yearSelectValue === '' ? 'selected' : '' ?>>All (use dates)</option>
        <?php foreach ($yearOptions as $y): ?>
          <option value="<?= $y ?>" <?= $yearSelectValue === (string) $y ? 'selected' : '' ?>><?= $y ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label for="from" class="block text-[11px] font-bold uppercase tracking-wider text-zinc-500 mb-1.5">From</label>
      <input type="date" id="from" name="from" value="<?= htmlspecialchars($dateFromRaw !== '' ? $dateFromRaw : '') ?>"
        min="<?= htmlspecialchars($dateMinBound) ?>" max="<?= htmlspecialchars($dateMaxBound) ?>"
        data-system-min="<?= htmlspecialchars($systemMinBound) ?>" data-system-max="<?= htmlspecialchars($systemMaxBound) ?>"
        class="w-full rounded-xl border border-zinc-200 bg-zinc-50 px-3 py-2.5 text-sm font-medium text-zinc-900 focus:outline-none focus:ring-2 focus:ring-orange-300 focus:border-orange-400">
    </div>
    <div>
      <label for="to" class="block text-[11px] font-bold uppercase tracking-wider text-zinc-500 mb-1.5">To</label>
      <input type="date" id="to" name="to" value="<?= htmlspecialchars($dateToRaw !== '' ? $dateToRaw : '') ?>"
        min="<?= htmlspecialchars($dateMinBound) ?>" max="<?= htmlspecialchars($dateMaxBound) ?>"
        class="w-full rounded-xl border border-zinc-200 bg-zinc-50 px-3 py-2.5 text-sm font-medium text-zinc-900 focus:outline-none focus:ring-2 focus:ring-orange-300 focus:border-orange-400">
    </div>
    <div>
      <label for="status" class="block text-[11px] font-bold uppercase tracking-wider text-zinc-500 mb-1.5">Status</label>
      <select id="status" name="status"
        class="w-full rounded-xl border border-zinc-200 bg-zinc-50 px-3 py-2.5 text-sm font-medium text-zinc-900 focus:outline-none focus:ring-2 focus:ring-orange-300 focus:border-orange-400">
        <option value="all" <?= $statusFilter === 'all' ? 'selected' : '' ?>>All (excl. archived)</option>
        <option value="published" <?= $statusFilter === 'published' ? 'selected' : '' ?>>Published</option>
        <option value="finished" <?= $statusFilter === 'finished' ? 'selected' : '' ?>>Finished</option>
        <option value="pending" <?= $statusFilter === 'pending' ? 'selected' : '' ?>>Pending</option>
        <option value="approved" <?= $statusFilter === 'approved' ? 'selected' : '' ?>>Approved</option>
        <option value="archived" <?= $statusFilter === 'archived' ? 'selected' : '' ?>>Archived</option>
      </select>
     </div>
    <div class="flex gap-2">
      <button type="submit"
        class="flex-1 rounded-xl bg-orange-600 text-white px-4 py-2.5 text-sm font-bold hover:bg-orange-700 shadow-sm">
        Apply
      </button>
      <a href="/admin_analytics"
        class="rounded-xl border border-zinc-200 bg-white px-3 py-2.5 text-sm font-bold text-zinc-600 hover:bg-zinc-50">
        Reset
      </a>
     </div>
  </div>
  <p class="text-[11px] text-zinc-500 mt-3 font-medium">
    Tip: pick a <span class="font-bold text-zinc-700">Year</span> then narrow with From/To inside that year. Use <span class="font-bold text-zinc-700">All</span> for a custom range within <?= (int) $systemStartYear ?>–<?= (int) $latestYear ?>.
  </p>
</form>

<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
  <div class="rounded-2xl border border-zinc-200 bg-white p-4 border-b-[3px] border-b-sky-500 shadow-sm">
    <div class="text-[10px] font-black uppercase tracking-widest text-zinc-400 mb-1">Events in range</div>
    <div class="text-2xl font-black text-zinc-900"><?= count($events) ?></div>
    <div class="text-[11px] text-zinc-500 font-medium mt-1">
      <?= (int) $statusBuckets['published'] ?> published · <?= (int) $statusBuckets['finished'] ?> finished
     </div>
  </div>
  <div class="rounded-2xl border border-zinc-200 bg-white p-4 border-b-[3px] border-b-orange-500 shadow-sm">
    <div class="text-[10px] font-black uppercase tracking-widest text-zinc-400 mb-1">Registrations</div>
    <div class="text-2xl font-black text-zinc-900"><?= $sumRegs ?></div>
    <div class="text-[11px] text-zinc-500 font-medium mt-1">Participants across listed events</div>
     </div>
  <div class="rounded-2xl border border-zinc-200 bg-white p-4 border-b-[3px] border-b-emerald-500 shadow-sm">
    <div class="text-[10px] font-black uppercase tracking-widest text-zinc-400 mb-1">Checked in</div>
    <div class="text-2xl font-black text-zinc-900"><?= $sumCheckIns ?></div>
    <div class="text-[11px] text-zinc-500 font-medium mt-1">
      <?= $sumRegs > 0 ? (int) round($sumCheckIns / $sumRegs * 100) : 0 ?>% of registrations
     </div>
  </div>
  <div class="rounded-2xl border border-zinc-200 bg-white p-4 border-b-[3px] border-b-violet-500 shadow-sm">
    <div class="text-[10px] font-black uppercase tracking-widest text-zinc-400 mb-1">Avg / event</div>
    <div class="text-2xl font-black text-zinc-900">
      <?= count($events) > 0 ? round($sumRegs / count($events), 1) : '0' ?>
    </div>
    <div class="text-[11px] text-zinc-500 font-medium mt-1">Mean registrations per event</div>
  </div>
</div>

<div class="rounded-2xl border border-zinc-200 bg-white shadow-sm overflow-hidden">
  <div class="px-5 py-4 border-b border-zinc-100 bg-zinc-50/80 flex flex-wrap items-center justify-between gap-3">
      <div>
      <h3 class="text-base font-bold text-zinc-900">Events</h3>
      <p class="text-[11px] text-zinc-500 font-medium mt-0.5">Newest first · click a row to open event details</p>
    </div>
  </div>

  <?php if (count($events) === 0): ?>
    <div class="py-16 text-center px-6">
      <div class="w-14 h-14 rounded-full bg-zinc-100 flex items-center justify-center mx-auto mb-3 text-zinc-400">
        <svg class="w-7 h-7" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 11.25v7.5"/>
        </svg>
      </div>
      <h4 class="text-zinc-900 font-bold text-base mb-1">No events in this range</h4>
      <p class="text-sm text-zinc-500 max-w-md mx-auto">Try another year, widen the date range, or clear the status filter.</p>
    </div>
  <?php else: ?>
    <div class="overflow-x-auto">
      <table class="min-w-full text-left">
        <thead>
          <tr class="border-b border-zinc-100 text-[10px] font-black uppercase tracking-widest text-zinc-400">
            <th class="px-5 py-3 font-black">Event</th>
            <th class="px-4 py-3 font-black whitespace-nowrap">When</th>
            <th class="px-4 py-3 font-black">Status</th>
            <th class="px-4 py-3 font-black text-right whitespace-nowrap">Registered</th>
            <th class="px-4 py-3 font-black text-right whitespace-nowrap">Checked in</th>
            <th class="px-4 py-3 font-black text-right whitespace-nowrap">Turnout</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-zinc-100">
          <?php foreach ($events as $ev): ?>
    <?php
            if (!is_array($ev)) {
                continue;
            }
            $eid = trim((string) ($ev['id'] ?? ''));
            $title = trim((string) ($ev['title'] ?? 'Untitled'));
            if ($title === '') {
                $title = 'Untitled';
            }
            $loc = trim((string) ($ev['location'] ?? ''));
            $st = strtolower(trim((string) ($ev['status'] ?? '')));
            $regsN = (int) ($regByEvent[$eid] ?? 0);
            $inN = (int) ($checkInByEvent[$eid] ?? 0);
            $turnout = $regsN > 0 ? (int) round($inN / $regsN * 100) : 0;
            $when = format_date_local((string) ($ev['start_at'] ?? ''), 'M j, Y · g:i A');
            $statusClass = match ($st) {
                'published' => 'bg-emerald-50 text-emerald-800 border-emerald-200',
                'finished' => 'bg-zinc-100 text-zinc-700 border-zinc-200',
                'pending' => 'bg-amber-50 text-amber-900 border-amber-200',
                'approved' => 'bg-sky-50 text-sky-800 border-sky-200',
                'archived' => 'bg-zinc-50 text-zinc-500 border-zinc-200',
                default => 'bg-zinc-50 text-zinc-600 border-zinc-200',
            };
            ?>
            <tr class="hover:bg-orange-50/40 transition-colors">
              <td class="px-5 py-3.5">
                <a href="/event_view?id=<?= htmlspecialchars($eid) ?>" class="block min-w-0 group">
                  <div class="text-sm font-bold text-zinc-900 group-hover:text-orange-700 truncate max-w-[18rem] sm:max-w-md">
                    <?= htmlspecialchars($title) ?>
                  </div>
                  <?php if ($loc !== ''): ?>
                    <div class="text-[11px] text-zinc-500 font-medium truncate mt-0.5"><?= htmlspecialchars($loc) ?></div>
                  <?php endif; ?>
                </a>
              </td>
              <td class="px-4 py-3.5 text-[12px] font-semibold text-zinc-700 whitespace-nowrap"><?= htmlspecialchars($when !== '' ? $when : '—') ?></td>
              <td class="px-4 py-3.5">
                <span class="inline-flex text-[10px] font-black uppercase tracking-wider rounded-md border px-2 py-0.5 <?= $statusClass ?>">
                  <?= htmlspecialchars($st !== '' ? $st : 'unknown') ?>
                </span>
              </td>
              <td class="px-4 py-3.5 text-right text-sm font-bold text-zinc-900 tabular-nums"><?= $regsN ?></td>
              <td class="px-4 py-3.5 text-right text-sm font-bold text-emerald-800 tabular-nums"><?= $inN ?></td>
              <td class="px-4 py-3.5 text-right">
                <span class="text-sm font-bold tabular-nums <?= $turnout >= 70 ? 'text-emerald-700' : ($turnout >= 40 ? 'text-amber-700' : 'text-zinc-600') ?>">
                  <?= $turnout ?>%
                </span>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
  </div>
  <?php endif; ?>
</div>

<script>
(function () {
  var year = document.getElementById('year');
  var from = document.getElementById('from');
  var to = document.getElementById('to');
  if (!year || !from || !to) return;
  var systemMin = from.getAttribute('data-system-min') || from.getAttribute('min') || '';
  var systemMax = from.getAttribute('data-system-max') || from.getAttribute('max') || '';

  function pad(n) {
    return n < 10 ? '0' + n : String(n);
  }

  function addDays(ymd, delta) {
    if (!ymd || !/^\d{4}-\d{2}-\d{2}$/.test(ymd)) return '';
    var parts = ymd.split('-');
    var d = new Date(Number(parts[0]), Number(parts[1]) - 1, Number(parts[2]));
    d.setDate(d.getDate() + delta);
    return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate());
  }

  function clampYmd(value, minB, maxB) {
    if (!value) return value;
    if (minB && value < minB) return minB;
    if (maxB && value > maxB) return maxB;
    return value;
  }

  function yearBounds() {
    var y = String(year.value || '').trim();
    if (y) {
      return { min: y + '-01-01', max: y + '-12-31' };
    }
    return { min: systemMin, max: systemMax };
  }

  function syncDateBounds() {
    var bounds = yearBounds();
    var fromMin = bounds.min;
    var fromMax = bounds.max;
    var toMin = bounds.min;
    var toMax = bounds.max;

    // From cannot be after To; To cannot be on/before From → To starts the next day.
    if (to.value) {
      var maxForFrom = addDays(to.value, -1);
      if (maxForFrom && (!fromMax || maxForFrom < fromMax)) {
        fromMax = maxForFrom;
      }
      if (fromMin && fromMax && fromMin > fromMax) {
        fromMax = fromMin;
      }
    }
    if (from.value) {
      var minForTo = addDays(from.value, 1);
      if (minForTo && (!toMin || minForTo > toMin)) {
        toMin = minForTo;
      }
      if (toMin && toMax && toMin > toMax) {
        toMin = toMax;
      }
    }

    from.min = fromMin || '';
    from.max = fromMax || '';
    to.min = toMin || '';
    to.max = toMax || '';

    if (from.value) {
      from.value = clampYmd(from.value, from.min, from.max);
    }
    if (to.value) {
      to.value = clampYmd(to.value, to.min, to.max);
      // If To collapsed onto/before From, clear it so the user picks a later day.
      if (from.value && to.value && to.value <= from.value) {
        to.value = '';
        syncDateBounds();
        return;
      }
    }
  }

  year.addEventListener('change', function () {
    if (year.value) {
      from.value = '';
      to.value = '';
    }
    syncDateBounds();
  });
  from.addEventListener('change', syncDateBounds);
  to.addEventListener('change', syncDateBounds);
  syncDateBounds();
})();
</script>

<?php render_footer(); ?>
