<?php
declare(strict_types=1);

session_start();

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/supabase.php';
require_once __DIR__ . '/includes/layout.php';

$user = require_role(['admin']);
csrf_ensure_token();

// Fetch all certificate templates with their linked event titles
$url = rtrim(SUPABASE_URL, '/') . '/rest/v1/certificate_templates?select=id,title,event_id,created_at,thumbnail_url,events(title)&order=created_at.desc';

$headers = [
    'Accept: application/json',
    'apikey: ' . SUPABASE_KEY,
    'Authorization: Bearer ' . SUPABASE_KEY,
];

$res = supabase_request('GET', $url, $headers);
$templates = $res['ok'] ? json_decode((string) $res['body'], true) : [];
if (!is_array($templates)) {
    // Maybe an error occurred!
    error_log("Supabase error: " . print_r($res, true));
    $templates = [];
}
if (!$res['ok']) {
   echo "<div class='p-4 bg-red-100 text-red-800 m-8'>Error fetching templates: " . htmlspecialchars($res['body']) . "</div>";
}

// Fallback logic in case the relation "events" doesn't join properly due to schema config 
// we will just pull raw if $templates[0]['events'] is null and display "Custom Event".

render_header('Certificates Library', $user);
?>

<div class="mb-8 flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
  <div class="min-w-0">
    <h2 class="text-xl font-bold text-zinc-900 mb-1">All Certificates</h2>
    <p class="text-zinc-600 text-sm">Manage all custom certificate templates created for your events.</p>
  </div>
  <div class="relative group shrink-0 self-end sm:self-start">
    <button onclick="window.location.href='/certificate_admin.php'" type="button" class="inline-flex items-center justify-center gap-2 rounded-xl bg-orange-600 text-white px-5 py-2.5 text-[13px] font-bold shadow-sm hover:bg-orange-700 transition-colors border border-orange-600">
      <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/></svg>
      Create New Template
    </button>
  </div>
</div>

