<?php
declare(strict_types=1);

/**
 * FIFO registrar code pool helpers (service-role / PHP BFF only).
 */

function certificate_pool_headers(): array
{
    return [
        'Accept: application/json',
        'Content-Type: application/json',
        'apikey: ' . SUPABASE_KEY,
        'Authorization: Bearer ' . SUPABASE_KEY,
        'Prefer: return=representation',
    ];
}

function certificate_pool_teacher_may_manage(string $eventId, string $teacherId): bool
{
    $eventId = trim($eventId);
    $teacherId = trim($teacherId);
    if ($eventId === '' || $teacherId === '') {
        return false;
    }
    $headers = [
        'Accept: application/json',
        'apikey: ' . SUPABASE_KEY,
        'Authorization: Bearer ' . SUPABASE_KEY,
    ];
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
        . '&teacher_id=eq.' . rawurlencode($teacherId) . '&limit=1';
    $assignRes = supabase_request('GET', $assignUrl, $headers);
    $assignRows = json_decode((string) ($assignRes['body'] ?? ''), true);
    return is_array($assignRows) && count($assignRows) > 0;
}

/**
 * Scope filter for pool rows: seminar session, or whole-event (session_id IS NULL).
 */
function certificate_pool_scope_filter(?string $sessionId): string
{
    $sessionId = $sessionId !== null ? trim($sessionId) : '';
    if ($sessionId !== '') {
        return '&session_id=eq.' . rawurlencode($sessionId);
    }
    return '&session_id=is.null';
}

/**
 * True if this registrar code is already on a certificate row (any scope).
 */
function certificate_pool_code_already_on_certificate(string $code, string $studentId): bool
{
    $code = trim($code);
    $studentId = trim($studentId);
    if ($code === '' || $studentId === '') {
        return false;
    }
    $headers = [
        'Accept: application/json',
        'apikey: ' . SUPABASE_KEY,
        'Authorization: Bearer ' . SUPABASE_KEY,
    ];
    $simpleUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/certificates'
        . '?select=id&certificate_code=eq.' . rawurlencode($code)
        . '&limit=1';
    $simpleRes = function_exists('supabase_request_once')
        ? supabase_request_once('GET', $simpleUrl, $headers)
        : supabase_request('GET', $simpleUrl, $headers);
    $simpleRows = json_decode((string) ($simpleRes['body'] ?? ''), true);
    if (is_array($simpleRows) && count($simpleRows) > 0) {
        return true;
    }

    $sessionUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/event_session_certificates'
        . '?select=id&certificate_code=eq.' . rawurlencode($code)
        . '&limit=1';
    $sessionRes = function_exists('supabase_request_once')
        ? supabase_request_once('GET', $sessionUrl, $headers)
        : supabase_request('GET', $sessionUrl, $headers);
    $sessionRows = json_decode((string) ($sessionRes['body'] ?? ''), true);
    return is_array($sessionRows) && count($sessionRows) > 0;
}

/**
 * Reuse a code already assigned to this student in the same pool (idempotent),
 * but only if it is not already written onto a certificate row.
 * Prevents double-submit from burning a second FIFO code.
 */
function certificate_pool_reuse_assigned(string $eventId, string $studentId, ?string $sessionId = null): ?string
{
    $eventId = trim($eventId);
    $studentId = trim($studentId);
    if ($eventId === '' || $studentId === '') {
        return null;
    }

    $url = rtrim(SUPABASE_URL, '/') . '/rest/v1/event_certificate_codes'
        . '?select=id,code'
        . '&event_id=eq.' . rawurlencode($eventId)
        . '&assigned_to=eq.' . rawurlencode($studentId)
        . '&status=eq.assigned'
        . certificate_pool_scope_filter($sessionId)
        . '&order=assigned_at.desc.nullslast,sort_order.asc'
        . '&limit=5';
    $res = function_exists('supabase_request_once')
        ? supabase_request_once('GET', $url, [
            'Accept: application/json',
            'apikey: ' . SUPABASE_KEY,
            'Authorization: Bearer ' . SUPABASE_KEY,
        ])
        : supabase_request('GET', $url, [
            'Accept: application/json',
            'apikey: ' . SUPABASE_KEY,
            'Authorization: Bearer ' . SUPABASE_KEY,
        ]);
    $rows = json_decode((string) ($res['body'] ?? ''), true);
    if (!is_array($rows)) {
        return null;
    }
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $code = trim((string) ($row['code'] ?? ''));
        if ($code === '') {
            continue;
        }
        // Skip codes already consumed by an issued certificate (e.g. seminar A
        // from shared event pool) so seminar B claims a fresh FIFO code.
        if (certificate_pool_code_already_on_certificate($code, $studentId)) {
            continue;
        }
        return $code;
    }
    return null;
}

/**
 * Release a previously claimed code back to the pool (e.g. cert insert failed).
 * Matches by event + code + assignee (session optional).
 */
function certificate_pool_release(string $eventId, string $studentId, string $code, ?string $sessionId = null): bool
{
    $eventId = trim($eventId);
    $studentId = trim($studentId);
    $code = trim($code);
    if ($eventId === '' || $studentId === '' || $code === '') {
        return false;
    }

    $url = rtrim(SUPABASE_URL, '/') . '/rest/v1/event_certificate_codes'
        . '?event_id=eq.' . rawurlencode($eventId)
        . '&code=eq.' . rawurlencode($code)
        . '&assigned_to=eq.' . rawurlencode($studentId)
        . '&status=eq.assigned';
    if ($sessionId !== null && trim($sessionId) !== '') {
        $url .= certificate_pool_scope_filter($sessionId);
    }
    $patch = function_exists('supabase_request_once')
        ? supabase_request_once('PATCH', $url, certificate_pool_headers(), json_encode([
            'status' => 'available',
            'assigned_to' => null,
            'assigned_at' => null,
        ]))
        : supabase_request('PATCH', $url, certificate_pool_headers(), json_encode([
            'status' => 'available',
            'assigned_to' => null,
            'assigned_at' => null,
        ]));
    return (bool) ($patch['ok'] ?? false);
}

