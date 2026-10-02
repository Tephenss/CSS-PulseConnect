<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bug_reporting_security.php';
bug_report_session_start();
require_once __DIR__ . '/includes/csrf.php';
$csrfToken = csrf_ensure_token();
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="theme-color" content="#4f1014">
  <title>Report an Issue | PulseCONNECT</title>
  <style>
    :root { color-scheme: light; --wine: #4f1014; --wine-deep: #2d090c; --gold: #efbb59; --ink: #221b1a; --muted: #716764; --paper: #fffdfa; }
    * { box-sizing: border-box; }
    body { min-height: 100vh; margin: 0; color: var(--ink); font-family: "Segoe UI", system-ui, sans-serif; background: radial-gradient(ellipse at 15% 8%, rgba(239,187,89,.22), transparent 31rem), radial-gradient(ellipse at 95% 88%, rgba(154,44,37,.24), transparent 34rem), linear-gradient(135deg, #2d090c, #63181b 54%, #351014); }
    .shell { width: min(100% - 32px, 760px); margin: 0 auto; padding: 32px 0 44px; }
    .brand { display: flex; align-items: center; gap: 12px; color: white; margin: 0 0 30px 2px; }
    .brand-mark { display:grid; width:46px; height:46px; place-items:center; border:1px solid rgba(255,255,255,.25); border-radius:15px; background:linear-gradient(145deg,rgba(255,255,255,.17),rgba(255,255,255,.06)); color:#f1c36c; font-family:Georgia,serif; font-size:18px; font-weight:700; }
    .brand strong { display: block; font-size: 15px; letter-spacing: -.02em; }
    .brand span { display: block; margin-top: 2px; color: #f1c36c; font-size: 10px; font-weight: 700; letter-spacing: .16em; text-transform: uppercase; }
    .card { overflow: hidden; border: 1px solid rgba(255,255,255,.58); border-radius: 26px; background: var(--paper); box-shadow: 0 28px 80px rgba(12,3,4,.35); }
    .hero { position: relative; padding: 30px clamp(22px, 6vw, 48px) 25px; overflow: hidden; color: white; background: linear-gradient(115deg, rgba(46,8,11,.97), rgba(102,25,28,.95)), var(--wine); }
    .hero:after { position: absolute; right: -48px; bottom: -125px; width: 260px; height: 260px; border: 1px solid rgba(239,187,89,.25); border-radius: 50%; box-shadow: 0 0 0 22px rgba(239,187,89,.04), 0 0 0 48px rgba(239,187,89,.035); content: ""; }
    .eyebrow { position: relative; z-index: 1; color: var(--gold); font-size: 11px; font-weight: 800; letter-spacing: .18em; text-transform: uppercase; }
    h1 { position: relative; z-index: 1; margin: 9px 0 7px; font-family: Georgia, "Times New Roman", serif; font-size: clamp(30px, 6vw, 44px); font-weight: 500; letter-spacing: -.035em; line-height: 1.05; }
    .hero p { position: relative; z-index: 1; max-width: 540px; margin: 0; color: #f1e5de; font-size: 14px; line-height: 1.6; }
    form { padding: 28px clamp(22px, 6vw, 48px) 34px; }
    .privacy { display: flex; align-items: flex-start; gap: 10px; margin: 0 0 22px; padding: 13px 14px; border: 1px solid #f0dfb8; border-radius: 14px; background: #fff8e9; color: #63491e; font-size: 12px; line-height: 1.5; }
    .privacy svg { flex: 0 0 17px; margin-top: 1px; }
    label.title { display: block; margin-bottom: 8px; font-size: 13px; font-weight: 750; }
    textarea { display: block; width: 100%; min-height: 190px; resize: vertical; padding: 15px 16px; border: 1px solid #ded6d1; border-radius: 15px; outline: none; background: white; color: var(--ink); font: inherit; font-size: 14px; line-height: 1.6; transition: border-color .18s, box-shadow .18s; }
    textarea:focus { border-color: #a44632; box-shadow: 0 0 0 4px rgba(164,70,50,.12); }
    textarea::placeholder { color: #a59b96; }
    .field-foot { display: flex; justify-content: space-between; gap: 12px; margin: 7px 2px 21px; color: var(--muted); font-size: 11px; }
    .upload-box { padding: 15px; border: 1px dashed #cfc4bd; border-radius: 16px; background: #fff; }
    .upload-row { display: flex; align-items: center; justify-content: space-between; gap: 16px; }
    .upload-copy strong { display: block; font-size: 13px; }
    .upload-copy span { display: block; margin-top: 4px; color: var(--muted); font-size: 11px; line-height: 1.45; }
    .choose { flex: 0 0 auto; display: inline-flex; align-items: center; gap: 8px; min-height: 40px; padding: 0 13px; border: 1px solid #ead5bd; border-radius: 11px; background: #fff7eb; color: #763421; cursor: pointer; font: inherit; font-size: 12px; font-weight: 750; transition: background .15s, transform .15s; }
    .choose:hover { transform: translateY(-1px); background: #ffefd8; }
    .preview { display: none; margin-top: 13px; overflow: hidden; border: 1px solid #e8dfda; border-radius: 12px; background: #f4f0ed; }
    .preview.visible { display: block; }
    .preview-gallery { display:grid; grid-template-columns:repeat(auto-fill,minmax(120px,1fr)); gap:9px; padding:10px; }
    .preview-card { position:relative; min-width:0; overflow:hidden; border:1px solid #e8dfda; border-radius:9px; background:#f4f0ed; }
    .preview-card img { display:block; width:100%; height:125px; object-fit:contain; }
    .preview button { position:absolute; top:6px; right:6px; padding:6px 8px; border:0; border-radius:8px; background:rgba(38,22,20,.88); color:white; cursor:pointer; font:inherit; font-size:10px; font-weight:700; }
    .status { min-height: 22px; margin: 14px 1px 0; color: #655a56; font-size: 12px; line-height: 1.5; }
    .status.error { color: #a42323; }
    .status.success { padding: 12px 13px; border: 1px solid #a5ddbd; border-radius: 12px; background: #effbf3; color: #196438; }
    .tutorial { margin: 20px clamp(22px, 6vw, 48px) 0; padding: 15px 17px; border: 1px solid #eadfcf; border-radius: 15px; background: linear-gradient(120deg,#fff8eb,#fffdfa); }
    .tutorial h2 { margin: 0 0 8px; color: #5a3323; font-size: 13px; }
    .tutorial ol { display: grid; grid-template-columns: repeat(3,minmax(0,1fr)); gap: 12px; margin: 0; padding: 0; list-style: none; counter-reset: steps; }
    .tutorial li { color: #73635b; font-size: 11px; line-height: 1.5; counter-increment: steps; }
    .tutorial li:before { content: counter(steps) ". "; color: #a54a2c; font-weight: 850; }
    .tutorial-note { margin: 9px 0 0; color: #8b7a70; font-size: 10px; line-height: 1.45; }
    .ticket-result { display:none; margin-top:10px; padding:14px; border:1px solid #c5ddf0; border-radius:13px; background:#f1f8fd; }
    .ticket-result.visible { display:block; }
    .ticket-label { margin:0 0 7px; color:#526d7e; font-size:10px; font-weight:850; letter-spacing:.12em; text-transform:uppercase; }
    .ticket-code { color:#183b53; font:800 16px/1.4 Consolas,monospace; letter-spacing:.035em; overflow-wrap:anywhere; }
    .ticket-actions { display:flex; flex-wrap:wrap; gap:8px; margin-top:12px; }
    .ticket-actions button,.ticket-actions a { display:inline-flex; align-items:center; justify-content:center; min-height:36px; padding:0 11px; border:1px solid #c5dbea; border-radius:9px; background:#fff; color:#214d69; cursor:pointer; font:inherit; font-size:11px; font-weight:750; text-decoration:none; }
    .ticket-actions a { border-color:#245875; background:#245875; color:#fff; }
    .ticket-lookup { margin:0 clamp(22px,6vw,48px); padding:17px; border:1px solid #d8e4eb; border-radius:15px; background:linear-gradient(120deg,#f3f9fc,#fff); }
    .ticket-lookup h2 { margin:0 0 5px; color:#24475d; font-size:14px; }
    .ticket-lookup p { margin:0 0 12px; color:#6d7b82; font-size:11px; line-height:1.5; }
    .ticket-lookup-form { display:flex; gap:8px; }
    .ticket-lookup input { min-width:0; flex:1; height:40px; padding:0 11px; border:1px solid #d5e0e7; border-radius:10px; outline:none; color:#183b53; font:700 12px Consolas,monospace; }
    .ticket-lookup input:focus { border-color:#6494ac; box-shadow:0 0 0 3px rgba(76,133,162,.12); }
    .ticket-lookup-form button { flex:0 0 auto; min-height:40px; padding:0 13px; border:0; border-radius:10px; background:#245875; color:#fff; cursor:pointer; font:inherit; font-size:11px; font-weight:800; }
    .ticket-lookup-form button:disabled { opacity:.65; cursor:wait; }
    .lookup-message { min-height:0!important; margin:8px 1px 0!important; color:#58676e!important; font-size:11px!important; }
    .lookup-message.error { color:#a42323!important; }
    .lookup-result { display:none; margin-top:11px; padding:12px; border:1px solid #dae6ec; border-radius:11px; background:#fff; }
    .lookup-result.visible { display:block; }
    .lookup-result-head { display:flex; align-items:center; justify-content:space-between; gap:10px; }
    .lookup-result code { color:#183b53; font:750 11px Consolas,monospace; overflow-wrap:anywhere; }
    .lookup-badge { padding:5px 8px; border-radius:99px; font-size:9px; font-weight:850; text-transform:uppercase; }
    .lookup-badge.new { color:#815710; background:#fff0c7; }
    .lookup-badge.reviewing { color:#255a7c; background:#dff1fc; }
    .lookup-badge.resolved { color:#216340; background:#dcf4e4; }
    .lookup-update { margin:9px 0 0!important; padding:10px; border-radius:9px; background:#f5fafc; color:#3d4f58!important; white-space:pre-wrap; overflow-wrap:anywhere; }
    .submit-row { display: flex; justify-content: flex-end; margin-top: 14px; }
    .submit { display: inline-flex; align-items: center; justify-content: center; gap: 9px; min-width: 176px; min-height: 46px; padding: 0 18px; border: 0; border-radius: 13px; background: linear-gradient(100deg, #b34125, #8e1f25); box-shadow: 0 9px 22px rgba(142,31,37,.2); color: white; cursor: pointer; font: inherit; font-size: 13px; font-weight: 800; transition: transform .18s, box-shadow .18s; }
    .submit:hover:not(:disabled) { transform: translateY(-2px); box-shadow: 0 13px 25px rgba(142,31,37,.27); }
    .submit:disabled { cursor: wait; opacity: .66; }
    .footnote { padding: 0 18px 21px; color: #8a7e78; text-align: center; font-size: 11px; }
    .honeypot { position: absolute !important; left: -10000px !important; width: 1px !important; height: 1px !important; overflow: hidden !important; }
    @media (max-width: 520px) { .shell { width: min(100% - 20px, 760px); padding-top: 20px; } .brand { margin-bottom: 20px; } .card { border-radius: 21px; } .hero { padding-top: 25px; } form { padding-top: 22px; } .tutorial ol { grid-template-columns: 1fr; gap: 7px; } .ticket-lookup-form { align-items:stretch; flex-direction:column; } .upload-row { align-items: flex-start; flex-direction: column; } .choose { width: 100%; justify-content: center; } .ticket-actions button, .ticket-actions a { flex: 1 1 100%; } .submit-row, .submit { width: 100%; } }
    @media (prefers-reduced-motion: reduce) { *, *:before, *:after { scroll-behavior: auto !important; transition-duration: .01ms !important; } }
  </style>
</head>
<body>
  <main class="shell">
    <div class="brand" aria-label="PulseCONNECT">
      <div class="brand-mark" aria-hidden="true">PC</div>
      <span><strong>PulseCONNECT</strong><span>College of Computing Studies</span></span>
    </div>
    <section class="card" aria-labelledby="page-title">
      <header class="hero">
        <div class="eyebrow">Help us make it better</div>
        <h1 id="page-title">Report an issue</h1>
        <p>Tell us what went wrong. Add a screenshot if it helps us understand and reproduce the problem.</p>
      </header>
      <aside class="tutorial" aria-label="How issue tickets work">
        <h2>How to follow up on your report</h2>
        <ol><li>Send your issue report.</li><li>Save the ticket code shown after submission.</li><li>Enter that code in the ticket lookup at the bottom to see the latest progress.</li></ol>
        <p class="tutorial-note">Updates are not sent by email or text. Keep your ticket code so you can check back anytime.</p>
      </aside>
      <form id="bugReportForm" novalidate>
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
        <div class="honeypot" aria-hidden="true"><label>Leave this field empty<input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>
        <div class="privacy">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 3l8 3v5c0 5-3.4 8.5-8 10-4.6-1.5-8-5-8-10V6l8-3z"/><path stroke-linecap="round" stroke-linejoin="round" d="M8.8 12l2.1 2.1 4.5-4.6"/></svg>
          <span>No account details are needed. Please do not include passwords, OTPs, student numbers, or other sensitive information in your report or screenshot.</span>
        </div>
        <label class="title" for="message">What issue did you encounter?</label>
        <textarea id="message" name="message" minlength="10" maxlength="2500" required placeholder="Describe what you were doing, what you expected to happen, and what happened instead."></textarea>
        <div class="field-foot"><span>Be specific, but leave out private account information.</span><span id="charCount">0 / 2,500</span></div>
        <div class="upload-box">
          <div class="upload-row">
            <div class="upload-copy"><strong>Screenshots <span style="display:inline;color:#8d817b;font-weight:500">(optional)</span></strong><span>Add multiple JPG, PNG, or WEBP pictures. They are compressed automatically; combined size limit is 600 KB.</span></div>
            <label class="choose" for="reportImages"><span aria-hidden="true">＋</span> Add pictures</label>
          </div>
          <input id="reportImages" type="file" accept="image/jpeg,image/png,image/webp" multiple hidden>
          <div id="imagePreview" class="preview"><div id="previewGallery" class="preview-gallery"></div></div>
        </div>
        <div id="formStatus" class="status" role="status" aria-live="polite"></div>
        <section id="ticketResult" class="ticket-result" aria-live="polite" aria-label="Your issue ticket">
          <p class="ticket-label">Save this ticket code</p>
          <div><code id="ticketCode" class="ticket-code"></code></div>
          <div class="ticket-actions"><button id="copyTicket" type="button">Copy ticket code</button><a id="checkTicketLink" href="#ticketLookup">Check report status</a></div>
        </section>
        <div class="submit-row"><button id="submitReport" class="submit" type="submit">Send bug report <span aria-hidden="true">→</span></button></div>
      </form>
      <aside id="ticketLookup" class="ticket-lookup" aria-labelledby="ticket-lookup-heading">
        <h2 id="ticket-lookup-heading">Already sent a report?</h2>
        <p>Enter your ticket code here to see the status of your report.</p>
        <form id="ticketLookupForm" class="ticket-lookup-form" novalidate>
          <input id="ticketLookupInput" type="text" autocomplete="off" spellcheck="false" maxlength="32" aria-label="Ticket code" placeholder="PC-ABCD-EF01-2345-6789-ABCD-EF01" required>
          <button id="ticketLookupButton" type="submit">Check status</button>
        </form>
        <div id="ticketLookupMessage" class="lookup-message" role="status" aria-live="polite"></div>
        <div id="ticketLookupResult" class="lookup-result" aria-live="polite">
          <div class="lookup-result-head"><code id="lookupTicketCode"></code><span id="lookupTicketStatus" class="lookup-badge"></span></div>
          <p id="lookupTicketUpdate" class="lookup-update"></p>
        </div>
      </aside>
      <div class="footnote">Reports are reviewed by the PulseCONNECT team.</div>
    </section>
  </main>
  <script>
    const form = document.getElementById('bugReportForm');
    const messageInput = document.getElementById('message');
    const imageInput = document.getElementById('reportImages');
    const previewWrap = document.getElementById('imagePreview');
    const previewGallery = document.getElementById('previewGallery');
    const statusBox = document.getElementById('formStatus');
    const submitButton = document.getElementById('submitReport');
    const ticketResult = document.getElementById('ticketResult');
    const ticketCodeNode = document.getElementById('ticketCode');
    const maxStoredImageBytes = 600 * 1024;
    const maxSourceImageBytes = 12 * 1024 * 1024;
    let selectedImages = [];
    let previewUrls = [];

    function showStatus(message, kind = '') {
      statusBox.textContent = message;
      statusBox.className = `status${kind ? ` ${kind}` : ''}`;
    }

    function clearImages() {
      selectedImages = [];
      imageInput.value = '';
      previewUrls.forEach((url) => URL.revokeObjectURL(url));
      previewUrls = [];
      previewGallery.replaceChildren();
      previewWrap.classList.remove('visible');
    }

    function renderImagePreviews() {
      previewUrls.forEach((url) => URL.revokeObjectURL(url));
      previewUrls = [];
      previewGallery.replaceChildren();
      selectedImages.forEach((file, index) => {
        const card = document.createElement('div');
        card.className = 'preview-card';
        const image = document.createElement('img');
        const url = URL.createObjectURL(file);
        previewUrls.push(url);
        image.src = url;
        image.alt = `Attached screenshot ${index + 1}`;
        const remove = document.createElement('button');
        remove.type = 'button';
        remove.textContent = 'Remove';
        remove.setAttribute('aria-label', `Remove screenshot ${index + 1}`);
        remove.addEventListener('click', () => {
          selectedImages.splice(index, 1);
          renderImagePreviews();
          showStatus(selectedImages.length ? `${selectedImages.length} picture${selectedImages.length === 1 ? '' : 's'} attached.` : 'Pictures removed.');
        });
        card.append(image, remove);
        previewGallery.append(card);
      });
      previewWrap.classList.toggle('visible', selectedImages.length > 0);
    }

    messageInput.addEventListener('input', () => {
      document.getElementById('charCount').textContent = `${messageInput.value.length.toLocaleString()} / 2,500`;
    });

    imageInput.addEventListener('change', () => {
      const files = Array.from(imageInput.files || []);
      imageInput.value = '';
      if (files.length === 0) return;
      let rejected = '';
      for (const file of files) {
        if (!['image/jpeg', 'image/png', 'image/webp'].includes(file.type)) {
          rejected = 'Only JPG, PNG, and WEBP pictures are supported.';
          continue;
        }
        if (file.size > maxSourceImageBytes) {
          rejected = 'Each selected picture must be 12 MB or smaller.';
          continue;
        }
        const duplicate = selectedImages.some((item) => item.name === file.name && item.size === file.size && item.lastModified === file.lastModified);
        if (!duplicate) selectedImages.push(file);
      }
      renderImagePreviews();
      if (rejected) showStatus(rejected, 'error');
      else showStatus(`${selectedImages.length} picture${selectedImages.length === 1 ? '' : 's'} attached. You can add more before sending.`);
    });

    function canvasBlob(canvas, quality) {
      return new Promise((resolve) => canvas.toBlob(resolve, 'image/jpeg', quality));
    }

    async function compressScreenshot(file, targetBytes) {
      if (typeof createImageBitmap !== 'function') {
        throw new Error('This browser cannot prepare the screenshot. Try a recent version of Chrome, Edge, or Safari.');
      }
      const bitmap = await createImageBitmap(file);
      try {
        if (bitmap.width < 1 || bitmap.height < 1 || bitmap.width > 12000 || bitmap.height > 12000 || bitmap.width * bitmap.height > 50000000) {
          throw new Error('That image is too large to process. Try a regular screenshot instead.');
        }
        let scale = Math.min(1, 1600 / Math.max(bitmap.width, bitmap.height));
        for (let pass = 0; pass < 8; pass += 1) {
          const canvas = document.createElement('canvas');
          canvas.width = Math.max(1, Math.round(bitmap.width * scale));
          canvas.height = Math.max(1, Math.round(bitmap.height * scale));
          const context = canvas.getContext('2d', { alpha: false });
          if (!context) throw new Error('Unable to prepare the screenshot. Try another image.');
          context.fillStyle = '#ffffff';
          context.fillRect(0, 0, canvas.width, canvas.height);
          context.drawImage(bitmap, 0, 0, canvas.width, canvas.height);
          for (const quality of [0.84, 0.74, 0.64, 0.54, 0.44, 0.34]) {
            const blob = await canvasBlob(canvas, quality);
            if (blob && blob.size <= targetBytes) {
              return new File([blob], 'bug-screenshot.jpg', { type: 'image/jpeg' });
            }
          }
          scale *= 0.72;
        }
        throw new Error('The selected pictures are too large together after compression. Remove a few pictures or choose smaller screenshots.');
      } finally {
        bitmap.close?.();
      }
    }

    async function fileToBase64(file) {
      const bytes = new Uint8Array(await file.arrayBuffer());
      let binary = '';
      for (let offset = 0; offset < bytes.length; offset += 0x8000) {
        binary += String.fromCharCode(...bytes.subarray(offset, offset + 0x8000));
      }
      return btoa(binary);
    }

    async function compressSelectedImages() {
      const images = [];
      let remainingBytes = maxStoredImageBytes;
      for (let index = 0; index < selectedImages.length; index += 1) {
        const remainingCount = selectedImages.length - index;
        const imageBudget = Math.floor(remainingBytes / remainingCount);
        if (imageBudget < 1024) {
          throw new Error('Too many pictures to fit this report. Remove a few or combine screenshots into one image.');
        }
        const compressed = await compressScreenshot(selectedImages[index], imageBudget);
        remainingBytes -= compressed.size;
        images.push({ mime: compressed.type, data: await fileToBase64(compressed) });
      }
      return images;
    }

    form.addEventListener('submit', async (event) => {
      event.preventDefault();
      const message = messageInput.value.trim();
      if (message.length < 10 || message.length > 2500) {
        showStatus('Please describe the issue in 10 to 2,500 characters.', 'error');
        messageInput.focus();
        return;
      }

      submitButton.disabled = true;
      showStatus(selectedImages.length ? `Preparing ${selectedImages.length} picture${selectedImages.length === 1 ? '' : 's'}…` : 'Sending your report…');
      try {
        const body = new FormData(form);
        if (selectedImages.length) {
          const compressedImages = await compressSelectedImages();
          body.append('report_images_json', JSON.stringify(compressedImages));
        }
        showStatus('Sending your report securely…');
        const response = await fetch('/api/bug_report_submit.php', { method: 'POST', body });
        const result = await response.json();
        if (!response.ok || !result.ok) throw new Error(result.error || 'The report could not be sent. Try again.');

        const ticketCode = result.ticket_code || '';
        if (!ticketCode) throw new Error('The report was received, but a ticket code was not returned. Please contact the review team.');
        form.reset();
        clearImages();
        document.getElementById('charCount').textContent = '0 / 2,500';
        ticketCodeNode.textContent = ticketCode;
        ticketResult.classList.add('visible');
        showStatus('Report received. Thank you for helping improve PulseCONNECT.', 'success');
      } catch (error) {
        showStatus(error.message || 'The report could not be sent. Try again.', 'error');
      } finally {
        submitButton.disabled = false;
      }
    });

    document.getElementById('copyTicket').addEventListener('click', async () => {
      const ticketCode = ticketCodeNode.textContent;
      try {
        await navigator.clipboard.writeText(ticketCode);
        showStatus('Ticket code copied. Keep it somewhere safe.', 'success');
      } catch {
        showStatus('Copy was unavailable. Select and save the ticket code shown above.', 'error');
      }
    });

    const ticketLookupForm = document.getElementById('ticketLookupForm');
    const ticketLookupInput = document.getElementById('ticketLookupInput');
    const ticketLookupButton = document.getElementById('ticketLookupButton');
    const ticketLookupMessage = document.getElementById('ticketLookupMessage');
    const ticketLookupResult = document.getElementById('ticketLookupResult');

    ticketLookupForm.addEventListener('submit', async (event) => {
      event.preventDefault();
      const ticketCode = ticketLookupInput.value.trim();
      if (!ticketCode) {
        ticketLookupMessage.textContent = 'Enter the ticket code from your report confirmation.';
        ticketLookupMessage.className = 'lookup-message error';
        ticketLookupInput.focus();
        return;
      }
      ticketLookupButton.disabled = true;
      ticketLookupResult.classList.remove('visible');
      ticketLookupMessage.textContent = 'Checking ticket…';
      ticketLookupMessage.className = 'lookup-message';
      try {
        const response = await fetch('/api/bug_report_status.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ ticket_code: ticketCode }),
        });
        const data = await response.json();
        if (!response.ok || !data.ok) throw new Error(data.error || 'Could not find this ticket. Check the code and try again.');
        const ticket = data.ticket;
        const status = ['new', 'reviewing', 'resolved'].includes(ticket.status) ? ticket.status : 'new';
        document.getElementById('lookupTicketCode').textContent = ticket.ticket_code;
        const badge = document.getElementById('lookupTicketStatus');
        badge.textContent = status === 'new' ? 'New' : status === 'reviewing' ? 'Reviewing' : 'Resolved';
        badge.className = `lookup-badge ${status}`;
        document.getElementById('lookupTicketUpdate').textContent = ticket.public_update || 'No public update yet. Check again later to see the latest progress.';
        ticketLookupResult.classList.add('visible');
        ticketLookupMessage.textContent = 'Ticket found. Here is the latest update.';
      } catch (error) {
        ticketLookupMessage.textContent = error.message || 'Could not check this ticket. Try again.';
        ticketLookupMessage.className = 'lookup-message error';
      } finally {
        ticketLookupButton.disabled = false;
      }
    });

    document.getElementById('checkTicketLink').addEventListener('click', (event) => {
      event.preventDefault();
      ticketLookupInput.value = ticketCodeNode.textContent;
      window.location.hash = 'ticketLookup';
      ticketLookupForm.requestSubmit();
    });
  </script>
</body>
</html>
