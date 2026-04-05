<?php
declare(strict_types=1);

session_start();

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/supabase.php';
require_once __DIR__ . '/includes/layout.php';

$user = require_role(['admin']);

$sectionId = $_GET['id'] ?? '';
$sectionId = str_replace(' ', '-', trim($sectionId)); // Defensive fix against spaces replacing hyphens
$sectionName = $_GET['name'] ?? 'Section Student List';

if (empty($sectionId)) {
    header('Location: admin_sections.php');
    exit;
}

// Fetch students assigned to this section
$url = rtrim(SUPABASE_URL, '/') . '/rest/v1/users?select=id,first_name,last_name,email,student_id&role=eq.student&section_id=eq.' . urlencode($sectionId) . '&order=last_name.asc';
$headers = [
    'Accept: application/json',
    'apikey: ' . SUPABASE_KEY,
    'Authorization: Bearer ' . SUPABASE_KEY,
];

$students = [];
$res = supabase_request('GET', $url, $headers);
if ($res['ok']) {
    $decoded = json_decode((string) $res['body'], true);
    $students = is_array($decoded) ? $decoded : [];
}

render_header('Section: ' . htmlspecialchars($sectionName), $user);
?>

<div class="mb-6 flex items-center justify-between">
  <div>
    <h2 class="text-xl font-bold text-zinc-900 mb-1"><?= htmlspecialchars($sectionName) ?> Students</h2>
    <p class="text-sm text-zinc-500">Total Enrolled: <?= count($students) ?></p>
  </div>
  <a href="admin_sections.php" class="text-sm font-semibold text-zinc-600 hover:text-zinc-900 px-3 py-2 bg-zinc-100 hover:bg-zinc-200 rounded-lg transition-colors border border-zinc-200 inline-flex items-center gap-2">
    <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5L3 12m0 0l7.5-7.5M3 12h18"/></svg>
    Back
  </a>
</div>

<div class="mb-5 relative w-full sm:w-96">
    <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
        <svg class="w-5 h-5 text-zinc-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path></svg>
    </div>
    <input type="text" id="searchInput" class="block w-full pl-10 pr-4 py-2.5 bg-white border border-zinc-200 rounded-xl text-sm placeholder-zinc-400 focus:ring-2 focus:ring-orange-500/30 focus:border-orange-400 transition-all outline-none" placeholder="Search by name or email...">
</div>

<div class="bg-white rounded-2xl shadow-sm border border-zinc-200 overflow-hidden">
    <div class="overflow-x-auto">
      <table class="w-full text-left text-sm text-zinc-600 whitespace-nowrap">
        <thead class="bg-[#FAFAFA] text-zinc-800 font-semibold border-b border-zinc-200">
          <tr>
            <th class="px-6 py-4 rounded-tl-xl">Student Number</th>
            <th class="px-6 py-4">Name</th>
            <th class="px-6 py-4">Email</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-zinc-100">
          <?php if (count($students) > 0): ?>
            <?php foreach ($students as $row): ?>
              <tr class="hover:bg-zinc-50 transition-colors group">
                <td class="px-6 py-4">
                  <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-semibold bg-zinc-100 text-zinc-700">
                    <?= htmlspecialchars($row['student_id'] ?? 'N/A') ?>
                  </span>
                </td>
                <td class="px-6 py-4">
                  <div class="font-semibold text-zinc-900">
                    <?= htmlspecialchars(($row['last_name'] ?? '') . ', ' . ($row['first_name'] ?? '')) ?>
                  </div>
                </td>
                <td class="px-6 py-4 text-zinc-500">
                  <?= htmlspecialchars($row['email'] ?? 'No email') ?>
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
                  <p class="text-xs mt-1 text-zinc-400">Students assigned to this section will appear here.</p>
                </div>
              </td>
            </tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
</div>

<script>
document.getElementById('searchInput').addEventListener('input', function(e) {
    const term = e.target.value.toLowerCase();
    const rows = document.querySelectorAll('tbody tr.group');
    
    let visibleCount = 0;
    rows.forEach(row => {
        // Getting text from the row, avoiding picking up the empty row
        const text = row.textContent.toLowerCase();
        if (text.includes(term)) {
            row.style.display = '';
            visibleCount++;
        } else {
            row.style.display = 'none';
        }
    });

    // Handle empty state row if needed
    const emptyRow = document.getElementById('emptySearchRow');
    if (visibleCount === 0 && rows.length > 0) {
        if (!emptyRow) {
            const tbody = document.querySelector('tbody');
            const tr = document.createElement('tr');
            tr.id = 'emptySearchRow';
            tr.innerHTML = `<td colspan="3" class="px-6 py-8 text-center text-zinc-500 bg-white">No students match your search.</td>`;
            tbody.appendChild(tr);
        } else {
            emptyRow.style.display = '';
        }
    } else if (emptyRow) {
        emptyRow.style.display = 'none';
    }
});
</script>

<?php render_footer(); ?>
