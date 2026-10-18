<?php
declare(strict_types=1);

require_once __DIR__ . '/event_targeting.php';
require_once __DIR__ . '/event_sessions.php';

function normalize_session_attendance_rule(mixed $value): string
{
    return strtolower(trim((string) $value)) === 'any' ? 'any' : 'all';
}

function event_uses_split_session_attendance(array $event): bool
{
    // `any` is the explicit split-session setting; event mode metadata may lag behind.
    if (array_key_exists('session_attendance_rule', $event)) {
        return normalize_session_attendance_rule($event['session_attendance_rule']) === 'any';
    }

    // Older API select fallbacks may omit the rule column. Distinct, explicit
    // per-session targets are the persisted split assignment in that case.
    $sessions = $event['sessions'] ?? [];
    if (!is_array($sessions)) {
        return false;
    }

    $targets = [];
    foreach ($sessions as $session) {
        if (!is_array($session)) {
            continue;
        }
        $target = strtoupper(trim((string) ($session['target_participant'] ?? '')));
        if ($target !== '') {
            $targets[$target] = true;
        }
    }

    return count($targets) > 1;
}

/** @return array<string,true> */
function event_session_target_scope(string $target): array
{
    $raw = strtoupper(trim($target));
    $course = 'ALL';
    $years = ['ALL'];

    if (preg_match('/^COURSE\s*=\s*(ALL|BSIT-SD|BSIT-BA|BSIT|BSCS)\s*;\s*YEARS\s*=\s*([0-9,\sA-Z]+)$/', $raw, $matches)) {
        $course = normalize_event_target_course($matches[1]);
        $years = array_values(array_filter(array_map('trim', explode(',', $matches[2]))));
    } elseif (in_array($raw, ['1', '2', '3', '4'], true)) {
        $years = [$raw];
    } elseif (preg_match('/^(ALL(?:\s+COURSES)?|BSIT(?:[\s_-]*(?:SD|BA))?|BSCS|IT|CS)\s*[-:]\s*(.+)$/', $raw, $matches)) {
        $course = normalize_event_target_course($matches[1]);
        preg_match_all('/([1-4])(?:ST|ND|RD|TH)?(?:\s*YEAR)?/', $matches[2], $yearMatches);
        if (!empty($yearMatches[1])) {
            $years = array_values(array_unique($yearMatches[1]));
        }
    } else {
        $course = normalize_event_target_course($raw);
    }

    $courses = match ($course) {
        'BSIT' => ['BSIT-SD', 'BSIT-BA'],
        'BSIT-SD' => ['BSIT-SD'],
        'BSIT-BA' => ['BSIT-BA'],
        'BSCS' => ['BSCS'],
        default => ['BSIT-SD', 'BSIT-BA', 'BSCS'],
    };
    $years = in_array('ALL', $years, true) || $years === []
        ? ['1', '2', '3', '4']
        : array_values(array_intersect($years, ['1', '2', '3', '4']));

    $scope = [];
    foreach ($courses as $targetCourse) {
        foreach ($years as $year) {
            $scope[$targetCourse . ':' . $year] = true;
        }
    }
    return $scope;
}

function event_session_target_is_strict_subset(string $candidate, string $other): bool
{
    $candidateScope = event_session_target_scope($candidate);
    $otherScope = event_session_target_scope($other);
    if ($candidateScope === [] || count($candidateScope) >= count($otherScope)) {
        return false;
    }

    return array_diff_key($candidateScope, $otherScope) === [];
}

/** @return array<int,array<string,mixed>> */
function event_matching_sessions_for_student(array $event, array $sessions, array $student): array
{
    $eventTarget = (string) ($event['event_for'] ?? 'All');
    if (!student_matches_event_target($student, $eventTarget)) {
        return [];
    }

    $splitAttendance = event_uses_split_session_attendance($event);
    $explicitTargets = [];
    foreach ($sessions as $index => $session) {
        if (!is_array($session)) {
            continue;
        }
        $target = trim((string) ($session['target_participant'] ?? ''));
        if ($target !== '') {
            $explicitTargets[$index] = $target;
        }
    }

    // Older split events may have saved only Seminar 1's selected group. In
    // that case the one blank session is the other half of the event target,
    // not a second copy of the full event audience.
    $implicitComplementIndex = null;
    if ($splitAttendance && count($sessions) === 2 && count($explicitTargets) === 1) {
        $onlyExplicitIndex = array_key_first($explicitTargets);
        foreach (array_keys($sessions) as $index) {
            if ($index !== $onlyExplicitIndex && !isset($explicitTargets[$index])) {
                $implicitComplementIndex = $index;
                break;
            }
        }
    }

    $matching = [];
    $matchingIndexes = [];
    foreach ($sessions as $index => $session) {
        if (!is_array($session)) {
            continue;
        }

        $sessionTarget = trim((string) ($session['target_participant'] ?? ''));
        if ($sessionTarget === '') {
            if ($implicitComplementIndex === $index) {
                $otherTarget = reset($explicitTargets);
                if (student_matches_event_target($student, (string) $otherTarget)) {
                    continue;
                }
                $matching[] = $session;
                $matchingIndexes[] = $index;
                continue;
            }
            $sessionTarget = $eventTarget;
        }
        if (student_matches_event_target($student, $sessionTarget)) {
            $matching[] = $session;
            $matchingIndexes[] = $index;
        }
    }

    if ($splitAttendance && count($matching) === 2 && count($explicitTargets) === 2) {
        $firstTarget = $explicitTargets[$matchingIndexes[0]] ?? '';
        $secondTarget = $explicitTargets[$matchingIndexes[1]] ?? '';
        if (event_session_target_is_strict_subset($firstTarget, $secondTarget)) {
            return [$matching[0]];
        }
        if (event_session_target_is_strict_subset($secondTarget, $firstTarget)) {
            return [$matching[1]];
        }
    }

    return $matching;
}

