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

$fields = mobile_api_require_post_fields();
mobile_api_validate_key($fields);
$user = mobile_api_require_user_from_post();
$studentId = trim((string) ($user['id'] ?? ''));
if (strtolower(trim((string) ($user['role'] ?? ''))) !== 'student' || $studentId === '') {
    json_response(['ok' => false, 'error' => 'Only signed-in students can submit a payment.'], 403);
}

$eventId = trim((string) ($fields['event_id'] ?? ''));
$reference = trim((string) ($fields['reference_number'] ?? ''));
$amount = filter_var($fields['amount'] ?? null, FILTER_VALIDATE_FLOAT);
if ($eventId === '' || $reference === '' || mb_strlen($reference) > 80) {
    json_response(['ok' => false, 'error' => 'Enter the event and GCash reference number.'], 400);
}
if ($amount === false || !is_finite((float) $amount) || (float) $amount <= 0) {
    json_response(['ok' => false, 'error' => 'Enter a valid amount greater than zero.'], 400);
}

$clientIp = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
if (!api_rate_limit_allow('mobile_event_payment:' . $studentId . ':' . $clientIp, 8, 300)) {
    json_response(['ok' => false, 'error' => 'Too many payment submissions. Please wait and try again.'], 429);
}

$fileKey = isset($_FILES['receipt_file']) ? 'receipt_file' : '';
if ($fileKey === '' || !is_array($_FILES[$fileKey])) {
    json_response(['ok' => false, 'error' => 'Upload a screenshot of your GCash receipt.'], 400);
}
$upload = $_FILES[$fileKey];
if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    json_response(['ok' => false, 'error' => 'Receipt upload failed. Please try again.'], 400);
}
$tmpName = (string) ($upload['tmp_name'] ?? '');
$size = (int) ($upload['size'] ?? 0);
if ($tmpName === '' || !is_uploaded_file($tmpName) || $size <= 0 || $size > 5 * 1024 * 1024) {
    json_response(['ok' => false, 'error' => 'Receipt must be a valid image no larger than 5MB.'], 400);
}
$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mimeType = $finfo ? (string) finfo_file($finfo, $tmpName) : 'application/octet-stream';
if ($finfo) {
    finfo_close($finfo);
}
$allowedMimes = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
if (!isset($allowedMimes[$mimeType]) || @getimagesize($tmpName) === false) {
    json_response(['ok' => false, 'error' => 'Receipt must be a JPG, PNG, or WEBP image.'], 400);
}
$fileBytes = file_get_contents($tmpName);
if (!is_string($fileBytes) || $fileBytes === '') {
    json_response(['ok' => false, 'error' => 'Could not read the uploaded receipt.'], 400);
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
if ($fee === null || (float) $amount > $fee) {
    json_response(['ok' => false, 'error' => 'Amount cannot exceed the event fee.'], 400);
}
if (trim((string) ($event['gcash_account_name'] ?? '')) === ''
    || trim((string) ($event['gcash_number'] ?? '')) === '') {
    json_response(['ok' => false, 'error' => 'The organizer has not set up GCash payments for this event yet.'], 409);
}
$student = fetch_student_profile_by_id($studentId, $headers);
if (!is_array($student)
    || !student_matches_event_target($student, (string) ($event['event_for'] ?? 'All'))) {
    json_response(['ok' => false, 'error' => 'You are not eligible to pay for this event.'], 403);
}
$alreadyRegistered = is_array(fetch_existing_event_registration($eventId, $studentId, $headers));
if (!$alreadyRegistered && is_event_registration_window_closed($event)) {
    json_response(['ok' => false, 'error' => 'The registration window for this event is closed.'], 403);
}
if (!$alreadyRegistered) {
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
        json_response(['ok' => false, 'error' => $isSessionEvent
            ? 'Registration is full for your assigned seminar.'
            : 'Registration is full for this event.'], 409);
    }
}

$accessRows = supabase_request('GET', rtrim(SUPABASE_URL, '/') . '/rest/v1/event_registration_access'
    . '?select=approved,payment_status&event_id=eq.' . rawurlencode($eventId)
    . '&student_id=eq.' . rawurlencode($studentId) . '&limit=1', $headers);
$access = json_decode((string) ($accessRows['body'] ?? ''), true);
if (is_array($access) && isset($access[0]) && registration_access_row_allows($access[0])) {
    json_response(['ok' => false, 'error' => 'Your payment is already confirmed for this event.'], 409);
}

$existingRes = supabase_request('GET', rtrim(SUPABASE_URL, '/') . '/rest/v1/event_payment_submissions'
    . '?select=id,receipt_path&event_id=eq.' . rawurlencode($eventId)
    . '&student_id=eq.' . rawurlencode($studentId) . '&status=eq.pending&limit=1', $headers);
if (!$existingRes['ok']) {
    json_response(['ok' => false, 'error' => 'Payment submissions are not available yet. Run migration 073 first.'], 500);
}
$existingRows = json_decode((string) ($existingRes['body'] ?? ''), true);
$existing = is_array($existingRows) && isset($existingRows[0]) && is_array($existingRows[0])
    ? $existingRows[0]
    : null;
if (is_array($existing)) {
    json_response(['ok' => false, 'error' => 'Your GCash payment is already waiting for organizer review.'], 409);
}

$objectPath = $eventId . '/' . $studentId . '/' . bin2hex(random_bytes(16)) . '.' . $allowedMimes[$mimeType];
$storageUrl = rtrim(SUPABASE_URL, '/') . '/storage/v1/object/event-payment-proofs/'
    . implode('/', array_map('rawurlencode', explode('/', $objectPath)));
$storageRes = supabase_request('POST', $storageUrl, [
    'Content-Type: ' . $mimeType,
    'x-upsert: false',
    'apikey: ' . SUPABASE_KEY,
    'Authorization: Bearer ' . SUPABASE_KEY,
], $fileBytes);
if (!$storageRes['ok']) {
    json_response(['ok' => false, 'error' => 'Could not safely store the receipt. Please try again.'], 500);
}

$payload = [
    'amount' => round((float) $amount, 2),
    'reference_number' => $reference,
    'receipt_path' => $objectPath,
    'status' => 'pending',
    'review_note' => null,
    'reviewed_by' => null,
    'reviewed_at' => null,
    'created_at' => gmdate('c'),
];
$payload['event_id'] = $eventId;
$payload['student_id'] = $studentId;
$saveRes = supabase_request('POST', rtrim(SUPABASE_URL, '/') . '/rest/v1/event_payment_submissions', [
    'Content-Type: application/json', 'Accept: application/json',
    'apikey: ' . SUPABASE_KEY, 'Authorization: Bearer ' . SUPABASE_KEY,
    'Prefer: return=minimal',
], json_encode($payload, JSON_UNESCAPED_SLASHES));
if (!$saveRes['ok']) {
    json_response(['ok' => false, 'error' => 'Receipt was uploaded but the payment request could not be saved. Please retry.'], 500);
}

json_response(['ok' => true, 'status' => 'pending', 'message' => 'GCash payment submitted. Your slot will be secured after organizer approval.']);
