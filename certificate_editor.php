<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/session.php';
session_bootstrap();

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/supabase.php';
require_once __DIR__ . '/includes/event_sessions.php';

$user = require_role(['teacher']);
$userId = trim((string) ($user['id'] ?? ''));
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

// Teachers may only edit certificates for events they own or are assigned to.
$teacherMayAccessEvent = static function (string $checkEventId, string $teacherId, array $headers): bool {
    if ($checkEventId === '' || $teacherId === '') {
        return false;
    }
    $eventUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/events'
        . '?select=id,created_by&id=eq.' . rawurlencode($checkEventId) . '&limit=1';
    $eventRes = supabase_request('GET', $eventUrl, $headers);
    $eventRows = json_decode((string) ($eventRes['body'] ?? ''), true);
    $event = is_array($eventRows) && isset($eventRows[0]) ? $eventRows[0] : null;
    if (is_array($event) && (string) ($event['created_by'] ?? '') === $teacherId) {
        return true;
    }
    $assignUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/event_teacher_assignments'
        . '?select=id&event_id=eq.' . rawurlencode($checkEventId)
        . '&teacher_id=eq.' . rawurlencode($teacherId) . '&limit=1';
    $assignRes = supabase_request('GET', $assignUrl, $headers);
    $assignRows = json_decode((string) ($assignRes['body'] ?? ''), true);
    return is_array($assignRows) && count($assignRows) > 0;
};

if ($eventId !== '' && !$teacherMayAccessEvent($eventId, $userId, $headers)) {
    http_response_code(403);
    echo 'You do not have permission to edit certificates for this event.';
    exit;
}

if ($templateId !== '' && $eventId === '') {
    $linkedEventId = '';
    $linkedSessionId = '';

    $tplUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/certificate_templates'
        . '?select=event_id,created_by&id=eq.' . rawurlencode($templateId) . '&limit=1';
    $tplRes = supabase_request('GET', $tplUrl, $headers);
    $tplRows = json_decode((string) ($tplRes['body'] ?? ''), true);
    if (is_array($tplRows) && isset($tplRows[0]) && is_array($tplRows[0])) {
        $linkedEventId = trim((string) ($tplRows[0]['event_id'] ?? ''));
        $tplCreatedBy = trim((string) ($tplRows[0]['created_by'] ?? ''));
        if ($linkedEventId === '' && $tplCreatedBy === $userId) {
            // Teacher-owned template without an event link — allow editor open.
            $linkedEventId = '';
        } elseif ($linkedEventId === '' || !$teacherMayAccessEvent($linkedEventId, $userId, $headers)) {
            if ($tplCreatedBy !== $userId) {
                http_response_code(403);
                echo 'You do not have permission to edit this certificate template.';
                exit;
            }
        }
    } else {
        // Fall back to seminar (session) templates.
        $sessTplUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/event_session_certificate_templates'
            . '?select=session_id,created_by,event_sessions(event_id)'
            . '&id=eq.' . rawurlencode($templateId)
            . '&limit=1';
        $sessTplRes = supabase_request('GET', $sessTplUrl, $headers);
        $sessTplRows = json_decode((string) ($sessTplRes['body'] ?? ''), true);
        if (!is_array($sessTplRows) || !isset($sessTplRows[0]) || !is_array($sessTplRows[0])) {
            http_response_code(404);
            echo 'Certificate template not found.';
            exit;
        }
        $linkedSessionId = trim((string) ($sessTplRows[0]['session_id'] ?? ''));
        $tplCreatedBy = trim((string) ($sessTplRows[0]['created_by'] ?? ''));
        $nested = $sessTplRows[0]['event_sessions'] ?? null;
        if (is_array($nested)) {
            $linkedEventId = trim((string) ($nested['event_id'] ?? ''));
        }
        if ($linkedEventId === '' && $linkedSessionId !== '') {
            $lookup = rtrim(SUPABASE_URL, '/') . '/rest/v1/event_sessions'
                . '?select=event_id&id=eq.' . rawurlencode($linkedSessionId) . '&limit=1';
            $lookupRes = supabase_request('GET', $lookup, $headers);
            $lookupRows = json_decode((string) ($lookupRes['body'] ?? ''), true);
            $linkedEventId = is_array($lookupRows) && isset($lookupRows[0]['event_id'])
                ? trim((string) $lookupRows[0]['event_id'])
                : '';
        }
        if ($linkedEventId === '' || !$teacherMayAccessEvent($linkedEventId, $userId, $headers)) {
            if ($tplCreatedBy !== $userId) {
                http_response_code(403);
                echo 'You do not have permission to edit this certificate template.';
                exit;
            }
        }
        if ($sessionId === '' && $linkedSessionId !== '') {
            $sessionId = $linkedSessionId;
        }
    }

    if ($linkedEventId !== '') {
        $eventId = $linkedEventId;
    }
}

// Standalone design library is allowed without an event link.
$eventName = 'Design Library';
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
                    $customTemplates[] = array_merge($tpl, [
                        'template_scope' => 'event',
                        'scope_session_id' => '',
                        'scope_label' => 'Whole Event',
                    ]);
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
                        $customTemplates[] = array_merge($tpl, [
                            'template_scope' => 'session',
                            'scope_session_id' => (string) ($tpl['session_id'] ?? ''),
                            'scope_label' => build_session_display_name($sessionMeta),
                        ]);
                    }
                }
            }
        }
    }
} else {
    // Design library: teacher's templates (created_by) + claimable orphans.
    $templatesById = [];
    $mergeTpl = static function (array $rows, string $scopeLabel = 'Design Library') use (&$templatesById, &$customTemplates): void {
        if ($rows !== [] && isset($rows['id']) && is_string($rows['id'] ?? null)) {
            $rows = [$rows];
        }
        foreach ($rows as $tpl) {
            if (!is_array($tpl)) {
                continue;
            }
            // Library sidebar: never list Import/Link event clones.
            if (trim((string) ($tpl['event_id'] ?? '')) !== '') {
                continue;
            }
            $tid = trim((string) ($tpl['id'] ?? ''));
            if ($tid === '' || isset($templatesById[$tid])) {
                continue;
            }
            $templatesById[$tid] = true;
            $customTemplates[] = array_merge($tpl, [
                'template_scope' => 'library',
                'scope_session_id' => '',
                'scope_label' => $scopeLabel,
            ]);
        }
    };

    $libUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/certificate_templates'
        . '?select=id,title,canvas_state,thumbnail_url,event_id,created_by'
        . '&created_by=eq.' . rawurlencode($userId)
        . '&event_id=is.null'
        . '&order=created_at.desc';
    $libRes = supabase_request('GET', $libUrl, $headers);
    if ($libRes['ok']) {
        $arrTpl = json_decode((string) $libRes['body'], true);
        if (is_array($arrTpl)) {
            $mergeTpl($arrTpl);
        }
    } else {
        $libFallback = rtrim(SUPABASE_URL, '/') . '/rest/v1/certificate_templates'
            . '?select=id,title,canvas_state,thumbnail_url,event_id,created_by'
            . '&created_by=eq.' . rawurlencode($userId)
            . '&order=created_at.desc';
        $libFbRes = supabase_request('GET', $libFallback, $headers);
        if ($libFbRes['ok']) {
            $arrTpl = json_decode((string) $libFbRes['body'], true);
            if (is_array($arrTpl)) {
                $mergeTpl($arrTpl);
            }
        }
    }

    // Legacy rows with null created_by (pre-teacher ownership).
    $orphanUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/certificate_templates'
        . '?select=id,title,canvas_state,thumbnail_url,event_id,created_by'
        . '&created_by=is.null'
        . '&order=created_at.desc'
        . '&limit=100';
    $orphanRes = supabase_request('GET', $orphanUrl, $headers);
    if ($orphanRes['ok']) {
        $orphanRows = json_decode((string) $orphanRes['body'], true);
        if (is_array($orphanRows)) {
            $claimable = [];
            $patchHeaders = [
                'Accept: application/json',
                'Content-Type: application/json',
                'apikey: ' . SUPABASE_KEY,
                'Authorization: Bearer ' . SUPABASE_KEY,
                'Prefer: return=minimal',
            ];
            foreach ($orphanRows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                // Prefer standalone designs; skip claiming random event-linked orphans here.
                if (trim((string) ($row['event_id'] ?? '')) !== '') {
                    continue;
                }
                $claimable[] = $row;
                $tid = trim((string) ($row['id'] ?? ''));
                if ($tid !== '') {
                    $patchUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/certificate_templates?id=eq.' . rawurlencode($tid);
                    supabase_request('PATCH', $patchUrl, $patchHeaders, json_encode(['created_by' => $userId]));
                }
            }
            $mergeTpl($claimable);
        }
    }

    // Ensure the requested template is included even if created_by filter missed it.
    if ($templateId !== '') {
        $found = false;
        foreach ($customTemplates as $tpl) {
            if ((string) ($tpl['id'] ?? '') === $templateId) {
                $found = true;
                break;
            }
        }
        if (!$found) {
            $oneUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/certificate_templates'
                . '?select=id,title,canvas_state,thumbnail_url,event_id,created_by'
                . '&id=eq.' . rawurlencode($templateId) . '&limit=1';
            $oneRes = supabase_request('GET', $oneUrl, $headers);
            $oneRows = $oneRes['ok'] ? json_decode((string) $oneRes['body'], true) : [];
            if (is_array($oneRows) && isset($oneRows[0]) && is_array($oneRows[0])) {
                $mergeTpl([$oneRows[0]]);
            }
        }
    }
}

$initialEditingTitle = '';
$initialEditingScope = 'library';
if ($templateId !== '' && count($customTemplates) > 0) {
    foreach ($customTemplates as $tpl) {
        if ((string) ($tpl['id'] ?? '') !== $templateId) {
            continue;
        }
        $initialEditingTitle = (string) ($tpl['title'] ?? '');
        $initialEditingScope = (string) ($tpl['template_scope'] ?? 'library');
        if ($initialEditingScope === 'session') {
            $sessionId = (string) ($tpl['scope_session_id'] ?? '');
        }
        break;
    }
}

$initialTemplateScope = $eventId === ''
    ? 'library'
    : ($isSeminarBasedForCertificates ? 'session' : ($sessionId !== '' ? 'session' : 'event'));
