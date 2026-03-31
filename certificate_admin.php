<?php
declare(strict_types=1);
session_start();

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/supabase.php';

$user = require_role(['admin', 'teacher']);
$eventId = isset($_GET['event_id']) ? (string) $_GET['event_id'] : '';

$eventName = 'Sample Event One';
if ($eventId !== '') {
    $url = rtrim(SUPABASE_URL, '/') . '/rest/v1/events?select=title&id=eq.' . rawurlencode($eventId);
    $res = supabase_request('GET', $url, [
        'apikey: ' . SUPABASE_KEY,
        'Authorization: Bearer ' . SUPABASE_KEY
    ]);
    if ($res['ok']) {
        $arr = json_decode((string) $res['body'], true);
        if (is_array($arr) && isset($arr[0]['title'])) {
            $eventName = $arr[0]['title'];
        }
    }
}

// Fetch saved templates for this event
$customTemplates = [];
if ($eventId !== '') {
    $tplUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/certificate_templates?select=id,name,canvas_state&event_id=eq.' . rawurlencode($eventId) . '&order=created_at.desc';
    $tplRes = supabase_request('GET', $tplUrl, [
        'apikey: ' . SUPABASE_KEY,
        'Authorization: Bearer ' . SUPABASE_KEY
    ]);
    if ($tplRes['ok']) {
        $arrTpl = json_decode((string) $tplRes['body'], true);
        if (is_array($arrTpl)) {
            $customTemplates = $arrTpl;
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8"/>
    <meta name="viewport" content="width=device-width, initial-scale=1"/>
    <title>Certificate Pro Editor — PulseConnect</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/fabric.js/5.3.1/fabric.min.js"></script>
    <style>
        body { font-family: 'Inter', sans-serif; }
        
        .toolbar-btn {
            display: inline-flex; justify-content: center; align-items: center;
            width: 32px; height: 32px; border-radius: 6px; color: #a1a1aa; 
            transition: all 0.2s; border: 1px solid transparent; background: transparent;
        }
        .toolbar-btn:hover { background-color: rgba(255,255,255,0.05); color: #f4f4f5; }
        .toolbar-btn.active { 
            background-color: rgba(249, 115, 22, 0.15); color: #f97316; 
            border-color: rgba(249, 115, 22, 0.3);
        }
        
        .sidebar-tab { 
            cursor: pointer; padding-bottom: 12px; color: #71717a; font-weight: 700; font-size: 13px; 
            border-bottom: 2px solid transparent; transition: all 0.2s;
        }
        .sidebar-tab:hover { color: #f97316; border-color: rgba(249, 115, 22, 0.3); }
        .sidebar-tab.active { color: #f97316; border-color: #f97316; }
        
        ::-webkit-scrollbar { width: 8px; height: 8px;}
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: #3f3f46; border-radius: 4px; }
        ::-webkit-scrollbar-thumb:hover { background: #52525b; }

        input[type=range] { -webkit-appearance: none; width: 100px; background: transparent; }
        input[type=range]::-webkit-slider-thumb {
            -webkit-appearance: none; height: 16px; width: 16px; border-radius: 50%; 
            background: #f97316; border: 2px solid #18181b; cursor: pointer; margin-top: -6px; 
        }
        input[type=range]::-webkit-slider-runnable-track {
            width: 100%; height: 4px; cursor: pointer; background: #3f3f46; border-radius: 2px;
        }
        
        /* Clean Soft Dark Inputs */
        .select-clean {
            border: 1px solid #3f3f46; background-color: #18181b; border-radius: 6px;
            padding: 4px 8px; color: #e4e4e7; outline: none; transition: border-color 0.2s;
        }
        .select-clean:hover, .select-clean:focus { border-color: #f97316; }

        input[type="color"] {
            -webkit-appearance: none; border: 1px solid #3f3f46; width: 28px; height: 28px;
            border-radius: 6px; padding: 0; cursor: pointer; background: #18181b; overflow: hidden;
        }
        input[type="color"]::-webkit-color-swatch-wrapper { padding: 0; }
        input[type="color"]::-webkit-color-swatch { border: none; border-radius: 4px;}
        
        .template-card:hover { transform: scale(1.02); border-color: #f97316 !important; }
    </style>
</head>
<body class="h-screen w-screen overflow-hidden flex flex-col bg-[#121214] text-zinc-100 selection:bg-orange-500/30">

    <!-- TOP NAV / HEADER (Soft Dark) -->
    <div class="h-14 bg-[#0a0a0c] border-b border-zinc-800 text-white flex items-center justify-between px-6 z-40 flex-shrink-0 relative">
        <div class="flex items-center gap-4">
             <a href="<?= $eventId ? '/event_view.php?id='.htmlspecialchars($eventId) : '/manage_events.php' ?>" class="flex items-center gap-2 text-zinc-400 hover:text-white transition-colors text-sm font-semibold group">
                <div class="w-7 h-7 rounded bg-zinc-800/80 group-hover:bg-orange-500/20 flex flex-col justify-center items-center transition-all border border-zinc-700">
                    <svg class="w-4 h-4 group-hover:text-orange-500 transition-colors" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5L8.25 12l7.5-7.5"/></svg>
                </div>
                Exit Editor
            </a>
            <div class="h-5 w-px bg-zinc-800"></div>
            <div class="text-[13px] font-semibold truncate max-w-[300px] text-zinc-300">Workspace: <span class="text-white ml-1 font-bold"><?= htmlspecialchars($eventName) ?></span></div>
        </div>

        <div class="flex gap-3">
            <button id="btnPreview" class="flex items-center gap-1.5 bg-zinc-800 hover:bg-zinc-700 text-zinc-300 hover:text-white px-4 py-2 rounded-md text-xs font-bold transition-all border border-zinc-700">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5m-13.5-9L12 3m0 0l4.5 4.5M12 3v13.5"/></svg>
                HD Preview
            </button>
            <button id="btnSaveTemplate" class="flex items-center gap-1.5 bg-gradient-to-r from-orange-500 to-red-500 text-white px-6 py-2 rounded-md text-sm font-bold transition-all shadow-lg shadow-orange-500/20 hover:shadow-orange-500/40">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M17.5 3h-11a2 2 0 00-2 2v14a2 2 0 002 2h11a2 2 0 002-2v-14a2 2 0 00-2-2z"/><path stroke-linecap="round" stroke-linejoin="round" d="M9 13h6m-3-3v6"/></svg>
                Save Layout
            </button>
        </div>
    </div>

    <!-- MAIN CONTEXTUAL TOOLBAR -->
    <div class="h-14 bg-[#18181b] border-b border-zinc-800 flex items-center px-4 z-30 flex-shrink-0 shadow-md transition-all overflow-x-auto">
        <div id="textFormattingBar" class="flex items-center gap-3 opacity-30 pointer-events-none transition-opacity duration-200 pl-2 shrink-0">
            <select id="pageSize" class="select-clean text-sm font-medium cursor-pointer w-[120px]">
                <option value="A4">A4 Landscape</option>
                <option value="Letter">Letter Landscape</option>
            </select>
            <div class="h-6 w-px bg-zinc-700"></div>

            <div class="flex items-center gap-1.5">
                <select id="fontFamily" class="select-clean text-sm font-medium cursor-pointer w-[140px] truncate">
                    <option value="Inter" style="font-family: Inter;">Inter</option>
                    <option value="Arial" style="font-family: Arial;">Arial</option>
                    <option value="Times New Roman" style="font-family: 'Times New Roman'">Times New Roman</option>
                    <option value="Georgia" style="font-family: Georgia">Georgia</option>
                </select>
                <button id="btnImportFont" class="w-8 h-8 rounded bg-zinc-800 hover:bg-orange-500/20 text-zinc-300 hover:text-orange-400 text-[11px] font-bold border border-zinc-700 hover:border-orange-500/50 transition-colors" title="Add Google Font">A+</button>
            </div>
            
            <div class="h-6 w-px bg-zinc-700 mx-1"></div>

            <!-- Number Input -->
            <div class="flex items-center border border-zinc-700 rounded-md overflow-hidden bg-[#18181b]">
                <button id="btnSizeDec" class="w-8 h-7 flex items-center justify-center text-zinc-400 hover:bg-zinc-800 font-bold">-</button>
                <input type="number" id="fontSize" value="24" class="w-12 h-7 text-center text-sm font-medium bg-transparent text-white focus:outline-none border-x border-zinc-700" style="-moz-appearance: textfield;" />
                <button id="btnSizeInc" class="w-8 h-7 flex items-center justify-center text-zinc-400 hover:bg-zinc-800 font-bold">+</button>
            </div>

            <div class="h-6 w-px bg-zinc-700 mx-1"></div>
            <input type="color" id="fontColor" value="#000000" title="Text Color" />
            <div class="h-6 w-px bg-zinc-700 mx-1"></div>

            <div class="flex gap-0.5">
                <button id="btnBold" class="toolbar-btn font-bold">B</button>
                <button id="btnItalic" class="toolbar-btn italic font-serif">I</button>
                <button id="btnUnderline" class="toolbar-btn underline">U</button>
            </div>
            
            <div class="h-6 w-px bg-zinc-700 mx-1"></div>
            
            <div class="flex gap-0.5">
                <button id="btnAlignLeft" class="toolbar-btn active"><svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25H12"/></svg></button>
                <button id="btnAlignCenter" class="toolbar-btn"><svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5M3.75 17.25h16.5" class="origin-center scale-x-75"/></svg></button>
                <button id="btnAlignRight" class="toolbar-btn"><svg class="w-4 h-4 transform rotate-180" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25H12"/></svg></button>
            </div>
        </div>

        <!-- Right Side Object Actions -->
        <div class="flex-1"></div>
        <div id="objectToolbar" class="flex items-center gap-4 opacity-0 pointer-events-none transition-opacity duration-200 pr-2 shrink-0 border-l border-zinc-700 pl-4 h-full">
            <div class="flex items-center gap-2">
                 <span class="text-[10px] font-bold text-zinc-500 uppercase tracking-wide">Opacity</span>
                 <input type="range" id="objOpacity" min="10" max="100" value="100" />
                 <span id="opacityLabel" class="text-[11px] font-mono text-zinc-400 w-8 text-right">100%</span>
             </div>
             
             <div class="h-6 w-px bg-zinc-700"></div>

             <div class="flex gap-1.5">
                 <button id="btnBringForward" class="flex items-center gap-1.5 bg-zinc-800 border border-zinc-700 hover:bg-zinc-700 hover:text-white px-2.5 py-1.5 rounded-md text-xs font-semibold text-zinc-400 transition" title="Bring to Front">
                     <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m0 0l6-6m-6 6l-6-6" class="origin-center rotate-180"/></svg>
                 </button>
                 <button id="btnSendBack" class="flex items-center gap-1.5 bg-zinc-800 border border-zinc-700 hover:bg-zinc-700 hover:text-white px-2.5 py-1.5 rounded-md text-xs font-semibold text-zinc-400 transition" title="Send Backward">
                     <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m0 0l6-6m-6 6l-6-6"/></svg>
                 </button>
             </div>

             <div class="h-6 w-px bg-zinc-700"></div>

             <button id="btnDuplicate" class="flex items-center gap-1.5 text-zinc-300 hover:text-orange-400 bg-zinc-800 hover:bg-orange-500/10 border border-zinc-700 hover:border-orange-500/30 px-3 py-1.5 rounded-md text-xs font-semibold transition">
                 <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 15L12 18.75 15.75 15M12 18.75V8.25"/></svg> Copy
             </button>
             
             <button id="btnLock" class="flex items-center gap-1.5 text-zinc-400 hover:text-white hover:bg-zinc-800 px-3 py-1.5 rounded-md text-xs font-semibold transition w-20 justify-center border border-transparent">
                 <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 10-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z"/></svg> <span id="lblLock">Lock</span>
             </button>

             <button id="btnDeleteObj" class="flex items-center justify-center w-8 h-8 rounded-md bg-red-500/10 hover:bg-red-500/20 text-red-500 transition-colors border border-red-500/20" title="Delete Layer">
                 <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 01-2.244 2.077H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 00-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 013.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 00-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 00-7.5 0"/></svg>
             </button>
        </div>
    </div>

    <!-- MAIN APP AREA -->
    <div class="flex-1 flex overflow-hidden">
        
        <!-- LEFT SIDEBAR -->
        <div class="w-[310px] bg-[#0c0c0e] flex flex-col border-r border-zinc-800 z-10 shrink-0">
            
            <div class="flex items-center justify-between px-4 pt-5 bg-[#0a0a0c] border-b border-zinc-900">
                <!-- NEW TEMPLATES TAB -->
                <div class="sidebar-tab active flex flex-col items-center gap-1.5" data-tab="templates">
                    <svg class="w-5 h-5 mb-0.5" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 8.25h19.5M2.25 9h19.5m-16.5 5.25h6m-6 2.25h3m-3.75 3h15a2.25 2.25 0 002.25-2.25V6.75A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25v10.5A2.25 2.25 0 004.5 19.5z"/></svg><span class="text-[11px]">Templates</span>
                </div>
                <div class="sidebar-tab flex flex-col items-center gap-1.5" data-tab="bg">
                    <svg class="w-5 h-5 mb-0.5" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 15.75l5.159-5.159a2.25 2.25 0 013.182 0l5.159 5.159m-1.5-1.5l1.409-1.409a2.25 2.25 0 013.182 0l2.909 2.909m-18 3.75h16.5a1.5 1.5 0 001.5-1.5V6a1.5 1.5 0 00-1.5-1.5H3.75A1.5 1.5 0 002.25 6v12a1.5 1.5 0 001.5 1.5zm10.5-11.25h.008v.008h-.008V8.25zm.375 0a.375.375 0 11-.75 0 .375.375 0 01.75 0z"/></svg><span class="text-[11px]">Bg</span>
                </div>
                <div class="sidebar-tab flex flex-col items-center gap-1.5" data-tab="logo">
                     <svg class="w-5 h-5 mb-0.5" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 21a9.004 9.004 0 008.716-6.747M12 21a9.004 9.004 0 01-8.716-6.747M12 21c2.485 0 4.5-4.03 4.5-9S14.485 3 12 3m0 18c-2.485 0-4.5-4.03-4.5-9S9.515 3 12 3m0 0a8.997 8.997 0 017.843 4.582M12 3a8.997 8.997 0 00-7.843 4.582m15.686 0A11.953 11.953 0 0112 10.5c-2.998 0-5.74-1.1-7.843-2.918m15.686 0A8.959 8.959 0 0121 12c0 .778-.099 1.533-.284 2.253m0 0A17.919 17.919 0 0112 16.5c-3.162 0-6.133-.815-8.716-2.247m0 0A9.015 9.015 0 013 12c0-1.605.42-3.113 1.157-4.418"/></svg><span class="text-[11px]">Logo</span>
                </div>
                <div class="sidebar-tab flex flex-col items-center gap-1.5" data-tab="text">
                    <svg class="w-5 h-5 mb-0.5" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25H12"/></svg><span class="text-[11px]">Text</span>
                </div>
                <div class="sidebar-tab flex flex-col items-center gap-1.5" data-tab="sig">
                     <svg class="w-5 h-5 mb-0.5" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931zm0 0L19.5 7.125M18 14v4.75A2.25 2.25 0 0115.75 21H5.25A2.25 2.25 0 013 18.75V8.25A2.25 2.25 0 015.25 6H10"/></svg><span class="text-[11px]">Sign</span>
                </div>
            </div>

            <div class="flex-1 overflow-y-auto p-5">
                
                <!-- TEMPLATES PANEL (NEW) -->
                <div id="panel-templates" class="tab-panel flex flex-col gap-4">
                    
                    <?php if (count($customTemplates) > 0): ?>
                    <div class="text-[11px] font-black text-orange-500 uppercase tracking-widest mb-1 flex items-center gap-1.5 border-b border-zinc-800 pb-2">
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M17.5 3h-11a2 2 0 00-2 2v14a2 2 0 002 2h11a2 2 0 002-2v-14a2 2 0 00-2-2z"/><path stroke-linecap="round" stroke-linejoin="round" d="M9 13h6m-3-3v6"/></svg>
                        Workspace Saved
                    </div>
                    <?php foreach ($customTemplates as $ct): ?>
                        <div class="custom-template-card border border-orange-500/20 bg-orange-500/5 hover:bg-orange-500/10 rounded-lg p-3 cursor-pointer transition-all flex flex-col gap-2 shadow-sm" data-json="<?= htmlspecialchars(json_encode($ct['canvas_state'])) ?>">
                            <div class="text-[13px] font-bold text-orange-200"><?= htmlspecialchars((string)$ct['name']) ?></div>
                            <div class="text-[10px] text-zinc-500 font-medium">Click to restore this canvas state</div>
                        </div>
                    <?php endforeach; ?>
                    <div class="h-4"></div>
                    <?php endif; ?>

                    <div class="text-[11px] font-bold text-zinc-500 uppercase tracking-widest mb-1">Premade Designs</div>
                    
                    <!-- Template 1: Classic Green -->
                    <div class="template-card border border-zinc-700 bg-[#18181b] rounded-lg p-2 cursor-pointer transition-all flex flex-col gap-2 shadow-sm" data-preset="classic-green">
                        <div class="w-full h-32 bg-emerald-900 rounded border border-zinc-800 flex flex-col items-center justify-center p-4 relative overflow-hidden pointer-events-none">
                            <!-- Visual CSS Mockup -->
                            <div class="absolute inset-0 bg-gradient-to-br from-emerald-800 to-emerald-900 opacity-80"></div>
                            <div class="w-12 h-1 bg-orange-400 absolute top-0 left-0"></div>
                            <div class="w-12 h-1 bg-orange-400 absolute bottom-0 right-0"></div>
                            <div class="text-[8px] font-bold text-white uppercase tracking-widest mt-2 relative">Certificate of Participation</div>
                            <div class="text-[12px] font-black text-white leading-none relative mt-1">{{participant_name}}</div>
                        </div>
                        <div class="text-xs font-semibold text-zinc-300 text-center">Classic Emerald & Gold</div>
                    </div>

                    <!-- Template 2: Nutrition Month (Requested) -->
                    <div class="template-card border border-zinc-700 bg-[#18181b] rounded-lg p-2 cursor-pointer transition-all flex flex-col gap-2 shadow-sm" data-preset="nutrition-month">
                        <div class="w-full h-32 bg-orange-900 rounded border border-zinc-800 flex flex-col items-center justify-center p-4 relative overflow-hidden pointer-events-none">
                            <div class="absolute inset-0 bg-gradient-to-tr from-amber-700 to-orange-500 opacity-60"></div>
                            <div class="w-16 h-16 rounded-full border-2 border-white/20 absolute -right-8 -top-8"></div>
                            <div class="text-[8px] font-bold text-orange-100 uppercase tracking-widest relative mt-2">NUTRITION MONTH 2026</div>
                            <div class="text-[13px] font-serif font-black text-white leading-none relative mt-1">{{participant_name}}</div>
                        </div>
                        <div class="text-xs font-semibold text-zinc-300 text-center">Nutrition Month</div>
                    </div>
                </div>

                <div id="panel-bg" class="tab-panel hidden flex flex-col gap-4">
                    <label class="w-full flex items-center justify-center gap-2 rounded-xl border-2 border-dashed border-zinc-700 bg-zinc-800/30 hover:bg-orange-500/10 hover:border-orange-500/50 cursor-pointer p-6 text-sm font-bold text-zinc-300 shadow-sm transition-all group">
                        <svg class="w-6 h-6 text-zinc-500 group-hover:text-orange-500 transition" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5m-13.5-9L12 3m0 0l4.5 4.5M12 3v13.5"/></svg>
                        Upload Background
                        <input type="file" id="uploadBg" class="hidden" accept="image/*">
                    </label>
                    <button class="w-full py-2.5 bg-red-500/10 text-red-500 border border-red-500/20 rounded-md text-xs font-semibold hover:bg-red-500 hover:text-white transition shadow-sm" onclick="canvas.setBackgroundImage(null, canvas.renderAll.bind(canvas));">Remove Background</button>
                    
                    <div class="rounded-lg bg-[#18181b] border border-zinc-800 p-4 mt-2">
                        <p class="text-[11px] text-zinc-400 font-medium leading-relaxed">
                            Images automatically stretch. 1920x1080 resolution is highly recommended.
                        </p>
                    </div>
                </div>

                <div id="panel-logo" class="tab-panel hidden flex flex-col gap-4">
                    <label class="w-full flex-shrink-0 flex flex-col items-center justify-center gap-2 rounded-xl border-2 border-dashed border-zinc-700 bg-zinc-800/30 px-4 py-8 text-sm font-bold text-zinc-300 hover:bg-orange-500/10 hover:border-orange-500/50 hover:text-orange-400 cursor-pointer transition-all shadow-sm">
                        <svg class="w-10 h-10 opacity-60 mb-1 text-zinc-600" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 16.5V9.75m0 0l3 3m-3-3l-3 3M6.75 19.5a4.5 4.5 0 01-1.41-8.775 5.25 5.25 0 0110.233-2.33 3 3 0 013.758 3.848A3.752 3.752 0 0118 19.5H6.75z"/></svg>
                        Add Transparent Logo
                        <span class="text-[10px] text-zinc-500 font-medium font-normal">PNG formats recommended</span>
                        <input type="file" id="uploadLogo" class="hidden" accept="image/png, image/jpeg">
                    </label>
                </div>

                <div id="panel-text" class="tab-panel hidden flex flex-col gap-3">
                    <button id="addHeading" class="w-full rounded-md bg-[#18181b] border border-zinc-800 p-4 text-center text-xl font-bold text-white hover:bg-zinc-800 hover:border-zinc-600 transition-all shadow-sm">Add a heading</button>
                    <button id="addSubheading" class="w-full rounded-md bg-[#18181b] border border-zinc-800 p-3 text-center text-base font-semibold text-zinc-300 hover:bg-zinc-800 hover:border-zinc-600 transition-all shadow-sm">Add a subheading</button>
                    <button id="addBodyText" class="w-full rounded-md bg-[#18181b] border border-zinc-800 p-2 text-center text-xs font-medium text-zinc-400 hover:bg-zinc-800 hover:border-zinc-600 transition-all shadow-sm">Add a little bit of body text</button>

                    <div class="h-px w-full bg-zinc-800 my-3"></div>
                    
                    <button id="addAutoName" class="w-full rounded-md bg-gradient-to-r from-orange-500/10 to-red-500/10 border border-orange-500/30 p-4 flex flex-col justify-center items-center gap-1 hover:from-orange-500/20 hover:to-red-500/20 transition-all shadow-sm group">
                        <span class="text-[13px] font-bold text-orange-500">Insert Student Variable</span>
                        <span class="text-[10px] text-orange-600/60 font-mono tracking-tight">{{participant_name}}</span>
                    </button>
                </div>

                <div id="panel-sig" class="tab-panel hidden flex flex-col gap-4">
                     <button id="addSignatoryLine" class="w-full rounded-md bg-[#18181b] border border-zinc-800 p-3 text-center text-sm font-semibold text-zinc-300 hover:bg-zinc-800 hover:border-zinc-600 transition-all flex items-center justify-center gap-2 shadow-sm">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 12h-15"/></svg> Add Signature Line
                    </button>
                    <label class="w-full flex-shrink-0 flex flex-col items-center justify-center gap-1.5 rounded-xl border-2 border-dashed border-zinc-700 bg-zinc-800/30 px-4 py-8 text-sm font-bold text-zinc-300 hover:bg-orange-500/10 hover:border-orange-500/50 cursor-pointer transition-all shadow-sm mt-3">
                        <svg class="w-8 h-8 opacity-60 mb-1 text-zinc-600" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931zm0 0L19.5 7.125M18 14v4.75A2.25 2.25 0 0115.75 21H5.25A2.25 2.25 0 013 18.75V8.25A2.25 2.25 0 015.25 6H10"/></svg>
                        Upload E-Signature
                        <span class="text-[10px] text-zinc-500 font-normal">Transparent PNG</span>
                        <input type="file" id="uploadSig" class="hidden" accept="image/png">
                    </label>
                </div>
            </div>
        </div>

        <!-- CANVAS WORKSPACE (Right) -->
        <div class="flex-1 overflow-auto bg-[#1f1f22] bg-[radial-gradient(ellipse_at_center,_var(--tw-gradient-stops))] from-[#27272a] to-[#1f1f22] flex items-center justify-center relative shadow-inner" id="workspaceContainer">
             <div class="absolute bottom-4 left-4 z-20 flex bg-[#18181b] border border-zinc-800 shadow-sm rounded-md px-3 py-1.5 items-center gap-2">
                 <svg class="w-3.5 h-3.5 text-zinc-500" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607zM10.5 7.5v6m3-3h-6"/></svg>
                 <span id="zoomLabel" class="text-[11px] font-bold text-zinc-400 w-10 text-center">100%</span>
             </div>

             <div id="canvasWrapper" class="shadow-[0_20px_60px_rgba(0,0,0,0.5)] bg-white transition-transform duration-200 origin-center ring-1 ring-black" style="transform: scale(1);">
                 <canvas id="certCanvas"></canvas>
             </div>
        </div>
    </div>

    <!-- GOOGLE FONTS IMPORT MODAL -->
    <div id="fontModal" class="fixed inset-0 z-50 flex items-center justify-center bg-zinc-900/80 backdrop-blur-sm opacity-0 hidden transition-opacity duration-300">
        <div class="relative w-full max-w-lg mx-4 bg-[#121214] border border-zinc-800 rounded-2xl shadow-2xl overflow-hidden scale-95 transition-transform duration-300" id="fontModalContent">
            <div class="px-6 py-5 border-b border-zinc-800 flex items-center justify-between">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-xl bg-orange-500/10 border border-orange-500/20 flex items-center justify-center text-orange-500 font-bold text-lg">
                        A+
                    </div>
                    <div>
                        <h3 class="text-lg font-bold text-zinc-100 leading-none">Import Google Font</h3>
                        <p class="text-xs text-zinc-500 mt-1">Make your certificates on-brand.</p>
                    </div>
                </div>
                <button onclick="document.getElementById('fontModal').classList.add('hidden')" class="p-1 -mr-1 text-zinc-500 hover:text-white rounded bg-zinc-800/50">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            </div>
            <div class="p-6 space-y-5">
                <div>
                   <label class="block text-[11px] font-bold text-zinc-400 mb-1.5 uppercase tracking-wide">Font URL Connection</label>
                   <input type="text" id="fontUrlInput" placeholder="https://fonts.googleapis.com/css2?family=Roboto..." class="w-full rounded-md border border-zinc-700 bg-zinc-900 px-4 py-2.5 text-sm text-zinc-100 outline-none focus:border-orange-500 focus:ring-1 focus:ring-orange-500 transition shadow-inner font-mono">
                </div>
                <div class="bg-zinc-800/50 border border-zinc-700 rounded-lg p-4">
                    <p class="text-xs text-zinc-400 font-medium leading-relaxed">Copy the direct URL from Google fonts excluding the HTML link tags. <br><code class="mt-1 block bg-[#0a0a0c] px-2 py-1 rounded text-orange-400 border border-zinc-800">https://fonts.googleapis.com/css2?family=...</code></p>
                </div>
            </div>
            <div class="px-6 py-4 border-t border-zinc-800 bg-[#18181b] flex items-center justify-end gap-3">
                <button onclick="document.getElementById('fontModal').classList.add('hidden')" class="px-4 py-2 font-semibold text-sm text-zinc-500 hover:text-white transition">Cancel</button>
                <button id="fontModalAddBtn" class="rounded-md bg-orange-600 text-white px-6 py-2 text-sm font-bold hover:bg-orange-500 transition shadow-sm">Import Font</button>
            </div>
        </div>
    </div>

<script>
// --- UI Layout Script ---
const tabs = document.querySelectorAll('.sidebar-tab');
const panels = document.querySelectorAll('.tab-panel');
tabs.forEach(tab => {
    tab.addEventListener('click', () => {
        tabs.forEach(t => t.classList.remove('active'));
        panels.forEach(p => p.classList.add('hidden'));
        tab.classList.add('active');
        document.getElementById('panel-' + tab.dataset.tab).classList.remove('hidden');
    });
});

// --- Fabric Initialization ---
const sizes = {
    'A4': { width: 1123, height: 794 },     
    'Letter': { width: 1056, height: 816 }  
};

const canvas = new fabric.Canvas('certCanvas', {
    width: sizes['A4'].width,
    height: sizes['A4'].height,
    backgroundColor: '#ffffff',
    selection: true,
    preserveObjectStacking: true 
});

fabric.Object.prototype.set({
    transparentCorners: false,
    cornerColor: '#f97316',
    cornerStrokeColor: '#ffffff',
    borderColor: '#f97316',
    cornerSize: 10, padding: 10,
    cornerStyle: 'circle', borderDashArray: [3, 3]
});

// --- Zoom & Auto Fit Logic ---
let currentZoom = 1;
const wrapper = document.getElementById('canvasWrapper');
const workspace = document.getElementById('workspaceContainer');

function autoFitCanvas() {
    const pWidth = workspace.clientWidth - 100; 
    const pHeight = workspace.clientHeight - 80;
    const scale = Math.min(pWidth/canvas.width, pHeight/canvas.height, 1); 
    currentZoom = scale;
    updateZoom();
}
function updateZoom() {
    wrapper.style.transform = `scale(${currentZoom})`;
    document.getElementById('zoomLabel').textContent = Math.round(currentZoom * 100) + '%';
}
workspace.addEventListener('wheel', (e) => {
    if (e.ctrlKey) {
        e.preventDefault();
        currentZoom += e.deltaY * -0.001;
        currentZoom = Math.min(Math.max(0.1, currentZoom), 3); 
        updateZoom();
    }
}, {passive: false});

window.addEventListener('resize', autoFitCanvas);
setTimeout(autoFitCanvas, 100);

// --- Magnetic Snapping ---
const SNAP_DISTANCE = 15;
let snapLines = [];

canvas.on('object:moving', function(e) {
    const obj = e.target;
    const cx = canvas.width / 2;
    const cy = canvas.height / 2;
    canvas.remove(...snapLines); snapLines = [];

    if (Math.abs(obj.top - cy) < SNAP_DISTANCE) {
        obj.set({ top: cy }).setCoords();
        let hLine = new fabric.Line([0, cy, canvas.width, cy], {
            stroke: '#f97316', strokeWidth: 2, selectable: false, evented: false, 
            strokeDashArray: [5, 5], opacity: 0.6
        });
        snapLines.push(hLine);
        canvas.add(hLine);
    }
    if (Math.abs(obj.left - cx) < SNAP_DISTANCE) {
        obj.set({ left: cx }).setCoords();
        let vLine = new fabric.Line([cx, 0, cx, canvas.height], {
            stroke: '#f97316', strokeWidth: 2, selectable: false, evented: false, 
            strokeDashArray: [5, 5], opacity: 0.6
        });
        snapLines.push(vLine);
        canvas.add(vLine);
    }
});

canvas.on('object:modified', function() { canvas.remove(...snapLines); snapLines = []; });
canvas.on('mouse:up', function() { canvas.remove(...snapLines); snapLines = []; });

// --- Adding Elements ---
function addCanvasText(textRaw, size, isBold = false) {
    const txt = new fabric.IText(textRaw, {
        left: canvas.width / 2, top: canvas.height / 2,
        fontFamily: 'Inter', fontSize: size, fontWeight: isBold ? 'bold' : 'normal',
        fill: '#000000', originX: 'center', originY: 'center', textAlign: 'center'
    });
    canvas.add(txt); canvas.setActiveObject(txt);
}

document.getElementById('addHeading').addEventListener('click', () => addCanvasText('CERTIFICATE TITLE', 60, true));
document.getElementById('addSubheading').addEventListener('click', () => addCanvasText('Subheading Text', 30, false));
document.getElementById('addBodyText').addEventListener('click', () => addCanvasText('Double click to edit text...', 20, false));
document.getElementById('addAutoName').addEventListener('click', () => addCanvasText('{{participant_name}}', 50, true));
document.getElementById('addSignatoryLine').addEventListener('click', () => addCanvasText('_________________________\nAuthorized Signature', 18, false));

function handleImageUpload(e, isBackground = false) {
    const file = e.target.files[0];
    if (!file) return;
    const reader = new FileReader();
    reader.onload = function(f) {
        const data = f.target.result;
        fabric.Image.fromURL(data, function(img) {
            if (isBackground) {
                img.set({ scaleX: canvas.width / img.width, scaleY: canvas.height / img.height, originX: 'left', originY: 'top', left: 0, top: 0 });
                canvas.setBackgroundImage(img, canvas.renderAll.bind(canvas));
            } else {
                img.set({ left: canvas.width / 2, top: canvas.height / 2, originX: 'center', originY: 'center' });
                if (img.width > 250) img.scaleToWidth(250);
                canvas.add(img); canvas.setActiveObject(img);
            }
        });
    };
    reader.readAsDataURL(file);
    e.target.value = ''; 
}

document.getElementById('uploadBg').addEventListener('change', (e) => handleImageUpload(e, true));
document.getElementById('uploadLogo').addEventListener('change', (e) => handleImageUpload(e, false));
document.getElementById('uploadSig').addEventListener('change', (e) => handleImageUpload(e, false));

// --- SYNC TOOLBARS ---
const objToolbar = document.getElementById('objectToolbar');
const textToolbar = document.getElementById('textFormattingBar');

canvas.on('selection:created', syncToolbars);
canvas.on('selection:updated', syncToolbars);
canvas.on('selection:cleared', () => {
    objToolbar.classList.add('opacity-0', 'pointer-events-none');
    textToolbar.classList.add('opacity-30', 'pointer-events-none');
});

function syncToolbars() {
    const obj = canvas.getActiveObject();
    if (!obj) return;
    
    objToolbar.classList.remove('opacity-0', 'pointer-events-none');
    document.getElementById('objOpacity').value = Math.round(obj.opacity * 100);
    document.getElementById('opacityLabel').textContent = Math.round(obj.opacity * 100) + '%';
    document.getElementById('lblLock').textContent = obj.selectable ? 'Lock' : 'Unlock';
    document.getElementById('btnLock').classList.toggle('text-orange-500', !obj.selectable);

    if (obj.text) {
        textToolbar.classList.remove('opacity-30', 'pointer-events-none');
        document.getElementById('fontSize').value = obj.fontSize;
        document.getElementById('fontFamily').value = obj.fontFamily;
        document.getElementById('fontColor').value = obj.fill;
        document.getElementById('btnBold').classList.toggle('active', obj.fontWeight === 'bold');
        document.getElementById('btnItalic').classList.toggle('active', obj.fontStyle === 'italic');
    } else {
        textToolbar.classList.add('opacity-30', 'pointer-events-none');
    }
}

function executeActiveObj(fn) {
    const obj = canvas.getActiveObject();
    if(obj) { fn(obj); canvas.renderAll(); syncToolbars(); }
}

document.getElementById('objOpacity').addEventListener('input', (e) => { let val = e.target.value / 100; executeActiveObj(o => o.set('opacity', val)); });
document.getElementById('btnBringForward').addEventListener('click', () => executeActiveObj(o => canvas.bringForward(o)));
document.getElementById('btnSendBack').addEventListener('click', () => executeActiveObj(o => canvas.sendBackwards(o)));

document.getElementById('btnLock').addEventListener('click', () => {
    executeActiveObj(o => {
        const locked = o.selectable; 
        o.set({
            selectable: !locked, evented: !locked,
            lockMovementX: locked, lockMovementY: locked, lockRotation: locked,
            lockScalingX: locked, lockScalingY: locked
        });
        canvas.discardActiveObject(); 
    });
});

document.getElementById('btnDuplicate').addEventListener('click', () => {
    const obj = canvas.getActiveObject();
    if(!obj) return;
    obj.clone(function(cloned) {
        canvas.discardActiveObject();
        cloned.set({ left: obj.left + 20, top: obj.top + 20, evented: true });
        if (cloned.type === 'activeSelection') {
            cloned.canvas = canvas;
            cloned.forEachObject(function(o) { canvas.add(o); });
            cloned.setCoords();
        } else { canvas.add(cloned); }
        canvas.setActiveObject(cloned); canvas.renderAll();
    });
});

document.getElementById('btnDeleteObj').addEventListener('click', () => {
    const obj = canvas.getActiveObject();
    if(obj) { canvas.remove(...canvas.getActiveObjects()); canvas.discardActiveObject(); }
});

window.addEventListener('keydown', (e) => {
    if (e.key === 'Delete') {
       const obj = canvas.getActiveObject();
       if (obj && !obj.isEditing) { canvas.remove(obj); canvas.discardActiveObject(); }
    }
});

function setStyle(prop, val) { executeActiveObj(o => o.text && o.set(prop, val)); }
document.getElementById('fontSize').addEventListener('change', (e) => setStyle('fontSize', parseInt(e.target.value)));
document.getElementById('btnSizeInc').addEventListener('click', () => { let s = parseInt(document.getElementById('fontSize').value) + 2; document.getElementById('fontSize').value = s; setStyle('fontSize', s); });
document.getElementById('btnSizeDec').addEventListener('click', () => { let s = Math.max(8, parseInt(document.getElementById('fontSize').value) - 2); document.getElementById('fontSize').value = s; setStyle('fontSize', s); });
document.getElementById('fontColor').addEventListener('input', (e) => setStyle('fill', e.target.value));
document.getElementById('fontFamily').addEventListener('change', (e) => setStyle('fontFamily', e.target.value));
document.getElementById('btnBold').addEventListener('click', (e) => executeActiveObj(o => { if(o.text) o.set('fontWeight', o.fontWeight === 'bold' ? 'normal' : 'bold'); }));
document.getElementById('btnItalic').addEventListener('click', (e) => executeActiveObj(o => { if(o.text) o.set('fontStyle', o.fontStyle === 'italic' ? 'normal' : 'italic'); }));
['Left','Center','Right'].forEach(a => { document.getElementById('btnAlign'+a).addEventListener('click', () => setStyle('textAlign', a.toLowerCase())); });

// --- GOOGLE FONTS MODAL LOGIC ---
document.getElementById('btnImportFont').addEventListener('click', () => {
    const m = document.getElementById('fontModal');
    m.classList.remove('hidden');
    setTimeout(() => { m.classList.remove('opacity-0'); document.getElementById('fontModalContent').classList.remove('scale-95'); }, 10);
});
document.getElementById('fontModalAddBtn').addEventListener('click', () => {
    const url = document.getElementById('fontUrlInput').value.trim();
    if (!url) return;
    const match = url.match(/family=([^:&]+)/);
    if (!match || !match[1]) return alert('Invalid Google Fonts URL');
    
    const fontFamily = match[1].replace(/\+/g, ' ');
    const link = document.createElement('link'); link.href = url; link.rel = 'stylesheet';
    document.head.appendChild(link);
    
    const sel = document.getElementById('fontFamily');
    let exists = false;
    for (let i=0; i<sel.options.length; i++) if(sel.options[i].value === fontFamily) exists = true;
    
    if(!exists) {
        const opt = document.createElement('option'); opt.value = fontFamily; opt.textContent = fontFamily; opt.style.fontFamily = fontFamily;
        sel.appendChild(opt);
    }
    sel.value = fontFamily; setStyle('fontFamily', fontFamily);
    
    document.getElementById('fontModalContent').classList.add('scale-95');
    document.getElementById('fontModal').classList.add('opacity-0');
    setTimeout(() => { document.getElementById('fontModal').classList.add('hidden'); }, 300);
});

// --- DOWNLOAD AND SAVE LOGIC ---
document.getElementById('pageSize').addEventListener('change', (e) => {
    const dim = sizes[e.target.value]; canvas.setWidth(dim.width); canvas.setHeight(dim.height);
    canvas.renderAll(); autoFitCanvas();
});

document.getElementById('btnPreview').addEventListener('click', () => {
    canvas.discardActiveObject(); canvas.renderAll();
    const dataURL = canvas.toDataURL({ format: 'png', quality: 1, multiplier: 2 });
    let link = document.createElement('a'); link.download = `Certificate_Preview_${Date.now()}.png`;
    link.href = dataURL; document.body.appendChild(link); link.click(); document.body.removeChild(link);
});

document.getElementById('btnSaveTemplate').addEventListener('click', async () => {
    const jsonState = canvas.toJSON();
    const name = prompt("Enter a name for this Certificate Template:", "Custom Draft 1");
    if (!name) return;
    
    const btn = document.getElementById('btnSaveTemplate');
    const originalText = btn.innerHTML;
    btn.innerHTML = `<svg class="w-4 h-4 animate-spin" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0A9.928 9.928 0 013 12c0-5.523 4.477-10 10-10a9.928 9.928 0 017.015 2.822M3 12c0 5.523 4.477 10 10 10a9.928 9.928 0 0110-10"/></svg> Saving...`;
    btn.disabled = true;

    try {
        const res = await fetch('/api/certificate_save.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                 event_id: '<?php echo htmlspecialchars($eventId); ?>',
                 name: name,
                 canvas_state: jsonState,
                 csrf_token: <?php echo json_encode($_SESSION['csrf_token'] ?? ''); ?>
            })
        });
        const data = await res.json();
        if(!data.ok) throw new Error(data.error || 'Failed to save layout');
        
        // Show success and reload to fetch new templates
        btn.innerHTML = `<svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/></svg> Saved!`;
        btn.classList.replace('from-orange-500', 'from-emerald-500');
        btn.classList.replace('to-red-500', 'to-emerald-400');
        
        setTimeout(() => window.location.reload(), 1000);
    } catch(err) {
        alert("Error saving: " + err.message);
        btn.innerHTML = originalText;
        btn.disabled = false;
    }
});

// --- WORKSPACE LOAD SAVED TEMPLATE ---
document.querySelectorAll('.custom-template-card').forEach(card => {
    card.addEventListener('click', () => {
        if(!confirm("Loading this template will overwrite your current canvas workspace. Continue?")) return;
        try {
            const rawJson = card.dataset.json;
            if(!rawJson) return;
            const parsed = JSON.parse(rawJson);
            
            canvas.clear();
            canvas.loadFromJSON(parsed, () => {
                canvas.renderAll();
                autoFitCanvas();
            });
        } catch(e) {
            alert('Failed to parse the saved template data.');
            console.error(e);
        }
    });
});

// --- TEMPLATES LOGIC (PRESETS: PAGE 31 FEATURE) ---
const presetCards = document.querySelectorAll('.template-card');

function loadTemplatePreset(type) {
    if(!confirm("Loading a template will clear your current canvas. Are you sure?")) return;
    
    canvas.clear();
    // Default config restoration
    canvas.backgroundColor = '#ffffff';

    if (type === 'classic-green') {
        const title = new fabric.IText('CERTIFICATE OF PARTICIPATION', {
            left: canvas.width / 2, top: 300,
            fontFamily: 'Georgia', fontSize: 60, fontWeight: 'bold', fill: '#064e3b',
            originX: 'center', originY: 'center', textAlign: 'center'
        });
        const sub1 = new fabric.IText('THIS CERTIFICATE IS PROUDLY PRESENTED TO', {
            left: canvas.width / 2, top: 380,
            fontFamily: 'Inter', fontSize: 20, fontWeight: 'normal', fill: '#3f3f46',
            originX: 'center', originY: 'center', textAlign: 'center'
        });
        const name = new fabric.IText('{{participant_name}}', {
            left: canvas.width / 2, top: 480,
            fontFamily: 'Inter', fontSize: 75, fontWeight: 'bold', fill: '#f97316',
            originX: 'center', originY: 'center', textAlign: 'center'
        });
        const sub2 = new fabric.IText('For outstanding participation and completion of the event program.', {
            left: canvas.width / 2, top: 580,
            fontFamily: 'Inter', fontSize: 18, fontWeight: 'normal', fill: '#71717a',
            originX: 'center', originY: 'center', textAlign: 'center'
        });
        
        // Add decorative bars
        var rectTop = new fabric.Rect({
            left: 0, top: 0, fill: '#064e3b', width: canvas.width, height: 40, selectable: false
        });
        var rectBot = new fabric.Rect({
            left: 0, top: canvas.height - 40, fill: '#f97316', width: canvas.width, height: 40, selectable: false
        });

        canvas.add(rectTop, rectBot, title, sub1, name, sub2);

    } else if (type === 'nutrition-month') {
        // Build a massive orange-red style template
        const title = new fabric.IText('NUTRITION MONTH 2026', {
            left: canvas.width / 2, top: 250,
            fontFamily: 'Inter', fontSize: 50, fontWeight: 'bold', fill: '#c2410c',
            originX: 'center', originY: 'center', textAlign: 'center'
        });
        const name = new fabric.IText('{{participant_name}}', {
            left: canvas.width / 2, top: 380,
            fontFamily: 'Georgia', fontSize: 80, fontWeight: 'bold', fill: '#000000',
            originX: 'center', originY: 'center', textAlign: 'center', fontStyle: 'italic'
        });
        const reason = new fabric.IText('Awarded for joining the school\'s diet consciousness initiative.', {
            left: canvas.width / 2, top: 480,
            fontFamily: 'Inter', fontSize: 22, fontWeight: 'normal', fill: '#3f3f46',
            originX: 'center', originY: 'center', textAlign: 'center'
        });
        
        var circle = new fabric.Circle({
            radius: 150, fill: '#fb923c', left: -50, top: -50, opacity: 0.2, selectable: false
        });

        canvas.add(circle, title, name, reason);
    }
    
    canvas.renderAll();
}

presetCards.forEach(card => {
    card.addEventListener('click', () => {
        const type = card.getAttribute('data-preset');
        loadTemplatePreset(type);
    });
});
</script>
</body>
</html>
