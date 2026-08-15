<?php
declare(strict_types=1);

/**
 * Service-role helpers for student_class_schedules (fail-closed like roster).
 */

function student_class_schedules_headers(bool $preferRepresentation = false): array
{
    $h = [
        'Accept: application/json',
        'Content-Type: application/json',
        'apikey: ' . SUPABASE_KEY,
        'Authorization: Bearer ' . SUPABASE_KEY,
    ];
    if ($preferRepresentation) {
        $h[] = 'Prefer: return=representation';
    }
    return $h;
}

/**
 * Infer AM/PM for LU registration-form clocks that omit the meridian.
 */
function student_class_schedule_default_meridian(int $hour): string
{
    if ($hour >= 1 && $hour <= 6) {
        return 'PM';
    }
    if ($hour === 12) {
        return 'PM';
    }
    return 'AM';
}

/**
 * @return array{0:string,1:string} meridians for start/end
 */
function student_class_schedule_pair_meridians(int $startHour, int $endHour, string $startMeridian, string $endMeridian): array
{
    $startMeridian = strtoupper($startMeridian);
    $endMeridian = strtoupper($endMeridian);
    if ($startMeridian === '') {
        $startMeridian = student_class_schedule_default_meridian($startHour);
    }
    if ($endMeridian === '') {
        $endMeridian = student_class_schedule_default_meridian($endHour);
        if ($startHour > $endHour) {
            $endMeridian = 'PM';
            if ($startMeridian === '') {
                $startMeridian = student_class_schedule_default_meridian($startHour);
            }
        } elseif ($startMeridian === 'PM' && $endHour >= 7 && $endHour <= 11) {
            $endMeridian = 'PM';
        }
    }
    return [$startMeridian, $endMeridian];
}

function student_class_schedule_format_time_range(string $raw): string
{
    $original = trim($raw);
    if ($original === '') {
        return '';
    }
    $t = strtolower($original);
    $t = str_replace([' ', '.', '–', '—'], ['', '', '-', '-'], $t);
    if (preg_match('/^(\d{1,2}):(\d{2})(am|pm)?-(\d{1,2}):(\d{2})(am|pm)?$/', $t, $m)) {
        $h1 = (int) $m[1];
        $min1 = (int) $m[2];
        $h2 = (int) $m[4];
        $min2 = (int) $m[5];
        if ($h1 < 1 || $h1 > 12 || $h2 < 1 || $h2 > 12 || $min1 > 59 || $min2 > 59) {
            return $original;
        }
        [$p1, $p2] = student_class_schedule_pair_meridians($h1, $h2, (string) ($m[3] ?? ''), (string) ($m[6] ?? ''));
        return sprintf('%d:%02d %s – %d:%02d %s', $h1, $min1, $p1, $h2, $min2, $p2);
    }
    if (preg_match('/^(\d{1,2}):(\d{2})(am|pm)?$/', $t, $m)) {
        $h = (int) $m[1];
        $min = (int) $m[2];
        if ($h < 1 || $h > 12 || $min > 59) {
            return $original;
        }
        $p = strtoupper((string) ($m[3] ?? ''));
        if ($p === '') {
            $p = student_class_schedule_default_meridian($h);
        }
        return sprintf('%d:%02d %s', $h, $min, $p);
    }
    return $original;
}

function student_class_schedule_format_time_label(string $label): string
{
    $parts = preg_split('/\s*;\s*/', trim($label)) ?: [];
    $out = [];
    foreach ($parts as $part) {
        $formatted = student_class_schedule_format_time_range((string) $part);
        if ($formatted !== '') {
            $out[] = $formatted;
        }
    }
    return implode('; ', $out);
}

/**
 * @param list<array<string,mixed>> $subjects
 * @return list<array<string,mixed>>
 */
