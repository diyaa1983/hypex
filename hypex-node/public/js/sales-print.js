/**
 * طباعة موحّدة: وقت الطباعة + ترقيم صفحات تقريبي عند المعاينة/الطباعة
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

  /**
   * Chrome لا يدعم counter(page) في العناصر العادية.
   * نقدّر عدد الصفحات من ارتفاع المحتوى ونرقم كل صفحة مرئية في التذييل الثابت.
   * التذييل الثابت نفسه يتكرر؛ نعرض "الكل" كتقريب: 1–N
   */
  function stampPageEstimate() {
    var numEls = document.querySelectorAll('.hx-page-num');
    if (!numEls.length) return;
    var area =
      document.querySelector('.si-print-area') ||
      document.querySelector('.ora-stmt') ||
      document.querySelector('main') ||
      document.body;
    var h = area ? area.scrollHeight : document.body.scrollHeight;
    // ارتفاع صفحة A4 تقريباً بعد الهوامش (بكسل شاشة ~ 1122px للمحتوى)
    var pagePx = 1000;
    var total = Math.max(1, Math.ceil(h / pagePx));
    var label = total <= 1 ? '1' : '1–' + total + ' / ' + total;
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
