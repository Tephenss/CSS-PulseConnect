<?php
declare(strict_types=1);

/**
 * Admin: import school student roster from CSV/XLSX.
 * Creates/updates student_roster + auto-creates sections (incl. IRREGULAR).
 */

require_once __DIR__ . '/../includes/session.php';
session_bootstrap();

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/supabase.php';
require_once __DIR__ . '/../includes/json.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/api_rate_limit.php';
require_once __DIR__ . '/../includes/simple_spreadsheet.php';
require_once __DIR__ . '/../includes/student_roster.php';

$user = require_role(['admin']);
$userId = trim((string) ($user['id'] ?? ''));

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    json_response(['ok' => false, 'error' => 'POST required'], 405);
}

$clientIp = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
if (!api_rate_limit_allow('students_roster_import:' . $userId . ':' . $clientIp, 10, 3600)) {
    json_response(['ok' => false, 'error' => 'Too many imports. Try again later.'], 429);
}

$csrf = $_POST['csrf_token'] ?? null;
csrf_validate(is_string($csrf) ? $csrf : null);

if (!isset($_FILES['file']) || !is_array($_FILES['file'])) {
    json_response(['ok' => false, 'error' => 'Upload a CSV or XLSX file.'], 400);
}

$file = $_FILES['file'];
$err = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
if ($err !== UPLOAD_ERR_OK) {
    json_response(['ok' => false, 'error' => 'Upload failed.'], 400);
}

$tmpPath = (string) ($file['tmp_name'] ?? '');
$originalName = (string) ($file['name'] ?? 'upload.csv');
if ($tmpPath === '' || !is_uploaded_file($tmpPath)) {
    json_response(['ok' => false, 'error' => 'Invalid upload.'], 400);
}

$maxBytes = 5 * 1024 * 1024;
$size = (int) ($file['size'] ?? 0);
if ($size <= 0 || $size > $maxBytes) {
    json_response(['ok' => false, 'error' => 'File must be under 5 MB.'], 400);
}

try {
    $workbookSheets = read_uploaded_spreadsheet_sheets($tmpPath, $originalName);
} catch (Throwable $e) {
    json_response(['ok' => false, 'error' => $e->getMessage()], 400);
}

if ($workbookSheets === []) {
    json_response(['ok' => false, 'error' => 'The spreadsheet is empty.'], 400);
}

require_once __DIR__ . '/../includes/student_roster_import_parse.php';

@set_time_limit(300);

$now = gmdate('c');
$parsed = student_roster_parse_workbook_sheets($workbookSheets, $userId, $now);
$payloadsByNo = $parsed['payloadsByNo'];
$excelRowByNo = $parsed['excelRowByNo'];
$sheetsUsed = $parsed['sheetsUsed'];
$sheetErrors = $parsed['sheetErrors'];
$rowErrors = $parsed['rowErrors'];
$skipped = (int) $parsed['skipped'];
$sectionCreated = $parsed['sectionCreated'];
$sectionCache = $parsed['sectionCache'];
$inserted = 0;
$updated = 0;
$linkedExisting = 0;

if ($payloadsByNo === [] && $sheetErrors !== []) {
    json_response([
        'ok' => false,
        'error' => 'No importable sheets. ' . implode(' ', array_slice($sheetErrors, 0, 3)),
        'sheets' => array_map(static fn ($s) => (string) ($s['name'] ?? ''), $workbookSheets),
    ], 400);
}

if ($payloadsByNo === []) {
    json_response([
        'ok' => true,
        'inserted' => 0,
        'updated' => 0,
        'skipped' => $skipped,
        'linked_existing_users' => 0,
        'sheets_imported' => $sheetsUsed,
        'sections_created' => array_values(array_unique($sectionCreated)),
        'sections_created_count' => count(array_unique($sectionCreated)),
        'errors' => array_slice(array_merge($sheetErrors, $rowErrors), 0, 40),
        'error_count' => count($sheetErrors) + count($rowErrors),
    ]);
}

