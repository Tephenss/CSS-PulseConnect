<?php
declare(strict_types=1);

/**
 * Shared ownership / filter helpers for mobile secure read/write and signed URLs.
 */

function mobile_secure_is_admin_role(string $role): bool
{
    return in_array(strtolower(trim($role)), ['admin', 'super_admin'], true);
}

function mobile_secure_is_event_staff(string $eventId, string $userId, array $headers, bool $requireScan = false): bool
{
    if ($eventId === '' || $userId === '') {
        return false;
    }
    $eventUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/events'
        . '?select=id,created_by&id=eq.' . rawurlencode($eventId) . '&limit=1';
    $eventRes = supabase_request('GET', $eventUrl, $headers);
    $eventRows = json_decode((string) ($eventRes['body'] ?? ''), true);
    $event = is_array($eventRows) && isset($eventRows[0]) ? $eventRows[0] : null;
    if (is_array($event) && (string) ($event['created_by'] ?? '') === $userId) {
        return true;
    }

    $assignUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/event_teacher_assignments'
        . '?select=id&event_id=eq.' . rawurlencode($eventId)
        . '&teacher_id=eq.' . rawurlencode($userId);
    if ($requireScan) {
        $assignUrl .= '&can_scan=eq.true';
    }
    $assignUrl .= '&limit=1';
    $assignRes = supabase_request('GET', $assignUrl, $headers);
    $assignRows = json_decode((string) ($assignRes['body'] ?? ''), true);
    if (is_array($assignRows) && count($assignRows) > 0) {
        return true;
    }

    $assistUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/event_assistants'
        . '?select=id&event_id=eq.' . rawurlencode($eventId)
        . '&student_id=eq.' . rawurlencode($userId);
    if ($requireScan) {
        $assistUrl .= '&allow_scan=eq.true';
    }
    $assistUrl .= '&limit=1';
    $assistRes = supabase_request('GET', $assistUrl, $headers);
    $assistRows = json_decode((string) ($assistRes['body'] ?? ''), true);
    return is_array($assistRows) && count($assistRows) > 0;
}

function mobile_secure_can_manage_assistants(string $eventId, string $teacherId, array $headers): bool
{
    if ($eventId === '' || $teacherId === '') {
        return false;
    }
    $manageUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/event_teacher_assignments'
        . '?select=id&event_id=eq.' . rawurlencode($eventId)
        . '&teacher_id=eq.' . rawurlencode($teacherId)
        . '&can_manage_assistants=eq.true&limit=1';
    $manageRes = supabase_request('GET', $manageUrl, $headers);
    $manageRows = json_decode((string) ($manageRes['body'] ?? ''), true);
    if (is_array($manageRows) && count($manageRows) > 0) {
        return true;
    }
    $scanUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/event_teacher_assignments'
        . '?select=id&event_id=eq.' . rawurlencode($eventId)
        . '&teacher_id=eq.' . rawurlencode($teacherId)
        . '&can_scan=eq.true&limit=1';
    $scanRes = supabase_request('GET', $scanUrl, $headers);
    $scanRows = json_decode((string) ($scanRes['body'] ?? ''), true);
    return is_array($scanRows) && count($scanRows) > 0;
}

function mobile_secure_is_event_creator(string $eventId, string $userId, array $headers): bool
{
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

function mobile_secure_can_write_attendance(string $eventId, string $userId, string $role, array $headers): bool
{
    if (mobile_secure_is_admin_role($role) || $role === 'admin') {
        return true;
    }
    return mobile_secure_is_event_staff($eventId, $userId, $headers, true);
}

function mobile_secure_is_uuid(string $value): bool
{
    return (bool) preg_match(
        '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
        $value
    );
}

/**
 * @return list<string>
 */
function mobile_secure_fetch_ids(string $url, array $headers): array
{
    $res = supabase_request('GET', $url, $headers);
    if (!($res['ok'] ?? false)) {
        return [];
    }
    $rows = json_decode((string) ($res['body'] ?? ''), true);
    if (!is_array($rows)) {
        return [];
    }
    $ids = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $id = trim((string) ($row['id'] ?? ''));
        if ($id !== '' && mobile_secure_is_uuid($id)) {
            $ids[] = $id;
        }
    }
    return array_values(array_unique($ids));
}

/**
 * @return list<string>
 */
function mobile_secure_owned_registration_ids(string $userId, array $headers, ?string $eventId = null): array
{
    if ($userId === '' || !mobile_secure_is_uuid($userId)) {
        return [];
    }
    $url = rtrim(SUPABASE_URL, '/') . '/rest/v1/event_registrations'
        . '?select=id&student_id=eq.' . rawurlencode($userId);
    if ($eventId !== null && $eventId !== '') {
        $url .= '&event_id=eq.' . rawurlencode($eventId);
    }
    $url .= '&limit=500';
    return mobile_secure_fetch_ids($url, $headers);
}

