<?php
declare(strict_types=1);
session_start();

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/supabase.php';
require_once __DIR__ . '/includes/event_sessions.php';

$user = require_role(['admin', 'teacher']);
$eventId = isset($_GET['event_id']) ? (string) $_GET['event_id'] : '';
if ($eventId === '' && isset($_GET['id'])) {
    $eventId = (string) $_GET['id'];
}
$templateId = isset($_GET['template_id']) ? (string) $_GET['template_id'] : '';
$sessionId = isset($_GET['session_id']) ? trim((string) $_GET['session_id']) : '';

$headers = [
    'apikey: ' . SUPABASE_KEY,
    'Authorization: Bearer ' . SUPABASE_KEY
];

$eventName = 'Sample Event One';
$sessions = [];
$isSeminarBasedForCertificates = false;
if ($eventId !== '') {
    $url = rtrim(SUPABASE_URL, '/') . '/rest/v1/events?select=title&id=eq.' . rawurlencode($eventId);
    $res = supabase_request('GET', $url, $headers);
    if ($res['ok']) {
        $arr = json_decode((string) $res['body'], true);
        if (is_array($arr) && isset($arr[0]['title'])) {
            $eventName = $arr[0]['title'];
        }
    }

    $sessions = fetch_event_sessions($eventId, $headers);
    $isSeminarBasedForCertificates = count($sessions) > 0;
    $knownSessionIds = array_map(static fn (array $session): string => (string) ($session['id'] ?? ''), $sessions);
    if ($sessionId !== '' && !in_array($sessionId, $knownSessionIds, true)) {
        $sessionId = '';
    }
    if ($sessionId === '' && $isSeminarBasedForCertificates) {
        $sessionId = (string) ($sessions[0]['id'] ?? '');
    }
}

// Fetch saved templates for this workspace
$customTemplates = [];
if ($eventId !== '') {
    // For seminar-based certificate flow, templates are per-session only.
    // Whole-event templates remain for simple events.
    if (!$isSeminarBasedForCertificates) {
        $eventTplUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/certificate_templates?select=id,title,canvas_state,thumbnail_url&event_id=eq.' . rawurlencode($eventId) . '&order=created_at.desc';
        $eventTplRes = supabase_request('GET', $eventTplUrl, $headers);
        if ($eventTplRes['ok']) {
            $arrTpl = json_decode((string) $eventTplRes['body'], true);
            if (is_array($arrTpl)) {
                foreach ($arrTpl as $tpl) {
                    if (!is_array($tpl)) {
                        continue;
                    }
                    $customTemplates[] = [
                        ...$tpl,
                        'template_scope' => 'event',
                        'scope_session_id' => '',
                        'scope_label' => 'Whole Event',
                    ];
                }
            }
        }
    }

    if (count($sessions) > 0) {
        $sessionIds = array_values(array_filter(array_map(
            static fn (array $session): string => (string) ($session['id'] ?? ''),
            $sessions
        )));

        if (count($sessionIds) > 0) {
            $sessionTplUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/event_session_certificate_templates'
                . '?select=id,title,canvas_state,thumbnail_url,session_id,event_sessions(title,topic)'
                . '&session_id=in.(' . implode(',', array_map('rawurlencode', $sessionIds)) . ')'
                . '&order=created_at.desc';
            $sessionTplRes = supabase_request('GET', $sessionTplUrl, $headers);
            if ($sessionTplRes['ok']) {
                $sessionTplRows = json_decode((string) $sessionTplRes['body'], true);
                if (is_array($sessionTplRows)) {
                    foreach ($sessionTplRows as $tpl) {
                        if (!is_array($tpl)) {
                            continue;
                        }
                        $sessionMeta = isset($tpl['event_sessions']) && is_array($tpl['event_sessions'])
                            ? $tpl['event_sessions']
                            : [];
                        $customTemplates[] = [
                            ...$tpl,
                            'template_scope' => 'session',
                            'scope_session_id' => (string) ($tpl['session_id'] ?? ''),
                            'scope_label' => build_session_display_name($sessionMeta),
                        ];
                    }
                }
            }
        }
    }
} else {
    // Fetch all recent templates if no event context
    $limit = $templateId !== '' ? 20 : 10;
    $tplUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/certificate_templates?select=id,title,canvas_state,thumbnail_url&order=created_at.desc&limit=' . $limit;
    $tplRes = supabase_request('GET', $tplUrl, $headers);
    if ($tplRes['ok']) {
        $arrTpl = json_decode((string) $tplRes['body'], true);
        if (is_array($arrTpl)) {
            $customTemplates = $arrTpl;
        }
    }
}

if ($templateId !== '' && count($customTemplates) > 0) {
    foreach ($customTemplates as $tpl) {
        if ((string) ($tpl['id'] ?? '') !== $templateId) {
            continue;
        }
        if ((string) ($tpl['template_scope'] ?? 'event') === 'session') {
            $sessionId = (string) ($tpl['scope_session_id'] ?? '');
        }
        break;
    }
}

