<?php
require_once 'config.php';
require_once 'includes/supabase.php';

// Testing 'remarks' column
$data = ['remarks' => 'Test Reason'];
$url = rtrim(SUPABASE_URL, '/') . '/rest/v1/events?id=eq.f657c633-07aa-4b54-9291-3347ef1af0f9';
$headers = [
    'Content-Type: application/json',
    'apikey: ' . SUPABASE_KEY,
    'Authorization: Bearer ' . SUPABASE_KEY,
    'Prefer: return=representation'
];

$res = supabase_request('PATCH', $url, $headers, json_encode($data));
header('Content-Type: application/json');
echo json_encode(['remarks_test' => $res['ok'], 'status' => $res['status'], 'body' => $res['body']]);
