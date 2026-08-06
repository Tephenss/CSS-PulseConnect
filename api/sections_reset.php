<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/session.php';
session_bootstrap();

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/supabase.php';
require_once __DIR__ . '/../includes/json.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/student_roster.php';

// Only admins can reset
$user = require_role(['admin']);
$data = require_post_json();
require_csrf_from_json($data);

$studentId = isset($data['student_id']) ? trim((string) $data['student_id']) : '';
$rosterId = isset($data['roster_id']) ? trim((string) $data['roster_id']) : '';

$headers = [
    'apikey: ' . SUPABASE_KEY,
    'Authorization: Bearer ' . SUPABASE_KEY,
    'Content-Type: application/json',
    'Accept: application/json',
    'Prefer: return=representation',
];

if ($studentId !== '' || $rosterId !== '') {
    $clearedUser = false;
    $clearedRoster = false;

    if ($studentId !== '') {
        $url = rtrim(SUPABASE_URL, '/') . '/rest/v1/users?id=eq.' . rawurlencode($studentId) . '&role=eq.student&select=id';
        $payload = json_encode(['section_id' => null], JSON_UNESCAPED_SLASHES);
        $res = supabase_request('PATCH', $url, $headers, $payload);
        if (!$res['ok']) {
            json_response([
                'ok' => false,
                'error' => build_error($res['body'] ?? null, (int) ($res['status'] ?? 0), $res['error'] ?? null, 'Failed to reset student section'),
            ], 500);
        }
        $rows = json_decode((string) ($res['body'] ?? '[]'), true);
        $clearedUser = is_array($rows) && isset($rows[0]);
    }

    if ($rosterId !== '') {
        $rosterUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/student_roster?id=eq.' . rawurlencode($rosterId) . '&select=id';
        $rosterRes = supabase_request(
            'PATCH',
            $rosterUrl,
            student_roster_supabase_headers(true),
            json_encode(['section_id' => null, 'updated_at' => gmdate('c')], JSON_UNESCAPED_SLASHES)
        );
        if ($rosterRes['ok']) {
            $clearedRoster = true;
        }
    } elseif ($studentId !== '' && $clearedUser) {
        // Also clear roster rows linked to this user in this assignment.
        $byUserUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/student_roster?user_id=eq.' . rawurlencode($studentId);
        supabase_request(
            'PATCH',
            $byUserUrl,
            student_roster_supabase_headers(true),
            json_encode(['section_id' => null, 'updated_at' => gmdate('c')], JSON_UNESCAPED_SLASHES)
        );
        $clearedRoster = true;
    }

    if (!$clearedUser && !$clearedRoster) {
        json_response(['ok' => false, 'error' => 'Student not found or not eligible for reset'], 404);
    }

    json_response([
        'ok' => true,
        'mode' => 'single',
        'student_id' => $studentId,
        'roster_id' => $rosterId,
    ], 200);
}

// Global Reset: clear section_id for all students + active roster rows.
$url = rtrim(SUPABASE_URL, '/') . '/rest/v1/users?role=eq.student';
$globalHeaders = [
    'apikey: ' . SUPABASE_KEY,
    'Authorization: Bearer ' . SUPABASE_KEY,
    'Content-Type: application/json',
    'Prefer: return=minimal',
];

$payload = json_encode(['section_id' => null], JSON_UNESCAPED_SLASHES);
$res = supabase_request('PATCH', $url, $globalHeaders, $payload);

if (!$res['ok']) {
    json_response([
        'ok' => false,
        'error' => build_error($res['body'] ?? null, (int) ($res['status'] ?? 0), $res['error'] ?? null, 'Failed to reset sections'),
    ], 500);
}

supabase_request(
    'PATCH',
    rtrim(SUPABASE_URL, '/') . '/rest/v1/student_roster?section_id=not.is.null',
    student_roster_supabase_headers(true),
    json_encode(['section_id' => null, 'updated_at' => gmdate('c')], JSON_UNESCAPED_SLASHES)
);

json_response(['ok' => true, 'mode' => 'global'], 200);
