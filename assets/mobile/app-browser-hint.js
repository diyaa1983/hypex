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

  /** هاتف عمودي أو أفقي — لا نعامل العرض الأفقي كمتصفح سطح مكتب */
  function isPhoneLike() {
    try {
      if (!window.matchMedia) return false;
      if (window.matchMedia('(max-width: 519px)').matches) return true;
      if (window.matchMedia('(max-height: 519px) and (orientation: landscape)').matches) return true;
    } catch (e) {
      /* ignore */
    }
    return false;
  }

  function isLandscape() {
    try {
      return !!(window.matchMedia && window.matchMedia('(orientation: landscape)').matches);
    } catch (e) {
      return false;
    }
  }

  function applyDeviceUi() {
    var html = document.documentElement;
    var native = isNativeApp();
    var standalone = isStandalonePwa();
    var phone = isPhoneLike();
    var landscape = isLandscape();
    var immersive = native || standalone || phone;

    html.classList.toggle('cap-native-app', native);
    html.classList.toggle('m-immersive', immersive);
    html.classList.toggle('m-phone', phone || native || standalone);
    html.classList.toggle('m-landscape', landscape);
    html.classList.toggle('m-portrait', !landscape);
    html.classList.toggle('m-installed-app', native || standalone);
    html.classList.toggle('m-in-browser', !(native || standalone));
  }

  applyDeviceUi();

  window.addEventListener('orientationchange', function () {
    setTimeout(applyDeviceUi, 120);
  });
  window.addEventListener('resize', applyDeviceUi);
  if (window.visualViewport) {
    window.visualViewport.addEventListener('resize', applyDeviceUi);
  }
})();
