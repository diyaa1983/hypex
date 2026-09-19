/**
 * تطبيق سطح المكتب: التكبير + تأكيد عند إغلاق النافذة بزر X.
 *
 * ملاحظة: رسالة زر X من المتصفح (Leave app?) ولا يمكن تخصيصها من الويب.
 * غلاف Electron يعرض تأكيداً عربياً من النافذة الأصلية.
 */
(function (global) {
  'use strict';

  var skipUnloadPrompt = false;
  /** يزيد مع كل allow حتى لا تُصفِّر مهلة قديمة سماحاً أحدث. */
  var unloadAllowToken = 0;
  var STORAGE_KEY = '__managerAllowUnloadAt';

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

  function isElectronShell() {
    try {
      return !!(global.navigator && /\bElectron\//i.test(global.navigator.userAgent));
    } catch (e) {
      return false;
    }
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
      // بعض بيئات PWA ترفض resize قبل اكتمال العرض — نحاول بهدوء
      if (typeof global.moveTo === 'function') {
        global.moveTo(l, t);
      }
      if (typeof global.resizeTo === 'function') {
        global.resizeTo(w, h);
      }
      return true;
    } catch (e) {
      return false;
    }
  }

  function scheduleMaximizeBurst() {
    maximizeWindow();
    [40, 120, 350, 800, 1600].forEach(function (ms) {
      setTimeout(function () {
        if (isMeaningfullyShrunk()) {
          maximizeWindow();
        }
      }, ms);
    });
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

  /**
   * يسمح بالانتقال التالي دون تأكيد (تسجيل خروج / تحديث / انتقال فعلي / حفظ فورم).
   * يُخزَّن أيضاً في sessionStorage لأن POST+redirect على السيرفر البطيء
   * قد يُفقد علم الذاكرة بين طلبين، فيظهر Leave app? رغم أن الحفظ نجح.
   */
  function allowNextUnload() {
    skipUnloadPrompt = true;
    unloadAllowToken += 1;
    try {
      global.__managerAllowUnload = true;
    } catch (e) {
      /* ignore */
    }
    try {
      global.sessionStorage.setItem(STORAGE_KEY, String(Date.now()));
    } catch (e2) {
      /* ignore */
    }
    return unloadAllowToken;
  }

  function clearUnloadAllow() {
    skipUnloadPrompt = false;
    try {
      global.__managerAllowUnload = false;
    } catch (e) {
      /* ignore */
    }
    try {
      global.sessionStorage.removeItem(STORAGE_KEY);
    } catch (e2) {
      /* ignore */
    }
  }

  function hasStoredUnloadAllow() {
    try {
      var raw = global.sessionStorage.getItem(STORAGE_KEY);
      if (!raw) {
        return false;
      }
      var ts = parseInt(raw, 10);
      if (!ts || isNaN(ts)) {
        return false;
      }
      // نافذة واسعة تكفي لبطء السيرفر وسلسلة POST → 302 → GET
      return Date.now() - ts < 120000;
    } catch (e) {
      return false;
    }
  }

  function shouldPromptOnUnload() {
    if (skipUnloadPrompt || global.__managerAllowUnload || hasStoredUnloadAllow()) {
      // لا نُصفّر هنا: المتصفح قد يستدعي beforeunload أكثر من مرة لنفس الانتقال.
      return false;
    }
    // Electron يتولى التأكيد من الغلاف الأصلي.
    if (isElectronShell()) {
      return false;
    }
    return true;
  }

  function isLogoutLink(a, href) {
    if (!a) {
      return false;
    }
    if (
      a.classList.contains('app-header-logout-btn') ||
      a.classList.contains('sidebar-logout-btn') ||
      a.classList.contains('m-header-logout')
    ) {
      return true;
    }
    return !!(href && href.indexOf('logout.php') >= 0);
  }

  function isSameOriginHref(href) {
    if (!href) {
      return false;
    }
    if (href.charAt(0) === '#' || href.indexOf('javascript:') === 0) {
      return false;
    }
    try {
      var url = new URL(href, global.location.href);
      return url.origin === global.location.origin;
    } catch (err) {
      return false;
    }
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

  function markInternalNavigation() {
    allowNextUnload();
  }

  function bindCloseConfirm() {
    global.addEventListener('beforeunload', function (e) {
      if (!shouldPromptOnUnload()) {
        return;
      }
      e.preventDefault();
      e.returnValue = '';
      return '';
    });

    // امسح السماح بعد استقرار الصفحة الجديدة (بعد سلسلة التحويل من السيرفر).
    global.addEventListener('pageshow', function () {
      setTimeout(function () {
        clearUnloadAllow();
      }, 1500);
    });

    document.addEventListener(
      'click',
      function (e) {
        var a = e.target && e.target.closest ? e.target.closest('a[href]') : null;
        if (a) {
          var href = a.getAttribute('href');
          if (!href || href.charAt(0) === '#' || href.indexOf('javascript:') === 0) {
            return;
          }
          if (a.hasAttribute('download') || a.getAttribute('target') === '_blank') {
            return;
          }
          if (isLogoutLink(a, href)) {
            return;
          }
          if (isSameOriginHref(href)) {
            markInternalNavigation();
          }
          return;
        }

        /* صفوف القوائم (مستخدمين / مجموعات / موظفين…) تنتقل عبر data-href بدون <a> */
        var row = e.target && e.target.closest ? e.target.closest('[data-href]') : null;
        if (!row) {
          return;
        }
        var dataHref = row.getAttribute('data-href');
        if (isSameOriginHref(dataHref)) {
          markInternalNavigation();
        }
      },
      true
    );

    document.addEventListener(
      'submit',
      function () {
        markInternalNavigation();
      },
      true
    );

    var refreshBtn = document.getElementById('app-titlebar-refresh');
    if (refreshBtn) {
      refreshBtn.addEventListener(
        'click',
        function () {
          allowNextUnload();
        },
        true
      );
    }
  }

  function bindMaximizeGuards() {
    // Electron يكبّر من الغلاف الأصلي — إعادة resize هنا تسبب بطئاً ووميضاً
    if (isElectronShell()) {
      return;
    }

    scheduleMaximizeBurst();

    global.addEventListener('load', function () {
      scheduleMaximizeBurst();
    });

    document.addEventListener('visibilitychange', function () {
      if (document.visibilityState === 'visible' && isMeaningfullyShrunk()) {
        scheduleMaximizeBurst();
      }
    });
  }

  function boot() {
    // تأكيد الإغلاق على كل صفحات النظام (ويب / PWA)، ما عدا Electron.
    if (!isElectronShell()) {
      bindCloseConfirm();
    }

    if (isDesktopInstalledApp() || isElectronShell()) {
      bindMaximizeGuards();
    } else {
      // محاولة مبكرة: بعض اختصارات Edge تبدأ بدون display-mode فوراً
      setTimeout(function () {
        if (isDesktopInstalledApp() && isMeaningfullyShrunk()) {
          scheduleMaximizeBurst();
        }
      }, 300);
    }
  }

  global.AppDesktopWindow = {
    maximize: maximizeWindow,
    isInstalled: isDesktopInstalledApp,
    confirmExit: confirmExit,
    allowNextUnload: allowNextUnload,
    markInternalNavigation: markInternalNavigation,
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})(window);
