(function () {
  'use strict';

  function isNativeApp() {
    try {
      if (window.Capacitor && typeof window.Capacitor.isNativePlatform === 'function') {
        return window.Capacitor.isNativePlatform();
      }
      if (window.Capacitor && window.Capacitor.platform && window.Capacitor.platform !== 'web') {
        return true;
      }
    } catch (e) {
      /* ignore */
    }
    return false;
  }

  function isStandalonePwa() {
    try {
      if (window.matchMedia && window.matchMedia('(display-mode: standalone)').matches) {
        return true;
      }
      if (window.matchMedia && window.matchMedia('(display-mode: fullscreen)').matches) {
        return true;
      }
      if (navigator.standalone === true) {
        return true;
      }
    } catch (e) {
      /* ignore */
    }
    return false;
  }

  if (isNativeApp() || isStandalonePwa()) {
    document.documentElement.classList.add('m-installed-app');
    return;
  }

  document.documentElement.classList.add('m-in-browser');

  if (sessionStorage.getItem('m_browser_hint_dismiss') === '1') {
    return;
  }

  var bar = document.createElement('div');
  bar.className = 'm-browser-hint';
  bar.setAttribute('role', 'status');
  bar.innerHTML =
    '<p class="m-browser-hint__text">' +
    '<strong>لتجربة تطبيق حقيقي بدون شريط المتصفح:</strong> ثبّت تطبيق ' +
    '<strong>النظام المحاسبي</strong> (ملف APK) من قائمة التطبيقات — لا تستخدم Chrome.' +
    '</p>' +
    '<button type="button" class="m-browser-hint__close" aria-label="إخفاء">×</button>';

  bar.querySelector('.m-browser-hint__close').addEventListener('click', function () {
    sessionStorage.setItem('m_browser_hint_dismiss', '1');
    bar.remove();
  });

  var mount = document.body;
  if (mount) {
    mount.insertBefore(bar, mount.firstChild);
  }
})();
