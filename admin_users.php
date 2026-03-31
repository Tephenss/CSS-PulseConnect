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
    . '?select=id,first_name,middle_name,last_name,suffix,email,role,section_id,created_at'
    . '&order=created_at.desc'
    . '&limit=500';

$res = supabase_request('GET', $url, $headers);
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

render_header('Users & Roles', $user);
?>

<div class="mb-8 flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
  <div class="min-w-0">
    <h2 class="text-xl font-bold text-zinc-900 mb-1">Users & Roles</h2>
    <p class="text-zinc-600 text-sm">Manage accounts, assign privileges, and oversee the system's members.</p>
  </div>
  <div class="flex items-center gap-3 shrink-0 self-end sm:self-start relative z-40">
      
      <!-- Teacher Actions Dropdown -->
      <div class="relative group" id="actionTeacher">
        <button type="button" class="inline-flex items-center justify-center gap-2 rounded-xl bg-orange-600 text-white px-5 py-2.5 text-[13px] font-bold shadow-sm hover:bg-orange-700 transition-colors border border-orange-600">
          <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/></svg>
          Add Teacher
          <svg class="w-3.5 h-3.5 ml-1 opacity-70 cursor-pointer" fill="none" stroke="currentColor" stroke-width="3" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5"/></svg>
        </button>
        <div class="absolute right-0 top-full mt-2 w-48 rounded-xl bg-white border border-zinc-200 shadow-[0_10px_40px_-10px_rgba(0,0,0,0.15)] opacity-0 invisible group-hover:opacity-100 group-hover:visible transition-all overflow-hidden transform origin-top-right group-hover:translate-y-0 translate-y-1">
          <button type="button" id="btnOpenRegisterTeacher" class="w-full flex items-center gap-2 px-4 py-3 text-[13px] font-bold text-zinc-700 hover:bg-orange-50 hover:text-orange-700 border-b border-zinc-100 transition-colors">
              <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931zm0 0L19.5 7.125M18 14v4.75A2.25 2.25 0 0115.75 21H5.25A2.25 2.25 0 013 18.75V8.25A2.25 2.25 0 015.25 6H10"/></svg>
              Add Manually
          </button>
          <button type="button" onclick="alert('File Parsing Backend Required: Feature Coming Soon!')" class="w-full flex items-center gap-2 px-4 py-3 text-[13px] font-bold text-zinc-700 hover:bg-orange-50 hover:text-orange-700 transition-colors">
              <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m3.75 9v6m3-3H9m1.5-12H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z"/></svg>
              Import Teachers
          </button>
        </div>
      </div>
      
      <!-- Student Actions Dropdown -->
      <div class="relative group hidden" id="actionStudent">
        <button type="button" class="inline-flex items-center justify-center gap-2 rounded-xl bg-emerald-600 text-white px-5 py-2.5 text-[13px] font-bold shadow-sm hover:bg-emerald-700 transition-colors border border-emerald-600">
          <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/></svg>
          Add Student
          <svg class="w-3.5 h-3.5 ml-1 opacity-70 cursor-pointer" fill="none" stroke="currentColor" stroke-width="3" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5"/></svg>
        </button>
        <div class="absolute right-0 top-full mt-2 w-48 rounded-xl bg-white border border-zinc-200 shadow-[0_10px_40px_-10px_rgba(0,0,0,0.15)] opacity-0 invisible group-hover:opacity-100 group-hover:visible transition-all overflow-hidden transform origin-top-right group-hover:translate-y-0 translate-y-1">
          <button type="button" class="w-full flex items-center gap-2 px-4 py-3 text-[13px] font-bold text-zinc-700 hover:bg-emerald-50 hover:text-emerald-700 border-b border-zinc-100 transition-colors">
              <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 002.625.372 9.337 9.337 0 004.121-.952 4.125 4.125 0 00-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 018.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0111.964-3.07M12 6.375a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zm8.25 2.25a2.625 2.625 0 11-5.25 0 2.625 2.625 0 015.25 0z"/></svg>
              Add Manually
          </button>
          <button type="button" onclick="alert('File Parsing Backend Required: Feature Coming Soon!')" class="w-full flex items-center gap-2 px-4 py-3 text-[13px] font-bold text-zinc-700 hover:bg-emerald-50 hover:text-emerald-700 transition-colors">
              <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m3.75 9v6m3-3H9m1.5-12H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z"/></svg>
              Import Students
          </button>
        </div>
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
?>

