<?php
declare(strict_types=1);

/**
 * Preview-only registrar PPTX scan: extract codes + layout and suggest a matching
 * Library template. Does NOT insert pool codes and does NOT mutate Library canvases.
 * Persist happens on Save & link via event_certificate_import.php.
 */

require_once __DIR__ . '/../includes/session.php';
session_bootstrap();

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/supabase.php';
require_once __DIR__ . '/../includes/json.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/certificate_code_extract.php';
require_once __DIR__ . '/../includes/certificate_code_pool.php';
require_once __DIR__ . '/../includes/api_rate_limit.php';

$user = require_role(['teacher']);
$userId = trim((string) ($user['id'] ?? ''));

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    json_response(['ok' => false, 'error' => 'POST required'], 405);
}

$eventId = trim((string) ($_POST['event_id'] ?? ''));
$sessionId = trim((string) ($_POST['session_id'] ?? ''));
$csrf = $_POST['csrf_token'] ?? null;
csrf_validate(is_string($csrf) ? $csrf : null);

if ($eventId === '') {
    json_response(['ok' => false, 'error' => 'event_id required'], 400);
}
if (!certificate_pool_teacher_may_manage($eventId, $userId)) {
    json_response(['ok' => false, 'error' => 'Forbidden'], 403);
}

$clientIp = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
if (!api_rate_limit_allow('cert_scan:' . $userId . ':' . $clientIp, 40, 3600)) {
    json_response(['ok' => false, 'error' => 'Too many scans. Try again later.'], 429);
}