function student_class_schedules_public_rows(array $subjects): array
{
    $out = [];
    foreach ($subjects as $row) {
        if (!is_array($row)) {
            continue;
        }
        $meetings = $row['meetings'] ?? [];
        if (is_string($meetings)) {
            $decoded = json_decode($meetings, true);
            $meetings = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($meetings)) {
            $meetings = [];
        }
        $publicMeetings = [];
        foreach ($meetings as $meeting) {
            if (!is_array($meeting)) {
                continue;
            }
            $publicMeetings[] = [
                'day' => trim((string) ($meeting['day'] ?? '')),
                'time' => student_class_schedule_format_time_range(trim((string) ($meeting['time'] ?? ''))),
            ];
        }
        $out[] = [
            'course_code' => trim((string) ($row['course_code'] ?? '')),
            'course_description' => trim((string) ($row['course_description'] ?? '')),
            'instructor' => trim((string) ($row['instructor'] ?? '')),
            'days' => trim((string) ($row['days'] ?? '')),
            'time_label' => student_class_schedule_format_time_label(trim((string) ($row['time_label'] ?? ''))),
            'meetings' => $publicMeetings,
        ];
    }
    return $out;
}

function student_class_schedule_fingerprint(array $row): string
{
    return strtolower(trim(
        (string) ($row['course_code'] ?? '') . '|'
        . (string) ($row['days'] ?? '') . '|'
        . (string) ($row['time_label'] ?? '') . '|'
        . (string) ($row['instructor'] ?? '')
    ));
}

/**
 * @param list<array<string,mixed>> $subjects
 */
function student_class_schedules_replace(string $userId, string $studentNo, array $subjects): bool
{
    $userId = strtolower(trim($userId));
    $studentNo = trim($studentNo);
    if ($userId === '' || $subjects === []) {
        return false;
    }

    $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DATE_ATOM);
    $headers = student_class_schedules_headers();
    $delUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/student_class_schedules'
        . '?user_id=eq.' . rawurlencode($userId);
    $delRes = supabase_request('DELETE', $delUrl, $headers);
    if (!($delRes['ok'] ?? false)) {
        return false;
    }

    $rows = [];
    foreach ($subjects as $subject) {
        if (!is_array($subject)) {
            continue;
        }
        $code = trim((string) ($subject['course_code'] ?? ''));
        $desc = trim((string) ($subject['course_description'] ?? ''));
        if ($code === '' || $desc === '') {
            continue;
        }
        $meetings = $subject['meetings'] ?? [];
        if (!is_array($meetings)) {
            $meetings = [];
        }
        $rows[] = [
            'user_id' => $userId,
            'student_no' => $studentNo,
            'course_code' => $code,
            'course_description' => $desc,
            'instructor' => trim((string) ($subject['instructor'] ?? '')),
            'days' => trim((string) ($subject['days'] ?? '')),
            'time_label' => trim((string) ($subject['time_label'] ?? '')),
            'meetings' => $meetings,
            'updated_at' => $now,
        ];
    }
    if ($rows === []) {
        return false;
    }

    $insUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/student_class_schedules';
      $insRes = supabase_request(
        'POST',
        $insUrl,
        student_class_schedules_headers(),
        json_encode($rows, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)
    );
    if ($insRes['ok'] ?? false) {
        return true;
    }
    $body = strtolower((string) ($insRes['body'] ?? ''));
    if (str_contains($body, 'student_class_schedules') && (str_contains($body, 'does not exist') || str_contains($body, '42p01'))) {
        return false;
    }
    return false;
}

/**
 * @param list<string> $userIds
 * @return array<string, list<array<string,mixed>>>
 */
