<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/session.php';
session_bootstrap();

@ini_set('display_errors', '0');

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/json.php';
require_once __DIR__ . '/../includes/csrf.php';

$user = require_role(['teacher']);
$userId = trim((string) ($user['id'] ?? ''));

$token = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $contentType = (string) ($_SERVER['CONTENT_TYPE'] ?? '');
    if (stripos($contentType, 'application/json') !== false) {
        $input = require_post_json();
        csrf_validate($input['csrf_token'] ?? null);
        $token = trim((string) ($input['token'] ?? ''));
    } else {
        csrf_validate($_POST['csrf_token'] ?? null);
        $token = trim((string) ($_POST['token'] ?? ''));
    }
} else {
    $token = trim((string) ($_GET['token'] ?? ''));
}

if ($token === '' || preg_match('/^[a-f0-9]{32}$/', $token) !== 1) {
    json_response(['ok' => false, 'error' => 'Invalid download token.'], 400);
}

$meta = $_SESSION['pptx_export'][$token] ?? null;
if (!is_array($meta)) {
    json_response(['ok' => false, 'error' => 'Export expired. Try Export PPTX again.'], 404);
}

if ((string) ($meta['user_id'] ?? '') !== $userId) {
    json_response(['ok' => false, 'error' => 'Forbidden.'], 403);
}

if ((int) ($meta['expires'] ?? 0) < time()) {
    $pathExpired = (string) ($meta['path'] ?? '');
    if ($pathExpired !== '' && is_file($pathExpired)) {
        @unlink($pathExpired);
    }
    unset($_SESSION['pptx_export'][$token]);
    json_response(['ok' => false, 'error' => 'Export expired. Try Export PPTX again.'], 410);
}

$path = (string) ($meta['path'] ?? '');
if ($path === '' || !is_file($path)) {
    unset($_SESSION['pptx_export'][$token]);
    json_response(['ok' => false, 'error' => 'Export file missing. Try Export PPTX again.'], 404);
}

$filename = (string) ($meta['filename'] ?? 'certificate_template.pptx');
$filename = preg_replace('/[^a-zA-Z0-9._-]+/', '_', $filename) ?: 'certificate_template.pptx';
$bytes = filesize($path);
if ($bytes === false) {
    $bytes = 0;
}

header('Content-Type: application/vnd.openxmlformats-officedocument.presentationml.presentation');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . (string) $bytes);
header('Cache-Control: no-store');

$fp = fopen($path, 'rb');
if ($fp === false) {
    json_response(['ok' => false, 'error' => 'Unable to read export file.'], 500);
}
fpassthru($fp);
fclose($fp);

@unlink($path);
unset($_SESSION['pptx_export'][$token]);
exit;
