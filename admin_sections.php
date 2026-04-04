<?php
declare(strict_types=1);

session_start();

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/supabase.php';
require_once __DIR__ . '/includes/layout.php';

$user = require_role(['admin']);

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

render_header('Manage Sections', $user);
?>

<div class="mb-6 flex flex-col sm:flex-row sm:items-center justify-between gap-4">
  <div>
    <h2 class="text-xl font-bold text-zinc-900 mb-1">Sections Management</h2>
    <p class="text-zinc-600 text-sm">Add or remove sections for student registration.</p>
  </div>
  <!-- Buttons -->
  <div class="flex gap-3">
    <button id="resetSectionsBtn" class="flex items-center gap-2 rounded-xl bg-zinc-100 text-zinc-700 px-4 py-2.5 text-sm font-semibold hover:bg-red-50 hover:text-red-600 transition shadow-sm border border-zinc-200 hover:border-red-200">
      <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0l3.181 3.183a8.25 8.25 0 0013.803-3.7M4.031 9.865a8.25 8.25 0 0113.803-3.7l3.181 3.182m0-4.991v4.99"/></svg>
      Global Reset Flow
    </button>
    <button id="openModalBtn" class="flex items-center gap-2 rounded-xl bg-orange-600 text-white px-4 py-2.5 text-sm font-semibold hover:bg-orange-700 transition shadow-sm">
      <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/></svg>
      Add Section
    </button>
  </div>
</div>

