<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/supabase.php';
require_once __DIR__ . '/../includes/json.php';
require_once __DIR__ . '/../includes/mobile_api.php';
require_once __DIR__ . '/../includes/mobile_session.php';
require_once __DIR__ . '/../includes/storage_signed.php';

$data = mobile_api_require_post_json();
mobile_api_validate_key($data);
$sessionUser = mobile_api_require_user($data);
$userId = (string) ($sessionUser['id'] ?? '');

$patch = [];
if (array_key_exists('section_id', $data)) {
    $sectionId = $data['section_id'];
    $patch['section_id'] = ($sectionId === null || $sectionId === '') ? null : (string) $sectionId;
}
if (array_key_exists('photo_url', $data)) {
    $photo = trim((string) ($data['photo_url'] ?? ''));
    if ($photo === '') {
        $patch['photo_url'] = null;
    } else {
        $normalized = storage_normalize_avatar_photo_value($photo);
        $patch['photo_url'] = $normalized !== '' ? $normalized : $photo;
    }
}

if ($patch === []) {
    json_response(['ok' => false, 'error' => 'No profile fields to update.'], 400);
}

$patch['updated_at'] = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DATE_ATOM);

$headers = [
    'Content-Type: application/json',
    'Accept: application/json',
    'apikey: ' . SUPABASE_KEY,
    'Authorization: Bearer ' . SUPABASE_KEY,
    'Prefer: return=representation',
];
$url = rtrim(SUPABASE_URL, '/') . '/rest/v1/' . SUPABASE_TABLE_USERS
    . '?id=eq.' . rawurlencode($userId)
    . '&select=' . rawurlencode(mobile_user_public_fields());
$res = supabase_request('PATCH', $url, $headers, json_encode($patch, JSON_UNESCAPED_SLASHES));
if (!$res['ok']) {
    json_response(['ok' => false, 'error' => 'Failed to update profile.'], 500);
}

$rows = json_decode((string) $res['body'], true);
$user = is_array($rows) && isset($rows[0]) && is_array($rows[0])
    ? mobile_user_strip_secrets($rows[0])
    : null;

json_response(['ok' => true, 'user' => $user], 200);
