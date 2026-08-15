<?php
declare(strict_types=1);

/**
 * Extract subject rows from a Laguna University Form No. 1 / Registration Form PDF.
 * PHP-only (no Composer). Tuned to the digital LU table:
 * No | Course Code | Course Description | Units | Day | Time | Room | Instructor
 */

function registration_form_utf8(string $value): string
{
    $value = str_replace("\x00", '', $value);
    if (function_exists('mb_convert_encoding')) {
        $converted = @mb_convert_encoding($value, 'UTF-8', 'UTF-8');
        if (is_string($converted) && $converted !== '') {
            $value = $converted;
        }
    } elseif (!preg_match('//u', $value)) {
        $value = preg_replace('/[\x80-\xFF]/', '', $value) ?? $value;
    }
    return trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $value) ?? $value);
}

function registration_form_pdf_utf16be(string $raw): string
{
    if (!function_exists('mb_convert_encoding')) {
        return '';
    }
    $converted = @mb_convert_encoding($raw, 'UTF-8', 'UTF-16BE');
    return is_string($converted) ? registration_form_utf8($converted) : '';
}

function registration_form_decode_pdf_literal(string $inner): string
{
    $inner = str_replace(
        ['\\\\', '\\n', '\\r', '\\t', '\\(', '\\)', '\\f', '\\b'],
        ['\\', "\n", "\r", "\t", '(', ')', "\f", "\x08"],
        $inner
    );
    $inner = preg_replace_callback('/\\\\([0-7]{1,3})/', static function (array $m): string {
        $n = (int) octdec($m[1]);
        return ($n >= 32 && $n <= 126) ? chr($n) : '';
    }, $inner) ?? $inner;
    if (str_starts_with($inner, "\xFE\xFF") || (strlen($inner) >= 2 && (ord($inner[0]) === 0 || ord($inner[1]) === 0))) {
        $converted = registration_form_pdf_utf16be($inner);
        if ($converted !== '') {
            return $converted;
        }
    }
    return registration_form_utf8($inner);
}

function registration_form_stream_looks_textual(string $decoded): bool
{
    if ($decoded === '') {
        return false;
    }
    $head = substr($decoded, 0, 8);
    if (str_starts_with($head, "\xFF\xD8") || str_starts_with($head, "\x89PNG") || str_starts_with($head, '%PDF')) {
        return false;
    }
    $sampleLen = min(strlen($decoded), 2500);
    $sample = substr($decoded, 0, $sampleLen);
    $printable = 0;
    for ($i = 0; $i < $sampleLen; $i++) {
        $c = ord($sample[$i]);
        if (($c >= 32 && $c <= 126) || $c === 10 || $c === 13 || $c === 9) {
            $printable++;
        }
    }
    return $printable >= (int) ($sampleLen * 0.35);
}

/**
 * Linear scan for PDF literal strings — avoids regex backtracking on image streams.
 *
 * @return list<string>
 */
function registration_form_collect_pdf_literals(string $decoded): array
{
    $texts = [];
    $len = strlen($decoded);
    $i = 0;
    while ($i < $len) {
        $ch = $decoded[$i];
        if ($ch === '(') {
            $i++;
            $buf = '';
            $depth = 1;
            while ($i < $len && $depth > 0 && strlen($buf) < 420) {
                $c = $decoded[$i];
                if ($c === '\\' && $i + 1 < $len) {
                    $buf .= $c . $decoded[$i + 1];
                    $i += 2;
                    continue;
                }
                if ($c === '(') {
                    $depth++;
                    $buf .= $c;
                    $i++;
                    continue;
                }
                if ($c === ')') {
                    $depth--;
                    if ($depth === 0) {
                        $i++;
                        break;
                    }
                    $buf .= $c;
                    $i++;
                    continue;
                }
                $buf .= $c;
                $i++;
            }
            $inner = registration_form_decode_pdf_literal($buf);
            if ($inner !== '' && strlen($inner) <= 400 && preg_match('/[A-Za-z0-9]/', $inner)) {
                $texts[] = preg_replace('/\s+/', ' ', $inner) ?? $inner;
            }
            continue;
        }
        $i++;
    }
    return $texts;
}

/**
 * @return list<string>
 */
