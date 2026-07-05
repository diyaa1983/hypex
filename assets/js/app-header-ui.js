(function () {
  'use strict';

  function bindRefresh() {
    var btn = document.getElementById('app-titlebar-refresh');
    if (!btn) return;
    btn.addEventListener('click', function () {
      window.location.reload();
    });
  }

  function bindLogoutConfirm() {
    document.querySelectorAll('.app-header-logout-btn, .sidebar-logout-btn').forEach(function (link) {
      link.addEventListener('click', function (e) {
        e.preventDefault();
        var url = link.getAttribute('href');
        if (!url) return;

        function go(ok) {
          if (!ok) return;
          try {
            localStorage.removeItem('manager:sidebar-nav-open');
          } catch (_err) {}
          window.location.href = url;
        }

        if (window.AppDialog && typeof window.AppDialog.confirm === 'function') {
          window.AppDialog.confirm('هل تريد تسجيل الخروج من النظام؟', {
            title: 'تسجيل خروج',
            okText: 'نعم، خروج',
            cancelText: 'إلغاء',
            danger: true,
          }).then(go);
          return;
        }

        if (window.confirm('هل تريد تسجيل الخروج من النظام؟')) {
          go(true);
        }
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
