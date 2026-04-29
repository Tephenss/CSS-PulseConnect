<?php
declare(strict_types=1);

session_start();

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/supabase.php';
require_once __DIR__ . '/../includes/json.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/email_notifications.php';

$user = require_role(['admin']);
$data = require_post_json();
require_csrf_from_json($data);

$firstName = isset($data['first_name']) ? clean_string((string) $data['first_name']) : '';
$middleName = isset($data['middle_name']) ? clean_string((string) $data['middle_name']) : '';
$lastName = isset($data['last_name']) ? clean_string((string) $data['last_name']) : '';
$suffix = isset($data['suffix']) ? clean_string((string) $data['suffix']) : '';
$contactNumber = isset($data['contact_number']) ? clean_string((string) $data['contact_number']) : '';
$email = isset($data['email']) ? strtolower(clean_string((string) $data['email'])) : '';

if ($firstName === '' || mb_strlen($firstName) < 2 || mb_strlen($firstName) > 60) {
    json_response(['ok' => false, 'error' => 'Please enter a valid first name (2–60 characters).'], 400);
}
if ($lastName === '' || mb_strlen($lastName) < 2 || mb_strlen($lastName) > 60) {
    json_response(['ok' => false, 'error' => 'Please enter a valid last name (2–60 characters).'], 400);
}
if ($middleName !== '' && mb_strlen($middleName) > 60) {
    json_response(['ok' => false, 'error' => 'Middle name is too long.'], 400);
}
if ($suffix !== '' && mb_strlen($suffix) > 30) {
    json_response(['ok' => false, 'error' => 'Suffix is too long.'], 400);
}
if ($contactNumber !== '' && mb_strlen($contactNumber) > 30) {
    json_response(['ok' => false, 'error' => 'Contact number is too long.'], 400);
}
if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    json_response(['ok' => false, 'error' => 'Please enter a valid email address.'], 400);
}

function generate_teacher_temp_password(int $length = 12): string
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

$tempPassword = generate_teacher_temp_password(12);
$passwordHash = password_hash($tempPassword, PASSWORD_DEFAULT);

$payload = [
    'first_name' => $firstName,
    'middle_name' => $middleName !== '' ? $middleName : null,
    'last_name' => $lastName,
    'suffix' => $suffix !== '' ? $suffix : null,
    'contact_number' => $contactNumber !== '' ? $contactNumber : null,
    'email' => $email,
    'password' => $passwordHash,
    'role' => 'teacher',
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
    json_response([
        'ok' => false,
        'error' => build_error($res['body'] ?? null, (int) ($res['status'] ?? 0), $res['error'] ?? null, 'Could not create teacher account'),
    ], 500);
}

$rows = json_decode((string) $res['body'], true);
$created = is_array($rows) && isset($rows[0]) ? $rows[0] : null;

$fullName = build_display_name($firstName, $middleName, $lastName, $suffix);
$sent = send_teacher_account_credentials_email($email, $fullName, $tempPassword);
if (!$sent) {
    // Rollback: delete the created teacher account to avoid an account with unknown password.
    $createdId = is_array($created) ? (string) ($created['id'] ?? '') : '';
    if ($createdId !== '') {
        $deleteUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/' . SUPABASE_TABLE_USERS
            . '?id=eq.' . rawurlencode($createdId);
        $deleteHeaders = [
            'Accept: application/json',
            'apikey: ' . SUPABASE_KEY,
            'Authorization: Bearer ' . SUPABASE_KEY,
        ];
        supabase_request('DELETE', $deleteUrl, $deleteHeaders);
    }
    json_response([
        'ok' => false,
        'error' => 'Teacher account email failed to send. No account was created.',
        'debug' => function_exists('smtp_get_last_error') ? smtp_get_last_error() : null,
    ], 500);
}

json_response(['ok' => true, 'user' => $created, 'email_sent' => true], 201);
