<?php
require_once 'config.php';
require_once 'includes/supabase.php';
$res = supabase_request('GET', rtrim(SUPABASE_URL, '/') . '/rest/v1/events?select=*&limit=1', [
    'apikey: ' . SUPABASE_KEY,
    'Authorization: Bearer ' . SUPABASE_KEY
]);
header('Content-Type: application/json');
echo $res['body'];
