<?php
require 'config.php';
echo "Gemini Key Prefix: " . substr(GEMINI_API_KEY, 0, 8) . "...\n";

// Test Gemini
$geminiUrl = "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent?key=" . GEMINI_API_KEY;
$payload = ["contents" => [["role" => "user", "parts" => [["text" => "Confirm you are working."]]]]];
$ch = curl_init($geminiUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
if (defined('SUPABASE_DEV_SKIP_SSL_VERIFY') && SUPABASE_DEV_SKIP_SSL_VERIFY) {
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
}
$result = curl_exec($ch);
$info = curl_getinfo($ch);
curl_close($ch);

echo "Gemini HTTP Code: " . $info['http_code'] . "\n";
if ($info['http_code'] == 200) {
    echo "Gemini is WORKING!\n";
} else {
    echo "Gemini Failed: " . $result . "\n";
}

// Test FCM File
$fcmPath = __DIR__ . '/includes/service-account.json';
if (file_exists($fcmPath)) {
    echo "FCM JSON detected: OK\n";
    $data = json_decode(file_get_contents($fcmPath), true);
    if ($data && isset($data['project_id'])) {
        echo "FCM JSON structure: OK (" . $data['project_id'] . ")\n";
    } else {
        echo "FCM JSON structure: ERROR!\n";
    }
} else {
    echo "FCM JSON missing!\n";
}
