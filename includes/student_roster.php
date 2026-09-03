<?php
declare(strict_types=1);

/**
 * School student roster helpers (CSV import + mobile claim).
 * All DB access must use service role — table is locked from anon.
 */

if (!function_exists('student_roster_normalize_no')) {
    function student_roster_normalize_no(string $raw): string
    {
        $s = strtoupper(trim($raw));
        // Keep digits + letters + hyphen (school format 231-1022); drop spaces/other junk.
        $s = preg_replace('/[^A-Z0-9\-]+/', '', $s) ?? $s;
        return $s;
    }
}

if (!function_exists('student_roster_digits_key')) {
    /** Digits-only key so 231-1022 and 2311022 match. */
    function student_roster_digits_key(string $raw): string
    {
        return preg_replace('/\D+/', '', strtoupper(trim($raw))) ?? '';
    }
}

if (!function_exists('student_roster_parse_full_name')) {
    /**
     * PH-friendly: "LAST, FIRST MIDDLE" or "FIRST MIDDLE LAST".
     *
     * @return array{first_name:string,middle_name:string,last_name:string,suffix:string}
     */
    function student_roster_parse_full_name(string $raw): array
    {
        $raw = trim(preg_replace('/\s+/', ' ', $raw) ?? $raw);
        $suffixRe = '(JR\.?|SR\.?|II|III|IV|V)';
        $suffixes = ['JR', 'JR.', 'SR', 'SR.', 'II', 'III', 'IV', 'V'];

        $normSuffix = static function (string $tok): string {
            return rtrim(strtoupper(trim($tok)), '.');
        };

        $pack = static function (string $first, string $middle, string $last, string $suffix): array {
            return [
                'first_name' => trim($first),
                'middle_name' => trim($middle),
                'last_name' => trim($last),
                'suffix' => trim($suffix),
            ];
        };

        $splitGiven = static function (string $rest): array {
            $restParts = preg_split('/\s+/', trim($rest)) ?: [];
            $first = (string) ($restParts[0] ?? '');
            $middle = trim(implode(' ', array_slice($restParts, 1)));
            return [$first, $middle];
        };

        // LAST, JR, FIRST MIDDLE  (BSCS: "BASCA,JR, SANDRO")
        if (preg_match('/^(.+?),\s*' . $suffixRe . '\s*,\s*(.+)$/iu', $raw, $m)) {
            [$first, $middle] = $splitGiven((string) $m[3]);
            return $pack($first, $middle, (string) $m[1], $normSuffix((string) $m[2]));
        }

        $suffix = '';
        $parts = preg_split('/\s+/', $raw) ?: [];
        if ($parts !== []) {
            $lastTok = strtoupper(rtrim((string) end($parts), ','));
            if (in_array($lastTok, $suffixes, true)) {
                $suffix = $normSuffix((string) array_pop($parts));
                $raw = trim(implode(' ', $parts));
            }
        }

        if (str_contains($raw, ',')) {
            [$last, $rest] = array_pad(array_map('trim', explode(',', $raw, 2)), 2, '');
            if (preg_match('/^(.+?)\s+' . $suffixRe . '\.?$/iu', $last, $sm)) {
                $last = trim((string) $sm[1]);
                $suffix = $normSuffix((string) $sm[2]);
            }
            [$first, $middle] = $splitGiven($rest);
            return $pack($first, $middle, $last, $suffix);
        }

        $toks = preg_split('/\s+/', $raw) ?: [];
        if (count($toks) === 0) {
            return $pack('', '', '', $suffix);
        }
        if (count($toks) === 1) {
            return $pack($toks[0], '', $toks[0], $suffix);
        }
        if (count($toks) === 2) {
            return $pack($toks[0], '', $toks[1], $suffix);
        }
        $first = $toks[0];
        $last = $toks[count($toks) - 1];
        $middle = trim(implode(' ', array_slice($toks, 1, -1)));
        return $pack($first, $middle, $last, $suffix);
    }
}

