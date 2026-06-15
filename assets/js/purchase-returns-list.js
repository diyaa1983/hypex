(function () {
  'use strict';

  var screen = document.getElementById('purchase-returns-list-screen');
  if (!screen) return;

  var postUrl = screen.getAttribute('data-post-url') || '';
  var deleteUrl = screen.getAttribute('data-delete-url') || '';
  var csrf = screen.getAttribute('data-csrf') || '';
  var unpostedEl = document.getElementById('pur-ret-unposted-count');
  var postSelectedBtn = document.getElementById('pur-ret-post-selected');
  var checkAll = document.getElementById('pur-ret-check-all');

  function updateUnpostedCount(n) {
    if (unpostedEl && n !== undefined) {
      unpostedEl.textContent = String(n);
    }
  }

  function postReturns(ids, onDone) {
    if (!postUrl || !ids.length) return;
    var fd = new FormData();
    fd.append('_csrf', csrf);
    ids.forEach(function (id) {
      fd.append('return_ids[]', String(id));
    });
    fetch(postUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
      .then(function (r) {
        return r.json();
      })
      .then(function (data) {
        if (!data.ok && (!data.errors || !data.errors.length)) {
          AppDialog.error(data.error || data.message || 'تعذر الترحيل.');
          return;
        }
        AppDialog.success(data.message || 'تم الترحيل.');
        updateUnpostedCount(data.remaining_returns);
        if (typeof onDone === 'function') onDone();
        else window.location.reload();
      })
      .catch(function () {
        AppDialog.error('تعذر الاتصال بالخادم.');
      });
  }

  function selectedIds() {
    var ids = [];
    document.querySelectorAll('.pur-ret-row-check:checked').forEach(function (cb) {
      var v = parseInt(cb.value, 10);
      if (v > 0) ids.push(v);
    });
    return ids;
  }

  function syncPostSelectedBtn() {
    if (!postSelectedBtn) return;
    postSelectedBtn.disabled = selectedIds().length === 0;
  }

  document.querySelectorAll('.pur-ret-row-check').forEach(function (cb) {
    cb.addEventListener('change', syncPostSelectedBtn);
  });

  if (checkAll) {
    checkAll.addEventListener('change', function () {
      var on = checkAll.checked;
      document.querySelectorAll('.pur-ret-row-check').forEach(function (cb) {
        cb.checked = on;
      });
      syncPostSelectedBtn();
    });
  }

  if (postSelectedBtn) {
    postSelectedBtn.addEventListener('click', function () {
      var ids = selectedIds();
      if (!ids.length) return;
      AppDialog.confirm('ترحيل ' + ids.length + ' مردود محدد؟', { title: 'ترحيل' }).then(function (ok) {
        if (ok) postReturns(ids);
      });
    });
  }

  document.querySelectorAll('.pur-ret-post-one').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var id = parseInt(btn.getAttribute('data-id'), 10);
      if (id < 1) return;
      AppDialog.confirm('ترحيل هذا المردود (مخزون وذمة المورد)؟', { title: 'ترحيل' }).then(function (ok) {
        if (ok) postReturns([id]);
      });
    });
  });

  function deleteReturns(ids, labels) {
    if (!deleteUrl || !ids.length) return;
    var label =
      labels && labels.length === 1 ? '«' + labels[0] + '»' : ids.length + ' مردود';
    AppDialog.confirm(
      'حذف ' + label + ' نهائياً؟\nلا يمكن التراجع. يُسمح فقط بالمردودات غير المرحّلة (لم يُسجَّل لها مخزون أو ذمة مورد). الحذف يزيل السجل فقط؛ يمكنك بعدها إنشاء نفس المردود من جديد.',
      { title: 'حذف المردود', danger: true, okText: 'حذف' }
    ).then(function (ok) {
      if (!ok) return;
      var fd = new FormData();
      fd.append('_csrf', csrf);
      ids.forEach(function (id) {
        fd.append('return_ids[]', String(id));
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
          updateUnpostedCount(data.remaining_returns);
          window.location.reload();
        })
        .catch(function () {
          AppDialog.error('تعذر الاتصال بالخادم.');
        });
    });
  }

  document.querySelectorAll('.pur-ret-delete-one').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var id = parseInt(btn.getAttribute('data-id'), 10);
      if (id < 1) return;
      var no = btn.getAttribute('data-no') || String(id);
      deleteReturns([id], [no]);
    });
  });

  document.addEventListener('master-toolbar', function (e) {
    if (!e.detail) return;
    if (e.detail.action === 'post') {
      e.preventDefault();
      var ids = selectedIds();
      if (!ids.length) {
        AppDialog.alert('حدّد مردوداً واحداً على الأقل غير مرحّل للترحيل.', { type: 'warning' });
        return;
      }
      AppDialog.confirm('ترحيل ' + ids.length + ' مردود (مخزون وذمة المورد)؟', { title: 'ترحيل' }).then(function (ok) {
        if (ok) postReturns(ids);
      });
      return;
    }
    if (e.detail.action === 'delete') {
      e.preventDefault();
      var delIds = selectedIds();
      if (!delIds.length) {
        AppDialog.alert('حدّد مردوداً واحداً على الأقل غير مرحّل للحذف.', { type: 'warning' });
        return;
      }
      var labels = [];
      document.querySelectorAll('.pur-ret-row-check:checked').forEach(function (cb) {
        var tr = cb.closest('tr');
        if (!tr) return;
        var code = tr.querySelector('code');
        labels.push(code ? code.textContent.trim() : cb.value);
      });
      deleteReturns(delIds, labels);
    }
  });
})();
