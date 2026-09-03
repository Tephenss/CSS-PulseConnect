<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/session.php';
session_bootstrap();

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/supabase.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/event_sessions.php';
require_once __DIR__ . '/includes/event_tabs.php';
require_once __DIR__ . '/includes/student_requirements.php';
require_once __DIR__ . '/includes/registration_access.php';
require_once __DIR__ . '/includes/api_cache.php';
require_once __DIR__ . '/includes/storage_signed.php';
require_once __DIR__ . '/includes/student_roster.php';

$user = require_role(['admin', 'teacher']);
$role = (string) ($user['role'] ?? 'admin');
$userId = (string) ($user['id'] ?? '');
$appTz = new DateTimeZone('Asia/Manila');
$toLocalDt = static function (?string $raw) use ($appTz): ?DateTimeImmutable {
    if (!$raw) return null;
    try {
        return (new DateTimeImmutable($raw))->setTimezone($appTz);
    } catch (Throwable $e) {
        return null;
    }
};

$eventId = isset($_GET['event_id']) ? (string) $_GET['event_id'] : '';
if ($eventId === '') {
    http_response_code(400);
    echo 'Missing event_id';
    exit;
}

// Load event details (for day tabs + teacher ownership check)
$eventLookup = fetch_event_row_by_id(
    $eventId,
    [
        'Accept: application/json',
        'apikey: ' . SUPABASE_KEY,
        'Authorization: Bearer ' . SUPABASE_KEY,
    ],
    'id,title,start_at,end_at,created_by,status,grace_time,is_free_event,event_fee,allow_registration,early_out_enabled_at'
);
if (!$eventLookup['ok']) {
    $eventLookup = fetch_event_row_by_id(
        $eventId,
        [
            'Accept: application/json',
            'apikey: ' . SUPABASE_KEY,
            'Authorization: Bearer ' . SUPABASE_KEY,
        ],
        'id,title,start_at,end_at,created_by,status,grace_time,is_free_event,event_fee,allow_registration'
    );
}
if (!$eventLookup['ok']) {
    $status = (int) ($eventLookup['status'] ?? 503);
    http_response_code($status === 404 ? 404 : 503);
    echo htmlspecialchars($eventLookup['message'] !== '' ? $eventLookup['message'] : 'Could not load event.');
    exit;
}
$event = $eventLookup['event'];
$headers = [
    'Accept: application/json',
    'apikey: ' . SUPABASE_KEY,
    'Authorization: Bearer ' . SUPABASE_KEY,
];

if ($role === 'teacher') {
    $isOwner = ((string) ($event['created_by'] ?? '') === $userId);
    $isPublished = ((string) ($event['status'] ?? '') === 'published');
    if (!$isOwner && !$isPublished) {
        http_response_code(403);
        echo 'Forbidden';
        exit;
    }
}

$sessions = fetch_event_sessions($eventId, $headers);
$eventUsesSessions = count($sessions) > 0;
$usesSessions = count($sessions) > 0;
$isFinishedEvent = strtolower(trim((string) ($event['status'] ?? ''))) === 'finished';
$participantTab = isset($_GET['participant_tab']) ? strtolower(trim((string) $_GET['participant_tab'])) : 'participants';
if (!in_array($participantTab, ['participants', 'absence_reasons'], true)) {
    $participantTab = 'participants';
}
if ($role === 'teacher' && $participantTab === 'absence_reasons') {
    $participantTab = 'participants';
}
$backHref = event_management_return_to($role, isset($_GET['return_to']) ? (string) $_GET['return_to'] : null);
$hasStudentRequirements = event_has_student_requirements($eventId, $headers);
$isEventCreator = $role === 'admin' || ((string) ($event['created_by'] ?? '') === $userId);
$canResetAttendance = $isEventCreator;
$canRemoveParticipant = $role === 'admin';
$isAbsenceFormCreator = ((string) ($event['created_by'] ?? '') === $userId);
$absenceExportHtml = '';
if ($isAbsenceFormCreator) {
    $absenceExportHtml = '<button type="button" id="btnExportAbsenceForm" data-event-id="'
        . htmlspecialchars($eventId)
        . '" class="rounded-xl border border-sky-200 bg-sky-600 text-white px-4 py-2 text-sm font-semibold hover:bg-sky-700 transition shadow-sm flex items-center gap-2 group disabled:opacity-60 disabled:cursor-wait">'
        . '<svg class="w-4 h-4 group-hover:scale-110 transition-transform" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m.75 12l3 3m0 0l3-3m-3 3v-6m-1.5-9H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z"/></svg>'
        . '<span data-label>Export Absence Form</span></button>';
}
$isPaidEvent = !event_is_free_registration_event($event);
$returnTo = $backHref;
$returnToQuery = '&return_to=' . rawurlencode($returnTo);
$exportExcelHtml = '<a href="/participants?event_id=' . htmlspecialchars($eventId)
    . '&export=excel' . htmlspecialchars($returnToQuery)
    . '" class="rounded-xl border border-emerald-200 bg-emerald-600 text-white px-4 py-2 text-sm font-semibold hover:bg-emerald-700 transition shadow-sm flex items-center gap-2 group">'
    . '<svg class="w-4 h-4 group-hover:scale-110 transition-transform" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5M16.5 12L12 16.5m0 0L7.5 12m4.5 4.5V3"/></svg>'
    . 'Export Excel</a>';
$nowUtc = new DateTimeImmutable('now', new DateTimeZone('UTC'));

$resetIsTimeoutPhase = false;
$earlyOutRawForReset = trim((string) ($event['early_out_enabled_at'] ?? ''));
if ($earlyOutRawForReset !== '') {
    $attendanceWindowsPath = __DIR__ . '/includes/event_attendance_windows.php';
    if (is_file($attendanceWindowsPath)) {
        require_once $attendanceWindowsPath;
        if (function_exists('attendance_early_out_is_active')) {
            $resetIsTimeoutPhase = attendance_early_out_is_active($earlyOutRawForReset, $nowUtc);
        }
    }
}
if (!$resetIsTimeoutPhase) {
    $endRawForReset = trim((string) ($event['end_at'] ?? ''));
    if ($endRawForReset !== '') {
        try {
            $eventEndUtcForReset = (new DateTimeImmutable($endRawForReset))->setTimezone(new DateTimeZone('UTC'));
            if ($nowUtc >= $eventEndUtcForReset) {
                $resetIsTimeoutPhase = true;
            }
        } catch (Throwable $e) {
            // Keep time-in phase.
        }
    }
}
$resetConfirmMessage = $resetIsTimeoutPhase
    ? 'Reset this participant time-out? Check-in will be kept so they can be timed out again.'
    : 'Reset this participant time-in? This clears check-in (and any time-out).';
$resetButtonLabel = $resetIsTimeoutPhase ? '↺ Reset Time-Out' : '↺ Reset Time-In';
$resetButtonShort = $resetIsTimeoutPhase ? '↺ Out' : '↺ In';

$attendanceCountsAsPresent = static function (?array $row): bool {
    if (!is_array($row)) {
        return false;
    }
    $status = strtolower(trim((string) ($row['status'] ?? '')));
    $checkInAt = trim((string) ($row['check_in_at'] ?? ''));
    if ($checkInAt !== '') {
        return true;
    }
    return in_array($status, ['present', 'scanned', 'late', 'early'], true);
};

$attendanceHasCheckOut = static function (?array $row): bool {
    if (!is_array($row)) {
        return false;
    }
    return trim((string) ($row['check_out_at'] ?? '')) !== '';
};

$inferFollowUpCase = static function (?array $reason, string $fallback = 'absent'): string {
    $text = strtolower(trim((string) ($reason['reason_text'] ?? '')));
    if (str_starts_with($text, '[no time-out]')) {
        return 'missed_timeout';
    }
    if (str_starts_with($text, '[no time-in]')) {
        return 'absent';
    }
    return $fallback === 'missed_timeout' ? 'missed_timeout' : 'absent';
};

$followUpCaseLabel = static function (string $case): string {
    return $case === 'missed_timeout' ? 'No time-out' : 'No time-in';
};

$formatParticipantName = static function (array $profile): string {
    $nameParts = [];
    foreach (['first_name', 'middle_name', 'last_name'] as $key) {
        $value = trim((string) ($profile[$key] ?? ''));
        if ($value !== '') {
            $nameParts[] = $value;
        }
    }
    $name = implode(' ', $nameParts);
    $suffix = trim((string) ($profile['suffix'] ?? ''));
    if ($suffix !== '') {
        $name .= ', ' . $suffix;
    }
    return $name !== '' ? $name : 'Unnamed Participant';
};

$absenceReasonRows = [];
$absenceReasonTableAvailable = true;
$absenceUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/attendance_absence_reasons'
    . '?select=id,student_id,event_id,session_id,reason_text,review_status,admin_note,submitted_at,reviewed_at'
    . '&event_id=eq.' . rawurlencode($eventId)
    . '&order=submitted_at.desc';
$absenceRes = supabase_request('GET', $absenceUrl, $headers);
if ($absenceRes['ok']) {
    $decoded = json_decode((string) $absenceRes['body'], true);
    $absenceReasonRows = is_array($decoded) ? $decoded : [];
} else {
    $absenceReasonTableAvailable = !str_contains(strtolower((string) ($absenceRes['body'] ?? '')), 'attendance_absence_reasons');
}

