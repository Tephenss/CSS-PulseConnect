<?php
declare(strict_types=1);

session_start();

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/supabase.php';
require_once __DIR__ . '/includes/layout.php';

$user = require_role(['admin']);
$role = (string) ($user['role'] ?? 'admin');

// Load events to show on homepage (students see published only).
$select = 'select=id,title,description,location,start_at,end_at,status';
$base = rtrim(SUPABASE_URL, '/') . '/rest/v1/events?' . $select . '&order=start_at.asc';
$url = $role === 'student' ? $base . '&status=eq.published' : $base;

$headers = [
    'Accept: application/json',
    'apikey: ' . SUPABASE_KEY,
    'Authorization: Bearer ' . SUPABASE_KEY,
];

$events = [];
$res = supabase_request('GET', $url, $headers);
if ($res['ok']) {
    $decoded = json_decode((string) $res['body'], true);
    $events = is_array($decoded) ? $decoded : [];
}

$firstName = explode(' ', trim((string) ($user['full_name'] ?? 'User')))[0];

render_header('Dashboard', $user);
?>

<style>
/* MacBook Animation Styles */
.macbook {
  width: 150px;
  height: 96px;
  position: absolute;
  left: 50%;
  top: 50%;
  margin: -60px 0 0 -75px;
  perspective: 500px;
  transform: scale(1.1);
}
.mac-shadow {
  position: absolute;
  width: 60px;
  height: 0px;
  left: 40px;
  top: 160px;
  transform: rotateX(80deg) rotateY(0deg) rotateZ(0deg);
  box-shadow: 0 0 60px 40px rgba(0,0,0,0.3);
  animation: mac-shadow infinite 7s ease;
}
.mac-inner {
  z-index: 20;
  position: absolute;
  width: 150px;
  height: 96px;
  left: 0;
  top: 0;
  transform-style: preserve-3d;
  transform: rotateX(-20deg) rotateY(0deg) rotateZ(0deg);
  animation: mac-rotate infinite 7s ease;
}
<style>
/* MacBook Animation Styles */
.macbook {
  width: 150px;
  height: 96px;
  position: absolute;
  left: 50%;
  top: 50%;
  margin: -60px 0 0 -75px;
  perspective: 500px;
  transform: scale(0.95);
}
.mac-shadow {
  position: absolute;
  width: 60px;
  height: 0px;
  left: 45px;
  top: 140px;
  transform: rotateX(80deg) rotateY(0deg) rotateZ(0deg);
  box-shadow: 0 0 50px 30px rgba(0,0,0,0.25);
  animation: mac-shadow infinite 7s ease;
}
.mac-inner {
  z-index: 20;
  position: absolute;
  width: 150px;
  height: 96px;
  left: 0;
  top: 0;
  transform-style: preserve-3d;
  transform: rotateX(-20deg) rotateY(0deg) rotateZ(0deg);
  animation: mac-rotate infinite 7s ease;
}
.mac-screen {
  width: 150px;
  height: 96px;
  position: absolute;
  left: 0;
  bottom: 0;
  border-radius: 7px;
  background: #cbd5e1;
  transform-style: preserve-3d;
  transform-origin: 50% 93px;
  transform: rotateX(0deg) rotateY(0deg) rotateZ(0deg);
  animation: mac-lid-screen infinite 7s ease;
  background-image: linear-gradient(45deg, rgba(0,0,0,0.2) 0%, rgba(0,0,0,0) 100%);
  background-position: left bottom;
  box-shadow: inset 0 3px 5px rgba(255,255,255,0.4);
}
.mac-logo {
  position: absolute;
  width: 100%;
  text-align: center;
  top: 50%;
  left: 0;
  transform: translateY(-50%) rotateY(180deg) translateZ(1px);
  color: #fff;
  font-family: 'Inter', system-ui, sans-serif;
  font-weight: 900;
  font-size: 20px;
  letter-spacing: 2px;
  text-shadow: 0 0 10px rgba(251,146,60,0.8), 0 0 20px rgba(249,115,22,0.6), 0 0 30px rgba(234,88,12,0.4);
  animation: logo-glow 3.5s infinite ease-in-out;
}
@keyframes logo-glow {
  0%, 100% { text-shadow: 0 0 10px rgba(251,146,60,0.8), 0 0 20px rgba(249,115,22,0.6); color: #fff; }
  50% { text-shadow: 0 0 15px rgba(216,180,254,1), 0 0 30px rgba(251,146,60,0.8); color: #ffedd5; }
}

.mac-screen .face-one {
  width: 150px;
  height: 96px;
  position: absolute;
  left: 0;
  bottom: 0;
  border-radius: 7px;
  background: #d3d3d3;
  transform: translateZ(2px);
  background-image: linear-gradient(45deg,rgba(0,0,0,0.24) 0%,rgba(0,0,0,0) 100%);
}
.mac-screen .face-one .camera {
  width: 3px;
  height: 3px;
  border-radius: 100%;
  background: #000;
  position: absolute;
  left: 50%;
  top: 4px;
  margin-left: -1.5px;
}
.mac-screen .face-one .display {
  width: 130px;
  height: 74px;
  margin: 10px;
  background-color: #ffffff;
  background-size: contain;
  background-position: center;
  background-repeat: no-repeat;
  border-radius: 1px;
  position: relative;
  box-shadow: inset 0 0 3px rgba(0,0,0,1);
  animation: mac-screen-content 21s infinite;
}
@keyframes mac-screen-content {
  0%, 13.32% { background-image: url('assets/BSIT.png'); }
  13.33%, 46.65% { background-image: url('assets/CS.png'); }
  46.66%, 79.99% { background-image: url('assets/CCS.png'); }
  80%, 100% { background-image: url('assets/BSIT.png'); }
}

.mac-screen .face-one .display .shade {
  position: absolute;
  left: 0;
  top: 0;
  width: 130px;
  height: 74px;
  background: linear-gradient(-135deg, rgba(255,255,255,0) 0%,rgba(255,255,255,0.1) 47%,rgba(255,255,255,0) 48%);
  animation: mac-screen-shade infinite 7s ease;
  background-size: 300px 200px;
  background-position: 0px 0px;
}
.mac-screen .face-one span {
  position: absolute;
  top: 85px;
  left: 57px;
  font-size: 6px;
  color: #666
}
.macbody {
  width: 150px;
  height: 96px;
  position: absolute;
  left: 0;
  bottom: 0;
  border-radius: 7px;
  background: #cbcbcb;
  transform-style: preserve-3d;
  transform-origin: 50% bottom;
  transform: rotateX(-90deg);
  animation: mac-lid-macbody infinite 7s ease;
  background-image: linear-gradient(45deg, rgba(0,0,0,0.24) 0%,rgba(0,0,0,0) 100%);
}
.macbody .face-one {
  width: 150px;
  height: 96px;
  position: absolute;
  left: 0;
  bottom: 0;
  border-radius: 7px;
  transform-style: preserve-3d;
  background: #dfdfdf;
  animation: mac-lid-keyboard-area infinite 7s ease;
  transform: translateZ(-2px);
  background-image: linear-gradient(30deg, rgba(0,0,0,0.24) 0%,rgba(0,0,0,0) 100%);
}
.macbody .touchpad {
  width: 40px;
  height: 31px;
  position: absolute;
  left: 50%;
  top: 50%;
  border-radius: 4px;
  margin: -44px 0 0 -18px;
  background: #cdcdcd;
  background-image: linear-gradient(30deg, rgba(0,0,0,0.24) 0%,rgba(0,0,0,0) 100%);
  box-shadow: inset 0 0 3px #888;
}
.macbody .keyboard {
  width: 130px;
  height: 45px;
  position: absolute;
  left: 7px;
  top: 41px;
  border-radius: 4px;
  transform-style: preserve-3d;
  background: #cdcdcd;
  background-image: linear-gradient(30deg, rgba(0,0,0,0.24) 0%,rgba(0,0,0,0) 100%);
  box-shadow: inset 0 0 3px #777;
  padding: 0 0 0 2px;
}
.mac-keyboard .key {
  width: 6px;
  height: 6px;
  background: #444;
  float: left;
  margin: 1px;
  transform: translateZ(-2px);
  border-radius: 2px;
  box-shadow: 0 -2px 0 #222;
  animation: mac-keys infinite 7s ease;
}
.mac-keyboard .key.space { width: 45px; }
.mac-keyboard .key.f { height: 3px; }
.macbody .pad {
  width: 5px;
  height: 5px;
  background: #333;
  border-radius: 100%;
  position: absolute;
}
.pad.one { left: 20px; top: 20px; }
.pad.two { right: 20px; top: 20px; }
.pad.three { right: 20px; bottom: 20px; }
.pad.four { left: 20px; bottom: 20px; }

@keyframes mac-rotate {
  0% { transform: rotateX(-20deg) rotateY(0deg) rotateZ(0deg); }
  5% { transform: rotateX(-20deg) rotateY(-20deg) rotateZ(0deg); }
  20% { transform: rotateX(20deg) rotateY(180deg) rotateZ(0deg); }
  25% { transform: rotateX(-50deg) rotateY(150deg) rotateZ(0deg); }
  60% { transform: rotateX(-20deg) rotateY(130deg) rotateZ(0deg); }
  65% { transform: rotateX(-20deg) rotateY(120deg) rotateZ(0deg); }
  80% { transform: rotateX(-20deg) rotateY(375deg) rotateZ(0deg); }
  85% { transform: rotateX(-20deg) rotateY(357deg) rotateZ(0deg); }
  87% { transform: rotateX(-20deg) rotateY(360deg) rotateZ(0deg); }
  100% { transform: rotateX(-20deg) rotateY(360deg) rotateZ(0deg); }
}
@keyframes mac-lid-screen {
  0% { transform: rotateX(0deg); background-position: left bottom; }
  5% { transform: rotateX(50deg); background-position: left bottom; }
  20% { transform: rotateX(-90deg); background-position: -150px top; }
  25% { transform: rotateX(15deg); background-position: left bottom; }
  30% { transform: rotateX(-5deg); background-position: right top; }
  38% { transform: rotateX(5deg); background-position: right top; }
  48% { transform: rotateX(0deg); background-position: right top; }
  90% { transform: rotateX(0deg); background-position: right top; }
  100% { transform: rotateX(0deg); background-position: right center; }
}
@keyframes mac-lid-macbody {
  0%, 50%, 100% { transform: rotateX(-90deg); }
}
@keyframes mac-lid-keyboard-area {
  0%, 100% { background-color: #dfdfdf; }
  50% { background-color: #bbb; }
}
@keyframes mac-screen-shade {
  0% { background-position: -20px 0px; }
  5% { background-position: -40px 0px; }
  20% { background-position: 200px 0; }
  50% { background-position: -200px 0; }
  80% { background-position: 0px 0px; }
  85% { background-position: -30px 0; }
  90% { background-position: -20px 0; }
  100% { background-position: -20px 0px; }
}
@keyframes mac-keys {
  0%, 80%, 85%, 87%, 100% { box-shadow: 0 -2px 0 #222; }
  5% { box-shadow: 1px -1px 0 #222; }
  20%, 25%, 60% { box-shadow: -1px 1px 0 #222; }
}
@keyframes mac-shadow {
  0%, 5% { transform: rotateX(80deg) rotateY(0deg) rotateZ(0deg); box-shadow: 0 0 60px 40px rgba(0,0,0,0.3); }
  20% { transform: rotateX(30deg) rotateY(-20deg) rotateZ(-20deg); box-shadow: 0 0 40px 20px rgba(0,0,0,0.3); }
  25% { transform: rotateX(80deg) rotateY(-20deg) rotateZ(50deg); box-shadow: 0 0 35px 15px rgba(0,0,0,0.1); }
  60% { transform: rotateX(80deg) rotateY(0deg) rotateZ(-50deg) translateX(30px); box-shadow: 0 0 60px 40px rgba(0,0,0,0.3); }
  100% { transform: rotateX(80deg) rotateY(0deg) rotateZ(0deg); box-shadow: 0 0 60px 40px rgba(0,0,0,0.3); }
}

/* Greeting Holographic Scan Animation */
.greeting-loader {
  display: inline-block;
  position: relative;
  font-style: italic;
  font-weight: 800;
  color: #ea580c; /* orange-600 */
}
.greeting-loader span {
  display: inline-block;
  animation: greet-cut 2.5s infinite;
  transition: 1s cubic-bezier(0.175, 0.885, 0.32, 1.275);
}
.greeting-loader:hover span {
  color: #dc2626;
}
.greeting-loader::after {
  position: absolute;
  content: "";
  width: 104%;
  height: 6px;
  border-radius: 4px;
  background-color: rgba(249, 115, 22, 0.5); /* fuchsia / violet blur */
  top: 0;
  filter: blur(6px);
  animation: greet-scan 2.5s infinite ease-in-out;
  left: -2%;
  z-index: 0;
}
.greeting-loader::before {
  position: absolute;
  content: "";
  width: 104%;
  height: 4px;
  border-radius: 4px;
  background-color: #f97316; /* sharp violet line */
  top: 0;
  animation: greet-scan 2.5s infinite ease-in-out;
  left: -2%;
  z-index: 1;
  opacity: 0.9;
}
@keyframes greet-scan {
  0% { top: -10px; }
  25% { top: 100%; }
  50% { top: -10px; }
  75% { top: 100%; }
  100% { top: -10px; }
}
@keyframes greet-cut {
  0% { clip-path: inset(-20px -20px -20px -20px); }
  25% { clip-path: inset(100% -20px -20px -20px); }
  50% { clip-path: inset(-20px -20px 100% -20px); }
  75% { clip-path: inset(-20px -20px -20px -20px); }
  100% { clip-path: inset(-20px -20px -20px -20px); }
}
</style>

<!-- Welcome Banner -->
<div class="relative overflow-hidden rounded-3xl border border-zinc-200 bg-gradient-to-br from-white via-orange-50/40 to-red-50/30 p-6 md:p-8 mb-8 shadow-sm">
  <div class="absolute -top-24 -right-24 w-64 h-64 bg-orange-400/10 blur-3xl rounded-full pointer-events-none overflow-hidden"></div>
  <div class="absolute -bottom-24 -left-24 w-64 h-64 bg-red-400/10 blur-3xl rounded-full pointer-events-none overflow-hidden"></div>

  <div class="relative z-10 flex flex-col md:flex-row md:items-center justify-between gap-6 min-h-[140px]">
    <div class="flex-1">
      <h2 class="text-2xl md:text-3xl font-bold tracking-tight text-zinc-900 mb-2">
        Welcome back, 
        <div class="greeting-loader ml-1">
          <span><?= htmlspecialchars($firstName) ?></span>
        </div>
      </h2>
      <p class="text-zinc-600 text-sm md:text-base max-w-xl leading-relaxed">
        <?php if ($role === 'student'): ?>
          Ready to explore? Register for upcoming events, access your e-tickets securely, and track your attendance progress across the semester.
        <?php elseif ($role === 'teacher'): ?>
          Manage your classes effectively. Create engaging events, monitor registrations, and seamlessly scan participant QR tickets for accurate attendance.
        <?php else: ?>
          System dashboard active. Maintain complete oversight of all events, manage platform users, analyze engagement metrics, and issue certificates.
        <?php endif; ?>
      </p>
    </div>

    <!-- Apple MacBook Animation Container -->
    <div class="relative w-full md:w-64 h-32 md:h-40 flex-shrink-0 hidden sm:block">
      <div class="macbook">
        <div class="mac-inner">
          <div class="mac-screen">
            <div class="mac-logo">CCS</div>
            <div class="face-one">
              <div class="camera"></div>
              <div class="display">
                <div class="shade"></div>
              </div>
              <span>MacBook Air</span>
            </div>
          </div>
          <div class="macbody">
            <div class="face-one">
              <div class="touchpad"></div>
              <div class="keyboard mac-keyboard">
                <?php for($k=0; $k<59; $k++): ?><div class="key <?= $k==5 ? 'space' : '' ?>"></div><?php endfor; ?>
                <?php for($k=0; $k<16; $k++): ?><div class="key f"></div><?php endfor; ?>
              </div>
            </div>
            <div class="pad one"></div><div class="pad two"></div><div class="pad three"></div><div class="pad four"></div>
          </div>
        </div>
        <div class="mac-shadow"></div>
      </div>
    </div>
  </div>
</div>

<?php
  // Calculate stats
  $upcoming = 0;
  $published = 0;
  $pending = 0;
  $now = new DateTime();

  foreach ($events as $e) {
      if (!empty($e['start_at'])) {
          try { if (new DateTime($e['start_at']) > $now) $upcoming++; } catch (Throwable $ex) {}
      }
      $s = (string)($e['status'] ?? '');
      if ($s === 'published') $published++;
      if ($s === 'pending') $pending++;
  }
?>

<!-- System Stats -->
<div class="mb-4 flex flex-col sm:flex-row sm:items-center justify-between gap-4">
  <h3 class="text-sm font-semibold text-zinc-800 tracking-wide uppercase">System Overview</h3>
  
  <?php if ($role === 'admin' || $role === 'teacher'): ?>
  <div class="flex flex-wrap items-center gap-2">
    <a href="/manage_events.php" class="flex items-center gap-1.5 rounded-xl border border-orange-200 bg-orange-600 text-white px-3.5 py-2 text-xs font-bold hover:bg-orange-700 shadow-sm transition-colors group">
      <svg class="w-4 h-4 group-hover:rotate-90 transition-transform" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/></svg>
      New Event
    </a>
    <a href="/scan.php" class="flex items-center gap-1.5 rounded-xl border border-emerald-200 bg-white text-emerald-800 px-3.5 py-2 text-xs font-bold hover:bg-emerald-50 shadow-sm transition-colors">
      <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 4.875c0-.621.504-1.125 1.125-1.125h4.5c.621 0 1.125.504 1.125 1.125v4.5c0 .621-.504 1.125-1.125 1.125h-4.5A1.125 1.125 0 013.75 9.375v-4.5zM3.75 14.625c0-.621.504-1.125 1.125-1.125h4.5c.621 0 1.125.504 1.125 1.125v4.5c0 .621-.504 1.125-1.125 1.125h-4.5a1.125 1.125 0 01-1.125-1.125v-4.5zM13.5 4.875c0-.621.504-1.125 1.125-1.125h4.5c.621 0 1.125.504 1.125 1.125v4.5c0 .621-.504 1.125-1.125 1.125h-4.5A1.125 1.125 0 0113.5 9.375v-4.5z"/><path stroke-linecap="round" stroke-linejoin="round" d="M6.75 6.75h.75v.75h-.75v-.75zM6.75 16.5h.75v.75h-.75v-.75zM16.5 6.75h.75v.75h-.75v-.75zM13.5 13.5h.75v.75h-.75v-.75zM13.5 19.5h.75v.75h-.75v-.75zM19.5 13.5h.75v.75h-.75v-.75zM19.5 19.5h.75v.75h-.75v-.75zM16.5 16.5h.75v.75h-.75v-.75z"/></svg>
      Scan QR
    </a>
  </div>
  <?php endif; ?>
</div>
<div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-10">
  <!-- Total Events -->
  <div class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm group hover:border-sky-300 transition-colors relative overflow-hidden">
    <div class="absolute top-0 right-0 w-24 h-24 bg-sky-400/10 blur-2xl rounded-bl-full pointer-events-none"></div>
    <div class="relative z-10 flex items-center gap-4">
      <div class="w-12 h-12 rounded-xl bg-sky-100 border border-sky-200 flex items-center justify-center flex-shrink-0">
        <svg class="w-6 h-6 text-sky-700" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 11.25v7.5"/></svg>
      </div>
      <div>
        <div class="text-3xl font-bold text-zinc-900 tracking-tight"><?= count($events) ?></div>
        <div class="text-[13px] font-medium text-zinc-600">Total Events</div>
      </div>
    </div>
  </div>

  <!-- Upcoming -->
  <div class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm group hover:border-emerald-300 transition-colors relative overflow-hidden">
    <div class="absolute top-0 right-0 w-24 h-24 bg-emerald-400/10 blur-2xl rounded-bl-full pointer-events-none"></div>
    <div class="relative z-10 flex items-center gap-4">
      <div class="w-12 h-12 rounded-xl bg-emerald-100 border border-emerald-200 flex items-center justify-center flex-shrink-0">
        <svg class="w-6 h-6 text-emerald-700" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
      </div>
      <div>
        <div class="text-3xl font-bold text-zinc-900 tracking-tight"><?= $upcoming ?></div>
        <div class="text-[13px] font-medium text-zinc-600">Upcoming</div>
      </div>
    </div>
  </div>

  <!-- Published -->
  <div class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm group hover:border-orange-300 transition-colors relative overflow-hidden">
    <div class="absolute top-0 right-0 w-24 h-24 bg-orange-400/10 blur-2xl rounded-bl-full pointer-events-none"></div>
    <div class="relative z-10 flex items-center gap-4">
      <div class="w-12 h-12 rounded-xl bg-orange-100 border border-orange-200 flex items-center justify-center flex-shrink-0">
        <svg class="w-6 h-6 text-orange-700" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
      </div>
      <div>
        <div class="text-3xl font-bold text-zinc-900 tracking-tight"><?= $published ?></div>
        <div class="text-[13px] font-medium text-zinc-600">Published</div>
      </div>
    </div>
  </div>

  <!-- Pending -->
  <div class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm group hover:border-amber-300 transition-colors relative overflow-hidden">
    <div class="absolute top-0 right-0 w-24 h-24 bg-amber-400/10 blur-2xl rounded-bl-full pointer-events-none"></div>
    <div class="relative z-10 flex items-center gap-4">
      <div class="w-12 h-12 rounded-xl bg-amber-100 border border-amber-200 flex items-center justify-center flex-shrink-0">
        <svg class="w-6 h-6 text-amber-800" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z"/></svg>
      </div>
      <div>
        <div class="text-3xl font-bold text-zinc-900 tracking-tight"><?= $pending ?></div>
        <div class="text-[13px] font-medium text-zinc-600">Pending Request</div>
      </div>
    </div>
  </div>
</div>


<?php
  // Build upcoming events list (future, published, max 3)
  $upcomingList = [];
  foreach ($events as $e) {
    $s = (string)($e['status'] ?? '');
    $sa = !empty($e['start_at']) ? new DateTime($e['start_at']) : null;
    if ($s === 'published' && $sa && $sa > $now) {
      $upcomingList[] = $e;
    }
    if (count($upcomingList) >= 3) break;
  }

  // Build calendar event map keyed by "Y-m-d"
  $calEventMap = [];
  foreach ($events as $e) {
    if (!empty($e['start_at']) && ($e['status'] ?? '') === 'published') {
      try {
        $d = new DateTimeImmutable($e['start_at']);
        $eEnd = !empty($e['end_at']) ? new DateTimeImmutable($e['end_at']) : null;
        $key = $d->format('Y-m-d');
        if (!isset($calEventMap[$key])) $calEventMap[$key] = [];
        
        // Determine time-based status for the dot color
        $dotClass = 'bg-orange-500'; // upcoming
        if ($eEnd && $eEnd < $now) {
            $dotClass = 'bg-zinc-400'; // past
        } elseif ($d <= $now && (!$eEnd || $eEnd >= $now)) {
            $dotClass = 'bg-emerald-500'; // ongoing
        }

        $calEventMap[$key][] = [
          'title' => (string)($e['title'] ?? 'Event'),
          'type'  => (string)($e['event_type'] ?? 'Event'),
          'status'=> (string)($e['status'] ?? ''),
          'dot'   => $dotClass
        ];
      } catch(Throwable $ex) {}
    }
  }

  $eventTypeIcons = [
    'seminar'     => 'M18 18.72a9.094 9.094 0 003.741-.479 3 3 0 00-4.682-2.72m.94 3.198l.001.031c0 .225-.012.447-.037.666A11.944 11.944 0 0112 21c-2.17 0-4.207-.576-5.963-1.584A6.062 6.062 0 016 18.719m12 0a5.971 5.971 0 00-.941-3.197m0 0A5.995 5.995 0 0012 12.75a5.995 5.995 0 00-5.058 2.772m0 0a3 3 0 00-4.681 2.72 8.986 8.986 0 003.74.477m.94-3.197a5.971 5.971 0 00-.94 3.197M15 6.75a3 3 0 11-6 0 3 3 0 016 0zm6 3a2.25 2.25 0 11-4.5 0 2.25 2.25 0 014.5 0zm-13.5 0a2.25 2.25 0 11-4.5 0 2.25 2.25 0 014.5 0z',
    'festival'    => 'M9.813 15.904L9 18.75l-.813-2.846a4.5 4.5 0 00-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 003.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 003.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 00-3.09 3.09zM18.259 8.715L18 9.75l-.259-1.035a3.375 3.375 0 00-2.455-2.456L14.25 6l1.036-.259a3.375 3.375 0 002.455-2.456L18 2.25l.259 1.035a3.375 3.375 0 002.456 2.456L21.75 6l-1.035.259a3.375 3.375 0 00-2.456 2.456z',
    'default'     => 'M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 11.25v7.5',
  ];
?>

<!-- ═══ BOTTOM SECTION: Two Column Layout ═══ -->
<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

  <!-- LEFT: Upcoming Events List -->
  <div class="lg:col-span-2 space-y-4">
    <div class="flex items-center justify-between mb-1">
      <div class="flex items-center gap-2">
        <div class="w-8 h-8 rounded-lg bg-orange-100 border border-orange-200 flex items-center justify-center">
          <svg class="w-4 h-4 text-orange-700" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 11.25v7.5"/></svg>
        </div>
        <h3 class="text-base font-semibold text-zinc-900">Upcoming Events</h3>
      </div>
      <a href="/events.php" class="group flex items-center gap-1.5 text-sm font-medium text-orange-700 hover:text-orange-900 transition-colors">
        View all
        <svg class="w-4 h-4 group-hover:translate-x-1 transition-transform" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12h15m0 0l-6.75-6.75M19.5 12l-6.75 6.75"/></svg>
      </a>
    </div>

    <?php if (count($upcomingList) === 0): ?>
      <div class="rounded-2xl border border-dashed border-zinc-300 bg-zinc-50/80 p-10 text-center">
        <div class="w-14 h-14 rounded-full bg-white border border-zinc-200 flex items-center justify-center mx-auto mb-3 shadow-sm">
          <svg class="w-7 h-7 text-zinc-400" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 11.25v7.5"/></svg>
        </div>
        <h3 class="text-base font-medium text-zinc-800 mb-1">No Upcoming Events</h3>
        <p class="text-sm text-zinc-500">No published events scheduled in the future.</p>
      </div>
    <?php endif; ?>

    <?php foreach ($upcomingList as $e):
      $eType = strtolower((string)($e['event_type'] ?? 'default'));
      $iconPath = $eventTypeIcons[$eType] ?? $eventTypeIcons['default'];
      $iconBg = match($eType) {
        'seminar' => ['bg' => 'bg-sky-100', 'border' => 'border-sky-200', 'icon' => 'text-sky-700'],
        'festival'=> ['bg' => 'bg-purple-100', 'border' => 'border-purple-200', 'icon' => 'text-purple-700'],
        default   => ['bg' => 'bg-orange-100', 'border' => 'border-orange-200', 'icon' => 'text-orange-700'],
      };
      $eStart = !empty($e['start_at']) ? new DateTimeImmutable($e['start_at']) : null;
      $dayName = $eStart ? $eStart->format('D') : '';
      $dateFull = $eStart ? $eStart->format('D, M d, Y') . ' at ' . $eStart->format('g:i A') : 'TBA';
      $postedAt = !empty($e['created_at']) ? (new DateTimeImmutable($e['created_at']))->format('F d, Y') : '';
      $eTypePill = ucfirst($eType);
      $pillColor = match($eType) {
        'seminar'  => 'bg-sky-100 text-sky-800 border-sky-200',
        'festival' => 'bg-purple-100 text-purple-800 border-purple-200',
        default    => 'bg-orange-100 text-orange-800 border-orange-200',
      };
    ?>
      <a href="/event_view.php?id=<?= htmlspecialchars((string)($e['id'] ?? '')) ?>"
         class="group flex items-center gap-4 bg-white rounded-2xl border border-zinc-200 hover:border-orange-300 p-4 shadow-sm hover:shadow-md transition-all duration-200">
        <!-- Icon -->
        <div class="w-12 h-12 rounded-xl <?= $iconBg['bg'] ?> border <?= $iconBg['border'] ?> flex items-center justify-center flex-shrink-0">
          <svg class="w-6 h-6 <?= $iconBg['icon'] ?>" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" d="<?= $iconPath ?>"/>
          </svg>
        </div>
        <!-- Content -->
        <div class="flex-1 min-w-0">
          <div class="flex items-start justify-between gap-2 mb-0.5">
            <h4 class="text-sm font-bold text-zinc-900 group-hover:text-orange-800 transition-colors truncate"><?= htmlspecialchars((string)($e['title'] ?? 'Event')) ?></h4>
            <span class="text-[10px] font-bold uppercase tracking-wider rounded-full border px-2 py-0.5 flex-shrink-0 <?= $pillColor ?>"><?= htmlspecialchars($eTypePill) ?></span>
          </div>
          <p class="text-xs text-zinc-500 mb-1">
            <?php if (!empty($e['target_audience'])): ?><span class="font-medium text-zinc-600">For: <?= htmlspecialchars((string)$e['target_audience']) ?></span> · <?php endif; ?>
            <?= htmlspecialchars($dateFull) ?>
          </p>
          <?php if ($postedAt): ?>
            <p class="text-[11px] text-zinc-400">Posted: <?= htmlspecialchars($postedAt) ?></p>
          <?php endif; ?>
        </div>
        <svg class="w-4 h-4 text-zinc-400 group-hover:text-orange-500 group-hover:translate-x-0.5 transition-all flex-shrink-0" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5"/></svg>
      </a>
    <?php endforeach; ?>
  </div>

  <!-- RIGHT: Mini Calendar -->
  <div class="lg:col-span-1">
    <?php
      $calNow  = new DateTime();
      $calYear  = (int)$calNow->format('Y');
      $calMonth = (int)$calNow->format('n');
      $calToday = (int)$calNow->format('j');
      $firstDay = (int)(new DateTime("$calYear-$calMonth-01"))->format('w'); // 0=Sun
      $daysInMonth = (int)(new DateTime("$calYear-$calMonth-01"))->format('t');
      $monthName = $calNow->format('F');
    ?>
    <div class="bg-white rounded-2xl border border-zinc-200 shadow-sm p-5 sticky top-6">
      <!-- Calendar Header -->
      <div class="flex items-center justify-between mb-4">
        <h3 class="text-sm font-bold text-zinc-900"><?= $monthName ?></h3>
        <span class="text-xs font-bold text-zinc-400 bg-zinc-100 px-2 py-0.5 rounded-md"><?= $calYear ?></span>
      </div>
      <!-- Day Labels -->
      <div class="grid grid-cols-7 mb-2">
        <?php foreach(['Su','Mo','Tu','We','Th','Fr','Sa'] as $d): ?>
          <div class="text-center text-[10px] font-bold text-zinc-400 uppercase"><?= $d ?></div>
        <?php endforeach; ?>
      </div>
      <!-- Calendar Days -->
      <div class="grid grid-cols-7 gap-y-1 relative" id="calGrid">
        <!-- Empty cells for offset -->
        <?php for($i = 0; $i < $firstDay; $i++): ?>
          <div></div>
        <?php endfor; ?>

        <?php for($day = 1; $day <= $daysInMonth; $day++):
          $dateKey = sprintf('%04d-%02d-%02d', $calYear, $calMonth, $day);
          $hasEvent = isset($calEventMap[$dateKey]);
          $isToday  = ($day === $calToday);
          $dayEvents = $hasEvent ? $calEventMap[$dateKey] : [];
          $tooltipId = 'cal-tip-' . $day;
        ?>
          <div class="relative flex flex-col items-center group/day">
            <button
              class="w-7 h-7 rounded-full text-xs font-semibold flex items-center justify-center transition-all
                <?= $isToday ? 'bg-orange-600 text-white shadow-md shadow-orange-200 font-bold' : 'hover:bg-zinc-100 text-zinc-700' ?>
                <?= $hasEvent && !$isToday ? 'font-bold text-orange-700' : '' ?>"
              type="button"
              <?= $hasEvent ? 'data-has-event="1"' : '' ?>
            >
              <?= $day ?>
            </button>
            <!-- Event Dot -->
            <?php if ($hasEvent): 
                  // If multiple events, prioritize dot color (ongoing > upcoming > past)
                  $finalDot = 'bg-orange-500';
                  $dots = array_column($dayEvents, 'dot');
                  if (in_array('bg-emerald-500', $dots)) $finalDot = 'bg-emerald-500';
                  elseif (in_array('bg-orange-500', $dots)) $finalDot = 'bg-orange-500';
                  else $finalDot = 'bg-zinc-400';
            ?>
              <div class="w-1.5 h-1.5 rounded-full <?= $isToday ? 'bg-white/70' : $finalDot ?> mt-0.5"></div>
            <?php else: ?>
              <div class="w-1.5 h-1.5"></div>
            <?php endif; ?>

            <!-- Hover Tooltip -->
            <?php if ($hasEvent): ?>
              <div class="absolute bottom-full left-1/2 -translate-x-1/2 mb-2 z-50 hidden group-hover/day:block pointer-events-none" style="min-width:160px; max-width:220px;">
                <div class="bg-zinc-900 text-white rounded-xl shadow-xl p-3 text-left">
                  <p class="text-[10px] font-bold text-zinc-400 uppercase tracking-wider mb-1.5"><?= date('M d', mktime(0,0,0,$calMonth,$day,$calYear)) ?></p>
                  <?php foreach($dayEvents as $ev): ?>
                    <div class="flex items-start gap-1.5 mb-1 last:mb-0">
                      <div class="w-1.5 h-1.5 rounded-full <?= $ev['dot'] ?> mt-1 flex-shrink-0"></div>
                      <div>
                        <p class="text-xs font-semibold text-white leading-tight"><?= htmlspecialchars($ev['title']) ?></p>
                        <?php if ($ev['type']): ?>
                          <p class="text-[10px] text-zinc-400"><?= htmlspecialchars(ucfirst($ev['type'])) ?></p>
                        <?php endif; ?>
                      </div>
                    </div>
                  <?php endforeach; ?>
                </div>
                <div class="w-2.5 h-2.5 bg-zinc-900 rotate-45 mx-auto -mt-1.5 rounded-sm"></div>
              </div>
            <?php endif; ?>
          </div>
        <?php endfor; ?>
      </div>

      <!-- Legend -->
      <div class="mt-4 pt-4 border-t border-zinc-100 flex items-center gap-x-4 gap-y-2 flex-wrap">
        <div class="flex items-center gap-1.5 text-[10px] sm:text-[11px] text-zinc-500">
          <div class="w-3 h-3 rounded-full bg-orange-600"></div>Today
        </div>
        <div class="flex items-center gap-1.5 text-[10px] sm:text-[11px] text-zinc-500">
          <div class="w-1.5 h-1.5 rounded-full bg-emerald-500"></div>Ongoing
        </div>
        <div class="flex items-center gap-1.5 text-[10px] sm:text-[11px] text-zinc-500">
          <div class="w-1.5 h-1.5 rounded-full bg-orange-500"></div>Upcoming
        </div>
        <div class="flex items-center gap-1.5 text-[10px] sm:text-[11px] text-zinc-500">
          <div class="w-1.5 h-1.5 rounded-full bg-zinc-400"></div>Past
        </div>
      </div>
    </div>
  </div>

</div>

<?php
render_footer();

