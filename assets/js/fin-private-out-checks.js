(function () {
  'use strict';

  var screen = document.getElementById('fin-private-out-checks-screen');
  if (!screen) return;

  function parseNum(v) {
    if (v === '' || v == null) return 0;
    var s = String(v).replace(/,/g, '');
    var x = parseFloat(s);
    return isFinite(x) ? x : 0;
  }

  function fmtMoney(v) {
    if (window.AppFormat && AppFormat.fmt) {
      return AppFormat.fmt(v);
    }
    return parseNum(v).toFixed(2);
  }

  function dialogAlert(msg, type) {
    if (window.AppDialog && AppDialog.alert) {
      AppDialog.alert(msg, { type: type || 'warning' });
      return;
    }
    window.alert(msg);
  }

  document.querySelectorAll('.fin-poc-amount').forEach(function (inp) {
    inp.addEventListener('blur', function () {
      var n = parseNum(inp.value);
      inp.value = n > 0 ? fmtMoney(n) : '';
    });
    inp.addEventListener('focus', function () {
      var n = parseNum(inp.value);
      if (n > 0) inp.value = String(n);
    });
  });

  var form = document.getElementById('fin-private-out-checks-form');
  if (form) {
    form.addEventListener('submit', function (e) {
      var amt = form.querySelector('.fin-poc-amount');
      if (amt) {
        var n = parseNum(amt.value);
        if (n <= 0) {
          e.preventDefault();
          dialogAlert('أدخل مبلغ الشيك.', 'warning');
          return;
        }
        amt.value = String(n);
      }
      var due = form.querySelector('[name="due_date"]');
      if (due && !String(due.value || '').trim()) {
        e.preventDefault();
        dialogAlert('تاريخ الاستحقاق مطلوب للتذكير.', 'warning');
      }
    });
  }

  var filterForm = document.getElementById('fin-poc-filters-form');
  if (filterForm) {
    var statusEl = document.getElementById('fin-poc-filter-status');
    if (statusEl) {
      statusEl.addEventListener('change', function () {
        filterForm.submit();
      });
    }

    var results = document.querySelector('.fin-checks-results');
    if (results && !window.location.search.match(/(?:^|&)(new|id)=/)) {
      results.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
  }
})();