if (!function_exists('student_roster_infer_from_sheet_name')) {
    /**
     * Sheet tabs like "BSCS-DS 1A" carry program + year/block (no Year/Block columns).
     *
     * @return array{program:string,year:?int,block:string}
     */
    function student_roster_infer_from_sheet_name(string $sheetName): array
    {
        $n = strtoupper(trim($sheetName));
        $compact = preg_replace('/[^A-Z0-9]+/', '', $n) ?? $n;
        $spaced = trim(preg_replace('/\s+/', ' ', preg_replace('/[^A-Z0-9]+/', ' ', $n) ?? $n) ?? $n);

        $program = '';
        if (preg_match('/BSIT.*BA|BA.*BSIT/', $compact) || preg_match('/\bBSIT\b.*\bBA\b/', $spaced)) {
            $program = 'BSIT BA';
        } elseif (preg_match('/BSIT.*SD|SD.*BSIT/', $compact) || preg_match('/\bBSIT\b.*\bSD\b/', $spaced)) {
            $program = 'BSIT SD';
        } elseif (str_contains($compact, 'BSCS') || str_contains($spaced, 'COMPUTER SCIENCE')) {
            $program = 'BSCS';
        }

        $year = null;
        $block = '';
        if (preg_match('/([1-4])([A-F])$/', $compact, $m)) {
            $year = (int) $m[1];
            $block = $m[1] . $m[2];
        }

        return [
            'program' => $program,
            'year' => $year,
            'block' => $block,
        ];
    }
}

if (!function_exists('student_roster_map_program')) {
    /**
     * @return array{course_code:string,program_label:string}|null
     */
    function student_roster_map_program(string $course, string $specialization): ?array
    {
        $c = strtoupper(trim($course));
        $s = strtoupper(trim($specialization));
        // Normalize separators: BSIT-BA / BSIT_BA / BSIT BA → BSIT BA
        $cNorm = preg_replace('/[\s_\-\/]+/', ' ', $c) ?? $c;
        $cNorm = trim($cNorm);
        $sNorm = preg_replace('/[\s_\-\/]+/', ' ', $s) ?? $s;
        $sNorm = trim(preg_replace('/\s+/', ' ', $sNorm) ?? $sNorm);
        $blob = trim($cNorm . ' ' . $sNorm);

        // Already a full program label in Course (or Course+Spec).
        if (preg_match('/\bBSIT\s*SD\b/', $blob) || preg_match('/\bSOFTWARE\s*DEV/', $blob)) {
            return ['course_code' => 'IT', 'program_label' => 'BSIT SD'];
        }
        if (preg_match('/\bBSIT\s*BA\b/', $blob) || preg_match('/\bBUSINESS\s*ANALYTIC/', $blob)) {
            return ['course_code' => 'IT', 'program_label' => 'BSIT BA'];
        }
        if (
            preg_match('/\bBSCS\b/', $blob)
            || $cNorm === 'CS'
            || str_contains($blob, 'COMPUTER SCIENCE')
            || $sNorm === 'BSCS'
        ) {
            return ['course_code' => 'CS', 'program_label' => 'BSCS'];
        }
        if ($cNorm === 'IT' || $cNorm === 'BSIT' || preg_match('/\bBSIT\b/', $blob)) {
            if (in_array($sNorm, ['BA', 'BUSINESS ANALYTICS', 'BUSINESS ANALYTIC'], true)) {
                return ['course_code' => 'IT', 'program_label' => 'BSIT BA'];
            }
            if (in_array($sNorm, ['SD', 'SOFTWARE DEVELOPMENT', 'SOFTWARE DEV'], true)) {
                return ['course_code' => 'IT', 'program_label' => 'BSIT SD'];
            }
            // Default BSIT track when specialization missing.
            return ['course_code' => 'IT', 'program_label' => 'BSIT SD'];
        }

        return null;
    }
}

if (!function_exists('student_roster_is_irregular_block')) {
    function student_roster_is_irregular_block(string $block): bool
    {
        $b = strtoupper(trim($block));
        $b = str_replace(' ', '', $b);
        return $b === '' || $b === '--' || $b === '—' || $b === '-' || $b === 'IRREGULAR' || $b === 'N/A' || $b === 'NA';
    }
}