if ($usesSessions) {

    $pUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/event_registrations'
        . '?select=id,registered_at,student_id,users(first_name,middle_name,last_name,suffix,email,student_id,photo_url,sections(name)),tickets(id,token)'
        . '&event_id=eq.' . rawurlencode($eventId)
        . '&order=registered_at.desc';
    $pRes = supabase_request('GET', $pUrl, $headers);
    $participants = $pRes['ok'] ? json_decode((string) $pRes['body'], true) : [];
    $participants = is_array($participants) ? $participants : [];

    $sessionUserIds = [];
    $sessionStudentNos = [];
    foreach ($participants as $row) {
        if (!is_array($row)) {
            continue;
        }
        $uid = trim((string) ($row['student_id'] ?? ''));
        if ($uid !== '') {
            $sessionUserIds[] = $uid;
        }
        $u = isset($row['users']) && is_array($row['users']) ? $row['users'] : [];
        $no = trim((string) ($u['student_id'] ?? ''));
        if ($no !== '') {
            $sessionStudentNos[] = $no;
        }
    }
    $sessionYearMaps = student_roster_fetch_year_maps($sessionUserIds, $sessionStudentNos, $headers);

    $attendanceMap = [];
    $ticketToRegistration = [];
    foreach ($participants as $participant) {
        if (!is_array($participant)) {
            continue;
        }
        $registrationId = (string) ($participant['id'] ?? '');
        if ($registrationId === '') {
            continue;
        }
        $tickets = isset($participant['tickets']) && is_array($participant['tickets']) ? $participant['tickets'] : [];
        foreach ($tickets as $ticket) {
            if (!is_array($ticket)) {
                continue;
            }
            $ticketId = (string) ($ticket['id'] ?? '');
            if ($ticketId !== '') {
                $ticketToRegistration[$ticketId] = $registrationId;
            }
        }
    }

    if (count($sessions) > 0) {
        $sessionIds = [];
        foreach ($sessions as $session) {
            $sessionId = (string) ($session['id'] ?? '');
            if ($sessionId !== '') {
                $sessionIds[] = '"' . $sessionId . '"';
            }
        }

        if (count($sessionIds) > 0) {
            $attachAttendanceRow = static function (array $row) use (&$attendanceMap, $ticketToRegistration): void {
                $sessionId = (string) ($row['session_id'] ?? '');
                if ($sessionId === '') {
                    return;
                }

                $registrationId = (string) ($row['registration_id'] ?? '');
                if ($registrationId === '') {
                    $ticketId = (string) ($row['ticket_id'] ?? '');
                    if ($ticketId !== '' && isset($ticketToRegistration[$ticketId])) {
                        $registrationId = (string) $ticketToRegistration[$ticketId];
                    }
                }

                if ($registrationId === '') {
                    return;
                }

                $existing = $attendanceMap[$registrationId][$sessionId] ?? null;
                if (!is_array($existing)) {
                    $attendanceMap[$registrationId][$sessionId] = $row;
                    return;
                }

                $existingCheckIn = trim((string) ($existing['check_in_at'] ?? ''));
                $nextCheckIn = trim((string) ($row['check_in_at'] ?? ''));
                if ($existingCheckIn === '' && $nextCheckIn !== '') {
                    $attendanceMap[$registrationId][$sessionId] = $row;
                    return;
                }

                $existingLastScan = trim((string) ($existing['last_scanned_at'] ?? ''));
                $nextLastScan = trim((string) ($row['last_scanned_at'] ?? ''));
                if ($nextLastScan !== '') {
                    $nextTs = strtotime($nextLastScan);
                    $existingTs = $existingLastScan !== '' ? strtotime($existingLastScan) : false;
                    if ($nextTs !== false && ($existingTs === false || $nextTs > $existingTs)) {
                        $attendanceMap[$registrationId][$sessionId] = $row;
                    }
                }
            };

            $sessionFilter = implode(',', $sessionIds);

            // Primary storage for seminar attendance.
            $attendanceUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/event_session_attendance'
                . '?select=id,session_id,registration_id,ticket_id,status,check_in_at,check_out_at,last_scanned_at'
                . '&session_id=in.(' . $sessionFilter . ')';
            $attendanceRes = supabase_request('GET', $attendanceUrl, $headers);
            if (!($attendanceRes['ok'] ?? false)) {
                $attendanceUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/event_session_attendance'
                    . '?select=id,session_id,registration_id,ticket_id,status,check_in_at,last_scanned_at'
                    . '&session_id=in.(' . $sessionFilter . ')';
                $attendanceRes = supabase_request('GET', $attendanceUrl, $headers);
            }
            $attendanceRows = $attendanceRes['ok'] ? json_decode((string) $attendanceRes['body'], true) : [];
            $primaryAttendanceCount = 0;
            if (is_array($attendanceRows)) {
                foreach ($attendanceRows as $row) {
                    if (is_array($row)) {
                        $attachAttendanceRow($row);
                        $primaryAttendanceCount++;
                    }
                }
            }

            // Fallback storage used by older seminar migrations — skip when primary already has rows.
            if ($primaryAttendanceCount === 0) {
                $legacyAttendanceUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/attendance'
                    . '?select=id,session_id,ticket_id,status,check_in_at,check_out_at,last_scanned_at'
                    . '&session_id=in.(' . $sessionFilter . ')';
                $legacyAttendanceRes = supabase_request('GET', $legacyAttendanceUrl, $headers);
                if (!($legacyAttendanceRes['ok'] ?? false)) {
                    $legacyAttendanceUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/attendance'
                        . '?select=id,session_id,ticket_id,status,check_in_at,last_scanned_at'
                        . '&session_id=in.(' . $sessionFilter . ')';
                    $legacyAttendanceRes = supabase_request('GET', $legacyAttendanceUrl, $headers);
                }
                $legacyAttendanceRows = $legacyAttendanceRes['ok'] ? json_decode((string) $legacyAttendanceRes['body'], true) : [];
                if (is_array($legacyAttendanceRows)) {
                    foreach ($legacyAttendanceRows as $row) {
                        if (is_array($row)) {
                            $attachAttendanceRow($row);
                        }
                    }
                }
            }
        }
    }

    $reasonByStudentSession = [];
    $reasonByStudentEvent = [];
    if ($absenceReasonTableAvailable) {
        foreach ($absenceReasonRows as $reason) {
            if (!is_array($reason)) {
                continue;
            }
            $studentId = (string) ($reason['student_id'] ?? '');
            $sessionId = (string) ($reason['session_id'] ?? '');
            if ($studentId === '') {
                continue;
            }
            if ($sessionId === '') {
                $reasonByStudentEvent[$studentId] = $reason;
                continue;
            }
            $reasonByStudentSession[$studentId][$sessionId] = $reason;
        }
    }

    $sessionWindowMeta = [];
    foreach ($sessions as $session) {
        $sessionId = (string) ($session['id'] ?? '');
        if ($sessionId === '') {
            continue;
        }
        $startAtRaw = (string) ($session['start_at'] ?? '');
        $startAt = $toLocalDt($startAtRaw);
        if (!$startAt) {
            continue;
        }
        $windowMinutes = max(1, (int) ($session['scan_window_minutes'] ?? 30));
        $closesAt = $startAt->modify('+' . $windowMinutes . ' minutes');
        $endAt = $toLocalDt(trim((string) ($session['end_at'] ?? '')));
        $earlyOutAt = $toLocalDt(trim((string) ($session['early_out_enabled_at'] ?? '')));
        $timeoutClosesAt = $earlyOutAt instanceof DateTimeImmutable
            ? $earlyOutAt->modify('+1 hour')
            : ($endAt instanceof DateTimeImmutable ? $endAt->modify('+1 hour') : null);
        $sessionWindowMeta[$sessionId] = [
            'start_at' => $startAt,
            'closes_at' => $closesAt,
            'end_at' => $endAt,
            'timeout_closes_at' => $timeoutClosesAt,
            'window_minutes' => $windowMinutes,
            'closed' => $nowUtc > $closesAt->setTimezone(new DateTimeZone('UTC')),
            'timeout_closed' => $timeoutClosesAt instanceof DateTimeImmutable
                && $nowUtc > $timeoutClosesAt->setTimezone(new DateTimeZone('UTC')),
        ];
    }

    $syncNowIso = $nowUtc->format('c');
    $jsonHeaders = [
        'Content-Type: application/json',
        'Accept: application/json',
        'apikey: ' . SUPABASE_KEY,
        'Authorization: Bearer ' . SUPABASE_KEY,
        'Prefer: return=representation',
    ];
    // Throttle absent write-stampede when many teachers open Participants at once.
    $absentSyncLockKey = 'participants_absent_sync_' . $eventId;
    $shouldSyncAbsents = api_cache_try_lock($absentSyncLockKey, 60);
    if ($shouldSyncAbsents) {
        foreach ($participants as $participant) {
        if (!is_array($participant)) {
            continue;
        }
        $registrationId = (string) ($participant['id'] ?? '');
        if ($registrationId === '') {
            continue;
        }
        $tickets = isset($participant['tickets']) && is_array($participant['tickets']) ? $participant['tickets'] : [];
        $ticket = isset($tickets[0]) && is_array($tickets[0]) ? $tickets[0] : [];
        $ticketId = (string) ($ticket['id'] ?? '');
        if ($ticketId === '') {
            continue;
        }
        foreach ($sessions as $session) {
            $sessionId = (string) ($session['id'] ?? '');
            if ($sessionId === '') {
                continue;
            }
            $meta = $sessionWindowMeta[$sessionId] ?? null;
            if (!is_array($meta) || empty($meta['closed'])) {
                continue;
            }
            $attendance = $attendanceMap[$registrationId][$sessionId] ?? null;
            if ($attendanceCountsAsPresent(is_array($attendance) ? $attendance : null)) {
                continue;
            }

            $statusRaw = strtolower(trim((string) (is_array($attendance) ? ($attendance['status'] ?? '') : '')));
            if ($statusRaw === 'absent') {
                continue;
            }

            $updatedRow = null;
            $attendanceId = (string) (is_array($attendance) ? ($attendance['id'] ?? '') : '');
            if ($attendanceId !== '') {
                $patchByIdUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/event_session_attendance'
                    . '?id=eq.' . rawurlencode($attendanceId)
                    . '&check_in_at=is.null'
                    . '&select=id,session_id,registration_id,ticket_id,status,check_in_at,last_scanned_at';
                $patchByIdRes = supabase_request(
                    'PATCH',
                    $patchByIdUrl,
                    $jsonHeaders,
                    json_encode([
                        'status' => 'absent',
                        'last_scanned_at' => $syncNowIso,
                    ], JSON_UNESCAPED_SLASHES)
                );
                if ($patchByIdRes['ok']) {
                    $patchedRows = json_decode((string) $patchByIdRes['body'], true);
                    if (is_array($patchedRows) && isset($patchedRows[0]) && is_array($patchedRows[0])) {
                        $updatedRow = $patchedRows[0];
                    }
                }
            }

            if (!is_array($updatedRow)) {
                $patchByRegUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/event_session_attendance'
                    . '?session_id=eq.' . rawurlencode($sessionId)
                    . '&registration_id=eq.' . rawurlencode($registrationId)
                    . '&check_in_at=is.null'
                    . '&select=id,session_id,registration_id,ticket_id,status,check_in_at,last_scanned_at';
                $patchByRegRes = supabase_request(
                    'PATCH',
                    $patchByRegUrl,
                    $jsonHeaders,
                    json_encode([
                        'status' => 'absent',
                        'last_scanned_at' => $syncNowIso,
                    ], JSON_UNESCAPED_SLASHES)
                );
                if ($patchByRegRes['ok']) {
                    $patchedRows = json_decode((string) $patchByRegRes['body'], true);
                    if (is_array($patchedRows) && isset($patchedRows[0]) && is_array($patchedRows[0])) {
                        $updatedRow = $patchedRows[0];
                    }
                }
            }

            if (!is_array($updatedRow)) {
                $patchByTicketUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/event_session_attendance'
                    . '?session_id=eq.' . rawurlencode($sessionId)
                    . '&ticket_id=eq.' . rawurlencode($ticketId)
                    . '&check_in_at=is.null'
                    . '&select=id,session_id,registration_id,ticket_id,status,check_in_at,last_scanned_at';
                $patchByTicketRes = supabase_request(
                    'PATCH',
                    $patchByTicketUrl,
                    $jsonHeaders,
                    json_encode([
                        'status' => 'absent',
                        'last_scanned_at' => $syncNowIso,
                    ], JSON_UNESCAPED_SLASHES)
                );
                if ($patchByTicketRes['ok']) {
                    $patchedRows = json_decode((string) $patchByTicketRes['body'], true);
                    if (is_array($patchedRows) && isset($patchedRows[0]) && is_array($patchedRows[0])) {
                        $updatedRow = $patchedRows[0];
                    }
                }
            }

            if (!is_array($updatedRow)) {
                $insertUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/event_session_attendance'
                    . '?select=id,session_id,registration_id,ticket_id,status,check_in_at,last_scanned_at';
                $insertRes = supabase_request(
                    'POST',
                    $insertUrl,
                    $jsonHeaders,
                    json_encode([[
                        'session_id' => $sessionId,
                        'registration_id' => $registrationId,
                        'ticket_id' => $ticketId,
                        'status' => 'absent',
                        'last_scanned_at' => $syncNowIso,
                    ]], JSON_UNESCAPED_SLASHES)
                );
                if ($insertRes['ok']) {
                    $insertedRows = json_decode((string) $insertRes['body'], true);
                    if (is_array($insertedRows) && isset($insertedRows[0]) && is_array($insertedRows[0])) {
                        $updatedRow = $insertedRows[0];
                    }
                }
            }

            if (is_array($updatedRow)) {
                $attendanceMap[$registrationId][$sessionId] = $updatedRow;
            }
        }
    }
        api_cache_write($absentSyncLockKey, ['synced_at' => time()]);
        api_cache_release_lock($absentSyncLockKey);
    }

    $sessionCounts = [];
    $sessionOutCounts = [];
    foreach ($sessions as $session) {
        $sid = (string) ($session['id'] ?? '');
        $sessionCounts[$sid] = 0;
        $sessionOutCounts[$sid] = 0;
    }
    foreach ($attendanceMap as $rows) {
        foreach ($rows as $sessionId => $row) {
            if (!isset($sessionCounts[$sessionId])) {
                continue;
            }
            if ($attendanceCountsAsPresent(is_array($row) ? $row : null)) {
                $sessionCounts[$sessionId]++;
            }
            if (is_array($row) && trim((string) ($row['check_out_at'] ?? '')) !== '') {
                $sessionOutCounts[$sessionId]++;
            }
        }
    }

    $absentRows = [];
    $seenSeminarFollowUps = [];
    $seminarParticipantByStudent = [];
    foreach ($participants as $participant) {
        if (!is_array($participant)) {
            continue;
        }
        $registrationId = (string) ($participant['id'] ?? '');
        $studentId = (string) ($participant['student_id'] ?? '');
        if ($registrationId === '' || $studentId === '') {
            continue;
        }
        $seminarParticipantByStudent[$studentId] = $participant;
        $profile = isset($participant['users']) && is_array($participant['users']) ? $participant['users'] : [];
        $name = $formatParticipantName($profile);
        $section = isset($profile['sections']) && is_array($profile['sections'])
            ? (string) ($profile['sections']['name'] ?? '')
            : '';

        $missedClosedSessions = [];
        $hasAnyPresent = false;
        foreach ($sessions as $session) {
            $sessionId = (string) ($session['id'] ?? '');
            if ($sessionId === '' || !isset($sessionWindowMeta[$sessionId])) {
                continue;
            }
            $meta = $sessionWindowMeta[$sessionId];
            $attendance = $attendanceMap[$registrationId][$sessionId] ?? null;
            if ($attendanceCountsAsPresent(is_array($attendance) ? $attendance : null)) {
                $hasAnyPresent = true;
            }
            if (empty($meta['closed'])) {
                continue;
            }
            if ($attendanceCountsAsPresent(is_array($attendance) ? $attendance : null)) {
                continue;
            }
            $missedClosedSessions[] = [
                'session' => $session,
                'meta' => $meta,
            ];
        }

        if (count($missedClosedSessions) > 0 && !$hasAnyPresent) {
            $firstStart = null;
            $lastClose = null;
            foreach ($missedClosedSessions as $entry) {
                $meta = $entry['meta'];
                $startAt = isset($meta['start_at']) && $meta['start_at'] instanceof DateTimeImmutable ? $meta['start_at'] : null;
                $closesAt = isset($meta['closes_at']) && $meta['closes_at'] instanceof DateTimeImmutable ? $meta['closes_at'] : null;
                if ($startAt && ($firstStart === null || $startAt < $firstStart)) {
                    $firstStart = $startAt;
                }
                if ($closesAt && ($lastClose === null || $closesAt > $lastClose)) {
                    $lastClose = $closesAt;
                }
            }
            if ($firstStart && $lastClose) {
                $reason = $reasonByStudentEvent[$studentId] ?? null;
                $case = $inferFollowUpCase(is_array($reason) ? $reason : null, 'absent');
                $seenSeminarFollowUps[$studentId . '::event'] = true;
                $absentRows[] = [
                    'student_id' => $studentId,
                    'registration_id' => $registrationId,
                    'participant_name' => $name,
                    'student_number' => (string) ($profile['student_id'] ?? 'N/A'),
                    'section' => $section !== '' ? $section : 'N/A',
                    'session_name' => 'Whole event',
                    'session_start_at' => $firstStart,
                    'session_closes_at' => $lastClose,
                    'session_window_minutes' => 30,
                    'case' => $case,
                    'reason' => is_array($reason) ? $reason : null,
                ];
            }
        } elseif (count($missedClosedSessions) > 0) {
            foreach ($missedClosedSessions as $entry) {
                $session = $entry['session'];
                $meta = $entry['meta'];
                $sessionId = (string) ($session['id'] ?? '');
                if ($sessionId === '') {
                    continue;
                }
                $reason = $reasonByStudentSession[$studentId][$sessionId] ?? null;
                $case = $inferFollowUpCase(is_array($reason) ? $reason : null, 'absent');
                $seenSeminarFollowUps[$studentId . '::session::' . $sessionId] = true;
                $absentRows[] = [
                    'student_id' => $studentId,
                    'registration_id' => $registrationId,
                    'participant_name' => $name,
                    'student_number' => (string) ($profile['student_id'] ?? 'N/A'),
                    'section' => $section !== '' ? $section : 'N/A',
                    'session_name' => build_session_display_name($session),
                    'session_start_at' => $meta['start_at'],
                    'session_closes_at' => $meta['closes_at'],
                    'session_window_minutes' => (int) ($meta['window_minutes'] ?? 30),
                    'case' => $case,
                    'reason' => is_array($reason) ? $reason : null,
                ];
            }
        }

        foreach ($sessions as $session) {
            $sessionId = (string) ($session['id'] ?? '');
            if ($sessionId === '' || !isset($sessionWindowMeta[$sessionId])) {
                continue;
            }
            $meta = $sessionWindowMeta[$sessionId];
            if (empty($meta['timeout_closed'])) {
                continue;
            }
            $attendance = $attendanceMap[$registrationId][$sessionId] ?? null;
            if (!$attendanceCountsAsPresent(is_array($attendance) ? $attendance : null)) {
                continue;
            }
            if ($attendanceHasCheckOut(is_array($attendance) ? $attendance : null)) {
                continue;
            }
            $followKey = $studentId . '::session::' . $sessionId;
            if (!empty($seenSeminarFollowUps[$followKey])) {
                continue;
            }
            $reason = $reasonByStudentSession[$studentId][$sessionId] ?? null;
            $timeoutOpen = isset($meta['end_at']) && $meta['end_at'] instanceof DateTimeImmutable
                ? $meta['end_at']
                : ($meta['closes_at'] ?? $meta['start_at']);
            $timeoutClose = $meta['timeout_closes_at'] ?? $timeoutOpen;
            $seenSeminarFollowUps[$followKey] = true;
            $absentRows[] = [
                'student_id' => $studentId,
                'registration_id' => $registrationId,
                'participant_name' => $name,
                'student_number' => (string) ($profile['student_id'] ?? 'N/A'),
                'section' => $section !== '' ? $section : 'N/A',
                'session_name' => build_session_display_name($session),
                'session_start_at' => $timeoutOpen,
                'session_closes_at' => $timeoutClose,
                'session_window_minutes' => 60,
                'case' => 'missed_timeout',
                'reason' => is_array($reason) ? $reason : null,
            ];
        }
    }

    foreach ($absenceReasonRows as $reason) {
        if (!is_array($reason)) {
            continue;
        }
        $studentId = (string) ($reason['student_id'] ?? '');
        if ($studentId === '') {
            continue;
        }
        $sessionId = trim((string) ($reason['session_id'] ?? ''));
        $followKey = $sessionId === ''
            ? ($studentId . '::event')
            : ($studentId . '::session::' . $sessionId);
        if (!empty($seenSeminarFollowUps[$followKey])) {
            continue;
        }
        $participant = $seminarParticipantByStudent[$studentId] ?? [];
        $profile = isset($participant['users']) && is_array($participant['users']) ? $participant['users'] : [];
        $section = isset($profile['sections']) && is_array($profile['sections'])
            ? (string) ($profile['sections']['name'] ?? '')
            : '';
        $sessionName = 'Whole event';
        $windowStart = $toLocalDt((string) ($reason['submitted_at'] ?? ''));
        $windowClose = $windowStart;
        if ($sessionId !== '') {
            foreach ($sessions as $session) {
                if ((string) ($session['id'] ?? '') === $sessionId) {
                    $sessionName = build_session_display_name($session);
                    $meta = $sessionWindowMeta[$sessionId] ?? null;
                    if (is_array($meta)) {
                        $windowStart = $meta['start_at'] ?? $windowStart;
                        $windowClose = $meta['closes_at'] ?? $windowClose;
                    }
                    break;
                }
            }
        }
        if (!$windowStart instanceof DateTimeImmutable) {
            $windowStart = new DateTimeImmutable('now', $appTz);
        }
        if (!$windowClose instanceof DateTimeImmutable) {
            $windowClose = $windowStart;
        }
        $seenSeminarFollowUps[$followKey] = true;
        $absentRows[] = [
            'student_id' => $studentId,
            'registration_id' => (string) ($participant['id'] ?? ''),
            'participant_name' => $formatParticipantName($profile),
            'student_number' => (string) ($profile['student_id'] ?? 'N/A'),
            'section' => $section !== '' ? $section : 'N/A',
            'session_name' => $sessionName,
            'session_start_at' => $windowStart,
            'session_closes_at' => $windowClose,
            'session_window_minutes' => 30,
            'case' => $inferFollowUpCase($reason, $sessionId === '' ? 'absent' : 'absent'),
            'reason' => $reason,
        ];
    }

    if (isset($_GET['export']) && $_GET['export'] === 'excel') {
        $eventStart = $toLocalDt((string) ($event['start_at'] ?? ''));
        $eventEnd = $toLocalDt((string) ($event['end_at'] ?? ''));
        if (!$eventStart && $sessions !== []) {
            $eventStart = $toLocalDt((string) ($sessions[0]['start_at'] ?? ''));
        }
        if (!$eventEnd && $sessions !== []) {
            $lastSession = $sessions[count($sessions) - 1];
            $eventEnd = $toLocalDt(trim((string) ($lastSession['end_at'] ?? '')));
        }

        $sectionsMap = [];
        foreach ($participants as $r) {
            if (!is_array($r)) {
                continue;
            }
            $u = isset($r['users']) && is_array($r['users']) ? $r['users'] : [];
            $sec = isset($u['sections']) && is_array($u['sections']) ? $u['sections'] : null;
            $secName = is_array($sec) && isset($sec['name']) && trim((string) $sec['name']) !== ''
                ? trim((string) $sec['name'])
                : 'Unknown Block';
            $yearKey = student_roster_resolve_year_key(
                trim((string) ($r['student_id'] ?? '')),
                trim((string) ($u['student_id'] ?? '')),
                $secName,
                $sessionYearMaps
            );
            $yearLvl = $yearKey !== '' ? $yearKey : 'N/A';
            if (!isset($sectionsMap[$secName])) {
                $sectionsMap[$secName] = ['year' => $yearLvl, 'participants' => []];
            }
            $sectionsMap[$secName]['participants'][] = $r;
        }
        ksort($sectionsMap);

        $sessionCols = [];
        foreach ($sessions as $session) {
            if (!is_array($session)) {
                continue;
            }
            $sid = (string) ($session['id'] ?? '');
            if ($sid === '') {
                continue;
            }
            $sessionCols[] = [
                'id' => $sid,
                'name' => build_session_display_name($session, 'Seminar'),
            ];
        }

        $baseCols = 4;
        $colCount = $baseCols + (count($sessionCols) * 3);
        if ($colCount < 4) {
            $colCount = 4;
        }

        $eventTitle = strtoupper((string) ($event['title'] ?? 'UNKNOWN EVENT'));
        $eventDate = ($eventStart ? $eventStart->format('M d, Y') : '')
            . ($eventEnd && $eventStart && $eventEnd->format('Y-m-d') !== $eventStart->format('Y-m-d')
                ? ' - ' . $eventEnd->format('M d, Y')
                : '');

        header('Content-Type: application/vnd.ms-excel; charset=utf-8');
        header('Content-Disposition: attachment; filename="Event_Participants_' . preg_replace('/[^A-Za-z0-9_\-]/', '_', (string) ($event['title'] ?? '')) . '.xls"');

        echo '<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns="http://www.w3.org/TR/REC-html40">';
        echo '<head><meta charset="utf-8"> <style>';
        echo '  .hdr-main { font-family: "Segoe UI", Arial, sans-serif; font-size: 18pt; font-weight: bold; color: #1e293b; text-align: center; }';
        echo '  .hdr-sub  { font-family: "Segoe UI", Arial, sans-serif; font-size: 11pt; color: #64748b; font-weight: normal; text-align: center; }';
        echo '  .gen-on   { font-family: "Segoe UI", Arial, sans-serif; font-size: 9pt; color: #94a3b8; text-align: center; }';
        echo '  .logo-badge { background-color: #ea580c; color: #ffffff; font-family: "Impact", Arial, sans-serif; font-size: 24pt; font-weight: bold; text-align: center; vertical-align: middle; border: 2pt solid #c2410c; }';
        echo '  .event-hdr { background-color: #ea580c; color: #ffffff; font-family: "Segoe UI", Arial, sans-serif; font-size: 14pt; font-weight: bold; padding: 15px; text-align: center; height: 35px; border: 1pt solid #c2410c; }';
        echo '  .event-date { background-color: #fef2f2; color: #991b1b; font-family: "Segoe UI", Arial, sans-serif; font-size: 11pt; font-weight: bold; text-align: center; height: 25px; border-bottom: 2pt solid #ea580c; }';
        echo '  .sec-hdr { background-color: #1e293b; color: #ffffff; font-family: "Segoe UI", Arial, sans-serif; font-size: 11pt; font-weight: bold; padding: 10px; height: 25px; }';
        echo '  .col-hdr { background-color: #f8fafc; border: 1pt solid #cbd5e1; font-family: "Segoe UI", Arial, sans-serif; font-size: 10pt; font-weight: bold; text-align: center; height: 30px; white-space: nowrap; }';
        echo '  .sess-hdr { background-color: #0c4a6e; color: #ffffff; border: 1pt solid #0369a1; font-family: "Segoe UI", Arial, sans-serif; font-size: 10pt; font-weight: bold; text-align: center; height: 26px; }';
        echo '  .data-cell { border: 0.2pt solid #e2e8f0; font-family: "Segoe UI", Arial, sans-serif; font-size: 10pt; height: 25px; vertical-align: middle; }';
        echo '  .compl { color: #059669; font-weight: bold; text-align: center; background-color: #f0fdf4; }';
        echo '  .timedin { color: #0369a1; font-weight: bold; text-align: center; background-color: #f0f9ff; }';
        echo '  .absent { color: #b45309; font-weight: bold; text-align: center; background-color: #fffbeb; }';
        echo '  .pend  { color: #64748b; font-weight: bold; text-align: center; background-color: #f8fafc; }';
        echo '  col.col-name { width: 280px; }';
        echo '  col.col-studno { width: 160px; }';
        echo '  col.col-year { width: 70px; }';
        echo '  col.col-block { width: 150px; }';
        echo '  col.col-time { width: 150px; }';
        echo '  col.col-status { width: 110px; }';
        echo ' </style></head><body>';
        echo '<table border="0" style="border-collapse:collapse; table-layout:fixed;">';
        echo '<colgroup>';
        echo '<col class="col-name" style="width:280px" />';
        echo '<col class="col-studno" style="width:160px" />';
        echo '<col class="col-year" style="width:70px" />';
        echo '<col class="col-block" style="width:150px" />';
        foreach ($sessionCols as $_) {
            echo '<col class="col-time" style="width:150px" />';
            echo '<col class="col-time" style="width:150px" />';
            echo '<col class="col-status" style="width:110px" />';
        }
        echo '</colgroup>';

        echo '<tr><td colspan="' . $colCount . '" style="height: 10px;"></td></tr>';
        echo '<tr>';
        echo '  <td colspan="1" rowspan="3" class="logo-badge">CCS</td>';
        echo '  <td colspan="' . ($colCount - 1) . '" class="hdr-main">COLLEGE OF COMPUTER STUDIES</td>';
        echo '</tr>';
        echo '<tr><td colspan="' . ($colCount - 1) . '" class="hdr-sub">PulseConnect Seminar Participant Registry Report</td></tr>';
        echo '<tr><td colspan="' . ($colCount - 1) . '" class="gen-on">Generated on ' . date('F j, Y, g:i A') . '</td></tr>';
        echo '<tr><td colspan="' . $colCount . '" style="height: 15px;"></td></tr>';
        echo '<tr><td colspan="' . $colCount . '" class="event-hdr">' . htmlspecialchars($eventTitle) . '</td></tr>';
        echo '<tr><td colspan="' . $colCount . '" class="event-date">' . htmlspecialchars($eventDate) . '</td></tr>';
        echo '<tr><td colspan="' . $colCount . '" style="height: 10px;"></td></tr>';

        foreach ($sectionsMap as $secName => $secData) {
            $secText = 'BLOCK: ' . strtoupper($secName) . '   |   YEAR LEVEL: ' . (string) $secData['year'];
            echo '<tr><td colspan="' . $colCount . '" class="sec-hdr">' . htmlspecialchars($secText) . '</td></tr>';

            echo '<tr>';
            echo '<th class="col-hdr" colspan="4">STUDENT</th>';
            foreach ($sessionCols as $sessionCol) {
                echo '<th class="sess-hdr" colspan="3">' . htmlspecialchars(strtoupper((string) $sessionCol['name'])) . '</th>';
            }
            echo '</tr>';

            echo '<tr>';
            echo ' <th class="col-hdr">STUDENT NAME</th>';
            echo ' <th class="col-hdr">STUDENT&nbsp;NUMBER</th>';
            echo ' <th class="col-hdr">YEAR</th>';
            echo ' <th class="col-hdr">BLOCK</th>';
            foreach ($sessionCols as $_) {
                echo ' <th class="col-hdr">TIME IN</th>';
                echo ' <th class="col-hdr">TIME OUT</th>';
                echo ' <th class="col-hdr">STATUS</th>';
            }
            echo '</tr>';

            foreach ($secData['participants'] as $r) {
                if (!is_array($r)) {
                    continue;
                }
                $u = isset($r['users']) && is_array($r['users']) ? $r['users'] : [];
                $lastName = trim((string) ($u['last_name'] ?? ''));
                $givenParts = [];
                foreach (['first_name', 'middle_name'] as $k) {
                    $v = trim((string) ($u[$k] ?? ''));
                    if ($v !== '') {
                        $givenParts[] = $v;
                    }
                }
                $given = implode(' ', $givenParts);
                $suffix = trim((string) ($u['suffix'] ?? ''));
                if ($lastName !== '' && $given !== '') {
                    $name = $lastName . ($suffix !== '' ? ' ' . $suffix : '') . ', ' . $given;
                } elseif ($lastName !== '') {
                    $name = $lastName . ($suffix !== '' ? ', ' . $suffix : '');
                } else {
                    $name = $given . ($suffix !== '' ? ', ' . $suffix : '');
                }
                $studentNumber = trim((string) ($u['student_id'] ?? ''));
                if ($studentNumber === '') {
                    $studentNumber = 'N/A';
                }
                $registrationId = (string) ($r['id'] ?? '');

                echo '<tr>';
                echo ' <td class="data-cell" style="padding-left: 5px;">' . htmlspecialchars($name !== '' ? $name : 'Unnamed') . '</td>';
                echo ' <td class="data-cell" style="text-align:center; font-family: Consolas, monospace; mso-number-format:\'\\@\';">' . htmlspecialchars($studentNumber) . '</td>';
                echo ' <td class="data-cell" style="text-align:center;">' . htmlspecialchars((string) $secData['year']) . '</td>';
                echo ' <td class="data-cell" style="text-align:center;">' . htmlspecialchars((string) $secName) . '</td>';

                foreach ($sessionCols as $sessionCol) {
                    $att = $attendanceMap[$registrationId][$sessionCol['id']] ?? null;
                    $checkIn = is_array($att) ? trim((string) ($att['check_in_at'] ?? '')) : '';
                    $checkOut = is_array($att) ? trim((string) ($att['check_out_at'] ?? '')) : '';
                    $hasIn = $attendanceCountsAsPresent(is_array($att) ? $att : null);
                    $sessionMeta = $sessionWindowMeta[$sessionCol['id']] ?? null;
                    $sessionClosed = is_array($sessionMeta) && !empty($sessionMeta['closed']);

                    $timeInDisplay = '-';
                    $timeOutDisplay = '-';
                    if ($checkIn !== '') {
                        $checkInLocal = $toLocalDt($checkIn);
                        if ($checkInLocal) {
                            $timeInDisplay = $checkInLocal->format('m/d/Y h:i A');
                        }
                    }
                    if ($checkOut !== '') {
                        $checkOutLocal = $toLocalDt($checkOut);
                        if ($checkOutLocal) {
                            $timeOutDisplay = $checkOutLocal->format('m/d/Y h:i A');
                        }
                    }

                    if ($hasIn && $checkOut !== '') {
                        $statusStr = 'COMPLETED';
                        $statusCls = 'compl';
                    } elseif ($hasIn) {
                        $statusStr = 'TIMED IN';
                        $statusCls = 'timedin';
                    } elseif ($sessionClosed) {
                        $statusStr = 'ABSENT';
                        $statusCls = 'absent';
                    } else {
                        $statusStr = 'NO RECORD';
                        $statusCls = 'pend';
                    }

                    echo ' <td class="data-cell" style="text-align:center;">' . htmlspecialchars($timeInDisplay) . '</td>';
                    echo ' <td class="data-cell" style="text-align:center;">' . htmlspecialchars($timeOutDisplay) . '</td>';
                    echo ' <td class="data-cell ' . $statusCls . '">' . $statusStr . '</td>';
                }
                echo '</tr>';
            }
            echo '<tr><td colspan="' . $colCount . '" style="height: 10px;"></td></tr>';
        }

        echo '</table></body></html>';
        exit;
    }

    render_header('Participants', $user);
    ?>
    <?php
    render_event_page_header([
        'back_href' => $backHref,
        'title' => (string) ($event['title'] ?? ''),
        'subtitle' => 'Seminar attendance is tracked per session.',
        'actions_html' => $absenceExportHtml . $exportExcelHtml,
    ]);
    render_event_tabs([
        'event_id' => $eventId,
        'current_tab' => $participantTab === 'absence_reasons' ? 'absence_reasons' : 'participants',
        'role' => $role,
        'uses_sessions' => $usesSessions,
        'event_status' => (string) ($event['status'] ?? ''),
        'return_to' => $returnTo,
        'has_student_requirements' => $hasStudentRequirements,
        'is_event_creator' => $isEventCreator,
        'is_paid_event' => $isPaidEvent,
    ]);
    ?>

      <?php if ($participantTab === 'participants'): ?>
        <div class="grid grid-cols-1 md:grid-cols-<?= max(1, min(4, count($sessions))) ?> gap-4 mb-6">
          <?php
            $nowLocal = $nowUtc->setTimezone($appTz);
            foreach ($sessions as $sessionIndex => $session):
              $sessionId = (string) ($session['id'] ?? '');
              $sessionStart = $toLocalDt((string) ($session['start_at'] ?? ''));
              $sessionEnd = $toLocalDt(trim((string) ($session['end_at'] ?? '')));
              $meta = $sessionWindowMeta[$sessionId] ?? null;
              $timeInCloses = is_array($meta) && ($meta['closes_at'] ?? null) instanceof DateTimeImmutable
                  ? $meta['closes_at']
                  : null;
              $timeoutCloses = is_array($meta) && ($meta['timeout_closes_at'] ?? null) instanceof DateTimeImmutable
                  ? $meta['timeout_closes_at']
                  : null;
              $timeoutOpens = is_array($meta) && ($meta['end_at'] ?? null) instanceof DateTimeImmutable
                  ? $meta['end_at']
                  : $sessionEnd;
              $earlyOutAt = $toLocalDt(trim((string) ($session['early_out_enabled_at'] ?? '')));
              if ($earlyOutAt instanceof DateTimeImmutable && $nowLocal >= $earlyOutAt) {
                  $timeoutOpens = $earlyOutAt;
              }
              $statusLabel = 'Upcoming';
              $statusClass = 'seminar-summary-status is-upcoming';
              if ($sessionStart instanceof DateTimeImmutable && $nowLocal < $sessionStart) {
                  $statusLabel = 'Upcoming';
                  $statusClass = 'seminar-summary-status is-upcoming';
              } elseif ($timeInCloses instanceof DateTimeImmutable && $nowLocal <= $timeInCloses) {
                  $statusLabel = 'Time-in open';
                  $statusClass = 'seminar-summary-status is-time-in';
              } elseif (
                  $timeoutOpens instanceof DateTimeImmutable
                  && $nowLocal >= $timeoutOpens
                  && (!($timeoutCloses instanceof DateTimeImmutable) || $nowLocal <= $timeoutCloses)
              ) {
                  $statusLabel = 'Time-out open';
                  $statusClass = 'seminar-summary-status is-time-out';
              } elseif ($timeoutCloses instanceof DateTimeImmutable && $nowLocal > $timeoutCloses) {
                  $statusLabel = 'Ended';
                  $statusClass = 'seminar-summary-status is-ended';
              } else {
                  $statusLabel = 'Ongoing';
                  $statusClass = 'seminar-summary-status is-ongoing';
              }
              $sameDay = $sessionStart instanceof DateTimeImmutable
                  && $sessionEnd instanceof DateTimeImmutable
                  && $sessionStart->format('Y-m-d') === $sessionEnd->format('Y-m-d');
              $startDate = $sessionStart instanceof DateTimeImmutable ? $sessionStart->format('M j, Y') : '—';
              $startTime = $sessionStart instanceof DateTimeImmutable ? $sessionStart->format('g:i A') : '—';
              $endDate = $sessionEnd instanceof DateTimeImmutable ? $sessionEnd->format('M j, Y') : '—';
              $endTime = $sessionEnd instanceof DateTimeImmutable ? $sessionEnd->format('g:i A') : '—';
          ?>
            <article class="seminar-summary-card">
              <div class="seminar-summary-card-inner">
                <div class="seminar-summary-top">
                  <div class="seminar-summary-identity">
                    <span class="seminar-summary-index"><?= (int) $sessionIndex + 1 ?></span>
                    <div>
                      <div class="seminar-summary-kicker">Seminar</div>
                      <h3 class="seminar-summary-title"><?= htmlspecialchars(build_session_display_name($session)) ?></h3>
                    </div>
                  </div>
                  <span class="<?= htmlspecialchars($statusClass) ?>"><?= htmlspecialchars($statusLabel) ?></span>
                </div>

                <?php if ($sameDay): ?>
                  <div class="seminar-summary-date"><?= htmlspecialchars($startDate) ?></div>
                <?php endif; ?>

                <div class="seminar-summary-window">
                  <div class="seminar-summary-slot">
                    <span class="seminar-summary-slot-label">Start</span>
                    <?php if (!$sameDay): ?>
                      <span class="seminar-summary-slot-date"><?= htmlspecialchars($startDate) ?></span>
                    <?php endif; ?>
                    <span class="seminar-summary-slot-time"><?= htmlspecialchars($startTime) ?></span>
                  </div>
                  <div class="seminar-summary-slot-rule" aria-hidden="true"></div>
                  <div class="seminar-summary-slot">
                    <span class="seminar-summary-slot-label">End</span>
                    <?php if (!$sameDay): ?>
                      <span class="seminar-summary-slot-date"><?= htmlspecialchars($endDate) ?></span>
                    <?php endif; ?>
                    <span class="seminar-summary-slot-time"><?= htmlspecialchars($endTime) ?></span>
                  </div>
                </div>

                <div class="seminar-summary-stats">
                  <div class="seminar-summary-stat is-in">
                    <span class="seminar-summary-stat-value"><?= htmlspecialchars((string) ($sessionCounts[$sessionId] ?? 0)) ?></span>
                    <span class="seminar-summary-stat-label">Timed in</span>
                  </div>
                  <div class="seminar-summary-stat is-out">
                    <span class="seminar-summary-stat-value"><?= htmlspecialchars((string) ($sessionOutCounts[$sessionId] ?? 0)) ?></span>
                    <span class="seminar-summary-stat-label">Timed out</span>
                  </div>
                </div>
              </div>
            </article>
          <?php endforeach; ?>
        </div>

        <!-- Live Search & Counter Bar -->
        <div class="mb-4 flex flex-col sm:flex-row items-stretch sm:items-center justify-between gap-3 bg-white p-3 rounded-2xl border border-zinc-200/80 shadow-2xs">
          <div class="relative flex-1">
            <div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3.5 text-zinc-400">
              <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z"/></svg>
            </div>
            <input
              type="text"
              id="multiSessionSearchInput"
              placeholder="Search participant name, student ID, section..."
              class="w-full rounded-xl border border-zinc-200 bg-zinc-50/60 py-2 pl-10 pr-4 text-xs text-zinc-900 placeholder-zinc-400 transition focus:border-sky-500 focus:bg-white focus:outline-none focus:ring-2 focus:ring-sky-500/20 font-medium"
            />
          </div>
          <div class="flex items-center justify-between sm:justify-end gap-2 px-1">
            <span class="text-xs font-semibold text-zinc-500">Total Participants:</span>
            <span class="inline-flex items-center justify-center rounded-full bg-sky-50 px-2.5 py-0.5 text-xs font-bold text-sky-700 border border-sky-200/60">
              <?= count($participants) ?>
            </span>
          </div>
        </div>

        <div class="overflow-x-auto rounded-2xl border border-zinc-200/80 bg-white shadow-sm">
          <table class="min-w-full divide-y divide-zinc-200/80">
            <thead class="bg-zinc-50/80">
              <tr>
                <th class="px-4 py-3.5 text-left text-[11px] font-bold uppercase tracking-wider text-zinc-500">Participant</th>
                <th class="px-4 py-3.5 text-left text-[11px] font-bold uppercase tracking-wider text-zinc-500">Student No.</th>
                <th class="px-4 py-3.5 text-left text-[11px] font-bold uppercase tracking-wider text-zinc-500">Section</th>
                <?php foreach ($sessions as $session): ?>
                  <th class="px-4 py-3.5 text-left text-[11px] font-bold uppercase tracking-wider text-zinc-500"><?= htmlspecialchars(build_session_display_name($session)) ?></th>
                <?php endforeach; ?>
                <?php if ($canResetAttendance): ?>
                  <th class="px-4 py-3.5 text-right text-[11px] font-bold uppercase tracking-wider text-zinc-500">Action</th>
                <?php endif; ?>
              </tr>
            </thead>
            <tbody class="divide-y divide-zinc-100">
              <?php foreach ($participants as $participant): ?>
                <?php
                  $registrationId = (string) ($participant['id'] ?? '');
                  $studentId = (string) ($participant['student_id'] ?? '');
                  $profile = isset($participant['users']) && is_array($participant['users']) ? $participant['users'] : [];
                  $avatarUrl = storage_resolve_user_avatar_url(
                      $studentId,
                      (string) ($profile['photo_url'] ?? '')
                  );
                  $email = (string) ($profile['email'] ?? '');
                  $firstName = trim((string) ($profile['first_name'] ?? ''));
                  $lastName = trim((string) ($profile['last_name'] ?? ''));
                  $initials = strtoupper(mb_substr($firstName, 0, 1) . mb_substr($lastName, 0, 1));
                  if ($initials === '') { $initials = 'P'; }

                  $section = isset($profile['sections']) && is_array($profile['sections']) ? trim((string) ($profile['sections']['name'] ?? '')) : '';
                  $yearKey = student_roster_resolve_year_key(
                      $studentId,
                      trim((string) ($profile['student_id'] ?? '')),
                      $section,
                      $sessionYearMaps
                  );
                  $yearLabel = student_roster_year_ordinal_label($yearKey);
                  if ($yearLabel !== '' && ($section === '' || preg_match('/irreg/i', $section) || !preg_match('/[1-4]/', $section))) {
                      $section = $section !== '' ? ($section . ' • ' . $yearLabel) : $yearLabel;
                  }
                  $nameParts = [];
                  foreach (['first_name', 'middle_name', 'last_name'] as $key) {
                      $value = trim((string) ($profile[$key] ?? ''));
                      if ($value !== '') {
                          $nameParts[] = $value;
                      }
                  }
                  $name = implode(' ', $nameParts);
                  $suffix = trim((string) ($profile['suffix'] ?? ''));
                  if ($suffix !== '') {
                      $name .= ', ' . $suffix;
                  }
                  $searchStr = strtolower($name . ' ' . $email . ' ' . $studentId . ' ' . $section);

                  $nextResetSessionId = '';
                  $nextResetHasOut = false;
                  $nextResetHasIn = false;
                  foreach ($sessions as $resetSession) {
                      $resetSid = (string) ($resetSession['id'] ?? '');
                      $resetAtt = $attendanceMap[$registrationId][$resetSid] ?? null;
                      $resetHasIn = is_array($resetAtt) && $attendanceCountsAsPresent($resetAtt);
                      $resetHasOut = is_array($resetAtt) && trim((string) ($resetAtt['check_out_at'] ?? '')) !== '';
                      if ($resetHasOut) {
                          $nextResetSessionId = $resetSid;
                          $nextResetHasOut = true;
                          $nextResetHasIn = true;
                          break;
                      }
                      if ($resetHasIn && $nextResetSessionId === '') {
                          $nextResetSessionId = $resetSid;
                          $nextResetHasIn = true;
                      }
                  }
                  $nextResetLabel = $nextResetHasOut ? '↺ Reset Time-Out' : '↺ Reset Time-In';
                  $nextResetConfirm = $nextResetHasOut
                      ? 'Reset Time-Out for this seminar? Time-in will be kept. Reset again to clear time-in.'
                      : 'Reset Time-In for this seminar? Only this seminar is cleared.';
                ?>
                <tr class="multi-participant-row hover:bg-zinc-50/80 transition-colors" data-search="<?= htmlspecialchars($searchStr) ?>">
                  <td class="px-4 py-3.5 whitespace-nowrap">
                    <div class="flex items-center gap-3">
                      <div class="participant-avatar flex-shrink-0 w-9 h-9 rounded-full overflow-hidden bg-gradient-to-br from-sky-500 to-sky-700 text-white flex items-center justify-center font-extrabold text-xs shadow-2xs border border-white">
                        <?php if ($avatarUrl !== ''): ?>
                          <img src="<?= htmlspecialchars($avatarUrl) ?>" alt="<?= htmlspecialchars($name) ?>" class="w-full h-full object-cover" loading="lazy" onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                          <span style="display:none;" class="w-full h-full items-center justify-center"><?= htmlspecialchars($initials) ?></span>
                        <?php else: ?>
                          <span><?= htmlspecialchars($initials) ?></span>
                        <?php endif; ?>
                      </div>
                      <div class="min-w-0">
                        <div class="text-sm font-bold text-zinc-900 truncate"><?= htmlspecialchars($name !== '' ? $name : 'Unnamed Participant') ?></div>
                        <?php if ($email !== ''): ?>
                          <div class="text-xs text-zinc-400 truncate"><?= htmlspecialchars($email) ?></div>
                        <?php endif; ?>
                      </div>
                    </div>
                  </td>
                  <td class="px-4 py-3.5 whitespace-nowrap text-xs font-mono text-zinc-600">
                    <span class="inline-block px-2 py-0.5 rounded bg-zinc-100/90 border border-zinc-200/60 font-semibold text-zinc-700">
                      <?= htmlspecialchars((string) ($profile['student_id'] ?? 'N/A')) ?>
                    </span>
                  </td>
                  <td class="px-4 py-3.5 whitespace-nowrap">
                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold bg-slate-100 text-slate-700 border border-slate-200/70">
                      <?= htmlspecialchars($section !== '' ? $section : 'N/A') ?>
                    </span>
                  </td>
                  <?php foreach ($sessions as $session): ?>
                    <?php
                      $sessionId = (string) ($session['id'] ?? '');
                      $attendance = $attendanceMap[$registrationId][$sessionId] ?? null;
                      $checkInAt = is_array($attendance) ? trim((string) ($attendance['check_in_at'] ?? '')) : '';
                      $checkOutAt = is_array($attendance) ? trim((string) ($attendance['check_out_at'] ?? '')) : '';
                      $sessionMeta = $sessionWindowMeta[$sessionId] ?? null;
                      $sessionClosed = is_array($sessionMeta) && !empty($sessionMeta['closed']);
                      $hasTimeIn = is_array($attendance) && $attendanceCountsAsPresent($attendance);
                      $hasTimeOut = $checkOutAt !== '';
                    ?>
                    <td class="px-4 py-3.5 text-xs whitespace-nowrap">
                      <?php if ($hasTimeIn): ?>
                        <div class="space-y-1">
                          <div>
                            <span class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-xs font-semibold bg-emerald-50 text-emerald-700 border border-emerald-200/80">
                              <svg class="w-3 h-3 text-emerald-600" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/></svg>
                              <span>Time-In</span>
                              <?php if ($checkInAt !== ''): ?>
                                <span class="text-[11px] font-normal text-emerald-600/80">• <?= htmlspecialchars(format_date_local($checkInAt, 'g:i A')) ?></span>
                              <?php endif; ?>
                            </span>
                          </div>
                          <div>
                            <?php if ($hasTimeOut): ?>
                              <span class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-xs font-semibold bg-sky-50 text-sky-700 border border-sky-200/80">
                                <svg class="w-3 h-3 text-sky-600" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 9V5.25A2.25 2.25 0 0013.5 3h-6a2.25 2.25 0 00-2.25 2.25v13.5A2.25 2.25 0 007.5 21h6a2.25 2.25 0 002.25-2.25V15M12 9l-3 3m0 0l3 3m-3-3h12.75"/></svg>
                                <span>Time-Out</span>
                                <span class="text-[11px] font-normal text-sky-600/80">• <?= htmlspecialchars(format_date_local($checkOutAt, 'g:i A')) ?></span>
                              </span>
                            <?php else: ?>
                              <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-[11px] font-medium bg-zinc-100 text-zinc-500 border border-zinc-200/60">No time-out</span>
                            <?php endif; ?>
                          </div>
                        </div>
                      <?php elseif ($sessionClosed): ?>
                        <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-bold bg-amber-50 text-amber-700 border border-amber-200/80">
                          <svg class="w-3 h-3 text-amber-600" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                          Absent
                        </span>
                      <?php else: ?>
                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-zinc-50 text-zinc-400 border border-zinc-200/50">No record</span>
                      <?php endif; ?>
                    </td>
                  <?php endforeach; ?>
                  <?php if ($canResetAttendance): ?>
                  <td class="px-4 py-3.5 whitespace-nowrap text-right">
                    <?php if ($nextResetHasIn): ?>
                      <button
                        type="button"
                        class="btnResetAttendance inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl text-xs font-semibold bg-amber-50 text-amber-800 border border-amber-200/80 hover:bg-amber-100 hover:border-amber-300 active:scale-95 transition-all shadow-2xs cursor-pointer"
                        data-id="<?= htmlspecialchars($registrationId) ?>"
                        data-session-id="<?= htmlspecialchars($nextResetSessionId) ?>"
                        data-confirm="<?= htmlspecialchars($nextResetConfirm) ?>"
                      >
                        <svg class="w-3.5 h-3.5 text-amber-600" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0l3.181 3.183a8.25 8.25 0 0013.803-3.7M4.031 9.865a8.25 8.25 0 0113.803-3.7l3.181 3.182m0-4.991v4.99"/></svg>
                        <span><?= htmlspecialchars($nextResetLabel) ?></span>
                      </button>
                    <?php endif; ?>
                  </td>
                  <?php endif; ?>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php else: ?>
        <?php if (!$absenceReasonTableAvailable): ?>
          <div class="rounded-2xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900 mb-6">
            Absence reason storage is not available yet. Apply migration <code>008_attendance_absence_reasons.sql</code> first.
          </div>
        <?php endif; ?>
        <div class="rounded-2xl border border-zinc-200 bg-white shadow-sm overflow-x-auto">
          <table class="min-w-full divide-y divide-zinc-200">
            <thead class="bg-zinc-50">
              <tr>
                <th class="px-4 py-3 text-left text-xs font-bold uppercase tracking-wider text-zinc-500">Participant</th>
                <th class="px-4 py-3 text-left text-xs font-bold uppercase tracking-wider text-zinc-500">Student No.</th>
                <th class="px-4 py-3 text-left text-xs font-bold uppercase tracking-wider text-zinc-500">Section</th>
                <th class="px-4 py-3 text-left text-xs font-bold uppercase tracking-wider text-zinc-500">Case</th>
                <th class="px-4 py-3 text-left text-xs font-bold uppercase tracking-wider text-zinc-500">Missed Seminar</th>
                <th class="px-4 py-3 text-left text-xs font-bold uppercase tracking-wider text-zinc-500">Scan Window</th>
                <th class="px-4 py-3 text-left text-xs font-bold uppercase tracking-wider text-zinc-500">Reason</th>
                <th class="px-4 py-3 text-left text-xs font-bold uppercase tracking-wider text-zinc-500">Submitted</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-zinc-100">
              <?php if (count($absentRows) === 0): ?>
                <tr>
                  <td colspan="8" class="px-4 py-12 text-center text-sm text-zinc-500 font-semibold">
                    No unresolved absences or submitted reasons found.
                  </td>
                </tr>
              <?php endif; ?>
              <?php foreach ($absentRows as $row): ?>
                <?php
                  $reason = $row['reason'];
                  $hasReason = is_array($reason);
                  $windowLabel = $row['session_start_at']->format('M j, g:i A') . ' - ' . $row['session_closes_at']->format('g:i A');
                  $submittedLabel = $hasReason && !empty($reason['submitted_at'])
                      ? format_date_local((string) $reason['submitted_at'], 'M j, g:i A')
                      : '—';
                  $reviewStatus = $hasReason ? strtolower(trim((string) ($reason['review_status'] ?? 'pending'))) : '';
                  $reviewLabel = $reviewStatus === 'approved'
                      ? 'Approved'
                      : ($reviewStatus === 'rejected' ? 'Rejected' : 'For Review');
                  $reviewBadge = $reviewStatus === 'approved'
                      ? 'bg-emerald-100 text-emerald-800 border-emerald-200'
                      : ($reviewStatus === 'rejected'
                          ? 'bg-red-100 text-red-800 border-red-200'
                          : 'bg-sky-100 text-sky-800 border-sky-200');
                  $fullReasonText = (string) ($reason['reason_text'] ?? '');
                  if (function_exists('mb_strlen') && function_exists('mb_substr')) {
                      $reasonPreview = mb_strlen($fullReasonText) > 72
                          ? (mb_substr($fullReasonText, 0, 72) . '...')
                          : $fullReasonText;
                  } else {
                      $reasonPreview = strlen($fullReasonText) > 72
                          ? (substr($fullReasonText, 0, 72) . '...')
                          : $fullReasonText;
                  }
                  $reasonModalId = 'reason-modal-session-' . ($reason['id'] ?? md5((string) $row['participant_name'] . (string) $row['session_name']));
                ?>
                <tr>
                  <td class="px-4 py-4 text-sm font-semibold text-zinc-900"><?= htmlspecialchars((string) $row['participant_name']) ?></td>
                  <td class="px-4 py-4 text-sm text-zinc-600"><?= htmlspecialchars((string) $row['student_number']) ?></td>
                  <td class="px-4 py-4 text-sm text-zinc-600"><?= htmlspecialchars((string) $row['section']) ?></td>
                  <?php
                    $rowCase = (string) ($row['case'] ?? 'absent');
                    $rowCaseLabel = $followUpCaseLabel($rowCase);
                    $rowCaseBadge = $rowCase === 'missed_timeout'
                        ? 'bg-orange-50 text-orange-800 border-orange-200'
                        : 'bg-amber-50 text-amber-800 border-amber-200';
                  ?>
                  <td class="px-4 py-4 text-sm text-zinc-700">
                    <span class="inline-flex items-center rounded-full border px-2 py-1 text-[10px] font-bold uppercase tracking-wider <?= $rowCaseBadge ?>">
                      <?= htmlspecialchars($rowCaseLabel) ?>
                    </span>
                  </td>
                  <td class="px-4 py-4 text-sm text-zinc-700"><?= htmlspecialchars((string) $row['session_name']) ?></td>
                  <td class="px-4 py-4 text-sm text-zinc-600"><?= htmlspecialchars($windowLabel) ?></td>
                  <td class="px-4 py-4 text-sm text-zinc-700">
                    <?php if ($hasReason): ?>
                      <div class="space-y-2">
                        <span class="inline-flex items-center rounded-full border px-2 py-1 text-[10px] font-bold uppercase tracking-wider <?= $reviewBadge ?>">
                          <?= htmlspecialchars($reviewLabel) ?>
                        </span>
                        <button
                          type="button"
                          class="btn-view-reason inline-flex items-center rounded-lg border border-zinc-300 bg-white px-3 py-1.5 text-xs font-semibold text-zinc-700 hover:bg-zinc-50"
                          data-modal-id="<?= htmlspecialchars((string) $reasonModalId) ?>"
                        >
                          View full reason
                        </button>
                        <div id="<?= htmlspecialchars((string) $reasonModalId) ?>" class="reason-modal fixed inset-0 z-[100] hidden">
                          <div class="absolute inset-0 bg-black/50 reason-modal-close" data-modal-id="<?= htmlspecialchars((string) $reasonModalId) ?>"></div>
                          <div class="absolute inset-0 flex items-center justify-center p-4">
                            <div class="w-full max-w-xl rounded-2xl border border-zinc-200 bg-white shadow-2xl">
                              <div class="flex items-center justify-between border-b border-zinc-200 px-5 py-4">
                                <div>
                                  <div class="text-sm font-bold text-zinc-900"><?= htmlspecialchars((string) $row['participant_name']) ?></div>
                                  <div class="text-xs text-zinc-500"><?= htmlspecialchars((string) $row['session_name']) ?> • <?= htmlspecialchars($submittedLabel) ?></div>
                                </div>
                                <button type="button" class="reason-modal-close rounded-lg p-2 text-zinc-500 hover:bg-zinc-100" data-modal-id="<?= htmlspecialchars((string) $reasonModalId) ?>">✕</button>
                              </div>
                              <div class="px-5 py-4">
                                <div class="mb-3">
                                  <span class="inline-flex items-center rounded-full border px-2 py-1 text-[10px] font-bold uppercase tracking-wider <?= $reviewBadge ?>">
                                    <?= htmlspecialchars($reviewLabel) ?>
                                  </span>
                                </div>
                                <div class="max-h-72 overflow-y-auto whitespace-pre-wrap text-sm leading-6 text-zinc-700"><?= nl2br(htmlspecialchars($fullReasonText)) ?></div>
                                <?php if (!empty($reason['admin_note'])): ?>
                                  <div class="mt-4 rounded-xl border border-zinc-200 bg-zinc-50 p-3 text-xs text-zinc-600">
                                    <span class="font-semibold">Admin note:</span>
                                    <div class="mt-1 whitespace-pre-wrap"><?= nl2br(htmlspecialchars((string) $reason['admin_note'])) ?></div>
                                  </div>
                                <?php endif; ?>
                              </div>
                            </div>
                          </div>
                        </div>
                      </div>
                    <?php else: ?>
                      <span class="inline-flex items-center rounded-full border border-red-200 bg-red-50 px-2 py-1 text-[10px] font-bold uppercase tracking-wider text-red-700">
                        No reason submitted
                      </span>
                    <?php endif; ?>
                  </td>
                  <td class="px-4 py-4 text-sm text-zinc-600"><?= htmlspecialchars($submittedLabel) ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>

    <script>
      document.querySelectorAll('.btn-view-reason').forEach(btn => {
        btn.addEventListener('click', () => {
          const id = btn.dataset.modalId;
          const modal = id ? document.getElementById(id) : null;
          if (modal) modal.classList.remove('hidden');
        });
      });

      document.querySelectorAll('.reason-modal-close').forEach(btn => {
        btn.addEventListener('click', () => {
          const id = btn.dataset.modalId;
          const modal = id ? document.getElementById(id) : null;
          if (modal) modal.classList.add('hidden');
        });
      });

      document.querySelectorAll('.btnResetAttendance').forEach(btn => {
        btn.addEventListener('click', async () => {
          const confirmText = (btn.dataset.confirm || <?= json_encode($resetConfirmMessage) ?>).trim();
          const ok = confirm(confirmText);
          if (!ok) return;

          btn.disabled = true;
          try {
            const payload = {
              registration_id: btn.dataset.id,
              csrf_token: window.CSRF_TOKEN
            };
            if ((btn.dataset.sessionId || '').trim() !== '') {
              payload.session_id = btn.dataset.sessionId.trim();
            }
            const res = await fetch('/api/participant_attendance_reset.php', {
              method: 'POST',
              headers: { 'Content-Type': 'application/json' },
              body: JSON.stringify(payload)
            });
            const data = await res.json();
            if (!data.ok) throw new Error(data.error || 'Failed');
            window.location.reload();
          } catch (err) {
            alert(err.message || 'Failed');
            btn.disabled = false;
          }
        });
      });

      (() => {
        const btn = document.getElementById('btnExportAbsenceForm');
        if (!btn) return;
        btn.addEventListener('click', async () => {
          const eventId = String(btn.dataset.eventId || '').trim();
          if (!eventId) return;
          const label = btn.querySelector('[data-label]');
          const prev = label ? label.textContent : 'Export Absence Form';
          btn.disabled = true;
          if (label) label.textContent = 'Exporting…';
          try {
            const res = await fetch(
              '/api/event_absence_form_export.php?event_id=' + encodeURIComponent(eventId) + '&ajax=1',
              { credentials: 'same-origin', headers: { Accept: 'application/json' } }
            );
            const ct = String(res.headers.get('Content-Type') || '').toLowerCase();
            if (ct.includes('application/json')) {
              const data = await res.json().catch(() => ({}));
              throw new Error((data && data.error) || 'Export failed');
            }
            if (!res.ok) {
              const text = await res.text();
              throw new Error(text || 'Export failed');
            }
            const blob = await res.blob();
            let filename = 'Approved_Absence_Form.docx';
            const cd = String(res.headers.get('Content-Disposition') || '');
            const m = /filename="([^"]+)"/i.exec(cd);
            if (m && m[1]) filename = m[1];
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = filename;
            document.body.appendChild(a);
            a.click();
            a.remove();
            URL.revokeObjectURL(url);
          } catch (e) {
            alert(e.message || 'Export failed');
          } finally {
            btn.disabled = false;
            if (label) label.textContent = prev;
          }
        });
      })();
    </script>
    <?php
    render_footer();
    exit;
}