/**
 * Claim one available row in a specific pool scope (optimistic lock + retries).
 * Uses supabase_request_once so a timed-out-but-applied PATCH is not blindly re-sent.
 */
function certificate_pool_claim_in_scope(string $eventId, string $studentId, ?string $sessionId, int $maxAttempts = 5): ?string
{
    $eventId = trim($eventId);
    $studentId = trim($studentId);
    if ($eventId === '' || $studentId === '') {
        return null;
    }

    $reuse = certificate_pool_reuse_assigned($eventId, $studentId, $sessionId);
    if ($reuse !== null && $reuse !== '') {
        return $reuse;
    }

    $readHeaders = [
        'Accept: application/json',
        'apikey: ' . SUPABASE_KEY,
        'Authorization: Bearer ' . SUPABASE_KEY,
    ];
    $writeHeaders = certificate_pool_headers();
    $attempts = max(1, min(8, $maxAttempts));

    for ($i = 0; $i < $attempts; $i++) {
        $url = rtrim(SUPABASE_URL, '/') . '/rest/v1/event_certificate_codes'
            . '?select=id,code,status'
            . '&event_id=eq.' . rawurlencode($eventId)
            . '&status=eq.available'
            . certificate_pool_scope_filter($sessionId)
            . '&order=sort_order.asc,created_at.asc'
            . '&limit=1';
        $res = function_exists('supabase_request_once')
            ? supabase_request_once('GET', $url, $readHeaders)
            : supabase_request('GET', $url, $readHeaders);
        $rows = json_decode((string) ($res['body'] ?? ''), true);
        $row = is_array($rows) && isset($rows[0]) && is_array($rows[0]) ? $rows[0] : null;
        if (!$row) {
            // Pool empty → try seed from linked canvas (usually event-level).
            certificate_pool_ensure_seed_from_linked_template($eventId, $sessionId);
            if ($sessionId !== null && $sessionId !== '') {
                // Session pool may still be empty; mint only if this seminar has its own seed.
                // Otherwise claim_next falls back to the shared event-level pool.
                return certificate_pool_mint_next_sequential($eventId, $studentId, $sessionId);
            }
            $retry = function_exists('supabase_request_once')
                ? supabase_request_once('GET', $url, $readHeaders)
                : supabase_request('GET', $url, $readHeaders);
            $retryRows = json_decode((string) ($retry['body'] ?? ''), true);
            $row = is_array($retryRows) && isset($retryRows[0]) && is_array($retryRows[0])
                ? $retryRows[0]
                : null;
            if (!$row) {
                return certificate_pool_mint_next_sequential($eventId, $studentId, $sessionId);
            }
        }

        $id = trim((string) ($row['id'] ?? ''));
        $code = trim((string) ($row['code'] ?? ''));
        if ($id === '' || $code === '') {
            return null;
        }

        $patchUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/event_certificate_codes?id=eq.' . rawurlencode($id)
            . '&status=eq.available';
        $patch = function_exists('supabase_request_once')
            ? supabase_request_once('PATCH', $patchUrl, $writeHeaders, json_encode([
                'status' => 'assigned',
                'assigned_to' => $studentId,
                'assigned_at' => gmdate('c'),
            ]))
            : supabase_request('PATCH', $patchUrl, $writeHeaders, json_encode([
                'status' => 'assigned',
                'assigned_to' => $studentId,
                'assigned_at' => gmdate('c'),
            ]));

        if (!($patch['ok'] ?? false)) {
            // Likely race or transient error — try next available row.
            usleep(40000 * ($i + 1));
            continue;
        }

        $patched = json_decode((string) ($patch['body'] ?? ''), true);
        // Empty representation ⇒ another worker claimed this row first.
        if (is_array($patched) && $patched === []) {
            usleep(40000 * ($i + 1));
            continue;
        }

        return $code;
    }

    // Retries exhausted or pool empty mid-loop — still try seed auto-count (…-01 → …-02).
    return certificate_pool_mint_next_sequential($eventId, $studentId, $sessionId);
}

/**
 * Take the next available code for an event (+ optional seminar session).
 * Each seminar uses its own session-scoped pool/seed (…199.01.* vs …199.02.*).
 * Only fall back to the whole-event pool when this seminar has no linked seed.
 *
 * @return string|null
 */
function certificate_pool_claim_next(string $eventId, string $studentId, ?string $sessionId = null): ?string
{
    $eventId = trim($eventId);
    $studentId = trim($studentId);
    $sessionId = $sessionId !== null ? trim($sessionId) : '';
    if ($eventId === '' || $studentId === '') {
        return null;
    }

    if ($sessionId !== '') {
        $fromSession = certificate_pool_claim_in_scope($eventId, $studentId, $sessionId);
        if ($fromSession !== null && $fromSession !== '') {
            return $fromSession;
        }
        // Mint from this seminar's template seed before touching the shared pool.
        $minted = certificate_pool_mint_next_sequential($eventId, $studentId, $sessionId);
        if ($minted !== null && $minted !== '') {
            return $minted;
        }
        // No session seed linked — legacy single-pool events only.
        $sessionSeed = certificate_pool_read_seed_from_linked_template($eventId, $sessionId);
        if (trim((string) ($sessionSeed['code'] ?? '')) !== '') {
            return null;
        }
        return certificate_pool_claim_in_scope($eventId, $studentId, null);
    }

    return certificate_pool_claim_in_scope($eventId, $studentId, null);
}

/**
 * @return array{available:int,assigned:int,total:int,codes:list<array<string,mixed>>}
 */
