<?php
declare(strict_types=1);

?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="theme-color" content="#28151a">
  <title>Issue Report Review</title>
  <style>
    :root { color-scheme: light; --ink:#251c1c; --muted:#7a6d6b; --line:#e9e1de; --wine:#511a22; --gold:#c28a3c; --paper:#fffdfa; --canvas:#f5f2ef; }
    * { box-sizing:border-box; }
    body { min-height:100vh; margin:0; color:var(--ink); background:radial-gradient(ellipse at 4% 0%,#ead9c9 0,transparent 36rem),var(--canvas); font-family:"Segoe UI",system-ui,sans-serif; }
    button,select,textarea { font:inherit; }
    .topbar { display:flex; align-items:center; justify-content:space-between; gap:18px; padding:18px clamp(18px,4vw,52px); border-bottom:1px solid rgba(255,255,255,.12); color:white; background:linear-gradient(110deg,#281116,#542028); box-shadow:0 8px 26px rgba(38,15,19,.13); }
    .brand { display:flex; align-items:center; gap:12px; min-width:0; }
    .brand-mark { display:grid; width:42px; height:42px; flex:0 0 42px; place-items:center; border:1px solid rgba(255,255,255,.2); border-radius:13px; color:#e7bd73; background:rgba(255,255,255,.08); font-family:Georgia,serif; font-weight:800; }
    .brand strong { display:block; font-size:14px; letter-spacing:-.02em; }
    .brand span { display:block; margin-top:3px; color:#e7bd73; font-size:9px; font-weight:800; letter-spacing:.17em; text-transform:uppercase; }
    .top-title { padding-left:19px; border-left:1px solid rgba(255,255,255,.2); }
    .top-title strong { display:block; font-size:13px; }
    .top-title span { display:block; margin-top:3px; color:#ddc8c6; font-size:11px; }
    .top-left { display:flex; align-items:center; gap:20px; }
    .container { width:min(1440px,100% - 40px); margin:0 auto; padding:34px 0 50px; }
    .intro { display:flex; align-items:flex-end; justify-content:space-between; gap:18px; margin-bottom:21px; }
    .eyebrow { color:#9a6332; font-size:10px; font-weight:850; letter-spacing:.2em; text-transform:uppercase; }
    h1 { margin:5px 0 4px; font-family:Georgia,"Times New Roman",serif; font-size:clamp(28px,4vw,39px); font-weight:500; letter-spacing:-.04em; }
    .intro p { margin:0; color:var(--muted); font-size:13px; }
    .refresh { min-height:40px; padding:0 14px; border:1px solid #d9ccc6; border-radius:11px; color:#503638; background:#fffdfa; cursor:pointer; font-size:12px; font-weight:750; transition:.15s; }
    .refresh:hover { border-color:#b88d74; background:#fff8ef; }
    .notice { margin:0 0 16px; padding:12px 15px; border:1px solid #b7dfc5; border-radius:12px; color:#23663c; background:#f0fbf3; font-size:13px; font-weight:650; }
    .notice.is-error { border-color:#f0c1be; color:#9c302b; background:#fff3f1; }
    .is-hidden { display:none !important; }
    .workspace { display:grid; grid-template-columns:minmax(270px,350px) minmax(0,1fr); gap:17px; min-height:610px; }
    .panel { min-width:0; overflow:hidden; border:1px solid var(--line); border-radius:18px; background:var(--paper); box-shadow:0 12px 35px rgba(53,30,26,.055); }
    .panel-head { display:flex; align-items:center; justify-content:space-between; gap:12px; padding:17px 19px; border-bottom:1px solid #eee8e5; }
    .panel-head h2 { margin:0; font-size:14px; font-weight:800; }
    .panel-head p { margin:4px 0 0; color:var(--muted); font-size:11px; }
    .newest { padding:6px 8px; border-radius:7px; color:#805021; background:#fff3dc; font-size:9px; font-weight:850; letter-spacing:.12em; }
    .report-list { max-height:calc(100vh - 270px); min-height:440px; overflow:auto; }
    .report-card { display:block; width:100%; padding:15px 17px; border:0; border-bottom:1px solid #f0ebe9; background:transparent; text-align:left; cursor:pointer; transition:background .14s; }
    .report-card:hover,.report-card.is-active { background:#fff6ed; }
    .report-card.is-active { box-shadow:inset 3px 0 #b45c38; }
    .report-meta { display:flex; align-items:center; justify-content:space-between; gap:10px; }
    .report-badge,.detail-badge { display:inline-flex; align-items:center; width:max-content; padding:5px 8px; border-radius:99px; font-size:9px; font-weight:850; letter-spacing:.08em; text-transform:uppercase; }
    .status-new { color:#815710; background:#fff0c7; }
    .status-reviewing { color:#255a7c; background:#dff1fc; }
    .status-resolved { color:#216340; background:#dcf4e4; }
    .report-date { color:#9b908c; font-size:10px; white-space:nowrap; }
    .report-preview { display:-webkit-box; overflow:hidden; margin-top:9px; color:#3e3433; font-size:12px; font-weight:650; line-height:1.5; -webkit-box-orient:vertical; -webkit-line-clamp:3; }
    .report-kind { display:block; margin-top:9px; color:#9a8c87; font-size:9px; font-weight:800; letter-spacing:.1em; }
    .list-message { padding:20px 17px; color:var(--muted); font-size:12px; }
    .list-message.is-error { color:#a3312d; }
    .detail-empty { display:flex; min-height:610px; flex-direction:column; align-items:center; justify-content:center; padding:28px; text-align:center; }
    .empty-mark { display:grid; width:52px; height:52px; place-items:center; border:1px solid #eed9c5; border-radius:17px; color:#875033; background:#fff4e8; font-family:Georgia,serif; font-size:27px; }
    .detail-empty h2 { margin:15px 0 5px; font-size:15px; }
    .detail-empty p { max-width:335px; margin:0; color:var(--muted); font-size:12px; line-height:1.6; }
    .detail-head { display:flex; align-items:flex-start; justify-content:space-between; gap:18px; padding:21px clamp(18px,3vw,30px); border-bottom:1px solid #eee8e5; }
    .report-id { color:#988986; font-size:10px; letter-spacing:.04em; }
    .report-id code { color:#65514f; font-family:Consolas,monospace; }
    .detail-head h2 { margin:5px 0 3px; font-size:17px; font-weight:850; }
    .detail-date { margin:0; color:var(--muted); font-size:11px; }
    .detail-body { padding:21px clamp(18px,3vw,30px) 27px; }
    .section-label { margin:0 0 9px; color:#857875; font-size:10px; font-weight:850; letter-spacing:.13em; text-transform:uppercase; }
    .message-content { margin:0; padding:15px; border:1px solid #eee7e3; border-radius:13px; background:#faf8f5; color:#3d3332; font-size:13px; line-height:1.75; overflow-wrap:anywhere; }
    .screenshot { margin-top:22px; }
    .screenshot-gallery { display:grid; grid-template-columns:repeat(auto-fit,minmax(220px,1fr)); gap:10px; }
    .screenshot-frame { display:grid; min-height:120px; max-height:420px; place-items:center; overflow:auto; border:1px solid #e8dfda; border-radius:13px; background:#f1eeeb; }
    .screenshot-frame img { display:block; max-width:100%; max-height:420px; object-fit:contain; }
    .review-form { margin-top:24px; padding-top:20px; border-top:1px solid #eee8e5; }
    .review-fields { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:14px; }
    .review-fields .status-field { grid-column:1 / -1; }
    .review-form label { display:block; margin:0 0 7px; color:#625654; font-size:11px; font-weight:800; }
    .review-form select,.review-form textarea { width:100%; border:1px solid #ded4d0; border-radius:10px; outline:0; background:#fff; color:#352a29; font-size:12px; }
    .review-form select { min-height:41px; padding:0 10px; }
    .review-form textarea { min-height:88px; padding:10px; resize:vertical; line-height:1.5; }
    .review-form select:focus,.review-form textarea:focus { border-color:#ae7258; box-shadow:0 0 0 3px rgba(174,114,88,.12); }
    .review-actions { display:flex; align-items:center; justify-content:space-between; gap:15px; margin-top:13px; }
    .updated-at { color:#9b908c; font-size:10px; }
    .save-review { min-height:39px; padding:0 15px; border:0; border-radius:10px; color:#fff; background:#64242b; cursor:pointer; font-size:11px; font-weight:800; transition:.15s; }
    .save-review:hover:not(:disabled) { background:#7b2d35; }
    .save-review:disabled { opacity:.6; cursor:wait; }
    .panel-tools { display:flex; align-items:center; gap:9px; }
    .search-reports { width:min(260px,32vw); min-height:36px; padding:0 11px; border:1px solid #e4dbd7; border-radius:9px; outline:0; background:white; color:#352a29; font:inherit; font-size:11px; }
    .search-reports:focus { border-color:#ae7258; box-shadow:0 0 0 3px rgba(174,114,88,.1); }
    @media (max-width:800px) { .container { width:calc(100% - 24px); padding-top:25px; } .workspace { grid-template-columns:1fr; } .report-list { max-height:330px; min-height:120px; } .detail-empty { min-height:330px; } }
    @media (max-width:520px) { .topbar { padding:14px; } .top-left { gap:11px; } .top-title { padding-left:11px; } .top-title span { max-width:110px; line-height:1.3; } .brand strong { font-size:12px; } .brand span { font-size:8px; letter-spacing:.1em; } .intro { align-items:flex-start; } .intro p { max-width:260px; line-height:1.45; } .refresh { padding:0 10px; } .panel-head { align-items:flex-start; flex-direction:column; } .panel-tools { width:100%; } .search-reports { width:100%; } .review-fields { grid-template-columns:1fr; } .review-actions { align-items:flex-start; flex-direction:column; } .save-review { width:100%; } }
  </style>
</head>
<body>
  <header class="topbar">
    <div class="top-left">
      <div class="brand"><div class="brand-mark" aria-hidden="true">IR</div><div><strong>Issue Desk</strong><span>Standalone review page</span></div></div>
      <div class="top-title"><strong>Issue Review</strong><span>Browse submitted reports</span></div>
    </div>
  </header>
  <main class="container">
    <header class="intro">
      <div><div class="eyebrow">Standalone review address</div><h1>Bug report inbox</h1><p>Search reports, inspect screenshots, and record the review outcome.</p></div>
      <button id="refreshReports" class="refresh" type="button">Refresh inbox</button>
    </header>
    <div id="reviewNotice" class="notice is-hidden" role="status" aria-live="polite"></div>
    <div class="workspace">
      <section class="panel" aria-labelledby="report-list-heading">
        <div class="panel-head"><div><h2 id="report-list-heading">Inbox</h2><p id="reportCount">Loading reports…</p></div><div class="panel-tools"><input id="searchReports" class="search-reports" type="search" placeholder="Search issue, report ID, or status…" aria-label="Search bug reports"><span class="newest">NEWEST</span></div></div>
        <div id="reportList" class="report-list"><div class="list-message">Loading…</div></div>
      </section>
      <section id="reportDetail" class="panel" aria-labelledby="detail-heading">
        <div id="emptyDetail" class="detail-empty"><div class="empty-mark" aria-hidden="true">!</div><h2>Choose a report</h2><p>Select an item from the inbox to read the report, inspect its screenshot, and update its review status.</p></div>
        <div id="detailContent" class="is-hidden">
          <div class="detail-head"><div><div class="report-id">REPORT <code id="detailId"></code></div><h2 id="detail-heading">Issue details</h2><p id="detailDate" class="detail-date"></p></div><span id="detailStatusBadge" class="detail-badge"></span></div>
          <div class="detail-body">
            <div><p class="section-label">What the user reported</p><p id="detailMessage" class="message-content"></p></div>
            <div id="detailImageSection" class="screenshot is-hidden"><p class="section-label">Attached screenshots</p><div id="detailImages" class="screenshot-gallery"></div></div>
            <form id="reviewForm" class="review-form">
              <input type="hidden" id="reviewReportId">
              <div class="review-fields">
                <div class="status-field"><label for="reviewStatusSelect">Review status</label><select id="reviewStatusSelect"><option value="new">New</option><option value="reviewing">Reviewing</option><option value="resolved">Resolved</option></select></div>
                <div><label for="publicUpdate">Update for reporter <span style="font-weight:400;color:#a39a97">(visible with ticket)</span></label><textarea id="publicUpdate" maxlength="1500" placeholder="Share a progress update or resolution the reporter can see…"></textarea></div>
                <div><label for="reviewNote">Reviewer note <span style="font-weight:400;color:#a39a97">(not shown on ticket lookup)</span></label><textarea id="reviewNote" maxlength="1500" placeholder="Add a note for the review team…"></textarea></div>
              </div>
              <div class="review-actions"><p id="updatedAt" class="updated-at"></p><button id="saveReview" type="submit" class="save-review">Save review</button></div>
            </form>
          </div>
        </div>
      </section>
    </div>
  </main>
  <script>
  (() => {
    const list = document.getElementById('reportList');
    const count = document.getElementById('reportCount');
    const search = document.getElementById('searchReports');
    const statusBox = document.getElementById('reviewNotice');
    const detailPane = document.getElementById('detailContent');
    const emptyPane = document.getElementById('emptyDetail');
    let activeId = '';
    let reports = [];

    function setStatus(message, kind = 'info') {
      statusBox.textContent = message;
      statusBox.className = `notice${kind === 'error' ? ' is-error' : ''}`;
    }

    function formattedDate(value) {
      const date = new Date(value || '');
      return Number.isNaN(date.getTime()) ? 'Date unavailable' : date.toLocaleString('en-PH', { dateStyle: 'medium', timeStyle: 'short' });
    }

    function statusClass(status) {
      if (status === 'resolved') return 'status-resolved';
      if (status === 'reviewing') return 'status-reviewing';
      return 'status-new';
    }

    function renderList() {
      const query = search.value.trim().toLocaleLowerCase();
      const filteredReports = query ? reports.filter((report) => [
        report.id,
        report.message,
        report.status,
        report.review_note,
        report.created_at,
      ].some((value) => String(value || '').toLocaleLowerCase().includes(query))) : reports;
      count.textContent = query
        ? `${filteredReports.length} of ${reports.length} reports`
        : `${reports.length} report${reports.length === 1 ? '' : 's'}`;
      list.replaceChildren();
      if (filteredReports.length === 0) {
        const empty = document.createElement('p');
        empty.className = 'list-message';
        empty.textContent = query ? 'No matching reports.' : 'No bug reports yet.';
        list.append(empty);
        return;
      }
      for (const report of filteredReports) {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = `report-card${report.id === activeId ? ' is-active' : ''}`;
        const meta = document.createElement('span');
        meta.className = 'report-meta';
        const badge = document.createElement('span');
        badge.className = `report-badge ${statusClass(report.status)}`;
        badge.textContent = report.status || 'new';
        const date = document.createElement('time');
        date.className = 'report-date';
        date.textContent = formattedDate(report.created_at);
        meta.append(badge, date);
        const text = document.createElement('span');
        text.className = 'report-preview';
        text.textContent = report.message || '(No description)';
        const attachment = document.createElement('span');
        attachment.className = 'report-kind';
        attachment.textContent = report.has_image ? 'SCREENSHOT ATTACHED' : 'TEXT REPORT';
        button.append(meta, text, attachment);
        button.addEventListener('click', () => loadDetail(report.id));
        list.append(button);
      }
    }

    async function request(url, options = {}) {
      const response = await fetch(url, options);
      const data = await response.json();
      if (!response.ok || !data.ok) throw new Error(data.error || 'Request failed.');
      return data;
    }

    async function loadReports(keepSelection = true) {
      try {
        const data = await request('/api/bug_reports_review.php?action=list');
        reports = Array.isArray(data.reports) ? data.reports : [];
        renderList();
        if (keepSelection && activeId && reports.some((item) => item.id === activeId)) await loadDetail(activeId, false);
        else if (activeId && !reports.some((item) => item.id === activeId)) {
          activeId = '';
          detailPane.classList.add('is-hidden');
          emptyPane.classList.remove('is-hidden');
        }
        statusBox.classList.add('is-hidden');
      } catch (error) {
        list.replaceChildren();
        const failed = document.createElement('p');
        failed.className = 'list-message is-error';
        failed.textContent = error.message || 'Could not load reports.';
        list.append(failed);
        count.textContent = 'Unable to load';
      }
    }

    async function loadDetail(id, updateList = true) {
      activeId = id;
      if (updateList) renderList();
      try {
        const data = await request(`/api/bug_reports_review.php?action=detail&id=${encodeURIComponent(id)}`);
        const report = data.report;
        document.getElementById('reviewReportId').value = report.id;
        document.getElementById('detailId').textContent = report.id;
        document.getElementById('detailDate').textContent = `Submitted ${formattedDate(report.created_at)}`;
        document.getElementById('detailMessage').textContent = report.message || '';
        const badge = document.getElementById('detailStatusBadge');
        badge.textContent = report.status || 'new';
        badge.className = `detail-badge ${statusClass(report.status)}`;
        document.getElementById('reviewStatusSelect').value = report.status || 'new';
        document.getElementById('publicUpdate').value = report.public_update || '';
        document.getElementById('reviewNote').value = report.review_note || '';
        document.getElementById('updatedAt').textContent = report.updated_at ? `Last updated ${formattedDate(report.updated_at)}` : '';
        const imageSection = document.getElementById('detailImageSection');
        const imageGallery = document.getElementById('detailImages');
        const reportImages = Array.isArray(report.images) ? [...report.images] : [];
        if (reportImages.length === 0 && report.image_data) {
          reportImages.push({ mime: report.image_mime, data: report.image_data });
        }
        imageGallery.replaceChildren();
        reportImages.forEach((image, index) => {
          if (!image || !['image/jpeg', 'image/png', 'image/webp'].includes(image.mime) || !image.data) return;
          const frame = document.createElement('div');
          frame.className = 'screenshot-frame';
          const element = document.createElement('img');
          element.src = `data:${image.mime};base64,${image.data}`;
          element.alt = `Screenshot ${index + 1} attached to bug report`;
          frame.append(element);
          imageGallery.append(frame);
        });
        imageSection.classList.toggle('is-hidden', imageGallery.childElementCount === 0);
        emptyPane.classList.add('is-hidden');
        detailPane.classList.remove('is-hidden');
      } catch (error) {
        setStatus(error.message || 'Could not open report.', 'error');
      }
    }

    document.getElementById('refreshReports').addEventListener('click', () => loadReports());
    search.addEventListener('input', renderList);
    document.getElementById('reviewForm').addEventListener('submit', async (event) => {
      event.preventDefault();
      const saveButton = document.getElementById('saveReview');
      saveButton.disabled = true;
      try {
        await request('/api/bug_reports_review.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({
            action: 'update',
            id: document.getElementById('reviewReportId').value,
            status: document.getElementById('reviewStatusSelect').value,
            public_update: document.getElementById('publicUpdate').value,
            review_note: document.getElementById('reviewNote').value,
          }),
        });
        await loadReports();
        setStatus('Review saved.');
      } catch (error) {
        setStatus(error.message || 'Could not save review.', 'error');
      } finally {
        saveButton.disabled = false;
      }
    });

    loadReports(false);
  })();
  </script>
</body>
</html>