$initialTemplateScope = $isSeminarBasedForCertificates ? 'session' : ($sessionId !== '' ? 'session' : 'event');
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
        html, body { 
            height: 100% !important; 
            min-height: 100% !important;
            max-height: 100% !important;
            margin: 0 !important; 
            padding: 0 !important; 
            overflow: hidden !important;
            overscroll-behavior: none !important;
            position: fixed !important;
            width: 100% !important;
        }
        body { font-family: 'Inter', sans-serif; }
        
        /* Fix for Fabric.js hidden textarea causing scroll jumps */
        .canvas-container textarea, 
        .canvas-container .hiddenTextarea,
        body > textarea,
        #workspaceContainer textarea {
            position: fixed !important;
            top: 0 !important;
            left: 0 !important;
            width: 1px !important;
            height: 1px !important;
            padding: 0 !important;
            margin: 0 !important;
            opacity: 0 !important;
            pointer-events: none !important;
            z-index: -1 !important;
            border: none !important;
            outline: none !important;
            background: transparent !important;
        }
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
        
        .template-card.active-template { 
            border-color: #f97316 !important; 
            background-color: rgba(249, 115, 22, 0.05); 
            box-shadow: 0 0 15px rgba(249, 115, 22, 0.15);
        }

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

        .delete-tpl-btn {
            position: absolute; top: 6px; right: 6px; z-index: 10;
            width: 24px; height: 24px; border-radius: 4px;
            background: rgba(18, 18, 20, 0.8); border: 1px solid rgba(239, 68, 68, 0.2);
            color: #ef4444; display: flex; align-items: center; justify-content: center;
            opacity: 0; transition: all 0.2s;
        }
        .custom-template-card:hover .delete-tpl-btn { opacity: 1; }
        .delete-tpl-btn:hover { background: #ef4444; color: white; transform: scale(1.1); }
    </style>
</head>
<body class="fixed inset-0 h-screen w-screen overflow-hidden flex flex-col bg-[#121214] text-zinc-100 selection:bg-orange-500/30">

    <!-- TOP NAV / HEADER (Soft Dark) -->
    <div class="h-14 bg-[#0a0a0c] border-b border-zinc-800 text-white flex items-center justify-between px-6 z-40 flex-shrink-0 relative">
        <div class="flex items-center gap-4">
             <a href="<?= $eventId ? '/event_view.php?id='.htmlspecialchars($eventId) : '/admin_certificates.php' ?>" class="flex items-center gap-2 text-zinc-400 hover:text-white transition-colors text-sm font-semibold group">
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
    <div class="h-14 bg-[#18181b] border-b border-zinc-800 flex items-center px-4 z-30 flex-shrink-0 shadow-md transition-all overflow-x-auto gap-3">
        <select id="pageSize" class="select-clean text-sm font-medium cursor-pointer w-[120px] flex-shrink-0">
            <option value="A4">A4 Landscape</option>
            <option value="Letter">Letter Landscape</option>
        </select>
        <div class="h-6 w-px bg-zinc-700 flex-shrink-0"></div>
        <div id="textFormattingBar" class="flex items-center gap-3 opacity-30 pointer-events-none transition-opacity duration-200 shrink-0">

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

             <button id="btnUndo" class="flex items-center gap-1.5 text-zinc-400 hover:text-white hover:bg-zinc-800 px-3 py-1.5 rounded-md text-xs font-semibold transition border border-transparent opacity-50 pointer-events-none" title="Undo (Ctrl+Z)">
                 <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 14L4 9m0 0l5-5M4 9h9a7 7 0 110 14h-1"/></svg> Undo
             </button>

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
    <div class="flex-1 min-h-0 flex overflow-hidden">
        
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
                    <?php if ($eventId !== '' && count($sessions) > 0): ?>
                    <div class="rounded-lg border border-zinc-800 bg-[#18181b] p-3 shadow-sm">
                        <div class="text-[11px] font-bold text-zinc-500 uppercase tracking-widest mb-2">Template Scope</div>
                        <select id="templateScopeSelect" class="select-clean w-full text-sm font-semibold">
                            <?php if (!$isSeminarBasedForCertificates): ?>
                            <option value="event" <?= $initialTemplateScope === 'event' ? 'selected' : '' ?>>Whole Event</option>
                            <?php endif; ?>
                            <?php foreach ($sessions as $session): ?>
                                <?php
                                    $scopeValue = 'session:' . (string) ($session['id'] ?? '');
                                    $selectedScope = $initialTemplateScope === 'session' && (string) ($session['id'] ?? '') === $sessionId;
                                ?>
                                <option value="<?= htmlspecialchars($scopeValue) ?>" <?= $selectedScope ? 'selected' : '' ?>>
                                    <?= htmlspecialchars(build_session_display_name($session)) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <p id="templateScopeHint" class="mt-2 text-[11px] text-zinc-400 leading-relaxed">
                            Save and browse templates for the selected certificate scope.
                        </p>
                    </div>
                    <?php endif; ?>
                    
                    <h3 class="text-xs font-bold text-zinc-100 flex items-center gap-2">
                        <svg class="w-4 h-4 text-orange-500" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6.042A8.967 8.967 0 006 3.75c-1.052 0-2.062.18-3 .512v14.25A8.987 8.987 0 016 18c2.305 0 4.408.867 6 2.292m0-14.25a8.966 8.966 0 016-2.292c1.052 0 2.062.18 3 .512v14.25A8.987 8.987 0 0018 18a8.967 8.967 0 00-6 2.292m0-14.25v14.25"/></svg>
                        Saved Templates
                    </h3>
                    <?php if (count($customTemplates) > 0): ?>
                    <?php foreach ($customTemplates as $tpl): ?>
                        <?php
                            $cardScope = (string) ($tpl['template_scope'] ?? 'event');
                            $cardSessionId = (string) ($tpl['scope_session_id'] ?? '');
                            $cardScopeLabel = (string) ($tpl['scope_label'] ?? ($cardScope === 'session' ? 'Seminar' : 'Whole Event'));
                        ?>
                        <div
                            class="custom-template-card border border-zinc-700 bg-[#18181b] rounded-lg p-2 cursor-pointer transition-all flex flex-col gap-2 shadow-sm hover:border-orange-500 relative group"
                            data-json="<?= htmlspecialchars(json_encode($tpl['canvas_state'])) ?>"
                            data-id="<?= htmlspecialchars($tpl['id']) ?>"
                            data-scope="<?= htmlspecialchars($cardScope) ?>"
                            data-session-id="<?= htmlspecialchars($cardSessionId) ?>"
                            data-scope-label="<?= htmlspecialchars($cardScopeLabel) ?>"
                        >
                            <button class="delete-tpl-btn" onclick="event.stopPropagation(); deleteCustomTemplate('<?= htmlspecialchars($tpl['id']) ?>', this.parentElement, '<?= htmlspecialchars($cardScope) ?>', '<?= htmlspecialchars($cardSessionId) ?>')">
                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                            </button>
                            <div class="w-full h-32 bg-zinc-900 rounded border border-zinc-800 overflow-hidden relative pointer-events-none flex items-center justify-center">
                                <?php if (!empty($tpl['thumbnail_url'])): ?>
                                    <img src="<?= htmlspecialchars($tpl['thumbnail_url']) ?>" class="w-full h-full object-cover">
                                <?php else: ?>
                                    <div class="text-[10px] text-zinc-600 font-bold uppercase tracking-widest">No Preview</div>
                                <?php endif; ?>
                            </div>
                            <div class="flex items-center justify-between gap-2 px-1">
                                <div class="text-xs font-semibold text-zinc-300 truncate"><?= htmlspecialchars((string)$tpl['title']) ?></div>
                                <span class="shrink-0 rounded-full border border-zinc-700 bg-zinc-900 px-2 py-0.5 text-[10px] font-bold uppercase tracking-widest text-zinc-400"><?= htmlspecialchars($cardScopeLabel) ?></span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                    <div id="savedTemplatesEmpty" class="hidden rounded-lg border border-dashed border-zinc-700 bg-[#18181b] px-4 py-6 text-center text-[11px] text-zinc-500 font-semibold">
                        No saved templates for the selected scope yet.
                    </div>
                    <div class="h-4"></div>
                    <?php else: ?>
                    <div id="savedTemplatesEmpty" class="rounded-lg border border-dashed border-zinc-700 bg-[#18181b] px-4 py-6 text-center text-[11px] text-zinc-500 font-semibold">
                        No saved templates for the selected scope yet.
                    </div>
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

                    <!-- Template 2: CCS Event Template (Updated from Nutrition Month) -->
                    <div class="template-card border border-zinc-700 bg-[#18181b] rounded-lg p-2 cursor-pointer transition-all flex flex-col gap-2 shadow-sm" data-preset="nutrition-month">
                        <div class="w-full h-32 bg-orange-900 rounded border border-zinc-800 flex flex-col items-center justify-center p-4 relative overflow-hidden pointer-events-none">
                            <div class="absolute inset-0 bg-gradient-to-tr from-amber-700 to-orange-500 opacity-60"></div>
                            <div class="w-16 h-16 rounded-full border-2 border-white/20 absolute -right-8 -top-8"></div>
                            <div class="text-[8px] font-bold text-orange-100 uppercase tracking-widest relative mt-2">CCS EVENT 2026</div>
                            <div class="text-[13px] font-serif font-black text-white leading-none relative mt-1">{{participant_name}}</div>
                        </div>
                        <div class="text-xs font-semibold text-zinc-300 text-center">CCS Event Template</div>
                    </div>
                </div>

                <div id="panel-bg" class="tab-panel hidden flex flex-col gap-4">
                    <label class="w-full flex items-center justify-center gap-2 rounded-xl border-2 border-dashed border-zinc-700 bg-zinc-800/30 hover:bg-orange-500/10 hover:border-orange-500/50 cursor-pointer p-6 text-sm font-bold text-zinc-300 shadow-sm transition-all group">
                        <svg class="w-6 h-6 text-zinc-500 group-hover:text-orange-500 transition" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5m-13.5-9L12 3m0 0l4.5 4.5M12 3v13.5"/></svg>
                        Upload Background
                        <input type="file" id="uploadBg" class="hidden" accept="image/*">
                    </label>
                    <button id="btnRemoveBackground" class="w-full py-2.5 bg-red-500/10 text-red-500 border border-red-500/20 rounded-md text-xs font-semibold hover:bg-red-500 hover:text-white transition shadow-sm">Remove Background</button>
                    
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


    <!-- SAVE LAYOUT MODAL -->
    <div id="saveLayoutModal" class="fixed inset-0 z-[100] flex items-center justify-center bg-black/70 backdrop-blur-md hidden opacity-0 transition-all duration-300">
        <div id="saveLayoutModalContent" class="bg-[#121214] border border-zinc-800 rounded-2xl w-full max-w-sm overflow-hidden shadow-2xl transform scale-90 transition-all duration-300">
            <div class="px-6 py-5 border-b border-zinc-800 flex items-center justify-between bg-[#18181b]">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-xl bg-orange-500/10 border border-orange-500/20 flex items-center justify-center text-orange-500">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M17.5 3h-11a2 2 0 00-2 2v14a2 2 0 002 2h11a2 2 0 002-2v-14a2 2 0 00-2-2z"/><path stroke-linecap="round" stroke-linejoin="round" d="M9 13h6m-3-3v6"/></svg>
                    </div>
                    <div>
                        <h3 class="text-base font-bold text-zinc-100 leading-none">Save Template</h3>
                        <p class="text-[11px] text-zinc-500 mt-1 uppercase tracking-wider font-semibold">Workspace Layout</p>
                    </div>
                </div>
            </div>
            <div class="p-6 space-y-4">
                <div>
                   <label class="block text-[11px] font-bold text-zinc-500 mb-2 uppercase tracking-widest">Template Name</label>
                   <input type="text" id="saveTemplateName" placeholder="e.g., Certificate of Participation" class="w-full rounded-lg border border-zinc-700 bg-zinc-900 px-4 py-3 text-sm text-zinc-100 outline-none focus:border-orange-500 focus:ring-2 focus:ring-orange-500/20 transition shadow-inner">
                </div>
            </div>
            <div class="px-6 py-4 border-t border-zinc-800 bg-[#18181b] flex items-center justify-end gap-3">
                <button id="btnCancelSave" class="px-4 py-2 font-bold text-xs text-zinc-500 hover:text-white transition uppercase tracking-widest">Cancel</button>
                <button id="btnConfirmSave" class="rounded-lg bg-orange-600 text-white px-6 py-2.5 text-xs font-black uppercase tracking-widest hover:bg-orange-500 transition shadow-lg shadow-orange-600/20 active:scale-95">Save Layout</button>
            </div>
        </div>
    </div>

    <!-- TOAST NOTIFICATION CONTAINER -->
    <div id="notificationContainer" class="fixed bottom-6 right-6 z-[200] flex flex-col gap-3"></div>

<script>
// ============================================================
//  CERTIFICATE EDITOR — COMPREHENSIVE FIX
// ============================================================

// --- Custom Confirm Modal (replaces browser confirm() which can be suppressed) ---
function showConfirm(message, onOk) {
    const existing = document.getElementById('customConfirmModal');
    if (existing) existing.remove();
    const modal = document.createElement('div');
    modal.id = 'customConfirmModal';
    modal.style.cssText = 'position:fixed;inset:0;z-index:9999;display:flex;align-items:center;justify-content:center;background:rgba(0,0,0,.65);backdrop-filter:blur(4px)';
    modal.innerHTML = `<div style="background:#18181b;border:1px solid #3f3f46;border-radius:16px;padding:24px;max-width:360px;width:90%;box-shadow:0 25px 60px rgba(0,0,0,.6)">
        <div style="display:flex;gap:12px;margin-bottom:20px">
            <div style="width:36px;height:36px;border-radius:10px;background:rgba(249,115,22,.12);border:1px solid rgba(249,115,22,.3);display:flex;align-items:center;justify-content:center;flex-shrink:0">
                <svg width="16" height="16" fill="none" stroke="#f97316" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z"/></svg>
            </div>
            <div>
                <div style="font-size:13px;font-weight:700;color:#f4f4f5;margin-bottom:4px">Unsaved Changes</div>
                <div style="font-size:12px;color:#a1a1aa;line-height:1.5">${message}</div>
            </div>
        </div>
        <div style="display:flex;gap:8px;justify-content:flex-end">
            <button id="_cfCancel" style="padding:8px 16px;font-size:12px;font-weight:700;color:#a1a1aa;background:#27272a;border:1px solid #3f3f46;border-radius:8px;cursor:pointer">Keep Editing</button>
            <button id="_cfOk" style="padding:8px 16px;font-size:12px;font-weight:700;color:#fff;background:#f97316;border:none;border-radius:8px;cursor:pointer">Discard & Load</button>
        </div>
    </div>`;
    document.body.appendChild(modal);
    document.getElementById('_cfOk').onclick     = () => { modal.remove(); onOk(); };
    document.getElementById('_cfCancel').onclick  = () => modal.remove();
    modal.onclick = (e) => { if (e.target === modal) modal.remove(); };
}

// --- Save Layout Modal ---
function showSaveLayoutModal(onSave) {
    const modal = document.getElementById('saveLayoutModal');
    const content = document.getElementById('saveLayoutModalContent');
    const nameInput = document.getElementById('saveTemplateName');
    
    modal.classList.remove('hidden');
    setTimeout(() => {
        modal.classList.remove('opacity-0');
        content.classList.remove('scale-90');
        nameInput.focus();
    }, 10);

    const close = () => {
        modal.classList.add('opacity-0');
        content.classList.add('scale-90');
        setTimeout(() => modal.classList.add('hidden'), 300);
    };

    document.getElementById('btnCancelSave').onclick = close;
    document.getElementById('btnConfirmSave').onclick = () => {
        const name = nameInput.value.trim();
        if (!name) return showNotification('Please enter a template name', 'error');
        onSave(name);
        close();
    };
    
    // Enter key support
    nameInput.onkeydown = (e) => {
        if (e.key === 'Enter') document.getElementById('btnConfirmSave').click();
    };
}

// --- Toast Notifications ---
function showNotification(message, type = 'success') {
    const container = document.getElementById('notificationContainer');
    const toast = document.createElement('div');
    toast.className = `flex items-center gap-3 px-5 py-4 rounded-2xl border shadow-2xl transform translate-y-10 opacity-0 transition-all duration-500 min-w-[320px] backdrop-blur-xl ${
        type === 'success' 
        ? 'bg-emerald-950/95 border-emerald-500/40 text-emerald-50' 
        : 'bg-red-950/95 border-red-500/40 text-red-50'
    }`;
    
    const icon = type === 'success' 
        ? '<div class="flex-shrink-0 w-8 h-8 rounded-full bg-emerald-500/20 flex items-center justify-center text-emerald-400"><svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg></div>'
        : '<div class="flex-shrink-0 w-8 h-8 rounded-full bg-red-500/20 flex items-center justify-center text-red-400"><svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z"/></svg></div>';
        
    toast.innerHTML = `${icon}<span class="text-sm font-bold tracking-tight">${message}</span>`;
    container.appendChild(toast);
    
    setTimeout(() => { toast.classList.remove('translate-y-10', 'opacity-0'); }, 10);
    setTimeout(() => {
        toast.classList.add('translate-y-[-20px]', 'opacity-0');
        setTimeout(() => toast.remove(), 500);
    }, 4000);
}

// --- Sidebar Tabs ---
const tabs   = document.querySelectorAll('.sidebar-tab');
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
const sizes = { 'A4': { width: 1123, height: 794 }, 'Letter': { width: 1056, height: 816 } };

const canvas = new fabric.Canvas('certCanvas', {
    width: sizes['A4'].width, height: sizes['A4'].height,
    backgroundColor: '#ffffff', selection: true, preserveObjectStacking: true
});

// CRITICAL FIX: Prevent "stuck up" layout shift on text edit
// Force Fabric to place its hidden textarea inside the workspace instead of body
canvas.hiddenTextareaParentElement = document.getElementById('workspaceContainer');
window.canvas = canvas; // Ensure global access

fabric.Object.prototype.set({
    transparentCorners: false, cornerColor: '#f97316', cornerStrokeColor: '#ffffff',
    borderColor: '#f97316', cornerSize: 10, padding: 10,
    cornerStyle: 'circle', borderDashArray: [3, 3]
});

// --- Dirty State Tracking ---
let isCanvasDirty = false;
let isProgrammaticChange = false;

// --- Undo History ---
const HISTORY_MAX = 80;
const historyStack = [];
let historyIndex = -1;
let historySuspend = false;
const btnUndo = document.getElementById('btnUndo');

function currentCanvasSnapshot() {
    const state = canvas.toDatalessJSON([
        'lockMovementX', 'lockMovementY', 'lockRotation', 'lockScalingX', 'lockScalingY',
        'hasControls', 'hoverCursor'
    ]);
    state.customFonts = (typeof activeCustomFonts !== 'undefined' && Array.isArray(activeCustomFonts))
        ? activeCustomFonts
        : [];
    return JSON.stringify(state);
}

function updateUndoUi() {
    if (!btnUndo) return;
    const canUndo = historyIndex > 0;
    btnUndo.classList.toggle('opacity-50', !canUndo);
    btnUndo.classList.toggle('pointer-events-none', !canUndo);
    btnUndo.classList.toggle('text-zinc-400', !canUndo);
    btnUndo.classList.toggle('text-zinc-300', canUndo);
    btnUndo.title = canUndo ? `Undo (${historyIndex} step${historyIndex > 1 ? 's' : ''})` : 'Undo (Ctrl+Z)';
}

function pushHistoryState(force = false) {
    if (isProgrammaticChange || historySuspend) return;
    const snapshot = currentCanvasSnapshot();
    if (!force && historyIndex >= 0 && historyStack[historyIndex] === snapshot) return;

    if (historyIndex < historyStack.length - 1) {
        historyStack.splice(historyIndex + 1);
    }
    historyStack.push(snapshot);
    if (historyStack.length > HISTORY_MAX) {
        historyStack.shift();
    } else {
        historyIndex += 1;
    }
    if (historyStack.length > 0 && historyIndex >= historyStack.length) {
        historyIndex = historyStack.length - 1;
    }
    updateUndoUi();
}

function resetHistoryBaseline() {
    historyStack.length = 0;
    historyIndex = -1;
    pushHistoryState(true);
    isCanvasDirty = false;
}

function undoCanvasState() {
    if (historyIndex <= 0) return;
    historyIndex -= 1;
    updateUndoUi();
    historySuspend = true;
    isProgrammaticChange = true;
    clearSnapLines();
    const snapshot = historyStack[historyIndex];
    canvas.loadFromJSON(JSON.parse(snapshot), () => {
        canvas.renderAll();
        syncToolbars();
        isProgrammaticChange = false;
        historySuspend = false;
        isCanvasDirty = historyIndex > 0;
    });
}

canvas.on('object:added', () => {
    if (!isProgrammaticChange) {
        isCanvasDirty = true;
        pushHistoryState();
    }
});
canvas.on('object:modified', () => {
    if (!isProgrammaticChange) {
        isCanvasDirty = true;
        pushHistoryState();
    }
});
canvas.on('object:removed', () => {
    if (!isProgrammaticChange) {
        isCanvasDirty = true;
        pushHistoryState();
    }
});

window.addEventListener('beforeunload', e => { if (isCanvasDirty) { e.preventDefault(); e.returnValue = ''; } });

// --- Zoom & Auto-Fit ---
let currentZoom = 1;
const wrapper   = document.getElementById('canvasWrapper');
const workspace = document.getElementById('workspaceContainer');

function autoFitCanvas() {
    const scale = Math.min((workspace.clientWidth - 100) / canvas.width, (workspace.clientHeight - 80) / canvas.height, 1);
    currentZoom = scale; updateZoom();
}
function updateZoom() {
    wrapper.style.transform = `scale(${currentZoom})`;
    document.getElementById('zoomLabel').textContent = Math.round(currentZoom * 100) + '%';
}
workspace.addEventListener('wheel', e => {
    if (e.ctrlKey) { e.preventDefault(); currentZoom = Math.min(Math.max(0.1, currentZoom + e.deltaY * -0.001), 3); updateZoom(); }
}, { passive: false });
window.addEventListener('resize', autoFitCanvas);
// --- Global State for Custom Fonts ---
const FONT_STORAGE_KEY = `custom_fonts_event_<?php echo $eventId; ?>`;
let activeCustomFonts = [];

/**
 * Register a custom Google Font in the document and UI.
 * @param {string} url - Google Fonts CSS URL
 * @param {string} familyName - Font Family Name
 * @param {boolean} skipSave - If true, don't update localStorage (used during initial load)
 */
window.registerCustomFont = (url, familyName, skipSave = false) => {
    if (!url || !familyName) return;
    // 1. Inject Link tag to head if not exists
    if (!document.querySelector(`link[href*="${url}"]`)) {
        const link = document.createElement('link');
        link.rel = 'stylesheet';
        link.href = url;
        document.head.appendChild(link);
    }

    // 2. Add to dropdown if not exists
    const selector = document.getElementById('fontFamily');
    const exists = Array.from(selector.options).some(o => o.value === familyName);
    
    if (!exists) {
        const option = document.createElement('option');
        option.value = familyName;
        option.textContent = familyName;
        option.style.fontFamily = `"${familyName}"`;
        selector.appendChild(option);
    }

    // 3. Track in active fonts list (unique)
    if (!activeCustomFonts.find(f => f.url === url)) {
        activeCustomFonts.push({ url, family: familyName });
    }

    // 4. Persistence: Save to LocalStorage for this event
    if (!skipSave) {
        try {
            const saved = JSON.parse(localStorage.getItem(FONT_STORAGE_KEY) || '[]');
            if (!saved.find(f => f.url === url)) {
                saved.push({ url, family: familyName });
                localStorage.setItem(FONT_STORAGE_KEY, JSON.stringify(saved));
            }
        } catch (e) { console.error('LocalStorage Font Save Failed:', e); }
    }
};

/**
 * Harvest all custom fonts from saved templates and localStorage on load.
 */
function initializePersistentFonts() {
    // A. Harvest from saved template cards already in the DOM
    document.querySelectorAll('.custom-template-card').forEach(card => {
        try {
            const data = JSON.parse(card.dataset.json || '{}');
            if (data.customFonts && Array.isArray(data.customFonts)) {
                data.customFonts.forEach(f => window.registerCustomFont(f.url, f.family, true));
            }
        } catch(e) {}
    });

    // B. Restore from LocalStorage (for fonts added but not yet saved in a template)
    try {
        const saved = JSON.parse(localStorage.getItem(FONT_STORAGE_KEY) || '[]');
        saved.forEach(f => window.registerCustomFont(f.url, f.family, true));
    } catch(e) {}
}

// Initialize everything on load
initializePersistentFonts();
setTimeout(autoFitCanvas, 150);
setTimeout(resetHistoryBaseline, 0);
btnUndo?.addEventListener('click', undoCanvasState);
updateUndoUi();

// --- Magnetic Snap Lines ---
const SNAP = 15;
let snapLines = [];

function clearSnapLines() {
    isProgrammaticChange = true;
    canvas.remove(...snapLines); snapLines = [];
    isProgrammaticChange = false;
}

canvas.on('object:moving', e => {
    const obj = e.target, cx = canvas.width / 2, cy = canvas.height / 2;
    clearSnapLines();
    const addSnap = (coords) => {
        isProgrammaticChange = true;
        const ln = new fabric.Line(coords, { stroke: '#f97316', strokeWidth: 2, selectable: false, evented: false, strokeDashArray: [5,5], opacity: 0.6 });
        snapLines.push(ln); canvas.add(ln);
        isProgrammaticChange = false;
    };
    if (Math.abs(obj.top  - cy) < SNAP) { obj.set({ top:  cy }).setCoords(); addSnap([0, cy, canvas.width, cy]); }
    if (Math.abs(obj.left - cx) < SNAP) { obj.set({ left: cx }).setCoords(); addSnap([cx, 0, cx, canvas.height]); }
});
canvas.on('object:modified', clearSnapLines);
canvas.on('mouse:up',        clearSnapLines);

// --- Adding Elements ---
function addCanvasText(text, size, bold = false) {
    const txt = new fabric.IText(text, {
        left: canvas.width / 2, top: canvas.height / 2,
        fontFamily: 'Inter', fontSize: size, fontWeight: bold ? 'bold' : 'normal',
        fill: '#000000', originX: 'center', originY: 'center', textAlign: 'center'
    });
    canvas.add(txt); canvas.setActiveObject(txt); canvas.renderAll();
}

document.getElementById('addHeading').addEventListener('click',      () => addCanvasText('CERTIFICATE TITLE', 60, true));
document.getElementById('addSubheading').addEventListener('click',   () => addCanvasText('Subheading Text', 30));
document.getElementById('addBodyText').addEventListener('click',     () => addCanvasText('Double click to edit text...', 20));
document.getElementById('addAutoName').addEventListener('click',     () => addCanvasText('{{participant_name}}', 50, true));
document.getElementById('addSignatoryLine').addEventListener('click',() => addCanvasText('_________________________\nAuthorized Signature', 18));

// --- Image Uploads ---
function handleImageUpload(e, isBackground = false) {
    const file = e.target.files[0]; if (!file) return;
    const reader = new FileReader();
    reader.onload = f => {
        fabric.Image.fromURL(f.target.result, img => {
            if (isBackground) {
                img.set({ scaleX: canvas.width / img.width, scaleY: canvas.height / img.height, originX: 'left', originY: 'top', left: 0, top: 0 });
                canvas.setBackgroundImage(img, () => {
                    canvas.renderAll();
                    isCanvasDirty = true;
                    pushHistoryState();
                });
                isCanvasDirty = true;
            } else {
                img.set({ left: canvas.width / 2, top: canvas.height / 2, originX: 'center', originY: 'center' });
                if (img.width > 250) img.scaleToWidth(250);
                canvas.add(img); canvas.setActiveObject(img); canvas.renderAll();
            }
        });
    };
    reader.readAsDataURL(file); e.target.value = '';
}
document.getElementById('uploadBg').addEventListener('change',  e => handleImageUpload(e, true));
document.getElementById('uploadLogo').addEventListener('change', e => handleImageUpload(e, false));
document.getElementById('uploadSig').addEventListener('change',  e => handleImageUpload(e, false));
document.getElementById('btnRemoveBackground')?.addEventListener('click', () => {
    canvas.setBackgroundImage(null, () => {
        canvas.renderAll();
        isCanvasDirty = true;
        pushHistoryState();
    });
});

// --- Toolbar Sync ---
const objToolbar  = document.getElementById('objectToolbar');
const textToolbar = document.getElementById('textFormattingBar');

canvas.on('selection:created', syncToolbars);
canvas.on('selection:updated', syncToolbars);
canvas.on('selection:cleared', () => {
    objToolbar.classList.add('opacity-0', 'pointer-events-none');
    textToolbar.classList.add('opacity-30', 'pointer-events-none');
});

function syncToolbars() {
    const obj = canvas.getActiveObject(); if (!obj) return;
    objToolbar.classList.remove('opacity-0', 'pointer-events-none');
    document.getElementById('objOpacity').value             = Math.round((obj.opacity ?? 1) * 100);
    document.getElementById('opacityLabel').textContent     = Math.round((obj.opacity ?? 1) * 100) + '%';
    
    // Check lock state based on movement properties rather than selectability
    const isLocked = !!obj.lockMovementX;
    document.getElementById('lblLock').textContent          = isLocked ? 'Unlock' : 'Lock';
    document.getElementById('btnLock').classList.toggle('text-orange-500', isLocked);
    document.getElementById('btnLock').classList.toggle('border-orange-500/50', isLocked);
    document.getElementById('btnLock').classList.toggle('bg-orange-500/10', isLocked);

    const isText = (obj.type === 'i-text' || obj.type === 'text');
    if (isText) {
        textToolbar.classList.remove('opacity-30', 'pointer-events-none');
        document.getElementById('fontSize').value   = obj.fontSize   ?? 24;
        document.getElementById('fontFamily').value = obj.fontFamily ?? 'Inter';
        const fillVal = (typeof obj.fill === 'string' && obj.fill.startsWith('#')) ? obj.fill : '#000000';
        document.getElementById('fontColor').value  = fillVal;
        
        // Highlights for formatting buttons
        document.getElementById('btnBold').classList.toggle('active',      obj.fontWeight === 'bold');
        document.getElementById('btnItalic').classList.toggle('active',    obj.fontStyle  === 'italic');
        document.getElementById('btnUnderline').classList.toggle('active', !!obj.underline);
        
        // Highlights for alignment buttons
        const align = obj.textAlign || 'left';
        document.getElementById('btnAlignLeft').classList.toggle('active',   align === 'left');
        document.getElementById('btnAlignCenter').classList.toggle('active', align === 'center');
        document.getElementById('btnAlignRight').classList.toggle('active',  align === 'right');
    } else {
        textToolbar.classList.add('opacity-30', 'pointer-events-none');
    }
}

function executeActiveObj(fn) {
    const obj = canvas.getActiveObject();
    if (obj) {
        fn(obj);
        canvas.renderAll();
        syncToolbars();
        isCanvasDirty = true;
        pushHistoryState();
    }
}

// --- Object Toolbar Actions ---
document.getElementById('objOpacity').addEventListener('input', e => executeActiveObj(o => o.set('opacity', e.target.value / 100)));

document.getElementById('btnLock').addEventListener('click', () => {
    executeActiveObj(o => {
        const isLocked = !!o.lockMovementX;
        const newState = !isLocked;
        
        // Keep selectable:true and evented:true so we can still select and unlock it!
        o.set({ 
            lockMovementX: newState, 
            lockMovementY: newState, 
            lockRotation: newState, 
            lockScalingX: newState, 
            lockScalingY: newState,
            hasControls: !newState, // Hide controls when locked
            hoverCursor: newState ? 'not-allowed' : 'move'
        });
        
        // Refresh selection state to update handles immediately
        canvas.discardActiveObject();
        canvas.setActiveObject(o);
    });
});

document.getElementById('btnDuplicate').addEventListener('click', () => {
    const obj = canvas.getActiveObject(); if (!obj) return;
    obj.clone(cloned => {
        canvas.discardActiveObject();
        cloned.set({ left: obj.left + 20, top: obj.top + 20, evented: true });
        if (cloned.type === 'activeSelection') { cloned.canvas = canvas; cloned.forEachObject(o => canvas.add(o)); cloned.setCoords(); }
        else canvas.add(cloned);
        canvas.setActiveObject(cloned); canvas.renderAll();
    });
});

document.getElementById('btnDeleteObj').addEventListener('click', () => {
    const obj = canvas.getActiveObject();
    if (obj) { canvas.remove(...canvas.getActiveObjects()); canvas.discardActiveObject(); canvas.renderAll(); }
});

window.addEventListener('keydown', e => {
    const target = e.target;
    const isTypingTarget = !!target && (
        target.tagName === 'INPUT' ||
        target.tagName === 'TEXTAREA' ||
        target.tagName === 'SELECT' ||
        target.isContentEditable
    );
    const wantsUndo = (e.ctrlKey || e.metaKey) && !e.shiftKey && !e.altKey && e.key.toLowerCase() === 'z';
    if (wantsUndo && !isTypingTarget) {
        e.preventDefault();
        undoCanvasState();
        return;
    }

    const obj = canvas.getActiveObject();
    if (e.key === 'Delete') {
        if (obj && !obj.isEditing) { canvas.remove(obj); canvas.discardActiveObject(); canvas.renderAll(); }
    }
    
    // --- Keyboard Arrow Navigation ---
    if (obj && !obj.isEditing && ['ArrowUp', 'ArrowDown', 'ArrowLeft', 'ArrowRight'].includes(e.key)) {
        e.preventDefault(); // Prevent scrolling the workspace
        const moveAmount = e.shiftKey ? 10 : 1;
        if (e.key === 'ArrowUp')    obj.set('top',  obj.top  - moveAmount);
        if (e.key === 'ArrowDown')  obj.set('top',  obj.top  + moveAmount);
        if (e.key === 'ArrowLeft')  obj.set('left', obj.left - moveAmount);
        if (e.key === 'ArrowRight') obj.set('left', obj.left + moveAmount);
        
        obj.setCoords();
        canvas.requestRenderAll();
        isCanvasDirty = true;
        pushHistoryState();
    }
});

// --- Text Formatting ---
function setStyle(prop, val) {
    executeActiveObj(o => { if (o.type === 'i-text' || o.type === 'text') o.set(prop, val); });
}
document.getElementById('fontSize').addEventListener('change', e => setStyle('fontSize', parseInt(e.target.value) || 24));
document.getElementById('btnSizeInc').addEventListener('click', () => {
    const el = document.getElementById('fontSize'), s = (parseInt(el.value) || 24) + 2;
    el.value = s; setStyle('fontSize', s);
});
document.getElementById('btnSizeDec').addEventListener('click', () => {
    const el = document.getElementById('fontSize'), s = Math.max(8, (parseInt(el.value) || 24) - 2);
    el.value = s; setStyle('fontSize', s);
});
document.getElementById('fontColor').addEventListener('input',  e => setStyle('fill', e.target.value));
document.getElementById('fontFamily').addEventListener('change', e => setStyle('fontFamily', e.target.value));
document.getElementById('btnBold').addEventListener('click',      () => executeActiveObj(o => { if(o.type==='i-text'||o.type==='text') o.set('fontWeight', o.fontWeight==='bold'?'normal':'bold'); }));
document.getElementById('btnItalic').addEventListener('click',    () => executeActiveObj(o => { if(o.type==='i-text'||o.type==='text') o.set('fontStyle',  o.fontStyle==='italic'?'normal':'italic'); }));
document.getElementById('btnUnderline').addEventListener('click', () => executeActiveObj(o => { if(o.type==='i-text'||o.type==='text') o.set('underline', !o.underline); }));
// --- Text Formatting Alignment (Canvas Positioning & TextAlign Toggle) ---
['Left', 'Center', 'Right'].forEach(a => {
    document.getElementById('btnAlign' + a).addEventListener('click', () => {
        const align = a.toLowerCase();
        executeActiveObj(o => {
            if (o.type === 'i-text' || o.type === 'text') {
                // Force center origin for predictable positioning
                o.set({ originX: 'center', textAlign: align });
                
                const margin = canvas.width * 0.05;
                const scaledWidth = o.getScaledWidth();
                
                if (align === 'center') {
                    canvas.centerObjectH(o);
                } else if (align === 'left') {
                    o.set('left', margin + (scaledWidth / 2));
                } else if (align === 'right') {
                    o.set('left', canvas.width - margin - (scaledWidth / 2));
                }
                
                o.setCoords();
                canvas.requestRenderAll();
            }
        });
    });
});


// --- Page Size ---
document.getElementById('pageSize').addEventListener('change', e => {
    const dim = sizes[e.target.value];
    canvas.setWidth(dim.width);
    canvas.setHeight(dim.height);
    canvas.renderAll();
    autoFitCanvas();
    isCanvasDirty = true;
    pushHistoryState();
});

// --- HD Preview ---
document.getElementById('btnPreview').addEventListener('click', () => {
    canvas.discardActiveObject(); canvas.renderAll();
    const dataURL = canvas.toDataURL({ format: 'png', quality: 1, multiplier: 2 });
    const link = document.createElement('a'); link.download = `Certificate_Preview_${Date.now()}.png`; link.href = dataURL;
    document.body.appendChild(link); link.click(); document.body.removeChild(link);
});

// --- Save Template ---
document.getElementById('btnSaveTemplate').addEventListener('click', async () => {
    // Check if canvas is actually modified (optional but good)
    if (!isCanvasDirty && canvas.getObjects().length === 0) {
        return showNotification('Canvas is empty!', 'error');
    }

    showSaveLayoutModal(async (name) => {
        canvas.discardActiveObject(); 
        canvas.renderAll();
        
        const jsonState = canvas.toJSON();
        
        // --- Persistence: Include custom fonts in the JSON state ---
        jsonState.customFonts = activeCustomFonts;
        
        const thumb     = canvas.toDataURL({ format: 'jpeg', quality: 0.5, multiplier: 0.25 });

        const btn = document.getElementById('btnSaveTemplate');
        const originalBtnContent = btn.innerHTML;
        btn.innerHTML = `<svg class="w-4 h-4 animate-spin" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0A9.928 9.928 0 013 12c0-5.523 4.477-10 10-10a9.928 9.928 0 017.015 2.822M3 12c0 5.523 4.477 10 10 10a9.928 9.928 0 0110-10"/></svg> Saving...`;
        btn.disabled = true;

        try {
            const scopeValue = document.getElementById('templateScopeSelect')?.value || 'event';
            const templateScope = scopeValue.startsWith('session:') ? 'session' : 'event';
            const selectedSessionId = scopeValue.startsWith('session:') ? scopeValue.split(':')[1] : '';
            const res = await fetch('/api/certificate_save.php', {
                method: 'POST', 
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    event_id: '<?php echo htmlspecialchars($eventId); ?>',
                    session_id: selectedSessionId,
                    template_scope: templateScope,
                    title: name, 
                    canvas_state: jsonState, 
                    thumbnail_url: thumb,
                    csrf_token: <?php echo json_encode($_SESSION['csrf_token'] ?? ''); ?>
                })
            });
            const data = await res.json();
            if (!data.ok) throw new Error(data.error || 'Save failed');
            
            showNotification('Template saved successfully!');
            isCanvasDirty = false;
            
            // Visual success state on button
            btn.innerHTML = `<svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/></svg> Saved!`;
            btn.classList.add('!from-emerald-500', '!to-emerald-400');
            
            setTimeout(() => {
                const nextUrl = new URL(window.location.href);
                if (data?.template_id) {
                    nextUrl.searchParams.set('template_id', data.template_id);
                }
                if (data?.template_scope === 'session' && data?.session_id) {
                    nextUrl.searchParams.set('session_id', data.session_id);
                } else {
                    nextUrl.searchParams.delete('session_id');
                }
                window.location.href = nextUrl.toString();
            }, 1000);
        } catch (err) {
            showNotification(err.message, 'error');
            btn.innerHTML = originalBtnContent; 
            btn.disabled = false;
        }
    });
});

