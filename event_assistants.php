<?php
declare(strict_types=1);

/**
 * Teacher/admin Assist Student tab — assign registered participants as ticket scanners.
 * Mirrors Flutter teacher_event_manage Assistants tab (assign + allow_scan toggle).
 */

require_once __DIR__ . '/includes/session.php';
session_bootstrap();

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/supabase.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/event_sessions.php';
require_once __DIR__ . '/includes/event_tabs.php';
require_once __DIR__ . '/includes/registration_access.php';
require_once __DIR__ . '/includes/student_requirements.php';
require_once __DIR__ . '/includes/mobile_secure_access.php';

$user = require_role(['admin', 'teacher']);
$role = strtolower(trim((string) ($user['role'] ?? 'teacher')));
$userId = trim((string) ($user['id'] ?? ''));
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
    'Prefer: return=representation',
    'apikey: ' . SUPABASE_KEY,
    'Authorization: Bearer ' . SUPABASE_KEY,
];

$returnTo = event_management_return_to($role, isset($_GET['return_to']) ? (string) $_GET['return_to'] : null);
$redirectBase = '/event_assistants?event_id=' . rawurlencode($eventId)
    . '&return_to=' . rawurlencode($returnTo);

function assist_person_name(array $profile): string
{
    $name = trim(implode(' ', array_filter([
        (string) ($profile['first_name'] ?? ''),
        (string) ($profile['middle_name'] ?? ''),
        (string) ($profile['last_name'] ?? ''),
        (string) ($profile['suffix'] ?? ''),
    ], static fn($p) => trim((string) $p) !== '')));

    return $name !== '' ? $name : 'Student';
}

function assist_student_number(array $profile): string
{
    $sid = trim((string) ($profile['student_id'] ?? ''));
    if ($sid !== '' && strtolower($sid) !== 'null') {
        return $sid;
    }
    return 'N/A';
}

function assist_initials(string $fullName): string
{
    $parts = preg_split('/\s+/', trim($fullName)) ?: [];
    $initials = '';
    foreach ($parts as $part) {
        if ($part === '') {
            continue;
        }
        $initials .= strtoupper($part[0]);
        if (strlen($initials) >= 2) {
            break;
        }
    }
    return $initials !== '' ? $initials : 'S';
}

function load_assist_event(string $eventId, array $headers): ?array
{
    $url = rtrim(SUPABASE_URL, '/') . '/rest/v1/events'
        . '?select=id,title,status,start_at,end_at,location,created_by,is_free_event'
        . '&id=eq.' . rawurlencode($eventId)
        . '&limit=1';
    $res = supabase_request('GET', $url, $headers);
    $rows = $res['ok'] ? json_decode((string) $res['body'], true) : [];
    return is_array($rows) && isset($rows[0]) && is_array($rows[0]) ? $rows[0] : null;
}

function load_event_assistants(string $eventId, array $headers): array
{
    $url = rtrim(SUPABASE_URL, '/') . '/rest/v1/event_assistants'
        . '?select=' . rawurlencode(
            'id,event_id,student_id,allow_scan,assigned_by_teacher_id,assigned_at,'
            . 'users:student_id(first_name,middle_name,last_name,suffix,email,student_id)'
        )
        . '&event_id=eq.' . rawurlencode($eventId)
        . '&order=assigned_at.desc'
        . '&limit=200';
    $res = supabase_request('GET', $url, $headers);
    if (!$res['ok']) {
        // Legacy select without assigned_at.
        $url = rtrim(SUPABASE_URL, '/') . '/rest/v1/event_assistants'
            . '?select=' . rawurlencode(
                'id,event_id,student_id,allow_scan,assigned_by_teacher_id,'
                . 'users:student_id(first_name,middle_name,last_name,suffix,email,student_id)'
            )
            . '&event_id=eq.' . rawurlencode($eventId)
            . '&order=student_id.asc'
            . '&limit=200';
        $res = supabase_request('GET', $url, $headers);
    }
    $rows = $res['ok'] ? json_decode((string) $res['body'], true) : [];
    return is_array($rows) ? $rows : [];
}

function load_registered_participants(string $eventId, array $headers): array
{
    $url = rtrim(SUPABASE_URL, '/') . '/rest/v1/event_registrations'
        . '?select=' . rawurlencode(
            'id,student_id,registered_at,'
            . 'users:student_id(first_name,middle_name,last_name,suffix,email,student_id)'
        )
        . '&event_id=eq.' . rawurlencode($eventId)
        . '&order=registered_at.desc'
        . '&limit=500';
    $res = supabase_request('GET', $url, $headers);
    $rows = $res['ok'] ? json_decode((string) $res['body'], true) : [];
    return is_array($rows) ? $rows : [];
}