<div class="grid grid-cols-1 sm:grid-cols-3 gap-5 mb-8">
  <div class="rounded-2xl border border-zinc-200 bg-white p-5 border-b-[3px] border-b-emerald-500 shadow-sm flex items-center gap-4 relative overflow-hidden group hover:-translate-y-0.5 transition-transform">
     <div class="absolute -right-8 -top-8 w-24 h-24 bg-emerald-400/10 blur-2xl rounded-full pointer-events-none"></div>
     <div class="w-12 h-12 rounded-xl bg-emerald-100 border border-emerald-200 flex items-center justify-center text-emerald-700 z-10 flex-shrink-0">
        <svg class="w-6 h-6" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M4.26 10.147a60.436 60.436 0 00-.491 6.347A48.627 48.627 0 0112 20.904a48.627 48.627 0 018.232-4.41 60.46 60.46 0 00-.491-6.347m-15.482 0a50.57 50.57 0 00-2.658-.813A59.905 59.905 0 0112 3.493a59.902 59.902 0 0110.399 5.84c-.896.248-1.783.52-2.658.814m-15.482 0A50.697 50.697 0 0112 13.489a50.702 50.702 0 017.74-3.342"/></svg>
     </div>
     <div class="z-10 min-w-0">
        <div class="text-3xl font-bold text-zinc-900"><?= $studentCount ?></div>
        <div class="text-[11px] text-zinc-600 uppercase tracking-widest font-bold truncate">Students</div>
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

<!-- Top Nav Tabs (Pages 37 & 38) -->
<div class="flex border-b border-zinc-200 mb-6 gap-6 mt-2 relative z-10 w-full overflow-x-auto">
    <button id="tabTeachers" class="pb-3 border-b-2 border-orange-500 font-bold text-orange-600 text-sm transition-colors whitespace-nowrap px-1 group flex items-center gap-2">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M4.26 10.147a60.436 60.436 0 00-.491 6.347A48.627 48.627 0 0112 20.904a48.627 48.627 0 018.232-4.41 60.46 60.46 0 00-.491-6.347m-15.482 0a50.57 50.57 0 00-2.658-.813A59.905 59.905 0 0112 3.493a59.902 59.902 0 0110.399 5.84c-.896.248-1.783.52-2.658.814m-15.482 0A50.697 50.697 0 0112 13.489a50.702 50.702 0 017.74-3.342"/></svg>
        All Teachers
        <span class="bg-orange-100 text-orange-700 text-[10px] font-black px-2 py-0.5 rounded-full border border-orange-200 group-hover:bg-orange-200 transition-colors"><?= $teacherCount ?></span>
    </button>
    <button id="tabStudents" class="pb-3 border-b-2 border-transparent font-bold text-zinc-500 hover:text-zinc-800 text-sm transition-colors whitespace-nowrap px-1 group flex items-center gap-2">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 002.625.372 9.337 9.337 0 004.121-.952 4.125 4.125 0 00-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 018.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0111.964-3.07M12 6.375a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zm8.25 2.25a2.625 2.625 0 11-5.25 0 2.625 2.625 0 015.25 0z"/></svg>
        All Students
        <span class="bg-zinc-100 text-zinc-600 border border-zinc-200 text-[10px] font-black px-2 py-0.5 rounded-full group-hover:bg-zinc-200 transition-colors"><?= $studentCount ?></span>
    </button>
</div>

<div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-3">
  <h3 id="panelTitle" class="text-base font-bold text-zinc-900 tracking-tight flex items-center gap-2">
     <div class="w-8 h-8 rounded-xl bg-indigo-100 border border-indigo-200 flex items-center justify-center">
       <svg class="w-4 h-4 text-indigo-700" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 002.625.372 9.337 9.337 0 004.121-.952 4.125 4.125 0 00-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 018.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0111.964-3.07M12 6.375a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zm8.25 2.25a2.625 2.625 0 11-5.25 0 2.625 2.625 0 015.25 0z"/></svg>
     </div>
     User Directory
  </h3>
  <div class="px-3.5 py-1.5 rounded-xl bg-zinc-100 border border-zinc-200 flex items-center gap-2 shrink-0 self-start sm:self-auto">
     <span class="text-[11px] font-bold text-zinc-600 uppercase tracking-wider">Total</span>
     <span class="text-base font-bold text-zinc-900 leading-none"><?= count($users) ?></span>
  </div>
</div>