if (!function_exists('student_roster_parse_block_letter')) {
    /**
     * Accept "A", "2B", "3C" → letter; null if unusable (caller may treat as irregular).
     */
    function student_roster_parse_block_letter(string $block): ?string
    {
        if (student_roster_is_irregular_block($block)) {
            return null;
        }
        $b = strtoupper(preg_replace('/\s+/', '', trim($block)) ?? '');
        if (preg_match('/^[A-F]$/', $b)) {
            return $b;
        }
        // School rosters often encode section as year+letter: 1A, 2B, 4C.
        if (preg_match('/^[1-4]([A-F])$/', $b, $m)) {
            return $m[1];
        }
        if (preg_match('/([A-F])$/', $b, $m) && strlen($b) <= 4) {
            return $m[1];
        }
        return null;
    }
}

if (!function_exists('student_roster_parse_year_level')) {
    /** Extract 1–4 from "1st", "Year 2", "2B", etc. */
    function student_roster_parse_year_level(string $yearRaw, string $blockRaw = ''): ?int
    {
        if (preg_match('/([1-4])/', $yearRaw, $ym)) {
            return (int) $ym[1];
        }
        $b = preg_replace('/\s+/', '', $blockRaw) ?? '';
        if (preg_match('/^([1-4])[A-F]$/i', $b, $bm)) {
            return (int) $bm[1];
        }
        return null;
    }
}

if (!function_exists('student_roster_format_year_label')) {
    /** Display like Excel: 1st / 2nd / 3rd / 4th */
    function student_roster_format_year_label(?int $year): string
    {
        return match ($year) {
            1 => '1st',
            2 => '2nd',
            3 => '3rd',
            4 => '4th',
            default => '—',
        };
    }
}

if (!function_exists('student_roster_format_block_label')) {
    /**
     * Display like Excel: 1A / 2B / --
     */
    function student_roster_format_block_label(?int $year, ?string $block, bool $irregular): string
    {
        if ($irregular || student_roster_is_irregular_block((string) $block)) {
            $raw = strtoupper(trim((string) $block));
            if ($raw === '' || $raw === '—' || $raw === '-' || $raw === 'IRREGULAR' || $raw === 'N/A' || $raw === 'NA') {
                return '--';
            }
            if ($raw === '--') {
                return '--';
            }
        }
        $b = strtoupper(preg_replace('/\s+/', '', trim((string) $block)) ?? '');
        if (preg_match('/^[1-4][A-F]$/', $b)) {
            return $b;
        }
        $letter = student_roster_parse_block_letter((string) $block);
        if ($year !== null && $letter !== null) {
            return $year . $letter;
        }
        if ($letter !== null) {
            return $letter;
        }
        return $b !== '' ? $b : '—';
    }
}

if (!function_exists('student_roster_section_name')) {
    function student_roster_section_name(string $programLabel, ?int $year, ?string $block, bool $irregular): string
    {
        if ($irregular) {
            return 'IRREGULAR';
        }
        $year = $year ?? 0;
        $blockLetter = strtoupper(trim((string) $block));
        if ($year < 1 || $year > 4 || !preg_match('/^[A-F]$/', $blockLetter)) {
            return 'IRREGULAR';
        }
        return trim($programLabel) . ' ' . $year . $blockLetter;
    }
}

if (!function_exists('student_roster_supabase_headers')) {
    /** @return list<string> */
    function student_roster_supabase_headers(bool $preferRepresentation = false): array
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
}

