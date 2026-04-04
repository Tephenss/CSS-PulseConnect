<?php
declare(strict_types=1);

session_start();
header('Content-Type: application/json');

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/supabase.php';

// Only admins can reset
$user = require_role(['admin']);
if (!$user) {
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit;
}

// Verify CSRF
$rawInput = file_get_contents('php://input');
$data = json_decode($rawInput, true);
$clientCsrf = $data['csrf_token'] ?? '';
$sessionCsrf = $_SESSION['csrf_token'] ?? '';

if (empty($clientCsrf) || empty($sessionCsrf) || !hash_equals($sessionCsrf, $clientCsrf)) {
    echo json_encode(['ok' => false, 'error' => 'Invalid CSRF token. Please refresh the page.']);
    exit;
}

// Global Reset: Set section_id to NULL for all students
$url = rtrim(SUPABASE_URL, '/') . '/rest/v1/users?role=eq.student';
$headers = [
    'apikey: ' . SUPABASE_KEY,
    'Authorization: Bearer ' . SUPABASE_KEY,
    'Content-Type: application/json',
    'Prefer: return=minimal'
];

$payload = json_encode(['section_id' => null]);
$res = supabase_request('PATCH', $url, $headers, $payload);

if ($res['ok']) {
    echo json_encode(['ok' => true]);
} else {
    echo json_encode(['ok' => false, 'error' => 'Failed to reset sections', 'details' => $res['body']]);
}
