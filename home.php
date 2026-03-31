<?php
declare(strict_types=1);

session_start();

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/supabase.php';
require_once __DIR__ . '/includes/layout.php';

$user = require_login();
$role = (string) ($user['role'] ?? 'student');

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

<!-- Events Header -->
<div class="mb-5 flex items-center justify-between gap-3">
  <div class="flex items-center gap-2">
    <div class="w-8 h-8 rounded-lg bg-zinc-100 border border-zinc-200 flex items-center justify-center">
      <svg class="w-4 h-4 text-zinc-600" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6A2.25 2.25 0 016 3.75h2.25A2.25 2.25 0 0110.5 6v2.25a2.25 2.25 0 01-2.25 2.25H6a2.25 2.25 0 01-2.25-2.25V6zM3.75 15.75A2.25 2.25 0 016 13.5h2.25a2.25 2.25 0 012.25 2.25V18a2.25 2.25 0 01-2.25 2.25H6A2.25 2.25 0 013.75 18v-2.25zM13.5 6a2.25 2.25 0 012.25-2.25H18A2.25 2.25 0 0120.25 6v2.25A2.25 2.25 0 0118 10.5h-2.25a2.25 2.25 0 01-2.25-2.25V6zM13.5 15.75a2.25 2.25 0 012.25-2.25H18a2.25 2.25 0 012.25 2.25V18A2.25 2.25 0 0118 20.25h-2.25A2.25 2.25 0 0113.5 18v-2.25z"/></svg>
    </div>
    <h3 class="text-base font-semibold text-zinc-900">
      <?php if ($role === 'student'): ?>Available Events<?php else: ?>All Events Board<?php endif; ?>
    </h3>
  </div>
  <a href="/events.php" class="group flex items-center gap-1.5 text-sm font-medium text-orange-700 hover:text-orange-900 transition-colors">
    View all
    <svg class="w-4 h-4 group-hover:translate-x-1 transition-transform" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12h15m0 0l-6.75-6.75M19.5 12l-6.75 6.75"/></svg>
  </a>
</div>

<!-- Events Grid -->
<div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-5">
  <?php if (count($events) === 0): ?>
    <div class="md:col-span-2 lg:col-span-3 rounded-2xl border border-dashed border-zinc-300 bg-zinc-50/80 p-12 text-center">
      <div class="w-16 h-16 rounded-full bg-white border border-zinc-200 flex items-center justify-center mx-auto mb-4 shadow-sm">
        <svg class="w-8 h-8 text-zinc-400" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M15.182 15.182a4.5 4.5 0 01-6.364 0M21 12a9 9 0 11-18 0 9 9 0 0118 0zM9.75 9.75c0 .414-.168.75-.375.75S9 10.164 9 9.75 9.168 9 9.375 9s.375.336.375.75zm3.625 0c0 .414-.168.75-.375.75s-.375-.336-.375-.75.168-.75.375-.75.375.336.375.75z"/></svg>
      </div>
      <h3 class="text-lg font-medium text-zinc-800 mb-1">No events found</h3>
      <p class="text-sm text-zinc-600 max-w-md mx-auto">There are currently no active events in the system. Check back later or create a new event to get started.</p>
    </div>
  <?php endif; ?>

  <?php foreach ($events as $i => $e): ?>
    <?php if ($i >= 6) break; // Show max 6 on dashboard ?>
    <?php
      $status = (string)($e['status'] ?? '');
      $statusConfig = match($status) {
          'published' => ['bg' => 'bg-emerald-100', 'text' => 'text-emerald-900', 'border' => 'border-emerald-200', 'accent' => 'border-b-emerald-500'],
          'pending' => ['bg' => 'bg-amber-100', 'text' => 'text-amber-900', 'border' => 'border-amber-200', 'accent' => 'border-b-amber-500'],
          'approved' => ['bg' => 'bg-sky-100', 'text' => 'text-sky-900', 'border' => 'border-sky-200', 'accent' => 'border-b-sky-500'],
          default => ['bg' => 'bg-zinc-100', 'text' => 'text-zinc-800', 'border' => 'border-zinc-200', 'accent' => 'border-b-zinc-400'],
      };

      // Format Date properly
      $rawDate = (string) ($e['start_at'] ?? '');
      $formattedDate = $rawDate ? (new DateTimeImmutable($rawDate))->format('M d, Y · g:i A') : 'TBA';
    ?>
    <a href="/event_view.php?id=<?= htmlspecialchars((string) ($e['id'] ?? '')) ?>"
       class="group relative block rounded-2xl border border-zinc-200 bg-white p-6 border-b-[3px] shadow-sm hover:shadow-md hover:-translate-y-0.5 transition-all duration-300 <?= $statusConfig['accent'] ?>">

      <div class="relative z-10">
        <div class="flex items-start justify-between gap-4 mb-4">
          <h4 class="text-sm font-bold tracking-tight text-zinc-900 group-hover:text-orange-900 transition-colors line-clamp-2"><?= htmlspecialchars((string) ($e['title'] ?? 'Event')) ?></h4>
          <span class="text-[10px] uppercase tracking-wider font-bold rounded-full border px-2.5 py-1 flex-shrink-0 <?= $statusConfig['bg'] ?> <?= $statusConfig['text'] ?> <?= $statusConfig['border'] ?>">
            <?= htmlspecialchars($status) ?>
          </span>
        </div>

        <p class="text-xs text-zinc-600 line-clamp-2 mb-5 min-h-[32px] leading-relaxed">
          <?= htmlspecialchars((string) ($e['description'] ?? 'No description provided for this event.')) ?>
        </p>

        <div class="space-y-2.5">
          <div class="flex items-center gap-2.5 text-xs font-medium text-zinc-800">
            <div class="w-7 h-7 rounded-full bg-orange-50 flex items-center justify-center flex-shrink-0 border border-orange-100">
              <svg class="w-3.5 h-3.5 text-orange-700" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            </div>
            <?= htmlspecialchars($formattedDate) ?>
          </div>
          <div class="flex items-center gap-2.5 text-xs text-zinc-600">
            <div class="w-7 h-7 rounded-full bg-emerald-50 flex items-center justify-center flex-shrink-0 border border-emerald-100">
              <svg class="w-3.5 h-3.5 text-emerald-700" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M15 10.5a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1115 0z"/></svg>
            </div>
            <span class="truncate"><?= htmlspecialchars((string) ($e['location'] ?? 'Location TBA')) ?></span>
          </div>
        </div>
      </div>
    </a>
  <?php endforeach; ?>
</div>

<?php
render_footer();
