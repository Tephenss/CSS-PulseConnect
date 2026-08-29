<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/session.php';
session_bootstrap();

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/supabase.php';
require_once __DIR__ . '/../includes/simple_spreadsheet.php';
require_once __DIR__ . '/../includes/admin_users_export_lib.php';

$user = require_role(['admin']);

$type = strtolower(trim((string) ($_GET['type'] ?? '')));
if (!in_array($type, ['students', 'teachers'], true)) {
    http_response_code(400);
    echo 'Invalid export type.';
    exit;
}

$users = admin_users_export_fetch_users();
$sectionMap = admin_users_export_fetch_section_map();

try {
    if ($type === 'students') {
        $rosterRows = admin_users_export_fetch_roster();
        $directory = admin_users_export_build_student_directory($users, $rosterRows, $sectionMap);
        $headerRow = [
            'Student No.',
            'Surname',
            'First Name',
            'Middle Name',
            'Program',
            'Year',
            'Block',
            'Email',
            'Status',
        ];
        $groups = admin_users_export_group_students_by_program($directory);
        $totalStudents = count($directory);
        $exportedAt = gmdate('Y-m-d H:i');
        $exportSheets = [];
        foreach ($groups as $program => $rows) {
            $dataRows = [];
            foreach ($rows as $row) {
                $dataRows[] = admin_users_export_student_row_to_values($row);
            }
            $exportSheets[] = [
                'name' => admin_users_export_program_sheet_name($program),
                'headerRow' => $headerRow,
                'dataRows' => $dataRows,
                'options' => [
                    'title' => 'PulseConnect Student Directory',
                    'subtitle' => 'Exported ' . $exportedAt . ' UTC · ' . count($dataRows) . ' students · ' . $program . ' (' . $totalStudents . ' total)',
                    'instruction' => 'Read-only export from Users & Roles. One sheet per program, matching roster import tabs (e.g. BSIT-SD, BSIT-BA, BSCS).',
                    'columnWidths' => [14, 18, 18, 18, 14, 10, 10, 28, 16],
                ],
            ];
        }
        $binary = build_multi_sheet_xlsx($exportSheets);
        $filename = 'pulseconnect-students-' . gmdate('Ymd-His') . '.xlsx';
    } else {
        $teachers = admin_users_export_build_teacher_rows($users, $sectionMap);
        $headerRow = [
            'Surname',
            'First Name',
            'Middle Name',
            'Email',
            'Contact No.',
            'Year Level',
            'Block',
        ];
        $dataRows = [];
        foreach ($teachers as $row) {
            $dataRows[] = [
                (string) ($row['last_name'] ?? ''),
                (string) ($row['first_name'] ?? ''),
                (string) ($row['middle_name'] ?? ''),
                (string) ($row['email'] ?? ''),
                (string) ($row['contact_number'] ?? ''),
                (string) ($row['year_level'] ?? ''),
                (string) ($row['block'] ?? ''),
            ];
        }
        $binary = build_simple_xlsx(
            $headerRow,
            $dataRows,
            'Teachers',
            [
                'title' => 'PulseConnect Teacher Directory',
                'subtitle' => 'Exported ' . gmdate('Y-m-d H:i') . ' UTC · ' . count($dataRows) . ' teachers',
                'instruction' => 'Read-only export from Users & Roles.',
                'columnWidths' => [18, 18, 18, 28, 16, 12, 12],
            ]
        );
        $filename = 'pulseconnect-teachers-' . gmdate('Ymd-His') . '.xlsx';
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo $e->getMessage();
    exit;
}

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . strlen($binary));
header('Cache-Control: private, max-age=0, must-revalidate');
echo $binary;
exit;