// --- Highlight Active Template Card ---
function setActiveCard(el) {
    document.querySelectorAll('.template-card, .custom-template-card').forEach(c => c.classList.remove('active-template'));
    if (el) el.classList.add('active-template');
}

const templateScopeSelect = document.getElementById('templateScopeSelect');
const templateScopeHint = document.getElementById('templateScopeHint');
const savedTemplatesEmpty = document.getElementById('savedTemplatesEmpty');

function getTemplateScopeMeta() {
    const defaultScope = templateScopeSelect?.options?.[0]?.value || 'event';
    const scopeValue = templateScopeSelect?.value || defaultScope;
    if (scopeValue.startsWith('session:')) {
        const sessionId = scopeValue.split(':')[1] || '';
        const sessionLabel = templateScopeSelect?.selectedOptions?.[0]?.textContent?.trim() || 'Seminar';
        return { scope: 'session', sessionId, label: sessionLabel };
    }
    return { scope: 'event', sessionId: '', label: 'Whole Event' };
}

function filterCustomTemplatesByScope() {
    const { scope, sessionId, label } = getTemplateScopeMeta();
    let visibleCount = 0;
    const activeHidden = !!document.querySelector('.custom-template-card.active-template');

    document.querySelectorAll('.custom-template-card').forEach((card) => {
        const cardScope = card.dataset.scope || 'event';
        const cardSessionId = card.dataset.sessionId || '';
        const visible = cardScope === scope && (scope !== 'session' || cardSessionId === sessionId);
        card.classList.toggle('hidden', !visible);
        if (visible) {
            visibleCount += 1;
        } else if (card.classList.contains('active-template')) {
            card.classList.remove('active-template');
        }
    });

    if (savedTemplatesEmpty) {
        savedTemplatesEmpty.classList.toggle('hidden', visibleCount > 0);
    }

    if (templateScopeHint) {
        templateScopeHint.textContent = scope === 'session'
            ? `You are editing templates for ${label}.`
            : 'You are editing templates for the whole event.';
    }

    if (activeHidden && !document.querySelector('.custom-template-card.active-template')) {
        setActiveCard(null);
    }
}

