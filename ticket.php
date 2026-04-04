<?php
declare(strict_types=1);

session_start();

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/supabase.php';
require_once __DIR__ . '/includes/layout.php';

$user = require_role(['admin']);
$role = (string) ($user['role'] ?? 'admin');
$token = isset($_GET['token']) ? (string) $_GET['token'] : '';
if ($token === '') {
    http_response_code(400);
    echo 'Missing token';
    exit;
}

$url = rtrim(SUPABASE_URL, '/') . '/rest/v1/tickets'
    . '?select=id,token,registration_id,event_registrations(student_id,event_id,events(title,start_at,end_at,location))'
    . '&token=eq.' . rawurlencode($token)
    . '&limit=1';
$headers = [
    'Accept: application/json',
    'apikey: ' . SUPABASE_KEY,
    'Authorization: Bearer ' . SUPABASE_KEY,
];
$res = supabase_request('GET', $url, $headers);
$rows = $res['ok'] ? json_decode((string) $res['body'], true) : null;
$ticket = is_array($rows) && isset($rows[0]) ? $rows[0] : null;

if (!is_array($ticket)) {
    http_response_code(404);
    echo 'Ticket not found';
    exit;
}

$reg = isset($ticket['event_registrations']) && is_array($ticket['event_registrations']) ? $ticket['event_registrations'] : null;
if (!is_array($reg) || ($role !== 'admin' && (string) ($reg['student_id'] ?? '') !== (string) ($user['id'] ?? ''))) {
    http_response_code(403);
    echo 'Forbidden';
    exit;
}

$event = isset($reg['events']) && is_array($reg['events']) ? $reg['events'] : [];

render_header('Ticket', $user);
?>

<div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
  <div class="rounded-2xl border border-zinc-800 bg-zinc-900/30 p-6">
    <div class="text-sm uppercase tracking-widest text-zinc-400">E-ticket</div>
    <h1 class="text-2xl font-semibold mt-2"><?= htmlspecialchars((string) ($event['title'] ?? 'Event')) ?></h1>
    <p class="text-zinc-400 text-sm mt-2"><?= htmlspecialchars((string) ($event['location'] ?? 'TBA')) ?></p>

    <div class="mt-5 text-sm text-zinc-400">
      Start: <span class="text-zinc-200"><?= htmlspecialchars((string) ($event['start_at'] ?? '')) ?></span><br/>
      End: <span class="text-zinc-200"><?= htmlspecialchars((string) ($event['end_at'] ?? '')) ?></span>
    </div>

    <div class="mt-5 text-xs text-zinc-500 break-all">
      Token: <?= htmlspecialchars((string) ($ticket['token'] ?? '')) ?>
    </div>

    <div class="mt-6">
      <button onclick="window.print()" class="rounded-lg border border-zinc-800 bg-zinc-900/40 px-4 py-2.5 text-sm hover:bg-zinc-900 transition">
        Print / Save as PDF
      </button>
    </div>
  </div>

  <div class="rounded-2xl border border-zinc-800 bg-zinc-900/30 p-6 flex items-center justify-center">
    <div class="text-center">
      <div class="text-sm uppercase tracking-widest text-zinc-400">QR</div>
      <div id="qrcode" class="mt-4 bg-white p-4 rounded-xl"></div>
      <p class="text-xs text-zinc-500 mt-3">Show this QR to the scanner.</p>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/qrcodejs@1.0.0/qrcode.min.js"></script>
<script>
  new QRCode(document.getElementById("qrcode"), {
    text: <?= json_encode((string) $token) ?>,
    width: 240,
    height: 240,
    correctLevel: QRCode.CorrectLevel.M
  });
</script>

<?php render_footer(); ?>

