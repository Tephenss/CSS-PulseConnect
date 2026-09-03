<?php
declare(strict_types=1);

require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/favicon.php';

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
    if ($role === 'admin') { $roleBadge = 'Admin'; $roleColor = 'from-orange-500 to-red-500'; }
    elseif ($role === 'teacher') { $roleBadge = 'Teacher'; $roleColor = 'from-orange-600 to-red-600'; }
    else { $roleBadge = 'Student'; $roleColor = 'from-emerald-500 to-teal-500'; }

    echo '<!doctype html><html lang="en"><head>';
    echo '<meta charset="utf-8"/>';
    render_favicon_tags();
    echo '<meta name="viewport" content="width=device-width, initial-scale=1"/>';
    echo '<title>' . htmlspecialchars($title) . ' — PulseCONNECT</title>';
    // Stable asset versions (filemtime) so browsers can cache CSS across navigations.
    // Avoid ?v=time() — that forced a full CSS redownload on every click.
    $assetVersion = static function (string $relativePath): string {
        $full = dirname(__DIR__) . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
        $mtime = is_file($full) ? (int) @filemtime($full) : 1;
        return (string) max(1, $mtime);
    };
    echo '<link rel="stylesheet" href="/assets/css/tailwind.css?v=' . $assetVersion('/assets/css/tailwind.css') . '">';
    echo '<link rel="preconnect" href="https://fonts.googleapis.com">';
    echo '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>';
    echo '<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">';
    echo '<link rel="stylesheet" href="/assets/css/app.css?v=' . $assetVersion('/assets/css/app.css') . '">';
    echo '<link rel="stylesheet" href="/assets/css/layout.css?v=' . $assetVersion('/assets/css/layout.css') . '">';
    $roleClass = $role === 'teacher' ? 'role-teacher' : ($role === 'admin' ? 'role-admin' : 'role-student');
    echo '<link rel="stylesheet" href="/assets/css/auth.css?v=' . $assetVersion('/assets/css/auth.css') . '">';
    echo '<script src="/assets/js/password-strength.js?v=' . $assetVersion('/assets/js/password-strength.js') . '"></script>';
    echo '<style>
      @keyframes skeleton-pulse {
        0%, 100% { opacity: 1; }
        50% { opacity: 0.35; }
      }
      .skeleton-pulse {
        animation: skeleton-pulse 1.5s cubic-bezier(0.4, 0, 0.6, 1) infinite;
      }
    </style>';
    echo '</head><body class="min-h-screen bg-zinc-50 text-zinc-900 ' . $roleClass . '">';



    // Mobile overlay
    echo '<div id="sidebar-overlay" class="fixed inset-0 bg-black/40 z-40 opacity-0 pointer-events-none lg:hidden" onclick="closeMobileSidebar()"></div>';

    // ── SIDEBAR ──
    echo '<aside id="sidebar" data-lenis-prevent class="sidebar-shell fixed top-0 left-0 h-screen flex flex-col z-50 overflow-hidden">';

    // Logo area
    echo '<div class="sidebar-header px-2 pt-8 pb-6 flex flex-col items-center justify-center flex-shrink-0 min-w-0 transition-all">';
    echo '  <a href="/home" class="flex flex-col items-center text-center gap-3 group min-w-0">';
    echo '    <div class="p-3 bg-white/5 backdrop-blur-md rounded-2xl border border-white/10 shadow-lg group-hover:bg-white/10 transition-colors duration-300">';
    echo '      <div class="sidebar-logo w-16 h-16 flex-shrink-0 flex items-center justify-center floating-logo relative transition-all duration-300 logo-container">';
    echo '        <div class="hide-anatomy anatomy-left"><div class="anatomy-bracket-left"></div><div class="anatomy-diagonal-left"></div><span class="anatomy-text-left">BSIT</span></div>';
    echo '        <div class="hide-anatomy anatomy-right"><div class="anatomy-bracket-right"></div><div class="anatomy-diagonal-right"></div><span class="anatomy-text-right">CS</span></div>';
    echo '        <div class="absolute inset-0 bg-white/10 rounded-full blur-xl scale-110 opacity-0 group-hover:opacity-100 transition-opacity"></div>';
    echo '        <img src="/assets/CCS.png" alt="CCS Logo" class="w-full h-full object-contain relative z-10 drop-shadow-md" />';
    echo '      </div>';
    echo '    </div>';
    echo '    <div class="sidebar-logo-text min-w-0 mt-1">';
    echo '      <div class="text-[15px] font-bold text-white tracking-tight group-hover:text-amber-300 transition truncate">PulseCONNECT</div>';
    echo '      <div class="text-[10px] font-medium text-red-300 truncate tracking-wider uppercase mt-0.5">CCS Event System</div>';
    echo '    </div>';
    echo '  </a>';
    echo '</div>';

    // Navigation
    echo '<nav id="sidebar-nav" class="flex-1 min-h-0 overflow-y-auto overflow-x-hidden overscroll-contain px-3 pb-4 content-area">';

    // ── Main nav ──
    echo '<div class="sidebar-section">Main</div>';

    // Dashboard
    $isActive = str_contains($title, 'Homepage') || str_contains($title, 'Dashboard');
    echo '<a href="/home" data-tooltip="Dashboard" class="sidebar-link ' . ($isActive ? 'active' : '') . '">';
    echo '<svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 12l8.954-8.955a1.126 1.126 0 011.591 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75M8.25 21h8.25"/></svg>';
    echo '<span class="sidebar-label">Dashboard</span></a>';

    // Events browse (published / finished) — available to teacher & admin
    $isActive = str_contains($title, 'Events')
        && !str_contains($title, 'Manage')
        && !str_contains($title, 'Archive');
    echo '<a href="/events" data-tooltip="Events" class="sidebar-link ' . ($isActive ? 'active' : '') . '">';
    echo '<svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 11.25v7.5"/></svg>';
    echo '<span class="sidebar-label">Events</span></a>';

    // Student-only links
    if ($role === 'student') {
        echo '<a href="/my_tickets" data-tooltip="My Tickets" class="sidebar-link ' . (str_contains($title, 'ticket') ? 'active' : '') . '">';
        echo '<svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M16.5 6v.75m0 3v.75m0 3v.75m0 3V18m-9-5.25h5.25M7.5 15h3M3.375 5.25c-.621 0-1.125.504-1.125 1.125v3.026a2.999 2.999 0 010 5.198v3.026c0 .621.504 1.125 1.125 1.125h17.25c.621 0 1.125-.504 1.125-1.125v-3.026a2.999 2.999 0 010-5.198V6.375c0-.621-.504-1.125-1.125-1.125H3.375z"/></svg>';
        echo '<span class="sidebar-label">My Tickets</span></a>';

        echo '<a href="/my_certificates" data-tooltip="My Certificates" class="sidebar-link ' . (str_contains($title, 'certificate') ? 'active' : '') . '">';
        echo '<svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M4.26 10.147a60.436 60.436 0 00-.491 6.347A48.627 48.627 0 0112 20.904a48.627 48.627 0 018.232-4.41 60.46 60.46 0 00-.491-6.347m-15.482 0a50.57 50.57 0 00-2.658-.813A59.905 59.905 0 0112 3.493a59.902 59.902 0 0110.399 5.84c-.896.248-1.783.52-2.658.814m-15.482 0A50.697 50.697 0 0112 13.489a50.702 50.702 0 017.74-3.342"/></svg>';
        echo '<span class="sidebar-label">My Certificates</span></a>';

        echo '<a href="/student_calendar" data-tooltip="Calendar" class="sidebar-link ' . (str_contains($title, 'Calendar') ? 'active' : '') . '">';
        echo '<svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 11.25v7.5"/></svg>';
        echo '<span class="sidebar-label">Calendar</span></a>';
    }

    // Teacher / Admin links
    if ($role === 'teacher' || $role === 'admin') {
        echo '<div class="sidebar-section">Management</div>';

        echo '<a href="/manage_events" data-tooltip="Manage Events" class="sidebar-link ' . (str_contains($title, 'Manage Events') ? 'active' : '') . '" id="manage-events-link">';
        echo '<svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M10.5 6h9.75M10.5 6a1.5 1.5 0 11-3 0m3 0a1.5 1.5 0 10-3 0M3.75 6H7.5m3 12h9.75m-9.75 0a1.5 1.5 0 01-3 0m3 0a1.5 1.5 0 00-3 0m-3.75 0H7.5m9-6h3.75m-3.75 0a1.5 1.5 0 01-3 0m3 0a1.5 1.5 0 00-3 0m-9.75 0h9.75"/></svg>';
        echo '<span class="sidebar-label">Manage Events</span>';
        echo '<span id="manage-events-badge" class="manage-events-sidebar-badge ml-auto inline-flex items-center justify-center min-w-[20px] h-5 px-1.5 rounded-full text-[10px] font-bold bg-orange-500 text-white border border-orange-300 shadow-sm hidden">0</span>';
        echo '</a>';

        $calHref = $role === 'admin' ? '/admin_calendar' : '/teacher_calendar';
        echo '<a href="' . $calHref . '" data-tooltip="Calendar" class="sidebar-link ' . (str_contains($title, 'Calendar') ? 'active' : '') . '">';
        echo '<svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 11.25v7.5"/></svg>';
        echo '<span class="sidebar-label">Calendar</span></a>';

        if ($role === 'teacher') {
            echo '<a href="/teacher_sections" data-tooltip="Blocks" class="sidebar-link ' . ((str_contains($title, 'Block') || str_contains($title, 'Section')) ? 'active' : '') . '">';
            echo '<svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6A2.25 2.25 0 016 3.75h2.25A2.25 2.25 0 0110.5 6v2.25a2.25 2.25 0 01-2.25 2.25H6a2.25 2.25 0 01-2.25-2.25V6zM3.75 15.75A2.25 2.25 0 016 13.5h2.25a2.25 2.25 0 012.25 2.25V18a2.25 2.25 0 01-2.25 2.25H6A2.25 2.25 0 013.75 18v-2.25zM13.5 6a2.25 2.25 0 012.25-2.25H18A2.25 2.25 0 0120.25 6v2.25A2.25 2.25 0 0118 10.5h-2.25a2.25 2.25 0 01-2.25-2.25V6zM13.5 15.75a2.25 2.25 0 012.25-2.25H18a2.25 2.25 0 012.25 2.25V18A2.25 2.25 0 0118 20.25h-2.25A2.25 2.25 0 0113.5 18v-2.25z"/></svg>';
            echo '<span class="sidebar-label">Blocks</span></a>';

            echo '<a href="/certificates_library" data-tooltip="Cert Templates" class="sidebar-link ' . (str_contains($title, 'Certificates') || str_contains($title, 'Cert') ? 'active' : '') . '">';
            echo '<svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z"/></svg>';
            echo '<span class="sidebar-label">Cert Templates</span></a>';
        }
    }

    // Admin-only links
    if ($role === 'admin') {
        echo '<div class="sidebar-section">Admin</div>';

        echo '<a href="/admin_sections" data-tooltip="Blocks" class="sidebar-link ' . ((str_contains($title, 'Block') || str_contains($title, 'Section')) ? 'active' : '') . '">';
        echo '<svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6A2.25 2.25 0 016 3.75h2.25A2.25 2.25 0 0110.5 6v2.25a2.25 2.25 0 01-2.25 2.25H6a2.25 2.25 0 01-2.25-2.25V6zM3.75 15.75A2.25 2.25 0 016 13.5h2.25a2.25 2.25 0 012.25 2.25V18a2.25 2.25 0 01-2.25 2.25H6A2.25 2.25 0 013.75 18v-2.25zM13.5 6a2.25 2.25 0 012.25-2.25H18A2.25 2.25 0 0120.25 6v2.25A2.25 2.25 0 0118 10.5h-2.25a2.25 2.25 0 01-2.25-2.25V6zM13.5 15.75a2.25 2.25 0 012.25-2.25H18a2.25 2.25 0 012.25 2.25V18A2.25 2.25 0 0118 20.25h-2.25A2.25 2.25 0 0113.5 18v-2.25z"/></svg>';
        echo '<span class="sidebar-label">Blocks</span></a>';

        echo '<a href="/admin_archive" data-tooltip="Archive" class="sidebar-link ' . (str_contains($title, 'Archive') ? 'active' : '') . '">';
        echo '<svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M20.25 7.5l-.625 10.632a2.25 2.25 0 01-2.247 2.118H6.622a2.25 2.25 0 01-2.247-2.118L3.75 7.5M10 11.25h4M3.375 7.5h17.25c.621 0 1.125-.504 1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125H2.25c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125z"/></svg>';
        echo '<span class="sidebar-label">Archive</span></a>';

        echo '<a href="/admin_analytics" data-tooltip="Analytics" class="sidebar-link ' . (str_contains($title, 'Analytics') ? 'active' : '') . '">';
        echo '<svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 013 19.875v-6.75zM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V8.625zM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V4.125z"/></svg>';
        echo '<span class="sidebar-label">Analytics</span></a>';

        echo '<a href="/admin_showcase" data-tooltip="Showcase" class="sidebar-link ' . (str_contains($title, 'Showcase') ? 'active' : '') . '">';
        echo '<svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 15.75l5.159-5.159a2.25 2.25 0 013.182 0l5.159 5.159m-1.5-1.5l1.409-1.409a2.25 2.25 0 013.182 0l2.909 2.909M3.75 21h16.5A2.25 2.25 0 0022.5 18.75V5.25A2.25 2.25 0 0020.25 3H3.75A2.25 2.25 0 001.5 5.25v13.5A2.25 2.25 0 003.75 21z"/></svg>';
        echo '<span class="sidebar-label">Showcase</span></a>';

        echo '<a href="/admin_users" data-tooltip="Users &amp; Roles" class="sidebar-link ' . (str_contains($title, 'Users') ? 'active' : '') . '">';
        echo '<svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 002.625.372 9.337 9.337 0 004.121-.952 4.125 4.125 0 00-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 018.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0111.964-3.07M12 6.375a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zm8.25 2.25a2.625 2.625 0 11-5.25 0 2.625 2.625 0 015.25 0z"/></svg>';
        echo '<span class="sidebar-label">Users &amp; Roles</span></a>';
    }

    echo '</nav>';

    // User section at bottom
    echo '<div class="border-t border-red-900/50 px-3 py-3 flex-shrink-0">';
    echo '  <div class="flex items-center justify-between gap-2 px-2 py-2 rounded-xl hover:bg-red-900/60 transition cursor-pointer group" onclick="openPasswordModal()">';
    echo '    <div class="flex items-center gap-3 min-w-0 flex-1">';
    echo '      <div class="w-9 h-9 rounded-full bg-gradient-to-br ' . $roleColor . ' flex items-center justify-center text-white text-xs font-bold shadow-sm flex-shrink-0">' . htmlspecialchars($initials) . '</div>';
    echo '      <div class="sidebar-logo-text flex-1 min-w-0">';
    echo '        <div class="text-sm font-medium text-white truncate group-hover:text-orange-400 transition">' . $fullName . '</div>';
    echo '        <div class="text-[11px] text-red-300">' . htmlspecialchars($roleBadge) . '</div>';
    echo '      </div>';
    echo '    </div>';
    echo '    <button class="p-1.5 rounded-lg text-red-300 group-hover:text-orange-400 transition flex-shrink-0 sidebar-logo-text" title="Profile Settings">';
    echo '      <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>';
    echo '    </button>';
    echo '  </div>';
    echo '  <a href="/logout" class="mt-1 flex items-center justify-center gap-2 w-full px-2 py-2 text-xs font-medium text-red-400 hover:bg-red-900/80 hover:text-red-300 rounded-lg transition sidebar-logo-text" title="Logout">';
    echo '    <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 9V5.25A2.25 2.25 0 0013.5 3h-6a2.25 2.25 0 00-2.25 2.25v13.5A2.25 2.25 0 007.5 21h6a2.25 2.25 0 002.25-2.25V15m3 0l3-3m0 0l-3-3m3 3H9"/></svg>';
    echo '    Sign out';
    echo '  </a>';
    echo '</div>';

    echo '</aside>';
    // ── END SIDEBAR ──

    // ── MAIN CONTENT AREA ──
    echo '<div id="main-wrapper" class="main-offset min-h-screen flex flex-col">';

    // Top bar
    echo '<header class="sticky top-0 z-30 border-b border-zinc-200 bg-white/80 backdrop-blur-xl">';
    echo '  <div class="flex items-center justify-between px-5 py-3.5">';

    // Left: Burger button (works on BOTH mobile and desktop)
    echo '  <button id="sidebar-burger" onclick="toggleSidebarUniversal()" aria-label="Toggle sidebar"';
    echo '    class="p-2 -ml-2 rounded-lg text-zinc-500 hover:text-zinc-900 hover:bg-zinc-100 transition flex-shrink-0">';
    echo '    <svg id="burger-icon" class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">';
    echo '      <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5"/>';
    echo '    </svg>';
    echo '  </button>';

    // Page title
    echo '  <div class="flex items-center gap-3">';
    echo '    <h1 class="text-lg font-semibold text-zinc-900">' . htmlspecialchars($title) . '</h1>';
    echo '  </div>';

    // Right side - user info (desktop)
    echo '  <div class="hidden sm:flex items-center gap-3">';
    echo '    <div class="text-xs text-zinc-500">' . date('M d, Y') . '</div>';
    echo '    <div class="w-px h-4 bg-zinc-200"></div>';
    echo '    <div class="flex items-center gap-2">';
    echo '      <div class="w-7 h-7 rounded-full bg-gradient-to-br ' . $roleColor . ' flex items-center justify-center text-white text-[10px] font-bold">' . htmlspecialchars($initials) . '</div>';
    echo '      <span class="text-xs text-zinc-600">' . $fullName . '</span>';
    echo '    </div>';
    echo '  </div>';

    echo '  </div>';
    echo '</header>';

    // ══════ NOTIFICATION SYSTEM (Admin Only) ══════
    if (in_array($role, ['admin', 'teacher'], true)) {
        echo '
        <div id="notif-system" class="fixed bottom-6 right-6 z-[999] flex flex-col items-end pointer-events-none">
            
            <!-- Notification Panel -->
            <div id="notif-panel" class="pointer-events-auto w-80 sm:w-96 bg-white rounded-2xl shadow-2xl border border-zinc-200 mb-4 transition-all duration-300 origin-bottom-right scale-95 opacity-0 invisible flex flex-col max-h-[80vh]">
                <div class="px-5 py-4 border-b border-zinc-100 flex items-center justify-between">
                    <h3 class="text-sm font-bold text-zinc-900">Notifications</h3>
                    <button id="notif-mark-read" class="text-xs font-semibold text-emerald-600 hover:text-emerald-700 transition">Mark all as read</button>
                </div>
                
                <div id="notif-list" class="flex-1 overflow-y-auto min-h-[100px] bg-white">
                    <div class="flex items-center justify-center h-full py-8 text-xs text-zinc-500">Loading...</div>
                </div>
                
                <div class="px-5 py-3 border-t border-zinc-100 bg-zinc-50 rounded-b-2xl">
                    <a href="/notifications" class="block text-center text-xs font-semibold text-emerald-600 hover:text-emerald-800 transition">See All Notifications</a>
                </div>
            </div>

            <!-- Bell stack: preview pops in directly above the bell -->
            <div class="notif-bell-stack pointer-events-auto flex flex-col items-end">
                <div id="notif-preview" class="notif-preview hidden w-[18rem] sm:w-[20rem] max-w-[calc(100vw-2rem)] mb-3">
                    <div class="notif-preview-card rounded-2xl border border-emerald-200 bg-white shadow-2xl overflow-hidden">
                        <div class="px-3.5 py-2.5 border-b border-emerald-100 bg-emerald-50/80">
                            <div id="notif-preview-area" class="text-[10px] font-bold uppercase tracking-wide text-emerald-700 truncate"></div>
                        </div>
                        <div class="px-3.5 py-2.5">
                            <div id="notif-preview-title" class="text-[13px] font-bold text-zinc-900 leading-snug"></div>
                            <div id="notif-preview-desc" class="text-[11px] text-zinc-600 leading-snug mt-1 line-clamp-3"></div>
                        </div>
                    </div>
                    <span class="notif-preview-tail" aria-hidden="true"></span>
                </div>

                <button id="notif-trigger" class="notif-trigger relative w-14 h-14 bg-emerald-600 hover:bg-emerald-500 text-white rounded-full shadow-lg hover:shadow-xl transition-all duration-300 flex items-center justify-center transform hover:-translate-y-1 group border-4 border-white/50">
                <svg class="w-6 h-6 group-hover:animate-bounce" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M14.857 17.082a23.848 23.848 0 005.454-1.31A8.967 8.967 0 0118 9.75v-.7V9A6 6 0 006 9v.75a8.967 8.967 0 01-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 01-5.714 0m5.714 0a3 3 0 11-5.714 0" />
                </svg>
                <div id="notif-badge" class="absolute -top-1 -right-1 w-6 h-6 bg-red-500 text-white text-[10px] font-bold rounded-full border-2 border-white flex items-center justify-center hidden shadow-sm">
                    0
                </div>
            </button>
            </div>
        </div>

        <script>
        document.addEventListener("DOMContentLoaded", function() {
            const trigger = document.getElementById("notif-trigger");
            const panel = document.getElementById("notif-panel");
            const list = document.getElementById("notif-list");
            const badge = document.getElementById("notif-badge");
            const markReadBtn = document.getElementById("notif-mark-read");
            const preview = document.getElementById("notif-preview");
            const previewArea = document.getElementById("notif-preview-area");
            const previewTitle = document.getElementById("notif-preview-title");
            const previewDesc = document.getElementById("notif-preview-desc");
            
            if(!trigger || !panel) return;

            let isPanelOpen = false;
            let loadedNotifications = [];
            let unreadCount = 0;
            let knownNotificationIds = [];
            let previewHideTimer = null;
            let previewShownTimer = null;
            let currentPreviewItem = null;
            let notifAudioReady = false;
            const pulseUserId = String(window.PULSE_USER_ID || "default");
            const readStorageKey = "pulse_notifs_read_" + pulseUserId;
            const previewShownKey = "pulse_preview_shown_" + pulseUserId;
            const pendingPreviewStorageKey = "pulse_pending_previews_" + pulseUserId;
            const polledNotificationIdsKey = "pulse_notifs_polled_" + pulseUserId;
            let pendingPreviewQueue = [];
            let polledNotificationIds = [];
            let notificationsBootstrapped = false;

            function ensureNotifAudioContext() {
                const AudioCtx = window.AudioContext || window.webkitAudioContext;
                if (!AudioCtx) return null;
                if (!window.__pulseNotifAudioCtx) {
                    window.__pulseNotifAudioCtx = new AudioCtx();
                }
                return window.__pulseNotifAudioCtx;
            }

            function unlockNotifAudio() {
                const ctx = ensureNotifAudioContext();
                requestSystemNotifPermission();
                if (!ctx || notifAudioReady) return;
                if (ctx.state === "suspended") {
                    ctx.resume().then(() => { notifAudioReady = true; }).catch(() => {});
                } else {
                    notifAudioReady = true;
                }
            }

            function playNotifSound() {
                const ctx = ensureNotifAudioContext();
                if (!ctx) return;
                const startPlayback = () => {
                    try {
                        const now = ctx.currentTime;
                        const playTone = (frequency, startAt, duration, volume) => {
                            const oscillator = ctx.createOscillator();
                            const gain = ctx.createGain();
                            oscillator.type = "triangle";
                            oscillator.frequency.setValueAtTime(frequency, startAt);
                            gain.gain.setValueAtTime(0.0001, startAt);
                            gain.gain.exponentialRampToValueAtTime(volume, startAt + 0.012);
                            gain.gain.exponentialRampToValueAtTime(0.0001, startAt + duration);
                            oscillator.connect(gain);
                            gain.connect(ctx.destination);
                            oscillator.start(startAt);
                            oscillator.stop(startAt + duration + 0.05);
                        };
                        playTone(784, now, 0.12, 0.14);
                        playTone(988, now + 0.13, 0.12, 0.12);
                        playTone(1175, now + 0.26, 0.18, 0.1);
                    } catch (e) {
                        // Ignore autoplay or audio API failures.
                    }
                };
                if (ctx.state === "suspended") {
                    ctx.resume().then(startPlayback).catch(() => {});
                    return;
                }
                startPlayback();
            }

            document.addEventListener("click", unlockNotifAudio, { passive: true });
            document.addEventListener("keydown", unlockNotifAudio, { passive: true });
            document.addEventListener("pointerdown", unlockNotifAudio, { passive: true });
            document.addEventListener("touchstart", unlockNotifAudio, { passive: true });
            document.addEventListener("click", function (e) {
                const link = e.target && e.target.closest ? e.target.closest("a[href]") : null;
                if (!link || link.target === "_blank") return;
                const href = String(link.getAttribute("href") || "");
                if (!href || href.charAt(0) === "#" || href.indexOf("javascript:") === 0) return;
                if (currentPreviewItem && preview && !preview.classList.contains("hidden")) {
                    queueInterruptedPreview();
                }
            }, true);
            
            function formatTimeAgo(isoString) {
                const date = new Date(isoString);
                const now = new Date();
                const isToday = date.getDate() === now.getDate() && date.getMonth() === now.getMonth() && date.getFullYear() === now.getFullYear();
                const timeString = date.toLocaleTimeString([], { hour: "numeric", minute: "2-digit" });
                return isToday ? `Today, ${timeString}` : `${date.toLocaleDateString([], { month: "short", day: "numeric" })}, ${timeString}`;
            }
            
            function renderNotifications(data) {
                if (!data || data.length === 0) {
                    list.innerHTML = `<div class="px-5 py-8 text-center"><div class="mx-auto w-10 h-10 bg-zinc-100 rounded-full flex items-center justify-center mb-3"><svg class="w-5 h-5 text-zinc-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9" /></svg></div><p class="text-[13px] font-medium text-zinc-500">You\'re all caught up!</p></div>`;
                    return;
                }
                
                let html = "";
                data.forEach(item => {
                    const areaLabel = item.area || "Notification";
                    html += `
                    <a href="${item.link || \'/notifications\'}" class="flex items-start gap-4 p-4 border-b border-zinc-100 hover:bg-zinc-50/80 transition-colors group">
                        <div class="mt-1 w-9 h-9 rounded-full bg-emerald-50 border border-emerald-100 flex items-center justify-center flex-shrink-0 group-hover:scale-105 transition-transform">
                            <svg class="w-4 h-4 text-emerald-600" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M14.857 17.082a23.848 23.848 0 005.454-1.31A8.967 8.967 0 0118 9.75v-.7V9A6 6 0 006 9v.75a8.967 8.967 0 01-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 01-5.714 0m5.714 0a3 3 0 11-5.714 0" /></svg>
                        </div>
                        <div class="flex-1 min-w-0">
                            <div class="text-[10px] font-bold uppercase tracking-wide text-emerald-700 mb-1 truncate">${areaLabel}</div>
                            <h4 class="text-[13px] font-bold text-zinc-900 truncate mb-0.5">${item.title}</h4>
                            <p class="text-[12px] text-zinc-600 leading-snug line-clamp-2">${item.description}</p>
                            <div class="text-[11px] text-zinc-400 mt-1.5 font-medium">${formatTimeAgo(item.created_at)}</div>
                        </div>
                    </a>`;
                });
                list.innerHTML = html;
            }
            
            function updateBadge() {
                const readIds = JSON.parse(localStorage.getItem(readStorageKey) || "[]");
                unreadCount = loadedNotifications.filter(n => !readIds.includes(n.id)).length;
                
                if (unreadCount > 0) {
                    badge.classList.remove("hidden");
                    badge.textContent = unreadCount > 9 ? "9+" : unreadCount;
                } else {
                    badge.classList.add("hidden");
                }
            }
            
            function getPreviewDedupeKey(item) {
                if (!item) return "";
                if (item.dedupe_key) return String(item.dedupe_key);
                if (item.event_id && item.kind) return String(item.kind) + ":" + String(item.event_id);
                return String(item.id || "");
            }

            function shouldShowNotificationPreview(item) {
                const dedupeKey = getPreviewDedupeKey(item);
                if (!dedupeKey) return false;
                const now = Date.now();
                let history = {};
                try {
                    history = JSON.parse(sessionStorage.getItem(previewShownKey) || "{}");
                } catch (e) {
                    history = {};
                }
                const lastShown = Number(history[dedupeKey] || 0);
                return !(lastShown > 0 && (now - lastShown) < 120000);
            }

            function markNotificationPreviewShown(item) {
                const dedupeKey = getPreviewDedupeKey(item);
                if (!dedupeKey) return;
                const now = Date.now();
                let history = {};
                try {
                    history = JSON.parse(sessionStorage.getItem(previewShownKey) || "{}");
                } catch (e) {
                    history = {};
                }
                history[dedupeKey] = now;
                Object.keys(history).forEach(function (key) {
                    if ((now - Number(history[key] || 0)) > 900000) {
                        delete history[key];
                    }
                });
                sessionStorage.setItem(previewShownKey, JSON.stringify(history));
            }

            function clearNotificationPreviewShown(item) {
                const dedupeKey = getPreviewDedupeKey(item);
                if (!dedupeKey) return;
                try {
                    const history = JSON.parse(sessionStorage.getItem(previewShownKey) || "{}");
                    delete history[dedupeKey];
                    sessionStorage.setItem(previewShownKey, JSON.stringify(history));
                } catch (e) {
                    // Ignore storage failures.
                }
            }

            function loadPolledNotificationIds() {
                try {
                    const parsed = JSON.parse(sessionStorage.getItem(polledNotificationIdsKey) || "[]");
                    return Array.isArray(parsed) ? parsed : [];
                } catch (e) {
                    return [];
                }
            }

            function savePolledNotificationIds(ids) {
                try {
                    sessionStorage.setItem(polledNotificationIdsKey, JSON.stringify(ids.slice(-80)));
                } catch (e) {
                    // Ignore storage failures.
                }
            }

            function rememberPolledNotificationIds(ids) {
                if (!Array.isArray(ids) || ids.length === 0) return;
                let changed = false;
                ids.forEach(function (id) {
                    if (id && !polledNotificationIds.includes(id)) {
                        polledNotificationIds.push(id);
                        changed = true;
                    }
                });
                if (changed) {
                    savePolledNotificationIds(polledNotificationIds);
                }
            }

            function loadPendingPreviewQueue() {
                try {
                    const parsed = JSON.parse(sessionStorage.getItem(pendingPreviewStorageKey) || "[]");
                    pendingPreviewQueue = Array.isArray(parsed) ? parsed : [];
                } catch (e) {
                    pendingPreviewQueue = [];
                }
            }

            function savePendingPreviewQueue() {
                try {
                    sessionStorage.setItem(pendingPreviewStorageKey, JSON.stringify(pendingPreviewQueue.slice(-5)));
                } catch (e) {
                    // Ignore storage failures.
                }
            }

            function enqueuePendingPreview(item) {
                if (!item) return;
                const key = getPreviewDedupeKey(item);
                if (!key) return;
                if (pendingPreviewQueue.some((queued) => getPreviewDedupeKey(queued) === key)) return;
                clearNotificationPreviewShown(item);
                pendingPreviewQueue.push(item);
                savePendingPreviewQueue();
                if (trigger) trigger.classList.add("notif-bell-alert");
            }

            function requestSystemNotifPermission() {
                if (!("Notification" in window)) return;
                if (Notification.permission !== "default") return;
                Notification.requestPermission().catch(function () {});
            }

            function maybeShowSystemNotification(item) {
                if (!item || !("Notification" in window)) return;
                if (Notification.permission !== "granted") return;
                const tag = getPreviewDedupeKey(item) || String(item.id || "pulse-notif");
                try {
                    new Notification(item.title || "PulseCONNECT", {
                        body: ((item.area ? item.area + " — " : "") + (item.description || "")).trim(),
                        tag: tag,
                    });
                } catch (e) {
                    // Ignore OS notification failures.
                }
            }

            function flushPendingPreviews() {
                if (document.visibilityState !== "visible" || pendingPreviewQueue.length === 0) return;
                if (currentPreviewItem) return;
                const item = pendingPreviewQueue.shift();
                savePendingPreviewQueue();
                showNotificationPreview(item, { forceVisible: true, skipDedupe: true });
            }

            function queueInterruptedPreview() {
                if (!currentPreviewItem || !preview) return;
                const interrupted = currentPreviewItem;
                const wasOpen = !preview.classList.contains("hidden");
                if (!wasOpen) return;
                window.clearTimeout(previewHideTimer);
                window.clearTimeout(previewShownTimer);
                preview.classList.remove("notif-preview-visible");
                preview.classList.add("hidden");
                if (trigger) trigger.classList.remove("notif-bell-alert");
                currentPreviewItem = null;
                enqueuePendingPreview(interrupted);
            }

            function syncNotificationsOnFocus() {
                flushPendingPreviews();
                fetchNotifications(true);
            }

            function showNotificationPreview(item, options) {
                options = options || {};
                if (!preview || !previewTitle || !previewDesc || !item) return;
                if (!options.forceVisible && document.visibilityState === "hidden") {
                    enqueuePendingPreview(item);
                    maybeShowSystemNotification(item);
                    return;
                }
                if (!options.skipDedupe && !shouldShowNotificationPreview(item)) {
                    if (pendingPreviewQueue.length > 0) {
                        window.setTimeout(flushPendingPreviews, 300);
                    }
                    return;
                }
                currentPreviewItem = item;
                if (previewArea) {
                    previewArea.textContent = item.area || "Notification";
                }
                previewTitle.textContent = item.title || "New update";
                previewDesc.textContent = item.description || "";
                window.clearTimeout(previewHideTimer);
                window.clearTimeout(previewShownTimer);
                preview.classList.remove("hidden");
                preview.classList.remove("notif-preview-visible");
                window.requestAnimationFrame(() => {
                    window.requestAnimationFrame(() => {
                        preview.classList.add("notif-preview-visible");
                        previewShownTimer = window.setTimeout(() => {
                            markNotificationPreviewShown(item);
                        }, 700);
                    });
                });
                trigger.classList.add("notif-bell-alert");
                playNotifSound();
                previewHideTimer = window.setTimeout(() => {
                    preview.classList.remove("notif-preview-visible");
                    window.setTimeout(() => {
                        preview.classList.add("hidden");
                        trigger.classList.remove("notif-bell-alert");
                        currentPreviewItem = null;
                        if (pendingPreviewQueue.length > 0) {
                            window.setTimeout(flushPendingPreviews, 350);
                        }
                    }, 380);
                }, 6200);
            }
            
            let notificationsInFlight = false;
            let layoutBadgesInFlight = false;

            function applyNotificationsPayload(data, isCatchUp) {
                if (!data || !data.ok) return;
                const nextNotifications = data.notifications || [];
                const nextIds = nextNotifications.map((item) => item.id).filter(Boolean);
                const readIds = JSON.parse(localStorage.getItem(readStorageKey) || "[]");

                if (!notificationsBootstrapped) {
                    notificationsBootstrapped = true;
                    polledNotificationIds = loadPolledNotificationIds();
                    if (polledNotificationIds.length === 0 && nextIds.length > 0) {
                        rememberPolledNotificationIds(nextIds);
                    }
                }

                const freshUnread = nextNotifications.filter((item) => {
                    if (!item || !item.id) return false;
                    if (polledNotificationIds.includes(item.id)) return false;
                    if (readIds.includes(item.id)) return false;
                    return true;
                });

                if (freshUnread.length > 0) {
                    if (document.visibilityState === "hidden") {
                        freshUnread.forEach(function (item) { enqueuePendingPreview(item); });
                        maybeShowSystemNotification(freshUnread[0]);
                    } else if (isCatchUp) {
                        freshUnread.forEach(function (item) { enqueuePendingPreview(item); });
                        flushPendingPreviews();
                    } else {
                        showNotificationPreview(freshUnread[0]);
                        freshUnread.slice(1).forEach(function (item) { enqueuePendingPreview(item); });
                    }
                    rememberPolledNotificationIds(freshUnread.map((item) => item.id));
                } else {
                    const unseenIds = nextIds.filter((id) => !polledNotificationIds.includes(id));
                    if (unseenIds.length > 0) {
                        rememberPolledNotificationIds(unseenIds);
                    }
                }

                knownNotificationIds = nextIds;
                loadedNotifications = nextNotifications;
                        renderNotifications(loadedNotifications);
                        updateBadge();
            }

            function applyApplicationsBadge(applications) {
                var applicationsBadgeEl = document.getElementById("manage-applications-badge");
                if (!applicationsBadgeEl || !applications || applications.ok !== true) return;
                var count = Number(applications.count || 0);
                if (!Number.isFinite(count) || count < 0) count = 0;
                if (count <= 0) {
                    applicationsBadgeEl.classList.add("hidden");
                    return;
                }
                applicationsBadgeEl.classList.remove("hidden");
                applicationsBadgeEl.textContent = count > 99 ? "99+" : String(count);
            }

            function applyManageEventsFromBadges(manage) {
                if (!manage || manage.ok !== true) return;
                if (typeof window.PulseApplyManageEventsLite === "function") {
                    window.PulseApplyManageEventsLite(manage);
                }
            }

            async function fetchLayoutBadges(isCatchUp) {
                if (layoutBadgesInFlight) return;
                layoutBadgesInFlight = true;
                notificationsInFlight = true;
                const controller = new AbortController();
                const timeoutId = window.setTimeout(() => controller.abort(), 10000);
                try {
                    const freshParam = isCatchUp ? "&fresh=1" : "";
                    const res = await fetch("/api/layout_badges.php?limit=10" + freshParam + "&_=" + Date.now(), {
                        cache: "no-store",
                        credentials: "same-origin",
                        signal: controller.signal,
                    });
                    const data = await res.json();
                    if (data && data.ok) {
                        applyNotificationsPayload(data, !!isCatchUp);
                        applyManageEventsFromBadges(data.manage_events || null);
                        applyApplicationsBadge(data.applications || null);
                    }
                } catch (e) {
                    console.error("Failed to load layout badges", e);
                } finally {
                    window.clearTimeout(timeoutId);
                    layoutBadgesInFlight = false;
                    notificationsInFlight = false;
                }
            }

            async function fetchNotifications(isCatchUp) {
                // Prefer combined badges poll; keep name for existing focus hooks.
                await fetchLayoutBadges(isCatchUp);
            }
            
            trigger.addEventListener("click", (e) => {
                e.preventDefault();
                e.stopPropagation();
                unlockNotifAudio();
                if (preview) {
                    preview.classList.remove("notif-preview-visible");
                    window.setTimeout(() => preview.classList.add("hidden"), 280);
                    trigger.classList.remove("notif-bell-alert");
                    window.clearTimeout(previewHideTimer);
                    window.clearTimeout(previewShownTimer);
                    currentPreviewItem = null;
                }
                isPanelOpen = !isPanelOpen;
                if (isPanelOpen) {
                    panel.classList.remove("scale-95", "opacity-0", "invisible");
                    panel.classList.add("scale-100", "opacity-100", "visible");
                } else {
                    panel.classList.remove("scale-100", "opacity-100", "visible");
                    panel.classList.add("scale-95", "opacity-0", "invisible");
                }
            });
            
            document.addEventListener("click", (e) => {
                if (isPanelOpen && !panel.contains(e.target) && !trigger.contains(e.target)) {
                    isPanelOpen = false;
                    panel.classList.remove("scale-100", "opacity-100", "visible");
                    panel.classList.add("scale-95", "opacity-0", "invisible");
                }
            });
            
            markReadBtn.addEventListener("click", (e) => {
                e.preventDefault();
                const readIds = loadedNotifications.map(n => n.id);
                localStorage.setItem(readStorageKey, JSON.stringify(readIds));
                updateBadge();
            });
            
            loadPendingPreviewQueue();
            polledNotificationIds = loadPolledNotificationIds();
            if (pendingPreviewQueue.length > 0) {
                window.setTimeout(flushPendingPreviews, 500);
            }
            fetchNotifications(false);
            setInterval(function () { fetchNotifications(false); }, 90000);
            document.addEventListener("visibilitychange", function () {
                if (document.visibilityState === "visible") {
                    syncNotificationsOnFocus();
                } else if (currentPreviewItem) {
                    enqueuePendingPreview(currentPreviewItem);
                }
            });
            window.addEventListener("focus", syncNotificationsOnFocus);
            window.addEventListener("pagehide", queueInterruptedPreview);

            window.showPulseNotifPreview = showNotificationPreview;
            window.playPulseNotifSound = playNotifSound;
            window.PulseFlushPendingNotifications = syncNotificationsOnFocus;
        });
        </script>';
    }

    $emailMasked = '';
    $sessionEmail = strtolower(trim((string) ($user['email'] ?? '')));
    if ($sessionEmail !== '' && str_contains($sessionEmail, '@')) {
        [$localPart, $domainPart] = explode('@', $sessionEmail, 2);
        $keep = min(2, max(1, (int) floor(mb_strlen($localPart) / 3)));
        $emailMasked = mb_substr($localPart, 0, $keep) . '***@' . $domainPart;
    }
    echo '<script>window.CSRF_TOKEN=' . json_encode($csrf) . ';window.PULSE_USER_ID=' . json_encode((string) ($user['id'] ?? '')) . ';window.PULSE_USER_EMAIL_MASKED=' . json_encode($emailMasked) . ';</script>';
    echo '<main class="relative flex-1 p-5 lg:p-8 content-area">';

    // Determine skeleton layout category depending on current page filename
    $currentPage = basename($_SERVER['PHP_SELF'], '.php');
    $skeletonType = 'dashboard';
    if (in_array($currentPage, ['admin_users', 'admin_section_students', 'teacher_section_students', 'participants', 'event_document_review', 'admin_archive', 'notifications'], true)) {
        $skeletonType = 'table';
    } elseif (in_array($currentPage, ['events', 'admin_sections', 'certificates_library', 'teacher_sections', 'my_certificates', 'my_tickets'], true)) {
        $skeletonType = 'grid';
    }

    // Database-Free Content Area Skeleton Loader (does not cover sidebar/topbar)
    echo '<div id="page-skeleton-loader" style="
      position: absolute;
      top: 0;
      left: 0;
      right: 0;
      bottom: 0;
      z-index: 50;
      background-color: #f4f4f5;
      pointer-events: none;
      transition: opacity 0.22s ease-out;
      overflow: hidden;
      padding: inherit;
    ">';

    if ($skeletonType === 'table') {
        // 📋 TABLE / LIST SKELETON
        echo '<div style="display: flex; flex-direction: column; gap: 20px; width: 100%;">
          <!-- Search filter placeholder -->
          <div style="height: 56px; background-color: #ffffff; border: 1px solid #e4e4e7; border-radius: 16px; display: flex; align-items: center; padding: 0 16px; gap: 12px;">
            <div class="skeleton-pulse" style="width: 200px; height: 20px; background-color: #e4e4e7; border-radius: 6px;"></div>
            <div class="skeleton-pulse" style="width: 80px; height: 28px; background-color: #e4e4e7; border-radius: 14px; margin-left: auto;"></div>
          </div>
          <!-- Table frame -->
          <div style="background-color: #ffffff; border: 1px solid #e4e4e7; border-radius: 20px; padding: 16px; display: flex; flex-direction: column; gap: 4px;">
            <div style="height: 40px; border-bottom: 1px solid #f4f4f5; display: flex; align-items: center; padding: 0 16px; gap: 16px; margin-bottom: 8px;">
              <div class="skeleton-pulse" style="width: 30%; height: 16px; background-color: #e4e4e7; border-radius: 4px;"></div>
              <div class="skeleton-pulse" style="width: 25%; height: 16px; background-color: #e4e4e7; border-radius: 4px;"></div>
              <div class="skeleton-pulse" style="width: 25%; height: 16px; background-color: #e4e4e7; border-radius: 4px;"></div>
              <div class="skeleton-pulse" style="width: 10%; height: 16px; background-color: #e4e4e7; border-radius: 4px; margin-left: auto;"></div>
            </div>';
        for ($i = 0; $i < 6; $i++) {
            echo '<div style="height: 56px; display: flex; align-items: center; padding: 0 16px; gap: 16px; border-bottom: 1px solid #f4f4f5;">
              <div class="skeleton-pulse" style="width: 32px; height: 32px; background-color: #e4e4e7; border-radius: 50%;"></div>
              <div class="skeleton-pulse" style="width: 25%; height: 16px; background-color: #e4e4e7; border-radius: 4px;"></div>
              <div class="skeleton-pulse" style="width: 20%; height: 16px; background-color: #e4e4e7; border-radius: 4px;"></div>
              <div class="skeleton-pulse" style="width: 25%; height: 16px; background-color: #e4e4e7; border-radius: 4px;"></div>
              <div class="skeleton-pulse" style="width: 24px; height: 24px; background-color: #e4e4e7; border-radius: 6px; margin-left: auto;"></div>
            </div>';
        }
        echo '</div></div>';
    } elseif ($skeletonType === 'grid') {
        // 🎴 CARDS GRID SKELETON
        echo '<div style="display: flex; flex-direction: column; gap: 24px; width: 100%;">
          <div style="display: flex; align-items: center; justify-content: space-between;">
            <div class="skeleton-pulse" style="width: 150px; height: 24px; background-color: #e4e4e7; border-radius: 6px;"></div>
            <div class="skeleton-pulse" style="width: 100px; height: 36px; background-color: #e4e4e7; border-radius: 12px;"></div>
          </div>
          <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 20px;">';
        for ($i = 0; $i < 6; $i++) {
            echo '<div style="background-color: #ffffff; border: 1px solid #e4e4e7; border-radius: 20px; overflow: hidden; padding: 16px; display: flex; flex-direction: column; gap: 12px;">
              <div class="skeleton-pulse" style="width: 100%; height: 140px; background-color: #e4e4e7; border-radius: 12px;"></div>
              <div class="skeleton-pulse" style="width: 70%; height: 18px; background-color: #e4e4e7; border-radius: 6px;"></div>
              <div class="skeleton-pulse" style="width: 40%; height: 14px; background-color: #e4e4e7; border-radius: 4px;"></div>
              <div style="display: flex; align-items: center; justify-content: space-between; margin-top: 8px;">
                <div class="skeleton-pulse" style="width: 80px; height: 28px; background-color: #e4e4e7; border-radius: 8px;"></div>
                <div class="skeleton-pulse" style="width: 24px; height: 24px; background-color: #e4e4e7; border-radius: 50%;"></div>
              </div>
            </div>';
        }
        echo '</div></div>';
    } else {
        // 📊 DASHBOARD OVERVIEW SKELETON
        echo '<div style="display: flex; flex-direction: column; gap: 24px; width: 100%;">
          <!-- Welcome card placeholder -->
          <div style="height: 120px; background-color: #ffffff; border: 1px solid #e4e4e7; border-radius: 20px; padding: 24px; display: flex; align-items: center; justify-content: space-between; gap: 16px;">
            <div style="flex: 1;">
              <div class="skeleton-pulse" style="width: 30%; height: 24px; background-color: #e4e4e7; border-radius: 6px; margin-bottom: 12px;"></div>
              <div class="skeleton-pulse" style="width: 60%; height: 14px; background-color: #e4e4e7; border-radius: 4px;"></div>
            </div>
            <div class="skeleton-pulse" style="width: 100px; height: 70px; background-color: #d4d4d8; border-radius: 12px;"></div>
          </div>
          <!-- System stats grid -->
          <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px;">';
        for ($i = 0; $i < 4; $i++) {
            echo '<div style="height: 76px; background-color: #ffffff; border: 1px solid #e4e4e7; border-radius: 16px; padding: 16px; display: flex; align-items: center; gap: 12px;">
              <div class="skeleton-pulse" style="width: 44px; height: 44px; background-color: #e4e4e7; border-radius: 12px;"></div>
              <div style="flex: 1;">
                <div class="skeleton-pulse" style="width: 24px; height: 20px; background-color: #e4e4e7; border-radius: 4px; margin-bottom: 6px;"></div>
                <div class="skeleton-pulse" style="width: 50%; height: 12px; background-color: #e4e4e7; border-radius: 4px;"></div>
              </div>
            </div>';
        }
        echo '  </div>
          <!-- Columns -->
          <div style="flex: 1; display: grid; grid-template-columns: 2fr 1fr; gap: 24px;">
            <div style="background-color: #ffffff; border: 1px solid #e4e4e7; border-radius: 20px; padding: 24px;">
              <div class="skeleton-pulse" style="width: 140px; height: 20px; background-color: #e4e4e7; border-radius: 6px; margin-bottom: 24px;"></div>
              <div class="skeleton-pulse" style="height: 48px; background-color: #f4f4f5; border-radius: 12px; margin-bottom: 12px;"></div>
              <div class="skeleton-pulse" style="height: 48px; background-color: #f4f4f5; border-radius: 12px; margin-bottom: 12px;"></div>
              <div class="skeleton-pulse" style="height: 48px; background-color: #f4f4f5; border-radius: 12px; margin-bottom: 12px;"></div>
            </div>
            <div style="background-color: #ffffff; border: 1px solid #e4e4e7; border-radius: 20px; padding: 24px;">
              <div class="skeleton-pulse" style="width: 100px; height: 20px; background-color: #e4e4e7; border-radius: 6px; margin-bottom: 24px;"></div>
              <div class="skeleton-pulse" style="height: 160px; background-color: #f4f4f5; border-radius: 16px;"></div>
            </div>
          </div>
        </div>';
    }

    echo '</div>';

    // Auto-dismiss script (fast fade-out and DOM removal)
    echo '<script>
      (function() {
        var hideSkel = function() {
          var loader = document.getElementById("page-skeleton-loader");
          if (loader) {
            loader.style.opacity = "0";
            setTimeout(function() {
              if (loader.parentNode) {
                loader.parentNode.removeChild(loader);
              }
            }, 250);
          }
        };
        if (document.readyState === "complete") {
          hideSkel();
        } else {
          window.addEventListener("load", hideSkel);
          // Safety timeout of 1.8 seconds in case external resource fetches stall
          setTimeout(hideSkel, 1800);
        }
      })();
    </script>';
}