<!-- Sections Grid Layout -->
<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-5 mb-10">
  <?php foreach ($sections as $s): ?>
    <?php 
      $sid = (string) ($s['id'] ?? ''); 
      $rawName = trim((string) ($s['name'] ?? ''));
      $yearLevel = 'N/A';
      $sectionName = $rawName;

      // Handle old legacy formats with split or parse standard string like "BSIT SD 1A"
      if (strpos($rawName, '-') !== false) {
          $parts = explode('-', $rawName, 2);
          $yearLevel = trim($parts[0]);
          $sectionName = trim($parts[1]);
      } else {
          // If it is just 'BSIT SD 1C', we can regex the year
          if (preg_match('/(?:BSIT SD|BSIT BA|BSCS|BSIT)\s*(\d)/i', $rawName, $m)) {
              $lvl = $m[1];
              $suffix = ($lvl == '1') ? 'st' : (($lvl == '2') ? 'nd' : (($lvl == '3') ? 'rd' : 'th'));
              $yearLevel = $lvl . $suffix . ' Year';
          }
      }
    ?>
    <div class="relative group">
      <a href="admin_section_students.php?id=<?= urlencode($sid) ?>&name=<?= urlencode($sectionName) ?>" class="block bg-white rounded-2xl shadow-sm border border-zinc-200 p-5 hover:-translate-y-1 hover:shadow-md hover:border-orange-500/40 transition-all duration-300 flex flex-col h-full">
      
      <!-- Top Row: Badge & Actions -->
      <div class="flex items-start justify-between mb-4">
        <span class="inline-flex items-center px-2.5 py-1 rounded-lg text-[10px] font-black tracking-widest uppercase bg-orange-50 text-orange-700 border border-orange-200/50 shadow-sm">
            <?= htmlspecialchars($yearLevel) ?>
        </span>
        
        <div class="flex items-center gap-1 opacity-100 lg:opacity-0 lg:group-hover:opacity-100 transition-opacity duration-200 bg-white/50 backdrop-blur-sm rounded-lg p-0.5">
          <button class="btnEdit p-1.5 rounded-lg text-sky-600 hover:text-sky-700 hover:bg-sky-50 transition-colors" data-id="<?= htmlspecialchars($sid) ?>" data-raw-name="<?= htmlspecialchars($rawName) ?>" title="Edit Section">
             <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L6.832 19.82a4.5 4.5 0 01-1.897 1.13l-2.685.8.8-2.685a4.5 4.5 0 011.13-1.897L16.863 4.487zm0 0L19.5 7.125"/></svg>
          </button>
          <button class="btnDelete p-1.5 rounded-lg text-red-500 hover:text-red-700 hover:bg-red-50 transition-colors" data-id="<?= htmlspecialchars($sid) ?>" title="Drop Section">
             <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 01-2.244 2.077H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 00-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 013.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 00-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 00-7.5 0"/></svg>
          </button>
        </div>
      </div>
      
      <!-- Section Content -->
      <div class="mt-auto">
        <h3 class="text-xl font-bold text-zinc-900 leading-tight"><?= htmlspecialchars($sectionName) ?></h3>
        <p class="text-xs font-semibold tracking-wide text-zinc-500 mt-1 uppercase">Class Section</p>
      </div>

      <!-- Decorative subtle element -->
      <div class="absolute bottom-0 right-0 w-16 h-16 bg-gradient-to-tl from-zinc-100 to-transparent rounded-tl-[40px] rounded-br-[15px] pointer-events-none opacity-50 group-hover:from-orange-100 transition-colors"></div>
      </a>
    </div>
  <?php endforeach; ?>

  <?php if (count($sections) === 0): ?>
    <div class="col-span-full bg-white rounded-3xl border border-dashed border-zinc-300 py-16 flex flex-col items-center justify-center text-center">
      <div class="w-16 h-16 rounded-full bg-zinc-50 border border-zinc-200 flex items-center justify-center mb-5 shadow-sm">
        <svg class="w-7 h-7 text-zinc-400" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6A2.25 2.25 0 016 3.75h2.25A2.25 2.25 0 0110.5 6v2.25a2.25 2.25 0 01-2.25 2.25H6a2.25 2.25 0 01-2.25-2.25V6zM3.75 15.75A2.25 2.25 0 016 13.5h2.25a2.25 2.25 0 012.25 2.25V18a2.25 2.25 0 01-2.25 2.25H6A2.25 2.25 0 013.75 18v-2.25zM13.5 6a2.25 2.25 0 012.25-2.25H18A2.25 2.25 0 0120.25 6v2.25A2.25 2.25 0 0118 10.5h-2.25a2.25 2.25 0 01-2.25-2.25V6zM13.5 15.75a2.25 2.25 0 012.25-2.25H18a2.25 2.25 0 012.25 2.25V18A2.25 2.25 0 0118 20.25h-2.25A2.25 2.25 0 0113.5 18v-2.25z"/></svg>
      </div>
      <p class="text-zinc-800 font-bold text-lg mb-1">No sections exist</p>
      <p class="text-zinc-500 text-sm max-w-sm">Click "Add Section" to create your first class directory element. They will appear here statically.</p>
    </div>
  <?php endif; ?>
</div>

