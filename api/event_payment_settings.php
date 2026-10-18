<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/session.php';
session_bootstrap();

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/supabase.php';
require_once __DIR__ . '/../includes/json.php';
require_once __DIR__ . '/../includes/csrf.php';

$user = require_role(['admin', 'teacher']);
$data = require_post_json();
require_csrf_from_json($data);

$eventId = trim((string) ($data['event_id'] ?? ''));
$accountName = trim((string) ($data['gcash_account_name'] ?? ''));
$number = preg_replace('/\s+/', '', (string) ($data['gcash_number'] ?? '')) ?? '';
$userId = trim((string) ($user['id'] ?? ''));
$role = strtolower(trim((string) ($user['role'] ?? '')));

if ($eventId === '') {
    json_response(['ok' => false, 'error' => 'Missing event id.'], 400);
}
if (mb_strlen($accountName) > 100 || mb_strlen($number) > 20) {
    json_response(['ok' => false, 'error' => 'GCash account details are too long.'], 400);
}
if (($accountName === '') !== ($number === '')) {
    json_response(['ok' => false, 'error' => 'Enter both the GCash account name and number, or clear both.'], 400);
}
if ($number !== '' && preg_match('/\A(?:\+?63|0)9\d{9}\z/', $number) !== 1) {
    json_response(['ok' => false, 'error' => 'Enter a valid Philippine GCash mobile number.'], 400);
}

$headers = [
    'Accept: application/json',
    'apikey: ' . SUPABASE_KEY,
    'Authorization: Bearer ' . SUPABASE_KEY,
];
$eventUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/events'
    . '?id=eq.' . rawurlencode($eventId)
    . '&select=id,created_by'
    . '&limit=1';
$eventRes = supabase_request('GET', $eventUrl, $headers);
$eventRows = json_decode((string) ($eventRes['body'] ?? ''), true);
$event = is_array($eventRows) && isset($eventRows[0]) && is_array($eventRows[0])
    ? $eventRows[0]
    : null;
if (!is_array($event)) {
    json_response(['ok' => false, 'error' => 'Event not found.'], 404);
}
if ($role !== 'admin' && (string) ($event['created_by'] ?? '') !== $userId) {
    json_response(['ok' => false, 'error' => 'Only the event creator can update payment details.'], 403);
}

$url = rtrim(SUPABASE_URL, '/') . '/rest/v1/events?id=eq.' . rawurlencode($eventId);
$res = supabase_request('PATCH', $url, [
    'Content-Type: application/json',
    'Accept: application/json',
    'apikey: ' . SUPABASE_KEY,
    'Authorization: Bearer ' . SUPABASE_KEY,
    'Prefer: return=minimal',
], json_encode([
    'gcash_account_name' => $accountName !== '' ? $accountName : null,
    'gcash_number' => $number !== '' ? $number : null,
], JSON_UNESCAPED_SLASHES));

if (!$res['ok']) {
    json_response(['ok' => false, 'error' => 'Could not save GCash details. Run migration 073 first.'], 500);
}

json_response(['ok' => true, 'message' => 'GCash payment details saved.']);
