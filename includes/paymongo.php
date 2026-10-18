<?php
declare(strict_types=1);

require_once __DIR__ . '/supabase.php';

function paymongo_is_configured(): bool
{
    $apiKey = defined('PAYMONGO_SECRET_KEY') ? trim((string) PAYMONGO_SECRET_KEY) : '';
    $webhookSecret = defined('PAYMONGO_WEBHOOK_SECRET') ? trim((string) PAYMONGO_WEBHOOK_SECRET) : '';
    return (str_starts_with($apiKey, 'sk_test_') || str_starts_with($apiKey, 'sk_live_'))
        && $webhookSecret !== '';
}

/** @return array{ok:bool,status:int,data?:array,error?:string} */
function paymongo_request(string $method, string $path, array $payload, string $idempotencyKey): array
{
    if (!paymongo_is_configured()) {
        return ['ok' => false, 'status' => 503, 'error' => 'PayMongo is not configured on the server.'];
    }

    $body = json_encode($payload, JSON_UNESCAPED_SLASHES);
    if (!is_string($body)) {
        return ['ok' => false, 'status' => 500, 'error' => 'Could not prepare the payment request.'];
    }

    $ch = curl_init('https://api.paymongo.com' . $path);
    if ($ch === false) {
        return ['ok' => false, 'status' => 500, 'error' => 'Could not initialize the payment request.'];
    }

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'Content-Type: application/json',
            'Idempotency-Key: ' . $idempotencyKey,
        ],
        CURLOPT_USERPWD => trim((string) PAYMONGO_SECRET_KEY) . ':',
        CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 25,
        CURLOPT_NOSIGNAL => true,
    ]);
    apply_curl_ssl_policy($ch);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $body);

    $responseBody = curl_exec($ch);
    $curlError = curl_error($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if (!is_string($responseBody)) {
        error_log('PayMongo request failed: ' . substr($curlError, 0, 300));
        return ['ok' => false, 'status' => 502, 'error' => 'Could not reach PayMongo. Please try again.'];
    }

    $decoded = json_decode($responseBody, true);
    if ($status < 200 || $status >= 300 || !is_array($decoded)) {
        $detail = $decoded['errors'][0]['detail'] ?? $decoded['errors'][0]['code'] ?? null;
        error_log('PayMongo API error HTTP ' . $status . ': ' . substr($responseBody, 0, 1000));
        return [
            'ok' => false,
            'status' => 502,
            'error' => is_string($detail) && $detail !== ''
                ? 'PayMongo could not create the QR payment: ' . $detail
                : 'PayMongo could not create the QR payment. Please try again.',
        ];
    }

    return ['ok' => true, 'status' => $status, 'data' => $decoded];
}

function paymongo_verify_webhook_signature(string $signatureHeader, string $rawBody, string $secret, bool $liveMode): bool
{
    $parts = [];
    foreach (explode(',', $signatureHeader) as $part) {
        $pair = explode('=', trim($part), 2);
        if (count($pair) === 2) {
            $parts[trim($pair[0])] = trim($pair[1]);
        }
    }

    $timestamp = $parts['t'] ?? '';
    $provided = $parts[$liveMode ? 'li' : 'te'] ?? '';
    if ($timestamp === '' || $provided === '' || $secret === '') {
        return false;
    }

    $expected = hash_hmac('sha256', $timestamp . '.' . $rawBody, $secret);
    return hash_equals($expected, $provided);
}

function paymongo_checkout_update(string $checkoutId, array $payload, ?string $requiredStatus = null): array
{
    $query = '?id=eq.' . rawurlencode($checkoutId);
    if ($requiredStatus !== null) {
        $query .= '&status=eq.' . rawurlencode($requiredStatus);
    }
    return supabase_request(
        'PATCH',
        rtrim(SUPABASE_URL, '/') . '/rest/v1/event_payment_checkouts' . $query,
        [
            'Content-Type: application/json',
            'Accept: application/json',
            'apikey: ' . SUPABASE_KEY,
            'Authorization: Bearer ' . SUPABASE_KEY,
            'Prefer: return=representation',
        ],
        json_encode($payload, JSON_UNESCAPED_SLASHES)
    );
}

function paymongo_fetch_checkout_by_intent(string $intentId): ?array
{
    $url = rtrim(SUPABASE_URL, '/') . '/rest/v1/event_payment_checkouts'
        . '?select=id,event_id,student_id,amount,payment_intent_id,status,updated_at,paid_at,receipt_email_sent_at'
        . '&payment_intent_id=eq.' . rawurlencode($intentId)
        . '&limit=1';
    $res = supabase_request('GET', $url, [
        'Accept: application/json',
        'apikey: ' . SUPABASE_KEY,
        'Authorization: Bearer ' . SUPABASE_KEY,
    ]);
    $rows = json_decode((string) ($res['body'] ?? ''), true);
    return $res['ok'] && is_array($rows) && isset($rows[0]) && is_array($rows[0])
        ? $rows[0]
        : null;
}