<div class="pb-10 relative">
  <!-- Notifications Overlay -->
  <div id="msg" class="fixed bottom-6 inset-x-0 mx-auto w-max z-50 px-5 py-3 rounded-xl shadow-2xl transition-all duration-300 transform translate-y-20 opacity-0 pointer-events-none font-bold text-sm"></div>

<!-- Users Table Layout (Matches PDF Page 36 & 37) -->
  <div id="tableTeachers" class="bg-white rounded-2xl border border-zinc-200 shadow-sm overflow-hidden min-h-[400px]">
    <div class="overflow-x-auto">
      <table class="w-full text-left text-sm text-zinc-600">
        <thead class="bg-zinc-50 border-b border-zinc-200">
          <tr>
            <th scope="col" class="px-6 py-4 font-bold text-zinc-900">Name</th>
            <th scope="col" class="px-6 py-4 font-bold text-zinc-900">Email</th>
            <th scope="col" class="px-6 py-4 font-bold text-zinc-900">Contact No.</th>
            <th scope="col" class="px-6 py-4 font-bold text-zinc-900">Grade Level</th>
            <th scope="col" class="px-6 py-4 font-bold text-zinc-900">Section</th>
            <th scope="col" class="px-6 py-4 font-bold text-zinc-900 text-right">Actions</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-zinc-200">
          <?php foreach ($users as $u): if (($u['role'] ?? '') === 'student') continue; ?>
            <?php
              $nameParts = [];
              foreach (['first_name','middle_name','last_name'] as $k) {
                $v = trim((string) ($u[$k] ?? ''));
                if ($v !== '') $nameParts[] = $v;
              }
              $name = implode(' ', $nameParts);
              $suffix = trim((string) ($u['suffix'] ?? ''));
              if ($suffix !== '') $name .= ', ' . $suffix;
              $uid = (string) ($u['id'] ?? '');
              
              $secId = (string)($u['section_id'] ?? ''); 
              $secNameRaw = $sectionMap[$secId] ?? 'No Section';
              
              $gradeLvl = "N/A";
              $parsedSec = "N/A";
              if ($secNameRaw !== 'No Section') {
                  $parts = explode('-', $secNameRaw, 2);
                  if (count($parts) === 2) {
                      $gradeLvl = trim($parts[0]);
                      $parsedSec = trim($parts[1]);
                  } else {
                      $gradeLvl = $secNameRaw;
                      $parsedSec = "-";
                  }
              }
            ?>
            <tr class="hover:bg-zinc-50/80 transition-colors group user-row">
              <td class="px-6 py-4">
                  <div class="font-bold text-zinc-900 truncate max-w-[200px]" title="<?= htmlspecialchars($name) ?>"><?= htmlspecialchars($name) ?></div>
              </td>
              <td class="px-6 py-4 font-medium text-zinc-500">
                  <div class="truncate max-w-[200px]" title="<?= htmlspecialchars((string)($u['email'] ?? '')) ?>"><?= htmlspecialchars((string)($u['email'] ?? '')) ?></div>
              </td>
              <td class="px-6 py-4 font-medium text-zinc-500">
                  N/A
              </td>
              <td class="px-6 py-4 font-bold text-zinc-800"><?= htmlspecialchars($gradeLvl) ?></td>
              <td class="px-6 py-4 font-bold text-zinc-800"><?= htmlspecialchars($parsedSec) ?></td>
              <td class="px-6 py-4 text-right">
                <div class="flex items-center justify-end gap-1.5 opacity-0 group-hover:opacity-100 transition-opacity">
                    <!-- Visual mock for archive/edit from Page 36 -->
                    <button class="p-2 rounded-xl text-zinc-400 hover:text-sky-600 hover:bg-sky-50 transition-colors border border-transparent hover:border-sky-200" title="Edit Teacher">
                       <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L6.832 19.82a4.5 4.5 0 01-1.897 1.13l-2.685.8.8-2.685a4.5 4.5 0 011.13-1.897L16.863 4.487zm0 0L19.5 7.125"/></svg>
                    </button>
                    <button class="p-2 rounded-xl text-zinc-400 hover:text-red-500 hover:bg-red-50 transition-colors border border-transparent hover:border-red-200" title="Archive User">
                       <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M20.25 7.5l-.625 10.632a2.25 2.25 0 01-2.247 2.118H6.622a2.25 2.25 0 01-2.247-2.118L3.75 7.5M10 11.25h4M3.375 7.5h17.25c.621 0 1.125-.504 1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125H3.375c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125z"/></svg>
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

  <!-- Students Table Layout (Matches PDF Page 38) -->
  <div id="tableStudents" class="bg-white rounded-2xl border border-zinc-200 shadow-sm overflow-hidden min-h-[400px] hidden">
    <div class="overflow-x-auto">
      <table class="w-full text-left text-sm text-zinc-600">
        <thead class="bg-zinc-50 border-b border-zinc-200">
          <tr>
            <th scope="col" class="px-6 py-4 font-bold text-zinc-900 w-1/4">Name</th>
            <th scope="col" class="px-6 py-4 font-bold text-zinc-900 w-1/4">Email</th>
            <th scope="col" class="px-4 py-4 font-bold text-zinc-900">Year Level</th>
            <th scope="col" class="px-4 py-4 font-bold text-zinc-900">Section</th>
            <th scope="col" class="px-4 py-4 font-bold text-zinc-900 text-center">ID Number</th>
            <th scope="col" class="px-6 py-4 font-bold text-zinc-900 text-right">Action</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-zinc-200">
          <?php foreach ($users as $u): if (($u['role'] ?? '') !== 'student') continue; ?>
            <?php
              $nameParts = [];
              foreach (['first_name','middle_name','last_name'] as $k) {
                $v = trim((string) ($u[$k] ?? ''));
                if ($v !== '') $nameParts[] = $v;
              }
              $name = implode(' ', $nameParts);
              $suffix = trim((string) ($u['suffix'] ?? ''));
              if ($suffix !== '') $name .= ', ' . $suffix;
              
              // ID Number from UUID snippet
              $uid = (string) ($u['id'] ?? '');
              $idNumber = "N/A";
              if (strlen($uid) > 8) {
                  $idNumber = "2026-" . strtoupper(substr($uid, 0, 4));
              }
              
              $secId = (string)($u['section_id'] ?? ''); 
              $secNameRaw = $sectionMap[$secId] ?? 'No Section';
              
              $gradeLvl = "N/A";
              $parsedSec = "N/A";
              if ($secNameRaw !== 'No Section') {
                  $parts = explode('-', $secNameRaw, 2);
                  if (count($parts) === 2) {
                      $gradeLvl = trim($parts[0]);
                      $parsedSec = trim($parts[1]);
                  } else {
                      $gradeLvl = $secNameRaw;
                      $parsedSec = "-";
                  }
              }
            ?>
            <tr class="hover:bg-zinc-50/80 transition-colors group user-row">
              <td class="px-6 py-4">
                  <div class="font-bold text-zinc-900 truncate" title="<?= htmlspecialchars($name) ?>"><?= htmlspecialchars($name) ?></div>
              </td>
              <td class="px-6 py-4 font-medium text-zinc-500">
                  <div class="truncate" title="<?= htmlspecialchars((string)($u['email'] ?? '')) ?>"><?= htmlspecialchars((string)($u['email'] ?? '')) ?></div>
              </td>
              <td class="px-4 py-4 font-bold text-zinc-800"><?= htmlspecialchars($gradeLvl) ?></td>
              <td class="px-4 py-4 font-bold text-zinc-800"><?= htmlspecialchars($parsedSec) ?></td>
              <td class="px-4 py-4 font-bold text-emerald-600 text-center tracking-wider font-mono text-xs"><?= htmlspecialchars($idNumber) ?></td>
              <td class="px-6 py-4 text-right">
                <div class="flex items-center justify-end gap-1.5 opacity-0 group-hover:opacity-100 transition-opacity">
                    <button class="p-2 rounded-xl text-zinc-400 hover:text-sky-600 hover:bg-sky-50 transition-colors border border-transparent hover:border-sky-200" title="Edit Student">
                       <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L6.832 19.82a4.5 4.5 0 01-1.897 1.13l-2.685.8.8-2.685a4.5 4.5 0 011.13-1.897L16.863 4.487zm0 0L19.5 7.125"/></svg>
                    </button>
                    <button class="p-2 rounded-xl text-zinc-400 hover:text-red-500 hover:bg-red-50 transition-colors border border-transparent hover:border-red-200" title="Archive User">
                       <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M20.25 7.5l-.625 10.632a2.25 2.25 0 01-2.247 2.118H6.622a2.25 2.25 0 01-2.247-2.118L3.75 7.5M10 11.25h4M3.375 7.5h17.25c.621 0 1.125-.504 1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125H3.375c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125z"/></svg>
                    </button>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php if ($studentCount === 0 || count($users) === 0): ?>
            <tr><td colspan="6" class="px-6 py-16 text-center text-zinc-500">No students found.</td></tr>
          <?php endif; ?>
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
      <p class="text-sm text-zinc-600">Creates a new account with role <span class="font-semibold text-sky-800">Teacher</span>. They can log in with the email and password you set.</p>
      <div id="regTeacherErr" class="hidden rounded-xl border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-800"></div>
      <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
        <div class="sm:col-span-1">
          <label class="block text-xs font-semibold text-zinc-600 mb-1" for="rt_first_name">First name</label>
          <input id="rt_first_name" name="first_name" type="text" required autocomplete="given-name" class="w-full rounded-xl border border-zinc-300 bg-white px-3 py-2.5 text-sm text-zinc-900 outline-none focus:ring-2 focus:ring-sky-500/40 focus:border-sky-400" />
        </div>
        <div class="sm:col-span-1">
          <label class="block text-xs font-semibold text-zinc-600 mb-1" for="rt_middle_name">Middle name <span class="text-zinc-400 font-normal">(optional)</span></label>
          <input id="rt_middle_name" name="middle_name" type="text" autocomplete="additional-name" class="w-full rounded-xl border border-zinc-300 bg-white px-3 py-2.5 text-sm text-zinc-900 outline-none focus:ring-2 focus:ring-sky-500/40 focus:border-sky-400" />
        </div>
        <div class="sm:col-span-1">
          <label class="block text-xs font-semibold text-zinc-600 mb-1" for="rt_last_name">Last name</label>
          <input id="rt_last_name" name="last_name" type="text" required autocomplete="family-name" class="w-full rounded-xl border border-zinc-300 bg-white px-3 py-2.5 text-sm text-zinc-900 outline-none focus:ring-2 focus:ring-sky-500/40 focus:border-sky-400" />
        </div>
        <div class="sm:col-span-1">
          <label class="block text-xs font-semibold text-zinc-600 mb-1" for="rt_suffix">Suffix <span class="text-zinc-400 font-normal">(optional)</span></label>
          <input id="rt_suffix" name="suffix" type="text" autocomplete="honorific-suffix" class="w-full rounded-xl border border-zinc-300 bg-white px-3 py-2.5 text-sm text-zinc-900 outline-none focus:ring-2 focus:ring-sky-500/40 focus:border-sky-400" placeholder="Jr., III" />
        </div>
      </div>
      <div>
        <label class="block text-xs font-semibold text-zinc-600 mb-1" for="rt_email">Email</label>
        <input id="rt_email" name="email" type="email" required autocomplete="email" class="w-full rounded-xl border border-zinc-300 bg-white px-3 py-2.5 text-sm text-zinc-900 outline-none focus:ring-2 focus:ring-sky-500/40 focus:border-sky-400" />
      </div>
      <div>
        <label class="block text-xs font-semibold text-zinc-600 mb-1" for="rt_password">Password</label>
        <input id="rt_password" name="password" type="password" required minlength="8" autocomplete="new-password" class="w-full rounded-xl border border-zinc-300 bg-white px-3 py-2.5 text-sm text-zinc-900 outline-none focus:ring-2 focus:ring-sky-500/40 focus:border-sky-400" />
        <p class="text-[11px] text-zinc-500 mt-1">At least 8 characters.</p>
      </div>
      <div>
        <label class="block text-xs font-semibold text-zinc-600 mb-1" for="rt_password2">Confirm password</label>
        <input id="rt_password2" name="password2" type="password" required minlength="8" autocomplete="new-password" class="w-full rounded-xl border border-zinc-300 bg-white px-3 py-2.5 text-sm text-zinc-900 outline-none focus:ring-2 focus:ring-sky-500/40 focus:border-sky-400" />
      </div>
      <div class="flex flex-col-reverse sm:flex-row gap-2 sm:justify-end pt-2">
        <button type="button" id="btnCancelRegisterTeacher" class="rounded-xl border border-zinc-300 bg-white px-4 py-2.5 text-sm font-bold text-zinc-700 hover:bg-zinc-50">Cancel</button>
        <button type="submit" id="btnSubmitRegisterTeacher" class="rounded-xl bg-sky-600 text-white px-4 py-2.5 text-sm font-bold hover:bg-sky-700 border border-sky-600">Create teacher</button>
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
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && modalReg && !modalReg.classList.contains('hidden')) closeModalReg();
  });

  formReg?.addEventListener('submit', async (e) => {
    e.preventDefault();
    regErr.classList.add('hidden');
    regErr.textContent = '';
    const fd = new FormData(formReg);
    const pw = (fd.get('password') || '').toString();
    const pw2 = (fd.get('password2') || '').toString();
    if (pw !== pw2) {
      regErr.textContent = 'Passwords do not match.';
      regErr.classList.remove('hidden');
      return;
    }
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
          email: fd.get('email'),
          password: pw,
        }),
      });
      const data = await res.json();
      if (!data.ok) throw new Error(data.error || 'Registration failed');
      showToast('Teacher account created.');
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
  // --- Tabs Navigation JS (Pages 37 & 38) ---
  const tabTeachers = document.getElementById('tabTeachers');
  const tabStudents = document.getElementById('tabStudents');
  const actionTeacher = document.getElementById('actionTeacher');
  const actionStudent = document.getElementById('actionStudent');
  const tableTeachers = document.getElementById('tableTeachers');
  const tableStudents = document.getElementById('tableStudents');
  const panelTitle = document.getElementById('panelTitle');
  const tbBadgeT = tabTeachers.querySelector('span');
  const tbBadgeS = tabStudents.querySelector('span');

  if (tabTeachers && tabStudents) {
      tabTeachers.addEventListener('click', () => {
          tabTeachers.classList.replace('border-transparent','border-orange-500');
          tabTeachers.classList.replace('text-zinc-500','text-orange-600');
          tbBadgeT.classList.replace('bg-zinc-100','bg-orange-100');
          tbBadgeT.classList.replace('text-zinc-600','text-orange-700');
          
          tabStudents.classList.replace('border-orange-500','border-transparent');
          tabStudents.classList.replace('text-orange-600','text-zinc-500');
          tbBadgeS.classList.replace('bg-orange-100','bg-zinc-100');
          tbBadgeS.classList.replace('text-orange-700','text-zinc-600');
          
          actionTeacher.classList.remove('hidden');
          actionStudent.classList.add('hidden');
          tableTeachers.classList.remove('hidden');
          tableStudents.classList.add('hidden');
          panelTitle.innerHTML = `<div class="w-8 h-8 rounded-xl bg-orange-100 border border-orange-200 flex items-center justify-center"><svg class="w-4 h-4 text-orange-700" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 002.625.372 9.337 9.337 0 004.121-.952 4.125 4.125 0 00-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 018.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0111.964-3.07M12 6.375a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zm8.25 2.25a2.625 2.625 0 11-5.25 0 2.625 2.625 0 015.25 0z"/></svg></div> Teacher Management`;
      });
      
      tabStudents.addEventListener('click', () => {
          tabStudents.classList.replace('border-transparent','border-orange-500');
          tabStudents.classList.replace('text-zinc-500','text-orange-600');
          tbBadgeS.classList.replace('bg-zinc-100','bg-orange-100');
          tbBadgeS.classList.replace('text-zinc-600','text-orange-700');
          
          tabTeachers.classList.replace('border-orange-500','border-transparent');
          tabTeachers.classList.replace('text-orange-600','text-zinc-500');
          tbBadgeT.classList.replace('bg-orange-100','bg-zinc-100');
          tbBadgeT.classList.replace('text-orange-700','text-zinc-600');
          
          actionStudent.classList.remove('hidden');
          actionTeacher.classList.add('hidden');
          tableStudents.classList.remove('hidden');
          tableTeachers.classList.add('hidden');
          panelTitle.innerHTML = `<div class="w-8 h-8 rounded-xl bg-emerald-100 border border-emerald-200 flex items-center justify-center"><svg class="w-4 h-4 text-emerald-700" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M4.26 10.147a60.436 60.436 0 00-.491 6.347A48.627 48.627 0 0112 20.904a48.627 48.627 0 018.232-4.41 60.46 60.46 0 00-.491-6.347m-15.482 0a50.57 50.57 0 00-2.658-.813A59.905 59.905 0 0112 3.493a59.902 59.902 0 0110.399 5.84c-.896.248-1.783.52-2.658.814m-15.482 0A50.697 50.697 0 0112 13.489a50.702 50.702 0 017.74-3.342"/></svg></div> Student Management`;
      });
      // trigger init
      tabTeachers.click();
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
