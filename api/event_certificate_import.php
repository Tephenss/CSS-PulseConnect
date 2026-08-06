<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/session.php';
session_bootstrap();

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/supabase.php';
require_once __DIR__ . '/../includes/json.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/certificate_code_extract.php';
require_once __DIR__ . '/../includes/certificate_code_pool.php';
require_once __DIR__ . '/../includes/certificate_pptx_sync.php';
require_once __DIR__ . '/../includes/api_rate_limit.php';

$user = require_role(['teacher']);
$userId = trim((string) ($user['id'] ?? ''));

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    json_response(['ok' => false, 'error' => 'POST required'], 405);
}

$contentType = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? ''));
$isJson = str_contains($contentType, 'application/json');

$eventId = '';
$sessionId = '';
$csrf = null;
$templateId = '';
$manualCodes = [];
$layout = null;
$hasFile = isset($_FILES['file']) && is_array($_FILES['file'])
    && (int) ($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;

if ($isJson) {
    $input = require_post_json();
    $eventId = trim((string) ($input['event_id'] ?? ''));
    $sessionId = trim((string) ($input['session_id'] ?? ''));
    $csrf = $input['csrf_token'] ?? null;
    $templateId = trim((string) ($input['template_id'] ?? ''));
    if (isset($input['layout']) && is_array($input['layout'])) {
        $layout = $input['layout'];
    }
    if (isset($input['codes'])) {
        $manualCodes = is_array($input['codes']) ? $input['codes'] : [];
    } elseif (isset($input['codes_text'])) {
        $manualCodes = (string) $input['codes_text'];
    }
} else {
    $eventId = trim((string) ($_POST['event_id'] ?? ''));
    $sessionId = trim((string) ($_POST['session_id'] ?? ''));
    $csrf = $_POST['csrf_token'] ?? null;
    $templateId = trim((string) ($_POST['template_id'] ?? ''));
    if (isset($_POST['codes_text']) && trim((string) $_POST['codes_text']) !== '') {
        $manualCodes = (string) $_POST['codes_text'];
    }
    if (isset($_POST['layout_json']) && is_string($_POST['layout_json']) && trim($_POST['layout_json']) !== '') {
        $decoded = json_decode((string) $_POST['layout_json'], true);
        if (is_array($decoded)) {
            $layout = $decoded;
        }
    }
}

csrf_validate(is_string($csrf) ? $csrf : null);

if ($eventId === '') {
    json_response(['ok' => false, 'error' => 'event_id required'], 400);
}
if (!certificate_pool_teacher_may_manage($eventId, $userId)) {
    json_response(['ok' => false, 'error' => 'You do not have permission to import codes for this event.'], 403);
}

$clientIp = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
if (!api_rate_limit_allow('cert_import:' . $userId . ':' . $clientIp, 20, 3600)) {
    json_response(['ok' => false, 'error' => 'Too many imports. Try again later.'], 429);
}

$kind = 'manual';
$filename = 'manual-entry';
$codes = [];
$syncResult = null;

/**
 * Reject a code that another event/seminar already owns, before anything is written.
 * @param list<string> $rawCodes
 */
function certificate_import_guard_codes(array $rawCodes, string $eventId, string $sessionId): void
{
    $normalized = certificate_pool_collapse_to_seed(certificate_pool_normalize_codes($rawCodes));
    foreach ($normalized as $code) {
        $usage = certificate_pool_code_usage($code, $eventId, $sessionId !== '' ? $sessionId : null);
        if (($usage['taken'] ?? false) === true) {
            json_response([
                'ok' => false,
                'error' => certificate_pool_code_conflict_message($usage),
                'code_conflict' => $usage,
            ], 409);
        }
    }
}

/**
 * Same guard for the code printed on a template that is about to be linked.
 */
function certificate_import_guard_template_seed(string $templateId, string $eventId, string $sessionId): void
{
    $templateId = trim($templateId);
    if ($templateId === '') {
        return;
    }
    $headers = [
        'Accept: application/json',
        'apikey: ' . SUPABASE_KEY,
        'Authorization: Bearer ' . SUPABASE_KEY,
    ];
    $canvas = null;
    foreach (['certificate_templates', 'event_session_certificate_templates'] as $table) {
        $res = supabase_request(
            'GET',
            rtrim(SUPABASE_URL, '/') . '/rest/v1/' . $table
                . '?select=canvas_state&id=eq.' . rawurlencode($templateId) . '&limit=1',
            $headers
        );
        $rows = json_decode((string) ($res['body'] ?? ''), true);
        if (is_array($rows) && isset($rows[0]['canvas_state'])) {
            $canvas = $rows[0]['canvas_state'];
            break;
        }
    }
    if ($canvas === null) {
        return;
    }
    $seed = certificate_pool_extract_seed_from_canvas($canvas);
    if (!is_string($seed) || $seed === '') {
        return;
    }
    certificate_import_guard_codes([$seed], $eventId, $sessionId);
}

if ($hasFile) {
    $file = $_FILES['file'];
    if ((int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        json_response(['ok' => false, 'error' => 'Upload failed.'], 400);
    }
    $size = (int) ($file['size'] ?? 0);
    if ($size <= 0 || $size > 12 * 1024 * 1024) {
        json_response(['ok' => false, 'error' => 'File must be between 1 byte and 12 MB.'], 400);
    }
    $tmp = (string) ($file['tmp_name'] ?? '');
    $filename = (string) ($file['name'] ?? 'upload.bin');
    $mime = (string) ($file['type'] ?? '');
    $binary = (string) file_get_contents($tmp);
    if ($binary === '') {
        json_response(['ok' => false, 'error' => 'Empty upload.'], 400);
    }
    if ($templateId === '') {
        json_response([
            'ok' => false,
            'error' => 'Select a saved template first. PPTX import syncs layout into the system Canva template.',
        ], 400);
    }
    try {
        $extracted = certificate_extract_codes_from_upload($binary, $filename, $mime);
    } catch (Throwable $e) {
        json_response(['ok' => false, 'error' => $e->getMessage()], 400);
    }
    $kind = (string) ($extracted['kind'] ?? 'pptx');
    $codes = $extracted['codes'] ?? [];
    if (!is_array($codes) || $codes === []) {
        json_response([
            'ok' => false,
            'error' => 'No certificate codes found in this PPTX. Ask the registrar to put codes as editable text (e.g. LU-AA-FO-180-01).',
        ], 400);
    }
    if ($layout === null && isset($extracted['layout']) && is_array($extracted['layout'])) {
        $layout = $extracted['layout'];
    }
} elseif ($manualCodes !== [] && $manualCodes !== '') {
    $kind = (is_array($layout) && !empty($layout['items'])) ? 'pptx' : 'manual';
    $filename = $kind === 'pptx' ? 'scanned-pptx' : 'manual-entry';
    $codes = certificate_pool_normalize_codes($manualCodes);
    $codes = array_map(static fn (string $c): array => ['code' => $c], $codes);
    // Layout-only sync from prior scan (JSON) still needs a template.
    if ($layout !== null && $templateId === '') {
        json_response([
            'ok' => false,
            'error' => 'Select a saved template to apply PPTX layout changes.',
        ], 400);
    }
} elseif ($templateId !== '') {
    // Template-only link (no codes this round).
    certificate_import_guard_template_seed($templateId, $eventId, $sessionId);
    $sessionLinked = null;
    $eventScopedTemplateId = $templateId;
    if ($sessionId !== '') {
        $sessionLinked = certificate_pool_link_template_to_session($templateId, $sessionId, $eventId, $userId);
        if (!$sessionLinked) {
            json_response(['ok' => false, 'error' => 'Unable to link template to this seminar.'], 400);
        }
        $linkedTemplate = true;
    } else {
        $copiedId = certificate_pool_link_template($templateId, $eventId, $userId);
        if ($copiedId === null || $copiedId === '') {
            json_response(['ok' => false, 'error' => 'Unable to link template.'], 400);
        }
        $linkedTemplate = true;
        $eventScopedTemplateId = $copiedId;
        $metaHeaders = [
            'Accept: application/json',
            'apikey: ' . SUPABASE_KEY,
            'Authorization: Bearer ' . SUPABASE_KEY,
        ];
        $metaUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/certificate_templates'
            . '?select=id,title,thumbnail_url'
            . '&id=eq.' . rawurlencode($eventScopedTemplateId)
            . '&limit=1';
        $metaRes = supabase_request('GET', $metaUrl, $metaHeaders);
        $metaRows = json_decode((string) ($metaRes['body'] ?? ''), true);
        $meta = is_array($metaRows) && isset($metaRows[0]) && is_array($metaRows[0]) ? $metaRows[0] : null;
        if ($meta) {
            $sessionLinked = [
                'id' => (string) ($meta['id'] ?? $eventScopedTemplateId),
                'title' => (string) ($meta['title'] ?? 'Certificate'),
                'thumbnail_url' => (string) ($meta['thumbnail_url'] ?? ''),
                'session_id' => '',
                'session_title' => 'Event certificate',
                'source_template_id' => $templateId,
            ];
        }
    }
    $seeded = certificate_pool_ensure_seed_from_linked_template(
        $eventId,
        $sessionId !== '' ? $sessionId : null
    );
    $pool = certificate_pool_status($eventId, $sessionId !== '' ? $sessionId : null);
    json_response([
        'ok' => true,
        'linked_template' => true,
        'template_id' => $eventScopedTemplateId,
        'source_template_id' => $templateId,
        'session_template' => $sessionLinked,
        'scanned' => 0,
        'inserted' => $seeded ? (int) ($pool['available'] ?? 0) : 0,
        'skipped' => [],
        'codes' => [],
        'pool' => $pool,
        'message' => $seeded
            ? ($sessionId !== ''
                ? 'Template linked — seed taken from design for auto-count.'
                : 'Template linked — seed taken from design for auto-count.')
            : ($sessionId !== ''
                ? 'Template linked to this seminar. Add a seed code (or PPTX) so certificates can auto-issue.'
                : 'Template linked to this event. Add a seed code (or PPTX) so certificates can auto-issue.'),
    ]);
} else {
    json_response(['ok' => false, 'error' => 'Upload a PPTX, provide scanned codes, or select a saved template.'], 400);
}

// Validate before any write, so a duplicate code never leaves a half-linked design.
$incomingCodes = [];
foreach ((array) $codes as $row) {
    if (is_array($row)) {
        $incomingCodes[] = (string) ($row['code'] ?? '');
    } elseif (is_string($row)) {
        $incomingCodes[] = $row;
    }
}
certificate_import_guard_codes($incomingCodes, $eventId, $sessionId);

// Link first (copy Library → event/session scoped design), then sync layout
// onto that copy only — never mutate the reusable Library row.
$linkedTemplate = false;
$sessionLinked = null;
$eventScopedTemplateId = $templateId;
$syncResult = null;
$sampleCodeForSync = '';
foreach ((array) $codes as $row) {
    if (is_array($row)) {
        $c = trim((string) ($row['code'] ?? ''));
    } elseif (is_string($row)) {
        $c = trim($row);
    } else {
        $c = '';
    }
    if ($c !== '') {
        $sampleCodeForSync = $c;
        break;
    }
}

if ($templateId !== '') {
    if ($sessionId !== '') {
        $sessionLinked = certificate_pool_link_template_to_session($templateId, $sessionId, $eventId, $userId);
        $linkedTemplate = $sessionLinked !== null;
        if (
            $linkedTemplate
            && is_array($layout)
            && !empty($layout['items'])
            && is_array($layout['items'])
            && !empty($sessionLinked['id'])
        ) {
            $syncResult = certificate_pptx_sync_session_template(
                (string) $sessionLinked['id'],
                $userId,
                $layout,
                $sampleCodeForSync !== '' ? $sampleCodeForSync : null
            );
            if (!($syncResult['ok'] ?? false)) {
                json_response([
                    'ok' => false,
                    'error' => (string) ($syncResult['error'] ?? 'Failed to sync PPTX layout into seminar template'),
                ], 400);
            }
        }
    } else {
        $copiedId = certificate_pool_link_template($templateId, $eventId, $userId);
        $linkedTemplate = $copiedId !== null && $copiedId !== '';
        if ($linkedTemplate) {
            $eventScopedTemplateId = $copiedId;
        }
        if (
            $linkedTemplate
            && is_array($layout)
            && !empty($layout['items'])
            && is_array($layout['items'])
            && $eventScopedTemplateId !== ''
        ) {
            $syncResult = certificate_pptx_sync_template(
                $eventScopedTemplateId,
                $userId,
                $layout,
                $eventId,
                null,
                $sampleCodeForSync !== '' ? $sampleCodeForSync : null
            );
            if (!($syncResult['ok'] ?? false)) {
                json_response([
                    'ok' => false,
                    'error' => (string) ($syncResult['error'] ?? 'Failed to sync PPTX layout into event template'),
                ], 400);
            }
        }
    }
}

$codeStrings = [];
foreach ((array) $codes as $row) {
    if (is_array($row)) {
        $codeStrings[] = (string) ($row['code'] ?? '');
    } elseif (is_string($row)) {
        $codeStrings[] = $row;
    }
}

try {
    $batch = certificate_pool_insert_batch(
        $eventId,
        $sessionId !== '' ? $sessionId : null,
        $userId,
        $codeStrings,
        $kind,
        $filename
    );
} catch (InvalidArgumentException $e) {
    json_response(['ok' => false, 'error' => $e->getMessage()], 400);
} catch (Throwable $e) {
    json_response(['ok' => false, 'error' => $e->getMessage()], 500);
}

$status = certificate_pool_status($eventId, $sessionId !== '' ? $sessionId : null);

// Whole-event link: mirror session_template shape so the client can update UI without refresh.
if ($sessionLinked === null && $linkedTemplate && $eventScopedTemplateId !== '') {
    $metaHeaders = [
        'Accept: application/json',
        'apikey: ' . SUPABASE_KEY,
        'Authorization: Bearer ' . SUPABASE_KEY,
    ];
    $metaUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/certificate_templates'
        . '?select=id,title,thumbnail_url'
        . '&id=eq.' . rawurlencode($eventScopedTemplateId)
        . '&limit=1';
    $metaRes = supabase_request('GET', $metaUrl, $metaHeaders);
    $metaRows = json_decode((string) ($metaRes['body'] ?? ''), true);
    $meta = is_array($metaRows) && isset($metaRows[0]) && is_array($metaRows[0]) ? $metaRows[0] : null;
    if ($meta) {
        $sessionLinked = [
            'id' => (string) ($meta['id'] ?? $eventScopedTemplateId),
            'title' => (string) ($meta['title'] ?? 'Certificate'),
            'thumbnail_url' => (string) ($meta['thumbnail_url'] ?? ''),
            'session_id' => '',
            'session_title' => 'Event certificate',
            'source_template_id' => $templateId,
        ];
    }
}

json_response([
    'ok' => true,
    'import_id' => $batch['import_id'],
    'source_kind' => $kind,
    'linked_template' => $linkedTemplate,
    'template_id' => $eventScopedTemplateId !== '' ? $eventScopedTemplateId : null,
    'source_template_id' => $templateId !== '' ? $templateId : null,
    'session_template' => $sessionLinked,
    'layout_synced' => is_array($syncResult) && !empty($syncResult['ok']),
    'layout_updated' => is_array($syncResult) ? (int) ($syncResult['updated'] ?? 0) : 0,
    'layout_warnings' => is_array($syncResult) ? ($syncResult['warnings'] ?? []) : [],
    'scanned' => count($codeStrings),
    'inserted' => count($batch['inserted']),
    'skipped' => $batch['skipped'],
    'codes' => array_map(static fn (string $c): array => ['code' => $c, 'status' => 'available'], $batch['inserted']),
    'pool' => [
        'available' => $status['available'],
        'assigned' => $status['assigned'],
        'total' => $status['total'],
    ],
]);
