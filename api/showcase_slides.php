<?php
declare(strict_types=1);

/**
 * Public read of active showcase slides (marketing images only — no PII).
 * Served via PHP BFF; table remains locked down in Supabase.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/supabase.php';
require_once __DIR__ . '/../includes/json.php';
require_once __DIR__ . '/../includes/api_rate_limit.php';
require_once __DIR__ . '/../includes/showcase_lib.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    json_response(['ok' => false, 'error' => 'Method not allowed.'], 405);
}

$clientIp = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
if (!api_rate_limit_allow('showcase_slides:' . $clientIp, 120, 60)) {
    json_response(['ok' => false, 'error' => 'Too many requests.'], 429);
}

$result = showcase_fetch_active_slides();
if (!$result['ok']) {
    json_response([
        'ok' => true,
        'slides' => showcase_default_fallback_slides(),
        'version' => 'fallback',
        'fallback' => true,
    ]);
}

$slides = $result['slides'];
$version = (string) ($result['version'] ?? '');
$usingFallback = false;
if ($slides === []) {
    $slides = showcase_default_fallback_slides();
    $version = 'fallback';
    $usingFallback = true;
}

header('Cache-Control: public, max-age=300');
if ($version !== '' && $version !== 'fallback') {
    header('ETag: "' . $version . '"');
}

$ifNoneMatch = trim((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? ''), '"');
if ($ifNoneMatch !== '' && hash_equals($version, $ifNoneMatch)) {
    http_response_code(304);
    exit;
}

json_response([
    'ok' => true,
    'slides' => $slides,
    'version' => $version,
    'fallback' => $usingFallback,
]);
