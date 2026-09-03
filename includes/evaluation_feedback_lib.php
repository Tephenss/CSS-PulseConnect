<?php
declare(strict_types=1);

require_once __DIR__ . '/event_sessions.php';

/**
 * Rating (Likert) questions first, then comment/text — stable by sort_order within type.
 *
 * @param list<array<string, mixed>> $questions
 * @return list<array<string, mixed>>
 */
function evaluation_sort_questions_by_type(array $questions): array
{
    $indexed = [];
    foreach ($questions as $i => $question) {
        if (!is_array($question)) {
            continue;
        }
        $indexed[] = ['q' => $question, 'i' => $i];
    }

    usort($indexed, static function (array $a, array $b): int {
        $typeA = strtolower(trim((string) ($a['q']['field_type'] ?? 'text')));
        $typeB = strtolower(trim((string) ($b['q']['field_type'] ?? 'text')));
        $rankA = $typeA === 'rating' ? 0 : 1;
        $rankB = $typeB === 'rating' ? 0 : 1;
        if ($rankA !== $rankB) {
            return $rankA <=> $rankB;
        }
        $sortA = (int) ($a['q']['sort_order'] ?? 0);
        $sortB = (int) ($b['q']['sort_order'] ?? 0);
        if ($sortA !== $sortB) {
            return $sortA <=> $sortB;
        }
        return $a['i'] <=> $b['i'];
    });

    return array_values(array_map(static fn (array $row): array => $row['q'], $indexed));
}

/**
 * Rewrite sort_order so DB order matches rating-then-comment display order.
 *
 * @param 'evaluation_questions'|'event_session_evaluation_questions' $table
 */
function evaluation_renormalize_question_sort_orders(
    string $table,
    string $filterColumn,
    string $filterValue,
    array $headers
): void {
    $filterColumn = trim($filterColumn);
    $filterValue = trim($filterValue);
    if ($filterColumn === '' || $filterValue === '') {
        return;
    }
    if (!in_array($table, ['evaluation_questions', 'event_session_evaluation_questions'], true)) {
        return;
    }
    if (!in_array($filterColumn, ['event_id', 'session_id'], true)) {
        return;
    }

    $listUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/' . $table
        . '?select=id,field_type,sort_order'
        . '&' . $filterColumn . '=eq.' . rawurlencode($filterValue)
        . '&order=sort_order.asc';
    $listRes = supabase_request('GET', $listUrl, [
        'Accept: application/json',
        'apikey: ' . SUPABASE_KEY,
        'Authorization: Bearer ' . SUPABASE_KEY,
    ]);
    if (!$listRes['ok']) {
        return;
    }
    $rows = json_decode((string) ($listRes['body'] ?? ''), true);
    if (!is_array($rows) || count($rows) === 0) {
        return;
    }

    $sorted = evaluation_sort_questions_by_type($rows);
    $patchHeaders = [
        'Content-Type: application/json',
        'Accept: application/json',
        'apikey: ' . SUPABASE_KEY,
        'Authorization: Bearer ' . SUPABASE_KEY,
        'Prefer: return=minimal',
    ];

    $order = 1;
    foreach ($sorted as $question) {
        $id = trim((string) ($question['id'] ?? ''));
        if ($id === '') {
            continue;
        }
        $current = (int) ($question['sort_order'] ?? -1);
        if ($current === $order) {
            $order++;
            continue;
        }
        $patchUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/' . $table
            . '?id=eq.' . rawurlencode($id);
        supabase_request(
            'PATCH',
            $patchUrl,
            $patchHeaders,
            json_encode(['sort_order' => $order], JSON_UNESCAPED_SLASHES)
        );
        $order++;
    }
}

function feedback_attendance_counts_as_present(array $row): bool
{
    $status = strtolower(trim((string) ($row['status'] ?? '')));
    $checkInAt = trim((string) ($row['check_in_at'] ?? ''));

    if ($checkInAt !== '') {
        return true;
    }

    return in_array($status, ['present', 'scanned', 'late', 'early'], true);
}

function feedback_ticket_student_map(string $eventId, array $headers): array
{
    $url = rtrim(SUPABASE_URL, '/') . '/rest/v1/event_registrations'
        . '?select=student_id,tickets(id)'
        . '&event_id=eq.' . rawurlencode($eventId);
    $res = supabase_request('GET', $url, $headers);
    if (!$res['ok']) {
        return [];
    }

    $rows = json_decode((string) ($res['body'] ?? ''), true);
    if (!is_array($rows)) {
        return [];
    }

    $ticketToStudent = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }

        $studentId = trim((string) ($row['student_id'] ?? ''));
        if ($studentId === '') {
            continue;
        }

        $tickets = isset($row['tickets']) && is_array($row['tickets']) ? $row['tickets'] : [];
        foreach ($tickets as $ticketRow) {
            if (!is_array($ticketRow)) {
                continue;
            }

            $ticketId = trim((string) ($ticketRow['id'] ?? ''));
            if ($ticketId !== '') {
                $ticketToStudent[$ticketId] = $studentId;
            }
        }
    }

    return $ticketToStudent;
}

