<?php
declare(strict_types=1);

function json_response(array $data, int $status = 200): void
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
    if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
        $flags |= JSON_INVALID_UTF8_SUBSTITUTE;
    }
    $json = json_encode($data, $flags);
    if (!is_string($json) || $json === '') {
        $json = '{"ok":false,"error":"Server encoding error."}';
        if ($status < 400) {
            http_response_code(500);
        }
    }
    echo $json;
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

