<?php
declare(strict_types=1);

session_start();

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/supabase.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/event_sessions.php';
require_once __DIR__ . '/includes/event_tabs.php';

$user = require_role(['admin']);
$eventId = isset($_GET['event_id']) ? trim((string) $_GET['event_id']) : '';
if ($eventId === '') {
    http_response_code(400);
    echo 'Missing event_id';
    exit;
}

$headers = [
    'Accept: application/json',
    'apikey: ' . SUPABASE_KEY,
    'Authorization: Bearer ' . SUPABASE_KEY,
];

$writeHeaders = [
    'Accept: application/json',
    'Content-Type: application/json',
    'Prefer: return=representation,resolution=merge-duplicates',
    'apikey: ' . SUPABASE_KEY,
    'Authorization: Bearer ' . SUPABASE_KEY,
];

function load_qr_event(string $eventId, array $headers): ?array
{
    $url = rtrim(SUPABASE_URL, '/') . '/rest/v1/events'
        . '?select=id,title,status,start_at,end_at,location,created_by,users:created_by(first_name,last_name,suffix)'
        . '&id=eq.' . rawurlencode($eventId)
        . '&limit=1';
    $res = supabase_request('GET', $url, $headers);
    $rows = $res['ok'] ? json_decode((string) $res['body'], true) : [];
    return is_array($rows) && isset($rows[0]) && is_array($rows[0]) ? $rows[0] : null;
}

function load_event_teachers_with_access(string $eventId, array $headers): array
{
    $url = rtrim(SUPABASE_URL, '/') . '/rest/v1/event_teacher_assignments'
        . '?select=id,event_id,teacher_id,can_scan,can_manage_assistants'
        . '&event_id=eq.' . rawurlencode($eventId)
        . '&order=assigned_at.asc';

    $res = supabase_request('GET', $url, $headers);
    $rows = $res['ok'] ? json_decode((string) $res['body'], true) : [];
    return is_array($rows) ? $rows : [];
}

function load_teacher_profiles(array $teacherIds, array $headers): array
{
    if (empty($teacherIds)) {
        return [];
    }

    $inList = '(' . implode(',', array_map('rawurlencode', $teacherIds)) . ')';
    $url = rtrim(SUPABASE_URL, '/') . '/rest/v1/users'
        . '?select=id,first_name,middle_name,last_name,suffix,email'
        . '&role=eq.teacher'
        . '&id=in.' . $inList
        . '&order=last_name.asc,first_name.asc';

    $res = supabase_request('GET', $url, $headers);
    $rows = $res['ok'] ? json_decode((string) $res['body'], true) : [];
    if (!is_array($rows)) {
        return [];
    }

    $map = [];
    foreach ($rows as $row) {
        $teacherId = trim((string) ($row['id'] ?? ''));
        if ($teacherId !== '') {
            $map[$teacherId] = is_array($row) ? $row : [];
        }
    }

    return $map;
}

function load_all_teachers(array $headers): array
{
    $url = rtrim(SUPABASE_URL, '/') . '/rest/v1/users'
        . '?select=id,first_name,middle_name,last_name,suffix,email'
        . '&role=eq.teacher'
        . '&order=last_name.asc,first_name.asc';
    $res = supabase_request('GET', $url, $headers);
    $rows = $res['ok'] ? json_decode((string) $res['body'], true) : [];
    return is_array($rows) ? $rows : [];
}

function send_qr_assignment_notification(array $teacherIds, string $eventId, string $eventTitle): void
{
    if (empty($teacherIds)) {
        return;
    }

    require_once __DIR__ . '/includes/fcm.php';

    $inList = '(' . implode(',', array_map('rawurlencode', $teacherIds)) . ')';
    $tokensRes = supabase_request('GET',
        rtrim(SUPABASE_URL, '/') . '/rest/v1/fcm_tokens?select=token&user_id=in.' . $inList,
        ['apikey: ' . SUPABASE_KEY, 'Authorization: Bearer ' . SUPABASE_KEY]
    );

    if (!$tokensRes['ok']) {
        return;
    }

    $tokenRows = json_decode((string) $tokensRes['body'], true);
    $tokens = [];
    if (is_array($tokenRows)) {
        foreach ($tokenRows as $row) {
            $token = trim((string) ($row['token'] ?? ''));
            if ($token !== '') {
                $tokens[$token] = true;
            }
        }
    }

    if (empty($tokens)) {
        return;
    }

    $title = 'QR Scanner Access Granted';
    $body = 'You can now scan attendance and manage assistants for "' . $eventTitle . '".';
    send_fcm_notification(array_keys($tokens), $title, $body, [
        'event_id' => $eventId,
        'type' => 'teacher_qr_assigned',
    ]);
}

