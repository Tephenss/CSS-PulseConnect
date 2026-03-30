<?php
declare(strict_types=1);

require_once __DIR__ . '/csrf.php';

function render_header(string $title, ?array $user): void
{
    $role = $user && isset($user['role']) ? (string) $user['role'] : null;
    $csrf = csrf_ensure_token();
    $fullName = htmlspecialchars((string) ($user['full_name'] ?? 'User'));
    $initials = '';
    $parts = explode(' ', trim((string) ($user['full_name'] ?? 'U')));
    foreach ($parts as $p) {
        $p = trim($p);
        if ($p !== '') $initials .= mb_strtoupper(mb_substr($p, 0, 1));
        if (mb_strlen($initials) >= 2) break;
    }
    if ($initials === '') $initials = 'U';

    $roleBadge = '';
    $roleColor = '';
    if ($role === 'admin') { $roleBadge = 'Admin'; $roleColor = 'from-violet-500 to-fuchsia-500'; }
    elseif ($role === 'teacher') { $roleBadge = 'Teacher'; $roleColor = 'from-sky-500 to-cyan-500'; }
    else { $roleBadge = 'Student'; $roleColor = 'from-emerald-500 to-teal-500'; }

    echo '<!doctype html><html lang="en" class="dark"><head>';
    echo '<meta charset="utf-8"/><meta name="viewport" content="width=device-width, initial-scale=1"/>';
    echo '<title>' . htmlspecialchars($title) . ' — CSS PulseConnect</title>';
    echo '<script src="https://cdn.tailwindcss.com"></script>';
    echo '<link rel="preconnect" href="https://fonts.googleapis.com">';
    echo '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>';
    echo '<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">';
    echo '<link rel="stylesheet" href="/assets/css/app.css">';
    echo '<link rel="stylesheet" href="/assets/css/layout.css">';
    echo '<link rel="stylesheet" href="/assets/css/auth.css">';
    echo '</head><body class="min-h-screen bg-zinc-950 text-zinc-100">';

    // Mobile overlay
    echo '<div id="sidebar-overlay" class="fixed inset-0 bg-black/60 z-40 opacity-0 pointer-events-none lg:hidden" onclick="toggleSidebar()"></div>';

    // ── SIDEBAR ──
    echo '<aside id="sidebar" class="fixed top-0 left-0 h-screen w-[260px] bg-zinc-950 border-r border-zinc-800/60 flex flex-col z-50">';

    // Logo area
    echo '<div class="px-5 pt-5 pb-3">';
    echo '  <a href="/home.php" class="flex items-center gap-2.5 group">';
    echo '    <div class="w-8 h-8 rounded-lg bg-gradient-to-br from-violet-600 to-fuchsia-600 flex items-center justify-center shadow-lg shadow-violet-600/20">';
    echo '      <svg class="w-4 h-4 text-white" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 13.5l10.5-11.25L12 10.5h8.25L9.75 21.75 12 13.5H3.75z"/></svg>';
    echo '    </div>';
    echo '    <div>';
    echo '      <div class="text-sm font-semibold text-zinc-100 tracking-tight group-hover:text-white transition">PulseConnect</div>';
    echo '      <div class="text-[10px] text-zinc-500 -mt-0.5">CSS Event System</div>';
    echo '    </div>';
    echo '  </a>';
    echo '</div>';

    // Navigation
    echo '<nav class="flex-1 overflow-y-auto px-3 pb-4 content-area">';

    // ── Main nav ──
    echo '<div class="sidebar-section">Main</div>';

    // Dashboard
    $isActive = str_contains($title, 'Homepage') || str_contains($title, 'Dashboard');
    echo '<a href="/home.php" class="sidebar-link ' . ($isActive ? 'active' : '') . '">';
    echo '<svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 12l8.954-8.955a1.126 1.126 0 011.591 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75M8.25 21h8.25"/></svg>';
    echo 'Dashboard</a>';

    // Events
    $isActive = str_contains($title, 'Events') && !str_contains($title, 'Manage');
    echo '<a href="/events.php" class="sidebar-link ' . ($isActive ? 'active' : '') . '">';
    echo '<svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 11.25v7.5"/></svg>';
    echo 'Events</a>';

    // Student-only links
    if ($role === 'student') {
        echo '<a href="/my_tickets.php" class="sidebar-link ' . (str_contains($title, 'ticket') ? 'active' : '') . '">';
        echo '<svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M16.5 6v.75m0 3v.75m0 3v.75m0 3V18m-9-5.25h5.25M7.5 15h3M3.375 5.25c-.621 0-1.125.504-1.125 1.125v3.026a2.999 2.999 0 010 5.198v3.026c0 .621.504 1.125 1.125 1.125h17.25c.621 0 1.125-.504 1.125-1.125v-3.026a2.999 2.999 0 010-5.198V6.375c0-.621-.504-1.125-1.125-1.125H3.375z"/></svg>';
        echo 'My Tickets</a>';

        echo '<a href="/my_certificates.php" class="sidebar-link ' . (str_contains($title, 'certificate') ? 'active' : '') . '">';
        echo '<svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M4.26 10.147a60.436 60.436 0 00-.491 6.347A48.627 48.627 0 0112 20.904a48.627 48.627 0 018.232-4.41 60.46 60.46 0 00-.491-6.347m-15.482 0a50.57 50.57 0 00-2.658-.813A59.905 59.905 0 0112 3.493a59.902 59.902 0 0110.399 5.84c-.896.248-1.783.52-2.658.814m-15.482 0A50.697 50.697 0 0112 13.489a50.702 50.702 0 017.74-3.342"/></svg>';
        echo 'My Certificates</a>';
    }

    // Teacher / Admin links
    if ($role === 'teacher' || $role === 'admin') {
        echo '<div class="sidebar-section">Management</div>';

        echo '<a href="/manage_events.php" class="sidebar-link ' . (str_contains($title, 'Manage') ? 'active' : '') . '">';
        echo '<svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M10.5 6h9.75M10.5 6a1.5 1.5 0 11-3 0m3 0a1.5 1.5 0 10-3 0M3.75 6H7.5m3 12h9.75m-9.75 0a1.5 1.5 0 01-3 0m3 0a1.5 1.5 0 00-3 0m-3.75 0H7.5m9-6h3.75m-3.75 0a1.5 1.5 0 01-3 0m3 0a1.5 1.5 0 00-3 0m-9.75 0h9.75"/></svg>';
        echo 'Manage Events</a>';

        echo '<a href="/scan.php" class="sidebar-link ' . (str_contains($title, 'Scan') ? 'active' : '') . '">';
        echo '<svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 4.875c0-.621.504-1.125 1.125-1.125h4.5c.621 0 1.125.504 1.125 1.125v4.5c0 .621-.504 1.125-1.125 1.125h-4.5A1.125 1.125 0 013.75 9.375v-4.5zM3.75 14.625c0-.621.504-1.125 1.125-1.125h4.5c.621 0 1.125.504 1.125 1.125v4.5c0 .621-.504 1.125-1.125 1.125h-4.5a1.125 1.125 0 01-1.125-1.125v-4.5zM13.5 4.875c0-.621.504-1.125 1.125-1.125h4.5c.621 0 1.125.504 1.125 1.125v4.5c0 .621-.504 1.125-1.125 1.125h-4.5A1.125 1.125 0 0113.5 9.375v-4.5z"/><path stroke-linecap="round" stroke-linejoin="round" d="M6.75 6.75h.75v.75h-.75v-.75zM6.75 16.5h.75v.75h-.75v-.75zM16.5 6.75h.75v.75h-.75v-.75zM13.5 13.5h.75v.75h-.75v-.75zM13.5 19.5h.75v.75h-.75v-.75zM19.5 13.5h.75v.75h-.75v-.75zM19.5 19.5h.75v.75h-.75v-.75zM16.5 16.5h.75v.75h-.75v-.75z"/></svg>';
        echo 'QR Scanner</a>';
    }

    // Admin-only links
    if ($role === 'admin') {
        echo '<div class="sidebar-section">Admin</div>';

        echo '<a href="/admin_analytics.php" class="sidebar-link ' . (str_contains($title, 'Analytics') ? 'active' : '') . '">';
        echo '<svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 013 19.875v-6.75zM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V8.625zM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V4.125z"/></svg>';
        echo 'Analytics</a>';

        echo '<a href="/certificate_admin.php" class="sidebar-link ' . (str_contains($title, 'Certificates') || str_contains($title, 'Cert') ? 'active' : '') . '">';
        echo '<svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z"/></svg>';
        echo 'Cert Templates</a>';

        echo '<a href="/admin_users.php" class="sidebar-link ' . (str_contains($title, 'Users') ? 'active' : '') . '">';
        echo '<svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 002.625.372 9.337 9.337 0 004.121-.952 4.125 4.125 0 00-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 018.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0111.964-3.07M12 6.375a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zm8.25 2.25a2.625 2.625 0 11-5.25 0 2.625 2.625 0 015.25 0z"/></svg>';
        echo 'Users & Roles</a>';
    }

    echo '</nav>';

    // User section at bottom
    echo '<div class="border-t border-zinc-800/60 px-3 py-3">';
    echo '  <div class="flex items-center gap-3 px-2 py-2">';
    echo '    <div class="w-9 h-9 rounded-full bg-gradient-to-br ' . $roleColor . ' flex items-center justify-center text-white text-xs font-bold shadow-lg">' . htmlspecialchars($initials) . '</div>';
    echo '    <div class="flex-1 min-w-0">';
    echo '      <div class="text-sm font-medium text-zinc-200 truncate">' . $fullName . '</div>';
    echo '      <div class="text-[11px] text-zinc-500">' . htmlspecialchars($roleBadge) . '</div>';
    echo '    </div>';
    echo '    <a href="/logout.php" class="p-1.5 rounded-lg text-zinc-500 hover:text-red-400 hover:bg-zinc-800/60 transition" title="Logout">';
    echo '      <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 9V5.25A2.25 2.25 0 0013.5 3h-6a2.25 2.25 0 00-2.25 2.25v13.5A2.25 2.25 0 007.5 21h6a2.25 2.25 0 002.25-2.25V15m3 0l3-3m0 0l-3-3m3 3H9"/></svg>';
    echo '    </a>';
    echo '  </div>';
    echo '</div>';

    echo '</aside>';
    // ── END SIDEBAR ──

    // ── MAIN CONTENT AREA ──
    echo '<div class="lg:pl-[260px] min-h-screen flex flex-col">';

    // Top bar (mobile header + breadcrumb)
    echo '<header class="sticky top-0 z-30 border-b border-zinc-800/50 bg-zinc-950/80 backdrop-blur-xl">';
    echo '  <div class="flex items-center justify-between px-5 py-3.5">';

    // Mobile menu button
    echo '    <button onclick="toggleSidebar()" class="lg:hidden p-2 -ml-2 rounded-lg text-zinc-400 hover:text-zinc-100 hover:bg-zinc-800/60 transition">';
    echo '      <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5"/></svg>';
    echo '    </button>';

    // Page title
    echo '    <div class="flex items-center gap-3">';
    echo '      <h1 class="text-lg font-semibold text-zinc-100">' . htmlspecialchars($title) . '</h1>';
    echo '    </div>';

    // Right side - user info (desktop)
    echo '    <div class="hidden sm:flex items-center gap-3">';
    echo '      <div class="text-xs text-zinc-500">' . date('M d, Y') . '</div>';
    echo '      <div class="w-px h-4 bg-zinc-800"></div>';
    echo '      <div class="flex items-center gap-2">';
    echo '        <div class="w-7 h-7 rounded-full bg-gradient-to-br ' . $roleColor . ' flex items-center justify-center text-white text-[10px] font-bold">' . htmlspecialchars($initials) . '</div>';
    echo '        <span class="text-xs text-zinc-400">' . $fullName . '</span>';
    echo '      </div>';
    echo '    </div>';

    echo '  </div>';
    echo '</header>';

    echo '<script>window.CSRF_TOKEN=' . json_encode($csrf) . ';</script>';
    echo '<main class="flex-1 p-5 lg:p-8 content-area">';
}

function render_footer(): void
{
    echo '</main>';

    // Footer
    echo '<footer class="border-t border-zinc-800/40 px-5 lg:px-8 py-4">';
    echo '  <div class="flex items-center justify-between text-xs text-zinc-600">';
    echo '    <span>© ' . date('Y') . ' CSS PulseConnect</span>';
    echo '    <span>Event Management System</span>';
    echo '  </div>';
    echo '</footer>';

    echo '</div>'; // close lg:pl-[260px]

    // Sidebar toggle script
    echo '<script>
    function toggleSidebar() {
        const sidebar = document.getElementById("sidebar");
        const overlay = document.getElementById("sidebar-overlay");
        sidebar.classList.toggle("open");
        overlay.classList.toggle("open");
        document.body.classList.toggle("overflow-hidden");
    }
    </script>';

    echo '</body></html>';
}
