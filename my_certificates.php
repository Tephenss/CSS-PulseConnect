<?php
declare(strict_types=1);

session_start();

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/supabase.php';
require_once __DIR__ . '/includes/layout.php';

$user = require_role(['student']);
$headers = [
    'Accept: application/json',
    'apikey: ' . SUPABASE_KEY,
    'Authorization: Bearer ' . SUPABASE_KEY,
];

$url = rtrim(SUPABASE_URL, '/') . '/rest/v1/certificates'
    . '?select=id,certificate_code,issued_at,event_id,events(title,start_at)&student_id=eq.' . rawurlencode((string) ($user['id'] ?? ''))
    . '&order=issued_at.desc';

$res = supabase_request('GET', $url, $headers);
$rows = $res['ok'] ? json_decode((string) $res['body'], true) : null;
$certs = is_array($rows) ? $rows : [];

render_header('My Certificates', $user);
?>

<div class="mb-6">
  <p class="text-zinc-400 text-sm">Printable digital certificates appear after your attendance is fully verified.</p>
</div>

<div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4">
  <?php if (count($certs) === 0): ?>
    <div class="md:col-span-2 xl:col-span-3 rounded-xl glass-card p-8 text-center">
      <div class="w-12 h-12 rounded-full bg-zinc-800/60 flex items-center justify-center mx-auto mb-3">
        <svg class="w-6 h-6 text-zinc-500" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M4.26 10.147a60.436 60.436 0 00-.491 6.347A48.627 48.627 0 0112 20.904a48.627 48.627 0 018.232-4.41 60.46 60.46 0 00-.491-6.347m-15.482 0a50.57 50.57 0 00-2.658-.813A59.905 59.905 0 0112 3.493a59.902 59.902 0 0110.399 5.84c-.896.248-1.783.52-2.658.814m-15.482 0A50.697 50.697 0 0112 13.489a50.702 50.702 0 017.74-3.342"/></svg>
      </div>
      <div class="text-sm text-zinc-400">No certificates yet.</div>
      <div class="text-xs text-zinc-500 mt-1">Attend events and complete check-in/out to earn certificates.</div>
    </div>
  <?php endif; ?>

  <?php foreach ($certs as $c): ?>
    <?php
      $event = isset($c['events']) && is_array($c['events']) ? $c['events'] : [];
    ?>
    <a href="/certificate.php?code=<?= htmlspecialchars((string) ($c['certificate_code'] ?? '')) ?>"
       class="group block rounded-xl glass-card p-5 hover:border-zinc-600/60 transition-all duration-200">
      <div class="flex items-start gap-3">
        <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-emerald-600/20 to-teal-600/20 border border-emerald-500/20 flex items-center justify-center flex-shrink-0">
          <svg class="w-5 h-5 text-emerald-400" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12c0 1.268-.63 2.39-1.593 3.068a3.745 3.745 0 01-1.043 3.296 3.745 3.745 0 01-3.296 1.043A3.745 3.745 0 0112 21c-1.268 0-2.39-.63-3.068-1.593a3.746 3.746 0 01-3.296-1.043 3.745 3.745 0 01-1.043-3.296A3.745 3.745 0 013 12c0-1.268.63-2.39 1.593-3.068a3.745 3.745 0 011.043-3.296 3.746 3.746 0 013.296-1.043A3.746 3.746 0 0112 3c1.268 0 2.39.63 3.068 1.593a3.746 3.746 0 013.296 1.043 3.746 3.746 0 011.043 3.296A3.745 3.745 0 0121 12z"/></svg>
        </div>
        <div class="min-w-0">
          <div class="font-medium text-zinc-200 group-hover:text-white transition"><?= htmlspecialchars((string) ($event['title'] ?? 'Event')) ?></div>
          <div class="text-xs text-zinc-500 mt-1">Issued: <?= htmlspecialchars((string) ($c['issued_at'] ?? '')) ?></div>
        </div>
      </div>
      <div class="mt-3 text-[10px] text-zinc-600 font-mono break-all bg-zinc-900/40 rounded-lg px-2.5 py-1.5"><?= htmlspecialchars((string) ($c['certificate_code'] ?? '')) ?></div>
    </a>
  <?php endforeach; ?>
</div>

<?php render_footer(); ?>