/** @return array<int,array<string,mixed>>|null Null means the saved assignment could not be read. */
function event_sessions_for_registration_assignment(
    string $eventId,
    string $registrationId,
    array $sessions,
    array $headers
): ?array {
    $eventId = trim($eventId);
    $registrationId = trim($registrationId);
    if ($eventId === '' || $registrationId === '' || !defined('SUPABASE_URL') || !function_exists('supabase_request')) {
        return null;
    }

    $url = rtrim(SUPABASE_URL, '/') . '/rest/v1/event_session_registrations'
        . '?select=session_id'
        . '&event_id=eq.' . rawurlencode($eventId)
        . '&registration_id=eq.' . rawurlencode($registrationId)
        . '&limit=3';
    $response = supabase_request('GET', $url, $headers);
    if (!($response['ok'] ?? false)) {
        return null;
    }

    $rows = json_decode((string) ($response['body'] ?? ''), true);
    if (!is_array($rows)) {
        return null;
    }

    $assignedIds = [];
    foreach ($rows as $row) {
        if (is_array($row)) {
            $sessionId = trim((string) ($row['session_id'] ?? ''));
            if ($sessionId !== '') {
                $assignedIds[$sessionId] = true;
            }
        }
    }

    $assigned = [];
    foreach ($sessions as $session) {
        if (is_array($session) && isset($assignedIds[(string) ($session['id'] ?? '')])) {
            $assigned[] = $session;
        }
    }
    return $assigned;
}

/** @return array<string,array<int,array<string,mixed>>>|null Registration id => assigned sessions; null means lookup failed. */
function event_session_assignments_for_registrations(
    string $eventId,
    array $registrationIds,
    array $sessions,
    array $headers
): ?array {
    $eventId = trim($eventId);
    $registrationIds = array_values(array_unique(array_filter(array_map(
        static fn(mixed $id): string => trim((string) $id),
        $registrationIds
    ))));
    if ($eventId === '' || $registrationIds === []) {
        return [];
    }
    if (!defined('SUPABASE_URL') || !function_exists('supabase_request')) {
        return null;
    }

    $sessionsById = [];
    foreach ($sessions as $session) {
        if (is_array($session)) {
            $sessionId = trim((string) ($session['id'] ?? ''));
            if ($sessionId !== '') {
                $sessionsById[$sessionId] = $session;
            }
        }
    }

    $assignments = [];
    foreach (array_chunk($registrationIds, 80) as $chunk) {
        $url = rtrim(SUPABASE_URL, '/') . '/rest/v1/event_session_registrations'
            . '?select=registration_id,session_id'
            . '&event_id=eq.' . rawurlencode($eventId)
            . '&registration_id=in.(' . implode(',', array_map('rawurlencode', $chunk)) . ')';
        $response = supabase_request('GET', $url, $headers);
        if (!($response['ok'] ?? false)) {
            return null;
        }

        $rows = json_decode((string) ($response['body'] ?? ''), true);
        if (!is_array($rows)) {
            return null;
        }
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $registrationId = trim((string) ($row['registration_id'] ?? ''));
            $sessionId = trim((string) ($row['session_id'] ?? ''));
            if ($registrationId !== '' && isset($sessionsById[$sessionId])) {
                $assignments[$registrationId][$sessionId] = $sessionsById[$sessionId];
            }
        }
    }

    foreach ($assignments as &$assignedSessions) {
        $assignedSessions = array_values($assignedSessions);
    }
    unset($assignedSessions);

    return $assignments;
}

/** @return array<int,array<string,mixed>> */
function event_matching_sessions_for_registration(
    array $event,
    array $sessions,
    ?array $student,
    string $eventId,
    string $registrationId,
    array $headers
): array {
    if (event_uses_split_session_attendance($event)) {
        $assigned = event_sessions_for_registration_assignment(
            $eventId,
            $registrationId,
            $sessions,
            $headers
        );
        if (!is_array($assigned)) {
            return [];
        }
        if (count($assigned) === 1) {
            return $assigned;
        }
        if ($assigned !== []) {
            return [];
        }
    }

    return is_array($student)
        ? event_matching_sessions_for_student($event, $sessions, $student)
        : [];
}