function feedback_missing_table(array $response, string $table): bool
{
    $body = strtolower((string) ($response['body'] ?? ''));
    $error = strtolower((string) ($response['error'] ?? ''));
    $needle = strtolower($table);

    return str_contains($body, $needle) && (
        str_contains($body, 'does not exist')
        || str_contains($body, 'schema cache')
        || str_contains($body, 'could not find the table')
        || str_contains($body, '42p01')
    ) || (
        str_contains($error, $needle) && (
            str_contains($error, 'does not exist')
            || str_contains($error, 'schema cache')
            || str_contains($error, 'could not find the table')
            || str_contains($error, '42p01')
        )
    );
}

function feedback_year_label(string $yearKey): string
{
    return match ($yearKey) {
        '1' => '1st Year',
        '2' => '2nd Year',
        '3' => '3rd Year',
        '4' => '4th Year',
        default => '',
    };
}

function feedback_parse_section_meta(string $sectionName): array
{
    $raw = trim(preg_replace('/\s+/', ' ', $sectionName) ?? '');
    $yearKey = '';
    $block = $raw !== '' ? $raw : 'Unassigned';

    // e.g. "BSIT SD 1A", "BSIT-BA-2B", "BSCS 3C", "BSIT 1A"
    if (preg_match('/^(BSIT[\s\-]*SD|BSIT[\s\-]*BA|BSCS|BSIT)[\s\-]*([1-4])[\s\-]*([A-Z])$/i', $raw, $m)) {
        $program = strtoupper(preg_replace('/[\s\-]+/', ' ', trim((string) $m[1])) ?? '');
        if ($program === 'BSIT') {
            $program = 'BSIT SD';
        }
        $yearKey = (string) $m[2];
        $letter = strtoupper((string) $m[3]);
        $short = str_contains($program, 'BA') ? 'BA' : (str_contains($program, 'CS') && !str_contains($program, 'IT') ? 'CS' : 'SD');
        $block = $short . ' ' . $yearKey . $letter;
    } elseif (preg_match('/\b(SD|BA|CS)\s*([1-4])\s*([A-Z])\b/i', $raw, $mShort)) {
        $yearKey = (string) $mShort[2];
        $block = strtoupper((string) $mShort[1]) . ' ' . $yearKey . strtoupper((string) $mShort[3]);
    } elseif (preg_match('/\b([1-4])\s*([A-Z])\b/i', $raw, $m2)) {
        $yearKey = (string) $m2[1];
        $block = $yearKey . strtoupper((string) $m2[2]);
    } elseif (preg_match('/\birreg(?:ular)?[\s\-]*([1-4])/i', $raw, $mIrreg)) {
        $yearKey = (string) $mIrreg[1];
    } elseif (preg_match('/([1-4])(?:st|nd|rd|th)?\s*year/i', $raw, $m3)) {
        $yearKey = (string) $m3[1];
    }

    return [
        'year_key' => $yearKey,
        'year_level' => $yearKey !== '' ? feedback_year_label($yearKey) : '',
        'block' => $block,
    ];
}

/**
 * Build SVG pie slices + in-slice percent labels from percent segments (0–100).
 *
 * @param list<array{percent:float|int,color:string,label?:string}> $segments
 * @return array{paths: list<array{d:string,color:string}>, labels: list<array{x:float,y:float,text:string,show:bool}>}
 */
