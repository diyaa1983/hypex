(function (global) {
  'use strict';

  var DEFAULT_LAT = 31.9539;
  var DEFAULT_LNG = 35.9106;
  var leafletPromise = null;

  function loadScript(src) {
    return new Promise(function (resolve, reject) {
      var s = document.createElement('script');
      s.src = src;
      s.async = true;
      s.onload = function () {
        resolve();
      };
      s.onerror = reject;
      document.head.appendChild(s);
    });
  }

  function loadStyle(href) {
    return new Promise(function (resolve, reject) {
      var l = document.createElement('link');
      l.rel = 'stylesheet';
      l.href = href;
      l.onload = function () {
        resolve();
      };
      l.onerror = reject;
      document.head.appendChild(l);
    });
  }

  function ensureLeaflet() {
    if (global.LeafletMapLayers && typeof LeafletMapLayers.ensureLeaflet === 'function') {
      return LeafletMapLayers.ensureLeaflet();
    }
    if (global.L && global.L.map) {
      return Promise.resolve();
    }
    if (leafletPromise) {
      return leafletPromise;
    }
    leafletPromise = loadStyle('https://unpkg.com/leaflet@1.9.4/dist/leaflet.css')
      .then(function () {
        return loadScript('https://unpkg.com/leaflet@1.9.4/dist/leaflet.js');
      })
      .then(function () {
        if (!global.L || !global.L.map) {
          throw new Error('leaflet_load_failed');
        }
      });
    return leafletPromise;
  }

  /**
   * طبقات الأساس: Carto عند التكبير العالي (يمنع بلاطات Esri «Map data not yet available»).
   */
  function attachPickBaseLayer(map) {
    var osmCfg = global.AppOsmConfig || {};
    if (global.LeafletMapLayers && typeof LeafletMapLayers.attach === 'function') {
      return LeafletMapLayers.attach(map, {
        tileUrl: osmCfg.tileUrl,
        attribution: osmCfg.attribution,
        mapProvider: osmCfg.mapProvider,
        googleKey: osmCfg.googleMapsKey || osmCfg.google_maps_key,
      });
    }

    var carto = global.L.tileLayer(
      'https://{s}.basemaps.cartocdn.com/rastertiles/voyager/{z}/{x}/{y}{r}.png',
      {
        attribution:
          '&copy; <a href="https://www.openstreetmap.org/copyright">OSM</a> &copy; CARTO',
        maxZoom: 20,
        subdomains: 'abcd',
      }
    );
    carto.addTo(map);

    var provider = String(osmCfg.mapProvider || 'esri').toLowerCase();
    if (provider === 'carto') {
      return Promise.resolve('carto');
    }

    var esriUrl =
      (osmCfg.tileUrl && String(osmCfg.tileUrl).indexOf('{z}') >= 0
        ? String(osmCfg.tileUrl)
        : 'https://server.arcgisonline.com/ArcGIS/rest/services/World_Street_Map/MapServer/tile/{z}/{y}/{x}');
    var esri = global.L.tileLayer(esriUrl, {
      attribution: (osmCfg.attribution && String(osmCfg.attribution)) || '&copy; Esri',
      maxNativeZoom: 17,
      maxZoom: 17,
    });
    var esriCutoff = 14;
    function syncEsri() {
      var z = map.getZoom();
      if (z > esriCutoff) {
        if (map.hasLayer(esri)) map.removeLayer(esri);
      } else if (!map.hasLayer(esri)) {
        esri.addTo(map);
      }
    }
    map.on('zoomend', syncEsri);
    syncEsri();
    return Promise.resolve('esri-hybrid');
  }

  function readLastCoords() {
    try {
      var lat = parseFloat(localStorage.getItem('mgr_last_gps_lat'));
      var lng = parseFloat(localStorage.getItem('mgr_last_gps_lng'));
      if (isFinite(lat) && isFinite(lng)) {
        return { lat: lat, lng: lng, isDefault: false };
      }
    } catch (e) {
      /* ignore */
    }
    return { lat: DEFAULT_LAT, lng: DEFAULT_LNG, isDefault: true };
  }

  function classifyGpsError(err) {
    if (global.AppGeo && typeof AppGeo.gpsEnvironmentHint === 'function') {
      var env = AppGeo.gpsEnvironmentHint();
      if (env === 'need_https') return 'need_https';
      if (env === 'no_geolocation') return 'no_geolocation';
    }
    if (typeof global.isSecureContext === 'boolean' && !global.isSecureContext) {
      var h = (global.location && location.hostname) || '';
      if (h && h !== 'localhost' && h !== '127.0.0.1' && h !== '[::1]') {
        return 'need_https';
      }
    }
    if (!global.navigator || !navigator.geolocation) {
      return 'no_geolocation';
    }
    var code = err && (err.code != null ? err.code : err.message);
    var msg = String((err && err.message) || err || '').toLowerCase();
    if (code === 'need_https' || msg.indexOf('need_https') !== -1) {
      return 'need_https';
    }
    if (msg.indexOf('no_app_geo') !== -1) {
      /* نتابع للمسار البديل */
    }
    if (code === 1 || code === '1' || msg.indexOf('denied') !== -1 || msg.indexOf('permission') !== -1) {
      return 'denied';
    }
    if (code === 3 || code === '3' || msg.indexOf('timeout') !== -1) {
      return 'timeout';
    }
    if (code === 2 || code === '2' || msg.indexOf('unavailable') !== -1) {
      return 'unavailable';
    }
    if (msg.indexOf('no_geolocation') !== -1) return 'no_geolocation';
    return 'failed';
  }

  function hintForGpsError(code, forPost) {
    if (code === 'need_https') {
      return 'المتصفح يمنع GPS على عنوان الشبكة بدون HTTPS. افتح النظام عبر localhost أو فعّل HTTPS، أو حدّد موقعك بالنقر على الخريطة / البحث.';
    }
    if (code === 'denied') {
      return 'تم رفض إذن الموقع. اسمح للمتصفح بالوصول للموقع (أيقونة القفل بجانب الشريط) ثم أعد المحاولة.';
    }
    if (code === 'timeout') {
      return 'انتهت مهلة انتظار GPS. تأكد من تفعيل موقع Windows/الجهاز و Wi‑Fi، ثم اضغط «موقعي الآن» مجدداً أو انقر على الخريطة.';
    }
    if (code === 'unavailable') {
      return 'خدمة الموقع غير متاحة حالياً. فعّل GPS/Location في النظام ثم أعد المحاولة، أو انقر موقعك على الخريطة.';
    }
    if (code === 'no_geolocation') {
      return 'المتصفح لا يدعم تحديد الموقع. استخدم بحث الإحداثيات أو انقر على الخريطة.';
    }
    if (forPost) {
      return 'تعذر جلب GPS. انقر موقعك على الخريطة ثم «تأكيد الموقع».';
    }
    return 'تعذر جلب موقعك الحالي. اسمح بالوصول للموقع من المتصفح، أو انقر على الخريطة / ابحث عن مكان.';
  }

  function readingOk(gps) {
    return (
      gps &&
      isFinite(gps.lat != null ? gps.lat : gps.latitude) &&
      isFinite(gps.lng != null ? gps.lng : gps.longitude) &&
      !(
        Math.abs(gps.lat != null ? gps.lat : gps.latitude) < 0.000001 &&
        Math.abs(gps.lng != null ? gps.lng : gps.longitude) < 0.000001
      )
    );
  }

  function normalizeReading(gps) {
    if (!gps) return null;
    var lat = gps.lat != null ? gps.lat : gps.latitude;
    var lng = gps.lng != null ? gps.lng : gps.longitude;
    if (!isFinite(lat) || !isFinite(lng)) return null;
    return {
      lat: lat,
      lng: lng,
      fromGps: true,
      accuracy: gps.accuracy != null ? gps.accuracy : null,
      capturedAt: gps.capturedAt != null ? gps.capturedAt : Date.now(),
    };
  }

  function tryBrowserGeolocation(opts) {
    opts = opts || {};
    return new Promise(function (resolve, reject) {
      if (!global.navigator || !navigator.geolocation) {
        reject(Object.assign(new Error('no_geolocation'), { code: 0 }));
        return;
      }
      navigator.geolocation.getCurrentPosition(
        function (pos) {
          resolve({
            latitude: pos.coords.latitude,
            longitude: pos.coords.longitude,
            accuracy: pos.coords.accuracy,
            capturedAt: pos.timestamp || Date.now(),
          });
        },
        function (err) {
          reject(err || new Error('gps_failed'));
        },
        {
          enableHighAccuracy: opts.enableHighAccuracy !== false,
          maximumAge: opts.maximumAge != null ? opts.maximumAge : 60000,
          timeout: opts.timeout || 15000,
        }
      );
    });
  }

  /**
   * محاولات متدرجة: AppGeo (دقة عالية→منخفضة) ثم المتصفح مباشرة.
   * يُرجع {lat,lng,...} أو {error:'denied'|...}
   */
  function tryGpsCoords(timeoutMs, forPost) {
    var highTimeout = Math.max(10000, timeoutMs || (forPost ? 22000 : 18000));
    var lowTimeout = Math.min(12000, Math.max(8000, Math.floor(highTimeout * 0.7)));
    var maxAge = forPost ? 0 : 300000;
    var lastErr = null;

    function viaAppGeo(high, to) {
      if (!global.AppGeo || typeof AppGeo.getCurrentPosition !== 'function') {
        return Promise.reject(new Error('no_app_geo'));
      }
      // بيئة HTTP على شبكة محلية — غالباً المتصفح يمنع GPS
      if (typeof AppGeo.isHttpLanBlocked === 'function' && AppGeo.isHttpLanBlocked()) {
        return Promise.reject(Object.assign(new Error('need_https'), { code: 'need_https' }));
      }
      return AppGeo.getCurrentPosition({
        enableHighAccuracy: high,
        maximumAge: maxAge,
        timeout: to,
      });
    }

    function viaBrowser(high, to) {
      if (typeof global.isSecureContext === 'boolean' && !global.isSecureContext) {
        var host = (global.location && location.hostname) || '';
        if (host && host !== 'localhost' && host !== '127.0.0.1' && host !== '[::1]') {
          return Promise.reject(Object.assign(new Error('need_https'), { code: 'need_https' }));
        }
      }
      return tryBrowserGeolocation({
        enableHighAccuracy: high,
        maximumAge: maxAge,
        timeout: to,
      });
    }

    function ensurePermCheck() {
      if (!global.navigator || !navigator.permissions || !navigator.permissions.query) {
        return Promise.resolve();
      }
      return navigator.permissions
        .query({ name: 'geolocation' })
        .then(function (status) {
          if (status && status.state === 'denied') {
            return Promise.reject(Object.assign(new Error('denied'), { code: 1 }));
          }
        })
        .catch(function (err) {
          if (err && (err.code === 1 || String(err.message || '') === 'denied')) {
            return Promise.reject(err);
          }
          // بعض المتصفحات لا تدعم query('geolocation')
        });
    }

    function attempt(fn) {
      return fn().then(function (gps) {
        var n = normalizeReading(gps);
        if (n) return n;
        throw new Error('invalid_reading');
      });
    }

    var chain = [
      function () {
        return ensurePermCheck().then(function () {
          return attempt(function () {
            return viaAppGeo(true, highTimeout);
          });
        });
      },
      function () {
        return attempt(function () {
          return viaAppGeo(false, lowTimeout);
        });
      },
      function () {
        return attempt(function () {
          return viaBrowser(true, highTimeout);
        });
      },
      function () {
        return attempt(function () {
          return viaBrowser(false, lowTimeout);
        });
      },
    ];

    return chain
      .reduce(function (p, step) {
        return p.catch(function (err) {
          lastErr = err || lastErr;
          // لا نضيّع الوقت على محاولات بعد اكتشاف منع HTTPS / رفض الصلاحية
          var classified = classifyGpsError(err);
          if (classified === 'need_https' || classified === 'denied' || classified === 'no_geolocation') {
            return Promise.reject(err);
          }
          return step();
        });
      }, Promise.reject(new Error('start')))
      .catch(function (err) {
        lastErr = err || lastErr;
        return { error: classifyGpsError(lastErr) };
      });
  }

  /**
   * ترتيب البداية:
   * - preferCurrentGps / !forPost: GPS الحالي أولاً
   * - ثم إحداثيات محفوظة في options
   * - ثم آخر موقع / افتراضي
   */
  function resolveStartCoords(options) {
    options = options || {};
    var forPost = !!options.forPost;
    var preferGps = options.preferCurrentGps !== false && !forPost;

    function fromOptions() {
      if (isFinite(options.latitude) && isFinite(options.longitude)) {
        return {
          lat: options.latitude,
          lng: options.longitude,
          fromGps: false,
          isDefault: false,
        };
      }
      return null;
    }

    function fromLastOrDefault() {
      if (forPost) {
        return {
          lat: DEFAULT_LAT,
          lng: DEFAULT_LNG,
          fromGps: false,
          isDefault: true,
        };
      }
      var last = readLastCoords();
      return {
        lat: last.lat,
        lng: last.lng,
        fromGps: false,
        isDefault: !!last.isDefault,
      };
    }

    function packGps(gps) {
      if (!forPost) {
        rememberCoords(gps.lat, gps.lng);
      }
      return {
        lat: gps.lat,
        lng: gps.lng,
        fromGps: true,
        isDefault: false,
        accuracy: gps.accuracy,
        capturedAt: gps.capturedAt,
      };
    }

    if (preferGps) {
      return tryGpsCoords(forPost ? 22000 : 18000, forPost).then(function (gps) {
        if (gps && !gps.error && readingOk(gps)) return packGps(gps);
        return fromOptions() || fromLastOrDefault();
      });
    }

    var opt = fromOptions();
    if (opt) return Promise.resolve(opt);

    return tryGpsCoords(forPost ? 22000 : 16000, forPost).then(function (gps) {
      if (gps && !gps.error && readingOk(gps)) return packGps(gps);
      return fromLastOrDefault();
    });
  }

  function parseCoordQuery(raw) {
    var s = String(raw || '')
      .trim()
      .replace(/[٬،]/g, '.')
      .replace(/\s+/g, ' ');
    if (!s) return null;
    // 31.9539, 35.9106  |  31.9539 35.9106  |  lat=.. lng=..
    var m = s.match(/(-?\d+(?:\.\d+)?)\s*[,;\s]\s*(-?\d+(?:\.\d+)?)/);
    if (!m) return null;
    var lat = parseFloat(m[1]);
    var lng = parseFloat(m[2]);
    if (!isFinite(lat) || !isFinite(lng)) return null;
    if (Math.abs(lat) > 90 || Math.abs(lng) > 180) return null;
    return { lat: lat, lng: lng };
  }

  function searchPlaces(query) {
    var q = String(query || '').trim();
    if (!q) return Promise.resolve([]);
    var coords = parseCoordQuery(q);
    if (coords) {
      return Promise.resolve([
        {
          lat: coords.lat,
          lng: coords.lng,
          label: coords.lat.toFixed(6) + ', ' + coords.lng.toFixed(6),
          fromCoords: true,
        },
      ]);
    }
    var url =
      'https://nominatim.openstreetmap.org/search?format=json&limit=6&addressdetails=0&q=' +
      encodeURIComponent(q);
    return fetch(url, {
      headers: { Accept: 'application/json' },
      credentials: 'omit',
    })
      .then(function (r) {
        if (!r.ok) throw new Error('search_failed');
        return r.json();
      })
      .then(function (rows) {
        if (!Array.isArray(rows)) return [];
        return rows
          .map(function (row) {
            var lat = parseFloat(row.lat);
            var lng = parseFloat(row.lon);
            if (!isFinite(lat) || !isFinite(lng)) return null;
            return {
              lat: lat,
              lng: lng,
              label: String(row.display_name || q).slice(0, 120),
              fromCoords: false,
            };
          })
          .filter(Boolean);
      })
      .catch(function () {
        return [];
      });
  }

  function hintForStart(start, forPost) {
    if (forPost) {
      if (start && start.fromGps) {
        return 'تم جلب موقعك من GPS. تحقق من الدبوس ثم «تأكيد الموقع» — أو انقر على الخريطة لتعديله.';
      }
      return 'لم يُعثر على GPS تلقائياً. اضغط «موقعي الآن» أو انقر على موقعك الصحيح على الخريطة — لا تؤكد قبل تحديد مكانك.';
    }
    if (start && start.fromGps) {
      return 'تم فتح الخريطة على موقعك الحالي (GPS). ابحث بإحداثيات أو اسم مكان، أو انقر على الخريطة، ثم «تأكيد الموقع».';
    }
    if (start && start.isDefault) {
      return 'لم يُعثر على موقعك تلقائياً. اضغط «موقعي الآن»، أو ابحث بإحداثيات GPS / اسم مكان، أو انقر على الخريطة.';
    }
    return 'يُعرض موقع محفوظ. اضغط «موقعي الآن» لتحديث موقعك، أو استخدم البحث للانتقال إلى إحداثيات أخرى.';
  }

  function rememberCoords(lat, lng) {
    try {
      localStorage.setItem('mgr_last_gps_lat', String(lat));
      localStorage.setItem('mgr_last_gps_lng', String(lng));
    } catch (e) {
      /* ignore */
    }
  }

  function alertPickRequired(forPost) {
    var msg = forPost
      ? 'حدّد موقعك أولاً:\n\n• اضغط «موقعي الآن»\n• أو انقر على موقعك على الخريطة\n\nلا تُستخدم إحداثيات قديمة أو افتراضية.'
      : 'حدّد موقعاً على الخريطة أو اضغط «موقعي الآن».';
    if (global.AppDialog && AppDialog.alert) {
      return AppDialog.alert(msg, { type: 'warning', title: 'تحديد الموقع' });
    }
    global.alert(msg);
    return Promise.resolve();
  }

  /**
   * @returns {Promise<{latitude:number, longitude:number, accuracy:number|null, manual:boolean, capturedAt:number}>}
   */
  function pickLocationOnMap(options) {
    options = options || {};
    var forPost = !!options.forPost;

    return ensureLeaflet()
      .then(function () {
        return resolveStartCoords(options);
      })
      .then(function (start) {
        return new Promise(function (resolve, reject) {
          var lat = start.lat;
          var lng = start.lng;
          var settled = false;
          var userAdjusted = false;
          var freshGpsAtStart = !!(start && start.fromGps);
          var startAccuracy = start && start.accuracy != null ? start.accuracy : null;
          var startCapturedAt = start && start.capturedAt != null ? start.capturedAt : null;

          var root = document.createElement('div');
          root.className = 'geo-map-pick-root';
          root.innerHTML =
            '<div class="geo-map-pick-backdrop" data-geo-map-cancel></div>' +
            '<div class="geo-map-pick-panel" role="dialog" aria-modal="true">' +
            '<header class="geo-map-pick-head">' +
            '<h3 class="geo-map-pick-title">' +
            (forPost ? 'موقع ترحيل الفاتورة' : 'تحديد الموقع على الخريطة') +
            '</h3>' +
            '<button type="button" class="geo-map-pick-close" data-geo-map-cancel aria-label="إغلاق">×</button>' +
            '</header>' +
            '<p class="geo-map-pick-hint" id="geo-map-pick-hint"></p>' +
            '<div class="geo-map-pick-search">' +
            '<input type="search" class="geo-map-pick-search-input" id="geo-map-pick-q" ' +
            'placeholder="بحث: اسم مكان أو إحداثيات GPS مثل 31.95, 35.91" autocomplete="off" dir="auto">' +
            '<button type="button" class="geo-map-pick-btn geo-map-pick-btn--search" id="geo-map-pick-search-btn">بحث</button>' +
            '</div>' +
            '<div class="geo-map-pick-search-results" id="geo-map-pick-results" hidden></div>' +
            '<div class="geo-map-pick-actions">' +
            '<button type="button" class="geo-map-pick-btn geo-map-pick-btn--gps" data-geo-map-my-loc>📍 موقعي الآن</button>' +
            '</div>' +
            '<div class="geo-map-pick-map" id="geo-map-pick-map"></div>' +
            '<p class="geo-map-pick-coords" id="geo-map-pick-coords"></p>' +
            '<footer class="geo-map-pick-foot">' +
            '<button type="button" class="geo-map-pick-btn" data-geo-map-cancel>إلغاء</button>' +
            '<button type="button" class="geo-map-pick-btn geo-map-pick-btn--primary" data-geo-map-ok>تأكيد الموقع</button>' +
            '</footer>' +
            '</div>';

          var hintEl = root.querySelector('#geo-map-pick-hint');
          var coordsEl = root.querySelector('#geo-map-pick-coords');
          var mapEl = root.querySelector('#geo-map-pick-map');
          var myLocBtn = root.querySelector('[data-geo-map-my-loc]');
          var okBtn = root.querySelector('[data-geo-map-ok]');
          var qEl = root.querySelector('#geo-map-pick-q');
          var searchBtn = root.querySelector('#geo-map-pick-search-btn');
          var resultsEl = root.querySelector('#geo-map-pick-results');
          var map = null;
          var marker = null;

          if (hintEl) {
            hintEl.textContent = hintForStart(start, forPost);
          }

          function updateCoordsLabel() {
            if (coordsEl) {
              coordsEl.textContent =
                'خط العرض: ' + lat.toFixed(6) + '  |  خط الطول: ' + lng.toFixed(6);
            }
          }

          function cleanup() {
            if (map) {
              map.remove();
              map = null;
            }
            if (root.parentNode) {
              root.parentNode.removeChild(root);
            }
          }

          function finish(err, result) {
            if (settled) {
              return;
            }
            settled = true;
            cleanup();
            if (err) {
              reject(err);
            } else {
              resolve(result);
            }
          }

          function setPoint(newLat, newLng, flyTo, fromGpsRefresh) {
            lat = newLat;
            lng = newLng;
            if (fromGpsRefresh) {
              userAdjusted = true;
              freshGpsAtStart = true;
            }
            if (!marker) {
              marker = global.L.marker([lat, lng]).addTo(map);
            } else {
              marker.setLatLng([lat, lng]);
            }
            if (flyTo && map) {
              map.setView([lat, lng], Math.max(map.getZoom(), 16));
            }
            updateCoordsLabel();
          }

          function canConfirmForPost() {
            if (!forPost) {
              return true;
            }
            if (userAdjusted) {
              return true;
            }
            if (freshGpsAtStart && !start.isDefault) {
              return true;
            }
            return false;
          }

          function refreshFromGps() {
            if (!myLocBtn || myLocBtn.disabled) {
              return;
            }
            myLocBtn.disabled = true;
            myLocBtn.textContent = 'جاري تحديد الموقع…';
            if (hintEl) {
              hintEl.textContent = 'جاري جلب موقعك الحالي من GPS…';
            }
            tryGpsCoords(forPost ? 25000 : 22000, forPost)
              .then(function (gps) {
                if (gps && !gps.error && readingOk(gps)) {
                  startAccuracy = gps.accuracy;
                  startCapturedAt = gps.capturedAt;
                  setPoint(gps.lat, gps.lng, true, true);
                  if (hintEl) {
                    hintEl.textContent = hintForStart({ fromGps: true }, forPost);
                  }
                  if (!forPost) {
                    rememberCoords(gps.lat, gps.lng);
                  }
                  if (global.AppGeo && typeof AppGeo.rememberReading === 'function') {
                    AppGeo.rememberReading({
                      latitude: gps.lat,
                      longitude: gps.lng,
                      accuracy: gps.accuracy,
                      capturedAt: gps.capturedAt,
                    });
                  }
                  return;
                }
                var code = (gps && gps.error) || 'failed';
                if (hintEl) {
                  hintEl.textContent = hintForGpsError(code, forPost);
                }
              })
              .finally(function () {
                if (myLocBtn) {
                  myLocBtn.disabled = false;
                  myLocBtn.textContent = '📍 موقعي الآن';
                }
              });
          }

          root.querySelectorAll('[data-geo-map-cancel]').forEach(function (el) {
            el.addEventListener('click', function () {
              finish(new Error('cancelled'));
            });
          });

          okBtn.addEventListener('click', function () {
            if (!canConfirmForPost()) {
              alertPickRequired(forPost);
              return;
            }
            rememberCoords(lat, lng);
            finish(null, {
              latitude: lat,
              longitude: lng,
              accuracy: startAccuracy,
              manual: true,
              capturedAt: startCapturedAt != null ? startCapturedAt : Date.now(),
            });
          });

          if (myLocBtn) {
            myLocBtn.addEventListener('click', refreshFromGps);
          }

          function hideResults() {
            if (!resultsEl) return;
            resultsEl.hidden = true;
            resultsEl.innerHTML = '';
          }

          function showResults(items) {
            if (!resultsEl) return;
            if (!items || !items.length) {
              resultsEl.hidden = false;
              resultsEl.innerHTML =
                '<p class="geo-map-pick-search-empty">لا نتائج. جرّب إحداثيات مثل 31.9539, 35.9106</p>';
              return;
            }
            resultsEl.hidden = false;
            resultsEl.innerHTML = items
              .map(function (it, i) {
                return (
                  '<button type="button" class="geo-map-pick-search-item" data-idx="' +
                  i +
                  '">' +
                  String(it.label || '')
                    .replace(/&/g, '&amp;')
                    .replace(/</g, '&lt;')
                    .replace(/"/g, '&quot;') +
                  '</button>'
                );
              })
              .join('');
            resultsEl.querySelectorAll('.geo-map-pick-search-item').forEach(function (btn) {
              btn.addEventListener('click', function () {
                var idx = parseInt(btn.getAttribute('data-idx') || '-1', 10);
                var hit = items[idx];
                if (!hit) return;
                userAdjusted = true;
                startAccuracy = null;
                setPoint(hit.lat, hit.lng, true, false);
                hideResults();
                if (hintEl) {
                  hintEl.textContent = hit.fromCoords
                    ? 'تم الانتقال إلى الإحداثيات المدخلة. تأكد من الدبوس ثم «تأكيد الموقع».'
                    : 'تم الانتقال إلى نتيجة البحث. تأكد من الدبوس ثم «تأكيد الموقع».';
                }
              });
            });
          }

          function runSearch() {
            var q = qEl ? qEl.value : '';
            if (!String(q || '').trim()) {
              if (hintEl) hintEl.textContent = 'اكتب اسم مكان أو إحداثيات GPS ثم اضغط بحث.';
              return;
            }
            if (searchBtn) {
              searchBtn.disabled = true;
              searchBtn.textContent = '…';
            }
            searchPlaces(q)
              .then(function (items) {
                if (items.length === 1 && items[0].fromCoords) {
                  userAdjusted = true;
                  startAccuracy = null;
                  setPoint(items[0].lat, items[0].lng, true, false);
                  hideResults();
                  if (hintEl) {
                    hintEl.textContent =
                      'تم الانتقال إلى الإحداثيات المدخلة. تأكد من الدبوس ثم «تأكيد الموقع».';
                  }
                  return;
                }
                showResults(items);
              })
              .finally(function () {
                if (searchBtn) {
                  searchBtn.disabled = false;
                  searchBtn.textContent = 'بحث';
                }
              });
          }

          if (searchBtn) searchBtn.addEventListener('click', runSearch);
          if (qEl) {
            qEl.addEventListener('keydown', function (e) {
              if (e.key === 'Enter') {
                e.preventDefault();
                runSearch();
              }
            });
          }

          document.body.appendChild(root);

          map = global.L.map(mapEl, { zoomControl: true }).setView([lat, lng], forPost ? 15 : 17);
          attachPickBaseLayer(map);

          marker = global.L.marker([lat, lng]).addTo(map);
          updateCoordsLabel();

          map.on('click', function (ev) {
            if (ev && ev.latlng) {
              userAdjusted = true;
              setPoint(ev.latlng.lat, ev.latlng.lng);
              hideResults();
            }
          });

          setTimeout(function () {
            if (map) {
              map.invalidateSize();
            }
          }, 120);

          // عند عدم توفر GPS عند الفتح: حاول مرة أخرى تلقائياً (وليس فقط للترحيل)
          if (!freshGpsAtStart) {
            setTimeout(refreshFromGps, forPost ? 280 : 200);
          }
        });
      })
      .catch(function () {
        return Promise.reject(new Error('map_unavailable'));
      });
  }

  global.AppGeoMapPick = {
    pickLocationOnMap: pickLocationOnMap,
    rememberCoords: rememberCoords,
  };
})(typeof window !== 'undefined' ? window : this);
