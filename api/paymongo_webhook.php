<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/supabase.php';
require_once __DIR__ . '/../includes/json.php';
require_once __DIR__ . '/../includes/registration_access.php';
require_once __DIR__ . '/../includes/event_registration_submit.php';
require_once __DIR__ . '/../includes/paymongo.php';
require_once __DIR__ . '/../includes/email_notifications.php';

function paymongo_send_event_receipt(array $checkout, string $paymentId): bool
{
    if (!empty($checkout['receipt_email_sent_at'])) {
        return true;
    }

    $studentId = trim((string) ($checkout['student_id'] ?? ''));
    $eventId = trim((string) ($checkout['event_id'] ?? ''));
    $student = $studentId !== ''
        ? fetch_student_profile_by_id($studentId, event_registration_read_headers())
        : null;
    $event = $eventId !== ''
        ? fetch_event_with_registration_settings($eventId, event_registration_read_headers())
        : null;
    if (!is_array($student) || !is_array($event)) {
        error_log('Could not load receipt recipient or event for checkout ' . (string) ($checkout['id'] ?? ''));
        return false;
    }

    $sent = send_event_payment_receipt_email(
        (string) ($student['email'] ?? ''),
        (string) ($student['display_name'] ?? 'Student'),
        (string) ($event['title'] ?? 'Event'),
        (float) ($checkout['amount'] ?? 0),
        $paymentId,
        (string) ($checkout['paid_at'] ?? gmdate('c'))
    );
    if (!$sent) {
        error_log('Could not send payment receipt for checkout ' . (string) ($checkout['id'] ?? ''));
        return false;
    }

    $marked = paymongo_checkout_update((string) $checkout['id'], [
        'receipt_email_sent_at' => gmdate('c'),
        'updated_at' => gmdate('c'),
    ]);
    if (!$marked['ok']) {
        error_log('Could not mark payment receipt sent for checkout ' . (string) ($checkout['id'] ?? ''));
        return false;
    }
    return true;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    json_response(['ok' => false, 'error' => 'Method not allowed.'], 405);
}
$rawBody = file_get_contents('php://input');
if (!is_string($rawBody) || $rawBody === '') {
    json_response(['ok' => false, 'error' => 'Empty webhook payload.'], 400);
}
$payload = json_decode($rawBody, true);
if (!is_array($payload)) {
    json_response(['ok' => false, 'error' => 'Invalid webhook payload.'], 400);
}
$signature = trim((string) ($_SERVER['HTTP_PAYMONGO_SIGNATURE'] ?? ''));
$event = $payload['data']['attributes'] ?? null;
$eventType = is_array($event) ? trim((string) ($event['type'] ?? '')) : '';
$liveMode = is_array($event) && ($event['livemode'] ?? false) === true;
$webhookSecret = trim((string) PAYMONGO_WEBHOOK_SECRET);
if (!paymongo_verify_webhook_signature($signature, $rawBody, $webhookSecret, $liveMode)) {
    json_response(['ok' => false, 'error' => 'Invalid webhook signature.'], 401);
}

if (!in_array($eventType, ['payment.paid', 'payment.failed'], true)) {
    json_response(['ok' => true, 'ignored' => true]);
}
$payment = $event['data'] ?? null;
$attributes = is_array($payment) ? ($payment['attributes'] ?? null) : null;
$intentId = is_array($attributes) ? trim((string) ($attributes['payment_intent_id'] ?? '')) : '';
$paymentId = is_array($payment) ? trim((string) ($payment['id'] ?? '')) : '';
$paymentAmount = is_array($attributes) ? (int) ($attributes['amount'] ?? 0) : 0;
$paymentCurrency = strtoupper(trim((string) (is_array($attributes) ? ($attributes['currency'] ?? '') : '')));
$paymentStatus = strtolower(trim((string) (is_array($attributes) ? ($attributes['status'] ?? '') : '')));
if ($intentId === '' || $paymentId === '' || $paymentCurrency !== 'PHP') {
    json_response(['ok' => false, 'error' => 'Invalid payment payload.'], 400);
}

