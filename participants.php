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
$isPaidEvent = !event_is_free_registration_event($event);
$returnTo = $backHref;
$returnToQuery = '&return_to=' . rawurlencode($returnTo);
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
        . '?select=id,registered_at,student_id,users(first_name,middle_name,last_name,suffix,email,student_id,sections(name)),tickets(id,token)'
        . '&event_id=eq.' . rawurlencode($eventId)
        . '&order=registered_at.desc';
    $pRes = supabase_request('GET', $pUrl, $headers);
    $participants = $pRes['ok'] ? json_decode((string) $pRes['body'], true) : [];
    $participants = is_array($participants) ? $participants : [];

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
                . '?select=id,session_id,registration_id,ticket_id,status,check_in_at,last_scanned_at'
                . '&session_id=in.(' . $sessionFilter . ')';
            $attendanceRes = supabase_request('GET', $attendanceUrl, $headers);
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
                    . '?select=id,session_id,ticket_id,status,check_in_at,last_scanned_at'
                    . '&session_id=in.(' . $sessionFilter . ')';
                $legacyAttendanceRes = supabase_request('GET', $legacyAttendanceUrl, $headers);
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
        $sessionWindowMeta[$sessionId] = [
            'start_at' => $startAt,
            'closes_at' => $closesAt,
            'window_minutes' => $windowMinutes,
            'closed' => $nowUtc > $closesAt->setTimezone(new DateTimeZone('UTC')),
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
    foreach ($sessions as $session) {
        $sessionCounts[(string) ($session['id'] ?? '')] = 0;
    }
    foreach ($attendanceMap as $rows) {
        foreach ($rows as $sessionId => $row) {
            if ($attendanceCountsAsPresent(is_array($row) ? $row : null) && isset($sessionCounts[$sessionId])) {
                $sessionCounts[$sessionId]++;
            }
        }
    }

    $absentRows = [];
    foreach ($participants as $participant) {
        if (!is_array($participant)) {
            continue;
        }
        $registrationId = (string) ($participant['id'] ?? '');
        $studentId = (string) ($participant['student_id'] ?? '');
        if ($registrationId === '' || $studentId === '') {
            continue;
        }
        $profile = isset($participant['users']) && is_array($participant['users']) ? $participant['users'] : [];
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
            if (empty($meta['closed'])) {
                continue;
            }
            $attendance = $attendanceMap[$registrationId][$sessionId] ?? null;
            if ($attendanceCountsAsPresent(is_array($attendance) ? $attendance : null)) {
                $hasAnyPresent = true;
                continue;
            }
            $missedClosedSessions[] = [
                'session' => $session,
                'meta' => $meta,
            ];
        }

        if (count($missedClosedSessions) === 0) {
            continue;
        }

        // If participant has no present attendance across seminars, treat as one
        // whole-event absence row (non-redundant).
        if (!$hasAnyPresent) {
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
            if (!$firstStart || !$lastClose) {
                continue;
            }

            $reason = $reasonByStudentEvent[$studentId] ?? null;
            $absentRows[] = [
                'student_id' => $studentId,
                'registration_id' => $registrationId,
                'participant_name' => $name !== '' ? $name : 'Unnamed Participant',
                'student_number' => (string) ($profile['student_id'] ?? 'N/A'),
                'section' => $section !== '' ? $section : 'N/A',
                'session_name' => 'Whole event',
                'session_start_at' => $firstStart,
                'session_closes_at' => $lastClose,
                'session_window_minutes' => 30,
                'reason' => is_array($reason) ? $reason : null,
            ];
            continue;
        }

        // If participant attended at least one seminar, keep session-specific rows
        // only for the seminars they missed.
        foreach ($missedClosedSessions as $entry) {
            $session = $entry['session'];
            $meta = $entry['meta'];
            $sessionId = (string) ($session['id'] ?? '');
            if ($sessionId === '') {
                continue;
            }
            $reason = $reasonByStudentSession[$studentId][$sessionId] ?? null;
            $absentRows[] = [
                'student_id' => $studentId,
                'registration_id' => $registrationId,
                'participant_name' => $name !== '' ? $name : 'Unnamed Participant',
                'student_number' => (string) ($profile['student_id'] ?? 'N/A'),
                'section' => $section !== '' ? $section : 'N/A',
                'session_name' => build_session_display_name($session),
                'session_start_at' => $meta['start_at'],
                'session_closes_at' => $meta['closes_at'],
                'session_window_minutes' => (int) ($meta['window_minutes'] ?? 30),
                'reason' => is_array($reason) ? $reason : null,
            ];
        }
    }

    render_header('Participants', $user);
    ?>
    <?php
    render_event_page_header([
        'back_href' => $backHref,
        'title' => (string) ($event['title'] ?? ''),
        'subtitle' => 'Seminar attendance is tracked per session.',
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
          <?php foreach ($sessions as $session): ?>
            <?php $sessionId = (string) ($session['id'] ?? ''); ?>
            <div class="rounded-2xl border border-zinc-200 bg-white p-4 shadow-sm">
              <div class="text-xs font-bold uppercase tracking-wider text-zinc-500">Seminar</div>
              <div class="text-sm font-bold text-zinc-900 mt-1"><?= htmlspecialchars(build_session_display_name($session)) ?></div>
              <div class="text-xs text-zinc-500 mt-2"><?= htmlspecialchars(format_date_local((string) ($session['start_at'] ?? ''), 'M j, Y g:i A')) ?></div>
              <div class="text-xl font-black text-emerald-700 mt-3"><?= htmlspecialchars((string) ($sessionCounts[$sessionId] ?? 0)) ?></div>
              <div class="text-xs text-zinc-500">checked in</div>
            </div>
          <?php endforeach; ?>
        </div>

        <div class="overflow-x-auto rounded-2xl border border-zinc-200 bg-white shadow-sm">
          <table class="min-w-full divide-y divide-zinc-200">
            <thead class="bg-zinc-50">
              <tr>
                <th class="px-4 py-3 text-left text-xs font-bold uppercase tracking-wider text-zinc-500">Participant</th>
                <th class="px-4 py-3 text-left text-xs font-bold uppercase tracking-wider text-zinc-500">Student No.</th>
                <th class="px-4 py-3 text-left text-xs font-bold uppercase tracking-wider text-zinc-500">Section</th>
                <?php foreach ($sessions as $session): ?>
                  <th class="px-4 py-3 text-left text-xs font-bold uppercase tracking-wider text-zinc-500"><?= htmlspecialchars(build_session_display_name($session)) ?></th>
                <?php endforeach; ?>
                <th class="px-4 py-3 text-left text-xs font-bold uppercase tracking-wider text-zinc-500">Action</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-zinc-100">
              <?php foreach ($participants as $participant): ?>
                <?php
                  $registrationId = (string) ($participant['id'] ?? '');
                  $studentId = (string) ($participant['student_id'] ?? '');
                  $profile = isset($participant['users']) && is_array($participant['users']) ? $participant['users'] : [];
                  $section = isset($profile['sections']) && is_array($profile['sections']) ? (string) ($profile['sections']['name'] ?? '') : '';
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
                ?>
                <tr>
                  <td class="px-4 py-4 text-sm font-semibold text-zinc-900"><?= htmlspecialchars($name !== '' ? $name : 'Unnamed Participant') ?></td>
                  <td class="px-4 py-4 text-sm text-zinc-600"><?= htmlspecialchars((string) ($profile['student_id'] ?? 'N/A')) ?></td>
                  <td class="px-4 py-4 text-sm text-zinc-600"><?= htmlspecialchars($section !== '' ? $section : 'N/A') ?></td>
                  <?php foreach ($sessions as $session): ?>
                    <?php
                      $sessionId = (string) ($session['id'] ?? '');
                      $attendance = $attendanceMap[$registrationId][$sessionId] ?? null;
                      $checkInAt = is_array($attendance) ? (string) ($attendance['check_in_at'] ?? '') : '';
                      $sessionMeta = $sessionWindowMeta[$sessionId] ?? null;
                      $sessionClosed = is_array($sessionMeta) && !empty($sessionMeta['closed']);
                    ?>
                    <td class="px-4 py-4 text-sm text-zinc-600">
                      <?php if (is_array($attendance) && $attendanceCountsAsPresent($attendance)): ?>
                        <span class="inline-flex items-center rounded-full border border-emerald-200 bg-emerald-50 px-2.5 py-1 text-xs font-bold text-emerald-700">Present</span>
                        <?php if ($checkInAt !== ''): ?>
                          <div class="mt-2 text-xs text-zinc-500"><?= htmlspecialchars(format_date_local($checkInAt, 'M j, g:i A')) ?></div>
                        <?php endif; ?>
                      <?php elseif ($sessionClosed): ?>
                        <span class="inline-flex items-center rounded-full border border-amber-200 bg-amber-50 px-2.5 py-1 text-xs font-bold text-amber-700">Absent</span>
                      <?php else: ?>
                        <span class="inline-flex items-center rounded-full border border-zinc-200 bg-zinc-50 px-2.5 py-1 text-xs font-bold text-zinc-500">No record</span>
                      <?php endif; ?>
                    </td>
                  <?php endforeach; ?>
                  <td class="px-4 py-4">
                    <button class="btnResetAttendance rounded-xl border border-amber-200 bg-amber-50 px-3 py-2 text-xs font-semibold text-amber-800 hover:bg-amber-100 transition" data-id="<?= htmlspecialchars($registrationId) ?>">
                      <?= htmlspecialchars($resetButtonLabel) ?>
                    </button>
                  </td>
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
                <th class="px-4 py-3 text-left text-xs font-bold uppercase tracking-wider text-zinc-500">Missed Seminar</th>
                <th class="px-4 py-3 text-left text-xs font-bold uppercase tracking-wider text-zinc-500">Scan Window</th>
                <th class="px-4 py-3 text-left text-xs font-bold uppercase tracking-wider text-zinc-500">Reason</th>
                <th class="px-4 py-3 text-left text-xs font-bold uppercase tracking-wider text-zinc-500">Submitted</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-zinc-100">
              <?php if (count($absentRows) === 0): ?>
                <tr>
                  <td colspan="7" class="px-4 py-12 text-center text-sm text-zinc-500 font-semibold">
                    No unresolved absences for closed seminar scan windows.
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
          const ok = confirm(<?= json_encode($resetConfirmMessage) ?>);
          if (!ok) return;

          btn.disabled = true;
          try {
            const res = await fetch('/api/participant_attendance_reset.php', {
              method: 'POST',
              headers: { 'Content-Type': 'application/json' },
              body: JSON.stringify({
                registration_id: btn.dataset.id,
                csrf_token: window.CSRF_TOKEN
              })
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
    
    // Group participants by Section
    $sectionsMap = [];
    foreach ($participants as $r) {
        $u = isset($r['users']) && is_array($r['users']) ? $r['users'] : [];
        $sec = isset($u['sections']) && is_array($u['sections']) ? $u['sections'] : null;
        $secName = is_array($sec) && isset($sec['name']) ? $sec['name'] : 'Unknown Section';
        
        $yearLvl = 'N/A';
        if (preg_match('/-([1-4])[A-Z]$/i', trim($secName), $m)) {
            $yearLvl = $m[1];
        } else if (preg_match('/([1-4])/', trim($secName), $m)) {
            $yearLvl = $m[1];
        }

        if(!isset($sectionsMap[$secName])) {
            $sectionsMap[$secName] = ['year' => $yearLvl, 'participants' => []];
        }
        $sectionsMap[$secName]['participants'][] = $r;
    }
    ksort($sectionsMap);

    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    header('Content-Disposition: attachment; filename="Event_Participants_' . preg_replace('/[^A-Za-z0-9_\-]/', '_', (string)($event['title'] ?? '')) . '.xls"');
    
    // Group participants by Section
    $sectionsMap = [];
    foreach ($participants as $r) {
        $u = isset($r['users']) && is_array($r['users']) ? $r['users'] : [];
        $sec = isset($u['sections']) && is_array($u['sections']) ? $u['sections'] : null;
        $secName = is_array($sec) && isset($sec['name']) ? $sec['name'] : 'Unknown Section';
        
        $yearLvl = 'N/A';
        if (preg_match('/-([1-4])[A-Z]$/i', trim($secName), $m)) {
            $yearLvl = $m[1];
        } else if (preg_match('/([1-4])/', trim($secName), $m)) {
            $yearLvl = $m[1];
        }

        if(!isset($sectionsMap[$secName])) {
            $sectionsMap[$secName] = ['year' => $yearLvl, 'participants' => []];
        }
        $sectionsMap[$secName]['participants'][] = $r;
    }
    ksort($sectionsMap);

    $eventTitle = strtoupper(htmlspecialchars((string)($event['title'] ?? 'UNKNOWN EVENT')));
    $eventDate = ($start ? $start->format('M d, Y') : '') . ($multiDay && $end ? ' - ' . $end->format('M d, Y') : '');

    echo '<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns="http://www.w3.org/TR/REC-html40">';
    echo '<head><meta charset="utf-8"> <style>';
    echo '  .hdr-main { font-family: "Segoe UI", Arial, sans-serif; font-size: 18pt; font-weight: bold; color: #1e293b; text-align: center; }';
    echo '  .hdr-sub  { font-family: "Segoe UI", Arial, sans-serif; font-size: 11pt; color: #64748b; font-weight: normal; text-align: center; }';
    echo '  .gen-on   { font-family: "Segoe UI", Arial, sans-serif; font-size: 9pt; color: #94a3b8; text-align: center; }';
    echo '  .logo-badge { background-color: #ea580c; color: #ffffff; font-family: "Impact", Arial, sans-serif; font-size: 24pt; font-weight: bold; text-align: center; vertical-align: middle; border: 2pt solid #c2410c; }';
    echo '  .event-hdr { background-color: #ea580c; color: #ffffff; font-family: "Segoe UI", Arial, sans-serif; font-size: 14pt; font-weight: bold; padding: 15px; text-align: center; height: 35px; border: 1pt solid #c2410c; }';
    echo '  .event-date { background-color: #fef2f2; color: #991b1b; font-family: "Segoe UI", Arial, sans-serif; font-size: 11pt; font-weight: bold; text-align: center; height: 25px; border-bottom: 2pt solid #ea580c; }';
    echo '  .sec-hdr { background-color: #1e293b; color: #ffffff; font-family: "Segoe UI", Arial, sans-serif; font-size: 11pt; font-weight: bold; padding: 10px; height: 25px; }';
    echo '  .col-hdr { background-color: #f8fafc; border: 1pt solid #cbd5e1; font-family: "Segoe UI", Arial, sans-serif; font-size: 10pt; font-weight: bold; text-align: center; height: 30px; }';
    echo '  .data-cell { border: 0.2pt solid #e2e8f0; font-family: "Segoe UI", Arial, sans-serif; font-size: 10pt; height: 25px; vertical-align: middle; }';
    echo '  .compl { color: #059669; font-weight: bold; text-align: center; background-color: #f0fdf4; }';
    echo '  .pend  { color: #d97706; font-weight: bold; text-align: center; background-color: #fffbeb; }';
    echo ' </style></head>';
    echo '<body>';
    echo '<table border="0" style="border-collapse:collapse;">';
    
    // Top Logo & Header (Merged perfectly)
    echo '<tr><td colspan="6" style="height: 10px;"></td></tr>';
    echo '<tr>';
    echo '  <td colspan="1" rowspan="3" class="logo-badge">CCS</td>'; // Styled badge logo
    echo '  <td colspan="5" class="hdr-main">COLLEGE OF COMPUTER STUDIES</td>';
    echo '</tr>';
    echo '<tr><td colspan="5" class="hdr-sub">PulseConnect Participant Registry Report</td></tr>';
    echo '<tr><td colspan="5" class="gen-on">Generated on ' . date('F j, Y, g:i A') . '</td></tr>';
    
    echo '<tr><td colspan="6" style="height: 15px;"></td></tr>';
    
    // Event Center Banner
    echo '<tr><td colspan="6" class="event-hdr">' . htmlspecialchars($eventTitle) . '</td></tr>';
    echo '<tr><td colspan="6" class="event-date">' . htmlspecialchars($eventDate) . '</td></tr>';
    echo '<tr><td colspan="6" style="height: 10px;"></td></tr>';

    foreach($sectionsMap as $secName => $secData) {
        $secText = 'SECTION: ' . strtoupper(htmlspecialchars($secName)) . '   |   YEAR LEVEL: ' . htmlspecialchars($secData['year']);
        echo '<tr><td colspan="6" class="sec-hdr">' . $secText . '</td></tr>';
        
        echo '<tr>';
        echo ' <th class="col-hdr" style="width:300px;">STUDENT NAME</th>';
        echo ' <th class="col-hdr" style="width:130px;">STUDENT NUMBER</th>';
        echo ' <th class="col-hdr" style="width:80px;">YEAR</th>';
        echo ' <th class="col-hdr" style="width:150px;">SECTION</th>';
        echo ' <th class="col-hdr" style="width:180px;">CHECK IN</th>';
        echo ' <th class="col-hdr" style="width:120px;">STATUS</th>';
        echo '</tr>';
        
        foreach($secData['participants'] as $r) {
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
            $ticket = isset($tickets[0]) && is_array($tickets[0]) ? $tickets[0] : [];
            $attendance = null;
            if (isset($ticket['attendance'])) {
                $atts = $ticket['attendance'];
                if (is_array($atts)) {
                    $attendance = isset($atts[0]) && is_array($atts[0]) ? $atts[0] : (isset($atts) && is_array($atts) ? $atts : null);
                }
            }
            $checkIn = is_array($attendance) ? ($attendance['check_in_at'] ?? '') : '';
            $attStatus = is_array($attendance) ? ($attendance['status'] ?? '') : '';
            
            if ($checkIn) {
                $checkInLocal = $toLocalDt((string) $checkIn);
                if ($checkInLocal) $checkIn = $checkInLocal->format('m/d/Y h:i A');
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
            echo ' <td class="data-cell" style="text-align:center; font-family: monospace;">' . htmlspecialchars($studentNumber) . '</td>';
            echo ' <td class="data-cell" style="text-align:center;">' . htmlspecialchars($secData['year']) . '</td>';
            echo ' <td class="data-cell" style="text-align:center;">' . htmlspecialchars($secName) . '</td>';
            echo ' <td class="data-cell" style="text-align:center;">' . ($checkIn ? htmlspecialchars($checkIn) : '-') . '</td>';
            echo ' <td class="data-cell ' . $statusCls . '">' . $statusStr . '</td>';
            echo '</tr>';
        }
        echo '<tr><td colspan="6" style="height: 10px;"></td></tr>';
    }
    
    echo '</table></body></html>';
    exit;
}

// CSV export
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="participants.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Name', 'StudentNumber', 'Email', 'RegisteredAt', 'Token', 'CheckIn', 'AttendanceStatus']);
    foreach ($participants as $r) {
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
        $ticket = isset($tickets[0]) && is_array($tickets[0]) ? $tickets[0] : [];
        $token = (string) ($ticket['token'] ?? '');

        $attendance = null;
        if (isset($ticket['attendance'])) {
            $atts = $ticket['attendance'];
            if (is_array($atts)) {
                $attendance = isset($atts[0]) && is_array($atts[0]) ? $atts[0] : (isset($atts) && is_array($atts) ? $atts : null);
            }
        }
        $checkIn = is_array($attendance) ? ($attendance['check_in_at'] ?? '') : '';
        $attStatus = is_array($attendance) ? ($attendance['status'] ?? '') : '';
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
            is_string($checkIn) ? $checkIn : '',
            (string) $attStatus,
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

$simpleEventAbsentRows = [];
if ($eventWindowClosed) {
    foreach ($participants as $participant) {
        if (!is_array($participant)) {
            continue;
        }
        $studentId = (string) ($participant['student_id'] ?? '');
        if ($studentId === '') {
            continue;
        }
        $profile = isset($participant['users']) && is_array($participant['users']) ? $participant['users'] : [];
        $tickets = isset($participant['tickets']) && is_array($participant['tickets']) ? $participant['tickets'] : [];
        $ticket = isset($tickets[0]) && is_array($tickets[0]) ? $tickets[0] : [];
        $attendance = null;
        if (isset($ticket['attendance']) && is_array($ticket['attendance'])) {
            $atts = $ticket['attendance'];
            $attendance = isset($atts[0]) && is_array($atts[0]) ? $atts[0] : null;
        }
        if ($attendanceCountsAsPresent($attendance)) {
            continue;
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
        $section = isset($profile['sections']) && is_array($profile['sections'])
            ? (string) ($profile['sections']['name'] ?? '')
            : '';
        $simpleEventAbsentRows[] = [
            'student_id' => $studentId,
            'participant_name' => $name !== '' ? $name : 'Unnamed Participant',
            'student_number' => (string) ($profile['student_id'] ?? 'N/A'),
            'section' => $section !== '' ? $section : 'N/A',
            'reason' => isset($reasonByStudentEvent[$studentId]) && is_array($reasonByStudentEvent[$studentId])
                ? $reasonByStudentEvent[$studentId]
                : null,
        ];
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
    'actions_html' => $exportExcelHtml,
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
.pc-card, .pc-card * {
  box-sizing: border-box;
}

.pc-card {
  width: 100%;
  aspect-ratio: 1 / 1;
  max-width: 280px;
  background: white;
  border-radius: 28px;
  padding: 3px;
  position: relative;
  box-shadow: #60747430 0px 50px 24px -36px;
  transition: all 0.5s ease-in-out;
  overflow: hidden;
  cursor: default;
}

.pc-card .profile-pic {
  position: absolute;
  width: calc(100% - 6px);
  height: calc(100% - 6px);
  top: 3px;
  left: 3px;
  border-radius: 25px;
  z-index: 1;
  border: 0px solid #f97316;
  overflow: hidden;
  transition: all 0.5s ease-in-out 0.2s, z-index 0.5s ease-in-out 0.2s;
  background: #fff7ed;
}

.pc-card .profile-pic img {
  object-fit: cover;
  width: 100%;
  height: 100%;
  object-position: center top;
  transition: all 0.5s ease-in-out 0s;
}

.pc-card .profile-initials {
  width: 100%;
  height: 100%;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 3rem;
  font-weight: 900;
  color: #ea580c;
  background: linear-gradient(135deg, #fff7ed 0%, #ffedd5 100%);
  transition: all 0.5s ease-in-out 0s;
  user-select: none;
}

.pc-card .bottom {
  position: absolute;
  bottom: 3px;
  left: 3px;
  right: 3px;
  background: linear-gradient(135deg, #c2410c 0%, #ea580c 60%, #f97316 100%);
  top: 61%;
  border-radius: 22px;
  z-index: 2;
  box-shadow: rgba(234, 88, 12, 0.22) 0px 5px 5px 0px inset;
  overflow: hidden;
  transition: all 0.5s cubic-bezier(0.645, 0.045, 0.355, 1) 0s;
  padding: 10px 12px 12px 12px;
  display: flex;
  flex-direction: column;
  justify-content: space-between;
  box-sizing: border-box;
}

.pc-card .bottom .bottom-header {
  display: flex;
  flex-direction: column;
  gap: 2px;
  min-width: 0;
  width: 100%;
  transition: padding-left 0.5s ease-in-out;
}

.pc-card:hover .bottom .bottom-header {
  padding-left: 88px;
}

.pc-card .bottom .pname {
  display: block;
  font-size: 0.95rem;
  color: white;
  font-weight: 800;
  line-height: 1.25;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  text-shadow: 0 1px 3px rgba(0,0,0,0.18);
}

.pc-card .bottom .psubtitle {
  display: block;
  font-size: 0.72rem;
  color: rgba(255, 255, 255, 0.85);
  font-weight: 600;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  text-shadow: 0 1px 2px rgba(0,0,0,0.1);
}

.pc-card .bottom .pdetails-hover {
  opacity: 0;
  transform: translateY(12px);
  transition: all 0.4s ease-in-out;
  margin-top: 8px;
  visibility: hidden;
  max-height: 0;
  overflow: hidden;
}

.pc-card:hover .bottom .pdetails-hover {
  visibility: visible;
  max-height: 200px;
  opacity: 1;
  transform: translateY(0);
  transition-delay: 0.15s;
  /* Add top margin to clear the 90px circular avatar fully */
  margin-top: 14px;
}

.pc-card .bottom .pinfo {
  display: flex;
  flex-wrap: wrap;
  gap: 4px;
}

.pc-card .bottom .pinfo-badge {
  display: inline-flex;
  align-items: center;
  gap: 3px;
  font-size: 0.65rem;
  font-weight: 700;
  color: rgba(255,255,255,0.92);
  background: rgba(255,255,255,0.18);
  border: 1px solid rgba(255,255,255,0.22);
  border-radius: 6px;
  padding: 2px 6px;
  white-space: nowrap;
  max-width: 100%;
  overflow: hidden;
  text-overflow: ellipsis;
}

.pc-card .bottom .bottom-bottom {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.4rem;
  margin-top: auto;
  padding-top: 8px;
  min-width: 0;
  flex-shrink: 0;
}

.pc-card .bottom .att-badge {
  font-size: 0.58rem;
  font-weight: 800;
  padding: 0.28rem 0.55rem;
  border-radius: 999px;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  white-space: nowrap;
  flex: 0 1 auto;
  max-width: 42%;
  overflow: hidden;
  text-overflow: ellipsis;
}

.pc-card .bottom .admin-btns {
  display: flex;
  align-items: center;
  gap: 0.3rem;
  flex: 0 0 auto;
  flex-wrap: nowrap;
  margin-left: auto;
}

.pc-card .bottom .admin-btns button {
  background: rgba(255,255,255,0.18);
  color: white;
  border: 1px solid rgba(255,255,255,0.3);
  border-radius: 999px;
  font-size: 0.58rem;
  font-weight: 700;
  line-height: 1;
  padding: 0.38rem 0.55rem;
  cursor: pointer;
  transition: all 0.2s;
  white-space: nowrap;
  flex-shrink: 0;
}

.pc-card .bottom .admin-btns button.btnRemove {
  padding: 0.38rem 0.5rem;
}

.pc-card .bottom .admin-btns button:hover {
  background: rgba(255,255,255,0.32);
}

.pc-card:hover {
  border-top-left-radius: 50px;
}

.pc-card:hover .bottom {
  top: 22%;
  border-radius: 40px 22px 22px 22px;
  transition: all 0.5s cubic-bezier(0.645, 0.045, 0.355, 1) 0.2s;
}

.pc-card:hover .profile-pic {
  width: 90px;
  height: 90px;
  top: 10px;
  left: 10px;
  border-radius: 50%;
  z-index: 3;
  border: 6px solid #f97316;
  box-shadow: rgba(234, 88, 12, 0.25) 0px 5px 12px 0px;
  transition: all 0.5s ease-in-out, z-index 0.5s ease-in-out 0.1s;
}

.pc-card:hover .profile-pic img,
.pc-card:hover .profile-initials {
  transition: all 0.5s ease-in-out 0.4s;
}

.pc-card:hover .profile-initials {
  font-size: 2rem;
}

/* ── Attendance status colours in the bottom panel ───────────────────── */
.att-present  { background: #d1fae5; color: #065f46; }
.att-absent   { background: #fee2e2; color: #991b1b; }
.att-late     { background: #fef3c7; color: #92400e; }
.att-early    { background: #e0f2fe; color: #075985; }
.att-timed-out{ background: #dbeafe; color: #1e3a8a; }
.att-unscanned{ background: rgba(255,255,255,0.2); color: white; border: 1px solid rgba(255,255,255,0.3); }
</style>

<div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 xl:grid-cols-4 gap-6 pb-10 justify-items-center">
  <?php if (count($rows) === 0): ?>
    <div class="col-span-full rounded-3xl border border-dashed border-zinc-300 bg-zinc-50 py-16 flex flex-col items-center justify-center pointer-events-none">
      <svg class="w-10 h-10 text-zinc-400 mb-3" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 002.625.372 9.337 9.337 0 004.121-.952 4.125 4.125 0 00-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 018.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0111.964-3.07M12 6.375a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zm8.25 2.25a2.625 2.625 0 11-5.25 0 2.625 2.625 0 015.25 0z"/></svg>
      <p class="text-zinc-700 font-semibold text-sm">No participants found</p>
    </div>
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
      $token   = (string) ($ticket['token'] ?? '');

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

      // Initials fallback
      $initials = '';
      foreach ($nameParts as $p) { $initials .= mb_strtoupper(mb_substr($p, 0, 1)); if (mb_strlen($initials) >= 2) break; }
      if (mb_strlen($initials) === 0) $initials = '?';

      // Check-in / check-out times (compact one-line for hover)
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
      $secName = is_array($sec) && isset($sec['name']) ? $sec['name'] : 'N/A';
      $yearLvl = 'N/A';
      if (preg_match('/-([1-4])[A-Z]$/i', trim($secName), $m)) {
          $yearLvl = $m[1] . (match($m[1]){'1'=>'st','2'=>'nd','3'=>'rd','4'=>'th',default=>''}) . ' Year';
      } else if (preg_match('/([1-4])/', trim($secName), $m)) {
          $yearLvl = $m[1] . (match($m[1]){'1'=>'st','2'=>'nd','3'=>'rd','4'=>'th',default=>''}) . ' Year';
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

      $avatarUrl = storage_resolve_avatar_url((string) ($u['photo_url'] ?? ''));
      $studentId = (string) ($u['student_id'] ?? 'N/A');
      $email     = (string) ($u['email'] ?? '');
      $searchStr = strtolower($name . ' ' . $email . ' ' . $studentId . ' ' . $secName);
    ?>
    <div class="pc-card participant-card"
         data-search="<?= htmlspecialchars($searchStr) ?>">

      <!-- Profile picture / initials -->
      <div class="profile-pic">
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

      <!-- Slide-up info panel -->
      <div class="bottom">
        <!-- Always visible name, student number, and section/course -->
        <div class="bottom-header">
          <span class="pname" title="<?= htmlspecialchars($name) ?>"><?= htmlspecialchars($name ?: 'Unnamed') ?></span>
          <span class="psubtitle">#<?= htmlspecialchars($studentId) ?> • <?= htmlspecialchars($secName) ?></span>
        </div>

        <!-- Extra details revealed on hover -->
        <div class="pdetails-hover">
          <div class="pinfo">
            <span class="pinfo-badge" title="<?= htmlspecialchars($email) ?>">
              ✉ <?= htmlspecialchars($email ?: '—') ?>
            </span>
            <span class="pinfo-badge">🏛 <?= htmlspecialchars($secName) ?></span>
            <span class="pinfo-badge">📅 <?= htmlspecialchars($yearLvl) ?></span>
            <span class="pinfo-badge" title="Time In / Time Out">⏰ <?= htmlspecialchars($attendanceTimeLine) ?></span>
          </div>
        </div>

        <div class="bottom-bottom">
          <span class="att-badge <?= $attBadgeClass ?>"><?= htmlspecialchars($attLabel) ?></span>
          <?php if ($role === 'admin'): ?>
            <div class="admin-btns">
              <button
                type="button"
                class="btnResetAttendance"
                data-id="<?= htmlspecialchars($registrationId) ?>"
                title="<?= htmlspecialchars($resetConfirmMessage) ?>"
                aria-label="<?= htmlspecialchars($resetButtonLabel) ?>"
              ><?= htmlspecialchars($resetButtonShort) ?></button>
              <button
                type="button"
                class="btnRemove"
                data-id="<?= htmlspecialchars($registrationId) ?>"
                title="Remove participant"
                aria-label="Remove participant"
              >✕</button>
            </div>
          <?php endif; ?>
        </div>
      </div>
    </div>
  <?php endforeach; ?>
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
    <div class="mt-2 text-xs text-zinc-500">Absent is counted only after the scan window closes.</div>
  </div>
  <div class="rounded-2xl border border-zinc-200 bg-white p-4">
    <div class="text-xs font-bold uppercase tracking-wider text-zinc-500">Absent Participants</div>
    <div class="mt-2 text-2xl font-black text-amber-700"><?= count($simpleEventAbsentRows) ?></div>
  </div>
  <div class="rounded-2xl border border-zinc-200 bg-white p-4">
    <div class="text-xs font-bold uppercase tracking-wider text-zinc-500">Reasons Submitted</div>
    <div class="mt-2 text-2xl font-black text-emerald-700"><?= count(array_filter($simpleEventAbsentRows, static fn(array $row): bool => is_array($row['reason'] ?? null))) ?></div>
  </div>
</div>

<div class="rounded-2xl border border-zinc-200 bg-white shadow-sm overflow-x-auto">
  <table class="min-w-full divide-y divide-zinc-200">
    <thead class="bg-zinc-50">
      <tr>
        <th class="px-4 py-3 text-left text-xs font-bold uppercase tracking-wider text-zinc-500">Participant</th>
        <th class="px-4 py-3 text-left text-xs font-bold uppercase tracking-wider text-zinc-500">Student No.</th>
        <th class="px-4 py-3 text-left text-xs font-bold uppercase tracking-wider text-zinc-500">Section</th>
        <th class="px-4 py-3 text-left text-xs font-bold uppercase tracking-wider text-zinc-500">Reason</th>
        <th class="px-4 py-3 text-left text-xs font-bold uppercase tracking-wider text-zinc-500">Submitted</th>
      </tr>
    </thead>
    <tbody class="divide-y divide-zinc-100">
      <?php if (!$eventWindowClosed): ?>
        <tr>
          <td colspan="5" class="px-4 py-12 text-center text-sm text-zinc-500 font-semibold">
            Event scan window is still open or not started yet. Absences will appear after it closes.
          </td>
        </tr>
      <?php elseif (count($simpleEventAbsentRows) === 0): ?>
        <tr>
          <td colspan="5" class="px-4 py-12 text-center text-sm text-zinc-500 font-semibold">
            No unresolved absences found.
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

<?php if ($role === 'admin'): ?>
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
      const ok = confirm(<?= json_encode($resetConfirmMessage) ?>);
      if (!ok) return;
      btn.disabled = true;
      try {
        const registration_id = btn.dataset.id;
        const res = await fetch('/api/participant_attendance_reset.php', {
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

  // Client-side live search
  const searchInput = document.getElementById('participantSearch');
  const totalCountEl = document.getElementById('totalCount');
  const cards = document.querySelectorAll('.participant-card');
  const emptyState = document.querySelector('.pointer-events-none');

  if (searchInput) {
    searchInput.addEventListener('input', (e) => {
      const term = e.target.value.toLowerCase().trim();
      let visibleCount = 0;

      cards.forEach(card => {
        const searchable = card.dataset.search;
        if (searchable.includes(term)) {
          card.classList.remove('hidden');
          visibleCount++;
        } else {
          card.classList.add('hidden');
        }
      });

      if (totalCountEl) totalCountEl.textContent = visibleCount;
      
      if (emptyState) {
        if (visibleCount === 0 && cards.length > 0) {
          emptyState.classList.remove('hidden');
          emptyState.querySelector('p').textContent = 'No results match your search';
        } else if (cards.length === 0) {
          emptyState.classList.remove('hidden');
          emptyState.querySelector('p').textContent = 'No participants found';
        } else {
          emptyState.classList.add('hidden');
        }
      }
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
</script>

<?php render_footer(); ?>
