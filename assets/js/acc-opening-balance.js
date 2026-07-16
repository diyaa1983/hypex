(function () {
  'use strict';

  var form = document.getElementById('acc-opening-balance-form');
  var table = document.getElementById('acc-opening-table');
  var unpostForm = document.getElementById('acc-opening-unpost-form');
  var page = document.querySelector('.acc-opening-balance-ora-screen');
  if (!form || !table) {
    return;
  }

  function isPosted() {
    return page && page.getAttribute('data-is-posted') === '1';
  }

  function submitUnpostForm() {
    if (!unpostForm) {
      return;
    }
    if (!isPosted()) {
      if (window.AppDialog && AppDialog.alert) {
        AppDialog.alert("لا يوجد قيد افتتاحي مرحّل لهذه السنة.", { type: 'warning', theme: 'oracle' });
      }
      return;
    }
    if (typeof unpostForm.requestSubmit === 'function') {
      unpostForm.requestSubmit();
    } else {
      unpostForm.submit();
    }
  }

  function parseAmount(value) {
    var s = String(value || '').replace(/,/g, '.').trim();
    if (s === '') {
      return 0;
    }
    var n = parseFloat(s);
    return Number.isFinite(n) && n > 0 ? n : 0;
  }

  function formatDisplay(n) {
    if (!Number.isFinite(n)) {
      return '0.00';
    }
    return n.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 6 });
  }

  function recalcTotals() {
    var debitInputs = table.querySelectorAll('.acc-opening-debit');
    var creditInputs = table.querySelectorAll('.acc-opening-credit');
    var totalDebit = 0;
    var totalCredit = 0;
    var i;

    for (i = 0; i < debitInputs.length; i++) {
      totalDebit += parseAmount(debitInputs[i].value);
    }
    for (i = 0; i < creditInputs.length; i++) {
      totalCredit += parseAmount(creditInputs[i].value);
    }

    var diff = Math.round((totalDebit - totalCredit) * 1000000) / 1000000;
    var diffEl = document.getElementById('acc-opening-diff');
    var debitEl = document.getElementById('acc-opening-total-debit');
    var creditEl = document.getElementById('acc-opening-total-credit');

    if (debitEl) {
      debitEl.textContent = formatDisplay(totalDebit);
    }
    if (creditEl) {
      creditEl.textContent = formatDisplay(totalCredit);
    }
    if (diffEl) {
      diffEl.textContent = formatDisplay(Math.abs(diff));
      diffEl.classList.toggle('is-balanced', Math.abs(diff) < 0.000001);
      diffEl.classList.toggle('is-unbalanced', Math.abs(diff) >= 0.000001);
    }
    var diffInline = document.getElementById('acc-opening-diff-inline');
    if (diffInline) {
      diffInline.textContent = formatDisplay(Math.abs(diff));
      diffInline.classList.toggle('is-balanced', Math.abs(diff) < 0.000001);
      diffInline.classList.toggle('is-unbalanced', Math.abs(diff) >= 0.000001);
    }

    var rows = table.querySelectorAll('tbody tr');
    rows.forEach(function (row) {
      var d = row.querySelector('.acc-opening-debit');
      var c = row.querySelector('.acc-opening-credit');
      var has = parseAmount(d && d.value) > 0 || parseAmount(c && c.value) > 0;
      row.classList.toggle('has-value', has);
    });
  }

  table.addEventListener('input', function (e) {
    var t = e.target;
    if (!t || !t.classList) {
      return;
    }
    if (t.classList.contains('acc-opening-debit') && parseAmount(t.value) > 0) {
      var credit = t.closest('tr').querySelector('.acc-opening-credit');
      if (credit && parseAmount(credit.value) > 0) {
        credit.value = '';
      }
    }
    if (t.classList.contains('acc-opening-credit') && parseAmount(t.value) > 0) {
      var debit = t.closest('tr').querySelector('.acc-opening-debit');
      if (debit && parseAmount(debit.value) > 0) {
        debit.value = '';
      }
    }
    if (t.classList.contains('acc-opening-amount')) {
      recalcTotals();
    }
  });

  var filterInput = document.getElementById('acc_opening_account_filter');
  if (filterInput) {
    filterInput.addEventListener('input', function () {
      var q = filterInput.value.trim().toLowerCase();
      table.querySelectorAll('tbody tr').forEach(function (row) {
        var hay = row.getAttribute('data-search') || '';
        row.classList.toggle('is-hidden', q !== '' && hay.indexOf(q) === -1);
      });
    });
  }

  form.addEventListener('submit', function (e) {
    recalcTotals();
    var diffEl = document.getElementById('acc-opening-diff');
    if (diffEl && diffEl.classList.contains('is-unbalanced')) {
      if (!window.confirm('مجموع المدين لا يساوي مجموع الدائن. هل تريد المتابعة؟')) {
        e.preventDefault();
      }
    }
  });

  document.addEventListener('master-toolbar', function (e) {
    if (!e.detail) {
      return;
    }
    var bar = document.getElementById('master-toolbar');
    if (bar && bar.getAttribute('data-active-route') !== 'acc_opening_balance') {
      return;
    }
    if (e.detail.action === 'save') {
      e.preventDefault();
      e.stopImmediatePropagation();
      if (typeof form.requestSubmit === 'function') {
        form.requestSubmit();
      } else {
        form.submit();
      }
      return;
    }
    if (e.detail.action === 'unpost') {
      e.preventDefault();
      e.stopImmediatePropagation();
      submitUnpostForm();
    }
  });

  var btnSave = document.getElementById('acc-opening-btn-save');
  if (btnSave) {
    btnSave.addEventListener('click', function () {
      if (typeof form.requestSubmit === 'function') {
        form.requestSubmit();
      } else {
        form.submit();
      }
    });
  }

  var btnUnpost = document.getElementById('acc-opening-btn-unpost');
  if (btnUnpost) {
    btnUnpost.addEventListener('click', submitUnpostForm);
  }

  recalcTotals();
})();
