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
$currentAdminId = trim((string) ($user['id'] ?? ''));

$headers = [
    'Accept: application/json',
    'apikey: ' . SUPABASE_KEY,
    'Authorization: Bearer ' . SUPABASE_KEY,
];

$url = rtrim(SUPABASE_URL, '/') . '/rest/v1/' . SUPABASE_TABLE_USERS
    . '?select=id,first_name,middle_name,last_name,suffix,email,role,section_id,contact_number,created_at,student_id,course,archived_at'
    . '&archived_at=is.null'
    . '&order=created_at.desc'
    . '&limit=500';

$res = supabase_request('GET', $url, $headers);
if (!$res['ok'] && str_contains((string) ($res['body'] ?? ''), 'archived_at')) {
    $url = rtrim(SUPABASE_URL, '/') . '/rest/v1/' . SUPABASE_TABLE_USERS
        . '?select=id,first_name,middle_name,last_name,suffix,email,role,section_id,contact_number,created_at,student_id,course'
        . '&order=created_at.desc'
        . '&limit=500';
    $res = supabase_request('GET', $url, $headers);
}
$rows = $res['ok'] ? json_decode((string) $res['body'], true) : [];

$urlSec = rtrim(SUPABASE_URL, '/') . '/rest/v1/sections?select=id,name';
$resSec = supabase_request('GET', $urlSec, $headers);
$sectionsData = $resSec['ok'] ? json_decode((string) $resSec['body'], true) : [];
$sectionMap = [];
if (is_array($sectionsData)) {
    foreach ($sectionsData as $sec) {
        $sectionMap[(string)$sec['id']] = $sec['name'];
    }
}
$users = is_array($rows) ? $rows : [];

// School roster (paginate — PostgREST max-rows is often 1000).
$rosterRows = [];
$rosterOffset = 0;
$rosterHasArchiveCol = true;
while (true) {
    $rosterUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/student_roster'
        . '?select=id,student_no,full_name_raw,first_name,middle_name,last_name,suffix,'
        . 'course_code,program_label,year_level,block,is_irregular,section_id,user_id,imported_at'
        . ($rosterHasArchiveCol ? ',archived_at&archived_at=is.null' : '')
        . '&order=last_name.asc'
        . '&limit=1000&offset=' . $rosterOffset;
    $rosterRes = supabase_request('GET', $rosterUrl, $headers);
    if (!$rosterRes['ok'] && $rosterHasArchiveCol && str_contains((string) ($rosterRes['body'] ?? ''), 'archived_at')) {
        $rosterHasArchiveCol = false;
        continue;
    }
    $chunk = $rosterRes['ok'] ? json_decode((string) ($rosterRes['body'] ?? ''), true) : [];
    if (!is_array($chunk) || $chunk === []) {
        break;
    }
    foreach ($chunk as $row) {
        if (is_array($row)) {
            $rosterRows[] = $row;
        }
    }
    if (count($chunk) < 1000) {
        break;
    }
    $rosterOffset += 1000;
    if ($rosterOffset >= 5000) {
        break;
    }
}

$registeredStudentIds = [];
$studentUserById = [];
$studentUserByNo = [];
$studentUserByDigits = [];
foreach ($users as $u) {
    if (($u['role'] ?? '') !== 'student') {
        continue;
    }
    $uid = trim((string) ($u['id'] ?? ''));
    if ($uid !== '') {
        $studentUserById[$uid] = $u;
    }
    $sid = student_roster_normalize_no((string) ($u['student_id'] ?? ''));
    if ($sid !== '') {
        $registeredStudentIds[$sid] = true;
        $studentUserByNo[$sid] = $u;
    }
    $dig = student_roster_digits_key((string) ($u['student_id'] ?? ''));
    if ($dig !== '') {
        $studentUserByDigits[$dig] = $u;
    }
}

/**
 * @return array{name:string,year:string,course:string,section:string}
 */
function admin_users_format_section_bits(string $secNameRaw, string $courseCode = ''): array
{
    $secNameRaw = trim($secNameRaw);
    $course = strtoupper(trim($courseCode));
    if (!in_array($course, ['IT', 'CS'], true)) {
        $course = '';
    }
    if ($secNameRaw === '' || strcasecmp($secNameRaw, 'No Section') === 0) {
        return ['name' => '', 'year' => '—', 'course' => $course !== '' ? $course : '—', 'section' => '—'];
    }
    if (strcasecmp($secNameRaw, 'IRREGULAR') === 0) {
        return ['name' => $secNameRaw, 'year' => '—', 'course' => $course !== '' ? $course : '—', 'section' => 'IRREGULAR'];
    }
    // Legacy "Year-Section"
    if (str_contains($secNameRaw, '-')) {
        $parts = explode('-', $secNameRaw, 2);
        return [
            'name' => $secNameRaw,
            'year' => trim($parts[0]),
            'course' => $course !== '' ? $course : '—',
            'section' => trim($parts[1] ?? '—'),
        ];
    }
    // "BSIT SD 1A" / "BSCS 2B"
    if (preg_match('/^(.*?)\s+([1-4])([A-F])$/i', $secNameRaw, $m)) {
        return [
            'name' => $secNameRaw,
            'year' => $m[2],
            'course' => $course !== '' ? $course : trim($m[1]),
            'section' => $secNameRaw,
        ];
    }
    return [
        'name' => $secNameRaw,
        'year' => $secNameRaw,
        'course' => $course !== '' ? $course : '—',
        'section' => $secNameRaw,
    ];
}

$studentDirectory = [];
$shownUserIds = [];
$shownStudentNos = [];
$rosterByNo = [];
foreach ($rosterRows as $r) {
    if (!is_array($r)) {
        continue;
    }
    $sno = student_roster_normalize_no((string) ($r['student_no'] ?? ''));
    if ($sno !== '') {
        $rosterByNo[$sno] = $r;
    }
}

// Roster is source of truth (Excel import). Overlay registered status from users.
foreach ($rosterRows as $r) {
    if (!is_array($r)) {
        continue;
    }
    $sno = student_roster_normalize_no((string) ($r['student_no'] ?? ''));
    $yearInt = isset($r['year_level']) && $r['year_level'] !== null && $r['year_level'] !== ''
        ? (int) $r['year_level']
        : null;
    $irregular = !empty($r['is_irregular']);
    $program = trim((string) ($r['program_label'] ?? ''));
    if ($program === '') {
        $program = strtoupper((string) ($r['course_code'] ?? '')) === 'CS' ? 'BSCS' : '—';
    }

    $linked = null;
    $uid = trim((string) ($r['user_id'] ?? ''));
    if ($uid !== '' && isset($studentUserById[$uid])) {
        $linked = $studentUserById[$uid];
    } elseif ($sno !== '' && isset($studentUserByNo[$sno])) {
        $linked = $studentUserByNo[$sno];
    } else {
        $dig = student_roster_digits_key($sno);
        if ($dig !== '' && isset($studentUserByDigits[$dig])) {
            $linked = $studentUserByDigits[$dig];
        }
    }

    $isRegistered = is_array($linked);
    if ($isRegistered) {
        $linkedId = trim((string) ($linked['id'] ?? ''));
        if ($linkedId !== '') {
            $shownUserIds[$linkedId] = true;
        }
    }
    if ($sno !== '') {
        $shownStudentNos[$sno] = true;
        $dig = student_roster_digits_key($sno);
        if ($dig !== '') {
            $shownStudentNos[$dig] = true;
        }
    }

    $studentDirectory[] = [
        'kind' => $isRegistered ? 'registered' : 'roster',
        'roster_id' => trim((string) ($r['id'] ?? '')),
        'user_id' => $isRegistered ? trim((string) ($linked['id'] ?? '')) : '',
        'student_no' => (string) ($r['student_no'] ?? ''),
        'last_name' => trim((string) ($r['last_name'] ?? '')),
        'first_name' => trim((string) ($r['first_name'] ?? '')),
        'middle_name' => trim((string) ($r['middle_name'] ?? '')),
        'program' => $program,
        'year' => student_roster_format_year_label($yearInt),
        'block' => student_roster_format_block_label($yearInt, (string) ($r['block'] ?? ''), $irregular),
        'email' => $isRegistered ? (string) ($linked['email'] ?? '') : '—',
        'status' => $isRegistered ? 'Registered' : 'Awaiting signup',
    ];
}

// Registered students not present on roster (legacy accounts).
foreach ($users as $u) {
    if (($u['role'] ?? '') !== 'student') {
        continue;
    }
    $uid = trim((string) ($u['id'] ?? ''));
    if ($uid !== '' && isset($shownUserIds[$uid])) {
        continue;
    }
    $sno = student_roster_normalize_no((string) ($u['student_id'] ?? ''));
    $dig = student_roster_digits_key($sno);
    if (($sno !== '' && isset($shownStudentNos[$sno])) || ($dig !== '' && isset($shownStudentNos[$dig]))) {
        continue;
    }

    $secId = (string) ($u['section_id'] ?? '');
    $secName = $sectionMap[$secId] ?? '';
    $bits = admin_users_format_section_bits($secName, (string) ($u['course'] ?? ''));
    $yearInt = ctype_digit((string) $bits['year']) ? (int) $bits['year'] : student_roster_parse_year_level((string) $bits['year'], (string) $bits['section']);
    $program = '—';
    if (preg_match('/^(BSIT\s*BA|BSIT\s*SD|BSCS)\b/i', $secName, $pm)) {
        $hit = strtoupper(trim((string) ($pm[0] ?? '')));
        if (str_contains($hit, 'BA')) {
            $program = 'BSIT BA';
        } elseif (str_contains($hit, 'SD')) {
            $program = 'BSIT SD';
        } else {
            $program = 'BSCS';
        }
    } elseif (strtoupper((string) ($u['course'] ?? '')) === 'CS') {
        $program = 'BSCS';
    } elseif (strtoupper((string) ($u['course'] ?? '')) === 'IT') {
        $program = 'BSIT';
    }
    $blockLabel = '—';
    if (strcasecmp($secName, 'IRREGULAR') === 0) {
        $blockLabel = '--';
    } elseif (preg_match('/([1-4][A-F])$/i', $secName, $bm)) {
        $blockLabel = strtoupper($bm[1]);
    }

    $studentDirectory[] = [
        'kind' => 'registered',
        'roster_id' => '',
        'user_id' => $uid,
        'student_no' => $sno,
        'last_name' => trim((string) ($u['last_name'] ?? '')),
        'first_name' => trim((string) ($u['first_name'] ?? '')),
        'middle_name' => trim((string) ($u['middle_name'] ?? '')),
        'program' => $program,
        'year' => student_roster_format_year_label($yearInt),
        'block' => $blockLabel,
        'email' => (string) ($u['email'] ?? ''),
        'status' => 'Registered',
    ];
}

$rosterAwaitingCount = 0;
foreach ($studentDirectory as $row) {
    if (($row['kind'] ?? '') === 'roster') {
        $rosterAwaitingCount++;
    }
}

usort($studentDirectory, static function (array $a, array $b): int {
    $c = strcasecmp((string) ($a['last_name'] ?? ''), (string) ($b['last_name'] ?? ''));
    if ($c !== 0) {
        return $c;
    }
    $c = strcasecmp((string) ($a['first_name'] ?? ''), (string) ($b['first_name'] ?? ''));
    if ($c !== 0) {
        return $c;
    }
    return strcasecmp((string) ($a['student_no'] ?? ''), (string) ($b['student_no'] ?? ''));
});

render_header('Users & Roles', $user);
?>

