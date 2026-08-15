<?php
declare(strict_types=1);

/**
 * Time-in / time-out window helpers for Event QR attendance.
 * Time-in: start_at + grace (or scan_window_minutes).
 * Time-out: early_out_enabled_at + 1 hour when Early Out was used;
 * otherwise end_at + 1 hour.
 */

const ATTENDANCE_CHECK_OUT_WINDOW_HOURS = 1;

function attendance_manila_tz(): DateTimeZone
{
    return new DateTimeZone('Asia/Manila');
}

function attendance_format_manila_time(?DateTimeImmutable $dt): string
{
    if (!$dt instanceof DateTimeImmutable) {
        return '';
    }
    return $dt->setTimezone(attendance_manila_tz())->format('g:i A');
}

function attendance_format_manila_datetime(?DateTimeImmutable $dt): string
{
    if (!$dt instanceof DateTimeImmutable) {
        return '';
    }
    return $dt->setTimezone(attendance_manila_tz())->format('M j, Y · g:i A');
}

function attendance_same_manila_day(DateTimeImmutable $a, DateTimeImmutable $b): bool
{
    $tz = attendance_manila_tz();
    return $a->setTimezone($tz)->format('Y-m-d') === $b->setTimezone($tz)->format('Y-m-d');
}

/**
 * Shared ISO parser for attendance windows.
 * Defined here so this file can load without scan_context.php.
 */
if (!function_exists('parse_iso_datetime')) {
    function parse_iso_datetime(?string $raw): ?DateTimeImmutable
    {
        $raw = trim((string) $raw);
        if ($raw === '') {
            return null;
        }
        try {
            return new DateTimeImmutable($raw);
        } catch (Throwable $e) {
            return null;
        }
    }
}

/**
 * Valid time-in is required before any time-out / check-out.
 * Marked absent or never timed in must fail closed (anti-cheat).
 */
if (!function_exists('attendance_has_valid_time_in')) {
    function attendance_has_valid_time_in(?array $row): bool
    {
        if (!is_array($row)) {
            return false;
        }
        $status = strtolower(trim((string) ($row['status'] ?? '')));
        if ($status === 'absent') {
            return false;
        }
        if (trim((string) ($row['check_in_at'] ?? '')) !== '') {
            return true;
        }
        return in_array($status, ['present', 'checked_in', 'in', 'scanned', 'late', 'early'], true);
    }
}

function attendance_early_out_expires_at(?DateTimeImmutable $enabledAt): ?DateTimeImmutable
{
    if (!$enabledAt instanceof DateTimeImmutable) {
        return null;
    }
    return $enabledAt->modify('+' . ATTENDANCE_CHECK_OUT_WINDOW_HOURS . ' hours');
}

function attendance_early_out_is_active(?string $enabledAtRaw, DateTimeImmutable $nowUtc): bool
{
    $enabledAt = parse_iso_datetime(trim((string) $enabledAtRaw));
    if (!$enabledAt instanceof DateTimeImmutable) {
        return false;
    }
    $expires = attendance_early_out_expires_at($enabledAt);
    return $expires instanceof DateTimeImmutable && $nowUtc <= $expires;
}

/**
 * Previously nulled expired early_out_enabled_at. We keep the timestamp so
 * checkout stays on the Early Out path (enabled→+1h) and does not fall back
 * to the normal end_at window after Early Out expires.
 * Explicit Disable still clears the column.
 */
function attendance_lazy_clear_early_out(string $table, string $id, ?string $enabledAtRaw, DateTimeImmutable $nowUtc, array $headers): void
{
    // Intentionally no-op — see note above.
}

/**
 * @return array{open:bool,opens_at:?string,closes_at:?string,window_minutes:?int,status:string,message:string}
 */
