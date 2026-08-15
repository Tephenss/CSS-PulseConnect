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

function mobile_api_install_json_error_trap(): void
{
    if (ob_get_level() === 0) {
        ob_start();
    }
    set_exception_handler(static function (Throwable $e): void {
        json_response(['ok' => false, 'error' => 'Could not process the PDF. Please try again.'], 500);
    });
    register_shutdown_function(static function (): void {
        $err = error_get_last();
        if (!is_array($err)) {
            return;
        }
        $fatal = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
        if (!in_array((int) ($err['type'] ?? 0), $fatal, true)) {
            return;
        }
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
        }
        echo '{"ok":false,"error":"Could not process the PDF. Please try again."}';
    });
}

function mobile_api_request_content_type(): string
{
    $ct = (string) ($_SERVER['CONTENT_TYPE'] ?? '');
    if ($ct === '') {
        $ct = (string) ($_SERVER['HTTP_CONTENT_TYPE'] ?? '');
    }
    return strtolower(trim($ct));
}

function mobile_api_is_multipart(): bool
{
    $ct = mobile_api_request_content_type();
    if (str_contains($ct, 'multipart/form-data')) {
        return true;
    }
    // Some hosts rewrite Content-Type; uploaded files still mean multipart.
    return isset($_FILES) && is_array($_FILES) && $_FILES !== [];
}

function mobile_api_require_post_json(): array
{
    mobile_api_handle_preflight();

    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        json_response(['ok' => false, 'error' => 'Method not allowed'], 405);
    }

    if (mobile_api_is_multipart()) {
        json_response([
            'ok' => false,
            'error' => 'This endpoint expects JSON. Redeploy the latest PHP register API for PDF signup.',
        ], 400);
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

/**
 * POST fields from JSON or multipart (for PDF uploads).
 *
 * @return array<string,mixed>
 */
function mobile_api_require_post_fields(): array
{
    mobile_api_handle_preflight();

    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        json_response(['ok' => false, 'error' => 'Method not allowed'], 405);
    }

    if (mobile_api_is_multipart()) {
        $fields = [];
        foreach ($_POST as $key => $value) {
            if (is_string($key) && (is_string($value) || is_numeric($value))) {
                $fields[$key] = (string) $value;
            }
        }
        // Soft fail with a clear message when PHP dropped the multipart body
        // (common when post_max_size / upload_max_filesize is too low).
        if ($fields === [] && (!isset($_FILES) || $_FILES === [])) {
            $contentLength = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
            if ($contentLength > 0) {
                json_response([
                    'ok' => false,
                    'error' => 'Upload was rejected by the server (file may be too large). Try a smaller PDF or ask admin to raise PHP upload limits.',
                ], 400);
            }
        }
        return $fields;
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
function mobile_api_is_event_creator(string $eventId, string $userId, array $headers): bool
{
    $eventId = trim($eventId);
    $userId = trim($userId);
    if ($eventId === '' || $userId === '') {
        return false;
    }
    $url = rtrim(SUPABASE_URL, '/') . '/rest/v1/events'
        . '?select=id,created_by&id=eq.' . rawurlencode($eventId) . '&limit=1';
    $res = supabase_request('GET', $url, $headers);
    $rows = json_decode((string) ($res['body'] ?? ''), true);
    $event = is_array($rows) && isset($rows[0]) ? $rows[0] : null;
    return is_array($event) && (string) ($event['created_by'] ?? '') === $userId;
}

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

/**
 * Assigned student scanners may cache the participant ticket roster offline.
 */
function mobile_student_is_event_assistant(string $eventId, string $studentId, array $headers): bool
{
    $eventId = trim($eventId);
    $studentId = trim($studentId);
    if ($eventId === '' || $studentId === '') {
        return false;
    }

    $url = rtrim(SUPABASE_URL, '/') . '/rest/v1/event_assistants'
        . '?select=id&event_id=eq.' . rawurlencode($eventId)
        . '&student_id=eq.' . rawurlencode($studentId)
        . '&allow_scan=eq.true&limit=1';
    $res = supabase_request('GET', $url, $headers);
    $rows = json_decode((string) ($res['body'] ?? ''), true);
    return is_array($rows) && count($rows) > 0;
}
