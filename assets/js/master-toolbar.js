(function () {

  'use strict';



  var bar = document.getElementById('master-toolbar');

  if (!bar) return;



  var defaultExitUrl = bar.getAttribute('data-exit-url') || '';



  bar.addEventListener('click', function (e) {

    var btn = e.target.closest('[data-master-action]');

    if (!btn || !bar.contains(btn)) return;



    var action = btn.getAttribute('data-master-action');

    if (!action) return;



    var ev = new CustomEvent('master-toolbar', {

      bubbles: true,

      cancelable: true,

      detail: { action: action, button: btn },

    });

    document.dispatchEvent(ev);



    if (ev.defaultPrevented) return;

    if (action === 'search') {
      var qInp = document.querySelector('form[method="get"] input[name="q"]');
      if (qInp) {
        var listForm = qInp.closest('form');
        if (listForm) {
          var q = String(qInp.value || '').trim();
          if (!q) {
            if (window.AppDialog && AppDialog.alert) {
              AppDialog.alert('أدخل نص البحث ثم اضغط بحث.', { type: 'warning' });
            }
            qInp.focus();
            return;
          }
          if (typeof listForm.requestSubmit === 'function') {
            listForm.requestSubmit();
          } else {
            listForm.submit();
          }
          return;
        }
      }
    }

    if (action === 'post') {
      var route = bar.getAttribute('data-active-route') || '';
      if (route === 'purchase_orders' || route === 'purchase_orders_list') {
        return;
      }

      AppDialog.alert(

        'الترحيل من شاشة المستند أو من قائمة الترحيل.\nفاتورة البيع: صرف مخزون + حساب العميل.\nمرتجع المبيعات: تسجيل حساب العميل.\nفاتورة الشراء: إدخال مخزون + ذمة المورد.\nمردود المشتريات: صرف مخزون + تعديل ذمة المورد.',

        { type: 'info', title: 'ترحيل' }

      );

      return;

    }



    if (action === 'print' && !ev.defaultPrevented) {
      if (document.querySelector('.report-sales-page .report-sales-print-area')) {
        window.print();
        return;
      }
      if (document.querySelector('.sal-gps-list-page .report-sales-print-area')) {
        window.print();
        return;
      }
    }

    if (action === 'exit' && defaultExitUrl) {
      if (window.ScreenExitGuard && typeof window.ScreenExitGuard.confirmLeave === 'function') {
        window.ScreenExitGuard.confirmLeave(function () {
          window.location.href = defaultExitUrl;
        });
        return;
      }
      if (window.ManagerScreenExit && typeof window.ManagerScreenExit.confirmLeave === 'function') {
        window.ManagerScreenExit.confirmLeave(function () {
          window.location.href = defaultExitUrl;
        });
        return;
      }
      window.location.href = defaultExitUrl;
    }

  });

})();

