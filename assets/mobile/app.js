(function () {
  'use strict';

  function refreshActionDockInset() {
    if (window.MobileToolbar && typeof MobileToolbar.refresh === 'function') {
      MobileToolbar.refresh();
      return;
    }
    var bar = document.getElementById('m-main-toolbar');
    var visible = !!(bar && !bar.hidden);
    document.body.classList.toggle('m-has-action-dock', visible);
  }

  window.AppMobile = window.AppMobile || {};
  window.AppMobile.refreshActionDock = refreshActionDockInset;

  document.querySelectorAll('.m-alert:not(#m-inv-post-status)').forEach(function (el) {
    setTimeout(function () {
      el.style.opacity = '0';
      el.style.transition = 'opacity 0.3s';
      setTimeout(function () { el.remove(); }, 320);
    }, 5000);
  });
})();
