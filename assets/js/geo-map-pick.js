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

  function tryGpsCoords(timeoutMs, forPost) {
    if (!global.AppGeo || typeof AppGeo.getCurrentPosition !== 'function') {
      return Promise.resolve(null);
    }
    return AppGeo.getCurrentPosition({
      enableHighAccuracy: true,
      maximumAge: forPost ? 0 : 120000,
      timeout: timeoutMs || (forPost ? 20000 : 14000),
    })
      .then(function (gps) {
        if (
          gps &&
          isFinite(gps.latitude) &&
          isFinite(gps.longitude) &&
          !(Math.abs(gps.latitude) < 0.000001 && Math.abs(gps.longitude) < 0.000001)
        ) {
          return {
            lat: gps.latitude,
            lng: gps.longitude,
            fromGps: true,
            accuracy: gps.accuracy != null ? gps.accuracy : null,
            capturedAt: gps.capturedAt != null ? gps.capturedAt : Date.now(),
          };
        }
        return null;
      })
      .catch(function () {
        return null;
      });
  }

  /** GPS → آخر موقع محفوظ → الافتراضي (أو GPS فقط عند الترحيل) */
  function resolveStartCoords(options) {
    options = options || {};
    var forPost = !!options.forPost;

    if (isFinite(options.latitude) && isFinite(options.longitude)) {
      return Promise.resolve({
        lat: options.latitude,
        lng: options.longitude,
        fromGps: false,
        isDefault: false,
      });
    }

    return tryGpsCoords(forPost ? 22000 : 14000, forPost).then(function (gps) {
      if (gps) {
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
      return 'تم جلب موقعك من GPS. انقر على الخريطة لتعديل الدبوس إن لزم، ثم «تأكيد الموقع».';
    }
    if (start && start.isDefault) {
      return 'لم يُعثر على موقعك تلقائياً — الخريطة تعرض موقعاً افتراضياً. اضغط «موقعي الآن» أو انقر على مكانك الصحيح على الخريطة.';
    }
    return 'يُعرض آخر موقع محفوظ. اضغط «موقعي الآن» للتحديث، أو انقر على الخريطة لوضع الدبوس.';
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
            tryGpsCoords(forPost ? 22000 : 18000, forPost)
              .then(function (gps) {
                if (gps) {
                  startAccuracy = gps.accuracy;
                  startCapturedAt = gps.capturedAt;
                  setPoint(gps.lat, gps.lng, true, true);
                  if (hintEl) {
                    hintEl.textContent = hintForStart({ fromGps: true }, forPost);
                  }
                  if (!forPost) {
                    rememberCoords(gps.lat, gps.lng);
                  }
                } else if (hintEl) {
                  hintEl.textContent = forPost
                    ? 'تعذر جلب GPS. انقر موقعك على الخريطة ثم «تأكيد الموقع».'
                    : 'تعذر جلب GPS. على المحاكي: Android Studio → ⋮ → Location → Send. أو انقر موقعك على الخريطة.';
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

          document.body.appendChild(root);

          map = global.L.map(mapEl, { zoomControl: true }).setView([lat, lng], forPost ? 15 : 16);
          attachPickBaseLayer(map);

          marker = global.L.marker([lat, lng]).addTo(map);
          updateCoordsLabel();

          map.on('click', function (ev) {
            if (ev && ev.latlng) {
              userAdjusted = true;
              setPoint(ev.latlng.lat, ev.latlng.lng);
            }
          });

          setTimeout(function () {
            if (map) {
              map.invalidateSize();
            }
          }, 120);

          if (forPost && !freshGpsAtStart) {
            setTimeout(refreshFromGps, 280);
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