if (!function_exists('student_roster_ensure_section')) {
    /**
     * Find or create a section by exact name. Returns section id or ''.
     */
    function student_roster_ensure_section(string $name, array &$createdNames): string
    {
        $name = trim($name);
        if ($name === '') {
            return '';
        }
        $headers = student_roster_supabase_headers();
        $getUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/sections'
            . '?select=id,name,status&name=eq.' . rawurlencode($name) . '&limit=1';
        $getRes = supabase_request('GET', $getUrl, $headers);
        $rows = $getRes['ok'] ? json_decode((string) ($getRes['body'] ?? ''), true) : [];
        if (is_array($rows) && isset($rows[0]['id'])) {
            $id = trim((string) $rows[0]['id']);
            $status = strtolower(trim((string) ($rows[0]['status'] ?? 'active')));
            if ($status === 'archived') {
                supabase_request(
                    'PATCH',
                    rtrim(SUPABASE_URL, '/') . '/rest/v1/sections?id=eq.' . rawurlencode($id),
                    student_roster_supabase_headers(true),
                    json_encode(['status' => 'active'])
                );
            }
            return $id;
        }

        $postRes = supabase_request(
            'POST',
            rtrim(SUPABASE_URL, '/') . '/rest/v1/sections?select=id,name',
            student_roster_supabase_headers(true),
            json_encode([['name' => $name]], JSON_UNESCAPED_SLASHES)
        );
        $created = $postRes['ok'] ? json_decode((string) ($postRes['body'] ?? ''), true) : [];
        if (is_array($created) && isset($created[0]['id'])) {
            $createdNames[] = $name;
            return trim((string) $created[0]['id']);
        }
        // Race: unique name conflict — re-fetch.
        $getRes2 = supabase_request('GET', $getUrl, $headers);
        $rows2 = $getRes2['ok'] ? json_decode((string) ($getRes2['body'] ?? ''), true) : [];
        if (is_array($rows2) && isset($rows2[0]['id'])) {
            return trim((string) $rows2[0]['id']);
        }
        return '';
    }
}

if (!function_exists('student_roster_fetch_by_no')) {
    /**
     * @return array<string,mixed>|null
     */
    function student_roster_fetch_by_no(string $studentNo, bool $includeArchived = false): ?array
    {
        $studentNo = student_roster_normalize_no($studentNo);
        if ($studentNo === '') {
            return null;
        }
        $url = rtrim(SUPABASE_URL, '/') . '/rest/v1/student_roster'
            . '?select=id,student_no,full_name_raw,first_name,middle_name,last_name,suffix,'
            . 'course_code,program_label,year_level,block,is_irregular,section_id,user_id,archived_at'
            . '&student_no=eq.' . rawurlencode($studentNo)
            . ($includeArchived ? '' : '&archived_at=is.null')
            . '&limit=1';
        $res = supabase_request('GET', $url, student_roster_supabase_headers());
        $rows = $res['ok'] ? json_decode((string) ($res['body'] ?? ''), true) : [];
        if ((!is_array($rows) || $rows === []) && !$includeArchived && !$res['ok']) {
            $fallbackUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/student_roster'
                . '?select=id,student_no,full_name_raw,first_name,middle_name,last_name,suffix,'
                . 'course_code,program_label,year_level,block,is_irregular,section_id,user_id'
                . '&student_no=eq.' . rawurlencode($studentNo)
                . '&limit=1';
            $fallbackRes = supabase_request('GET', $fallbackUrl, student_roster_supabase_headers());
            $rows = $fallbackRes['ok'] ? json_decode((string) ($fallbackRes['body'] ?? ''), true) : [];
        }
        $row = is_array($rows) && isset($rows[0]) && is_array($rows[0]) ? $rows[0] : null;
        if ($row !== null && !$includeArchived && !empty($row['archived_at'])) {
            return null;
        }
        return $row;
    }
}