function certificate_pool_status(string $eventId, ?string $sessionId = null, int $limit = 200): array
{
    $eventId = trim($eventId);
    $sessionId = $sessionId !== null ? trim($sessionId) : '';
    $headers = [
        'Accept: application/json',
        'apikey: ' . SUPABASE_KEY,
        'Authorization: Bearer ' . SUPABASE_KEY,
    ];
    $url = rtrim(SUPABASE_URL, '/') . '/rest/v1/event_certificate_codes'
        . '?select=id,code,status,sort_order,assigned_to,assigned_at,scanned_from,import_id,created_at'
        . '&event_id=eq.' . rawurlencode($eventId)
        . '&order=sort_order.asc'
        . '&limit=' . max(1, min(500, $limit));
    if ($sessionId !== '') {
        $url .= '&session_id=eq.' . rawurlencode($sessionId);
    } else {
        $url .= '&session_id=is.null';
    }
    $res = supabase_request('GET', $url, $headers);
    $rows = $res['ok'] ? json_decode((string) $res['body'], true) : [];
    if (!is_array($rows)) {
        $rows = [];
    }
    $available = 0;
    $assigned = 0;
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $st = (string) ($row['status'] ?? '');
        if ($st === 'available') {
            $available++;
        } elseif ($st === 'assigned') {
            $assigned++;
        }
    }
    return [
        'available' => $available,
        'assigned' => $assigned,
        'total' => count($rows),
        'codes' => $rows,
    ];
}

/**
 * Normalize a free-text / list of codes into unique trimmed strings.
 *
 * @param list<mixed>|string $raw
 * @return list<string>
 */
function certificate_pool_normalize_codes(array|string $raw): array
{
    if (is_string($raw)) {
        $raw = preg_split('/[\r\n,;]+/', $raw) ?: [];
    }
    $out = [];
    $seen = [];
    foreach ($raw as $item) {
        $code = '';
        if (is_array($item)) {
            $code = trim((string) ($item['code'] ?? ''));
        } else {
            $code = trim((string) $item);
        }
        if ($code === '' || strlen($code) > 120) {
            continue;
        }
        // Soft sanitize: printable tokens (allow colon for LU:AA-CE-… registrar format)
        if (preg_match('/^[\p{L}\p{N}][\p{L}\p{N}\s\-_:.#\/]+$/u', $code) !== 1) {
            continue;
        }
        $key = strtoupper($code);
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $out[] = $code;
    }
    return $out;
}

/**
 * Parse registrar seed like LU-AA-FO-180-01 → prefix + starting serial.
 *
 * @return array{prefix:string,number:int,width:int}|null
 */
function certificate_pool_parse_sequence(string $code): ?array
{
    $code = trim($code);
    if ($code === '') {
        return null;
    }
    // Prefer serial after a separator (…-01 / …_01 / …/01).
    if (preg_match('/^(.*[-_\/.#:])(\d+)$/u', $code, $m) !== 1) {
        return null;
    }
    $digits = $m[2];
    if ($digits === '') {
        return null;
    }
    return [
        'prefix' => $m[1],
        'number' => (int) $digits,
        'width' => strlen($digits),
    ];
}

function certificate_pool_format_sequence(string $prefix, int $number, int $width): string
{
    $width = max(1, min(12, $width));
    $number = max(0, $number);
    return $prefix . str_pad((string) $number, $width, '0', STR_PAD_LEFT);
}

function certificate_pool_registrant_count(string $eventId): int
{
    $eventId = trim($eventId);
    if ($eventId === '') {
        return 0;
    }
    $headers = [
        'Accept: application/json',
        'apikey: ' . SUPABASE_KEY,
        'Authorization: Bearer ' . SUPABASE_KEY,
        'Prefer: count=exact',
    ];
    $url = rtrim(SUPABASE_URL, '/') . '/rest/v1/event_registrations'
        . '?select=id&event_id=eq.' . rawurlencode($eventId) . '&limit=1';
    $res = function_exists('supabase_request_once')
        ? supabase_request_once('GET', $url, $headers)
        : supabase_request('GET', $url, $headers);
    $contentRange = '';
    if (isset($res['headers']) && is_array($res['headers'])) {
        $contentRange = (string) ($res['headers']['content-range'] ?? '');
    }
    if ($contentRange !== '' && preg_match('/\/(\d+)\s*$/', $contentRange, $m) === 1) {
        return max(0, (int) $m[1]);
    }
    // Fallback: page a modest list.
    $url = rtrim(SUPABASE_URL, '/') . '/rest/v1/event_registrations'
        . '?select=student_id&event_id=eq.' . rawurlencode($eventId) . '&limit=500';
    $res = function_exists('supabase_request_once')
        ? supabase_request_once('GET', $url, [
            'Accept: application/json',
            'apikey: ' . SUPABASE_KEY,
            'Authorization: Bearer ' . SUPABASE_KEY,
        ])
        : supabase_request('GET', $url, [
            'Accept: application/json',
            'apikey: ' . SUPABASE_KEY,
            'Authorization: Bearer ' . SUPABASE_KEY,
        ]);
    $rows = json_decode((string) ($res['body'] ?? ''), true);
    return is_array($rows) ? count($rows) : 0;
}

/**
 * Import/link stores ONE seed per seminar (e.g. LU-AA-FO-180-01).
 * Later numbers are minted on eval claim — never pre-expanded into the pool.
 *
 * If a scan/OCR returned a contiguous run (…01, …02), keep only the lowest seed.
 *
 * @param list<string> $codes
 * @return list<string>
 */
function certificate_pool_collapse_to_seed(array $codes): array
{
    $codes = array_values($codes);
    if (count($codes) <= 1) {
        return $codes;
    }

    $parsed = [];
    foreach ($codes as $c) {
        $p = certificate_pool_parse_sequence($c);
        if ($p === null) {
            return $codes;
        }
        $parsed[] = [
            'code' => $c,
            'prefix' => $p['prefix'],
            'number' => $p['number'],
            'width' => $p['width'],
        ];
    }

    $prefix = $parsed[0]['prefix'];
    $width = $parsed[0]['width'];
    foreach ($parsed as $p) {
        if ($p['prefix'] !== $prefix || $p['width'] !== $width) {
            return $codes;
        }
    }

    usort($parsed, static fn (array $a, array $b): int => $a['number'] <=> $b['number']);
    for ($i = 1, $n = count($parsed); $i < $n; $i++) {
        if ($parsed[$i]['number'] !== $parsed[0]['number'] + $i) {
            return $codes;
        }
    }

    return [$parsed[0]['code']];
}

