(function (global) {

  'use strict';



  var timer = null;

  var intervalTimer = null;

  var running = false;

  var paused = false;



  function sessionGpsEnabled() {

    if (global.AppGeo && typeof global.AppGeo.isSessionGpsEnabled === 'function') {

      return global.AppGeo.isSessionGpsEnabled();

    }

    if (global.AppGeo && typeof global.AppGeo.isSupported === 'function') {

      return global.AppGeo.isSupported();

    }

    return false;

  }



  function readConfig() {

    var cfg = global.UserSessionGpsConfig || {};

    var isMobile = cfg.source === 'mobile';

    return {

      pingApi: String(cfg.pingApi || ''),

      csrf: String(cfg.csrf || ''),

      source: isMobile ? 'mobile' : 'desktop',

      intervalMs: Math.max(600000, parseInt(cfg.intervalMs, 10) || 600000),

      initialDelayMs: Math.max(15000, parseInt(cfg.initialDelayMs, 10) || 45000),

      geoTimeoutMs: Math.max(5000, parseInt(cfg.geoTimeoutMs, 10) || 8000),

    };

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

    fd.append('gps_source', cfg.source);



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

    if (running || paused || !sessionGpsEnabled()) {

      return;

    }



    running = true;



    var geoPromise;

    if (global.AppGeo && typeof global.AppGeo.getCurrentPosition === 'function') {

      geoPromise = global.AppGeo.getCurrentPosition({

        enableHighAccuracy: false,

        maximumAge: 300000,

        timeout: cfg.geoTimeoutMs,

      });

    } else if (global.navigator && navigator.geolocation) {

      geoPromise = new Promise(function (resolve, reject) {

        navigator.geolocation.getCurrentPosition(

          function (pos) {

            resolve({

              latitude: pos.coords.latitude,

              longitude: pos.coords.longitude,

              accuracy:

                typeof pos.coords.accuracy === 'number' ? pos.coords.accuracy : null,

            });

          },

          reject,

          {

            enableHighAccuracy: false,

            maximumAge: 300000,

            timeout: cfg.geoTimeoutMs,

          }

        );

      });

    } else {

      running = false;

      return;

    }



    geoPromise

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



  function isNativeAppTracking() {

    if (global.AppNativeGps && typeof global.AppNativeGps.isNativeApp === 'function') {

      return global.AppNativeGps.isNativeApp();

    }

    if (global.AppGeo && typeof global.AppGeo.isCapacitorNative === 'function') {

      return global.AppGeo.isCapacitorNative();

    }

    return false;

  }



  function start() {

    var cfg = readConfig();

    if (isNativeAppTracking()) {

      return;

    }

    // تتبع دوري للمندوب — تطبيق APK فقط؛ لا نُظهر طلب موقع على متصفح الهاتف.
    if (cfg.source === 'mobile') {

      return;

    }

    if (!cfg.pingApi || !sessionGpsEnabled()) {

      return;

    }



    clearTimeout(timer);

    clearInterval(intervalTimer);



    timer = setTimeout(function () {

      captureAndPing(cfg);

      intervalTimer = setInterval(function () {

        captureAndPing(cfg);

      }, cfg.intervalMs);

    }, cfg.initialDelayMs);

  }



  if (global.document) {

    document.addEventListener(

      'manager:invoice-busy',

      function (ev) {

        paused = !!(ev && ev.detail && ev.detail.busy);

      },

      false

    );

  }



  if (document.readyState === 'loading') {

    document.addEventListener('DOMContentLoaded', start);

  } else {

    start();

  }

})(typeof window !== 'undefined' ? window : this);


