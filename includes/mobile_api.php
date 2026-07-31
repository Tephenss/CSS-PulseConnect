<?php
declare(strict_types=1);

function mobile_api_send_cors_headers(): void
{
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Mobile-Api-Key, X-Mobile-Push-Key, X-Mobile-Session');
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
    // Fail closed: never allow unauthenticated mobile API access in production/misconfig.
    if ($expected === '') {
        json_response(['ok' => false, 'error' => 'Mobile API key is not configured on the server.'], 503);
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
        // Single Prefer header — duplicate Prefer lines are unreliable with cURL.
        'Prefer: resolution=merge-duplicates,return=minimal',
    ];
}

/**
 * Teachers assigned to an event (any assignment) or the event creator may view rosters.
 */
function mobile_teacher_can_access_event(string $eventId, string $teacherId, array $headers): bool
{
    $eventId = trim($eventId);
    $teacherId = trim($teacherId);
    if ($eventId === '' || $teacherId === '') {
        return false;
    }

    $eventUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/events'
        . '?select=id,created_by&id=eq.' . rawurlencode($eventId) . '&limit=1';
    $eventRes = supabase_request('GET', $eventUrl, $headers);
    $eventRows = json_decode((string) ($eventRes['body'] ?? ''), true);
    $event = is_array($eventRows) && isset($eventRows[0]) ? $eventRows[0] : null;
    if (is_array($event) && (string) ($event['created_by'] ?? '') === $teacherId) {
        return true;
    }

    $assignUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/event_teacher_assignments'
        . '?select=id&event_id=eq.' . rawurlencode($eventId)
        . '&teacher_id=eq.' . rawurlencode($teacherId)
        . '&limit=1';
    $assignRes = supabase_request('GET', $assignUrl, $headers);
    $assignRows = json_decode((string) ($assignRes['body'] ?? ''), true);

    return is_array($assignRows) && count($assignRows) > 0;
}
