<?php
declare(strict_types=1);

session_start();

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/supabase.php';
require_once __DIR__ . '/../includes/json.php';
require_once __DIR__ . '/../includes/csrf.php';

$user = require_role(['admin']);
$data = require_post_json();
require_csrf_from_json($data);

$firstName = isset($data['first_name']) ? clean_string((string) $data['first_name']) : '';
$middleName = isset($data['middle_name']) ? clean_string((string) $data['middle_name']) : '';
$lastName = isset($data['last_name']) ? clean_string((string) $data['last_name']) : '';
$suffix = isset($data['suffix']) ? clean_string((string) $data['suffix']) : '';
$contactNumber = isset($data['contact_number']) ? clean_string((string) $data['contact_number']) : '';
$email = isset($data['email']) ? strtolower(clean_string((string) $data['email'])) : '';
$password = isset($data['password']) ? (string) $data['password'] : '';

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
if (mb_strlen($password) < 8) {
    json_response(['ok' => false, 'error' => 'Password must be at least 8 characters.'], 400);
}

$passwordHash = password_hash($password, PASSWORD_DEFAULT);

$payload = [
    'first_name' => $firstName,
    'middle_name' => $middleName !== '' ? $middleName : null,
    'last_name' => $lastName,
    'suffix' => $suffix !== '' ? $suffix : null,
    'contact_number' => $contactNumber !== '' ? $contactNumber : null,
    'email' => $email,
    'password' => $passwordHash,
    'role' => 'teacher',
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
json_response(['ok' => true, 'user' => $created], 201);