function notify_assistant_assignment(string $studentId, string $eventId, string $eventTitle, bool $allowScan): void
{
    require_once __DIR__ . '/includes/user_notifications.php';
    $title = $allowScan ? 'Scanner assistant access granted' : 'Scanner assistant access updated';
    $body = $allowScan
        ? 'You can now scan attendance tickets for "' . $eventTitle . '".'
        : 'Your scanner access for "' . $eventTitle . '" was updated.';
    dispatch_user_notifications([$studentId], $title, $body, [
        'event_id' => $eventId,
        'type' => 'assistant_assignment',
        'allow_scan' => $allowScan ? '1' : '0',
    ]);
}

function notify_assistant_removal(string $studentId, string $eventId, string $eventTitle): void
{
    require_once __DIR__ . '/includes/user_notifications.php';
    dispatch_user_notifications(
        [$studentId],
        'Removed as assistant scanner',
        'You can no longer scan attendance tickets for "' . $eventTitle . '".',
        [
            'event_id' => $eventId,
            'type' => 'assistant_assignment',
            'allow_scan' => '0',
            'removed' => '1',
        ]
    );
}

/**
 * @param list<string> $studentIds
 * @return array{ok:bool, assigned:int, failed:int, error?:string}
 */
function assign_event_assistants_bulk(
    string $eventId,
    array $studentIds,
    string $teacherId,
    string $eventTitle,
    array $candidates,
    array $assignedStudentIds,
    array $writeHeaders
): array {
    $eligibleIds = [];
    foreach ($candidates as $c) {
        $sid = trim((string) ($c['student_id'] ?? ''));
        if ($sid !== '') {
            $eligibleIds[$sid] = true;
        }
    }

    $unique = [];
    foreach ($studentIds as $sid) {
        $sid = trim((string) $sid);
        if ($sid === '') {
            continue;
        }
        $unique[$sid] = true;
    }

    $assigned = 0;
    $failed = 0;
    $lastError = null;

    foreach (array_keys($unique) as $studentId) {
        $ok = isset($eligibleIds[$studentId]) || isset($assignedStudentIds[$studentId]);
        if (!$ok) {
            $failed++;
            $lastError = 'Only registered participants of this event can be assigned as assistants.';
            continue;
        }

        $result = upsert_event_assistant($eventId, $studentId, $teacherId, true, $writeHeaders);
        if (!($result['ok'] ?? false)) {
            $failed++;
            $lastError = (string) ($result['error'] ?? 'Failed to assign assistant.');
            continue;
        }

        notify_assistant_assignment($studentId, $eventId, $eventTitle, true);
        $assigned++;
    }

    if ($assigned === 0 && $failed > 0) {
        return [
            'ok' => false,
            'assigned' => 0,
            'failed' => $failed,
            'error' => $lastError ?? 'Failed to assign assistants.',
        ];
    }

    return [
        'ok' => $assigned > 0,
        'assigned' => $assigned,
        'failed' => $failed,
        'error' => $failed > 0 ? ($lastError ?? null) : null,
    ];
}

function upsert_event_assistant(
    string $eventId,
    string $studentId,
    string $teacherId,
    bool $allowScan,
    array $writeHeaders
): array {
    $nowIso = gmdate('c');
    $payload = [
        'event_id' => $eventId,
        'student_id' => $studentId,
        'allow_scan' => $allowScan,
        'assigned_by_teacher_id' => $teacherId,
        'assigned_at' => $nowIso,
    ];

    $patchUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/event_assistants'
        . '?event_id=eq.' . rawurlencode($eventId)
        . '&student_id=eq.' . rawurlencode($studentId);

    $res = supabase_request(
        'PATCH',
        $patchUrl,
        $writeHeaders,
        json_encode([
            'allow_scan' => $allowScan,
            'assigned_by_teacher_id' => $teacherId,
            'assigned_at' => $nowIso,
        ], JSON_UNESCAPED_SLASHES)
    );
    $patched = json_decode((string) ($res['body'] ?? ''), true);
    if (($res['ok'] ?? false) && is_array($patched) && count($patched) > 0) {
        return ['ok' => true, 'assistant' => $patched[0]];
    }

    $res = supabase_request(
        'POST',
        rtrim(SUPABASE_URL, '/') . '/rest/v1/event_assistants',
        $writeHeaders,
        json_encode($payload, JSON_UNESCAPED_SLASHES)
    );
    if (!($res['ok'] ?? false)) {
        unset($payload['assigned_by_teacher_id']);
        $res = supabase_request(
            'POST',
            rtrim(SUPABASE_URL, '/') . '/rest/v1/event_assistants',
            $writeHeaders,
            json_encode($payload, JSON_UNESCAPED_SLASHES)
        );
    }
    $rows = json_decode((string) ($res['body'] ?? ''), true);
    return [
        'ok' => (bool) ($res['ok'] ?? false),
        'assistant' => is_array($rows) && isset($rows[0]) ? $rows[0] : $payload,
        'error' => ($res['ok'] ?? false)
            ? null
            : build_error($res['body'] ?? null, (int) ($res['status'] ?? 0), $res['error'] ?? null, 'Failed to assign assistant'),
    ];
}

