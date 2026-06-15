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

  function tryGpsCoords(timeoutMs) {
    if (!global.AppGeo || typeof AppGeo.getCurrentPosition !== 'function') {
      return Promise.resolve(null);
    }
    return AppGeo.getCurrentPosition({
      enableHighAccuracy: true,
      maximumAge: 120000,
      timeout: timeoutMs || 14000,
    })
      .then(function (gps) {
        if (
          gps &&
          isFinite(gps.latitude) &&
          isFinite(gps.longitude) &&
          !(Math.abs(gps.latitude) < 0.000001 && Math.abs(gps.longitude) < 0.000001)
        ) {
          return { lat: gps.latitude, lng: gps.longitude, fromGps: true };
        }
        return null;
      })
      .catch(function () {
        return null;
      });
  }

  /** GPS → آخر موقع محفوظ → الافتراضي */
  function resolveStartCoords(options) {
    if (
      options &&
      isFinite(options.latitude) &&
      isFinite(options.longitude)
    ) {
      return Promise.resolve({
        lat: options.latitude,
        lng: options.longitude,
        fromGps: false,
        isDefault: false,
      });
    }
    return tryGpsCoords(14000).then(function (gps) {
      if (gps) {
        rememberCoords(gps.lat, gps.lng);
        return gps;
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

  function hintForStart(start) {
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

  /**
   * @returns {Promise<{latitude:number, longitude:number, accuracy:null, manual:boolean}>}
   */
  function pickLocationOnMap(options) {
    options = options || {};

    return ensureLeaflet()
      .then(function () {
        return resolveStartCoords(options);
      })
      .then(function (start) {
        return new Promise(function (resolve, reject) {
          var lat = start.lat;
          var lng = start.lng;
          var settled = false;

          var root = document.createElement('div');
          root.className = 'geo-map-pick-root';
          root.innerHTML =
            '<div class="geo-map-pick-backdrop" data-geo-map-cancel></div>' +
            '<div class="geo-map-pick-panel" role="dialog" aria-modal="true">' +
            '<header class="geo-map-pick-head">' +
            '<h3 class="geo-map-pick-title">تحديد الموقع على الخريطة</h3>' +
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
          var map = null;
          var marker = null;

          if (hintEl) {
            hintEl.textContent = hintForStart(start);
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

          function setPoint(newLat, newLng, flyTo) {
            lat = newLat;
            lng = newLng;
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

          function refreshFromGps() {
            if (!myLocBtn || myLocBtn.disabled) {
              return;
            }
            myLocBtn.disabled = true;
            myLocBtn.textContent = 'جاري تحديد الموقع…';
            tryGpsCoords(18000)
              .then(function (gps) {
                if (gps) {
                  rememberCoords(gps.lat, gps.lng);
                  setPoint(gps.lat, gps.lng, true);
                  if (hintEl) {
                    hintEl.textContent = hintForStart({ fromGps: true });
                  }
                } else if (hintEl) {
                  hintEl.textContent =
                    'تعذر جلب GPS. على المحاكي: Android Studio → ⋮ → Location → Send. أو انقر موقعك على الخريطة.';
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

          root.querySelector('[data-geo-map-ok]').addEventListener('click', function () {
            rememberCoords(lat, lng);
            finish(null, {
              latitude: lat,
              longitude: lng,
              accuracy: null,
              manual: true,
            });
          });

          if (myLocBtn) {
            myLocBtn.addEventListener('click', refreshFromGps);
          }

          document.body.appendChild(root);

          map = global.L.map(mapEl, { zoomControl: true }).setView([lat, lng], 16);
          global.L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 19,
            attribution: '&copy; OpenStreetMap',
          }).addTo(map);

          marker = global.L.marker([lat, lng]).addTo(map);
          updateCoordsLabel();

          map.on('click', function (ev) {
            if (ev && ev.latlng) {
              setPoint(ev.latlng.lat, ev.latlng.lng);
            }
          });

          setTimeout(function () {
            if (map) {
              map.invalidateSize();
            }
          }, 120);
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