/** @return array<int,array<string,mixed>> */
function event_matching_sessions_for_student_registration(
    array $event,
    array $sessions,
    ?array $student,
    string $eventId,
    string $studentId,
    array $headers
): array {
    $eventId = trim($eventId);
    $studentId = trim($studentId);
    if (event_uses_split_session_attendance($event)) {
        if ($eventId === '' || $studentId === '' || !defined('SUPABASE_URL') || !function_exists('supabase_request')) {
            return [];
        }

        $url = rtrim(SUPABASE_URL, '/') . '/rest/v1/event_registrations'
            . '?select=id'
            . '&event_id=eq.' . rawurlencode($eventId)
            . '&student_id=eq.' . rawurlencode($studentId)
            . '&limit=5';
        $response = supabase_request('GET', $url, $headers);
        if (!($response['ok'] ?? false)) {
            return [];
        }
        $rows = json_decode((string) ($response['body'] ?? ''), true);
        if (!is_array($rows)) {
            return [];
        }

        $registrationIds = [];
        foreach ($rows as $row) {
            $registrationId = is_array($row) ? trim((string) ($row['id'] ?? '')) : '';
            if ($registrationId !== '') {
                $registrationIds[$registrationId] = true;
            }
        }
        if ($registrationIds === []) {
            return [];
        }

        $assignedById = [];
        foreach (array_keys($registrationIds) as $registrationId) {
            $assigned = event_sessions_for_registration_assignment(
                $eventId,
                $registrationId,
                $sessions,
                $headers
            );
            if (!is_array($assigned)) {
                return [];
            }
            foreach ($assigned as $session) {
                $sessionId = trim((string) ($session['id'] ?? ''));
                if ($sessionId !== '') {
                    $assignedById[$sessionId] = $session;
                }
            }
        }
        if (count($assignedById) === 1) {
            return array_values($assignedById);
        }
        if ($assignedById !== []) {
            return [];
        }
    }

    return is_array($student)
        ? event_matching_sessions_for_student($event, $sessions, $student)
        : [];
}

/** @return array{registration_count:int,registration_limit:?int,is_full:bool,session_counts:array<int,array<string,mixed>>} */
function event_session_registration_capacity(array $sessions, ?int $limit, array $headers): array
{
    $counts = [];
    $participantCount = 0;
    $isFull = false;
    $sessionLimits = [];

    foreach ($sessions as $session) {
        if (!is_array($session)) {
            continue;
        }
        $sessionId = trim((string) ($session['id'] ?? ''));
        if ($sessionId === '') {
            continue;
        }
        $count = max(0, supabase_exact_count(
            'event_session_registrations',
            $headers,
            'session_id=eq.' . rawurlencode($sessionId)
        ));
        $sessionLimit = normalize_registration_limit($session['registration_limit'] ?? null) ?? $limit;
        $participantCount = max($participantCount, $count);
        $sessionFull = $sessionLimit !== null && $count >= $sessionLimit;
        $isFull = $isFull || $sessionFull;
        $sessionLimits[] = $sessionLimit;
        $counts[] = [
            'session_id' => $sessionId,
            'title' => (string) ($session['title'] ?? ''),
            'registration_count' => $count,
            'registration_limit' => $sessionLimit,
            'is_full' => $sessionFull,
        ];
    }

    $reportedLimit = $limit;
    if (count($sessionLimits) === 1) {
        $reportedLimit = $sessionLimits[0];
    } elseif ($sessionLimits !== [] && count(array_unique($sessionLimits, SORT_REGULAR)) === 1) {
        $reportedLimit = $sessionLimits[0];
    }

    return [
        'registration_count' => $participantCount,
        'registration_limit' => $reportedLimit,
        'is_full' => $isFull,
        'session_counts' => $counts,
    ];
}

function event_student_meets_session_attendance_rule(
    array $event,
    array $requiredSessions,
    array $attendedSessionIds
): bool {
    $requiredIds = [];
    foreach ($requiredSessions as $session) {
        $sessionId = trim((string) ($session['id'] ?? ''));
        if ($sessionId !== '') {
            $requiredIds[$sessionId] = true;
        }
    }
    if ($requiredIds === []) {
        return false;
    }

    $attended = array_fill_keys(array_map('strval', $attendedSessionIds), true);
    $completedCount = 0;
    foreach ($requiredIds as $sessionId => $_required) {
        if (isset($attended[$sessionId])) {
            $completedCount++;
        }
    }

    if (normalize_session_attendance_rule($event['session_attendance_rule'] ?? 'all') === 'any') {
        return $completedCount > 0;
    }

    return $completedCount === count($requiredIds);
}
