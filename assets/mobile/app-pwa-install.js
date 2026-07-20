(function () {
  'use strict';

  var STORAGE_KEY = 'm_pwa_install_hint_dismissed';
  var deferredPrompt = null;

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

  function isAndroid() {
    return /Android/i.test(navigator.userAgent || '');
  }

  function isSafari() {
    var ua = navigator.userAgent || '';
    if (!isIos()) return false;
    if (/CriOS|FxiOS|EdgiOS|OPiOS|mercury|GSA/.test(ua)) return false;
    return /Safari/i.test(ua);
  }

  function isChromeAndroid() {
    var ua = navigator.userAgent || '';
    return isAndroid() && /Chrome/i.test(ua) && !/EdgA|OPR|SamsungBrowser|Firefox/i.test(ua);
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

  function renderHint(html, withInstallBtn) {
    if (document.querySelector('.m-pwa-install-hint')) return null;
    var host =
      document.querySelector('.m-login-card') ||
      document.querySelector('.m-main') ||
      document.querySelector('.m-body');
    if (!host) return null;

    var installBtnHtml = withInstallBtn
      ? '<button type="button" class="m-pwa-install-hint__install" id="m-pwa-install-btn">تثبيت التطبيق</button>'
      : '';

    var box = document.createElement('div');
    box.className = 'm-pwa-install-hint';
    box.setAttribute('role', 'note');
    box.innerHTML =
      '<div class="m-pwa-install-hint__icon" aria-hidden="true">📲</div>' +
      '<div class="m-pwa-install-hint__body">' + html + installBtnHtml + '</div>' +
      '<button type="button" class="m-pwa-install-hint__close" aria-label="إخفاء">×</button>';

    box.querySelector('.m-pwa-install-hint__close').addEventListener('click', function () {
      dismiss();
      box.remove();
    });

    if (host.classList && host.classList.contains('m-login-card')) {
      host.insertBefore(box, host.firstChild);
    } else {
      host.insertAdjacentElement('afterbegin', box);
    }

    return box;
  }

  function bindInstallButton(box) {
    var btn = box ? box.querySelector('#m-pwa-install-btn') : document.getElementById('m-pwa-install-btn');
    if (!btn) return;
    btn.addEventListener('click', function () {
      if (!deferredPrompt) return;
      btn.disabled = true;
      deferredPrompt.prompt();
      deferredPrompt.userChoice
        .then(function (choice) {
          if (choice && choice.outcome === 'accepted') {
            dismiss();
            if (box) box.remove();
          }
          deferredPrompt = null;
        })
        .catch(function () {
          btn.disabled = false;
        });
    });
  }

  function showIosHint() {
    if (isSafari()) {
      renderHint(
        '<p class="m-pwa-install-hint__title">ثبّت التطبيق على iPhone</p>' +
          '<ol class="m-pwa-install-hint__steps">' +
          '<li>اضغط زر <strong>المشاركة</strong> <span class="m-pwa-install-hint__share" aria-hidden="true">⎋</span> أسفل الشاشة</li>' +
          '<li>اختر <strong>«إضافة إلى الشاشة الرئيسية»</strong></li>' +
          '<li>اضغط <strong>«إضافة»</strong></li>' +
          '</ol>',
        false
      );
      return;
    }
    renderHint(
      '<p class="m-pwa-install-hint__title">لتثبيت التطبيق على iPhone</p>' +
        '<p class="m-pwa-install-hint__sub">افتح هذا الرابط في <strong>Safari</strong> ثم أضِفه إلى الشاشة الرئيسية.</p>',
      false
    );
  }

  function showAndroidHint(withBtn) {
    var html =
      '<p class="m-pwa-install-hint__title">ثبّت التطبيق على Android</p>';
    if (withBtn) {
      html += '<p class="m-pwa-install-hint__sub">اضغط الزر أدناه للتثبيت على الشاشة الرئيسية.</p>';
    } else if (isChromeAndroid()) {
      html +=
        '<ol class="m-pwa-install-hint__steps">' +
        '<li>اضغط القائمة <strong>⋮</strong> أعلى Chrome</li>' +
        '<li>اختر <strong>«تثبيت التطبيق»</strong> أو <strong>«إضافة إلى الشاشة الرئيسية»</strong></li>' +
        '</ol>';
    } else {
      html +=
        '<p class="m-pwa-install-hint__sub">افتح الرابط في <strong>Chrome</strong> ثم من القائمة ⋮ اختر تثبيت التطبيق.</p>';
    }
    var box = renderHint(html, withBtn);
    if (withBtn && box) bindInstallButton(box);
  }

  function init() {
    if (isNativeApp() || isStandalone() || dismissed()) return;

    if (isIos()) {
      showIosHint();
      return;
    }

    if (isAndroid()) {
      window.addEventListener('beforeinstallprompt', function (e) {
        e.preventDefault();
        deferredPrompt = e;
        var existing = document.querySelector('.m-pwa-install-hint');
        if (existing) existing.remove();
        showAndroidHint(true);
      });

      // إن لم يظهر beforeinstallprompt خلال ثانيتين — تعليمات يدوية
      setTimeout(function () {
        if (deferredPrompt || isStandalone() || dismissed()) return;
        if (!document.querySelector('.m-pwa-install-hint')) {
          showAndroidHint(false);
        }
      }, 2000);
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