<div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-6 pb-12">
    <!-- Dynamo Saved Custom Templates from DB -->
    <?php foreach ($templates as $t): ?>
        <?php 
           $tName = htmlspecialchars($t['title'] ?? 'Untitled Template');
           $evTitle = htmlspecialchars($t['events']['title'] ?? 'Custom Event');
           if(empty($t['events'])) { $evTitle = 'Linked Event'; } // Fallback incase relationship query fails 
        ?>
        <div class="group relative bg-white rounded-2xl border border-zinc-200 overflow-hidden shadow-sm hover:shadow-md hover:-translate-y-1 transition-all cursor-pointer" onclick="window.location.href='/certificate_admin.php?template_id=<?= urlencode((string)$t['id']) ?>'">
            <div class="aspect-[4/3] bg-zinc-100 flex items-center justify-center relative border-b border-zinc-200 overflow-hidden">
                 <?php if (!empty($t['thumbnail_url'])): ?>
                    <img src="<?= htmlspecialchars($t['thumbnail_url']) ?>" class="w-full h-full object-cover group-hover:scale-105 transition-transform duration-500">
                 <?php else: ?>
                    <div class="w-12 h-12 rounded-full bg-white border border-zinc-200 flex items-center justify-center text-indigo-500 shadow-sm relative z-10">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9.53 16.122l9.37-9.445m-1.121 2.807l1.121-2.807-2.846 1.147m6.165 9.482a9.75 9.75 0 01-3.645 5.054 9.75 9.75 0 01-6.423 2.305c-5.385 0-9.75-4.365-9.75-9.75 0-4.11 2.541-7.625 6.163-9.052m.5 13.12l-5.103-5.103m0 0l2.186-2.186m-2.186 2.186l2.186 2.186m1.961 1.961l5.357-5.357m1.328-1.24a1.5 1.5 0 10-2.121-2.121l-5.357 5.357m1.328 1.24L12 15l-1.494-1.494m1.494 1.494l1.494-1.494"/></svg>
                    </div>
                    <div class="absolute bottom-2 left-0 right-0 text-center text-[9px] font-black tracking-widest text-zinc-300 uppercase">No Preview</div>
                 <?php endif; ?>
            </div>
            <div class="p-4 bg-white relative">
                 <span class="inline-flex py-0.5 px-2 rounded-full bg-zinc-100 text-[10px] font-bold text-zinc-600 uppercase tracking-widest leading-none mb-2 border border-zinc-200">Custom</span>
                 <h3 class="font-bold text-zinc-900 text-[15px] tracking-tight truncate leading-tight mb-1 cursor-pointer hover:text-orange-600 transition-colors" onclick="window.location.href='/certificate_admin.php?template_id=<?= urlencode($t['id']) ?>'"><?= $tName ?></h3>
                 <p class="text-xs text-zinc-500 font-medium truncate"><?= $evTitle ?></p>
                 
                 <!-- 3-Dot Options Dropdown Mock per manual page 40 -->
                 <div class="absolute bottom-4 right-3 group/menu">
                    <button class="p-1.5 rounded-lg text-zinc-400 hover:text-zinc-600 hover:bg-zinc-100 transition-colors" onclick="event.stopPropagation()">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6.75a.75.75 0 110-1.5.75.75 0 010 1.5zM12 12.75a.75.75 0 110-1.5.75.75 0 010 1.5zM12 18.75a.75.75 0 110-1.5.75.75 0 010 1.5z"/></svg>
                    </button>
                    <!-- Menu -->
                    <div class="absolute right-0 bottom-full mb-1 w-32 bg-white rounded-xl shadow-[0_4px_20px_-4px_rgba(0,0,0,0.15)] border border-zinc-200 opacity-0 invisible group-hover/menu:opacity-100 group-hover/menu:visible transition-all z-20 py-1" onclick="event.stopPropagation()">
                        <button onclick="window.location.href='/certificate_admin.php?template_id=<?= urlencode((string)$t['id']) ?>'" class="w-full text-left px-4 py-2 text-xs font-bold text-zinc-700 hover:bg-zinc-50 hover:text-orange-600">Edit Layout</button>
                        <button onclick="deleteTemplate('<?= htmlspecialchars($t['id']) ?>', this.closest('.group'))" class="w-full text-left px-4 py-2 text-xs font-bold text-red-600 hover:bg-red-50">Delete</button>
                    </div>
                 </div>
            </div>
        </div>
    <?php endforeach; ?>

    <?php if (count($templates) === 0): ?>
        <div class="col-span-full border-2 border-dashed border-zinc-200 rounded-2xl p-12 flex flex-col items-center justify-center text-center bg-zinc-50 mt-4">
           <div class="w-16 h-16 rounded-full bg-white border border-zinc-200 shadow-sm flex items-center justify-center mb-4 text-zinc-300">
               <svg class="w-8 h-8" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z"/></svg>
           </div>
           <h3 class="text-zinc-800 font-bold mb-1">No Saved Workspaces</h3>
           <p class="text-zinc-500 text-sm max-w-xs">You haven't saved any custom designs yet. Open a template above or click Create New to start designing!</p>
        </div>
    <?php endif; ?>
</div>

<script>
window.CSRF_TOKEN = <?= json_encode($_SESSION['csrf_token'] ?? '') ?>;

async function deleteTemplate(id, el) {
    if(!confirm('Are you sure you want to permanently delete this template?')) return;
    try {
        const res = await fetch('/api/certificate_delete.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ template_id: id, csrf_token: window.CSRF_TOKEN })
        });
        
        if (res.ok) {
            const data = await res.json();
            if (data.ok) {
                el.remove();
                // If it's the last one, maybe refresh to show empty state
                if (document.querySelectorAll('.group.relative.bg-white').length === 2) {
                    window.location.reload();
                }
            } else {
                alert(data.error || 'Failed to delete template');
            }
        } else {
            const txt = await res.text();
            alert('Server Error (' + res.status + '): ' + txt.substring(0, 50));
        }
    } catch (err) {
        console.error(err);
        alert('Network error while deleting. Check console for details.');
    }
}
</script>

<?php render_footer(); ?>
