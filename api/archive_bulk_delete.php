<?php
declare(strict_types=1);

session_start();

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/supabase.php';
require_once __DIR__ . '/../includes/json.php';
require_once __DIR__ . '/../includes/csrf.php';

$user = require_role(['admin']);
$data = require_post_json();
require_csrf_from_json($data);

$scope = strtolower(trim((string) ($data['scope'] ?? '')));
$validScopes = ['events', 'rejected', 'teachers', 'students', 'sections'];
if (!in_array($scope, $validScopes, true)) {
    json_response(['ok' => false, 'error' => 'Invalid scope.'], 400);
}

$authHeaders = [
    'Accept: application/json',
    'apikey: ' . SUPABASE_KEY,
    'Authorization: Bearer ' . SUPABASE_KEY,
];

$writeHeaders = [
    'Content-Type: application/json',
    'Accept: application/json',
    'apikey: ' . SUPABASE_KEY,
    'Authorization: Bearer ' . SUPABASE_KEY,
    'Prefer: return=representation',
];

function build_error_from_response(array $res, string $fallback): string
{
    return build_error(
        $res['body'] ?? null,
        (int) ($res['status'] ?? 0),
        $res['error'] ?? null,
        $fallback
    );
}

function encode_in_list(array $ids): string
{
    return implode(',', array_map('rawurlencode', $ids));
}

if ($scope === 'events' || $scope === 'rejected') {
    $fetchUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/events'
        . '?select=id,description&status=eq.archived';
    $fetchRes = supabase_request('GET', $fetchUrl, $authHeaders);
    if (!$fetchRes['ok']) {
        json_response(['ok' => false, 'error' => build_error_from_response($fetchRes, 'Failed to fetch archived events')], 500);
    }

    $rows = json_decode((string) $fetchRes['body'], true);
    $rows = is_array($rows) ? $rows : [];
    $ids = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $id = trim((string) ($row['id'] ?? ''));
        if ($id === '') {
            continue;
        }
        $isRejected = str_contains((string) ($row['description'] ?? ''), '[REJECT_REASON:');
        if ($scope === 'rejected' && !$isRejected) {
            continue;
        }
        if ($scope === 'events' && $isRejected) {
            continue;
        }
        $ids[] = $id;
    }

    if (count($ids) === 0) {
        json_response(['ok' => true, 'deleted_count' => 0]);
    }

    $deleteUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/events'
        . '?id=in.(' . encode_in_list($ids) . ')';
    $deleteRes = supabase_request('DELETE', $deleteUrl, $writeHeaders);
    if (!$deleteRes['ok']) {
        json_response(['ok' => false, 'error' => build_error_from_response($deleteRes, 'Failed to delete archived events')], 500);
    }

    json_response(['ok' => true, 'deleted_count' => count($ids)]);
}

if ($scope === 'sections') {
    $fetchUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/sections?select=id&status=eq.archived';
    $fetchRes = supabase_request('GET', $fetchUrl, $authHeaders);
    if (!$fetchRes['ok']) {
        json_response(['ok' => false, 'error' => build_error_from_response($fetchRes, 'Failed to fetch archived sections')], 500);
    }
    $rows = json_decode((string) $fetchRes['body'], true);
    $rows = is_array($rows) ? $rows : [];
    $ids = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $id = trim((string) ($row['id'] ?? ''));
        if ($id !== '') {
            $ids[] = $id;
        }
    }
    if (count($ids) === 0) {
        json_response(['ok' => true, 'deleted_count' => 0]);
    }
    $deleteUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/sections'
        . '?id=in.(' . encode_in_list($ids) . ')';
    $deleteRes = supabase_request('DELETE', $deleteUrl, $writeHeaders);
    if (!$deleteRes['ok']) {
        json_response(['ok' => false, 'error' => build_error_from_response($deleteRes, 'Failed to delete archived sections')], 500);
    }
    json_response(['ok' => true, 'deleted_count' => count($ids)]);
}

$targetRole = $scope === 'teachers' ? 'teacher' : 'student';
$fetchUsersUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/users'
    . '?select=id&status=eq.archived&role=eq.' . rawurlencode($targetRole);
$fetchUsersRes = supabase_request('GET', $fetchUsersUrl, $authHeaders);
if (!$fetchUsersRes['ok']) {
    $fetchErrorText = (string) ($fetchUsersRes['body'] ?? '');
    $statusColumnMissing = str_contains($fetchErrorText, 'users.status') ||
        str_contains($fetchErrorText, 'status does not exist') ||
        str_contains($fetchErrorText, "Could not find the 'status' column");
    if ($statusColumnMissing) {
        // Current schema has no users.status archive marker; treat as no archived users.
        json_response(['ok' => true, 'deleted_count' => 0]);
    }
    json_response(['ok' => false, 'error' => build_error_from_response($fetchUsersRes, 'Failed to fetch archived users')], 500);
}

$userRows = json_decode((string) $fetchUsersRes['body'], true);
$userRows = is_array($userRows) ? $userRows : [];
$userIds = [];
foreach ($userRows as $row) {
    if (!is_array($row)) {
        continue;
    }
    $id = trim((string) ($row['id'] ?? ''));
    if ($id !== '') {
        $userIds[] = $id;
    }
}

if (count($userIds) === 0) {
    json_response(['ok' => true, 'deleted_count' => 0]);
}

$deleteUsersUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/users'
    . '?id=in.(' . encode_in_list($userIds) . ')';
$deleteUsersRes = supabase_request('DELETE', $deleteUsersUrl, $writeHeaders);
if (!$deleteUsersRes['ok']) {
    json_response(['ok' => false, 'error' => build_error_from_response($deleteUsersRes, 'Failed to delete archived users')], 500);
}

json_response(['ok' => true, 'deleted_count' => count($userIds)]);

