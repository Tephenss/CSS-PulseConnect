<?php
declare(strict_types=1);

function mobile_api_send_cors_headers(): void
{
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, X-Mobile-Api-Key, X-Mobile-Push-Key');
}

function mobile_api_handle_preflight(): void
{
    mobile_api_send_cors_headers();
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
}

function mobile_api_require_post_json(): array
{
    mobile_api_handle_preflight();

    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        json_response(['ok' => false, 'error' => 'Method not allowed'], 405);
    }

    $raw = file_get_contents('php://input');
    if (!is_string($raw) || trim($raw) === '') {
        json_response(['ok' => false, 'error' => 'Empty body'], 400);
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        json_response(['ok' => false, 'error' => 'Invalid JSON'], 400);
    }

    return $decoded;
}

function mobile_api_validate_key(array $data): void
{
    $expected = defined('MOBILE_PUSH_API_KEY') ? trim((string) MOBILE_PUSH_API_KEY) : '';
    if ($expected === '') {
        return;
    }

    $provided = '';
    if (isset($_SERVER['HTTP_X_MOBILE_API_KEY']) && is_string($_SERVER['HTTP_X_MOBILE_API_KEY'])) {
        $provided = trim($_SERVER['HTTP_X_MOBILE_API_KEY']);
    } elseif (isset($_SERVER['HTTP_X_MOBILE_PUSH_KEY']) && is_string($_SERVER['HTTP_X_MOBILE_PUSH_KEY'])) {
        $provided = trim($_SERVER['HTTP_X_MOBILE_PUSH_KEY']);
    }

    if ($provided === '') {
        $provided = trim((string) ($data['mobile_api_key'] ?? $data['api_key'] ?? ''));
    }

    if ($provided === '' || !hash_equals($expected, $provided)) {
        json_response(['ok' => false, 'error' => 'Unauthorized mobile API request.'], 401);
    }
}

function mobile_api_supabase_headers(): array
{
    return [
        'Accept: application/json',
        'apikey: ' . SUPABASE_KEY,
        'Authorization: Bearer ' . SUPABASE_KEY,
    ];
}

function mobile_api_supabase_write_headers(): array
{
    return [
        'Content-Type: application/json',
        'Accept: application/json',
        'apikey: ' . SUPABASE_KEY,
        'Authorization: Bearer ' . SUPABASE_KEY,
        'Prefer: return=minimal',
        'Prefer: resolution=merge-duplicates',
    ];
}