/**
 * @deprecated Use certificate_pool_collapse_to_seed — kept for callers that still expand.
 * @param list<string> $codes
 * @return list<string>
 */
function certificate_pool_expand_seed_sequence(string $eventId, array $codes): array
{
    unset($eventId);
    return certificate_pool_collapse_to_seed($codes);
}

/**
 * Drop leftover available codes that look like auto-expanded siblings of this seed
 * (same prefix, higher serial) so re-Save & link cleans “dalawang code” pools.
 */
function certificate_pool_prune_expanded_siblings(
    string $eventId,
    ?string $sessionId,
    string $seedCode
): void {
    $eventId = trim($eventId);
    $sessionId = $sessionId !== null ? trim($sessionId) : '';
    $seed = certificate_pool_parse_sequence($seedCode);
    if ($eventId === '' || $seed === null) {
        return;
    }

    $url = rtrim(SUPABASE_URL, '/') . '/rest/v1/event_certificate_codes'
        . '?select=id,code,status'
        . '&event_id=eq.' . rawurlencode($eventId)
        . '&status=eq.available'
        . certificate_pool_scope_filter($sessionId !== '' ? $sessionId : null)
        . '&limit=500';
    $res = function_exists('supabase_request_once')
        ? supabase_request_once('GET', $url, [
            'Accept: application/json',
            'apikey: ' . SUPABASE_KEY,
            'Authorization: Bearer ' . SUPABASE_KEY,
        ])
        : supabase_request('GET', $url, [
            'Accept: application/json',
            'apikey: ' . SUPABASE_KEY,
            'Authorization: Bearer ' . SUPABASE_KEY,
        ]);
    $rows = json_decode((string) ($res['body'] ?? ''), true);
    if (!is_array($rows) || $rows === []) {
        return;
    }

    $seedKey = strtoupper(trim($seedCode));
    $deleteIds = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $code = trim((string) ($row['code'] ?? ''));
        $id = trim((string) ($row['id'] ?? ''));
        if ($id === '' || $code === '' || strtoupper($code) === $seedKey) {
            continue;
        }
        $p = certificate_pool_parse_sequence($code);
        if ($p === null) {
            continue;
        }
        if ($p['prefix'] !== $seed['prefix'] || $p['width'] !== $seed['width']) {
            continue;
        }
        if ($p['number'] > $seed['number']) {
            $deleteIds[] = $id;
        }
    }
    if ($deleteIds === []) {
        return;
    }

    $headers = [
        'Accept: application/json',
        'apikey: ' . SUPABASE_KEY,
        'Authorization: Bearer ' . SUPABASE_KEY,
        'Prefer: return=minimal',
    ];
    foreach (array_chunk($deleteIds, 50) as $chunk) {
        $ids = implode(',', array_map('rawurlencode', $chunk));
        $delUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/event_certificate_codes'
            . '?id=in.(' . $ids . ')'
            . '&status=eq.available';
        if (function_exists('supabase_request_once')) {
            supabase_request_once('DELETE', $delUrl, $headers);
        } else {
            supabase_request('DELETE', $delUrl, $headers);
        }
    }
}

/**
 * Resolve sequence cursor from existing pool rows for this event/session.
 *
 * @return array{prefix:string,next:int,width:int,import_id:string}|null
 */
function certificate_pool_sequence_cursor(string $eventId, ?string $sessionId): ?array
{
    $eventId = trim($eventId);
    $sessionId = $sessionId !== null ? trim($sessionId) : '';
    if ($eventId === '') {
        return null;
    }

    $url = rtrim(SUPABASE_URL, '/') . '/rest/v1/event_certificate_codes'
        . '?select=id,code,import_id,sort_order,created_at'
        . '&event_id=eq.' . rawurlencode($eventId)
        . certificate_pool_scope_filter($sessionId !== '' ? $sessionId : null)
        . '&order=sort_order.asc,created_at.asc'
        . '&limit=500';
    $res = function_exists('supabase_request_once')
        ? supabase_request_once('GET', $url, [
            'Accept: application/json',
            'apikey: ' . SUPABASE_KEY,
            'Authorization: Bearer ' . SUPABASE_KEY,
        ])
        : supabase_request('GET', $url, [
            'Accept: application/json',
            'apikey: ' . SUPABASE_KEY,
            'Authorization: Bearer ' . SUPABASE_KEY,
        ]);
    $rows = json_decode((string) ($res['body'] ?? ''), true);
    if (!is_array($rows) || $rows === []) {
        return null;
    }

    $prefix = null;
    $width = 2;
    $maxNum = null;
    $importId = '';
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        if ($importId === '') {
            $importId = trim((string) ($row['import_id'] ?? ''));
        }
        $parsed = certificate_pool_parse_sequence((string) ($row['code'] ?? ''));
        if ($parsed === null) {
            continue;
        }
        if ($prefix === null) {
            $prefix = $parsed['prefix'];
            $width = $parsed['width'];
            $maxNum = $parsed['number'];
            continue;
        }
        if ($parsed['prefix'] !== $prefix) {
            continue;
        }
        $width = max($width, $parsed['width']);
        if ($maxNum === null || $parsed['number'] > $maxNum) {
            $maxNum = $parsed['number'];
        }
    }
    if ($prefix === null || $maxNum === null) {
        return null;
    }
    return [
        'prefix' => $prefix,
        'next' => $maxNum + 1,
        'width' => $width,
        'import_id' => $importId,
    ];
}

/**
 * Ensure an import batch exists for auto-minted sequential codes.
 */
