<?php
declare(strict_types=1);

/**
 * Admin: create another admin account and email a temporary password.
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
require_once __DIR__ . '/../includes/email_notifications.php';

$actor = require_role(['admin']);
$actorId = trim((string) ($actor['id'] ?? ''));
$data = require_post_json();
require_csrf_from_json($data);

$clientIp = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
if (!api_rate_limit_allow('users_register_admin:' . $actorId . ':' . $clientIp, 10, 3600)) {
    json_response(['ok' => false, 'error' => 'Too many admin registrations. Try again later.'], 429);
}

$firstName = isset($data['first_name']) ? clean_string((string) $data['first_name']) : '';
$middleName = isset($data['middle_name']) ? clean_string((string) $data['middle_name']) : '';
$lastName = isset($data['last_name']) ? clean_string((string) $data['last_name']) : '';
$suffix = isset($data['suffix']) ? clean_string((string) $data['suffix']) : '';
$contactNumber = isset($data['contact_number']) ? clean_string((string) $data['contact_number']) : '';
$email = isset($data['email']) ? strtolower(clean_string((string) $data['email'])) : '';

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

function generate_admin_temp_password(int $length = 12): string
{
    $lower = 'abcdefghjkmnpqrstuvwxyz';
    $upper = 'ABCDEFGHJKMNPQRSTUVWXYZ';
    $digits = '23456789';
    $symbols = '@#$%*_-+';
    $all = $lower . $upper . $digits . $symbols;

    $pick = function (string $set): string {
        return $set[random_int(0, strlen($set) - 1)];
    };

    $chars = [
        $pick($lower),
        $pick($upper),
        $pick($digits),
        $pick($symbols),
    ];
    while (count($chars) < $length) {
        $chars[] = $pick($all);
    }
    shuffle($chars);
    return implode('', $chars);
}

$authHeaders = [
    'Accept: application/json',
    'apikey: ' . SUPABASE_KEY,
    'Authorization: Bearer ' . SUPABASE_KEY,
];
$dupUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/' . SUPABASE_TABLE_USERS
    . '?select=id,email&email=eq.' . rawurlencode($email)
    . '&limit=1';
$dupRes = supabase_request('GET', $dupUrl, $authHeaders);
$dupRows = $dupRes['ok'] ? json_decode((string) ($dupRes['body'] ?? ''), true) : [];
if (is_array($dupRows) && isset($dupRows[0]) && is_array($dupRows[0])) {
    json_response(['ok' => false, 'error' => 'That email is already in use.'], 409);
}

$tempPassword = generate_admin_temp_password(12);
$passwordHash = password_hash($tempPassword, PASSWORD_DEFAULT);

$payload = [
    'first_name' => $firstName,
    'middle_name' => $middleName !== '' ? $middleName : null,
    'last_name' => $lastName,
    'suffix' => $suffix !== '' ? $suffix : null,
    'contact_number' => $contactNumber !== '' ? $contactNumber : null,
    'email' => $email,
    'password' => $passwordHash,
    'role' => 'admin',
    'account_status' => 'approved',
    'registration_source' => 'admin',
    'section_id' => null,
];

$url = rtrim(SUPABASE_URL, '/') . '/rest/v1/' . SUPABASE_TABLE_USERS . '?select=id,first_name,middle_name,last_name,suffix,email,role';

$headers = [
    'Content-Type: application/json',
    'Accept: application/json',
    'apikey: ' . SUPABASE_KEY,
    'Authorization: Bearer ' . SUPABASE_KEY,
    'Prefer: return=representation',
];

$res = supabase_request('POST', $url, $headers, json_encode([$payload], JSON_UNESCAPED_SLASHES));

if (!$res['ok']) {
    $errText = strtolower((string) ($res['body'] ?? ''));
    if (str_contains($errText, 'duplicate') || str_contains($errText, 'unique')) {
        json_response(['ok' => false, 'error' => 'That email is already in use.'], 409);
    }
    json_response([
        'ok' => false,
        'error' => build_error($res['body'] ?? null, (int) ($res['status'] ?? 0), $res['error'] ?? null, 'Could not create admin account'),
    ], 500);
}

$rows = json_decode((string) $res['body'], true);
$created = is_array($rows) && isset($rows[0]) ? $rows[0] : null;

$fullName = build_display_name($firstName, $middleName, $lastName, $suffix);
$sent = send_staff_account_credentials_email($email, $fullName, $tempPassword, 'admin');
if (!$sent) {
    $createdId = is_array($created) ? (string) ($created['id'] ?? '') : '';
    if ($createdId !== '') {
        $deleteUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/' . SUPABASE_TABLE_USERS
            . '?id=eq.' . rawurlencode($createdId);
        supabase_request('DELETE', $deleteUrl, $authHeaders);
    }
    json_response([
        'ok' => false,
        'error' => 'Admin account email failed to send. No account was created.',
        'debug' => function_exists('smtp_get_last_error') ? smtp_get_last_error() : null,
    ], 500);
}

json_response(['ok' => true, 'user' => $created, 'email_sent' => true], 201);