<div class="mb-8 flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
  <div class="min-w-0">
    <h2 class="text-xl font-bold text-zinc-900 mb-1">Users & Roles</h2>
    <p class="text-zinc-600 text-sm">Manage accounts, assign privileges, and oversee the system's members.</p>
  </div>
  <div class="flex items-center gap-3 shrink-0 self-end sm:self-start relative z-10">
      <a id="exportStudents" href="api/admin_users_export.php?type=students"
         class="inline-flex items-center justify-center gap-2 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-2.5 text-[13px] font-bold text-emerald-800 shadow-sm hover:bg-emerald-100 hover:border-emerald-300 transition-colors whitespace-nowrap">
        <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5M16.5 12L12 16.5m0 0L7.5 12m4.5 4.5V3"/></svg>
        Export Excel
      </a>
      <a id="exportTeachers" href="api/admin_users_export.php?type=teachers"
         class="hidden inline-flex items-center justify-center gap-2 rounded-xl border border-orange-200 bg-orange-50 px-4 py-2.5 text-[13px] font-bold text-orange-800 shadow-sm hover:bg-orange-100 hover:border-orange-300 transition-colors whitespace-nowrap">
        <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5M16.5 12L12 16.5m0 0L7.5 12m4.5 4.5V3"/></svg>
        Export Excel
      </a>

      <!-- Teacher add (no import) -->
      <div class="hidden" id="actionTeacher">
        <button type="button" id="btnOpenRegisterTeacher" class="inline-flex items-center justify-center gap-2 rounded-xl bg-orange-600 text-white px-5 py-2.5 text-[13px] font-bold shadow-sm hover:bg-orange-700 transition-colors border border-orange-600">
          <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/></svg>
          Add Teacher
        </button>
      </div>
      
      <!-- Student Actions Dropdown -->
      <div class="relative group" id="actionStudent">
        <button type="button" class="inline-flex items-center justify-center gap-2 rounded-xl bg-emerald-600 text-white px-4 py-2.5 text-[13px] font-bold shadow-sm hover:bg-emerald-700 transition-colors border border-emerald-600 whitespace-nowrap">
          <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/></svg>
          <span>Add Student</span>
          <svg class="w-3.5 h-3.5 shrink-0 opacity-70" fill="none" stroke="currentColor" stroke-width="3" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5"/></svg>
        </button>
        <div class="absolute right-0 top-full mt-2 z-20 w-48 rounded-xl bg-white border border-zinc-200 shadow-[0_10px_40px_-10px_rgba(0,0,0,0.15)] opacity-0 invisible group-hover:opacity-100 group-hover:visible transition-all overflow-hidden transform origin-top-right group-hover:translate-y-0 translate-y-1">
          <button type="button" id="btnOpenAddStudent" class="w-full flex items-center gap-2 px-4 py-3 text-[13px] font-bold text-zinc-700 hover:bg-emerald-50 hover:text-emerald-700 border-b border-zinc-100 transition-colors">
              <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 002.625.372 9.337 9.337 0 004.121-.952 4.125 4.125 0 00-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 018.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0111.964-3.07M12 6.375a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zm8.25 2.25a2.625 2.625 0 11-5.25 0 2.625 2.625 0 015.25 0z"/></svg>
              Add Manually
          </button>
          <button type="button" id="btnOpenImportStudents" class="w-full flex items-center gap-2 px-4 py-3 text-[13px] font-bold text-zinc-700 hover:bg-emerald-50 hover:text-emerald-700 transition-colors">
              <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m3.75 9v6m3-3H9m1.5-12H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z"/></svg>
              Import Students
          </button>
        </div>
      </div>

      <!-- Admin add (no import) -->
      <div class="hidden" id="actionAdmin">
        <button type="button" id="btnOpenRegisterAdmin" class="inline-flex items-center justify-center gap-2 rounded-xl bg-orange-600 text-white px-5 py-2.5 text-[13px] font-bold shadow-sm hover:bg-orange-700 transition-colors border border-orange-600">
          <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/></svg>
          Add Admin
        </button>
      </div>
  </div>
</div>

<!-- Stats -->
<?php
  $studentCount = 0; $teacherCount = 0; $adminCount = 0;
  foreach ($users as $u) {
    $r = (string)($u['role'] ?? 'student');
    if ($r === 'student') $studentCount++;
    elseif ($r === 'teacher') $teacherCount++;
    elseif ($r === 'admin') $adminCount++;
  }
  $studentTabCount = count($studentDirectory);
  $studentCsCount = 0;
  $studentItCount = 0;
  foreach ($studentDirectory as $dirRow) {
    if (!is_array($dirRow)) {
      continue;
    }
    $prog = strtoupper(preg_replace('/\s+/', ' ', trim((string) ($dirRow['program'] ?? ''))) ?? '');
    if ($prog === 'BSCS' || $prog === 'CS') {
      $studentCsCount++;
    } elseif ($prog !== '' && $prog !== '—' && (str_contains($prog, 'IT') || str_starts_with($prog, 'BSIT'))) {
      $studentItCount++;
    }
  }
?>

<div class="grid grid-cols-1 sm:grid-cols-3 gap-5 mb-8">
  <div class="rounded-2xl border border-zinc-200 bg-white p-5 border-b-[3px] border-b-emerald-500 shadow-sm flex items-center gap-4 relative overflow-hidden group hover:-translate-y-0.5 transition-transform">
     <div class="absolute -right-8 -top-8 w-24 h-24 bg-emerald-400/10 blur-2xl rounded-full pointer-events-none"></div>
     <div class="w-12 h-12 rounded-xl bg-emerald-100 border border-emerald-200 flex items-center justify-center text-emerald-700 z-10 flex-shrink-0">
        <svg class="w-6 h-6" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M4.26 10.147a60.436 60.436 0 00-.491 6.347A48.627 48.627 0 0112 20.904a48.627 48.627 0 018.232-4.41 60.46 60.46 0 00-.491-6.347m-15.482 0a50.57 50.57 0 00-2.658-.813A59.905 59.905 0 0112 3.493a59.902 59.902 0 0110.399 5.84c-.896.248-1.783.52-2.658.814m-15.482 0A50.697 50.697 0 0112 13.489a50.702 50.702 0 017.74-3.342"/></svg>
     </div>
     <div class="z-10 min-w-0">
        <div class="text-3xl font-bold text-zinc-900"><?= (int) $studentTabCount ?></div>
        <div class="text-[11px] text-zinc-600 uppercase tracking-widest font-bold truncate">Students</div>
        <?php if ($rosterAwaitingCount > 0): ?>
          <div class="text-[10px] font-semibold text-amber-700 mt-0.5"><?= (int) $rosterAwaitingCount ?> awaiting signup</div>
        <?php endif; ?>
     </div>
  </div>

  <div class="rounded-2xl border border-zinc-200 bg-white p-5 border-b-[3px] border-b-sky-500 shadow-sm flex items-center gap-4 relative overflow-hidden group hover:-translate-y-0.5 transition-transform">
     <div class="absolute -right-8 -top-8 w-24 h-24 bg-sky-400/10 blur-2xl rounded-full pointer-events-none"></div>
     <div class="w-12 h-12 rounded-xl bg-sky-100 border border-sky-200 flex items-center justify-center text-sky-700 z-10 flex-shrink-0">
        <svg class="w-6 h-6" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.501 20.118a7.5 7.5 0 0114.998 0A17.933 17.933 0 0112 21.75c-2.676 0-5.216-.584-7.499-1.632z"/></svg>
     </div>
     <div class="z-10 min-w-0">
        <div class="text-3xl font-bold text-zinc-900"><?= $teacherCount ?></div>
        <div class="text-[11px] text-zinc-600 uppercase tracking-widest font-bold truncate">Teachers</div>
     </div>
  </div>

  <div class="rounded-2xl border border-zinc-200 bg-white p-5 border-b-[3px] border-b-orange-500 shadow-sm flex items-center gap-4 relative overflow-hidden group hover:-translate-y-0.5 transition-transform">
     <div class="absolute -right-8 -top-8 w-24 h-24 bg-orange-400/10 blur-2xl rounded-full pointer-events-none"></div>
     <div class="w-12 h-12 rounded-xl bg-orange-100 border border-orange-200 flex items-center justify-center text-orange-700 z-10 flex-shrink-0">
        <svg class="w-6 h-6" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75m-3-7.036A11.959 11.959 0 013.598 6 11.99 11.99 0 003 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285z"/></svg>
     </div>
     <div class="z-10 min-w-0">
        <div class="text-3xl font-bold text-zinc-900"><?= $adminCount ?></div>
        <div class="text-[11px] text-zinc-600 uppercase tracking-widest font-bold truncate">Admins</div>
     </div>
  </div>
</div>

<!-- Top Nav Tabs -->
<div class="flex border-b border-zinc-200 mb-6 gap-6 mt-2 relative z-10 w-full overflow-x-auto">
    <button id="tabStudents" class="pb-3 border-b-2 border-orange-500 font-bold text-orange-600 text-sm transition-colors whitespace-nowrap px-1 group flex items-center gap-2">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M4.26 10.147a60.436 60.436 0 00-.491 6.347A48.627 48.627 0 0112 20.904a48.627 48.627 0 018.232-4.41 60.46 60.46 0 00-.491-6.347m-15.482 0a50.57 50.57 0 00-2.658-.813A59.905 59.905 0 0112 3.493a59.902 59.902 0 0110.399 5.84c-.896.248-1.783.52-2.658.814m-15.482 0A50.697 50.697 0 0112 13.489a50.702 50.702 0 017.74-3.342"/></svg>
        All Students
        <span class="bg-orange-100 text-orange-700 border border-orange-200 text-[10px] font-black px-2 py-0.5 rounded-full group-hover:bg-orange-200 transition-colors"><?= $studentTabCount ?></span>
    </button>
    <button id="tabTeachers" class="pb-3 border-b-2 border-transparent font-bold text-zinc-500 hover:text-zinc-800 text-sm transition-colors whitespace-nowrap px-1 group flex items-center gap-2">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.501 20.118a7.5 7.5 0 0114.998 0A17.933 17.933 0 0112 21.75c-2.676 0-5.216-.584-7.499-1.632z"/></svg>
        All Teachers
        <span class="bg-zinc-100 text-zinc-600 border border-zinc-200 text-[10px] font-black px-2 py-0.5 rounded-full group-hover:bg-zinc-200 transition-colors"><?= $teacherCount ?></span>
    </button>
    <button id="tabAdmins" class="pb-3 border-b-2 border-transparent font-bold text-zinc-500 hover:text-zinc-800 text-sm transition-colors whitespace-nowrap px-1 group flex items-center gap-2">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75m-3-7.036A11.959 11.959 0 013.598 6 11.99 11.99 0 003 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285z"/></svg>
        All Admins
        <span class="bg-zinc-100 text-zinc-600 border border-zinc-200 text-[10px] font-black px-2 py-0.5 rounded-full group-hover:bg-zinc-200 transition-colors"><?= $adminCount ?></span>
    </button>
</div>

<div class="flex flex-col gap-3 mb-4">
  <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
    <h3 id="panelTitle" class="text-base font-bold text-zinc-900 tracking-tight flex items-center gap-2 min-w-0">
       <div class="w-8 h-8 rounded-xl bg-emerald-100 border border-emerald-200 flex items-center justify-center shrink-0">
         <svg class="w-4 h-4 text-emerald-700" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M4.26 10.147a60.436 60.436 0 00-.491 6.347A48.627 48.627 0 0112 20.904a48.627 48.627 0 018.232-4.41 60.46 60.46 0 00-.491-6.347m-15.482 0a50.57 50.57 0 00-2.658-.813A59.905 59.905 0 0112 3.493a59.902 59.902 0 0110.399 5.84c-.896.248-1.783.52-2.658.814m-15.482 0A50.697 50.697 0 0112 13.489a50.702 50.702 0 017.74-3.342"/></svg>
     </div>
       <span class="truncate">Student Management</span>
  </h3>
  <div class="flex flex-wrap items-center gap-2 shrink-0 self-start sm:self-auto">
    <div class="px-3.5 py-1.5 rounded-xl bg-zinc-100 border border-zinc-200 flex items-center gap-2">
      <span class="text-[11px] font-bold text-zinc-600 uppercase tracking-wider">Total</span>
      <span id="panelTotal" class="text-base font-bold text-zinc-900 leading-none"><?= (int) $studentTabCount ?></span>
    </div>
    <div id="panelProgramCounts" class="flex flex-wrap items-center gap-2">
      <div class="px-3.5 py-1.5 rounded-xl bg-sky-50 border border-sky-200 flex items-center gap-2">
        <span class="text-[11px] font-bold text-sky-700 uppercase tracking-wider">CS</span>
        <span id="panelCsCount" class="text-base font-bold text-sky-900 leading-none"><?= (int) $studentCsCount ?></span>
      </div>
      <div class="px-3.5 py-1.5 rounded-xl bg-violet-50 border border-violet-200 flex items-center gap-2">
        <span class="text-[11px] font-bold text-violet-700 uppercase tracking-wider">IT</span>
        <span id="panelItCount" class="text-base font-bold text-violet-900 leading-none"><?= (int) $studentItCount ?></span>
      </div>
    </div>
  </div>
  </div>
  <div class="relative w-full max-w-xl">
    <svg style="left:12px;top:50%;transform:translateY(-50%);position:absolute;width:16px;height:16px;color:#a1a1aa;pointer-events:none" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z"/></svg>
    <input id="userSearch" type="search" placeholder="Search student no, surname, first name, email…" autocomplete="off" aria-label="Search students"
      style="padding-left:2.5rem"
      class="w-full rounded-xl border border-zinc-300 bg-white pr-3 py-2.5 text-sm text-zinc-900 placeholder:text-zinc-400 focus:outline-none focus:ring-2 focus:ring-emerald-500/30 focus:border-emerald-500" />
  </div>