function certificate_pool_ensure_sequence_import(
    string $eventId,
    ?string $sessionId,
    ?string $existingImportId = null
): string {
    $existingImportId = $existingImportId !== null ? trim($existingImportId) : '';
    if ($existingImportId !== '') {
        return $existingImportId;
    }

    $eventId = trim($eventId);
    $sessionId = $sessionId !== null ? trim($sessionId) : '';
    $headers = certificate_pool_headers();
    $payload = [
        'event_id' => $eventId,
        'session_id' => $sessionId !== '' ? $sessionId : null,
        'source_filename' => 'auto-sequence',
        'source_kind' => 'manual',
        'status' => 'ready',
        'codes_found' => 0,
        'created_by' => null,
    ];
    $res = function_exists('supabase_request_once')
        ? supabase_request_once('POST', rtrim(SUPABASE_URL, '/') . '/rest/v1/event_certificate_imports', $headers, json_encode($payload))
        : supabase_request('POST', rtrim(SUPABASE_URL, '/') . '/rest/v1/event_certificate_imports', $headers, json_encode($payload));
    $rows = json_decode((string) ($res['body'] ?? ''), true);
    $id = is_array($rows) && isset($rows[0]['id']) ? (string) $rows[0]['id'] : '';
    return $id;
}

/**
 * Pull a registrar-style seed from Fabric canvas_state (certificate_code glyph).
 */
/** @param mixed $canvasState */
function certificate_pool_extract_seed_from_canvas($canvasState): ?string
{
    if (is_string($canvasState)) {
        $decoded = json_decode($canvasState, true);
        $canvasState = is_array($decoded) ? $decoded : null;
    }
    if (!is_array($canvasState)) {
        return null;
    }
    $objects = $canvasState['objects'] ?? null;
    if (!is_array($objects)) {
        return null;
    }

    if (!function_exists('certificate_code_extract_regex')) {
        require_once __DIR__ . '/certificate_code_extract.php';
    }
    $regex = certificate_code_extract_regex();

    $pick = static function (string $text) use ($regex): ?string {
        $text = trim($text);
        if ($text === '') {
            return null;
        }
        if (preg_match('/^\{\{\s*certificate_code\s*\}\}$/i', $text)) {
            return null;
        }
        if (preg_match('/CERTIFICATE[-_]?CODE/i', $text)) {
            return null;
        }
        if (preg_match('/^SAMPLE[-_]?CODE/i', $text)) {
            return null;
        }
        if (!preg_match($regex, $text, $m)) {
            return null;
        }
        $code = certificate_code_normalize((string) ($m[0] ?? ''));
        if ($code === '' || !preg_match('/\d/', $code)) {
            return null;
        }
        if (certificate_pool_parse_sequence($code) === null) {
            // Still allow non-sequential registrar codes as a one-shot pool entry.
            return $code;
        }
        return $code;
    };

    foreach ($objects as $obj) {
        if (!is_array($obj)) {
            continue;
        }
        $id = strtolower(trim((string) ($obj['id'] ?? '')));
        $name = strtolower(trim((string) ($obj['name'] ?? '')));
        if ($id !== 'certificate_code' && $name !== 'certificate code') {
            continue;
        }
        $found = $pick((string) ($obj['text'] ?? ''));
        if ($found !== null) {
            return $found;
        }
    }

    foreach ($objects as $obj) {
        if (!is_array($obj)) {
            continue;
        }
        $type = strtolower(trim((string) ($obj['type'] ?? '')));
        if ($type !== 'i-text' && $type !== 'text' && $type !== 'textbox') {
            continue;
        }
        $found = $pick((string) ($obj['text'] ?? ''));
        if ($found !== null) {
            return $found;
        }
    }

    return null;
}

/**
 * True when this event/session already has any pool row (seed or assigned).
 */
function certificate_pool_has_any_code(string $eventId, ?string $sessionId = null): bool
{
    $eventId = trim($eventId);
    $sessionId = $sessionId !== null ? trim($sessionId) : '';
    if ($eventId === '') {
        return false;
    }
    $url = rtrim(SUPABASE_URL, '/') . '/rest/v1/event_certificate_codes'
        . '?select=id'
        . '&event_id=eq.' . rawurlencode($eventId)
        . certificate_pool_scope_filter($sessionId !== '' ? $sessionId : null)
        . '&limit=1';
    $res = function_exists('supabase_request_once')
        ? supabase_request_once('GET', $url, [
            'Accept: application/json',
            'apikey: ' . SUPABASE_KEY,
            'Authorization: Bearer ' . SUPABASE_KEY,
        ])
        : supabase_request('GET', $url, [
            'Accept: application/json',
            'apikey: ' . SUPABASE_KEY,
            'Authorization: Bearer ' . SUPABASE_KEY,
        ]);
    $rows = json_decode((string) ($res['body'] ?? ''), true);
    return is_array($rows) && isset($rows[0]);
}

/**
 * Read seed text from the linked event/session certificate design.
 *
 * @return array{code:?string,created_by:string}
 */
function certificate_pool_read_seed_from_linked_template(string $eventId, ?string $sessionId = null): array
{
    $eventId = trim($eventId);
    $sessionId = $sessionId !== null ? trim($sessionId) : '';
    $headers = [
        'Accept: application/json',
        'apikey: ' . SUPABASE_KEY,
        'Authorization: Bearer ' . SUPABASE_KEY,
    ];
    $out = ['code' => null, 'created_by' => ''];
    if ($eventId === '') {
        return $out;
    }

    if ($sessionId !== '') {
        $sessUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/event_session_certificate_templates'
            . '?select=canvas_state,created_by'
            . '&session_id=eq.' . rawurlencode($sessionId)
            . '&order=updated_at.desc.nullslast,created_at.desc'
            . '&limit=1';
        $sessRes = supabase_request('GET', $sessUrl, $headers);
        $sessRows = $sessRes['ok'] ? json_decode((string) ($sessRes['body'] ?? ''), true) : [];
        $sessRow = is_array($sessRows) && isset($sessRows[0]) && is_array($sessRows[0]) ? $sessRows[0] : null;
        if ($sessRow) {
            $out['created_by'] = trim((string) ($sessRow['created_by'] ?? ''));
            $code = certificate_pool_extract_seed_from_canvas($sessRow['canvas_state'] ?? null);
            if ($code !== null && $code !== '') {
                $out['code'] = $code;
                return $out;
            }
        }
    }

    $tplUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/certificate_templates'
        . '?select=canvas_state,created_by'
        . '&event_id=eq.' . rawurlencode($eventId)
        . '&order=updated_at.desc.nullslast,created_at.desc'
        . '&limit=1';
    $tplRes = supabase_request('GET', $tplUrl, $headers);
    $tplRows = $tplRes['ok'] ? json_decode((string) ($tplRes['body'] ?? ''), true) : [];
    $tplRow = is_array($tplRows) && isset($tplRows[0]) && is_array($tplRows[0]) ? $tplRows[0] : null;
    if ($tplRow) {
        if ($out['created_by'] === '') {
            $out['created_by'] = trim((string) ($tplRow['created_by'] ?? ''));
        }
        $code = certificate_pool_extract_seed_from_canvas($tplRow['canvas_state'] ?? null);
        if ($code !== null && $code !== '') {
            $out['code'] = $code;
        }
    }

    return $out;
}

