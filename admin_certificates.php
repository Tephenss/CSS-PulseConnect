<?php
declare(strict_types=1);

session_start();

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/supabase.php';
require_once __DIR__ . '/includes/layout.php';

$user = require_role(['admin']);

// Fetch all certificate templates with their linked event titles
$url = rtrim(SUPABASE_URL, '/') . '/rest/v1/certificate_templates?select=id,name,event_id,created_at,events(title)&order=created_at.desc';

$headers = [
    'Accept: application/json',
    'apikey: ' . SUPABASE_KEY,
    'Authorization: Bearer ' . SUPABASE_KEY,
];

$res = supabase_request('GET', $url, $headers);
$templates = $res['ok'] ? json_decode((string) $res['body'], true) : [];
if (!is_array($templates)) $templates = [];

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
    <button onclick="window.location.href='/manage_events.php'" type="button" class="inline-flex items-center justify-center gap-2 rounded-xl bg-orange-600 text-white px-5 py-2.5 text-[13px] font-bold shadow-sm hover:bg-orange-700 transition-colors border border-orange-600">
      <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/></svg>
      Create New Template
    </button>
  </div>
</div>

<div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-6 pb-12">
    <!-- Built-in System Templates (Presets map to manual Page 40) -->
    <div class="group relative bg-white rounded-2xl border border-zinc-200 overflow-hidden shadow-sm hover:shadow-md hover:-translate-y-1 transition-all cursor-pointer">
        <div class="aspect-[4/3] bg-zinc-100 p-4 flex items-center justify-center relative border-b border-zinc-200">
             <div class="absolute inset-0 bg-gradient-to-br from-emerald-600 to-emerald-800 opacity-[0.85]"></div>
             <div class="relative z-10 w-full h-full border border-white/20 p-2 flex flex-col items-center justify-center text-center">
                 <h4 class="font-serif text-white font-bold text-sm tracking-wide mb-2 opacity-90">CERTIFICATE</h4>
                 <div class="w-16 h-px bg-white/40 mb-2"></div>
                 <p class="text-[8px] uppercase tracking-widest text-emerald-100 line-clamp-2">System Classic Template</p>
             </div>
        </div>
        <div class="p-4 bg-white relative">
             <span class="inline-flex py-0.5 px-2 rounded-full bg-orange-100 text-[10px] font-bold text-orange-700 uppercase tracking-widest leading-none mb-2 border border-orange-200">Preset</span>
             <h3 class="font-bold text-zinc-900 text-[15px] tracking-tight truncate leading-tight mb-1">Classic Emerald</h3>
             <p class="text-xs text-zinc-500 font-medium">Available to all events</p>
        </div>
    </div>

    <div class="group relative bg-white rounded-2xl border border-zinc-200 overflow-hidden shadow-sm hover:shadow-md hover:-translate-y-1 transition-all cursor-pointer">
        <div class="aspect-[4/3] bg-zinc-100 p-4 flex items-center justify-center relative border-b border-zinc-200">
             <div class="absolute inset-0 bg-gradient-to-br from-orange-400 to-red-600 opacity-90"></div>
             <div class="relative z-10 w-full h-full border border-white/20 p-2 flex flex-col items-center justify-center text-center">
                 <h4 class="font-sans text-white font-black text-lg italic tracking-tight mb-2 opacity-95">NUTRITION</h4>
                 <p class="text-[8px] uppercase tracking-widest text-orange-100">Month Special</p>
             </div>
        </div>
        <div class="p-4 bg-white relative">
             <span class="inline-flex py-0.5 px-2 rounded-full bg-orange-100 text-[10px] font-bold text-orange-700 uppercase tracking-widest leading-none mb-2 border border-orange-200">Preset</span>
             <h3 class="font-bold text-zinc-900 text-[15px] tracking-tight truncate leading-tight mb-1">Nutrition Month V1</h3>
             <p class="text-xs text-zinc-500 font-medium">Available to all events</p>
        </div>
    </div>

    <!-- Dynamo Saved Custom Templates from DB -->
    <?php foreach ($templates as $t): ?>
        <?php 
           $tName = htmlspecialchars($t['name'] ?? 'Untitled Template');
           $evTitle = htmlspecialchars($t['events']['title'] ?? 'Custom Event');
           if(empty($t['events'])) { $evTitle = 'Linked Event'; } // Fallback incase relationship query fails 
        ?>
        <div class="group relative bg-white rounded-2xl border border-zinc-200 overflow-hidden shadow-sm hover:shadow-md hover:-translate-y-1 transition-all">
            <div class="aspect-[4/3] bg-zinc-50 p-4 flex flex-col items-center justify-center relative border-b border-zinc-200 cursor-pointer" onclick="window.location.href='/certificate_admin.php?event_id=<?= urlencode($t['event_id']) ?>'">
                 <div class="w-12 h-12 rounded-full bg-white shadow-sm flex items-center justify-center border border-zinc-200 mb-3 group-hover:scale-110 transition-transform">
                     <svg class="w-5 h-5 text-indigo-500" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9.53 16.122a3 3 0 00-5.78 1.128 2.25 2.25 0 01-2.4 2.245 4.5 4.5 0 008.4-2.245c0-.399-.078-.78-.22-1.128zm0 0a15.998 15.998 0 003.388-1.62m-5.043-.025a15.994 15.994 0 011.622-3.395m3.42 3.42a15.995 15.995 0 004.764-4.648l3.876-5.83M10.875 10.875l4.629-4.86M10.875 10.875L13.84 5.25M6 18l3.125-3.125"/></svg>
                 </div>
                 <div class="text-[10px] font-bold text-zinc-400 tracking-widest uppercase">Fabric Workspace</div>
            </div>
            <div class="p-4 bg-white relative">
                 <span class="inline-flex py-0.5 px-2 rounded-full bg-zinc-100 text-[10px] font-bold text-zinc-600 uppercase tracking-widest leading-none mb-2 border border-zinc-200">Custom</span>
                 <h3 class="font-bold text-zinc-900 text-[15px] tracking-tight truncate leading-tight mb-1 cursor-pointer hover:text-orange-600 transition-colors" onclick="window.location.href='/certificate_admin.php?event_id=<?= urlencode($t['event_id']) ?>'"><?= $tName ?></h3>
                 <p class="text-xs text-zinc-500 font-medium truncate"><?= $evTitle ?></p>
                 
                 <!-- 3-Dot Options Dropdown Mock per manual page 40 -->
                 <div class="absolute bottom-4 right-3 group/menu">
                    <button class="p-1.5 rounded-lg text-zinc-400 hover:text-zinc-600 hover:bg-zinc-100 transition-colors">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6.75a.75.75 0 110-1.5.75.75 0 010 1.5zM12 12.75a.75.75 0 110-1.5.75.75 0 010 1.5zM12 18.75a.75.75 0 110-1.5.75.75 0 010 1.5z"/></svg>
                    </button>
                    <!-- Menu -->
                    <div class="absolute right-0 bottom-full mb-1 w-32 bg-white rounded-xl shadow-[0_4px_20px_-4px_rgba(0,0,0,0.15)] border border-zinc-200 opacity-0 invisible group-hover/menu:opacity-100 group-hover/menu:visible transition-all z-20 py-1">
                        <button onclick="window.location.href='/certificate_admin.php?event_id=<?= urlencode($t['event_id']) ?>'" class="w-full text-left px-4 py-2 text-xs font-bold text-zinc-700 hover:bg-zinc-50 hover:text-orange-600">Edit Layout</button>
                        <button onclick="confirm('Delete this template permanently?') && this.closest('.group').remove()" class="w-full text-left px-4 py-2 text-xs font-bold text-red-600 hover:bg-red-50">Delete</button>
                    </div>
                 </div>
            </div>
        </div>
    <?php endforeach; ?>

    <?php if (count($templates) === 0): ?>
        <div class="col-span-full border-2 border-dashed border-zinc-200 rounded-2xl p-12 flex flex-col items-center justify-center text-center bg-zinc-50">
           <div class="w-16 h-16 rounded-full bg-white border border-zinc-200 shadow-sm flex items-center justify-center mb-4 text-zinc-300">
               <svg class="w-8 h-8" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z"/></svg>
           </div>
           <h3 class="text-zinc-800 font-bold mb-1">No custom templates yet</h3>
           <p class="text-zinc-500 text-sm max-w-xs">Create an event and navigate to its Canva-style editor to design and save new certificates.</p>
        </div>
    <?php endif; ?>
</div>

<?php render_footer(); ?>
