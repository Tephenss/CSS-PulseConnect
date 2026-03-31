<?php
declare(strict_types=1);

session_start();

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/supabase.php';

$user = require_role(['student']);
$code = isset($_GET['code']) ? (string) $_GET['code'] : '';
if ($code === '') {
    http_response_code(400);
    echo 'Missing code';
    exit;
}

$headers = [
    'Accept: application/json',
    'apikey: ' . SUPABASE_KEY,
    'Authorization: Bearer ' . SUPABASE_KEY,
];

// Certificate + event + student
$cUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/certificates'
    . '?select=event_id,student_id,certificate_code,issued_at,events(title)&certificate_code=eq.' . rawurlencode($code)
    . '&limit=1';
$cRes = supabase_request('GET', $cUrl, $headers);
$cRows = $cRes['ok'] ? json_decode((string) $cRes['body'], true) : null;
$cert = is_array($cRows) && isset($cRows[0]) ? $cRows[0] : null;
if (!is_array($cert)) {
    http_response_code(404);
    echo 'Certificate not found';
    exit;
}
if ((string) ($cert['student_id'] ?? '') !== (string) ($user['id'] ?? '')) {
    http_response_code(403);
    echo 'Forbidden';
    exit;
}

$eventId = (string) ($cert['event_id'] ?? '');
$eventTitle = '';
if (isset($cert['events']) && is_array($cert['events'])) {
    $eventTitle = (string) ($cert['events']['title'] ?? '');
}

// Student name
$uUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/' . SUPABASE_TABLE_USERS
    . '?select=first_name,middle_name,last_name,suffix&id=eq.' . rawurlencode((string) ($user['id'] ?? ''))
    . '&limit=1';
$uRes = supabase_request('GET', $uUrl, $headers);
$uRows = $uRes['ok'] ? json_decode((string) $uRes['body'], true) : null;
$u = is_array($uRows) && isset($uRows[0]) ? $uRows[0] : [];

$nameParts = [];
foreach (['first_name','middle_name','last_name'] as $k) {
    $v = trim((string) ($u[$k] ?? ''));
    if ($v !== '') $nameParts[] = $v;
}
$name = implode(' ', $nameParts);
$suffix = trim((string) ($u['suffix'] ?? ''));
if ($suffix !== '') $name .= ', ' . $suffix;

// Template
$tUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/certificate_templates'
    . '?select=title,body_text,footer_text&event_id=eq.' . rawurlencode($eventId)
    . '&limit=1';
$tRes = supabase_request('GET', $tUrl, $headers);
$tRows = $tRes['ok'] ? json_decode((string) $tRes['body'], true) : null;
$tpl = is_array($tRows) && isset($tRows[0]) ? $tRows[0] : [
    'title' => 'Certificate of Participation',
    'body_text' => 'This certifies that {{name}} participated in {{event}}.',
    'footer_text' => null,
];

$body = (string) ($tpl['body_text'] ?? '');
$body = str_replace(['{{name}}', '{{event}}'], [$name, $eventTitle], $body);

// Render printable certificate.
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1"/>
  <title>Certificate</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <style>
    @media print { .no-print { display: none; } }
  </style>
</head>
<body class="min-h-screen bg-zinc-950 text-zinc-100 p-6">
  <div class="no-print max-w-4xl mx-auto mb-4 flex justify-between items-center gap-3">
    <a class="text-sm text-zinc-300 hover:underline" href="/my_certificates.php">← Back</a>
    <button onclick="window.print()" class="rounded-lg bg-zinc-100 text-zinc-900 px-4 py-2 text-sm font-medium hover:bg-zinc-200">Print / Save as PDF</button>
  </div>

  <div class="max-w-4xl mx-auto bg-white text-zinc-900 rounded-2xl p-10 border border-zinc-200">
    <div class="text-center">
      <div class="text-xs tracking-widest uppercase text-zinc-500">CCS PulseConnect</div>
      <h1 class="text-3xl font-semibold mt-3"><?= htmlspecialchars((string) ($tpl['title'] ?? 'Certificate')) ?></h1>
      <div class="mt-10 text-lg text-zinc-700">This is to certify that</div>
      <div class="mt-3 text-4xl font-bold"><?= htmlspecialchars($name) ?></div>
      <div class="mt-7 text-lg text-zinc-700"><?= nl2br(htmlspecialchars($body)) ?></div>
      <div class="mt-10 text-sm text-zinc-500">
        Code: <span class="font-mono"><?= htmlspecialchars((string) ($cert['certificate_code'] ?? '')) ?></span>
      </div>
      <?php if (!empty($tpl['footer_text'])): ?>
        <div class="mt-6 text-sm text-zinc-600"><?= htmlspecialchars((string) $tpl['footer_text']) ?></div>
      <?php endif; ?>
    </div>
  </div>
</body>
</html>

<?php

