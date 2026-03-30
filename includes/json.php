<?php
declare(strict_types=1);

function json_response(array $data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_SLASHES);
    exit;
}

function require_post_json(): array
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
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

function require_csrf_from_json(array $data): void
{
    if (!function_exists('csrf_validate')) {
        return;
    }
    $token = isset($data['csrf_token']) ? (string) $data['csrf_token'] : null;
    csrf_validate($token);
}