$start = isset($event['start_at']) ? $toLocalDt((string) $event['start_at']) : null;
$end = isset($event['end_at']) ? $toLocalDt((string) $event['end_at']) : null;

$days = [];
$multiDay = false;
if ($start && $end) {
    $d = $start->setTime(0, 0, 0);
    $endDate = $end->setTime(0, 0, 0);
    while ($d <= $endDate) {
        $days[] = $d->format('Y-m-d');
        $d = $d->modify('+1 day');
    }
    $multiDay = count($days) > 1;
}

// Simple events are treated as single-view participant lists.
if (!$usesSessions) {
    $days = [];
    $multiDay = false;
}

// Load participants
$pUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/event_registrations'
    . '?select=id,registered_at,student_id,users(first_name,middle_name,last_name,suffix,email,student_id,photo_url,sections(name)),'
    . 'tickets(id,token,attendance(id,check_in_at,check_out_at,status,last_scanned_at))'
    . '&event_id=eq.' . rawurlencode($eventId)
    . '&order=registered_at.desc';

$pRes = supabase_request('GET', $pUrl, $headers);
if (!($pRes['ok'] ?? false)) {
    // Older DBs may lack check_out_at on attendance.
    $pUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/event_registrations'
        . '?select=id,registered_at,student_id,users(first_name,middle_name,last_name,suffix,email,student_id,photo_url,sections(name)),'
        . 'tickets(id,token,attendance(id,check_in_at,status,last_scanned_at))'
        . '&event_id=eq.' . rawurlencode($eventId)
        . '&order=registered_at.desc';
    $pRes = supabase_request('GET', $pUrl, $headers);
}
$participants = [];
if ($pRes['ok']) {
    $decoded = json_decode((string) $pRes['body'], true);
    $participants = is_array($decoded) ? $decoded : [];
}

