<?php
declare(strict_types=1);

require_once __DIR__ . '/registration_access.php';
require_once __DIR__ . '/student_requirements.php';

function event_registration_service_headers(): array
{
    return [
        'Content-Type: application/json',
        'Accept: application/json',
        'apikey: ' . SUPABASE_KEY,
        'Authorization: Bearer ' . SUPABASE_KEY,
        'Prefer: return=representation',
    ];
}

function event_registration_read_headers(): array
{
    return [
        'Accept: application/json',
        'apikey: ' . SUPABASE_KEY,
        'Authorization: Bearer ' . SUPABASE_KEY,
    ];
}

function event_registration_extract_ticket(mixed $tickets): ?array
{
    if (!is_array($tickets)) {
        return null;
    }

    if (isset($tickets[0]) && is_array($tickets[0])) {
        return $tickets[0];
    }

    if (isset($tickets['token'])) {
        return $tickets;
    }

    return null;
}

function fetch_existing_event_registration(string $eventId, string $studentId, array $headers): ?array
{
    $url = rtrim(SUPABASE_URL, '/') . '/rest/v1/event_registrations'
        . '?select=id,event_id,student_id,tickets(id,token,registration_id)'
        . '&event_id=eq.' . rawurlencode($eventId)
        . '&student_id=eq.' . rawurlencode($studentId)
        . '&limit=1';

    $res = supabase_request('GET', $url, event_registration_read_headers());
    if (!$res['ok']) {
        return null;
    }

    $rows = json_decode((string) $res['body'], true);
    if (!is_array($rows) || !isset($rows[0]) || !is_array($rows[0])) {
        return null;
    }

    return $rows[0];
}

function submit_student_event_registration(string $eventId, string $studentId, array $headers): array
{
    $eventId = trim($eventId);
    $studentId = trim($studentId);
    if ($eventId === '' || $studentId === '') {
        return ['ok' => false, 'error' => 'event_id and student_id are required.', 'status' => 400];
    }

    $event = fetch_event_with_registration_settings($eventId, $headers);
    if (!is_array($event)) {
        return ['ok' => false, 'error' => 'Event not found.', 'status' => 404];
    }

    $studentRow = fetch_student_profile_by_id($studentId, $headers);
    if (!is_array($studentRow)) {
        return ['ok' => false, 'error' => 'Student profile not found.', 'status' => 404];
    }

    $access = resolve_student_registration_access($event, $studentRow, $headers);
    if (!(bool) ($access['allowed'] ?? false)) {
        return [
            'ok' => false,
            'error' => (string) ($access['message'] ?? 'Registration is not allowed for this event.'),
            'status' => 403,
        ];
    }

    $existing = fetch_existing_event_registration($eventId, $studentId, $headers);
    if (is_array($existing)) {
        return [
            'ok' => true,
            'already_registered' => true,
            'ticket' => event_registration_extract_ticket($existing['tickets'] ?? null),
            'registration_count' => fetch_event_registration_count($eventId, $headers),
            'registration_limit' => event_registration_limit($event),
            'is_full' => event_registration_is_full($eventId, $event, $headers),
        ];
    }

    if (event_registration_is_full($eventId, $event, $headers)) {
        return [
            'ok' => false,
            'error' => 'Registration is full for this event.',
            'status' => 403,
            'registration_count' => fetch_event_registration_count($eventId, $headers),
            'registration_limit' => event_registration_limit($event),
            'is_full' => true,
        ];
    }

    $writeHeaders = event_registration_service_headers();
    $regUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/event_registrations?select=id,event_id,student_id';
    $regRes = supabase_request(
        'POST',
        $regUrl,
        $writeHeaders,
        json_encode([
            [
                'event_id' => $eventId,
                'student_id' => $studentId,
            ],
        ], JSON_UNESCAPED_SLASHES)
    );

    if (!$regRes['ok']) {
        $errorBody = strtolower((string) ($regRes['body'] ?? ''));
        if (str_contains($errorBody, 'registration is full')) {
            return [
                'ok' => false,
                'error' => 'Registration is full for this event.',
                'status' => 403,
                'registration_count' => fetch_event_registration_count($eventId, $headers),
                'registration_limit' => event_registration_limit($event),
                'is_full' => true,
            ];
        }

        return [
            'ok' => false,
            'error' => build_error($regRes['body'] ?? null, (int) ($regRes['status'] ?? 0), $regRes['error'] ?? null, 'Registration failed'),
            'status' => 500,
        ];
    }

    $regRows = json_decode((string) $regRes['body'], true);
    $reg = is_array($regRows) && isset($regRows[0]) ? $regRows[0] : null;
    if (!is_array($reg) || empty($reg['id'])) {
        return ['ok' => false, 'error' => 'Registration failed.', 'status' => 500];
    }

    $token = bin2hex(random_bytes(16));
    $ticketUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/tickets?select=id,token,registration_id';
    $ticketRes = supabase_request(
        'POST',
        $ticketUrl,
        $writeHeaders,
        json_encode([
            [
                'registration_id' => (string) ($reg['id'] ?? ''),
                'token' => $token,
            ],
        ], JSON_UNESCAPED_SLASHES)
    );

    if (!$ticketRes['ok']) {
        return [
            'ok' => false,
            'error' => build_error($ticketRes['body'] ?? null, (int) ($ticketRes['status'] ?? 0), $ticketRes['error'] ?? null, 'Ticket issue failed'),
            'status' => 500,
        ];
    }

    $ticketRows = json_decode((string) $ticketRes['body'], true);
    $ticket = is_array($ticketRows) && isset($ticketRows[0]) ? $ticketRows[0] : null;
    if (!is_array($ticket) || empty($ticket['id'])) {
        return ['ok' => false, 'error' => 'Ticket issue failed.', 'status' => 500];
    }

    $attUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/attendance?select=id,status';
    supabase_request(
        'POST',
        $attUrl,
        $writeHeaders,
        json_encode([
            [
                'ticket_id' => (string) ($ticket['id'] ?? ''),
                'status' => 'unscanned',
            ],
        ], JSON_UNESCAPED_SLASHES)
    );

    maybe_close_event_registration_at_capacity($eventId, $event, $headers);

    $registrationCount = fetch_event_registration_count($eventId, $headers);
    $registrationLimit = event_registration_limit($event);

    return [
        'ok' => true,
        'ticket' => $ticket,
        'registration_count' => $registrationCount,
        'registration_limit' => $registrationLimit,
        'is_full' => $registrationLimit !== null && $registrationCount >= $registrationLimit,
    ];
}

