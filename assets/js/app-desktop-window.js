/**
 * تطبيق سطح المكتب (PWA): الإبقاء على وضع التكبير ومنع تصغير النافذة
 * الذي يكسر تخطيط الشاشات.
 *
 * ملاحظة: أزرار ويندوز (─ □ X) جزء من نظام التشغيل ولا يمكن إخفاؤها أو تعطيلها
 * من تطبيق ويب/PWA. لإخفاء زر X يلزم غلاف ويندوز أصلي (WebView2/Electron).
 * تأكيد الخروج برسائل النظام يتم عبر تسجيل الخروج داخل التطبيق.
 */
(function (global) {
  'use strict';

  function isDesktopInstalledApp() {
    try {
      if (global.matchMedia('(display-mode: window-controls-overlay)').matches) {
        return true;
      }
      if (global.matchMedia('(display-mode: standalone)').matches) {
        return true;
      }
      if (global.matchMedia('(display-mode: minimal-ui)').matches) {
        return true;
      }
    } catch (e) {
      /* ignore */
    }
    var body = document.body;
    return !!(
      body &&
      (body.classList.contains('app-body--standalone') ||
        body.classList.contains('app-body--wco'))
    );
  }

  function canControlWindow() {
    return (
      typeof global.moveTo === 'function' &&
      typeof global.resizeTo === 'function' &&
      global.screen &&
      global.screen.availWidth > 0
    );
  }

  function maximizeWindow() {
    if (!canControlWindow()) {
      return false;
    }
    try {
      var w = global.screen.availWidth;
      var h = global.screen.availHeight;
      var l = typeof global.screen.availLeft === 'number' ? global.screen.availLeft : 0;
      var t = typeof global.screen.availTop === 'number' ? global.screen.availTop : 0;
      global.moveTo(l, t);
      global.resizeTo(w, h);
      return true;
    } catch (e) {
      return false;
    }
  }

  function isMeaningfullyShrunk() {
    if (!global.screen) {
      return false;
    }
    var aw = global.screen.availWidth || 0;
    var ah = global.screen.availHeight || 0;
    if (aw < 1 || ah < 1) {
      return false;
    }
    var ow = global.outerWidth || global.innerWidth || 0;
    var oh = global.outerHeight || global.innerHeight || 0;
    return ow < aw * 0.92 || oh < ah * 0.92;
  }

  function confirmExit(message, options) {
    options = options || {};
    var msg = message || 'هل تريد الخروج من النظام؟';
    var opts = {
      title: options.title || 'تأكيد الخروج',
      okText: options.okText || 'نعم، خروج',
      cancelText: options.cancelText || 'إلغاء',
      danger: options.danger !== false,
      theme: options.theme || 'oracle',
    };

    if (global.AppDialog && typeof global.AppDialog.confirm === 'function') {
      return global.AppDialog.confirm(msg, opts);
    }

    return Promise.resolve(global.confirm(msg));
  }

  function bindMaximizeGuards() {
    maximizeWindow();

    var resizeTimer = null;
    global.addEventListener('resize', function () {
      clearTimeout(resizeTimer);
      resizeTimer = setTimeout(function () {
        if (isMeaningfullyShrunk()) {
          maximizeWindow();
        }
      }, 120);
    });

    document.addEventListener('visibilitychange', function () {
      if (document.visibilityState === 'visible') {
        setTimeout(maximizeWindow, 80);
      }
    });

    global.addEventListener('focus', function () {
      setTimeout(function () {
        if (isMeaningfullyShrunk()) {
          maximizeWindow();
        }
      }, 80);
    });
  }

  function boot() {
    if (!isDesktopInstalledApp()) {
      return;
    }
    bindMaximizeGuards();
  }

  global.AppDesktopWindow = {
    maximize: maximizeWindow,
    isInstalled: isDesktopInstalledApp,
    confirmExit: confirmExit,
    allowNextUnload: function () {},
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})(window);
