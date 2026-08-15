<?php
declare(strict_types=1);

/**
 * Event creator only: download LU-AA-FO-115 Approved Absence Form for registered participants.
 */
require_once __DIR__ . '/../includes/session.php';
session_bootstrap();

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/supabase.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/event_sessions.php';
require_once __DIR__ . '/../includes/student_roster.php';
require_once __DIR__ . '/../includes/student_class_schedules.php';
require_once __DIR__ . '/../includes/absence_form_docx.php';

/**
 * Prefer JSON for fetch/ajax clients; friendly HTML page for direct browser navigation.
 */
function absence_form_export_fail(int $status, string $message, string $eventId = ''): void
{
    http_response_code($status);
    $accept = strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? ''));
    $wantsJson = str_contains($accept, 'application/json')
        || trim((string) ($_GET['ajax'] ?? '')) === '1'
        || trim((string) ($_GET['format'] ?? '')) === 'json';

    if ($wantsJson) {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode(['ok' => false, 'error' => $message], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    $backHref = $eventId !== ''
        ? '/participants?event_id=' . rawurlencode($eventId)
        : '/manage_events.php';
    $safeMsg = htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $safeBack = htmlspecialchars($backHref, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>Absence form export</title>'
        . '<style>'
        . 'body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;'
        . 'font-family:system-ui,-apple-system,Segoe UI,sans-serif;background:#f4f4f5;color:#18181b;padding:24px;}'
        . '.card{max-width:28rem;width:100%;background:#fff;border:1px solid #e4e4e7;border-radius:16px;'
        . 'padding:24px;box-shadow:0 10px 30px rgba(0,0,0,.06);}'
        . 'h1{margin:0 0 8px;font-size:1.125rem;font-weight:800;}'
        . 'p{margin:0 0 18px;font-size:.925rem;line-height:1.5;color:#52525b;}'
        . 'a{display:inline-flex;align-items:center;gap:.4rem;border-radius:10px;background:#7f1d1d;'
        . 'color:#fff;text-decoration:none;font-weight:700;font-size:.875rem;padding:.7rem 1rem;}'
        . 'a:hover{background:#9f1239;}'
        . '</style></head><body><div class="card">'
        . '<h1>Could not export absence form</h1>'
        . '<p>' . $safeMsg . '</p>'
        . '<a href="' . $safeBack . '">Back to participants</a>'
        . '</div></body></html>';
    exit;
}

$user = require_role(['admin', 'teacher']);
$userId = trim((string) ($user['id'] ?? ''));
$eventId = trim((string) ($_GET['event_id'] ?? ''));
if ($eventId === '' || $userId === '') {
    absence_form_export_fail(400, 'Missing event. Open Participants and try Export again.', $eventId);
}

$headers = [
    'Accept: application/json',
    'apikey: ' . SUPABASE_KEY,
    'Authorization: Bearer ' . SUPABASE_KEY,
];

$lookup = fetch_event_row_by_id(
    $eventId,
    $headers,
    'id,title,start_at,end_at,location,created_by,status'
);
if (!($lookup['ok'] ?? false) || !is_array($lookup['event'] ?? null)) {
    absence_form_export_fail(
        (int) ($lookup['status'] ?? 404) === 404 ? 404 : 503,
        'Event not found.',
        $eventId
    );
}
$event = $lookup['event'];
if (trim((string) ($event['created_by'] ?? '')) !== $userId) {
    absence_form_export_fail(403, 'Only the event creator can export the absence form.', $eventId);
}

$sessions = fetch_event_sessions($eventId, $headers);
$tz = new DateTimeZone('Asia/Manila');
$header = absence_form_event_header($event, $sessions, $tz);

$pUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/event_registrations'
    . '?select=id,student_id,users(id,first_name,middle_name,last_name,suffix,student_id,sections(name))'
    . '&event_id=eq.' . rawurlencode($eventId)
    . '&limit=4000';
$pRes = supabase_request('GET', $pUrl, $headers);
$participants = ($pRes['ok'] ?? false) ? json_decode((string) ($pRes['body'] ?? ''), true) : [];
$participants = is_array($participants) ? $participants : [];

$userIds = [];
$studentNos = [];
foreach ($participants as $row) {
    if (!is_array($row)) {
        continue;
    }
    $uid = strtolower(trim((string) ($row['student_id'] ?? '')));
    if ($uid !== '' && preg_match('/^[0-9a-f-]{36}$/', $uid)) {
        $userIds[] = $uid;
    }
    $u = isset($row['users']) && is_array($row['users']) ? $row['users'] : [];
    $no = student_roster_normalize_no((string) ($u['student_id'] ?? ''));
    if ($no !== '') {
        $studentNos[] = $no;
    }
}

$yearMaps = student_roster_fetch_year_maps($userIds, $studentNos, $headers);
$schedulesByUser = student_class_schedules_fetch_by_user_ids($userIds, $headers);
$weekdays = is_array($header['weekdays'] ?? null) ? $header['weekdays'] : [];

$rosterByUser = [];
$rosterByNo = [];
if ($userIds !== [] || $studentNos !== []) {
    $rosterSelect = 'user_id,student_no,program_label,year_level,is_irregular';
    $chunks = [];
    if ($userIds !== []) {
        foreach (array_chunk(array_values(array_unique($userIds)), 80) as $chunk) {
            $in = '(' . implode(',', array_map('rawurlencode', $chunk)) . ')';
            $chunks[] = rtrim(SUPABASE_URL, '/') . '/rest/v1/student_roster'
                . '?select=' . $rosterSelect
                . '&user_id=in.' . $in
                . '&limit=2000';
        }
    }
    if ($studentNos !== []) {
        foreach (array_chunk(array_values(array_unique($studentNos)), 80) as $chunk) {
            $in = '(' . implode(',', array_map('rawurlencode', $chunk)) . ')';
            $chunks[] = rtrim(SUPABASE_URL, '/') . '/rest/v1/student_roster'
                . '?select=' . $rosterSelect
                . '&student_no=in.' . $in
                . '&limit=2000';
        }
    }
    foreach ($chunks as $url) {
        $res = supabase_request('GET', $url, $headers);
        $rows = ($res['ok'] ?? false) ? json_decode((string) ($res['body'] ?? ''), true) : [];
        if (!is_array($rows)) {
            continue;
        }
        foreach ($rows as $r) {
            if (!is_array($r)) {
                continue;
            }
            $uid = trim((string) ($r['user_id'] ?? ''));
            $no = student_roster_normalize_no((string) ($r['student_no'] ?? ''));
            if ($uid !== '') {
                $rosterByUser[$uid] = $r;
            }
            if ($no !== '') {
                $rosterByNo[$no] = $r;
            }
        }
    }
}

$exportRows = [];
foreach ($participants as $row) {
    if (!is_array($row)) {
        continue;
    }
    $uid = strtolower(trim((string) ($row['student_id'] ?? '')));
    $profile = isset($row['users']) && is_array($row['users']) ? $row['users'] : [];
    $section = '';
    if (isset($profile['sections']) && is_array($profile['sections'])) {
        $section = trim((string) ($profile['sections']['name'] ?? ''));
    }
    $studentNo = student_roster_normalize_no((string) ($profile['student_id'] ?? ''));
    $roster = $rosterByUser[$uid] ?? ($studentNo !== '' ? ($rosterByNo[$studentNo] ?? null) : null);
    $program = trim((string) (is_array($roster) ? ($roster['program_label'] ?? '') : ''));
    if ($program === '') {
        $program = absence_form_program_from_section($section);
    }
    $yearKey = student_roster_resolve_year_key($uid, $studentNo, $section, $yearMaps);
    $yearLabel = student_roster_year_ordinal_label($yearKey);
    if ($yearLabel === '' && is_array($roster) && isset($roster['year_level'])) {
        $yearLabel = student_roster_year_ordinal_label((string) (int) $roster['year_level']);
    }

    $first = trim((string) ($profile['first_name'] ?? ''));
    $middle = trim((string) ($profile['middle_name'] ?? ''));
    $last = trim((string) ($profile['last_name'] ?? ''));
    $suffix = trim((string) ($profile['suffix'] ?? ''));
    $name = trim($last . ', ' . $first . ($middle !== '' ? ' ' . $middle : ''));
    if ($suffix !== '') {
        $name .= ' ' . $suffix;
    }
    $name = trim($name, ' ,');

    $subjects = $schedulesByUser[strtolower($uid)] ?? [];
    $codes = [];
    $descs = [];
    $instructors = [];
    foreach ($subjects as $sub) {
        if (!is_array($sub)) {
            continue;
        }
        // Only classes that meet on the event weekday(s) count as affected.
        if ($weekdays === [] || !student_class_schedule_meets_weekdays($sub, $weekdays)) {
            continue;
        }
        $code = trim((string) ($sub['course_code'] ?? ''));
        $desc = trim((string) ($sub['course_description'] ?? ''));
        $inst = trim((string) ($sub['instructor'] ?? ''));
        if ($code === '' && $desc === '') {
            continue;
        }
        $codes[] = $code;
        $descs[] = $desc;
        $instructors[] = $inst;
    }

    // No overlapping class on the event day(s) → not affected; omit from form.
    if ($codes === [] && $descs === []) {
        continue;
    }

    $exportRows[] = [
        'name' => $name !== '' ? $name : 'Unnamed Participant',
        'program' => $program,
        'year_level' => $yearLabel,
        'section' => $section,
        'last_name' => $last,
        'first_name' => $first,
        'course_codes' => $codes,
        'course_descriptions' => $descs,
        'instructors' => $instructors,
    ];
}

if ($exportRows === []) {
    $dayNames = [
        'M' => 'Monday',
        'T' => 'Tuesday',
        'W' => 'Wednesday',
        'Th' => 'Thursday',
        'F' => 'Friday',
        'S' => 'Saturday',
        'Su' => 'Sunday',
    ];
    $labels = [];
    foreach ($weekdays as $code) {
        $labels[] = $dayNames[(string) $code] ?? (string) $code;
    }
    $dayText = $labels !== [] ? implode(', ', $labels) : 'the event day';
    absence_form_export_fail(
        422,
        'No registered students have class on ' . $dayText
            . '. Only students whose uploaded schedule overlaps the event day are included.',
        $eventId
    );
}

$exportRows = absence_form_sort_students($exportRows);

try {
    $binary = absence_form_build_docx(
        (string) ($header['dates'] ?? ''),
        (string) ($header['times'] ?? ''),
        (string) ($header['venue'] ?? ''),
        $exportRows
    );
} catch (Throwable $e) {
    absence_form_export_fail(500, 'Could not build the absence form. Please try again.', $eventId);
}

$title = preg_replace('/[^A-Za-z0-9_\-]+/', '_', (string) ($event['title'] ?? 'Event')) ?: 'Event';
$filename = 'Approved_Absence_Form_' . $title . '.docx';

header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . (string) strlen($binary));
header('Cache-Control: no-store');
echo $binary;
exit;
