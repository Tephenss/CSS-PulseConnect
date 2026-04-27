<?php
declare(strict_types=1);

session_start();

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/supabase.php';
require_once __DIR__ . '/../includes/json.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/registration_access.php';
require_once __DIR__ . '/../includes/simple_spreadsheet.php';

function can_import_registration_access(array $event, array $user): bool
{
    $role = (string) ($user['role'] ?? '');
    if ($role === 'admin') {
        return true;
    }

    return $role === 'teacher'
        && (string) ($event['created_by'] ?? '') !== ''
        && (string) ($event['created_by'] ?? '') === (string) ($user['id'] ?? '');
}

function normalize_import_header(string $value): string
{
    $value = strtolower(trim($value));
    return preg_replace('/[^a-z0-9]+/', '_', $value) ?? '';
}

function resolve_import_header_alias(string $value): string
{
    return match ($value) {
        'student_no', 'student_no_', 'student_number', 'student_num', 'student_id_number' => 'student_number',
        'student_name', 'name', 'full_name' => 'student_name',
        'email', 'email_address', 'student_email' => 'email',
        'year', 'year_level', 'level' => 'year_level',
        'paid', 'paid_', 'paid_status' => 'paid',
        'payment_note', 'remarks', 'note', 'notes' => 'payment_note',
        'template_event_id', 'export_event_id' => 'template_event_id',
        'template_event_title', 'export_event_title' => 'template_event_title',
        'template_key', 'template_signature', 'export_key' => 'template_key',
        default => $value,
    };
}

function import_row_has_meaningful_values(array $row): bool
{
    foreach ($row as $value) {
        if (trim((string) $value) !== '') {
            return true;
        }
    }
    return false;
}

function normalize_import_student_name(string $value): string
{
    $normalized = strtolower(trim($value));
    $normalized = preg_replace('/\s+/', ' ', $normalized) ?? $normalized;
    return trim($normalized);
}

function extract_import_template_event_title(array $rows): string
{
    foreach (array_slice($rows, 0, 3) as $row) {
        if (!is_array($row)) {
            continue;
        }

        foreach ($row as $cellValue) {
            $text = trim((string) $cellValue);
            if ($text === '') {
                continue;
            }

            if (str_contains($text, 'Target:')) {
                $parts = preg_split('/\s*(?:•|\x{2022}|â€¢|-)\s*Target:/u', $text);
                if (is_array($parts) && isset($parts[0])) {
                    return trim((string) $parts[0]);
                }

                $targetPos = stripos($text, 'Target:');
                if ($targetPos !== false) {
                    return trim(rtrim(substr($text, 0, $targetPos), " \t\n\r\0\x0B-•"));
                }
            }
        }
    }

    return '';
}

function normalize_import_event_title(string $value): string
{
    $normalized = strtolower(trim($value));
    $normalized = preg_replace('/\s+/', ' ', $normalized) ?? $normalized;
    return trim($normalized);
}

$user = require_role(['admin', 'teacher']);
csrf_validate(isset($_POST['csrf_token']) ? (string) $_POST['csrf_token'] : null);

$eventId = trim((string) ($_POST['event_id'] ?? ''));
if ($eventId === '') {
    json_response(['ok' => false, 'error' => 'event_id required'], 400);
}

if (!isset($_FILES['registration_file']) || !is_array($_FILES['registration_file'])) {
    json_response(['ok' => false, 'error' => 'Upload the exported Excel file first.'], 400);
}

