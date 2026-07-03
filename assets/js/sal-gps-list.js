(function () {
  'use strict';

  var page = document.querySelector('.sal-gps-list-page');
  if (!page) return;

  var listCfg = window.SalGpsListConfig || {};

  page.addEventListener('click', function (e) {
    var btn = e.target && e.target.closest ? e.target.closest('.sal-gps-attach-btn') : null;
    if (!btn || btn.disabled) return;
    e.preventDefault();

    var invoiceId = parseInt(btn.getAttribute('data-invoice-id') || '0', 10);
    var invoiceNo = btn.getAttribute('data-invoice-no') || String(invoiceId);
    if (invoiceId < 1 || !listCfg.attachApi) return;

    if (!window.APP_GPS_ENABLED || !window.AppGeo || !AppGeo.withGpsForPost) {
      if (window.AppDialog && AppDialog.alert) {
        AppDialog.alert('GPS غير متاح في هذا المتصفح. فعّل الموقع في Windows والمتصفح.', { type: 'warning' });
      }
      return;
    }

    var msg = 'تسجيل موقع هذا الجهاز على الفاتورة «' + invoiceNo + '»؟';
    var proceed = window.AppDialog && AppDialog.confirm
      ? AppDialog.confirm(msg, { title: 'تسجيل GPS', okText: 'نعم' })
      : Promise.resolve(window.confirm(msg));

    proceed.then(function (ok) {
      if (!ok) return;
      btn.disabled = true;
      AppGeo.withGpsForPost('desktop', function (gps) {
        if (!gps) {
          btn.disabled = false;
          if (window.AppDialog && AppDialog.alert) {
            AppDialog.alert('لم يُحدَّد موقع. اسمح للمتصفح بالوصول للموقع أو اختر موقعاً على الخريطة.', {
              type: 'warning',
            });
          }
          return;
        }
        var fd = new FormData();
        fd.append('_csrf', listCfg.csrf || '');
        fd.append('invoice_id', String(invoiceId));
        AppGeo.appendToFormData(fd, gps, 'desktop');
        fetch(listCfg.attachApi, { method: 'POST', body: fd, credentials: 'same-origin' })
          .then(function (r) {
            return r.json();
          })
          .then(function (data) {
            if (!data || !data.ok) {
              btn.disabled = false;
              if (window.AppDialog && AppDialog.error) {
                AppDialog.error((data && (data.error || data.message)) || 'تعذر حفظ الموقع.');
              }
              return;
            }
            if (window.AppDialog && AppDialog.success) {
              AppDialog.success(data.message || 'تم تسجيل الموقع.').then(function () {
                window.location.reload();
              });
            } else {
              window.location.reload();
            }
          })
          .catch(function () {
            btn.disabled = false;
            if (window.AppDialog && AppDialog.error) {
              AppDialog.error('تعذر الاتصال بالخادم.');
            }
          });
      });
    });
  });

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