if (!function_exists('student_roster_public_preview')) {
    /**
     * Safe fields for Create Account preview (no internal ids beyond what's needed).
     *
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    function student_roster_public_preview(array $row): array
    {
        $parts = array_filter([
            trim((string) ($row['first_name'] ?? '')),
            trim((string) ($row['middle_name'] ?? '')),
            trim((string) ($row['last_name'] ?? '')),
        ], static fn ($p) => $p !== '');
        $name = trim(implode(' ', $parts));
        $suffix = trim((string) ($row['suffix'] ?? ''));
        if ($suffix !== '') {
            $name .= ', ' . $suffix;
        }
        if ($name === '') {
            $name = trim((string) ($row['full_name_raw'] ?? ''));
        }
        return [
            'student_no' => (string) ($row['student_no'] ?? ''),
            'name' => $name,
            'first_name' => (string) ($row['first_name'] ?? ''),
            'middle_name' => (string) ($row['middle_name'] ?? ''),
            'last_name' => (string) ($row['last_name'] ?? ''),
            'suffix' => (string) ($row['suffix'] ?? ''),
            'course_code' => (string) ($row['course_code'] ?? ''),
            'program_label' => (string) ($row['program_label'] ?? ''),
            'year_level' => isset($row['year_level']) ? (int) $row['year_level'] : null,
            'block' => (string) ($row['block'] ?? ''),
            'is_irregular' => !empty($row['is_irregular']),
            'section_label' => !empty($row['is_irregular'])
                ? 'IRREGULAR'
                : trim((string) ($row['program_label'] ?? '') . ' '
                    . (string) ($row['year_level'] ?? '')
                    . strtoupper(trim((string) ($row['block'] ?? '')))),
        ];
    }
}

if (!function_exists('student_user_is_incomplete_signup')) {
    /**
     * True when Create Account wrote the row but email OTP never succeeded.
     * These must remain claimable / resumable — not "already has an account."
     *
     * @param array<string,mixed> $user
     */
    function student_user_is_incomplete_signup(array $user): bool
    {
        if (!empty($user['email_verified'])) {
            return false;
        }
        $status = strtolower(trim((string) ($user['account_status'] ?? '')));
        // preverify = intended; pending/empty = legacy fallback when preverify
        // was rejected by DB check during insert.
        return in_array($status, ['preverify', 'pending', ''], true);
    }
}

if (!function_exists('student_roster_fetch_signup_user')) {
    /**
     * Load a users row used for roster claim / incomplete-signup checks.
     *
     * @return array<string,mixed>|null
     */
    function student_roster_fetch_signup_user(string $userId): ?array
    {
        $userId = trim($userId);
        if ($userId === '') {
            return null;
        }
        $url = rtrim(SUPABASE_URL, '/') . '/rest/v1/' . SUPABASE_TABLE_USERS
            . '?select=id,email,student_id,role,account_status,email_verified,registration_source,archived_at'
            . '&id=eq.' . rawurlencode($userId)
            . '&limit=1';
        $res = supabase_request('GET', $url, student_roster_supabase_headers());
        if (!($res['ok'] ?? false)) {
            return null;
        }
        $rows = json_decode((string) ($res['body'] ?? ''), true);
        return is_array($rows) && isset($rows[0]) && is_array($rows[0]) ? $rows[0] : null;
    }
}

if (!function_exists('student_roster_find_incomplete_by_student_no')) {
    /**
     * Incomplete Create Account row for this student number (OTP not done).
     *
     * @return array<string,mixed>|null
     */
    function student_roster_find_incomplete_by_student_no(string $studentNo): ?array
    {
        $studentNo = student_roster_normalize_no($studentNo);
        if ($studentNo === '') {
            return null;
        }
        $url = rtrim(SUPABASE_URL, '/') . '/rest/v1/' . SUPABASE_TABLE_USERS
            . '?select=id,email,student_id,role,account_status,email_verified,registration_source,archived_at'
            . '&student_id=eq.' . rawurlencode($studentNo)
            . '&role=eq.student'
            . '&limit=5';
        $res = supabase_request('GET', $url, student_roster_supabase_headers());
        if (!($res['ok'] ?? false)) {
            return null;
        }
        $rows = json_decode((string) ($res['body'] ?? ''), true);
        if (!is_array($rows)) {
            return null;
        }
        foreach ($rows as $row) {
            if (!is_array($row) || !empty($row['archived_at'])) {
                continue;
            }
            if (student_user_is_incomplete_signup($row)) {
                return $row;
            }
        }
        return null;
    }
}

if (!function_exists('student_roster_year_ordinal_label')) {
    function student_roster_year_ordinal_label(string $yearKey): string
    {
        return match (trim($yearKey)) {
            '1' => '1st Year',
            '2' => '2nd Year',
            '3' => '3rd Year',
            '4' => '4th Year',
            default => '',
        };
    }
}