$event = load_assist_event($eventId, $headers);
if (!is_array($event)) {
    http_response_code(404);
    echo 'Event not found';
    exit;
}

$status = strtolower(trim((string) ($event['status'] ?? '')));
$isFinished = $status === 'finished';
$isExpired = $status === 'expired';
$isPublished = $status === 'published';
if ($isFinished) {
    header('Location: /event_view?id=' . rawurlencode($eventId) . '&return_to=' . rawurlencode($returnTo));
    exit;
}
if (!$isPublished && !$isExpired) {
    header('Location: /event_view?id=' . rawurlencode($eventId) . '&return_to=' . rawurlencode($returnTo));
    exit;
}

$isAdmin = mobile_secure_is_admin_role($role);
$isCreator = ((string) ($event['created_by'] ?? '') === $userId);
$isStaff = $isAdmin || $isCreator || mobile_secure_is_event_staff($eventId, $userId, $headers, false);
if (!$isStaff) {
    http_response_code(403);
    echo 'Forbidden';
    exit;
}

$canManage = $isAdmin || mobile_secure_can_manage_assistants($eventId, $userId, $headers);
$managementLocked = !$canManage || $isExpired;

$sessions = fetch_event_sessions($eventId, $headers);
$usesSessions = count($sessions) > 0;
$assistants = load_event_assistants($eventId, $headers);
$participants = load_registered_participants($eventId, $headers);

$assignedStudentIds = [];
foreach ($assistants as $row) {
    $sid = trim((string) ($row['student_id'] ?? ''));
    if ($sid !== '') {
        $assignedStudentIds[$sid] = true;
    }
}

$candidates = [];
foreach ($participants as $p) {
    $sid = trim((string) ($p['student_id'] ?? ''));
    if ($sid === '' || isset($assignedStudentIds[$sid])) {
        continue;
    }
    $profile = is_array($p['users'] ?? null) ? $p['users'] : [];
    $candidates[] = [
        'student_id' => $sid,
        'name' => assist_person_name($profile),
        'id_number' => assist_student_number($profile),
        'email' => trim((string) ($profile['email'] ?? '')),
    ];
}
usort($candidates, static fn(array $a, array $b): int => strcasecmp($a['name'], $b['name']));