if ($templateId !== '' && $initialEditingScope !== '') {
    $initialTemplateScope = $initialEditingScope === 'session'
        ? 'session'
        : ($eventId === '' ? 'library' : $initialEditingScope);
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8"/>
    <meta name="viewport" content="width=device-width, initial-scale=1"/>
    <title>Certificate Pro Editor — PulseConnect</title>
    <?php require_once __DIR__ . '/includes/favicon.php'; render_favicon_tags(); ?>
    <link rel="stylesheet" href="/assets/css/tailwind.css?v=<?= (int) @filemtime(__DIR__ . '/assets/css/tailwind.css') ?>" />
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
        .select-clean option {
            background-color: #18181b;
            color: #e4e4e7;
        }

        input[type="color"] {
            -webkit-appearance: none; border: 1px solid #3f3f46; width: 28px; height: 28px;
            border-radius: 6px; padding: 0; cursor: pointer; background: #18181b; overflow: hidden;
        }
        input[type="color"]::-webkit-color-swatch-wrapper { padding: 0; }
        input[type="color"]::-webkit-color-swatch { border: none; border-radius: 4px;}

        /* Signature line selected: only color picker is active */
        #textFormattingBar.cert-line-color-mode > *:not(#fontColor) {
            opacity: 0.35;
            pointer-events: none;
        }
        
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
             <a href="/certificates_library" class="flex items-center gap-2 text-zinc-400 hover:text-white transition-colors text-sm font-semibold group">
                <div class="w-7 h-7 rounded bg-zinc-800/80 group-hover:bg-orange-500/20 flex flex-col justify-center items-center transition-all border border-zinc-700">
                    <svg class="w-4 h-4 group-hover:text-orange-500 transition-colors" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5L8.25 12l7.5-7.5"/></svg>
                </div>
                Exit Editor
            </a>
            <div class="h-5 w-px bg-zinc-800"></div>
            <div class="text-[13px] font-semibold truncate max-w-[300px] text-zinc-300">Workspace: <span class="text-white ml-1 font-bold"><?= htmlspecialchars($eventName) ?></span></div>
        </div>

        <div class="flex gap-3 items-center">
            <button id="btnExportPptx" type="button" class="flex items-center gap-1.5 bg-zinc-800 hover:bg-zinc-700 text-zinc-300 hover:text-white px-4 py-2 rounded-md text-xs font-bold transition-all border border-zinc-700">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5M16.5 12L12 16.5m0 0L7.5 12m4.5 4.5V3"/></svg>
                Export PPTX
            </button>
            <button id="btnSaveAnother" type="button" class="hidden flex items-center gap-1.5 bg-zinc-800 hover:bg-zinc-700 text-zinc-300 hover:text-white px-4 py-2 rounded-md text-xs font-bold transition-all border border-zinc-700">
                Save Another
            </button>
            <button id="btnSaveTemplate" type="button" class="flex items-center gap-1.5 bg-gradient-to-r from-orange-500 to-red-500 text-white px-6 py-2 rounded-md text-sm font-bold transition-all shadow-lg shadow-orange-500/20 hover:shadow-orange-500/40">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M17.5 3h-11a2 2 0 00-2 2v14a2 2 0 002 2h11a2 2 0 002-2v-14a2 2 0 00-2-2z"/><path stroke-linecap="round" stroke-linejoin="round" d="M9 13h6m-3-3v6"/></svg>
                <span id="btnSaveTemplateLabel">Save Layout</span>
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
                <button id="btnAlignLeft" class="toolbar-btn active" title="Align left"><svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25H12"/></svg></button>
                <button id="btnAlignCenter" class="toolbar-btn" title="Align center"><svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M6.75 12h10.5M3.75 17.25h16.5"/></svg></button>
                <button id="btnAlignRight" class="toolbar-btn" title="Align right"><svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5M12 17.25h8.25"/></svg></button>
                <button id="btnAlignJustify" class="toolbar-btn" title="Justify (pantay sa magkabilang gilid)"><svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 5.25h16.5M3.75 9.75h16.5M3.75 14.25h16.5M3.75 18.75h16.5"/></svg></button>
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

                    <!-- Always-available reset: return to empty white canvas without leaving the editor -->
                    <div class="template-card border border-zinc-700 bg-[#18181b] rounded-lg p-2 cursor-pointer transition-all flex flex-col gap-2 shadow-sm hover:border-orange-500" data-preset="blank" title="Reset to a blank white page">
                        <div class="w-full h-32 bg-white rounded border border-zinc-600 overflow-hidden relative pointer-events-none flex flex-col items-center justify-center gap-2">
                            <svg class="w-8 h-8 text-zinc-300" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m2.25 0H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z"/></svg>
                            <div class="text-[10px] font-bold text-zinc-400 uppercase tracking-widest">Blank</div>
                        </div>
                        <div class="flex items-center justify-between gap-2 px-1">
                            <div class="text-xs font-semibold text-zinc-300 truncate">Blank Page</div>
                            <span class="shrink-0 rounded-full border border-zinc-700 bg-zinc-900 px-2 py-0.5 text-[10px] font-bold uppercase tracking-widest text-zinc-400">Reset</span>
                        </div>
                    </div>
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
                            data-title="<?= htmlspecialchars((string) ($tpl['title'] ?? 'Untitled')) ?>"
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
                    <div>
                        <div class="text-[11px] font-bold text-zinc-500 uppercase tracking-widest mb-2">Official Logos</div>
                        <div class="grid grid-cols-3 gap-2">
                            <button type="button" class="preset-logo-btn group rounded-xl border border-zinc-700 bg-[#18181b] p-2 hover:border-orange-500/60 hover:bg-orange-500/10 transition text-center" data-logo-src="/assets/CCS.png" data-logo-name="CCS">
                                <div class="rounded-lg bg-zinc-900 border border-zinc-800 flex items-center justify-center overflow-hidden mb-1.5 p-1.5" style="aspect-ratio:1/1">
                                    <img src="/assets/CCS.png" alt="CCS" class="max-w-full max-h-full object-contain">
                                </div>
                                <span class="text-[10px] font-bold text-zinc-400 group-hover:text-orange-400 uppercase tracking-wider">CCS</span>
                            </button>
                            <button type="button" class="preset-logo-btn group rounded-xl border border-zinc-700 bg-[#18181b] p-2 hover:border-orange-500/60 hover:bg-orange-500/10 transition text-center" data-logo-src="/assets/BSIT.png" data-logo-name="BSIT">
                                <div class="rounded-lg bg-zinc-900 border border-zinc-800 flex items-center justify-center overflow-hidden mb-1.5 p-1.5" style="aspect-ratio:1/1">
                                    <img src="/assets/BSIT.png" alt="BSIT" class="max-w-full max-h-full object-contain">
                                </div>
                                <span class="text-[10px] font-bold text-zinc-400 group-hover:text-orange-400 uppercase tracking-wider">BSIT</span>
                            </button>
                            <button type="button" class="preset-logo-btn group rounded-xl border border-zinc-700 bg-[#18181b] p-2 hover:border-orange-500/60 hover:bg-orange-500/10 transition text-center" data-logo-src="/assets/CS.png" data-logo-name="CS">
                                <div class="rounded-lg bg-zinc-900 border border-zinc-800 flex items-center justify-center overflow-hidden mb-1.5 p-1.5" style="aspect-ratio:1/1">
                                    <img src="/assets/CS.png" alt="CS" class="max-w-full max-h-full object-contain">
                                </div>
                                <span class="text-[10px] font-bold text-zinc-400 group-hover:text-orange-400 uppercase tracking-wider">CS</span>
                            </button>
                        </div>
                    </div>

                    <div class="h-px w-full bg-zinc-800"></div>

                    <label class="w-full flex-shrink-0 flex flex-col items-center justify-center gap-2 rounded-xl border-2 border-dashed border-zinc-700 bg-zinc-800/30 px-4 py-6 text-sm font-bold text-zinc-300 hover:bg-orange-500/10 hover:border-orange-500/50 hover:text-orange-400 cursor-pointer transition-all shadow-sm">
                        <svg class="w-8 h-8 opacity-60 mb-1 text-zinc-600" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 16.5V9.75m0 0l3 3m-3-3l-3 3M6.75 19.5a4.5 4.5 0 01-1.41-8.775 5.25 5.25 0 0110.233-2.33 3 3 0 013.758 3.848A3.752 3.752 0 0118 19.5H6.75z"/></svg>
                        Upload Custom Logo
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
                    <button id="addCertificateCode" type="button" class="w-full rounded-md bg-gradient-to-r from-emerald-500/10 to-teal-500/10 border border-emerald-500/30 p-4 flex flex-col justify-center items-center gap-1 hover:from-emerald-500/20 hover:to-teal-500/20 transition-all shadow-sm group">
                        <span class="text-[13px] font-bold text-emerald-400">Insert Certificate Code</span>
                        <span class="text-[10px] text-emerald-600/70 font-mono tracking-tight">Sample / registrar codes</span>
                    </button>

                    <div id="certificateCodeInputPanel" class="hidden rounded-xl border border-emerald-500/30 bg-emerald-500/5 p-3 space-y-2">
                        <label class="block text-[10px] font-bold uppercase tracking-widest text-emerald-400/90">Registrar code(s)</label>
                        <textarea
                            id="certificateCodeInput"
                            rows="4"
                            placeholder="place code here"
                            class="w-full rounded-lg border border-zinc-700 bg-zinc-900 px-3 py-2 text-xs font-mono text-zinc-100 outline-none focus:border-emerald-500 focus:ring-2 focus:ring-emerald-500/20 resize-y min-h-[88px]"
                        ></textarea>
                        <div class="flex gap-2">
                            <button type="button" id="btnPlaceCertificateCode" class="flex-1 rounded-lg bg-emerald-600 hover:bg-emerald-500 text-white text-[11px] font-bold py-2 transition">
                                Place on certificate
                            </button>
                            <button type="button" id="btnCancelCertificateCode" class="rounded-lg border border-zinc-700 bg-zinc-900 hover:bg-zinc-800 text-zinc-300 text-[11px] font-bold px-3 py-2 transition">
                                Cancel
                    </button>
                        </div>
                    </div>
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
                        <h3 id="saveLayoutModalTitle" class="text-base font-bold text-zinc-100 leading-none">Save Template</h3>
                        <p id="saveLayoutModalSubtitle" class="text-[11px] text-zinc-500 mt-1 uppercase tracking-wider font-semibold">Workspace Layout</p>
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

    <!-- Export progress overlay (1–100%) -->
    <div id="exportProgressOverlay" class="hidden fixed inset-0 z-[300] bg-black/70 backdrop-blur-sm items-center justify-center p-6" aria-live="polite" aria-busy="true">
        <div class="w-full max-w-md rounded-2xl border border-zinc-700 bg-[#121214] shadow-2xl p-6">
            <div class="flex items-center justify-between gap-3 mb-3">
                <div>
                    <p class="text-sm font-bold text-white">Exporting PPTX</p>
                    <p id="exportProgressLabel" class="text-xs text-zinc-400 mt-0.5">Preparing…</p>
                </div>
                <div id="exportProgressPct" class="text-2xl font-black tabular-nums text-orange-400">1%</div>
            </div>
            <div class="h-3 w-full rounded-full bg-zinc-800 overflow-hidden border border-zinc-700">
                <div id="exportProgressBar" class="h-full w-[1%] rounded-full bg-gradient-to-r from-orange-500 to-amber-400 transition-[width] duration-200 ease-out"></div>
            </div>
            <p class="text-[11px] text-zinc-500 mt-3">Exports the open canvas only — no database reload. Import / Link Cert reads the PPTX later.</p>
        </div>
    </div>

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
/** @type {{ id: string, title: string, scope: string, sessionId: string } | null} */
let editingTemplate = <?= $templateId !== '' ? json_encode([
    'id' => $templateId,
    'title' => $initialEditingTitle,
    'scope' => $initialEditingScope !== '' ? $initialEditingScope : $initialTemplateScope,
    'sessionId' => $sessionId,
], JSON_UNESCAPED_SLASHES) : 'null' ?>;