/**
 * @param list<string> $registrationIds
 * @return list<string>
 */
function mobile_secure_ticket_ids_for_registrations(array $registrationIds, array $headers): array
{
    $registrationIds = array_values(array_filter($registrationIds, 'mobile_secure_is_uuid'));
    if ($registrationIds === []) {
        return [];
    }
    $inList = '(' . implode(',', array_map('rawurlencode', $registrationIds)) . ')';
    $url = rtrim(SUPABASE_URL, '/') . '/rest/v1/tickets'
        . '?select=id&registration_id=in.' . $inList
        . '&limit=500';
    return mobile_secure_fetch_ids($url, $headers);
}

/**
 * @param list<string> $ids
 */
function mobile_secure_postgrest_in_list(array $ids): string
{
    $clean = [];
    foreach ($ids as $id) {
        $id = trim((string) $id);
        if (mobile_secure_is_uuid($id)) {
            $clean[] = $id;
        }
    }
    return '(' . implode(',', $clean) . ')';
}

/**
 * Parse attendance PATCH filters into safe PostgREST clauses.
 * Allowed: id|ticket_id|registration_id|session_id =eq.<uuid>
 * and check_in_at|check_out_at =is.null
 *
 * @return array{ok:bool,query?:string,error?:string}
 */
function mobile_secure_parse_attendance_filter(string $filter): array
{
    $filter = trim($filter);
    if ($filter === '') {
        return ['ok' => false, 'error' => 'Invalid filter.'];
    }
    $parts = explode('&', $filter);
    $clauses = [];
    $allowedEq = ['id', 'ticket_id', 'registration_id', 'session_id'];
    $allowedIs = ['check_in_at', 'check_out_at'];
    foreach ($parts as $part) {
        $part = trim($part);
        if ($part === '') {
            continue;
        }
        if (preg_match('/^([a-z0-9_]+)=eq\.([0-9a-f-]{36})$/i', $part, $m)) {
            $col = strtolower($m[1]);
            $val = $m[2];
            if (!in_array($col, $allowedEq, true) || !mobile_secure_is_uuid($val)) {
                return ['ok' => false, 'error' => 'Invalid filter.'];
            }
            $clauses[] = rawurlencode($col) . '=eq.' . rawurlencode($val);
            continue;
        }
        if (preg_match('/^([a-z0-9_]+)=is\.null$/i', $part, $m)) {
            $col = strtolower($m[1]);
            if (!in_array($col, $allowedIs, true)) {
                return ['ok' => false, 'error' => 'Invalid filter.'];
            }
            $clauses[] = rawurlencode($col) . '=is.null';
            continue;
        }
        return ['ok' => false, 'error' => 'Invalid filter.'];
    }
    if ($clauses === []) {
        return ['ok' => false, 'error' => 'Invalid filter.'];
    }
    return ['ok' => true, 'query' => implode('&', $clauses)];
}

/**
 * @return array{event_id:string,student_id:string}|null
 */
function mobile_secure_attendance_row_owner(string $table, array $row, array $headers): ?array
{
    if ($table === 'event_session_attendance') {
        $registrationId = trim((string) ($row['registration_id'] ?? ''));
        if ($registrationId === '' || !mobile_secure_is_uuid($registrationId)) {
            return null;
        }
        $url = rtrim(SUPABASE_URL, '/') . '/rest/v1/event_registrations'
            . '?select=event_id,student_id&id=eq.' . rawurlencode($registrationId)
            . '&limit=1';
        $res = supabase_request('GET', $url, $headers);
        $rows = json_decode((string) ($res['body'] ?? ''), true);
        $reg = is_array($rows) && isset($rows[0]) && is_array($rows[0]) ? $rows[0] : null;
        if (!is_array($reg)) {
            return null;
        }
        $eventId = trim((string) ($reg['event_id'] ?? ''));
        $studentId = trim((string) ($reg['student_id'] ?? ''));
        if ($eventId === '' || $studentId === '') {
            return null;
        }
        $sessionId = trim((string) ($row['session_id'] ?? ''));
        if ($sessionId !== '' && mobile_secure_is_uuid($sessionId)) {
            $sessionUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/event_sessions'
                . '?select=id,event_id&id=eq.' . rawurlencode($sessionId)
                . '&limit=1';
            $sessionRes = supabase_request('GET', $sessionUrl, $headers);
            $sessionRows = json_decode((string) ($sessionRes['body'] ?? ''), true);
            $session = is_array($sessionRows) && isset($sessionRows[0]) && is_array($sessionRows[0])
                ? $sessionRows[0]
                : null;
            if (!is_array($session) || trim((string) ($session['event_id'] ?? '')) !== $eventId) {
                return null;
            }
        }
        return ['event_id' => $eventId, 'student_id' => $studentId];
    }

    $ticketId = trim((string) ($row['ticket_id'] ?? ''));
    if ($ticketId === '' || !mobile_secure_is_uuid($ticketId)) {
        return null;
    }
    $url = rtrim(SUPABASE_URL, '/') . '/rest/v1/tickets'
        . '?select=id,registration_id,event_registrations(event_id,student_id)'
        . '&id=eq.' . rawurlencode($ticketId)
        . '&limit=1';
    $res = supabase_request('GET', $url, $headers);
    $rows = json_decode((string) ($res['body'] ?? ''), true);
    $ticket = is_array($rows) && isset($rows[0]) && is_array($rows[0]) ? $rows[0] : null;
    if (!is_array($ticket)) {
        return null;
    }
    $reg = $ticket['event_registrations'] ?? null;
    if (is_array($reg) && isset($reg[0]) && is_array($reg[0])) {
        $reg = $reg[0];
    }
    if (!is_array($reg)) {
        return null;
    }
    $eventId = trim((string) ($reg['event_id'] ?? ''));
    $studentId = trim((string) ($reg['student_id'] ?? ''));
    if ($eventId === '' || $studentId === '') {
        return null;
    }
    return ['event_id' => $eventId, 'student_id' => $studentId];
}