function student_class_schedules_fetch_by_user_ids(array $userIds, array $headers = []): array
{
    $ids = [];
    foreach ($userIds as $id) {
        $id = strtolower(trim((string) $id));
        if ($id !== '' && preg_match('/^[0-9a-f-]{36}$/', $id)) {
            $ids[$id] = true;
        }
    }
    $ids = array_keys($ids);
    if ($ids === []) {
        return [];
    }
    if ($headers === []) {
        $headers = student_class_schedules_headers();
    }

    $grouped = [];
    foreach (array_chunk($ids, 80) as $chunk) {
        $in = '(' . implode(',', array_map('rawurlencode', $chunk)) . ')';
        $url = rtrim(SUPABASE_URL, '/') . '/rest/v1/student_class_schedules'
            . '?select=user_id,student_no,course_code,course_description,instructor,days,time_label,meetings,updated_at'
            . '&user_id=in.' . $in
            . '&order=course_code.asc'
            . '&limit=2000';
        $res = supabase_request('GET', $url, $headers);
        $rows = ($res['ok'] ?? false) ? json_decode((string) ($res['body'] ?? ''), true) : [];
        if (!is_array($rows)) {
            continue;
        }
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $uid = strtolower(trim((string) ($row['user_id'] ?? '')));
            if ($uid === '') {
                continue;
            }
            $grouped[$uid][] = $row;
        }
    }
    return $grouped;
}

/**
 * Manila weekday codes used on LU registration forms: M T W Th F S Su
 *
 * @return list<string>
 */
function student_class_schedule_weekday_codes_for_dates(array $dates): array
{
    $codes = [];
    $tz = new DateTimeZone('Asia/Manila');
    foreach ($dates as $raw) {
        if ($raw instanceof DateTimeInterface) {
            $dt = DateTimeImmutable::createFromInterface($raw)->setTimezone($tz);
        } else {
            $s = trim((string) $raw);
            if ($s === '') {
                continue;
            }
            try {
                $dt = (new DateTimeImmutable($s))->setTimezone($tz);
            } catch (Throwable $e) {
                continue;
            }
        }
        $php = $dt->format('D');
        $code = match ($php) {
            'Mon' => 'M',
            'Tue' => 'T',
            'Wed' => 'W',
            'Thu' => 'Th',
            'Fri' => 'F',
            'Sat' => 'S',
            'Sun' => 'Su',
            default => '',
        };
        if ($code !== '' && !in_array($code, $codes, true)) {
            $codes[] = $code;
        }
    }
    return $codes;
}

/**
 * @param list<string> $weekdayCodes
 */
function student_class_schedule_meets_weekdays(array $row, array $weekdayCodes): bool
{
    if ($weekdayCodes === []) {
        return false;
    }
    $days = [];
    $rawDays = trim((string) ($row['days'] ?? ''));
    if ($rawDays !== '') {
        foreach (preg_split('/[,\s]+/', $rawDays) ?: [] as $part) {
            $part = trim($part);
            if ($part !== '') {
                $days[] = $part;
            }
        }
    }
    $meetings = $row['meetings'] ?? [];
    if (is_string($meetings)) {
        $decoded = json_decode($meetings, true);
        $meetings = is_array($decoded) ? $decoded : [];
    }
    if (is_array($meetings)) {
        foreach ($meetings as $m) {
            if (!is_array($m)) {
                continue;
            }
            $d = trim((string) ($m['day'] ?? ''));
            if ($d !== '') {
                $days[] = $d;
            }
        }
    }
    $days = array_values(array_unique($days));
    if ($days === []) {
        return false;
    }
    foreach ($days as $d) {
        foreach ($weekdayCodes as $code) {
            if (strcasecmp($d, $code) === 0) {
                return true;
            }
        }
    }
    return false;
}

/**
 * Canonical fingerprint set for one student's uploaded subjects.
 *
 * @param list<array<string,mixed>> $rows
 */
function student_class_schedule_signature(array $rows): string
{
    $fps = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $fp = student_class_schedule_fingerprint($row);
        if ($fp !== '|||') {
            $fps[] = $fp;
        }
    }
    sort($fps);
    return implode("\n", $fps);
}

/**
 * Shared block schedule: most common fingerprint set among regulars who uploaded.
 *
 * @param array<string, list<array<string,mixed>>> $byUser
 * @return list<array<string,mixed>>
 */
function student_class_schedules_block_consensus(array $byUser): array
{
    $monitor = student_class_schedules_block_monitor([], $byUser);
    return is_array($monitor['subjects'] ?? null) ? $monitor['subjects'] : [];
}