function feedback_pie_svg_paths(array $segments, float $cx = 100.0, float $cy = 100.0, float $r = 90.0): array
{
    $paths = [];
    $labels = [];
    $angle = -90.0; // start at top
    $total = 0.0;
    foreach ($segments as $seg) {
        $total += (float) ($seg['percent'] ?? 0);
    }
    if ($total <= 0) {
        return ['paths' => [], 'labels' => []];
    }

    foreach ($segments as $seg) {
        $pct = (float) ($seg['percent'] ?? 0);
        if ($pct <= 0) {
            continue;
        }
        $slice = ($pct / $total) * 360.0;
        if (abs($pct - round($pct)) < 0.05) {
            $labelText = (string) (int) round($pct) . '%';
        } else {
            $labelText = number_format($pct, 1) . '%';
        }

        // Full circle
        if ($slice >= 359.9) {
            $paths[] = [
                'd' => sprintf(
                    'M %.2f %.2f m -%.2f 0 a %.2f %.2f 0 1 1 %.2f 0 a %.2f %.2f 0 1 1 -%.2f 0',
                    $cx,
                    $cy,
                    $r,
                    $r,
                    $r,
                    $r * 2,
                    $r,
                    $r,
                    $r * 2
                ),
                'color' => (string) ($seg['color'] ?? '#9AA0A6'),
            ];
            $labels[] = [
                'x' => $cx,
                'y' => $cy,
                'text' => $labelText,
                'show' => true,
            ];
            break;
        }

        $startRad = deg2rad($angle);
        $endRad = deg2rad($angle + $slice);
        $midRad = deg2rad($angle + ($slice / 2.0));
        $x1 = $cx + $r * cos($startRad);
        $y1 = $cy + $r * sin($startRad);
        $x2 = $cx + $r * cos($endRad);
        $y2 = $cy + $r * sin($endRad);
        $large = $slice > 180 ? 1 : 0;
        $paths[] = [
            'd' => sprintf(
                'M %.2f %.2f L %.2f %.2f A %.2f %.2f 0 %d 1 %.2f %.2f Z',
                $cx,
                $cy,
                $x1,
                $y1,
                $r,
                $r,
                $large,
                $x2,
                $y2
            ),
            'color' => (string) ($seg['color'] ?? '#9AA0A6'),
        ];

        $labelR = $r * 0.58;
        $labels[] = [
            'x' => $cx + $labelR * cos($midRad),
            'y' => $cy + $labelR * sin($midRad),
            'text' => $labelText,
            // Hide tiny slices (labels won't fit)
            'show' => $slice >= 18.0,
        ];
        $angle += $slice;
    }

    return ['paths' => $paths, 'labels' => $labels];
}

/**
 * @return array<string, array{name:string,student_no:string,section_name:string,year_key:string,year_level:string,block:string}>
 */
function feedback_respondent_map(array $studentIds, array $headers): array
{
    $studentIds = array_values(array_unique(array_filter(array_map(
        static fn ($id): string => trim((string) $id),
        $studentIds
    ))));
    if (count($studentIds) === 0) {
        return [];
    }

    $url = rtrim(SUPABASE_URL, '/') . '/rest/v1/users'
        . '?select=id,first_name,middle_name,last_name,suffix,student_id,section_id,course'
        . '&id=in.(' . implode(',', array_map('rawurlencode', $studentIds)) . ')';
    $res = supabase_request('GET', $url, $headers);
    if (!$res['ok']) {
        return [];
    }

    $rows = json_decode((string) $res['body'], true);
    if (!is_array($rows)) {
        return [];
    }

    $sectionIds = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $sectionId = trim((string) ($row['section_id'] ?? ''));
        if ($sectionId !== '') {
            $sectionIds[$sectionId] = true;
        }
    }

    $rosterYearByUserId = [];
    $rosterYearByStudentNo = [];
    $studentNos = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $studentNo = trim((string) ($row['student_id'] ?? ''));
        if ($studentNo !== '') {
            $studentNos[$studentNo] = true;
        }
    }

    $applyRosterYear = static function (array $rosterRow) use (&$rosterYearByUserId, &$rosterYearByStudentNo): void {
        $year = (int) ($rosterRow['year_level'] ?? 0);
        if ($year < 1 || $year > 4) {
            return;
        }
        $uid = trim((string) ($rosterRow['user_id'] ?? ''));
        $no = trim((string) ($rosterRow['student_no'] ?? ''));
        $yearKey = (string) $year;
        if ($uid !== '') {
            $rosterYearByUserId[$uid] = $yearKey;
        }
        if ($no !== '') {
            $rosterYearByStudentNo[$no] = $yearKey;
        }
    };

    if (count($studentIds) > 0) {
        $rosterByUserUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/student_roster'
            . '?select=user_id,student_no,year_level'
            . '&user_id=in.(' . implode(',', array_map('rawurlencode', $studentIds)) . ')';
        $rosterByUserRes = supabase_request('GET', $rosterByUserUrl, $headers);
        if ($rosterByUserRes['ok']) {
            $rosterRows = json_decode((string) ($rosterByUserRes['body'] ?? ''), true);
            if (is_array($rosterRows)) {
                foreach ($rosterRows as $rosterRow) {
                    if (is_array($rosterRow)) {
                        $applyRosterYear($rosterRow);
                    }
                }
            }
        }
    }
    if (count($studentNos) > 0) {
        $rosterByNoUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/student_roster'
            . '?select=user_id,student_no,year_level'
            . '&student_no=in.(' . implode(',', array_map('rawurlencode', array_keys($studentNos))) . ')';
        $rosterByNoRes = supabase_request('GET', $rosterByNoUrl, $headers);
        if ($rosterByNoRes['ok']) {
            $rosterRows = json_decode((string) ($rosterByNoRes['body'] ?? ''), true);
            if (is_array($rosterRows)) {
                foreach ($rosterRows as $rosterRow) {
                    if (is_array($rosterRow)) {
                        $applyRosterYear($rosterRow);
                    }
                }
            }
        }
    }

    $sectionNameById = [];
    if (count($sectionIds) > 0) {
        $sectionsUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/sections'
            . '?select=id,name'
            . '&id=in.(' . implode(',', array_map('rawurlencode', array_keys($sectionIds))) . ')';
        $sectionsRes = supabase_request('GET', $sectionsUrl, $headers);
        if ($sectionsRes['ok']) {
            $sectionRows = json_decode((string) $sectionsRes['body'], true);
            if (is_array($sectionRows)) {
                foreach ($sectionRows as $sectionRow) {
                    if (!is_array($sectionRow)) {
                        continue;
                    }
                    $sid = trim((string) ($sectionRow['id'] ?? ''));
                    if ($sid === '') {
                        continue;
                    }
                    $sectionNameById[$sid] = trim((string) ($sectionRow['name'] ?? ''));
                }
            }
        }
    }

    $map = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }

        $userId = trim((string) ($row['id'] ?? ''));
        if ($userId === '') {
            continue;
        }

        $parts = array_values(array_filter([
            trim((string) ($row['first_name'] ?? '')),
            trim((string) ($row['middle_name'] ?? '')),
            trim((string) ($row['last_name'] ?? '')),
        ], static fn (string $value): bool => $value !== ''));
        $name = trim(implode(' ', $parts));
        $suffix = trim((string) ($row['suffix'] ?? ''));
        if ($suffix !== '') {
            $name = $name === '' ? $suffix : $name . ' ' . $suffix;
        }

        $sectionId = trim((string) ($row['section_id'] ?? ''));
        $sectionName = $sectionId !== '' ? trim((string) ($sectionNameById[$sectionId] ?? '')) : '';
        if ($sectionName === '') {
            $sectionName = trim((string) ($row['course'] ?? ''));
        }
        $meta = feedback_parse_section_meta($sectionName);
        $studentNo = trim((string) ($row['student_id'] ?? ''));
        $yearKey = (string) ($rosterYearByUserId[$userId] ?? '');
        if ($yearKey === '' && $studentNo !== '') {
            $yearKey = (string) ($rosterYearByStudentNo[$studentNo] ?? '');
        }
        if ($yearKey === '') {
            $yearKey = (string) ($meta['year_key'] ?? '');
        }

        $map[$userId] = [
            'name' => $name !== '' ? $name : ($studentNo !== '' ? $studentNo : $userId),
            'student_no' => $studentNo !== '' ? $studentNo : '—',
            'section_name' => $sectionName,
            'year_key' => $yearKey,
            'year_level' => feedback_year_label($yearKey),
            'block' => (string) $meta['block'],
        ];
    }

    return $map;
}

