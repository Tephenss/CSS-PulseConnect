<?php
declare(strict_types=1);
header('Content-Type: text/html; charset=utf-8');
$updated = 'July 26, 2026';
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <?php require_once __DIR__ . '/includes/favicon.php'; render_favicon_tags(); ?>
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Account Deletion — PulseCONNECT</title>
  <style>
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
    h1 { font-size: 1.85rem; margin: 0 0 0.35rem; }
    h2 { font-size: 1.15rem; margin: 2rem 0 0.6rem; }
    p, li { color: #3f3f46; }
    .meta { color: #71717a; font-size: 0.95rem; margin-bottom: 1.75rem; }
    a { color: #c2410c; }
  </style>
</head>
<body>
<main>
  <h1>Account &amp; Data Deletion</h1>
  <p class="meta">PulseCONNECT · Last updated: <?= htmlspecialchars($updated) ?></p>

  <p>
    This page explains how users of the PulseCONNECT mobile app can request deletion
    of their account and associated personal data.
  </p>

  <h2>How to request deletion</h2>
  <ol>
    <li>Sign in to PulseCONNECT (or use the email linked to your account).</li>
    <li>Email your school / CCS PulseCONNECT administrator from that same account email.</li>
    <li>Use the subject line: <strong>PulseCONNECT account deletion request</strong>.</li>
    <li>Include your full name, role (student/teacher), and the email used in the app.</li>
  </ol>
  <p>
    You may also submit the request through your official school support channel for
    PulseCONNECT, if your campus provides one.
  </p>

  <h2>What gets deleted</h2>
  <p>Upon verification, we process deletion of account profile data such as:</p>
  <ul>
    <li>Account login identifiers (for example email linked to the account)</li>
    <li>Profile details stored for the account</li>
    <li>Push notification tokens tied to the account</li>
    <li>Session / trusted-device records for the account where applicable</li>
  </ul>

  <h2>What may be retained</h2>
  <p>
    Some records may be kept for a limited period when required for school operations,
    security, audit, or legal obligations (for example historical attendance or
    registration records tied to completed campus events). Where retention applies,
    data is limited to what is necessary and kept according to school policy.
  </p>

  <h2>Timeline</h2>
  <p>
    After we verify your request, we aim to complete account deletion within
    <strong>30 days</strong>. You will receive confirmation through your school admin
    / support channel when processing is complete.
  </p>

  <h2>Contact</h2>
  <p>
    For help with deletion requests, contact your CCS administrator or the official
    PulseCONNECT support channel provided by your school.
  </p>
</main>
</body>
</html>
