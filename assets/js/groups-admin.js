(function () {
  'use strict';

  var form = document.getElementById('group-form');
  if (!form) return;

  var listUrl = form.getAttribute('data-list-url') || '';

  function allowLeave() {
    if (window.AppDesktopWindow && typeof window.AppDesktopWindow.allowNextUnload === 'function') {
      window.AppDesktopWindow.allowNextUnload();
    }
    window.__managerAllowUnload = true;
  }

  function goToGroup(id) {
    allowLeave();
    var sep = listUrl.indexOf('?') >= 0 ? '&' : '?';
    window.location.href = listUrl + sep + 'id=' + encodeURIComponent(String(id));
  }

  document.querySelectorAll('.users-admin-row[data-href]').forEach(function (row) {
    row.addEventListener('click', function () {
      var href = row.getAttribute('data-href');
      if (!href) return;
      allowLeave();
      window.location.href = href;
    });
    row.addEventListener('keydown', function (e) {
      if (e.key === 'Enter' || e.key === ' ') {
        e.preventDefault();
        var href = row.getAttribute('data-href');
        if (!href) return;
        allowLeave();
        window.location.href = href;
      }
    });
  });

  document.addEventListener('master-toolbar', function (e) {
    if (!e.detail) return;
    var action = e.detail.action;

    if (action === 'new') {
      e.preventDefault();
      e.stopImmediatePropagation();
      goToGroup('new');
      return;
    }

    if (action === 'save') {
      e.preventDefault();
      e.stopImmediatePropagation();

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
