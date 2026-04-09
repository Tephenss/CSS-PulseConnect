<?php
declare(strict_types=1);

session_start();

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/supabase.php';
require_once __DIR__ . '/../includes/json.php';
require_once __DIR__ . '/../includes/csrf.php';

$user = require_role(['admin']);
$data = require_post_json();
require_csrf_from_json($data);

$eventId = isset($data['event_id']) ? (string) $data['event_id'] : '';
$status = isset($data['status']) ? (string) $data['status'] : 'approved';
$rejectionReason = isset($data['reason']) ? (string) $data['reason'] : '';

if ($eventId === '') {
    json_response(['ok' => false, 'error' => 'event_id required'], 400);
}
if (!in_array($status, ['draft', 'pending', 'approved', 'published', 'closed', 'archived'], true)) {
    json_response(['ok' => false, 'error' => 'Invalid status'], 400);
}

$payload = [
    'status' => $status,
    'approved_by' => (string) ($user['id'] ?? ''),
    'updated_at' => gmdate('c'),
];

// We need created_by and description to notify the teacher and persist reasons
$url = rtrim(SUPABASE_URL, '/') . '/rest/v1/events?id=eq.' . rawurlencode($eventId) . '&select=id,status,title,created_by,description';
$headers = [
    'Content-Type: application/json',
    'Accept: application/json',
    'apikey: ' . SUPABASE_KEY,
    'Authorization: Bearer ' . SUPABASE_KEY,
];

// 1. Fetch current data first to append reason if needed
$fetchRes = supabase_request('GET', $url, $headers);
$existingEvent = null;
if ($fetchRes['ok']) {
    $rows = json_decode((string)$fetchRes['body'], true);
    $existingEvent = is_array($rows) && isset($rows[0]) ? $rows[0] : null;
}
$previousStatus = is_array($existingEvent) ? (string) ($existingEvent['status'] ?? '') : '';

if (in_array($status, ['draft', 'archived'], true) && !empty($rejectionReason) && $existingEvent) {
    // Prepend the reason to description for persistence
    $cleanDesc = (string)($existingEvent['description'] ?? '');
    // Remove existing [REJECT_REASON] if any to avoid stacking
    $cleanDesc = preg_replace('/\[REJECT_REASON:.*?\]\s*/s', '', $cleanDesc);
    $payload['description'] = "[REJECT_REASON: $rejectionReason] " . $cleanDesc;
}

$headers[] = 'Prefer: return=representation';
$res = supabase_request('PATCH', $url, $headers, json_encode($payload, JSON_UNESCAPED_SLASHES));
if (!$res['ok']) {
    json_response(['ok' => false, 'error' => build_error($res['body'] ?? null, (int) ($res['status'] ?? 0), $res['error'] ?? null, 'Approve failed')], 500);
}

$rows = json_decode((string) $res['body'], true);
$event = is_array($rows) && isset($rows[0]) ? $rows[0] : null;

// --- TEACHER NOTIFICATION (Approval or explicit rejection only) ---
$notifyTeacher = false;
if ($status === 'approved') {
    $notifyTeacher = true;
} elseif (in_array($status, ['draft', 'archived'], true) && trim($rejectionReason) !== '') {
    $notifyTeacher = true;
}

if ($event && $notifyTeacher) {
    require_once __DIR__ . '/../includes/fcm.php';

    $teacherId = $event['created_by'] ?? null;
    if ($teacherId) {
        // Fetch teacher's FCM tokens
        $tokensRes = supabase_request('GET',
            rtrim(SUPABASE_URL, '/') . '/rest/v1/fcm_tokens?select=token&user_id=eq.' . rawurlencode((string)$teacherId),
            ['apikey: ' . SUPABASE_KEY, 'Authorization: Bearer ' . SUPABASE_KEY]
        );

        if ($tokensRes['ok']) {
            $tokenRows = json_decode((string)$tokensRes['body'], true);
            $teacherTokens = array_column(is_array($tokenRows) ? $tokenRows : [], 'token');

            if (!empty($teacherTokens)) {
                $eventTitle = (string) ($event['title'] ?? 'your event proposal');
                if ($status === 'approved') {
                    $notifTitle = 'Proposal Approved';
                    $notifBody = "Great news! Your event \"$eventTitle\" has been approved by the admin.";
                } else {
                    $notifTitle = 'Proposal Review Required';
                    $notifBody = "The admin has requested changes for \"$eventTitle\".";
                    if (!empty($rejectionReason)) {
                        $notifBody .= " Reason: $rejectionReason";
                    }
                }
                send_fcm_notification($teacherTokens, $notifTitle, $notifBody, ['event_id' => $eventId, 'type' => 'proposal_update']);
            }
        }
    }
}

