(function () {
  'use strict';

  var STORAGE_KEY = 'm_pwa_ios_hint_dismissed';

  function isNativeApp() {
    try {
      if (window.Capacitor && typeof window.Capacitor.isNativePlatform === 'function') {
        return window.Capacitor.isNativePlatform();
      }
    } catch (e) {
      /* ignore */
    }
    return false;
  }

  function isStandalone() {
    try {
      if (window.matchMedia && window.matchMedia('(display-mode: standalone)').matches) return true;
      if (window.matchMedia && window.matchMedia('(display-mode: fullscreen)').matches) return true;
      if (navigator.standalone === true) return true;
    } catch (e) {
      /* ignore */
    }
    return false;
  }

  function isIos() {
    var ua = navigator.userAgent || '';
    return /iPad|iPhone|iPod/.test(ua) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
  }

  function isSafari() {
    var ua = navigator.userAgent || '';
    if (!isIos()) return false;
    if (/CriOS|FxiOS|EdgiOS|OPiOS|mercury|GSA/.test(ua)) return false;
    return /Safari/i.test(ua);
  }

  function dismissed() {
    try {
      return sessionStorage.getItem(STORAGE_KEY) === '1';
    } catch (e) {
      return false;
    }
  }

  function dismiss() {
    try {
      sessionStorage.setItem(STORAGE_KEY, '1');
    } catch (e) {
      /* ignore */
    }
  }

  function renderHint(html) {
    if (document.querySelector('.m-pwa-ios-hint')) return;
    var host =
      document.querySelector('.m-login-card') ||
      document.querySelector('.m-main') ||
      document.querySelector('.m-body');
    if (!host) return;

    var box = document.createElement('div');
    box.className = 'm-pwa-ios-hint';
    box.setAttribute('role', 'note');
    box.innerHTML =
      '<div class="m-pwa-ios-hint__icon" aria-hidden="true">📲</div>' +
      '<div class="m-pwa-ios-hint__body">' + html + '</div>' +
      '<button type="button" class="m-pwa-ios-hint__close" aria-label="إخفاء">×</button>';

    box.querySelector('.m-pwa-ios-hint__close').addEventListener('click', function () {
      dismiss();
      box.remove();
    });

    if (host.classList && host.classList.contains('m-login-card')) {
      host.insertBefore(box, host.firstChild);
    } else {
      host.insertAdjacentElement('afterbegin', box);
    }
  }

  function init() {
    if (isNativeApp() || isStandalone() || dismissed() || !isIos()) return;

    if (isSafari()) {
      renderHint(
        '<p class="m-pwa-ios-hint__title">ثبّت التطبيق على iPhone</p>' +
          '<ol class="m-pwa-ios-hint__steps">' +
          '<li>اضغط زر <strong>المشاركة</strong> <span class="m-pwa-ios-hint__share" aria-hidden="true">⎋</span> أسفل الشاشة</li>' +
          '<li>اختر <strong>«إضافة إلى الشاشة الرئيسية»</strong></li>' +
          '<li>اضغط <strong>«إضافة»</strong> — يفتح كتطبيق بدون شريط Safari</li>' +
          '</ol>'
      );
      return;
    }

    renderHint(
      '<p class="m-pwa-ios-hint__title">لتثبيت التطبيق على iPhone</p>' +
        '<p class="m-pwa-ios-hint__sub">افتح هذا الرابط في <strong>Safari</strong> ثم أضِفه إلى الشاشة الرئيسية.</p>' +
        '<p class="m-pwa-ios-hint__sub muted">Chrome على iPhone لا يدعم التثبيت كتطبيق.</p>'
    );
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