/**
 * Verify ticket/registration payload targets belong to the claimed event.
 */
function mobile_secure_attendance_payload_belongs_to_event(
    string $table,
    array $payload,
    string $eventId,
    array $headers
): bool {
    if ($eventId === '' || !mobile_secure_is_uuid($eventId)) {
        return false;
    }
    $ticketId = trim((string) ($payload['ticket_id'] ?? ''));
    $registrationId = trim((string) ($payload['registration_id'] ?? ''));
    if ($ticketId !== '' && mobile_secure_is_uuid($ticketId)) {
        $owner = mobile_secure_attendance_row_owner('attendance', ['ticket_id' => $ticketId], $headers);
        return is_array($owner) && ($owner['event_id'] ?? '') === $eventId;
    }
    if ($table === 'event_session_attendance' && $registrationId !== '' && mobile_secure_is_uuid($registrationId)) {
        $owner = mobile_secure_attendance_row_owner(
            'event_session_attendance',
            [
                'registration_id' => $registrationId,
                'session_id' => trim((string) ($payload['session_id'] ?? '')),
            ],
            $headers
        );
        return is_array($owner) && ($owner['event_id'] ?? '') === $eventId;
    }
    // Staff marking absent sometimes posts without resolving owner yet — reject unscoped.
    return false;
}

/**
 * Path ownership for private storage buckets.
 */
function mobile_secure_storage_path_allowed(
    string $bucket,
    string $path,
    string $userId,
    string $role,
    array $headers
): bool {
    $path = trim($path);
    $userId = trim($userId);
    if ($path === '' || str_contains($path, '..') || $userId === '') {
        return false;
    }
    $normalized = '/' . trim($path, '/') . '/';
    $isAdmin = mobile_secure_is_admin_role($role) || $role === 'admin';

    if ($bucket === 'avatars') {
        // Own avatar: profiles/{userId}.ext or media/avatars/profiles/{userId}.ext
        $base = basename(str_replace('\\', '/', $path));
        if ($base !== '' && str_starts_with(strtolower($base), strtolower($userId) . '.')) {
            return true;
        }
        if (str_contains($normalized, '/' . $userId . '/')
            || str_contains($normalized, '/' . $userId . '.')) {
            return true;
        }
        // Teachers/admins need participant photos on roster + scanner.
        // Canonical profile objects only — never arbitrary bucket paths.
        if ($isAdmin || $role === 'teacher') {
            $object = ltrim(str_replace('\\', '/', $path), '/');
            if (str_starts_with($object, 'media/avatars/')) {
                $object = substr($object, strlen('media/avatars/'));
            }
            if (str_starts_with($object, 'avatars/')) {
                $object = substr($object, strlen('avatars/'));
            }
            return (bool) preg_match(
                '#^profiles/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\.(jpe?g|png|webp)$#i',
                $object
            );
        }
        return false;
    }

    if ($bucket === 'proposal-documents') {
        if ($isAdmin) {
            return true;
        }
        return str_contains($normalized, '/' . $userId . '/');
    }

    if ($bucket === 'student-documents') {
        if ($role === 'student') {
            return str_contains($normalized, '/' . $userId . '/');
        }
        if ($isAdmin) {
            return true;
        }
        // Teachers: first path segment is event_id.
        $parts = explode('/', trim($path, '/'));
        $eventId = trim((string) ($parts[0] ?? ''));
        if ($eventId === '' || !mobile_secure_is_uuid($eventId)) {
            return false;
        }
        return mobile_secure_is_event_staff($eventId, $userId, $headers, false)
            || mobile_secure_is_event_creator($eventId, $userId, $headers);
    }

    return false;
}
