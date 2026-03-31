<?php
declare(strict_types=1);

session_start();

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/supabase.php';
require_once __DIR__ . '/includes/layout.php';

$user = require_role(['admin']);

$url = rtrim(SUPABASE_URL, '/') . '/rest/v1/sections?select=id,name&order=name.asc';
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

render_header('Manage Sections', $user);
?>

<div class="mb-6 flex flex-col sm:flex-row sm:items-center justify-between gap-4">
  <div>
    <h2 class="text-xl font-bold text-zinc-900 mb-1">Sections Management</h2>
    <p class="text-zinc-600 text-sm">Add or remove sections for student registration.</p>
  </div>
  <button id="openModalBtn" class="flex items-center gap-2 rounded-xl bg-orange-600 text-white px-4 py-2.5 text-sm font-semibold hover:bg-orange-700 transition shadow-sm">
    <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/></svg>
    Add Section
  </button>
</div>

<!-- Sections Table Layout (Matches PDF Page 35) -->
<div class="bg-white rounded-2xl border border-zinc-200 shadow-sm overflow-hidden mb-10">
  <div class="overflow-x-auto">
    <table class="w-full text-left text-sm text-zinc-600">
      <thead class="bg-zinc-50 border-b border-zinc-200">
        <tr>
          <th scope="col" class="px-6 py-4 font-bold text-zinc-900 w-1/3">Year Level</th>
          <th scope="col" class="px-6 py-4 font-bold text-zinc-900 w-1/2">Sections</th>
          <th scope="col" class="px-6 py-4 font-bold text-zinc-900 text-right">Action</th>
        </tr>
      </thead>
      <tbody class="divide-y divide-zinc-200">
        <?php foreach ($sections as $s): ?>
          <?php 
            $sid = (string) ($s['id'] ?? ''); 
            $rawName = trim((string) ($s['name'] ?? ''));
            // Dummy logic to separate "Grade 7 - Newton" into "Grade 7" and "Newton"
            $parts = explode('-', $rawName, 2);
            $yearLevel = count($parts) === 2 ? trim($parts[0]) : 'N/A';
            $sectionName = count($parts) === 2 ? trim($parts[1]) : $rawName;
          ?>
          <tr class="hover:bg-zinc-50/80 transition-colors group">
            <td class="px-6 py-4 font-semibold text-zinc-900">
                <?= htmlspecialchars($yearLevel) ?>
            </td>
            <td class="px-6 py-4 font-medium text-zinc-700">
                <?= htmlspecialchars($sectionName) ?>
            </td>
            <td class="px-6 py-4 text-right">
              <div class="flex items-center justify-end gap-2">
                  <button class="p-2 rounded-xl text-sky-600 hover:text-sky-700 hover:bg-sky-50 transition-colors" title="Edit Section (Concept)">
                     <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L6.832 19.82a4.5 4.5 0 01-1.897 1.13l-2.685.8.8-2.685a4.5 4.5 0 011.13-1.897L16.863 4.487zm0 0L19.5 7.125"/></svg>
                  </button>
                  <button class="btnDelete p-2 rounded-xl text-red-500 hover:text-red-700 hover:bg-red-50 transition-colors" data-id="<?= htmlspecialchars($sid) ?>" title="Drop Section">
                     <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 01-2.244 2.077H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 00-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 013.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 00-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 00-7.5 0"/></svg>
                  </button>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
        
        <?php if (count($sections) === 0): ?>
          <tr>
            <td colspan="3" class="px-6 py-16 text-center">
              <div class="flex flex-col items-center justify-center pointer-events-none">
                <div class="w-14 h-14 rounded-full bg-zinc-100 flex items-center justify-center mb-4 border border-zinc-200 shadow-sm">
                  <svg class="w-6 h-6 text-zinc-400" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6A2.25 2.25 0 016 3.75h2.25A2.25 2.25 0 0110.5 6v2.25a2.25 2.25 0 01-2.25 2.25H6a2.25 2.25 0 01-2.25-2.25V6zM3.75 15.75A2.25 2.25 0 016 13.5h2.25a2.25 2.25 0 012.25 2.25V18a2.25 2.25 0 01-2.25 2.25H6A2.25 2.25 0 013.75 18v-2.25zM13.5 6a2.25 2.25 0 012.25-2.25H18A2.25 2.25 0 0120.25 6v2.25A2.25 2.25 0 0118 10.5h-2.25a2.25 2.25 0 01-2.25-2.25V6zM13.5 15.75a2.25 2.25 0 012.25-2.25H18a2.25 2.25 0 012.25 2.25V18A2.25 2.25 0 0118 20.25h-2.25A2.25 2.25 0 0113.5 18v-2.25z"/></svg>
                </div>
                <p class="text-zinc-800 font-semibold text-base mb-1">No sections found</p>
                <p class="text-zinc-500 text-sm">Click "Add Section" to create your first class section.</p>
              </div>
            </td>
          </tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Add Section Modal Overlay -->