// --- Load Preset Template --- (FIX: set backgroundColor AFTER clear, no intermediate renderAll)
function doLoadPreset(type, cardEl) {
    isProgrammaticChange = true;
    canvas.clear();

    if (type === 'classic-green') {
        canvas.backgroundColor = '#064e3b';
        canvas.add(
            new fabric.Rect({ left: 0, top: 0, width: sizes['A4'].width, height: 25, fill: '#f6ad55', selectable: false, evented: false }),
            new fabric.Rect({ left: 0, top: sizes['A4'].height - 25, width: sizes['A4'].width, height: 25, fill: '#f6ad55', selectable: false, evented: false }),
            new fabric.IText('CERTIFICATE OF PARTICIPATION', { left: canvas.width/2, top: 250, fontFamily: 'Inter', fontSize: 32, fontWeight: 'bold', fill: '#ffffff', originX: 'center', originY: 'center', textAlign: 'center' }),
            new fabric.IText('{{participant_name}}', { left: canvas.width/2, top: 420, fontFamily: 'Inter', fontSize: 72, fontWeight: 'bold', fill: '#ffffff', originX: 'center', originY: 'center', textAlign: 'center' })
        );
    } else if (type === 'nutrition-month') {
        canvas.backgroundColor = '#d97706';
        canvas.add(
            new fabric.IText('CCS EVENT 2026',  { left: canvas.width/2, top: 250, fontFamily: 'Inter',   fontSize: 32, fontWeight: 'bold', fill: '#ffedd5', originX: 'center', originY: 'center', textAlign: 'center' }),
            new fabric.IText('{{participant_name}}',  { left: canvas.width/2, top: 420, fontFamily: 'Georgia', fontSize: 72, fontWeight: 'bold', fill: '#ffffff',  originX: 'center', originY: 'center', textAlign: 'center' })
        );
    }

    canvas.renderAll();
    isCanvasDirty = false;
    isProgrammaticChange = false;
    resetHistoryBaseline();
    setActiveCard(cardEl); autoFitCanvas();
}

