<?php
declare(strict_types=1);

/**
 * Admin: generate a new temp password for a teacher and email it again.
 */

require_once __DIR__ . '/../includes/session.php';
session_bootstrap();

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/supabase.php';
require_once __DIR__ . '/../includes/json.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/email_notifications.php';

$admin = require_role(['admin']);
$data = require_post_json();
require_csrf_from_json($data);

$userId = trim((string) ($data['user_id'] ?? ''));
if ($userId === '') {
    json_response(['ok' => false, 'error' => 'Missing teacher id.'], 400);
}

$headers = [
    'Accept: application/json',
    'apikey: ' . SUPABASE_KEY,
    'Authorization: Bearer ' . SUPABASE_KEY,
];
$getUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/' . SUPABASE_TABLE_USERS
    . '?select=id,email,first_name,middle_name,last_name,suffix,role'
    . '&id=eq.' . rawurlencode($userId)
    . '&role=eq.teacher'
    . '&limit=1';
$getRes = supabase_request('GET', $getUrl, $headers);
$rows = $getRes['ok'] ? json_decode((string) ($getRes['body'] ?? ''), true) : [];
$teacher = is_array($rows) && isset($rows[0]) && is_array($rows[0]) ? $rows[0] : null;
if ($teacher === null) {
    json_response(['ok' => false, 'error' => 'Teacher not found.'], 404);
}

$email = strtolower(trim((string) ($teacher['email'] ?? '')));
if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    json_response(['ok' => false, 'error' => 'Teacher has no valid email.'], 400);
}

$tempPassword = generate_teacher_temp_password(12);
$passwordHash = password_hash($tempPassword, PASSWORD_DEFAULT);
$patchRes = supabase_request(
    'PATCH',
    rtrim(SUPABASE_URL, '/') . '/rest/v1/' . SUPABASE_TABLE_USERS . '?id=eq.' . rawurlencode($userId) . '&role=eq.teacher',
    [
        'Content-Type: application/json',
        'Accept: application/json',
        'apikey: ' . SUPABASE_KEY,
        'Authorization: Bearer ' . SUPABASE_KEY,
        'Prefer: return=minimal',
    ],
    json_encode(['password' => $passwordHash], JSON_UNESCAPED_SLASHES)
);
if (!($patchRes['ok'] ?? false)) {
    json_response(['ok' => false, 'error' => 'Could not update teacher password.'], 500);
}

$fullName = build_display_name(
    (string) ($teacher['first_name'] ?? ''),
    (string) ($teacher['middle_name'] ?? ''),
    (string) ($teacher['last_name'] ?? ''),
    (string) ($teacher['suffix'] ?? '')
);
$sent = send_teacher_account_credentials_email($email, $fullName, $tempPassword);
$smtp = function_exists('smtp_get_last_error') ? smtp_get_last_error() : '';

json_response([
    'ok' => true,
    'email_sent' => $sent,
    'email' => $email,
    'temp_password' => $tempPassword,
    'error' => $sent ? null : ('Email may be delayed or in Spam.' . ($smtp !== '' ? (' ' . $smtp) : '')),
], 200);
