/**
 * طباعة: وقت + تقدير عدد الصفحات
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
      document.querySelector('.hx-print-content .si-print-area') ||
      document.querySelector('.hx-print-content .ora-stmt') ||
      document.querySelector('.hx-print-content') ||
      document.querySelector('main') ||
      document.body;
    var h = Math.max(area.scrollHeight || 0, area.offsetHeight || 0, 800);
    var total = Math.max(1, Math.ceil(h / 900));
    numEls.forEach(function (el) {
      el.textContent = String(total);
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