$participantUserIds = [];
$participantStudentNos = [];
foreach ($participants as $row) {
    if (!is_array($row)) {
        continue;
    }
    $uid = trim((string) ($row['student_id'] ?? ''));
    if ($uid !== '') {
        $participantUserIds[] = $uid;
    }
    $u = isset($row['users']) && is_array($row['users']) ? $row['users'] : [];
    $no = trim((string) ($u['student_id'] ?? ''));
    if ($no !== '') {
        $participantStudentNos[] = $no;
    }
}
$participantYearMaps = student_roster_fetch_year_maps($participantUserIds, $participantStudentNos, $headers);

// Build buckets by day
$buckets = [];
$buckets['all'] = $participants;
foreach ($participants as $r) {
    $ticket = null;
    $tickets = isset($r['tickets']) ? $r['tickets'] : null;
    if (is_array($tickets)) {
        $ticket = isset($tickets[0]) && is_array($tickets[0]) ? $tickets[0] : null;
    }
    $attendance = null;
    if ($ticket && isset($ticket['attendance'])) {
        $atts = $ticket['attendance'];
        if (is_array($atts)) {
            $attendance = isset($atts[0]) && is_array($atts[0]) ? $atts[0] : (is_array($atts) ? $atts : null);
        }
    }
    if (!is_array($attendance)) {
        continue;
    }
    $checkInAt = $attendance['check_in_at'] ?? null;
    if (!$checkInAt) continue;
    try {
        $checkLocal = $toLocalDt((string) $checkInAt);
        if (!$checkLocal) continue;
        $checkDate = $checkLocal->format('Y-m-d');
        if (!isset($buckets[$checkDate])) $buckets[$checkDate] = [];
        $buckets[$checkDate][] = $r;
    } catch (Throwable $e) {
        // ignore invalid dates
    }
}

