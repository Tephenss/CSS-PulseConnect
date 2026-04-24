<?php
declare(strict_types=1);

require_once __DIR__ . '/csrf.php';

function render_header(string $title, ?array $user): void
{
    $role = $user && isset($user['role']) ? (string) $user['role'] : null;
    $pendingAppCount = 0;
    if ($role === 'admin' && function_exists('supabase_request') && defined('SUPABASE_URL') && defined('SUPABASE_KEY') && defined('SUPABASE_TABLE_USERS')) {
        $countUrl = rtrim((string) SUPABASE_URL, '/') . '/rest/v1/' . SUPABASE_TABLE_USERS
            . '?select=id'
            . '&role=eq.student'
            . '&registration_source=eq.app'
            . '&account_status=eq.pending';
        $countHeaders = [
            'Accept: application/json',
            'apikey: ' . SUPABASE_KEY,
            'Authorization: Bearer ' . SUPABASE_KEY,
        ];
        $countRes = supabase_request('GET', $countUrl, $countHeaders);
        if (is_array($countRes) && !empty($countRes['ok'])) {
            $countRows = json_decode((string) ($countRes['body'] ?? '[]'), true);
            if (is_array($countRows)) {
                $pendingAppCount = count($countRows);
            }
        }
    }
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
    echo '<meta charset="utf-8"/><meta name="viewport" content="width=device-width, initial-scale=1"/>';
    echo '<title>' . htmlspecialchars($title) . ' — PulseCONNECT</title>';
    echo '<script src="https://cdn.tailwindcss.com"></script>';
    echo '<link rel="preconnect" href="https://fonts.googleapis.com">';
    echo '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>';
    echo '<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">';
    echo '<link rel="stylesheet" href="/assets/css/app.css?v=' . time() . '">';
    echo '<link rel="stylesheet" href="/assets/css/layout.css?v=' . time() . '">';
    echo '<link rel="stylesheet" href="/assets/css/auth.css">';
    echo '</head><body class="min-h-screen bg-zinc-50 text-zinc-900">';

    // Mobile overlay
    echo '<div id="sidebar-overlay" class="fixed inset-0 bg-black/40 z-40 opacity-0 pointer-events-none lg:hidden" onclick="closeMobileSidebar()"></div>';

    // ── SIDEBAR ──
    echo '<aside id="sidebar" class="fixed top-0 left-0 h-screen bg-[#450a0a] border-r border-red-900/50 flex flex-col z-50 overflow-hidden">';

    // Logo area
    echo '<div class="sidebar-header px-2 pt-8 pb-6 flex flex-col items-center justify-center flex-shrink-0 min-w-0 transition-all">';
    echo '  <a href="/home.php" class="flex flex-col items-center text-center gap-3 group min-w-0">';
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
    echo '<nav class="flex-1 overflow-y-auto overflow-x-hidden px-3 pb-4 content-area">';

    // ── Main nav ──
    echo '<div class="sidebar-section">Main</div>';

    // Dashboard
    $isActive = str_contains($title, 'Homepage') || str_contains($title, 'Dashboard');
    echo '<a href="/home.php" data-tooltip="Dashboard" class="sidebar-link ' . ($isActive ? 'active' : '') . '">';
    echo '<svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 12l8.954-8.955a1.126 1.126 0 011.591 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75M8.25 21h8.25"/></svg>';
    echo '<span class="sidebar-label">Dashboard</span></a>';

    // Events
    $isActive = str_contains($title, 'Events') && !str_contains($title, 'Manage');
    echo '<a href="/events.php" data-tooltip="Events" class="sidebar-link ' . ($isActive ? 'active' : '') . '">';
    echo '<svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 11.25v7.5"/></svg>';
    echo '<span class="sidebar-label">Events</span></a>';

    // Student-only links
    if ($role === 'student') {
        echo '<a href="/my_tickets.php" data-tooltip="My Tickets" class="sidebar-link ' . (str_contains($title, 'ticket') ? 'active' : '') . '">';
        echo '<svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M16.5 6v.75m0 3v.75m0 3v.75m0 3V18m-9-5.25h5.25M7.5 15h3M3.375 5.25c-.621 0-1.125.504-1.125 1.125v3.026a2.999 2.999 0 010 5.198v3.026c0 .621.504 1.125 1.125 1.125h17.25c.621 0 1.125-.504 1.125-1.125v-3.026a2.999 2.999 0 010-5.198V6.375c0-.621-.504-1.125-1.125-1.125H3.375z"/></svg>';
        echo '<span class="sidebar-label">My Tickets</span></a>';

        echo '<a href="/my_certificates.php" data-tooltip="My Certificates" class="sidebar-link ' . (str_contains($title, 'certificate') ? 'active' : '') . '">';
        echo '<svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M4.26 10.147a60.436 60.436 0 00-.491 6.347A48.627 48.627 0 0112 20.904a48.627 48.627 0 018.232-4.41 60.46 60.46 0 00-.491-6.347m-15.482 0a50.57 50.57 0 00-2.658-.813A59.905 59.905 0 0112 3.493a59.902 59.902 0 0110.399 5.84c-.896.248-1.783.52-2.658.814m-15.482 0A50.697 50.697 0 0112 13.489a50.702 50.702 0 017.74-3.342"/></svg>';
        echo '<span class="sidebar-label">My Certificates</span></a>';

        echo '<a href="/student_calendar.php" data-tooltip="Calendar" class="sidebar-link ' . (str_contains($title, 'Calendar') ? 'active' : '') . '">';
        echo '<svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 11.25v7.5"/></svg>';
        echo '<span class="sidebar-label">Calendar</span></a>';
    }

    // Teacher / Admin links
    if ($role === 'teacher' || $role === 'admin') {
        echo '<div class="sidebar-section">Management</div>';

        echo '<a href="/manage_events.php" data-tooltip="Manage Events" class="sidebar-link ' . (str_contains($title, 'Manage Events') ? 'active' : '') . '">';
        echo '<svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M10.5 6h9.75M10.5 6a1.5 1.5 0 11-3 0m3 0a1.5 1.5 0 10-3 0M3.75 6H7.5m3 12h9.75m-9.75 0a1.5 1.5 0 01-3 0m3 0a1.5 1.5 0 00-3 0m-3.75 0H7.5m9-6h3.75m-3.75 0a1.5 1.5 0 01-3 0m3 0a1.5 1.5 0 00-3 0m-9.75 0h9.75"/></svg>';
        echo '<span class="sidebar-label">Manage Events</span></a>';

        if ($role === 'admin') {
            echo '<a href="/manage_applications.php" data-tooltip="Manage Application" class="sidebar-link ' . (str_contains($title, 'Manage Application') ? 'active' : '') . '">';
            echo '<svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m3.75 9v6m3-3H9m-3.75 6.75h13.5A2.25 2.25 0 0021 18.75V8.25A2.25 2.25 0 0018.75 6H5.25A2.25 2.25 0 003 8.25v10.5A2.25 2.25 0 005.25 21z"/></svg>';
            echo '<span class="sidebar-label">Manage Application</span>';
            if ($pendingAppCount > 0) {
                $badge = $pendingAppCount > 99 ? '99+' : (string) $pendingAppCount;
                echo '<span class="ml-auto inline-flex items-center justify-center min-w-[20px] h-5 px-1.5 rounded-full text-[10px] font-bold bg-amber-500 text-white border border-amber-300 shadow-sm">' . htmlspecialchars($badge) . '</span>';
            }
            echo '</a>';
        }

        echo '<a href="/scan.php" data-tooltip="QR Scanner" class="sidebar-link ' . (str_contains($title, 'Scan') ? 'active' : '') . '">';
        echo '<svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 4.875c0-.621.504-1.125 1.125-1.125h4.5c.621 0 1.125.504 1.125 1.125v4.5c0 .621-.504 1.125-1.125 1.125h-4.5A1.125 1.125 0 013.75 9.375v-4.5zM3.75 14.625c0-.621.504-1.125 1.125-1.125h4.5c.621 0 1.125.504 1.125 1.125v4.5c0 .621-.504 1.125-1.125 1.125h-4.5a1.125 1.125 0 01-1.125-1.125v-4.5zM13.5 4.875c0-.621.504-1.125 1.125-1.125h4.5c.621 0 1.125.504 1.125 1.125v4.5c0 .621-.504 1.125-1.125 1.125h-4.5A1.125 1.125 0 0113.5 9.375v-4.5z"/><path stroke-linecap="round" stroke-linejoin="round" d="M6.75 6.75h.75v.75h-.75v-.75zM6.75 16.5h.75v.75h-.75v-.75zM16.5 6.75h.75v.75h-.75v-.75zM13.5 13.5h.75v.75h-.75v-.75zM13.5 19.5h.75v.75h-.75v-.75zM19.5 13.5h.75v.75h-.75v-.75zM19.5 19.5h.75v.75h-.75v-.75zM16.5 16.5h.75v.75h-.75v-.75z"/></svg>';
        echo '<span class="sidebar-label">QR Scanner</span></a>';

        $calHref = $role === 'admin' ? '/admin_calendar.php' : '/teacher_calendar.php';
        echo '<a href="' . $calHref . '" data-tooltip="Calendar" class="sidebar-link ' . (str_contains($title, 'Calendar') ? 'active' : '') . '">';
        echo '<svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 11.25v7.5"/></svg>';
        echo '<span class="sidebar-label">Calendar</span></a>';
    }

    // Admin-only links
    if ($role === 'admin') {
        echo '<div class="sidebar-section">Admin</div>';

        echo '<a href="/admin_sections.php" data-tooltip="Sections" class="sidebar-link ' . (str_contains($title, 'Sections') ? 'active' : '') . '">';
        echo '<svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6A2.25 2.25 0 016 3.75h2.25A2.25 2.25 0 0110.5 6v2.25a2.25 2.25 0 01-2.25 2.25H6a2.25 2.25 0 01-2.25-2.25V6zM3.75 15.75A2.25 2.25 0 016 13.5h2.25a2.25 2.25 0 012.25 2.25V18a2.25 2.25 0 01-2.25 2.25H6A2.25 2.25 0 013.75 18v-2.25zM13.5 6a2.25 2.25 0 012.25-2.25H18A2.25 2.25 0 0120.25 6v2.25A2.25 2.25 0 0118 10.5h-2.25a2.25 2.25 0 01-2.25-2.25V6zM13.5 15.75a2.25 2.25 0 012.25-2.25H18a2.25 2.25 0 012.25 2.25V18A2.25 2.25 0 0118 20.25h-2.25A2.25 2.25 0 0113.5 18v-2.25z"/></svg>';
        echo '<span class="sidebar-label">Sections</span></a>';

        echo '<a href="/admin_archive.php" data-tooltip="Archive" class="sidebar-link ' . (str_contains($title, 'Archive') ? 'active' : '') . '">';
        echo '<svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M20.25 7.5l-.625 10.632a2.25 2.25 0 01-2.247 2.118H6.622a2.25 2.25 0 01-2.247-2.118L3.75 7.5M10 11.25h4M3.375 7.5h17.25c.621 0 1.125-.504 1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125H2.25c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125z"/></svg>';
        echo '<span class="sidebar-label">Archive</span></a>';

        echo '<a href="/admin_analytics.php" data-tooltip="Analytics" class="sidebar-link ' . (str_contains($title, 'Analytics') ? 'active' : '') . '">';
        echo '<svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 013 19.875v-6.75zM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V8.625zM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V4.125z"/></svg>';
        echo '<span class="sidebar-label">Analytics</span></a>';

        echo '<a href="/admin_certificates.php" data-tooltip="Cert Templates" class="sidebar-link ' . (str_contains($title, 'Certificates') || str_contains($title, 'Cert') ? 'active' : '') . '">';
        echo '<svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z"/></svg>';
        echo '<span class="sidebar-label">Cert Templates</span></a>';

        echo '<a href="/admin_users.php" data-tooltip="Users &amp; Roles" class="sidebar-link ' . (str_contains($title, 'Users') ? 'active' : '') . '">';
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
    echo '  <a href="/logout.php" class="mt-1 flex items-center justify-center gap-2 w-full px-2 py-2 text-xs font-medium text-red-400 hover:bg-red-900/80 hover:text-red-300 rounded-lg transition sidebar-logo-text" title="Logout">';
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
    if ($role === 'admin') {
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
                    <a href="/manage_events.php" class="block text-center text-xs font-semibold text-emerald-600 hover:text-emerald-800 transition">See All Notifications</a>
                </div>
            </div>

            <!-- Trigger Button -->
            <button id="notif-trigger" class="pointer-events-auto relative w-14 h-14 bg-emerald-600 hover:bg-emerald-500 text-white rounded-full shadow-lg hover:shadow-xl transition-all duration-300 flex items-center justify-center transform hover:-translate-y-1 group border-4 border-white/50">
                <svg class="w-6 h-6 group-hover:animate-bounce" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M14.857 17.082a23.848 23.848 0 005.454-1.31A8.967 8.967 0 0118 9.75v-.7V9A6 6 0 006 9v.75a8.967 8.967 0 01-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 01-5.714 0m5.714 0a3 3 0 11-5.714 0" />
                </svg>
                <!-- Badge -->
                <div id="notif-badge" class="absolute -top-1 -right-1 w-6 h-6 bg-red-500 text-white text-[10px] font-bold rounded-full border-2 border-white flex items-center justify-center hidden shadow-sm">
                    0
                </div>
            </button>
        </div>

        <script>
        document.addEventListener("DOMContentLoaded", function() {
            const trigger = document.getElementById("notif-trigger");
            const panel = document.getElementById("notif-panel");
            const list = document.getElementById("notif-list");
            const badge = document.getElementById("notif-badge");
            const markReadBtn = document.getElementById("notif-mark-read");
            
            if(!trigger || !panel) return;

            let isPanelOpen = false;
            let loadedNotifications = [];
            let unreadCount = 0;
            
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
                    html += `
                    <a href="${item.link || \'/manage_events.php\'}" class="flex items-start gap-4 p-4 border-b border-zinc-100 hover:bg-zinc-50/80 transition-colors group">
                        <div class="mt-1 w-9 h-9 rounded-full bg-emerald-50 border border-emerald-100 flex items-center justify-center flex-shrink-0 group-hover:scale-105 transition-transform">
                            <svg class="w-4 h-4 text-emerald-600" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 11.25v7.5m-9-6h.008v.008H12v-.008zM12 15h.008v.008H12V15zm0 2.25h.008v.008H12v-.008zM9.75 15h.008v.008H9.75V15zm0 2.25h.008v.008H9.75v-.008zM7.5 15h.008v.008H7.5V15zm0 2.25h.008v.008H7.5v-.008zm6.75-4.5h.008v.008h-.008v-.008zm0 2.25h.008v.008h-.008V15zm0 2.25h.008v.008h-.008v-.008zm2.25-4.5h.008v.008H16.5v-.008zm0 2.25h.008v.008H16.5V15z" /></svg>
                        </div>
                        <div class="flex-1 min-w-0">
                            <h4 class="text-[13px] font-bold text-zinc-900 truncate mb-0.5">${item.title}</h4>
                            <p class="text-[12px] text-zinc-600 leading-snug line-clamp-2">${item.description}</p>
                            <div class="text-[11px] text-zinc-400 mt-1.5 font-medium">${formatTimeAgo(item.created_at)}</div>
                        </div>
                    </a>`;
                });
                list.innerHTML = html;
            }
            
            function updateBadge() {
                const readIds = JSON.parse(localStorage.getItem("pulse_notifs_read") || "[]");
                unreadCount = loadedNotifications.filter(n => !readIds.includes(n.id)).length;
                
                if (unreadCount > 0) {
                    badge.classList.remove("hidden");
                    badge.textContent = unreadCount > 9 ? "9+" : unreadCount;
                } else {
                    badge.classList.add("hidden");
                }
            }
            
            async function fetchNotifications() {
                try {
                    const res = await fetch("/api/get_notifications.php");
                    const data = await res.json();
                    if (data.ok) {
                        loadedNotifications = data.notifications;
                        renderNotifications(loadedNotifications);
                        updateBadge();
                    }
                } catch (e) {
                    console.error("Failed to load notifications", e);
                }
            }
            
            trigger.addEventListener("click", (e) => {
                e.preventDefault();
                e.stopPropagation();
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
                localStorage.setItem("pulse_notifs_read", JSON.stringify(readIds));
                updateBadge();
            });
            
            fetchNotifications();
            setInterval(fetchNotifications, 30000); // 30s polling
        });
        </script>';
    }

    echo '<script>window.CSRF_TOKEN=' . json_encode($csrf) . ';</script>';
    echo '<main class="flex-1 p-5 lg:p-8 content-area">';
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

        // ── Password Modal Logic ──
        window.openPasswordModal = function() {
            var m = document.getElementById("pword-modal");
            document.body.classList.add("overflow-hidden");
            m.classList.remove("hidden");
            m.classList.add("flex");
        };

        window.closePasswordModal = function() {
            var m = document.getElementById("pword-modal");
            document.body.classList.remove("overflow-hidden");
            m.classList.add("hidden");
            m.classList.remove("flex");
            document.getElementById("pform").reset();
            document.getElementById("pref-err").classList.add("hidden");
            document.getElementById("pref-suc").classList.add("hidden");
        };
        
        window.confirmPasswordChange = async function() {
            let cp = document.getElementById("p-curr").value;
            let np = document.getElementById("p-new").value;
            let cnp = document.getElementById("p-cnew").value;
            let err = document.getElementById("pref-err");
            let suc = document.getElementById("pref-suc");
            var btn = document.getElementById("pref-btn");
            var btnLbl = document.getElementById("pref-btn-lbl");
            var btnLoad = document.getElementById("pref-btn-load");
            
            err.classList.add("hidden");
            suc.classList.add("hidden");
            
            if(!cp || !np || !cnp) {
                err.textContent = "All fields are required.";
                err.classList.remove("hidden");
                return;
            }
            if(np !== cnp) {
                err.textContent = "New passwords do not match.";
                err.classList.remove("hidden");
                return;
            }
            if(np.length < 8) {
                err.textContent = "Password must be at least 8 characters.";
                err.classList.remove("hidden");
                return;
            }

            btn.disabled = true;
            btnLbl.classList.add("hidden");
            btnLoad.classList.remove("hidden");

            try {
                let r = await fetch("/api/change_password.php", {
                    method: "POST",
                    headers: { "Content-Type": "application/json" },
                    body: JSON.stringify({
                        csrf_token: window.CSRF_TOKEN || "",
                        current_password: cp,
                        new_password: np
                    })
                });
                
                let data = await r.json();
                
                if(!r.ok || data.error) {
                    err.textContent = data.error || "Update failed.";
                    err.classList.remove("hidden");
                } else if(data.success) {
                    suc.textContent = "Password updated successfully!";
                    suc.classList.remove("hidden");
                    setTimeout(() => closePasswordModal(), 2000);
                }
            } catch(e) {
                err.textContent = "Network error. Please try again.";
                err.classList.remove("hidden");
            } finally {
                btn.disabled = false;
                btnLbl.classList.remove("hidden");
                btnLoad.classList.add("hidden");
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
        <p class="text-xs text-zinc-500 mb-5">Securely update your account credentials.</p>
        
        <div id="pref-err" class="mb-4 hidden rounded-lg bg-red-50 p-3 text-xs font-medium text-red-600 ring-1 ring-inset ring-red-500/20"></div>
        <div id="pref-suc" class="mb-4 hidden rounded-lg bg-green-50 p-3 text-xs font-medium text-green-600 ring-1 ring-inset ring-green-600/20"></div>

        <form id="pform" class="space-y-4" onsubmit="event.preventDefault(); window.confirmPasswordChange();">
            <div>
                <label class="block text-xs font-semibold text-zinc-700 mb-1">Current Password</label>
                <input type="password" id="p-curr" required class="w-full rounded-xl bg-zinc-50 border border-zinc-200 px-3 py-2.5 text-sm outline-none focus:ring-2 focus:ring-indigo-600/30">
            </div>
            <div>
                <label class="block text-xs font-semibold text-zinc-700 mb-1">New Password</label>
                <input type="password" id="p-new" required class="w-full rounded-xl bg-zinc-50 border border-zinc-200 px-3 py-2.5 text-sm outline-none focus:ring-2 focus:ring-indigo-600/30">
            </div>
            <div>
                <label class="block text-xs font-semibold text-zinc-700 mb-1">Confirm New Password</label>
                <input type="password" id="p-cnew" required class="w-full rounded-xl bg-zinc-50 border border-zinc-200 px-3 py-2.5 text-sm outline-none focus:ring-2 focus:ring-indigo-600/30">
            </div>
            <button id="pref-btn" type="submit" class="mt-2 flex w-full justify-center items-center rounded-xl bg-indigo-600 px-3 py-3 text-sm font-semibold text-white shadow-md hover:bg-indigo-500 hover:shadow-lg transition">
                <span id="pref-btn-lbl">Update Password</span>
                <span id="pref-btn-load" class="hidden"><svg class="animate-spin h-5 w-5 text-white" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg></span>
            </button>
        </form>
      </div>
    </div>
    
    </body></html>';
}
