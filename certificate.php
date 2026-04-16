<?php
declare(strict_types=1);

session_start();

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/supabase.php';
require_once __DIR__ . '/includes/event_sessions.php';

$user = require_role(['student', 'teacher', 'admin']);
$role = (string) ($user['role'] ?? 'admin');
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

// Check session-scoped certificates first, then fall back to the legacy event-level table.
$sessionCertUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/event_session_certificates'
    . '?select=session_id,student_id,template_id,session_template_id,certificate_code,issued_at,event_sessions(title,topic,event_id,events(title))'
    . '&certificate_code=eq.' . rawurlencode($code)
    . '&limit=1';
$sessionCertRes = supabase_request('GET', $sessionCertUrl, $headers);
$sessionCertRows = $sessionCertRes['ok'] ? json_decode((string) $sessionCertRes['body'], true) : null;
$sessionCert = is_array($sessionCertRows) && isset($sessionCertRows[0]) ? $sessionCertRows[0] : null;

$isSessionCertificate = is_array($sessionCert);
$cert = null;
$eventId = '';
$eventTitle = '';
$sessionId = '';
$sessionTitle = '';

if ($isSessionCertificate) {
    $cert = $sessionCert;
    if ($role !== 'admin' && (string) ($cert['student_id'] ?? '') !== (string) ($user['id'] ?? '')) {
        http_response_code(403);
        echo 'Forbidden';
        exit;
    }

    $session = isset($cert['event_sessions']) && is_array($cert['event_sessions']) ? $cert['event_sessions'] : [];
    $sessionId = (string) ($cert['session_id'] ?? '');
    $eventId = (string) ($session['event_id'] ?? '');
    $eventTitle = isset($session['events']) && is_array($session['events'])
        ? (string) ($session['events']['title'] ?? '')
        : '';
    $sessionTitle = build_session_display_name($session);
} else {
    $cUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/certificates'
        . '?select=event_id,student_id,template_id,certificate_code,issued_at,events(title)&certificate_code=eq.' . rawurlencode($code)
        . '&limit=1';
    $cRes = supabase_request('GET', $cUrl, $headers);
    $cRows = $cRes['ok'] ? json_decode((string) $cRes['body'], true) : null;
    $cert = is_array($cRows) && isset($cRows[0]) ? $cRows[0] : null;
    if (!is_array($cert)) {
        http_response_code(404);
        echo 'Certificate not found';
        exit;
    }
    if ($role !== 'admin' && (string) ($cert['student_id'] ?? '') !== (string) ($user['id'] ?? '')) {
        http_response_code(403);
        echo 'Forbidden';
        exit;
    }

    $eventId = (string) ($cert['event_id'] ?? '');
    if (isset($cert['events']) && is_array($cert['events'])) {
        $eventTitle = (string) ($cert['events']['title'] ?? '');
    }
}

// Student name
$uUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/' . SUPABASE_TABLE_USERS
    . '?select=first_name,middle_name,last_name,suffix&id=eq.' . rawurlencode((string) ($cert['student_id'] ?? ''))
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
$tpl = null;
$canvasState = null;
if ($isSessionCertificate && !empty($cert['session_template_id'])) {
    $tUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/event_session_certificate_templates'
        . '?select=title,body_text,footer_text,canvas_state&id=eq.' . rawurlencode((string) $cert['session_template_id'])
        . '&limit=1';
    $tRes = supabase_request('GET', $tUrl, $headers);
    $tRows = $tRes['ok'] ? json_decode((string) $tRes['body'], true) : null;
    $tpl = is_array($tRows) && isset($tRows[0]) ? $tRows[0] : null;
}

if (!is_array($tpl) && !empty($cert['template_id'])) {
    $tUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/certificate_templates'
        . '?select=title,body_text,footer_text,canvas_state&id=eq.' . rawurlencode((string) $cert['template_id'])
        . '&limit=1';
    $tRes = supabase_request('GET', $tUrl, $headers);
    $tRows = $tRes['ok'] ? json_decode((string) $tRes['body'], true) : null;
    $tpl = is_array($tRows) && isset($tRows[0]) ? $tRows[0] : null;
}

