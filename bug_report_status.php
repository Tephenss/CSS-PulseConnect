<?php
declare(strict_types=1);
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="theme-color" content="#4f1014">
  <title>Check Report Status | PulseCONNECT</title>
  <style>
    :root { color-scheme:light; --wine:#4f1014; --wine-deep:#2d090c; --gold:#efbb59; --ink:#241c1b; --muted:#756a66; --paper:#fffdfa; }
    * { box-sizing:border-box; }
    body { min-height:100vh; margin:0; padding:28px 14px 44px; color:var(--ink); background:radial-gradient(ellipse at 12% 5%,rgba(239,187,89,.2),transparent 30rem),radial-gradient(ellipse at 92% 90%,rgba(154,44,37,.25),transparent 34rem),linear-gradient(135deg,#2d090c,#63181b 54%,#351014); font-family:"Segoe UI",system-ui,sans-serif; }
    .shell { width:min(100%,700px); margin:0 auto; }
    .brand { display:flex; align-items:center; gap:12px; margin:0 0 23px 2px; color:#fff; }
    .brand-mark { display:grid; width:44px; height:44px; place-items:center; border:1px solid rgba(255,255,255,.25); border-radius:14px; background:rgba(255,255,255,.08); color:#f1c36c; font-family:Georgia,serif; font-size:16px; font-weight:800; }
    .brand strong { display:block; font-size:14px; }
    .brand small { display:block; margin-top:3px; color:#f1c36c; font-size:9px; font-weight:800; letter-spacing:.16em; text-transform:uppercase; }
    .card { overflow:hidden; border:1px solid rgba(255,255,255,.58); border-radius:24px; background:var(--paper); box-shadow:0 28px 80px rgba(12,3,4,.35); }
    .hero { padding:29px clamp(21px,6vw,43px) 25px; color:#fff; background:linear-gradient(115deg,rgba(46,8,11,.97),rgba(102,25,28,.95)); }
    .eyebrow { color:var(--gold); font-size:10px; font-weight:850; letter-spacing:.19em; text-transform:uppercase; }
    h1 { margin:9px 0 7px; font-family:Georgia,"Times New Roman",serif; font-size:clamp(30px,6vw,42px); font-weight:500; letter-spacing:-.035em; line-height:1.08; }
    .hero p { max-width:520px; margin:0; color:#f1e5de; font-size:13px; line-height:1.65; }
    .content { padding:25px clamp(21px,6vw,43px) 31px; }
    .tutorial { margin-bottom:23px; padding:15px 16px; border:1px solid #eadfcf; border-radius:14px; background:linear-gradient(120deg,#fff8eb,#fffdfa); }
    .tutorial h2 { margin:0 0 10px; color:#5a3323; font-size:13px; }
    .steps { display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); gap:12px; margin:0; padding:0; list-style:none; counter-reset:steps; }
    .steps li { color:#73635b; font-size:11px; line-height:1.55; counter-increment:steps; }
    .steps li:before { content:counter(steps) ". "; color:#a54a2c; font-weight:850; }
    .tutorial-note { margin:10px 0 0; color:#8b7a70; font-size:10px; line-height:1.5; }
    label { display:block; margin-bottom:8px; font-size:12px; font-weight:800; }
    .field-row { display:flex; gap:9px; }
    input { min-width:0; flex:1; height:47px; padding:0 13px; border:1px solid #ded6d1; border-radius:12px; outline:none; color:#183b53; background:#fff; font:800 14px Consolas,monospace; letter-spacing:.04em; }
    input:focus { border-color:#a44632; box-shadow:0 0 0 4px rgba(164,70,50,.12); }
    input::placeholder { color:#a59b96; font:500 12px "Segoe UI",system-ui,sans-serif; letter-spacing:0; }
    .lookup { flex:0 0 auto; min-height:47px; padding:0 16px; border:0; border-radius:12px; color:#fff; background:linear-gradient(100deg,#b34125,#8e1f25); cursor:pointer; font:750 12px "Segoe UI",system-ui,sans-serif; }
    .lookup:disabled { cursor:wait; opacity:.65; }
    .message { min-height:21px; margin:11px 1px 0; color:#655a56; font-size:12px; line-height:1.5; }
    .message.error { color:#a42323; }
    .result { display:none; margin-top:19px; overflow:hidden; border:1px solid #e8dfda; border-radius:15px; background:#fff; }
    .result.visible { display:block; }
    .result-head { display:flex; align-items:center; justify-content:space-between; gap:12px; padding:16px; border-bottom:1px solid #eee8e5; background:#fcfaf7; }
    .result-head small { display:block; margin-bottom:5px; color:#8a7e78; font-size:9px; font-weight:850; letter-spacing:.13em; text-transform:uppercase; }
    .result-head code { color:#183b53; font:800 14px Consolas,monospace; letter-spacing:.025em; overflow-wrap:anywhere; }
    .badge { flex:0 0 auto; padding:7px 10px; border-radius:999px; font-size:9px; font-weight:850; letter-spacing:.08em; text-transform:uppercase; }
    .badge.new { color:#815710; background:#fff0c7; }
    .badge.reviewing { color:#255a7c; background:#dff1fc; }
    .badge.resolved { color:#216340; background:#dcf4e4; }
    .result-body { padding:17px; }
    .dates { display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:17px; }
    .date-card { padding:11px 12px; border:1px solid #eee8e5; border-radius:11px; background:#faf8f5; }
    .date-card span { display:block; margin-bottom:5px; color:#938681; font-size:9px; font-weight:800; letter-spacing:.09em; text-transform:uppercase; }
    .date-card strong { font-size:11px; font-weight:700; }
    .update-label { margin:0 0 8px; color:#857875; font-size:10px; font-weight:850; letter-spacing:.13em; text-transform:uppercase; }
    .public-update { margin:0; padding:13px; border:1px solid #e4edf3; border-radius:11px; color:#3d4f58; background:#f5fafc; font-size:13px; line-height:1.7; white-space:pre-wrap; overflow-wrap:anywhere; }
    .no-update { color:#7e7773; background:#faf8f5; border-color:#eee8e5; }
    .back { display:block; margin-top:17px; color:#82452f; text-align:center; font-size:11px; font-weight:750; text-decoration:none; }
    .back:hover { text-decoration:underline; }
    @media(max-width:560px) { body { padding:18px 10px 28px; } .card { border-radius:20px; } .hero { padding-top:24px; } .steps { grid-template-columns:1fr; gap:7px; } .field-row { flex-direction:column; } .lookup { width:100%; } .result-head { align-items:flex-start; flex-direction:column; } .dates { grid-template-columns:1fr; } }
    @media(prefers-reduced-motion:reduce) { *,*:before,*:after { scroll-behavior:auto!important; transition-duration:.01ms!important; } }
  </style>
</head>
<body>
  <main class="shell">
    <div class="brand"><div class="brand-mark" aria-hidden="true">PC</div><div><strong>PulseCONNECT</strong><small>Issue ticket lookup</small></div></div>
    <section class="card" aria-labelledby="page-title">
      <header class="hero"><div class="eyebrow">Issue desk</div><h1 id="page-title">Check your report</h1><p>Enter the ticket code you received after submitting your issue to see its latest status and any update from the review team.</p></header>
      <div class="content">
        <aside class="tutorial" aria-label="Ticket lookup tutorial">
          <h2>Quick guide</h2>
          <ol class="steps"><li>Find the ticket code shown after you sent your report.</li><li>Enter the full code below and select Check status.</li><li>Review the status and the latest message shared by the team.</li></ol>
          <p class="tutorial-note">New means received; Reviewing means the team is checking it; Resolved means the issue was addressed. Save your code to check again later. There are no automatic email or text notifications.</p>
        </aside>
        <form id="ticketForm" novalidate>
          <label for="ticketCodeInput">Your ticket code</label>
          <div class="field-row"><input id="ticketCodeInput" name="ticket_code" type="text" inputmode="text" autocomplete="off" spellcheck="false" maxlength="32" placeholder="PC-ABCD-EF01-2345-6789-ABCD-EF01" required><button id="lookupButton" class="lookup" type="submit">Check status</button></div>
          <p id="lookupMessage" class="message" role="status" aria-live="polite"></p>
        </form>
        <section id="ticketResult" class="result" aria-live="polite" aria-label="Issue report status">
          <div class="result-head"><div><small>Ticket</small><code id="resultTicket"></code></div><span id="resultStatus" class="badge"></span></div>
          <div class="result-body">
            <div class="dates"><div class="date-card"><span>Submitted</span><strong id="createdAt"></strong></div><div class="date-card"><span>Last updated</span><strong id="updatedAt"></strong></div></div>
            <p class="update-label">Latest update from the team</p><p id="publicUpdate" class="public-update"></p>
          </div>
        </section>
        <a class="back" href="/bug_report.php">← Back to report an issue</a>
      </div>
    </section>
  </main>
  <script>
    (() => {
      const form = document.getElementById('ticketForm');
      const input = document.getElementById('ticketCodeInput');
      const button = document.getElementById('lookupButton');
      const message = document.getElementById('lookupMessage');
      const result = document.getElementById('ticketResult');
      const labels = { new: 'New', reviewing: 'Reviewing', resolved: 'Resolved' };

      function showMessage(text, isError = false) {
        message.textContent = text;
        message.className = `message${isError ? ' error' : ''}`;
      }

      function formatDate(value) {
        const date = new Date(value || '');
        return Number.isNaN(date.getTime()) ? 'Not available' : date.toLocaleString('en-PH', { dateStyle: 'medium', timeStyle: 'short' });
      }

      form.addEventListener('submit', async (event) => {
        event.preventDefault();
        const ticketCode = input.value.trim();
        if (!ticketCode) {
          showMessage('Enter the ticket code from your submission confirmation.', true);
          input.focus();
          return;
        }
        button.disabled = true;
        result.classList.remove('visible');
        showMessage('Looking up your ticket…');
        try {
          const response = await fetch('/api/bug_report_status.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ ticket_code: ticketCode }),
          });
          const data = await response.json();
          if (!response.ok || !data.ok) throw new Error(data.error || 'Could not look up this ticket. Try again.');
          const ticket = data.ticket;
          document.getElementById('resultTicket').textContent = ticket.ticket_code;
          const badge = document.getElementById('resultStatus');
          badge.textContent = labels[ticket.status] || labels.new;
          badge.className = `badge ${ticket.status}`;
          document.getElementById('createdAt').textContent = formatDate(ticket.created_at);
          document.getElementById('updatedAt').textContent = formatDate(ticket.updated_at);
          const update = document.getElementById('publicUpdate');
          update.textContent = ticket.public_update || 'The team has not posted a public update yet. Please check again later.';
          update.classList.toggle('no-update', !ticket.public_update);
          result.classList.add('visible');
          showMessage('Ticket found. Here is the latest progress.');
        } catch (error) {
          showMessage(error.message || 'Could not look up this ticket. Try again.', true);
        } finally {
          button.disabled = false;
        }
      });

      try {
        const pendingTicket = sessionStorage.getItem('pc_bug_ticket_to_check');
        if (pendingTicket) {
          sessionStorage.removeItem('pc_bug_ticket_to_check');
          input.value = pendingTicket;
          form.requestSubmit();
        }
      } catch {}
    })();
  </script>
</body>
</html>