function attendance_check_in_window_for_start(?DateTimeImmutable $startAt, int $graceMinutes, DateTimeImmutable $nowUtc): array
{
    $graceMinutes = max(1, $graceMinutes);
    if (!$startAt instanceof DateTimeImmutable) {
        return [
            'open' => false,
            'opens_at' => null,
            'closes_at' => null,
            'window_minutes' => $graceMinutes,
            'status' => 'missing_schedule',
            'message' => 'Start time is missing.',
        ];
    }
    $closes = $startAt->modify('+' . $graceMinutes . ' minutes');
    if ($nowUtc < $startAt) {
        return [
            'open' => false,
            'opens_at' => $startAt->format('c'),
            'closes_at' => $closes->format('c'),
            'window_minutes' => $graceMinutes,
            'status' => 'waiting',
            'message' => attendance_same_manila_day($nowUtc, $startAt)
                ? ('Too early to time in. Wait for the scheduled start ('
                    . attendance_format_manila_time($startAt)
                    . ').')
                : 'Too early to time in. Wait for the scheduled start.',
        ];
    }
    if ($nowUtc <= $closes) {
        return [
            'open' => true,
            'opens_at' => $startAt->format('c'),
            'closes_at' => $closes->format('c'),
            'window_minutes' => $graceMinutes,
            'status' => 'open',
            'message' => 'Time-in is open until ' . attendance_format_manila_time($closes) . '.',
        ];
    }
    return [
        'open' => false,
        'opens_at' => $startAt->format('c'),
        'closes_at' => $closes->format('c'),
        'window_minutes' => $graceMinutes,
        'status' => 'closed',
        'message' => 'Time-in grace ended at ' . attendance_format_manila_time($closes)
            . '. You can no longer time in for this schedule.',
    ];
}

/**
 * Out window:
 * - If Early Out was triggered: ONLY enabled_at → enabled_at+1h
 *   (never fall back to event end_at, even when that window overlaps).
 * - Otherwise: normal end_at → end_at+1h.
 *
 * @return array{open:bool,opens_at:?string,closes_at:?string,status:string,message:string,mode:string}
 */
function attendance_check_out_window(
    ?DateTimeImmutable $endAt,
    ?string $earlyOutEnabledAtRaw,
    DateTimeImmutable $nowUtc
): array {
    $earlyOutRaw = trim((string) $earlyOutEnabledAtRaw);
    if ($earlyOutRaw !== '') {
        $enabledAt = parse_iso_datetime($earlyOutRaw);
        $closes = attendance_early_out_expires_at($enabledAt);
        if ($enabledAt instanceof DateTimeImmutable
            && $closes instanceof DateTimeImmutable
            && $nowUtc >= $enabledAt
            && $nowUtc <= $closes) {
            return [
                'open' => true,
                'opens_at' => $enabledAt->format('c'),
                'closes_at' => $closes->format('c'),
                'status' => 'open',
                'message' => 'Early time-out is open until ' . attendance_format_manila_time($closes) . '.',
                'mode' => 'early_out',
            ];
        }

        // Early Out was used — do not also open the normal end_at window.
        $closedLabel = $closes instanceof DateTimeImmutable
            ? attendance_format_manila_time($closes)
            : '';
        return [
            'open' => false,
            'opens_at' => $enabledAt?->format('c'),
            'closes_at' => $closes?->format('c'),
            'status' => 'closed',
            'message' => $closedLabel !== ''
                ? ('Early time-out window ended at ' . $closedLabel . '.')
                : 'Early time-out window has closed.',
            'mode' => 'early_out',
        ];
    }

    if (!$endAt instanceof DateTimeImmutable) {
        return [
            'open' => false,
            'opens_at' => null,
            'closes_at' => null,
            'status' => 'missing_schedule',
            'message' => 'End time is missing for time-out.',
            'mode' => 'normal',
        ];
    }

    $closes = $endAt->modify('+' . ATTENDANCE_CHECK_OUT_WINDOW_HOURS . ' hours');
    if ($nowUtc < $endAt) {
        return [
            'open' => false,
            'opens_at' => $endAt->format('c'),
            'closes_at' => $closes->format('c'),
            'status' => 'too_early_checkout',
            'message' => 'Too early to time out. Early Out is not enabled — time-out opens at the scheduled end ('
                . attendance_format_manila_time($endAt)
                . ').',
            'mode' => 'normal',
        ];
    }
    if ($nowUtc <= $closes) {
        return [
            'open' => true,
            'opens_at' => $endAt->format('c'),
            'closes_at' => $closes->format('c'),
            'status' => 'open',
            'message' => 'Time-out is open until ' . attendance_format_manila_time($closes) . '.',
            'mode' => 'normal',
        ];
    }

    return [
        'open' => false,
        'opens_at' => $endAt->format('c'),
        'closes_at' => $closes->format('c'),
        'status' => 'closed',
        'message' => 'Time-out window ended at ' . attendance_format_manila_time($closes) . '.',
        'mode' => 'normal',
    ];
}