$checkout = paymongo_fetch_checkout_by_intent($intentId);
if (!is_array($checkout)) {
    // A valid unrelated payment is acknowledged; it is not a PulseCONNECT checkout.
    json_response(['ok' => true, 'ignored' => true]);
}
$expectedAmount = (int) round(((float) ($checkout['amount'] ?? 0)) * 100);
if ($paymentAmount !== $expectedAmount) {
    error_log('PayMongo amount mismatch for checkout ' . (string) $checkout['id']);
    json_response(['ok' => false, 'error' => 'Payment amount does not match checkout.'], 400);
}
$checkoutStatus = strtolower(trim((string) ($checkout['status'] ?? '')));
if ($eventType === 'payment.failed') {
    if ($checkoutStatus === 'pending') {
        paymongo_checkout_update((string) $checkout['id'], [
            'status' => 'failed',
            'payment_id' => $paymentId,
            'failure_reason' => 'PayMongo reported that the payment attempt failed.',
            'updated_at' => gmdate('c'),
        ], 'pending');
    }
    json_response(['ok' => true, 'failed' => true]);
}
if ($paymentStatus !== 'paid') {
    json_response(['ok' => false, 'error' => 'Payment is not marked paid.'], 400);
}
if ($checkoutStatus === 'paid') {
    if (!paymongo_send_event_receipt($checkout, $paymentId)) {
        json_response(['ok' => false, 'error' => 'Payment is complete; receipt email delivery will be retried.'], 500);
    }
    json_response(['ok' => true, 'already_processed' => true]);
}
if (in_array($checkoutStatus, ['failed', 'expired', 'registration_failed'], true)) {
    json_response(['ok' => true, 'ignored' => true]);
}

$processingStatusFilter = $checkoutStatus;
if ($checkoutStatus === 'processing') {
    $updatedAt = strtotime((string) ($checkout['updated_at'] ?? '')) ?: 0;
    if ($updatedAt > time() - 120) {
        json_response(['ok' => true, 'already_processing' => true]);
    }
    $processingStatusFilter = 'processing';
} elseif (!in_array($checkoutStatus, ['pending', 'creating'], true)) {
    json_response(['ok' => true, 'ignored' => true]);
}
$processingRes = paymongo_checkout_update((string) $checkout['id'], [
    'status' => 'processing',
    'payment_id' => $paymentId,
    'updated_at' => gmdate('c'),
], $processingStatusFilter);
if (!$processingRes['ok']) {
    json_response(['ok' => false, 'error' => 'Could not lock payment fulfillment.'], 500);
}
$processingRows = json_decode((string) ($processingRes['body'] ?? ''), true);
if (!is_array($processingRows) || $processingRows === []) {
    json_response(['ok' => true, 'already_processing' => true]);
}

$result = submit_staff_paid_event_registration(
    (string) $checkout['event_id'],
    (string) $checkout['student_id'],
    (float) $checkout['amount'],
    '',
    [
        'Content-Type: application/json',
        'Accept: application/json',
        'apikey: ' . SUPABASE_KEY,
        'Authorization: Bearer ' . SUPABASE_KEY,
        'Prefer: return=representation',
    ],
    'PayMongo QR Ph | ' . $paymentId,
    true
);
if (!($result['ok'] ?? false)) {
    $status = (int) ($result['status'] ?? 500);
    if ($status >= 500 || $status === 0) {
        // Leave processing so PayMongo's retry can finish a transient failure.
        json_response(['ok' => false, 'error' => 'Registration fulfillment will be retried.'], 500);
    }
    paymongo_checkout_update((string) $checkout['id'], [
        'status' => 'registration_failed',
        'payment_id' => $paymentId,
        'paid_at' => gmdate('c'),
        'failure_reason' => mb_substr((string) ($result['error'] ?? 'Registration could not be completed.'), 0, 500),
        'updated_at' => gmdate('c'),
    ]);
    error_log('Paid PayMongo checkout needs manual fulfillment: ' . (string) $checkout['id']);
    json_response(['ok' => true, 'registration_failed' => true]);
}

$ticket = is_array($result['ticket'] ?? null) ? $result['ticket'] : [];
$saved = paymongo_checkout_update((string) $checkout['id'], [
    'status' => 'paid',
    'payment_id' => $paymentId,
    'paid_at' => gmdate('c'),
    'registration_id' => $ticket['registration_id'] ?? null,
    'failure_reason' => null,
    'updated_at' => gmdate('c'),
]);
if (!$saved['ok']) {
    // Retry is safe: paid registration helper recognizes an existing registration.
    json_response(['ok' => false, 'error' => 'Payment received; registration status is being reconciled.'], 500);
}

$checkout['paid_at'] = gmdate('c');
if (!paymongo_send_event_receipt($checkout, $paymentId)) {
    json_response(['ok' => false, 'error' => 'Payment and registration are complete; receipt email delivery will be retried.'], 500);
}

json_response(['ok' => true, 'registered' => true]);
