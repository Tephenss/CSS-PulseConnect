<?php
declare(strict_types=1);

session_start();

if (empty($_SESSION['user']) || !is_array($_SESSION['user'])) {
    header('Location: login.php?unauth=1');
    exit;
}

$user = $_SESSION['user'];
?>

<!doctype html>
<html lang="en" class="dark">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Dashboard</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="min-h-screen bg-zinc-950 text-zinc-100 p-6">
    <div class="max-w-3xl mx-auto">
        <div class="flex items-start justify-between gap-4">
            <div>
                <div class="text-sm uppercase tracking-widest text-zinc-400">Protected</div>
                <h1 class="text-2xl font-semibold mt-2">Welcome, <?= htmlspecialchars((string) ($user['full_name'] ?? '')) ?></h1>
                <p class="text-zinc-400 mt-2 text-sm">Logged in as <span class="text-zinc-200"><?= htmlspecialchars((string) ($user['email'] ?? '')) ?></span></p>
            </div>
            <a
                href="logout.php"
                class="rounded-lg border border-zinc-800 bg-zinc-900/40 px-4 py-2.5 text-sm hover:bg-zinc-900 transition"
            >
                Logout
            </a>
        </div>

        <div class="mt-8 rounded-xl border border-zinc-800 bg-zinc-900/30 p-5">
            <h2 class="text-base font-medium">Session info</h2>
            <div class="mt-3 text-sm text-zinc-300 leading-relaxed">
                <div><span class="text-zinc-400">User ID:</span> <?= htmlspecialchars((string) ($user['id'] ?? '')) ?></div>
                <div><span class="text-zinc-400">Full Name:</span> <?= htmlspecialchars((string) ($user['full_name'] ?? '')) ?></div>
                <div><span class="text-zinc-400">Email:</span> <?= htmlspecialchars((string) ($user['email'] ?? '')) ?></div>
            </div>
        </div>
    </div>
</body>
</html>