/**
 * Resolve which session is open for check-in (exactly one).
 *
 * @return array{status:string,session:?array,window:?array,message:string}
 */
function attendance_resolve_session_check_in(array $sessions, DateTimeImmutable $nowUtc): array
{
    $open = [];
    $upcoming = [];
    $closed = [];

    foreach ($sessions as $session) {
        if (!is_array($session)) {
            continue;
        }
        $startAt = parse_iso_datetime((string) ($session['start_at'] ?? ''));
        $grace = max(1, (int) ($session['scan_window_minutes'] ?? 30));
        $window = attendance_check_in_window_for_start($startAt, $grace, $nowUtc);
        $meta = ['session' => $session, 'window' => $window];
        if (($window['status'] ?? '') === 'open') {
            $open[] = $meta;
        } elseif (($window['status'] ?? '') === 'waiting') {
            $upcoming[] = $meta;
        } else {
            $closed[] = $meta;
        }
    }

    if (count($open) > 1) {
        return [
            'status' => 'conflict',
            'session' => null,
            'window' => null,
            'message' => 'Multiple seminars are open for time-in. Fix overlapping schedule.',
        ];
    }
    if (count($open) === 1) {
        return [
            'status' => 'open',
            'session' => $open[0]['session'],
            'window' => $open[0]['window'],
            'message' => (string) ($open[0]['window']['message'] ?? 'Seminar time-in is open.'),
        ];
    }
    if (!empty($upcoming)) {
        usort($upcoming, static function (array $a, array $b): int {
            return strcmp((string) ($a['window']['opens_at'] ?? ''), (string) ($b['window']['opens_at'] ?? ''));
        });
        return [
            'status' => 'waiting',
            'session' => $upcoming[0]['session'],
            'window' => $upcoming[0]['window'],
            'message' => (string) ($upcoming[0]['window']['message'] ?? 'Waiting for seminar time-in window.'),
        ];
    }

    $last = $closed[0] ?? null;
    return [
        'status' => 'closed',
        'session' => is_array($last) ? $last['session'] : null,
        'window' => is_array($last) ? $last['window'] : null,
        'message' => is_array($last)
            ? (string) ($last['window']['message'] ?? 'Seminar time-in window has closed.')
            : 'Seminar time-in window has closed.',
    ];
}

/**
 * Build early-out status payload for UI.
 *
 * @return array{enabled:bool,enabled_at:?string,expires_at:?string,seconds_remaining:int,can_enable:bool,grace_ends_at:?string}
 */
function attendance_early_out_status(
    ?string $enabledAtRaw,
    DateTimeImmutable $nowUtc,
    ?DateTimeImmutable $startAt = null,
    ?DateTimeImmutable $endAt = null,
    int $graceMinutes = 30
): array {
    $enabledAt = parse_iso_datetime(trim((string) $enabledAtRaw));
    $graceEnds = attendance_early_out_grace_ends_at($startAt, $graceMinutes);
    $canEnable = attendance_early_out_schedule_allows_enable($startAt, $endAt, $nowUtc, $graceMinutes);
    $base = [
        'can_enable' => $canEnable,
        'grace_ends_at' => $graceEnds?->format('c'),
    ];

    if (!$enabledAt instanceof DateTimeImmutable) {
        return $base + [
            'enabled' => false,
            'enabled_at' => null,
            'expires_at' => null,
            'seconds_remaining' => 0,
        ];
    }
    $expires = attendance_early_out_expires_at($enabledAt);
    if (!$expires instanceof DateTimeImmutable || $nowUtc > $expires) {
        return $base + [
            'enabled' => false,
            'enabled_at' => null,
            'expires_at' => null,
            'seconds_remaining' => 0,
        ];
    }
    return $base + [
        'enabled' => true,
        'enabled_at' => $enabledAt->format('c'),
        'expires_at' => $expires->format('c'),
        'seconds_remaining' => max(0, $expires->getTimestamp() - $nowUtc->getTimestamp()),
    ];
}

/**
 * Early Out may be turned ON only after the time-in grace window ends,
 * and while the event/seminar is still within its end time.
 * Turning OFF is always allowed.
 */
