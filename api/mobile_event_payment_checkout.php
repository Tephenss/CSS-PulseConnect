<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/supabase.php';
require_once __DIR__ . '/../includes/json.php';
require_once __DIR__ . '/../includes/mobile_api.php';
require_once __DIR__ . '/../includes/mobile_session.php';
require_once __DIR__ . '/../includes/api_rate_limit.php';
require_once __DIR__ . '/../includes/registration_access.php';
require_once __DIR__ . '/../includes/event_registration_submit.php';
require_once __DIR__ . '/../includes/paymongo.php';

$data = mobile_api_require_post_json();
mobile_api_validate_key($data);
$user = mobile_api_require_user($data);
$studentId = trim((string) ($user['id'] ?? ''));
if (strtolower(trim((string) ($user['role'] ?? ''))) !== 'student' || $studentId === '') {
    json_response(['ok' => false, 'error' => 'Only signed-in students can pay for an event.'], 403);
}
if (!paymongo_is_configured()) {
    json_response(['ok' => false, 'error' => 'Online GCash payment is not configured yet.'], 503);
}

$eventId = trim((string) ($data['event_id'] ?? ''));
if ($eventId === '') {
    json_response(['ok' => false, 'error' => 'Missing event.'], 400);
}
$clientIp = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
if (!api_rate_limit_allow('mobile_paymongo_checkout:' . $studentId . ':' . $clientIp, 8, 300)) {
    json_response(['ok' => false, 'error' => 'Too many payment attempts. Please wait and try again.'], 429);
}

$headers = mobile_api_supabase_headers();
$event = fetch_event_with_registration_settings($eventId, $headers);
if (!is_array($event) || strtolower(trim((string) ($event['status'] ?? ''))) !== 'published') {
    json_response(['ok' => false, 'error' => 'Published event not found.'], 404);
}
if (event_is_free_registration_event($event)) {
    json_response(['ok' => false, 'error' => 'This event does not require payment.'], 400);
}
$fee = event_settlement_fee($event);
$amountCentavos = $fee !== null ? (int) round($fee * 100) : 0;
if ($fee === null || $amountCentavos < 100) {
    json_response(['ok' => false, 'error' => 'PayMongo QR Ph requires an event fee of at least ₱1.00.'], 400);
}

$student = fetch_student_profile_by_id($studentId, $headers);
if (!is_array($student)
    || !student_matches_event_target($student, (string) ($event['event_for'] ?? 'All'))) {
    json_response(['ok' => false, 'error' => 'You are not eligible to pay for this event.'], 403);
}

$existingRegistration = fetch_existing_event_registration($eventId, $studentId, $headers);
if (is_array($existingRegistration)) {
    json_response(['ok' => true, 'already_registered' => true, 'status' => 'paid']);
}
if (is_event_registration_window_closed($event)) {
    json_response(['ok' => false, 'error' => 'The registration window for this event is closed.'], 403);
}

$sessions = fetch_event_sessions($eventId, event_registration_read_headers());
$isSessionEvent = $sessions !== [] || event_uses_sessions($event);
$eligibleSessions = $isSessionEvent
    ? event_matching_sessions_for_student($event, $sessions, $student)
    : [];
if ($isSessionEvent && $eligibleSessions === []) {
    json_response(['ok' => false, 'error' => 'You are not assigned to a seminar session in this event.'], 403);
}
$capacity = $isSessionEvent
    ? event_session_registration_capacity($eligibleSessions, event_registration_limit($event), event_registration_read_headers())
    : null;
$isFull = is_array($capacity)
    ? (bool) ($capacity['is_full'] ?? false)
    : event_registration_is_full($eventId, $event, $headers);
if ($isFull) {
    json_response(['ok' => false, 'error' => 'Registration is full for your assigned event or seminar.'], 409);
}

$accessRes = supabase_request('GET', rtrim(SUPABASE_URL, '/') . '/rest/v1/event_registration_access'
    . '?select=approved,payment_status&event_id=eq.' . rawurlencode($eventId)
    . '&student_id=eq.' . rawurlencode($studentId) . '&limit=1', $headers);
$accessRows = json_decode((string) ($accessRes['body'] ?? ''), true);
if (is_array($accessRows) && isset($accessRows[0]) && registration_access_row_allows($accessRows[0])) {
    $registration = submit_student_event_registration($eventId, $studentId, $headers);
    if (($registration['ok'] ?? false) === true) {
        json_response(['ok' => true, 'already_registered' => true, 'status' => 'paid']);
    }
    json_response(['ok' => false, 'error' => 'Payment is already confirmed. Refresh the event and try again.'], 409);
}

