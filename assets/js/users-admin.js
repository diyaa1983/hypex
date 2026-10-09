(function () {
  'use strict';

  var form = document.getElementById('user-form');
  if (!form) return;

  var listUrl = form.getAttribute('data-list-url') || '';

  function allowLeave() {
    try {
      window.__managerAllowUnload = true;
      window.sessionStorage.setItem('__managerAllowUnloadAt', String(Date.now()));
    } catch (e) {
      /* ignore */
    }
    if (window.AppDesktopWindow && typeof window.AppDesktopWindow.allowNextUnload === 'function') {
      window.AppDesktopWindow.allowNextUnload();
    } else if (window.AppDesktopWindow && typeof window.AppDesktopWindow.markInternalNavigation === 'function') {
      window.AppDesktopWindow.markInternalNavigation();
    }
  }

  function goToUser(id) {
    allowLeave();
    var sep = listUrl.indexOf('?') >= 0 ? '&' : '?';
    window.location.href = listUrl + sep + 'id=' + encodeURIComponent(String(id));
  }

  // مهم: يُنفَّذ قبل مغادرة الصفحة حتى على السيرفر البطيء.
  form.addEventListener(
    'submit',
    function () {
      allowLeave();
    },
    true
  );

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

  function hasSelectedGroup() {
    return !!form.querySelector('input[name="group_ids[]"]:checked');
  }

  function submitUserForm() {
    allowLeave();
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

    // requestSubmit يطلق حدث submit؛ form.submit() لا يفعل ذلك في بعض المتصفحات.
    allowLeave();
    if (typeof form.requestSubmit === 'function') {
      form.requestSubmit();
    } else {
      form.submit();
    }
  }

  function submitDeleteUser() {
    var idInput = form.querySelector('input[name="id"]');
    var id = idInput ? parseInt(idInput.value, 10) || 0 : 0;
    if (id < 1) {
      if (window.AppDialog && AppDialog.alert) {
        AppDialog.alert('اختر مستخدماً موجوداً للحذف.', { type: 'warning' });
      } else {
        alert('اختر مستخدماً موجوداً للحذف.');
      }
      return;
    }
    var ask = window.AppDialog && AppDialog.confirm
      ? AppDialog.confirm('هل تريد حذف هذا المستخدم نهائياً؟ لا يمكن التراجع.', {
          type: 'danger',
          okText: 'حذف',
        })
      : Promise.resolve(window.confirm('هل تريد حذف هذا المستخدم نهائياً؟ لا يمكن التراجع.'));
    Promise.resolve(ask).then(function (ok) {
      if (!ok) return;
      var actionInput = form.querySelector('input[name="_action"]');
      if (actionInput) actionInput.value = 'delete';
      allowLeave();
      if (typeof form.requestSubmit === 'function') {
        form.requestSubmit();
      } else {
        form.submit();
      }
    });
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
      submitUserForm();
      return;
    }

    if (action === 'delete') {
      e.preventDefault();
      e.stopImmediatePropagation();
      submitDeleteUser();
    }
  });
})();