if (!function_exists('student_roster_year_key_from_section')) {
    function student_roster_year_key_from_section(string $sectionName): string
    {
        $raw = trim($sectionName);
        if ($raw === '' || strcasecmp($raw, 'N/A') === 0 || strcasecmp($raw, 'IRREGULAR') === 0) {
            return '';
        }
        if (preg_match('/-([1-4])[A-Z]$/i', $raw, $m)) {
            return (string) $m[1];
        }
        if (preg_match('/\birreg(?:ular)?[\s\-]*([1-4])/i', $raw, $m)) {
            return (string) $m[1];
        }
        if (preg_match('/\b([1-4])[A-Z]\b/i', $raw, $m)) {
            return (string) $m[1];
        }
        if (preg_match('/\b([1-4])(?:st|nd|rd|th)?(?:\s*year)?\b/i', $raw, $m)) {
            return (string) $m[1];
        }
        return '';
    }
}

if (!function_exists('student_roster_fetch_year_maps')) {
    /**
     * @param list<string> $userIds
     * @param list<string> $studentNos
     * @return array{by_user_id: array<string,string>, by_student_no: array<string,string>}
     */
    function student_roster_fetch_year_maps(array $userIds, array $studentNos, array $headers): array
    {
        $userIds = array_values(array_unique(array_filter(array_map(
            static fn ($id): string => trim((string) $id),
            $userIds
        ), static fn (string $id): bool => $id !== '')));
        $studentNos = array_values(array_unique(array_filter(array_map(
            static fn ($no): string => trim((string) $no),
            $studentNos
        ), static fn (string $no): bool => $no !== '')));

        $byUserId = [];
        $byStudentNo = [];
        $apply = static function (array $row) use (&$byUserId, &$byStudentNo): void {
            $year = (int) ($row['year_level'] ?? 0);
            if ($year < 1 || $year > 4) {
                return;
            }
            $key = (string) $year;
            $uid = trim((string) ($row['user_id'] ?? ''));
            $no = trim((string) ($row['student_no'] ?? ''));
            if ($uid !== '') {
                $byUserId[$uid] = $key;
            }
            if ($no !== '') {
                $byStudentNo[$no] = $key;
            }
        };

        if ($userIds !== []) {
            $url = rtrim(SUPABASE_URL, '/') . '/rest/v1/student_roster'
                . '?select=user_id,student_no,year_level'
                . '&user_id=in.(' . implode(',', array_map('rawurlencode', $userIds)) . ')';
            $res = supabase_request('GET', $url, $headers);
            if ($res['ok']) {
                $rows = json_decode((string) ($res['body'] ?? ''), true);
                if (is_array($rows)) {
                    foreach ($rows as $row) {
                        if (is_array($row)) {
                            $apply($row);
                        }
                    }
                }
            }
        }
        if ($studentNos !== []) {
            $url = rtrim(SUPABASE_URL, '/') . '/rest/v1/student_roster'
                . '?select=user_id,student_no,year_level'
                . '&student_no=in.(' . implode(',', array_map('rawurlencode', $studentNos)) . ')';
            $res = supabase_request('GET', $url, $headers);
            if ($res['ok']) {
                $rows = json_decode((string) ($res['body'] ?? ''), true);
                if (is_array($rows)) {
                    foreach ($rows as $row) {
                        if (is_array($row)) {
                            $apply($row);
                        }
                    }
                }
            }
        }

        return [
            'by_user_id' => $byUserId,
            'by_student_no' => $byStudentNo,
        ];
    }
}

if (!function_exists('student_roster_resolve_year_key')) {
    /**
     * @param array{by_user_id?: array<string,string>, by_student_no?: array<string,string>} $maps
     */
    function student_roster_resolve_year_key(
        string $userId,
        string $studentNo,
        string $sectionName,
        array $maps
    ): string {
        $userId = trim($userId);
        $studentNo = trim($studentNo);
        $byUser = is_array($maps['by_user_id'] ?? null) ? $maps['by_user_id'] : [];
        $byNo = is_array($maps['by_student_no'] ?? null) ? $maps['by_student_no'] : [];
        if ($userId !== '' && isset($byUser[$userId]) && preg_match('/^[1-4]$/', (string) $byUser[$userId])) {
            return (string) $byUser[$userId];
        }
        if ($studentNo !== '' && isset($byNo[$studentNo]) && preg_match('/^[1-4]$/', (string) $byNo[$studentNo])) {
            return (string) $byNo[$studentNo];
        }
        return student_roster_year_key_from_section($sectionName);
    }
}