</div>

<style>
  /* Directory tables — horizontal scroll when columns need room */
  .users-dir-table {
    width: 100%;
    border-collapse: collapse;
  }
  .users-dir-table th,
  .users-dir-table td {
    vertical-align: middle;
  }
  .users-dir-table th {
    white-space: nowrap;
  }
  .users-dir-table .cell-clip {
    display: block;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    max-width: 100%;
  }
  .users-dir-table .cell-nowrap {
    white-space: nowrap;
  }
  .users-dir-scroll {
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
    overscroll-behavior-x: contain;
  }

  /* Students: auto layout + min widths so names/emails stay full; scroll sideways */
  #tableStudents .users-dir-table {
    width: max-content;
    min-width: 100%;
    table-layout: auto;
  }
  #tableStudents .users-dir-table th,
  #tableStudents .users-dir-table td {
    overflow: visible;
    white-space: nowrap;
  }
  #tableStudents .users-dir-table .cell-clip {
    overflow: visible;
    text-overflow: clip;
    max-width: none;
  }
  #tableStudents .users-dir-table col.c-sno { min-width: 110px; }
  #tableStudents .users-dir-table col.c-surname { min-width: 140px; }
  #tableStudents .users-dir-table col.c-first { min-width: 160px; }
  #tableStudents .users-dir-table col.c-middle { min-width: 140px; }
  #tableStudents .users-dir-table col.c-program { min-width: 100px; }
  #tableStudents .users-dir-table col.c-year { min-width: 64px; }
  #tableStudents .users-dir-table col.c-block { min-width: 72px; }
  #tableStudents .users-dir-table col.c-email { min-width: 240px; }
  #tableStudents .users-dir-table col.c-status { min-width: 130px; }
  #tableStudents .users-dir-table col.c-actions { min-width: 96px; }

  /* Teachers / Admins — name columns + sideways scroll */
  #tableTeachers .users-dir-table,
  #tableAdmins .users-dir-table {
    width: max-content;
    min-width: 100%;
    table-layout: auto;
  }
  #tableTeachers .users-dir-table th,
  #tableTeachers .users-dir-table td,
  #tableAdmins .users-dir-table th,
  #tableAdmins .users-dir-table td {
    overflow: visible;
    white-space: nowrap;
  }
  #tableTeachers .users-dir-table .cell-clip,
  #tableAdmins .users-dir-table .cell-clip {
    overflow: visible;
    text-overflow: clip;
    max-width: none;
  }
  #tableTeachers .users-dir-table col.c-t-surname { min-width: 140px; }
  #tableTeachers .users-dir-table col.c-t-first { min-width: 140px; }
  #tableTeachers .users-dir-table col.c-t-middle { min-width: 120px; }
  #tableTeachers .users-dir-table col.c-t-email { min-width: 220px; }
  #tableTeachers .users-dir-table col.c-t-contact { min-width: 130px; }
  #tableTeachers .users-dir-table col.c-t-actions { min-width: 96px; }
  #tableAdmins .users-dir-table col.c-a-surname { min-width: 140px; }
  #tableAdmins .users-dir-table col.c-a-first { min-width: 140px; }
  #tableAdmins .users-dir-table col.c-a-middle { min-width: 120px; }
  #tableAdmins .users-dir-table col.c-a-email { min-width: 240px; }
  #tableAdmins .users-dir-table col.c-a-contact { min-width: 130px; }
  #tableAdmins .users-dir-table col.c-a-actions { min-width: 96px; }
</style>

<div class="pb-10 relative">
  <!-- Notifications Overlay -->
  <div id="msg" class="fixed bottom-6 inset-x-0 mx-auto w-max z-50 px-5 py-3 rounded-xl shadow-2xl transition-all duration-300 transform translate-y-20 opacity-0 pointer-events-none font-bold text-sm"></div>