$eventTitle = trim((string) ($event['title'] ?? 'Event'));
if ($eventTitle === '') {
    $eventTitle = 'Event';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_validate(isset($_POST['csrf_token']) ? (string) $_POST['csrf_token'] : null);
    $action = trim((string) ($_POST['action'] ?? ''));

    if ($managementLocked) {
        $_SESSION['flash_error'] = $isExpired
            ? 'This event has ended. Assistant management is disabled.'
            : 'Only teachers assigned by admin (QR scanner access) can manage assistants for this event.';
        header('Location: ' . $redirectBase);
        exit;
    }

    if ($action === 'assign_assistant') {
        $studentIds = [];
        if (isset($_POST['student_ids']) && is_array($_POST['student_ids'])) {
            foreach ($_POST['student_ids'] as $sid) {
                $sid = trim((string) $sid);
                if ($sid !== '') {
                    $studentIds[] = $sid;
                }
            }
        }
        $singleId = trim((string) ($_POST['student_id'] ?? ''));
        if ($singleId !== '') {
            $studentIds[] = $singleId;
        }

        if ($studentIds === []) {
            $_SESSION['flash_error'] = 'Please select at least one student to assign.';
            header('Location: ' . $redirectBase);
            exit;
        }

        $result = assign_event_assistants_bulk(
            $eventId,
            $studentIds,
            $userId,
            $eventTitle,
            $candidates,
            $assignedStudentIds,
            $writeHeaders
        );

        if (!($result['ok'] ?? false)) {
            $_SESSION['flash_error'] = (string) ($result['error'] ?? 'Failed to assign assistants. Please try again.');
            header('Location: ' . $redirectBase);
            exit;
        }

        $n = (int) ($result['assigned'] ?? 0);
        $failed = (int) ($result['failed'] ?? 0);
        if ($n === 1 && $failed === 0) {
            $_SESSION['flash_success'] = 'Student assigned as assistant scanner.';
        } elseif ($failed > 0) {
            $_SESSION['flash_success'] = $n . ' student(s) assigned. ' . $failed . ' could not be assigned.';
        } else {
            $_SESSION['flash_success'] = $n . ' students assigned as assistant scanners.';
        }
        header('Location: ' . $redirectBase);
        exit;
    }

    if ($action === 'remove_assistant') {
        $assistantId = trim((string) ($_POST['assistant_id'] ?? ''));
        $studentId = trim((string) ($_POST['student_id'] ?? ''));

        if ($assistantId === '' && $studentId === '') {
            $_SESSION['flash_error'] = 'Missing assistant identity.';
            header('Location: ' . $redirectBase);
            exit;
        }

        $notifyStudentId = $studentId;
        if ($notifyStudentId === '') {
            foreach ($assistants as $row) {
                if (trim((string) ($row['id'] ?? '')) === $assistantId) {
                    $notifyStudentId = trim((string) ($row['student_id'] ?? ''));
                    break;
                }
            }
        }

        $url = rtrim(SUPABASE_URL, '/') . '/rest/v1/event_assistants?';
        if ($assistantId !== '') {
            $url .= 'id=eq.' . rawurlencode($assistantId);
        } else {
            $url .= 'event_id=eq.' . rawurlencode($eventId)
                . '&student_id=eq.' . rawurlencode($studentId);
        }

        $res = supabase_request('DELETE', $url, $writeHeaders);
        if (!($res['ok'] ?? false)) {
            $_SESSION['flash_error'] = build_error(
                $res['body'] ?? null,
                (int) ($res['status'] ?? 0),
                $res['error'] ?? null,
                'Failed to remove assistant'
            );
            header('Location: ' . $redirectBase);
            exit;
        }

        if ($notifyStudentId !== '') {
            notify_assistant_removal($notifyStudentId, $eventId, $eventTitle);
        }

        $_SESSION['flash_success'] = 'Assistant removed from this event.';
        header('Location: ' . $redirectBase);
        exit;
    }

    if ($action === 'toggle_access') {
        $assistantId = trim((string) ($_POST['assistant_id'] ?? ''));
        $studentId = trim((string) ($_POST['student_id'] ?? ''));
        $allowScan = isset($_POST['allow_scan']) && (string) $_POST['allow_scan'] === '1';

        if ($assistantId === '' && $studentId === '') {
            $_SESSION['flash_error'] = 'Missing assistant identity.';
            header('Location: ' . $redirectBase);
            exit;
        }

        $nowIso = gmdate('c');
        $patch = [
            'allow_scan' => $allowScan,
            'assigned_by_teacher_id' => $userId,
            'assigned_at' => $nowIso,
        ];
        $url = rtrim(SUPABASE_URL, '/') . '/rest/v1/event_assistants?';
        if ($assistantId !== '') {
            $url .= 'id=eq.' . rawurlencode($assistantId);
        } else {
            $url .= 'event_id=eq.' . rawurlencode($eventId) . '&student_id=eq.' . rawurlencode($studentId);
        }

        $res = supabase_request('PATCH', $url, $writeHeaders, json_encode($patch, JSON_UNESCAPED_SLASHES));
        if (!($res['ok'] ?? false)) {
            unset($patch['assigned_by_teacher_id']);
            $res = supabase_request('PATCH', $url, $writeHeaders, json_encode($patch, JSON_UNESCAPED_SLASHES));
        }

        if (!($res['ok'] ?? false)) {
            $_SESSION['flash_error'] = build_error(
                $res['body'] ?? null,
                (int) ($res['status'] ?? 0),
                $res['error'] ?? null,
                'Failed to update assistant access'
            );
            header('Location: ' . $redirectBase);
            exit;
        }

        $notifyStudentId = $studentId;
        if ($notifyStudentId === '') {
            foreach ($assistants as $row) {
                if (trim((string) ($row['id'] ?? '')) === $assistantId) {
                    $notifyStudentId = trim((string) ($row['student_id'] ?? ''));
                    break;
                }
            }
        }
        if ($notifyStudentId !== '') {
            notify_assistant_assignment($notifyStudentId, $eventId, $eventTitle, $allowScan);
        }

        $_SESSION['flash_success'] = $allowScan
            ? 'Scanner access enabled for assistant.'
            : 'Scanner access disabled for assistant.';
        header('Location: ' . $redirectBase);
        exit;
    }

    $_SESSION['flash_error'] = 'Unknown action.';
    header('Location: ' . $redirectBase);
    exit;
}

