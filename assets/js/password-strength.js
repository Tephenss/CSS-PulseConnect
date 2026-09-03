(function (window) {
  'use strict';

  function score(value) {
    var v = String(value || '');
    var s = 0;
    if (v.length >= 8) s++;
    if (/[A-Z]/.test(v)) s++;
    if (/[a-z]/.test(v)) s++;
    if (/\d/.test(v)) s++;
    if (/[^A-Za-z0-9]/.test(v)) s++;
    return s;
  }

  function isStrong(value) {
    var v = String(value || '');
    return v.length >= 8 &&
      /[A-Z]/.test(v) &&
      /[a-z]/.test(v) &&
      /\d/.test(v) &&
      /[^A-Za-z0-9]/.test(v);
  }

  function label(s) {
    if (s >= 5) return 'Strong';
    if (s >= 3) return 'Medium';
    return 'Weak';
  }

  function color(s) {
    if (s >= 5) return '#16a34a';
    if (s >= 3) return '#d97706';
    return '#dc2626';
  }

  function bindMeter(input, meter) {
    if (!input || !meter) return;
    var bar = meter.querySelector('[data-pw-bar]');
    var fill = meter.querySelector('[data-pw-fill]');
    var text = meter.querySelector('[data-pw-label]');
    var rules = meter.querySelectorAll('[data-pw-rule]');

    function paint() {
      var v = input.value || '';
      var s = score(v);
      var pct = (s / 5) * 100;
      var c = color(s);
      if (fill) {
        fill.style.width = pct + '%';
        fill.style.backgroundColor = c;
      }
      if (text) {
        text.textContent = v === '' ? '' : label(s);
        text.style.color = c;
      }
      rules.forEach(function (el) {
        var rule = el.getAttribute('data-pw-rule') || '';
        var met = false;
        if (rule === 'len') met = v.length >= 8;
        else if (rule === 'upper') met = /[A-Z]/.test(v);
        else if (rule === 'lower') met = /[a-z]/.test(v);
        else if (rule === 'digit') met = /\d/.test(v);
        else if (rule === 'special') met = /[^A-Za-z0-9]/.test(v);
        el.classList.toggle('pw-rule-met', met);
      });
    }

    input.addEventListener('input', paint);
    paint();
  }

  window.PulsePassword = {
    score: score,
    isStrong: isStrong,
    label: label,
    bindMeter: bindMeter,
    error: 'Use 8+ chars with upper, lower, number, and symbol.',
  };
})(window);
