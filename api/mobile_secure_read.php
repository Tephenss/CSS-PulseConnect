<?php
declare(strict_types=1);

/**
 * Session-bound reads for sensitive tables revoked from anon.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/supabase.php';
require_once __DIR__ . '/../includes/json.php';
require_once __DIR__ . '/../includes/mobile_api.php';
require_once __DIR__ . '/../includes/mobile_session.php';
require_once __DIR__ . '/../includes/api_rate_limit.php';
require_once __DIR__ . '/../includes/mobile_secure_access.php';

$data = mobile_api_require_post_json();
mobile_api_validate_key($data);
$sessionUser = mobile_api_require_user($data);
$userId = (string) ($sessionUser['id'] ?? '');
$role = strtolower(trim((string) ($sessionUser['role'] ?? 'student')));

$clientIp = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
if (!api_rate_limit_allow('mobile_secure_read:' . $userId . ':' . $clientIp, 120, 60)) {
    json_response(['ok' => false, 'error' => 'Too many requests.'], 429);
}

$table = trim((string) ($data['table'] ?? ''));
$select = trim((string) ($data['select'] ?? '*'));
$filters = $data['filters'] ?? [];
$limit = (int) ($data['limit'] ?? 100);
$limit = max(1, min(500, $limit));

$allowed = [
    'event_registrations',
    'tickets',
    'attendance',
    'event_session_attendance',
    'event_student_requirements',
    'event_student_documents',
    'event_student_submissions',
    'user_notifications',
    'user_notification_reads',
    'user_notification_watermarks',
    'fcm_tokens',
    'attendance_absence_reasons',
    'evaluation_answers',
    'event_session_evaluation_answers',
];

if (!in_array($table, $allowed, true)) {
    json_response(['ok' => false, 'error' => 'Table not allowed.'], 400);
}
if ($select === '' || !preg_match('/^[a-z0-9_,.\s*]+$/i', $select)) {
    json_response(['ok' => false, 'error' => 'Invalid select.'], 400);
}
if (!is_array($filters)) {
    json_response(['ok' => false, 'error' => 'filters must be an object.'], 400);
}

$readHeaders = mobile_api_supabase_headers();
$isAdmin = mobile_secure_is_admin_role($role) || $role === 'admin';

// Force ownership scoping for student role on user-bound tables.
$studentScoped = [
    'event_registrations' => 'student_id',
    'event_student_documents' => 'student_id',
    'event_student_submissions' => 'student_id',
    'user_notifications' => 'user_id',
    'user_notification_reads' => 'user_id',
    'user_notification_watermarks' => 'user_id',
    'fcm_tokens' => 'user_id',
    'attendance_absence_reasons' => 'student_id',
    'evaluation_answers' => 'student_id',
    'event_session_evaluation_answers' => 'student_id',
];

if ($role === 'student' && isset($studentScoped[$table])) {
    $filters[$studentScoped[$table]] = $userId;
}

// Ticket / attendance / requirements: never allow unscoped dumps.
$eventScopedTables = ['tickets', 'attendance', 'event_session_attendance', 'event_student_requirements'];
if (in_array($table, $eventScopedTables, true)) {
    $eventId = trim((string) ($filters['event_id'] ?? ''));
    // tickets / attendance do not have event_id column — strip before query.
    if (in_array($table, ['tickets', 'attendance', 'event_session_attendance'], true)) {
        unset($filters['event_id']);
    }

    if ($role === 'student') {
        if ($table === 'event_student_requirements') {
            if ($eventId === '' || !mobile_secure_is_uuid($eventId)) {
                json_response(['ok' => false, 'error' => 'event_id required.'], 400);
            }
            $regsForEvent = mobile_secure_owned_registration_ids($userId, $readHeaders, $eventId);
            if ($regsForEvent === []) {
                json_response(['ok' => false, 'error' => 'Forbidden.'], 403);
            }
            $filters['event_id'] = $eventId;
        } else {
            $ownedRegs = mobile_secure_owned_registration_ids($userId, $readHeaders, $eventId !== '' ? $eventId : null);
            if ($ownedRegs === []) {
                json_response(['ok' => true, 'rows' => []], 200);
            }
            if ($table === 'tickets') {
                $filters['registration_id'] = $ownedRegs;
            } elseif ($table === 'attendance') {
                $ticketIds = mobile_secure_ticket_ids_for_registrations($ownedRegs, $readHeaders);
                if ($ticketIds === []) {
                    json_response(['ok' => true, 'rows' => []], 200);
                }
                $filters['ticket_id'] = $ticketIds;
            } elseif ($table === 'event_session_attendance') {
                $filters['registration_id'] = $ownedRegs;
            }
        }
    } elseif ($role === 'teacher' || $isAdmin) {
        if ($eventId === '' || !mobile_secure_is_uuid($eventId)) {
            json_response(['ok' => false, 'error' => 'event_id required for this table.'], 400);
        }
        if (!$isAdmin && !mobile_secure_is_event_staff($eventId, $userId, $readHeaders, false)) {
            json_response(['ok' => false, 'error' => 'Forbidden.'], 403);
        }
        if ($table === 'event_student_requirements') {
            $filters['event_id'] = $eventId;
        } else {
            $regUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/event_registrations'
                . '?select=id&event_id=eq.' . rawurlencode($eventId)
                . '&limit=500';
            $regIds = mobile_secure_fetch_ids($regUrl, $readHeaders);
            if ($regIds === []) {
                json_response(['ok' => true, 'rows' => []], 200);
            }
            if ($table === 'tickets' || $table === 'event_session_attendance') {
                $filters['registration_id'] = $regIds;
            } elseif ($table === 'attendance') {
                $ticketIds = mobile_secure_ticket_ids_for_registrations($regIds, $readHeaders);
                if ($ticketIds === []) {
                    json_response(['ok' => true, 'rows' => []], 200);
                }
                $filters['ticket_id'] = $ticketIds;
            }
        }
    } else {
        json_response(['ok' => false, 'error' => 'Forbidden.'], 403);
    }
}

// Teachers must not read other users' notification/FCM/doc rows without scope.
if ($role === 'teacher' && isset($studentScoped[$table])) {
    // Allow event-scoped document tables for staff of that event only.
    if (in_array($table, ['event_student_documents', 'event_student_submissions'], true)) {
        $eventId = trim((string) ($filters['event_id'] ?? ''));
        if ($eventId === '' || !mobile_secure_is_uuid($eventId)) {
            json_response(['ok' => false, 'error' => 'event_id required.'], 400);
        }
        if (!mobile_secure_is_event_staff($eventId, $userId, $readHeaders, false)) {
            json_response(['ok' => false, 'error' => 'Forbidden.'], 403);
        }
    } elseif (in_array($table, ['event_registrations', 'attendance_absence_reasons'], true)) {
        $eventId = trim((string) ($filters['event_id'] ?? ''));
        $studentFilter = trim((string) ($filters['student_id'] ?? ''));
        if ($eventId !== '' && mobile_secure_is_uuid($eventId)) {
            if (!mobile_secure_is_event_staff($eventId, $userId, $readHeaders, false)) {
                json_response(['ok' => false, 'error' => 'Forbidden.'], 403);
            }
        } elseif ($studentFilter !== '' && $studentFilter === $userId) {
            // Teacher reading own student-scoped rows (e.g. absence as participant) — ok.
        } else {
            json_response(['ok' => false, 'error' => 'event_id or own student_id required.'], 400);
        }
    } else {
        // Notifications / FCM / evaluations: force own user id.
        $ownerCol = $studentScoped[$table];
        $filters[$ownerCol] = $userId;
    }
}

$query = 'select=' . rawurlencode($select);
foreach ($filters as $key => $value) {
    $col = (string) $key;
    if (!preg_match('/^[a-z0-9_]+$/i', $col)) {
        continue;
    }
    if (is_array($value)) {
        $ids = [];
        foreach ($value as $item) {
            $item = trim((string) $item);
            if (mobile_secure_is_uuid($item)) {
                $ids[] = $item;
            }
        }
        if ($ids === []) {
            json_response(['ok' => true, 'rows' => []], 200);
        }
        $query .= '&' . rawurlencode($col) . '=in.' . mobile_secure_postgrest_in_list($ids);
        continue;
    }
    $val = is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
    $query .= '&' . rawurlencode($col) . '=eq.' . rawurlencode($val);
}
$query .= '&limit=' . $limit;

$url = rtrim(SUPABASE_URL, '/') . '/rest/v1/' . $table . '?' . $query;
$res = supabase_request('GET', $url, $readHeaders);
if (!$res['ok']) {
    json_response(['ok' => false, 'error' => 'Read failed.'], 500);
}

$rows = json_decode((string) $res['body'], true);
json_response([
    'ok' => true,
    'rows' => is_array($rows) ? $rows : [],
], 200);
