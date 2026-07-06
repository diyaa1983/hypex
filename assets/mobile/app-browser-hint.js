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

  function isPhoneViewport() {
    try {
      return !!(window.matchMedia && window.matchMedia('(max-width: 519px)').matches);
    } catch (e) {
      return false;
    }
  }

  var immersive = isNativeApp() || isStandalonePwa() || isPhoneViewport();
  if (immersive) {
    document.documentElement.classList.add('m-immersive');
  }
  if (isNativeApp() || isStandalonePwa()) {
    document.documentElement.classList.add('m-installed-app');
  } else {
    document.documentElement.classList.add('m-in-browser');
  }
})();