<!-- Add Section Modal Overlay -->
<div id="sectionModal" class="fixed inset-0 z-50 flex items-center justify-center p-4 pointer-events-none opacity-0 transition-opacity duration-300">
  <div id="modalBackdrop" class="absolute inset-0 bg-zinc-900/40 backdrop-blur-sm"></div>
  
  <div id="modalContent" class="relative bg-[#FAFAFA] rounded-3xl w-full max-w-md overflow-hidden shadow-2xl scale-95 transition-transform duration-300">
    <div class="px-8 pt-8 pb-6 bg-white border-b border-zinc-100">
      <div class="flex items-center gap-3 mb-2">
        <div class="w-10 h-10 rounded-full bg-orange-100 flex items-center justify-center text-orange-600">
          <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/></svg>
        </div>
        <h3 id="modalTitle" class="text-xl font-bold text-zinc-900 tracking-tight">Add Section</h3>
      </div>
      
      <form id="sectionForm">
        <input type="hidden" id="sectionId" name="sectionId" value="">
        
        <div class="grid grid-cols-2 gap-4 mb-4">
          <!-- Course Dropdown -->
          <div>
            <label class="block text-[13px] font-semibold text-zinc-700 mb-2">Program / Course</label>
            <div class="relative">
              <select id="courseSel" required class="w-full appearance-none rounded-xl bg-white border border-zinc-200 pl-4 pr-10 py-3 text-sm text-zinc-900 focus:ring-2 focus:ring-orange-500/30 focus:border-orange-400 outline-none transition font-medium">
                <option value="BSIT SD">BSIT SD</option>
                <option value="BSIT BA">BSIT BA</option>
                <option value="BSCS">BSCS</option>
              </select>
              <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center px-3 text-zinc-500">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/></svg>
              </div>
            </div>
          </div>
          
          <!-- Year Dropdown -->
          <div>
            <label class="block text-[13px] font-semibold text-zinc-700 mb-2">Year Level</label>
            <div class="relative">
              <select id="yearSel" required class="w-full appearance-none rounded-xl bg-white border border-zinc-200 pl-4 pr-10 py-3 text-sm text-zinc-900 focus:ring-2 focus:ring-orange-500/30 focus:border-orange-400 outline-none transition font-medium">
                <option value="1">1st Year</option>
                <option value="2">2nd Year</option>
                <option value="3">3rd Year</option>
                <option value="4">4th Year</option>
              </select>
              <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center px-3 text-zinc-500">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/></svg>
              </div>
            </div>
          </div>
        </div>

        <!-- Block Selection -->
        <div class="mb-8">
          <label class="block text-[13px] font-semibold text-zinc-700 mb-3">Block Section</label>
          <div class="flex flex-wrap gap-3" id="blockContainer">
            <?php 
              $blocks = ['A','B','C','D','E','F'];
              foreach($blocks as $b):
            ?>
            <button type="button" class="block-btn w-11 h-11 rounded-full border border-zinc-200 bg-white flex items-center justify-center text-sm font-bold text-zinc-600 hover:border-orange-400 focus:outline-none transition-all duration-200" data-val="<?= $b ?>">
              <?= $b ?>
            </button>
            <?php endforeach; ?>
          </div>
          <input type="hidden" id="blockSel" required>
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
  window.CSRF_TOKEN = '<?php echo $_SESSION['csrf_token'] ?? ''; ?>';
  const modal = document.getElementById('sectionModal');
  const modalContent = document.getElementById('modalContent');
  const openBtn = document.getElementById('openModalBtn');
  const closeBtn = document.getElementById('closeModalBtn');
  const backdrop = document.getElementById('modalBackdrop');
  
  const courseSel = document.getElementById('courseSel');
  const yearSel = document.getElementById('yearSel');
  const blockBtns = document.querySelectorAll('.block-btn');
  
  const sectionIdInput = document.getElementById('sectionId');
  const modalTitle = document.getElementById('modalTitle');

  // Multi-select toggle helper 
  function toggleBlock(btn, forceSelect = false) {
    const isEditMode = sectionIdInput.value !== '';
    
    if (isEditMode) {
      // In Edit Mode, restrict to strictly ONE selection
      blockBtns.forEach(b => {
        b.classList.remove('bg-orange-600', 'text-white', 'border-orange-600', 'shadow-md', 'scale-105', 'selected-block');
        b.classList.add('bg-white', 'text-zinc-600', 'border-zinc-200');
      });
      btn.classList.add('bg-orange-600', 'text-white', 'border-orange-600', 'shadow-md', 'scale-105', 'selected-block');
      btn.classList.remove('bg-white', 'text-zinc-600', 'border-zinc-200');
    } else {
      // In Add Mode, toggle independently
      if (forceSelect || !btn.classList.contains('selected-block')) {
        btn.classList.add('bg-orange-600', 'text-white', 'border-orange-600', 'shadow-md', 'scale-105', 'selected-block');
        btn.classList.remove('bg-white', 'text-zinc-600', 'border-zinc-200');
      } else {
        btn.classList.remove('bg-orange-600', 'text-white', 'border-orange-600', 'shadow-md', 'scale-105', 'selected-block');
        btn.classList.add('bg-white', 'text-zinc-600', 'border-zinc-200');
      }
    }
  }

  // Bind click logic
  blockBtns.forEach(btn => {
    btn.addEventListener('click', () => toggleBlock(btn));
  });

  // Helper to pre-select a block
  function setBlock(val) {
    let found = false;
    blockBtns.forEach(btn => {
      // Clear everyone first
      btn.classList.remove('bg-orange-600', 'text-white', 'border-orange-600', 'shadow-md', 'scale-105', 'selected-block');
      btn.classList.add('bg-white', 'text-zinc-600', 'border-zinc-200');
      
      if (btn.dataset.val === val) {
        toggleBlock(btn, true);
        found = true;
      }
    });
    if (!found) toggleBlock(blockBtns[0], true);
  }
  
  function openModal(isEdit = false, id = '', currentName = '') {
    modal.classList.remove('pointer-events-none', 'opacity-0');
    modalContent.classList.remove('scale-95');
    modalContent.classList.add('scale-100');
    document.getElementById('formMsg').textContent = '';
    
    if (isEdit) {
      modalTitle.textContent = 'Edit Section';
      sectionIdInput.value = id;
      document.getElementById('btnSubmit').textContent = 'Update Section';

      let parseName = currentName.includes('-') ? currentName.split('-')[1].trim() : currentName;
      const match = parseName.match(/^(BSIT SD|BSIT BA|BSCS|BSIT)\s*(\d)([A-Z])$/i);
      if (match) {
        yearSel.value = match[2];
        courseSel.value = match[1].toUpperCase() === "BSIT" ? "BSIT SD" : match[1].toUpperCase();
        setBlock(match[3].toUpperCase());
      } else {
        courseSel.value = "BSIT SD";
        yearSel.value = "1";
        setBlock("A");
      }
    } else {
      modalTitle.textContent = 'Add Section';
      sectionIdInput.value = '';
      courseSel.value = "BSIT SD";
      yearSel.value = "1";
      setBlock("A"); // pre-select A initially for Add Mode 
      document.getElementById('btnSubmit').textContent = 'Save Sections';
    }
  }
  
  function closeModal() {
    modal.classList.add('pointer-events-none', 'opacity-0');
    modalContent.classList.remove('scale-100');
    modalContent.classList.add('scale-95');
    setTimeout(() => {
      document.getElementById('sectionForm').reset();
      document.getElementById('formMsg').textContent = '';
      sectionIdInput.value = '';
    }, 300);
  }
  
  openBtn.addEventListener('click', () => openModal(false));
  closeBtn.addEventListener('click', closeModal);
  backdrop.addEventListener('click', closeModal);
  
  // Also close modal on Esc key
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && !modal.classList.contains('opacity-0')) {
      closeModal();
    }
  });

  document.querySelectorAll('.btnEdit').forEach(btn => {
    btn.addEventListener('click', () => {
      const id = btn.dataset.id;
      const rawName = btn.dataset.rawName;
      openModal(true, id, rawName);
    });
  });

  document.getElementById('sectionForm').addEventListener('submit', async (e) => {
    e.preventDefault();
    const btn = document.getElementById('btnSubmit');
    const msg = document.getElementById('formMsg');
    
    const course = courseSel.value;
    const year = yearSel.value;
    
    // Gather all selected blocks
    const activeBlocks = Array.from(document.querySelectorAll('.selected-block')).map(b => b.dataset.val);
    
    if (activeBlocks.length === 0) {
      msg.textContent = 'Please select at least one block section';
      msg.className = 'text-sm font-bold text-center mt-3 text-red-500';
      return;
    }

    const id = sectionIdInput.value;
    const isEditMode = id !== '';
    const action = isEditMode ? 'update' : 'create';

    let payload;
    
    if (isEditMode) {
      // Edit passes a single name and id, purely clean format e.g. "BSIT SD 1A"
      const fullyFormattedName = `${course} ${year}${activeBlocks[0]}`;
      payload = { id, name: fullyFormattedName, action, csrf_token: window.CSRF_TOKEN };
    } else {
      // Create passes an array of names natively
      const generatedNames = activeBlocks.map(block => `${course} ${year}${block}`);
      payload = { names: generatedNames, action, csrf_token: window.CSRF_TOKEN };
    }
    
    btn.disabled = true;
    btn.textContent = 'Saving...';
    btn.classList.add('opacity-70', 'cursor-not-allowed');
    msg.className = 'text-sm font-medium text-center mt-3 text-zinc-400';
    
    try {
      const res = await fetch('/api/sections_manage.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
      });
      const data = await res.json();
      if (!data.ok) throw new Error(data.error || 'Failed to save section');
      
      msg.textContent = isEditMode ? 'Section updated!' : `${activeBlocks.length} Section(s) added!`;
      msg.className = 'text-sm font-bold text-center mt-3 text-emerald-500';
      btn.textContent = 'Success';
      btn.classList.add('bg-emerald-500');
      
      setTimeout(() => window.location.reload(), 600);
    } catch (err) {
      msg.textContent = err.message || 'Failed';
      msg.className = 'text-sm font-bold text-center mt-3 text-red-500';
      btn.disabled = false;
      btn.textContent = isEditMode ? 'Update Section' : 'Save Sections';
      btn.classList.remove('opacity-70', 'cursor-not-allowed');
    }
  });

  document.querySelectorAll('.btnDelete').forEach(btn => {
    btn.addEventListener('click', async () => {
      if(!confirm('Are you sure you want to delete this section? All students in this section will be affected.')) return;
      const id = btn.dataset.id;
      const card = btn.closest('.group');
      
      btn.disabled = true;
      try {
        const res = await fetch('/api/sections_manage.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ id, action: 'delete', csrf_token: window.CSRF_TOKEN })
        });
        const data = await res.json();
        if (!data.ok) throw new Error(data.error || 'Failed to delete section');
        
        if (card) {
          card.style.transition = 'opacity 0.3s, transform 0.3s';
          card.style.transform = 'translateY(10px) scale(0.95)';
          card.style.opacity = '0';
        }
        setTimeout(() => window.location.reload(), 300);
        
      } catch (err) {
        alert(err.message || 'Failed to connect');
        btn.disabled = false;
      }
    });
  });

  const resetSectionsBtn = document.getElementById('resetSectionsBtn');
  if(resetSectionsBtn) {
    resetSectionsBtn.addEventListener('click', async () => {
      const confirmReset = confirm(
        'GLOBAL RESET: Are you sure you want to reset the sections of ALL students?\n\n' +
        'This will clear their section data and force them to re-select their Year & Section the next time they open the app. ' +
        'Usually done at the end of the school year.'
      );
      if (!confirmReset) return;

      resetSectionsBtn.disabled = true;
      const originalText = resetSectionsBtn.innerHTML;
      resetSectionsBtn.innerHTML = '<span class="animate-pulse">Resetting...</span>';

      try {
        const res = await fetch('/api/sections_reset.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ csrf_token: window.CSRF_TOKEN })
        });
        const data = await res.json();
        if(!data.ok) throw new Error(data.error || 'Failed to perform global reset');

        alert('Global Reset Successful. All students have been unassigned from their sections.');
        window.location.reload();
      } catch (err) {
        alert(err.message || 'Failed to perform global reset.');
        resetSectionsBtn.disabled = false;
        resetSectionsBtn.innerHTML = originalText;
      }
    });
  }
</script>

<?php render_footer(); ?>
