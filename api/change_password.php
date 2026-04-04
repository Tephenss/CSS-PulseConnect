<?php
require_once '../includes/supabase.php';
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'Method not allowed.']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
$userId = $data['user_id'] ?? null;
$oldPassword = $data['old_password'] ?? '';
$newPassword = $data['new_password'] ?? '';

if (!$userId || !$oldPassword || !$newPassword) {
    echo json_encode(['ok' => false, 'error' => 'Missing required fields.']);
    exit;
}

// 1. Fetch user to verify old password
require_once '../config.php';
$url = SUPABASE_URL . '/rest/v1/users?id=eq.' . urlencode($userId);
$headers = [
    'apikey: ' . SUPABASE_KEY,
    'Authorization: Bearer ' . SUPABASE_KEY,
];

$userQuery = supabase_request('GET', $url, $headers);
if (!$userQuery['ok']) {
    echo json_encode(['ok' => false, 'error' => 'User not found.']);
    exit;
}
$usersData = json_decode($userQuery['body'], true);
if (empty($usersData)) {
    echo json_encode(['ok' => false, 'error' => 'User not found.']);
    exit;
}

$user = $usersData[0];
$storedHash = $user['password'] ?? '';

// 2. Verify old password
if (!password_verify($oldPassword, $storedHash)) {
    echo json_encode(['ok' => false, 'error' => 'Incorrect old password.']);
    exit;
}

// 3. Hash new password
$newHash = password_hash($newPassword, PASSWORD_DEFAULT);

// 4. Update the password
$updateUrl = SUPABASE_URL . '/rest/v1/users?id=eq.' . urlencode($userId);
$updateHeaders = [
    'apikey: ' . SUPABASE_KEY,
    'Authorization: Bearer ' . SUPABASE_KEY,
    'Content-Type: application/json',
    'Prefer: return=minimal'
];
$updatePayload = [
    'password' => $newHash
];

$updateResult = supabase_request('PATCH', $updateUrl, $updateHeaders, json_encode($updatePayload));

if (!$updateResult['ok']) {
    echo json_encode(['ok' => false, 'error' => 'Failed to update database.']);
    exit;
}

// 5. Success
echo json_encode(['ok' => true]);
