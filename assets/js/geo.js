(function (global) {

  'use strict';



  var nativeGeoAdapter = null;
  var postGpsCache = null;
  var postGpsWarmupTimer = null;
  var postGpsWatchId = null;
  var primedPostGpsPromise = null;

  function isCapacitorNative() {
    try {
      var cap = global.Capacitor;
      if (
        cap &&
        typeof cap.isNativePlatform === 'function' &&
        cap.isNativePlatform() &&
        typeof cap.nativePromise === 'function'
      ) {
        return true;
      }
      if (global.androidBridge && typeof global.androidBridge.postMessage === 'function') {
        return true;
      }
    } catch (e) {
      /* ignore */
    }
    return false;
  }

  /**
   * GPS أصلي من تطبيق APK — يعمل على صفحات السيرفر عبر Capacitor.nativePromise
   * (لا يحتاج تحميل @capacitor/geolocation من السيرفر).
   */
  function getNativeGeoPlugin() {
    if (!isCapacitorNative()) {
      return null;
    }
    if (nativeGeoAdapter) {
      return nativeGeoAdapter;
    }
    var cap = global.Capacitor;
    if (!cap || typeof cap.nativePromise !== 'function') {
      return null;
    }
    nativeGeoAdapter = {
      getCurrentPosition: function (opts) {
        return cap.nativePromise('Geolocation', 'getCurrentPosition', opts || {});
      },
      watchPosition: function (opts, callback) {
        if (typeof cap.nativeCallback === 'function') {
          return Promise.resolve(
            cap.nativeCallback('Geolocation', 'watchPosition', opts || {}, callback)
          );
        }
        return Promise.reject(new Error('watch_unavailable'));
      },
      clearWatch: function (opts) {
        return cap.nativePromise('Geolocation', 'clearWatch', opts || {});
      },
      checkPermissions: function () {
        return cap.nativePromise('Geolocation', 'checkPermissions', {});
      },
      requestPermissions: function () {
        return cap.nativePromise('Geolocation', 'requestPermissions', {});
      },
    };
    return nativeGeoAdapter;
  }



  function canUseGeolocation() {

    if (getNativeGeoPlugin()) {

      return true;

    }

    return !!(global.navigator && navigator.geolocation);

  }



  /** للتتبع الخلفي — يتطلب https/localhost في المتصفح، أو تطبيق APK الأصلي. */

  function isGeoSupported() {

    if (getNativeGeoPlugin()) {

      return true;

    }

    if (!canUseGeolocation()) {

      return false;

    }

    if (typeof global.isSecureContext === 'boolean' && !global.isSecureContext) {

      return false;

    }

    return true;

  }



  function isLocalHostAccess() {

    var h = (global.location && location.hostname) || '';

    return h === 'localhost' || h === '127.0.0.1' || h === '[::1]';

  }



  function isPrivateLanBrowser() {

    if (isCapacitorNative() || !global.location || isLocalHostAccess()) {

      return false;

    }

    var parts = String(location.hostname || '').split('.');

    if (parts.length !== 4) {

      return false;

    }

    var a = parseInt(parts[0], 10);

    var b = parseInt(parts[1], 10);

    if (isNaN(a) || isNaN(b)) {

      return false;

    }

    if (a === 10) {

      return true;

    }

    if (a === 192 && b === 168) {

      return true;

    }

    if (a === 172 && b >= 16 && b <= 31) {

      return true;

    }

    return false;

  }



  function isMobileBrowser() {

    if (isCapacitorNative()) {

      return false;

    }

    try {

      var ua = navigator.userAgent || '';

      if (/Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini|Mobile/i.test(ua)) {

        return true;

      }

      if (global.matchMedia && global.matchMedia('(max-device-width: 900px)').matches) {

        return true;

      }

    } catch (e) {

      /* ignore */

    }

    return false;

  }



  function isMobileLikeClient(source) {

    return source === 'mobile' || isMobileBrowser() || !!getNativeGeoPlugin();

  }



  /** تتبع الجلسة — معطّل على متصفح الكمبيوتر عبر IP الشبكة فقط. */

  function isSessionGpsEnabled() {

    if (getNativeGeoPlugin()) {

      return true;

    }

    if (isPrivateLanBrowser()) {

      return isMobileBrowser() && isGeoSupported();

    }

    return isGeoSupported();

  }



  function readingFromPosition(pos) {

    var coords = pos && pos.coords ? pos.coords : pos;
    var capturedAt =
      pos && typeof pos.timestamp === 'number' && isFinite(pos.timestamp)
        ? pos.timestamp
        : Date.now();

    return {

      latitude: coords.latitude,

      longitude: coords.longitude,

      accuracy:

        typeof coords.accuracy === 'number' && isFinite(coords.accuracy)

          ? coords.accuracy

          : null,

      capturedAt: capturedAt,

    };

  }



  function readingAgeMs(reading) {

    if (!reading || reading.capturedAt == null || !isFinite(reading.capturedAt)) {

      return null;

    }

    return Math.max(0, Date.now() - reading.capturedAt);

  }



  function isCoarseReading(gps, maxAccuracyM) {

    return !!(

      gps &&

      gps.accuracy != null &&

      isFinite(gps.accuracy) &&

      gps.accuracy > maxAccuracyM

    );

  }



  /** قراءة حديثة ودقيقة بما يكفي لترحيل الفاتورة */
  function isFreshPostReading(gps, maxAgeMs, maxAccuracyM) {

    if (

      !gps ||

      !isFinite(gps.latitude) ||

      !isFinite(gps.longitude) ||

      (Math.abs(gps.latitude) < 0.000001 && Math.abs(gps.longitude) < 0.000001)

    ) {

      return false;

    }

    var age = readingAgeMs(gps);

    if (age != null && age > maxAgeMs) {

      return false;

    }

    if (isCoarseReading(gps, maxAccuracyM)) {

      return false;

    }

    return true;

  }



  function isBetterReading(candidate, current) {

    if (!current) {

      return true;

    }

    if (candidate.accuracy == null && current.accuracy == null) {

      var cAge = readingAgeMs(candidate);

      var curAge = readingAgeMs(current);

      if (cAge != null && curAge != null) {

        return cAge < curAge;

      }

      return false;

    }

    if (candidate.accuracy == null) {

      return false;

    }

    if (current.accuracy == null) {

      return true;

    }

    if (candidate.accuracy < current.accuracy - 10) {

      return true;

    }

    if (current.accuracy < candidate.accuracy - 10) {

      return false;

    }

    var candAge = readingAgeMs(candidate);

    var currAge = readingAgeMs(current);

    if (candAge != null && currAge != null && candAge + 4000 < currAge) {

      return true;

    }

    if (currAge != null && candAge != null && currAge + 4000 < candAge) {

      return false;

    }

    return candidate.accuracy < current.accuracy;

  }



  function defaultGeoOptions(options) {

    var opts = options || {};

    return {

      enableHighAccuracy: opts.enableHighAccuracy !== false,

      maximumAge: opts.maximumAge != null ? opts.maximumAge : 0,

      timeout: Math.max(12000, parseInt(opts.timeout, 10) || 28000),

    };

  }



  function ensureNativeGeoPermission(plugin) {
    if (!plugin || typeof plugin.requestPermissions !== 'function') {
      return Promise.resolve();
    }
    return plugin
      .requestPermissions()
      .catch(function () {
        if (typeof plugin.checkPermissions === 'function') {
          return plugin.checkPermissions();
        }
      });
  }



  function getCurrentPositionNative(options) {

    var plugin = getNativeGeoPlugin();

    if (!plugin) {

      return Promise.reject(new Error('no_geolocation'));

    }

    var opts = defaultGeoOptions(options);

    return ensureNativeGeoPermission(plugin)

      .then(function () {

        return plugin.getCurrentPosition({

          enableHighAccuracy: opts.enableHighAccuracy,

          timeout: opts.timeout,

          maximumAge: opts.maximumAge,

        });

      })

      .then(function (pos) {

        return readingFromPosition(pos);

      });

  }



  function getBestPositionNative(options) {
    var plugin = getNativeGeoPlugin();
    if (!plugin) {
      return Promise.reject(new Error('no_geolocation'));
    }
    options = options || {};
    var geoOpts = defaultGeoOptions(options);
    geoOpts.enableHighAccuracy = true;
    geoOpts.maximumAge = 0;
    return ensureNativeGeoPermission(plugin).then(function () {
      var readings = [];
      function sampleOnce() {
        return getCurrentPositionNative(geoOpts);
      }
      return sampleOnce().then(function (first) {
        readings.push(first);
        return new Promise(function (resolve) {
          setTimeout(function () {
            sampleOnce()
              .then(function (second) {
                readings.push(second);
                setTimeout(function () {
                  sampleOnce()
                    .then(function (third) {
                      readings.push(third);
                      resolve(pickBestReading(readings));
                    })
                    .catch(function () {
                      resolve(pickBestReading(readings));
                    });
                }, 2800);
              })
              .catch(function () {
                resolve(pickBestReading(readings));
              });
          }, 2800);
        });
      });
    });
  }

  function pickBestReading(readings) {
    var best = null;
    readings.forEach(function (reading) {
      if (isBetterReading(reading, best)) {
        best = reading;
      }
    });
    if (!best) {
      throw new Error('gps_failed');
    }
    return best;
  }



  /**

   * @returns {Promise<{latitude:number, longitude:number, accuracy:number|null}>}

   */

  function getCurrentPosition(options) {

    if (getNativeGeoPlugin()) {

      return getCurrentPositionNative(options);

    }

    return new Promise(function (resolve, reject) {

      if (!canUseGeolocation()) {

        reject(new Error('no_geolocation'));

        return;

      }

      var opts = defaultGeoOptions(options);

      navigator.geolocation.getCurrentPosition(

        function (pos) {

          resolve(readingFromPosition(pos));

        },

        function (err) {

          reject(err || new Error('gps_failed'));

        },

        opts

      );

    });

  }



  /**

   * عدة قراءات خلال فترة قصيرة — يُعاد أدق موقع (أقل accuracy بالأمتار).

   * @returns {Promise<{latitude:number, longitude:number, accuracy:number|null}>}

   */

  function getBestPosition(options) {

    options = options || {};

    if (!canUseGeolocation()) {

      return Promise.reject(new Error('no_geolocation'));

    }

    if (getNativeGeoPlugin()) {

      return getBestPositionNative(options);

    }



    var sampleMs = Math.max(5000, Math.min(22000, parseInt(options.sampleMs, 10) || 14000));

    var goodEnoughM = parseFloat(options.goodEnoughM);

    if (!isFinite(goodEnoughM) || goodEnoughM <= 0) {

      goodEnoughM = 25;

    }

    var geoOpts = defaultGeoOptions(options);



    return new Promise(function (resolve, reject) {

      var best = null;

      var watchId = null;

      var sampleTimer = null;

      var settled = false;



      function cleanup() {

        if (watchId != null) {

          navigator.geolocation.clearWatch(watchId);

          watchId = null;

        }

        if (sampleTimer != null) {

          clearTimeout(sampleTimer);

          sampleTimer = null;

        }

      }



      function finish(err) {

        if (settled) {

          return;

        }

        settled = true;

        cleanup();

        if (best) {

          resolve(best);

          return;

        }

        reject(err || new Error('gps_failed'));

      }



      function consider(pos) {

        var reading = readingFromPosition(pos);

        if (isBetterReading(reading, best)) {

          best = reading;

        }

        if (reading.accuracy != null && reading.accuracy <= goodEnoughM) {

          finish();

        }

      }



      watchId = navigator.geolocation.watchPosition(

        consider,

        function (err) {

          if (!best) {

            finish(err);

          }

        },

        geoOpts

      );



      sampleTimer = setTimeout(function () {

        finish();

      }, sampleMs);



      getCurrentPosition(geoOpts)

        .then(function (reading) {

          if (isBetterReading(reading, best)) {

            best = reading;

          }

          if (reading.accuracy != null && reading.accuracy <= goodEnoughM) {

            finish();

          }

        })

        .catch(function () {

          /* watchPosition أو المؤقت يكملان */

        });

    });

  }



  function appendToFormData(fd, gps, source) {

    if (!gps || !fd) {

      return;

    }

    fd.append('latitude', String(gps.latitude));

    fd.append('longitude', String(gps.longitude));

    if (gps.accuracy != null && isFinite(gps.accuracy)) {

      fd.append('gps_accuracy', String(gps.accuracy));

    }

    var src = gps.manual ? 'manual' : source;

    if (src) {

      fd.append('gps_source', src);

    }

    var capturedAt = gps.capturedAt != null ? gps.capturedAt : Date.now();
    fd.append('gps_captured_at', String(Math.floor(capturedAt)));

  }



  function isValidCoordReading(gps) {
    return !!(
      gps &&
      isFinite(gps.latitude) &&
      isFinite(gps.longitude) &&
      !(Math.abs(gps.latitude) < 0.000001 && Math.abs(gps.longitude) < 0.000001)
    );
  }

  function pingUserLocationSilently(gps, source) {
    var cfg = global.UserSessionGpsConfig || {};
    if (!cfg.pingApi || !gps || !isValidCoordReading(gps)) {
      return;
    }
    var fd = new FormData();
    fd.append('_csrf', cfg.csrf || '');
    fd.append('latitude', String(gps.latitude));
    fd.append('longitude', String(gps.longitude));
    if (gps.accuracy != null && isFinite(gps.accuracy)) {
      fd.append('gps_accuracy', String(gps.accuracy));
    }
    fd.append('gps_source', source || cfg.source || 'mobile');
    fetch(cfg.pingApi, {
      method: 'POST',
      body: fd,
      credentials: 'same-origin',
      headers: { Accept: 'application/json' },
    }).catch(function () {
      /* صامت */
    });
  }

  function isAcceptablePostGps(gps) {

    if (!isValidCoordReading(gps)) {

      return false;

    }

    if (gps.manual) {

      return true;

    }

    if (isCoarseReading(gps, 150)) {

      return false;

    }

    var age = readingAgeMs(gps);

    if (age != null && age > 180000) {

      return false;

    }

    return true;

  }



  function rememberGps(gps) {

    if (!gps) {

      return;

    }

    try {

      if (global.AppGeoMapPick && AppGeoMapPick.rememberCoords) {

        AppGeoMapPick.rememberCoords(gps.latitude, gps.longitude);

      } else {

        localStorage.setItem('mgr_last_gps_lat', String(gps.latitude));

        localStorage.setItem('mgr_last_gps_lng', String(gps.longitude));

      }

    } catch (e) {

      /* ignore */

    }

  }



  /** يحفظ آخر قراءة GPS للاستخدام الفوري عند الترحيل */
  function rememberReading(gps) {
    if (
      !gps ||
      !isFinite(gps.latitude) ||
      !isFinite(gps.longitude) ||
      (Math.abs(gps.latitude) < 0.000001 && Math.abs(gps.longitude) < 0.000001)
    ) {
      return;
    }
    postGpsCache = {
      latitude: gps.latitude,
      longitude: gps.longitude,
      accuracy:
        typeof gps.accuracy === 'number' && isFinite(gps.accuracy) ? gps.accuracy : null,
      at: Date.now(),
    };
  }



  function getPostGpsCache(maxAgeMs) {
    if (!postGpsCache) {
      return null;
    }
    if (Date.now() - postGpsCache.at > maxAgeMs) {
      return null;
    }
    return {
      latitude: postGpsCache.latitude,
      longitude: postGpsCache.longitude,
      accuracy: postGpsCache.accuracy,
      capturedAt: postGpsCache.at,
    };
  }

  /** موقع حديث للترحيل — لا نستخدم قراءات قديمة أو تقريبية */
  function getFreshPostGpsCache(maxAgeMs, maxAccuracyM) {
    var cached = getPostGpsCache(maxAgeMs);
    if (!cached || !isFreshPostReading(cached, maxAgeMs, maxAccuracyM)) {
      return null;
    }
    return cached;
  }



  /** تتبع GPS في الخلفية — يجهّز موقعاً جاهزاً عند الترحيل */
  function startPostGpsWarmup(source) {
    if (!canUseGeolocation()) {
      return;
    }

    function tickNative() {
      getCurrentPosition({
        enableHighAccuracy: true,
        maximumAge: 0,
        timeout: 22000,
      })
        .then(function (gps) {
          rememberReading(gps);
        })
        .catch(function () {
          /* صامت */
        });
    }

    if (getNativeGeoPlugin()) {
      ensureNativeGeoPermission()
        .then(function () {
          tickNative();
          if (!postGpsWarmupTimer) {
            postGpsWarmupTimer = setInterval(tickNative, 90000);
          }
        })
        .catch(function () {
          /* ignore */
        });
      return;
    }

    if (!navigator.geolocation) {
      return;
    }
    if (postGpsWatchId != null) {
      return;
    }

    var opts = defaultGeoOptions({
      enableHighAccuracy: true,
      maximumAge: 0,
      timeout: 28000,
    });

    postGpsWatchId = navigator.geolocation.watchPosition(
      function (pos) {
        rememberReading(readingFromPosition(pos));
      },
      function () {
        /* ignore */
      },
      opts
    );
  }



  function pickMapLocation(options) {

    options = options || {};

    if (global.AppGeoMapPick && typeof AppGeoMapPick.pickLocationOnMap === 'function') {

      return AppGeoMapPick.pickLocationOnMap(options);

    }

    return Promise.reject(new Error('map_unavailable'));

  }



  function offerPostWithoutGps(onReady) {

    confirmPostWithoutGps().then(function (proceed) {

      if (proceed) {

        onReady(null);

      }

    });

  }



  /** http على IP الشبكة (XAMPP) — المتصفح يمنع GPS التلقائي؛ الخريطة اليدوية تعمل. */
  function isHttpLanBlocked() {
    if (isCapacitorNative()) {
      return false;
    }
    if (isLocalHostAccess()) {
      return false;
    }
    if (typeof global.isSecureContext === 'boolean' && global.isSecureContext) {
      return false;
    }
    return isPrivateLanBrowser() || !isGeoSupported();
  }

  function seedMobileSessionGps(source) {
    source = source || 'mobile';
    if (!canUseGeolocation()) {
      return;
    }
    getCurrentPosition({
      enableHighAccuracy: true,
      maximumAge: 180000,
      timeout: 18000,
    })
      .then(function (gps) {
        if (gps && isValidCoordReading(gps)) {
          rememberReading(gps);
          pingUserLocationSilently(gps, source);
        }
      })
      .catch(function () {
        /* صامت */
      })
      .finally(function () {
        startPostGpsWarmup(source);
      });
  }

  function captureForPost(source, opts) {
    opts = opts || {};
    source = source || 'mobile';
    var primed = consumePrimedPostGps(opts.primed);

    function fromPrimed() {
      if (primed && typeof primed.then === 'function') {
        return primed
          .then(function (gps) {
            return gps && isValidCoordReading(gps) ? gps : null;
          })
          .catch(function () {
            return null;
          });
      }
      return Promise.resolve(null);
    }

    return fromPrimed()
      .then(function (gps) {
        if (gps) {
          return gps;
        }
        return resolveGpsForPost(source).catch(function () {
          return null;
        });
      })
      .then(function (gps) {
        if (gps && isValidCoordReading(gps)) {
          return gps;
        }
        return silentPostFinalAttempt(source);
      })
      .then(function (gps) {
        if (gps && isValidCoordReading(gps)) {
          rememberReading(gps);
          pingUserLocationSilently(gps, source);
          return gps;
        }
        return null;
      });
  }

  function gpsEnvironmentHint() {
    if (isCapacitorNative()) {
      return 'apk';
    }
    if (typeof global.isSecureContext === 'boolean' && !global.isSecureContext && !isLocalHostAccess()) {
      return 'need_https';
    }
    if (!canUseGeolocation()) {
      return 'no_geolocation';
    }
    return 'ok';
  }

  function prefetchForPost(source) {
    if (!canUseGeolocation() || isHttpLanBlocked()) {
      return;
    }
    resolveGpsForPost(source || 'desktop')
      .then(function (gps) {
        rememberReading(gps);
      })
      .catch(function () {
        /* صامت */
      });
  }

  function offerLocationFallback(source, onReady, fallbackOpts) {

    fallbackOpts = fallbackOpts || {};
    var forPost = !!fallbackOpts.forPost;
    var requireLocation = !!fallbackOpts.requireLocation;

    var inApk = isCapacitorNative();
    var httpLan = isHttpLanBlocked();
    var mapMsg = inApk
      ? 'تعذر تحديد الموقع من GPS الهاتف.\n\nتأكد من:\n• تفعيل الموقع في إعدادات أندرويد\n• السماح للتطبيق «النظام المحاسبي» بالوصول للموقع\n\nحدّد موقعك على الخريطة (اضغط «موقعي الآن» أو انقر على موقعك).'
      : httpLan
        ? 'GPS التلقائي غير متاح عبر http على الشبكة المحلية.\n\n• اضغط «فتح الخريطة»\n• ثم «موقعي الآن» أو انقر على موقعك\n\nبعد الرفع على https سيعمل GPS تلقائياً.'
        : 'تعذر تحديد الموقع تلقائياً (GPS).\n\nحدّد موقعك على الخريطة — اضغط «موقعي الآن» أو انقر على موقعك.';

    if (requireLocation) {
      mapMsg += '\n\nلا يمكن إتمام الترحيل بدون تحديد موقع صحيح.';
    }

    if (global.AppDialog && AppDialog.confirm) {

      AppDialog.confirm(mapMsg, {

        title: 'تحديد الموقع',

        okText: 'فتح الخريطة',

        cancelText: requireLocation ? 'إلغاء الترحيل' : 'خيارات أخرى',

      }).then(function (useMap) {

        if (useMap) {

          pickMapLocation({ forPost: forPost || requireLocation })

            .then(function (gps) {

              if (!isAcceptablePostGps(gps)) {
                if (global.AppDialog && AppDialog.alert) {
                  AppDialog.alert('الموقع المحدّد غير صالح. حاول مرة أخرى.', { type: 'warning' });
                }
                onReady(undefined);
                return;
              }

              rememberGps(gps);

              onReady(gps);

            })

            .catch(function (err) {

              if (err && err.message === 'cancelled') {

                onReady(undefined);

                return;

              }

              if (global.AppDialog && AppDialog.alert) {

                AppDialog.alert(

                  'تعذر تحميل الخريطة. تحقق من الاتصال بالإنترنت لتحميل الخريطة.',

                  { type: 'warning' }

                );

              }

              if (requireLocation) {
                onReady(undefined);
              } else {
                offerPostWithoutGps(onReady);
              }

            });

          return;

        }

        if (requireLocation) {
          onReady(undefined);
        } else {
          offerPostWithoutGps(onReady);
        }

      });

      return;

    }

    if (requireLocation) {
      onReady(undefined);
    } else {
      offerPostWithoutGps(onReady);
    }

  }



  function confirmPostWithoutGps() {

    var insecure =

      typeof global.isSecureContext === 'boolean' && !global.isSecureContext;

    var native = isCapacitorNative();

    var httpsHint = '';

    var extra = '';



    var mobileBrowser = isMobileBrowser();

    if (native) {
      extra =
        '\n\nتأكد من تفعيل الموقع في إعدادات أندرويد والسماح لتطبيق «النظام المحاسبي» بالوصول للموقع.';

    } else if (insecure && global.location && location.hostname) {

      var host = location.hostname;

      if (host !== 'localhost' && host !== '127.0.0.1') {

        httpsHint = mobileBrowser

          ? '\n\nافتح الرابط بـ https://' +

            host +

            location.pathname +

            '\n\nأو ثبّت تطبيق APK «النظام المحاسبي».'

          : '\n\nلتفعيل GPS افتح:\nhttps://' + host + location.pathname;

      }

    } else if (mobileBrowser) {

      extra =

        '\n\nعلى الهاتف:\n' +

        '• فعّل خدمة الموقع من إعدادات الجهاز\n' +

        '• اسمح للمتصفح بالوصول إلى الموقع عند ظهور الطلب\n' +

        '• أو استخدم تطبيق APK «النظام المحاسبي»';

    } else if (!insecure) {

      extra =

        '\n\nعلى الكمبيوتر: فعّل الموقع من إعدادات Windows → الخصوصية → الموقع.';

    }



    var msg = insecure && !native

      ? 'تعذر تحديد الموقع (GPS).\n\nمتصفح الهاتف/الكمبيوتر لا يدعم GPS عبر http على الشبكة المحلية.' +

        httpsHint +

        '\n\nأو تابع الترحيل بدون حفظ الإحداثيات.'

      : 'تعذر تحديد الموقع (GPS).' + extra + '\n\nهل تريد الترحيل بدون حفظ الإحداثيات؟';



    if (global.AppDialog && AppDialog.confirm) {

      return AppDialog.confirm(msg, {

        title: 'الموقع غير متاح',

        okText: 'ترحيل بدون موقع',

        cancelText: 'إلغاء',

      });

    }

    return Promise.resolve(global.confirm('تعذر تحديد الموقع. ترحيل بدون إحداثيات؟'));

  }



  function requestGpsForPost(source) {
    if (!canUseGeolocation()) {
      return Promise.reject(new Error('no_geolocation'));
    }
    var isMobile = isMobileLikeClient(source);
    var postOpts = {
      enableHighAccuracy: true,
      maximumAge: 0,
    };
    if (isMobile) {
      return getBestPosition(
        Object.assign(
          {
            sampleMs: 14000,
            goodEnoughM: 50,
            timeout: 22000,
          },
          postOpts
        )
      )
        .then(function (reading) {
          if (isCoarseReading(reading, 200)) {
            return getCurrentPosition(
              Object.assign(
                {
                  timeout: 18000,
                  maximumAge: 60000,
                },
                postOpts
              )
            );
          }
          return reading;
        })
        .catch(function () {
          return getCurrentPosition(
            Object.assign(
              {
                timeout: 20000,
                maximumAge: 120000,
              },
              postOpts
            )
          );
        });
    }
    return getBestPosition(
      Object.assign(
        {
          sampleMs: 10000,
          goodEnoughM: 80,
          timeout: 22000,
        },
        postOpts
      )
    ).catch(function () {
      return getCurrentPosition(
        Object.assign(
          {
            timeout: 18000,
            maximumAge: 60000,
          },
          postOpts
        )
      );
    });
  }



  function resolveGpsForPost(source) {
    var isMobile = isMobileLikeClient(source);
    var isNative = !!getNativeGeoPlugin();

    function tryFresh() {
      return requestGpsForPost(source);
    }

    function cacheFallback(maxAgeMs) {
      var stale = getPostGpsCache(maxAgeMs);
      if (stale && isValidCoordReading(stale)) {
        return stale;
      }
      return null;
    }

    if (isNative) {
      return getCurrentPosition({
        enableHighAccuracy: true,
        maximumAge: 60000,
        timeout: 22000,
      })
        .catch(function () {
          return tryFresh();
        })
        .catch(function () {
          var c = cacheFallback(600000);
          if (c) {
            return c;
          }
          return Promise.reject(new Error('gps_failed'));
        });
    }

    if (isMobile) {
      var cached = getFreshPostGpsCache(300000, 500);
      if (cached) {
        return Promise.resolve(cached);
      }
      return getCurrentPosition({
        enableHighAccuracy: true,
        maximumAge: 120000,
        timeout: 12000,
      })
        .catch(function () {
          return tryFresh();
        })
        .catch(function () {
          var stale = cacheFallback(600000);
          if (stale) {
            return stale;
          }
          return Promise.reject(new Error('gps_failed'));
        });
    }

    var cachedDesktop = cacheFallback(900000);
    if (cachedDesktop) {
      return Promise.resolve(cachedDesktop);
    }

    return tryFresh().catch(function () {
      var stale = cacheFallback(600000);
      if (stale) {
        return stale;
      }
      return Promise.reject(new Error('gps_failed'));
    });
  }



  function wantsAutoGpsOnly(source) {
    return source === 'mobile' || isCapacitorNative() || isMobileLikeClient(source);
  }

  function isSilentPost(source, opts) {
    if (opts && opts.silent) {
      return true;
    }
    if (global.APP_GPS_SILENT_POST) {
      return wantsAutoGpsOnly(source);
    }
    return false;
  }

  function onPostGpsUnavailable(source, opts, done, offerMapFallback) {
    if (isSilentPost(source, opts)) {
      silentPostFinalAttempt(source)
        .then(function (gps) {
          if (gps && isValidCoordReading(gps)) {
            rememberReading(gps);
            done(gps);
            return;
          }
          done(null);
        })
        .catch(function () {
          done(null);
        });
      return;
    }
    if (isMobileLikeClient(source)) {
      offerMapFallback();
      return;
    }
    done(null);
  }

  function silentPostFinalAttempt(source) {
    if (!canUseGeolocation()) {
      return Promise.resolve(null);
    }
    var cached = getPostGpsCache(14400000);
    if (cached && isValidCoordReading(cached)) {
      return Promise.resolve(cached);
    }
    return getCurrentPosition({
      enableHighAccuracy: true,
      maximumAge: 600000,
      timeout: 20000,
    }).catch(function () {
      return getCurrentPosition({
        enableHighAccuracy: false,
        maximumAge: 900000,
        timeout: 12000,
      });
    });
  }

  /**
   * يُستدعى مباشرة من نقرة المستخدم (قبل أي await) لإظهار طلب صلاحية الموقع في المتصفح.
   * @returns {Promise<object>|null}
   */
  function primePostGpsFromUserGesture(source) {
    primedPostGpsPromise = null;
    if (!canUseGeolocation()) {
      return null;
    }

    var postOpts = {
      enableHighAccuracy: true,
      maximumAge: 0,
      timeout: 30000,
    };

    if (getNativeGeoPlugin()) {
      primedPostGpsPromise = ensureNativeGeoPermission()
        .then(function () {
          return getCurrentPosition(postOpts);
        })
        .catch(function () {
          return requestGpsForPost(source);
        });
      return primedPostGpsPromise;
    }

    primedPostGpsPromise = getCurrentPosition(postOpts).catch(function () {
      return requestGpsForPost(source);
    });
    return primedPostGpsPromise;
  }

  function clearPrimedPostGps() {
    primedPostGpsPromise = null;
  }

  function consumePrimedPostGps(externalPrimed) {
    var primed = externalPrimed || primedPostGpsPromise;
    primedPostGpsPromise = null;
    return primed || null;
  }



  /**

   * @param {'mobile'|'desktop'} source

   * @param {function(object|null|undefined): void} onReady — null = بدون GPS، undefined = أُلغي

   * @param {{ primed?: Promise<object>|null }} [opts]

   */

  function withGpsForPost(source, onReady, opts) {

    opts = opts || {};
    var settled = false;
    var mobilePost = isMobileLikeClient(source);
    var primed = consumePrimedPostGps(opts.primed);

    function done(gps) {
      if (settled) {
        return;
      }
      settled = true;
      if (gps === undefined) {
        onReady(undefined);
        return;
      }
      if (gps && isValidCoordReading(gps)) {
        rememberReading(gps);
        pingUserLocationSilently(gps, source);
        onReady(gps);
        return;
      }
      onReady(null);
    }

    function offerMapFallback() {
      if (isSilentPost(source, opts)) {
        done(null);
        return;
      }
      if (!global.AppGeoMapPick || typeof AppGeoMapPick.pickLocationOnMap !== 'function') {
        done(isMobileLikeClient(source) && !isSilentPost(source, opts) ? undefined : null);
        return;
      }
      offerLocationFallback(source, done, {
        forPost: true,
        requireLocation: mobilePost && !isSilentPost(source, opts),
      });
    }

    function gpsUnavailable() {
      onPostGpsUnavailable(source, opts, done, offerMapFallback);
    }

    function runResolvePath() {
      resolveGpsForPost(source)
        .then(function (gps) {
          if (gps && isValidCoordReading(gps)) {
            done(gps);
            return;
          }
          if (mobilePost && !isSilentPost(source, opts)) {
            offerMapFallback();
            return;
          }
          if (mobilePost) {
            gpsUnavailable();
            return;
          }
          done(null);
        })
        .catch(function () {
          var cached = getPostGpsCache(600000);
          if (cached && isValidCoordReading(cached)) {
            done(cached);
            return;
          }
          if (mobilePost && !isSilentPost(source, opts)) {
            offerMapFallback();
            return;
          }
          if (mobilePost) {
            gpsUnavailable();
            return;
          }
          done(null);
        });
    }

    if (primed && typeof primed.then === 'function') {
      primed
        .then(function (gps) {
          if (gps && isValidCoordReading(gps)) {
            done(gps);
            return;
          }
          if (mobilePost && !isSilentPost(source, opts)) {
            offerMapFallback();
            return;
          }
          runResolvePath();
        })
        .catch(function () {
          if (mobilePost && !isSilentPost(source, opts)) {
            offerMapFallback();
            return;
          }
          runResolvePath();
        });
      return;
    }

    if (!canUseGeolocation()) {
      if (mobilePost) {
        gpsUnavailable();
        return;
      }
      done(null);
      return;
    }

    startPostGpsWarmup(source);
    runResolvePath();

  }



  global.AppGeo = {

    isSupported: isGeoSupported,

    isSessionGpsEnabled: isSessionGpsEnabled,

    isPrivateLanBrowser: isPrivateLanBrowser,

    isHttpLanBlocked: isHttpLanBlocked,

    isMobileBrowser: isMobileBrowser,

    canUseGeolocation: canUseGeolocation,

    isCapacitorNative: isCapacitorNative,

    getCurrentPosition: getCurrentPosition,

    getBestPosition: getBestPosition,

    appendToFormData: appendToFormData,

    isAcceptablePostGps: isAcceptablePostGps,

    withGpsForPost: withGpsForPost,

    primePostGpsFromUserGesture: primePostGpsFromUserGesture,

    clearPrimedPostGps: clearPrimedPostGps,

    captureForPost: captureForPost,

    seedMobileSessionGps: seedMobileSessionGps,

    gpsEnvironmentHint: gpsEnvironmentHint,

    prefetchForPost: prefetchForPost,

    pickMapLocation: pickMapLocation,

    rememberReading: rememberReading,

    startPostGpsWarmup: startPostGpsWarmup,

    getPostGpsCache: getPostGpsCache,

  };



  global.MobileGeo = global.AppGeo;



  if (global.document) {

    function bootPostGpsWarmup() {

      var cfg = global.UserSessionGpsConfig || {};

      if (!global.APP_GPS_ENABLED) {
        return;
      }

      // على متصفح الموبايل: لا نطلب الموقع عند فتح الصفحة — فقط عند الترحيل (صامت).
      if (isMobileBrowser() && !isCapacitorNative()) {
        return;
      }

      startPostGpsWarmup(cfg.source === 'mobile' || isCapacitorNative() ? 'mobile' : 'desktop');

    }

    if (document.readyState === 'loading') {

      document.addEventListener('DOMContentLoaded', bootPostGpsWarmup);

    } else {

      bootPostGpsWarmup();

    }

  }

})(typeof window !== 'undefined' ? window : this);