// Avoid letting a student pay twice while a legacy manual transfer is under review.
$manualRes = supabase_request('GET', rtrim(SUPABASE_URL, '/') . '/rest/v1/event_payment_submissions'
    . '?select=id&event_id=eq.' . rawurlencode($eventId)
    . '&student_id=eq.' . rawurlencode($studentId) . '&status=eq.pending&limit=1', $headers);
$manualRows = json_decode((string) ($manualRes['body'] ?? ''), true);
if ($manualRes['ok'] && is_array($manualRows) && $manualRows !== []) {
    json_response(['ok' => false, 'error' => 'A manual payment is already waiting for organizer review.'], 409);
}

$checkoutUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/event_payment_checkouts'
    . '?select=id,event_id,student_id,amount,status,payment_intent_id,payment_method_id,qr_image_url,expires_at,created_at'
    . '&event_id=eq.' . rawurlencode($eventId)
    . '&student_id=eq.' . rawurlencode($studentId)
    . '&status=in.(creating,pending,processing)&order=created_at.desc&limit=1';
$checkoutRes = supabase_request('GET', $checkoutUrl, $headers);
    if (!$checkoutRes['ok']) {
        json_response(['ok' => false, 'error' => 'Online payments are not ready yet. Run migration 074 first.'], 500);
}
$checkoutRows = json_decode((string) ($checkoutRes['body'] ?? ''), true);
$checkout = is_array($checkoutRows) && isset($checkoutRows[0]) && is_array($checkoutRows[0])
    ? $checkoutRows[0]
    : null;
if (is_array($checkout)) {
    $expiresAt = strtotime((string) ($checkout['expires_at'] ?? '')) ?: 0;
    if (($checkout['status'] ?? '') === 'pending' && $expiresAt > time()
        && trim((string) ($checkout['qr_image_url'] ?? '')) !== '') {
        json_response([
            'ok' => true,
            'status' => 'pending',
            'checkout_id' => (string) $checkout['id'],
            'amount' => (float) $checkout['amount'],
            'qr_image_url' => (string) $checkout['qr_image_url'],
            'expires_at' => (string) $checkout['expires_at'],
        ]);
    }
    if (($checkout['status'] ?? '') === 'processing'
        || (($checkout['status'] ?? '') === 'creating' && (time() - (strtotime((string) ($checkout['created_at'] ?? '')) ?: 0)) < 120)) {
        json_response(['ok' => true, 'status' => (string) $checkout['status'], 'checkout_id' => (string) $checkout['id']]);
    }
    if (($checkout['status'] ?? '') === 'pending' || ($checkout['status'] ?? '') === 'creating') {
        paymongo_checkout_update((string) $checkout['id'], [
            'status' => ($checkout['status'] ?? '') === 'pending' ? 'expired' : 'failed',
            'updated_at' => gmdate('c'),
        ], (string) $checkout['status']);
    }
}

$checkoutId = sprintf('%04x%04x-%04x-4%03x-%04x-%04x%04x%04x',
    random_int(0, 0xffff), random_int(0, 0xffff), random_int(0, 0xffff),
    random_int(0, 0x0fff), random_int(0, 0x3fff) | 0x8000,
    random_int(0, 0xffff), random_int(0, 0xffff), random_int(0, 0xffff));
$reserveRes = supabase_request('POST', rtrim(SUPABASE_URL, '/') . '/rest/v1/rpc/reserve_event_payment_checkout', [
    'Content-Type: application/json',
    'Accept: application/json',
    'apikey: ' . SUPABASE_KEY,
    'Authorization: Bearer ' . SUPABASE_KEY,
], json_encode([
    'p_checkout_id' => $checkoutId,
    'p_event_id' => $eventId,
    'p_student_id' => $studentId,
    'p_amount' => number_format($amountCentavos / 100, 2, '.', ''),
], JSON_UNESCAPED_SLASHES));
if (!$reserveRes['ok']) {
    $reserveError = strtolower((string) ($reserveRes['body'] ?? ''));
    if (str_contains($reserveError, 'full')) {
        json_response(['ok' => false, 'error' => 'Registration filled while starting checkout. No payment was created.'], 409);
    }
    json_response(['ok' => false, 'error' => 'Could not reserve an event slot or start checkout. Run migration 074, then retry.'], 409);
}

