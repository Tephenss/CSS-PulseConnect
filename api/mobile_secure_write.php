<?php
declare(strict_types=1);

/**
 * Session-bound writes for tables revoked from anon (attendance, tickets, FCM, evaluations).
 * Allowlisted actions only — never arbitrary PostgREST.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/supabase.php';
require_once __DIR__ . '/../includes/json.php';
require_once __DIR__ . '/../includes/mobile_api.php';
require_once __DIR__ . '/../includes/mobile_session.php';
require_once __DIR__ . '/../includes/api_rate_limit.php';
require_once __DIR__ . '/../includes/registration_access.php';
require_once __DIR__ . '/../includes/proposal_requirements.php';
require_once __DIR__ . '/../includes/student_requirements.php';
require_once __DIR__ . '/../includes/event_schedule_conflict.php';
require_once __DIR__ . '/../includes/mobile_secure_access.php';

$data = mobile_api_require_post_json();
mobile_api_validate_key($data);
$sessionUser = mobile_api_require_user($data);
$userId = (string) ($sessionUser['id'] ?? '');
$role = strtolower(trim((string) ($sessionUser['role'] ?? 'student')));
$action = strtolower(trim((string) ($data['action'] ?? '')));

$clientIp = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
if (!api_rate_limit_allow('mobile_secure_write:' . $userId . ':' . $clientIp, 60, 60)) {
    json_response(['ok' => false, 'error' => 'Too many requests. Please wait.'], 429);
}

$writeHeaders = mobile_api_supabase_write_headers();
$readHeaders = mobile_api_supabase_headers();
$reprHeaders = [
    'Content-Type: application/json',
    'Accept: application/json',
    'apikey: ' . SUPABASE_KEY,
    'Authorization: Bearer ' . SUPABASE_KEY,
    'Prefer: return=representation',
];

switch ($action) {
    case 'fcm_upsert': {
        $token = trim((string) ($data['token'] ?? ''));
        if ($token === '') {
            json_response(['ok' => false, 'error' => 'token required.'], 400);
        }
        // Keep payload minimal — older fcm_tokens schemas may lack `platform`.
        $payload = [
            'user_id' => $userId,
            'token' => $token,
            'updated_at' => gmdate('c'),
        ];
        $headers = [
            'Content-Type: application/json',
            'Accept: application/json',
            'apikey: ' . SUPABASE_KEY,
            'Authorization: Bearer ' . SUPABASE_KEY,
            'Prefer: return=minimal',
        ];
        // Production unique is on user_id (fcm_tokens_user_id_key), not token.
        // 1) Drop this device token from any previous account (daily OTP gate
        //    can leave yesterday's mapping on the same phone).
        // 2) Drop this user's other tokens, then insert — avoids 42P10
        //    (on_conflict=token) and 23505 when the same user refreshes.
        supabase_request(
            'DELETE',
            rtrim(SUPABASE_URL, '/') . '/rest/v1/fcm_tokens?token=eq.' . rawurlencode($token),
            $headers
        );
        supabase_request(
            'DELETE',
            rtrim(SUPABASE_URL, '/') . '/rest/v1/fcm_tokens?user_id=eq.' . rawurlencode($userId),
            $headers
        );
        $res = supabase_request(
            'POST',
            rtrim(SUPABASE_URL, '/') . '/rest/v1/fcm_tokens',
            $headers,
            json_encode($payload, JSON_UNESCAPED_SLASHES)
        );
        if (!($res['ok'] ?? false)) {
            error_log('fcm_upsert failed user=' . $userId . ' body=' . substr((string) ($res['body'] ?? ''), 0, 400));
        }
        json_response([
            'ok' => (bool) ($res['ok'] ?? false),
            'error' => ($res['ok'] ?? false) ? null : trim((string) ($res['body'] ?? 'FCM save failed')),
        ], ($res['ok'] ?? false) ? 200 : 500);
        break;
    }

    case 'fcm_delete': {
        $token = trim((string) ($data['token'] ?? ''));
        $url = rtrim(SUPABASE_URL, '/') . '/rest/v1/fcm_tokens?';
        if ($token !== '') {
            $url .= 'token=eq.' . rawurlencode($token);
        } else {
            $url .= 'user_id=eq.' . rawurlencode($userId);
        }
        $res = supabase_request('DELETE', $url, $readHeaders);
        json_response(['ok' => (bool) ($res['ok'] ?? false)], ($res['ok'] ?? false) ? 200 : 500);
        break;
    }

    case 'attendance_upsert': {
        $eventId = trim((string) ($data['event_id'] ?? ''));
        $payload = $data['payload'] ?? null;
        if (!is_array($payload) || $eventId === '' || !mobile_secure_is_uuid($eventId)) {
            json_response(['ok' => false, 'error' => 'event_id and payload required.'], 400);
        }
        // Teachers, admins, and student assistants may write attendance for their events.
        $isStaff = mobile_secure_can_write_attendance($eventId, $userId, $role, $readHeaders);
        $studentId = trim((string) ($payload['student_id'] ?? ''));
        if (!$isStaff && $studentId !== $userId) {
            json_response(['ok' => false, 'error' => 'Not allowed to write this attendance row.'], 403);
        }
        // attendance / event_session_attendance rows do not always carry event_id.
        unset($payload['event_id']);
        $table = trim((string) ($data['table'] ?? 'attendance'));
        if (!in_array($table, ['attendance', 'event_session_attendance'], true)) {
            json_response(['ok' => false, 'error' => 'Invalid attendance table.'], 400);
        }
        $method = strtoupper(trim((string) ($data['method'] ?? 'POST')));
        $filter = trim((string) ($data['filter'] ?? ''));
        $safeFilterQuery = '';
        $verifiedRows = [];
        if ($method === 'PATCH') {
            $parsed = mobile_secure_parse_attendance_filter($filter);
            if (!($parsed['ok'] ?? false)) {
                json_response(['ok' => false, 'error' => (string) ($parsed['error'] ?? 'Invalid filter.')], 400);
            }
            $safeFilterQuery = (string) ($parsed['query'] ?? '');
            $lookupUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/' . $table
                . '?' . $safeFilterQuery
                . '&select=id,ticket_id,registration_id,session_id,status,check_in_at,check_out_at'
                . '&limit=20';
            $lookupRes = supabase_request('GET', $lookupUrl, $readHeaders);
            $lookupRows = json_decode((string) ($lookupRes['body'] ?? ''), true);
            if (!is_array($lookupRows) || $lookupRows === []) {
                json_response(['ok' => false, 'error' => 'Attendance row not found.'], 404);
            }
            foreach ($lookupRows as $candidate) {
                if (!is_array($candidate)) {
                    continue;
                }
                $owner = mobile_secure_attendance_row_owner($table, $candidate, $readHeaders);
                if (!is_array($owner) || ($owner['event_id'] ?? '') !== $eventId) {
                    json_response(['ok' => false, 'error' => 'Not allowed to write this attendance row.'], 403);
                }
                if (!$isStaff && ($owner['student_id'] ?? '') !== $userId) {
                    json_response(['ok' => false, 'error' => 'Not allowed to write this attendance row.'], 403);
                }
                $verifiedRows[] = $candidate;
            }
            if ($verifiedRows === []) {
                json_response(['ok' => false, 'error' => 'Attendance row not found.'], 404);
            }
            // Target only verified row ids (ignore any other client filter tricks).
            $verifiedIds = [];
            foreach ($verifiedRows as $vr) {
                $rid = trim((string) ($vr['id'] ?? ''));
                if (mobile_secure_is_uuid($rid)) {
                    $verifiedIds[] = $rid;
                }
            }
            if ($verifiedIds === []) {
                json_response(['ok' => false, 'error' => 'Attendance row not found.'], 404);
            }
            $safeFilterQuery = 'id=in.' . mobile_secure_postgrest_in_list($verifiedIds);
            if (str_contains($filter, 'check_in_at=is.null')) {
                $safeFilterQuery .= '&check_in_at=is.null';
            }
            if (str_contains($filter, 'check_out_at=is.null')) {
                $safeFilterQuery .= '&check_out_at=is.null';
            }
        } else {
            // POST must target a ticket/registration that belongs to this event.
            if (!mobile_secure_attendance_payload_belongs_to_event($table, $payload, $eventId, $readHeaders)) {
                json_response(['ok' => false, 'error' => 'Attendance target does not belong to this event.'], 403);
            }
            if (!$isStaff) {
                $owner = null;
                $ticketId = trim((string) ($payload['ticket_id'] ?? ''));
                $registrationId = trim((string) ($payload['registration_id'] ?? ''));
                if ($ticketId !== '') {
                    $owner = mobile_secure_attendance_row_owner('attendance', ['ticket_id' => $ticketId], $readHeaders);
                } elseif ($registrationId !== '') {
                    $owner = mobile_secure_attendance_row_owner(
                        'event_session_attendance',
                        [
                            'registration_id' => $registrationId,
                            'session_id' => trim((string) ($payload['session_id'] ?? '')),
                        ],
                        $readHeaders
                    );
                }
                if (!is_array($owner) || ($owner['student_id'] ?? '') !== $userId) {
                    json_response(['ok' => false, 'error' => 'Not allowed to write this attendance row.'], 403);
                }
            }
        }
        $outgoingCheckOut = trim((string) ($payload['check_out_at'] ?? ''));
        $outgoingCheckIn = trim((string) ($payload['check_in_at'] ?? ''));
        $outgoingStatus = strtolower(trim((string) ($payload['status'] ?? '')));
        $isCheckOutOnly = $outgoingCheckOut !== ''
            && $outgoingCheckIn === ''
            && !in_array($outgoingStatus, ['present', 'checked_in', 'in', 'scanned', 'late', 'early'], true);
        if ($isCheckOutOnly) {
            $windowsPath = __DIR__ . '/../includes/event_attendance_windows.php';
            if (is_file($windowsPath)) {
                require_once $windowsPath;
            }
            $existingRow = $verifiedRows[0] ?? null;
            $hasTimeIn = function_exists('attendance_has_valid_time_in')
                ? attendance_has_valid_time_in($existingRow)
                : (is_array($existingRow)
                    && strtolower(trim((string) ($existingRow['status'] ?? ''))) !== 'absent'
                    && trim((string) ($existingRow['check_in_at'] ?? '')) !== '');
            if (!$hasTimeIn) {
                json_response([
                    'ok' => false,
                    'error' => 'Cannot time out — this student has no time-in (marked absent).',
                    'status' => 'absent_no_time_in',
                ], 409);
            }
        }
        if ($method === 'PATCH') {
            $url = rtrim(SUPABASE_URL, '/') . '/rest/v1/' . $table . '?' . $safeFilterQuery;
            $res = supabase_request('PATCH', $url, $reprHeaders, json_encode($payload, JSON_UNESCAPED_SLASHES));
        } else {
            $url = rtrim(SUPABASE_URL, '/') . '/rest/v1/' . $table;
            $res = supabase_request('POST', $url, $reprHeaders, json_encode($payload, JSON_UNESCAPED_SLASHES));
        }
        $rows = json_decode((string) ($res['body'] ?? ''), true);
        json_response([
            'ok' => (bool) $res['ok'],
            'rows' => is_array($rows) ? $rows : [],
            'error' => $res['ok'] ? null : 'Attendance write failed.',
        ], $res['ok'] ? 200 : 500);
        break;
    }

    case 'sync_closed_session_absences': {
        $eventId = trim((string) ($data['event_id'] ?? ''));
        if ($eventId === '') {
            json_response(['ok' => false, 'error' => 'event_id required.'], 400);
        }
        if (!mobile_secure_can_write_attendance($eventId, $userId, $role, $readHeaders)) {
            json_response(['ok' => false, 'error' => 'Not allowed.'], 403);
        }
        $rpcUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/rpc/sync_closed_session_absences';
        $res = supabase_request(
            'POST',
            $rpcUrl,
            $writeHeaders,
            json_encode(['p_event_id' => $eventId], JSON_UNESCAPED_SLASHES)
        );
        json_response([
            'ok' => (bool) ($res['ok'] ?? false),
            'error' => ($res['ok'] ?? false) ? null : 'Sync absences failed.',
        ], ($res['ok'] ?? false) ? 200 : 500);
        break;
    }

    case 'ticket_insert': {
        $registrationId = trim((string) ($data['registration_id'] ?? ''));
        $token = trim((string) ($data['token'] ?? ''));
        if ($registrationId === '' || $token === '') {
            json_response(['ok' => false, 'error' => 'registration_id and token required.'], 400);
        }
        // Verify registration belongs to session user (students) or staff.
        $regUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/event_registrations'
            . '?select=id,event_id,student_id&id=eq.' . rawurlencode($registrationId) . '&limit=1';
        $regRes = supabase_request('GET', $regUrl, $readHeaders);
        $regRows = json_decode((string) ($regRes['body'] ?? ''), true);
        $reg = is_array($regRows) && isset($regRows[0]) ? $regRows[0] : null;
        if (!is_array($reg)) {
            json_response(['ok' => false, 'error' => 'Registration not found.'], 404);
        }
        $regStudent = (string) ($reg['student_id'] ?? '');
        $eventId = (string) ($reg['event_id'] ?? '');
        $isStaff = mobile_secure_is_event_staff($eventId, $userId, $readHeaders);
        if (!$isStaff && $regStudent !== $userId) {
            json_response(['ok' => false, 'error' => 'Not allowed.'], 403);
        }
        $payload = ['registration_id' => $registrationId, 'token' => $token];
        $res = supabase_request(
            'POST',
            rtrim(SUPABASE_URL, '/') . '/rest/v1/tickets',
            $reprHeaders,
            json_encode($payload, JSON_UNESCAPED_SLASHES)
        );
        $rows = json_decode((string) ($res['body'] ?? ''), true);
        json_response([
            'ok' => (bool) $res['ok'],
            'rows' => is_array($rows) ? $rows : [],
        ], $res['ok'] ? 200 : 500);
        break;
    }

    case 'registration_insert': {
        if ($role !== 'student' && $role !== 'teacher' && $role !== 'admin') {
            json_response(['ok' => false, 'error' => 'Not allowed.'], 403);
        }
        // Prefer dedicated mobile_register_event.php; this is a constrained fallback.
        $eventId = trim((string) ($data['event_id'] ?? ''));
        $studentId = trim((string) ($data['student_id'] ?? $userId));
        if ($eventId === '' || !mobile_secure_is_uuid($eventId)) {
            json_response(['ok' => false, 'error' => 'event_id required.'], 400);
        }
        if ($studentId !== $userId) {
            if ($role === 'admin') {
                // ok
            } elseif ($role === 'teacher' && mobile_secure_is_event_staff($eventId, $userId, $readHeaders, false)) {
                // ok — creator / assigned teacher only
            } else {
                json_response(['ok' => false, 'error' => 'Not allowed.'], 403);
            }
        }
        $payload = ['event_id' => $eventId, 'student_id' => $studentId];
        $res = supabase_request(
            'POST',
            rtrim(SUPABASE_URL, '/') . '/rest/v1/event_registrations',
            $reprHeaders,
            json_encode($payload, JSON_UNESCAPED_SLASHES)
        );
        $rows = json_decode((string) ($res['body'] ?? ''), true);
        json_response([
            'ok' => (bool) $res['ok'],
            'rows' => is_array($rows) ? $rows : [],
        ], $res['ok'] ? 200 : 500);
        break;
    }

    case 'evaluation_upsert': {
        $table = trim((string) ($data['table'] ?? ''));
        if (!in_array($table, ['evaluation_answers', 'event_session_evaluation_answers'], true)) {
            json_response(['ok' => false, 'error' => 'Invalid evaluation table.'], 400);
        }
        $rowsIn = $data['rows'] ?? null;
        if (!is_array($rowsIn) || $rowsIn === []) {
            json_response(['ok' => false, 'error' => 'rows required.'], 400);
        }

        require_once __DIR__ . '/../includes/event_sessions.php';
        require_once __DIR__ . '/../includes/certificate_auto_issue.php';
        require_once __DIR__ . '/../includes/evaluation_notifications.php';

        $normalized = [];
        $checkedEventIds = [];
        $checkedSessionIds = [];

        foreach ($rowsIn as $row) {
            if (!is_array($row)) {
                json_response(['ok' => false, 'error' => 'Invalid row.'], 400);
            }
            $sid = trim((string) ($row['student_id'] ?? $row['user_id'] ?? ''));
            if ($sid !== '' && $sid !== $userId) {
                json_response(['ok' => false, 'error' => 'Cannot submit evaluation for another user.'], 403);
            }
            $row['student_id'] = $userId;

            if ($table === 'evaluation_answers') {
                $eid = trim((string) ($row['event_id'] ?? ''));
                if ($eid === '') {
                    json_response(['ok' => false, 'error' => 'event_id required on evaluation answers.'], 400);
                }
                if (!isset($checkedEventIds[$eid])) {
                    $eventUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/events'
                        . '?select=id,event_mode,event_structure'
                        . '&id=eq.' . rawurlencode($eid) . '&limit=1';
                    $eventRes = supabase_request('GET', $eventUrl, $readHeaders);
                    $eventRows = $eventRes['ok'] ? json_decode((string) ($eventRes['body'] ?? ''), true) : [];
                    $eventRow = is_array($eventRows) && isset($eventRows[0]) ? $eventRows[0] : null;
                    if (!is_array($eventRow)) {
                        json_response(['ok' => false, 'error' => 'Event not found.'], 404);
                    }
                    $sessions = fetch_event_sessions($eid, $readHeaders);
                    $usesSessions = event_uses_sessions(array_merge($eventRow, ['sessions' => $sessions]));
                    if ($usesSessions) {
                        $checkedOut = certificate_auto_checked_out_session_ids($sessions, $userId, $readHeaders);
                        $final = evaluation_final_seminar_for_event($eid);
                        $finalId = $final !== null ? trim((string) ($final['id'] ?? '')) : '';
                        // 2 seminars: wait for Seminar 2 out — never open on Seminar 1 alone.
                        if ($finalId !== '') {
                            $ok = in_array($finalId, $checkedOut, true);
                        } elseif (evaluation_event_expects_two_seminars($eid)) {
                            $ok = false;
                        } else {
                            $ok = $checkedOut !== [];
                        }
                        if (!$ok) {
                            json_response([
                                'ok' => false,
                                'error' => $finalId !== ''
                                    ? 'Evaluation opens only after you time out of the final seminar.'
                                    : 'Time-out required before submitting evaluation.',
                                'status' => 'checkout_required',
                            ], 403);
                        }
                    } elseif (!certificate_auto_student_has_checkout_simple($eid, $userId, $readHeaders)) {
                        json_response([
                            'ok' => false,
                            'error' => 'Time-out required before submitting evaluation.',
                            'status' => 'checkout_required',
                        ], 403);
                    }
                    $checkedEventIds[$eid] = true;
                }
            } else {
                $sessionId = trim((string) ($row['session_id'] ?? ''));
                if ($sessionId === '') {
                    json_response(['ok' => false, 'error' => 'session_id required.'], 400);
                }
                if (!isset($checkedSessionIds[$sessionId])) {
                    $sessUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/event_sessions'
                        . '?select=id,event_id&id=eq.' . rawurlencode($sessionId) . '&limit=1';
                    $sessRes = supabase_request('GET', $sessUrl, $readHeaders);
                    $sessRows = $sessRes['ok'] ? json_decode((string) ($sessRes['body'] ?? ''), true) : [];
                    $sess = is_array($sessRows) && isset($sessRows[0]) ? $sessRows[0] : null;
                    if (!is_array($sess)) {
                        json_response(['ok' => false, 'error' => 'Seminar not found.'], 404);
                    }
                    $eventIdForSession = trim((string) ($sess['event_id'] ?? ''));
                    $checkedOut = certificate_auto_checked_out_session_ids(
                        [['id' => $sessionId]],
                        $userId,
                        $readHeaders
                    );
                    if (!in_array($sessionId, $checkedOut, true)) {
                        json_response([
                            'ok' => false,
                            'error' => 'Time-out required before submitting seminar evaluation.',
                            'status' => 'checkout_required',
                        ], 403);
                    }
                    // Multi-seminar: all seminar sections wait for the final out.
                    if ($eventIdForSession !== '') {
                        $final = evaluation_final_seminar_for_event($eventIdForSession);
                        $finalId = $final !== null ? trim((string) ($final['id'] ?? '')) : '';
                        if ($finalId !== '') {
                            $allOut = certificate_auto_checked_out_session_ids(
                                fetch_event_sessions($eventIdForSession, $readHeaders),
                                $userId,
                                $readHeaders
                            );
                            if (!in_array($finalId, $allOut, true)) {
                                json_response([
                                    'ok' => false,
                                    'error' => 'Evaluation opens only after you time out of the final seminar.',
                                    'status' => 'checkout_required',
                                ], 403);
                            }
                        }
                    }
                    $checkedSessionIds[$sessionId] = true;
                }
            }
            $normalized[] = $row;
        }

        $res = supabase_request(
            'POST',
            rtrim(SUPABASE_URL, '/') . '/rest/v1/' . $table,
            // Prefer is already on $writeHeaders — do not merge a second Prefer line.
            $writeHeaders,
            json_encode($normalized, JSON_UNESCAPED_SLASHES)
        );
        $ok = (bool) ($res['ok'] ?? false);
        $status = (int) ($res['status'] ?? 0);
        $bodyLower = strtolower((string) ($res['body'] ?? '') . ' ' . (string) ($res['error'] ?? ''));
        // Idempotent re-submit: unique(question_id, student_id) conflict means
        // answers are already saved — treat as success (stops HTTP 500 loops).
        if (
            !$ok
            && (
                $status === 409
                || str_contains($bodyLower, 'duplicate')
                || str_contains($bodyLower, 'unique')
                || str_contains($bodyLower, 'conflict')
            )
        ) {
            $ok = true;
        }
        if (!$ok) {
            json_response([
                'ok' => false,
                'error' => build_error(
                    $res['body'] ?? null,
                    $status,
                    $res['error'] ?? null,
                    'Evaluation submission failed'
                ),
            ], $status >= 400 ? $status : 500);
        }
        // Certificate issuance is a separate BFF action after full eval submit.
        json_response(['ok' => true], 200);
        break;
    }

    case 'certificate_auto_issue': {
        // Student claims own cert after eval+checkout via service role (no anon write).
        if ($role !== 'student') {
            json_response(['ok' => false, 'error' => 'Only students can auto-claim certificates here.'], 403);
        }
        $eventId = trim((string) ($data['event_id'] ?? ''));
        if ($eventId === '') {
            json_response(['ok' => false, 'error' => 'event_id required.'], 400);
        }
        require_once __DIR__ . '/../includes/certificate_auto_issue.php';
        $cert = certificate_auto_issue_for_student($eventId, $userId, $readHeaders);
        // Always HTTP 200 when the action ran — skipped/issued=0 is not a transport failure.
        // (Eval submit must not look like a hard error because cert claim was soft-skipped.)
        json_response([
            'ok' => true,
            'certificate' => $cert,
        ], 200);
        break;
    }

    case 'certificate_auto_issue_pending': {
        // Student self-heal: retry FIFO issue for one or all registered events.
        if ($role !== 'student') {
            json_response(['ok' => false, 'error' => 'Only students can claim pending certificates here.'], 403);
        }
        require_once __DIR__ . '/../includes/certificate_auto_issue.php';
        $eventId = trim((string) ($data['event_id'] ?? ''));
        $pending = certificate_auto_issue_pending_for_student(
            $userId,
            $eventId !== '' ? $eventId : null,
            $readHeaders
        );
        json_response([
            'ok' => (bool) ($pending['ok'] ?? false),
            'issued_total' => (int) ($pending['issued_total'] ?? 0),
            'events' => $pending['events'] ?? [],
        ], ($pending['ok'] ?? false) ? 200 : 500);
        break;
    }

    case 'absence_reason_upsert': {
        $payload = $data['payload'] ?? null;
        if (!is_array($payload)) {
            json_response(['ok' => false, 'error' => 'payload required.'], 400);
        }
        $eventId = trim((string) ($payload['event_id'] ?? ''));
        $studentId = trim((string) ($payload['student_id'] ?? $userId));
        if ($eventId === '') {
            json_response(['ok' => false, 'error' => 'event_id required.'], 400);
        }
        $isStaff = mobile_secure_is_event_staff($eventId, $userId, $readHeaders);
        if (!$isStaff && $studentId !== $userId) {
            json_response(['ok' => false, 'error' => 'Not allowed.'], 403);
        }
        $reasonText = trim((string) ($payload['reason_text'] ?? ''));
        if ($reasonText === '') {
            json_response(['ok' => false, 'error' => 'reason_text required.'], 400);
        }
        $sessionId = trim((string) ($payload['session_id'] ?? ''));
        $cleanPayload = [
            'student_id' => $studentId,
            'event_id' => $eventId,
            'reason_text' => $reasonText,
            'review_status' => 'pending',
            'submitted_at' => trim((string) ($payload['submitted_at'] ?? gmdate('c'))),
            'admin_note' => null,
            'reviewed_at' => null,
            'reviewed_by' => null,
        ];
        if ($sessionId !== '') {
            $cleanPayload['session_id'] = $sessionId;
        }

        $method = strtoupper(trim((string) ($data['method'] ?? 'POST')));
        $filter = trim((string) ($data['filter'] ?? ''));
        if ($method === 'PATCH' && $filter !== '') {
            if (!preg_match('/^id=eq\.([0-9a-f-]{36})$/i', $filter, $fm) || !mobile_secure_is_uuid($fm[1])) {
                json_response(['ok' => false, 'error' => 'Invalid filter.'], 400);
            }
            $reasonId = $fm[1];
            $existingUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/attendance_absence_reasons'
                . '?select=id,student_id,event_id&id=eq.' . rawurlencode($reasonId)
                . '&limit=1';
            $existingRes = supabase_request('GET', $existingUrl, $readHeaders);
            $existingRows = json_decode((string) ($existingRes['body'] ?? ''), true);
            $existing = is_array($existingRows) && isset($existingRows[0]) && is_array($existingRows[0])
                ? $existingRows[0]
                : null;
            if (!is_array($existing)) {
                json_response(['ok' => false, 'error' => 'Absence reason not found.'], 404);
            }
            if ((string) ($existing['event_id'] ?? '') !== $eventId
                || (string) ($existing['student_id'] ?? '') !== $studentId) {
                json_response(['ok' => false, 'error' => 'Not allowed.'], 403);
            }
            $url = rtrim(SUPABASE_URL, '/') . '/rest/v1/attendance_absence_reasons?id=eq.' . rawurlencode($reasonId);
            $res = supabase_request('PATCH', $url, $reprHeaders, json_encode($cleanPayload, JSON_UNESCAPED_SLASHES));
        } else {
            $res = supabase_request(
                'POST',
                rtrim(SUPABASE_URL, '/') . '/rest/v1/attendance_absence_reasons',
                $reprHeaders,
                json_encode($cleanPayload, JSON_UNESCAPED_SLASHES)
            );
        }
        if (!($res['ok'] ?? false)) {
            $errBody = strtolower((string) ($res['body'] ?? ''));
            $errMsg = 'Failed to save your reason.';
            if (str_contains($errBody, 'attendance_absence_reasons')) {
                $errMsg = 'Absence reason storage is not available yet.';
            } elseif (str_contains($errBody, 'duplicate') || str_contains($errBody, 'unique')) {
                $errMsg = 'A reason was already submitted for this event.';
            }
            json_response([
                'ok' => false,
                'error' => $errMsg,
            ], 500);
        }
        json_response(['ok' => true], 200);
        break;
    }

    case 'notification_read': {
        $payload = $data['payload'] ?? null;
        if (!is_array($payload)) {
            json_response(['ok' => false, 'error' => 'payload required.'], 400);
        }
        $payload['user_id'] = $userId;
        $table = trim((string) ($data['table'] ?? 'user_notification_reads'));
        if (!in_array($table, ['user_notification_reads', 'user_notification_watermarks'], true)) {
            json_response(['ok' => false, 'error' => 'Invalid table.'], 400);
        }
        $res = supabase_request(
            'POST',
            rtrim(SUPABASE_URL, '/') . '/rest/v1/' . $table,
            array_merge($writeHeaders, ['Prefer: resolution=merge-duplicates,return=minimal']),
            json_encode($payload, JSON_UNESCAPED_SLASHES)
        );
        json_response(['ok' => (bool) $res['ok']], $res['ok'] ? 200 : 500);
        break;
    }

    case 'assistant_assign': {
        if (!in_array($role, ['teacher', 'admin'], true)) {
            json_response(['ok' => false, 'error' => 'Only teachers can assign assistants.'], 403);
        }
        $eventId = trim((string) ($data['event_id'] ?? ''));
        $studentId = trim((string) ($data['student_id'] ?? ''));
        $allowScan = array_key_exists('allow_scan', $data) ? (bool) $data['allow_scan'] : true;
        if ($eventId === '' || $studentId === '') {
            json_response(['ok' => false, 'error' => 'event_id and student_id required.'], 400);
        }
        if ($role !== 'admin' && !mobile_secure_can_manage_assistants($eventId, $userId, $readHeaders)) {
            json_response([
                'ok' => false,
                'error' => 'Only teachers assigned by admin can manage assistants for this event.',
            ], 403);
        }
        // Participants-only (same as Flutter).
        $regUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/event_registrations'
            . '?select=id&event_id=eq.' . rawurlencode($eventId)
            . '&student_id=eq.' . rawurlencode($studentId) . '&limit=1';
        $regRes = supabase_request('GET', $regUrl, $readHeaders);
        $regRows = json_decode((string) ($regRes['body'] ?? ''), true);
        if (!is_array($regRows) || count($regRows) === 0) {
            json_response([
                'ok' => false,
                'error' => 'Only registered participants of this event can be assigned as assistants.',
            ], 400);
        }

        $nowIso = gmdate('c');
        // Production event_assistants has assigned_at, not created_at/updated_at.
        $payload = [
            'event_id' => $eventId,
            'student_id' => $studentId,
            'allow_scan' => $allowScan,
            'assigned_by_teacher_id' => $userId,
            'assigned_at' => $nowIso,
        ];
        $writeHeaders = [
            'Content-Type: application/json',
            'Accept: application/json',
            'apikey: ' . SUPABASE_KEY,
            'Authorization: Bearer ' . SUPABASE_KEY,
            'Prefer: return=representation',
        ];
        // Avoid on_conflict (42P10 if unique missing). PATCH then INSERT.
        $patchUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/event_assistants'
            . '?event_id=eq.' . rawurlencode($eventId)
            . '&student_id=eq.' . rawurlencode($studentId);
        $res = supabase_request(
            'PATCH',
            $patchUrl,
            $writeHeaders,
            json_encode([
                'allow_scan' => $allowScan,
                'assigned_by_teacher_id' => $userId,
                'assigned_at' => $nowIso,
            ], JSON_UNESCAPED_SLASHES)
        );
        $patched = json_decode((string) ($res['body'] ?? ''), true);
        if (!($res['ok'] ?? false) || !is_array($patched) || count($patched) === 0) {
            $res = supabase_request(
                'POST',
                rtrim(SUPABASE_URL, '/') . '/rest/v1/event_assistants',
                $writeHeaders,
                json_encode($payload, JSON_UNESCAPED_SLASHES)
            );
            if (!($res['ok'] ?? false)) {
                // Legacy schema without assigned_by_teacher_id.
                unset($payload['assigned_by_teacher_id']);
                $res = supabase_request(
                    'POST',
                    rtrim(SUPABASE_URL, '/') . '/rest/v1/event_assistants',
                    $writeHeaders,
                    json_encode($payload, JSON_UNESCAPED_SLASHES)
                );
            }
        }
        $rows = json_decode((string) ($res['body'] ?? ''), true);
        json_response([
            'ok' => (bool) ($res['ok'] ?? false),
            'assistant' => is_array($rows) && isset($rows[0]) ? $rows[0] : $payload,
            'error' => ($res['ok'] ?? false) ? null : 'Failed to assign assistant. Please try again.',
        ], ($res['ok'] ?? false) ? 200 : 500);
        break;
    }

    case 'assistant_update_access': {
        if (!in_array($role, ['teacher', 'admin'], true)) {
            json_response(['ok' => false, 'error' => 'Only teachers can update assistants.'], 403);
        }
        $eventId = trim((string) ($data['event_id'] ?? ''));
        $assistantId = trim((string) ($data['assistant_id'] ?? ''));
        $studentId = trim((string) ($data['student_id'] ?? ''));
        if ($eventId === '') {
            json_response(['ok' => false, 'error' => 'Missing event identity.'], 400);
        }
        if ($role !== 'admin' && !mobile_secure_can_manage_assistants($eventId, $userId, $readHeaders)) {
            json_response([
                'ok' => false,
                'error' => 'Only teachers assigned by admin can update assistant access for this event.',
            ], 403);
        }
        if (!array_key_exists('allow_scan', $data)) {
            json_response(['ok' => false, 'error' => 'allow_scan required.'], 400);
        }
        $allowScan = (bool) $data['allow_scan'];
        $nowIso = gmdate('c');
        $patch = [
            'allow_scan' => $allowScan,
            'assigned_by_teacher_id' => $userId,
            'assigned_at' => $nowIso,
        ];
        $url = rtrim(SUPABASE_URL, '/') . '/rest/v1/event_assistants?';
        if ($assistantId !== '') {
            $url .= 'id=eq.' . rawurlencode($assistantId);
        } elseif ($studentId !== '') {
            $url .= 'event_id=eq.' . rawurlencode($eventId) . '&student_id=eq.' . rawurlencode($studentId);
        } else {
            json_response(['ok' => false, 'error' => 'assistant_id or student_id required.'], 400);
        }
        $res = supabase_request('PATCH', $url, $reprHeaders, json_encode($patch, JSON_UNESCAPED_SLASHES));
        if (!($res['ok'] ?? false)) {
            unset($patch['assigned_by_teacher_id']);
            $res = supabase_request('PATCH', $url, $reprHeaders, json_encode($patch, JSON_UNESCAPED_SLASHES));
        }
        json_response([
            'ok' => (bool) ($res['ok'] ?? false),
            'error' => ($res['ok'] ?? false) ? null : 'Failed to update assistant access.',
        ], ($res['ok'] ?? false) ? 200 : 500);
        break;
    }

    case 'proposal_submit_review': {
        if ($role !== 'teacher' && $role !== 'admin') {
            json_response(['ok' => false, 'error' => 'Only teachers can submit proposals.'], 403);
        }
        $eventId = trim((string) ($data['event_id'] ?? ''));
        if ($eventId === '') {
            json_response(['ok' => false, 'error' => 'Missing event id.'], 400);
        }
        if ($role !== 'admin' && !mobile_secure_is_event_creator($eventId, $userId, $readHeaders)) {
            json_response(['ok' => false, 'error' => 'You can only submit your own proposal for review.'], 403);
        }
        $nowIso = gmdate('c');
        // Match Flutter field set (do not force requirements_requested_at).
        $docsUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/event_proposal_documents'
            . '?event_id=eq.' . rawurlencode($eventId)
            . '&teacher_id=eq.' . rawurlencode($userId);
        $docsRes = supabase_request('PATCH', $docsUrl, $reprHeaders, json_encode([
            'admin_visible' => true,
            'visible_at' => $nowIso,
            'updated_at' => $nowIso,
        ], JSON_UNESCAPED_SLASHES));
        if (!($docsRes['ok'] ?? false)) {
            json_response(['ok' => false, 'error' => 'Failed to reveal the uploaded proposal documents.'], 500);
        }
        $eventUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/events?id=eq.' . rawurlencode($eventId);
        $eventRes = supabase_request('PATCH', $eventUrl, $reprHeaders, json_encode([
            'proposal_stage' => 'under_review',
            'requirements_submitted_at' => $nowIso,
            'updated_at' => $nowIso,
        ], JSON_UNESCAPED_SLASHES));
        if (!($eventRes['ok'] ?? false)) {
            json_response(['ok' => false, 'error' => 'Failed to submit the proposal for review.'], 500);
        }
        json_response(['ok' => true, 'message' => 'Proposal submitted for admin review.'], 200);
        break;
    }

    case 'event_create': {
        if (!in_array($role, ['teacher', 'admin'], true)) {
            json_response(['ok' => false, 'error' => 'Only teachers can create events.'], 403);
        }
        $payload = $data['payload'] ?? null;
        if (!is_array($payload)) {
            json_response(['ok' => false, 'error' => 'payload required.'], 400);
        }
        $sessions = $payload['sessions'] ?? [];
        unset($payload['sessions']);
        if (!is_array($sessions)) {
            $sessions = [];
        }

        $proposalRequirements = is_array($payload['proposal_requirements'] ?? null)
            ? $payload['proposal_requirements']
            : [];
        $studentRequirements = is_array($payload['student_requirements'] ?? null)
            ? $payload['student_requirements']
            : [];
        unset(
            $payload['proposal_requirements'],
            $payload['student_requirements'],
            $payload['cover_image_url'],
            $payload['proposal_stage'],
            $payload['id']
        );

        if ($role === 'teacher' && $proposalRequirements === []) {
            json_response(['ok' => false, 'error' => 'Add the required proposal documents before submitting.'], 400);
        }

        // Force identity + pending approval — same as Flutter createEvent.
        $payload['created_by'] = $userId;
        $payload['status'] = 'pending';
        $eventFor = trim((string) ($payload['event_for'] ?? ''));
        $payload['event_for'] = $eventFor !== '' ? $eventFor : 'All';

        $description = clean_text((string) ($payload['description'] ?? ''));
        $descriptionError = validate_event_description_words($description);
        if ($descriptionError !== null) {
            json_response(['ok' => false, 'error' => $descriptionError], 400);
        }
        $payload['description'] = $description !== '' ? $description : null;

        $isSeminarBased = (trim((string) ($payload['event_mode'] ?? '')) === 'seminar_based') || count($sessions) > 0;
        $payload['event_mode'] = $isSeminarBased ? 'seminar_based' : 'simple';
        $payload['event_structure'] = $isSeminarBased
            ? (count($sessions) > 1 ? 'two_seminars' : 'one_seminar')
            : 'simple';
        // Never INSERT uses_sessions — column absent on prod (42703 Postgres ERROR storm).
        unset($payload['uses_sessions']);

        $startAtRaw = trim((string) ($payload['start_at'] ?? ''));
        $endAtRaw = trim((string) ($payload['end_at'] ?? ''));
        try {
            $startAtDt = $startAtRaw !== '' ? new DateTimeImmutable($startAtRaw) : null;
        } catch (Throwable $e) {
            $startAtDt = null;
        }
        try {
            $endAtDt = $endAtRaw !== '' ? new DateTimeImmutable($endAtRaw) : null;
        } catch (Throwable $e) {
            $endAtDt = null;
        }
        if ($startAtDt === null) {
            json_response(['ok' => false, 'error' => 'Start date/time is required.'], 400);
        }
        if ($endAtDt === null) {
            json_response(['ok' => false, 'error' => 'End date/time is required.'], 400);
        }
        if ($endAtDt <= $startAtDt) {
            json_response(['ok' => false, 'error' => 'End must be after start.'], 400);
        }

        event_reject_if_published_schedule_conflict(
            $startAtDt->format('c'),
            $endAtDt->format('c'),
            (string) ($payload['location'] ?? ''),
            (string) ($payload['event_for'] ?? 'All')
        );

        if ($role === 'teacher') {
            $isFreeEvent = true;
            if (array_key_exists('is_free_event', $payload)) {
                $isFreeEvent = normalize_registration_bool($payload['is_free_event']);
            }
            $eventFee = null;
            if (!$isFreeEvent) {
                $eventFee = normalize_event_fee($payload['event_fee'] ?? null);
                if ($eventFee === null || $eventFee <= 0) {
                    json_response(['ok' => false, 'error' => 'Settlement amount must be between 1 and ' . (int) EVENT_FEE_MAX . '.'], 400);
                }
            }
            $registrationLimit = null;
            if (array_key_exists('registration_limit', $payload) && $payload['registration_limit'] !== null && $payload['registration_limit'] !== '') {
                $registrationLimit = normalize_registration_limit($payload['registration_limit']);
                if ($registrationLimit === null) {
                    json_response(['ok' => false, 'error' => 'Student limit must be between 1 and 9999.'], 400);
                }
            }
            $registrationCloseWeeks = null;
            if (array_key_exists('registration_close_weeks', $payload) && $payload['registration_close_weeks'] !== null && $payload['registration_close_weeks'] !== '') {
                $registrationCloseWeeks = normalize_registration_close_weeks($payload['registration_close_weeks']);
                $maxCloseWeeks = max_registration_close_weeks_for_start($startAtDt);
                if ($maxCloseWeeks < 1) {
                    $registrationCloseWeeks = null;
                } elseif ($registrationCloseWeeks === null || $registrationCloseWeeks < 1 || $registrationCloseWeeks > $maxCloseWeeks) {
                    json_response([
                        'ok' => false,
                        'error' => 'Registration close limit must be between 1 and '
                            . $maxCloseWeeks . ' week' . ($maxCloseWeeks === 1 ? '' : 's')
                            . ' before this event start.',
                    ], 400);
                }
            }
            $payload['is_free_event'] = $isFreeEvent;
            $payload['event_fee'] = $eventFee;
            if ($registrationLimit !== null) {
                $payload['registration_limit'] = $registrationLimit;
            } else {
                unset($payload['registration_limit']);
            }
            if ($registrationCloseWeeks !== null) {
                $payload['registration_close_weeks'] = $registrationCloseWeeks;
            } else {
                unset($payload['registration_close_weeks']);
            }
        } else {
            unset(
                $payload['is_free_event'],
                $payload['event_fee'],
                $payload['registration_limit'],
                $payload['registration_close_weeks']
            );
        }

        $optionalColumns = [
            'event_mode',
            'event_structure',
            'event_span',
            'is_free_event',
            'event_fee',
            'registration_limit',
            'registration_close_weeks',
        ];
        $working = $payload;
        $created = null;
        for ($attempt = 0; $attempt < 8; $attempt++) {
            $res = supabase_request(
                'POST',
                rtrim(SUPABASE_URL, '/') . '/rest/v1/events',
                $reprHeaders,
                json_encode($working, JSON_UNESCAPED_SLASHES)
            );
            if ($res['ok'] ?? false) {
                $rows = json_decode((string) ($res['body'] ?? ''), true);
                $created = is_array($rows) && isset($rows[0]) ? $rows[0] : null;
                break;
            }
            $err = strtolower((string) ($res['body'] ?? ''));
            $removed = false;
            foreach ($optionalColumns as $col) {
                if (isset($working[$col]) && str_contains($err, $col)) {
                    unset($working[$col]);
                    $removed = true;
                }
            }
            if (!$removed) {
                foreach ($optionalColumns as $col) {
                    if (isset($working[$col])) {
                        unset($working[$col]);
                        $removed = true;
                        break;
                    }
                }
            }
            if (!$removed) {
                json_response([
                    'ok' => false,
                    'error' => 'Failed to create event: Unable to save event schema fields.',
                ], 500);
            }
        }
        if (!is_array($created) || empty($created['id'])) {
            json_response(['ok' => false, 'error' => 'Failed to create event.'], 500);
        }

        $eventId = (string) $created['id'];
        if ($isSeminarBased && $sessions !== []) {
            $sessionRows = [];
            foreach ($sessions as $i => $source) {
                if (!is_array($source)) {
                    continue;
                }
                $title = trim((string) ($source['title'] ?? ''));
                $startAt = trim((string) ($source['start_at'] ?? ''));
                if ($title === '' || $startAt === '') {
                    supabase_request('DELETE', rtrim(SUPABASE_URL, '/') . '/rest/v1/events?id=eq.' . rawurlencode($eventId), $readHeaders);
                    json_response(['ok' => false, 'error' => 'Seminar ' . ($i + 1) . ' requires title and start time.'], 400);
                }
                $row = [
                    'event_id' => $eventId,
                    'title' => $title,
                    'start_at' => $startAt,
                    'sort_order' => $i,
                    'scan_window_minutes' => (int) ($source['scan_window_minutes']
                        ?? $source['attendance_window_minutes']
                        ?? 30),
                ];
                $endAt = trim((string) ($source['end_at'] ?? ''));
                if ($endAt !== '') {
                    $row['end_at'] = $endAt;
                }
                foreach (['topic', 'description', 'location'] as $opt) {
                    $val = trim((string) ($source[$opt] ?? ''));
                    if ($val !== '') {
                        $row[$opt] = $val;
                    }
                }
                $sessionRows[] = $row;
            }
            if ($sessionRows !== []) {
                $sessRes = supabase_request(
                    'POST',
                    rtrim(SUPABASE_URL, '/') . '/rest/v1/event_sessions',
                    $reprHeaders,
                    json_encode($sessionRows, JSON_UNESCAPED_SLASHES)
                );
                if (!($sessRes['ok'] ?? false)) {
                    supabase_request('DELETE', rtrim(SUPABASE_URL, '/') . '/rest/v1/events?id=eq.' . rawurlencode($eventId), $readHeaders);
                    json_response(['ok' => false, 'error' => 'Failed to create event sessions.'], 500);
                }
            }
        }

        if ($role === 'teacher') {
            $proposalSave = save_proposal_requirements(
                $eventId,
                $proposalRequirements,
                $userId,
                $readHeaders,
                [
                    'skip_event_stage_update' => true,
                    'include_requirements' => true,
                ]
            );
            if (!($proposalSave['ok'] ?? false)) {
                supabase_request('DELETE', rtrim(SUPABASE_URL, '/') . '/rest/v1/events?id=eq.' . rawurlencode($eventId), $readHeaders);
                json_response(['ok' => false, 'error' => (string) ($proposalSave['error'] ?? 'Failed to save proposal requirements.')], 500);
            }
            $created['proposal_requirements'] = $proposalSave['requirements'] ?? [];

            if ($studentRequirements !== []) {
                $studentSave = save_student_requirements(
                    $eventId,
                    $studentRequirements,
                    $userId,
                    $readHeaders
                );
                if (!($studentSave['ok'] ?? false)) {
                    supabase_request('DELETE', rtrim(SUPABASE_URL, '/') . '/rest/v1/events?id=eq.' . rawurlencode($eventId), $readHeaders);
                    json_response(['ok' => false, 'error' => (string) ($studentSave['error'] ?? 'Failed to save student requirements.')], 500);
                }
                $created['student_requirements'] = $studentSave['requirements'] ?? [];
            }
        }

        json_response(['ok' => true, 'event' => $created], 200);
        break;
    }

    default:
        json_response(['ok' => false, 'error' => 'Unknown action.'], 400);
}
