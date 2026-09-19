(function () {
  'use strict';

  var ALLOWED_TOOLBAR = { exit: 1, print: 1, pdf: 1, excel: 1 };

  function initLedgerDocumentView() {
    var form = document.querySelector('form[data-ledger-view="1"]');
    if (!form) return;

    document.body.classList.add('is-ledger-document-view');

    document.querySelectorAll('.sales-inv-btn-new, .sales-ret-btn-new').forEach(function (el) {
      el.hidden = true;
    });

    document.querySelectorAll('.sales-inv-no-arrow').forEach(function (el) {
      el.hidden = true;
      el.disabled = true;
    });

    var bar = document.getElementById('master-toolbar');
    if (bar) {
      bar.querySelectorAll('[data-master-action]').forEach(function (btn) {
        var action = btn.getAttribute('data-master-action') || '';
        if (!ALLOWED_TOOLBAR[action]) {
          btn.hidden = true;
        }
      });
    }

    document.addEventListener(
      'master-toolbar',
      function (e) {
        if (!e.detail || !ALLOWED_TOOLBAR[e.detail.action]) {
          e.preventDefault();
          e.stopImmediatePropagation();
        }
      },
      true
    );
  }

  document.addEventListener('DOMContentLoaded', initLedgerDocumentView);
})();
