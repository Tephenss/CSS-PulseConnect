<?php
declare(strict_types=1);

session_start();

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/supabase.php';
require_once __DIR__ . '/../includes/json.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/email_notifications.php';

$admin = require_role(['admin']);
$data = require_post_json();
require_csrf_from_json($data);

$userId = trim((string) ($data['user_id'] ?? ''));
$action = strtolower(trim((string) ($data['action'] ?? '')));
$reason = trim((string) ($data['reason'] ?? ''));

if ($userId === '') {
    json_response(['ok' => false, 'error' => 'user_id is required.'], 400);
}
if (!in_array($action, ['approve', 'reject'], true)) {
    json_response(['ok' => false, 'error' => 'Invalid action.'], 400);
}
if ($action === 'reject' && $reason === '') {
    json_response(['ok' => false, 'error' => 'Rejection reason is required.'], 400);
}

$headers = [
    'Accept: application/json',
    'apikey: ' . SUPABASE_KEY,
    'Authorization: Bearer ' . SUPABASE_KEY,
];

$lookupUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/' . SUPABASE_TABLE_USERS
    . '?select=id,first_name,last_name,email,role,account_status'
    . '&id=eq.' . rawurlencode($userId)
    . '&limit=1';

$lookupRes = supabase_request('GET', $lookupUrl, $headers);
if (!$lookupRes['ok']) {
    json_response(['ok' => false, 'error' => 'Failed to load student record.'], 500);
}

$lookupRows = json_decode((string) $lookupRes['body'], true);
$target = is_array($lookupRows) && isset($lookupRows[0]) && is_array($lookupRows[0]) ? $lookupRows[0] : null;
if (!$target) {
    json_response(['ok' => false, 'error' => 'Student record not found.'], 404);
}

if (strtolower((string) ($target['role'] ?? '')) !== 'student') {
    json_response(['ok' => false, 'error' => 'Only student applications can be reviewed.'], 400);
}
$oldStatus = strtolower((string) ($target['account_status'] ?? 'pending'));

$newStatus = $action === 'approve' ? 'approved' : 'rejected';
$payload = [
    'account_status' => $newStatus,
    'approval_note' => $reason !== '' ? $reason : null,
    'reviewed_at' => gmdate('c'),
    'reviewed_by' => (string) ($admin['id'] ?? ''),
    'updated_at' => gmdate('c'),
];

$updateUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/' . SUPABASE_TABLE_USERS
    . '?id=eq.' . rawurlencode($userId)
    . '&select=id,first_name,last_name,email,account_status,approval_note';

$updateHeaders = [
    'Content-Type: application/json',
    'Accept: application/json',
    'apikey: ' . SUPABASE_KEY,
    'Authorization: Bearer ' . SUPABASE_KEY,
    'Prefer: return=representation',
];

$updateRes = supabase_request(
    'PATCH',
    $updateUrl,
    $updateHeaders,
    json_encode($payload, JSON_UNESCAPED_SLASHES)
);

if (!$updateRes['ok']) {
    json_response(['ok' => false, 'error' => 'Failed to update application status.'], 500);
}

$updatedRows = json_decode((string) $updateRes['body'], true);
$updated = is_array($updatedRows) && isset($updatedRows[0]) ? $updatedRows[0] : null;

$email = trim((string) ($target['email'] ?? ''));
$fullName = trim(((string) ($target['first_name'] ?? '')) . ' ' . ((string) ($target['last_name'] ?? '')));
$emailSent = send_student_application_status_email($email, $fullName, $newStatus, $reason);
if (!$emailSent) {
    // Revert status if we cannot notify the student.
    $revertPayload = [
        'account_status' => $oldStatus,
        'updated_at' => gmdate('c'),
    ];
    supabase_request(
        'PATCH',
        $updateUrl,
        $updateHeaders,
        json_encode($revertPayload, JSON_UNESCAPED_SLASHES)
    );
    json_response([
        'ok' => false,
        'error' => 'Status email failed to send. Application status was not changed.',
    ], 500);
}

json_response([
    'ok' => true,
    'user' => $updated,
    'email_sent' => $emailSent,
], 200);

