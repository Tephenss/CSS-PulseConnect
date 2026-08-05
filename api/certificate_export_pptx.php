<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/session.php';
session_bootstrap();

// Never leak PHP warnings into the binary PPTX download body.
@ini_set('display_errors', '0');
@ini_set('memory_limit', '512M');
@ini_set('max_execution_time', '180');

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/supabase.php';
require_once __DIR__ . '/../includes/json.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/certificate_pptx.php';

$user = require_role(['teacher']);

/**
 * Accept JSON (legacy) or multipart FormData (fast path for image-heavy designs).
 *
 * @return array<string,mixed>
 */
function certificate_export_read_input(): array
{
    $contentType = (string) ($_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '');
    if (stripos($contentType, 'multipart/form-data') !== false) {
        $input = $_POST;
        if (isset($input['canvas_state']) && is_string($input['canvas_state'])) {
            $decoded = json_decode($input['canvas_state'], true);
            $input['canvas_state'] = is_array($decoded) ? $decoded : null;
        }
        return is_array($input) ? $input : [];
    }
    return require_post_json();
}

/**
 * Build a data-URL from an uploaded file part.
 */
function certificate_export_file_data_url(string $field): ?string
{
    if (empty($_FILES[$field]) || !is_array($_FILES[$field])) {
        return null;
    }
    $err = (int) ($_FILES[$field]['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($err !== UPLOAD_ERR_OK) {
        return null;
    }
    $tmp = (string) ($_FILES[$field]['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        return null;
    }
    $bin = @file_get_contents($tmp);
    if (!is_string($bin) || $bin === '') {
        return null;
    }
    $mime = strtolower(trim((string) ($_FILES[$field]['type'] ?? '')));
    if ($mime === '' || !str_starts_with($mime, 'image/')) {
        if (str_starts_with($bin, "\xFF\xD8")) {
            $mime = 'image/jpeg';
        } elseif (str_starts_with($bin, "\x89PNG")) {
            $mime = 'image/png';
        } else {
            $mime = 'image/jpeg';
        }
    }
    return 'data:' . $mime . ';base64,' . base64_encode($bin);
}

/**
 * Resolve __upload:media_N placeholders in canvas_state image srcs.
 *
 * @param array<string,mixed> $canvasState
 */
function certificate_export_hydrate_upload_refs(array &$canvasState): void
{
    $hydrate = static function (&$obj): void {
        if (!is_array($obj)) {
            return;
        }
        $src = isset($obj['src']) ? trim((string) $obj['src']) : '';
        if ($src === '' || !str_starts_with($src, '__upload:')) {
            return;
        }
        $field = substr($src, strlen('__upload:'));
        if ($field === '' || !preg_match('/^media_\d+$/', $field)) {
            return;
        }
        $dataUrl = certificate_export_file_data_url($field);
        if ($dataUrl !== null) {
            $obj['src'] = $dataUrl;
        }
    };

    if (isset($canvasState['backgroundImage']) && is_array($canvasState['backgroundImage'])) {
        $hydrate($canvasState['backgroundImage']);
    }
    if (isset($canvasState['objects']) && is_array($canvasState['objects'])) {
        foreach ($canvasState['objects'] as &$obj) {
            $hydrate($obj);
            if (is_array($obj) && isset($obj['objects']) && is_array($obj['objects'])) {
                foreach ($obj['objects'] as &$child) {
                    $hydrate($child);
                }
                unset($child);
            }
        }
        unset($obj);
    }
}

/** @var bool */
$streamProgress = false;

/**
 * @param array<string,mixed> $payload
 */
function certificate_export_emit(array $payload): void
{
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    if (function_exists('ob_flush')) {
        @ob_flush();
    }
    flush();
}

/**
 * @param array<string,mixed> $payload
 */
function certificate_export_fail(array $payload, int $status = 400): void
{
    global $streamProgress;
    if ($streamProgress) {
        $payload['ok'] = false;
        if (!isset($payload['pct'])) {
            $payload['pct'] = 0;
        }
        certificate_export_emit($payload);
        exit;
    }
    json_response($payload, $status);
}

$input = certificate_export_read_input();
csrf_validate($input['csrf_token'] ?? null);

$streamProgress = !empty($input['progress_stream'])
    && ($input['progress_stream'] === true
        || $input['progress_stream'] === 1
        || $input['progress_stream'] === '1'
        || $input['progress_stream'] === 'true');

if ($streamProgress) {
    while (ob_get_level() > 0) {
        @ob_end_flush();
    }
    @ini_set('zlib.output_compression', '0');
    @ini_set('implicit_flush', '1');
    header('Content-Type: application/x-ndjson; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Accel-Buffering: no');
    certificate_export_emit(['ok' => true, 'pct' => 3, 'label' => 'Starting export…']);
}

$userId = trim((string) ($user['id'] ?? ''));
$templateId = trim((string) ($input['template_id'] ?? ''));
$title = trim((string) ($input['title'] ?? 'Certificate Template'));
$canvasState = $input['canvas_state'] ?? null;

$headers = [
    'Accept: application/json',
    'apikey: ' . SUPABASE_KEY,
    'Authorization: Bearer ' . SUPABASE_KEY,
];

if (is_string($canvasState)) {
    $decoded = json_decode($canvasState, true);
    $canvasState = is_array($decoded) ? $decoded : null;
}

// Live canvas from the editor (preferred). DB load is fallback only when no canvas was posted.
$hasLiveCanvas = is_array($canvasState) && (
    (isset($canvasState['objects']) && is_array($canvasState['objects']) && $canvasState['objects'] !== [])
    || !empty($canvasState['backgroundImage'])
    || !empty($canvasState['background_data_url'])
    || (isset($canvasState['background']) && is_string($canvasState['background']) && trim((string) $canvasState['background']) !== '')
);

if ($hasLiveCanvas) {
    if ($streamProgress) {
        certificate_export_emit(['ok' => true, 'pct' => 10, 'label' => 'Using open canvas (no database reload)…']);
    }
} else {
    if ($templateId === '') {
        certificate_export_fail(['ok' => false, 'error' => 'canvas_state or template_id required.'], 400);
    }
    if ($streamProgress) {
        certificate_export_emit(['ok' => true, 'pct' => 8, 'label' => 'Loading saved template…']);
    }
    $tplUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/certificate_templates'
        . '?select=id,title,canvas_state,created_by&id=eq.' . rawurlencode($templateId) . '&limit=1';
    $tplRes = supabase_request('GET', $tplUrl, $headers);
    if ($streamProgress) {
        certificate_export_emit(['ok' => true, 'pct' => 14, 'label' => 'Decoding design…']);
    }
    $tplRows = json_decode((string) ($tplRes['body'] ?? ''), true);
    $tpl = is_array($tplRows) && isset($tplRows[0]) && is_array($tplRows[0]) ? $tplRows[0] : null;
    if (!$tpl || (string) ($tpl['created_by'] ?? '') !== $userId) {
        certificate_export_fail(['ok' => false, 'error' => 'Template not found or not owned by you.'], 403);
    }
    if ($title === 'Certificate Template' && !empty($tpl['title'])) {
        $title = (string) $tpl['title'];
    }
    $cs = $tpl['canvas_state'] ?? null;
    if (is_string($cs)) {
        $cs = json_decode($cs, true);
    }
    if (!is_array($cs)) {
        certificate_export_fail(['ok' => false, 'error' => 'Saved template has no editable canvas. Open it in the editor and Export PPTX.'], 400);
    }
    $canvasState = $cs;
}

// Ensure dimensions exist for coordinate mapping.
if (!isset($canvasState['width'])) {
    $canvasState['width'] = (float) ($input['canvas_width'] ?? 1123);
}
if (!isset($canvasState['height'])) {
    $canvasState['height'] = (float) ($input['canvas_height'] ?? 794);
}

// Multipart background file (preferred) or legacy data-URL field.
$bgFromFile = certificate_export_file_data_url('background');
if ($bgFromFile !== null) {
    $canvasState['background_data_url'] = $bgFromFile;
} else {
    $bgDataUrl = trim((string) ($input['background_data_url'] ?? ($canvasState['background_data_url'] ?? '')));
    if ($bgDataUrl !== '' && str_starts_with($bgDataUrl, 'data:image/')) {
        $canvasState['background_data_url'] = $bgDataUrl;
    }
}

if ($streamProgress) {
    certificate_export_emit(['ok' => true, 'pct' => 16, 'label' => 'Preparing media…']);
}
certificate_export_hydrate_upload_refs($canvasState);

// Skip preview/thumbnail — optional and can trigger GD; not needed for editable PPTX.
unset($canvasState['preview_data_url']);

try {
    $onProgress = null;
    if ($streamProgress) {
        $onProgress = static function (int $pct, string $label): void {
            certificate_export_emit(['ok' => true, 'pct' => $pct, 'label' => $label]);
        };
    }
    $pptx = certificate_pptx_build_from_fabric($canvasState, $title, $templateId, $onProgress);
} catch (Throwable $e) {
    certificate_export_fail(['ok' => false, 'error' => $e->getMessage()], 400);
}

$filename = preg_replace('/[^a-zA-Z0-9_-]+/', '_', $title) ?: 'certificate_template';

if ($streamProgress) {
    $token = bin2hex(random_bytes(16));
    $dir = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'pulseconnect_pptx';
    if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
        certificate_export_fail(['ok' => false, 'error' => 'Unable to create export temp folder.'], 500);
    }
    $path = $dir . DIRECTORY_SEPARATOR . $token . '.pptx';
    if (@file_put_contents($path, $pptx) === false) {
        certificate_export_fail(['ok' => false, 'error' => 'Unable to stage PPTX for download.'], 500);
    }
    if (!isset($_SESSION['pptx_export']) || !is_array($_SESSION['pptx_export'])) {
        $_SESSION['pptx_export'] = [];
    }
    // Drop expired tokens.
    $now = time();
    foreach ($_SESSION['pptx_export'] as $oldToken => $meta) {
        if (!is_array($meta) || (int) ($meta['expires'] ?? 0) < $now) {
            $oldPath = (string) ($meta['path'] ?? '');
            if ($oldPath !== '' && is_file($oldPath)) {
                @unlink($oldPath);
            }
            unset($_SESSION['pptx_export'][$oldToken]);
        }
    }
    $_SESSION['pptx_export'][$token] = [
        'path' => $path,
        'user_id' => $userId,
        'expires' => $now + 300,
        'filename' => $filename . '.pptx',
        'bytes' => strlen($pptx),
    ];
    certificate_export_emit([
        'ok' => true,
        'pct' => 100,
        'label' => 'Ready',
        'token' => $token,
        'filename' => $filename . '.pptx',
        'bytes' => strlen($pptx),
    ]);
    exit;
}

header('Content-Type: application/vnd.openxmlformats-officedocument.presentationml.presentation');
header('Content-Disposition: attachment; filename="' . $filename . '.pptx"');
header('Content-Length: ' . (string) strlen($pptx));
header('Cache-Control: no-store');
echo $pptx;
exit;
