/**
 * طباعة بسيطة — محتوى الصفحة فقط (بدون ترويسة/تذييل/شعار)
 */
(function () {
  'use strict';

  document.querySelectorAll('.si-btn--print, [data-print]').forEach(function (btn) {
    btn.addEventListener('click', function (e) {
      e.preventDefault();
      window.print();
    });
  });
})();
