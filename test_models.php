<?php
require_once __DIR__ . '/config.php';
$url = "https://generativelanguage.googleapis.com/v1beta/models?key=" . GEMINI_API_KEY;
$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
if (defined('SUPABASE_DEV_SKIP_SSL_VERIFY') && SUPABASE_DEV_SKIP_SSL_VERIFY) {
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
}
$response = curl_exec($ch);
curl_close($ch);
$data = json_decode($response, true);
if (isset($data['models'])) {
    foreach($data['models'] as $model) {
        if (in_array('generateContent', $model['supportedGenerationMethods'] ?? [])) {
            echo $model['name'] . "\n";
        }
    }
} else {
    echo "ERROR: " . print_r($data, true);
}