$upload = $_FILES['registration_file'];
if ((int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    json_response(['ok' => false, 'error' => 'The uploaded file could not be processed.'], 400);
}

$tmpPath = (string) ($upload['tmp_name'] ?? '');
$originalName = (string) ($upload['name'] ?? 'registration-access.xlsx');
if ($tmpPath === '' || !is_uploaded_file($tmpPath)) {
    json_response(['ok' => false, 'error' => 'Invalid uploaded file.'], 400);
}

$headers = [
    'Content-Type: application/json',
    'Accept: application/json',
    'apikey: ' . SUPABASE_KEY,
    'Authorization: Bearer ' . SUPABASE_KEY,
];

$event = fetch_event_with_registration_settings($eventId, $headers);
if (!is_array($event)) {
    json_response(['ok' => false, 'error' => 'Event not found'], 404);
}

if (!can_import_registration_access($event, $user)) {
    json_response(['ok' => false, 'error' => 'Forbidden'], 403);
}

if (strtolower(trim((string) ($event['status'] ?? ''))) !== 'published') {
    json_response(['ok' => false, 'error' => 'Publish the event first before importing registration approvals.'], 409);
}

try {
$rows = read_uploaded_spreadsheet_rows($tmpPath, $originalName);
} catch (Throwable $e) {
    json_response(['ok' => false, 'error' => $e->getMessage()], 400);
}

if (count($rows) < 2) {
    json_response(['ok' => false, 'error' => 'The uploaded file does not contain any student rows.'], 400);
}

$headerRowIndex = null;
$headerRow = [];
foreach ($rows as $index => $candidateRow) {
    $normalizedCandidate = array_map(
        static fn ($value): string => resolve_import_header_alias(
            normalize_import_header((string) $value)
        ),
        array_values(is_array($candidateRow) ? $candidateRow : [])
    );
    if (in_array('paid', $normalizedCandidate, true)
        && (in_array('student_number', $normalizedCandidate, true) || in_array('email', $normalizedCandidate, true))) {
        $headerRowIndex = $index;
        $headerRow = $normalizedCandidate;
        break;
    }
}

if ($headerRowIndex === null) {
    json_response(['ok' => false, 'error' => 'Unable to find the registration table header in the uploaded file. Export a fresh template and try again.'], 400);
}

$headerLookup = [];
foreach ($headerRow as $index => $label) {
    if ($label !== '') {
        $headerLookup[$label] = $index;
    }
}

if (!array_key_exists('paid', $headerLookup)) {
    json_response(['ok' => false, 'error' => 'The uploaded file is missing the "paid" column. Export a fresh template and try again.'], 400);
}

if (!array_key_exists('student_number', $headerLookup) && !array_key_exists('email', $headerLookup)) {
    json_response(['ok' => false, 'error' => 'The uploaded file needs at least the "student_number" or "email" column so the system can match students correctly.'], 400);
}

$hasTemplateValidationColumns =
    array_key_exists('template_event_id', $headerLookup) &&
    array_key_exists('template_key', $headerLookup);
$skipTemplateValidation = false;

if (!$hasTemplateValidationColumns) {
    $currentEventTitle = trim((string) ($event['title'] ?? 'this event'));
    $sourceEventTitle = extract_import_template_event_title($rows);
    if (
        $sourceEventTitle !== '' &&
        normalize_import_event_title($sourceEventTitle) === normalize_import_event_title($currentEventTitle)
    ) {
        // Legacy export fallback:
        // accept older files without hidden validation columns as long as the
        // visible event title still matches the current event title.
        $skipTemplateValidation = true;
    } else {
        json_response([
            'ok' => false,
            'error' => $sourceEventTitle !== ''
                ? 'This approval file was exported for "' . $sourceEventTitle . '". Please import it into that same event.'
                : 'This file is missing its event validation columns. Please export a fresh approval file from "' . $currentEventTitle . '" and try again.',
        ], 400);
    }
}

if (!$skipTemplateValidation) {
    $expectedTemplateKey = build_registration_access_template_key($eventId);
    $validatedTemplate = false;
    $templateEventIdIndex = (int) $headerLookup['template_event_id'];
    $templateKeyIndex = (int) $headerLookup['template_key'];
    $templateEventTitleIndex = array_key_exists('template_event_title', $headerLookup)
        ? (int) $headerLookup['template_event_title']
        : null;
    $studentNumberIndex = array_key_exists('student_number', $headerLookup)
        ? (int) $headerLookup['student_number']
        : null;
    $emailIndex = array_key_exists('email', $headerLookup)
        ? (int) $headerLookup['email']
        : null;
    $studentNameIndex = array_key_exists('student_name', $headerLookup)
        ? (int) $headerLookup['student_name']
        : null;

    foreach (array_slice($rows, $headerRowIndex + 1) as $row) {
        if (!is_array($row) || !import_row_has_meaningful_values($row)) {
            continue;
        }

        $templateEventId = trim((string) ($row[$templateEventIdIndex] ?? ''));
        $templateKey = trim((string) ($row[$templateKeyIndex] ?? ''));
        $studentNumber = $studentNumberIndex !== null
            ? trim((string) ($row[$studentNumberIndex] ?? ''))
            : '';
        $email = $emailIndex !== null
            ? trim((string) ($row[$emailIndex] ?? ''))
            : '';
        $studentName = $studentNameIndex !== null
            ? trim((string) ($row[$studentNameIndex] ?? ''))
            : '';

        if ($studentNumber === '' && $email === '' && $studentName === '') {
            continue;
        }

        $validatedTemplate = true;
        $templateEventTitle = $templateEventTitleIndex !== null
            ? trim((string) ($row[$templateEventTitleIndex] ?? ''))
            : '';
        $currentEventTitle = trim((string) ($event['title'] ?? 'this event'));
        if ($templateEventId !== $eventId || !hash_equals($expectedTemplateKey, $templateKey)) {
            json_response([
                'ok' => false,
                'error' => $templateEventTitle !== ''
                    ? 'This approval file was exported for "' . $templateEventTitle . '", not "' . $currentEventTitle . '". Export the approval list from "' . $currentEventTitle . '" and import that file here instead.'
                    : 'This approval file was exported for a different event. Export the approval list from "' . $currentEventTitle . '" and import it here instead.',
            ], 400);
        }
        break;
    }

    if (!$validatedTemplate) {
        json_response([
            'ok' => false,
            'error' => 'The uploaded file does not contain a valid event-bound approval list. Export a fresh file from this event and try again.',
        ], 400);
    }
}

$targetStudents = fetch_target_students_for_event($event, [
    'Accept: application/json',
    'apikey: ' . SUPABASE_KEY,
    'Authorization: Bearer ' . SUPABASE_KEY,
]);
$existingAccessMap = build_event_registration_access_map(
    fetch_event_registration_access_rows($eventId, $headers)
);

$byUserId = [];
$byStudentNumber = [];
$byEmail = [];
$byStudentName = [];
foreach ($targetStudents as $student) {
    if (!is_array($student)) {
        continue;
    }
    $studentId = trim((string) ($student['id'] ?? ''));
    if ($studentId !== '') {
        $byUserId[$studentId] = $student;
    }
    $studentNumber = strtolower(trim((string) ($student['student_id'] ?? '')));
    if ($studentNumber !== '') {
        $byStudentNumber[$studentNumber] = $student;
    }
    $email = strtolower(trim((string) ($student['email'] ?? '')));
    if ($email !== '') {
        $byEmail[$email] = $student;
    }
    $studentName = normalize_import_student_name((string) ($student['display_name'] ?? ''));
    if ($studentName !== '') {
        $byStudentName[$studentName] = $student;
    }
}

$upsertRows = [];
$matchedCount = 0;
$approvedCount = 0;
$skippedCount = 0;
$newlyApprovedStudentIds = [];
$approvedStudentIds = [];
$approvedNames = [];

foreach (array_slice($rows, $headerRowIndex + 1) as $row) {
    if (!is_array($row) || !import_row_has_meaningful_values($row)) {
        continue;
    }

    $get = static function (string $key) use ($headerLookup, $row): string {
        if (!array_key_exists($key, $headerLookup)) {
            return '';
        }
        return trim((string) ($row[$headerLookup[$key]] ?? ''));
    };

    $systemUserId = $get('system_user_id');
    $studentNumber = strtolower($get('student_number'));
    $email = strtolower($get('email'));
    $studentName = normalize_import_student_name($get('student_name'));

    $student = null;
    if ($systemUserId !== '' && isset($byUserId[$systemUserId]) && is_array($byUserId[$systemUserId])) {
        $student = $byUserId[$systemUserId];
    } elseif ($studentNumber !== '' && isset($byStudentNumber[$studentNumber]) && is_array($byStudentNumber[$studentNumber])) {
        $student = $byStudentNumber[$studentNumber];
    } elseif ($email !== '' && isset($byEmail[$email]) && is_array($byEmail[$email])) {
        $student = $byEmail[$email];
    } elseif ($studentName !== '' && isset($byStudentName[$studentName]) && is_array($byStudentName[$studentName])) {
        $student = $byStudentName[$studentName];
    }

    if (!is_array($student)) {
        $skippedCount++;
        continue;
    }

    $paymentStatusRaw = $get('payment_status');
    $paymentStatus = $paymentStatusRaw !== ''
        ? normalize_registration_payment_status($paymentStatusRaw)
        : 'pending';
    $paidFlag = normalize_registration_bool($get('paid'));
    $allowFlag = normalize_registration_bool($get('allow_registration'));
    $approved = $allowFlag || $paidFlag || in_array($paymentStatus, ['paid', 'waived'], true);

    if ($approved && $paymentStatus === 'pending') {
        $paymentStatus = $paidFlag ? 'paid' : 'waived';
    }

    if (!$approved && $paymentStatus === 'paid') {
        $approved = true;
    }

    $studentId = trim((string) ($student['id'] ?? ''));
    if ($studentId === '') {
        $skippedCount++;
        continue;
    }

    $upsertRows[$studentId] = [
        'event_id' => $eventId,
        'student_id' => $studentId,
        'approved' => $approved,
        'payment_status' => $paymentStatus,
        'payment_note' => $get('payment_note'),
        'imported_at' => gmdate('c'),
        'imported_by' => (string) ($user['id'] ?? ''),
        'updated_at' => gmdate('c'),
    ];

    $matchedCount++;
    if ($approved) {
        $approvedCount++;
        $approvedStudentIds[$studentId] = true;
        $approvedNames[$studentId] = trim((string) ($student['display_name'] ?? ''));
        $previousRow = isset($existingAccessMap[$studentId]) && is_array($existingAccessMap[$studentId])
            ? $existingAccessMap[$studentId]
            : null;
        if ($previousRow === null || !registration_access_row_allows($previousRow)) {
            $newlyApprovedStudentIds[$studentId] = true;
        }
    }
}

$deleteUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/event_registration_access'
    . '?event_id=eq.' . rawurlencode($eventId);
$deleteRes = supabase_request('DELETE', $deleteUrl, [
    'Accept: application/json',
    'apikey: ' . SUPABASE_KEY,
    'Authorization: Bearer ' . SUPABASE_KEY,
]);
if (!$deleteRes['ok']) {
    $message = (string) ($deleteRes['body'] ?? '') . ' ' . (string) ($deleteRes['error'] ?? '');
    if (!registration_access_missing_table_message($message)) {
        json_response([
            'ok' => false,
            'error' => build_error($deleteRes['body'] ?? null, (int) ($deleteRes['status'] ?? 0), $deleteRes['error'] ?? null, 'Failed to clear the previous approval list'),
        ], 500);
    }
}

$signalId = 'reg_access_approved_' . $eventId;
$signalDeleteUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/user_notification_reads'
    . '?notification_id=eq.' . rawurlencode($signalId);
$signalDeleteRes = supabase_request('DELETE', $signalDeleteUrl, [
    'Accept: application/json',
    'apikey: ' . SUPABASE_KEY,
    'Authorization: Bearer ' . SUPABASE_KEY,
]);

if ($upsertRows !== []) {
    $insertUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/event_registration_access?on_conflict=event_id,student_id';
    $insertPayload = json_encode(array_values($upsertRows), JSON_UNESCAPED_SLASHES);
    if (!is_string($insertPayload)) {
        json_response(['ok' => false, 'error' => 'Failed to prepare the imported approval list.'], 500);
    }

    $insertHeaders = $headers;
    $insertHeaders[] = 'Prefer: return=minimal';
    $insertRes = supabase_request('POST', $insertUrl, $insertHeaders, $insertPayload);
    if (!$insertRes['ok']) {
        $message = (string) ($insertRes['body'] ?? '') . ' ' . (string) ($insertRes['error'] ?? '');
        if (registration_access_missing_table_message($message)) {
            json_response([
                'ok' => false,
                'error' => 'Database update required: run migration 024_registration_access_control.sql first.',
            ], 500);
        }

        json_response([
            'ok' => false,
            'error' => build_error($insertRes['body'] ?? null, (int) ($insertRes['status'] ?? 0), $insertRes['error'] ?? null, 'Failed to import the approval list'),
        ], 500);
    }
}

if ($approvedStudentIds !== []) {
    $signalRows = [];
    foreach (array_keys($approvedStudentIds) as $studentId) {
        $signalRows[] = [
            'user_id' => $studentId,
            'notification_id' => $signalId,
            'read_at' => gmdate('c'),
        ];
    }

    $signalInsertUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/user_notification_reads?on_conflict=user_id,notification_id';
    $signalPayload = json_encode($signalRows, JSON_UNESCAPED_SLASHES);
    if (is_string($signalPayload)) {
        $signalHeaders = $headers;
        $signalHeaders[] = 'Prefer: return=minimal';
        supabase_request('POST', $signalInsertUrl, $signalHeaders, $signalPayload);
    }
}

if ($newlyApprovedStudentIds !== []) {
    $eventTitle = trim((string) ($event['title'] ?? 'Event'));
    notify_users_for_registration_access(
        array_keys($newlyApprovedStudentIds),
        'Registration Approved',
        'You are now approved to register for "' . $eventTitle . '".',
        [
            'event_id' => $eventId,
            'type' => 'reg_approved',
        ]
    );
}

json_response([
    'ok' => true,
    'processed' => max(0, count($rows) - ($headerRowIndex + 1)),
    'matched' => $matchedCount,
    'approved' => $approvedCount,
    'skipped' => $skippedCount,
    'approved_names' => array_values(array_filter($approvedNames)),
], 200);
