<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/session.php';
session_bootstrap();

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/supabase.php';
require_once __DIR__ . '/includes/layout.php';

$user = require_role(['teacher']);
$userId = trim((string) ($user['id'] ?? ''));
csrf_ensure_token();

$headers = [
    'Accept: application/json',
    'apikey: ' . SUPABASE_KEY,
    'Authorization: Bearer ' . SUPABASE_KEY,
];

$templatesById = [];
$allowedEventIds = [];
$eventTitles = [];

if ($userId !== '') {
    // Events this teacher owns or is assigned to (for legacy templates with null created_by).
    $ownedUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/events'
        . '?select=id,title&created_by=eq.' . rawurlencode($userId);
    $ownedRes = supabase_request('GET', $ownedUrl, $headers);
    $ownedRows = $ownedRes['ok'] ? json_decode((string) $ownedRes['body'], true) : [];
    if (is_array($ownedRows)) {
        foreach ($ownedRows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $eid = trim((string) ($row['id'] ?? ''));
            if ($eid === '') {
                continue;
            }
            $allowedEventIds[$eid] = true;
            $eventTitles[$eid] = trim((string) ($row['title'] ?? ''));
        }
    }
    $assignUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/event_teacher_assignments'
        . '?select=event_id,events(title)&teacher_id=eq.' . rawurlencode($userId);
    $assignRes = supabase_request('GET', $assignUrl, $headers);
    $assignRows = $assignRes['ok'] ? json_decode((string) $assignRes['body'], true) : [];
    if (is_array($assignRows)) {
        foreach ($assignRows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $eid = trim((string) ($row['event_id'] ?? ''));
            if ($eid === '') {
                continue;
            }
            $allowedEventIds[$eid] = true;
            $nested = $row['events'] ?? null;
            if (is_array($nested)) {
                $eventTitles[$eid] = trim((string) ($nested['title'] ?? $eventTitles[$eid] ?? ''));
            }
        }
    }

    $mergeRows = static function (array $rows) use (&$templatesById): void {
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            // Design library only — event-linked Import copies must not appear here.
            if (trim((string) ($row['event_id'] ?? '')) !== '') {
                continue;
            }
            $tid = trim((string) ($row['id'] ?? ''));
            if ($tid === '' || isset($templatesById[$tid])) {
                continue;
            }
            $templatesById[$tid] = $row;
        }
    };

    // Standalone library designs only (event_id IS NULL). Import/Link clones stay on the event.
    $byCreatorUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/certificate_templates'
        . '?select=id,title,event_id,created_at,thumbnail_url,created_by'
        . '&created_by=eq.' . rawurlencode($userId)
        . '&event_id=is.null'
        . '&order=created_at.desc';
    $byCreatorRes = supabase_request('GET', $byCreatorUrl, $headers);
    if ($byCreatorRes['ok']) {
        $rows = json_decode((string) $byCreatorRes['body'], true);
        if (is_array($rows)) {
            $mergeRows($rows);
        }
    } else {
        // Fallback if event_id=is.null filter unsupported — filter in PHP.
        $fallbackUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/certificate_templates'
            . '?select=id,title,event_id,created_at,thumbnail_url,created_by'
            . '&created_by=eq.' . rawurlencode($userId)
            . '&order=created_at.desc';
        $fallbackRes = supabase_request('GET', $fallbackUrl, $headers);
        if ($fallbackRes['ok']) {
            $rows = json_decode((string) $fallbackRes['body'], true);
            if (is_array($rows)) {
                $mergeRows($rows);
            }
        } else {
            echo "<div class='p-4 bg-red-100 text-red-800 m-8'>Error fetching templates: "
                . htmlspecialchars((string) ($byCreatorRes['body'] ?? ''))
                . '</div>';
        }
    }

    // Orphan standalone designs (created_by null, not tied to an event) — claim for this teacher.
    $orphanUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/certificate_templates'
        . '?select=id,title,event_id,created_at,thumbnail_url,created_by'
        . '&created_by=is.null'
        . '&event_id=is.null'
        . '&order=created_at.desc'
        . '&limit=100';
    $orphanRes = supabase_request('GET', $orphanUrl, $headers);
    if ($orphanRes['ok']) {
        $rows = json_decode((string) $orphanRes['body'], true);
        if (is_array($rows)) {
            $mergeRows($rows);
        }
    } else {
        $orphanFallback = rtrim(SUPABASE_URL, '/') . '/rest/v1/certificate_templates'
            . '?select=id,title,event_id,created_at,thumbnail_url,created_by'
            . '&created_by=is.null'
            . '&order=created_at.desc'
            . '&limit=100';
        $orphanFbRes = supabase_request('GET', $orphanFallback, $headers);
        if ($orphanFbRes['ok']) {
            $rows = json_decode((string) $orphanFbRes['body'], true);
            if (is_array($rows)) {
                $mergeRows($rows);
            }
        }
    }

    // Backfill created_by on orphan library rows so they keep showing under this teacher.
    $patchHeaders = [
        'Accept: application/json',
        'Content-Type: application/json',
        'apikey: ' . SUPABASE_KEY,
        'Authorization: Bearer ' . SUPABASE_KEY,
        'Prefer: return=minimal',
    ];
    foreach ($templatesById as $tid => $row) {
        $cb = trim((string) ($row['created_by'] ?? ''));
        if ($cb !== '') {
            continue;
        }
        if (trim((string) ($row['event_id'] ?? '')) !== '') {
            continue;
        }
        $patchUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/certificate_templates?id=eq.' . rawurlencode((string) $tid);
        supabase_request('PATCH', $patchUrl, $patchHeaders, json_encode(['created_by' => $userId]));
        $templatesById[$tid]['created_by'] = $userId;
    }
}

