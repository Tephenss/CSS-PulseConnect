<?php
declare(strict_types=1);
session_start();

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/supabase.php';
require_once __DIR__ . '/../includes/json.php';
require_once __DIR__ . '/../includes/csrf.php';

// Only admins can delete templates
$user = require_role(['admin']);
error_log("Delete API: User role verified.");

$input = require_post_json();
error_log("Delete API: Input parsed: " . print_r($input, true));

// CSRF check
csrf_validate($input['csrf_token'] ?? null);
error_log("Delete API: CSRF validated.");

$template_id = trim((string) ($input['template_id'] ?? ''));
error_log("Delete API: Template ID: " . $template_id);

if (empty($template_id)) {
    json_response(['ok' => false, 'error' => 'Template ID is required.'], 400);
}

$url = rtrim(SUPABASE_URL, '/') . '/rest/v1/certificate_templates?id=eq.' . urlencode($template_id);
error_log("Delete API: Request URL: " . $url);
$headers = [
    'apikey: ' . SUPABASE_KEY,
    'Authorization: Bearer ' . SUPABASE_KEY,
];

// Perform DELETE request to Supabase
$res = supabase_request('DELETE', $url, $headers);
error_log("Delete API: Supabase response: " . print_r($res, true));

if (!$res['ok']) {
    $err = build_error($res['body'], $res['status'], $res['error'], 'Failed to delete certificate template.');
    error_log("Delete API: Error built: " . $err);
    json_response(['ok' => false, 'error' => $err], 500);
}

json_response(['ok' => true]);