function syncSaveButtons() {
    const primaryLabel = document.getElementById('btnSaveTemplateLabel');
    const btnAnother = document.getElementById('btnSaveAnother');
    const hasExisting = !!(editingTemplate && editingTemplate.id);
    if (primaryLabel) primaryLabel.textContent = hasExisting ? 'Save Changes' : 'Save Layout';
    if (btnAnother) btnAnother.classList.toggle('hidden', !hasExisting);
}

function setEditingTemplate(meta) {
    if (meta && meta.id) {
        editingTemplate = {
            id: String(meta.id),
            title: String(meta.title || ''),
            scope: String(meta.scope || 'library'),
            sessionId: String(meta.sessionId || ''),
        };
    } else {
        editingTemplate = null;
    }
    syncSaveButtons();
}

/**
 * @param {(name: string) => void} onSave
 * @param {{ mode?: 'create'|'update'|'another', defaultName?: string }} opts
 */
function showSaveLayoutModal(onSave, opts = {}) {
    const mode = opts.mode || 'create';
    const modal = document.getElementById('saveLayoutModal');
    const content = document.getElementById('saveLayoutModalContent');
    const nameInput = document.getElementById('saveTemplateName');
    const titleEl = document.getElementById('saveLayoutModalTitle');
    const subtitleEl = document.getElementById('saveLayoutModalSubtitle');
    const confirmBtn = document.getElementById('btnConfirmSave');

    if (titleEl) {
        titleEl.textContent = mode === 'update' ? 'Save Changes' : (mode === 'another' ? 'Save Another' : 'Save Template');
    }
    if (subtitleEl) {
        subtitleEl.textContent = mode === 'update'
            ? 'Update this template'
            : (mode === 'another' ? 'Create a new copy' : 'Workspace Layout');
    }
    if (confirmBtn) {
        confirmBtn.textContent = mode === 'update' ? 'Save Changes' : (mode === 'another' ? 'Save Another' : 'Save Layout');
    }
    nameInput.value = opts.defaultName || '';
    
    modal.classList.remove('hidden');
    setTimeout(() => {
        modal.classList.remove('opacity-0');
        content.classList.remove('scale-90');
        nameInput.focus();
        nameInput.select();
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
    
    nameInput.onkeydown = (e) => {
        if (e.key === 'Enter') document.getElementById('btnConfirmSave').click();
    };
}

syncSaveButtons();

// --- Toast Notifications ---
function escapeToastHtml(value) {
    return String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

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
        
    toast.innerHTML = `${icon}<span class="text-sm font-bold tracking-tight">${escapeToastHtml(message)}</span>`;
    container.appendChild(toast);
    
    // Errors (e.g. "code already used in …") need reading time.
    const visibleFor = type === 'success' ? 4000 : 9000;
    setTimeout(() => { toast.classList.remove('translate-y-10', 'opacity-0'); }, 10);
    setTimeout(() => {
        toast.classList.add('translate-y-[-20px]', 'opacity-0');
        setTimeout(() => toast.remove(), 500);
    }, visibleFor);
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

/** Stable ids for PPTX round-trip (descr="pc:{id}"). */
function ensureFabricObjectIds() {
    canvas.getObjects().forEach((o) => {
        if (!o || o.id) return;
        const id = (typeof crypto !== 'undefined' && crypto.randomUUID)
            ? ('pc_' + crypto.randomUUID().replace(/-/g, '').slice(0, 16))
            : ('pc_' + Date.now().toString(36) + Math.random().toString(36).slice(2, 10));
        o.set('id', id);
    });
}

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
function defaultTextboxWidth() {
    return Math.round(Math.min(canvas.width * 0.72, Math.max(320, canvas.width - 180)));
}

/**
 * Convert non-wrapping IText/Text into a wrapping Textbox (keeps style/position).
 * Returns the Textbox (or the original if already wrapping).
 */
function ensureTextWraps(obj) {
    if (!obj || !canvas) return obj;
    const type = String(obj.type || '').toLowerCase();
    if (type === 'textbox') {
        const minW = defaultTextboxWidth() / Math.max(0.01, obj.scaleX || 1);
        if (!obj.width || obj.width < 120) {
            obj.set({ width: minW });
            if (typeof obj.initDimensions === 'function') obj.initDimensions();
            obj.setCoords();
            canvas.requestRenderAll();
        }
        return obj;
    }
    if (type !== 'i-text' && type !== 'text') return obj;

    const scaleX = obj.scaleX || 1;
    const scaleY = obj.scaleY || 1;
    const visualW = Math.max(
        (typeof obj.getScaledWidth === 'function' ? obj.getScaledWidth() : (obj.width || 0) * scaleX) || 0,
        defaultTextboxWidth()
    );
    const width = visualW / scaleX;

    const tb = new fabric.Textbox(String(obj.text || ''), {
        left: obj.left,
        top: obj.top,
        originX: obj.originX || 'center',
        originY: obj.originY || 'center',
        fontFamily: obj.fontFamily || 'Inter',
        fontSize: obj.fontSize || 20,
        fontWeight: obj.fontWeight || 'normal',
        fontStyle: obj.fontStyle || 'normal',
        underline: !!obj.underline,
        fill: obj.fill || '#000000',
        textAlign: obj.textAlign || 'center',
        angle: obj.angle || 0,
        scaleX,
        scaleY,
        opacity: obj.opacity == null ? 1 : obj.opacity,
        width,
        id: obj.id,
        name: obj.name,
        selectable: true,
        evented: true,
    });

    canvas.remove(obj);
    canvas.add(tb);
    canvas.setActiveObject(tb);
    canvas.requestRenderAll();
    isCanvasDirty = true;
    if (typeof syncToolbars === 'function') syncToolbars();
    return tb;
}

function addCanvasText(text, size, bold = false, slot = 'body') {
    const txt = new fabric.Textbox(text, {
        ...getTextSlotCenter(slot),
        fontFamily: 'Inter',
        fontSize: size,
        fontWeight: bold ? 'bold' : 'normal',
        fill: '#000000',
        textAlign: 'center',
        width: defaultTextboxWidth(),
        textSlot: slot,
    });
    canvas.add(txt);
    canvas.setActiveObject(txt);
    canvas.renderAll();
    isCanvasDirty = true;
    if (typeof pushHistoryState === 'function') pushHistoryState();
}

document.getElementById('addHeading').addEventListener('click',      () => addCanvasText('CERTIFICATE TITLE', 60, true, 'heading'));
document.getElementById('addSubheading').addEventListener('click',   () => addCanvasText('Subheading Text', 30, false, 'subheading'));
document.getElementById('addBodyText').addEventListener('click',     () => addCanvasText('Double click to edit text...', 20, false, 'body'));
document.getElementById('addAutoName').addEventListener('click',     () => addCanvasText('{{participant_name}}', 50, true, 'participant_name'));

/** @type {string[]} */
let pendingRegistrarCodes = [];

function parseRegistrarCodesInput(raw) {
    return String(raw || '')
        .split(/[\r\n,;]+/)
        .map((s) => s.trim())
        .filter(Boolean);
}

function findCertificateCodeObject() {
    return (canvas.getObjects() || []).find((o) => {
        const id = String(o?.id || '').toLowerCase();
        const name = String(o?.name || '').toLowerCase();
        return id === 'certificate_code' || name === 'certificate code';
    }) || null;
}

function placeCertificateCodeOnCanvas(sampleCode) {
    const code = String(sampleCode || '').trim();
    if (!code) return null;
    const boxWidth = Math.round(Math.min(canvas.width * 0.55, 520));
    const existing = findCertificateCodeObject();
    if (existing) {
        existing.set({
            text: code,
            id: 'certificate_code',
            name: 'Certificate Code',
        });
        if (typeof existing.initDimensions === 'function') existing.initDimensions();
        existing.setCoords();
        canvas.setActiveObject(existing);
        canvas.requestRenderAll();
        isCanvasDirty = true;
        if (typeof pushHistoryState === 'function') pushHistoryState();
        return existing;
    }
    const txt = new fabric.Textbox(code, {
        ...getTextSlotCenter('certificate_code'),
        fontFamily: 'Inter',
        fontSize: 18,
        fontWeight: 'bold',
        fill: '#111827',
        textAlign: 'center',
        width: boxWidth,
        id: 'certificate_code',
        name: 'Certificate Code',
        textSlot: 'certificate_code',
    });
    canvas.add(txt);
    canvas.setActiveObject(txt);
    canvas.renderAll();
    isCanvasDirty = true;
    if (typeof pushHistoryState === 'function') pushHistoryState();
    return txt;
}

const certificateCodeInputPanel = document.getElementById('certificateCodeInputPanel');
const certificateCodeInput = document.getElementById('certificateCodeInput');

document.getElementById('addCertificateCode')?.addEventListener('click', () => {
    if (!certificateCodeInputPanel) return;
    certificateCodeInputPanel.classList.remove('hidden');
    if (certificateCodeInput && !certificateCodeInput.value.trim()) {
        if (pendingRegistrarCodes.length) {
            certificateCodeInput.value = pendingRegistrarCodes.join('\n');
        } else {
            const existing = findCertificateCodeObject();
            const t = String(existing?.text || '').trim();
            if (t && !/\{\{\s*certificate_code\s*\}\}/i.test(t)) {
                certificateCodeInput.value = t;
            }
        }
    }
    certificateCodeInput?.focus();
    certificateCodeInputPanel.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
});

document.getElementById('btnCancelCertificateCode')?.addEventListener('click', () => {
    certificateCodeInputPanel?.classList.add('hidden');
});

document.getElementById('btnPlaceCertificateCode')?.addEventListener('click', () => {
    const codes = parseRegistrarCodesInput(certificateCodeInput?.value || '');
    if (!codes.length) {
        showNotification('Place a code first.', 'error');
        certificateCodeInput?.focus();
        return;
    }
    // One sample per design — avoid stacking codes when switching templates later.
    const sample = codes[0];
    pendingRegistrarCodes = [sample];
    if (certificateCodeInput) certificateCodeInput.value = sample;
    placeCertificateCodeOnCanvas(sample);
    certificateCodeInputPanel?.classList.add('hidden');
    showNotification('Code placed on certificate.');
});
document.getElementById('addSignatoryLine').addEventListener('click', () => {
    const cx = canvas.width / 2;
    const lineY = getTextSlotTop('signatory');
    const half = 140;
    const line = new fabric.Line([cx - half, lineY, cx + half, lineY], {
        stroke: '#111827',
        strokeWidth: 2,
        selectable: true,
        evented: true,
        name: 'signature_line',
    });
    const label = new fabric.IText('Authorized Signature', {
        left: cx,
        top: lineY + 18,
        fontFamily: 'Inter',
        fontSize: 18,
        fill: '#111827',
        originX: 'center',
        originY: 'top',
        textAlign: 'center',
        textSlot: 'signatory',
    });
    canvas.add(line, label);
    canvas.setActiveObject(label);
    canvas.renderAll();
});

// Existing IText objects (from old templates) won't wrap on paste — convert when editing starts.
canvas.on('editing:entered', (opt) => {
    const obj = opt?.target;
    if (!obj) return;
    const type = String(obj.type || '').toLowerCase();
    if (type !== 'i-text' && type !== 'text') return;
    // Swap to Textbox, then resume editing so long paste wraps.
    obj.exitEditing();
    const tb = ensureTextWraps(obj);
    setTimeout(() => {
        if (!tb) return;
        tb.enterEditing();
        if (tb.hiddenTextarea) {
            const len = String(tb.text || '').length;
            tb.selectionStart = len;
            tb.selectionEnd = len;
            tb.hiddenTextarea.focus();
        }
        canvas.requestRenderAll();
    }, 0);
});

// Long single-line IText (already pasted) → wrap as soon as selected.
function maybeWrapSelectedText(obj) {
    if (!obj) return;
    const type = String(obj.type || '').toLowerCase();
    if (type !== 'i-text' && type !== 'text') return;
    const text = String(obj.text || '');
    const scaledW = typeof obj.getScaledWidth === 'function' ? obj.getScaledWidth() : (obj.width || 0);
    if (text.length > 40 || scaledW > defaultTextboxWidth()) {
        ensureTextWraps(obj);
    }
}
canvas.on('selection:created', (opt) => maybeWrapSelectedText(opt?.selected?.[0] || opt?.target));
canvas.on('selection:updated', (opt) => maybeWrapSelectedText(opt?.selected?.[0] || opt?.target));

// Keep wrapping width usable while editing Textbox (paste long paragraphs).
canvas.on('text:changed', (opt) => {
    const obj = opt?.target;
    if (!obj || String(obj.type || '').toLowerCase() !== 'textbox') return;
    const minW = defaultTextboxWidth() / Math.max(0.01, obj.scaleX || 1);
    if ((obj.width || 0) < minW * 0.5) {
        obj.set({ width: minW });
        if (typeof obj.initDimensions === 'function') obj.initDimensions();
        canvas.requestRenderAll();
    }
});

// --- Image Uploads ---
function editorYield() {
    return new Promise((r) => setTimeout(r, 0));
}

/** Downscale/recompress a data URL so save/export stay fast. */
function compressDataUrl(dataUrl, {
    maxEdge = 1600,
    quality = 0.78,
    preferJpeg = true,
    force = false,
} = {}) {
    return new Promise((resolve) => {
        try {
            const src = String(dataUrl || '');
            if (!src.startsWith('data:image/')) {
                resolve(src);
                return;
            }
            // Skip tiny assets unless export forces a re-encode.
            if (!force && src.length < 90000) {
                resolve(src);
                return;
            }
            const img = new Image();
            img.onload = () => {
                try {
                    const w = img.naturalWidth || img.width || 1;
                    const h = img.naturalHeight || img.height || 1;
                    const scale = Math.min(1, maxEdge / Math.max(w, h));
                    const tw = Math.max(1, Math.round(w * scale));
                    const th = Math.max(1, Math.round(h * scale));
                    // Already within budget and not forcing — keep original.
                    if (!force && scale >= 0.98 && src.length < 180000 && /^data:image\/jpe?g/i.test(src)) {
                        resolve(src);
                        return;
                    }
                    const c = document.createElement('canvas');
                    c.width = tw;
                    c.height = th;
                    const ctx = c.getContext('2d');
                    if (!ctx) {
                        resolve(src);
                        return;
                    }
                    // JPEG has no alpha — fill white so logos don't get a black box.
                    if (preferJpeg) {
                        ctx.fillStyle = '#ffffff';
                        ctx.fillRect(0, 0, tw, th);
                    }
                    ctx.drawImage(img, 0, 0, tw, th);
                    const hasAlpha = /image\/png/i.test(src) && !preferJpeg;
                    const out = hasAlpha
                        ? c.toDataURL('image/png')
                        : c.toDataURL('image/jpeg', quality);
                    if (!out) {
                        resolve(src);
                        return;
                    }
                    // Export must shrink; save keeps original only if compression lost.
                    if (force || out.length < src.length) {
                        resolve(out);
                    } else {
                        resolve(src);
                    }
                } catch (_) {
                    resolve(src);
                }
            };
            img.onerror = () => resolve(src);
            img.src = src;
        } catch (_) {
            resolve(String(dataUrl || ''));
        }
    });
}

function dataUrlToBlob(dataUrl) {
    try {
        const src = String(dataUrl || '');
        const m = /^data:([^;,]+);base64,(.+)$/i.exec(src);
        if (!m) return null;
        const bin = atob(m[2]);
        const len = bin.length;
        const bytes = new Uint8Array(len);
        for (let i = 0; i < len; i++) bytes[i] = bin.charCodeAt(i);
        return new Blob([bytes], { type: m[1] || 'application/octet-stream' });
    } catch (_) {
        return null;
    }
}

/** Compress from an already-decoded HTMLImageElement / canvas — avoids re-parsing multi‑MB data URLs. */
function compressFromElement(el, {
    maxEdge = 960,
    quality = 0.58,
    preferJpeg = true,
} = {}) {
    return new Promise((resolve) => {
        try {
            if (!el) {
                resolve('');
                return;
            }
            const w = el.naturalWidth || el.width || 1;
            const h = el.naturalHeight || el.height || 1;
            const scale = Math.min(1, maxEdge / Math.max(w, h));
            const tw = Math.max(1, Math.round(w * scale));
            const th = Math.max(1, Math.round(h * scale));
            const c = document.createElement('canvas');
            c.width = tw;
            c.height = th;
            const ctx = c.getContext('2d');
            if (!ctx) {
                resolve('');
                return;
            }
            if (preferJpeg) {
                ctx.fillStyle = '#ffffff';
                ctx.fillRect(0, 0, tw, th);
            }
            ctx.drawImage(el, 0, 0, tw, th);
            resolve(preferJpeg ? c.toDataURL('image/jpeg', quality) : c.toDataURL('image/png'));
        } catch (_) {
            resolve('');
        }
    });
}

/**
 * Serialize canvas without embedding multi‑MB image srcs (stub getSrc during toJSON).
 * Then attach lightly compressed JPEGs from live bitmap elements.
 */
async function buildLiveExportCanvasState() {
    const stubbed = [];
    const stubGetSrc = (img) => {
        if (!img || typeof img.getSrc !== 'function') return;
        stubbed.push([img, img.getSrc]);
        img.getSrc = () => '';
    };
    canvas.getObjects().forEach((o) => {
        if (String(o.type || '').toLowerCase() === 'image') stubGetSrc(o);
    });
    if (canvas.backgroundImage) stubGetSrc(canvas.backgroundImage);

    let state;
    try {
        state = canvas.toJSON(['crossOrigin', 'selectable', 'evented', 'id', 'name', 'logoSlot', 'textSlot']);
    } finally {
        stubbed.forEach(([img, fn]) => {
            try { img.getSrc = fn; } catch (_) {}
        });
    }

    state.width = canvas.getWidth();
    state.height = canvas.getHeight();

    const jobs = [];
    if (canvas.backgroundImage && state.backgroundImage) {
        const el = canvas.backgroundImage._element || canvas.backgroundImage.getElement?.();
        jobs.push((async () => {
            const src = await compressFromElement(el, { maxEdge: 900, quality: 0.52, preferJpeg: true });
            if (src) state.backgroundImage.src = src;
        })());
    }
    const liveObjects = canvas.getObjects();
    if (Array.isArray(state.objects)) {
        state.objects.forEach((jo, idx) => {
            const live = liveObjects[idx];
            if (!jo || !live) return;
            if (String(live.type || jo.type || '').toLowerCase() !== 'image') return;
            const el = live._element || live.getElement?.();
            const disp = Math.max(
                (Number(live.width) || 0) * (Number(live.scaleX) || 1),
                (Number(live.height) || 0) * (Number(live.scaleY) || 1)
            );
            const preferJpeg = disp > 420;
            jobs.push((async () => {
                const src = await compressFromElement(el, {
                    maxEdge: preferJpeg ? 640 : 320,
                    quality: 0.55,
                    preferJpeg,
                });
                if (src) jo.src = src;
            })());
        });
    }
    await Promise.all(jobs);
    return minifyCanvasStateForExport(state);
}

async function slimCanvasStateImages(state, { maxEdge = 1200, quality = 0.72, force = false } = {}) {
    if (!state || typeof state !== 'object') return state;
    const slimOne = async (obj, edge = maxEdge, q = quality, preferJpeg = true) => {
        if (!obj || typeof obj !== 'object') return;
        const src = String(obj.src || '');
        if (!src.startsWith('data:image/')) return;
        if (force) {
            // Skip assets already small enough for a fast upload.
            if (/^data:image\/jpe?g/i.test(src) && src.length < 140000) return;
            if (/image\/png/i.test(src) && src.length < 100000 && edge <= 400) return;
        } else {
            if (/^data:image\/jpe?g/i.test(src) && src.length < 700000) return;
            if (src.length <= 90000) return;
        }
        obj.src = await compressDataUrl(src, { maxEdge: edge, quality: q, preferJpeg, force });
    };
    const jobs = [];
    if (state.backgroundImage) {
        jobs.push(slimOne(state.backgroundImage, Math.min(1400, maxEdge + 200), quality, true));
    }
    if (Array.isArray(state.objects)) {
        for (const obj of state.objects) {
            const t = String(obj?.type || '').toLowerCase();
            if (t === 'image' || obj?.src) {
                const disp = Math.max(
                    (Number(obj.width) || 0) * (Number(obj.scaleX) || 1),
                    (Number(obj.height) || 0) * (Number(obj.scaleY) || 1)
                );
                const isPng = /image\/png/i.test(String(obj.src || ''));
                // Keep alpha on small logo/signature PNGs; JPEG everything else.
                const preferJpeg = force ? (disp > 420 || !isPng) : true;
                const edge = force ? (disp > 420 ? maxEdge : Math.min(400, maxEdge)) : maxEdge;
                jobs.push(slimOne(obj, edge, quality, preferJpeg));
            }
        }
    }
    await Promise.all(jobs);
    return state;
}

/** Drop Fabric junk so export JSON stays tiny (text + geometry + image refs). */
function minifyCanvasStateForExport(state) {
    if (!state || typeof state !== 'object') return state;
    const keepImage = (o) => {
        if (!o || typeof o !== 'object') return null;
        return {
            type: 'image',
            src: o.src,
            left: o.left,
            top: o.top,
            width: o.width,
            height: o.height,
            scaleX: o.scaleX,
            scaleY: o.scaleY,
            angle: o.angle,
            originX: o.originX,
            originY: o.originY,
            opacity: o.opacity,
            visible: o.visible,
            id: o.id,
            name: o.name,
        };
    };
    const keepObj = (o) => {
        if (!o || typeof o !== 'object') return null;
        const t = String(o.type || '').toLowerCase();
        if (t === 'image') return keepImage(o);
        const base = {
            type: o.type,
            left: o.left,
            top: o.top,
            width: o.width,
            height: o.height,
            scaleX: o.scaleX,
            scaleY: o.scaleY,
            angle: o.angle,
            originX: o.originX,
            originY: o.originY,
            fill: o.fill,
            stroke: o.stroke,
            strokeWidth: o.strokeWidth,
            opacity: o.opacity,
            visible: o.visible,
            id: o.id,
            name: o.name,
        };
        if (t === 'i-text' || t === 'text' || t === 'textbox') {
            base.text = o.text;
            base.fontSize = o.fontSize;
            base.fontFamily = o.fontFamily;
            base.fontWeight = o.fontWeight;
            base.fontStyle = o.fontStyle;
            base.underline = o.underline;
            base.textAlign = o.textAlign;
            base.lineHeight = o.lineHeight;
            base.charSpacing = o.charSpacing;
        }
        if (t === 'line') {
            base.x1 = o.x1;
            base.x2 = o.x2;
            base.y1 = o.y1;
            base.y2 = o.y2;
        }
        if (t === 'group' && Array.isArray(o.objects)) {
            base.objects = o.objects.map(keepObj).filter(Boolean);
        }
        return base;
    };
    return {
        version: state.version,
        width: state.width,
        height: state.height,
        background: state.background,
        backgroundColor: state.backgroundColor,
        backgroundImage: state.backgroundImage ? keepImage(state.backgroundImage) : undefined,
        objects: Array.isArray(state.objects) ? state.objects.map(keepObj).filter(Boolean) : [],
    };
}

const LOGO_SLOT_MAX_WIDTH = 140;

function getHeaderLogoCenterY() {
    const logoTop = Math.round(Math.max(44, canvas.height * 0.055));
    return logoTop + Math.round(LOGO_SLOT_MAX_WIDTH / 2);
}

function getTextSlotTop(slot) {
    const headerY = getHeaderLogoCenterY();
    const h = canvas.height;
    switch (slot) {
        case 'heading':
            return headerY;
        case 'subheading':
            return headerY + Math.round(h * 0.09);
        case 'participant_name':
            return Math.round(h * 0.50);
        case 'body':
            return Math.round(h * 0.65);
        case 'certificate_code':
            return Math.round(h * 0.78);
        case 'signatory':
            return Math.round(h * 0.72);
        default:
            return Math.round(h * 0.5);
    }
}

function getTextSlotCenter(slot) {
    return {
        left: canvas.width / 2,
        top: getTextSlotTop(slot),
        originX: 'center',
        originY: 'center',
    };
}

function getLogoSlotPosition(slot) {
    if (slot === 'center') {
        return {
            left: canvas.width / 2,
            top: canvas.height / 2,
            originX: 'center',
            originY: 'center',
        };
    }
    const padX = Math.round(Math.max(72, canvas.width * 0.08));
    const padY = Math.round(Math.max(44, canvas.height * 0.055));
    if (slot === 1) {
        return {
            left: padX,
            top: padY,
            originX: 'left',
            originY: 'top',
        };
    }
    return {
        left: canvas.width - padX,
        top: padY,
        originX: 'right',
        originY: 'top',
    };
}

function getOccupiedLogoSlots() {
    const occupied = new Set();
    canvas.getObjects().forEach((obj) => {
        if (obj?.type !== 'image') return;
        const slot = Number.parseInt(String(obj.logoSlot || ''), 10);
        if (slot === 1 || slot === 2) {
            occupied.add(slot);
        }
    });
    return occupied;
}

function resolveNextLogoSlot() {
    const occupied = getOccupiedLogoSlots();
    if (!occupied.has(1)) return 1;
    if (!occupied.has(2)) return 2;
    return 'center';
}

function placeLogoOnCanvas(img, name) {
    const slot = resolveNextLogoSlot();
    const maxWidth = slot === 'center' ? 220 : LOGO_SLOT_MAX_WIDTH;

    img.set({
        ...getLogoSlotPosition(slot),
        name: name || 'Logo',
        logoSlot: slot,
        crossOrigin: 'anonymous',
    });
    if (img.width > maxWidth) img.scaleToWidth(maxWidth);
    canvas.add(img);
    canvas.setActiveObject(img);
    canvas.renderAll();
    isCanvasDirty = true;
    pushHistoryState();
}

function addLogoFromUrl(url, name) {
    if (!url) return;
    fabric.Image.fromURL(url, (img) => {
        if (!img) {
            showNotification('Failed to load logo', 'error');
            return;
        }
        placeLogoOnCanvas(img, name);
    }, { crossOrigin: 'anonymous' });
}

function handleImageUpload(e, isBackground = false, placement = 'logo') {
    const file = e.target.files[0]; if (!file) return;
    const reader = new FileReader();
    reader.onload = async (f) => {
        let dataUrl = String(f.target.result || '');
        // Compress at upload — from-scratch designs were embedding multi‑MB PNGs.
        dataUrl = await compressDataUrl(dataUrl, {
            maxEdge: isBackground ? 1400 : 800,
            quality: isBackground ? 0.78 : 0.76,
            preferJpeg: isBackground || !/image\/png/i.test(file.type || ''),
        });
        fabric.Image.fromURL(dataUrl, img => {
            if (isBackground) {
                img.set({ scaleX: canvas.width / img.width, scaleY: canvas.height / img.height, originX: 'left', originY: 'top', left: 0, top: 0 });
                canvas.setBackgroundImage(img, () => {
                    canvas.renderAll();
                    isCanvasDirty = true;
                    pushHistoryState();
                });
                isCanvasDirty = true;
            } else if (placement === 'logo') {
                placeLogoOnCanvas(img, file.name || 'Logo');
            } else {
                img.set({ left: canvas.width / 2, top: canvas.height / 2, originX: 'center', originY: 'center' });
                if (img.width > 250) img.scaleToWidth(250);
                canvas.add(img); canvas.setActiveObject(img); canvas.renderAll();
                isCanvasDirty = true;
                pushHistoryState();
            }
        });
    };
    reader.readAsDataURL(file); e.target.value = '';
}
document.getElementById('uploadBg').addEventListener('change',  e => handleImageUpload(e, true));
document.getElementById('uploadLogo').addEventListener('change', e => handleImageUpload(e, false, 'logo'));
document.getElementById('uploadSig').addEventListener('change',  e => handleImageUpload(e, false, 'signature'));
document.querySelectorAll('.preset-logo-btn').forEach((btn) => {
    btn.addEventListener('click', () => {
        addLogoFromUrl(btn.getAttribute('data-logo-src') || '', btn.getAttribute('data-logo-name') || 'Logo');
    });
});
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
    textToolbar.classList.remove('cert-line-color-mode');
});

function isFabricText(obj) {
    const t = String(obj?.type || '').toLowerCase();
    return t === 'i-text' || t === 'text' || t === 'textbox';
}

function isFabricLine(obj) {
    return String(obj?.type || '').toLowerCase() === 'line';
}

function colorPickerValueFromFabric(obj) {
    if (isFabricLine(obj)) {
        const stroke = obj.stroke;
        return (typeof stroke === 'string' && stroke.startsWith('#')) ? stroke : '#111827';
    }
    const fill = obj.fill;
    return (typeof fill === 'string' && fill.startsWith('#')) ? fill : '#000000';
}

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

    const fontColorEl = document.getElementById('fontColor');

    if (isFabricText(obj)) {
        textToolbar.classList.remove('opacity-30', 'pointer-events-none', 'cert-line-color-mode');
        fontColorEl.title = 'Text Color';
        document.getElementById('fontSize').value   = obj.fontSize   ?? 24;
        document.getElementById('fontFamily').value = obj.fontFamily ?? 'Inter';
        const fillVal = colorPickerValueFromFabric(obj);
        fontColorEl.value = fillVal;
        
        // Highlights for formatting buttons (prefer active character selection while editing)
        const selBold = hasTextSelection(obj) ? selectionStyleValue(obj, 'fontWeight') : undefined;
        const selItalic = hasTextSelection(obj) ? selectionStyleValue(obj, 'fontStyle') : undefined;
        const selUnderline = hasTextSelection(obj) ? selectionStyleValue(obj, 'underline') : undefined;
        const boldVal = selBold != null ? selBold : obj.fontWeight;
        const italicVal = selItalic != null ? selItalic : obj.fontStyle;
        const underVal = selUnderline != null ? !!selUnderline : !!obj.underline;
        document.getElementById('btnBold').classList.toggle('active', boldVal === 'bold' || boldVal === 700 || boldVal === '700');
        document.getElementById('btnItalic').classList.toggle('active', italicVal === 'italic');
        document.getElementById('btnUnderline').classList.toggle('active', underVal);

        const selSize = hasTextSelection(obj) ? selectionStyleValue(obj, 'fontSize') : undefined;
        const selFill = hasTextSelection(obj) ? selectionStyleValue(obj, 'fill') : undefined;
        const selFamily = hasTextSelection(obj) ? selectionStyleValue(obj, 'fontFamily') : undefined;
        if (selSize != null) document.getElementById('fontSize').value = selSize;
        if (typeof selFill === 'string' && selFill.startsWith('#')) fontColorEl.value = selFill;
        if (selFamily) document.getElementById('fontFamily').value = selFamily;
        
        // Highlights for alignment buttons
        const align = obj.textAlign || 'left';
        document.getElementById('btnAlignLeft').classList.toggle('active',   align === 'left');
        document.getElementById('btnAlignCenter').classList.toggle('active', align === 'center');
        document.getElementById('btnAlignRight').classList.toggle('active',  align === 'right');
        document.getElementById('btnAlignJustify')?.classList.toggle('active', align === 'justify');
    } else if (isFabricLine(obj)) {
        textToolbar.classList.remove('opacity-30', 'pointer-events-none');
        textToolbar.classList.add('cert-line-color-mode');
        fontColorEl.title = 'Line Color';
        fontColorEl.value = colorPickerValueFromFabric(obj);
    } else {
        textToolbar.classList.add('opacity-30', 'pointer-events-none');
        textToolbar.classList.remove('cert-line-color-mode');
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
function hasTextSelection(o) {
    if (!o || !o.isEditing) return false;
    const start = o.selectionStart;
    const end = o.selectionEnd;
    return typeof start === 'number' && typeof end === 'number' && end > start;
}

function selectionStyleValue(o, prop) {
    if (!hasTextSelection(o) || typeof o.getSelectionStyles !== 'function') return undefined;
    const styles = o.getSelectionStyles() || [];
    if (!styles.length) return undefined;
    const first = styles[0] ? styles[0][prop] : undefined;
    for (let i = 1; i < styles.length; i++) {
        const v = styles[i] ? styles[i][prop] : undefined;
        if (v !== first) return undefined; // mixed
    }
    return first;
}

function applyTextStyle(o, props, { selectionOnly = false } = {}) {
    if (!isFabricText(o)) return;

    // Apply to highlighted characters only (partial bold/italic/underline/color/size).
    if (selectionOnly && hasTextSelection(o) && typeof o.setSelectionStyles === 'function') {
        o.setSelectionStyles(props);
        if (typeof o.initDimensions === 'function') o.initDimensions();
        o.setCoords();
        canvas.requestRenderAll();
        return;
    }

    // Whole-object style (no active character selection).
    if (o.isEditing && typeof o.exitEditing === 'function') {
        o.exitEditing();
    }
    o.set(props);
    if (typeof o.initDimensions === 'function') o.initDimensions();
    o.setCoords();
}

function setStyle(prop, val) {
    executeActiveObj(o => applyTextStyle(o, { [prop]: val }, { selectionOnly: true }));
}

// Keep character selection when clicking formatting BUTTONS while editing.
// Do NOT preventDefault on <select>/<input> — that blocks the font dropdown from opening.
textToolbar?.addEventListener('mousedown', (e) => {
    if (e.target.closest('button')) {
        e.preventDefault();
    }
});

document.getElementById('fontSize').addEventListener('change', e => setStyle('fontSize', parseInt(e.target.value) || 24));
document.getElementById('btnSizeInc').addEventListener('click', () => {
    const el = document.getElementById('fontSize');
    const obj = canvas.getActiveObject();
    const cur = (hasTextSelection(obj) ? selectionStyleValue(obj, 'fontSize') : null) ?? (parseInt(el.value) || 24);
    const s = cur + 2;
    el.value = s;
    setStyle('fontSize', s);
});
document.getElementById('btnSizeDec').addEventListener('click', () => {
    const el = document.getElementById('fontSize');
    const obj = canvas.getActiveObject();
    const cur = (hasTextSelection(obj) ? selectionStyleValue(obj, 'fontSize') : null) ?? (parseInt(el.value) || 24);
    const s = Math.max(8, cur - 2);
    el.value = s;
    setStyle('fontSize', s);
});
document.getElementById('fontColor').addEventListener('input', e => {
    executeActiveObj(o => {
        if (isFabricLine(o)) {
            o.set('stroke', e.target.value);
            o.setCoords();
            return;
        }
        applyTextStyle(o, { fill: e.target.value }, { selectionOnly: true });
    });
});
document.getElementById('fontFamily').addEventListener('change', e => setStyle('fontFamily', e.target.value));
document.getElementById('btnBold').addEventListener('click', () => executeActiveObj(o => {
    const fromSel = hasTextSelection(o) ? selectionStyleValue(o, 'fontWeight') : undefined;
    const current = fromSel != null ? fromSel : o.fontWeight;
    const bold = current === 'bold' || current === 700 || current === '700';
    applyTextStyle(o, { fontWeight: bold ? 'normal' : 'bold' }, { selectionOnly: true });
}));
document.getElementById('btnItalic').addEventListener('click', () => executeActiveObj(o => {
    const fromSel = hasTextSelection(o) ? selectionStyleValue(o, 'fontStyle') : undefined;
    const current = fromSel != null ? fromSel : o.fontStyle;
    applyTextStyle(o, { fontStyle: current === 'italic' ? 'normal' : 'italic' }, { selectionOnly: true });
}));
document.getElementById('btnUnderline').addEventListener('click', () => executeActiveObj(o => {
    const fromSel = hasTextSelection(o) ? selectionStyleValue(o, 'underline') : undefined;
    const current = fromSel != null ? !!fromSel : !!o.underline;
    applyTextStyle(o, { underline: !current }, { selectionOnly: true });
}));

// Align text INSIDE the box only — do not move the whole object on the canvas.
['Left', 'Center', 'Right', 'Justify'].forEach(a => {
    document.getElementById('btnAlign' + a)?.addEventListener('click', () => {
        const align = a.toLowerCase();
        executeActiveObj(o => {
            // Justify needs a Textbox with fixed width so both edges look even.
            if (align === 'justify' && String(o.type || '').toLowerCase() !== 'textbox') {
                const tb = ensureTextWraps(o);
                if (tb && typeof applyTextStyle === 'function') applyTextStyle(tb, { textAlign: 'justify' });
                else if (tb) tb.set({ textAlign: 'justify' });
                return;
            }
            if (typeof applyTextStyle === 'function') applyTextStyle(o, { textAlign: align });
            else o.set({ textAlign: align });
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

// --- Save Template (create / update / another) ---
function resolveTemplateScope() {
    const scopeEl = document.getElementById('templateScopeSelect');
    const scopeValue = scopeEl?.value || (<?= json_encode($initialTemplateScope) ?>);
    let templateScope = 'library';
    let selectedSessionId = '';
    if (typeof scopeValue === 'string' && scopeValue.startsWith('session:')) {
        templateScope = 'session';
        selectedSessionId = scopeValue.split(':')[1] || '';
    } else if (scopeValue === 'session') {
        templateScope = 'session';
    } else if (scopeValue === 'event' || <?= json_encode($eventId !== '') ?>) {
        templateScope = <?= json_encode($eventId !== '' ? 'event' : 'library') ?>;
    } else {
        templateScope = 'library';
    }
    return { templateScope, selectedSessionId };
}

async function buildCanvasSavePayload() {
        canvas.discardActiveObject(); 
        canvas.renderAll();
        ensureFabricObjectIds();
    // Yield so the "Saving…" label paints before heavy serialize/rasterize.
    await new Promise((r) => setTimeout(r, 0));
    const jsonState = canvas.toJSON(['src', 'crossOrigin', 'selectable', 'evented', 'id', 'name', 'logoSlot', 'textSlot']);
    // Prefer existing JSON src — getSrc() re-encodes and freezes on large BGs.
    if (canvas.backgroundImage && jsonState.backgroundImage && !jsonState.backgroundImage.src) {
        try {
            if (canvas.backgroundImage._element && canvas.backgroundImage._element.src) {
                jsonState.backgroundImage.src = canvas.backgroundImage._element.src;
            }
        } catch (_) {}
    }
    // From-scratch designs embed multi‑MB data URLs — slim before POST.
    await slimCanvasStateImages(jsonState, { maxEdge: 1200, quality: 0.72 });
    await new Promise((r) => setTimeout(r, 0));
    jsonState.customFonts = activeCustomFonts;
    // Single sample code for this design (Import/Link uses PPT scan; keep pending in sync with canvas).
    const existing = findCertificateCodeObject();
    const sample = String(existing?.text || '').trim();
    if (sample && !/\{\{\s*certificate_code\s*\}\}/i.test(sample)) {
        jsonState.pending_registrar_codes = [sample];
        pendingRegistrarCodes = [sample];
    } else {
        // Code removed / placeholder only — do not keep a stale seed from earlier saves.
        jsonState.pending_registrar_codes = [];
        pendingRegistrarCodes = [];
        if (certificateCodeInput) certificateCodeInput.value = '';
    }
    // Tiny library card preview — not print quality.
    let thumb = '';
    try {
        thumb = canvas.toDataURL({ format: 'jpeg', quality: 0.55, multiplier: 0.35, enableRetinaScaling: false });
    } catch (_) {
        try {
            thumb = canvas.toDataURL({ format: 'jpeg', quality: 0.45, multiplier: 0.25, enableRetinaScaling: false });
        } catch (__) {
            thumb = '';
        }
    }
    return { jsonState, thumb };
}

function editorFetchJson(url, body, timeoutMs = 90000) {
    const ctrl = new AbortController();
    const timer = setTimeout(() => ctrl.abort(), timeoutMs);
    return fetch(url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(body),
        signal: ctrl.signal,
    }).then(async (res) => {
        let data = null;
        try {
            data = await res.json();
        } catch (_) {
            data = null;
        }
        if (!res.ok) {
            const msg = (data && (data.error || data.message)) || (`Request failed (HTTP ${res.status})`);
            throw new Error(msg);
        }
        if (!data || typeof data !== 'object') {
            throw new Error('Invalid server response.');
        }
        return data;
    }).finally(() => clearTimeout(timer));
}

function editorFetchBlob(url, body, timeoutMs = 120000, onProgress) {
    const ctrl = new AbortController();
    const timer = setTimeout(() => ctrl.abort(), timeoutMs);
    const isForm = (typeof FormData !== 'undefined') && body instanceof FormData;
    return fetch(url, {
        method: 'POST',
        headers: isForm ? undefined : { 'Content-Type': 'application/json' },
        body: isForm ? body : JSON.stringify(body),
        signal: ctrl.signal,
    }).then(async (res) => {
        if (!res.ok) {
            let msg = 'Export failed';
            try {
                const err = await res.json();
                msg = err.error || msg;
            } catch (_) {}
            throw new Error(msg);
        }
        // Prefer streaming read so we can report real download % when Content-Length is set.
        if (typeof onProgress === 'function' && res.body && typeof res.body.getReader === 'function') {
            const total = Number(res.headers.get('Content-Length') || 0);
            const reader = res.body.getReader();
            const chunks = [];
            let received = 0;
            while (true) {
                const { done, value } = await reader.read();
                if (done) break;
                if (value) {
                    chunks.push(value);
                    received += value.length;
                    if (total > 0) {
                        onProgress(Math.min(99, Math.round((received / total) * 100)), received, total);
                    } else {
                        onProgress(null, received, 0);
                    }
                }
            }
            return new Blob(chunks, {
                type: res.headers.get('Content-Type') || 'application/octet-stream',
            });
        }
        return res.blob();
    }).finally(() => clearTimeout(timer));
}

/**
 * Build PPTX with real server progress (NDJSON), then download via token.
 * onProgress(pct, label) — pct is overall 1–100.
 */
async function editorExportPptxStreaming(bodyOrForm, timeoutMs, onProgress) {
    const ctrl = new AbortController();
    const timer = setTimeout(() => ctrl.abort(), timeoutMs);
    const isForm = (typeof FormData !== 'undefined') && bodyOrForm instanceof FormData;
    let payload = bodyOrForm;
    if (isForm) {
        payload.set('progress_stream', '1');
    } else {
        payload = { ...bodyOrForm, progress_stream: true };
    }
    try {
        const res = await fetch('/api/certificate_export_pptx.php', {
            method: 'POST',
            headers: isForm ? undefined : { 'Content-Type': 'application/json' },
            body: isForm ? payload : JSON.stringify(payload),
            signal: ctrl.signal,
        });
        if (!res.ok) {
            let msg = 'Export failed';
            try {
                const err = await res.json();
                msg = err.error || msg;
            } catch (_) {}
            throw new Error(msg);
        }
        if (!res.body || typeof res.body.getReader !== 'function') {
            throw new Error('Streaming progress is not supported in this browser.');
        }

        const reader = res.body.getReader();
        const decoder = new TextDecoder();
        let buf = '';
        let token = '';
        let filename = 'certificate_template.pptx';

        while (true) {
            const { done, value } = await reader.read();
            if (done) break;
            buf += decoder.decode(value, { stream: true });
            let nl;
            while ((nl = buf.indexOf('\n')) >= 0) {
                const line = buf.slice(0, nl).trim();
                buf = buf.slice(nl + 1);
                if (!line) continue;
                let msg;
                try {
                    msg = JSON.parse(line);
                } catch (_) {
                    continue;
                }
                if (!msg || typeof msg !== 'object') continue;
                if (msg.ok === false) {
                    throw new Error(msg.error || 'Export failed');
                }
                if (typeof msg.pct === 'number' && typeof onProgress === 'function') {
                    // Build phase maps to 1–88; leave headroom for download.
                    const mapped = Math.min(88, Math.max(1, Math.round(msg.pct * 0.88)));
                    onProgress(mapped, msg.label || 'Building…');
                }
                if (msg.token) {
                    token = String(msg.token);
                    if (msg.filename) filename = String(msg.filename);
                }
            }
        }

        if (!token) {
            throw new Error('Export finished without a download token.');
        }

        if (typeof onProgress === 'function') {
            onProgress(90, 'Downloading…');
        }

        const blob = await editorFetchBlob('/api/certificate_export_download.php', {
            token,
            csrf_token: isForm
                ? (payload.get('csrf_token') || '')
                : (payload.csrf_token || ''),
        }, timeoutMs, (pct) => {
            if (typeof onProgress !== 'function') return;
            if (typeof pct === 'number' && !Number.isNaN(pct)) {
                onProgress(90 + Math.round(pct * 0.09), 'Downloading…');
            } else {
                onProgress(Math.min(98, (typeof exportProgressValue === 'number' ? exportProgressValue : 90) + 1), 'Downloading…');
            }
        });

        return { blob, filename };
    } finally {
        clearTimeout(timer);
    }
}

let editorIoBusy = false;
let exportBtnOriginalHtml = '';
let exportProgressTimer = null;
let exportProgressValue = 1;

function setExportBusy(busy, label) {
    const btn = document.getElementById('btnExportPptx');
    const btnSave = document.getElementById('btnSaveTemplate');
    const btnAnother = document.getElementById('btnSaveAnother');
    if (!btn) return;
    if (busy) {
        if (!exportBtnOriginalHtml) exportBtnOriginalHtml = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = label || 'Exporting…';
        if (btnSave) btnSave.disabled = true;
        if (btnAnother) btnAnother.disabled = true;
        return;
    }
    btn.disabled = false;
    btn.innerHTML = exportBtnOriginalHtml || 'Export PPTX';
    exportBtnOriginalHtml = '';
    if (btnSave) btnSave.disabled = false;
    if (btnAnother) btnAnother.disabled = false;
    try { syncSaveButtons(); } catch (_) {}
}

function showExportProgress(label) {
    const overlay = document.getElementById('exportProgressOverlay');
    if (!overlay) return;
    overlay.classList.remove('hidden');
    overlay.classList.add('flex');
    exportProgressValue = 1;
    setExportProgress(1, label || 'Preparing…');
}

function hideExportProgress() {
    if (exportProgressTimer) {
        clearInterval(exportProgressTimer);
        exportProgressTimer = null;
    }
    const overlay = document.getElementById('exportProgressOverlay');
    if (!overlay) return;
    overlay.classList.add('hidden');
    overlay.classList.remove('flex');
}

function setExportProgress(pct, label) {
    const n = Math.max(1, Math.min(100, Math.round(Number(pct) || 1)));
    exportProgressValue = Math.max(exportProgressValue, n);
    const bar = document.getElementById('exportProgressBar');
    const pctEl = document.getElementById('exportProgressPct');
    const labelEl = document.getElementById('exportProgressLabel');
    if (bar) bar.style.width = exportProgressValue + '%';
    if (pctEl) pctEl.textContent = exportProgressValue + '%';
    if (labelEl && label) labelEl.textContent = label;
    setExportBusy(true, exportProgressValue + '%');
}

function stopExportProgressTicker() {
    if (exportProgressTimer) {
        clearInterval(exportProgressTimer);
        exportProgressTimer = null;
    }
}

async function yieldToUi(label) {
    if (label) setExportBusy(true, label);
    await new Promise((r) => requestAnimationFrame(() => setTimeout(r, 0)));
}

function restoreSaveButtons(btn, btnAnother, originalBtnContent, originalAnother) {
    if (btn) {
        btn.innerHTML = originalBtnContent;
        btn.disabled = false;
        // Drop the "Saved!" green override, otherwise the button stays emerald.
        btn.classList.remove('!from-emerald-500', '!to-emerald-400');
    }
    if (btnAnother) {
        btnAnother.innerHTML = originalAnother;
        btnAnother.disabled = false;
    }
    try { syncSaveButtons(); } catch (_) {}
}

/**
 * Sidebar cards keep the canvas JSON they were rendered with, and switching cards
 * re-reads that copy. Refresh it after an in-place save so going away and back
 * shows the new design without a full page reload.
 */
function syncTemplateCardCache(templateId, { canvasState, thumb, title }) {
    const id = String(templateId || '');
    if (!id) return;
    let card = null;
    try {
        card = document.querySelector(`.custom-template-card[data-id="${CSS.escape(id)}"]`);
    } catch (_) {
        card = null;
    }
    if (!card) return;

    if (canvasState) {
        try {
            card.dataset.json = typeof canvasState === 'string' ? canvasState : JSON.stringify(canvasState);
        } catch (_) {}
    }
    if (title) {
        card.dataset.title = title;
        const titleEl = card.querySelector('.text-xs.font-semibold.text-zinc-300');
        if (titleEl) titleEl.textContent = title;
    }
    if (thumb) {
        const holder = card.querySelector('.h-32');
        if (holder) {
            let img = holder.querySelector('img');
            if (!img) {
                holder.innerHTML = '';
                img = document.createElement('img');
                img.className = 'w-full h-full object-cover';
                holder.appendChild(img);
            }
            img.src = thumb;
        }
    }
}

async function persistTemplate({ mode, name }) {
    if (editorIoBusy) {
        showNotification('Please wait — another save/export is still running.', 'error');
        return;
    }
        const btn = document.getElementById('btnSaveTemplate');
    const btnAnother = document.getElementById('btnSaveAnother');
    const btnExport = document.getElementById('btnExportPptx');
    const originalBtnContent = btn ? btn.innerHTML : '';
    const originalAnother = btnAnother ? btnAnother.innerHTML : '';
    editorIoBusy = true;
    if (btn) {
        btn.innerHTML = `<span id="btnSaveTemplateLabel">Saving…</span>`;
        btn.disabled = true;
    }
    if (btnAnother) btnAnother.disabled = true;
    if (btnExport) btnExport.disabled = true;

    let redirected = false;
    try {
        const { jsonState, thumb } = await buildCanvasSavePayload();
        const { templateScope, selectedSessionId } = resolveTemplateScope();
        let data;

        if (mode === 'update' && editingTemplate?.id) {
            data = await editorFetchJson('/api/certificate_template_update.php', {
                template_id: editingTemplate.id,
                template_scope: editingTemplate.scope || templateScope,
                title: name,
                canvas_state: jsonState,
                thumbnail_url: thumb || null,
                csrf_token: <?php echo json_encode($_SESSION['csrf_token'] ?? ''); ?>
            });
            if (!data.ok) throw new Error(data.error || 'Update failed');
            setEditingTemplate({
                id: editingTemplate.id,
                title: name,
                scope: editingTemplate.scope || templateScope,
                sessionId: editingTemplate.sessionId || selectedSessionId,
            });
            showNotification('Changes saved!');
            syncTemplateCardCache(data.template_id || editingTemplate.id, {
                canvasState: jsonState,
                thumb: thumb || '',
                title: name,
            });
            isCanvasDirty = false;
            if (btn) {
                btn.innerHTML = `<svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/></svg> <span id="btnSaveTemplateLabel">Saved!</span>`;
                btn.classList.add('!from-emerald-500', '!to-emerald-400');
            }
            // Stay in editor on in-place update — full reload was doubling wait on heavy designs.
            redirected = false;
            setTimeout(() => {
                restoreSaveButtons(btn, btnAnother, originalBtnContent, originalAnother);
            }, 900);
        } else {
            data = await editorFetchJson('/api/certificate_save.php', {
                    event_id: '<?php echo htmlspecialchars($eventId); ?>',
                    session_id: selectedSessionId,
                    template_scope: templateScope,
                template_id: (mode === 'another') ? '' : (editingTemplate?.id || ''),
                    title: name, 
                    canvas_state: jsonState, 
                thumbnail_url: thumb || null,
                    csrf_token: <?php echo json_encode($_SESSION['csrf_token'] ?? ''); ?>
            });
            if (!data.ok) throw new Error(data.error || 'Save failed');
            showNotification(mode === 'another' ? 'New template saved!' : 'Template saved successfully!');
            
            isCanvasDirty = false;
            if (btn) {
                btn.innerHTML = `<svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/></svg> <span id="btnSaveTemplateLabel">Saved!</span>`;
            btn.classList.add('!from-emerald-500', '!to-emerald-400');
            }
            
            redirected = true;
            setTimeout(() => {
                try {
                const nextUrl = new URL(window.location.href);
                    const tid = data?.template_id || editingTemplate?.id || '';
                    if (tid) nextUrl.searchParams.set('template_id', tid);
                if (data?.template_scope === 'session' && data?.session_id) {
                    nextUrl.searchParams.set('session_id', data.session_id);
                    } else if (!(editingTemplate?.scope === 'session' && editingTemplate?.sessionId)) {
                    nextUrl.searchParams.delete('session_id');
                }
                window.location.href = nextUrl.toString();
                } catch (_) {
                    redirected = false;
                    editorIoBusy = false;
                    restoreSaveButtons(btn, btnAnother, originalBtnContent, originalAnother);
                    if (btnExport) btnExport.disabled = false;
                }
            }, 700);
        }
        } catch (err) {
        const msg = (err && err.name === 'AbortError')
            ? 'Save timed out. Try again (large images can slow this down).'
            : (err.message || 'Save failed');
        showNotification(msg, 'error');
        restoreSaveButtons(btn, btnAnother, originalBtnContent, originalAnother);
    } finally {
        if (!redirected) {
            editorIoBusy = false;
            if (btnExport) btnExport.disabled = false;
        }
    }
}

document.getElementById('btnSaveTemplate').addEventListener('click', () => {
    if (!isCanvasDirty && canvas.getObjects().length === 0) {
        return showNotification('Canvas is empty!', 'error');
    }
    // Re-editing an existing template → save in place (no rename modal).
    if (editingTemplate?.id) {
        void persistTemplate({
            mode: 'update',
            name: editingTemplate.title || 'Certificate',
        });
        return;
    }
    // Fresh design → ask for a name.
    showSaveLayoutModal((name) => { void persistTemplate({ mode: 'create', name }); }, {
        mode: 'create',
        defaultName: '',
    });
});

document.getElementById('btnSaveAnother')?.addEventListener('click', () => {
    if (!isCanvasDirty && canvas.getObjects().length === 0) {
        return showNotification('Canvas is empty!', 'error');
    }
    const base = editingTemplate?.title || 'Certificate';
    showSaveLayoutModal((name) => { void persistTemplate({ mode: 'another', name }); }, {
        mode: 'another',
        defaultName: base + ' (copy)',
    });
});

document.getElementById('btnExportPptx')?.addEventListener('click', async () => {
    if (editorIoBusy) {
        showNotification('Please wait for the current save/export to finish.', 'error');
        return;
    }
    if (canvas.getObjects().length === 0 && !canvas.backgroundImage) {
        showNotification('Canvas is empty!', 'error');
        return;
    }
    editorIoBusy = true;
    showExportProgress('Preparing…');
    setExportBusy(true, '1%');
    try {
        await yieldToUi('Preparing…');
        setExportProgress(3, 'Reading open canvas…');
        canvas.discardActiveObject();
        canvas.renderAll();
        ensureFabricObjectIds();

        const csrf = <?= json_encode($_SESSION['csrf_token'] ?? '') ?>;
        const title = (editingTemplate?.title || <?= json_encode($eventName) ?> || 'Certificate').trim() || 'Certificate';
        // Optional id for PPTX metadata only — never re-fetch canvas from DB on export.
        const tid = String(editingTemplate?.id || <?= json_encode($templateId) ?> || '').trim();

        const onServerProgress = (pct, label) => {
            setExportProgress(pct, label || 'Working…');
        };

        // Export = what's on screen → PPTX to Downloads. Import later re-reads that file.
        // No Supabase canvas reload (that was the multi‑MB stall).
        setExportProgress(5, 'Compressing open design…');
        let canvasState = await buildLiveExportCanvasState();
        setExportProgress(12, 'Preparing files…');

        let backgroundBlob = null;
        const bgSrc = String(canvasState?.backgroundImage?.src || '');
        if (bgSrc.startsWith('data:image/')) {
            backgroundBlob = dataUrlToBlob(bgSrc);
            delete canvasState.backgroundImage;
            delete canvasState.background;
            delete canvasState.backgroundColor;
        }

        const mediaFiles = {};
        let mediaN = 0;
        const hoistSrc = (obj) => {
            if (!obj || typeof obj !== 'object') return;
            const src = String(obj.src || '');
            if (!src.startsWith('data:image/')) return;
            const blobPart = dataUrlToBlob(src);
            if (!blobPart) return;
            const key = 'media_' + (mediaN++);
            mediaFiles[key] = blobPart;
            obj.src = '__upload:' + key;
        };
        if (Array.isArray(canvasState.objects)) {
            canvasState.objects.forEach(hoistSrc);
        }

        const fd = new FormData();
        if (tid) fd.append('template_id', tid);
        fd.append('title', title);
        fd.append('canvas_width', String(canvas.getWidth()));
        fd.append('canvas_height', String(canvas.getHeight()));
        fd.append('csrf_token', csrf);
        fd.append('canvas_state', JSON.stringify(canvasState));
        if (backgroundBlob) {
            fd.append('background', backgroundBlob, 'background.jpg');
        }
        Object.keys(mediaFiles).forEach((key) => {
            const part = mediaFiles[key];
            const ext = (part.type || '').includes('png') ? 'png' : 'jpg';
            fd.append(key, part, key + '.' + ext);
        });

        setExportProgress(15, 'Building PPTX…');
        const { blob, filename } = await editorExportPptxStreaming(fd, 180000, onServerProgress);

        setExportProgress(99, 'Saving to your folder…');
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = filename || 'certificate_template.pptx';
        document.body.appendChild(a);
        a.click();
        a.remove();
        URL.revokeObjectURL(url);
        setExportProgress(100, 'Done');
        await new Promise((r) => setTimeout(r, 250));
        showNotification('PPTX saved to your Downloads — use Import / Link Cert to attach it to a seminar.');
    } catch (err) {
        stopExportProgressTicker();
        const msg = (err && err.name === 'AbortError')
            ? 'Export timed out. Try again (large images can slow this down).'
            : (err.message || 'Export failed');
        showNotification(msg, 'error');
    } finally {
        editorIoBusy = false;
        hideExportProgress();
        setExportBusy(false);
    }
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
    // Design Library (no scope dropdown) must keep library cards visible.
    if (!templateScopeSelect) {
        return {
            scope: <?= json_encode($initialTemplateScope === 'library' ? 'library' : ($eventId !== '' ? 'event' : 'library')) ?>,
            sessionId: '',
            label: 'Design Library',
        };
    }
    const defaultScope = templateScopeSelect.options?.[0]?.value || 'event';
    const scopeValue = templateScopeSelect.value || defaultScope;
    if (scopeValue.startsWith('session:')) {
        const sessionId = scopeValue.split(':')[1] || '';
        const sessionLabel = templateScopeSelect.selectedOptions?.[0]?.textContent?.trim() || 'Seminar';
        return { scope: 'session', sessionId, label: sessionLabel };
    }
    if (scopeValue === 'library') {
        return { scope: 'library', sessionId: '', label: 'Design Library' };
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
        let visible = false;
        if (scope === 'library') {
            // Library workspace: show all teacher designs (library + event-linked).
            visible = cardScope === 'library' || cardScope === 'event';
        } else if (scope === 'session') {
            visible = cardScope === 'session' && cardSessionId === sessionId;
        } else {
            visible = cardScope === 'event';
        }
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
        if (scope === 'session') {
            templateScopeHint.textContent = `You are editing templates for ${label}.`;
        } else if (scope === 'library') {
            templateScopeHint.textContent = 'Save and browse your certificate designs.';
        } else {
            templateScopeHint.textContent = 'You are editing templates for the whole event.';
        }
    }

    if (activeHidden && !document.querySelector('.custom-template-card.active-template')) {
        setActiveCard(null);
    }
}

// --- Load Preset Template --- (FIX: set backgroundColor AFTER clear, no intermediate renderAll)
function doLoadPreset(type, cardEl) {
    isProgrammaticChange = true;
    canvas.clear();
    canvas.backgroundImage = null;

    if (type === 'blank') {
        canvas.backgroundColor = '#ffffff';
    } else if (type === 'classic-green') {
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
    pendingRegistrarCodes = [];
    if (certificateCodeInput) certificateCodeInput.value = '';
    certificateCodeInputPanel?.classList.add('hidden');
    setEditingTemplate(null);
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
            if (Array.isArray(parsed.pending_registrar_codes) && parsed.pending_registrar_codes.length) {
                pendingRegistrarCodes = parsed.pending_registrar_codes.map((c) => String(c || '').trim()).filter(Boolean);
            } else {
                const existing = findCertificateCodeObject();
                const sample = String(existing?.text || '').trim();
                pendingRegistrarCodes = (sample && !/\{\{\s*certificate_code\s*\}\}/i.test(sample)) ? [sample] : [];
            }
            if (certificateCodeInput) {
                certificateCodeInput.value = pendingRegistrarCodes.join('\n');
            }
            certificateCodeInputPanel?.classList.add('hidden');
            setEditingTemplate({
                id: cardEl.dataset.id || '',
                title: cardEl.dataset.title || '',
                scope: cardEl.dataset.scope || 'library',
                sessionId: cardEl.dataset.sessionId || '',
            });
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
