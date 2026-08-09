/**
 * طباعة موحّدة: وقت الطباعة + عدد الصفحات التقريبي
 */
(function () {
  'use strict';

  function stampPrintTime() {
    var els = document.querySelectorAll('.si-print-when');
    var now = new Date();
    var pad = function (n) {
      return String(n).padStart(2, '0');
    };
    var text =
      pad(now.getDate()) +
      '-' +
      pad(now.getMonth() + 1) +
      '-' +
      now.getFullYear() +
      ' ' +
      pad(now.getHours()) +
      ':' +
      pad(now.getMinutes());
    els.forEach(function (el) {
      el.textContent = text;
    });
  }

  function stampPageEstimate() {
    var numEls = document.querySelectorAll('.hx-page-num');
    if (!numEls.length) return;
    var area =
      document.querySelector('.hx-print-shell-body') ||
      document.querySelector('.si-print-area') ||
      document.querySelector('.ora-stmt') ||
      document.querySelector('main') ||
      document.body;
    var h = Math.max(area.scrollHeight || 0, area.offsetHeight || 0, 1);
    /* ~ A4 content height in CSS px for typical screen/print scaling */
    var pagePx = 920;
    var total = Math.max(1, Math.ceil(h / pagePx));
    var label = String(total);
    numEls.forEach(function (el) {
      el.textContent = label;
    });
  }

  function preparePrint() {
    stampPrintTime();
    stampPageEstimate();
  }

  document.querySelectorAll('.si-btn--print, [data-print]').forEach(function (btn) {
    btn.addEventListener('click', function (e) {
      e.preventDefault();
      preparePrint();
      window.print();
    });
  });

  window.addEventListener('beforeprint', preparePrint);
})();
