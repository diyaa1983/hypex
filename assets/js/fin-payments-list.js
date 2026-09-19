(function () {
  'use strict';

  var screen = document.getElementById('fin-payments-list-screen');
  if (!screen) return;

  var postUrl = screen.getAttribute('data-post-url') || '';
  var deleteUrl = screen.getAttribute('data-delete-url') || '';
  var csrf = screen.getAttribute('data-csrf') || '';
  var unpostedEl = document.getElementById('fin-py-unposted-count');
  var postSelectedBtn = document.getElementById('fin-py-post-selected');
  var checkAll = document.getElementById('fin-py-check-all');

  function updateUnpostedCount(n) {
    if (unpostedEl && n !== undefined) {
      unpostedEl.textContent = String(n);
    }
  }

  function postVouchers(ids, onDone) {
    if (!postUrl || !ids.length) return;
    var fd = new FormData();
    fd.append('_csrf', csrf);
    ids.forEach(function (id) {
      fd.append('voucher_ids[]', String(id));
    });
    fetch(postUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
      .then(function (r) {
        return r.json();
      })
      .then(function (data) {
        if (!data.ok) {
          AppDialog.error(data.message || data.error || 'تعذر الترحيل.');
          return;
        }
        AppDialog.success(data.message || 'تم الترحيل.');
        if (data.remaining_payments !== undefined) {
          updateUnpostedCount(data.remaining_payments);
        }
        if (typeof onDone === 'function') onDone();
        else window.location.reload();
      })
      .catch(function () {
        AppDialog.error('تعذر الاتصال بالخادم.');
      });
  }

  function selectedIds() {
    var ids = [];
    document.querySelectorAll('.fin-py-row-check:checked').forEach(function (cb) {
      var v = parseInt(cb.value, 10);
      if (v > 0) ids.push(v);
    });
    return ids;
  }

  function syncPostSelectedBtn() {
    if (!postSelectedBtn) return;
    postSelectedBtn.disabled = selectedIds().length === 0;
  }

  document.querySelectorAll('.fin-py-row-check').forEach(function (cb) {
    cb.addEventListener('change', syncPostSelectedBtn);
  });

  if (checkAll) {
    checkAll.addEventListener('change', function () {
      var on = checkAll.checked;
      document.querySelectorAll('.fin-py-row-check').forEach(function (cb) {
        cb.checked = on;
      });
      syncPostSelectedBtn();
    });
  }

  if (postSelectedBtn) {
    postSelectedBtn.addEventListener('click', function () {
      var ids = selectedIds();
      if (!ids.length) return;
      AppDialog.confirm('ترحيل ' + ids.length + ' سند صرف محدد؟', { title: 'ترحيل' }).then(function (ok) {
        if (ok) postVouchers(ids);
      });
    });
  }

  document.querySelectorAll('.fin-py-post-one').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var id = parseInt(btn.getAttribute('data-id'), 10);
      if (id < 1) return;
      AppDialog.confirm('ترحيل سند الصرف (قيد محاسبي + كشف حساب الطرف)؟', { title: 'ترحيل' }).then(function (ok) {
        if (ok) postVouchers([id]);
      });
    });
  });

  function deleteVouchers(ids, labels) {
    if (!deleteUrl || !ids.length) return;
    var label =
      labels && labels.length === 1 ? '«' + labels[0] + '»' : ids.length + ' سند';
    AppDialog.confirm(
      'حذف ' + label + ' نهائياً؟\nلا يمكن التراجع. يُسمح فقط بالسندات غير المرحّلة.',
      { title: 'حذف السند', danger: true, okText: 'حذف' }
    ).then(function (ok) {
      if (!ok) return;
      var fd = new FormData();
      fd.append('_csrf', csrf);
      ids.forEach(function (id) {
        fd.append('voucher_ids[]', String(id));
      });
      fetch(deleteUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(function (r) {
          return r.json();
        })
        .then(function (data) {
          if (!data.ok) {
            AppDialog.error(
              data.message ||
                (data.errors && data.errors.length ? data.errors.join('؛ ') : '') ||
                'تعذر الحذف.'
            );
            return;
          }
          AppDialog.success(data.message || 'تم الحذف.');
          window.location.reload();
        })
        .catch(function () {
          AppDialog.error('تعذر الاتصال بالخادم.');
        });
    });
  }

  document.querySelectorAll('.fin-py-delete-one').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var id = parseInt(btn.getAttribute('data-id'), 10);
      var no = btn.getAttribute('data-no') || '';
      if (id < 1) return;
      deleteVouchers([id], [no]);
    });
  });

  document.addEventListener('master-toolbar', function (e) {
    if (!e.detail) return;
    if (e.detail.action === 'post') {
      e.preventDefault();
      var ids = selectedIds();
      if (!ids.length) {
        AppDialog.alert('حدّد سند صرف واحد على الأقل غير مرحّل.', { type: 'warning' });
        return;
      }
      AppDialog.confirm('ترحيل ' + ids.length + ' سند صرف (قيد محاسبي + كشف حساب الطرف)؟', {
        title: 'ترحيل',
      }).then(function (ok) {
        if (ok) postVouchers(ids);
      });
      return;
    }
    if (e.detail.action === 'delete') {
      e.preventDefault();
      var delIds = selectedIds();
      if (!delIds.length) {
        AppDialog.alert('حدّد سند صرف واحد على الأقل غير مرحّل للحذف.', { type: 'warning' });
        return;
      }
      var labels = [];
      document.querySelectorAll('.fin-py-row-check:checked').forEach(function (cb) {
        var tr = cb.closest('tr');
        if (!tr) return;
        var code = tr.querySelector('code');
        labels.push(code ? code.textContent.trim() : cb.value);
      });
      deleteVouchers(delIds, labels);
    }
  });
})();