// Excel export
if (isset($_GET['export']) && $_GET['export'] === 'excel') {
    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    header('Content-Disposition: attachment; filename="Event_Participants_' . preg_replace('/[^A-Za-z0-9_\-]/', '_', (string)($event['title'] ?? '')) . '.xls"');
    
    // Group participants by block (section name)
    $sectionsMap = [];
    foreach ($participants as $r) {
        $u = isset($r['users']) && is_array($r['users']) ? $r['users'] : [];
        $sec = isset($u['sections']) && is_array($u['sections']) ? $u['sections'] : null;
        $secName = is_array($sec) && isset($sec['name']) ? $sec['name'] : 'Unknown Block';
        
        $yearKey = student_roster_resolve_year_key(
            trim((string) ($r['student_id'] ?? '')),
            trim((string) ($u['student_id'] ?? '')),
            $secName,
            $participantYearMaps
        );
        $yearLvl = $yearKey !== '' ? $yearKey : 'N/A';

        if(!isset($sectionsMap[$secName])) {
            $sectionsMap[$secName] = ['year' => $yearLvl, 'participants' => []];
        }
        $sectionsMap[$secName]['participants'][] = $r;
    }
    ksort($sectionsMap);

    $eventTitle = strtoupper(htmlspecialchars((string)($event['title'] ?? 'UNKNOWN EVENT')));
    $eventDate = ($start ? $start->format('M d, Y') : '') . ($multiDay && $end ? ' - ' . $end->format('M d, Y') : '');
    $colCount = 7;

    echo '<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns="http://www.w3.org/TR/REC-html40">';
    echo '<head><meta charset="utf-8"> <style>';
    echo '  .hdr-main { font-family: "Segoe UI", Arial, sans-serif; font-size: 18pt; font-weight: bold; color: #1e293b; text-align: center; }';
    echo '  .hdr-sub  { font-family: "Segoe UI", Arial, sans-serif; font-size: 11pt; color: #64748b; font-weight: normal; text-align: center; }';
    echo '  .gen-on   { font-family: "Segoe UI", Arial, sans-serif; font-size: 9pt; color: #94a3b8; text-align: center; }';
    echo '  .logo-badge { background-color: #ea580c; color: #ffffff; font-family: "Impact", Arial, sans-serif; font-size: 24pt; font-weight: bold; text-align: center; vertical-align: middle; border: 2pt solid #c2410c; }';
    echo '  .event-hdr { background-color: #ea580c; color: #ffffff; font-family: "Segoe UI", Arial, sans-serif; font-size: 14pt; font-weight: bold; padding: 15px; text-align: center; height: 35px; border: 1pt solid #c2410c; }';
    echo '  .event-date { background-color: #fef2f2; color: #991b1b; font-family: "Segoe UI", Arial, sans-serif; font-size: 11pt; font-weight: bold; text-align: center; height: 25px; border-bottom: 2pt solid #ea580c; }';
    echo '  .sec-hdr { background-color: #1e293b; color: #ffffff; font-family: "Segoe UI", Arial, sans-serif; font-size: 11pt; font-weight: bold; padding: 10px; height: 25px; }';
    echo '  .col-hdr { background-color: #f8fafc; border: 1pt solid #cbd5e1; font-family: "Segoe UI", Arial, sans-serif; font-size: 10pt; font-weight: bold; text-align: center; height: 30px; white-space: nowrap; mso-rotate: 0; }';
    echo '  .data-cell { border: 0.2pt solid #e2e8f0; font-family: "Segoe UI", Arial, sans-serif; font-size: 10pt; height: 25px; vertical-align: middle; }';
    echo '  .compl { color: #059669; font-weight: bold; text-align: center; background-color: #f0fdf4; }';
    echo '  .pend  { color: #d97706; font-weight: bold; text-align: center; background-color: #fffbeb; }';
    echo '  col.col-name { width: 280px; mso-width-source: userset; mso-width-alt: 7000; }';
    echo '  col.col-studno { width: 170px; mso-width-source: userset; mso-width-alt: 4800; }';
    echo '  col.col-year { width: 70px; mso-width-source: userset; mso-width-alt: 2000; }';
    echo '  col.col-block { width: 150px; mso-width-source: userset; mso-width-alt: 4200; }';
    echo '  col.col-time { width: 160px; mso-width-source: userset; mso-width-alt: 4500; }';
    echo '  col.col-status { width: 110px; mso-width-source: userset; mso-width-alt: 3000; }';
    echo ' </style></head>';
    echo '<body>';
    echo '<table border="0" style="border-collapse:collapse; table-layout:fixed;">';
    echo '<colgroup>';
    echo '<col class="col-name" style="width:280px" />';
    echo '<col class="col-studno" style="width:170px" />';
    echo '<col class="col-year" style="width:70px" />';
    echo '<col class="col-block" style="width:150px" />';
    echo '<col class="col-time" style="width:160px" />';
    echo '<col class="col-time" style="width:160px" />';
    echo '<col class="col-status" style="width:110px" />';
    echo '</colgroup>';
    
    // Top Logo & Header (Merged perfectly)
    echo '<tr><td colspan="' . $colCount . '" style="height: 10px;"></td></tr>';
    echo '<tr>';
    echo '  <td colspan="1" rowspan="3" class="logo-badge">CCS</td>';
    echo '  <td colspan="' . ($colCount - 1) . '" class="hdr-main">COLLEGE OF COMPUTER STUDIES</td>';
    echo '</tr>';
    echo '<tr><td colspan="' . ($colCount - 1) . '" class="hdr-sub">PulseConnect Participant Registry Report</td></tr>';
    echo '<tr><td colspan="' . ($colCount - 1) . '" class="gen-on">Generated on ' . date('F j, Y, g:i A') . '</td></tr>';
    
    echo '<tr><td colspan="' . $colCount . '" style="height: 15px;"></td></tr>';
    
    // Event Center Banner
    echo '<tr><td colspan="' . $colCount . '" class="event-hdr">' . htmlspecialchars($eventTitle) . '</td></tr>';
    echo '<tr><td colspan="' . $colCount . '" class="event-date">' . htmlspecialchars($eventDate) . '</td></tr>';
    echo '<tr><td colspan="' . $colCount . '" style="height: 10px;"></td></tr>';

    foreach($sectionsMap as $secName => $secData) {
        $secText = 'BLOCK: ' . strtoupper(htmlspecialchars($secName)) . '   |   YEAR LEVEL: ' . htmlspecialchars($secData['year']);
        echo '<tr><td colspan="' . $colCount . '" class="sec-hdr">' . $secText . '</td></tr>';
        
        echo '<tr>';
        echo ' <th class="col-hdr" style="width:280px;">STUDENT NAME</th>';
        echo ' <th class="col-hdr" style="width:170px; white-space:nowrap;">STUDENT&nbsp;NUMBER</th>';
        echo ' <th class="col-hdr" style="width:70px;">YEAR</th>';
        echo ' <th class="col-hdr" style="width:150px;">BLOCK</th>';
        echo ' <th class="col-hdr" style="width:160px;">TIME IN</th>';
        echo ' <th class="col-hdr" style="width:160px;">TIME OUT</th>';
        echo ' <th class="col-hdr" style="width:110px;">STATUS</th>';
        echo '</tr>';
        
        foreach($secData['participants'] as $r) {
            $u = isset($r['users']) && is_array($r['users']) ? $r['users'] : [];
            $lastName = trim((string) ($u['last_name'] ?? ''));
            $givenParts = [];
            foreach (['first_name', 'middle_name'] as $k) {
                $v = trim((string) ($u[$k] ?? ''));
                if ($v !== '') $givenParts[] = $v;
            }
            $given = implode(' ', $givenParts);
            $suffix = trim((string) ($u['suffix'] ?? ''));
            if ($lastName !== '' && $given !== '') {
                $name = $lastName . ($suffix !== '' ? ' ' . $suffix : '') . ', ' . $given;
            } elseif ($lastName !== '') {
                $name = $lastName . ($suffix !== '' ? ', ' . $suffix : '');
            } else {
                $name = $given . ($suffix !== '' ? ', ' . $suffix : '');
            }
            
            $tickets = isset($r['tickets']) && is_array($r['tickets']) ? $r['tickets'] : [];
            $ticket = isset($tickets[0]) && is_array($tickets[0]) ? $tickets[0] : [];
            $attendance = null;
            if (isset($ticket['attendance'])) {
                $atts = $ticket['attendance'];
                if (is_array($atts)) {
                    $attendance = isset($atts[0]) && is_array($atts[0]) ? $atts[0] : (isset($atts) && is_array($atts) ? $atts : null);
                }
            }
            $checkIn = is_array($attendance) ? trim((string) ($attendance['check_in_at'] ?? '')) : '';
            $checkOut = is_array($attendance) ? trim((string) ($attendance['check_out_at'] ?? '')) : '';
            $attStatus = is_array($attendance) ? ($attendance['status'] ?? '') : '';
            
            $timeInDisplay = '-';
            $timeOutDisplay = '-';
            if ($checkIn !== '') {
                $checkInLocal = $toLocalDt($checkIn);
                if ($checkInLocal) {
                    $timeInDisplay = $checkInLocal->format('m/d/Y h:i A');
                }
            }
            if ($checkOut !== '') {
                $checkOutLocal = $toLocalDt($checkOut);
                if ($checkOutLocal) {
                    $timeOutDisplay = $checkOutLocal->format('m/d/Y h:i A');
                }
            }
            $normalizedStatus = strtolower(trim((string) $attStatus));
            $isComp = $checkIn !== '' || in_array($normalizedStatus, ['completed', 'present', 'late', 'early', 'scanned'], true);
            $statusStr = $isComp ? 'COMPLETED' : 'PENDING';
            $statusCls = $isComp ? 'compl' : 'pend';
            $studentNumber = trim((string) ($u['student_id'] ?? ''));
            if ($studentNumber === '') {
                $studentNumber = trim((string) ($r['student_number'] ?? ''));
            }
            if ($studentNumber === '') {
                $studentNumber = 'N/A';
            }
            
            echo '<tr>';
            echo ' <td class="data-cell" style="padding-left: 5px;">' . htmlspecialchars($name) . '</td>';
            echo ' <td class="data-cell" style="text-align:center; font-family: Consolas, monospace; mso-number-format:\'\\@\';">' . htmlspecialchars($studentNumber) . '</td>';
            echo ' <td class="data-cell" style="text-align:center;">' . htmlspecialchars($secData['year']) . '</td>';
            echo ' <td class="data-cell" style="text-align:center;">' . htmlspecialchars($secName) . '</td>';
            echo ' <td class="data-cell" style="text-align:center;">' . htmlspecialchars($timeInDisplay) . '</td>';
            echo ' <td class="data-cell" style="text-align:center;">' . htmlspecialchars($timeOutDisplay) . '</td>';
            echo ' <td class="data-cell ' . $statusCls . '">' . $statusStr . '</td>';
            echo '</tr>';
        }
        echo '<tr><td colspan="' . $colCount . '" style="height: 10px;"></td></tr>';
    }
    
    echo '</table></body></html>';
    exit;
}

