(function () {
  'use strict';

  var form = document.getElementById('acc-period-close-form');
  if (!form) {
    return;
  }

  function submitForm() {
    if (typeof form.requestSubmit === 'function') {
      form.requestSubmit();
    } else {
      form.submit();
    }
  }

  document.addEventListener('master-toolbar', function (e) {
    if (!e.detail || e.detail.action !== 'save') {
      return;
    }
    var bar = document.getElementById('master-toolbar');
    if (bar && bar.getAttribute('data-active-route') !== 'acc_period_close') {
      return;
    }
    e.preventDefault();
    e.stopImmediatePropagation();
    submitForm();
  });

  var btnSave = document.getElementById('acc-period-btn-save');
  if (btnSave) {
    btnSave.addEventListener('click', submitForm);
  }
})();
