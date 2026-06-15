(function () {
  'use strict';

  var form = document.getElementById('tax-rates-form');
  if (!form) return;

  document.addEventListener('master-toolbar', function (e) {
    if (!e.detail || e.detail.action !== 'save') return;
    e.preventDefault();
    e.stopImmediatePropagation();
    if (typeof form.reportValidity === 'function' && !form.reportValidity()) {
      return;
    }
    if (typeof form.requestSubmit === 'function') {
      form.requestSubmit();
    } else {
      form.submit();
    }
  });
})();
