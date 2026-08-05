<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/session.php';
session_bootstrap();

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/supabase.php';
require_once __DIR__ . '/../includes/json.php';
require_once __DIR__ . '/../includes/csrf.php';

$user = require_role(['teacher']);
$input = require_post_json();
csrf_validate($input['csrf_token'] ?? null);

$template_id = trim((string) ($input['template_id'] ?? ''));
$template_scope = strtolower(trim((string) ($input['template_scope'] ?? 'library')));

if ($template_id === '') {
    json_response(['ok' => false, 'error' => 'Template ID is required.'], 400);
}

if (!in_array($template_scope, ['library', 'event', 'session'], true)) {
    $template_scope = 'library';
}

$userId = trim((string) ($user['id'] ?? ''));
$headers = [
    'Accept: application/json',
    'apikey: ' . SUPABASE_KEY,
    'Authorization: Bearer ' . SUPABASE_KEY,
];

if ($template_scope === 'session') {
    $sessUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/event_session_certificate_templates'
        . '?select=id,created_by,session_id,event_sessions(event_id)'
        . '&id=eq.' . rawurlencode($template_id)
        . '&limit=1';
    $sessRes = supabase_request('GET', $sessUrl, $headers);
    $sessRows = json_decode((string) ($sessRes['body'] ?? ''), true);
    $row = is_array($sessRows) && isset($sessRows[0]) && is_array($sessRows[0]) ? $sessRows[0] : null;
    if (!$row) {
        json_response(['ok' => false, 'error' => 'Template not found.'], 404);
    }
    $createdBy = trim((string) ($row['created_by'] ?? ''));
    $eventId = '';
    $nested = $row['event_sessions'] ?? null;
    if (is_array($nested)) {
        $eventId = trim((string) ($nested['event_id'] ?? ''));
    }
    $allowed = $createdBy === $userId;
    if (!$allowed && $eventId !== '') {
        $eventUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/events'
            . '?select=id,created_by&id=eq.' . rawurlencode($eventId) . '&limit=1';
        $eventRes = supabase_request('GET', $eventUrl, $headers);
        $eventRows = json_decode((string) ($eventRes['body'] ?? ''), true);
        $event = is_array($eventRows) && isset($eventRows[0]) ? $eventRows[0] : null;
        $allowed = is_array($event) && (string) ($event['created_by'] ?? '') === $userId;
    }
    if (!$allowed) {
        json_response(['ok' => false, 'error' => 'You do not have permission to delete this template.'], 403);
    }
    $url = rtrim(SUPABASE_URL, '/') . '/rest/v1/event_session_certificate_templates?id=eq.' . rawurlencode($template_id);
} else {
    $tplUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/certificate_templates'
        . '?select=id,created_by,event_id&id=eq.' . rawurlencode($template_id) . '&limit=1';
    $tplRes = supabase_request('GET', $tplUrl, $headers);
    $tplRows = json_decode((string) ($tplRes['body'] ?? ''), true);
    $row = is_array($tplRows) && isset($tplRows[0]) && is_array($tplRows[0]) ? $tplRows[0] : null;
    if (!$row) {
        json_response(['ok' => false, 'error' => 'Template not found.'], 404);
    }
    $createdBy = trim((string) ($row['created_by'] ?? ''));
    $eventId = trim((string) ($row['event_id'] ?? ''));
    $allowed = $createdBy === $userId;
    if (!$allowed && $eventId !== '') {
        $eventUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/events'
            . '?select=id,created_by&id=eq.' . rawurlencode($eventId) . '&limit=1';
        $eventRes = supabase_request('GET', $eventUrl, $headers);
        $eventRows = json_decode((string) ($eventRes['body'] ?? ''), true);
        $event = is_array($eventRows) && isset($eventRows[0]) ? $eventRows[0] : null;
        $allowed = is_array($event) && (string) ($event['created_by'] ?? '') === $userId;
    }
    if (!$allowed) {
        json_response(['ok' => false, 'error' => 'You do not have permission to delete this template.'], 403);
    }
    $url = rtrim(SUPABASE_URL, '/') . '/rest/v1/certificate_templates?id=eq.' . rawurlencode($template_id);
}

$res = supabase_request('DELETE', $url, $headers);
if (!$res['ok']) {
    $err = build_error($res['body'], $res['status'], $res['error'], 'Failed to delete certificate template.');
    json_response(['ok' => false, 'error' => $err], 500);
}

json_response(['ok' => true]);