<div id="sectionModal" class="fixed inset-0 z-[100] flex items-center justify-center pointer-events-none opacity-0 transition-opacity duration-300">
  <div class="absolute inset-0 bg-black/60 backdrop-blur-sm" id="modalBackdrop"></div>
  
  <div class="relative w-full max-w-md mx-4 bg-white border border-zinc-200 rounded-3xl shadow-xl scale-95 transition-transform duration-300" id="modalContent">
    <div class="p-6 sm:p-8">
      <div class="flex items-center gap-4 mb-6">
        <div class="w-12 h-12 rounded-xl bg-orange-100 border border-orange-200 flex items-center justify-center flex-shrink-0">
          <svg class="w-6 h-6 text-orange-700" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/></svg>
        </div>
        <h3 class="text-xl font-bold text-zinc-900 tracking-tight">Add Section</h3>
      </div>
      
      <form id="sectionForm">
        <div class="mb-6">
          <label class="block text-[13px] font-semibold text-zinc-700 mb-2" for="name">Section Name</label>
          <input id="name" name="name" required class="w-full rounded-xl bg-white border border-zinc-200 px-4 py-3.5 text-base text-zinc-900 placeholder-zinc-400 outline-none focus:ring-2 focus:ring-orange-500/30 focus:border-orange-400 transition" placeholder="e.g. BSIT 1A" autocomplete="off" />
        </div>

        <button type="submit" id="btnSubmit" class="w-full rounded-xl bg-orange-600 text-white px-4 py-3.5 text-[15px] font-bold hover:bg-orange-700 transition-colors shadow-sm">
          Save Section
        </button>
        <div id="formMsg" class="text-sm font-medium text-center mt-3 empty:hidden"></div>
      </form>
    </div>
    
    <button id="closeModalBtn" class="absolute top-5 right-5 p-2 rounded-full text-zinc-500 hover:text-zinc-900 hover:bg-zinc-100 transition-colors focus:outline-none">
      <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
    </button>
  </div>
</div>

<script>
  const modal = document.getElementById('sectionModal');
  const modalContent = document.getElementById('modalContent');
  const openBtn = document.getElementById('openModalBtn');
  const closeBtn = document.getElementById('closeModalBtn');
  const backdrop = document.getElementById('modalBackdrop');
  const sectionInput = document.getElementById('name');
  
  function openModal() {
    modal.classList.remove('pointer-events-none', 'opacity-0');
    modalContent.classList.remove('scale-95');
    modalContent.classList.add('scale-100');
    // Ensure timeout so the transition finishes before focus
    setTimeout(() => sectionInput.focus(), 100);
  }
  
  function closeModal() {
    modal.classList.add('pointer-events-none', 'opacity-0');
    modalContent.classList.remove('scale-100');
    modalContent.classList.add('scale-95');
    setTimeout(() => {
      document.getElementById('sectionForm').reset();
      document.getElementById('formMsg').textContent = '';
    }, 300);
  }
  
  openBtn.addEventListener('click', openModal);
  closeBtn.addEventListener('click', closeModal);
  backdrop.addEventListener('click', closeModal);
  
  // Also close modal on Esc key
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && !modal.classList.contains('opacity-0')) {
      closeModal();
    }
  });

  document.getElementById('sectionForm').addEventListener('submit', async (e) => {
    e.preventDefault();
    const btn = document.getElementById('btnSubmit');
    const msg = document.getElementById('formMsg');
    const name = sectionInput.value.trim();
    
    if(!name) return;
    
    btn.disabled = true;
    btn.textContent = 'Saving...';
    btn.classList.add('opacity-70', 'cursor-not-allowed');
    msg.className = 'text-sm font-medium text-center mt-3 text-zinc-400';
    
    try {
      const res = await fetch('/api/sections_manage.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ name, action: 'create', csrf_token: window.CSRF_TOKEN })
      });
      const data = await res.json();
      if (!data.ok) throw new Error(data.error || 'Failed to save section');
      
      msg.textContent = 'Section saved!';
      msg.className = 'text-sm font-bold text-center mt-3 text-emerald-400';
      btn.textContent = 'Success';
      btn.classList.remove('bg-[#8b5cf6]', 'hover:bg-[#7c3aed]');
      btn.classList.add('bg-emerald-500');
      
      setTimeout(() => window.location.reload(), 600);
    } catch (err) {
      msg.textContent = err.message || 'Failed';
      msg.className = 'text-sm font-bold text-center mt-3 text-red-500';
      btn.disabled = false;
      btn.textContent = 'Save Section';
      btn.classList.remove('opacity-70', 'cursor-not-allowed');
    }
  });

  document.querySelectorAll('.btnDelete').forEach(btn => {
    btn.addEventListener('click', async () => {
      if(!confirm('Are you sure you want to delete this section?')) return;
      const id = btn.dataset.id;
      btn.disabled = true;
      btn.textContent = '...';
      try {
        const res = await fetch('/api/sections_manage.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ id, action: 'delete', csrf_token: window.CSRF_TOKEN })
        });
        const data = await res.json();
        if (!data.ok) throw new Error(data.error || 'Failed to delete section');
        
        // Remove from DOM beautifully
        const row = btn.closest('tr');
        row.style.transition = 'opacity 0.3s, transform 0.3s';
        row.style.transform = 'translateY(10px)';
        row.style.opacity = '0';
        setTimeout(() => window.location.reload(), 300);
        
      } catch (err) {
        alert(err.message || 'Failed');
        btn.textContent = 'Delete';
        btn.disabled = false;
      }
    });
  });
</script>

<?php render_footer(); ?>
