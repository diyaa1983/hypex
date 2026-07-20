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
    // Capacitor يقدّم ملفات www المحلية على localhost داخل WebView
    var candidates = [
      'https://localhost/index.html' + q,
      'http://localhost/index.html' + q,
      'capacitor://localhost/index.html' + q,
      '/index.html' + q,
    ];
    var target = candidates[0];
    try {
      var platform =
        (window.Capacitor &&
          window.Capacitor.getPlatform &&
          window.Capacitor.getPlatform()) ||
        (window.Capacitor && window.Capacitor.platform) ||
        '';
      if (String(platform).toLowerCase() === 'ios') {
        target = candidates[2];
      }
    } catch (e) {
      /* ignore */
    }
    window.location.href = target;
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