function registration_form_pdf_extract_strings(string $binary): array
{
    $texts = [];
    $offset = 0;
    $len = strlen($binary);
    $streamCount = 0;
    while ($offset < $len && $streamCount < 80) {
        $pos = strpos($binary, 'stream', $offset);
        if ($pos === false) {
            break;
        }
        $after = $pos + 6;
        if ($after < $len && ($binary[$after] === "\r" || $binary[$after] === "\n")) {
            if ($binary[$after] === "\r" && ($after + 1) < $len && $binary[$after + 1] === "\n") {
                $after += 2;
            } else {
                $after++;
            }
        } else {
            $offset = $pos + 6;
            continue;
        }

        $dictLookback = substr($binary, max(0, $pos - 400), min(400, $pos));
        if (preg_match('/\/(Image|DCTDecode|JPXDecode|JBIG2Decode|CCITTFaxDecode)\b/', $dictLookback)) {
            $end = strpos($binary, 'endstream', $after);
            $offset = $end === false ? $len : $end + 9;
            continue;
        }

        $end = strpos($binary, 'endstream', $after);
        if ($end === false) {
            break;
        }
        $payload = substr($binary, $after, $end - $after);
        $payload = rtrim($payload, "\r\n");
        $streamCount++;
        if (strlen($payload) > 2_000_000) {
            $offset = $end + 9;
            continue;
        }
        $decoded = @gzuncompress($payload);
        if ($decoded === false) {
            $decoded = @gzinflate($payload);
        }
        if (is_string($decoded) && strlen($decoded) <= 1_500_000 && registration_form_stream_looks_textual($decoded)) {
            foreach (registration_form_collect_pdf_literals($decoded) as $t) {
                $texts[] = $t;
            }
        }
        $offset = $end + 9;
    }

    if ($texts === [] && strlen($binary) <= 2_000_000) {
        $texts = registration_form_collect_pdf_literals($binary);
    }

    return $texts;
}

function registration_form_normalize_day(string $raw): string
{
    $u = strtoupper(preg_replace('/[^A-Za-z]/', '', $raw) ?? '');
    return match ($u) {
        'M', 'MON', 'MONDAY' => 'M',
        'T', 'TUE', 'TUES', 'TUESDAY' => 'T',
        'W', 'WED', 'WEDNESDAY' => 'W',
        'TH', 'THU', 'THUR', 'THURS', 'THURSDAY' => 'Th',
        'F', 'FRI', 'FRIDAY' => 'F',
        'S', 'SA', 'SAT', 'SATURDAY' => 'S',
        'SU', 'SUN', 'SUNDAY' => 'Su',
        default => '',
    };
}

/**
 * @return list<string>
 */
function registration_form_expand_day_token(string $raw): array
{
    $u = strtoupper(preg_replace('/[\s\/,]+/', '', $raw) ?? '');
    if ($u === '') {
        return [];
    }
    $single = registration_form_normalize_day($u);
    if ($single !== '') {
        return [$single];
    }
    $combo = match ($u) {
        'MW' => ['M', 'W'],
        'TTH', 'TTHU' => ['T', 'Th'],
        'WF' => ['W', 'F'],
        'MWF' => ['M', 'W', 'F'],
        'MTWTHF', 'MTWTHFS' => ['M', 'T', 'W', 'Th', 'F'],
        default => [],
    };
    if ($combo !== []) {
        return $combo;
    }
    $out = [];
    $rest = $u;
    while ($rest !== '') {
        if (str_starts_with($rest, 'TH')) {
            $out[] = 'Th';
            $rest = substr($rest, 2);
            continue;
        }
        if (str_starts_with($rest, 'SU')) {
            $out[] = 'Su';
            $rest = substr($rest, 2);
            continue;
        }
        $one = registration_form_normalize_day($rest[0]);
        if ($one === '') {
            return [];
        }
        $out[] = $one;
        $rest = substr($rest, 1);
    }
    return $out;
}

function registration_form_is_day_token(string $raw): bool
{
    return registration_form_expand_day_token($raw) !== [];
}

function registration_form_is_time_token(string $raw): bool
{
    $t = strtolower(trim($raw));
    $t = str_replace([' ', '.'], '', $t);
    return (bool) preg_match('/^\d{1,2}:\d{2}-\d{1,2}:\d{2}(?:[ap]m)?$/', $t);
}

function registration_form_is_room_token(string $raw): bool
{
    $t = strtoupper(trim($raw));
    if ($t === '') {
        return false;
    }
    if (in_array($t, ['OL', 'ONLINE', 'TBA', 'HYBRID', 'HYB', 'TBD', 'VIRTUAL'], true)) {
        return true;
    }
    if (preg_match('/^(AV|RM|ROOM|LAB|CL|Bldg|BLDG)\b/', $t)) {
        return true;
    }
    // "AV 408a" / "408a" style rooms — trailing letter after 2–4 digits.
    if (preg_match('/^[A-Z]{1,3}\s*\d{2,4}[A-Z]$/', $t)) {
        return true;
    }
    return false;
}

function registration_form_is_course_code(string $raw): bool
{
    $t = strtoupper(trim($raw));
    if (registration_form_is_room_token($t)) {
        return false;
    }
    return (bool) preg_match('/^[A-Z]{2,6}\s*-?\s*\d{1,5}[A-Z]?$/', $t);
}

