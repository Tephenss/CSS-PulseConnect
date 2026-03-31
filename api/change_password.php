<?php
declare(strict_types=1);

session_start();

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/supabase.php';

// Ensure the user is logged in
$user = require_login();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed.']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?? [];

$csrfToken = (string)($input['csrf_token'] ?? '');
$expected = (string)($_SESSION['csrf_token'] ?? '');

if ($expected === '' || $csrfToken === '' || !hash_equals($expected, $csrfToken)) {
    http_response_code(403);
    echo json_encode(['error' => 'Invalid CSRF token.']);
    exit;
}

$currentPassword = (string)($input['current_password'] ?? '');
$newPassword = (string)($input['new_password'] ?? '');

if (strlen($currentPassword) < 1) {
    echo json_encode(['error' => 'Please enter your current password.']);
    exit;
}

if (strlen($newPassword) < 8) {
    echo json_encode(['error' => 'New password must be at least 8 characters long.']);
    exit;
}

$userId = $user['id'] ?? '';
if (!$userId) {
    echo json_encode(['error' => 'User ID missing from session.']);
    exit;
}

// 1. Fetch current stored password hash from Supabase
$url = rtrim(SUPABASE_URL, '/') . '/rest/v1/' . SUPABASE_TABLE_USERS 
    . '?select=password&id=eq.' . rawurlencode((string)$userId) . '&limit=1';

$headers = [
    'Accept: application/json',
    'apikey: ' . SUPABASE_KEY,
    'Authorization: Bearer ' . SUPABASE_KEY,
];

try {
    $res = supabase_request('GET', $url, $headers);
    if (!$res['ok']) {
        echo json_encode(['error' => 'Failed to fetch user data.']);
        exit;
    }

    $decoded = json_decode((string)$res['body'], true);
    if (!is_array($decoded) || count($decoded) === 0 || !isset($decoded[0]['password'])) {
        echo json_encode(['error' => 'User record corrupted or missing.']);
        exit;
    }

    $storedHash = (string) $decoded[0]['password'];

    // 2. Verify current password
    if (!password_verify($currentPassword, $storedHash)) {
        echo json_encode(['error' => 'Incorrect current password.']);
        exit;
    }

    // 3. Hash the new password
    $newHash = password_hash($newPassword, PASSWORD_DEFAULT);

    // 4. Update the user record via PATCH
    $patchUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/' . SUPABASE_TABLE_USERS 
        . '?id=eq.' . rawurlencode((string)$userId);
    
    $patchHeaders = [
        'Content-Type: application/json',
        'Prefer: return=minimal',
        'apikey: ' . SUPABASE_KEY,
        'Authorization: Bearer ' . SUPABASE_KEY,
    ];
    
    $patchPayload = json_encode(['password' => $newHash]);
    if (!$patchPayload) {
        throw new RuntimeException('Failed to encode JSON payload.');
    }

    $patchRes = supabase_request('PATCH', $patchUrl, $patchHeaders, $patchPayload);

    if (!$patchRes['ok']) {
        echo json_encode(['error' => 'Failed to securely update password.']);
        exit;
    }

    echo json_encode(['success' => true]);
    exit;

} catch (Throwable $e) {
    echo json_encode(['error' => 'An unexpected server error occurred.']);
    exit;
}