function send_teacher_event_assignment_notification(array $teacherIds, string $eventId, string $eventTitle): void
{
    if (empty($teacherIds)) {
        return;
    }

    require_once __DIR__ . '/includes/fcm.php';

    $inList = '(' . implode(',', array_map('rawurlencode', $teacherIds)) . ')';
    $tokensRes = supabase_request('GET',
        rtrim(SUPABASE_URL, '/') . '/rest/v1/fcm_tokens?select=token&user_id=in.' . $inList,
        ['apikey: ' . SUPABASE_KEY, 'Authorization: Bearer ' . SUPABASE_KEY]
    );

    if (!$tokensRes['ok']) {
        return;
    }

    $tokenRows = json_decode((string) $tokensRes['body'], true);
    $tokens = [];
    if (is_array($tokenRows)) {
        foreach ($tokenRows as $row) {
            $token = trim((string) ($row['token'] ?? ''));
            if ($token !== '') {
                $tokens[$token] = true;
            }
        }
    }

    if (empty($tokens)) {
        return;
    }

    $title = 'Event Assignment';
    $body = 'You were assigned to "' . $eventTitle . '". Check your event list for details.';
    send_fcm_notification(array_keys($tokens), $title, $body, [
        'event_id' => $eventId,
        'type' => 'teacher_event_assigned',
    ]);
}

function sync_teacher_assignment_state(
    string $eventId,
    string $teacherId,
    bool $enableQr,
    string $assignedBy,
    array $writeHeaders
): ?string {
    $payload = json_encode([
        'can_scan' => $enableQr,
        'can_manage_assistants' => $enableQr,
        'assigned_by' => $assignedBy,
        'assigned_at' => gmdate('c'),
    ], JSON_UNESCAPED_SLASHES);

    if (!is_string($payload)) {
        return 'Failed to prepare teacher assignment sync payload.';
    }

    $url = rtrim(SUPABASE_URL, '/') . '/rest/v1/event_teacher_assignments'
        . '?event_id=eq.' . rawurlencode($eventId)
        . '&teacher_id=eq.' . rawurlencode($teacherId);

    $res = supabase_request('PATCH', $url, $writeHeaders, $payload);
    if (!$res['ok']) {
        return build_error(
            $res['body'] ?? null,
            (int) ($res['status'] ?? 0),
            $res['error'] ?? null,
            'Failed to sync QR assignment state'
        );
    }

    return null;
}

$event = load_qr_event($eventId, $headers);
if (!is_array($event)) {
    http_response_code(404);
    echo 'Event not found';
    exit;
}

$sessions = fetch_event_sessions($eventId, $headers);
$usesSessions = count($sessions) > 0;

$assignments = load_event_teachers_with_access($eventId, $headers);
$assignmentMap = [];
foreach ($assignments as $row) {
    $teacherId = trim((string) ($row['teacher_id'] ?? ''));
    if ($teacherId === '') {
        continue;
    }
    $assignmentMap[$teacherId] = is_array($row) ? $row : [];
}

