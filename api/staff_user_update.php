<?php
declare(strict_types=1);

/**
 * Admin: update a teacher or admin profile (no password / role change).
 */

require_once __DIR__ . '/../includes/session.php';
session_bootstrap();

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/supabase.php';
require_once __DIR__ . '/../includes/json.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/api_rate_limit.php';

$admin = require_role(['admin']);
$adminId = trim((string) ($admin['id'] ?? ''));
$data = require_post_json();
require_csrf_from_json($data);

$clientIp = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
if (!api_rate_limit_allow('staff_user_update:' . $adminId . ':' . $clientIp, 60, 3600)) {
    json_response(['ok' => false, 'error' => 'Too many updates. Try again later.'], 429);
}

$targetId = trim((string) ($data['user_id'] ?? ''));
$firstName = isset($data['first_name']) ? clean_string((string) $data['first_name']) : '';
$middleName = isset($data['middle_name']) ? clean_string((string) $data['middle_name']) : '';
$lastName = isset($data['last_name']) ? clean_string((string) $data['last_name']) : '';
$suffix = isset($data['suffix']) ? clean_string((string) $data['suffix']) : '';
$contactNumber = isset($data['contact_number']) ? clean_string((string) $data['contact_number']) : '';
$email = isset($data['email']) ? strtolower(clean_string((string) $data['email'])) : '';

if ($targetId === '') {
    json_response(['ok' => false, 'error' => 'user_id required'], 400);
}
$contactNumber = normalize_ph_contact_digits($contactNumber);
if (!is_valid_person_name($firstName, true)) {
    json_response(['ok' => false, 'error' => 'First name must be letters only (2–60 characters, no numbers).'], 400);
}
if (!is_valid_person_name($lastName, true)) {
    json_response(['ok' => false, 'error' => 'Surname must be letters only (2–60 characters, no numbers).'], 400);
}
if (!is_valid_person_name($middleName, false)) {
    json_response(['ok' => false, 'error' => 'Middle name must be letters only (no numbers).'], 400);
}
if ($suffix !== '' && mb_strlen($suffix) > 30) {
    json_response(['ok' => false, 'error' => 'Suffix is too long.'], 400);
}
if (!is_valid_ph_contact($contactNumber, false)) {
    json_response(['ok' => false, 'error' => 'Contact number must be 11 digits (example: 09123456789).'], 400);
}
if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    json_response(['ok' => false, 'error' => 'Please enter a valid email address.'], 400);
}

$headers = [
    'Accept: application/json',
    'apikey: ' . SUPABASE_KEY,
    'Authorization: Bearer ' . SUPABASE_KEY,
];
$writeHeaders = array_merge($headers, [
    'Content-Type: application/json',
    'Prefer: return=representation',
]);

$getUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/' . SUPABASE_TABLE_USERS
    . '?select=id,email,role,archived_at'
    . '&id=eq.' . rawurlencode($targetId)
    . '&limit=1';
$getRes = supabase_request('GET', $getUrl, $headers);
$rows = $getRes['ok'] ? json_decode((string) ($getRes['body'] ?? ''), true) : [];
$existing = is_array($rows) && isset($rows[0]) && is_array($rows[0]) ? $rows[0] : null;
if ($existing === null) {
    json_response(['ok' => false, 'error' => 'Account not found.'], 404);
}

$role = strtolower(trim((string) ($existing['role'] ?? '')));
if ($role !== 'teacher' && $role !== 'admin') {
    json_response(['ok' => false, 'error' => 'Only teacher or admin accounts can be edited here.'], 403);
}

$oldEmail = strtolower(trim((string) ($existing['email'] ?? '')));
if ($email !== $oldEmail) {
    $dupUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/' . SUPABASE_TABLE_USERS
        . '?select=id&email=eq.' . rawurlencode($email) . '&limit=1';
    $dupRes = supabase_request('GET', $dupUrl, $headers);
    $dupRows = $dupRes['ok'] ? json_decode((string) ($dupRes['body'] ?? ''), true) : [];
    $dupId = is_array($dupRows) && isset($dupRows[0]['id']) ? trim((string) $dupRows[0]['id']) : '';
    if ($dupId !== '' && $dupId !== $targetId) {
        json_response(['ok' => false, 'error' => 'That email is already in use.'], 409);
    }
}

$payload = [
    'first_name' => $firstName,
    'middle_name' => $middleName !== '' ? $middleName : null,
    'last_name' => $lastName,
    'suffix' => $suffix !== '' ? $suffix : null,
    'contact_number' => $contactNumber !== '' ? $contactNumber : null,
    'email' => $email,
    'updated_at' => gmdate('c'),
];

$patchUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/' . SUPABASE_TABLE_USERS
    . '?id=eq.' . rawurlencode($targetId)
    . '&select=id,first_name,middle_name,last_name,suffix,email,role,contact_number';
$patchRes = supabase_request('PATCH', $patchUrl, $writeHeaders, json_encode($payload, JSON_UNESCAPED_SLASHES));
if (!$patchRes['ok']) {
    json_response([
        'ok' => false,
        'error' => build_error($patchRes['body'] ?? null, (int) ($patchRes['status'] ?? 0), $patchRes['error'] ?? null, 'Update failed'),
    ], 500);
}

$updatedRows = json_decode((string) ($patchRes['body'] ?? ''), true);
$updated = is_array($updatedRows) && isset($updatedRows[0]) ? $updatedRows[0] : null;

if ($targetId === $adminId && is_array($updated)) {
    $_SESSION['user']['full_name'] = build_display_name($firstName, $middleName, $lastName, $suffix);
    $_SESSION['user']['email'] = $email;
}

json_response(['ok' => true, 'user' => $updated]);
