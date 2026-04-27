<?php
declare(strict_types=1);

session_start();

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/supabase.php';
require_once __DIR__ . '/../includes/registration_access.php';
require_once __DIR__ . '/../includes/simple_spreadsheet.php';

function can_export_registration_access(array $event, array $user): bool
{
    $role = (string) ($user['role'] ?? '');
    if ($role === 'admin') {
        return true;
    }

    return $role === 'teacher'
        && (string) ($event['created_by'] ?? '') !== ''
        && (string) ($event['created_by'] ?? '') === (string) ($user['id'] ?? '');
}

function slugify_event_filename(string $value): string
{
    $value = strtolower(trim($value));
    $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? 'event';
    $value = trim($value, '-');
    return $value !== '' ? $value : 'event';
}

$user = require_role(['admin', 'teacher']);
$eventId = trim((string) ($_GET['event_id'] ?? ''));
if ($eventId === '') {
    http_response_code(400);
    echo 'event_id required';
    exit;
}

$headers = [
    'Accept: application/json',
    'apikey: ' . SUPABASE_KEY,
    'Authorization: Bearer ' . SUPABASE_KEY,
];

$event = fetch_event_with_registration_settings($eventId, $headers);
if (!is_array($event)) {
    http_response_code(404);
    echo 'Event not found';
    exit;
}

if (!can_export_registration_access($event, $user)) {
    http_response_code(403);
    echo 'Forbidden';
    exit;
}

$targetStudents = fetch_target_students_for_event($event, $headers);
$accessRows = build_event_registration_access_map(
    fetch_event_registration_access_rows($eventId, $headers)
);

$headerRow = [
    'No.',
    'Student No.',
    'Student Name',
    'Course',
    'Year/Section',
    'Email Address',
    'Paid?',
    'Payment Note',
    'Template Event ID',
    'Template Event Title',
    'Template Key',
];

$templateKey = build_registration_access_template_key($eventId);
$templateEventTitle = trim((string) ($event['title'] ?? 'Event'));

$dataRows = [];
foreach ($targetStudents as $index => $student) {
    if (!is_array($student)) {
        continue;
    }

    $studentId = trim((string) ($student['id'] ?? ''));
    $accessRow = $studentId !== '' && isset($accessRows[$studentId]) && is_array($accessRows[$studentId])
        ? $accessRows[$studentId]
        : [];
    $allowed = $accessRow !== [] && registration_access_row_allows($accessRow);
    $paymentStatus = (string) ($accessRow['payment_status'] ?? 'pending');

    $dataRows[] = [
        (string) ($index + 1),
        (string) ($student['student_id'] ?? ''),
        (string) ($student['display_name'] ?? 'Student'),
        (string) ($student['normalized_course'] ?? ''),
        trim(
            implode(' / ', array_values(array_filter([
                (string) ($student['year_level'] ?? ''),
                (string) ($student['section_name'] ?? ''),
            ], static fn ($value): bool => trim($value) !== '')))
        ),
        (string) ($student['email'] ?? ''),
        $allowed || $paymentStatus === 'paid' || $paymentStatus === 'waived' ? 'YES' : '',
        (string) ($accessRow['payment_note'] ?? ''),
        $eventId,
        $templateEventTitle,
        $templateKey,
    ];
}

try {
    $binary = build_simple_xlsx(
        $headerRow,
        $dataRows,
        'Registration Access',
        [
            'title' => 'PulseConnect Registration Approval List',
            'subtitle' => (string) ($event['title'] ?? 'Event') . ' • Target: ' . (string) ($event['event_for'] ?? 'All'),
            'instruction' => 'Mark the PAID column with YES, PAID, CHECK, or a check mark for students who already paid. You may also leave a note in PAYMENT NOTE.',
            'columnWidths' => [6, 18, 30, 12, 22, 28, 12, 26, 2, 2, 2],
            'hiddenColumns' => [8, 9, 10],
        ]
    );
} catch (Throwable $e) {
    http_response_code(500);
    echo $e->getMessage();
    exit;
}

$filename = slugify_event_filename((string) ($event['title'] ?? 'event'))
    . '-registration-access.xlsx';

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . strlen($binary));
header('Cache-Control: private, max-age=0, must-revalidate');
echo $binary;
exit;
