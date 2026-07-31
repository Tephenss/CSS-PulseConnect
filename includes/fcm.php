<?php
declare(strict_types=1);

if (!defined('SUPABASE_DEV_SKIP_SSL_VERIFY')) {
    $configPath = dirname(__DIR__) . '/config.php';
    if (is_file($configPath)) {
        require_once $configPath;
    }
}

require_once __DIR__ . '/curl_ssl.php';

/**
 * Helper to send FCM notifications using Firebase HTTP v1 API.
 * Requires the service-account.json to be placed in the api/ directory.
 */
/**
 * @return array{ok:bool,sent:int,failed:int,http_status:?int,error:?string,detail:?string}
 */
function send_fcm_notification_detailed(array $tokens, string $title, string $body, array $data = []): array
{
    if (empty($tokens)) {
        return [
            'ok' => false,
            'sent' => 0,
            'failed' => 0,
            'http_status' => null,
            'error' => 'no_tokens',
            'detail' => null,
        ];
    }

    $keyData = null;
    $candidatePaths = [
        __DIR__ . '/service-account.json',
        __DIR__ . '/fcm-credentials.php',
        dirname(__DIR__) . '/api/service-account.json',
        dirname(__DIR__) . '/includes/service-account.json',
        dirname(__DIR__) . '/includes/fcm-credentials.php',
    ];

    foreach ($candidatePaths as $keyFilePath) {
        if (!is_file($keyFilePath)) {
            continue;
        }
        if (str_ends_with(strtolower($keyFilePath), '.php')) {
            $loaded = require $keyFilePath;
            if (is_array($loaded)) {
                $keyData = $loaded;
                break;
            }
        } else {
            $decoded = json_decode((string) file_get_contents($keyFilePath), true);
            if (is_array($decoded)) {
                $keyData = $decoded;
                break;
            }
        }
    }

    if ($keyData === null) {
        error_log('FCM Key configuration missing (checked includes/ and api/).');
        return [
            'ok' => false,
            'sent' => 0,
            'failed' => count($tokens),
            'http_status' => null,
            'error' => 'missing_credentials',
            'detail' => 'FCM service account file not found',
        ];
    }

    if (!isset($keyData['client_email']) || !isset($keyData['private_key']) || !isset($keyData['project_id'])) {
        error_log('Invalid FCM Key file structure.');
        return [
            'ok' => false,
            'sent' => 0,
            'failed' => count($tokens),
            'http_status' => null,
            'error' => 'invalid_credentials',
            'detail' => 'FCM credentials missing required fields',
        ];
    }

    $privateKey = (string) $keyData['private_key'];
    // Support JSON-escaped keys that still contain literal "\n" sequences.
    if (str_contains($privateKey, '\\n') && !str_contains($privateKey, "\n")) {
        $privateKey = str_replace('\\n', "\n", $privateKey);
    }

    $accessToken = get_fcm_access_token($keyData['client_email'], $privateKey);
    if (!$accessToken) {
        error_log('FCM OAuth access token could not be obtained.');
        return [
            'ok' => false,
            'sent' => 0,
            'failed' => count($tokens),
            'http_status' => null,
            'error' => 'oauth_failed',
            'detail' => 'Could not get Google OAuth token (check private_key / SSL)',
        ];
    }

    $url = 'https://fcm.googleapis.com/v1/projects/' . $keyData['project_id'] . '/messages:send';

    $stringData = [];
    if (!empty($data)) {
        foreach ($data as $key => $value) {
            $k = trim((string) $key);
            if ($k === '') {
                continue;
            }
            $stringData[$k] = (string) $value;
        }
    }

    $tokenList = array_values(array_filter(array_map(
        static fn ($t): string => trim((string) $t),
        $tokens
    )));
    if ($tokenList === []) {
        return [
            'ok' => false,
            'sent' => 0,
            'failed' => 0,
            'http_status' => null,
            'error' => 'no_tokens',
            'detail' => null,
        ];
    }

    $sent = 0;
    $failed = 0;
    $lastHttp = null;
    $lastError = null;
    $lastDetail = null;
    $batchSize = 20;

    for ($offset = 0; $offset < count($tokenList); $offset += $batchSize) {
        $batch = array_slice($tokenList, $offset, $batchSize);
        $mh = curl_multi_init();
        $handles = [];

        foreach ($batch as $deviceToken) {
            $message = [
                'token' => $deviceToken,
                'notification' => [
                    'title' => $title,
                    'body' => $body,
                ],
                'android' => [
                    'priority' => 'HIGH',
                    'notification' => [
                        'sound' => 'default',
                        'channel_id' => 'pulseconnect_events',
                    ],
                ],
                'apns' => [
                    'payload' => [
                        'aps' => [
                            'sound' => 'default',
                        ],
                    ],
                ],
            ];
            if ($stringData !== []) {
                $message['data'] = $stringData;
            }

            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Authorization: Bearer ' . $accessToken,
                'Content-Type: application/json',
            ]);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 8);
            curl_setopt($ch, CURLOPT_TIMEOUT, 20);
            apply_curl_ssl_policy($ch);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['message' => $message]));
            curl_multi_add_handle($mh, $ch);
            $handles[] = ['ch' => $ch, 'token' => $deviceToken];
        }

        $running = null;
        do {
            $status = curl_multi_exec($mh, $running);
            if ($running > 0) {
                curl_multi_select($mh, 1.0);
            }
        } while ($running > 0 && $status === CURLM_OK);

        foreach ($handles as $item) {
            $ch = $item['ch'];
            $deviceToken = $item['token'];
            $result = curl_multi_getcontent($ch);
            $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlErr = curl_error($ch);
            $lastHttp = $httpCode > 0 ? $httpCode : $lastHttp;

            if ($result === false || $result === null || $httpCode === 0) {
                $failed++;
                $lastError = 'curl_error';
                $lastDetail = $curlErr !== '' ? $curlErr : 'empty FCM response (SSL/network?)';
                error_log('FCM Curl Error for token ' . substr($deviceToken, 0, 12) . '...: ' . $lastDetail);
            } elseif ($httpCode !== 200) {
                $failed++;
                $decoded = json_decode((string) $result, true);
                $fcmStatus = '';
                $fcmMessage = '';
                if (is_array($decoded)) {
                    $fcmStatus = (string) ($decoded['error']['status'] ?? '');
                    $fcmMessage = (string) ($decoded['error']['message'] ?? '');
                }
                $lastError = $fcmStatus !== '' ? strtolower($fcmStatus) : 'http_' . $httpCode;
                $lastDetail = $fcmMessage !== ''
                    ? $fcmMessage
                    : substr(trim((string) $result), 0, 180);
                error_log('FCM HTTP Error ' . $httpCode . ' status=' . $fcmStatus . ' msg=' . $lastDetail);

                // Drop dead tokens so future publishes are cleaner.
                if (in_array(strtoupper($fcmStatus), ['UNREGISTERED', 'INVALID_ARGUMENT', 'NOT_FOUND'], true)
                    || str_contains(strtolower($lastDetail), 'not a valid fcm')
                    || str_contains(strtolower($lastDetail), 'requested entity was not found')
                ) {
                    fcm_delete_token_best_effort($deviceToken);
                }
            } else {
                $sent++;
            }

            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
        }
        curl_multi_close($mh);
    }

    return [
        'ok' => $sent > 0 && $failed === 0,
        'sent' => $sent,
        'failed' => $failed,
        'http_status' => $lastHttp,
        'error' => $failed > 0 ? ($lastError ?? 'fcm_send_failed') : null,
        'detail' => $failed > 0 ? $lastDetail : null,
    ];
}