<!-- Users Table Layout -->
  <div id="tableTeachers" class="bg-white rounded-2xl border border-zinc-200 shadow-sm overflow-hidden min-h-[400px] hidden">
    <div class="users-dir-scroll">
      <table class="users-dir-table text-left text-sm text-zinc-600">
        <colgroup>
          <col class="c-t-surname" />
          <col class="c-t-first" />
          <col class="c-t-middle" />
          <col class="c-t-email" />
          <col class="c-t-contact" />
          <col class="c-t-actions" />
        </colgroup>
        <thead class="bg-zinc-50 border-b border-zinc-200">
          <tr>
            <th scope="col" class="px-4 py-3.5 font-bold text-zinc-900">Surname</th>
            <th scope="col" class="px-4 py-3.5 font-bold text-zinc-900">First Name</th>
            <th scope="col" class="px-4 py-3.5 font-bold text-zinc-900">Middle Name</th>
            <th scope="col" class="px-4 py-3.5 font-bold text-zinc-900">Email</th>
            <th scope="col" class="px-4 py-3.5 font-bold text-zinc-900">Contact No.</th>
            <th scope="col" class="px-4 py-3.5 font-bold text-zinc-900 text-right">Actions</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-zinc-200">
          <?php foreach ($users as $u): if (($u['role'] ?? '') !== 'teacher') continue; ?>
            <?php
              $tLast = trim((string) ($u['last_name'] ?? ''));
              $tFirst = trim((string) ($u['first_name'] ?? ''));
              $tMiddle = trim((string) ($u['middle_name'] ?? ''));
              $uid = (string) ($u['id'] ?? '');
            ?>
            <tr class="hover:bg-zinc-50/80 transition-colors group user-row"
              data-user-id="<?= htmlspecialchars($uid) ?>"
              data-role="teacher"
              data-last-name="<?= htmlspecialchars($tLast) ?>"
              data-first-name="<?= htmlspecialchars($tFirst) ?>"
              data-middle-name="<?= htmlspecialchars($tMiddle) ?>"
              data-suffix="<?= htmlspecialchars(trim((string) ($u['suffix'] ?? ''))) ?>"
              data-email="<?= htmlspecialchars((string)($u['email'] ?? '')) ?>"
              data-contact="<?= htmlspecialchars((string)($u['contact_number'] ?? '')) ?>">
              <td class="px-4 py-3"><span class="cell-clip font-bold text-zinc-900" title="<?= htmlspecialchars($tLast) ?>"><?= htmlspecialchars($tLast !== '' ? $tLast : '—') ?></span></td>
              <td class="px-4 py-3"><span class="cell-clip font-semibold text-zinc-800" title="<?= htmlspecialchars($tFirst) ?>"><?= htmlspecialchars($tFirst !== '' ? $tFirst : '—') ?></span></td>
              <td class="px-4 py-3"><span class="cell-clip font-medium text-zinc-600" title="<?= htmlspecialchars($tMiddle) ?>"><?= htmlspecialchars($tMiddle !== '' ? $tMiddle : '—') ?></span></td>
              <td class="px-4 py-3"><span class="cell-clip font-medium text-zinc-500" title="<?= htmlspecialchars((string)($u['email'] ?? '')) ?>"><?= htmlspecialchars((string)(($u['email'] ?? '') !== '' ? $u['email'] : '—')) ?></span></td>
              <td class="px-4 py-3 cell-nowrap font-medium text-zinc-500"><?= htmlspecialchars((string)(($u['contact_number'] ?? '') !== '' ? $u['contact_number'] : '—')) ?></td>
              <td class="px-4 py-3 text-right">
                <div class="inline-flex items-center justify-end gap-1">
                    <button type="button" class="btnEditStaff p-2 rounded-xl text-zinc-400 hover:text-sky-600 hover:bg-sky-50 transition-colors border border-transparent hover:border-sky-200" title="Edit teacher" aria-label="Edit teacher">
                       <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L6.832 19.82a4.5 4.5 0 01-1.897 1.13l-2.685.8.8-2.685a4.5 4.5 0 011.13-1.897L16.863 4.487zm0 0L19.5 7.125"/></svg>
                    </button>
                    <button type="button" class="btnArchiveStaff p-2 rounded-xl text-zinc-400 hover:text-red-500 hover:bg-red-50 transition-colors border border-transparent hover:border-red-200" title="Move to Archive" aria-label="Move to Archive">
                       <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 01-2.244 2.077H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 00-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 013.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 00-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 00-7.5 0"/></svg>
                    </button>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php if ($teacherCount === 0 || count($users) === 0): ?>
            <tr><td colspan="6" class="px-6 py-16 text-center text-zinc-500">No teachers found.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- Students Table — columns match school Excel roster -->
  <div id="tableStudents" class="bg-white rounded-2xl border border-zinc-200 shadow-sm min-h-[400px] overflow-hidden">
    <div class="users-dir-scroll">
      <table class="users-dir-table text-left text-sm text-zinc-600">
        <colgroup>
          <col class="c-sno" />
          <col class="c-surname" />
          <col class="c-first" />
          <col class="c-middle" />
          <col class="c-program" />
          <col class="c-year" />
          <col class="c-block" />
          <col class="c-email" />
          <col class="c-status" />
          <col class="c-actions" />
        </colgroup>
        <thead class="bg-zinc-50 border-b border-zinc-200">
          <tr>
            <th scope="col" class="px-3 py-3.5 font-bold text-zinc-900">Student No.</th>
            <th scope="col" class="px-3 py-3.5 font-bold text-zinc-900">Surname</th>
            <th scope="col" class="px-3 py-3.5 font-bold text-zinc-900">First Name</th>
            <th scope="col" class="px-3 py-3.5 font-bold text-zinc-900">Middle Name</th>
            <th scope="col" class="px-3 py-3.5 font-bold text-zinc-900">Program</th>
            <th scope="col" class="px-3 py-3.5 font-bold text-zinc-900">Year</th>
            <th scope="col" class="px-3 py-3.5 font-bold text-zinc-900">Block</th>
            <th scope="col" class="px-3 py-3.5 font-bold text-zinc-900">Email</th>
            <th scope="col" class="px-3 py-3.5 font-bold text-zinc-900">Status</th>
            <th scope="col" class="px-3 py-3.5 font-bold text-zinc-900 text-right">Actions</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-zinc-200">
          <?php foreach ($studentDirectory as $row): ?>
            <?php
              $emailDisp = trim((string) ($row['email'] ?? ''));
              if ($emailDisp === '' || $emailDisp === '—') {
                  $emailDisp = '—';
              }
            ?>
            <tr class="hover:bg-zinc-50/80 transition-colors group student-row"
              data-kind="<?= htmlspecialchars((string) ($row['kind'] ?? '')) ?>"
              data-roster-id="<?= htmlspecialchars((string) ($row['roster_id'] ?? '')) ?>"
              data-user-id="<?= htmlspecialchars((string) ($row['user_id'] ?? '')) ?>"
              data-student-no="<?= htmlspecialchars((string) ($row['student_no'] ?? '')) ?>"
              data-last-name="<?= htmlspecialchars((string) ($row['last_name'] ?? '')) ?>"
              data-first-name="<?= htmlspecialchars((string) ($row['first_name'] ?? '')) ?>"
              data-middle-name="<?= htmlspecialchars((string) ($row['middle_name'] ?? '')) ?>"
              data-program="<?= htmlspecialchars((string) ($row['program'] ?? '')) ?>"
              data-year="<?= htmlspecialchars((string) ($row['year'] ?? '')) ?>"
              data-block="<?= htmlspecialchars((string) ($row['block'] ?? '')) ?>"
              data-email="<?= htmlspecialchars($emailDisp === '—' ? '' : $emailDisp) ?>">
              <td class="px-3 py-3 cell-nowrap font-mono text-xs font-bold text-emerald-700 tracking-wide"><?= htmlspecialchars((string) ($row['student_no'] !== '' ? $row['student_no'] : '—')) ?></td>
              <td class="px-3 py-3"><span class="cell-clip font-bold text-zinc-900" title="<?= htmlspecialchars((string) $row['last_name']) ?>"><?= htmlspecialchars((string) ($row['last_name'] !== '' ? $row['last_name'] : '—')) ?></span></td>
              <td class="px-3 py-3"><span class="cell-clip font-semibold text-zinc-800" title="<?= htmlspecialchars((string) $row['first_name']) ?>"><?= htmlspecialchars((string) ($row['first_name'] !== '' ? $row['first_name'] : '—')) ?></span></td>
              <td class="px-3 py-3"><span class="cell-clip font-medium text-zinc-600" title="<?= htmlspecialchars((string) $row['middle_name']) ?>"><?= htmlspecialchars((string) ($row['middle_name'] !== '' ? $row['middle_name'] : '—')) ?></span></td>
              <td class="px-3 py-3 cell-nowrap font-semibold text-zinc-800"><?= htmlspecialchars((string) $row['program']) ?></td>
              <td class="px-3 py-3 cell-nowrap font-semibold text-zinc-800"><?= htmlspecialchars((string) $row['year']) ?></td>
              <td class="px-3 py-3 cell-nowrap font-semibold text-zinc-800"><?= htmlspecialchars((string) $row['block']) ?></td>
              <td class="px-3 py-3"><span class="cell-clip font-medium text-zinc-600" title="<?= htmlspecialchars($emailDisp) ?>"><?= htmlspecialchars($emailDisp) ?></span></td>
              <td class="px-3 py-3">
                <?php if (($row['kind'] ?? '') === 'roster'): ?>
                  <span class="inline-flex rounded-full border border-amber-200 bg-amber-50 px-2 py-0.5 text-[10px] font-black uppercase tracking-wider text-amber-800 whitespace-nowrap">Awaiting signup</span>
                <?php else: ?>
                  <span class="inline-flex rounded-full border border-emerald-200 bg-emerald-50 px-2 py-0.5 text-[10px] font-black uppercase tracking-wider text-emerald-800 whitespace-nowrap">Registered</span>
                <?php endif; ?>
              </td>
              <td class="px-3 py-3 text-right">
                <div class="inline-flex items-center justify-end gap-1">
                  <button type="button" class="btnEditStudent p-2 rounded-xl text-zinc-400 hover:text-sky-600 hover:bg-sky-50 transition-colors border border-transparent hover:border-sky-200" title="Edit student" aria-label="Edit student">
                       <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L6.832 19.82a4.5 4.5 0 01-1.897 1.13l-2.685.8.8-2.685a4.5 4.5 0 011.13-1.897L16.863 4.487zm0 0L19.5 7.125"/></svg>
                    </button>
                  <button type="button" class="btnDeleteStudent p-2 rounded-xl text-zinc-400 hover:text-red-500 hover:bg-red-50 transition-colors border border-transparent hover:border-red-200" title="Move to Archive" aria-label="Move to Archive">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 01-2.244 2.077H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 00-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 013.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 00-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 00-7.5 0"/></svg>
                    </button>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php if (count($studentDirectory) === 0): ?>
            <tr><td colspan="10" class="px-6 py-16 text-center text-zinc-500">No students yet. Import a school CSV to seed the roster.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- Admins Table Layout -->
  <div id="tableAdmins" class="bg-white rounded-2xl border border-zinc-200 shadow-sm overflow-hidden min-h-[400px] hidden">
    <div class="users-dir-scroll">
      <table class="users-dir-table text-left text-sm text-zinc-600">
        <colgroup>
          <col class="c-a-surname" />
          <col class="c-a-first" />
          <col class="c-a-middle" />
          <col class="c-a-email" />
          <col class="c-a-contact" />
          <col class="c-a-actions" />
        </colgroup>
        <thead class="bg-zinc-50 border-b border-zinc-200">
          <tr>
            <th scope="col" class="px-4 py-3.5 font-bold text-zinc-900">Surname</th>
            <th scope="col" class="px-4 py-3.5 font-bold text-zinc-900">First Name</th>
            <th scope="col" class="px-4 py-3.5 font-bold text-zinc-900">Middle Name</th>
            <th scope="col" class="px-4 py-3.5 font-bold text-zinc-900">Email</th>
            <th scope="col" class="px-4 py-3.5 font-bold text-zinc-900">Contact No.</th>
            <th scope="col" class="px-4 py-3.5 font-bold text-zinc-900 text-right">Actions</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-zinc-200">
          <?php foreach ($users as $u): if (($u['role'] ?? '') !== 'admin') continue; ?>
            <?php
              $aLast = trim((string) ($u['last_name'] ?? ''));
              $aFirst = trim((string) ($u['first_name'] ?? ''));
              $aMiddle = trim((string) ($u['middle_name'] ?? ''));
              $aId = trim((string) ($u['id'] ?? ''));
              $isSelf = $currentAdminId !== '' && $aId === $currentAdminId;
            ?>
            <tr class="hover:bg-zinc-50/80 transition-colors group user-row"
              data-user-id="<?= htmlspecialchars($aId) ?>"
              data-role="admin"
              data-last-name="<?= htmlspecialchars($aLast) ?>"
              data-first-name="<?= htmlspecialchars($aFirst) ?>"
              data-middle-name="<?= htmlspecialchars($aMiddle) ?>"
              data-suffix="<?= htmlspecialchars(trim((string) ($u['suffix'] ?? ''))) ?>"
              data-email="<?= htmlspecialchars((string)($u['email'] ?? '')) ?>"
              data-contact="<?= htmlspecialchars((string)($u['contact_number'] ?? '')) ?>">
              <td class="px-4 py-3"><span class="cell-clip font-bold text-zinc-900" title="<?= htmlspecialchars($aLast) ?>"><?= htmlspecialchars($aLast !== '' ? $aLast : '—') ?></span></td>
              <td class="px-4 py-3"><span class="cell-clip font-semibold text-zinc-800" title="<?= htmlspecialchars($aFirst) ?>"><?= htmlspecialchars($aFirst !== '' ? $aFirst : '—') ?></span></td>
              <td class="px-4 py-3"><span class="cell-clip font-medium text-zinc-600" title="<?= htmlspecialchars($aMiddle) ?>"><?= htmlspecialchars($aMiddle !== '' ? $aMiddle : '—') ?></span></td>
              <td class="px-4 py-3"><span class="cell-clip font-medium text-zinc-500" title="<?= htmlspecialchars((string)($u['email'] ?? '')) ?>"><?= htmlspecialchars((string)(($u['email'] ?? '') !== '' ? $u['email'] : '—')) ?></span></td>
              <td class="px-4 py-3 cell-nowrap font-medium text-zinc-500"><?= htmlspecialchars((string)(($u['contact_number'] ?? '') !== '' ? $u['contact_number'] : '—')) ?></td>
              <td class="px-4 py-3 text-right">
                <div class="inline-flex items-center justify-end gap-1">
                    <button type="button" class="btnEditStaff p-2 rounded-xl text-zinc-400 hover:text-sky-600 hover:bg-sky-50 transition-colors border border-transparent hover:border-sky-200" title="Edit admin" aria-label="Edit admin">
                       <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L6.832 19.82a4.5 4.5 0 01-1.897 1.13l-2.685.8.8-2.685a4.5 4.5 0 011.13-1.897L16.863 4.487zm0 0L19.5 7.125"/></svg>
                    </button>
                    <button type="button" class="btnArchiveStaff p-2 rounded-xl text-zinc-400 hover:text-red-500 hover:bg-red-50 transition-colors border border-transparent hover:border-red-200<?= $isSelf ? ' opacity-40 cursor-not-allowed' : '' ?>" title="<?= $isSelf ? 'You cannot archive your own account' : 'Move to Archive' ?>" aria-label="Move to Archive" <?= $isSelf ? 'disabled' : '' ?>>
                       <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 01-2.244 2.077H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 00-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 013.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 00-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 00-7.5 0"/></svg>
                    </button>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Register teacher modal -->
<div id="modalRegisterTeacher" class="fixed inset-0 z-[60] hidden items-center justify-center p-4 bg-zinc-900/50 backdrop-blur-sm" aria-hidden="true">
  <div class="w-full max-w-md rounded-2xl bg-white border border-zinc-200 shadow-xl overflow-hidden" role="dialog" aria-labelledby="modalRegTeacherTitle">
    <div class="px-5 py-4 border-b border-zinc-200 flex items-center justify-between">
      <h4 id="modalRegTeacherTitle" class="text-lg font-bold text-zinc-900">Register teacher</h4>
      <button type="button" id="btnCloseRegisterTeacher" class="rounded-lg p-2 text-zinc-500 hover:bg-zinc-100 hover:text-zinc-800 transition-colors" aria-label="Close">
        <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
      </button>
    </div>
    <form id="formRegisterTeacher" class="p-5 space-y-4 max-h-[min(70vh,520px)] overflow-y-auto">
      <p class="text-sm text-zinc-600">
        Creates a new account with role <span class="font-semibold text-sky-800">Teacher</span>.
        A temporary password will be auto-generated and emailed to the teacher.
        They should change it in <span class="font-semibold">Settings &gt; Change Password</span>.
      </p>
      <div id="regTeacherErr" class="hidden rounded-xl border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-800"></div>
      <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
        <div class="sm:col-span-1">
          <label class="block text-xs font-semibold text-zinc-600 mb-1" for="rt_first_name">First name</label>
          <input id="rt_first_name" name="first_name" type="text" required maxlength="60" autocomplete="given-name" class="js-person-name w-full rounded-xl border border-zinc-300 bg-white px-3 py-2.5 text-sm text-zinc-900 outline-none focus:ring-2 focus:ring-sky-500/40 focus:border-sky-400" />
        </div>
        <div class="sm:col-span-1">
          <label class="block text-xs font-semibold text-zinc-600 mb-1" for="rt_middle_name">Middle name <span class="text-zinc-400 font-normal">(optional)</span></label>
          <input id="rt_middle_name" name="middle_name" type="text" maxlength="60" autocomplete="additional-name" class="js-person-name w-full rounded-xl border border-zinc-300 bg-white px-3 py-2.5 text-sm text-zinc-900 outline-none focus:ring-2 focus:ring-sky-500/40 focus:border-sky-400" />
        </div>
        <div class="sm:col-span-1">
          <label class="block text-xs font-semibold text-zinc-600 mb-1" for="rt_last_name">Surname</label>
          <input id="rt_last_name" name="last_name" type="text" required maxlength="60" autocomplete="family-name" class="js-person-name w-full rounded-xl border border-zinc-300 bg-white px-3 py-2.5 text-sm text-zinc-900 outline-none focus:ring-2 focus:ring-sky-500/40 focus:border-sky-400" />
        </div>
        <div class="sm:col-span-1">
          <label class="block text-xs font-semibold text-zinc-600 mb-1" for="rt_suffix">Suffix <span class="text-zinc-400 font-normal">(optional)</span></label>
          <input id="rt_suffix" name="suffix" type="text" autocomplete="honorific-suffix" class="w-full rounded-xl border border-zinc-300 bg-white px-3 py-2.5 text-sm text-zinc-900 outline-none focus:ring-2 focus:ring-sky-500/40 focus:border-sky-400" placeholder="Jr., III" />
        </div>
      </div>
      <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
        <div>
          <label class="block text-xs font-semibold text-zinc-600 mb-1" for="rt_email">Email</label>
          <input id="rt_email" name="email" type="email" required autocomplete="email" class="w-full rounded-xl border border-zinc-300 bg-white px-3 py-2.5 text-sm text-zinc-900 outline-none focus:ring-2 focus:ring-sky-500/40 focus:border-sky-400" />
        </div>
        <div>
          <label class="block text-xs font-semibold text-zinc-600 mb-1" for="rt_contact">Contact Number <span class="text-zinc-400 font-normal">(11 digits)</span></label>
          <input id="rt_contact" name="contact_number" type="text" inputmode="numeric" maxlength="11" pattern="[0-9]{11}" placeholder="09123456789" class="js-ph-contact w-full rounded-xl border border-zinc-300 bg-white px-3 py-2.5 text-sm text-zinc-900 outline-none focus:ring-2 focus:ring-sky-500/40 focus:border-sky-400" />
        </div>
      </div>
      <div class="flex flex-col-reverse sm:flex-row gap-2 sm:justify-end pt-2">
        <button type="button" id="btnCancelRegisterTeacher" class="rounded-xl border border-zinc-300 bg-white px-4 py-2.5 text-sm font-bold text-zinc-700 hover:bg-zinc-50">Cancel</button>
        <button type="submit" id="btnSubmitRegisterTeacher" class="rounded-xl bg-sky-600 text-white px-4 py-2.5 text-sm font-bold hover:bg-sky-700 border border-sky-600">Create teacher</button>
      </div>
    </form>
  </div>