// --- Load Custom Saved Template ---
function doLoadCustom(cardEl) {
    const rawJson = cardEl.dataset.json; if (!rawJson) return;
    try {
        const parsed = JSON.parse(rawJson);
        
        // --- Persistence: Restore custom fonts before loading objects ---
        if (parsed.customFonts && Array.isArray(parsed.customFonts)) {
            parsed.customFonts.forEach(f => {
                if (f.url && f.family) window.registerCustomFont(f.url, f.family);
            });
        }

        isProgrammaticChange = true;
        canvas.loadFromJSON(parsed, () => {
            canvas.renderAll(); autoFitCanvas();
            isCanvasDirty = false;
            isProgrammaticChange = false;
            resetHistoryBaseline();
            setActiveCard(cardEl);
        });
    } catch (e) {
        isProgrammaticChange = false;
        alert('Failed to read template data.'); console.error(e);
    }
}

// --- Preset Template Clicks ---
document.querySelectorAll('.template-card').forEach(card => {
    card.addEventListener('click', () => {
        const type = card.getAttribute('data-preset');
        if (isCanvasDirty) showConfirm('Loading a new design will discard your current unsaved work.', () => doLoadPreset(type, card));
        else doLoadPreset(type, card);
    });
});

// --- Custom Saved Template Clicks ---
document.querySelectorAll('.custom-template-card').forEach(card => {
    card.addEventListener('click', () => {
        if (isCanvasDirty) showConfirm('Loading this saved template will discard your current unsaved work.', () => doLoadCustom(card));
        else doLoadCustom(card);
    });
});

