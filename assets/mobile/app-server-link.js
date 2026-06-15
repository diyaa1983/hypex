(function () {
  'use strict';

  /** يظهر في تطبيق Capacitor فقط — العودة لشاشة إعداد عنوان السيرفر */
  function isCapacitorApp() {
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

  if (!isCapacitorApp()) {
    return;
  }

  function openSetup() {
    var q = '?reconfigure=1';
    var candidates = [
      'http://localhost/index.html' + q,
      'https://localhost/index.html' + q,
      '/index.html' + q,
    ];
    window.location.href = candidates[0];
  }

  var link = document.createElement('button');
  link.type = 'button';
  link.className = 'm-app-server-link';
  link.textContent = 'تغيير عنوان السيرفر';
  link.addEventListener('click', openSetup);

  var host = document.querySelector('.m-login-card') || document.querySelector('.m-login');
  if (host) {
    host.appendChild(link);
  }
})();