/**
 * If the pool is empty, insert a seed from the linked template canvas (when present).
 * Returns true when the pool already had codes or a seed was inserted.
 *
 * Seminar claims MUST seed into that session_id scope (e.g. …199.02.01), never into
 * the shared event pool — otherwise seminar 2 steals …199.01.02 from seminar 1.
 */
function certificate_pool_ensure_seed_from_linked_template(string $eventId, ?string $sessionId = null): bool
{
    $eventId = trim($eventId);
    $sessionId = $sessionId !== null ? trim($sessionId) : '';
    if ($eventId === '') {
        return false;
    }
    $scopeSession = $sessionId !== '' ? $sessionId : null;
    if (certificate_pool_has_any_code($eventId, $scopeSession)) {
        return true;
    }

    $read = certificate_pool_read_seed_from_linked_template($eventId, $scopeSession);
    $seed = trim((string) ($read['code'] ?? ''));
    if ($seed === '') {
        return false;
    }
    $createdBy = trim((string) ($read['created_by'] ?? ''));
    if ($createdBy === '') {
        $evUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/events'
            . '?select=created_by&id=eq.' . rawurlencode($eventId) . '&limit=1';
        $evRes = supabase_request('GET', $evUrl, [
            'Accept: application/json',
            'apikey: ' . SUPABASE_KEY,
            'Authorization: Bearer ' . SUPABASE_KEY,
        ]);
        $evRows = $evRes['ok'] ? json_decode((string) ($evRes['body'] ?? ''), true) : [];
        $createdBy = is_array($evRows) && isset($evRows[0]['created_by'])
            ? trim((string) $evRows[0]['created_by'])
            : '';
    }

    try {
        $result = certificate_pool_insert_batch(
            $eventId,
            $scopeSession,
            $createdBy,
            [$seed],
            'manual',
            'canvas-seed'
        );
        return (($result['inserted'] ?? []) !== []);
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Mint the next sequential registrar code and assign it to the student.
 * Used when the FIFO pool is empty but a seed like LU-AA-FO-180-01 was linked.
 */
function certificate_pool_mint_next_sequential(string $eventId, string $studentId, ?string $sessionId = null): ?string
{
    $eventId = trim($eventId);
    $studentId = trim($studentId);
    $sessionId = $sessionId !== null ? trim($sessionId) : '';
    if ($eventId === '' || $studentId === '') {
        return null;
    }

    $cursor = certificate_pool_sequence_cursor($eventId, $sessionId !== '' ? $sessionId : null);
    if ($cursor === null) {
        // Linked design may already show LU-…-01 on canvas even if Import never saved a seed.
        if (certificate_pool_ensure_seed_from_linked_template($eventId, $sessionId !== '' ? $sessionId : null)) {
            $cursor = certificate_pool_sequence_cursor($eventId, $sessionId !== '' ? $sessionId : null);
        }
    }
    if ($cursor === null) {
        return null;
    }

    $importId = certificate_pool_ensure_sequence_import(
        $eventId,
        $sessionId !== '' ? $sessionId : null,
        $cursor['import_id']
    );
    if ($importId === '') {
        return null;
    }

    $headers = certificate_pool_headers();
    // Retry a few serial numbers if a global unique(code) collision occurs.
    for ($i = 0; $i < 8; $i++) {
        $number = $cursor['next'] + $i;
        $code = certificate_pool_format_sequence($cursor['prefix'], $number, $cursor['width']);
        $payload = [
            'import_id' => $importId,
            'event_id' => $eventId,
            'session_id' => $sessionId !== '' ? $sessionId : null,
            'code' => $code,
            'sort_order' => $number,
            'status' => 'assigned',
            'assigned_to' => $studentId,
            'assigned_at' => gmdate('c'),
            'scanned_from' => 'auto-sequence',
        ];
        $res = function_exists('supabase_request_once')
            ? supabase_request_once(
                'POST',
                rtrim(SUPABASE_URL, '/') . '/rest/v1/event_certificate_codes',
                $headers,
                json_encode($payload)
            )
            : supabase_request(
                'POST',
                rtrim(SUPABASE_URL, '/') . '/rest/v1/event_certificate_codes',
                $headers,
                json_encode($payload)
            );
        if ($res['ok'] ?? false) {
            return $code;
        }
    }
    return null;
}

/**
 * Create an import batch and insert codes into the pool.
 *
 * @param list<string> $codes
 * @return array{import_id:string,inserted:list<string>,skipped:list<array{code:string,error:string}>}
 */
function certificate_pool_insert_batch(
    string $eventId,
    ?string $sessionId,
    string $userId,
    array $codes,
    string $sourceKind,
    string $sourceFilename
): array {
    $eventId = trim($eventId);
    $sessionId = $sessionId !== null ? trim($sessionId) : '';
    $userId = trim($userId);
    $sourceKind = in_array($sourceKind, ['pptx', 'pdf', 'manual'], true) ? $sourceKind : 'manual';
    $sourceFilename = mb_substr(trim($sourceFilename) !== '' ? $sourceFilename : 'manual-entry', 0, 240);
    $codes = certificate_pool_normalize_codes($codes);
    // One seed only — …-02 / …-03 mint on claim, never pre-fill the pool.
    $codes = certificate_pool_collapse_to_seed($codes);
    if ($eventId === '' || $codes === []) {
        throw new InvalidArgumentException('No valid codes to insert.');
    }
    if (count($codes) === 1) {
        certificate_pool_prune_expanded_siblings(
            $eventId,
            $sessionId !== '' ? $sessionId : null,
            $codes[0]
        );
    }

    if ($sessionId !== '') {
        $sessUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/event_sessions'
            . '?select=id,event_id&id=eq.' . rawurlencode($sessionId) . '&limit=1';
        $sessRes = supabase_request('GET', $sessUrl, [
            'Accept: application/json',
            'apikey: ' . SUPABASE_KEY,
            'Authorization: Bearer ' . SUPABASE_KEY,
        ]);
        $sessRows = json_decode((string) ($sessRes['body'] ?? ''), true);
        $sess = is_array($sessRows) && isset($sessRows[0]) ? $sessRows[0] : null;
        if (!is_array($sess) || (string) ($sess['event_id'] ?? '') !== $eventId) {
            throw new InvalidArgumentException('session_id does not belong to this event.');
        }
    }

    $headers = certificate_pool_headers();
    $importPayload = [
        'event_id' => $eventId,
        'session_id' => $sessionId !== '' ? $sessionId : null,
        'source_filename' => $sourceFilename,
        'source_kind' => $sourceKind,
        'status' => 'ready',
        'codes_found' => count($codes),
        'created_by' => $userId !== '' ? $userId : null,
    ];
    $importUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/event_certificate_imports';
    $importRes = supabase_request('POST', $importUrl, $headers, json_encode($importPayload));
    if (!$importRes['ok']) {
        throw new RuntimeException('Failed to create import batch.');
    }
    $importRows = json_decode((string) ($importRes['body'] ?? ''), true);
    $importId = is_array($importRows) && isset($importRows[0]['id']) ? (string) $importRows[0]['id'] : '';
    if ($importId === '') {
        throw new RuntimeException('Import batch created but id missing.');
    }

    $inserted = [];
    $skipped = [];
    $sort = 0;
    foreach ($codes as $code) {
        $sort++;
        $payload = [
            'import_id' => $importId,
            'event_id' => $eventId,
            'session_id' => $sessionId !== '' ? $sessionId : null,
            'code' => $code,
            'sort_order' => $sort,
            'status' => 'available',
            'scanned_from' => $sourceKind === 'manual' ? 'manual' : mb_substr($sourceFilename, 0, 200),
        ];
        $codeUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/event_certificate_codes';
        $codeRes = supabase_request('POST', $codeUrl, $headers, json_encode($payload));
        if ($codeRes['ok']) {
            $inserted[] = $code;
        } else {
            $skipped[] = [
                'code' => $code,
                'error' => (string) ($codeRes['body'] ?? 'duplicate or insert failed'),
            ];
        }
    }

    return [
        'import_id' => $importId,
        'inserted' => $inserted,
        'skipped' => $skipped,
    ];
}

/**
 * Link a design-library template to an event (teacher-owned only).
 */
/**
 * Ensure an event-scoped copy of a Library (or other) template exists.
 * Never mutates a Library row (event_id IS NULL) — copies instead so designs stay reusable.
 *
 * @return string|null event-scoped certificate_templates.id
 */
function certificate_pool_link_template(string $templateId, string $eventId, string $userId): ?string
{
    $templateId = trim($templateId);
    $eventId = trim($eventId);
    $userId = trim($userId);
    if ($templateId === '' || $eventId === '' || $userId === '') {
        return null;
    }
    $headers = [
        'Accept: application/json',
        'apikey: ' . SUPABASE_KEY,
        'Authorization: Bearer ' . SUPABASE_KEY,
    ];
    $writeHeaders = certificate_pool_headers();

    $getUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/certificate_templates'
        . '?select=id,title,body_text,footer_text,canvas_state,thumbnail_url,created_by,event_id'
        . '&id=eq.' . rawurlencode($templateId) . '&limit=1';
    $getRes = supabase_request('GET', $getUrl, $headers);
    $rows = json_decode((string) ($getRes['body'] ?? ''), true);
    $row = is_array($rows) && isset($rows[0]) && is_array($rows[0]) ? $rows[0] : null;
    if (!$row) {
        return null;
    }
    $owner = (string) ($row['created_by'] ?? '');
    $linked = (string) ($row['event_id'] ?? '');
    if ($owner !== $userId && $linked !== $eventId) {
        return null;
    }

    // Already owned by this event — reuse as-is.
    if ($linked === $eventId) {
        return (string) ($row['id'] ?? $templateId);
    }

    // Prefer an existing event-scoped copy for this event (one linked design — overwrite on reuse).
    $existingUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/certificate_templates'
        . '?select=id&event_id=eq.' . rawurlencode($eventId)
        . '&created_by=eq.' . rawurlencode($userId)
        . '&order=updated_at.desc.nullslast&limit=1';
    $existingRes = supabase_request('GET', $existingUrl, $headers);
    $existingRows = json_decode((string) ($existingRes['body'] ?? ''), true);
    if (is_array($existingRows) && isset($existingRows[0]['id'])) {
        $existingId = (string) $existingRows[0]['id'];
        // Refresh copy from library source so Import picks up latest design.
        $patchPayload = [
            'title' => trim((string) ($row['title'] ?? '')) !== ''
                ? (string) $row['title']
                : 'Certificate of Participation',
            'body_text' => (string) ($row['body_text'] ?? 'This certifies that {{name}} participated in {{event}}.'),
            'footer_text' => $row['footer_text'] ?? null,
            'canvas_state' => $row['canvas_state'] ?? null,
            'thumbnail_url' => $row['thumbnail_url'] ?? null,
            'updated_at' => gmdate('c'),
        ];
        $patchUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/certificate_templates?id=eq.' . rawurlencode($existingId);
        $patch = supabase_request('PATCH', $patchUrl, $writeHeaders, json_encode($patchPayload));
        if ($patch['ok']) {
            return $existingId;
        }
    }

    $title = trim((string) ($row['title'] ?? '')) !== ''
        ? (string) $row['title']
        : 'Certificate of Participation';
    $postPayload = [
        'event_id' => $eventId,
        'title' => $title,
        'body_text' => (string) ($row['body_text'] ?? 'This certifies that {{name}} participated in {{event}}.'),
        'footer_text' => $row['footer_text'] ?? null,
        'canvas_state' => $row['canvas_state'] ?? null,
        'thumbnail_url' => $row['thumbnail_url'] ?? null,
        'created_by' => $userId,
        'created_at' => gmdate('c'),
        'updated_at' => gmdate('c'),
    ];
    $postUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/certificate_templates';
    $post = supabase_request('POST', $postUrl, $writeHeaders, json_encode($postPayload));
    if (!$post['ok']) {
        return null;
    }
    $posted = json_decode((string) ($post['body'] ?? ''), true);
    $newId = is_array($posted) && isset($posted[0]['id'])
        ? (string) $posted[0]['id']
        : (is_array($posted) && isset($posted['id']) ? (string) $posted['id'] : '');
    return $newId !== '' ? $newId : null;
}

/**
 * Copy a library template onto a seminar session so multi-seminar events
 * each keep their own linked design (+ codes pool by session).
 *
 * @return array{id:string,title:string,thumbnail_url:string,session_id:string,source_template_id:string}|null
 */
function certificate_pool_link_template_to_session(
    string $templateId,
    string $sessionId,
    string $eventId,
    string $userId
): ?array {
    $templateId = trim($templateId);
    $sessionId = trim($sessionId);
    $eventId = trim($eventId);
    $userId = trim($userId);
    if ($templateId === '' || $sessionId === '' || $eventId === '' || $userId === '') {
        return null;
    }

    $readHeaders = [
        'Accept: application/json',
        'apikey: ' . SUPABASE_KEY,
        'Authorization: Bearer ' . SUPABASE_KEY,
    ];
    $writeHeaders = certificate_pool_headers();

    // Session must belong to this event.
    $sessUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/event_sessions'
        . '?select=id,event_id,title&id=eq.' . rawurlencode($sessionId) . '&limit=1';
    $sessRes = supabase_request('GET', $sessUrl, $readHeaders);
    $sessRows = json_decode((string) ($sessRes['body'] ?? ''), true);
    $sess = is_array($sessRows) && isset($sessRows[0]) && is_array($sessRows[0]) ? $sessRows[0] : null;
    if (!$sess || (string) ($sess['event_id'] ?? '') !== $eventId) {
        return null;
    }

    $getUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/certificate_templates'
        . '?select=id,title,body_text,footer_text,canvas_state,thumbnail_url,created_by,event_id'
        . '&id=eq.' . rawurlencode($templateId) . '&limit=1';
    $getRes = supabase_request('GET', $getUrl, $readHeaders);
    $rows = json_decode((string) ($getRes['body'] ?? ''), true);
    $src = is_array($rows) && isset($rows[0]) && is_array($rows[0]) ? $rows[0] : null;
    if (!$src) {
        return null;
    }
    $owner = (string) ($src['created_by'] ?? '');
    $linked = (string) ($src['event_id'] ?? '');
    if ($owner !== $userId && $linked !== $eventId) {
        return null;
    }

    $title = trim((string) ($src['title'] ?? '')) !== ''
        ? (string) $src['title']
        : 'Certificate of Participation';
    $payload = [
        'session_id' => $sessionId,
        'title' => $title,
        'body_text' => (string) ($src['body_text'] ?? 'This certifies that {{name}} participated in {{session}}.'),
        'footer_text' => $src['footer_text'] ?? null,
        'canvas_state' => $src['canvas_state'] ?? null,
        'thumbnail_url' => $src['thumbnail_url'] ?? null,
        'created_by' => $userId !== '' ? $userId : null,
        'updated_at' => gmdate('c'),
    ];

    // Reuse latest session template row if present (keeps one active design per seminar).
    $existingUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/event_session_certificate_templates'
        . '?select=id&session_id=eq.' . rawurlencode($sessionId)
        . '&order=created_at.desc&limit=1';
    $existingRes = supabase_request('GET', $existingUrl, $readHeaders);
    $existingRows = json_decode((string) ($existingRes['body'] ?? ''), true);
    $existingId = is_array($existingRows) && isset($existingRows[0]['id'])
        ? (string) $existingRows[0]['id']
        : '';

    if ($existingId !== '') {
        $patchUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/event_session_certificate_templates?id=eq.' . rawurlencode($existingId);
        $patch = supabase_request('PATCH', $patchUrl, $writeHeaders, json_encode($payload));
        if (!$patch['ok']) {
            return null;
        }
        $sessionTemplateId = $existingId;
    } else {
        $postPayload = $payload;
        $postPayload['created_at'] = gmdate('c');
        $postUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/event_session_certificate_templates';
        $post = supabase_request('POST', $postUrl, $writeHeaders, json_encode($postPayload));
        if (!$post['ok']) {
            return null;
        }
        $posted = json_decode((string) ($post['body'] ?? ''), true);
        $sessionTemplateId = is_array($posted) && isset($posted[0]['id'])
            ? (string) $posted[0]['id']
            : (is_array($posted) && isset($posted['id']) ? (string) $posted['id'] : '');
        if ($sessionTemplateId === '') {
            return null;
        }
    }

    return [
        'id' => $sessionTemplateId,
        'title' => $title,
        'thumbnail_url' => (string) ($src['thumbnail_url'] ?? ''),
        'session_id' => $sessionId,
        'source_template_id' => $templateId,
        'session_title' => (string) ($sess['title'] ?? ''),
    ];
}
