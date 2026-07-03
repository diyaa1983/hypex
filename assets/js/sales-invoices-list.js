(function () {
  'use strict';

  var screen = document.getElementById('sales-invoices-list-screen');
  if (!screen) return;

  var postUrl = screen.getAttribute('data-post-url') || '';
  var deleteUrl = screen.getAttribute('data-delete-url') || '';
  var csrf = screen.getAttribute('data-csrf') || '';
  var canPost = screen.getAttribute('data-can-post') === '1';
  var canDelete = screen.getAttribute('data-can-delete') === '1';
  var unpostedEl = document.getElementById('sal-inv-unposted-count');
  var postSelectedBtn = document.getElementById('sal-inv-post-selected');
  var checkAll = document.getElementById('sal-inv-check-all');

  function updateUnpostedCount(n) {
    if (unpostedEl && n !== undefined) {
      unpostedEl.textContent = String(n);
    }
  }

  function postInvoices(ids, onDone) {
    if (!canPost) {
      AppDialog.error('ليس لديك صلاحية ترحيل فواتير المبيعات.');
      return;
    }
    if (!postUrl || !ids.length) return;

    function sendPost(gps) {
      var fd = new FormData();
      fd.append('_csrf', csrf);
      ids.forEach(function (id) {
        fd.append('invoice_ids[]', String(id));
      });
      if (window.AppGeo && AppGeo.appendToFormData && gps) {
        AppGeo.appendToFormData(fd, gps, 'desktop');
      }
      fetch(postUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(function (r) {
          return r.json();
        })
        .then(function (data) {
          if (!data.ok && (!data.errors || !data.errors.length)) {
            AppDialog.error(data.error || data.message || 'تعذر الترحيل.');
            return;
          }
          var msg = AppDialog.formatActionMessage
            ? AppDialog.formatActionMessage(data, { fallback: 'تم الترحيل.' })
            : data.message || 'تم الترحيل.';
          AppDialog.success(msg).then(function () {
            updateUnpostedCount(data.remaining_invoices);
            if (typeof onDone === 'function') onDone();
            else window.location.reload();
          });
        })
        .catch(function () {
          AppDialog.error('تعذر الاتصال بالخادم.');
        });
    }

    function startPost() {
      if (window.APP_GPS_ENABLED && window.AppGeo && AppGeo.withGpsForPost) {
        AppGeo.withGpsForPost('desktop', function (gps) {
          if (gps === undefined) {
            return;
          }
          sendPost(gps);
        });
        return;
      }
      sendPost(null);
    }

    startPost();
  }

  function selectedIds() {
    var ids = [];
    document.querySelectorAll('.sal-inv-row-check:checked').forEach(function (cb) {
      var v = parseInt(cb.value, 10);
      if (v > 0) ids.push(v);
    });
    return ids;
  }

  function syncPostSelectedBtn() {
    if (!postSelectedBtn) return;
    postSelectedBtn.disabled = selectedIds().length === 0;
  }

  document.querySelectorAll('.sal-inv-row-check').forEach(function (cb) {
    cb.addEventListener('change', syncPostSelectedBtn);
  });

  if (checkAll) {
    checkAll.addEventListener('change', function () {
      var on = checkAll.checked;
      document.querySelectorAll('.sal-inv-row-check').forEach(function (cb) {
        cb.checked = on;
      });
      syncPostSelectedBtn();
    });
  }

  if (postSelectedBtn && !canPost) {
    postSelectedBtn.hidden = true;
  }

  if (postSelectedBtn) {
    postSelectedBtn.addEventListener('click', function () {
      var ids = selectedIds();
      if (!ids.length) return;
      AppDialog.confirm('ترحيل ' + ids.length + ' فاتورة محددة؟\n\nسيتم تسجيل موقع هذا الجهاز (GPS) مع كل فاتورة تُرحَّل.', { title: 'ترحيل' }).then(function (ok) {
        if (ok) postInvoices(ids);
      });
    });
  }

  document.querySelectorAll('.sal-inv-post-one').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var id = parseInt(btn.getAttribute('data-id'), 10);
      if (id < 1) return;
      AppDialog.confirm('ترحيل هذه الفاتورة (مخزون + حساب العميل)؟\n\nسيتم تسجيل موقع هذا الجهاز (GPS).', { title: 'ترحيل' }).then(function (ok) {
        if (ok) postInvoices([id]);
      });
    });
  });

  function deleteInvoices(ids, labels) {
    if (!canDelete) {
      AppDialog.error('ليس لديك صلاحية حذف فواتير المبيعات.');
      return;
    }
    if (!deleteUrl || !ids.length) return;
    var label =
      labels && labels.length === 1
        ? '«' + labels[0] + '»'
        : ids.length + ' فاتورة';
    AppDialog.confirm(
      'حذف ' +
        label +
        ' نهائياً؟\nلا يمكن التراجع. يُسمح فقط بالفواتير غير المرحّلة وبعد حذف جميع بنود المواد منها.',
      { title: 'حذف الفاتورة', danger: true, okText: 'حذف' }
    ).then(function (ok) {
      if (!ok) return;
      var fd = new FormData();
      fd.append('_csrf', csrf);
      ids.forEach(function (id) {
        fd.append('invoice_ids[]', String(id));
      });
      fetch(deleteUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(function (r) {
          return r.json();
        })
        .then(function (data) {
          if (!data.ok) {
            var errMsg =
              data.error ||
              (data.errors && data.errors.length ? data.errors.join('؛ ') : '') ||
              data.message ||
              'تعذر الحذف.';
            AppDialog.error(errMsg);
            return;
          }
          AppDialog.success(data.message || 'تم الحذف.');
          updateUnpostedCount(data.remaining_invoices);
          window.location.reload();
        })
        .catch(function () {
          AppDialog.error('تعذر الاتصال بالخادم.');
        });
    });
  }

  document.querySelectorAll('.sal-inv-delete-one').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var id = parseInt(btn.getAttribute('data-id'), 10);
      if (id < 1) return;
      var no = btn.getAttribute('data-no') || String(id);
      deleteInvoices([id], [no]);
    });
  });

  document.addEventListener('master-toolbar', function (e) {
    if (!e.detail) return;
    if (e.detail.action === 'post') {
      e.preventDefault();
      var ids = selectedIds();
      if (!ids.length) {
        AppDialog.alert('حدّد فاتورة واحدة على الأقل غير مرحّلة للترحيل.', { type: 'warning' });
        return;
      }
      AppDialog.confirm('ترحيل ' + ids.length + ' فاتورة (مخزون + حساب العميل)؟\n\nسيتم تسجيل موقع هذا الجهاز (GPS).', { title: 'ترحيل' }).then(
        function (ok) {
          if (ok) postInvoices(ids);
        }
      );
      return;
    }
    if (e.detail.action === 'delete') {
      e.preventDefault();
      var ids = selectedIds();
      if (!ids.length) {
        AppDialog.alert('حدّد فاتورة واحدة على الأقل غير مرحّلة للحذف.', { type: 'warning' });
        return;
      }
      var labels = [];
      document.querySelectorAll('.sal-inv-row-check:checked').forEach(function (cb) {
        var tr = cb.closest('tr');
        if (!tr) return;
        var code = tr.querySelector('code');
        labels.push(code ? code.textContent.trim() : cb.value);
      });
      deleteInvoices(ids, labels);
    }
  });
})();
