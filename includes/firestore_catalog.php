<?php
declare(strict_types=1);

require_once __DIR__ . '/curl_ssl.php';
require_once __DIR__ . '/api_cache.php';

/**
 * Firestore public catalog assist (non-authoritative).
 * Paths (even segment counts):
 *   public_catalog_events/{id}
 *   public_catalog_meta/signals
 */

function firestore_service_account(): ?array
{
    static $cached = null;
    if ($cached !== null) {
        return $cached === false ? null : $cached;
    }

    $paths = [
        __DIR__ . '/service-account.json',
        __DIR__ . '/fcm-credentials.php',
        dirname(__DIR__) . '/api/service-account.json',
    ];

    foreach ($paths as $path) {
        if (!is_file($path)) {
            continue;
        }
        if (str_ends_with($path, '.php')) {
            $keyData = require $path;
        } else {
            $raw = @file_get_contents($path);
            $keyData = is_string($raw) ? json_decode($raw, true) : null;
        }
        if (is_array($keyData)
            && isset($keyData['client_email'], $keyData['private_key'], $keyData['project_id'])) {
            $cached = $keyData;
            return $cached;
        }
    }

    $cached = false;
    return null;
}

function firestore_access_token(): ?string
{
    $cached = api_cache_read('firestore_oauth_token', 3000);
    if (is_array($cached) && !empty($cached['token'])) {
        return (string) $cached['token'];
    }

    $keyData = firestore_service_account();
    if ($keyData === null) {
        return null;
    }

    $header = json_encode(['alg' => 'RS256', 'typ' => 'JWT']);
    $now = time();
    $payload = json_encode([
        'iss' => $keyData['client_email'],
        'scope' => 'https://www.googleapis.com/auth/datastore',
        'aud' => 'https://oauth2.googleapis.com/token',
        'exp' => $now + 3600,
        'iat' => $now,
    ]);

    $base64UrlHeader = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode((string) $header));
    $base64UrlPayload = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode((string) $payload));

    $signature = '';
    $ok = openssl_sign(
        $base64UrlHeader . '.' . $base64UrlPayload,
        $signature,
        (string) $keyData['private_key'],
        'sha256WithRSAEncryption'
    );
    if (!$ok) {
        return null;
    }

    $base64UrlSignature = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($signature));
    $jwt = $base64UrlHeader . '.' . $base64UrlPayload . '.' . $base64UrlSignature;

    $ch = curl_init('https://oauth2.googleapis.com/token');
    if ($ch === false) {
        return null;
    }
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_POSTFIELDS => http_build_query([
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion' => $jwt,
        ]),
    ]);
    apply_curl_ssl_policy($ch);
    $response = curl_exec($ch);
    curl_close($ch);
    if (!is_string($response) || $response === '') {
        return null;
    }
    $data = json_decode($response, true);
    $token = is_array($data) ? trim((string) ($data['access_token'] ?? '')) : '';
    if ($token === '') {
        return null;
    }

    api_cache_write('firestore_oauth_token', ['token' => $token]);
    return $token;
}

function firestore_project_id(): ?string
{
    $key = firestore_service_account();
    if ($key === null) {
        return null;
    }
    $id = trim((string) ($key['project_id'] ?? ''));
    return $id !== '' ? $id : null;
}

function firestore_doc_url(string $relativePath): ?string
{
    $projectId = firestore_project_id();
    if ($projectId === null) {
        return null;
    }
    $relativePath = trim($relativePath, '/');
    return 'https://firestore.googleapis.com/v1/projects/'
        . rawurlencode($projectId)
        . '/databases/(default)/documents/'
        . $relativePath;
}

/**
 * @param mixed $value
 * @return array<string, mixed>
 */
function firestore_encode_value($value): array
{
    if ($value === null) {
        return ['nullValue' => null];
    }
    if (is_bool($value)) {
        return ['booleanValue' => $value];
    }
    if (is_int($value)) {
        return ['integerValue' => (string) $value];
    }
    if (is_float($value)) {
        return ['doubleValue' => $value];
    }
    if (is_array($value)) {
        $isList = array_keys($value) === range(0, count($value) - 1);
        if ($isList) {
            return [
                'arrayValue' => [
                    'values' => array_map('firestore_encode_value', $value),
                ],
            ];
        }
        $fields = [];
        foreach ($value as $k => $v) {
            $fields[(string) $k] = firestore_encode_value($v);
        }
        return ['mapValue' => ['fields' => $fields]];
    }

    return ['stringValue' => (string) $value];
}

/**
 * @param array<string, mixed> $fields
 * @return array<string, mixed>
 */
function firestore_encode_fields(array $fields): array
{
    $out = [];
    foreach ($fields as $key => $value) {
        $out[(string) $key] = firestore_encode_value($value);
    }
    return $out;
}

/**
 * @param array<string, mixed>|null $value
 * @return mixed
 */