$flashSuccess = isset($_SESSION['flash_success']) ? (string) $_SESSION['flash_success'] : '';
$flashError = isset($_SESSION['flash_error']) ? (string) $_SESSION['flash_error'] : '';
unset($_SESSION['flash_success'], $_SESSION['flash_error']);

$csrfToken = csrf_ensure_token();
$statusColor = match ($status) {
    'published' => 'bg-emerald-100 text-emerald-900 border-emerald-200',
    'finished', 'expired' => 'bg-zinc-200 text-zinc-700 border-zinc-300',
    default => 'bg-zinc-100 text-zinc-800 border-zinc-200',
};

$totalAssistants = count($assistants);

render_header('Assist Student', $user);
?>

<style>
  .assist-card {
    border: 1px solid #e4e4e7;
    background: #fff;
    border-radius: 1.25rem;
    padding: 1rem 1.15rem;
    display: flex;
    align-items: center;
    gap: 1rem;
    transition: border-color .2s ease, box-shadow .2s ease;
  }
  .assist-card.is-enabled {
    border-color: #fdba74;
    background: #fffaf5;
    box-shadow: 0 8px 20px rgba(234, 88, 12, 0.06);
  }
  .assist-avatar {
    width: 2.75rem; height: 2.75rem; border-radius: 9999px;
    background: linear-gradient(135deg, #ea580c 0%, #f97316 100%);
    color: #fff; display: flex; align-items: center; justify-content: center;
    font-weight: 800; font-size: 0.9rem; flex-shrink: 0;
  }
  .assist-candidate {
    width: 100%; text-align: left; border: 1px solid #e4e4e7; border-radius: 1rem;
    padding: 0.85rem 1rem; background: #fff; transition: border-color .15s, background .15s;
    display: flex; align-items: center; gap: 0.75rem; cursor: pointer;
  }
  .assist-candidate:hover { border-color: #fb923c; background: #fff7ed; }
  .assist-candidate.is-checked { border-color: #fb923c; background: #fff7ed; }
  .assist-candidate input[type="checkbox"] {
    width: 1.1rem; height: 1.1rem; accent-color: #ea580c; flex-shrink: 0; cursor: pointer;
  }
  .assist-remove-btn {
    all: unset;
    box-sizing: border-box;
    color: #71717a;
    font: 500 1.4rem/1 system-ui, Segoe UI, sans-serif;
    cursor: pointer;
    -webkit-tap-highlight-color: transparent;
  }
  .assist-remove-btn:hover,
  .assist-remove-btn:focus,
  .assist-remove-btn:active {
    color: #dc2626;
  }
</style>

<div class="mb-4">
  <div class="flex items-center justify-between flex-wrap gap-4 pb-4 border-b border-zinc-200 mb-6">
    <div class="flex items-center gap-3 min-w-0">
      <?= render_event_back_button($returnTo) ?>
      <h2 class="text-xl md:text-2xl font-bold text-zinc-900 truncate"><?= htmlspecialchars($eventTitle) ?></h2>
      <span class="text-[10px] sm:text-xs font-bold uppercase tracking-widest rounded-md border px-2 py-0.5 <?= $statusColor ?>"><?= htmlspecialchars($status) ?></span>
    </div>
  </div>

  <?php
  render_event_tabs([
      'event_id' => $eventId,
      'current_tab' => 'assistants',
      'role' => $role,
      'uses_sessions' => $usesSessions,
      'event_status' => $status,
      'return_to' => $returnTo,
      'has_student_requirements' => event_has_student_requirements($eventId, $headers),
      'is_event_creator' => $isCreator || $isAdmin,
      'is_paid_event' => !event_is_free_registration_event($event),
  ]);
  ?>

  <div class="flex flex-col xl:flex-row gap-6">
    <div class="flex-1 min-w-0">
      <?php if ($flashSuccess !== ''): ?>
        <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-4 mb-6 flex gap-3 text-emerald-800">
          <span class="text-sm font-bold"><?= htmlspecialchars($flashSuccess) ?></span>
        </div>
      <?php endif; ?>
      <?php if ($flashError !== ''): ?>
        <div class="rounded-xl border border-red-200 bg-red-50 p-4 mb-6 flex gap-3 text-red-800">
          <span class="text-sm font-bold"><?= htmlspecialchars($flashError) ?></span>
        </div>
      <?php endif; ?>

      <div class="rounded-2xl border border-zinc-200 bg-white shadow-sm overflow-hidden">
        <div class="px-6 py-5 border-b border-zinc-100 bg-zinc-50/50 flex flex-wrap items-center justify-between gap-4">
          <div>
            <h3 class="text-lg font-bold text-zinc-900 leading-none">Authorized Scanners</h3>
            <p class="text-xs text-zinc-500 font-medium mt-1">
              <?php if ($isExpired): ?>
                This event has ended. Assistant management is disabled.
              <?php elseif ($canManage): ?>
                These students can scan tickets on your behalf (same as the mobile Assist Student feature).
              <?php else: ?>
                Assistant management is limited to teachers assigned by admin with QR scanner access.
              <?php endif; ?>
            </p>
          </div>
          <?php if (!$managementLocked): ?>
            <button type="button" id="btnOpenAssignModal"
              class="inline-flex items-center gap-1.5 rounded-xl bg-orange-600 text-white px-3.5 py-2 text-xs font-bold hover:bg-orange-700 shadow-sm transition">
              <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" d="M18 7.5v3m0 0v3m0-3h3m-3 0h-3m-2.25-4.125a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zM3 19.235v-.011a6.375 6.375 0 0112.75 0v.015"/>
              </svg>
              Assign
            </button>
          <?php endif; ?>
        </div>

        <div class="p-6">
          <?php if ($totalAssistants === 0): ?>
            <div class="rounded-3xl border-2 border-dashed border-zinc-200 bg-zinc-50/50 py-14 text-center">
              <div class="w-14 h-14 rounded-full bg-zinc-100 flex items-center justify-center mx-auto mb-3 text-zinc-400">
                <svg class="w-7 h-7" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.501 20.118a7.5 7.5 0 0114.998 0A17.933 17.933 0 0112 21.75c-2.676 0-5.216-.584-7.499-1.632z"/>
                </svg>
              </div>
              <h4 class="text-zinc-900 font-black text-base mb-1">
                <?= $managementLocked && !$isExpired
                  ? 'Only assigned teachers can manage assistants'
                  : 'No assistants assigned yet' ?>
              </h4>
              <p class="text-xs text-zinc-500 font-medium max-w-sm mx-auto">
                <?= $isExpired
                  ? 'No assistants were assigned to this event.'
                  : ($canManage
                    ? 'Assign registered participants who can scan tickets for this event.'
                    : 'Ask an admin to enable your QR scanner access for this event first.') ?>
              </p>
            </div>
          <?php else: ?>
            <div class="grid grid-cols-1 gap-3">
              <?php foreach ($assistants as $row): ?>
                <?php
                $assistantId = trim((string) ($row['id'] ?? ''));
                $studentId = trim((string) ($row['student_id'] ?? ''));
                $profile = is_array($row['users'] ?? null) ? $row['users'] : [];
                $name = assist_person_name($profile);
                $idNumber = assist_student_number($profile);
                $allowScan = !empty($row['allow_scan']);
                $initials = assist_initials($name);
                ?>
                <div class="assist-card <?= $allowScan ? 'is-enabled' : '' ?>">
                  <div class="assist-avatar"><?= htmlspecialchars($initials) ?></div>
                  <div class="flex-1 min-w-0">
                    <div class="text-sm font-black text-zinc-900 truncate"><?= htmlspecialchars($name) ?></div>
                    <div class="text-[11px] font-bold text-zinc-500 mt-0.5">Student ID: <?= htmlspecialchars($idNumber) ?></div>
                  </div>
                  <?php if (!$managementLocked): ?>
                    <form method="post" class="m-0"
                      onsubmit="return confirm(<?= htmlspecialchars(json_encode('Remove ' . $name . ' as assistant scanner?', JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?>);">
                      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                      <input type="hidden" name="action" value="remove_assistant">
                      <input type="hidden" name="assistant_id" value="<?= htmlspecialchars($assistantId) ?>">
                      <input type="hidden" name="student_id" value="<?= htmlspecialchars($studentId) ?>">
                      <button type="submit" class="assist-remove-btn" title="Remove assistant"
                        aria-label="Remove <?= htmlspecialchars($name) ?>">×</button>
                    </form>
                  <?php else: ?>
                    <span class="text-[10px] font-black uppercase tracking-wider text-orange-600">Assigned</span>
                  <?php endif; ?>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <div class="w-full xl:w-80 flex-shrink-0 flex flex-col gap-4">
      <div class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm">
        <h4 class="text-[11px] font-black uppercase tracking-[0.15em] text-zinc-400 mb-4">Assistant Summary</h4>
        <div class="grid grid-cols-1 gap-3">
          <div class="rounded-2xl border border-zinc-100 bg-zinc-50 p-4">
            <div class="text-[10px] font-black uppercase tracking-widest text-zinc-400 mb-1">Assigned</div>
            <div class="text-2xl font-black text-zinc-900"><?= $totalAssistants ?></div>
          </div>
        </div>
      </div>

      <div class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm">
        <h4 class="text-xs font-black text-zinc-900 mb-3 flex items-center gap-2">
          <span class="w-1 h-5 bg-orange-500 rounded-full"></span>
          How it works
        </h4>
        <ul class="space-y-3">
          <li class="flex items-start gap-2.5">
            <div class="w-1.5 h-1.5 rounded-full bg-orange-500 mt-1.5 flex-shrink-0"></div>
            <p class="text-[11px] text-zinc-500 font-medium leading-relaxed">Only <span class="text-zinc-900 font-bold">registered participants</span> can be assigned.</p>
          </li>
          <li class="flex items-start gap-2.5">
            <div class="w-1.5 h-1.5 rounded-full bg-emerald-500 mt-1.5 flex-shrink-0"></div>
            <p class="text-[11px] text-zinc-500 font-medium leading-relaxed">Assistants scan student tickets in the <span class="text-zinc-900 font-bold">mobile app</span> (Assist mode).</p>
          </li>
          <li class="flex items-start gap-2.5">
            <div class="w-1.5 h-1.5 rounded-full bg-sky-500 mt-1.5 flex-shrink-0"></div>
            <p class="text-[11px] text-zinc-500 font-medium leading-relaxed">Use × anytime to remove an assistant from this event.</p>
          </li>
        </ul>
      </div>
    </div>
  </div>
</div>

<?php if (!$managementLocked): ?>
<div id="assignAssistModal" class="fixed inset-0 z-50 hidden items-center justify-center bg-black/50 p-4">
  <div class="w-full max-w-lg rounded-2xl border border-orange-100 bg-white p-5 shadow-xl max-h-[85vh] flex flex-col">
    <div class="flex items-center justify-between mb-2">
      <h4 class="text-base font-black text-zinc-900">Assign Assistant</h4>
      <button type="button" id="btnCloseAssignModal" class="text-zinc-500 hover:text-zinc-700 text-lg leading-none">✕</button>
    </div>
    <p class="text-xs text-zinc-500 mb-3">Check one or more registered participants, then assign them together.</p>

    <form method="post" id="assignAssistForm" class="flex flex-col flex-1 min-h-0 m-0">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
      <input type="hidden" name="action" value="assign_assistant">

      <input type="search" id="assistSearch" placeholder="Search name or ID number..."
        class="w-full rounded-xl border border-zinc-200 bg-zinc-50 px-3 py-2.5 text-sm mb-3 focus:outline-none focus:ring-2 focus:ring-orange-300 focus:border-orange-400">

      <div class="flex items-center justify-between mb-2">
        <label class="inline-flex items-center gap-2 text-xs font-bold text-zinc-600 cursor-pointer select-none">
          <input type="checkbox" id="assistSelectAll" class="accent-orange-600 w-4 h-4">
          Select all visible
        </label>
        <span id="assistSelectedCount" class="text-[11px] font-bold text-zinc-400">0 selected</span>
      </div>

      <div id="assistCandidateList" class="overflow-y-auto space-y-2 flex-1 min-h-0 pr-0.5 mb-3">
        <?php if (count($candidates) === 0): ?>
          <p class="text-center text-sm text-zinc-500 font-semibold py-10">No eligible participants available.</p>
        <?php else: ?>
          <?php foreach ($candidates as $c): ?>
            <label class="assist-candidate"
              data-search="<?= htmlspecialchars(strtolower($c['name'] . ' ' . $c['id_number'] . ' ' . $c['email'])) ?>">
              <input type="checkbox" name="student_ids[]" value="<?= htmlspecialchars($c['student_id']) ?>"
                class="assist-pick">
              <div class="assist-avatar text-xs"><?= htmlspecialchars(assist_initials($c['name'])) ?></div>
              <div class="min-w-0 text-left flex-1">
                <div class="text-sm font-black text-zinc-900 truncate"><?= htmlspecialchars($c['name']) ?></div>
                <div class="text-[11px] font-bold text-zinc-500">ID: <?= htmlspecialchars($c['id_number']) ?></div>
              </div>
            </label>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>

      <div class="flex items-center justify-end gap-2 pt-2 border-t border-zinc-100">
        <button type="button" id="btnCancelAssignModal"
          class="rounded-xl border border-zinc-200 bg-white px-3.5 py-2 text-xs font-bold text-zinc-600 hover:bg-zinc-50">
          Cancel
        </button>
        <button type="submit" id="btnAssignSelected" disabled
          class="rounded-xl bg-orange-600 text-white px-3.5 py-2 text-xs font-bold hover:bg-orange-700 shadow-sm disabled:opacity-40 disabled:cursor-not-allowed">
          Assign selected
        </button>
      </div>
    </form>
  </div>
</div>

<script>
(function () {
  var modal = document.getElementById('assignAssistModal');
  var openBtn = document.getElementById('btnOpenAssignModal');
  var closeBtn = document.getElementById('btnCloseAssignModal');
  var cancelBtn = document.getElementById('btnCancelAssignModal');
  var search = document.getElementById('assistSearch');
  var selectAll = document.getElementById('assistSelectAll');
  var assignBtn = document.getElementById('btnAssignSelected');
  var countEl = document.getElementById('assistSelectedCount');
  var form = document.getElementById('assignAssistForm');

  function visibleRows() {
    return Array.prototype.slice.call(document.querySelectorAll('.assist-candidate')).filter(function (row) {
      return row.style.display !== 'none';
    });
  }

  function updateSelectionUi() {
    var checked = document.querySelectorAll('.assist-pick:checked');
    var n = checked.length;
    if (countEl) countEl.textContent = n + ' selected';
    if (assignBtn) {
      assignBtn.disabled = n === 0;
      assignBtn.textContent = n <= 1 ? 'Assign selected' : ('Assign ' + n + ' selected');
    }
    document.querySelectorAll('.assist-candidate').forEach(function (row) {
      var box = row.querySelector('.assist-pick');
      row.classList.toggle('is-checked', !!(box && box.checked));
    });
    if (selectAll) {
      var vis = visibleRows();
      var visChecked = vis.filter(function (row) {
        var box = row.querySelector('.assist-pick');
        return box && box.checked;
      });
      selectAll.checked = vis.length > 0 && visChecked.length === vis.length;
      selectAll.indeterminate = visChecked.length > 0 && visChecked.length < vis.length;
    }
  }

  function openModal() {
    if (!modal) return;
    modal.classList.remove('hidden');
    modal.classList.add('flex');
    search && search.focus();
    updateSelectionUi();
  }
  function closeModal() {
    if (!modal) return;
    modal.classList.add('hidden');
    modal.classList.remove('flex');
  }

  openBtn && openBtn.addEventListener('click', openModal);
  closeBtn && closeBtn.addEventListener('click', closeModal);
  cancelBtn && cancelBtn.addEventListener('click', closeModal);
  modal && modal.addEventListener('click', function (e) {
    if (e.target === modal) closeModal();
  });

  search && search.addEventListener('input', function () {
    var q = (search.value || '').toLowerCase().trim();
    document.querySelectorAll('.assist-candidate').forEach(function (row) {
      var hay = (row.getAttribute('data-search') || '');
      row.style.display = (!q || hay.indexOf(q) !== -1) ? '' : 'none';
    });
    updateSelectionUi();
  });

  selectAll && selectAll.addEventListener('change', function () {
    var on = !!selectAll.checked;
    visibleRows().forEach(function (row) {
      var box = row.querySelector('.assist-pick');
      if (box) box.checked = on;
    });
    updateSelectionUi();
  });

  document.querySelectorAll('.assist-pick').forEach(function (box) {
    box.addEventListener('change', updateSelectionUi);
  });

  form && form.addEventListener('submit', function (e) {
    var n = document.querySelectorAll('.assist-pick:checked').length;
    if (n === 0) {
      e.preventDefault();
      return;
    }
    if (assignBtn) {
      assignBtn.disabled = true;
      assignBtn.textContent = 'Assigning...';
    }
  });

  updateSelectionUi();
})();
</script>
<?php endif; ?>

<?php render_footer(); ?>
