(function () {
  'use strict';

  var progressEl = null;
  var NAV_BUSY_KEY = 'hypex:nav-busy';
  var NAV_MSG = 'جاري التحميل…';

  function ensureProgressBar() {
    if (progressEl) {
      return progressEl;
    }
    if (!document.body) {
      return null;
    }
    progressEl = document.createElement('div');
    progressEl.id = 'app-nav-progress';
    progressEl.setAttribute('aria-hidden', 'true');
    document.body.appendChild(progressEl);
    return progressEl;
  }

  function showProgress() {
    var bar = ensureProgressBar();
    if (bar) {
      bar.classList.add('is-active');
    }
  }

  function hideProgress() {
    if (!progressEl) {
      return;
    }
    progressEl.classList.remove('is-active');
  }

  function markNavBusy() {
    try {
      sessionStorage.setItem(NAV_BUSY_KEY, String(Date.now()));
    } catch (e) {
      /* ignore */
    }
    try {
      document.documentElement.classList.add('hypex-nav-busy');
    } catch (e2) {
      /* ignore */
    }
    if (window.AppBusy && typeof window.AppBusy.show === 'function') {
      window.AppBusy.show(NAV_MSG);
    }
  }

  function clearNavBusy() {
    try {
      sessionStorage.removeItem(NAV_BUSY_KEY);
    } catch (e) {
      /* ignore */
    }
    try {
      document.documentElement.classList.remove('hypex-nav-busy');
    } catch (e2) {
      /* ignore */
    }
    hideProgress();
    if (window.AppBusy && typeof window.AppBusy.hide === 'function') {
      // إخفاء طبقة التنقّل فقط — لا نلمس عمليات حفظ جارية إن وُجدت بعد التحميل
      try {
        if (!window.AppBusy.isActive || !window.AppBusy.isActive()) {
          return;
        }
      } catch (e3) {
        /* ignore */
      }
      window.AppBusy.hide(true);
    }
  }

  function isNavigableLink(anchor) {
    if (!anchor || anchor.tagName !== 'A') {
      return false;
    }
    if (anchor.target === '_blank' || anchor.hasAttribute('download')) {
      return false;
    }
    var href = anchor.getAttribute('href');
    if (!href || href.charAt(0) === '#') {
      return false;
    }
    if (/^(mailto:|tel:|javascript:)/i.test(href)) {
      return false;
    }
    try {
      if (anchor.origin && anchor.origin !== window.location.origin) {
        return false;
      }
    } catch (e) {
      return false;
    }
    var path = '';
    try {
      path = String(anchor.pathname || '');
    } catch (e2) {
      path = href;
    }
    if (/logout\.php$/i.test(path) || /\/logout\b/i.test(path)) {
      return false;
    }
    if (/^\/api\b/i.test(path) || path.indexOf('/api/') >= 0) {
      return false;
    }
    // أي رابط داخلي لنفس الأصل (قوائم Node و PHP)
    return true;
  }

  document.addEventListener(
    'click',
    function (e) {
      var anchor = e.target && e.target.closest ? e.target.closest('a[href]') : null;
      if (!isNavigableLink(anchor)) {
        return;
      }
      if (e.defaultPrevented) {
        return;
      }
      if (e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) {
        return;
      }
      // نفس الصفحة (hash فقط بعد المسار) — تجاهل
      try {
        if (
          anchor.pathname === window.location.pathname &&
          anchor.search === window.location.search &&
          anchor.hash
        ) {
          return;
        }
      } catch (err) {
        /* ignore */
      }
      markNavBusy();
      showProgress();
    },
    true
  );

  window.addEventListener('DOMContentLoaded', clearNavBusy);
  window.addEventListener('pageshow', clearNavBusy);
  window.addEventListener('load', hideProgress);
  window.addEventListener('DOMContentLoaded', function () {
    setTimeout(hideProgress, 1200);
  });
})();