function firestore_decode_value(?array $value)
{
    if (!is_array($value)) {
        return null;
    }
    if (array_key_exists('stringValue', $value)) {
        return (string) $value['stringValue'];
    }
    if (array_key_exists('integerValue', $value)) {
        return (int) $value['integerValue'];
    }
    if (array_key_exists('doubleValue', $value)) {
        return (float) $value['doubleValue'];
    }
    if (array_key_exists('booleanValue', $value)) {
        return (bool) $value['booleanValue'];
    }
    if (array_key_exists('nullValue', $value)) {
        return null;
    }
    if (isset($value['mapValue']['fields']) && is_array($value['mapValue']['fields'])) {
        return firestore_decode_fields($value['mapValue']['fields']);
    }
    if (isset($value['arrayValue']['values']) && is_array($value['arrayValue']['values'])) {
        return array_map(
            static fn($v) => firestore_decode_value(is_array($v) ? $v : null),
            $value['arrayValue']['values']
        );
    }
    return null;
}

/**
 * @param array<string, mixed> $fields
 * @return array<string, mixed>
 */
function firestore_decode_fields(array $fields): array
{
    $out = [];
    foreach ($fields as $key => $value) {
        $out[(string) $key] = firestore_decode_value(is_array($value) ? $value : null);
    }
    return $out;
}

/**
 * @return array{ok:bool,status:int,body:?string,error:?string}
 */
