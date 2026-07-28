(function () {
  'use strict';

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
    form.addEventListener('submit', function () {
      var amt = form.querySelector('.fin-poc-amount');
      if (amt) {
        var n = parseNum(amt.value);
        if (n > 0) amt.value = String(n);
      }
    });
  }
})();
