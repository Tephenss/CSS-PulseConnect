<?php
declare(strict_types=1);
/** @var array<string, mixed> $event */
/** @var list<array<string, mixed>> $feedbackSections */
/** @var bool $autoPrint */
/** @var string $exportedAt */
/** @var string $eventTitle */
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <?php require_once __DIR__ . '/favicon.php'; render_favicon_tags(); ?>
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title><?= htmlspecialchars($eventTitle) ?> — Event Feedback Report</title>
  <link rel="stylesheet" href="/assets/css/tailwind.css" />
  <link rel="stylesheet" href="/assets/css/layout.css" />
  <style>
    body { background: #fff; color: #18181b; }
    .export-toolbar {
      position: sticky;
      top: 0;
      z-index: 20;
      display: flex;
      justify-content: space-between;
      align-items: center;
      gap: 12px;
      padding: 12px 20px;
      border-bottom: 1px solid #e4e4e7;
      background: rgba(255,255,255,.96);
      backdrop-filter: blur(6px);
    }
    .export-toolbar button {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      border-radius: 10px;
      padding: 10px 16px;
      font-size: 13px;
      font-weight: 700;
      cursor: pointer;
      line-height: 1.2;
    }
    .btn-save-pdf {
      border: 1px solid #7f1d1d;
      background: #7f1d1d;
      color: #fff;
    }
    .btn-save-pdf:hover { background: #991b1b; border-color: #991b1b; }
    .btn-close { border: 1px solid #e4e4e7; background: #fff; color: #18181b; }
    .btn-close:hover { background: #f4f4f5; }
    .export-cover {
      border: 1px solid #e4e4e7;
      border-radius: 18px;
      overflow: hidden;
      margin-bottom: 28px;
      background: linear-gradient(135deg, #7f1d1d 0%, #991b1b 55%, #b45309 100%);
      color: #fff;
      padding: 24px 28px;
    }
    .export-cover .kicker {
      font-size: 11px;
      letter-spacing: .14em;
      text-transform: uppercase;
      font-weight: 800;
      opacity: .9;
    }
    .export-cover h1 {
      margin: 8px 0 0;
      font-size: 28px;
      line-height: 1.15;
    }
    .feedback-export-page .rounded-2xl.overflow-hidden,
    .feedback-export-page .feedback-indicator-card {
      overflow: visible !important;
    }
    .feedback-export-pie-chart {
      display: block;
      width: 224px;
      height: 224px;
      flex: 0 0 224px;
    }
    .feedback-chart-wrap {
      overflow: visible;
      width: 100%;
      max-width: 560px;
      margin: 0 auto;
    }
    .feedback-bar-chart,
    .feedback-export-bar-chart {
      display: block;
      width: 100%;
      max-width: 560px;
      height: auto;
      overflow: visible;
    }
    .feedback-export-legend-dot {
      display: inline-block;
      width: 14px;
      height: 14px;
      min-width: 14px;
      border-radius: 50%;
      border: 1px solid #d4d4d8;
      -webkit-print-color-adjust: exact;
      print-color-adjust: exact;
    }
    body.export-auto-download .export-toolbar {
      display: none;
    }
    .export-generating-banner {
      display: none;
      position: fixed;
      inset: 0;
      z-index: 50;
      align-items: center;
      justify-content: center;
      background: rgba(255, 255, 255, 0.92);
      color: #18181b;
      font-size: 15px;
      font-weight: 700;
    }
    body.export-auto-download .export-generating-banner {
      display: flex;
    }
    @media print {
      @page {
        size: A4;
        margin: 12mm;
      }
      .no-print { display: none !important; }
      .export-page { max-width: none; padding: 0; }
      .feedback-scroll-list { max-height: none !important; overflow: visible !important; }
      .rounded-2xl { overflow: visible !important; }
      .feedback-bar-chart,
      .feedback-export-bar-chart,
      .feedback-export-pie-chart {
        max-width: 100% !important;
        height: auto !important;
        overflow: visible !important;
      }
      .feedback-export-legend-dot,
      svg, path, rect, circle, text {
        -webkit-print-color-adjust: exact !important;
        print-color-adjust: exact !important;
      }
    }
  </style>
</head>
<body class="feedback-export-page<?= $autoPrint ? ' export-auto-download' : '' ?>">
  <div class="export-generating-banner" aria-live="polite">Generating PDF...</div>
  <div class="export-toolbar no-print">
    <div>
      <strong>PulseConnect Event Feedback</strong>
      <div class="text-xs text-zinc-500 mt-0.5">Download a clean PDF without browser URL/date headers.</div>
    </div>
    <div class="flex gap-2">
      <button type="button" class="btn-save-pdf" id="btn-download-pdf">Download PDF</button>
      <button type="button" class="btn-close" onclick="window.close()">Close</button>
    </div>
  </div>

  <div class="export-page max-w-4xl mx-auto px-6 py-8">
    <div class="export-cover">
      <div class="kicker">Evaluation Feedback Report</div>
      <h1><?= htmlspecialchars($eventTitle) ?></h1>
    </div>

    <div class="space-y-8">
      <?php foreach ($feedbackSections as $section): ?>
        <?php
          extract(evaluation_feedback_section_view_data($section), EXTR_SKIP);
          $feedbackExportMode = true;
          require __DIR__ . '/evaluation_feedback_section_render.php';
        ?>
      <?php endforeach; ?>
    </div>
  </div>

  <script src="/assets/js/html2pdf.bundle.min.js"></script>
  <script>
    (function () {
      const pdfFilename = <?= json_encode(
          preg_replace('/[^\p{L}\p{N}\s\-_]+/u', '', $eventTitle) ?: 'event-feedback',
          JSON_UNESCAPED_UNICODE
      ) ?> + '-feedback.pdf';
      const autoPrint = <?= $autoPrint ? 'true' : 'false' ?>;
      const inIframe = window.parent && window.parent !== window;
      let started = false;
      let attempt = 0;

      function pdfReady() {
        return typeof html2pdf !== 'undefined';
      }

      function notifyParent(type, message, extra) {
        if (!inIframe) return;
        const payload = { type: type, message: message || '' };
        if (extra && typeof extra === 'object') {
          Object.assign(payload, extra);
        }
        window.parent.postMessage(payload, window.location.origin);
      }

      function waitFrames(count) {
        return new Promise(function (resolve) {
          let remaining = count;
          function step() {
            remaining -= 1;
            if (remaining <= 0) {
              resolve();
              return;
            }
            requestAnimationFrame(step);
          }
          requestAnimationFrame(step);
        });
      }

      async function waitForRenderReady() {
        if (document.fonts && document.fonts.ready) {
          try {
            await document.fonts.ready;
          } catch (_) {}
        }
        await waitFrames(2);
        const images = Array.from(document.querySelectorAll('.export-page img'));
        await Promise.all(images.map(function (img) {
          if (img.complete) {
            return Promise.resolve();
          }
          return new Promise(function (resolve) {
            img.addEventListener('load', resolve, { once: true });
            img.addEventListener('error', resolve, { once: true });
          });
        }));
        await new Promise(function (resolve) {
          window.setTimeout(resolve, 350);
        });
      }

      async function buildPdfBlob(element) {
        return html2pdf().set({
          margin: [8, 8, 8, 8],
          filename: pdfFilename,
          image: { type: 'jpeg', quality: 0.92 },
          html2canvas: {
            scale: 1.5,
            useCORS: true,
            allowTaint: true,
            letterRendering: true,
            logging: false,
            backgroundColor: '#ffffff',
          },
          jsPDF: { unit: 'mm', format: 'a4', orientation: 'portrait' },
          pagebreak: {
            mode: ['css', 'legacy'],
            avoid: ['.feedback-indicator-card', '.export-cover'],
          },
        }).from(element).outputPdf('blob');
      }

      function triggerLocalDownload(blob) {
        const url = URL.createObjectURL(blob);
        const anchor = document.createElement('a');
        anchor.href = url;
        anchor.download = pdfFilename;
        document.body.appendChild(anchor);
        anchor.click();
        anchor.remove();
        window.setTimeout(function () { URL.revokeObjectURL(url); }, 1000);
      }

      async function downloadCleanPdf() {
        if (started) return;
        started = true;
        attempt += 1;

        const btn = document.getElementById('btn-download-pdf');
        const element = document.querySelector('.export-page');
        if (!element) {
          notifyParent('feedback-pdf-error', 'Export content missing.');
          started = false;
          return;
        }

        if (!pdfReady()) {
          notifyParent('feedback-pdf-error', 'PDF generator failed to load.');
          if (!autoPrint) {
            alert('PDF generator failed to load. Please refresh and try again.');
          }
          started = false;
          return;
        }

        const originalLabel = btn ? btn.textContent : '';
        if (btn) {
          btn.disabled = true;
          btn.textContent = 'Generating PDF...';
        }

        try {
          await waitForRenderReady();
          notifyParent('feedback-pdf-started');

          const blob = await buildPdfBlob(element);
          if (!(blob instanceof Blob)) {
            throw new Error('PDF blob missing');
          }

          if (inIframe) {
            notifyParent('feedback-pdf-blob', '', { blob: blob, filename: pdfFilename });
          } else {
            triggerLocalDownload(blob);
            notifyParent('feedback-pdf-done');
          }
        } catch (err) {
          console.error(err);
          if (attempt < 2) {
            started = false;
            await new Promise(function (resolve) { window.setTimeout(resolve, 600); });
            return downloadCleanPdf();
          }
          notifyParent('feedback-pdf-error', 'Could not generate PDF.');
          if (!autoPrint) {
            alert('Could not generate PDF. Please try again.');
          }
        } finally {
          if (btn) {
            btn.disabled = false;
            btn.textContent = originalLabel || 'Download PDF';
          }
        }
      }

      function startWhenReady(tries) {
        const count = typeof tries === 'number' ? tries : 0;
        if (!pdfReady()) {
          if (count >= 120) {
            notifyParent('feedback-pdf-error', 'PDF generator timed out loading.');
            return;
          }
          window.setTimeout(function () { startWhenReady(count + 1); }, 100);
          return;
        }
        if (autoPrint) {
          downloadCleanPdf();
        }
      }

      document.getElementById('btn-download-pdf')?.addEventListener('click', function () {
        started = false;
        attempt = 0;
        downloadCleanPdf();
      });
      window.addEventListener('load', function () { startWhenReady(0); });
    })();
  </script>
</body>
</html>
