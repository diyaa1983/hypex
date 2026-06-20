(function () {
  'use strict';

  var screen = document.getElementById('fin-emp-adv-screen');
  if (!screen) return;

  var paymentBase = screen.getAttribute('data-payment-url') || '';

  function alertMsg(msg) {
    if (window.appDialogAlert) {
      window.appDialogAlert(msg, 'warning');
      return;
    }
    window.alert(msg);
  }

  screen.querySelectorAll('.fin-emp-adv-disburse-btn').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var advanceId = parseInt(btn.getAttribute('data-advance-id') || '0', 10);
      if (advanceId < 1) return;
      var sel = document.getElementById('fin-emp-adv-cash-' + advanceId);
      var cashId = sel ? parseInt(sel.value || '0', 10) : 0;
      if (cashId < 1) {
        alertMsg('اختر حساب الصندوق أو البنك الذي يُخصم منه مبلغ السلفة.');
        if (sel) sel.focus();
        return;
      }
      var url = paymentBase;
      url += (url.indexOf('?') >= 0 ? '&' : '?') + 'disburse_advance=' + encodeURIComponent(String(advanceId));
      url += '&cash_account_id=' + encodeURIComponent(String(cashId));
      window.location.href = url;
    });
  });
})();