$allNos = array_keys($payloadsByNo);
$existingByNo = [];
$headers = student_roster_supabase_headers();

// Prefetch existing roster rows (preserve claimed user_id).
foreach (array_chunk($allNos, 80) as $chunk) {
    $inList = implode(',', array_map(static fn (string $n): string => '"' . str_replace('"', '', $n) . '"', $chunk));
    $url = rtrim(SUPABASE_URL, '/') . '/rest/v1/student_roster'
        . '?select=student_no,user_id,imported_at'
        . '&student_no=in.(' . $inList . ')';
    $res = supabase_request('GET', $url, $headers);
    $rows = $res['ok'] ? json_decode((string) ($res['body'] ?? ''), true) : [];
    if (!is_array($rows)) {
        continue;
    }
    foreach ($rows as $r) {
        if (!is_array($r)) {
            continue;
        }
        $no = student_roster_normalize_no((string) ($r['student_no'] ?? ''));
        if ($no !== '') {
            $existingByNo[$no] = $r;
        }
    }
}

// Prefetch app users already registered with these student numbers.
$userByStudentNo = [];
$userByDigits = [];
foreach (array_chunk($allNos, 80) as $chunk) {
    $inList = implode(',', array_map(static fn (string $n): string => '"' . str_replace('"', '', $n) . '"', $chunk));
    $url = rtrim(SUPABASE_URL, '/') . '/rest/v1/' . SUPABASE_TABLE_USERS
        . '?select=id,section_id,student_id,role'
        . '&role=eq.student'
        . '&student_id=in.(' . $inList . ')';
    $res = supabase_request('GET', $url, $headers);
    $rows = $res['ok'] ? json_decode((string) ($res['body'] ?? ''), true) : [];
    if (!is_array($rows)) {
        continue;
    }
    foreach ($rows as $r) {
        if (!is_array($r)) {
            continue;
        }
        $no = student_roster_normalize_no((string) ($r['student_id'] ?? ''));
        if ($no !== '') {
            $userByStudentNo[$no] = $r;
        }
        $dig = student_roster_digits_key((string) ($r['student_id'] ?? ''));
        if ($dig !== '') {
            $userByDigits[$dig] = $r;
        }
    }
}

// Also load student users and index by digits (covers hyphen mismatches not in in.() list).
$allStudentsUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/' . SUPABASE_TABLE_USERS
    . '?select=id,section_id,student_id,role'
    . '&role=eq.student'
    . '&student_id=not.is.null'
    . '&limit=2000';
$allStudentsRes = supabase_request('GET', $allStudentsUrl, $headers);
$allStudentRows = $allStudentsRes['ok'] ? json_decode((string) ($allStudentsRes['body'] ?? ''), true) : [];
if (is_array($allStudentRows)) {
    foreach ($allStudentRows as $r) {
        if (!is_array($r)) {
            continue;
        }
        $dig = student_roster_digits_key((string) ($r['student_id'] ?? ''));
        if ($dig !== '' && !isset($userByDigits[$dig])) {
            $userByDigits[$dig] = $r;
        }
        $no = student_roster_normalize_no((string) ($r['student_id'] ?? ''));
        if ($no !== '' && !isset($userByStudentNo[$no])) {
            $userByStudentNo[$no] = $r;
        }
    }
}