/** Compatibility helper used by older call sites expecting name-only map. */
function feedback_name_map(array $studentIds, array $headers): array
{
    $full = feedback_respondent_map($studentIds, $headers);
    $map = [];
    foreach ($full as $id => $row) {
        $map[$id] = (string) ($row['name'] ?? $id);
    }
    return $map;
}

function feedback_section_summary(
    string $label,
    string $description,
    array $questions,
    array $participantIds,
    array $answerRows,
    array $respondentMap
): array {
    $participantIds = array_values(array_unique(array_filter(array_map(
        static fn ($id): string => trim((string) $id),
        $participantIds
    ))));
    $participantLookup = array_fill_keys($participantIds, true);

    $answeredIds = [];
    foreach ($answerRows as $row) {
        $studentId = trim((string) ($row['student_id'] ?? ''));
        if ($studentId !== '' && isset($participantLookup[$studentId])) {
            $answeredIds[$studentId] = true;
        }
    }
    $answeredIdList = array_keys($answeredIds);

    $totalParticipants = count($participantIds);
    $totalResponses = count($answeredIdList);
    $pendingParticipants = max(0, $totalParticipants - $totalResponses);
    $responseRate = $totalParticipants > 0 ? round(($totalResponses / $totalParticipants) * 100, 1) : 0.0;

    $studentIds = [];
    $names = [];
    $blocks = [];
    $yearDist = [
        '1st Year' => 0,
        '2nd Year' => 0,
        '3rd Year' => 0,
        '4th Year' => 0,
    ];

    foreach ($answeredIdList as $userId) {
        $profile = $respondentMap[$userId] ?? [
            'name' => $userId,
            'student_no' => '—',
            'year_level' => '',
            'block' => 'Unassigned',
        ];
        $studentIds[] = (string) ($profile['student_no'] ?? '—');
        $names[] = (string) ($profile['name'] ?? $userId);
        $blockLabel = trim((string) ($profile['block'] ?? 'Unassigned'));
        if ($blockLabel === '') {
            $blockLabel = 'Unassigned';
        }
        $blocks[$blockLabel] = true;
        $yearLabel = (string) ($profile['year_level'] ?? '');
        if (isset($yearDist[$yearLabel])) {
            $yearDist[$yearLabel]++;
        }
    }

    $uniqueBlocks = array_keys($blocks);
    natcasesort($uniqueBlocks);
    $uniqueBlocks = array_values($uniqueBlocks);

    $ratingAnalytics = [];
    $comments = [];
    $suggestions = [];
    $otherText = [];

    foreach ($questions as $question) {
        $questionId = trim((string) ($question['id'] ?? ''));
        if ($questionId === '') {
            continue;
        }

        $questionText = trim((string) ($question['question_text'] ?? ''));
        $fieldType = (string) ($question['field_type'] ?? 'text');
        if ($fieldType === 'rating') {
            $distribution = ['1' => 0, '2' => 0, '3' => 0, '4' => 0, '5' => 0];
            $sum = 0;
            $count = 0;

            foreach ($answerRows as $row) {
                $studentId = trim((string) ($row['student_id'] ?? ''));
                if ($studentId === '' || !isset($participantLookup[$studentId])) {
                    continue;
                }
                if ((string) ($row['question_id'] ?? '') !== $questionId) {
                    continue;
                }

                $value = (int) ((string) ($row['answer_text'] ?? ''));
                if ($value < 1 || $value > 5) {
                    continue;
                }

                $distribution[(string) $value]++;
                $sum += $value;
                $count++;
            }

            $bars = [];
            foreach ($distribution as $score => $scoreCount) {
                $pct = $count > 0 ? round(($scoreCount / $count) * 100, 1) : 0.0;
                $bars[] = [
                    'score' => (string) $score,
                    'count' => $scoreCount,
                    'percent' => $pct,
                ];
            }

            $ratingAnalytics[] = [
                'question_text' => $questionText,
                'avg' => $count > 0 ? round($sum / $count, 1) : 0,
                'count' => $count,
                'dist' => $distribution,
                'bars' => $bars,
                'max_count' => max(1, max(array_values($distribution))),
            ];
            continue;
        }

        $responses = [];
        foreach ($answerRows as $row) {
            $studentId = trim((string) ($row['student_id'] ?? ''));
            if ($studentId === '' || !isset($participantLookup[$studentId])) {
                continue;
            }
            if ((string) ($row['question_id'] ?? '') !== $questionId) {
                continue;
            }

            $answerText = trim((string) ($row['answer_text'] ?? ''));
            if ($answerText === '') {
                continue;
            }

            $profile = $respondentMap[$studentId] ?? [];
            $responses[] = [
                'student_name' => (string) ($profile['name'] ?? $studentId),
                'student_no' => (string) ($profile['student_no'] ?? '—'),
                'answer_text' => $answerText,
            ];
        }

        if (count($responses) === 0) {
            continue;
        }

        $bucket = [
            'question_text' => $questionText,
            'responses' => $responses,
            'count' => count($responses),
        ];
        $lower = strtolower($questionText);
        if (str_contains($lower, 'suggest')) {
            $suggestions[] = $bucket;
        } elseif (str_contains($lower, 'comment')) {
            $comments[] = $bucket;
        } else {
            $otherText[] = $bucket;
        }
    }

    // Keep unlabeled text responses under Comments for visibility.
    foreach ($otherText as $bucket) {
        $comments[] = $bucket;
    }

    return [
        'label' => $label,
        'description' => $description,
        'total_participants' => $totalParticipants,
        'total_responses' => $totalResponses,
        'pending_participants' => $pendingParticipants,
        'response_rate' => $responseRate,
        'year_level_dist' => $yearDist,
        'student_ids' => $studentIds,
        'names' => $names,
        'blocks' => $uniqueBlocks,
        'rating_analytics' => $ratingAnalytics,
        'comments' => $comments,
        'suggestions' => $suggestions,
        'text_feedback' => array_merge($comments, $suggestions),
        'has_feedback' => $totalResponses > 0,
    ];
}