// CSV export
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="participants.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Name', 'StudentNumber', 'Email', 'RegisteredAt', 'Token', 'TimeIn', 'TimeOut', 'AttendanceStatus']);
    foreach ($participants as $r) {
        $u = isset($r['users']) && is_array($r['users']) ? $r['users'] : [];
        $lastName = trim((string) ($u['last_name'] ?? ''));
        $givenParts = [];
        foreach (['first_name', 'middle_name'] as $k) {
            $v = trim((string) ($u[$k] ?? ''));
            if ($v !== '') $givenParts[] = $v;
        }
        $given = implode(' ', $givenParts);
        $suffix = trim((string) ($u['suffix'] ?? ''));
        if ($lastName !== '' && $given !== '') {
            $name = $lastName . ($suffix !== '' ? ' ' . $suffix : '') . ', ' . $given;
        } elseif ($lastName !== '') {
            $name = $lastName . ($suffix !== '' ? ', ' . $suffix : '');
        } else {
            $name = $given . ($suffix !== '' ? ', ' . $suffix : '');
        }

        $tickets = isset($r['tickets']) && is_array($r['tickets']) ? $r['tickets'] : [];
        $ticket = isset($tickets[0]) && is_array($tickets[0]) ? $tickets[0] : [];
        $token = (string) ($ticket['token'] ?? '');

        $attendance = null;
        if (isset($ticket['attendance'])) {
            $atts = $ticket['attendance'];
            if (is_array($atts)) {
                $attendance = isset($atts[0]) && is_array($atts[0]) ? $atts[0] : (isset($atts) && is_array($atts) ? $atts : null);
            }
        }
        $checkIn = is_array($attendance) ? trim((string) ($attendance['check_in_at'] ?? '')) : '';
        $checkOut = is_array($attendance) ? trim((string) ($attendance['check_out_at'] ?? '')) : '';
        $attStatus = is_array($attendance) ? (string) ($attendance['status'] ?? '') : '';
        $timeIn = '';
        $timeOut = '';
        if ($checkIn !== '') {
            $checkInLocal = $toLocalDt($checkIn);
            if ($checkInLocal) {
                $timeIn = $checkInLocal->format('m/d/Y h:i A');
            }
        }
        if ($checkOut !== '') {
            $checkOutLocal = $toLocalDt($checkOut);
            if ($checkOutLocal) {
                $timeOut = $checkOutLocal->format('m/d/Y h:i A');
            }
        }
        $studentNumber = trim((string) ($u['student_id'] ?? ''));
        if ($studentNumber === '') {
            $studentNumber = trim((string) ($r['student_number'] ?? ''));
        }
        if ($studentNumber === '') {
            $studentNumber = 'N/A';
        }

        fputcsv($out, [
            $name,
            $studentNumber,
            (string) ($u['email'] ?? ''),
            (string) ($r['registered_at'] ?? ''),
            $token,
            $timeIn,
            $timeOut,
            $attStatus,
        ]);
    }
    fclose($out);
    exit;
}

$activeDay = !$usesSessions ? 'all' : (isset($_GET['day']) ? (string) $_GET['day'] : 'all');
if ($activeDay !== 'all' && !isset($buckets[$activeDay])) $activeDay = 'all';
$rows = $buckets[$activeDay] ?? [];

$eventWindowStart = $toLocalDt((string) ($event['start_at'] ?? ''));
$eventWindowClose = $eventWindowStart
    ? $eventWindowStart->modify('+' . max(1, (int) ($event['grace_time'] ?? 30)) . ' minutes')
    : null;
$eventWindowClosed = $eventWindowClose ? ($nowUtc > $eventWindowClose->setTimezone(new DateTimeZone('UTC'))) : false;

if ($eventWindowClosed) {
    $syncNowIso = $nowUtc->format('c');
    foreach ($participants as $participantIndex => $participant) {
        if (!is_array($participant)) {
            continue;
        }

        $tickets = isset($participant['tickets']) && is_array($participant['tickets']) ? $participant['tickets'] : [];
        if (!isset($tickets[0]) || !is_array($tickets[0])) {
            continue;
        }

        $ticket = $tickets[0];
        $ticketId = (string) ($ticket['id'] ?? '');
        if ($ticketId === '') {
            continue;
        }

        $attendance = null;
        if (isset($ticket['attendance']) && is_array($ticket['attendance'])) {
            $atts = $ticket['attendance'];
            $attendance = isset($atts[0]) && is_array($atts[0]) ? $atts[0] : (is_array($atts) ? $atts : null);
        }

        if ($attendanceCountsAsPresent(is_array($attendance) ? $attendance : null)) {
            continue;
        }

        $statusRaw = strtolower(trim((string) (is_array($attendance) ? ($attendance['status'] ?? '') : '')));
        if ($statusRaw === 'absent') {
            continue;
        }

        $updatedRow = null;
        $attendanceId = (string) (is_array($attendance) ? ($attendance['id'] ?? '') : '');
        if ($attendanceId !== '') {
            $patchUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/attendance'
                . '?id=eq.' . rawurlencode($attendanceId)
                . '&check_in_at=is.null'
                . '&select=id,check_in_at,status,last_scanned_at';
            $patchRes = supabase_request(
                'PATCH',
                $patchUrl,
                [
                    'Content-Type: application/json',
                    'Accept: application/json',
                    'apikey: ' . SUPABASE_KEY,
                    'Authorization: Bearer ' . SUPABASE_KEY,
                    'Prefer: return=representation',
                ],
                json_encode([
                    'status' => 'absent',
                    'last_scanned_at' => $syncNowIso,
                ], JSON_UNESCAPED_SLASHES)
            );
            if ($patchRes['ok']) {
                $patchedRows = json_decode((string) $patchRes['body'], true);
                if (is_array($patchedRows) && isset($patchedRows[0]) && is_array($patchedRows[0])) {
                    $updatedRow = $patchedRows[0];
                }
            }
        }

        if (!is_array($updatedRow)) {
            $patchByTicketUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/attendance'
                . '?ticket_id=eq.' . rawurlencode($ticketId)
                . '&check_in_at=is.null'
                . '&select=id,check_in_at,status,last_scanned_at';
            $patchByTicketRes = supabase_request(
                'PATCH',
                $patchByTicketUrl,
                [
                    'Content-Type: application/json',
                    'Accept: application/json',
                    'apikey: ' . SUPABASE_KEY,
                    'Authorization: Bearer ' . SUPABASE_KEY,
                    'Prefer: return=representation',
                ],
                json_encode([
                    'status' => 'absent',
                    'last_scanned_at' => $syncNowIso,
                ], JSON_UNESCAPED_SLASHES)
            );
            if ($patchByTicketRes['ok']) {
                $patchedRows = json_decode((string) $patchByTicketRes['body'], true);
                if (is_array($patchedRows) && isset($patchedRows[0]) && is_array($patchedRows[0])) {
                    $updatedRow = $patchedRows[0];
                }
            }
        }

        if (!is_array($updatedRow)) {
            $insertUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/attendance?select=id,check_in_at,status,last_scanned_at';
            $insertRes = supabase_request(
                'POST',
                $insertUrl,
                [
                    'Content-Type: application/json',
                    'Accept: application/json',
                    'apikey: ' . SUPABASE_KEY,
                    'Authorization: Bearer ' . SUPABASE_KEY,
                    'Prefer: return=representation',
                ],
                json_encode([[
                    'ticket_id' => $ticketId,
                    'status' => 'absent',
                    'last_scanned_at' => $syncNowIso,
                ]], JSON_UNESCAPED_SLASHES)
            );
            if ($insertRes['ok']) {
                $insertedRows = json_decode((string) $insertRes['body'], true);
                if (is_array($insertedRows) && isset($insertedRows[0]) && is_array($insertedRows[0])) {
                    $updatedRow = $insertedRows[0];
                }
            }
        }

        if (!is_array($updatedRow)) {
            $updatedRow = [
                'id' => $attendanceId !== '' ? $attendanceId : null,
                'check_in_at' => null,
                'status' => 'absent',
                'last_scanned_at' => $syncNowIso,
            ];
        }

        $tickets[0]['attendance'] = [$updatedRow];
        $participants[$participantIndex]['tickets'] = $tickets;
    }
}

$reasonByStudentEvent = [];
if ($absenceReasonTableAvailable) {
    foreach ($absenceReasonRows as $reason) {
        if (!is_array($reason)) {
            continue;
        }
        $studentId = (string) ($reason['student_id'] ?? '');
        $sessionId = trim((string) ($reason['session_id'] ?? ''));
        if ($studentId === '' || $sessionId !== '') {
            continue;
        }
        $reasonByStudentEvent[$studentId] = $reason;
    }
}

$simpleEventTimeoutClose = null;
$simpleEventEarlyOutRaw = trim((string) ($event['early_out_enabled_at'] ?? ''));
if ($simpleEventEarlyOutRaw !== '') {
    try {
        $simpleEventTimeoutClose = (new DateTimeImmutable($simpleEventEarlyOutRaw))
            ->setTimezone(new DateTimeZone('UTC'))
            ->modify('+1 hour');
    } catch (Throwable $e) {
        $simpleEventTimeoutClose = null;
    }
}
if (!$simpleEventTimeoutClose instanceof DateTimeImmutable) {
    $simpleEventEndRaw = trim((string) ($event['end_at'] ?? ''));
    if ($simpleEventEndRaw !== '') {
        try {
            $simpleEventTimeoutClose = (new DateTimeImmutable($simpleEventEndRaw))
                ->setTimezone(new DateTimeZone('UTC'))
                ->modify('+1 hour');
        } catch (Throwable $e) {
            $simpleEventTimeoutClose = null;
        }
    }
}
$simpleEventTimeoutClosed = $simpleEventTimeoutClose instanceof DateTimeImmutable
    && $nowUtc > $simpleEventTimeoutClose;

$simpleEventAbsentRows = [];
$seenSimpleFollowUps = [];
$simpleParticipantByStudent = [];
foreach ($participants as $participant) {
    if (!is_array($participant)) {
        continue;
    }
    $studentId = (string) ($participant['student_id'] ?? '');
    if ($studentId === '') {
        continue;
    }
    $simpleParticipantByStudent[$studentId] = $participant;
    $profile = isset($participant['users']) && is_array($participant['users']) ? $participant['users'] : [];
    $tickets = isset($participant['tickets']) && is_array($participant['tickets']) ? $participant['tickets'] : [];
    $ticket = isset($tickets[0]) && is_array($tickets[0]) ? $tickets[0] : [];
    $attendance = null;
    if (isset($ticket['attendance']) && is_array($ticket['attendance'])) {
        $atts = $ticket['attendance'];
        $attendance = isset($atts[0]) && is_array($atts[0]) ? $atts[0] : null;
    }
    $isPresent = $attendanceCountsAsPresent($attendance);
    $hasCheckOut = $attendanceHasCheckOut($attendance);
    $reason = isset($reasonByStudentEvent[$studentId]) && is_array($reasonByStudentEvent[$studentId])
        ? $reasonByStudentEvent[$studentId]
        : null;
    $section = isset($profile['sections']) && is_array($profile['sections'])
        ? (string) ($profile['sections']['name'] ?? '')
        : '';
    $rowBase = [
        'student_id' => $studentId,
        'participant_name' => $formatParticipantName($profile),
        'student_number' => (string) ($profile['student_id'] ?? 'N/A'),
        'section' => $section !== '' ? $section : 'N/A',
        'reason' => $reason,
    ];

    if ($eventWindowClosed && !$isPresent) {
        $seenSimpleFollowUps[$studentId] = true;
        $simpleEventAbsentRows[] = $rowBase + [
            'case' => $inferFollowUpCase($reason, 'absent'),
        ];
        continue;
    }

    if ($simpleEventTimeoutClosed && $isPresent && !$hasCheckOut) {
        $seenSimpleFollowUps[$studentId] = true;
        $simpleEventAbsentRows[] = $rowBase + [
            'case' => 'missed_timeout',
        ];
    }
}

