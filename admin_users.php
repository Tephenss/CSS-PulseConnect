<?php
declare(strict_types=1);

session_start();

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/supabase.php';
require_once __DIR__ . '/includes/layout.php';

$user = require_role(['admin']);

$headers = [
    'Accept: application/json',
    'apikey: ' . SUPABASE_KEY,
    'Authorization: Bearer ' . SUPABASE_KEY,
];

$url = rtrim(SUPABASE_URL, '/') . '/rest/v1/' . SUPABASE_TABLE_USERS
    . '?select=id,first_name,middle_name,last_name,suffix,email,role,created_at'
    . '&order=created_at.desc'
    . '&limit=500';

$res = supabase_request('GET', $url, $headers);
$rows = $res['ok'] ? json_decode((string) $res['body'], true) : [];
$users = is_array($rows) ? $rows : [];

render_header('Users & Roles', $user);
?>

<div class="mb-6">
  <p class="text-zinc-400 text-sm">Manage user accounts and assign roles (student / teacher / admin).</p>
</div>

<!-- Stats -->
<?php
  $studentCount = 0; $teacherCount = 0; $adminCount = 0;
  foreach ($users as $u) {
    $r = (string)($u['role'] ?? 'student');
    if ($r === 'student') $studentCount++;
    elseif ($r === 'teacher') $teacherCount++;
    elseif ($r === 'admin') $adminCount++;
  }
?>
<div class="grid grid-cols-3 gap-4 mb-6">
  <div class="rounded-xl glass-card p-4">
    <div class="flex items-center gap-3">
      <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-emerald-600/20 to-teal-600/20 border border-emerald-500/20 flex items-center justify-center">
        <svg class="w-5 h-5 text-emerald-400" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M4.26 10.147a60.436 60.436 0 00-.491 6.347A48.627 48.627 0 0112 20.904a48.627 48.627 0 018.232-4.41 60.46 60.46 0 00-.491-6.347m-15.482 0a50.57 50.57 0 00-2.658-.813A59.905 59.905 0 0112 3.493a59.902 59.902 0 0110.399 5.84c-.896.248-1.783.52-2.658.814m-15.482 0A50.697 50.697 0 0112 13.489a50.702 50.702 0 017.74-3.342"/></svg>
      </div>
      <div>
        <div class="text-2xl font-bold text-zinc-100"><?= $studentCount ?></div>
        <div class="text-xs text-zinc-500">Students</div>
      </div>
    </div>
  </div>
  <div class="rounded-xl glass-card p-4">
    <div class="flex items-center gap-3">
      <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-sky-600/20 to-cyan-600/20 border border-sky-500/20 flex items-center justify-center">
        <svg class="w-5 h-5 text-sky-400" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.501 20.118a7.5 7.5 0 0114.998 0A17.933 17.933 0 0112 21.75c-2.676 0-5.216-.584-7.499-1.632z"/></svg>
      </div>
      <div>
        <div class="text-2xl font-bold text-zinc-100"><?= $teacherCount ?></div>
        <div class="text-xs text-zinc-500">Teachers</div>
      </div>
    </div>
  </div>
  <div class="rounded-xl glass-card p-4">
    <div class="flex items-center gap-3">
      <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-violet-600/20 to-fuchsia-600/20 border border-violet-500/20 flex items-center justify-center">
        <svg class="w-5 h-5 text-violet-400" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75m-3-7.036A11.959 11.959 0 013.598 6 11.99 11.99 0 003 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285z"/></svg>
      </div>
      <div>
        <div class="text-2xl font-bold text-zinc-100"><?= $adminCount ?></div>
        <div class="text-xs text-zinc-500">Admins</div>
      </div>
    </div>
  </div>
</div>