foreach ($payloadsByNo as $studentNo => &$payload) {
    $existing = $existingByNo[$studentNo] ?? null;
    $appUser = $userByStudentNo[$studentNo] ?? null;
    // Also match users whose student_id omitted hyphens.
    if ($appUser === null) {
        $dig = student_roster_digits_key($studentNo);
        if ($dig !== '' && isset($userByDigits[$dig])) {
            $appUser = $userByDigits[$dig];
        }
    }

    if (is_array($appUser)) {
        $uid = trim((string) ($appUser['id'] ?? ''));
        if ($uid !== '') {
            $payload['user_id'] = $uid;
            $linkedExisting++;
            // Always correct linked account identity from Excel on re-import.
            supabase_request(
                'PATCH',
                rtrim(SUPABASE_URL, '/') . '/rest/v1/' . SUPABASE_TABLE_USERS . '?id=eq.' . rawurlencode($uid) . '&role=eq.student',
                student_roster_supabase_headers(true),
                json_encode([
                    'first_name' => $payload['first_name'],
                    'middle_name' => $payload['middle_name'],
                    'last_name' => $payload['last_name'],
                    'student_id' => $studentNo,
                    'course' => $payload['course_code'],
                    'section_id' => $payload['section_id'],
                    'updated_at' => $now,
                ], JSON_UNESCAPED_SLASHES)
            );
        }
    }

    if (is_array($existing)) {
        if (empty($payload['user_id']) && !empty($existing['user_id'])) {
            $payload['user_id'] = $existing['user_id'];
        }
        // Keep original imported_at on updates.
        if (!empty($existing['imported_at'])) {
            $payload['imported_at'] = $existing['imported_at'];
        }
        $updated++;
    } else {
        $inserted++;
    }
}
unset($payload);

// Batch upsert — force overwrite of name/program/year/block on conflict.
$upsertHeaders = student_roster_supabase_headers(false);
$upsertHeaders[] = 'Prefer: resolution=merge-duplicates,return=minimal';
$savedOk = 0;
foreach (array_chunk(array_values($payloadsByNo), 80) as $batch) {
    $url = rtrim(SUPABASE_URL, '/') . '/rest/v1/student_roster?on_conflict=student_no';
    $res = supabase_request('POST', $url, $upsertHeaders, json_encode($batch, JSON_UNESCAPED_SLASHES));
    if ($res['ok']) {
        $savedOk += count($batch);
        continue;
    }
    $detail = trim((string) ($res['body'] ?? $res['error'] ?? 'upsert failed'));
    if (strlen($detail) > 180) {
        $detail = substr($detail, 0, 180) . '…';
    }
    $rowErrors[] = 'Batch upsert failed (' . count($batch) . ' rows): ' . $detail;
    // Fall back: PATCH existing by student_no, else POST insert.
    foreach ($batch as $one) {
        $no = (string) ($one['student_no'] ?? '');
        $okOne = false;
        if (isset($existingByNo[$no])) {
            $patchUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/student_roster?student_no=eq.' . rawurlencode($no);
            $patchRes = supabase_request(
                'PATCH',
                $patchUrl,
                student_roster_supabase_headers(true),
                json_encode($one, JSON_UNESCAPED_SLASHES)
            );
            $okOne = !empty($patchRes['ok']);
        }
        if (!$okOne) {
            $oneRes = supabase_request(
                'POST',
                $url,
                $upsertHeaders,
                json_encode([$one], JSON_UNESCAPED_SLASHES)
            );
            $okOne = !empty($oneRes['ok']);
        }
        if ($okOne) {
            $savedOk++;
            continue;
        }
        $skipped++;
        if (isset($existingByNo[$no])) {
            $updated = max(0, $updated - 1);
        } else {
            $inserted = max(0, $inserted - 1);
        }
        $excelRow = $excelRowByNo[$no] ?? '?';
        $rowErrors[] = 'Row ' . $excelRow . ': save failed for ' . $no . '.';
    }
}

json_response([
    'ok' => true,
    'inserted' => $inserted,
    'updated' => $updated,
    'skipped' => $skipped,
    'saved' => $savedOk,
    'corrected' => $updated,
    'linked_existing_users' => $linkedExisting,
    'sheets_imported' => $sheetsUsed,
    'sections_created' => array_values(array_unique($sectionCreated)),
    'sections_created_count' => count(array_unique($sectionCreated)),
    'errors' => array_slice(array_merge($sheetErrors, $rowErrors), 0, 40),
    'error_count' => count($sheetErrors) + count($rowErrors),
    'message' => 'Import complete. All sheets were processed; existing students were overwritten from the spreadsheet.',
]);