foreach ($reasonByStudentEvent as $studentId => $reason) {
    if (!is_array($reason) || isset($seenSimpleFollowUps[$studentId])) {
        continue;
    }
    $participant = $simpleParticipantByStudent[$studentId] ?? [];
    $profile = isset($participant['users']) && is_array($participant['users']) ? $participant['users'] : [];
    $section = isset($profile['sections']) && is_array($profile['sections'])
        ? (string) ($profile['sections']['name'] ?? '')
        : '';
    $seenSimpleFollowUps[$studentId] = true;
    $simpleEventAbsentRows[] = [
        'student_id' => $studentId,
        'participant_name' => $formatParticipantName($profile),
        'student_number' => (string) ($profile['student_id'] ?? 'N/A'),
        'section' => $section !== '' ? $section : 'N/A',
        'case' => $inferFollowUpCase($reason, 'absent'),
        'reason' => $reason,
    ];
}

$simpleEventReasonCount = count($reasonByStudentEvent);
$simpleEventAbsentCount = 0;
$simpleEventTimeoutCount = 0;
foreach ($simpleEventAbsentRows as $followUpRow) {
    if (($followUpRow['case'] ?? '') === 'missed_timeout') {
        $simpleEventTimeoutCount++;
    } else {
        $simpleEventAbsentCount++;
    }
}

render_header('Participants', $user);

$exportExcelHtml = '<a href="/participants?event_id=' . htmlspecialchars($eventId)
    . '&export=excel' . htmlspecialchars($returnToQuery)
    . '" class="rounded-xl border border-emerald-200 bg-emerald-600 text-white px-4 py-2 text-sm font-semibold hover:bg-emerald-700 transition shadow-sm flex items-center gap-2 group">'
    . '<svg class="w-4 h-4 group-hover:scale-110 transition-transform" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5M16.5 12L12 16.5m0 0L7.5 12m4.5 4.5V3"/></svg>'
    . 'Export Excel</a>';

render_event_page_header([
    'back_href' => $backHref,
    'title' => (string) ($event['title'] ?? 'Event'),
    'subtitle' => 'Participant directory and real-time attendance tracking.',
    'actions_html' => $absenceExportHtml . $exportExcelHtml,
]);

render_event_tabs([
    'event_id' => $eventId,
    'current_tab' => $participantTab === 'absence_reasons' ? 'absence_reasons' : 'participants',
    'role' => $role,
    'uses_sessions' => $usesSessions,
    'event_status' => (string) ($event['status'] ?? ''),
    'participant_day' => $activeDay,
    'return_to' => $returnTo,
    'has_student_requirements' => $hasStudentRequirements,
    'is_event_creator' => $isEventCreator,
    'is_paid_event' => $isPaidEvent,
]);
?>

<?php if ($multiDay && $participantTab === 'participants'): ?>
  <div class="mb-6 flex gap-2 flex-wrap bg-zinc-100 p-1.5 rounded-2xl border border-zinc-200 w-full sm:w-fit">
    <a class="px-4 py-2 rounded-xl text-xs font-bold transition-all <?= $activeDay === 'all' ? 'bg-orange-600 text-white shadow-sm' : 'text-zinc-600 hover:bg-white' ?>"
       href="/participants?event_id=<?= htmlspecialchars($eventId) ?>&participant_tab=participants&day=all<?= htmlspecialchars($returnToQuery) ?>">All Days</a>
    <?php foreach ($days as $day): ?>
      <a class="px-4 py-2 rounded-xl text-xs font-bold transition-all <?= $activeDay === $day ? 'bg-orange-600 text-white shadow-sm' : 'text-zinc-600 hover:bg-white' ?>"
         href="/participants?event_id=<?= htmlspecialchars($eventId) ?>&participant_tab=participants&day=<?= htmlspecialchars($day) ?><?= htmlspecialchars($returnToQuery) ?>"><?= htmlspecialchars((new DateTimeImmutable($day))->format('M d, Y')) ?></a>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<?php if ($participantTab === 'participants'): ?>
<div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-5">
  <div class="flex flex-wrap items-center gap-3">
    <h3 class="text-lg font-bold text-zinc-900 tracking-tight flex items-center gap-2">
       <div class="w-8 h-8 rounded-xl bg-orange-100 border border-orange-200 flex items-center justify-center">
         <svg class="w-4 h-4 text-orange-700" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 002.625.372 9.337 9.337 0 004.121-.952 4.125 4.125 0 00-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 018.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0111.964-3.07M12 6.375a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zm8.25 2.25a2.625 2.625 0 11-5.25 0 2.625 2.625 0 015.25 0z"/></svg>
       </div>
       Registered Attendees
    </h3>
    <div class="px-3.5 py-1.5 rounded-xl bg-zinc-100 border border-zinc-200 flex items-center gap-2">
       <span class="text-[11px] font-bold text-zinc-600 uppercase tracking-wider">Total</span>
       <span id="totalCount" class="text-base font-bold text-zinc-900 leading-none"><?= count($rows) ?></span>
    </div>
  </div>

  <div class="relative w-full sm:w-80 group">
    <div class="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none text-zinc-400 group-focus-within:text-orange-500 transition-colors">
      <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z"/></svg>
    </div>
    <input type="text" id="participantSearch" placeholder="Search name, email, or section..." 
      class="block w-full pl-10 pr-4 py-2.5 bg-white border border-zinc-200 rounded-xl text-sm placeholder:text-zinc-400 focus:outline-none focus:ring-2 focus:ring-orange-500/20 focus:border-orange-500 transition shadow-sm">
  </div>
</div>


