<?php
declare(strict_types=1);

session_start();

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/web_notifications.php';

$user = require_role(['admin', 'teacher']);
$notifications = web_fetch_notifications_for_user($user, 100);

render_header('Notifications', $user);
?>

<div class="mb-8 flex items-start justify-between gap-4">
  <div>
    <h2 class="text-xl font-bold text-zinc-900 mb-1">Notifications</h2>
    <p class="text-zinc-600 text-sm">Review your latest updates, alerts, and action items.</p>
  </div>
  <div class="px-3 py-2 rounded-xl bg-zinc-100 border border-zinc-200 text-xs font-semibold text-zinc-700">
    Total: <?= count($notifications) ?>
  </div>
</div>

<section class="bg-white border border-zinc-200 rounded-2xl shadow-sm overflow-hidden">
  <div class="px-5 py-4 border-b border-zinc-200 flex items-center justify-between">
    <h3 class="font-bold text-zinc-900">All Notifications</h3>
    <span class="text-xs font-semibold text-emerald-700 bg-emerald-50 border border-emerald-200 px-2 py-1 rounded-full">
      <?= htmlspecialchars(ucfirst((string) ($user['role'] ?? 'user'))) ?>
    </span>
  </div>

  <?php if ($notifications === []): ?>
    <div class="px-6 py-16 text-center">
      <div class="mx-auto w-14 h-14 rounded-full bg-zinc-100 flex items-center justify-center mb-4">
        <svg class="w-7 h-7 text-zinc-400" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9" />
        </svg>
      </div>
      <p class="text-sm font-semibold text-zinc-700">You&apos;re all caught up.</p>
      <p class="text-xs text-zinc-500 mt-1">New alerts will appear here as soon as something needs your attention.</p>
    </div>
  <?php else: ?>
    <div class="divide-y divide-zinc-200">
      <?php foreach ($notifications as $item): ?>
        <?php
          $createdAtRaw = (string) ($item['created_at'] ?? '');
          $createdAtTs = strtotime($createdAtRaw);
          $createdAtLabel = $createdAtTs
              ? date('M j, Y g:i A', $createdAtTs)
              : 'Unknown time';
          $title = trim((string) ($item['title'] ?? 'Notification'));
          $description = trim((string) ($item['description'] ?? ''));
          $link = trim((string) ($item['link'] ?? '/notifications.php'));
        ?>
        <a href="<?= htmlspecialchars($link) ?>" class="block px-5 py-4 hover:bg-zinc-50 transition">
          <div class="flex items-start gap-4">
            <div class="mt-1 w-10 h-10 rounded-full bg-emerald-50 border border-emerald-200 flex items-center justify-center flex-shrink-0">
              <svg class="w-5 h-5 text-emerald-600" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" d="M13 16h-1v-4h-1m1-4h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H8l-4 4v10a2 2 0 002 2z" />
              </svg>
            </div>
            <div class="min-w-0 flex-1">
              <div class="flex items-start justify-between gap-4">
                <h4 class="text-sm font-bold text-zinc-900"><?= htmlspecialchars($title) ?></h4>
                <span class="text-[11px] font-medium text-zinc-400 whitespace-nowrap"><?= htmlspecialchars($createdAtLabel) ?></span>
              </div>
              <p class="text-sm text-zinc-600 mt-1 leading-relaxed"><?= htmlspecialchars($description) ?></p>
            </div>
          </div>
        </a>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</section>

<?php render_footer(); ?>
