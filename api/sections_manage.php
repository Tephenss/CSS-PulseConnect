<?php
declare(strict_types=1);

session_start();

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/supabase.php';
require_once __DIR__ . '/../includes/json.php';
require_once __DIR__ . '/../includes/csrf.php';

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    // Anyone can GET sections (for registration page)
    $url = rtrim(SUPABASE_URL, '/') . '/rest/v1/sections?select=id,name&status=eq.active&order=name.asc';
    $headers = [
        'Accept: application/json',
        'apikey: ' . SUPABASE_KEY,
        'Authorization: Bearer ' . SUPABASE_KEY,
    ];
    $res = supabase_request('GET', $url, $headers);
    if (!$res['ok']) {
        json_response(['ok' => false, 'error' => 'Failed to fetch sections'], 500);
    }
    json_response(['ok' => true, 'sections' => json_decode((string)$res['body'], true)]);
}

$user = require_role(['admin']);
$data = require_post_json();
require_csrf_from_json($data);

if ($method === 'POST') {
    $action = isset($data['action']) ? (string)$data['action'] : 'create';
    
    if ($action === 'create') {
        $names = [];
        if (isset($data['names']) && is_array($data['names'])) {
            $names = $data['names'];
        } elseif (isset($data['name']) && trim((string)$data['name']) !== '') {
            $names[] = trim((string)$data['name']);
        }

        if (empty($names)) json_response(['ok' => false, 'error' => 'At least one name required'], 400);

        $payloads = [];
        foreach ($names as $n) {
            $payloads[] = ['name' => trim((string)$n)];
        }

        $url = rtrim(SUPABASE_URL, '/') . '/rest/v1/sections?select=id,name';
        $headers = [
            'Content-Type: application/json',
            'Accept: application/json',
            'apikey: ' . SUPABASE_KEY,
            'Authorization: Bearer ' . SUPABASE_KEY,
            'Prefer: return=representation',
        ];

        $res = supabase_request('POST', $url, $headers, json_encode($payloads, JSON_UNESCAPED_SLASHES));
        if (!$res['ok']) {
            json_response(['ok' => false, 'error' => build_error($res['body'] ?? null, (int)($res['status'] ?? 0), $res['error'] ?? null, 'Creation failed')], 500);
        }
        $rows = json_decode((string)$res['body'], true);
        json_response(['ok' => true, 'sections' => $rows ?? []], 200);

    } elseif ($action === 'update') {
        $id = isset($data['id']) ? (string)$data['id'] : '';
        $name = isset($data['name']) ? trim((string)$data['name']) : '';
        if ($id === '' || $name === '') json_response(['ok' => false, 'error' => 'ID and Name required'], 400);

        $payload = ['name' => $name];

        $url = rtrim(SUPABASE_URL, '/') . '/rest/v1/sections?id=eq.' . rawurlencode($id);
        $headers = [
            'Content-Type: application/json',
            'Accept: application/json',
            'apikey: ' . SUPABASE_KEY,
            'Authorization: Bearer ' . SUPABASE_KEY,
            'Prefer: return=representation',
        ];

        $res = supabase_request('PATCH', $url, $headers, json_encode($payload, JSON_UNESCAPED_SLASHES));
        if (!$res['ok']) {
            json_response(['ok' => false, 'error' => 'Update failed.'], 500);
        }
        $rows = json_decode((string)$res['body'], true);
        json_response(['ok' => true, 'section' => $rows[0] ?? null], 200);

    } elseif ($action === 'delete') {
        $id = isset($data['id']) ? (string)$data['id'] : '';
        if ($id === '') json_response(['ok' => false, 'error' => 'ID required'], 400);

        $url = rtrim(SUPABASE_URL, '/') . '/rest/v1/sections?id=eq.' . rawurlencode($id);
        $headers = [
            'Content-Type: application/json',
            'Accept: application/json',
            'apikey: ' . SUPABASE_KEY,
            'Authorization: Bearer ' . SUPABASE_KEY,
        ];
        $payload = ['status' => 'archived'];
        $res = supabase_request('PATCH', $url, $headers, json_encode($payload));
        if (!$res['ok']) {
            json_response(['ok' => false, 'error' => 'Archiving failed.'], 500);
        }
        json_response(['ok' => true], 200);

    } elseif ($action === 'restore') {
        $id = isset($data['id']) ? (string)$data['id'] : '';
        if ($id === '') json_response(['ok' => false, 'error' => 'ID required'], 400);

        $url = rtrim(SUPABASE_URL, '/') . '/rest/v1/sections?id=eq.' . rawurlencode($id);
        $headers = [
            'Content-Type: application/json',
            'Accept: application/json',
            'apikey: ' . SUPABASE_KEY,
            'Authorization: Bearer ' . SUPABASE_KEY,
        ];
        $payload = ['status' => 'active'];
        $res = supabase_request('PATCH', $url, $headers, json_encode($payload));
        if (!$res['ok']) {
            json_response(['ok' => false, 'error' => 'Restore failed.'], 500);
        }
        json_response(['ok' => true], 200);

    } elseif ($action === 'hard_delete') {
        $id = isset($data['id']) ? (string)$data['id'] : '';
        if ($id === '') json_response(['ok' => false, 'error' => 'ID required'], 400);

        $url = rtrim(SUPABASE_URL, '/') . '/rest/v1/sections?id=eq.' . rawurlencode($id);
        $headers = [
            'Accept: application/json',
            'apikey: ' . SUPABASE_KEY,
            'Authorization: Bearer ' . SUPABASE_KEY,
        ];
        $res = supabase_request('DELETE', $url, $headers);
        if (!$res['ok']) {
            json_response(['ok' => false, 'error' => 'Delete failed. Section may be in use.'], 500);
        }
        json_response(['ok' => true], 200);
    }
}

json_response(['ok' => false, 'error' => 'Invalid method'], 405);