// --- Auto-load template_id from URL ---
const loadTemplateId = "<?= htmlspecialchars($templateId ?? '', ENT_QUOTES, 'UTF-8') ?>";
if (loadTemplateId !== '') {
    setTimeout(() => {
        const targetCard = document.querySelector(`.custom-template-card[data-id="${loadTemplateId}"]`);
        if (targetCard) {
            if (templateScopeSelect) {
                const scope = targetCard.dataset.scope || 'event';
                const sessionId = targetCard.dataset.sessionId || '';
                templateScopeSelect.value = scope === 'session' && sessionId ? `session:${sessionId}` : 'event';
                filterCustomTemplatesByScope();
            }
            doLoadCustom(targetCard);
        } else {
            filterCustomTemplatesByScope();
        }
    }, 300);
} else {
    filterCustomTemplatesByScope();
}

// --- Delete Custom Template ---
async function deleteCustomTemplate(id, cardEl, scope = 'event', sessionId = '') {
    showConfirm('Are you sure you want to delete this template forever?', async () => {
        try {
            const res = await fetch('/api/certificate_delete.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    template_id: id,
                    template_scope: scope,
                    session_id: sessionId,
                    csrf_token: <?php echo json_encode($_SESSION['csrf_token'] ?? ''); ?>
                })
            });
            const data = await res.json();
            if (!data.ok) throw new Error(data.error || 'Deletion failed');
            
            showNotification('Template deleted.');
            cardEl.style.transform = 'scale(0.8)';
            cardEl.style.opacity = '0';
            setTimeout(() => {
                cardEl.remove();
                filterCustomTemplatesByScope();
            }, 300);
        } catch (err) {
            showNotification(err.message, 'error');
            console.error(err);
        }
    });
}