function attendance_early_out_schedule_allows_enable(
    ?DateTimeImmutable $startAt,
    ?DateTimeImmutable $endAt,
    DateTimeImmutable $nowUtc,
    int $graceMinutes = 30
): bool {
    if (!$startAt instanceof DateTimeImmutable || !$endAt instanceof DateTimeImmutable) {
        return false;
    }

    $graceMinutes = max(0, $graceMinutes);
    $graceEnds = $startAt->modify('+' . $graceMinutes . ' minutes');

    // Clickable only after grace finishes, until scheduled end.
    return $nowUtc >= $graceEnds && $nowUtc <= $endAt;
}

/**
 * Grace window end (start + grace minutes) for Early Out UI messaging.
 */
function attendance_early_out_grace_ends_at(
    ?DateTimeImmutable $startAt,
    int $graceMinutes = 30
): ?DateTimeImmutable {
    if (!$startAt instanceof DateTimeImmutable) {
        return null;
    }
    return $startAt->modify('+' . max(0, $graceMinutes) . ' minutes');
}

/**
 * Pick the single seminar Early Out should target.
 * Seminars do not overlap in normal schedules, so one control is enough.
 *
 * Priority: already ON → can enable now → in-progress → next upcoming → last past.
 *
 * @param list<array<string,mixed>> $sessions
 * @return array<string,mixed>|null
 */
function attendance_resolve_early_out_target_session(array $sessions, DateTimeImmutable $nowUtc): ?array
{
    $rows = [];
    foreach ($sessions as $session) {
        if (!is_array($session)) {
            continue;
        }
        $id = trim((string) ($session['id'] ?? ''));
        if ($id === '') {
            continue;
        }
        $startAt = function_exists('parse_iso_datetime')
            ? parse_iso_datetime((string) ($session['start_at'] ?? ''))
            : null;
        $endAt = function_exists('parse_iso_datetime')
            ? parse_iso_datetime((string) ($session['end_at'] ?? ''))
            : null;
        if (!$startAt instanceof DateTimeImmutable || !$endAt instanceof DateTimeImmutable) {
            continue;
        }
        $graceMinutes = max(1, (int) ($session['scan_window_minutes'] ?? 30));
        $enabledRaw = trim((string) ($session['early_out_enabled_at'] ?? ''));
        $rows[] = [
            'session' => $session,
            'start_at' => $startAt,
            'end_at' => $endAt,
            'grace_minutes' => $graceMinutes,
            'enabled' => attendance_early_out_is_active($enabledRaw, $nowUtc),
            'can_enable' => attendance_early_out_schedule_allows_enable($startAt, $endAt, $nowUtc, $graceMinutes),
        ];
    }

    if ($rows === []) {
        return null;
    }

    foreach ($rows as $row) {
        if (($row['enabled'] ?? false) === true) {
            return $row['session'];
        }
    }

    $canEnable = array_values(array_filter(
        $rows,
        static fn(array $row): bool => ($row['can_enable'] ?? false) === true
    ));
    if (count($canEnable) === 1) {
        return $canEnable[0]['session'];
    }
    if (count($canEnable) > 1) {
        usort($canEnable, static function (array $a, array $b): int {
            return ($b['start_at']->getTimestamp()) <=> ($a['start_at']->getTimestamp());
        });
        return $canEnable[0]['session'];
    }

    foreach ($rows as $row) {
        /** @var DateTimeImmutable $start */
        $start = $row['start_at'];
        /** @var DateTimeImmutable $end */
        $end = $row['end_at'];
        if ($nowUtc >= $start && $nowUtc <= $end) {
            return $row['session'];
        }
    }

    $upcoming = array_values(array_filter(
        $rows,
        static fn(array $row): bool => $nowUtc < $row['start_at']
    ));
    if ($upcoming !== []) {
        usort($upcoming, static function (array $a, array $b): int {
            return ($a['start_at']->getTimestamp()) <=> ($b['start_at']->getTimestamp());
        });
        return $upcoming[0]['session'];
    }

    usort($rows, static function (array $a, array $b): int {
        return ($b['end_at']->getTimestamp()) <=> ($a['end_at']->getTimestamp());
    });

    return $rows[0]['session'];
}
