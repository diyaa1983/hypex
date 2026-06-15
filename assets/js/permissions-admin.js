(function () {
  'use strict';

  var form = document.getElementById('permissions-form');
  var groupSelect = document.getElementById('permissions-group-select');
  var groupForm = document.getElementById('permissions-group-form');

  if (groupSelect && groupForm) {
    groupSelect.addEventListener('change', function () {
      var base = groupForm.getAttribute('action') || 'index.php';
      var url;
      try {
        url = new URL(base, window.location.href);
      } catch (e) {
        url = new URL(window.location.pathname, window.location.origin);
      }
      url.searchParams.set('r', 'permissions');
      url.searchParams.set('group_id', groupSelect.value);
      window.location.href = url.pathname + url.search;
    });
    groupForm.addEventListener('submit', function (e) {
      e.preventDefault();
    });
  }

  if (!form) return;

  document.addEventListener('master-toolbar', function (e) {
    if (!e.detail || e.detail.action !== 'save') return;
    e.preventDefault();
    e.stopImmediatePropagation();
    if (typeof form.requestSubmit === 'function') {
      form.requestSubmit();
    } else {
      form.submit();
    }
  });
})();
