<?php
declare(strict_types=1);

session_start();

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/supabase.php';
require_once __DIR__ . '/includes/csrf.php';

// If already logged in, go to dashboard.
if (isset($_SESSION['user']) && is_array($_SESSION['user'])) {
    header('Location: home.php');
    exit;
}

// CSRF token (basic protection).
csrf_ensure_token();

$error = null;
$success = null;
$old = [
    'first_name' => '',
    'middle_name' => '',
    'last_name' => '',
    'suffix' => '',
    'course' => '',
    'email' => '',
    'section_id' => '',
];

$urlSec = rtrim(SUPABASE_URL, '/') . '/rest/v1/sections?select=id,name&order=name.asc';
$headersSec = [
    'Accept: application/json',
    'apikey: ' . SUPABASE_KEY,
    'Authorization: Bearer ' . SUPABASE_KEY,
];
$sections = [];
$resSections = supabase_request('GET', $urlSec, $headersSec);
if ($resSections['ok']) {
    $decoded = json_decode((string) $resSections['body'], true);
    $sections = is_array($decoded) ? $decoded : [];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $csrfToken = isset($_POST['csrf_token']) ? (string) $_POST['csrf_token'] : null;
        $expected = isset($_SESSION['csrf_token']) && is_string($_SESSION['csrf_token']) ? $_SESSION['csrf_token'] : '';
        if ($expected === '' || $csrfToken === null || $csrfToken === '' || !hash_equals($expected, $csrfToken)) {
            $error = 'Invalid request. Please refresh and try again.';
            throw new RuntimeException('Invalid CSRF token');
        }

        $firstName = isset($_POST['first_name']) ? clean_string((string) $_POST['first_name']) : '';
        $middleName = isset($_POST['middle_name']) ? clean_string((string) $_POST['middle_name']) : '';
        $lastName = isset($_POST['last_name']) ? clean_string((string) $_POST['last_name']) : '';
        $suffix = isset($_POST['suffix']) ? clean_string((string) $_POST['suffix']) : '';
        $course = isset($_POST['course']) ? strtoupper(clean_string((string) $_POST['course'])) : '';
        $email = isset($_POST['email']) ? strtolower(clean_string((string) $_POST['email'])) : '';
        $sectionId = isset($_POST['section_id']) ? (string) $_POST['section_id'] : '';
        $password = isset($_POST['password']) ? (string) $_POST['password'] : '';

        $old['first_name'] = $firstName;
        $old['middle_name'] = $middleName;
        $old['last_name'] = $lastName;
        $old['suffix'] = $suffix;
        $old['course'] = $course;
        $old['email'] = $email;
        $old['section_id'] = $sectionId;

        // Basic validation
        if ($firstName === '' || mb_strlen($firstName) < 2 || mb_strlen($firstName) > 60) {
            $error = 'Please enter a valid first name.';
        } elseif ($lastName === '' || mb_strlen($lastName) < 2 || mb_strlen($lastName) > 60) {
            $error = 'Please enter a valid last name.';
        } elseif ($middleName !== '' && mb_strlen($middleName) > 60) {
            $error = 'Middle name is too long.';
        } elseif ($suffix !== '' && mb_strlen($suffix) > 30) {
            $error = 'Suffix is too long.';
        } elseif ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Please enter a valid email address.';
        } elseif (!in_array($course, ['IT', 'CS'], true)) {
            $error = 'Please select your course (IT or CS).';
        } elseif ($sectionId === '') {
            $error = 'Please select your section.';
        } elseif (mb_strlen($password) < 8) {
            $error = 'Password must be at least 8 characters.';
        } else {
            $passwordHash = password_hash($password, PASSWORD_DEFAULT);

            $payload = [
                'first_name' => $firstName,
                'middle_name' => $middleName !== '' ? $middleName : null,
                'last_name' => $lastName,
                'suffix' => $suffix !== '' ? $suffix : null,
                'course' => $course,
                'email' => $email,
                'password' => $passwordHash,
                'section_id' => $sectionId !== '' ? $sectionId : null,
                // Role strategy: new accounts are students by default.
                'role' => 'student',
            ];

            // Return the inserted row so we can show meaningful errors if something fails.
            $url = rtrim(SUPABASE_URL, '/') . '/rest/v1/' . SUPABASE_TABLE_USERS . '?select=id,first_name,middle_name,last_name,suffix,email,role';

            $headers = [
                'Content-Type: application/json',
                'Accept: application/json',
                'apikey: ' . SUPABASE_KEY,
                'Authorization: Bearer ' . SUPABASE_KEY,
                'Prefer: return=representation',
            ];

            $res = supabase_request('POST', $url, $headers, json_encode([$payload], JSON_UNESCAPED_SLASHES));

            if (!$res['ok']) {
                $error = build_error(
                    $res['body'] ?? null,
                    (int) ($res['status'] ?? 0),
                    $res['error'] ?? null,
                    'Registration failed'
                );
            } else {
                $success = 'Account created successfully. You can now log in.';
            }
        }
    } catch (Throwable $e) {
        if ($error === null) {
            $error = 'Registration failed. Please try again.';
        }
    }
}
?>