if (!is_array($tpl) && $isSessionCertificate) {
    $tUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/event_session_certificate_templates'
        . '?select=title,body_text,footer_text,canvas_state&session_id=eq.' . rawurlencode($sessionId)
        . '&limit=1';
    $tRes = supabase_request('GET', $tUrl, $headers);
    $tRows = $tRes['ok'] ? json_decode((string) $tRes['body'], true) : null;
    $tpl = is_array($tRows) && isset($tRows[0]) ? $tRows[0] : null;

    if (!is_array($tpl) && $eventId !== '') {
        $fallbackUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/certificate_templates'
            . '?select=title,body_text,footer_text,canvas_state&event_id=eq.' . rawurlencode($eventId)
            . '&limit=1';
        $fallbackRes = supabase_request('GET', $fallbackUrl, $headers);
        $fallbackRows = $fallbackRes['ok'] ? json_decode((string) $fallbackRes['body'], true) : null;
        $tpl = is_array($fallbackRows) && isset($fallbackRows[0]) ? $fallbackRows[0] : null;
    }
} else {
    $tUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/certificate_templates'
        . '?select=title,body_text,footer_text,canvas_state&event_id=eq.' . rawurlencode($eventId)
        . '&limit=1';
    $tRes = supabase_request('GET', $tUrl, $headers);
    $tRows = $tRes['ok'] ? json_decode((string) $tRes['body'], true) : null;
    $tpl = is_array($tRows) && isset($tRows[0]) ? $tRows[0] : null;
}

if (!is_array($tpl)) {
    $tpl = [
        'title' => 'Certificate of Participation',
        'body_text' => $isSessionCertificate
            ? 'This certifies that {{name}} participated in {{session}}.'
            : 'This certifies that {{name}} participated in {{event}}.',
        'footer_text' => null,
        'canvas_state' => null,
    ];
}

$canvasState = $tpl['canvas_state'] ?? null;

$body = (string) ($tpl['body_text'] ?? '');
$body = str_replace(
    ['{{name}}', '{{event}}', '{{session}}'],
    [$name, $eventTitle, $sessionTitle],
    $body
);

// Render printable certificate.
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1"/>
  <title>Certificate</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <?php if (!empty($canvasState)): ?>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/fabric.js/5.3.1/fabric.min.js"></script>
  <?php endif; ?>
  <style>
    @media print { .no-print { display: none; } }
  </style>
</head>
<body class="min-h-screen bg-zinc-950 text-zinc-100 p-6">
  <div class="no-print max-w-4xl mx-auto mb-4 flex justify-between items-center gap-3">
    <a class="text-sm text-zinc-300 hover:underline" href="/my_certificates.php">← Back</a>
    <button onclick="window.print()" class="rounded-lg bg-zinc-100 text-zinc-900 px-4 py-2 text-sm font-medium hover:bg-zinc-200">Print / Save as PDF</button>
  </div>

  <?php if (!empty($canvasState)): ?>
    <div class="max-w-5xl mx-auto bg-white text-zinc-900 rounded-2xl p-6 border border-zinc-200">
      <div class="overflow-auto">
        <canvas id="certCanvas" width="1123" height="794" class="mx-auto block"></canvas>
      </div>
    </div>
    <script>
      const CERT_DATA = {
        participant_name: <?= json_encode($name) ?>,
        event_name: <?= json_encode($eventTitle) ?>,
        session_name: <?= json_encode($sessionTitle) ?>,
        certificate_code: <?= json_encode((string) ($cert['certificate_code'] ?? '')) ?>,
        issued_at: <?= json_encode((string) ($cert['issued_at'] ?? '')) ?>
      };

      const rawState = <?= json_encode($canvasState) ?>;
      const canvas = new fabric.Canvas('certCanvas', {
        width: 1123,
        height: 794,
        backgroundColor: '#ffffff',
        selection: false
      });

      const replaceTokens = (text) => {
        if (typeof text !== 'string') return text;
        return text
          .replace(/{{participant_name}}/g, CERT_DATA.participant_name || '')
          .replace(/{{name}}/g, CERT_DATA.participant_name || '')
          .replace(/{{event}}/g, CERT_DATA.event_name || '')
          .replace(/{{session}}/g, CERT_DATA.session_name || '')
          .replace(/{{certificate_code}}/g, CERT_DATA.certificate_code || '')
          .replace(/{{issued_at}}/g, CERT_DATA.issued_at || '');
      };

      canvas.loadFromJSON(rawState, () => {
        canvas.getObjects().forEach(obj => {
          if (obj.type === 'text' || obj.type === 'i-text' || obj.type === 'textbox') {
            obj.text = replaceTokens(obj.text);
          }
        });
        canvas.renderAll();
      });
    </script>
  <?php else: ?>
    <div class="max-w-4xl mx-auto bg-white text-zinc-900 rounded-2xl p-10 border border-zinc-200">
      <div class="text-center">
        <div class="text-xs tracking-widest uppercase text-zinc-500">CCS PulseConnect</div>
        <h1 class="text-3xl font-semibold mt-3"><?= htmlspecialchars((string) ($tpl['title'] ?? 'Certificate')) ?></h1>
        <?php if ($isSessionCertificate && $sessionTitle !== ''): ?>
          <div class="mt-4 text-sm font-semibold text-zinc-500 uppercase tracking-[0.2em]"><?= htmlspecialchars($sessionTitle) ?></div>
        <?php endif; ?>
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
  <?php endif; ?>
</body>
</html>

<?php

