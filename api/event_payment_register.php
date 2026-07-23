<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/session.php';
session_bootstrap();

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/supabase.php';
require_once __DIR__ . '/../includes/json.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/event_registration_submit.php';

$user = require_role(['admin', 'teacher']);
$data = require_post_json();
require_csrf_from_json($data);

$eventId = trim((string) ($data['event_id'] ?? ''));
$studentId = trim((string) ($data['student_id'] ?? ''));
$paymentMode = strtolower(trim((string) ($data['payment_mode'] ?? 'partial')));
$amountRaw = $data['amount_paid'] ?? null;
$paymentNote = trim((string) ($data['payment_note'] ?? ''));

if ($eventId === '' || $studentId === '') {
    json_response(['ok' => false, 'error' => 'event_id and student_id are required.'], 400);
}

if (!in_array($paymentMode, ['full', 'partial'], true)) {
    $paymentMode = 'partial';
}

$headers = event_registration_service_headers();
$event = fetch_event_with_registration_settings($eventId, $headers);
if (!is_array($event)) {
    json_response(['ok' => false, 'error' => 'Event not found.'], 404);
}

$role = (string) ($user['role'] ?? '');
$userId = (string) ($user['id'] ?? '');
$isCreator = (string) ($event['created_by'] ?? '') !== ''
    && (string) ($event['created_by'] ?? '') === $userId;

if ($role !== 'admin' && !($role === 'teacher' && $isCreator)) {
    json_response(['ok' => false, 'error' => 'Only the event creator or an admin can record payments.'], 403);
}

$eventFee = event_settlement_fee($event);
$amountPaid = 0.0;

if ($paymentMode === 'full') {
    if ($eventFee !== null && $eventFee > 0) {
        $amountPaid = $eventFee;
    } elseif ($amountRaw !== null && $amountRaw !== '') {
        $amountPaid = (float) $amountRaw;
        if (!is_finite($amountPaid) || $amountPaid < 0) {
            json_response(['ok' => false, 'error' => 'Invalid amount paid.'], 400);
        }
    }
    if ($paymentNote === '') {
        $paymentNote = 'Full payment';
    }
} else {
    if ($amountRaw === null || $amountRaw === '') {
        json_response(['ok' => false, 'error' => 'Enter the partial amount paid.'], 400);
    }
    $amountPaid = (float) $amountRaw;
    if (!is_finite($amountPaid) || $amountPaid <= 0) {
        json_response(['ok' => false, 'error' => 'Partial amount must be greater than 0.'], 400);
    }
    if ($eventFee !== null && $eventFee > 0 && $amountPaid > $eventFee) {
        json_response([
            'ok' => false,
            'error' => 'Partial amount cannot exceed the full event fee (' . format_event_fee_php($eventFee) . ').',
        ], 400);
    }
    if ($paymentNote === '') {
        $paymentNote = 'Partial payment';
    }
}

$result = submit_staff_paid_event_registration(
    $eventId,
    $studentId,
    $amountPaid,
    $userId,
    $headers,
    $paymentNote
);

$status = (int) ($result['status'] ?? 200);
unset($result['status']);
if (($result['ok'] ?? false) && $eventFee !== null) {
    $result['event_fee'] = $eventFee;
}
json_response($result, ($result['ok'] ?? false) ? 200 : ($status >= 400 ? $status : 500));