/**
 * @param array<int, array<string, mixed>> $sessions
 * @param array<int, array<string, mixed>> $eventQuestions
 * @param array<string, array<string, array<int, array<string, mixed>>>> $sessionQuestionGroups
 * @return list<array<string, mixed>>
 */
function evaluation_feedback_load_sections(
    string $eventId,
    array $headers,
    array $sessions,
    bool $usesSessions,
    array $eventQuestions,
    array $sessionQuestionGroups
): array {
    $feedbackSections = [];
    $eventQuestions = evaluation_sort_questions_by_type($eventQuestions);
if ($usesSessions) {
        $sessionIds = [];
        foreach ($sessions as $session) {
            $sid = (string) ($session['id'] ?? '');
            if ($sid !== '') {
                $sessionIds[] = $sid;
            }
        }

        $attendanceRows = [];
        if (count($sessionIds) > 0) {
            $ticketToStudent = feedback_ticket_student_map($eventId, $headers);
            $sessionFilter = implode(',', array_map('rawurlencode', $sessionIds));

            $attendanceUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/event_session_attendance'
                . '?select=session_id,status,check_in_at,registration:event_registrations(student_id)'
                . '&session_id=in.(' . $sessionFilter . ')';
            $attendanceRes = supabase_request('GET', $attendanceUrl, $headers);
            $attendanceRows = $attendanceRes['ok'] ? json_decode((string) $attendanceRes['body'], true) : [];
            $attendanceRows = is_array($attendanceRows) ? $attendanceRows : [];

            $legacyAttendanceUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/attendance'
                . '?select=session_id,ticket_id,status,check_in_at'
                . '&session_id=in.(' . $sessionFilter . ')';
            $legacyAttendanceRes = supabase_request('GET', $legacyAttendanceUrl, $headers);
            $legacyAttendanceRows = $legacyAttendanceRes['ok'] ? json_decode((string) $legacyAttendanceRes['body'], true) : [];
            $legacyAttendanceRows = is_array($legacyAttendanceRows) ? $legacyAttendanceRows : [];

            foreach ($legacyAttendanceRows as $legacyRow) {
                if (!is_array($legacyRow)) {
                    continue;
                }

                $ticketId = trim((string) ($legacyRow['ticket_id'] ?? ''));
                $studentId = $ticketId !== '' ? trim((string) ($ticketToStudent[$ticketId] ?? '')) : '';
                if ($studentId === '') {
                    continue;
                }

                $attendanceRows[] = [
                    'session_id' => $legacyRow['session_id'] ?? null,
                    'status' => $legacyRow['status'] ?? null,
                    'check_in_at' => $legacyRow['check_in_at'] ?? null,
                    'student_id' => $studentId,
                ];
            }
        }

        $presentBySession = [];
        $eventParticipantIds = [];
        foreach ($attendanceRows as $row) {
            if (!is_array($row) || !feedback_attendance_counts_as_present($row)) {
                continue;
            }

            $sid = trim((string) ($row['session_id'] ?? ''));
            $registration = isset($row['registration']) && is_array($row['registration']) ? $row['registration'] : [];
            $studentId = trim((string) ($row['student_id'] ?? ''));
            if ($studentId === '') {
                $studentId = trim((string) ($registration['student_id'] ?? ''));
            }
            if ($sid === '' || $studentId === '') {
                continue;
            }

            if (!isset($presentBySession[$sid])) {
                $presentBySession[$sid] = [];
            }
            $presentBySession[$sid][$studentId] = true;
            $eventParticipantIds[$studentId] = true;
        }

        $eventParticipantIds = array_values(array_keys($eventParticipantIds));
        $respondentMap = feedback_respondent_map($eventParticipantIds, $headers);

        $eventAnswerRows = [];
        if (count($eventQuestions) > 0) {
            $eventAnswersUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/evaluation_answers'
                . '?select=student_id,question_id,answer_text'
                . '&event_id=eq.' . rawurlencode($eventId);
            $eventAnswersRes = supabase_request('GET', $eventAnswersUrl, $headers);
            $eventAnswerRows = $eventAnswersRes['ok'] ? json_decode((string) $eventAnswersRes['body'], true) : [];
            $eventAnswerRows = is_array($eventAnswerRows) ? $eventAnswerRows : [];
        }

        $feedbackSections[] = feedback_section_summary(
            'Event Feedback',
            count($eventQuestions) > 0
                ? 'Responses to the whole-event evaluation.'
                : 'No event-level questions have been created yet.',
            $eventQuestions,
            $eventParticipantIds,
            $eventAnswerRows,
            $respondentMap
        );

        $sessionAnswerRows = [];
        if (count($sessionIds) > 0) {
            $sessionAnswersUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/event_session_evaluation_answers'
                . '?select=session_id,student_id,question_id,answer_text'
                . '&session_id=in.(' . implode(',', array_map('rawurlencode', $sessionIds)) . ')';
            $sessionAnswersRes = supabase_request('GET', $sessionAnswersUrl, $headers);
            if ($sessionAnswersRes['ok']) {
                $sessionAnswerRows = json_decode((string) $sessionAnswersRes['body'], true);
            } elseif (feedback_missing_table($sessionAnswersRes, 'event_session_evaluation_answers')) {
                $sessionAnswerRows = [];
            }
            $sessionAnswerRows = is_array($sessionAnswerRows) ? $sessionAnswerRows : [];
        }

        $answersBySession = [];
        foreach ($sessionAnswerRows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $sid = trim((string) ($row['session_id'] ?? ''));
            if ($sid === '') {
                continue;
            }
            if (!isset($answersBySession[$sid])) {
                $answersBySession[$sid] = [];
            }
            $answersBySession[$sid][] = $row;
        }

        foreach ($sessions as $session) {
            $sid = (string) ($session['id'] ?? '');
            $questionGroups = $sessionQuestionGroups[$sid] ?? [];
            $sessionQuestionList = [];
            foreach ($questionGroups as $items) {
                foreach ($items as $item) {
                    $sessionQuestionList[] = $item;
                }
            }
            $sessionQuestionList = evaluation_sort_questions_by_type($sessionQuestionList);

            $feedbackSections[] = feedback_section_summary(
                build_session_display_name($session),
                count($sessionQuestionList) > 0
                    ? 'Responses for this seminar only.'
                    : 'No seminar questions have been created yet for this session.',
                $sessionQuestionList,
                array_values(array_keys($presentBySession[$sid] ?? [])),
                $answersBySession[$sid] ?? [],
                $respondentMap
            );
        }
    } else {
        $attendanceUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/attendance'
            . '?select=status,check_in_at,tickets(registration_id,event_registrations(student_id,event_id))'
            . '&tickets.event_registrations.event_id=eq.' . rawurlencode($eventId);
        $attendanceRes = supabase_request('GET', $attendanceUrl, $headers);
        $attendanceRows = $attendanceRes['ok'] ? json_decode((string) $attendanceRes['body'], true) : [];
        $attendanceRows = is_array($attendanceRows) ? $attendanceRows : [];

        $participantIds = [];
        foreach ($attendanceRows as $row) {
            if (!is_array($row) || !feedback_attendance_counts_as_present($row)) {
                continue;
            }

            $ticket = isset($row['tickets']) && is_array($row['tickets']) ? $row['tickets'] : [];
            $registration = isset($ticket['event_registrations']) && is_array($ticket['event_registrations'])
                ? $ticket['event_registrations']
                : [];
            $studentId = trim((string) ($registration['student_id'] ?? ''));
            if ($studentId !== '') {
                $participantIds[$studentId] = true;
            }
        }

        $participantIds = array_values(array_keys($participantIds));
        $respondentMap = feedback_respondent_map($participantIds, $headers);

        $answerRows = [];
        if (count($eventQuestions) > 0) {
            $answersUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/evaluation_answers'
                . '?select=student_id,question_id,answer_text'
                . '&event_id=eq.' . rawurlencode($eventId);
            $answersRes = supabase_request('GET', $answersUrl, $headers);
            $answerRows = $answersRes['ok'] ? json_decode((string) $answersRes['body'], true) : [];
            $answerRows = is_array($answerRows) ? $answerRows : [];
        }

        $feedbackSections[] = feedback_section_summary(
            'Event Feedback',
            count($eventQuestions) > 0
                ? 'Responses to the whole-event evaluation.'
                : 'No event-level questions have been created yet.',
            $eventQuestions,
            $participantIds,
            $answerRows,
            $respondentMap
        );
    }
    return $feedbackSections;
}