</div>

<!-- Register admin modal -->
<div id="modalRegisterAdmin" class="fixed inset-0 z-[60] hidden items-center justify-center p-4 bg-zinc-900/50 backdrop-blur-sm" aria-hidden="true">
  <div class="w-full max-w-md rounded-2xl bg-white border border-zinc-200 shadow-xl overflow-hidden" role="dialog" aria-labelledby="modalRegAdminTitle">
    <div class="px-5 py-4 border-b border-zinc-200 flex items-center justify-between">
      <h4 id="modalRegAdminTitle" class="text-lg font-bold text-zinc-900">Register admin</h4>
      <button type="button" id="btnCloseRegisterAdmin" class="rounded-lg p-2 text-zinc-500 hover:bg-zinc-100 hover:text-zinc-800 transition-colors" aria-label="Close">
        <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
      </button>
    </div>
    <form id="formRegisterAdmin" class="p-5 space-y-4 max-h-[min(70vh,520px)] overflow-y-auto">
      <p class="text-sm text-zinc-600">
        Creates a new account with role <span class="font-semibold text-orange-800">Admin</span>.
        A temporary password will be auto-generated and emailed to the admin.
        They should change it in <span class="font-semibold">Settings &gt; Change Password</span>.
      </p>
      <div id="regAdminErr" class="hidden rounded-xl border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-800"></div>
      <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
        <div class="sm:col-span-1">
          <label class="block text-xs font-semibold text-zinc-600 mb-1" for="ra_first_name">First name</label>
          <input id="ra_first_name" name="first_name" type="text" required maxlength="60" autocomplete="given-name" class="js-person-name w-full rounded-xl border border-zinc-300 bg-white px-3 py-2.5 text-sm text-zinc-900 outline-none focus:ring-2 focus:ring-sky-500/40 focus:border-sky-400" />
        </div>
        <div class="sm:col-span-1">
          <label class="block text-xs font-semibold text-zinc-600 mb-1" for="ra_middle_name">Middle name <span class="text-zinc-400 font-normal">(optional)</span></label>
          <input id="ra_middle_name" name="middle_name" type="text" maxlength="60" autocomplete="additional-name" class="js-person-name w-full rounded-xl border border-zinc-300 bg-white px-3 py-2.5 text-sm text-zinc-900 outline-none focus:ring-2 focus:ring-sky-500/40 focus:border-sky-400" />
        </div>
        <div class="sm:col-span-1">
          <label class="block text-xs font-semibold text-zinc-600 mb-1" for="ra_last_name">Surname</label>
          <input id="ra_last_name" name="last_name" type="text" required maxlength="60" autocomplete="family-name" class="js-person-name w-full rounded-xl border border-zinc-300 bg-white px-3 py-2.5 text-sm text-zinc-900 outline-none focus:ring-2 focus:ring-sky-500/40 focus:border-sky-400" />
        </div>
        <div class="sm:col-span-1">
          <label class="block text-xs font-semibold text-zinc-600 mb-1" for="ra_suffix">Suffix <span class="text-zinc-400 font-normal">(optional)</span></label>
          <input id="ra_suffix" name="suffix" type="text" autocomplete="honorific-suffix" class="w-full rounded-xl border border-zinc-300 bg-white px-3 py-2.5 text-sm text-zinc-900 outline-none focus:ring-2 focus:ring-sky-500/40 focus:border-sky-400" placeholder="Jr., III" />
        </div>
      </div>
      <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
        <div>
          <label class="block text-xs font-semibold text-zinc-600 mb-1" for="ra_email">Email</label>
          <input id="ra_email" name="email" type="email" required autocomplete="email" class="w-full rounded-xl border border-zinc-300 bg-white px-3 py-2.5 text-sm text-zinc-900 outline-none focus:ring-2 focus:ring-sky-500/40 focus:border-sky-400" />
        </div>
        <div>
          <label class="block text-xs font-semibold text-zinc-600 mb-1" for="ra_contact">Contact Number <span class="text-zinc-400 font-normal">(11 digits)</span></label>
          <input id="ra_contact" name="contact_number" type="text" inputmode="numeric" maxlength="11" pattern="[0-9]{11}" placeholder="09123456789" class="js-ph-contact w-full rounded-xl border border-zinc-300 bg-white px-3 py-2.5 text-sm text-zinc-900 outline-none focus:ring-2 focus:ring-sky-500/40 focus:border-sky-400" />
        </div>
      </div>
      <div class="flex flex-col-reverse sm:flex-row gap-2 sm:justify-end pt-2">
        <button type="button" id="btnCancelRegisterAdmin" class="rounded-xl border border-zinc-300 bg-white px-4 py-2.5 text-sm font-bold text-zinc-700 hover:bg-zinc-50">Cancel</button>
        <button type="submit" id="btnSubmitRegisterAdmin" class="rounded-xl bg-orange-600 text-white px-4 py-2.5 text-sm font-bold hover:bg-orange-700 border border-orange-600">Create admin</button>
      </div>
    </form>
  </div>
</div>

<!-- Edit teacher / admin modal -->
<div id="modalEditStaff" class="fixed inset-0 z-[60] hidden items-center justify-center p-4 bg-zinc-900/50 backdrop-blur-sm" aria-hidden="true">
  <div class="w-full max-w-md rounded-2xl bg-white border border-zinc-200 shadow-xl overflow-hidden" role="dialog" aria-labelledby="modalEditStaffTitle">
    <div class="px-5 py-4 border-b border-zinc-200 flex items-center justify-between">
      <h4 id="modalEditStaffTitle" class="text-lg font-bold text-zinc-900">Edit teacher</h4>
      <button type="button" id="btnCloseEditStaff" class="rounded-lg p-2 text-zinc-500 hover:bg-zinc-100 hover:text-zinc-800 transition-colors" aria-label="Close">
        <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
      </button>
    </div>
    <form id="formEditStaff" class="p-5 space-y-4 max-h-[min(70vh,520px)] overflow-y-auto">
      <input type="hidden" id="st_user_id" name="user_id" value="" />
      <div id="editStaffErr" class="hidden rounded-xl border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-800"></div>
      <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
        <div>
          <label class="block text-xs font-semibold text-zinc-600 mb-1" for="st_first_name">First name</label>
          <input id="st_first_name" name="first_name" type="text" required maxlength="60" class="js-person-name w-full rounded-xl border border-zinc-300 bg-white px-3 py-2.5 text-sm text-zinc-900 outline-none focus:ring-2 focus:ring-sky-500/40 focus:border-sky-400" />
        </div>
        <div>
          <label class="block text-xs font-semibold text-zinc-600 mb-1" for="st_middle_name">Middle name <span class="text-zinc-400 font-normal">(optional)</span></label>
          <input id="st_middle_name" name="middle_name" type="text" maxlength="60" class="js-person-name w-full rounded-xl border border-zinc-300 bg-white px-3 py-2.5 text-sm text-zinc-900 outline-none focus:ring-2 focus:ring-sky-500/40 focus:border-sky-400" />
        </div>
        <div>
          <label class="block text-xs font-semibold text-zinc-600 mb-1" for="st_last_name">Surname</label>
          <input id="st_last_name" name="last_name" type="text" required maxlength="60" class="js-person-name w-full rounded-xl border border-zinc-300 bg-white px-3 py-2.5 text-sm text-zinc-900 outline-none focus:ring-2 focus:ring-sky-500/40 focus:border-sky-400" />
        </div>
        <div>
          <label class="block text-xs font-semibold text-zinc-600 mb-1" for="st_suffix">Suffix <span class="text-zinc-400 font-normal">(optional)</span></label>
          <input id="st_suffix" name="suffix" type="text" maxlength="30" placeholder="Jr., III" class="w-full rounded-xl border border-zinc-300 bg-white px-3 py-2.5 text-sm text-zinc-900 outline-none focus:ring-2 focus:ring-sky-500/40 focus:border-sky-400" />
        </div>
        <div>
          <label class="block text-xs font-semibold text-zinc-600 mb-1" for="st_email">Email</label>
          <input id="st_email" name="email" type="email" required class="w-full rounded-xl border border-zinc-300 bg-white px-3 py-2.5 text-sm text-zinc-900 outline-none focus:ring-2 focus:ring-sky-500/40 focus:border-sky-400" />
        </div>
        <div>
          <label class="block text-xs font-semibold text-zinc-600 mb-1" for="st_contact">Contact Number <span class="text-zinc-400 font-normal">(11 digits)</span></label>
          <input id="st_contact" name="contact_number" type="text" inputmode="numeric" maxlength="11" pattern="[0-9]{11}" placeholder="09123456789" class="js-ph-contact w-full rounded-xl border border-zinc-300 bg-white px-3 py-2.5 text-sm text-zinc-900 outline-none focus:ring-2 focus:ring-sky-500/40 focus:border-sky-400" />
        </div>
      </div>
      <div class="flex flex-col-reverse sm:flex-row gap-2 sm:justify-end pt-2">
        <button type="button" id="btnCancelEditStaff" class="rounded-xl border border-zinc-300 bg-white px-4 py-2.5 text-sm font-bold text-zinc-700 hover:bg-zinc-50">Cancel</button>
        <button type="submit" id="btnSubmitEditStaff" class="rounded-xl bg-sky-600 text-white px-4 py-2.5 text-sm font-bold hover:bg-sky-700 border border-sky-600">Save changes</button>
      </div>
    </form>
  </div>
</div>

<!-- Import students roster modal -->
<div id="modalImportStudents" class="fixed inset-0 z-[60] hidden items-center justify-center p-4 bg-zinc-900/50 backdrop-blur-sm" aria-hidden="true">
  <div class="w-full max-w-lg rounded-2xl bg-white border border-zinc-200 shadow-xl overflow-hidden" role="dialog" aria-labelledby="modalImportStudentsTitle">
    <div class="px-5 py-4 border-b border-zinc-200 flex items-center justify-between">
      <h4 id="modalImportStudentsTitle" class="text-lg font-bold text-zinc-900">Import student roster</h4>
      <button type="button" id="btnCloseImportStudents" class="rounded-lg p-2 text-zinc-500 hover:bg-zinc-100 hover:text-zinc-800 transition-colors" aria-label="Close">
        <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
      </button>
    </div>
    <form id="formImportStudents" class="p-5 space-y-4">
      <p class="text-sm text-zinc-600">
        Upload school CSV/XLSX. Two layouts are accepted:
        <span class="font-semibold text-zinc-800">BSIT</span> columns
        <span class="font-semibold text-zinc-800">Student No, Surname, First Name, Middle Name, Program, Year, Block</span>
        (sheets like <code class="text-xs bg-zinc-100 px-1 rounded">BSIT-BA</code> /
        <code class="text-xs bg-zinc-100 px-1 rounded">BSIT-SD</code>),
        or <span class="font-semibold text-zinc-800">BSCS</span> pages
        <span class="font-semibold text-zinc-800">Student No, Student's Name</span>
        with year/block in the sheet name (e.g. <code class="text-xs bg-zinc-100 px-1 rounded">BSCS-DS 1A</code>).
        Block <code class="text-xs bg-zinc-100 px-1 rounded">--</code> stays irregular (Year still kept).
        Missing sections are created automatically. Re-import overwrites existing roster rows (and linked account names) from the file.
        Students stay “Awaiting signup” until they create an app account.
      </p>
      <div id="importStudentsErr" class="hidden rounded-xl border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-800"></div>
      <div id="importStudentsOk" class="hidden rounded-xl border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-900"></div>
      <div>
        <label class="block text-xs font-semibold text-zinc-600 mb-1" for="importStudentsFile">Spreadsheet file</label>
        <input id="importStudentsFile" name="file" type="file" accept=".csv,.xlsx,text/csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" required
          class="w-full rounded-xl border border-zinc-300 bg-white px-3 py-2.5 text-sm text-zinc-900 file:mr-3 file:rounded-lg file:border-0 file:bg-emerald-50 file:px-3 file:py-1.5 file:text-xs file:font-bold file:text-emerald-800" />
      </div>
      <div class="flex items-center justify-end gap-2 pt-2 border-t border-zinc-100">
        <button type="button" id="btnCancelImportStudents" class="rounded-xl border border-zinc-200 bg-white px-4 py-2.5 text-sm font-bold text-zinc-700 hover:bg-zinc-50">Cancel</button>
        <button type="submit" id="btnSubmitImportStudents" class="rounded-xl bg-emerald-600 px-4 py-2.5 text-sm font-bold text-white hover:bg-emerald-700">Import</button>
      </div>
    </form>
  </div>