templateScopeSelect?.addEventListener('change', filterCustomTemplatesByScope);
</script>
    <!-- ══════ IMPORT FONT MODAL ══════ -->
    <div id="font-import-modal" style="z-index:9999;" class="fixed inset-0 hidden items-center justify-center px-4 bg-zinc-950/80 backdrop-blur-md transition-opacity">
      <div class="relative w-full max-w-md rounded-2xl bg-[#1c1c1e] p-6 shadow-2xl border border-zinc-800 transition-all transform scale-100 ring-1 ring-white/5">
        <!-- Close -->
        <button onclick="closeFontModal()" class="absolute right-5 top-5 text-zinc-500 hover:text-white transition">
           <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
        </button>
        
        <!-- Header -->
        <h3 class="text-lg font-bold text-white flex items-center gap-2 mb-1">
          <svg class="w-5 h-5 text-orange-500" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/></svg>
          Import Google Font
        </h3>
        <p class="text-xs text-zinc-400 mb-6 font-medium">Add professional typography to your certificates.</p>
        
        <!-- Input Area -->
        <div class="space-y-4 mb-6">
            <textarea id="fontInput" placeholder="Paste your Google Font URL here..." class="w-full h-24 rounded-xl bg-zinc-900 border border-zinc-800 px-4 py-3 text-sm text-white placeholder-zinc-600 outline-none focus:ring-2 focus:ring-orange-500/30 font-mono"></textarea>
            
            <a href="https://fonts.google.com" target="_blank" class="flex items-center gap-2 text-xs font-bold text-orange-500 hover:text-orange-400 transition group">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 6H5.25A2.25 2.25 0 003 8.25v10.5A2.25 2.25 0 005.25 21h10.5A2.25 2.25 0 0018 18.75V10.5m-10.5 6L21 3m0 0h-5.25M21 3v5.25"/></svg>
                Get your font URL from Google Fonts
            </a>
        </div>

        <!-- Instructions -->
        <div class="bg-zinc-900/50 rounded-xl p-4 border border-zinc-800/50 mb-6">
            <div class="text-[10px] uppercase tracking-widest text-zinc-500 font-bold mb-3 flex items-center gap-1.5">
               <svg class="w-3 h-3" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M11.25 11.25l.041-.02a.75.75 0 011.063.852l-.708 2.836a.75.75 0 001.063.853l.041-.021M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-9-3.75h.008v.008H12V8.25z"/></svg> 
               Important Instructions
            </div>
            <p class="text-[11px] text-zinc-400 leading-relaxed">
               When copying from Google Fonts, only paste the main **&lt;link&gt;** tag or the direct **URL**.
            </p>
            <div class="mt-2 p-2 bg-emerald-500/5 rounded border border-emerald-500/10 text-[10px] font-mono text-emerald-400/80">
                Correct: <span class="text-emerald-400">&lt;link href="...Roboto&display=swap" rel="stylesheet"&gt;</span>
            </div>
        </div>

        <!-- Actions -->
        <div class="flex items-center justify-end gap-3">
             <button onclick="closeFontModal()" class="px-4 py-2 text-xs font-bold text-zinc-400 hover:text-white transition">Cancel</button>
             <button id="btnAddFont" class="bg-orange-600 hover:bg-orange-500 text-white px-5 py-2.5 rounded-xl text-xs font-bold shadow-lg shadow-orange-600/20 transition-all flex items-center gap-2 group">
                <span id="btnFontLabel">Add Font</span>
                <span id="btnFontLoading" class="hidden"><svg class="animate-spin h-3.5 w-3.5 text-white" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg></span>
             </button>
        </div>
      </div>
    </div>

    <script>
    // --- Google Font Import Logic ---
    const fontModal = document.getElementById('font-import-modal');
    const fontInput = document.getElementById('fontInput');
    const btnImportFont = document.getElementById('btnImportFont');
    
    if (btnImportFont) {
        btnImportFont.addEventListener('click', () => {
            fontModal.classList.remove('hidden');
            fontModal.classList.add('flex');
            fontInput.focus();
        });
    }

    window.closeFontModal = () => {
        fontModal.classList.add('hidden');
        fontModal.classList.remove('flex');
        fontInput.value = '';
    };

    document.getElementById('btnAddFont').addEventListener('click', async () => {
        const input = fontInput.value.trim();
        if (!input) return;

        const btnLabel = document.getElementById('btnFontLabel');
        const btnLoading = document.getElementById('btnFontLoading');
        const btn = document.getElementById('btnAddFont');

        btnLabel.classList.add('hidden');
        btnLoading.classList.remove('hidden');
        btn.disabled = true;

        try {
            // Extract URL from <link> tag if present
            let url = input;
            if (input.includes('<link')) {
                const match = input.match(/href="([^"]+)"/);
                if (match) url = match[1];
            }

            // Extract family name (e.g., family=Roboto:wght@400;700)
            const familyMatch = url.match(/family=([^&:]+)/);
            if (!familyMatch) throw new Error('Could not find font family in the link provided.');
            
            const familyName = decodeURIComponent(familyMatch[1].replace(/\+/g, ' '));
            
            // Reusable registration helper
            window.registerCustomFont(url, familyName);

            // Apply to active object
            document.getElementById('fontFamily').value = familyName;
            setStyle('fontFamily', familyName);
            
            // Fabric.js might need a small delay to see the font loaded
            setTimeout(() => {
                canvas.requestRenderAll();
                showNotification(`Font "${familyName}" imported!`);
                closeFontModal();
            }, 500);

        } catch (err) {
            showNotification(err.message, 'error');
        } finally {
            btnLabel.classList.remove('hidden');
            btnLoading.classList.add('hidden');
            btn.disabled = false;
        }
    });

    // Close on overlay click
    fontModal.addEventListener('click', (e) => {
        if (e.target === fontModal) closeFontModal();
    });
    </script>
</body>
</html>