/**
 * @param array<string, mixed> $section
 * @return array<string, mixed>
 */
function evaluation_feedback_section_view_data(array $section): array
{
    $responseCount = (int) ($section['total_responses'] ?? 0);
    $yearDist = is_array($section['year_level_dist'] ?? null) ? $section['year_level_dist'] : [];
    $yearColors = [
        '1st Year' => '#4285F4',
        '2nd Year' => '#EA4335',
        '3rd Year' => '#FBBC04',
        '4th Year' => '#34A853',
    ];
    $yearKnownTotal = 0;
    foreach ($yearDist as $yearCount) {
        $yearKnownTotal += (int) $yearCount;
    }
    $pieBase = $yearKnownTotal > 0 ? $yearKnownTotal : $responseCount;
    $pieSegments = [];
    foreach ($yearDist as $yearLabel => $yearCount) {
        $yearCount = (int) $yearCount;
        if ($yearCount <= 0 || $pieBase <= 0) {
            continue;
        }
        $pct = ($yearCount / $pieBase) * 100;
        $pieSegments[] = [
            'label' => (string) $yearLabel,
            'count' => $yearCount,
            'percent' => round($pct, 1),
            'color' => $yearColors[$yearLabel] ?? '#34A853',
        ];
    }
    $pieChart = feedback_pie_svg_paths($pieSegments);
    $yearLegend = [];
    foreach ($yearColors as $yearLabel => $yearColor) {
        $yearLegend[] = [
            'label' => $yearLabel,
            'color' => $yearColor,
            'count' => (int) ($yearDist[$yearLabel] ?? 0),
        ];
    }

    return [
        'section' => $section,
        'responseCount' => $responseCount,
        'yearDist' => $yearDist,
        'piePaths' => $pieChart['paths'] ?? [],
        'pieLabels' => $pieChart['labels'] ?? [],
        'uniqueBlocks' => is_array($section['blocks'] ?? null) ? $section['blocks'] : [],
        'studentIdsList' => is_array($section['student_ids'] ?? null) ? $section['student_ids'] : [],
        'namesList' => is_array($section['names'] ?? null) ? $section['names'] : [],
        'yearLegend' => $yearLegend,
    ];
}