/**
 * Majority block schedule plus per-student match / mismatch vs that majority.
 *
 * @param list<array<string,mixed>> $students  user_id, student_no, first_name, last_name, kind
 * @param array<string, list<array<string,mixed>>> $byUser
 * @return array{
 *   subjects:list<array<string,mixed>>,
 *   majority_count:int,
 *   uploaded_count:int,
 *   match_count:int,
 *   mismatch_count:int,
 *   all_match:bool,
 *   mismatches:list<array<string,string>>
 * }
 */
function student_class_schedules_block_monitor(array $students, array $byUser): array
{
    $signatures = [];
    foreach ($byUser as $uid => $rows) {
        if (!is_array($rows) || $rows === []) {
            continue;
        }
        $sig = student_class_schedule_signature($rows);
        if ($sig === '') {
            continue;
        }
        if (!isset($signatures[$sig])) {
            $signatures[$sig] = ['count' => 0, 'rows' => $rows];
        }
        $signatures[$sig]['count']++;
    }

    $majoritySig = '';
    $majorityRows = [];
    $majorityCount = 0;
    if ($signatures !== []) {
        uasort($signatures, static fn (array $a, array $b): int => $b['count'] <=> $a['count']);
        $bestKey = array_key_first($signatures);
        $best = $signatures[$bestKey] ?? null;
        if (is_array($best)) {
            $majoritySig = (string) $bestKey;
            $majorityRows = is_array($best['rows'] ?? null) ? $best['rows'] : [];
            $majorityCount = (int) ($best['count'] ?? 0);
        }
    }

    $uploadedCount = 0;
    foreach ($byUser as $rows) {
        if (is_array($rows) && $rows !== [] && student_class_schedule_signature($rows) !== '') {
            $uploadedCount++;
        }
    }

    $matchCount = 0;
    $comparedCount = 0;
    $mismatches = [];
    foreach ($students as $student) {
        if (!is_array($student)) {
            continue;
        }
        $uid = strtolower(trim((string) ($student['user_id'] ?? '')));
        $kind = strtolower(trim((string) ($student['kind'] ?? '')));
        // Awaiting signup: no account yet, so they cannot upload. Skip match/mismatch.
        if ($kind === 'roster' || $uid === '') {
            continue;
        }
        $comparedCount++;
        $name = trim((string) ($student['last_name'] ?? '') . ', ' . (string) ($student['first_name'] ?? ''), ' ,');
        $no = trim((string) ($student['student_no'] ?? ''));
        $rows = (isset($byUser[$uid]) && is_array($byUser[$uid])) ? $byUser[$uid] : [];
        $sig = $rows !== [] ? student_class_schedule_signature($rows) : '';

        if ($majoritySig !== '' && $sig !== '' && $sig === $majoritySig) {
            $matchCount++;
            continue;
        }
        if ($sig === '') {
            $reason = 'no_upload';
            $label = 'No upload';
        } else {
            $reason = 'different';
            $label = 'Different schedule';
        }
        $mismatches[] = [
            'user_id' => $uid,
            'student_no' => $no,
            'name' => $name !== '' ? $name : 'Unnamed',
            'reason' => $reason,
            'reason_label' => $label,
        ];
    }

    usort($mismatches, static function (array $a, array $b): int {
        $rank = static fn (string $r): int => $r === 'different' ? 0 : 1;
        $byReason = $rank((string) ($a['reason'] ?? '')) <=> $rank((string) ($b['reason'] ?? ''));
        if ($byReason !== 0) {
            return $byReason;
        }
        return strcasecmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? ''));
    });

    $mismatchCount = count($mismatches);

    return [
        'subjects' => student_class_schedules_public_rows($majorityRows),
        'majority_count' => $majorityCount,
        'uploaded_count' => $uploadedCount,
        'match_count' => $matchCount,
        'mismatch_count' => $mismatchCount,
        'student_count' => $comparedCount,
        'all_match' => $comparedCount > 0 && $majoritySig !== '' && $mismatchCount === 0,
        'mismatches' => $mismatches,
    ];
}
