/**
 * طباعة تقارير Node 2027: ختم وقت الطباعة + جاهزية قبل window.print
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

  function preparePrint() {
    stampPrintTime();
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