function feedback_rating_y_max(int $maxCount): int
{
    $maxCount = max(0, $maxCount);
    if ($maxCount <= 0) {
        return 1;
    }
    if ($maxCount <= 5) {
        return $maxCount;
    }
    $mag = (int) pow(10, max(0, (int) floor(log10($maxCount))));
    return (int) (ceil($maxCount / $mag) * $mag);
}

function feedback_rating_format_percent(float $pct): string
{
    $rounded = round($pct, 1);
    if (abs($rounded - (int) $rounded) < 0.01) {
        return (string) (int) $rounded;
    }

    return number_format($rounded, 1);
}

/**
 * Google Forms–style rating bar chart (pure SVG — screen + print safe).
 *
 * @param list<array{score?:string,count?:int,percent?:float|int}> $bars
 */
function feedback_rating_bar_chart_svg(array $bars, int $yMax): string
{
    $width = 560;
    $height = 280;
    $padL = 36;
    $padR = 12;
    $padT = 44;
    $padB = 44;
    $plotW = $width - $padL - $padR;
    $plotH = $height - $padT - $padB;
    $barSlots = max(1, count($bars));
    $slotW = $plotW / $barSlots;
    $barW = min(48, $slotW * 0.55);
    $baseY = $padT + $plotH;

    $parts = [
        sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 %d %d" width="%d" height="%d" class="feedback-bar-chart feedback-export-bar-chart" overflow="visible" role="img" aria-label="Rating distribution chart">',
            $width,
            $height,
            $width,
            $height
        ),
    ];

    $ticks = [];
    for ($t = 0; $t <= 4; $t++) {
        $ticks[] = (int) round(($yMax / 4) * $t);
    }
    $ticks = array_values(array_unique($ticks));

    foreach ($ticks as $tick) {
        $y = $baseY - ($yMax > 0 ? ($tick / $yMax) * $plotH : 0);
        if ($tick > 0) {
            $parts[] = sprintf(
                '<line x1="%d" y1="%.1f" x2="%d" y2="%.1f" stroke="#f4f4f5" stroke-width="1"/>',
                $padL,
                $y,
                $padL + $plotW,
                $y
            );
        }
        $parts[] = sprintf(
            '<text x="%d" y="%.1f" text-anchor="end" dominant-baseline="middle" fill="#a1a1aa" font-size="11" font-family="system-ui,sans-serif">%d</text>',
            $padL - 6,
            $y,
            $tick
        );
    }

    $parts[] = sprintf(
        '<line x1="%d" y1="%.1f" x2="%d" y2="%.1f" stroke="#d4d4d8" stroke-width="1.5"/>',
        $padL,
        $baseY,
        $padL + $plotW,
        $baseY
    );

    foreach ($bars as $i => $bar) {
        $count = (int) ($bar['count'] ?? 0);
        $pct = (float) ($bar['percent'] ?? 0);
        $score = htmlspecialchars((string) ($bar['score'] ?? ''), ENT_QUOTES, 'UTF-8');
        $cx = $padL + ($i + 0.5) * $slotW;
        $barH = $yMax > 0 ? ($count / $yMax) * $plotH : 0.0;
        $x = $cx - ($barW / 2);
        $y = $baseY - $barH;

        if ($count > 0 && $barH > 0) {
            $parts[] = sprintf(
                '<rect x="%.1f" y="%.1f" width="%.1f" height="%.1f" fill="#7f1d1d" rx="2"/>',
                $x,
                $y,
                $barW,
                $barH
            );
            $labelText = $count . ' (' . feedback_rating_format_percent($pct) . '%)';
            $labelY = max(14, $y - 6);
            $parts[] = sprintf(
                '<text x="%.1f" y="%.1f" text-anchor="middle" fill="#18181b" font-size="12" font-weight="700" font-family="system-ui,sans-serif">%s</text>',
                $cx,
                $labelY,
                htmlspecialchars($labelText, ENT_QUOTES, 'UTF-8')
            );
        }

        $parts[] = sprintf(
            '<text x="%.1f" y="%.1f" text-anchor="middle" fill="#52525b" font-size="12" font-weight="600" font-family="system-ui,sans-serif">%s</text>',
            $cx,
            $baseY + 18,
            $score
        );
    }

    $parts[] = sprintf(
        '<text x="%d" y="%d" fill="#71717a" font-size="11" font-family="system-ui,sans-serif">Poor</text>',
        $padL,
        $height - 8
    );
    $parts[] = sprintf(
        '<text x="%d" y="%d" text-anchor="end" fill="#71717a" font-size="11" font-family="system-ui,sans-serif">Outstanding</text>',
        $padL + $plotW,
        $height - 8
    );
    $parts[] = '</svg>';

    return implode('', $parts);
}
