<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/supabase.php';
require_once __DIR__ . '/../includes/json.php';
require_once __DIR__ . '/../includes/mobile_api.php';
require_once __DIR__ . '/../includes/mobile_session.php';
require_once __DIR__ . '/../includes/device_trust.php';

try {
    $data = mobile_api_require_post_json();
    mobile_api_validate_key($data);

    $sessionUser = mobile_api_require_user($data);
    $userId = trim((string) ($sessionUser['id'] ?? ''));
    if ($userId === '') {
        json_response(['ok' => false, 'error' => 'Invalid mobile session user.'], 401);
    }

    $deviceKey = trim((string) ($data['device_key'] ?? ''));
    if ($deviceKey === '') {
        $deviceKey = device_trust_ip_key();
    }
    $deviceKey = strtolower($deviceKey);
    $isIpKey = str_starts_with($deviceKey, 'ip:');
    $isInstallKey = str_starts_with($deviceKey, 'install:');
    if ($deviceKey === '' || (!$isIpKey && !$isInstallKey)) {
        json_response(['ok' => false, 'error' => 'Unable to resolve device/IP key.'], 400);
    }
    if ($isInstallKey) {
        $installId = substr($deviceKey, strlen('install:'));
        if ($installId === '' || !preg_match('/^[a-f0-9-]{16,80}$/', $installId)) {
            json_response(['ok' => false, 'error' => 'Invalid install device key.'], 400);
        }
    }

    $platform = trim((string) ($data['platform'] ?? 'android'));
    $label = trim((string) ($data['label'] ?? $deviceKey));

    $upsert = device_trust_upsert(
        $userId,
        $deviceKey,
        $platform !== '' ? $platform : 'android',
        $label
    );

    if (($upsert['ok'] ?? false) !== true) {
        $error = trim((string) ($upsert['error'] ?? 'Failed to trust device.'));
        // Soft-fail for missing table so OTP/login is not blocked by deploy lag.
        if (($upsert['missing_table'] ?? false) === true) {
            json_response([
                'ok' => true,
                'device_key' => $deviceKey,
                'warning' => $error !== '' ? $error : 'trusted_devices table missing',
            ], 200);
        }
        json_response([
            'ok' => false,
            'error' => $error !== '' ? $error : 'Failed to trust device.',
        ], 502);
    }

    json_response(['ok' => true, 'device_key' => $deviceKey], 200);
} catch (Throwable $e) {
    error_log('mobile_trust_device: ' . $e->getMessage());
    json_response([
        'ok' => false,
        'error' => 'Trust device failed. Please try again.',
    ], 500);
}