$teacherProfiles = load_teacher_profiles(array_keys($assignmentMap), $headers);
$allTeachers = load_all_teachers($headers);
$availableTeachers = [];
foreach ($allTeachers as $teacher) {
    $teacherId = trim((string) ($teacher['id'] ?? ''));
    if ($teacherId === '' || isset($assignmentMap[$teacherId])) {
        continue;
    }
    $availableTeachers[] = is_array($teacher) ? $teacher : [];
}
$teacherRows = [];
foreach ($assignmentMap as $teacherId => $assignment) {
    $teacherRows[] = [
        'teacher_id' => $teacherId,
        'can_scan' => !empty($assignment['can_scan']),
        'can_manage_assistants' => !empty($assignment['can_manage_assistants']),
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_validate(isset($_POST['csrf_token']) ? (string) $_POST['csrf_token'] : null);
    $action = trim((string) ($_POST['action'] ?? ''));

    if ($action === 'add_teacher') {
        $teacherId = trim((string) ($_POST['add_teacher_id'] ?? ''));
        if ($teacherId === '') {
            $_SESSION['flash_error'] = 'Please select a teacher to add.';
            header('Location: /event_teachers.php?event_id=' . rawurlencode($eventId));
            exit;
        }

        $exists = false;
        foreach ($allTeachers as $teacher) {
            if (($teacher['id'] ?? '') === $teacherId) {
                $exists = true;
                break;
            }
        }
        if (!$exists) {
            $_SESSION['flash_error'] = 'Selected teacher is invalid.';
            header('Location: /event_teachers.php?event_id=' . rawurlencode($eventId));
            exit;
        }

        $payload = json_encode([[
            'event_id' => $eventId,
            'teacher_id' => $teacherId,
            'can_scan' => false,
            'can_manage_assistants' => false,
            'assigned_by' => (string) ($user['id'] ?? ''),
            'assigned_at' => gmdate('c'),
        ]], JSON_UNESCAPED_SLASHES);

        if (!is_string($payload)) {
            $_SESSION['flash_error'] = 'Failed to prepare teacher assignment payload.';
            header('Location: /event_teachers.php?event_id=' . rawurlencode($eventId));
            exit;
        }

        $url = rtrim(SUPABASE_URL, '/') . '/rest/v1/event_teacher_assignments'
            . '?on_conflict=event_id,teacher_id';
        $res = supabase_request('POST', $url, $writeHeaders, $payload);
        if (!$res['ok']) {
            $_SESSION['flash_error'] = build_error($res['body'] ?? null, (int) ($res['status'] ?? 0), $res['error'] ?? null, 'Failed to add teacher assignment');
            header('Location: /event_teachers.php?event_id=' . rawurlencode($eventId));
            exit;
        }

        $syncError = sync_teacher_assignment_state(
            $eventId,
            $teacherId,
            false,
            (string) ($user['id'] ?? ''),
            $writeHeaders
        );
        if ($syncError !== null) {
            $_SESSION['flash_error'] = $syncError;
            header('Location: /event_teachers.php?event_id=' . rawurlencode($eventId));
            exit;
        }

        send_teacher_event_assignment_notification([$teacherId], $eventId, (string) ($event['title'] ?? 'Event'));
        $_SESSION['flash_success'] = 'Teacher added to event assignment list.';
        header('Location: /event_teachers.php?event_id=' . rawurlencode($eventId));
        exit;
    }

    $validTeacherIds = [];
    $currentlyEnabled = [];
    foreach ($teacherRows as $row) {
        $teacherId = trim((string) ($row['teacher_id'] ?? ''));
        $validTeacherIds[$teacherId] = true;
        if (!empty($row['can_scan'])) {
            $currentlyEnabled[$teacherId] = true;
        }
    }

    $submitted = isset($_POST['teacher_ids']) && is_array($_POST['teacher_ids']) ? $_POST['teacher_ids'] : [];
    $selected = [];
    foreach ($submitted as $rawId) {
        $teacherId = trim((string) $rawId);
        if ($teacherId !== '' && isset($validTeacherIds[$teacherId])) {
            $selected[$teacherId] = true;
        }
    }

    $newlyEnabled = [];
    $errors = [];
    $upsertRows = [];
    foreach (array_keys($validTeacherIds) as $teacherId) {
        $enableQr = isset($selected[$teacherId]);
        if ($enableQr && !isset($currentlyEnabled[$teacherId])) {
            $newlyEnabled[] = $teacherId;
        }

        $upsertRows[] = [
            'event_id' => $eventId,
            'teacher_id' => $teacherId,
            'can_scan' => $enableQr,
            'can_manage_assistants' => $enableQr,
            'assigned_by' => (string) ($user['id'] ?? ''),
            'assigned_at' => gmdate('c'),
        ];
    }

    if (count($upsertRows) > 0) {
        $payload = json_encode($upsertRows, JSON_UNESCAPED_SLASHES);
        if (!is_string($payload)) {
            $errors[] = 'Failed to prepare QR access payload.';
        } else {
            $url = rtrim(SUPABASE_URL, '/') . '/rest/v1/event_teacher_assignments'
                . '?on_conflict=event_id,teacher_id';

            $res = supabase_request('POST', $url, $writeHeaders, $payload);
            if (!$res['ok']) {
                $errors[] = build_error($res['body'] ?? null, (int) ($res['status'] ?? 0), $res['error'] ?? null, 'Failed to update QR assignment');
            }
        }
    }

    foreach (array_keys($validTeacherIds) as $teacherId) {
        $syncError = sync_teacher_assignment_state(
            $eventId,
            $teacherId,
            isset($selected[$teacherId]),
            (string) ($user['id'] ?? ''),
            $writeHeaders
        );
        if ($syncError !== null) {
            $errors[] = $syncError;
        }
    }

    if (empty($errors)) {
        send_qr_assignment_notification($newlyEnabled, $eventId, (string) ($event['title'] ?? 'Event'));
        $_SESSION['flash_success'] = 'QR scanner assignments updated successfully.';
    } else {
        $_SESSION['flash_error'] = implode(' ', array_values(array_unique($errors)));
    }

    header('Location: /event_teachers.php?event_id=' . rawurlencode($eventId));
    exit;
}

$flashSuccess = isset($_SESSION['flash_success']) ? (string) $_SESSION['flash_success'] : '';
$flashError = isset($_SESSION['flash_error']) ? (string) $_SESSION['flash_error'] : '';
unset($_SESSION['flash_success'], $_SESSION['flash_error']);

$csrfToken = csrf_ensure_token();
$status = strtolower((string) ($event['status'] ?? 'draft'));
$isFinishedEvent = $status === 'finished';
if ($isFinishedEvent) {
    header('Location: /event_view.php?id=' . rawurlencode($eventId));
    exit;
}
$statusColor = match ($status) {
    'published' => 'bg-emerald-100 text-emerald-900 border-emerald-200',
    'finished' => 'bg-zinc-200 text-zinc-700 border-zinc-300',
    'pending' => 'bg-amber-100 text-amber-900 border-amber-200',
    'approved' => 'bg-sky-100 text-sky-900 border-sky-200',
    default => 'bg-zinc-100 text-zinc-800 border-zinc-200',
};

$totalAssigned = count($teacherRows);
$totalQrEnabled = 0;
foreach ($teacherRows as $row) {
    if (!empty($row['can_scan'])) {
        $totalQrEnabled += 1;
    }
}

$creatorName = '';
if (isset($event['users']) && is_array($event['users'])) {
    $creatorName = trim((string) (($event['users']['first_name'] ?? '') . ' ' . ($event['users']['last_name'] ?? '') . ' ' . ($event['users']['suffix'] ?? '')));
}

render_header('QR Scanner Assignment', $user);
?>

<style>
  :root {
    --primary: #ea580c;
    --primary-light: #fff7ed;
    --primary-border: #fdba74;
  }

  /* Teacher Card Premium - Retained & Refined */
  .qr-teacher-card {
    position: relative;
    border: 1px solid #e4e4e7;
    background: #ffffff;
    border-radius: 1.25rem;
    padding: 1.25rem;
    display: flex;
    align-items: center;
    gap: 1.25rem;
    cursor: pointer;
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    overflow: hidden;
  }

  .qr-teacher-card::before {
    content: "";
    position: absolute;
    inset: 0;
    background: linear-gradient(120deg, rgba(234, 88, 12, 0.05) 0%, rgba(255, 255, 255, 0) 60%);
    opacity: 0;
    transition: opacity 0.3s ease;
  }

  .qr-teacher-card:hover { border-color: #f97316; box-shadow: 0 12px 24px rgba(15, 23, 42, 0.08); transform: translateY(-2px); }
  .qr-teacher-card.is-enabled { border-color: #ea580c; background: #fffafa; box-shadow: 0 8px 20px rgba(234, 88, 12, 0.06); }
  .qr-teacher-card.is-enabled::before { opacity: 1; }

  .qr-avatar {
    width: 3.5rem; height: 3.5rem; border-radius: 1rem;
    background: linear-gradient(135deg, #ea580c 0%, #f97316 100%);
    color: white; display: flex; align-items: center; justify-content: center;
    font-weight: 800; font-size: 1.125rem;
    box-shadow: 0 8px 16px rgba(234, 88, 12, 0.2);
    position: relative; z-index: 1;
  }

  /* Side Panels Aligned */
  .qr-stat-box { padding: 1rem; border-radius: 1.25rem; border: 1px solid #f1f1f4; background: #fafafa; }
  .qr-stat-box.highlight { background: #fff7ed; border-color: #ffedd5; }

  /* Checkbox Aligned */
  .qr-checkbox-wrapper { position: relative; width: 1.5rem; height: 1.5rem; flex-shrink: 0; }
  .qr-teacher-checkbox {
    appearance: none; width: 100%; height: 100%; border: 2px solid #d4d4d8; border-radius: 0.5rem;
    background: white; cursor: pointer; transition: all 0.2s ease;
    display: flex; align-items: center; justify-content: center;
  }
  .qr-teacher-checkbox:checked { background: #ea580c; border-color: #ea580c; }
  .qr-teacher-checkbox:checked::after { content: "✓"; color: white; font-size: 0.875rem; font-weight: 900; }

  /* Badge Styles */
  .qr-badge { padding: 0.25rem 0.625rem; border-radius: 9999px; font-size: 0.625rem; font-weight: 800; text-transform: uppercase; letter-spacing: 0.05em; border: 1px solid transparent; }
  .qr-badge-orange { background: #fff7ed; color: #ea580c; border-color: #ffedd5; }
  .qr-badge-sky { background: #f0f9ff; color: #0369a1; border-color: #e0f2fe; }
  .qr-badge-violet { background: #f5f3ff; color: #6d28d9; border-color: #ede9fe; }
  .qr-badge-zinc { background: #f8fafc; color: #475569; border-color: #f1f5f9; }

  .qr-btn-primary {
    background: #ea580c; color: #ffffff;
    box-shadow: 0 4px 12px rgba(234, 88, 12, 0.2);
    transition: all 0.2s ease;
  }
  .qr-btn-primary:hover { background: #f97316; transform: translateY(-1px); }
</style>

<div class="mb-4">
    <!-- Standard Header Row (Aligned with event_view.php) -->
    <div class="flex items-center justify-between flex-wrap gap-4 pb-4 border-b border-zinc-200 mb-6">
        <div class="flex items-center gap-3">
            <a href="/events.php" class="flex items-center justify-center w-8 h-8 rounded-full bg-white border border-zinc-200 hover:bg-zinc-50 text-zinc-600 transition shadow-sm">
                <svg class="w-4 h-4 mr-0.5" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5L8.25 12l7.5-7.5"/></svg>
            </a>
            <h2 class="text-xl md:text-2xl font-bold text-zinc-900"><?= htmlspecialchars((string) ($event['title'] ?? '')) ?></h2>
            <span class="text-[10px] sm:text-xs font-bold uppercase tracking-widest rounded-md border px-2 py-0.5 <?= $statusColor ?>"><?= htmlspecialchars($status) ?></span>
        </div>
        

    </div>

    <?php
    render_event_tabs([
        'event_id' => $eventId,
        'current_tab' => 'qr',
        'role' => 'admin',
        'uses_sessions' => $usesSessions,
        'event_status' => $status,
    ]);
    ?>

    <!-- Layout Grid -->
    <div class="flex flex-col xl:flex-row gap-6">
        
        <!-- Main Content Area -->
        <div class="flex-1 min-w-0">
            <!-- Feedback Alerts -->
            <?php if ($flashSuccess !== ''): ?>
                <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-4 mb-6 flex gap-3 text-emerald-800">
                    <svg class="w-5 h-5 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/></svg>
                    <span class="text-sm font-bold"><?= htmlspecialchars($flashSuccess) ?></span>
                </div>
            <?php endif; ?>

            <?php if ($flashError !== ''): ?>
                <div class="rounded-xl border border-red-200 bg-red-50 p-4 mb-6 flex gap-3 text-red-800">
                    <svg class="w-5 h-5 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd"/></svg>
                    <span class="text-sm font-bold"><?= htmlspecialchars($flashError) ?></span>
                </div>
            <?php endif; ?>

            <!-- Assignment Section -->
            <div class="rounded-2xl border border-zinc-200 bg-white shadow-sm overflow-hidden">
                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                    <input type="hidden" name="action" value="save_assignments">
                    
                    <div class="px-6 py-5 border-b border-zinc-100 bg-zinc-50/50 flex flex-wrap items-center justify-between gap-4">
                        <div>
                            <h3 class="text-lg font-bold text-zinc-900 leading-none">Teacher Assignments</h3>
                            <p class="text-xs text-zinc-500 font-medium mt-1">Select teachers allowed to scan tickets</p>
                        </div>
                        <div class="flex items-center gap-2">
                            <button type="button" id="btnOpenAddTeacherModal" class="text-xs font-bold text-white px-3 py-1.5 rounded-lg border border-orange-500 bg-orange-500 hover:bg-orange-600 transition flex items-center gap-1">
                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/></svg>
                                Add Teacher
                            </button>
                            <button type="button" id="btnEnableAllQr" class="text-xs font-bold text-zinc-600 px-3 py-1.5 rounded-lg border border-zinc-200 bg-white hover:bg-zinc-50 transition">Enable All</button>
                            <button type="button" id="btnDisableAllQr" class="text-xs font-bold text-zinc-600 px-3 py-1.5 rounded-lg border border-zinc-200 bg-white hover:bg-zinc-50 transition">Disable All</button>
                            <div class="w-px h-6 bg-zinc-200 mx-1"></div>
                            <button type="submit" class="qr-btn-primary px-4 py-2 rounded-xl text-[13px] font-bold flex items-center gap-1.5">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/></svg>
                                Save Changes
                            </button>
                        </div>
                    </div>

                    <div class="p-6">
                        <?php if ($totalAssigned === 0): ?>
                            <div class="rounded-3xl border-2 border-dashed border-zinc-200 bg-zinc-50/50 py-16 text-center">
                                <div class="w-16 h-16 rounded-full bg-zinc-100 flex items-center justify-center mx-auto mb-4 text-zinc-400">
                                    <svg class="w-8 h-8" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M18 18.72a9.094 9.094 0 003.741-.479 3 3 0 00-4.682-2.72m.94 3.198l.001.031c0 .225-.012.447-.037.666A11.944 11.944 0 0112 21c-2.17 0-4.207-.576-5.963-1.584A6.062 6.062 0 016 18.719m12 0a5.971 5.971 0 00-.941-3.197m0 0A5.995 5.995 0 0012 12.75a5.995 5.995 0 00-5.058 2.772m0 0a5.971 5.971 0 00-.94 3.197m0 0l.001.031c0 .225.012.447.037.666A11.944 11.944 0 0112 21c2.17 0 4.207-.576 5.963-1.584A6.062 6.062 0 0118 18.722m-12 0a5.971 5.971 0 00.941-3.197m0 0A5.995 5.995 0 0012 12.75a5.995 5.995 0 005.058 2.772m0 0a5.971 5.971 0 00.94 3.197M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zm.45-1.125a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zM19.35 15.45a2.1 2.1 0 11-4.2 0 2.1 2.1 0 014.2 0zM6.75 15.45a2.1 2.1 0 11-4.2 0 2.1 2.1 0 014.2 0z"/></svg>
                                </div>
                                <h4 class="text-zinc-900 font-black text-lg mb-1">No Assigned Teachers Yet</h4>
                                <p class="text-xs text-zinc-500 font-medium">Only teachers assigned by admin during publish will appear here.</p>
                            </div>
                        <?php else: ?>
                            <div class="grid grid-cols-1 gap-4" id="qrTeacherList">
                                <?php foreach ($teacherRows as $row): ?>
                                    <?php
                                    $teacherId = trim((string) ($row['teacher_id'] ?? ''));
                                    if ($teacherId === '') continue;
                                    $profile = is_array($teacherProfiles[$teacherId] ?? null) ? $teacherProfiles[$teacherId] : [];
                                    $fullName = trim((string) (($profile['first_name'] ?? '') . ' ' . ($profile['middle_name'] ?? '') . ' ' . ($profile['last_name'] ?? '') . ' ' . ($profile['suffix'] ?? '')));
                                    $email = (string) ($profile['email'] ?? '');
                                    $isQrEnabled = !empty($row['can_scan']);

                                    $initialsParts = preg_split('/\s+/', trim($fullName)) ?: [];
                                    $initials = '';
                                    foreach ($initialsParts as $part) {
                                        if ($part !== '') $initials .= strtoupper($part[0]);
                                        if (strlen($initials) >= 2) break;
                                    }
                                    if ($initials === '') $initials = 'T';
                                    ?>
                                    <label class="qr-teacher-card <?= $isQrEnabled ? 'is-enabled' : '' ?>">
                                        <div class="qr-checkbox-wrapper">
                                            <input type="checkbox" name="teacher_ids[]" value="<?= htmlspecialchars($teacherId) ?>" class="qr-teacher-checkbox" <?= $isQrEnabled ? 'checked' : '' ?>>
                                        </div>
                                        
                                        <div class="qr-avatar"><?= htmlspecialchars($initials) ?></div>
                                        
                                        <div class="flex-1 min-w-0">
                                            <div class="flex items-center gap-2 mb-1">
                                                <h4 class="text-sm font-black text-zinc-900 truncate"><?= htmlspecialchars($fullName ?: 'Unknown Staff') ?></h4>
                                                <span class="qr-badge qr-badge-zinc">COORDINATOR</span>
                                                <?php if($isQrEnabled): ?>
                                                    <span class="qr-badge qr-badge-orange">SCANNER ACTIVE</span>
                                                <?php endif; ?>
                                            </div>
                                            <div class="text-[11px] font-bold text-zinc-400 mb-2"><?= htmlspecialchars($email) ?></div>
                                            <div class="flex flex-wrap gap-2">
                                                <?php if($isQrEnabled): ?>
                                                    <span class="qr-badge qr-badge-sky flex items-center gap-1">
                                                        <svg class="w-3 h-3" fill="none" stroke="currentColor" stroke-width="3" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M7.5 3.75H6A2.25 2.25 0 003.75 6v1.5M16.5 3.75H18A2.25 2.25 0 0120.25 6v1.5m0 9V18A2.25 2.25 0 0118 20.25h-1.5m-9 0H6A2.25 2.25 0 013.75 18v-1.5M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                                                        Scanner
                                                    </span>
                                                    <span class="qr-badge qr-badge-violet flex items-center gap-1">
                                                        <svg class="w-3 h-3" fill="none" stroke="currentColor" stroke-width="3" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M18 18.72a9.094 9.094 0 003.741-.479 3 3 0 00-4.682-2.72m.94 3.198l.001.031c0 .225-.012.447-.037.666A11.944 11.944 0 0112 21c-2.17 0-4.207-.576-5.963-1.584A6.062 6.062 0 016 18.719m12 0a5.971 5.971 0 00-.941-3.197m0 0A5.995 5.995 0 0012 12.75a5.995 5.995 0 00-5.058 2.772m0 0a5.971 5.971 0 00-.94 3.197m0 0l.001.031c0 .225.012.447.037.666A11.944 11.944 0 0112 21c2.17 0 4.207-.576 5.963-1.584A6.062 6.062 0 0118 18.722m-12 0a5.971 5.971 0 00.941-3.197m0 0A5.995 5.995 0 0012 12.75a5.995 5.995 0 005.058 2.772m0 0a5.971 5.971 0 00.94 3.197M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zm.45-1.125a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zM19.35 15.45a2.1 2.1 0 11-4.2 0 2.1 2.1 0 014.2 0zM6.75 15.45a2.1 2.1 0 11-4.2 0 2.1 2.1 0 014.2 0z"/></svg>
                                                        Assistant Manager
                                                    </span>
                                                <?php else: ?>
                                                    <span class="qr-badge qr-badge-zinc">No scanner access</span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>

        <!-- Sidebar (Aligned with event_view.php) -->
        <div class="w-full xl:w-80 flex-shrink-0 flex flex-col gap-4">
            
            <div class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm">
                <h4 class="text-[11px] font-black uppercase tracking-[0.15em] text-zinc-400 mb-4">Scanner Summary</h4>
                <div class="grid grid-cols-1 gap-3">
                    <div class="qr-stat-box">
                        <div class="text-[10px] font-black uppercase tracking-widest text-zinc-400 mb-1">Total Assigned</div>
                        <div class="text-2xl font-black text-zinc-900"><?= $totalAssigned ?></div>
                    </div>
                    <div class="qr-stat-box highlight">
                        <div class="text-[10px] font-black uppercase tracking-widest text-orange-600 mb-1">Scanning Enabled</div>
                        <div id="qrEnabledCount" class="text-2xl font-black text-orange-700"><?= $totalQrEnabled ?></div>
                    </div>
                </div>
            </div>

            <div class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm">
                <h4 class="text-xs font-black text-zinc-900 mb-4 flex items-center gap-2">
                    <span class="w-1 h-5 bg-orange-500 rounded-full"></span>
                    Event Context
                </h4>
                <div class="space-y-4">
                    <div>
                        <div class="text-[10px] font-black uppercase tracking-widest text-zinc-400 mb-1">Date & Time</div>
                        <p class="text-[13px] font-bold text-zinc-700 leading-tight"><?= htmlspecialchars(format_date_local((string) ($event['start_at'] ?? ''), 'M j, Y • g:i A')) ?></p>
                    </div>
                    <div>
                        <div class="text-[10px] font-black uppercase tracking-widest text-zinc-400 mb-1">Venue</div>
                        <p class="text-[13px] font-bold text-zinc-700"><?= htmlspecialchars((string) ($event['location'] ?? 'TBA')) ?></p>
                    </div>
                </div>
            </div>

            <div class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm">
                <h4 class="text-xs font-black text-zinc-900 mb-4 flex items-center gap-2">
                    <svg class="w-4 h-4 text-orange-500" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    Privilege Notes
                </h4>
                <ul class="space-y-3">
                   <li class="flex items-start gap-2.5">
                     <div class="w-1.5 h-1.5 rounded-full bg-orange-500 mt-1.5 flex-shrink-0"></div>
                     <p class="text-[11px] text-zinc-500 font-medium leading-relaxed">Enabled staff can use the <span class="text-zinc-900 font-bold">In-App Attendance Scanner</span>.</p>
                   </li>
                   <li class="flex items-start gap-2.5">
                     <div class="w-1.5 h-1.5 rounded-full bg-emerald-500 mt-1.5 flex-shrink-0"></div>
                     <p class="text-[11px] text-zinc-500 font-medium leading-relaxed">Authority to <span class="text-zinc-900 font-bold">Assign Student Assistants</span>.</p>
                   </li>
                </ul>
            </div>
            
        </div>
    </div>
</div>

<div id="addTeacherModal" class="fixed inset-0 z-50 hidden items-center justify-center bg-black/50 p-4">
  <div class="w-full max-w-md rounded-2xl border border-orange-100 bg-white p-5 shadow-xl">
    <div class="flex items-center justify-between mb-3">
      <h4 class="text-base font-black text-zinc-900 flex items-center gap-2">
        <span class="inline-flex h-7 w-7 items-center justify-center rounded-full bg-orange-100 text-orange-600">
          <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M18 18.72a9.094 9.094 0 003.741-.479 3 3 0 00-4.682-2.72m.94 3.198l.001.031c0 .225-.012.447-.037.666A11.944 11.944 0 0112 21c-2.17 0-4.207-.576-5.963-1.584A6.062 6.062 0 016 18.719m12 0a5.971 5.971 0 00-.941-3.197m0 0A5.995 5.995 0 0012 12.75a5.995 5.995 0 00-5.058 2.772m0 0a5.971 5.971 0 00-.94 3.197m0 0l.001.031c0 .225.012.447.037.666A11.944 11.944 0 0112 21c2.17 0 4.207-.576 5.963-1.584A6.062 6.062 0 0118 18.722m-12 0a5.971 5.971 0 00.941-3.197m0 0A5.995 5.995 0 0012 12.75a5.995 5.995 0 005.058 2.772m0 0a5.971 5.971 0 00.94 3.197M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0z"/></svg>
        </span>
        Add Teacher to Event
      </h4>
      <button type="button" id="btnCloseAddTeacherModal" class="text-zinc-500 hover:text-zinc-700">✕</button>
    </div>
    <p class="text-xs text-zinc-500 mb-4">Selected teacher will appear in this list and receive assignment notification.</p>

    <form method="post" class="space-y-4">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
      <input type="hidden" name="action" value="add_teacher">
      <select
        name="add_teacher_id"
        required
        class="w-full rounded-xl border border-zinc-300 px-3 py-2 text-sm font-medium text-zinc-800 focus:outline-none focus:ring-2 focus:ring-orange-300 focus:border-orange-400"
      >
        <option value="">Select teacher...</option>
        <?php foreach ($availableTeachers as $teacher): ?>
          <?php
          $tid = trim((string) ($teacher['id'] ?? ''));
          if ($tid === '') continue;
          $label = trim((string) (($teacher['first_name'] ?? '') . ' ' . ($teacher['last_name'] ?? '') . ' ' . ($teacher['suffix'] ?? '')));
          $mail = trim((string) ($teacher['email'] ?? ''));
          ?>
          <option value="<?= htmlspecialchars($tid) ?>">
            <?= htmlspecialchars($label !== '' ? $label : 'Teacher') ?><?= $mail !== '' ? ' - ' . htmlspecialchars($mail) : '' ?>
          </option>
        <?php endforeach; ?>
      </select>
      <div class="flex items-center justify-end gap-2">
        <button type="button" id="btnCancelAddTeacherModal" class="rounded-lg border border-zinc-200 bg-white px-3 py-1.5 text-xs font-bold text-zinc-600 hover:bg-zinc-50">Cancel</button>
        <button type="submit" class="rounded-lg bg-orange-500 px-3 py-1.5 text-xs font-bold text-white hover:bg-orange-600">Add Teacher</button>
      </div>
    </form>
  </div>
</div>

<script>
  (function () {
    const checkboxes = Array.from(document.querySelectorAll('.qr-teacher-checkbox'));
    const cards = Array.from(document.querySelectorAll('.qr-teacher-card'));
    const countEl = document.getElementById('qrEnabledCount');
    const btnEnableAll = document.getElementById('btnEnableAllQr');
    const btnDisableAll = document.getElementById('btnDisableAllQr');
    const addTeacherModal = document.getElementById('addTeacherModal');
    const btnOpenAddTeacherModal = document.getElementById('btnOpenAddTeacherModal');
    const btnCloseAddTeacherModal = document.getElementById('btnCloseAddTeacherModal');
    const btnCancelAddTeacherModal = document.getElementById('btnCancelAddTeacherModal');

    function refreshState() {
      let total = 0;
      checkboxes.forEach((checkbox, index) => {
        const checked = !!checkbox.checked;
        const card = cards[index];

        if (checked) {
          total += 1;
          card?.classList.add('is-enabled');
          const badge = card?.querySelector('.qr-badge-orange');
          if (!badge) {
            const h4 = card?.querySelector('h4');
            if (h4) {
              const span = document.createElement('span');
              span.className = 'qr-badge qr-badge-orange';
              span.textContent = 'SCANNER ACTIVE';
              h4.insertAdjacentElement('afterend', span);
            }
          }
        } else {
          card?.classList.remove('is-enabled');
          card?.querySelector('.qr-badge-orange')?.remove();
        }
      });

      if (countEl) countEl.textContent = String(total);
    }

    checkboxes.forEach((checkbox) => {
      checkbox.addEventListener('change', refreshState);
    });

    btnEnableAll?.addEventListener('click', () => {
      checkboxes.forEach((checkbox) => checkbox.checked = true);
      refreshState();
    });

    btnDisableAll?.addEventListener('click', () => {
      checkboxes.forEach((checkbox) => checkbox.checked = false);
      refreshState();
    });

    function closeAddTeacherModal() {
      addTeacherModal?.classList.add('hidden');
      addTeacherModal?.classList.remove('flex');
    }
    function openAddTeacherModal() {
      addTeacherModal?.classList.remove('hidden');
      addTeacherModal?.classList.add('flex');
    }

    btnOpenAddTeacherModal?.addEventListener('click', openAddTeacherModal);
    btnCloseAddTeacherModal?.addEventListener('click', closeAddTeacherModal);
    btnCancelAddTeacherModal?.addEventListener('click', closeAddTeacherModal);
    addTeacherModal?.addEventListener('click', (e) => {
      if (e.target === addTeacherModal) closeAddTeacherModal();
    });

    // Initial Refresh
    refreshState();
  })();
</script>

<?php render_footer(); ?>