function registration_form_is_units_token(string $raw): bool
{
    $t = trim($raw);
    if (!preg_match('/^\d(?:\.\d)?$/', $t)) {
        return false;
    }
    $n = (float) $t;
    return $n >= 0 && $n <= 12;
}

function registration_form_is_instructor_token(string $raw): bool
{
    $t = trim($raw);
    if ($t === '' || strlen($t) > 80) {
        return false;
    }
    if (registration_form_is_room_token($t)
        || registration_form_is_course_code($t)
        || registration_form_is_time_token($t)
        || registration_form_is_day_token($t)
        || registration_form_is_units_token($t)
    ) {
        return false;
    }
    // "E. Bergonio", "K. San Jose", "BERGONIO, E."
    return (bool) preg_match('/^[A-Za-z][A-Za-z.\-\' ]{1,}$/', $t)
        && preg_match('/[A-Za-z]{2,}/', $t)
        && (str_contains($t, '.') || str_contains($t, ' ') || str_contains($t, ',') || strlen($t) >= 5);
}

/**
 * @param list<string> $tokens
 * @return list<array{
 *   course_code:string,
 *   course_description:string,
 *   instructor:string,
 *   days:string,
 *   time_label:string,
 *   meetings:list<array{day:string,time:string}>
 * }>
 */
function registration_form_parse_tokens(array $tokens): array
{
    $start = null;
    $n = count($tokens);
    for ($i = 0; $i < $n; $i++) {
        if (strcasecmp($tokens[$i], 'Course Code') !== 0) {
            continue;
        }
        for ($j = $i; $j < min($n, $i + 12); $j++) {
            if (strcasecmp($tokens[$j], 'Instructor') === 0) {
                $start = $j + 1;
                break 2;
            }
        }
    }
    if ($start === null) {
        return [];
    }

    $slice = [];
    for ($i = $start; $i < $n; $i++) {
        $tok = trim($tokens[$i]);
        if ($tok === '') {
            continue;
        }
        if (preg_match('/^total units:?$/i', $tok) || preg_match('/^date:/i', $tok)) {
            break;
        }
        $slice[] = $tok;
    }

    $subjects = [];
    $i = 0;
    $len = count($slice);
    while ($i < $len) {
        $tok = $slice[$i];
        if (preg_match('/^\d{1,6}$/', $tok) && isset($slice[$i + 1]) && registration_form_is_course_code($slice[$i + 1])) {
            $i++;
            $tok = $slice[$i];
        }
        if (!registration_form_is_course_code($tok)) {
            $i++;
            continue;
        }

        $code = strtoupper(preg_replace('/\s+/', ' ', $tok) ?? $tok);
        $i++;
        $descParts = [];
        while ($i < $len && !registration_form_is_units_token($slice[$i]) && !registration_form_is_day_token($slice[$i])) {
            if (registration_form_is_course_code($slice[$i]) && $descParts !== []) {
                break;
            }
            $descParts[] = $slice[$i];
            $i++;
            if (count($descParts) > 8) {
                break;
            }
        }
        $description = trim(implode(' ', $descParts));
        if ($i < $len && registration_form_is_units_token($slice[$i])) {
            $i++;
        }

        $days = [];
        while ($i < $len && registration_form_is_day_token($slice[$i])) {
            foreach (registration_form_expand_day_token($slice[$i]) as $d) {
                if (!in_array($d, $days, true)) {
                    $days[] = $d;
                }
            }
            $i++;
        }

        $times = [];
        while ($i < $len && registration_form_is_time_token($slice[$i])) {
            $timeTok = strtolower(str_replace([' ', '.'], '', $slice[$i]));
            $i++;
            if ($i < $len && preg_match('/^(am|pm)$/i', $slice[$i]) && !preg_match('/[ap]m$/i', $timeTok)) {
                $timeTok .= strtolower($slice[$i]);
                $i++;
            }
            $times[] = $timeTok;
        }

        $rooms = [];
        while ($i < $len) {
            $next = $slice[$i];
            if (registration_form_is_instructor_token($next)) {
                break;
            }
            if (preg_match('/^\d{1,6}$/', $next) && isset($slice[$i + 1]) && registration_form_is_course_code($slice[$i + 1])) {
                break;
            }
            if (registration_form_is_course_code($next)) {
                break;
            }
            if (!registration_form_is_room_token($next) && $rooms !== [] && registration_form_is_instructor_token($next)) {
                break;
            }
            $rooms[] = $next;
            $i++;
            if (count($rooms) > 6) {
                break;
            }
        }

        $instructor = '';
        if ($i < $len && registration_form_is_instructor_token($slice[$i])) {
            $instructor = trim($slice[$i]);
            $i++;
            // "K. San Jose" may split as "K." + "San Jose" in some PDFs; sample keeps one token.
            if ($i < $len && !registration_form_is_course_code($slice[$i]) && !preg_match('/^\d{1,6}$/', $slice[$i])
                && !registration_form_is_time_token($slice[$i]) && !registration_form_is_day_token($slice[$i])
                && preg_match('/^[A-Za-z][A-Za-z.\-\' ]*$/', $slice[$i])
                && strlen($instructor) <= 4
            ) {
                $instructor = trim($instructor . ' ' . $slice[$i]);
                $i++;
            }
        }

        if ($description === '') {
            continue;
        }

        $meetings = [];
        $timeCount = count($times);
        foreach ($days as $idx => $day) {
            $time = $times[$idx] ?? ($times[0] ?? '');
            if ($timeCount === 1) {
                $time = $times[0];
            }
            $meetings[] = ['day' => $day, 'time' => $time];
        }
        if ($meetings === [] && $times !== []) {
            $meetings[] = ['day' => '', 'time' => $times[0]];
        }

        $subjects[] = [
            'course_code' => $code,
            'course_description' => $description,
            'instructor' => $instructor,
            'days' => implode(',', $days),
            'time_label' => implode('; ', $times),
            'meetings' => $meetings,
        ];
    }

    $unique = [];
    $out = [];
    foreach ($subjects as $row) {
        $key = strtolower($row['course_code'] . '|' . $row['days'] . '|' . $row['time_label'] . '|' . $row['instructor']);
        if (isset($unique[$key])) {
            continue;
        }
        $unique[$key] = true;
        $out[] = $row;
    }
    return $out;
}