function build_event_registration_info(string $eventId, array $headers, ?string $studentId = null): array
{
    $eventId = trim($eventId);
    if ($eventId === '') {
        return ['ok' => false, 'error' => 'event_id required.', 'status' => 400];
    }

    $event = fetch_event_with_registration_settings($eventId, $headers);
    if (!is_array($event)) {
        return ['ok' => false, 'error' => 'Event not found.', 'status' => 404];
    }

    $registrationCount = fetch_event_registration_count($eventId, $headers);
    $registrationLimit = event_registration_limit($event);
    $isFull = $registrationLimit !== null && $registrationCount >= $registrationLimit;

    $payload = [
        'ok' => true,
        'registration_count' => $registrationCount,
        'registration_limit' => $registrationLimit,
        'participant_total' => format_event_registration_total($registrationCount, $event),
        'is_full' => $isFull,
        'allow_registration' => event_allows_open_registration($event),
        'registration_open_to_all' => event_registration_open_to_all($event),
    ];

    $studentId = trim((string) $studentId);
    if ($studentId !== '') {
        $studentRow = fetch_student_profile_by_id($studentId, $headers);
        if (is_array($studentRow)) {
            $access = resolve_student_registration_access($event, $studentRow, $headers);
            $docAccess = resolve_student_document_access($eventId, $studentId, $headers);
            $payload['availability'] = [
                'allowed' => (bool) ($access['allowed'] ?? false),
                'target_allowed' => (bool) ($access['target_allowed'] ?? false),
                'approval_required' => (bool) ($access['approval_required'] ?? false),
                'requirements_required' => (bool) ($docAccess['required'] ?? false),
                'requirements_complete' => (bool) ($docAccess['complete'] ?? false),
                'requirements_approved' => (bool) ($docAccess['approved'] ?? false),
                'requirements_status' => (string) ($docAccess['status'] ?? ''),
                'requirements_decline_reason' => (string) ($docAccess['decline_reason'] ?? ''),
                'message' => (string) ($access['message'] ?? ''),
            ];
            if (($docAccess['required'] ?? false) && !($docAccess['approved'] ?? false) && ($access['message'] ?? '') === '') {
                $payload['availability']['message'] = (string) ($docAccess['message'] ?? '');
            }
            $payload['student_requirements'] = fetch_student_requirements_map([$eventId], $headers)[$eventId] ?? [];
        }
    }

    return $payload;
}
