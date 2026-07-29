(function () {
  'use strict';

  function bindRefresh() {
    var btn = document.getElementById('app-titlebar-refresh');
    if (!btn) return;
    btn.addEventListener('click', function () {
      window.location.reload();
    });
  }

  function confirmExitThen(go) {
    var msg = 'هل تريد الخروج من النظام؟';
    var opts = {
      title: 'تأكيد الخروج',
      okText: 'نعم، خروج',
      cancelText: 'إلغاء',
      danger: true,
      theme: 'oracle',
    };

    if (window.AppDesktopWindow && typeof window.AppDesktopWindow.confirmExit === 'function') {
      window.AppDesktopWindow.confirmExit(msg, opts).then(go);
      return;
    }

    if (window.AppDialog && typeof window.AppDialog.confirm === 'function') {
      window.AppDialog.confirm(msg, opts).then(go);
      return;
    }

    if (window.confirm(msg)) {
      go(true);
    }
  }

  function bindLogoutConfirm() {
    document.querySelectorAll('.app-header-logout-btn, .sidebar-logout-btn').forEach(function (link) {
      link.addEventListener('click', function (e) {
        e.preventDefault();
        var url = link.getAttribute('href');
        if (!url) return;

        confirmExitThen(function (ok) {
          if (!ok) return;
          try {
            localStorage.removeItem('manager:sidebar-nav-open');
          } catch (_err) {}
          if (window.AppDesktopWindow && typeof window.AppDesktopWindow.allowNextUnload === 'function') {
            window.AppDesktopWindow.allowNextUnload();
          }
          window.location.href = url;
        });
      });
    });
  }

  function init() {
    bindRefresh();
    bindLogoutConfirm();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