function send_fcm_notification(array $tokens, string $title, string $body, array $data = [])
{
    $result = send_fcm_notification_detailed($tokens, $title, $body, $data);
    return !empty($result['ok']);
}

function fcm_delete_token_best_effort(string $token): void
{
    $token = trim($token);
    if ($token === '' || !defined('SUPABASE_URL') || !defined('SUPABASE_KEY')) {
        return;
    }
    try {
        supabase_request(
            'DELETE',
            rtrim(SUPABASE_URL, '/') . '/rest/v1/fcm_tokens?token=eq.' . rawurlencode($token),
            [
                'Accept: application/json',
                'apikey: ' . SUPABASE_KEY,
                'Authorization: Bearer ' . SUPABASE_KEY,
            ]
        );
    } catch (Throwable $e) {
        // Best-effort cleanup only.
    }
}

/**
 * Generate an OAuth2.0 token representing Firebase Admin using RS256 JWT
 */
function get_fcm_access_token($clientEmail, $privateKey) {
    $header = json_encode(['alg' => 'RS256', 'typ' => 'JWT']);
    $now = time();
    $payload = json_encode([
        'iss' => $clientEmail,
        'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
        'aud' => 'https://oauth2.googleapis.com/token',
        'exp' => $now + 3600,
        'iat' => $now
    ]);

    $base64UrlHeader = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($header));
    $base64UrlPayload = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($payload));

    $signature = '';
    $key = openssl_pkey_get_private((string) $privateKey);
    if ($key === false) {
        error_log('FCM openssl_pkey_get_private failed: ' . (openssl_error_string() ?: 'unknown'));
        return null;
    }
    $signed = openssl_sign($base64UrlHeader . '.' . $base64UrlPayload, $signature, $key, OPENSSL_ALGO_SHA256);
    if (!$signed) {
        error_log('FCM openssl_sign failed: ' . (openssl_error_string() ?: 'unknown'));
        return null;
    }
    $base64UrlSignature = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($signature));

    $jwt = $base64UrlHeader . '.' . $base64UrlPayload . '.' . $base64UrlSignature;

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'https://oauth2.googleapis.com/token');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    apply_curl_ssl_policy($ch);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
        'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
        'assertion' => $jwt
    ]));

    $response = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);

    if ($response === false || $response === '') {
        error_log('FCM OAuth curl failed: ' . ($curlErr !== '' ? $curlErr : 'empty response'));
        return null;
    }
    $data = json_decode($response, true);
    if (!is_array($data) || empty($data['access_token'])) {
        error_log('FCM OAuth HTTP ' . $httpCode . ' body=' . substr((string) $response, 0, 300));
        return null;
    }
    return (string) $data['access_token'];
}