if (!isset($_FILES['file']) || !is_array($_FILES['file'])) {
    json_response(['ok' => false, 'error' => 'PPTX file required'], 400);
}
$file = $_FILES['file'];
if ((int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    json_response(['ok' => false, 'error' => 'Upload failed.'], 400);
}
$size = (int) ($file['size'] ?? 0);
if ($size <= 0 || $size > 12 * 1024 * 1024) {
    json_response(['ok' => false, 'error' => 'File must be between 1 byte and 12 MB.'], 400);
}

$tmp = (string) ($file['tmp_name'] ?? '');
$filename = (string) ($file['name'] ?? 'upload.pptx');
$mime = (string) ($file['type'] ?? '');
$binary = (string) file_get_contents($tmp);
if ($binary === '') {
    json_response(['ok' => false, 'error' => 'Empty upload.'], 400);
}

try {
    $extracted = certificate_extract_codes_from_upload($binary, $filename, $mime);
} catch (Throwable $e) {
    json_response(['ok' => false, 'error' => $e->getMessage()], 400);
}

$codes = $extracted['codes'] ?? [];
if (!is_array($codes)) {
    $codes = [];
}
$layout = $extracted['layout'] ?? ['items' => [], 'matched' => 0];
if (!is_array($layout)) {
    $layout = ['items' => [], 'matched' => 0];
}

$codeStrings = [];
foreach ($codes as $row) {
    if (is_array($row)) {
        $c = trim((string) ($row['code'] ?? ''));
    } else {
        $c = trim((string) $row);
    }
    if ($c !== '') {
        $codeStrings[] = $c;
    }
}
$codeStrings = certificate_pool_normalize_codes($codeStrings);
$codeStrings = certificate_pool_collapse_to_seed($codeStrings);
$sampleCode = $codeStrings[0] ?? '';

// Stop here when the registrar seed is already in use — importing it would either
// collide on unique(code) or print a code that differs from the issued one.
if ($sampleCode !== '') {
    $usage = certificate_pool_code_usage(
        $sampleCode,
        $eventId,
        $sessionId !== '' ? $sessionId : null
    );
    if (($usage['taken'] ?? false) === true) {
        json_response([
            'ok' => false,
            'error' => certificate_pool_code_conflict_message($usage, 'scanned code'),
            'code_conflict' => $usage,
        ], 409);
    }
}

$matchedTemplateId = trim((string) ($extracted['template_id'] ?? ''));
$matchSource = $matchedTemplateId !== '' ? 'pptx_meta' : '';
if ($matchedTemplateId === '') {
    $matchedTemplateId = certificate_match_template_by_layout($userId, $layout);
    if ($matchedTemplateId !== '') {
        $matchSource = 'object_ids';
    }
}

$matchedTitle = '';
$canvasState = null;
$layoutUpdated = 0;
$layoutWarnings = [];

if ($matchedTemplateId !== '') {
    $headers = [
        'Accept: application/json',
        'apikey: ' . SUPABASE_KEY,
        'Authorization: Bearer ' . SUPABASE_KEY,
    ];
    $url = rtrim(SUPABASE_URL, '/') . '/rest/v1/certificate_templates'
        . '?select=id,title,created_by,canvas_state'
        . '&id=eq.' . rawurlencode($matchedTemplateId)
        . '&limit=1';
    $res = supabase_request('GET', $url, $headers);
    $rows = json_decode((string) ($res['body'] ?? ''), true);
    $row = is_array($rows) && isset($rows[0]) && is_array($rows[0]) ? $rows[0] : null;
    if (!$row || (string) ($row['created_by'] ?? '') !== $userId) {
        $matchedTemplateId = '';
        $matchSource = '';
    } else {
        $matchedTitle = (string) ($row['title'] ?? '');
        $cs = $row['canvas_state'] ?? null;
        if (is_string($cs)) {
            $cs = json_decode($cs, true);
        }
        if (is_array($cs)) {
            $canvasState = $cs;
            // Preview must show PPT positions/sizes — apply layout in-memory (do not PATCH DB).
            if (is_array($layout) && !empty($layout['items']) && is_array($layout['items'])) {
                require_once __DIR__ . '/../includes/certificate_pptx_sync.php';
                $sync = certificate_pptx_sync_apply_layout($canvasState, $layout);
                if (isset($sync['canvas_state']) && is_array($sync['canvas_state'])) {
                    $canvasState = $sync['canvas_state'];
                }
                $layoutUpdated = (int) ($sync['updated'] ?? 0);
                $layoutWarnings = is_array($sync['warnings'] ?? null) ? $sync['warnings'] : [];
                // Stamp sample code onto certificate_code for preview.
                if ($sampleCode !== '' && isset($canvasState['objects']) && is_array($canvasState['objects'])) {
                    $foundCode = false;
                    foreach ($canvasState['objects'] as $i => $obj) {
                        if (!is_array($obj)) {
                            continue;
                        }
                        $oid = trim((string) ($obj['id'] ?? ''));
                        $oname = strtolower(trim((string) ($obj['name'] ?? '')));
                        if ($oid === 'certificate_code' || $oname === 'certificate code') {
                            $canvasState['objects'][$i]['text'] = $sampleCode;
                            $canvasState['objects'][$i]['id'] = 'certificate_code';
                            $canvasState['objects'][$i]['name'] = 'Certificate Code';
                            $foundCode = true;
                            break;
                        }
                    }
                    if (!$foundCode) {
                        // Prefer PPT code box from layout.
                        $codeItem = null;
                        foreach ($layout['items'] as $it) {
                            if (!is_array($it)) {
                                continue;
                            }
                            if ((string) ($it['kind'] ?? '') === 'certificate_code' || (string) ($it['id'] ?? '') === 'certificate_code') {
                                $codeItem = $it;
                                break;
                            }
                        }
                        $cw = (float) ($canvasState['width'] ?? 1123);
                        $ch = (float) ($canvasState['height'] ?? 794);
                        $canvasState['objects'][] = certificate_pptx_sync_new_code_object(
                            $codeItem ? (float) ($codeItem['left'] ?? $cw * 0.05) : $cw * 0.05,
                            $codeItem ? (float) ($codeItem['top'] ?? $ch * 0.88) : $ch * 0.88,
                            $codeItem ? max(40.0, (float) ($codeItem['width'] ?? 220)) : min(320.0, $cw * 0.35),
                            $codeItem ? max(14.0, (float) ($codeItem['height'] ?? 22)) : 22.0,
                            $sampleCode,
                            $codeItem ? [
                                'fontSize' => isset($codeItem['fontSize']) && is_numeric($codeItem['fontSize']) ? (float) $codeItem['fontSize'] : null,
                                'textAlign' => isset($codeItem['textAlign']) && is_string($codeItem['textAlign']) ? $codeItem['textAlign'] : 'left',
                                'fontWeight' => isset($codeItem['fontWeight']) && is_string($codeItem['fontWeight']) ? $codeItem['fontWeight'] : 'bold',
                                'fontFamily' => isset($codeItem['fontFamily']) && is_string($codeItem['fontFamily']) ? $codeItem['fontFamily'] : 'Arial',
                            ] : null
                        );
                    }
                }
            }
        }
    }
}

// Preview only — codes enter the pool on Save & link; Library canvas stays untouched.
json_response([
    'ok' => true,
    'source_kind' => (string) ($extracted['kind'] ?? 'pptx'),
    'filename' => $filename,
    'session_id' => $sessionId !== '' ? $sessionId : null,
    'scanned' => count($codeStrings),
    'codes' => array_values(array_map(static fn (string $c): array => ['code' => $c], $codeStrings)),
    'codes_inserted' => 0,
    'codes_skipped' => [],
    'sample_code' => $sampleCode !== '' ? $sampleCode : null,
    'layout' => $layout,
    'template_id' => $matchedTemplateId !== '' ? $matchedTemplateId : null,
    'template_title' => $matchedTitle !== '' ? $matchedTitle : null,
    'template_match' => $matchSource !== '' ? $matchSource : null,
    'linked_template' => false,
    'layout_synced' => $layoutUpdated > 0,
    'layout_updated' => $layoutUpdated,
    'layout_warnings' => $layoutWarnings,
    // Client must not re-apply layout on this canvas_state (prevents header pile-up).
    'layout_applied_preview' => is_array($canvasState) && is_array($layout) && !empty($layout['items']),
    'canvas_state' => $canvasState,
    'preview_only' => true,
    'message' => 'Scan ready. Click Save & link to attach the template and add codes to the FIFO pool.',
]);