// --- STUDENT NOTIFICATIONS ---
if ($event && in_array($status, ['published', 'draft'], true)) {
    require_once __DIR__ . '/../includes/fcm.php';

    // Fetch event details to determine target audience
    $eventDetailsRes = supabase_request('GET',
        rtrim(SUPABASE_URL, '/') . '/rest/v1/events?id=eq.' . rawurlencode($eventId) . '&select=id,title,event_for',
        ['apikey: ' . SUPABASE_KEY, 'Authorization: Bearer ' . SUPABASE_KEY]
    );
    $eventDetails = null;
    if ($eventDetailsRes['ok']) {
        $evRows = json_decode((string)$eventDetailsRes['body'], true);
        $eventDetails = is_array($evRows) && isset($evRows[0]) ? $evRows[0] : null;
    }

    $eventFor = $eventDetails['event_for'] ?? 'All';

    // Build user filter based on event_for
    $usersUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/users?select=id&role=eq.student';
    if ($eventFor !== 'All' && $eventFor !== '' && $eventFor !== 'all') {
        $usersUrl .= '&section_id=eq.' . rawurlencode((string) $eventFor);
    }

    $usersRes = supabase_request('GET', $usersUrl, [
        'apikey: ' . SUPABASE_KEY,
        'Authorization: Bearer ' . SUPABASE_KEY,
    ]);

    $targetUserIds = [];
    if ($usersRes['ok']) {
        $userRows = json_decode((string)$usersRes['body'], true);
        $targetUserIds = array_column(is_array($userRows) ? $userRows : [], 'id');
    }

    $deviceTokens = [];
    if (!empty($targetUserIds)) {
        $inList = '(' . implode(',', array_map('rawurlencode', $targetUserIds)) . ')';
        $tokensRes = supabase_request('GET',
            rtrim(SUPABASE_URL, '/') . '/rest/v1/fcm_tokens?select=token&user_id=in.' . $inList,
            ['apikey: ' . SUPABASE_KEY, 'Authorization: Bearer ' . SUPABASE_KEY]
        );
        if ($tokensRes['ok']) {
            $tokenRows = json_decode((string)$tokensRes['body'], true);
            $deviceTokens = array_column(is_array($tokenRows) ? $tokenRows : [], 'token');
        }
    }

    if (!empty($deviceTokens)) {
        $eventTitle = (string) ($event['title'] ?? 'Event');
        $notifTitle = '';
        $notifBody = '';
        $notifType = '';

        if ($status === 'published') {
            if ($previousStatus === 'draft') {
                // Draft -> Published = admin explicitly opened registration.
                $notifTitle = 'Registration Open!';
                $notifBody = "Registration for \"$eventTitle\" is now open.";
                $notifType = 'reg_open';
            } elseif (in_array($previousStatus, ['approved', 'pending'], true)) {
                // Backward-compatible path if older clients still publish directly.
                $notifTitle = 'New Event Published';
                $notifBody = "\"$eventTitle\" has been published.";
                $notifType = 'event_published';
            }
        } elseif ($status === 'draft') {
            if (in_array($previousStatus, ['approved', 'pending'], true)) {
                // First publish flow: published announcement, registration remains off.
                $notifTitle = 'New Event Published';
                $notifBody = "\"$eventTitle\" has been published. Registration opens soon.";
                $notifType = 'event_published';
            } elseif ($previousStatus === 'published') {
                // Published -> Draft = admin closed registration.
                $notifTitle = 'Registration Closed';
                $notifBody = "Registration for \"$eventTitle\" is now closed.";
                $notifType = 'reg_closed';
            }
        }

        if ($notifTitle !== '' && $notifType !== '') {
            send_fcm_notification($deviceTokens, $notifTitle, $notifBody, ['event_id' => $eventId, 'type' => $notifType]);
        }
    }
}

json_response(['ok' => true, 'event' => $event], 200);