function render_footer(): void
{
    echo '</main>';

    // Footer
    echo '<footer class="border-t border-zinc-200 px-5 lg:px-8 py-4">';
    echo '  <div class="flex flex-col sm:flex-row items-center justify-between gap-2 text-xs text-zinc-500 pr-16 sm:pr-24">';
    echo '    <span>© ' . date('Y') . ' PulseCONNECT</span>';
    echo '    <span>Event Management System</span>';
    echo '  </div>';
    echo '</footer>';

    echo '</div>'; // close main-wrapper

    // ── Sidebar toggle scripts ──
    echo '<script>
    (function () {
        var sidebar = document.getElementById("sidebar");
        var overlay = document.getElementById("sidebar-overlay");
        var wrapper = document.getElementById("main-wrapper");
        var isDesktop = function () { return window.innerWidth >= 1024; };

        // Restore desktop collapsed state from localStorage
        if (isDesktop() && localStorage.getItem("sidebar-collapsed") === "1") {
            sidebar.classList.add("collapsed");
            document.body.classList.add("sidebar-collapsed");
        }

        window.toggleSidebarUniversal = function () {
            if (isDesktop()) {
                // Desktop: collapse/expand icon-only
                var collapsed = sidebar.classList.toggle("collapsed");
                document.body.classList.toggle("sidebar-collapsed", collapsed);
                localStorage.setItem("sidebar-collapsed", collapsed ? "1" : "0");
            } else {
                // Mobile: slide-in drawer
                var open = sidebar.classList.toggle("open");
                overlay.classList.toggle("open", open);
                document.body.classList.toggle("overflow-hidden", open);
            }
        };

        window.closeMobileSidebar = function () {
            sidebar.classList.remove("open");
            overlay.classList.remove("open");
            document.body.classList.remove("overflow-hidden");
        };

        // Close mobile drawer on resize to desktop
        window.addEventListener("resize", function () {
            if (window.innerWidth >= 1024) {
                closeMobileSidebar();
            }
        });

        // Keep Manage Events sidebar badge updated without refresh.
        var manageEventsBadgeEl = document.getElementById("manage-events-badge");
        var manageEventsLinkEl = document.getElementById("manage-events-link");
        var manageEventsBadgePolling = null;
        var manageEventsBadgeInFlight = false;
        var manageEventsSeenKey = "pulse_manage_events_seen_" + String(window.PULSE_USER_ID || "default");
        var manageEventsPolledSignalIdsKey = "pulse_manage_events_polled_" + String(window.PULSE_USER_ID || "default");
        var polledManageEventsSignalIds = [];
        var manageEventsSignalsBootstrapped = false;

        function isManageEventsPage() {
            return window.location.pathname.indexOf("manage_events") !== -1;
        }

        function loadPolledManageEventsSignalIds() {
            try {
                var parsed = JSON.parse(sessionStorage.getItem(manageEventsPolledSignalIdsKey) || "[]");
                return Array.isArray(parsed) ? parsed : [];
            } catch (e) {
                return [];
            }
        }

        function savePolledManageEventsSignalIds(ids) {
            try {
                sessionStorage.setItem(manageEventsPolledSignalIdsKey, JSON.stringify(ids.slice(-120)));
            } catch (e) {
                // Ignore storage failures.
            }
        }

        function rememberPolledManageEventsSignalIds(ids) {
            if (!Array.isArray(ids) || ids.length === 0) return;
            var changed = false;
            ids.forEach(function (id) {
                if (id && polledManageEventsSignalIds.indexOf(id) === -1) {
                    polledManageEventsSignalIds.push(id);
                    changed = true;
                }
            });
            if (changed) {
                savePolledManageEventsSignalIds(polledManageEventsSignalIds);
            }
        }

        function signalToPreviewItem(signal) {
            if (!signal) return null;
            return {
                id: signal.id || "",
                area: signal.area || "Manage Events",
                title: signal.title || "New event update",
                description: signal.description || "",
                dedupe_key: signal.dedupe_key || "",
                event_id: signal.event_id || "",
                kind: signal.kind || "",
                link: "/manage_events",
            };
        }

        function getManageEventsSeenIds() {
            try {
                var parsed = JSON.parse(localStorage.getItem(manageEventsSeenKey) || "[]");
                return Array.isArray(parsed) ? parsed : [];
            } catch (e) {
                return [];
            }
        }

        function markManageEventsSignalsSeen(signals) {
            if (!Array.isArray(signals) || signals.length === 0) return;
            var seen = getManageEventsSeenIds();
            var changed = false;
            signals.forEach(function (signal) {
                if (!signal) return;
                if (signal.id && !seen.includes(signal.id)) {
                    seen.push(signal.id);
                    changed = true;
                }
                if (signal.dedupe_key && !seen.includes(signal.dedupe_key)) {
                    seen.push(signal.dedupe_key);
                    changed = true;
                }
            });
            if (changed) {
                localStorage.setItem(manageEventsSeenKey, JSON.stringify(seen.slice(-300)));
            }
        }

        window.markManageEventsSignalsSeen = markManageEventsSignalsSeen;
        window.updateManageEventsBadgeFromSignals = updateManageEventsBadgeFromSignals;

        function updateManageEventsBadgeFromSignals(signals) {
            if (!manageEventsBadgeEl) return;

            if (isManageEventsPage()) {
                markManageEventsSignalsSeen(signals || []);
            }

            var seen = getManageEventsSeenIds();
            var unseenKeys = {};
            var unseen = Array.isArray(signals)
                ? signals.filter(function (signal) {
                    if (!signal || !signal.id) return false;
                    if (seen.includes(signal.id)) return false;
                    if (signal.dedupe_key && seen.includes(signal.dedupe_key)) return false;
                    var groupKey = signal.dedupe_key || signal.id;
                    if (unseenKeys[groupKey]) return false;
                    unseenKeys[groupKey] = true;
                    return true;
                })
                : [];

            if (unseen.length <= 0) {
                manageEventsBadgeEl.classList.add("hidden");
                return;
            }
            manageEventsBadgeEl.classList.remove("hidden");
            manageEventsBadgeEl.textContent = unseen.length > 99 ? "99+" : String(unseen.length);
        }

        function handleFreshManageEventsSignals(signals) {
            if (!Array.isArray(signals) || signals.length === 0) return;

            var signalIds = signals.map(function (signal) {
                return signal && signal.id ? signal.id : "";
            }).filter(Boolean);

            if (!manageEventsSignalsBootstrapped) {
                manageEventsSignalsBootstrapped = true;
                polledManageEventsSignalIds = loadPolledManageEventsSignalIds();
                if (polledManageEventsSignalIds.length === 0 && signalIds.length > 0) {
                    rememberPolledManageEventsSignalIds(signalIds);
                    return;
                }
            }

            var freshSignals = signals.filter(function (signal) {
                if (!signal || !signal.id) return false;
                return polledManageEventsSignalIds.indexOf(signal.id) === -1;
            });

            if (freshSignals.length > 0) {
                rememberPolledManageEventsSignalIds(freshSignals.map(function (signal) { return signal.id; }));
                if (!isManageEventsPage() && typeof window.showPulseNotifPreview === "function") {
                    var previewItem = signalToPreviewItem(freshSignals[0]);
                    if (previewItem) {
                        window.showPulseNotifPreview(previewItem);
                    }
                }
            } else if (signalIds.length > 0) {
                var unseenIds = signalIds.filter(function (id) {
                    return polledManageEventsSignalIds.indexOf(id) === -1;
                });
                if (unseenIds.length > 0) {
                    rememberPolledManageEventsSignalIds(unseenIds);
                }
            }
        }

        function applyManageEventsLitePayload(data) {
            if (!data || data.ok !== true) return;
            handleFreshManageEventsSignals(data.signals || []);
            updateManageEventsBadgeFromSignals(data.signals || []);
        }
        window.PulseApplyManageEventsLite = applyManageEventsLitePayload;

        async function refreshManageEventsBadge(forceFresh) {
            // When layout badges poll is active, only force-refresh on demand (click/focus catch-up).
            if (!forceFresh && typeof window.PulseFlushPendingNotifications === "function") {
                return;
            }
            if (!manageEventsBadgeEl || manageEventsBadgeInFlight) return;
            manageEventsBadgeInFlight = true;
            const controller = new AbortController();
            const timeoutId = window.setTimeout(() => controller.abort(), 8000);
            try {
                var freshParam = forceFresh ? "&fresh=1" : "";
                var resp = await fetch("/api/manage_events_live.php?lite=1" + freshParam + "&_=" + Date.now(), {
                    cache: "no-store",
                    credentials: "same-origin",
                    signal: controller.signal,
                });
                if (!resp.ok) return;
                var data = await resp.json();
                if (!data || data.ok !== true) return;
                applyManageEventsLitePayload(data);
            } catch (e) {
                // Keep current badge state on transient network failures.
            } finally {
                window.clearTimeout(timeoutId);
                manageEventsBadgeInFlight = false;
            }
        }

        function initManageEventsBadgePolling() {
            if (!manageEventsBadgeEl) return;
            polledManageEventsSignalIds = loadPolledManageEventsSignalIds();
            // Combined layout_badges poll owns the periodic refresh; only bootstrap mark-seen here.
            if (isManageEventsPage()) {
                window.setTimeout(function () {
                    fetch("/api/manage_events_live.php?lite=1&_=" + Date.now(), { cache: "no-store", credentials: "same-origin" })
                        .then(function (resp) { return resp.json(); })
                        .then(function (data) {
                            if (data && data.ok) {
                                markManageEventsSignalsSeen(data.signals || []);
                                updateManageEventsBadgeFromSignals(data.signals || []);
                            }
                        })
                        .catch(function () {});
                }, 200);
            }
            if (manageEventsLinkEl) {
                manageEventsLinkEl.addEventListener("click", function () {
                    fetch("/api/manage_events_live.php?lite=1&_=" + Date.now(), { cache: "no-store", credentials: "same-origin" })
                        .then(function (resp) { return resp.json(); })
                        .then(function (data) {
                            if (data && data.ok) {
                                markManageEventsSignalsSeen(data.signals || []);
                                updateManageEventsBadgeFromSignals(data.signals || []);
                            }
                        })
                        .catch(function () {});
                });
            }
            document.addEventListener("visibilitychange", function () {
                if (document.visibilityState === "visible") {
                    // Catch-up via combined badges when available.
                    if (typeof window.PulseFlushPendingNotifications === "function") {
                        window.PulseFlushPendingNotifications();
                    } else {
                        refreshManageEventsBadge(true);
                    }
                }
            });
        }

        if (manageEventsBadgeEl) {
            if (document.readyState === "loading") {
                document.addEventListener("DOMContentLoaded", initManageEventsBadgePolling);
            } else {
                initManageEventsBadgePolling();
            }
        }

        // Applications badge is refreshed by combined /api/layout_badges.php poll.
        // Keep a one-shot bootstrap only when notification script is absent (students).
        var applicationsBadgeEl = document.getElementById("manage-applications-badge");
        if (applicationsBadgeEl && typeof window.PulseFlushPendingNotifications !== "function") {
            (async function refreshManageApplicationsBadgeOnce() {
                try {
                    var resp = await fetch("/api/manage_applications_pending_count.php", { cache: "no-store" });
                    if (!resp.ok) return;
                    var data = await resp.json();
                    if (!data || data.ok !== true) return;
                    var count = Number(data.count || 0);
                    if (!Number.isFinite(count) || count < 0) count = 0;
                    if (count <= 0) {
                        applicationsBadgeEl.classList.add("hidden");
                        return;
                    }
                    applicationsBadgeEl.classList.remove("hidden");
                    applicationsBadgeEl.textContent = count > 99 ? "99+" : String(count);
                } catch (e) {}
            })();
        }

        // ── Password Modal Logic (OTP first, then new + confirm) ──
        var pwStep = 0;
        var pwChangeToken = "";
        var pwCooldown = 0;
        var pwCooldownTimer = null;
        var pwEmailMasked = String(window.PULSE_USER_EMAIL_MASKED || "");
        var pwMeterBound = false;

        function pwEnsureMeter() {
            if (pwMeterBound) return;
            if (!window.PulsePassword || typeof PulsePassword.bindMeter !== "function") return;
            var input = document.getElementById("p-new");
            var meter = document.getElementById("p-new-meter");
            if (!input || !meter) return;
            PulsePassword.bindMeter(input, meter);
            pwMeterBound = true;
        }

        function pwSetLoading(isLoading) {
            var btn = document.getElementById("pref-btn");
            var btnLbl = document.getElementById("pref-btn-lbl");
            var btnLoad = document.getElementById("pref-btn-load");
            if (!btn || !btnLbl || !btnLoad) return;
            btn.disabled = !!isLoading;
            btnLbl.classList.toggle("hidden", !!isLoading);
            btnLoad.classList.toggle("hidden", !isLoading);
        }

        function pwShowMsg(kind, text) {
            var err = document.getElementById("pref-err");
            var suc = document.getElementById("pref-suc");
            if (!err || !suc) return;
            err.classList.add("hidden");
            suc.classList.add("hidden");
            if (!text) return;
            if (kind === "ok") {
                suc.textContent = text;
                suc.classList.remove("hidden");
            } else {
                err.textContent = text;
                err.classList.remove("hidden");
            }
        }

        function pwUpdateDesc() {
            var desc = document.getElementById("pref-desc");
            if (!desc) return;
            if (pwStep === 0) {
                desc.textContent = pwEmailMasked
                    ? ("Tap Send to receive a 6-digit code at " + pwEmailMasked + ".")
                    : "Tap Send to receive a 6-digit code on your email.";
            } else if (pwStep === 1) {
                desc.textContent = pwEmailMasked
                    ? ("Enter the 6-digit code sent to " + pwEmailMasked + ".")
                    : "Enter the 6-digit code sent to your email.";
            } else {
                desc.textContent = "Enter and confirm your new password.";
            }
        }

        function pwRenderSteps() {
            var sendStep = document.getElementById("pref-step-send");
            var otpStep = document.getElementById("pref-step-otp");
            var passStep = document.getElementById("pref-step-pass");
            var btnLbl = document.getElementById("pref-btn-lbl");
            if (sendStep) sendStep.classList.toggle("hidden", pwStep !== 0);
            if (otpStep) otpStep.classList.toggle("hidden", pwStep !== 1);
            if (passStep) passStep.classList.toggle("hidden", pwStep !== 2);
            if (btnLbl) {
                btnLbl.textContent = pwStep === 0 ? "Send Code" : (pwStep === 1 ? "Verify Code" : "Update Password");
            }
            if (pwStep === 2) pwEnsureMeter();
            pwUpdateDesc();
        }

        function pwStartCooldown(seconds) {
            pwCooldown = Math.max(0, parseInt(seconds, 10) || 0);
            var resendBtn = document.getElementById("pref-resend");
            if (pwCooldownTimer) {
                window.clearInterval(pwCooldownTimer);
                pwCooldownTimer = null;
            }
            function tick() {
                if (!resendBtn) return;
                if (pwCooldown <= 0) {
                    resendBtn.disabled = false;
                    resendBtn.textContent = "Resend Code";
                    return;
                }
                resendBtn.disabled = true;
                resendBtn.textContent = "Resend available in " + pwCooldown + "s";
            }
            tick();
            if (pwCooldown <= 0) return;
            pwCooldownTimer = window.setInterval(function () {
                pwCooldown -= 1;
                if (pwCooldown <= 0) {
                    window.clearInterval(pwCooldownTimer);
                    pwCooldownTimer = null;
                }
                tick();
            }, 1000);
        }

        function pwResetModalState() {
            pwStep = 0;
            pwChangeToken = "";
            pwCooldown = 0;
            if (pwCooldownTimer) {
                window.clearInterval(pwCooldownTimer);
                pwCooldownTimer = null;
            }
            var form = document.getElementById("pform");
            if (form) form.reset();
            var pnew = document.getElementById("p-new");
            if (pnew) pnew.dispatchEvent(new Event("input"));
            pwShowMsg("", "");
            pwRenderSteps();
            var resendBtn = document.getElementById("pref-resend");
            if (resendBtn) {
                resendBtn.disabled = false;
                resendBtn.textContent = "Resend Code";
            }
        }

        async function pwApi(payload) {
            var r = await fetch("/api/change_password.php", {
                method: "POST",
                headers: { "Content-Type": "application/json" },
                body: JSON.stringify(Object.assign({
                    csrf_token: window.CSRF_TOKEN || ""
                }, payload))
            });
            var data = {};
            try { data = await r.json(); } catch (e) { data = {}; }
            return { ok: r.ok && (data.ok === true || data.success === true), data: data, status: r.status };
        }

        window.openPasswordModal = function() {
            var m = document.getElementById("pword-modal");
            document.body.classList.add("overflow-hidden");
            m.classList.remove("hidden");
            m.classList.add("flex");
            pwResetModalState();
        };

        window.closePasswordModal = function() {
            var m = document.getElementById("pword-modal");
            document.body.classList.remove("overflow-hidden");
            m.classList.add("hidden");
            m.classList.remove("flex");
            pwResetModalState();
        };

        window.resendPasswordOtp = async function() {
            if (pwStep !== 1 || pwCooldown > 0) return;
            pwShowMsg("", "");
            pwSetLoading(true);
            try {
                var res = await pwApi({ action: "send_otp" });
                if (!res.ok) {
                    pwShowMsg("err", (res.data && res.data.error) || "Unable to send verification code.");
                    return;
                }
                if (res.data && res.data.email_masked) pwEmailMasked = String(res.data.email_masked);
                pwStartCooldown(res.data && res.data.cooldown_seconds ? res.data.cooldown_seconds : 60);
                pwUpdateDesc();
                pwShowMsg("ok", "Verification code sent. Check your email.");
            } catch (e) {
                pwShowMsg("err", "Network error. Please try again.");
            } finally {
                pwSetLoading(false);
            }
        };
        
        window.confirmPasswordChange = async function() {
            pwShowMsg("", "");
            if (pwStep === 0) {
                pwSetLoading(true);
                try {
                    var sendRes = await pwApi({ action: "send_otp" });
                    if (!sendRes.ok) {
                        pwShowMsg("err", (sendRes.data && sendRes.data.error) || "Unable to send verification code.");
                        return;
                    }
                    if (sendRes.data && sendRes.data.email_masked) pwEmailMasked = String(sendRes.data.email_masked);
                    pwStep = 1;
                    pwRenderSteps();
                    pwStartCooldown(sendRes.data && sendRes.data.cooldown_seconds ? sendRes.data.cooldown_seconds : 60);
                    if (!sendRes.data || sendRes.data.skipped !== true) {
                        pwShowMsg("ok", "Verification code sent. Check your email.");
                    }
                } catch (e) {
                    pwShowMsg("err", "Network error. Please try again.");
                } finally {
                    pwSetLoading(false);
                }
                return;
            }

            if (pwStep === 1) {
                var code = (document.getElementById("p-otp") || {}).value || "";
                code = String(code).trim();
                if (!/^\d{6}$/.test(code)) {
                    pwShowMsg("err", "Please enter the 6-digit code.");
                    return;
                }
                pwSetLoading(true);
                try {
                    var verifyRes = await pwApi({ action: "verify_otp", code: code });
                    if (!verifyRes.ok) {
                        pwShowMsg("err", (verifyRes.data && verifyRes.data.error) || "Invalid verification code.");
                        return;
                    }
                    pwChangeToken = String((verifyRes.data && verifyRes.data.change_token) || "");
                    if (!pwChangeToken) {
                        pwShowMsg("err", "Invalid verification session. Please request a new code.");
                        return;
                    }
                    pwStep = 2;
                    pwRenderSteps();
                    pwShowMsg("ok", "Code verified. Set your new password.");
                } catch (e) {
                    pwShowMsg("err", "Network error. Please try again.");
                } finally {
                    pwSetLoading(false);
                }
                return;
            }

            var np = (document.getElementById("p-new") || {}).value || "";
            var cnp = (document.getElementById("p-cnew") || {}).value || "";
            if (!np || !cnp) {
                pwShowMsg("err", "All fields are required.");
                return;
            }
            if (np !== cnp) {
                pwShowMsg("err", "New passwords do not match.");
                return;
            }
            if (!window.PulsePassword || !PulsePassword.isStrong(np)) {
                pwShowMsg("err", (window.PulsePassword && PulsePassword.error) || "Use 8+ chars with upper, lower, number, and symbol.");
                return;
            }
            if (!pwChangeToken) {
                pwShowMsg("err", "Please verify the code first.");
                pwStep = 0;
                pwRenderSteps();
                return;
            }

            pwSetLoading(true);
            try {
                var updateRes = await pwApi({
                    action: "update",
                    change_token: pwChangeToken,
                        new_password: np
                });
                if (!updateRes.ok) {
                    pwShowMsg("err", (updateRes.data && updateRes.data.error) || "Update failed.");
                    return;
                }
                pwShowMsg("ok", "Password updated successfully!");
                setTimeout(function () { closePasswordModal(); }, 2000);
            } catch (e) {
                pwShowMsg("err", "Network error. Please try again.");
            } finally {
                pwSetLoading(false);
            }
        };

    })();
    </script>
    
    <!-- ══════ CHANGE PASSWORD MODAL ══════ -->
    <div id="pword-modal" style="z-index:9999;" class="fixed inset-0 hidden items-center justify-center px-4 bg-zinc-950/80 backdrop-blur-sm transition-opacity">
      <div class="relative w-full max-w-sm rounded-[1.5rem] bg-white p-6 shadow-2xl ring-1 ring-zinc-900/5 transition-transform transform">
        <!-- Close -->
        <button onclick="closePasswordModal()" class="absolute right-5 top-5 text-zinc-400 hover:text-zinc-600">
          <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
        </button>
        <!-- Header -->
        <h3 class="text-xl font-bold text-zinc-900 flex items-center gap-2 mb-1">
          <svg class="w-5 h-5 text-indigo-600" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 10-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z"/></svg> 
          Change Password
        </h3>
        <p id="pref-desc" class="text-xs text-zinc-500 mb-5">Tap Send to receive a 6-digit code on your email.</p>
        
        <div id="pref-err" class="mb-4 hidden rounded-lg bg-red-50 p-3 text-xs font-medium text-red-600 ring-1 ring-inset ring-red-500/20"></div>
        <div id="pref-suc" class="mb-4 hidden rounded-lg bg-green-50 p-3 text-xs font-medium text-green-600 ring-1 ring-inset ring-green-600/20"></div>

        <form id="pform" class="space-y-4" onsubmit="event.preventDefault(); window.confirmPasswordChange();">
            <div id="pref-step-send"></div>
            <div id="pref-step-otp" class="hidden space-y-3">
            <div>
                    <label class="block text-xs font-semibold text-zinc-700 mb-1">Confirmation Code</label>
                    <input type="text" id="p-otp" inputmode="numeric" maxlength="6" autocomplete="one-time-code" class="w-full rounded-xl bg-zinc-50 border border-zinc-200 px-3 py-2.5 text-sm outline-none focus:ring-2 focus:ring-indigo-600/30 tracking-[0.3em]">
            </div>
                <div class="flex justify-end">
                    <button type="button" id="pref-resend" onclick="window.resendPasswordOtp()" class="text-xs font-semibold text-indigo-600 hover:text-indigo-500 disabled:text-zinc-400">Resend Code</button>
                </div>
            </div>
            <div id="pref-step-pass" class="hidden space-y-4">
            <div>
                <label class="block text-xs font-semibold text-zinc-700 mb-1">New Password</label>
                    <input type="password" id="p-new" autocomplete="new-password" class="w-full rounded-xl bg-zinc-50 border border-zinc-200 px-3 py-2.5 text-sm outline-none focus:ring-2 focus:ring-indigo-600/30">
                    <div id="p-new-meter" class="pw-meter mt-2 space-y-1.5">
                      <div class="flex items-center justify-between gap-2">
                        <div data-pw-bar class="h-1.5 flex-1 rounded-full bg-zinc-200 overflow-hidden">
                          <div data-pw-fill class="h-full w-0 rounded-full transition-all"></div>
                        </div>
                        <span data-pw-label class="text-[10px] font-black uppercase tracking-wider min-w-[3.5rem] text-right"></span>
                      </div>
                      <ul class="grid grid-cols-1 gap-0.5 text-[10px] text-zinc-500">
                        <li data-pw-rule="len">8+ characters</li>
                        <li data-pw-rule="upper">Uppercase letter</li>
                        <li data-pw-rule="lower">Lowercase letter</li>
                        <li data-pw-rule="digit">Number</li>
                        <li data-pw-rule="special">Symbol</li>
                      </ul>
                    </div>
            </div>
            <div>
                <label class="block text-xs font-semibold text-zinc-700 mb-1">Confirm New Password</label>
                    <input type="password" id="p-cnew" class="w-full rounded-xl bg-zinc-50 border border-zinc-200 px-3 py-2.5 text-sm outline-none focus:ring-2 focus:ring-indigo-600/30">
            </div>
            </div>
            <button id="pref-btn" type="submit" class="mt-2 flex w-full justify-center items-center rounded-xl bg-indigo-600 px-3 py-3 text-sm font-semibold text-white shadow-md hover:bg-indigo-500 hover:shadow-lg transition disabled:opacity-60">
                <span id="pref-btn-lbl">Send Code</span>
                <span id="pref-btn-load" class="hidden"><svg class="animate-spin h-5 w-5 text-white" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg></span>
            </button>
        </form>
      </div>
    </div>
    
    </body></html>';
}