/**
 * @return array{ok:bool,error:string,subjects:list<array<string,mixed>>}
 */
function registration_form_parse_pdf_bytes(string $binary): array
{
    if ($binary === '' || !str_starts_with($binary, '%PDF')) {
        return ['ok' => false, 'error' => 'Please upload a valid PDF of your LU registration form.', 'subjects' => []];
    }
    if (strlen($binary) > 8 * 1024 * 1024) {
        return ['ok' => false, 'error' => 'PDF must be 8 MB or smaller.', 'subjects' => []];
    }

    $tokens = registration_form_pdf_extract_strings($binary);
    $subjects = registration_form_parse_tokens($tokens);
    if ($subjects === []) {
        return [
            'ok' => false,
            'error' => 'Could not read subjects from that PDF. Use the digital LU Form No. 1 / Registration Form (not a photo scan).',
            'subjects' => [],
        ];
    }
    return ['ok' => true, 'error' => '', 'subjects' => $subjects];
}

/**
 * @return array{ok:bool,error:string,path:string,cleanup:bool}
 */
function registration_form_accept_upload(string $fileKey = 'schedule_file'): array
{
    $key = $fileKey;
    if (!isset($_FILES[$key]) || !is_array($_FILES[$key])) {
        foreach (['file', 'pdf', 'registration_form'] as $alt) {
            if (isset($_FILES[$alt]) && is_array($_FILES[$alt])) {
                $key = $alt;
                break;
            }
        }
    }
    if (!isset($_FILES[$key]) || !is_array($_FILES[$key])) {
        return ['ok' => false, 'error' => 'Upload your LU registration form PDF.', 'path' => '', 'cleanup' => false];
    }
    $upload = $_FILES[$key];
    if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'error' => 'Upload failed. Please try again.', 'path' => '', 'cleanup' => false];
    }
    $tmp = (string) ($upload['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        return ['ok' => false, 'error' => 'Invalid upload payload.', 'path' => '', 'cleanup' => false];
    }
    $size = (int) ($upload['size'] ?? 0);
    if ($size <= 0 || $size > 8 * 1024 * 1024) {
        return ['ok' => false, 'error' => 'PDF must be 8 MB or smaller.', 'path' => '', 'cleanup' => false];
    }
    $name = strtolower((string) ($upload['name'] ?? ''));
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = $finfo ? (string) finfo_file($finfo, $tmp) : '';
    if ($finfo) {
        finfo_close($finfo);
    }
    $mimeOk = in_array($mime, ['application/pdf', 'application/x-pdf', 'application/octet-stream'], true);
    if (!$mimeOk && !str_ends_with($name, '.pdf')) {
        return ['ok' => false, 'error' => 'Only a PDF registration form is allowed.', 'path' => '', 'cleanup' => false];
    }
    $head = (string) file_get_contents($tmp, false, null, 0, 5);
    if ($head !== '%PDF-') {
        return ['ok' => false, 'error' => 'Please upload a valid PDF of your LU registration form.', 'path' => '', 'cleanup' => false];
    }
    return ['ok' => true, 'error' => '', 'path' => $tmp, 'cleanup' => true];
}

function registration_form_discard(string $path): void
{
    $path = trim($path);
    if ($path === '' || !is_file($path)) {
        return;
    }
    @unlink($path);
}
