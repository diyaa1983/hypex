/**
 * تتبع موقع المندوب — تطبيق APK (مثل الكاش فان).
 * يعمل فقط داخل Capacitor الأصلي، وليس في متصفح Chrome.
 */
(function (global) {
  'use strict';

  var STORAGE_PERM_ASKED = 'mgr_native_gps_perm_asked_v1';
  var timer = null;
  var running = false;
  var paused = false;
  var started = false;

  function isNativeApp() {
    if (global.AppGeo && typeof AppGeo.isCapacitorNative === 'function') {
      return AppGeo.isCapacitorNative();
    }
    try {
      return !!(
        global.Capacitor &&
        Capacitor.isNativePlatform &&
        Capacitor.isNativePlatform() &&
        Capacitor.nativePromise
      );
    } catch (e) {
      return false;
    }
  }

  function readConfig() {
    var cfg = global.UserSessionGpsConfig || {};
    return {
      pingApi: String(cfg.pingApi || ''),
      csrf: String(cfg.csrf || ''),
      intervalMs: Math.max(120000, parseInt(cfg.nativeIntervalMs, 10) || 180000),
      initialDelayMs: Math.max(3000, parseInt(cfg.nativeInitialDelayMs, 10) || 8000),
      geoTimeoutMs: Math.max(10000, parseInt(cfg.nativeGeoTimeoutMs, 10) || 22000),
    };
  }

  function getNativePosition(timeoutMs) {
    var opts = {
      enableHighAccuracy: true,
      maximumAge: 120000,
      timeout: timeoutMs,
    };
    if (global.AppGeo && typeof AppGeo.getCurrentPosition === 'function') {
      return AppGeo.getCurrentPosition(opts).then(function (gps) {
        if (global.AppGeo && typeof AppGeo.rememberReading === 'function') {
          AppGeo.rememberReading(gps);
        }
        return gps;
      });
    }
    var cap = global.Capacitor;
    if (!cap || typeof cap.nativePromise !== 'function') {
      return Promise.reject(new Error('no_native'));
    }
    return cap
      .nativePromise('Geolocation', 'requestPermissions', {})
      .catch(function () {
        return null;
      })
      .then(function () {
        return cap.nativePromise('Geolocation', 'getCurrentPosition', {
          enableHighAccuracy: true,
          maximumAge: 0,
          timeout: timeoutMs,
        });
      })
      .then(function (pos) {
        var c = pos && pos.coords ? pos.coords : pos;
        var gps = {
          latitude: c.latitude,
          longitude: c.longitude,
          accuracy: typeof c.accuracy === 'number' ? c.accuracy : null,
        };
        if (global.AppGeo && typeof AppGeo.rememberReading === 'function') {
          AppGeo.rememberReading(gps);
        }
        return gps;
      });
  }

  function sendPing(gps, cfg) {
    if (!cfg.pingApi || !gps) {
      return Promise.resolve();
    }
    var fd = new FormData();
    fd.append('_csrf', cfg.csrf);
    fd.append('latitude', String(gps.latitude));
    fd.append('longitude', String(gps.longitude));
    if (gps.accuracy != null && isFinite(gps.accuracy)) {
      fd.append('gps_accuracy', String(gps.accuracy));
    }
    fd.append('gps_source', 'mobile');
    fd.append('gps_channel', 'native_app');

    return fetch(cfg.pingApi, {
      method: 'POST',
      body: fd,
      credentials: 'same-origin',
      headers: { Accept: 'application/json' },
    }).catch(function () {
      return null;
    });
  }

  function captureAndPing(cfg) {
    if (running || paused || !cfg.pingApi) {
      return;
    }
    running = true;
    getNativePosition(cfg.geoTimeoutMs)
      .then(function (gps) {
        return sendPing(gps, cfg);
      })
      .catch(function () {
        /* صامت */
      })
      .finally(function () {
        running = false;
      });
  }

  function schedule(cfg) {
    clearInterval(timer);
    timer = setInterval(function () {
      captureAndPing(cfg);
    }, cfg.intervalMs);
  }

  function requestPermissionOnce() {
    try {
      if (localStorage.getItem(STORAGE_PERM_ASKED) === '1') {
        return Promise.resolve();
      }
      localStorage.setItem(STORAGE_PERM_ASKED, '1');
    } catch (e) {
      /* ignore */
    }
    return getNativePosition(15000).catch(function () {
      /* صامت — يُعاد عند الترحيل */
    });
  }

  function start() {
    if (started || !isNativeApp()) {
      return;
    }
    var cfg = readConfig();
    if (!cfg.pingApi || !cfg.csrf) {
      return;
    }
    started = true;

    requestPermissionOnce().finally(function () {
      setTimeout(function () {
        captureAndPing(cfg);
        schedule(cfg);
      }, cfg.initialDelayMs);
    });

    document.addEventListener('visibilitychange', function () {
      if (!document.hidden && !paused) {
        captureAndPing(cfg);
      }
    });

    document.addEventListener(
      'manager:invoice-busy',
      function (ev) {
        paused = !!(ev && ev.detail && ev.detail.busy);
      },
      false
    );
  }

  function boot() {
    if (!isNativeApp()) {
      return;
    }
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', start);
    } else {
      start();
    }
  }

  boot();

  global.AppNativeGps = {
    isNativeApp: isNativeApp,
    captureNow: function () {
      var cfg = readConfig();
      captureAndPing(cfg);
    },
    captureNowReturning: function (timeoutMs) {
      var cfg = readConfig();
      return getNativePosition(timeoutMs || cfg.geoTimeoutMs).then(function (gps) {
        return sendPing(gps, cfg).then(function () {
          return gps;
        });
      });
    },
  };
})(typeof window !== 'undefined' ? window : this);