function firestore_request(string $method, string $url, ?array $jsonBody = null): array
{
    $token = firestore_access_token();
    if ($token === null) {
        return ['ok' => false, 'status' => 0, 'body' => null, 'error' => 'Missing Firebase credentials'];
    }

    $ch = curl_init($url);
    if ($ch === false) {
        return ['ok' => false, 'status' => 0, 'body' => null, 'error' => 'curl init failed'];
    }

    $headers = [
        'Authorization: Bearer ' . $token,
        'Accept: application/json',
        'Content-Type: application/json',
    ];
    $options = [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 25,
    ];
    if ($jsonBody !== null) {
        $options[CURLOPT_POSTFIELDS] = json_encode($jsonBody, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
    curl_setopt_array($ch, $options);
    apply_curl_ssl_policy($ch);

    $body = curl_exec($ch);
    $err = curl_error($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($body === false) {
        return ['ok' => false, 'status' => $status, 'body' => null, 'error' => $err ?: 'request failed'];
    }

    return [
        'ok' => $status >= 200 && $status < 300,
        'status' => $status,
        'body' => is_string($body) ? $body : null,
        'error' => null,
    ];
}

/**
 * @param array<string, mixed> $event
 * @return array<string, mixed>
 */
function firestore_catalog_public_fields(array $event): array
{
    return [
        'id' => trim((string) ($event['id'] ?? '')),
        'title' => (string) ($event['title'] ?? ''),
        'description' => (string) ($event['description'] ?? ''),
        'location' => (string) ($event['location'] ?? ''),
        'start_at' => (string) ($event['start_at'] ?? ''),
        'end_at' => (string) ($event['end_at'] ?? ''),
        'status' => strtolower(trim((string) ($event['status'] ?? 'published'))),
        'cover_image_url' => (string) ($event['cover_image_url'] ?? ''),
        'event_type' => (string) ($event['event_type'] ?? ''),
        'event_for' => (string) ($event['event_for'] ?? 'All'),
        'updated_at' => (string) ($event['updated_at'] ?? gmdate('c')),
    ];
}

function firestore_catalog_bump_signals(): void
{
    $url = firestore_doc_url('public_catalog_meta/signals');
    if ($url === null) {
        return;
    }

    $revision = (int) (time());
    $existing = firestore_request('GET', $url);
    if ($existing['ok']) {
        $decoded = json_decode((string) ($existing['body'] ?? ''), true);
        if (is_array($decoded) && isset($decoded['fields']) && is_array($decoded['fields'])) {
            $fields = firestore_decode_fields($decoded['fields']);
            $prev = (int) ($fields['events_revision'] ?? 0);
            if ($prev >= $revision) {
                $revision = $prev + 1;
            }
        }
    }

    $mask = 'updateMask.fieldPaths=events_revision&updateMask.fieldPaths=updated_at';
    firestore_request('PATCH', $url . '?' . $mask, [
        'fields' => firestore_encode_fields([
            'events_revision' => $revision,
            'updated_at' => gmdate('c'),
        ]),
    ]);
}

/**
 * Upsert published event into catalog, or remove when not published.
 * Fail-open: never throws to callers.
 *
 * @param array<string, mixed> $event
 */
function firestore_catalog_sync_event(array $event, bool $bumpSignals = true): bool
{
    try {
        $eventId = trim((string) ($event['id'] ?? ''));
        if ($eventId === '') {
            return false;
        }

        $status = strtolower(trim((string) ($event['status'] ?? '')));
        $docUrl = firestore_doc_url('public_catalog_events/' . rawurlencode($eventId));
        if ($docUrl === null) {
            return false;
        }

        if ($status !== 'published') {
            firestore_request('DELETE', $docUrl);
            if ($bumpSignals) {
                firestore_catalog_bump_signals();
            }
            return true;
        }

        $fields = firestore_catalog_public_fields($event);
        $maskParts = [];
        foreach (array_keys($fields) as $key) {
            $maskParts[] = 'updateMask.fieldPaths=' . rawurlencode((string) $key);
        }
        $res = firestore_request('PATCH', $docUrl . '?' . implode('&', $maskParts), [
            'fields' => firestore_encode_fields($fields),
        ]);
        if (!$res['ok']) {
            error_log('firestore_catalog_sync_event failed: HTTP ' . (int) ($res['status'] ?? 0));
            return false;
        }
        if ($bumpSignals) {
            firestore_catalog_bump_signals();
        }
        return true;
    } catch (Throwable $e) {
        error_log('firestore_catalog_sync_event exception: ' . $e->getMessage());
        return false;
    }
}

function firestore_catalog_remove_event(string $eventId): bool
{
    $eventId = trim($eventId);
    if ($eventId === '') {
        return false;
    }
    try {
        $docUrl = firestore_doc_url('public_catalog_events/' . rawurlencode($eventId));
        if ($docUrl === null) {
            return false;
        }
        firestore_request('DELETE', $docUrl);
        firestore_catalog_bump_signals();
        return true;
    } catch (Throwable $e) {
        error_log('firestore_catalog_remove_event exception: ' . $e->getMessage());
        return false;
    }
}

/**
 * @return list<array<string, mixed>>
 */
function firestore_catalog_list_events(int $limit = 60): array
{
    $projectId = firestore_project_id();
    $token = firestore_access_token();
    if ($projectId === null || $token === null) {
        return [];
    }

    $url = 'https://firestore.googleapis.com/v1/projects/'
        . rawurlencode($projectId)
        . '/databases/(default)/documents/public_catalog_events?pageSize='
        . max(1, min(100, $limit));

    $res = firestore_request('GET', $url);
    if (!$res['ok']) {
        return [];
    }

    $decoded = json_decode((string) ($res['body'] ?? ''), true);
    if (!is_array($decoded) || !isset($decoded['documents']) || !is_array($decoded['documents'])) {
        return [];
    }

    $events = [];
    foreach ($decoded['documents'] as $doc) {
        if (!is_array($doc) || !isset($doc['fields']) || !is_array($doc['fields'])) {
            continue;
        }
        $row = firestore_decode_fields($doc['fields']);
        if (trim((string) ($row['id'] ?? '')) === '') {
            continue;
        }
        $events[] = $row;
    }

    usort($events, static function (array $a, array $b): int {
        return strcmp((string) ($a['start_at'] ?? ''), (string) ($b['start_at'] ?? ''));
    });

    return $events;
}

/**
 * @return array{events_revision:int,updated_at:string}|null
 */
function firestore_catalog_read_signals(): ?array
{
    $url = firestore_doc_url('public_catalog_meta/signals');
    if ($url === null) {
        return null;
    }
    $res = firestore_request('GET', $url);
    if (!$res['ok']) {
        return null;
    }
    $decoded = json_decode((string) ($res['body'] ?? ''), true);
    if (!is_array($decoded) || !isset($decoded['fields']) || !is_array($decoded['fields'])) {
        return null;
    }
    $fields = firestore_decode_fields($decoded['fields']);
    return [
        'events_revision' => (int) ($fields['events_revision'] ?? 0),
        'updated_at' => (string) ($fields['updated_at'] ?? ''),
    ];
}

/**
 * Rebuild catalog from Supabase published events (admin maintenance).
 */
function firestore_catalog_rebuild_from_supabase(array $headers, int $limit = 200): array
{
    if (!function_exists('supabase_request')) {
        require_once __DIR__ . '/supabase.php';
    }

    $url = rtrim(SUPABASE_URL, '/') . '/rest/v1/events'
        . '?select=id,title,description,location,start_at,end_at,status,cover_image_url,event_type,event_for,updated_at'
        . '&status=eq.published'
        . '&order=start_at.asc'
        . '&limit=' . max(1, min(500, $limit));

    $res = supabase_request('GET', $url, $headers);
    if (!$res['ok']) {
        return ['ok' => false, 'synced' => 0, 'error' => 'Failed to load published events'];
    }
    $rows = json_decode((string) ($res['body'] ?? ''), true);
    if (!is_array($rows)) {
        return ['ok' => false, 'synced' => 0, 'error' => 'Invalid events payload'];
    }

    $synced = 0;
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        if (firestore_catalog_sync_event($row, false)) {
            $synced++;
        }
    }
    firestore_catalog_bump_signals();

    return ['ok' => true, 'synced' => $synced, 'error' => ''];
}