<div class="rounded-2xl glass-card p-5 overflow-x-auto">
  <div class="flex items-center gap-2.5 mb-4">
    <div class="w-8 h-8 rounded-lg bg-gradient-to-br from-violet-600/20 to-fuchsia-600/20 border border-violet-500/20 flex items-center justify-center">
      <svg class="w-4 h-4 text-violet-400" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 002.625.372 9.337 9.337 0 004.121-.952 4.125 4.125 0 00-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 018.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0111.964-3.07M12 6.375a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zm8.25 2.25a2.625 2.625 0 11-5.25 0 2.625 2.625 0 015.25 0z"/></svg>
    </div>
    <span class="text-sm font-medium text-zinc-200">All Users</span>
    <span class="text-xs text-zinc-600 ml-auto"><?= count($users) ?> total</span>
  </div>

  <table class="min-w-full text-sm">
    <thead>
      <tr class="text-xs text-zinc-500 uppercase tracking-wider">
        <th class="text-left py-2.5 pr-3 font-medium">Name</th>
        <th class="text-left py-2.5 pr-3 font-medium">Email</th>
        <th class="text-left py-2.5 pr-3 font-medium">Role</th>
        <th class="text-left py-2.5 pr-3 font-medium">Action</th>
      </tr>
    </thead>
    <tbody class="text-zinc-200">
      <?php foreach ($users as $u): ?>
        <?php
          $nameParts = [];
          foreach (['first_name','middle_name','last_name'] as $k) {
            $v = trim((string) ($u[$k] ?? ''));
            if ($v !== '') $nameParts[] = $v;
          }
          $name = implode(' ', $nameParts);
          $suffix = trim((string) ($u['suffix'] ?? ''));
          if ($suffix !== '') $name .= ', ' . $suffix;
          $uid = (string) ($u['id'] ?? '');
          $role = (string) ($u['role'] ?? 'student');
          $roleColor = match($role) {
              'admin' => 'from-violet-500 to-fuchsia-500',
              'teacher' => 'from-sky-500 to-cyan-500',
              default => 'from-emerald-500 to-teal-500',
          };
          $initials = '';
          foreach ($nameParts as $p) { $initials .= mb_strtoupper(mb_substr($p, 0, 1)); if (mb_strlen($initials) >= 2) break; }
        ?>
        <tr class="border-t border-zinc-800/50 hover:bg-zinc-800/20 transition">
          <td class="py-3 pr-3">
            <div class="flex items-center gap-2.5">
              <div class="w-8 h-8 rounded-full bg-gradient-to-br <?= $roleColor ?> flex items-center justify-center text-white text-[10px] font-bold flex-shrink-0"><?= htmlspecialchars($initials) ?></div>
              <span class="font-medium"><?= htmlspecialchars($name) ?></span>
            </div>
          </td>
          <td class="py-3 pr-3 text-zinc-400 text-xs"><?= htmlspecialchars((string) ($u['email'] ?? '')) ?></td>
          <td class="py-3 pr-3">
            <select class="roleSel rounded-lg bg-zinc-950/60 border border-zinc-800/60 px-2.5 py-2 text-xs outline-none focus:ring-2 focus:ring-violet-500/30 transition" data-uid="<?= htmlspecialchars($uid) ?>">
              <option value="student" <?= $role === 'student' ? 'selected' : '' ?>>student</option>
              <option value="teacher" <?= $role === 'teacher' ? 'selected' : '' ?>>teacher</option>
              <option value="admin" <?= $role === 'admin' ? 'selected' : '' ?>>admin</option>
            </select>
          </td>
          <td class="py-3 pr-3">
            <button class="btnSave rounded-lg bg-gradient-to-r from-violet-600 to-fuchsia-600 text-white px-3 py-1.5 text-xs font-medium hover:from-violet-500 hover:to-fuchsia-500 transition-all shadow-sm" data-uid="<?= htmlspecialchars($uid) ?>">
              Save
            </button>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (count($users) === 0): ?>
        <tr><td class="py-6 text-zinc-500 text-center" colspan="4">No users.</td></tr>
      <?php endif; ?>
    </tbody>
  </table>
  <div id="msg" class="mt-3 text-sm text-zinc-300"></div>
</div>

<script>
  const msg = document.getElementById('msg');
  document.querySelectorAll('.btnSave').forEach(btn => {
    btn.addEventListener('click', async () => {
      const uid = btn.dataset.uid;
      const sel = document.querySelector('.roleSel[data-uid="' + uid + '"]');
      const role = sel.value;
      msg.textContent = 'Saving...';
      btn.disabled = true;
      try {
        const res = await fetch('/api/users_update_role.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ user_id: uid, role, csrf_token: window.CSRF_TOKEN })
        });
        const data = await res.json();
        if (!data.ok) throw new Error(data.error || 'Failed');
        msg.textContent = 'Saved.';
      } catch (e) {
        msg.textContent = e.message || 'Failed';
      } finally {
        btn.disabled = false;
      }
    });
  });
</script>

<?php render_footer(); ?>
