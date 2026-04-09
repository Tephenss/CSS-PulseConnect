<?php
declare(strict_types=1);

session_start();

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/supabase.php';
require_once __DIR__ . '/../includes/json.php';
require_once __DIR__ . '/../includes/csrf.php';

// Only admins can reset
$user = require_role(['admin']);
$data = require_post_json();
require_csrf_from_json($data);

$studentId = isset($data['student_id']) ? trim((string) $data['student_id']) : '';

if ($studentId !== '') {
    // Specific Reset: Set section_id to NULL for one student only
    $url = rtrim(SUPABASE_URL, '/') . '/rest/v1/users?id=eq.' . rawurlencode($studentId) . '&role=eq.student&select=id';
    $headers = [
        'apikey: ' . SUPABASE_KEY,
        'Authorization: Bearer ' . SUPABASE_KEY,
        'Content-Type: application/json',
        'Accept: application/json',
        'Prefer: return=representation',
    ];

    $payload = json_encode(['section_id' => null], JSON_UNESCAPED_SLASHES);
    $res = supabase_request('PATCH', $url, $headers, $payload);

    if (!$res['ok']) {
        json_response([
            'ok' => false,
            'error' => build_error($res['body'] ?? null, (int) ($res['status'] ?? 0), $res['error'] ?? null, 'Failed to reset student section'),
        ], 500);
    }

    $rows = json_decode((string) ($res['body'] ?? '[]'), true);
    if (!is_array($rows) || !isset($rows[0])) {
        json_response(['ok' => false, 'error' => 'Student not found or not eligible for reset'], 404);
    }

    json_response(['ok' => true, 'mode' => 'single', 'student_id' => $studentId], 200);
}

// Global Reset: Set section_id to NULL for all students
$url = rtrim(SUPABASE_URL, '/') . '/rest/v1/users?role=eq.student';
$headers = [
    'apikey: ' . SUPABASE_KEY,
    'Authorization: Bearer ' . SUPABASE_KEY,
    'Content-Type: application/json',
    'Prefer: return=minimal',
];

$payload = json_encode(['section_id' => null], JSON_UNESCAPED_SLASHES);
$res = supabase_request('PATCH', $url, $headers, $payload);

if (!$res['ok']) {
    json_response([
        'ok' => false,
        'error' => build_error($res['body'] ?? null, (int) ($res['status'] ?? 0), $res['error'] ?? null, 'Failed to reset sections'),
    ], 500);
}

json_response(['ok' => true, 'mode' => 'global'], 200);