$templates = array_values($templatesById);
usort($templates, static function (array $a, array $b): int {
    return strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? ''));
});

// Collapse accidental duplicates (same teacher + same title): keep newest, remove older rows.
$deduped = [];
$seenTitleKeys = [];
$duplicateIdsToDelete = [];
foreach ($templates as $tpl) {
    if (!is_array($tpl)) {
        continue;
    }
    $tid = trim((string) ($tpl['id'] ?? ''));
    $titleKey = strtolower(preg_replace('/\s+/', ' ', trim((string) ($tpl['title'] ?? ''))) ?? '');
    if ($titleKey === '') {
        $titleKey = '__untitled__:' . $tid;
    }
    if (isset($seenTitleKeys[$titleKey])) {
        if ($tid !== '') {
            $duplicateIdsToDelete[] = $tid;
        }
        continue;
    }
    $seenTitleKeys[$titleKey] = true;
    $deduped[] = $tpl;
}
$templates = $deduped;

if ($duplicateIdsToDelete !== [] && $userId !== '') {
    $delHeaders = [
        'Accept: application/json',
        'apikey: ' . SUPABASE_KEY,
        'Authorization: Bearer ' . SUPABASE_KEY,
        'Prefer: return=minimal',
    ];
    foreach ($duplicateIdsToDelete as $dupId) {
        $dupId = trim((string) $dupId);
        if ($dupId === '') {
            continue;
        }
        $delUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/certificate_templates'
            . '?id=eq.' . rawurlencode($dupId)
            . '&event_id=is.null'
            . '&created_by=eq.' . rawurlencode($userId);
        $delRes = supabase_request('DELETE', $delUrl, $delHeaders);
        if (!empty($delRes['ok'])) {
            continue;
        }
        // Orphans claimed visually but not yet owned.
        $delOrphanUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/certificate_templates'
            . '?id=eq.' . rawurlencode($dupId)
            . '&event_id=is.null'
            . '&created_by=is.null';
        supabase_request('DELETE', $delOrphanUrl, $delHeaders);
    }
}

render_header('Certificates Library', $user);
?>

<div class="mb-8 flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
  <div class="min-w-0">
    <h2 class="text-xl font-bold text-zinc-900 mb-1">Certificate Templates</h2>
    <p class="text-zinc-600 text-sm">Design standalone certificate layouts, then export as PPTX for the registrar. Link coded files from an event later.</p>
  </div>
  <a href="/certificate_editor" class="inline-flex items-center justify-center gap-2 rounded-xl bg-orange-600 text-white px-5 py-2.5 text-[13px] font-bold shadow-sm hover:bg-orange-700 transition-colors border border-orange-600 shrink-0 self-end sm:self-start">
    <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/></svg>
    Create New Template
  </a>
</div>