<style>
.participant-list .att-badge {
  display: inline-flex;
  align-items: center;
  font-size: 0.65rem;
  font-weight: 800;
  padding: 0.28rem 0.6rem;
  border-radius: 999px;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  white-space: nowrap;
  border: 1px solid transparent;
}
.att-present  { background: #d1fae5; color: #065f46; border-color: #a7f3d0; }
.att-absent   { background: #fee2e2; color: #991b1b; border-color: #fecaca; }
.att-late     { background: #fef3c7; color: #92400e; border-color: #fde68a; }
.att-early    { background: #e0f2fe; color: #075985; border-color: #bae6fd; }
.att-timed-out{ background: #dbeafe; color: #1e3a8a; border-color: #bfdbfe; }
.att-unscanned{ background: #f4f4f5; color: #52525b; border-color: #e4e4e7; }
.participant-list .admin-btns {
  display: flex;
  align-items: center;
  gap: 0.35rem;
  flex-wrap: nowrap;
}
.participant-list .admin-btns button {
  background: #fff;
  color: #3f3f46;
  border: 1px solid #d4d4d8;
  border-radius: 0.5rem;
  font-size: 0.7rem;
  font-weight: 700;
  line-height: 1;
  padding: 0.45rem 0.6rem;
  cursor: pointer;
  white-space: nowrap;
}
.participant-list .admin-btns button:hover {
  background: #fafafa;
}
.participant-list .admin-btns button.btnRemove {
  color: #b91c1c;
  border-color: #fecaca;
}
.participant-list .admin-btns button.btnRemove:hover {
  background: #fef2f2;
}
.participant-avatar {
  width: 2.25rem;
  height: 2.25rem;
  border-radius: 999px;
  overflow: hidden;
  flex-shrink: 0;
  background: #fff7ed;
  border: 1px solid #fed7aa;
  display: flex;
  align-items: center;
  justify-content: center;
}
.participant-avatar img {
  width: 100%;
  height: 100%;
  object-fit: cover;
  object-position: center top;
}
.participant-avatar .profile-initials {
  font-size: 0.7rem;
  font-weight: 800;
  color: #ea580c;
  user-select: none;
}
</style>

<div class="participant-list rounded-2xl border border-zinc-200 bg-white shadow-sm overflow-x-auto pb-2">
  <table class="min-w-full divide-y divide-zinc-200">
    <thead class="bg-zinc-50">
      <tr>
        <th class="px-4 py-3 text-left text-xs font-bold uppercase tracking-wider text-zinc-500">Participant</th>
        <th class="px-4 py-3 text-left text-xs font-bold uppercase tracking-wider text-zinc-500">Student No.</th>
        <th class="px-4 py-3 text-left text-xs font-bold uppercase tracking-wider text-zinc-500">Section</th>
        <th class="px-4 py-3 text-left text-xs font-bold uppercase tracking-wider text-zinc-500">Time In / Out</th>
        <th class="px-4 py-3 text-left text-xs font-bold uppercase tracking-wider text-zinc-500">Status</th>
        <?php if ($canResetAttendance || $canRemoveParticipant): ?>
          <th class="px-4 py-3 text-right text-xs font-bold uppercase tracking-wider text-zinc-500">Actions</th>
        <?php endif; ?>
      </tr>
    </thead>
    <tbody class="divide-y divide-zinc-100">
      <?php if (count($rows) === 0): ?>
        <tr class="pointer-events-none">
          <td colspan="<?= ($canResetAttendance || $canRemoveParticipant) ? 6 : 5 ?>" class="px-4 py-12 text-center text-sm text-zinc-500 font-semibold">
            <p class="text-zinc-700 font-semibold text-sm">No participants found</p>
          </td>
        </tr>
      <?php endif; ?>

      <?php foreach ($rows as $r): ?>
        <?php
          $u = isset($r['users']) && is_array($r['users']) ? $r['users'] : [];
          $nameParts = [];
          foreach (['first_name','middle_name','last_name'] as $k) {
              $v = trim((string) ($u[$k] ?? ''));
              if ($v !== '') $nameParts[] = $v;
          }
          $name = implode(' ', $nameParts);
          $suffix = trim((string) ($u['suffix'] ?? ''));
          if ($suffix !== '') $name .= ', ' . $suffix;

          $tickets = isset($r['tickets']) && is_array($r['tickets']) ? $r['tickets'] : [];
          $ticket  = isset($tickets[0]) && is_array($tickets[0]) ? $tickets[0] : [];

          $attendance = null;
          if (isset($ticket['attendance'])) {
              $atts = $ticket['attendance'];
              if (is_array($atts)) {
                  $attendance = isset($atts[0]) && is_array($atts[0]) ? $atts[0] : $atts;
              }
          }

          $checkInRaw = is_array($attendance) ? ($attendance['check_in_at'] ?? '') : '';
          $checkOutRaw = is_array($attendance) ? ($attendance['check_out_at'] ?? '') : '';
          $attStatus  = is_array($attendance) ? ($attendance['status'] ?? '') : '';
          if (!$attendanceCountsAsPresent(is_array($attendance) ? $attendance : null) && $eventWindowClosed) {
              $attStatus = 'absent';
          }
          $registrationId = (string) ($r['id'] ?? '');

          $initials = '';
          foreach ($nameParts as $p) { $initials .= mb_strtoupper(mb_substr($p, 0, 1)); if (mb_strlen($initials) >= 2) break; }
          if (mb_strlen($initials) === 0) $initials = '?';

          $checkInShort = '—';
          $checkOutShort = '—';
          if ($checkInRaw) {
              try {
                  $checkInLocal = $toLocalDt((string) $checkInRaw);
                  if ($checkInLocal) $checkInShort = $checkInLocal->format('g:i A');
              } catch (Throwable $e) {}
          }
          if (trim((string) $checkOutRaw) !== '') {
              try {
                  $checkOutLocal = $toLocalDt((string) $checkOutRaw);
                  if ($checkOutLocal) $checkOutShort = $checkOutLocal->format('g:i A');
              } catch (Throwable $e) {}
          }
          $attendanceTimeLine = $checkInShort . ' / ' . $checkOutShort;

          $sec     = isset($u['sections']) && is_array($u['sections']) ? $u['sections'] : null;
          $secName = is_array($sec) && isset($sec['name']) ? trim((string) $sec['name']) : '';
          if ($secName === '') {
              $secName = 'N/A';
          }
          $yearKey = student_roster_resolve_year_key(
              trim((string) ($r['student_id'] ?? '')),
              trim((string) ($u['student_id'] ?? '')),
              $secName,
              $participantYearMaps
          );
          $yearLvl = student_roster_year_ordinal_label($yearKey);
          if ($yearLvl === '') {
              $yearLvl = 'N/A';
          }
          $sectionSubtitle = $secName;
          $sectionHasYearDigit = preg_match('/[1-4]/', $secName) === 1
              && !preg_match('/irreg/i', $secName);
          if ($yearLvl !== 'N/A' && !$sectionHasYearDigit) {
              $sectionSubtitle = (strcasecmp($secName, 'N/A') === 0)
                  ? $yearLvl
                  : ($secName . ' • ' . $yearLvl);
          }

          $attStatusNorm = strtolower(trim((string)$attStatus));
          if ($checkOutShort !== '—' && in_array($attStatusNorm, ['present', 'scanned', 'completed', 'late', 'early', ''], true)) {
              $attStatusNorm = 'timed out';
          }
          $attBadgeClass = match($attStatusNorm) {
              'present','scanned','completed' => 'att-present',
              'absent'  => 'att-absent',
              'late'    => 'att-late',
              'early'   => 'att-early',
              'timed out' => 'att-timed-out',
              default   => 'att-unscanned',
          };
          $attLabel = $attStatusNorm !== '' ? ucfirst($attStatusNorm) : 'Unscanned';

          $avatarUrl = storage_resolve_user_avatar_url(
              (string) ($r['student_id'] ?? ''),
              (string) ($u['photo_url'] ?? '')
          );
          $studentId = (string) ($u['student_id'] ?? 'N/A');
          $email     = (string) ($u['email'] ?? '');
          $searchStr = strtolower($name . ' ' . $email . ' ' . $studentId . ' ' . $secName . ' ' . $yearLvl);
        ?>
        <tr class="participant-card participant-row hover:bg-zinc-50/80"
            data-search="<?= htmlspecialchars($searchStr) ?>">
          <td class="px-4 py-3">
            <div class="flex items-center gap-3 min-w-0">
              <div class="participant-avatar">
                <?php if ($avatarUrl !== ''): ?>
                  <img
                    src="<?= htmlspecialchars($avatarUrl) ?>"
                    alt="<?= htmlspecialchars($name) ?>"
                    loading="lazy"
                    onerror="this.style.display='none'; const fb=this.nextElementSibling; if(fb) fb.style.display='flex';"
                  >
                  <div class="profile-initials" style="display:none;"><?= htmlspecialchars($initials) ?></div>
                <?php else: ?>
                  <div class="profile-initials"><?= htmlspecialchars($initials) ?></div>
                <?php endif; ?>
              </div>
              <div class="min-w-0">
                <div class="text-sm font-semibold text-zinc-900 truncate" title="<?= htmlspecialchars($name) ?>">
                  <?= htmlspecialchars($name ?: 'Unnamed') ?>
                </div>
                <div class="text-xs text-zinc-500 truncate" title="<?= htmlspecialchars($email) ?>">
                  <?= htmlspecialchars($email !== '' ? $email : '—') ?>
                </div>
              </div>
            </div>
          </td>
          <td class="px-4 py-3 text-sm text-zinc-700 whitespace-nowrap"><?= htmlspecialchars($studentId) ?></td>
          <td class="px-4 py-3 text-sm text-zinc-600">
            <div class="truncate" title="<?= htmlspecialchars($sectionSubtitle) ?>"><?= htmlspecialchars($sectionSubtitle) ?></div>
          </td>
          <td class="px-4 py-3 text-sm text-zinc-600 whitespace-nowrap"><?= htmlspecialchars($attendanceTimeLine) ?></td>
          <td class="px-4 py-3">
            <span class="att-badge <?= $attBadgeClass ?>"><?= htmlspecialchars($attLabel) ?></span>
          </td>
          <?php if ($canResetAttendance || $canRemoveParticipant): ?>
            <td class="px-4 py-3 text-right">
              <div class="admin-btns justify-end">
                <?php if ($canResetAttendance): ?>
                <?php
                  $rowHasOut = trim((string) $checkOutRaw) !== '';
                  $rowHasIn = $attendanceCountsAsPresent(is_array($attendance) ? $attendance : null);
                  $rowResetLabel = $rowHasOut ? '↺ Out' : '↺ In';
                  $rowResetAria = $rowHasOut ? 'Reset Time-Out' : 'Reset Time-In';
                  $rowResetConfirm = $rowHasOut
                      ? 'Reset this participant time-out? Time-in will be kept. Reset again to clear time-in.'
                      : 'Reset this participant time-in?';
                ?>
                <?php if ($rowHasIn): ?>
                <button
                  type="button"
                  class="btnResetAttendance"
                  data-id="<?= htmlspecialchars($registrationId) ?>"
                  data-confirm="<?= htmlspecialchars($rowResetConfirm) ?>"
                  title="<?= htmlspecialchars($rowResetConfirm) ?>"
                  aria-label="<?= htmlspecialchars($rowResetAria) ?>"
                ><?= htmlspecialchars($rowResetLabel) ?></button>
                <?php endif; ?>
                <?php endif; ?>
                <?php if ($canRemoveParticipant): ?>
                <button
                  type="button"
                  class="btnRemove"
                  data-id="<?= htmlspecialchars($registrationId) ?>"
                  title="Remove participant"
                  aria-label="Remove participant"
                >✕</button>
                <?php endif; ?>
              </div>
            </td>
          <?php endif; ?>
        </tr>
      <?php endforeach; ?>

      <?php if (count($rows) > 0): ?>
        <tr id="participantSearchEmpty" class="pointer-events-none hidden">
          <td colspan="<?= ($canResetAttendance || $canRemoveParticipant) ? 6 : 5 ?>" class="px-4 py-12 text-center text-sm text-zinc-500 font-semibold">
            <p class="text-zinc-700 font-semibold text-sm">No results match your search</p>
          </td>
        </tr>
      <?php endif; ?>
    </tbody>
  </table>
</div>
<?php else: ?>
<?php if (!$absenceReasonTableAvailable): ?>
  <div class="rounded-2xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900 mb-6">
    Absence reason storage is not available yet. Apply migration <code>008_attendance_absence_reasons.sql</code> first.
  </div>
<?php endif; ?>
<div class="mb-4 grid grid-cols-1 md:grid-cols-3 gap-4">
  <div class="rounded-2xl border border-zinc-200 bg-white p-4">
    <div class="text-xs font-bold uppercase tracking-wider text-zinc-500">Event Window</div>
    <div class="mt-2 text-sm font-semibold text-zinc-900">
      <?php if ($eventWindowStart && $eventWindowClose): ?>
        <?= htmlspecialchars($eventWindowStart->format('M j, g:i A')) ?> - <?= htmlspecialchars($eventWindowClose->format('g:i A')) ?>
      <?php else: ?>
        No start time
      <?php endif; ?>
    </div>
    <div class="mt-2 text-xs text-zinc-500">No time-in after scan close. No time-out after end + 1 hour.</div>
  </div>
  <div class="rounded-2xl border border-zinc-200 bg-white p-4">
    <div class="text-xs font-bold uppercase tracking-wider text-zinc-500">Follow-ups</div>
    <div class="mt-2 text-2xl font-black text-amber-700"><?= count($simpleEventAbsentRows) ?></div>
    <div class="mt-2 text-xs text-zinc-500"><?= (int) $simpleEventAbsentCount ?> no time-in · <?= (int) $simpleEventTimeoutCount ?> no time-out</div>
  </div>
  <div class="rounded-2xl border border-zinc-200 bg-white p-4">
    <div class="text-xs font-bold uppercase tracking-wider text-zinc-500">Reasons Submitted</div>
    <div class="mt-2 text-2xl font-black text-emerald-700"><?= (int) $simpleEventReasonCount ?></div>
  </div>
</div>

<div class="rounded-2xl border border-zinc-200 bg-white shadow-sm overflow-x-auto">
  <table class="min-w-full divide-y divide-zinc-200">
    <thead class="bg-zinc-50">
      <tr>
        <th class="px-4 py-3 text-left text-xs font-bold uppercase tracking-wider text-zinc-500">Participant</th>
        <th class="px-4 py-3 text-left text-xs font-bold uppercase tracking-wider text-zinc-500">Student No.</th>
        <th class="px-4 py-3 text-left text-xs font-bold uppercase tracking-wider text-zinc-500">Section</th>
        <th class="px-4 py-3 text-left text-xs font-bold uppercase tracking-wider text-zinc-500">Case</th>
        <th class="px-4 py-3 text-left text-xs font-bold uppercase tracking-wider text-zinc-500">Reason</th>
        <th class="px-4 py-3 text-left text-xs font-bold uppercase tracking-wider text-zinc-500">Submitted</th>
      </tr>
    </thead>
    <tbody class="divide-y divide-zinc-100">
      <?php if (count($simpleEventAbsentRows) === 0): ?>
        <tr>
          <td colspan="6" class="px-4 py-12 text-center text-sm text-zinc-500 font-semibold">
            <?php if (!$eventWindowClosed && !$simpleEventTimeoutClosed): ?>
              Event scan / time-out windows are still open. Follow-ups will appear after they close.
            <?php else: ?>
              No unresolved absences or submitted reasons found.
            <?php endif; ?>
          </td>
        </tr>
      <?php endif; ?>
      <?php foreach ($simpleEventAbsentRows as $row): ?>
        <?php
          $reason = $row['reason'];
          $hasReason = is_array($reason);
          $submittedLabel = $hasReason && !empty($reason['submitted_at'])
              ? format_date_local((string) $reason['submitted_at'], 'M j, g:i A')
              : '—';
          $reviewStatus = $hasReason ? strtolower(trim((string) ($reason['review_status'] ?? 'pending'))) : '';
          $reviewLabel = $reviewStatus === 'approved'
              ? 'Approved'
              : ($reviewStatus === 'rejected' ? 'Rejected' : 'For Review');
          $reviewBadge = $reviewStatus === 'approved'
              ? 'bg-emerald-100 text-emerald-800 border-emerald-200'
              : ($reviewStatus === 'rejected'
                  ? 'bg-red-100 text-red-800 border-red-200'
                  : 'bg-sky-100 text-sky-800 border-sky-200');
          $fullReasonText = (string) ($reason['reason_text'] ?? '');
          if (function_exists('mb_strlen') && function_exists('mb_substr')) {
              $reasonPreview = mb_strlen($fullReasonText) > 72
                  ? (mb_substr($fullReasonText, 0, 72) . '...')
                  : $fullReasonText;
          } else {
              $reasonPreview = strlen($fullReasonText) > 72
                  ? (substr($fullReasonText, 0, 72) . '...')
                  : $fullReasonText;
          }
          $reasonModalId = 'reason-modal-event-' . ($reason['id'] ?? md5((string) $row['participant_name'] . (string) $row['section']));
        ?>
        <tr>
          <td class="px-4 py-4 text-sm font-semibold text-zinc-900"><?= htmlspecialchars((string) $row['participant_name']) ?></td>
          <td class="px-4 py-4 text-sm text-zinc-600"><?= htmlspecialchars((string) $row['student_number']) ?></td>
          <td class="px-4 py-4 text-sm text-zinc-600"><?= htmlspecialchars((string) $row['section']) ?></td>
          <?php
            $rowCase = (string) ($row['case'] ?? 'absent');
            $rowCaseLabel = $followUpCaseLabel($rowCase);
            $rowCaseBadge = $rowCase === 'missed_timeout'
                ? 'bg-orange-50 text-orange-800 border-orange-200'
                : 'bg-amber-50 text-amber-800 border-amber-200';
          ?>
          <td class="px-4 py-4 text-sm text-zinc-700">
            <span class="inline-flex items-center rounded-full border px-2 py-1 text-[10px] font-bold uppercase tracking-wider <?= $rowCaseBadge ?>">
              <?= htmlspecialchars($rowCaseLabel) ?>
            </span>
          </td>
          <td class="px-4 py-4 text-sm text-zinc-700">
            <?php if ($hasReason): ?>
              <div class="space-y-2">
                <span class="inline-flex items-center rounded-full border px-2 py-1 text-[10px] font-bold uppercase tracking-wider <?= $reviewBadge ?>">
                  <?= htmlspecialchars($reviewLabel) ?>
                </span>
                <button
                  type="button"
                  class="btn-view-reason inline-flex items-center rounded-lg border border-zinc-300 bg-white px-3 py-1.5 text-xs font-semibold text-zinc-700 hover:bg-zinc-50"
                  data-modal-id="<?= htmlspecialchars((string) $reasonModalId) ?>"
                >
                  View full reason
                </button>
                <div id="<?= htmlspecialchars((string) $reasonModalId) ?>" class="reason-modal fixed inset-0 z-[100] hidden">
                  <div class="absolute inset-0 bg-black/50 reason-modal-close" data-modal-id="<?= htmlspecialchars((string) $reasonModalId) ?>"></div>
                  <div class="absolute inset-0 flex items-center justify-center p-4">
                    <div class="w-full max-w-xl rounded-2xl border border-zinc-200 bg-white shadow-2xl">
                      <div class="flex items-center justify-between border-b border-zinc-200 px-5 py-4">
                        <div>
                          <div class="text-sm font-bold text-zinc-900"><?= htmlspecialchars((string) $row['participant_name']) ?></div>
                          <div class="text-xs text-zinc-500"><?= htmlspecialchars((string) $row['section']) ?> • <?= htmlspecialchars($submittedLabel) ?></div>
                        </div>
                        <button type="button" class="reason-modal-close rounded-lg p-2 text-zinc-500 hover:bg-zinc-100" data-modal-id="<?= htmlspecialchars((string) $reasonModalId) ?>">✕</button>
                      </div>
                      <div class="px-5 py-4">
                        <div class="mb-3">
                          <span class="inline-flex items-center rounded-full border px-2 py-1 text-[10px] font-bold uppercase tracking-wider <?= $reviewBadge ?>">
                            <?= htmlspecialchars($reviewLabel) ?>
                          </span>
                        </div>
                        <div class="max-h-72 overflow-y-auto whitespace-pre-wrap text-sm leading-6 text-zinc-700"><?= nl2br(htmlspecialchars($fullReasonText)) ?></div>
                        <?php if (!empty($reason['admin_note'])): ?>
                          <div class="mt-4 rounded-xl border border-zinc-200 bg-zinc-50 p-3 text-xs text-zinc-600">
                            <span class="font-semibold">Admin note:</span>
                            <div class="mt-1 whitespace-pre-wrap"><?= nl2br(htmlspecialchars((string) $reason['admin_note'])) ?></div>
                          </div>
                        <?php endif; ?>
                      </div>
                    </div>
                  </div>
                </div>
              </div>
            <?php else: ?>
              <span class="inline-flex items-center rounded-full border border-red-200 bg-red-50 px-2 py-1 text-[10px] font-bold uppercase tracking-wider text-red-700">
                No reason submitted
              </span>
            <?php endif; ?>
          </td>
          <td class="px-4 py-4 text-sm text-zinc-600"><?= htmlspecialchars($submittedLabel) ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

<?php if ($canResetAttendance || $canRemoveParticipant): ?>
<script>
  document.querySelectorAll('.btn-view-reason').forEach(btn => {
    btn.addEventListener('click', () => {
      const id = btn.dataset.modalId;
      const modal = id ? document.getElementById(id) : null;
      if (modal) modal.classList.remove('hidden');
    });
  });

  document.querySelectorAll('.reason-modal-close').forEach(btn => {
    btn.addEventListener('click', () => {
      const id = btn.dataset.modalId;
      const modal = id ? document.getElementById(id) : null;
      if (modal) modal.classList.add('hidden');
    });
  });

  <?php if ($canResetAttendance): ?>
  document.querySelectorAll('.btnResetAttendance').forEach(btn => {
    btn.addEventListener('click', async () => {
      const confirmText = (btn.dataset.confirm || <?= json_encode($resetConfirmMessage) ?>).trim();
      const ok = confirm(confirmText);
      if (!ok) return;
      btn.disabled = true;
      try {
        const registration_id = btn.dataset.id;
        const payload = { registration_id, csrf_token: window.CSRF_TOKEN };
        if ((btn.dataset.sessionId || '').trim() !== '') {
          payload.session_id = btn.dataset.sessionId.trim();
        }
        const res = await fetch('/api/participant_attendance_reset.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify(payload)
        });
        const data = await res.json();
        if (!data.ok) throw new Error(data.error || 'Failed');
        window.location.reload();
      } catch (e) {
        alert(e.message || 'Failed');
      } finally {
        btn.disabled = false;
      }
    });
  });
  <?php endif; ?>

  <?php if ($canRemoveParticipant): ?>
  document.querySelectorAll('.btnRemove').forEach(btn => {
    btn.addEventListener('click', async () => {
      const ok = confirm('Remove this participant?');
      if (!ok) return;
      btn.disabled = true;
      try {
        const registration_id = btn.dataset.id;
        const res = await fetch('/api/participant_remove.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ registration_id, csrf_token: window.CSRF_TOKEN })
        });
        const data = await res.json();
        if (!data.ok) throw new Error(data.error || 'Failed');
        window.location.reload();
      } catch (e) {
        alert(e.message || 'Failed');
      } finally {
        btn.disabled = false;
      }
    });
  });
  <?php endif; ?>

  // Client-side live search
  const searchInput = document.getElementById('participantSearch');
  const totalCountEl = document.getElementById('totalCount');
  const cards = document.querySelectorAll('.participant-card');
  const searchEmpty = document.getElementById('participantSearchEmpty');

  if (searchInput) {
    searchInput.addEventListener('input', (e) => {
      const term = e.target.value.toLowerCase().trim();
      let visibleCount = 0;

      cards.forEach(card => {
        const searchable = card.dataset.search || '';
        if (searchable.includes(term)) {
          card.classList.remove('hidden');
          visibleCount++;
        } else {
          card.classList.add('hidden');
        }
      });

      if (totalCountEl) totalCountEl.textContent = visibleCount;

      if (searchEmpty) {
        if (visibleCount === 0 && cards.length > 0) {
          searchEmpty.classList.remove('hidden');
        } else {
          searchEmpty.classList.add('hidden');
        }
      }
    });
  }

  const multiSearchInput = document.getElementById('multiSessionSearchInput');
  if (multiSearchInput) {
    multiSearchInput.addEventListener('input', (e) => {
      const term = e.target.value.toLowerCase().trim();
      document.querySelectorAll('.multi-participant-row').forEach(row => {
        const searchable = row.dataset.search || '';
        if (searchable.includes(term)) {
          row.style.display = '';
        } else {
          row.style.display = 'none';
        }
      });
    });
  }
</script>
<?php endif; ?>

<script>
(() => {
  const eventId = <?= json_encode($eventId) ?>;
  if (!eventId || !window.CSRF_TOKEN) return;
  const run = () => {
    fetch('/api/attendance_backfill_run.php', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
      body: JSON.stringify({ event_id: eventId, csrf_token: window.CSRF_TOKEN })
    }).catch(() => {});
  };
  if ('requestIdleCallback' in window) {
    requestIdleCallback(run, { timeout: 2500 });
  } else {
    setTimeout(run, 1200);
  }
})();

(() => {
  const btn = document.getElementById('btnExportAbsenceForm');
  if (!btn) return;
  btn.addEventListener('click', async () => {
    const eventId = String(btn.dataset.eventId || '').trim();
    if (!eventId) return;
    const label = btn.querySelector('[data-label]');
    const prev = label ? label.textContent : 'Export Absence Form';
    btn.disabled = true;
    if (label) label.textContent = 'Exporting…';
    try {
      const res = await fetch(
        '/api/event_absence_form_export.php?event_id=' + encodeURIComponent(eventId) + '&ajax=1',
        { credentials: 'same-origin', headers: { Accept: 'application/json' } }
      );
      const ct = String(res.headers.get('Content-Type') || '').toLowerCase();
      if (ct.includes('application/json')) {
        const data = await res.json().catch(() => ({}));
        throw new Error((data && data.error) || 'Export failed');
      }
      if (!res.ok) {
        const text = await res.text();
        throw new Error(text || 'Export failed');
      }
      const blob = await res.blob();
      let filename = 'Approved_Absence_Form.docx';
      const cd = String(res.headers.get('Content-Disposition') || '');
      const m = /filename="([^"]+)"/i.exec(cd);
      if (m && m[1]) filename = m[1];
      const url = URL.createObjectURL(blob);
      const a = document.createElement('a');
      a.href = url;
      a.download = filename;
      document.body.appendChild(a);
      a.click();
      a.remove();
      URL.revokeObjectURL(url);
    } catch (e) {
      alert(e.message || 'Export failed');
    } finally {
      btn.disabled = false;
      if (label) label.textContent = prev;
    }
  });
})();
</script>

<?php render_footer(); ?>