<!doctype html>
<html lang="en" class="dark">

<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>CCS PulseConnect — Register</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="/assets/css/app.css" />
    <link rel="stylesheet" href="/assets/css/auth.css" />
</head>

<body class="min-h-screen bg-zinc-950 text-zinc-100 auth-login-bg">
    <div class="interactive-bg"></div>
    <div class="min-h-screen grid grid-cols-1 lg:grid-cols-2 relative z-10">
        <div class="hidden lg:flex items-center justify-center p-10">
            <div class="max-w-md w-full">
                <div class="logo-collision-wrapper">
                    <img src="/assets/BSIT.png" alt="BSIT" class="logo-bsit" />
                    <img src="/assets/CS.png" alt="CS" class="logo-cs" />
                    <div class="collision-flash"></div>
                    <img src="/assets/CCS.png" alt="CCS" class="logo-ccs" />
                </div>
                <div class="text-center mt-6">
                    <div class="text-xs tracking-[0.35em] uppercase text-zinc-400 font-bold">PulseCONNECT</div>
                    <h1 class="text-3xl font-semibold mt-2 leading-tight">Create your account</h1>
                    <p class="text-zinc-400 mt-4 text-sm leading-relaxed">
                        Students can register for events and get tickets. Teachers and Admin manage events, scanning,
                        and analytics.
                    </p>
                </div>
                <div
                    class="mt-8 rounded-2xl border border-zinc-800 bg-zinc-900/20 p-5 text-sm text-zinc-300 text-center">
                    Tip: New registrations are <span class="text-zinc-100 font-medium">Student</span> by default.
                </div>
            </div>
        </div>

        <div class="flex justify-center p-6 h-[100dvh] overflow-y-auto">
            <div class="w-full max-w-md my-auto py-10">
                <div class="mb-6">
                    <div class="text-xs tracking-[0.35em] uppercase text-zinc-400">Get started</div>
                    <h2 class="text-3xl font-semibold mt-3">Register</h2>
                    <p class="text-zinc-400 mt-2 text-sm">Fill in your details to continue.</p>
                </div>

                <?php if ($error): ?>
                    <div class="mb-4 rounded-xl border border-red-900/50 bg-red-950/30 px-4 py-3 text-sm text-red-200">
                        <?= htmlspecialchars($error) ?>
                    </div>
                <?php endif; ?>

                <?php if ($success): ?>
                    <div
                        class="mb-4 rounded-xl border border-emerald-900/50 bg-emerald-950/30 px-4 py-3 text-sm text-emerald-200">
                        <?= htmlspecialchars($success) ?>
                    </div>
                    <a href="login.php"
                        class="inline-flex items-center justify-center w-full rounded-xl bg-zinc-100 text-zinc-900 px-4 py-3 font-medium hover:bg-zinc-200 transition">
                        Go to login
                    </a>
                <?php else: ?>
                    <form method="POST" class="rounded-2xl border border-zinc-800 bg-zinc-900/30 p-6">
                        <input type="hidden" name="csrf_token"
                            value="<?= htmlspecialchars((string) $_SESSION['csrf_token']) ?>" />

                        <label class="block text-xs text-zinc-400 mb-1" for="first_name">First Name</label>
                        <input id="first_name" name="first_name" type="text"
                            value="<?= htmlspecialchars((string) $old['first_name']) ?>" required
                            class="w-full rounded-xl bg-zinc-950 border border-zinc-800 px-3 py-3 text-sm outline-none focus:ring-2 focus:ring-zinc-700"
                            placeholder="Enter Your First Name" autocomplete="given-name" />

                        <div class="h-4"></div>

                        <label class="block text-xs text-zinc-400 mb-1" for="middle_name">Middle Name <span
                                class="text-zinc-500">(optional)</span></label>
                        <input id="middle_name" name="middle_name" type="text"
                            value="<?= htmlspecialchars((string) $old['middle_name']) ?>"
                            class="w-full rounded-xl bg-zinc-950 border border-zinc-800 px-3 py-3 text-sm outline-none focus:ring-2 focus:ring-zinc-700"
                            placeholder="Enter Your Middle Name" autocomplete="additional-name" />

                        <div class="h-4"></div>

                        <label class="block text-xs text-zinc-400 mb-1" for="last_name">Last Name</label>
                        <input id="last_name" name="last_name" type="text"
                            value="<?= htmlspecialchars((string) $old['last_name']) ?>" required
                            class="w-full rounded-xl bg-zinc-950 border border-zinc-800 px-3 py-3 text-sm outline-none focus:ring-2 focus:ring-zinc-700"
                            placeholder="Enter Your Last Name" autocomplete="family-name" />

                        <div class="h-5"></div>

                        <label class="block text-xs text-zinc-400 mb-1" for="suffix">Suffix <span
                                class="text-zinc-500">(optional)</span></label>
                        <input id="suffix" name="suffix" type="text"
                            value="<?= htmlspecialchars((string) $old['suffix']) ?>"
                            class="w-full rounded-xl bg-zinc-950 border border-zinc-800 px-3 py-3 text-sm outline-none focus:ring-2 focus:ring-zinc-700"
                            placeholder="Jr., Sr., III..." autocomplete="off" />

                        <div class="h-4"></div>

                        <label class="block text-xs text-zinc-400 mb-1" for="email">Email</label>
                        <input id="email" name="email" type="email" value="<?= htmlspecialchars((string) $old['email']) ?>"
                            required
                            class="w-full rounded-xl bg-zinc-950 border border-zinc-800 px-3 py-3 text-sm outline-none focus:ring-2 focus:ring-zinc-700"
                            placeholder="name@email.com" autocomplete="email" />

                        <div class="h-4"></div>

                        <label class="block text-xs text-zinc-400 mb-1" for="course">Course</label>
                        <select id="course" name="course" required
                            class="w-full rounded-xl bg-zinc-950 border border-zinc-800 px-3 py-3 text-sm outline-none focus:ring-2 focus:ring-zinc-700 text-zinc-100">
                            <option value="" class="text-zinc-500">Select Course</option>
                            <option value="IT" <?= $old['course'] === 'IT' ? 'selected' : '' ?>>BSIT (IT)</option>
                            <option value="CS" <?= $old['course'] === 'CS' ? 'selected' : '' ?>>BSCS (CS)</option>
                        </select>

                        <div class="h-4"></div>

                        <label class="block text-xs text-zinc-400 mb-1" for="section_id">Section</label>
                        <select id="section_id" name="section_id" required
                            class="w-full rounded-xl bg-zinc-950 border border-zinc-800 px-3 py-3 text-sm outline-none focus:ring-2 focus:ring-zinc-700 text-zinc-100">
                            <option value="" class="text-zinc-500">Select Section</option>
                            <?php foreach ($sections as $sec): ?>
                                <option value="<?= htmlspecialchars((string) ($sec['id'] ?? '')) ?>"
                                    <?= $old['section_id'] === ($sec['id'] ?? '') ? 'selected' : '' ?>>
                                    <?= htmlspecialchars((string) ($sec['name'] ?? '')) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>

                        <div class="h-4"></div>

                        <label class="block text-xs text-zinc-400 mb-1" for="password">Password</label>
                        <input id="password" name="password" type="password" required minlength="8"
                            class="w-full rounded-xl bg-zinc-950 border border-zinc-800 px-3 py-3 text-sm outline-none focus:ring-2 focus:ring-zinc-700"
                            placeholder="Minimum 8 characters" autocomplete="new-password" />

                        <div class="h-5"></div>

                        <button type="submit"
                            class="w-full rounded-xl bg-zinc-100 text-zinc-900 px-4 py-3 font-medium hover:bg-zinc-200 transition">
                            Register
                        </button>

                        <div class="text-center text-xs text-zinc-400 mt-4">
                            Already have an account?
                            <a class="text-zinc-200 hover:underline" href="login.php">Login</a>
                        </div>
                    </form>
                <?php endif; ?>

                <div class="text-center text-xs text-zinc-500 mt-6">
                    © <?= htmlspecialchars((string) date('Y')) ?> PulseCONNECT
                </div>
            </div>
        </div>
    </div>
    <script>
        let mouseTimeout;
        let targetX = typeof window !== 'undefined' ? window.innerWidth / 2 : 0;
        let targetY = typeof window !== 'undefined' ? window.innerHeight / 2 : 0;
        let currentX = targetX, currentY = targetY;

        document.addEventListener('mousemove', function (e) {
            targetX = e.clientX;
            targetY = e.clientY;

            const bg = document.querySelector('.interactive-bg');
            if (bg) {
                bg.style.opacity = '0.85'; // Show on move

                clearTimeout(mouseTimeout);
                mouseTimeout = setTimeout(() => {
                    bg.style.opacity = '0'; // Hide after stop
                }, 500);
            }
        });

        document.addEventListener('mouseleave', function () {
            const bg = document.querySelector('.interactive-bg');
            if (bg) bg.style.opacity = '0';
        });

        function animateBg() {
            // Linear Interpolation for smooth trailing delay
            currentX += (targetX - currentX) * 0.06;
            currentY += (targetY - currentY) * 0.06;

            const bg = document.querySelector('.interactive-bg');
            if (bg) {
                bg.style.setProperty('--mouse-x', currentX + 'px');
                bg.style.setProperty('--mouse-y', currentY + 'px');
            }

            requestAnimationFrame(animateBg);
        }
        animateBg();
    </script>
</body>

</html>