</div>

<!-- Edit student modal -->
<div id="modalEditStudent" class="fixed inset-0 z-[60] hidden items-center justify-center p-4 bg-zinc-900/50 backdrop-blur-sm" aria-hidden="true">
  <div class="w-full max-w-lg rounded-2xl bg-white border border-zinc-200 shadow-xl overflow-hidden" role="dialog" aria-labelledby="modalEditStudentTitle">
    <div class="px-5 py-4 border-b border-zinc-200 flex items-center justify-between">
      <h4 id="modalEditStudentTitle" class="text-lg font-bold text-zinc-900">Edit student</h4>
      <button type="button" id="btnCloseEditStudent" class="rounded-lg p-2 text-zinc-500 hover:bg-zinc-100 hover:text-zinc-800 transition-colors" aria-label="Close">
        <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
      </button>
    </div>
    <form id="formEditStudent" class="p-5 space-y-4 max-h-[min(75vh,640px)] overflow-y-auto">
      <input type="hidden" id="es_roster_id" name="roster_id" value="" />
      <input type="hidden" id="es_user_id" name="user_id" value="" />
      <div id="editStudentErr" class="hidden rounded-xl border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-800"></div>
      <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
        <div class="sm:col-span-2">
          <label class="block text-xs font-semibold text-zinc-600 mb-1" for="es_student_no">Student No.</label>
          <input id="es_student_no" name="student_no" required maxlength="40"
            class="w-full rounded-xl border border-zinc-300 bg-white px-3 py-2.5 text-sm text-zinc-900 font-mono" />
      </div>
      <div>
          <label class="block text-xs font-semibold text-zinc-600 mb-1" for="es_last_name">Surname</label>
          <input id="es_last_name" name="last_name" required maxlength="80"
            class="w-full rounded-xl border border-zinc-300 bg-white px-3 py-2.5 text-sm text-zinc-900" />
      </div>
        <div>
          <label class="block text-xs font-semibold text-zinc-600 mb-1" for="es_first_name">First Name</label>
          <input id="es_first_name" name="first_name" required maxlength="80"
            class="w-full rounded-xl border border-zinc-300 bg-white px-3 py-2.5 text-sm text-zinc-900" />
        </div>
        <div class="sm:col-span-2">
          <label class="block text-xs font-semibold text-zinc-600 mb-1" for="es_middle_name">Middle Name</label>
          <input id="es_middle_name" name="middle_name" maxlength="80"
            class="w-full rounded-xl border border-zinc-300 bg-white px-3 py-2.5 text-sm text-zinc-900" />
        </div>
        <div>
          <label class="block text-xs font-semibold text-zinc-600 mb-1" for="es_program">Program</label>
          <select id="es_program" name="program" required
            class="w-full rounded-xl border border-zinc-300 bg-white px-3 py-2.5 text-sm text-zinc-900">
            <option value="BSIT BA">BSIT BA</option>
            <option value="BSIT SD">BSIT SD</option>
            <option value="BSCS">BSCS</option>
          </select>
        </div>
        <div>
          <label class="block text-xs font-semibold text-zinc-600 mb-1" for="es_year">Year</label>
          <select id="es_year" name="year" required
            class="w-full rounded-xl border border-zinc-300 bg-white px-3 py-2.5 text-sm text-zinc-900">
            <option value="1st">1st</option>
            <option value="2nd">2nd</option>
            <option value="3rd">3rd</option>
            <option value="4th">4th</option>
          </select>
        </div>
        <div class="sm:col-span-2">
          <label class="block text-xs font-semibold text-zinc-600 mb-1" for="es_block">Block</label>
          <input id="es_block" name="block" required maxlength="12" placeholder="1A or --"
            class="w-full rounded-xl border border-zinc-300 bg-white px-3 py-2.5 text-sm text-zinc-900 font-mono" />
          <p class="mt-1 text-[11px] text-zinc-500">Use <code class="bg-zinc-100 px-1 rounded">--</code> for irregular.</p>
        </div>
      </div>
      <div class="flex items-center justify-end gap-2 pt-2 border-t border-zinc-100">
        <button type="button" id="btnCancelEditStudent" class="rounded-xl border border-zinc-200 bg-white px-4 py-2.5 text-sm font-bold text-zinc-700 hover:bg-zinc-50">Cancel</button>
        <button type="submit" id="btnSubmitEditStudent" class="rounded-xl bg-sky-600 px-4 py-2.5 text-sm font-bold text-white hover:bg-sky-700">Save changes</button>
      </div>
    </form>
  </div>
</div>

