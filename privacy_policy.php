<?php
declare(strict_types=1);
header('Content-Type: text/html; charset=utf-8');
$updated = 'July 26, 2026';
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Privacy Policy — PulseCONNECT</title>
  <?php require_once __DIR__ . '/includes/favicon.php'; render_favicon_tags(); ?>
  <style>
    :root { color-scheme: light; }
    body {
      margin: 0;
      font-family: Georgia, "Times New Roman", serif;
      background: #fafafa;
      color: #18181b;
      line-height: 1.65;
    }
    main {
      max-width: 42rem;
      margin: 0 auto;
      padding: 2.5rem 1.25rem 4rem;
    }
    h1 { font-size: 1.85rem; margin: 0 0 0.35rem; letter-spacing: -0.02em; }
    h2 { font-size: 1.15rem; margin: 2rem 0 0.6rem; }
    p, li { font-size: 1rem; color: #3f3f46; }
    .meta { color: #71717a; font-size: 0.95rem; margin-bottom: 1.75rem; }
    ul { padding-left: 1.2rem; }
    a { color: #c2410c; }
  </style>
</head>
<body>
<main>
  <h1>Privacy Policy</h1>
  <p class="meta">PulseCONNECT · Last updated: <?= htmlspecialchars($updated) ?></p>

  <p>
    PulseCONNECT (“the App”, “we”, “us”) is a campus events and attendance companion
    used by students, teachers, and staff. This policy explains what information we
    collect, how we use it, and your choices.
  </p>

  <h2>1. Information we collect</h2>
  <ul>
    <li><strong>Account information</strong> such as name, email, role (student/teacher/admin), and school-related profile fields needed for login and event access.</li>
    <li><strong>Event activity</strong> such as registrations, tickets, attendance check-ins, and related submissions required by an event.</li>
    <li><strong>Device and app data</strong> such as push notification tokens, basic device identifiers used for trusted-device / security flows, and technical logs needed to keep the service working.</li>
    <li><strong>Media you choose to provide</strong> such as profile photos or documents required for an event (for example proposal or student requirement uploads).</li>
    <li><strong>Camera access</strong> when you scan QR codes for attendance. Camera frames are used for scanning on your device; we do not use the camera for unrelated advertising.</li>
  </ul>

  <h2>2. How we use information</h2>
  <ul>
    <li>To authenticate users and protect accounts.</li>
    <li>To run campus events: registration, tickets, attendance, approvals, and related school workflows.</li>
    <li>To send service notifications (for example event updates) when notifications are enabled.</li>
    <li>To maintain security, prevent abuse, and troubleshoot technical issues.</li>
    <li>To improve reliability and performance of the App and website.</li>
  </ul>

  <h2>3. How we share information</h2>
  <p>
    We do not sell personal information. Information may be processed by trusted infrastructure
    providers that host or deliver the service (for example database, file storage, email, and
    push notification providers) only as needed to operate PulseCONNECT for your school.
    School administrators and authorized staff may access information required for official
    campus operations.
  </p>

  <h2>4. Data retention</h2>
  <p>
    We retain information for as long as needed to provide the service, meet school
    operational needs, and comply with applicable requirements. When data is no longer
    needed, it may be deleted or de-identified according to school and system practices.
  </p>

  <h2>5. Security</h2>
  <p>
    We use reasonable technical and organizational measures to protect information,
    including encrypted transport (HTTPS) and server-side access controls for sensitive
    operations. No method of transmission or storage is 100% secure.
  </p>

  <h2>6. Your choices</h2>
  <ul>
    <li>You may update certain profile details in the App where available.</li>
    <li>You can control notification permissions in your device settings.</li>
    <li>Camera permission can be denied; QR check-in features will not work without it.</li>
    <li>For account or data requests, contact your school admin / PulseCONNECT support channel.</li>
  </ul>

  <h2>7. Children’s privacy</h2>
  <p>
    The App is intended for use in a school context by authorized students and staff.
    It is not directed at children under 13 for general consumer use outside school
    administration policies.
  </p>

  <h2>8. Changes</h2>
  <p>
    We may update this Privacy Policy from time to time. The “Last updated” date above
    will change when we do. Continued use of the App after updates means you acknowledge
    the revised policy.
  </p>

  <h2>9. Contact</h2>
  <p>
    For privacy questions about PulseCONNECT, contact your CCS administrator or the
    support channel provided by your school for this system.
  </p>
</main>
</body>
</html>
