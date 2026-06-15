(function () {
  'use strict';

  var page = document.querySelector('.sal-gps-list-page');
  if (!page) return;

  document.addEventListener('master-toolbar', function (e) {
    if (!e.detail || !page.querySelector('.report-sales-print-area')) return;

    if (e.detail.action === 'print') {
      e.preventDefault();
      window.print();
      return;
    }

    if (e.detail.action === 'search') {
      var form = document.getElementById('sal-gps-list-filter');
      if (!form) return;
      e.preventDefault();
      if (typeof form.requestSubmit === 'function') {
        form.requestSubmit();
      } else {
        form.submit();
      }
    }
  });
})();