<script>
  const msg = document.getElementById('msg');
  
  function showToast(text, isError = false) {
     msg.textContent = text;
     msg.className = `fixed bottom-6 inset-x-0 mx-auto w-max z-50 px-5 py-3 rounded-xl shadow-2xl transition-all duration-300 transform font-bold text-sm ${isError ? 'bg-red-500/20 text-red-500 border border-red-500/30' : 'bg-emerald-500/20 text-emerald-400 border border-emerald-500/30'}`;
     msg.style.opacity = '1';
     msg.style.transform = 'translateY(0)';
     
     setTimeout(() => {
        msg.style.opacity = '0';
        msg.style.transform = 'translateY(20px)';
     }, 3000);
  }

  function bindStaffFieldGuards(root) {
    if (!root) return;
    root.querySelectorAll('.js-person-name').forEach((el) => {
      el.addEventListener('input', () => {
        const next = el.value.replace(/[0-9]/g, '').replace(/[^\p{L}\s.'-]/gu, '');
        if (el.value !== next) el.value = next;
      });
    });
    root.querySelectorAll('.js-ph-contact').forEach((el) => {
      el.addEventListener('input', () => {
        const next = el.value.replace(/\D/g, '').slice(0, 11);
        if (el.value !== next) el.value = next;
      });
    });
  }
  bindStaffFieldGuards(document.getElementById('formRegisterTeacher'));
  bindStaffFieldGuards(document.getElementById('formRegisterAdmin'));
  bindStaffFieldGuards(document.getElementById('formEditStaff'));

  const modalReg = document.getElementById('modalRegisterTeacher');
  const formReg = document.getElementById('formRegisterTeacher');
  const regErr = document.getElementById('regTeacherErr');

  function openModalReg() {
    modalReg.classList.remove('hidden');
    modalReg.classList.add('flex');
    modalReg.setAttribute('aria-hidden', 'false');
    document.getElementById('rt_first_name')?.focus();
  }
  function closeModalReg() {
    modalReg.classList.add('hidden');
    modalReg.classList.remove('flex');
    modalReg.setAttribute('aria-hidden', 'true');
    regErr.classList.add('hidden');
    regErr.textContent = '';
    formReg.reset();
  }

  document.getElementById('btnOpenRegisterTeacher')?.addEventListener('click', openModalReg);
  document.getElementById('btnCloseRegisterTeacher')?.addEventListener('click', closeModalReg);
  document.getElementById('btnCancelRegisterTeacher')?.addEventListener('click', closeModalReg);
  modalReg?.addEventListener('click', (e) => { if (e.target === modalReg) closeModalReg(); });

  const modalRegAdmin = document.getElementById('modalRegisterAdmin');
  const formRegAdmin = document.getElementById('formRegisterAdmin');
  const regAdminErr = document.getElementById('regAdminErr');

  function openModalRegAdmin() {
    if (!modalRegAdmin) return;
    modalRegAdmin.classList.remove('hidden');
    modalRegAdmin.classList.add('flex');
    modalRegAdmin.setAttribute('aria-hidden', 'false');
    document.getElementById('ra_first_name')?.focus();
  }
  function closeModalRegAdmin() {
    if (!modalRegAdmin) return;
    modalRegAdmin.classList.add('hidden');
    modalRegAdmin.classList.remove('flex');
    modalRegAdmin.setAttribute('aria-hidden', 'true');
    if (regAdminErr) {
      regAdminErr.classList.add('hidden');
      regAdminErr.textContent = '';
    }
    formRegAdmin?.reset();
  }

  document.getElementById('btnOpenRegisterAdmin')?.addEventListener('click', openModalRegAdmin);
  document.getElementById('btnCloseRegisterAdmin')?.addEventListener('click', closeModalRegAdmin);
  document.getElementById('btnCancelRegisterAdmin')?.addEventListener('click', closeModalRegAdmin);
  modalRegAdmin?.addEventListener('click', (e) => { if (e.target === modalRegAdmin) closeModalRegAdmin(); });

  document.addEventListener('keydown', (e) => {
    if (e.key !== 'Escape') return;
    if (modalReg && !modalReg.classList.contains('hidden')) closeModalReg();
    if (modalRegAdmin && !modalRegAdmin.classList.contains('hidden')) closeModalRegAdmin();
    if (modalImport && !modalImport.classList.contains('hidden')) closeModalImport();
    if (modalEdit && !modalEdit.classList.contains('hidden')) closeModalEdit();
    if (typeof closeModalStaff === 'function') {
      const modalStaffEl = document.getElementById('modalEditStaff');
      if (modalStaffEl && !modalStaffEl.classList.contains('hidden')) closeModalStaff();
    }
  });

  const modalImport = document.getElementById('modalImportStudents');
  const formImport = document.getElementById('formImportStudents');
  const importErr = document.getElementById('importStudentsErr');
  const importOk = document.getElementById('importStudentsOk');
  function openModalImport() {
    if (!modalImport) return;
    importErr?.classList.add('hidden');
    importOk?.classList.add('hidden');
    formImport?.reset();
    modalImport.classList.remove('hidden');
    modalImport.classList.add('flex');
    modalImport.setAttribute('aria-hidden', 'false');
  }
  function closeModalImport() {
    if (!modalImport) return;
    modalImport.classList.add('hidden');
    modalImport.classList.remove('flex');
    modalImport.setAttribute('aria-hidden', 'true');
  }
  document.getElementById('btnOpenImportStudents')?.addEventListener('click', openModalImport);
  document.getElementById('btnCloseImportStudents')?.addEventListener('click', closeModalImport);
  document.getElementById('btnCancelImportStudents')?.addEventListener('click', closeModalImport);
  modalImport?.addEventListener('click', (e) => { if (e.target === modalImport) closeModalImport(); });

  formImport?.addEventListener('submit', async (e) => {
    e.preventDefault();
    importErr?.classList.add('hidden');
    importOk?.classList.add('hidden');
    const fileInput = document.getElementById('importStudentsFile');
    const file = fileInput?.files?.[0];
    if (!file) {
      if (importErr) { importErr.textContent = 'Choose a CSV or XLSX file.'; importErr.classList.remove('hidden'); }
      return;
    }
    const submitBtn = document.getElementById('btnSubmitImportStudents');
    const prev = submitBtn?.textContent || 'Import';
    if (submitBtn) { submitBtn.disabled = true; submitBtn.textContent = 'Importing…'; }
    try {
      const fd = new FormData();
      fd.append('file', file);
      fd.append('csrf_token', window.CSRF_TOKEN || '');
      const res = await fetch('/api/students_roster_import.php', { method: 'POST', body: fd });
      const data = await res.json().catch(() => ({}));
      if (!data.ok) throw new Error(data.error || 'Import failed');
      const sheetsLabel = Array.isArray(data.sheets_imported) && data.sheets_imported.length
        ? ` Sheets: ${data.sheets_imported.join(', ')}.`
        : '';
      const msg = `Imported: ${data.inserted || 0} new, ${data.updated || data.corrected || 0} corrected`
        + (data.saved ? ` (${data.saved} saved)` : '')
        + (data.sections_created_count ? `, ${data.sections_created_count} section(s) created` : '')
        + (data.skipped ? `, ${data.skipped} skipped` : '')
        + '.'
        + sheetsLabel;
      let detail = data.message || 'Existing rows were overwritten from the spreadsheet.';
      if (Array.isArray(data.errors) && data.errors.length) {
        detail += ' ' + data.errors.slice(0, 8).join(' · ');
        if ((data.error_count || data.errors.length) > 8) detail += ' …';
      }
      if (importOk) {
        importOk.innerHTML = '';
        importOk.appendChild(document.createTextNode(msg));
        if (detail) {
          const d = document.createElement('div');
          d.className = 'mt-1 text-xs text-emerald-800/80';
          d.textContent = detail;
          importOk.appendChild(d);
        }
        importOk.classList.remove('hidden');
      }
      showToast(msg);
      setTimeout(() => window.location.reload(), 1200);
    } catch (err) {
      if (importErr) { importErr.textContent = err.message || 'Import failed'; importErr.classList.remove('hidden'); }
    } finally {
      if (submitBtn) { submitBtn.disabled = false; submitBtn.textContent = prev; }
    }
  });

  // --- Edit / Delete student ---
  const modalEdit = document.getElementById('modalEditStudent');
  const formEdit = document.getElementById('formEditStudent');
  const editErr = document.getElementById('editStudentErr');

  function openModalEdit() {
    if (!modalEdit) return;
    editErr?.classList.add('hidden');
    modalEdit.classList.remove('hidden');
    modalEdit.classList.add('flex');
    modalEdit.setAttribute('aria-hidden', 'false');
  }
  function closeModalEdit() {
    if (!modalEdit) return;
    modalEdit.classList.add('hidden');
    modalEdit.classList.remove('flex');
    modalEdit.setAttribute('aria-hidden', 'true');
  }
  document.getElementById('btnCloseEditStudent')?.addEventListener('click', closeModalEdit);
  document.getElementById('btnCancelEditStudent')?.addEventListener('click', closeModalEdit);
  modalEdit?.addEventListener('click', (e) => { if (e.target === modalEdit) closeModalEdit(); });

  document.getElementById('btnOpenAddStudent')?.addEventListener('click', () => {
    formEdit?.reset();
    document.getElementById('es_roster_id').value = '';
    document.getElementById('es_user_id').value = '';
    const title = document.getElementById('modalEditStudentTitle');
    if (title) title.textContent = 'Add student';
    const submitBtn = document.getElementById('btnSubmitEditStudent');
    if (submitBtn) submitBtn.textContent = 'Add student';
    openModalEdit();
    document.getElementById('es_student_no')?.focus();
  });

  function normalizeProgramOption(raw) {
    const p = String(raw || '').toUpperCase().replace(/\s+/g, ' ').trim();
    if (p.includes('BA')) return 'BSIT BA';
    if (p.includes('SD') || p === 'BSIT' || p === 'IT') return 'BSIT SD';
    if (p.includes('CS')) return 'BSCS';
    return 'BSIT SD';
  }
  function normalizeYearOption(raw) {
    const m = String(raw || '').match(/([1-4])/);
    if (!m) return '1st';
    return ({ 1: '1st', 2: '2nd', 3: '3rd', 4: '4th' })[m[1]] || '1st';
  }

  document.querySelectorAll('.btnEditStudent').forEach((btn) => {
    btn.addEventListener('click', () => {
      const tr = btn.closest('tr');
      if (!tr) return;
      const title = document.getElementById('modalEditStudentTitle');
      if (title) title.textContent = 'Edit student';
      const submitBtn = document.getElementById('btnSubmitEditStudent');
      if (submitBtn) submitBtn.textContent = 'Save changes';
      document.getElementById('es_roster_id').value = tr.dataset.rosterId || '';
      document.getElementById('es_user_id').value = tr.dataset.userId || '';
      document.getElementById('es_student_no').value = tr.dataset.studentNo || '';
      document.getElementById('es_last_name').value = tr.dataset.lastName || '';
      document.getElementById('es_first_name').value = tr.dataset.firstName || '';
      document.getElementById('es_middle_name').value = tr.dataset.middleName || '';
      document.getElementById('es_program').value = normalizeProgramOption(tr.dataset.program);
      document.getElementById('es_year').value = normalizeYearOption(tr.dataset.year);
      document.getElementById('es_block').value = tr.dataset.block || '';
      openModalEdit();
    });
  });

  formEdit?.addEventListener('submit', async (e) => {
    e.preventDefault();
    editErr?.classList.add('hidden');
    const submitBtn = document.getElementById('btnSubmitEditStudent');
    const prev = submitBtn?.textContent || 'Save changes';
    if (submitBtn) { submitBtn.disabled = true; submitBtn.textContent = 'Saving…'; }
    try {
      const res = await fetch('/api/students_roster_update.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          csrf_token: window.CSRF_TOKEN || '',
          roster_id: document.getElementById('es_roster_id').value,
          user_id: document.getElementById('es_user_id').value,
          student_no: document.getElementById('es_student_no').value,
          last_name: document.getElementById('es_last_name').value,
          first_name: document.getElementById('es_first_name').value,
          middle_name: document.getElementById('es_middle_name').value,
          program: document.getElementById('es_program').value,
          year: document.getElementById('es_year').value,
          block: document.getElementById('es_block').value,
        }),
      });
      const data = await res.json().catch(() => ({}));
      if (!data.ok) throw new Error(data.error || 'Update failed');
      showToast('Student updated.');
      closeModalEdit();
      setTimeout(() => window.location.reload(), 400);
    } catch (err) {
      if (editErr) { editErr.textContent = err.message || 'Update failed'; editErr.classList.remove('hidden'); }
    } finally {
      if (submitBtn) { submitBtn.disabled = false; submitBtn.textContent = prev; }
    }
  });

  document.querySelectorAll('.btnDeleteStudent').forEach((btn) => {
    btn.addEventListener('click', async () => {
      const tr = btn.closest('tr');
      if (!tr) return;
      const sno = tr.dataset.studentNo || '';
      const name = [tr.dataset.lastName, tr.dataset.firstName].filter(Boolean).join(', ');
      const kind = tr.dataset.kind || '';
      const warn = kind === 'registered'
        ? `Move registered student ${name || sno} to Archive?\n\nThey will leave the active list. You can restore them from Archive.`
        : `Move ${name || sno} to Archive?\n\nThey will not be able to sign up until restored.`;
      if (!window.confirm(warn)) return;

      btn.disabled = true;
      try {
        const res = await fetch('/api/students_roster_delete.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({
            csrf_token: window.CSRF_TOKEN || '',
            roster_id: tr.dataset.rosterId || '',
            user_id: tr.dataset.userId || '',
            student_no: sno,
          }),
        });
        const data = await res.json().catch(() => ({}));
        if (!data.ok) throw new Error(data.error || 'Archive failed');
        showToast(data.message || 'Student moved to Archive.');
        tr.remove();
        if (typeof applyUserSearch === 'function') {
          applyUserSearch();
        } else {
          const totalEl = document.getElementById('panelTotal');
          if (totalEl) {
            const n = Math.max(0, (parseInt(totalEl.textContent, 10) || 0) - 1);
            totalEl.textContent = String(n);
          }
        }
      } catch (err) {
        showToast(err.message || 'Archive failed', true);
      } finally {
        btn.disabled = false;
      }
    });
  });

  const modalStaff = document.getElementById('modalEditStaff');
  const formStaff = document.getElementById('formEditStaff');
  const editStaffErr = document.getElementById('editStaffErr');
  function openModalStaff() {
    if (!modalStaff) return;
    editStaffErr?.classList.add('hidden');
    modalStaff.classList.remove('hidden');
    modalStaff.classList.add('flex');
    modalStaff.setAttribute('aria-hidden', 'false');
  }
  function closeModalStaff() {
    if (!modalStaff) return;
    modalStaff.classList.add('hidden');
    modalStaff.classList.remove('flex');
    modalStaff.setAttribute('aria-hidden', 'true');
    editStaffErr?.classList.add('hidden');
    formStaff?.reset();
  }
  document.getElementById('btnCloseEditStaff')?.addEventListener('click', closeModalStaff);
  document.getElementById('btnCancelEditStaff')?.addEventListener('click', closeModalStaff);
  modalStaff?.addEventListener('click', (e) => { if (e.target === modalStaff) closeModalStaff(); });

  document.querySelectorAll('.btnEditStaff').forEach((btn) => {
    btn.addEventListener('click', () => {
      const tr = btn.closest('tr');
      if (!tr) return;
      const role = (tr.dataset.role || 'teacher').toLowerCase();
      const title = document.getElementById('modalEditStaffTitle');
      if (title) title.textContent = role === 'admin' ? 'Edit admin' : 'Edit teacher';
      document.getElementById('st_user_id').value = tr.dataset.userId || '';
      document.getElementById('st_first_name').value = tr.dataset.firstName || '';
      document.getElementById('st_middle_name').value = tr.dataset.middleName || '';
      document.getElementById('st_last_name').value = tr.dataset.lastName || '';
      document.getElementById('st_suffix').value = tr.dataset.suffix || '';
      document.getElementById('st_email').value = tr.dataset.email || '';
      document.getElementById('st_contact').value = tr.dataset.contact || '';
      openModalStaff();
    });
  });

  formStaff?.addEventListener('submit', async (e) => {
    e.preventDefault();
    editStaffErr?.classList.add('hidden');
    const submitBtn = document.getElementById('btnSubmitEditStaff');
    const prev = submitBtn?.textContent || 'Save changes';
    if (submitBtn) { submitBtn.disabled = true; submitBtn.textContent = 'Saving…'; }
    try {
      const res = await fetch('/api/staff_user_update.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          csrf_token: window.CSRF_TOKEN || '',
          user_id: document.getElementById('st_user_id').value,
          first_name: document.getElementById('st_first_name').value,
          middle_name: document.getElementById('st_middle_name').value,
          last_name: document.getElementById('st_last_name').value,
          suffix: document.getElementById('st_suffix').value,
          email: document.getElementById('st_email').value,
          contact_number: document.getElementById('st_contact').value,
        }),
      });
      const data = await res.json().catch(() => ({}));
      if (!data.ok) throw new Error(data.error || 'Update failed');
      showToast('Account updated.');
      closeModalStaff();
      setTimeout(() => window.location.reload(), 400);
    } catch (err) {
      if (editStaffErr) {
        editStaffErr.textContent = err.message || 'Update failed';
        editStaffErr.classList.remove('hidden');
      }
    } finally {
      if (submitBtn) { submitBtn.disabled = false; submitBtn.textContent = prev; }
    }
  });

  document.querySelectorAll('.btnArchiveStaff').forEach((btn) => {
    btn.addEventListener('click', async () => {
      if (btn.disabled) return;
      const tr = btn.closest('tr');
      if (!tr) return;
      const uid = tr.dataset.userId || '';
      const role = (tr.dataset.role || 'teacher').toLowerCase();
      const name = [tr.dataset.lastName, tr.dataset.firstName].filter(Boolean).join(', ') || (tr.dataset.email || 'this account');
      const noun = role === 'admin' ? 'admin' : 'teacher';
      if (!window.confirm('Move ' + noun + ' ' + name + ' to Archive?\n\nThey will not be able to log in until restored.')) return;
      btn.disabled = true;
      try {
        const res = await fetch('/api/staff_user_archive.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({
            csrf_token: window.CSRF_TOKEN || '',
            user_id: uid,
            action: 'archive',
          }),
        });
        const data = await res.json().catch(() => ({}));
        if (!data.ok) throw new Error(data.error || 'Archive failed');
        showToast(data.message || 'Account moved to Archive.');
        tr.remove();
        if (typeof applyUserSearch === 'function') applyUserSearch();
      } catch (err) {
        showToast(err.message || 'Archive failed', true);
      } finally {
        btn.disabled = false;
      }
    });
  });

  formReg?.addEventListener('submit', async (e) => {
    e.preventDefault();
    regErr.classList.add('hidden');
    regErr.textContent = '';
    const fd = new FormData(formReg);
    const submitBtn = document.getElementById('btnSubmitRegisterTeacher');
    const prev = submitBtn.textContent;
    submitBtn.disabled = true;
    submitBtn.textContent = 'Creating…';
    try {
      const res = await fetch('/api/users_register_teacher.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          csrf_token: window.CSRF_TOKEN,
          first_name: fd.get('first_name'),
          middle_name: fd.get('middle_name'),
          last_name: fd.get('last_name'),
          suffix: fd.get('suffix'),
          contact_number: fd.get('contact_number'),
          email: fd.get('email'),
        }),
      });
      const data = await res.json();
      if (!data.ok) throw new Error(data.error || 'Registration failed');
      showToast('Teacher account created. Credentials were emailed.');
      closeModalReg();
      setTimeout(() => window.location.reload(), 400);
    } catch (err) {
      regErr.textContent = err.message || 'Failed';
      regErr.classList.remove('hidden');
    } finally {
      submitBtn.disabled = false;
      submitBtn.textContent = prev;
    }
  });

  formRegAdmin?.addEventListener('submit', async (e) => {
    e.preventDefault();
    if (regAdminErr) {
      regAdminErr.classList.add('hidden');
      regAdminErr.textContent = '';
    }
    const fd = new FormData(formRegAdmin);
    const submitBtn = document.getElementById('btnSubmitRegisterAdmin');
    const prev = submitBtn?.textContent || 'Create admin';
    if (submitBtn) { submitBtn.disabled = true; submitBtn.textContent = 'Creating…'; }
    try {
      const res = await fetch('/api/users_register_admin.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          csrf_token: window.CSRF_TOKEN,
          first_name: fd.get('first_name'),
          middle_name: fd.get('middle_name'),
          last_name: fd.get('last_name'),
          suffix: fd.get('suffix'),
          contact_number: fd.get('contact_number'),
          email: fd.get('email'),
        }),
      });
      const data = await res.json();
      if (!data.ok) throw new Error(data.error || 'Registration failed');
      showToast('Admin account created. Credentials were emailed.');
      closeModalRegAdmin();
      setTimeout(() => window.location.reload(), 400);
    } catch (err) {
      if (regAdminErr) {
        regAdminErr.textContent = err.message || 'Failed';
        regAdminErr.classList.remove('hidden');
      }
    } finally {
      if (submitBtn) { submitBtn.disabled = false; submitBtn.textContent = prev; }
    }
  });
  // --- Tabs Navigation JS ---
  const tabTeachers = document.getElementById('tabTeachers');
  const tabStudents = document.getElementById('tabStudents');
  const tabAdmins = document.getElementById('tabAdmins');
  
  const actionTeacher = document.getElementById('actionTeacher');
  const actionStudent = document.getElementById('actionStudent');
  const actionAdmin = document.getElementById('actionAdmin');
  const exportStudents = document.getElementById('exportStudents');
  const exportTeachers = document.getElementById('exportTeachers');
  
  const tableTeachers = document.getElementById('tableTeachers');
  const tableStudents = document.getElementById('tableStudents');
  const tableAdmins = document.getElementById('tableAdmins');
  
  const panelTitle = document.getElementById('panelTitle');
  const panelTotal = document.getElementById('panelTotal');
  const panelCsCount = document.getElementById('panelCsCount');
  const panelItCount = document.getElementById('panelItCount');
  const panelProgramCounts = document.getElementById('panelProgramCounts');
  const userSearch = document.getElementById('userSearch');
  const tbBadgeT = tabTeachers.querySelector('span');
  const tbBadgeS = tabStudents.querySelector('span');
  const tbBadgeA = tabAdmins.querySelector('span');

  function activeTable() {
    if (tableStudents && !tableStudents.classList.contains('hidden')) return tableStudents;
    if (tableTeachers && !tableTeachers.classList.contains('hidden')) return tableTeachers;
    if (tableAdmins && !tableAdmins.classList.contains('hidden')) return tableAdmins;
    return null;
  }

  function programBucket(program) {
    const p = String(program || '').toUpperCase().replace(/\s+/g, ' ').trim();
    if (p === 'BSCS' || p === 'CS') return 'cs';
    if (p !== '' && p !== '—' && (p.includes('IT') || p.startsWith('BSIT'))) return 'it';
    return '';
  }

  function applyUserSearch() {
    const raw = (userSearch?.value || '').trim().toLowerCase();
    const q = raw;
    const qDigits = raw.replace(/\D+/g, '');
    const tbl = activeTable();
    if (!tbl) return;
    let visible = 0;
    let csVisible = 0;
    let itVisible = 0;
    tbl.querySelectorAll('tbody tr').forEach((tr) => {
      if (tr.querySelector('td[colspan]')) {
        // empty-state row
        tr.classList.toggle('hidden', q !== '');
        return;
      }
      const hay = (tr.textContent || '').toLowerCase();
      const sno = (tr.dataset.studentNo || '').toLowerCase();
      const snoDigits = sno.replace(/\D+/g, '');
      const nameHay = [
        tr.dataset.lastName || '',
        tr.dataset.firstName || '',
        tr.dataset.middleName || '',
        tr.dataset.program || '',
        tr.dataset.block || '',
        tr.dataset.email || '',
      ].join(' ').toLowerCase();
      let show = q === '';
      if (!show) {
        show = hay.includes(q) || nameHay.includes(q) || sno.includes(q);
        if (!show && qDigits.length >= 3 && snoDigits.includes(qDigits)) {
          show = true;
        }
      }
      tr.classList.toggle('hidden', !show);
      if (show) {
        visible++;
        const bucket = programBucket(tr.dataset.program || '');
        if (bucket === 'cs') csVisible++;
        if (bucket === 'it') itVisible++;
      }
    });
    if (panelTotal) panelTotal.textContent = String(visible);
    if (panelCsCount) panelCsCount.textContent = String(csVisible);
    if (panelItCount) panelItCount.textContent = String(itVisible);
  }

  function resetTabs() {
      [tabTeachers, tabStudents, tabAdmins].forEach(t => {
          t.classList.replace('border-orange-500','border-transparent');
          t.classList.replace('text-orange-600','text-zinc-500');
          const b = t.querySelector('span');
          if (b) {
              b.classList.replace('bg-orange-100','bg-zinc-100');
              b.classList.replace('text-orange-700','text-zinc-600');
              b.classList.replace('border-orange-200','border-zinc-200');
          }
      });
      [tableTeachers, tableStudents, tableAdmins].forEach(tbl => tbl.classList.add('hidden'));
      actionTeacher.classList.add('hidden');
      actionStudent.classList.add('hidden');
      actionAdmin?.classList.add('hidden');
      exportStudents?.classList.add('hidden');
      exportTeachers?.classList.add('hidden');
  }

  if (tabTeachers && tabStudents && tabAdmins) {
      tabTeachers.addEventListener('click', () => {
          resetTabs();
          tabTeachers.classList.replace('border-transparent','border-orange-500');
          tabTeachers.classList.replace('text-zinc-500','text-orange-600');
          tbBadgeT.classList.replace('bg-zinc-100','bg-orange-100');
          tbBadgeT.classList.replace('text-zinc-600','text-orange-700');
          tbBadgeT.classList.replace('border-zinc-200','border-orange-200');
          
          actionTeacher.classList.remove('hidden');
          exportTeachers?.classList.remove('hidden');
          tableTeachers.classList.remove('hidden');
          panelProgramCounts?.classList.add('hidden');
          panelTitle.innerHTML = `<div class="w-8 h-8 rounded-xl bg-orange-100 border border-orange-200 flex items-center justify-center"><svg class="w-4 h-4 text-orange-700" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.501 20.118a7.5 7.5 0 0114.998 0A17.933 17.933 0 0112 21.75c-2.676 0-5.216-.584-7.499-1.632z"/></svg></div> Teacher Management`;
          if (userSearch) userSearch.placeholder = 'Search name, email, contact…';
          applyUserSearch();
      });
      
      tabStudents.addEventListener('click', () => {
          resetTabs();
          tabStudents.classList.replace('border-transparent','border-orange-500');
          tabStudents.classList.replace('text-zinc-500','text-orange-600');
          tbBadgeS.classList.replace('bg-zinc-100','bg-orange-100');
          tbBadgeS.classList.replace('text-zinc-600','text-orange-700');
          tbBadgeS.classList.replace('border-zinc-200','border-orange-200');
          
          actionStudent.classList.remove('hidden');
          exportStudents?.classList.remove('hidden');
          tableStudents.classList.remove('hidden');
          panelProgramCounts?.classList.remove('hidden');
          panelTitle.innerHTML = `<div class="w-8 h-8 rounded-xl bg-emerald-100 border border-emerald-200 flex items-center justify-center"><svg class="w-4 h-4 text-emerald-700" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M4.26 10.147a60.436 60.436 0 00-.491 6.347A48.627 48.627 0 0112 20.904a48.627 48.627 0 018.232-4.41 60.46 60.46 0 00-.491-6.347m-15.482 0a50.57 50.57 0 00-2.658-.813A59.905 59.905 0 0112 3.493a59.902 59.902 0 0110.399 5.84c-.896.248-1.783.52-2.658.814m-15.482 0A50.697 50.697 0 0112 13.489a50.702 50.702 0 017.74-3.342"/></svg></div> Student Management`;
          if (userSearch) userSearch.placeholder = 'Search student no, surname, first name, email…';
          applyUserSearch();
      });

      tabAdmins.addEventListener('click', () => {
          resetTabs();
          tabAdmins.classList.replace('border-transparent','border-orange-500');
          tabAdmins.classList.replace('text-zinc-500','text-orange-600');
          tbBadgeA.classList.replace('bg-zinc-100','bg-orange-100');
          tbBadgeA.classList.replace('text-zinc-600','text-orange-700');
          tbBadgeA.classList.replace('border-zinc-200','border-orange-200');
          
          tableAdmins.classList.remove('hidden');
          actionAdmin?.classList.remove('hidden');
          panelProgramCounts?.classList.add('hidden');
          panelTitle.innerHTML = `<div class="w-8 h-8 rounded-xl bg-orange-100 border border-orange-200 flex items-center justify-center shrink-0"><svg class="w-4 h-4 text-orange-700" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75m-3-7.036A11.959 11.959 0 013.598 6 11.99 11.99 0 003 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285z"/></svg></div> <span class="truncate">System Administrators</span>`;
          if (userSearch) userSearch.placeholder = 'Search name, email, contact…';
          applyUserSearch();
      });

      userSearch?.addEventListener('input', applyUserSearch);
      // Default: Students first
      tabStudents.click();
  }

  const roleSels = document.querySelectorAll('.roleSel');
  roleSels.forEach(s => {
      s.addEventListener('change', () => {
         // UI removed save role interaction since it's just visual mock per request
         showToast("Change is not yet saved to backend.", false);
      });
  });

  document.querySelectorAll('.btnSave').forEach(btn => {
    btn.addEventListener('click', async () => {
      const uid = btn.dataset.uid;
      const sel = document.querySelector('.roleSel[data-uid="' + uid + '"]');
      const role = sel.value;
      
      const originalText = btn.textContent;
      btn.textContent = '...';
      btn.disabled = true;
      try {
        const res = await fetch('/api/users_update_role.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ user_id: uid, role, csrf_token: window.CSRF_TOKEN })
        });
        const data = await res.json();
        if (!data.ok) throw new Error(data.error || 'Failed to update role');
        showToast('Role saved successfully.');
        
        // Slightly update the border color for visual feedback
        const row = btn.closest('tr');
        if(row) {
            row.style.backgroundColor = 'rgba(16, 185, 129, 0.1)';
            setTimeout(() => row.style.backgroundColor = '', 2000);
        }
      } catch (e) {
        showToast(e.message || 'Failed', true);
      } finally {
        btn.textContent = originalText;
        btn.disabled = false;
      }
    });
  });
</script>

<?php render_footer(); ?>