<div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-6 pb-12">
    <?php foreach ($templates as $t): ?>
        <?php
           if (!is_array($t)) {
               continue;
           }
           $tName = htmlspecialchars((string) ($t['title'] ?? 'Untitled Template'));
           $tid = (string) ($t['id'] ?? '');
           $subtitle = 'Design library';
           $editHref = '/certificate_editor?template_id=' . urlencode($tid);
        ?>
        <div class="group relative bg-white rounded-2xl border border-zinc-200 overflow-hidden shadow-sm hover:shadow-md hover:-translate-y-1 transition-all cursor-pointer" onclick="window.location.href='<?= htmlspecialchars($editHref) ?>'">
            <div class="aspect-[4/3] bg-zinc-100 flex items-center justify-center relative border-b border-zinc-200 overflow-hidden">
                 <?php if (!empty($t['thumbnail_url'])): ?>
                    <img src="<?= htmlspecialchars((string) $t['thumbnail_url']) ?>" class="w-full h-full object-cover group-hover:scale-105 transition-transform duration-500" alt="">
                 <?php else: ?>
                    <div class="w-12 h-12 rounded-full bg-white border border-zinc-200 flex items-center justify-center text-indigo-500 shadow-sm relative z-10">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z"/></svg>
                    </div>
                    <div class="absolute bottom-2 left-0 right-0 text-center text-[9px] font-black tracking-widest text-zinc-300 uppercase">No Preview</div>
                 <?php endif; ?>
            </div>
            <div class="p-4 bg-white relative">
                 <span class="inline-flex py-0.5 px-2 rounded-full bg-zinc-100 text-[10px] font-bold text-zinc-600 uppercase tracking-widest leading-none mb-2 border border-zinc-200">Design</span>
                 <h3 class="font-bold text-zinc-900 text-[15px] tracking-tight truncate leading-tight mb-1"><?= $tName ?></h3>
                 <p class="text-xs text-zinc-500 font-medium truncate"><?= htmlspecialchars($subtitle) ?></p>

                 <div class="absolute bottom-4 right-3 group/menu">
                    <button type="button" class="p-1.5 rounded-lg text-zinc-400 hover:text-zinc-600 hover:bg-zinc-100 transition-colors" onclick="event.stopPropagation()">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6.75a.75.75 0 110-1.5.75.75 0 010 1.5zM12 12.75a.75.75 0 110-1.5.75.75 0 010 1.5zM12 18.75a.75.75 0 110-1.5.75.75 0 010 1.5z"/></svg>
                    </button>
                    <div class="absolute right-0 bottom-full mb-1 w-36 bg-white rounded-xl shadow-[0_4px_20px_-4px_rgba(0,0,0,0.15)] border border-zinc-200 opacity-0 invisible group-hover/menu:opacity-100 group-hover/menu:visible transition-all z-20 py-1" onclick="event.stopPropagation()">
                        <button type="button" onclick="window.location.href='<?= htmlspecialchars($editHref) ?>'" class="w-full text-left px-4 py-2 text-xs font-bold text-zinc-700 hover:bg-zinc-50 hover:text-orange-600">Edit Layout</button>
                        <button type="button" onclick="exportTemplatePptx('<?= htmlspecialchars($tid) ?>')" class="w-full text-left px-4 py-2 text-xs font-bold text-zinc-700 hover:bg-zinc-50 hover:text-orange-600">Export PPTX</button>
                        <button type="button" onclick="deleteTemplate('<?= htmlspecialchars($tid) ?>', 'library', this.closest('.group'))" class="w-full text-left px-4 py-2 text-xs font-bold text-red-600 hover:bg-red-50">Delete</button>
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
           <h3 class="text-zinc-800 font-bold mb-1">No Saved Templates</h3>
           <p class="text-zinc-500 text-sm max-w-sm">Create a design, export PPTX for the registrar, then Import Cert on the event page after codes are added.</p>
        </div>
    <?php endif; ?>
</div>

<script>
window.CSRF_TOKEN = <?= json_encode($_SESSION['csrf_token'] ?? '') ?>;

async function exportTemplatePptx(templateId) {
    try {
        const res = await fetch('/api/certificate_export_pptx.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ template_id: templateId, csrf_token: window.CSRF_TOKEN })
        });
        if (!res.ok) {
            let msg = 'Export failed';
            try { const err = await res.json(); msg = err.error || msg; } catch (_) {}
            alert(msg);
            return;
        }
        const blob = await res.blob();
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = 'certificate_template.pptx';
        document.body.appendChild(a);
        a.click();
        a.remove();
        URL.revokeObjectURL(url);
    } catch (e) {
        alert('Network error while exporting.');
    }
}

async function deleteTemplate(id, scope, el) {
    if (!confirm('Are you sure you want to permanently delete this template?')) return;
    try {
        const res = await fetch('/api/certificate_delete.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ template_id: id, template_scope: scope || 'library', csrf_token: window.CSRF_TOKEN })
        });
        if (res.ok) {
            const data = await res.json();
            if (data.ok) {
                el.remove();
                if (document.querySelectorAll('.group.relative.bg-white').length === 0) {
                    window.location.reload();
                }
            } else {
                alert(data.error || 'Failed to delete template');
            }
        } else {
            const txt = await res.text();
            alert('Server Error (' + res.status + '): ' + txt.substring(0, 80));
        }
    } catch (err) {
        console.error(err);
        alert('Network error while deleting.');
    }
}
</script>

<?php render_footer(); ?>
