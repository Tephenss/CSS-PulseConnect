<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/session.php';
session_bootstrap();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/supabase.php';

$user = require_role(['admin', 'teacher']);
$submissionId = trim((string) ($_GET['id'] ?? ''));
if ($submissionId === '') {
    http_response_code(400);
    exit('Missing payment submission.');
}

$headers = [
    'Accept: application/json',
    'apikey: ' . SUPABASE_KEY,
    'Authorization: Bearer ' . SUPABASE_KEY,
];
$res = supabase_request('GET', rtrim(SUPABASE_URL, '/') . '/rest/v1/event_payment_submissions'
    . '?id=eq.' . rawurlencode($submissionId) . '&select=event_id,receipt_path&limit=1', $headers);
$rows = json_decode((string) ($res['body'] ?? ''), true);
$submission = is_array($rows) && isset($rows[0]) && is_array($rows[0]) ? $rows[0] : null;
if (!is_array($submission)) {
    http_response_code(404);
    exit('Payment proof not found.');
}

$userId = trim((string) ($user['id'] ?? ''));
$role = strtolower(trim((string) ($user['role'] ?? '')));
$eventRes = supabase_request('GET', rtrim(SUPABASE_URL, '/') . '/rest/v1/events'
    . '?id=eq.' . rawurlencode((string) $submission['event_id']) . '&select=created_by&limit=1', $headers);
$eventRows = json_decode((string) ($eventRes['body'] ?? ''), true);
$creatorId = is_array($eventRows) && isset($eventRows[0]) ? (string) ($eventRows[0]['created_by'] ?? '') : '';
if ($role !== 'admin' && $creatorId !== $userId) {
    http_response_code(403);
    exit('You cannot view this payment proof.');
}

$path = trim((string) ($submission['receipt_path'] ?? ''));
if ($path === '' || str_contains($path, '..')) {
    http_response_code(404);
    exit('Payment proof is unavailable.');
}
$encodedPath = implode('/', array_map('rawurlencode', explode('/', $path)));
$signed = supabase_request(
    'POST',
    rtrim(SUPABASE_URL, '/') . '/storage/v1/object/sign/event-payment-proofs/' . $encodedPath,
    ['Content-Type: application/json', 'apikey: ' . SUPABASE_KEY, 'Authorization: Bearer ' . SUPABASE_KEY],
    json_encode(['expiresIn' => 300], JSON_UNESCAPED_SLASHES)
);
$signedData = json_decode((string) ($signed['body'] ?? ''), true);
$signedPath = is_array($signedData) ? trim((string) ($signedData['signedURL'] ?? '')) : '';
if (!$signed['ok'] || $signedPath === '') {
    http_response_code(502);
    exit('Could not open the private payment proof.');
}
$target = str_starts_with($signedPath, 'http')
    ? $signedPath
    : rtrim(SUPABASE_URL, '/') . '/storage/v1' . '/' . ltrim($signedPath, '/');
header('Cache-Control: no-store, private');
header('Location: ' . $target, true, 302);
exit;