$title = trim((string) ($event['title'] ?? 'Event'));
$description = mb_substr('PulseCONNECT event: ' . ($title !== '' ? $title : 'Event'), 0, 255);
$intentRes = paymongo_request('POST', '/v1/payment_intents', [
    'data' => ['attributes' => [
        'amount' => $amountCentavos,
        'currency' => 'PHP',
        'payment_method_allowed' => ['qrph'],
        'description' => $description,
        'metadata' => [
            'checkout_id' => $checkoutId,
            'event_id' => $eventId,
            'student_id' => $studentId,
        ],
    ]],
], 'pc-' . str_replace('-', '', $checkoutId) . '-intent');
if (!$intentRes['ok']) {
    paymongo_checkout_update($checkoutId, ['status' => 'failed', 'failure_reason' => $intentRes['error'], 'updated_at' => gmdate('c')]);
    json_response(['ok' => false, 'error' => $intentRes['error']], 502);
}
$intent = $intentRes['data']['data'] ?? null;
$intentId = is_array($intent) ? trim((string) ($intent['id'] ?? '')) : '';
$clientKey = is_array($intent) ? trim((string) ($intent['attributes']['client_key'] ?? '')) : '';
if ($intentId === '' || $clientKey === '') {
    paymongo_checkout_update($checkoutId, ['status' => 'failed', 'failure_reason' => 'Incomplete payment intent response.', 'updated_at' => gmdate('c')]);
    json_response(['ok' => false, 'error' => 'PayMongo returned an incomplete payment intent.'], 502);
}
$intentSave = paymongo_checkout_update($checkoutId, ['payment_intent_id' => $intentId, 'updated_at' => gmdate('c')], 'creating');
if (!$intentSave['ok']) {
    error_log('Could not associate PayMongo intent with checkout ' . $checkoutId);
    json_response(['ok' => false, 'error' => 'Could not save the payment checkout. Please retry.'], 500);
}

$paymentMethodRes = paymongo_request('POST', '/v1/payment_methods', [
    'data' => ['attributes' => ['type' => 'qrph', 'expiry_seconds' => 1800]],
], 'pc-' . str_replace('-', '', $checkoutId) . '-method');
if (!$paymentMethodRes['ok']) {
    paymongo_checkout_update($checkoutId, ['status' => 'failed', 'failure_reason' => $paymentMethodRes['error'], 'updated_at' => gmdate('c')], 'creating');
    json_response(['ok' => false, 'error' => $paymentMethodRes['error']], 502);
}
$paymentMethod = $paymentMethodRes['data']['data'] ?? null;
$paymentMethodId = is_array($paymentMethod) ? trim((string) ($paymentMethod['id'] ?? '')) : '';
if ($paymentMethodId === '') {
    paymongo_checkout_update($checkoutId, ['status' => 'failed', 'failure_reason' => 'Incomplete payment method response.', 'updated_at' => gmdate('c')], 'creating');
    json_response(['ok' => false, 'error' => 'PayMongo returned an incomplete QR payment method.'], 502);
}

$attachRes = paymongo_request('POST', '/v1/payment_intents/' . rawurlencode($intentId) . '/attach', [
    'data' => ['attributes' => ['payment_method' => $paymentMethodId, 'client_key' => $clientKey]],
], 'pc-' . str_replace('-', '', $checkoutId) . '-attach');
if (!$attachRes['ok']) {
    paymongo_checkout_update($checkoutId, [
        'status' => 'failed',
        'payment_method_id' => $paymentMethodId,
        'failure_reason' => $attachRes['error'],
        'updated_at' => gmdate('c'),
    ], 'creating');
    json_response(['ok' => false, 'error' => $attachRes['error']], 502);
}
$attachedIntent = $attachRes['data']['data'] ?? null;
$qrImage = is_array($attachedIntent)
    ? trim((string) ($attachedIntent['attributes']['next_action']['code']['image_url'] ?? ''))
    : '';
if ($qrImage === '' || strlen($qrImage) > 500000) {
    paymongo_checkout_update($checkoutId, ['status' => 'failed', 'failure_reason' => 'PayMongo did not return a usable QR image.', 'updated_at' => gmdate('c')], 'creating');
    json_response(['ok' => false, 'error' => 'PayMongo did not return a usable QR code. Please retry.'], 502);
}

$expiresAt = gmdate('c', time() + 1800);
$saveQr = paymongo_checkout_update($checkoutId, [
    'status' => 'pending',
    'payment_method_id' => $paymentMethodId,
    'qr_image_url' => $qrImage,
    'expires_at' => $expiresAt,
    'updated_at' => gmdate('c'),
], 'creating');
if (!$saveQr['ok']) {
    error_log('Could not save QR payload for checkout ' . $checkoutId);
    json_response(['ok' => false, 'error' => 'Could not save the QR checkout. Please retry.'], 500);
}

json_response([
    'ok' => true,
    'status' => 'pending',
    'checkout_id' => $checkoutId,
    'amount' => $amountCentavos / 100,
    'currency' => 'PHP',
    'qr_image_url' => $qrImage,
    'expires_at' => $expiresAt,
]);
