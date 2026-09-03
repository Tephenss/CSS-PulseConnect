/**
 * PulseCONNECT circular code: App Clip look + real QR payload for scanners.
 * Requires global QRCode from qrcodejs.
 */
(function (global) {
  'use strict';

  var PLATE = '#1C1C1E';
  var WHITE = '#FFFFFF';
  var GRAY = '#8E8E93';

  function seedFromText(text) {
    var h = 2166136261;
    var i;
    for (i = 0; i < text.length; i++) {
      h ^= text.charCodeAt(i);
      h = Math.imul(h, 16777619);
    }
    return h >>> 0;
  }

  function hashBit(text, i) {
    var h = seedFromText(text);
    var x = (h + i * 9973) >>> 0;
    x ^= x << 13;
    x ^= x >>> 17;
    x ^= x << 5;
    return (x >>> 0) & 1;
  }

  function isDarkPx(data, x, y, width) {
    var idx = (y * width + x) * 4;
    return data[idx] < 140 && data[idx + 3] > 80;
  }

  function matrixFromQrSource(sourceEl) {
    var canvas = sourceEl;
    if (sourceEl.tagName === 'IMG') {
      canvas = document.createElement('canvas');
      canvas.width = sourceEl.naturalWidth || sourceEl.width;
      canvas.height = sourceEl.naturalHeight || sourceEl.height;
      var copy = canvas.getContext('2d');
      if (!copy) return null;
      copy.drawImage(sourceEl, 0, 0);
    }
    var ctx = canvas.getContext('2d');
    if (!ctx) return null;
    var w = canvas.width;
    var h = canvas.height;
    if (w < 8 || h < 8) return null;
    var img = ctx.getImageData(0, 0, w, h);
    var d = img.data;
    var x0 = -1;
    var y0 = -1;
    var y;
    var x;
    outer: for (y = 0; y < h; y++) {
      for (x = 0; x < w; x++) {
        if (isDarkPx(d, x, y, w)) {
          x0 = x;
          y0 = y;
          break outer;
        }
      }
    }
    if (x0 < 0) return null;
    var x1 = x0;
    while (x1 < w && isDarkPx(d, x1, y0, w)) x1++;
    var modulePx = (x1 - x0) / 7;
    if (modulePx < 1) return null;
    var n = Math.round(w / modulePx);
    if (n < 21 || n > 177) return null;
    var matrix = [];
    var r;
    var c;
    for (r = 0; r < n; r++) {
      var row = [];
      for (c = 0; c < n; c++) {
        var sx = Math.min(w - 1, Math.floor(c * modulePx + modulePx / 2));
        var sy = Math.min(h - 1, Math.floor(r * modulePx + modulePx / 2));
        row.push(isDarkPx(d, sx, sy, w));
      }
      matrix.push(row);
    }
    return matrix;
  }

  function inFinder(r, c, n) {
    return (r <= 7 && c <= 7) || (r <= 7 && c >= n - 8) || (r >= n - 8 && c <= 7);
  }

  function paintCode(size, matrix, logo, showLogo, payload) {
    var dpr = Math.min(global.devicePixelRatio || 1, 2);
    var canvas = document.createElement('canvas');
    canvas.width = Math.round(size * dpr);
    canvas.height = Math.round(size * dpr);
    canvas.style.width = size + 'px';
    canvas.style.height = size + 'px';
    var ctx = canvas.getContext('2d');
    if (!ctx) return null;
    ctx.scale(dpr, dpr);

    var cx = size / 2;
    var cy = size / 2;
    var R = size / 2;
    var n = matrix ? matrix.length : 0;

    ctx.beginPath();
    ctx.arc(cx, cy, R, 0, Math.PI * 2);
    ctx.fillStyle = PLATE;
    ctx.fill();

    var logoR = R * (showLogo ? 0.30 : 0.16);
    var pad = size * 0.16;
    var qrSize = size - pad * 2;
    var cell = n > 0 ? qrSize / n : 0;
    var origin = pad;

    function qrDark(r, c) {
      return !!(matrix && r >= 0 && c >= 0 && r < n && c < n && matrix[r][c]);
    }

    function inLogo(x, y) {
      var dx = x - cx;
      var dy = y - cy;
      return Math.sqrt(dx * dx + dy * dy) < logoR + cell * 0.35;
    }

    if (n > 0 && cell > 0) {
      ctx.fillStyle = WHITE;
      var r;
      var c;
      for (r = 0; r < n; r++) {
        for (c = 0; c < n; c++) {
          if (!qrDark(r, c) || inFinder(r, c, n)) continue;
          var x = origin + c * cell + cell / 2;
          var y = origin + r * cell + cell / 2;
          if (inLogo(x, y)) continue;
          ctx.beginPath();
          ctx.arc(x, y, cell * 0.32, 0, Math.PI * 2);
          ctx.fill();
        }
      }

      function roundRect(x, y, w, h, radius) {
        var rad = Math.min(radius, w / 2, h / 2);
        ctx.beginPath();
        ctx.moveTo(x + rad, y);
        ctx.arcTo(x + w, y, x + w, y + h, rad);
        ctx.arcTo(x + w, y + h, x, y + h, rad);
        ctx.arcTo(x, y + h, x, y, rad);
        ctx.arcTo(x, y, x + w, y, rad);
        ctx.closePath();
      }

      function drawFinder(row, col) {
        var fx = origin + col * cell;
        var fy = origin + row * cell;
        var s = cell * 7;
        roundRect(fx, fy, s, s, cell * 1.15);
        ctx.fillStyle = WHITE;
        ctx.fill();
        roundRect(fx + cell, fy + cell, cell * 5, cell * 5, cell * 0.85);
        ctx.fillStyle = PLATE;
        ctx.fill();
        roundRect(fx + cell * 2, fy + cell * 2, cell * 3, cell * 3, cell * 0.65);
        ctx.fillStyle = WHITE;
        ctx.fill();
      }

      drawFinder(0, 0);
      drawFinder(0, n - 7);
      drawFinder(n - 7, 0);
    }

    var innerR = logoR + R * 0.05;
    var outerR = R * 0.97;
    var rings = 7;
    var pitch = (outerR - innerR) / rings;
    var stroke = pitch * 0.38;
    ctx.lineCap = 'round';
    ctx.lineWidth = stroke;

    var i;
    for (i = 0; i < rings; i++) {
      var ringR = innerR + pitch * (i + 0.5);
      var slots = 64;
      var slot = (2 * Math.PI) / slots;
      var s = 0;
      while (s < slots) {
        var run = 0;
        var decorate = false;
        while (s + run < slots) {
          var ang = (s + run + 0.5) * slot;
          var px = cx + ringR * Math.cos(ang);
          var py = cy + ringR * Math.sin(ang);
          var inQr = px >= origin && py >= origin && px < origin + qrSize && py < origin + qrSize;
          var on = false;
          if (inQr && n > 0) {
            var col = Math.floor((px - origin) / cell);
            var row = Math.floor((py - origin) / cell);
            if (!inFinder(row, col, n) && qrDark(row, col) && !inLogo(px, py)) {
              on = true;
            }
          } else if (!inQr) {
            on = hashBit(payload, i * 64 + s + run) === 1;
            decorate = true;
          }
          if (!on) break;
          run += 1;
        }
        if (run > 0) {
          var start = s * slot + slot * 0.12;
          var sweep = run * slot - slot * 0.24;
          if (sweep > 0.01) {
            ctx.strokeStyle = decorate && run === 1 ? GRAY : WHITE;
            ctx.beginPath();
            ctx.arc(cx, cy, ringR, start, start + sweep);
            ctx.stroke();
          }
          s += run;
        } else {
          s += 1;
        }
      }
    }

    if (showLogo) {
      ctx.beginPath();
      ctx.arc(cx, cy, logoR, 0, Math.PI * 2);
      ctx.fillStyle = WHITE;
      ctx.fill();
      if (logo) {
        var lr = logoR * 0.86;
        ctx.save();
        ctx.beginPath();
        ctx.arc(cx, cy, lr, 0, Math.PI * 2);
        ctx.clip();
        ctx.drawImage(logo, cx - lr, cy - lr, lr * 2, lr * 2);
        ctx.restore();
      }
    }

    return canvas;
  }

  function loadImage(url) {
    return new Promise(function (resolve, reject) {
      var img = new Image();
      img.decoding = 'async';
      img.onload = function () { resolve(img); };
      img.onerror = function () { reject(new Error('logo')); };
      img.src = url;
    });
  }

  function waitForSource(hidden, attempt) {
    attempt = attempt || 0;
    return new Promise(function (resolve) {
      var canvas = hidden.querySelector('canvas');
      if (canvas && canvas.width > 0) {
        resolve(canvas);
        return;
      }
      var img = hidden.querySelector('img');
      if (img && img.complete && img.naturalWidth > 0) {
        resolve(img);
        return;
      }
      if (attempt >= 40) {
        resolve(null);
        return;
      }
      global.setTimeout(function () {
        waitForSource(hidden, attempt + 1).then(resolve);
      }, 40);
    });
  }

  function mount(options) {
    var el = options && options.el;
    var text = String((options && options.text) || '');
    if (!el || !text) {
      return Promise.resolve('');
    }

    var size = Number(options.size) || 220;
    var logoUrl = options.logoUrl || '/assets/CCS.png';
    var showLogo = options.showLogo !== false && size >= 110;

    var hidden = document.createElement('div');
    hidden.setAttribute('aria-hidden', 'true');
    hidden.style.cssText = 'position:absolute;left:-9999px;top:-9999px;width:0;height:0;overflow:hidden;';
    document.body.appendChild(hidden);

    if (typeof QRCode !== 'undefined') {
      try {
        new QRCode(hidden, {
          text: text,
          width: 400,
          height: 400,
          correctLevel: QRCode.CorrectLevel.H,
          colorDark: '#000000',
          colorLight: '#ffffff',
        });
      } catch (err) {
        hidden.remove();
        hidden = null;
      }
    } else {
      hidden.remove();
      hidden = null;
    }

    return Promise.all([
      hidden ? waitForSource(hidden) : Promise.resolve(null),
      showLogo ? loadImage(logoUrl).catch(function () { return null; }) : Promise.resolve(null),
    ]).then(function (results) {
      var source = results[0];
      var logo = results[1];
      if (hidden) hidden.remove();

      var matrix = source ? matrixFromQrSource(source) : null;
      var canvas = paintCode(size, matrix, logo, showLogo && !!matrix, text);
      if (!canvas && source && source.toDataURL) {
        el.replaceChildren(source);
        return source.toDataURL('image/png');
      }
      if (!canvas) return '';
      el.replaceChildren(canvas);
      var dataUrl = canvas.toDataURL('image/png');
      el.dataset.qrDataUrl = dataUrl;
      return dataUrl;
    });
  }

  global.PulseQR = { mount: mount };
})(window);
