(function () {
  'use strict';

  var form = document.getElementById('user-form');
  if (!form) return;

  var listUrl = form.getAttribute('data-list-url') || '';

  function goToUser(id) {
    var sep = listUrl.indexOf('?') >= 0 ? '&' : '?';
    window.location.href = listUrl + sep + 'id=' + encodeURIComponent(String(id));
  }

  document.querySelectorAll('.users-admin-row[data-href]').forEach(function (row) {
    row.addEventListener('click', function () {
      var href = row.getAttribute('data-href');
      if (href) window.location.href = href;
    });
    row.addEventListener('keydown', function (e) {
      if (e.key === 'Enter' || e.key === ' ') {
        e.preventDefault();
        var href = row.getAttribute('data-href');
        if (href) window.location.href = href;
      }
    });
  });

  function hasSelectedGroup() {
    return !!form.querySelector('input[name="group_ids[]"]:checked');
  }

  document.addEventListener('master-toolbar', function (e) {
    if (!e.detail) return;
    var action = e.detail.action;

    if (action === 'new') {
      e.preventDefault();
      e.stopImmediatePropagation();
      goToUser('new');
      return;
    }

    if (action === 'save') {
      e.preventDefault();
      e.stopImmediatePropagation();

      if (!hasSelectedGroup()) {
        if (window.AppDialog && AppDialog.alert) {
          AppDialog.alert('اختر مجموعة واحدة على الأقل للمستخدم.', { type: 'warning' });
        } else {
          alert('اختر مجموعة واحدة على الأقل للمستخدم.');
        }
        return;
      }

      if (typeof form.reportValidity === 'function' && !form.reportValidity()) {
        return;
      }

      if (typeof form.requestSubmit === 'function') {
        form.requestSubmit();
      } else {
        form.submit();
      }
    }
  });
})();
