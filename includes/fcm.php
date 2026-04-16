<?php
declare(strict_types=1);

if (!defined('SUPABASE_DEV_SKIP_SSL_VERIFY')) {
    $configPath = dirname(__DIR__) . '/config.php';
    if (is_file($configPath)) {
        require_once $configPath;
    }
}

function apply_curl_ssl_policy($ch): void
{
    $skipSslVerify = defined('SUPABASE_DEV_SKIP_SSL_VERIFY') && SUPABASE_DEV_SKIP_SSL_VERIFY;
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, $skipSslVerify ? false : true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, $skipSslVerify ? 0 : 2);
}

/**
 * Helper to send FCM notifications using Firebase HTTP v1 API.
 * Requires the service-account.json to be placed in the api/ directory.
 */
function send_fcm_notification(array $tokens, string $title, string $body, array $data = []) {
    if (empty($tokens)) return false;

    $keyFilePath = __DIR__ . '/service-account.json';
    
    // Fallback securely to the PHP wrapper if it exists instead
    if (!file_exists($keyFilePath)) {
        $keyFilePath = __DIR__ . '/fcm-credentials.php';
        if (!file_exists($keyFilePath)) {
            error_log('FCM Key configuration missing in includes/');
            return false;
        }
        $keyData = require $keyFilePath;
    } else {
        $keyData = json_decode(file_get_contents($keyFilePath), true);
    }
    
    if (!is_array($keyData) || !isset($keyData['client_email']) || !isset($keyData['private_key']) || !isset($keyData['project_id'])) {
        error_log('Invalid FCM Key file structure.');
        return false;
    }

    $token = get_fcm_access_token($keyData['client_email'], $keyData['private_key']);
    if (!$token) return false;

    $url = "https://fcm.googleapis.com/v1/projects/{$keyData['project_id']}/messages:send";
    
    $success = true;
    foreach ($tokens as $deviceToken) {
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
                        'channel_id' => 'pulseconnect_events'
                    ]
                ],
                'apns' => [
                    'payload' => [
                        'aps' => [
                            'sound' => 'default'
                        ]
                    ]
                ]
            ];
        
        if (!empty($data)) {
            $stringData = [];
            foreach ($data as $key => $value) {
                $k = trim((string) $key);
                if ($k === '') {
                    continue;
                }
                $stringData[$k] = (string) $value;
            }
            if (!empty($stringData)) {
                $message['data'] = $stringData;
            }
        }

        $payload = ['message' => $message];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json'
        ]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        apply_curl_ssl_policy($ch);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        
        $result = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        
        if ($result === false) {
            error_log("FCM Curl Error for token $deviceToken: " . curl_error($ch));
            $success = false;
        } elseif ($httpCode !== 200) {
            error_log("FCM HTTP Error $httpCode for token $deviceToken. Response: $result");
            $success = false;
        }
        curl_close($ch);
    }

    
    return $success;
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
    openssl_sign($base64UrlHeader . "." . $base64UrlPayload, $signature, $privateKey, 'sha256WithRSAEncryption');
    $base64UrlSignature = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($signature));
    
    $jwt = $base64UrlHeader . "." . $base64UrlPayload . "." . $base64UrlSignature;
    
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
    curl_close($ch);
    
    if (!$response) return null;
    $data = json_decode($response, true);
    return $data['access_token'] ?? null;
}
