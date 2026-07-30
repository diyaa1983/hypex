(function (global) {
  'use strict';

  function ensureLeaflet() {
    if (global.L && global.L.map) {
      return Promise.resolve();
    }
    if (global.LeafletMapLayers && global.LeafletMapLayers.ensureLeaflet) {
      return global.LeafletMapLayers.ensureLeaflet();
    }
    function loadStyle(href) {
      return new Promise(function (resolve, reject) {
        var l = document.createElement('link');
        l.rel = 'stylesheet';
        l.href = href;
        l.onload = resolve;
        l.onerror = reject;
        document.head.appendChild(l);
      });
    }
    function loadScript(src) {
      return new Promise(function (resolve, reject) {
        var s = document.createElement('script');
        s.src = src;
        s.async = true;
        s.onload = resolve;
        s.onerror = reject;
        document.head.appendChild(s);
      });
    }
    if (!global.__ugrLeafletPromise) {
      global.__ugrLeafletPromise = loadStyle('https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.css')
        .then(function () {
          return loadScript('https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.js');
        })
        .then(function () {
          if (!global.L || !global.L.map) {
            throw new Error('leaflet_load_failed');
          }
        });
    }
    return global.__ugrLeafletPromise;
  }

  function esc(s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function shiftDate(iso, days) {
    var d = new Date(iso + 'T00:00:00');
    if (isNaN(d.getTime())) d = new Date();
    d.setDate(d.getDate() + days);
    var y = d.getFullYear();
    var m = String(d.getMonth() + 1).padStart(2, '0');
    var day = String(d.getDate()).padStart(2, '0');
    return y + '-' + m + '-' + day;
  }

  function RouteView(root) {
    this.root = root;
    this.api = root.getAttribute('data-track-api') || '';
    this.tileUrl = root.getAttribute('data-tile-url') || 'https://{s}.basemaps.cartocdn.com/rastertiles/voyager/{z}/{x}/{y}{r}.png';
    this.attribution =
      root.getAttribute('data-attribution') ||
      '&copy; <a href="https://www.openstreetmap.org/copyright">OSM</a> &copy; CARTO';
    this.mapProvider = root.getAttribute('data-map-provider') || 'esri';
    this.googleKey = root.getAttribute('data-google-key') || '';
    this.today = root.getAttribute('data-today') || '';
    this.mode = root.getAttribute('data-mode') || 'desktop';
    this.map = null;
    this.layer = null;
    this.usersLoaded = false;
    this.loading = false;
    this.built = false;
    this.mapFocus = false;

    this.els = {
      user: root.querySelector('#ugr-user'),
      date: root.querySelector('#ugr-date'),
      prev: root.querySelector('#ugr-prev'),
      next: root.querySelector('#ugr-next'),
      load: root.querySelector('#ugr-load'),
      summary: root.querySelector('#ugr-summary'),
      stops: root.querySelector('#ugr-stops'),
      map: root.querySelector('#ugr-map'),
      status: root.querySelector('#ugr-status'),
      controls: root.querySelector('#ugr-controls') || root.querySelector('.ugr-controls'),
      fabs: root.querySelector('#ugr-map-fabs'),
      fabFilters: root.querySelector('#ugr-fab-filters'),
      fabStops: root.querySelector('#ugr-fab-stops'),
      closeStops: root.querySelector('#ugr-close-stops'),
    };
    this.bind();
  }

  RouteView.prototype.bind = function () {
    var self = this;
    var loadSoon = function () {
      clearTimeout(self._loadTimer);
      self._loadTimer = setTimeout(function () {
        self.loadTrack();
      }, 120);
    };
    if (this.els.load) {
      this.els.load.addEventListener('click', function () {
        clearTimeout(self._loadTimer);
        self.loadTrack();
      });
    }
    if (this.els.user) {
      this.els.user.addEventListener('change', loadSoon);
      this.els.user.addEventListener('input', loadSoon);
    }
    if (this.els.date) {
      this.els.date.addEventListener('change', loadSoon);
      this.els.date.addEventListener('input', loadSoon);
    }
    if (this.els.prev) {
      this.els.prev.addEventListener('click', function () {
        if (self.els.date) {
          self.els.date.value = shiftDate(self.els.date.value || self.today, -1);
          clearTimeout(self._loadTimer);
          self.loadTrack();
        }
      });
    }
    if (this.els.next) {
      this.els.next.addEventListener('click', function () {
        if (self.els.date) {
          var nv = shiftDate(self.els.date.value || self.today, 1);
          if (self.today && nv > self.today) nv = self.today;
          self.els.date.value = nv;
          clearTimeout(self._loadTimer);
          self.loadTrack();
        }
      });
    }
    if (this.els.fabFilters) {
      this.els.fabFilters.addEventListener('click', function () {
        self.setMapFocus(false);
        self.setStopsSheet(false);
      });
    }
    if (this.els.fabStops) {
      this.els.fabStops.addEventListener('click', function () {
        self.setStopsSheet(true);
      });
    }
    if (this.els.closeStops) {
      this.els.closeStops.addEventListener('click', function () {
        self.setStopsSheet(false);
      });
    }
  };

  RouteView.prototype.setMapFocus = function (on) {
    if (this.mode !== 'mobile') return;
    this.mapFocus = !!on;
    this.root.classList.toggle('ugr-mapfocus', this.mapFocus);
    var page = document.getElementById('ugt-root');
    if (page) page.classList.toggle('ugt-route-mapfocus', this.mapFocus);
    if (this.els.fabs) {
      if (this.mapFocus) this.els.fabs.removeAttribute('hidden');
      else this.els.fabs.setAttribute('hidden', 'hidden');
    }
    if (this.els.closeStops) {
      if (this.mapFocus) this.els.closeStops.removeAttribute('hidden');
      else this.els.closeStops.setAttribute('hidden', 'hidden');
    }
    this.invalidate();
  };

  RouteView.prototype.setStopsSheet = function (open) {
    if (this.mode !== 'mobile') return;
    this.root.classList.toggle('ugr-stops-open', !!open);
    this.invalidate();
  };

  RouteView.prototype.activate = function () {
    var self = this;
    return ensureLeaflet()
      .then(function () {
        self.buildMap();
        if (!self.map) {
          throw new Error('map_build_failed');
        }
        self.invalidate();
      })
      .then(function () {
        if (!self.usersLoaded) {
          return self.loadUsers();
        }
      })
      .catch(function (err) {
        self.setStatus('تعذّر تحميل الخريطة — تحقق من الإنترنت أو اختر Leaflet من الإعدادات');
        console.error(err);
      });
  };

  RouteView.prototype.buildMap = function () {
    if (!this.els.map) return;
    if (this.map) {
      this.invalidate();
      return;
    }
    if (!global.L || !global.L.map) return;

    var self = this;
    this.map = global.L.map(this.els.map, {
      zoomControl: true,
      attributionControl: true,
    }).setView([31.9539, 35.9106], 8);

    this._attachBaseLayer()
      .then(function () {
        self.invalidate();
      })
      .catch(function () {
        self._attachCartoLayer();
        self.invalidate();
      });

    this.lineLayer = global.L.layerGroup().addTo(this.map);
    this.markerLayer = global.L.layerGroup().addTo(this.map);
    this.layer = this.markerLayer;
    setTimeout(function () {
      if (self.map) self.map.invalidateSize();
    }, 150);
    if (!this._resizeBound) {
      this._resizeBound = true;
      global.addEventListener('resize', function () {
        self.invalidate();
      });
      if (typeof ResizeObserver !== 'undefined' && this.els.map) {
        try {
          var ro = new ResizeObserver(function () {
            self.invalidate();
          });
          ro.observe(this.els.map);
        } catch (e) {
          /* ignore */
        }
      }
    }
  };

  RouteView.prototype._attachBaseLayer = function () {
    var self = this;
    if (global.LeafletMapLayers && global.LeafletMapLayers.attach) {
      return global.LeafletMapLayers.attach(this.map, {
        tileUrl: this.tileUrl,
        attribution: this.attribution,
        mapProvider: this.mapProvider,
        googleKey: this.googleKey,
      });
    }
    this._attachCartoLayer();
    return Promise.resolve();
  };

  RouteView.prototype._attachCartoLayer = function () {
    var url =
      this.tileUrl && this.tileUrl.indexOf('{z}') >= 0
        ? this.tileUrl
        : 'https://{s}.basemaps.cartocdn.com/rastertiles/voyager/{z}/{x}/{y}{r}.png';
    global.L.tileLayer(url, {
      attribution: this.attribution,
      maxZoom: 20,
      subdomains: 'abcd',
    }).addTo(this.map);
  };

  RouteView.prototype.invalidate = function () {
    var self = this;
    function bump() {
      if (!self.map) return;
      try {
        self.map.invalidateSize({ animate: false });
      } catch (e) {
        /* ignore */
      }
    }
    bump();
    setTimeout(bump, 80);
    setTimeout(bump, 280);
    setTimeout(bump, 600);
  };

  RouteView.prototype.setStatus = function (t) {
    if (this.els.status) this.els.status.textContent = t || '';
  };

  /** تكبير تلقائي على موقع محدد لرؤية المنطقة بوضوح. */
  RouteView.prototype.focusPoint = function (lat, lng, zoom) {
    if (!this.map || isNaN(lat) || isNaN(lng)) return;
    var z = zoom != null ? zoom : 17;
    if (this.map.getZoom() < z) {
      this.map.setView([lat, lng], z, { animate: true });
      return;
    }
    this.map.setView([lat, lng], Math.max(this.map.getZoom(), z), { animate: true });
  };

  RouteView.prototype._bindMarkerFocus = function (marker, lat, lng, zoom) {
    var self = this;
    marker.on('click', function () {
      self.focusPoint(lat, lng, zoom);
      if (typeof marker.openPopup === 'function') {
        marker.openPopup();
      }
    });
  };

  RouteView.prototype.loadUsers = function () {
    var self = this;
    return fetch(this.api, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
      .then(function (r) {
        return r.json();
      })
      .then(function (data) {
        if (!data || data.ok !== true) return;
        self.usersLoaded = true;
        var sel = self.els.user;
        if (!sel) return;
        var users = Array.isArray(data.users) ? data.users : [];
        var html = '<option value="">— اختر المندوب —</option>';
        for (var i = 0; i < users.length; i++) {
          html += '<option value="' + esc(users[i].user_id) + '">' + esc(users[i].user_label) + '</option>';
        }
        sel.innerHTML = html;
      })
      .catch(function () {
        self.setStatus('تعذّر تحميل قائمة المندوبين');
      });
  };

  RouteView.prototype.loadTrack = function () {
    var self = this;
    if (!this.map) {
      return ensureLeaflet()
        .then(function () {
          self.buildMap();
          if (!self.map) {
            self.setStatus('تعذّر تحميل الخريطة');
            return;
          }
          return self.loadTrack();
        })
        .catch(function (err) {
          self.setStatus('تعذّر تحميل الخريطة');
          console.error(err);
        });
    }
    var uid = this.els.user ? this.els.user.value : '';
    var date = this.els.date ? this.els.date.value : this.today;
    if (!uid) {
      this.renderEmpty('اختر المندوب لعرض مساره.');
      return;
    }
    this._loadSeq = (this._loadSeq || 0) + 1;
    var seq = this._loadSeq;
    this.loading = true;
    this.setStatus('جاري التحميل...');

    var url =
      this.api +
      (this.api.indexOf('?') >= 0 ? '&' : '?') +
      'user_id=' +
      encodeURIComponent(uid) +
      '&date=' +
      encodeURIComponent(date || '');

    fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
      .then(function (r) {
        return r.json();
      })
      .then(function (data) {
        if (seq !== self._loadSeq) return;
        self.loading = false;
        if (!data || data.ok !== true) {
          self.setStatus((data && data.message) || 'تعذّر التحميل');
          return;
        }
        // اعرض البيانات فوراً (مهم على iPhone) ثم حاول الالتصاق بالشارع بالخلفية.
        self.render(data);
        if (self.mode === 'mobile' && Array.isArray(data.points) && data.points.length) {
          self.setStopsSheet(false);
          self.setMapFocus(true);
        }
        self.invalidate();
        self.snapInBackground(data, seq);
      })
      .catch(function () {
        if (seq !== self._loadSeq) return;
        self.loading = false;
        self.setStatus('تعذّر الاتصال بالسيرفر');
      });
  };

  /**
   * مطابقة الشارع دون حجب العرض — مع مهلة قصيرة حتى لا يتجمّد الآيفون.
   */
  RouteView.prototype.snapInBackground = function (data, seq) {
    var self = this;
    if (!data || (data.road_matched && self._looksRoadSnapped(data) && self._hasFullDayCoverage(data))) {
      return;
    }
    if (!Array.isArray(data.points) || data.points.length < 2) {
      return;
    }

    var timeoutMs = self.mode === 'mobile' ? 15000 : 45000;
    var deadline = Date.now() + timeoutMs;
    data._snapDeadline = deadline;

    self
      .ensureRoadSnapped(data)
      .then(function (snapped) {
        if (seq !== self._loadSeq) return;
        if (snapped && snapped.road_matched) {
          self.render(snapped);
          self.invalidate();
        }
      })
      .catch(function () {
        /* الإبقاء على المسار المعروض */
      });
  };

  /**
   * لصق خط السير على الشوارع (مثل Google Maps) عبر OSRM.
   * يُنفَّذ دائماً إن لم يؤكد السيرفر الالتصاق — حتى لو PHP بلا OpenSSL.
   */
  RouteView.prototype.ensureRoadSnapped = function (data) {
    var self = this;
    if (!data || !Array.isArray(data.points) || data.points.length < 2) {
      return Promise.resolve(data);
    }
    // إن أكد السيرفر مسارات متعددة تغطي اليوم نكتفي بها.
    if (data.road_matched && self._looksRoadSnapped(data) && self._hasFullDayCoverage(data)) {
      return Promise.resolve(data);
    }

    var points = data.points;
    var segments = Array.isArray(data.segments) ? data.segments : [];
    var jobs = [];
    if (segments.length) {
      for (var s = 0; s < segments.length; s++) {
        var seg = segments[s];
        if (!seg || seg.length < 2) continue;
        var pts = [];
        for (var i = 0; i < seg.length; i++) {
          var p = points[seg[i]];
          if (p) pts.push(p);
        }
        if (pts.length >= 2) {
          // قسّم المقاطع الطويلة حتى لا يُبتَر آخر اليوم عند انتهاء المهلة.
          var parts = self._splitPointsForSnap(pts, 280, 5400);
          for (var pi = 0; pi < parts.length; pi++) {
            if (parts[pi].length >= 2) jobs.push(parts[pi]);
          }
        }
      }
    }
    if (!jobs.length) {
      jobs = self._splitPointsForSnap(points, 280, 5400);
    }

    var lines = [];
    var chain = Promise.resolve();
    jobs.forEach(function (pts) {
      chain = chain.then(function () {
        var gpsFallback = self._pointsToLatLngPath(pts);
        if (data._snapDeadline && Date.now() > data._snapDeadline) {
          if (gpsFallback.length >= 2) lines.push(gpsFallback);
          return;
        }
        return self.osrmSnapPoints(pts).then(function (path) {
          if (path && path.length >= 2 && self._pathLen(path) >= self._pathLen(gpsFallback) * 0.55) {
            lines.push(path);
          } else if (gpsFallback.length >= 2) {
            lines.push(gpsFallback);
          }
        });
      });
    });

    return chain.then(function () {
      if (!lines.length) {
        return data;
      }
      data.track_lines = lines;
      data.road_paths = lines;
      data.road_path = lines[0];
      data.road_matched = true;
      if (data.summary) {
        data.summary.road_matched = true;
        data.summary.travel_segments = lines.length;
      }
      return data;
    });
  };

  RouteView.prototype._pointsToLatLngPath = function (pts) {
    var out = [];
    for (var i = 0; i < pts.length; i++) {
      var lat = parseFloat(pts[i].latitude);
      var lng = parseFloat(pts[i].longitude);
      if (isNaN(lat) || isNaN(lng)) continue;
      out.push({ latitude: lat, longitude: lng });
    }
    return out;
  };

  RouteView.prototype._pathLen = function (path) {
    if (!path || path.length < 2) return 0;
    var total = 0;
    for (var i = 1; i < path.length; i++) {
      var a = path[i - 1];
      var b = path[i];
      var alat = a.latitude != null ? a.latitude : a.lat;
      var alng = a.longitude != null ? a.longitude : a.lng;
      var blat = b.latitude != null ? b.latitude : b.lat;
      var blng = b.longitude != null ? b.longitude : b.lng;
      total += this._haversine(alat, alng, blat, blng);
    }
    return total;
  };

  RouteView.prototype._splitPointsForSnap = function (points, maxPoints, maxSpanSec) {
    maxPoints = maxPoints || 280;
    maxSpanSec = maxSpanSec || 5400;
    var n = points.length;
    if (n < 2) return n ? [points] : [];
    var chunks = [];
    var start = 0;
    while (start < n) {
      var end = start;
      var t0 = parseInt(points[start].ts, 10) || 0;
      if (!t0 && points[start].captured_at) {
        t0 = Math.floor(new Date(points[start].captured_at).getTime() / 1000) || 0;
      }
      while (end + 1 < n) {
        var next = end + 1;
        var t1 = parseInt(points[next].ts, 10) || 0;
        if (!t1 && points[next].captured_at) {
          t1 = Math.floor(new Date(points[next].captured_at).getTime() / 1000) || 0;
        }
        var span = t0 > 0 && t1 > 0 ? t1 - t0 : 0;
        var count = next - start + 1;
        if (count > maxPoints || (span > maxSpanSec && maxSpanSec > 0)) break;
        end = next;
      }
      if (end <= start && start + 1 < n) end = start + 1;
      chunks.push(points.slice(start, end + 1));
      if (end >= n - 1) break;
      start = end;
    }
    return chunks;
  };

  RouteView.prototype._hasFullDayCoverage = function (data) {
    var lines = Array.isArray(data.track_lines) ? data.track_lines : [];
    if (lines.length >= 2) return true;
    var pts = Array.isArray(data.points) ? data.points : [];
    if (pts.length < 2) return true;
    var first = pts[0];
    var last = pts[pts.length - 1];
    var linePts = 0;
    for (var i = 0; i < lines.length; i++) {
      linePts += Array.isArray(lines[i]) ? lines[i].length : 0;
    }
    // مقطع واحد قليل النقاط مقارنةً بيوم كامل ⇒ غير كافٍ.
    return linePts >= Math.max(30, Math.floor(pts.length * 0.08));
  };

  /** مسار ملتصق بالشارع يكون كثيف النقاط مقارنةً بـ GPS الخام. */
  RouteView.prototype._looksRoadSnapped = function (data) {
    var lines = Array.isArray(data.track_lines) ? data.track_lines : [];
    if (!lines.length) return false;
    var n = 0;
    for (var i = 0; i < lines.length; i++) {
      n += Array.isArray(lines[i]) ? lines[i].length : 0;
    }
    var gpsN = Array.isArray(data.points) ? data.points.length : 0;
    // الملتصق عادةً أكثر كثافة من نقاط GPS الخام.
    return n >= Math.max(40, Math.floor(gpsN * 1.5));
  };

  /**
   * طريقة أقرب لـ Google: نظّف النتوءات → match على الشارع → وإلا توجيه بمحطات متباعدة.
   */
  RouteView.prototype.osrmSnapPoints = function (points) {
    var self = this;
    var coords = [];
    var prevLat = null;
    var prevLng = null;
    for (var i = 0; i < points.length; i++) {
      var lat = parseFloat(points[i].latitude);
      var lng = parseFloat(points[i].longitude);
      if (isNaN(lat) || isNaN(lng)) continue;
      if (prevLat !== null) {
        var d = self._haversine(prevLat, prevLng, lat, lng);
        if (d < 25) continue;
      }
      coords.push([lng, lat]);
      prevLat = lat;
      prevLng = lng;
    }
    coords = self._despikeCoords(coords);
    if (coords.length < 2) {
      return Promise.resolve([]);
    }

    // محطات كل ~120م — يقلل التشعّب إلى الشوارع الجانبية
    var waypoints = [coords[0]];
    var last = coords[0];
    for (var j = 1; j < coords.length - 1; j++) {
      if (self._haversine(last[1], last[0], coords[j][1], coords[j][0]) >= 120) {
        waypoints.push(coords[j]);
        last = coords[j];
      }
    }
    waypoints.push(coords[coords.length - 1]);

    return self.osrmMatch(waypoints).then(function (matched) {
      if (matched.length >= 2) return self._removePathSpurs(matched);
      return self.osrmRouteWaypoints(waypoints).then(function (path) {
        if (path.length >= 2) return self._removePathSpurs(path);
        return self.osrmRoutePairs(waypoints).then(function (pairs) {
          return self._removePathSpurs(pairs);
        });
      });
    });
  };

  RouteView.prototype._despikeCoords = function (coords) {
    if (coords.length < 3) return coords;
    var out = [coords[0]];
    for (var i = 1; i < coords.length - 1; i++) {
      var prev = out[out.length - 1];
      var cur = coords[i];
      var next = coords[i + 1];
      var dPrev = this._haversine(prev[1], prev[0], cur[1], cur[0]);
      var dNext = this._haversine(cur[1], cur[0], next[1], next[0]);
      var dDirect = this._haversine(prev[1], prev[0], next[1], next[0]);
      if (dPrev > 25 && dNext > 25 && dDirect < Math.max(35, (dPrev + dNext) * 0.42)) {
        continue;
      }
      if (dDirect > 5 && dPrev + dNext > dDirect * 2.2 && dDirect < 90) {
        continue;
      }
      out.push(cur);
    }
    out.push(coords[coords.length - 1]);
    return out;
  };

  RouteView.prototype._removePathSpurs = function (path) {
    if (!path || path.length < 6) return path || [];
    var keep = [];
    for (var i = 0; i < path.length; i++) keep[i] = true;
    for (var a = 0; a < path.length - 4; a++) {
      if (!keep[a]) continue;
      var maxB = Math.min(path.length - 1, a + 35);
      for (var b = a + 3; b <= maxB; b++) {
        var d = this._haversine(path[a].latitude, path[a].longitude, path[b].latitude, path[b].longitude);
        if (d > 28) continue;
        var pathLen = 0;
        for (var k = a; k < b; k++) {
          pathLen += this._haversine(
            path[k].latitude,
            path[k].longitude,
            path[k + 1].latitude,
            path[k + 1].longitude
          );
        }
        if (pathLen >= 80 && pathLen > d * 3) {
          for (var x = a + 1; x < b; x++) keep[x] = false;
          a = b - 1;
          break;
        }
      }
    }
    var out = [];
    for (var p = 0; p < path.length; p++) {
      if (keep[p]) out.push(path[p]);
    }
    return out.length >= 2 ? out : path;
  };

  /** توجيه زوجاً زوجاً ثم لصق الأشكال على الشارع. */
  RouteView.prototype.osrmRoutePairs = function (lonLatPairs) {
    var self = this;
    if (lonLatPairs.length < 2) return Promise.resolve([]);

    var out = [];
    var chain = Promise.resolve();
    for (var i = 1; i < lonLatPairs.length; i++) {
      (function (a, b) {
        chain = chain.then(function () {
          return self.osrmRouteOnce([a, b]).then(function (part) {
            if (!part.length) {
              out.push({ latitude: a[1], longitude: a[0] });
              out.push({ latitude: b[1], longitude: b[0] });
              return;
            }
            if (out.length) {
              var last = out[out.length - 1];
              var first = part[0];
              if (
                Math.abs(last.latitude - first.latitude) < 0.00002 &&
                Math.abs(last.longitude - first.longitude) < 0.00002
              ) {
                part = part.slice(1);
              }
            }
            out = out.concat(part);
          });
        });
      })(lonLatPairs[i - 1], lonLatPairs[i]);
    }
    return chain.then(function () {
      return out;
    });
  };

  RouteView.prototype._haversine = function (lat1, lng1, lat2, lng2) {
    var R = 6371000;
    var p1 = (lat1 * Math.PI) / 180;
    var p2 = (lat2 * Math.PI) / 180;
    var dp = ((lat2 - lat1) * Math.PI) / 180;
    var dl = ((lng2 - lng1) * Math.PI) / 180;
    var a =
      Math.sin(dp / 2) * Math.sin(dp / 2) +
      Math.cos(p1) * Math.cos(p2) * Math.sin(dl / 2) * Math.sin(dl / 2);
    return R * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
  };

  RouteView.prototype.osrmMatch = function (lonLatPairs) {
    var self = this;
    if (lonLatPairs.length < 2) return Promise.resolve([]);

    // تقسيم لأن الخادم العام يرفض طلبات طويلة جداً.
    var chunks = [];
    var size = 80;
    for (var i = 0; i < lonLatPairs.length; i += size - 1) {
      var slice = lonLatPairs.slice(i, i + size);
      if (slice.length >= 2) chunks.push(slice);
      if (i + size >= lonLatPairs.length) break;
    }

    var chain = Promise.resolve([]);
    chunks.forEach(function (slice) {
      chain = chain.then(function (acc) {
        return self.osrmMatchOnce(slice).then(function (part) {
          if (!part.length) return acc;
          if (acc.length) {
            var last = acc[acc.length - 1];
            var first = part[0];
            if (
              Math.abs(last.latitude - first.latitude) < 0.00002 &&
              Math.abs(last.longitude - first.longitude) < 0.00002
            ) {
              part = part.slice(1);
            }
          }
          return acc.concat(part);
        });
      });
    });
    return chain;
  };

  RouteView.prototype.osrmMatchOnce = function (lonLatPairs) {
    var coordStr = lonLatPairs
      .map(function (c) {
        return c[0].toFixed(6) + ',' + c[1].toFixed(6);
      })
      .join(';');
    var url =
      'https://router.project-osrm.org/match/v1/driving/' +
      coordStr +
      '?overview=full&geometries=geojson&gaps=ignore';
    return fetch(url, { headers: { Accept: 'application/json' } })
      .then(function (r) {
        return r.json();
      })
      .then(function (data) {
        if (!data || data.code !== 'Ok' || !data.matchings || !data.matchings.length) {
          return [];
        }
        var out = [];
        for (var i = 0; i < data.matchings.length; i++) {
          var geom = data.matchings[i].geometry;
          if (!geom || !geom.coordinates) continue;
          for (var k = 0; k < geom.coordinates.length; k++) {
            out.push({
              latitude: geom.coordinates[k][1],
              longitude: geom.coordinates[k][0],
            });
          }
        }
        return out;
      })
      .catch(function () {
        return [];
      });
  };

  RouteView.prototype.osrmRouteWaypoints = function (lonLatPairs) {
    var self = this;
    if (lonLatPairs.length < 2) return Promise.resolve([]);

    var chunks = [];
    var size = 40;
    for (var i = 0; i < lonLatPairs.length; i += size - 1) {
      var slice = lonLatPairs.slice(i, i + size);
      if (slice.length >= 2) chunks.push(slice);
      if (i + size >= lonLatPairs.length) break;
    }

    var chain = Promise.resolve([]);
    chunks.forEach(function (slice) {
      chain = chain.then(function (acc) {
        return self.osrmRouteOnce(slice).then(function (part) {
          if (!part.length) return acc;
          if (acc.length) {
            var last = acc[acc.length - 1];
            var first = part[0];
            if (
              Math.abs(last.latitude - first.latitude) < 0.00002 &&
              Math.abs(last.longitude - first.longitude) < 0.00002
            ) {
              part = part.slice(1);
            }
          }
          return acc.concat(part);
        });
      });
    });
    return chain;
  };

  RouteView.prototype.osrmRouteOnce = function (lonLatPairs) {
    var coordStr = lonLatPairs
      .map(function (c) {
        return c[0].toFixed(6) + ',' + c[1].toFixed(6);
      })
      .join(';');
    var url =
      'https://router.project-osrm.org/route/v1/driving/' +
      coordStr +
      '?overview=full&geometries=geojson&continue_straight=true';
    return fetch(url, { headers: { Accept: 'application/json' } })
      .then(function (r) {
        return r.json();
      })
      .then(function (data) {
        if (!data || data.code !== 'Ok' || !data.routes || !data.routes[0]) {
          return [];
        }
        var coords = data.routes[0].geometry && data.routes[0].geometry.coordinates;
        if (!coords || !coords.length) return [];
        return coords.map(function (c) {
          return { latitude: c[1], longitude: c[0] };
        });
      })
      .catch(function () {
        return [];
      });
  };

  RouteView.prototype.renderEmpty = function (msg) {
    if (this.mode === 'mobile') {
      this.setMapFocus(false);
      this.setStopsSheet(false);
    }
    if (this.els.summary) this.els.summary.innerHTML = '';
    if (this.els.stops) {
      this.els.stops.innerHTML =
        '<div class="ugr-sidebar__head">التوقفات</div><div class="ugr-empty">' + esc(msg) + '</div>';
    }
    if (this.lineLayer) this.lineLayer.clearLayers();
    if (this.markerLayer) this.markerLayer.clearLayers();
    if (this.layer) this.layer.clearLayers();
  };

  RouteView.prototype.render = function (data) {
    var points = Array.isArray(data.points) ? data.points : [];
    var segments = Array.isArray(data.segments) ? data.segments : [];
    var stops = Array.isArray(data.stops) ? data.stops : [];
    var presence = Array.isArray(data.presence) ? data.presence : [];
    var roadPaths = Array.isArray(data.road_paths) ? data.road_paths : [];
    if (!roadPaths.length && Array.isArray(data.road_path) && data.road_path.length >= 2) {
      roadPaths = [data.road_path];
    }
    var trackLines = Array.isArray(data.track_lines) ? data.track_lines : [];
    if (!trackLines.length && roadPaths.length) {
      trackLines = roadPaths;
    }
    var roadMatched = !!data.road_matched && trackLines.length > 0;
    var summary = data.summary || {};

    // الملخّص
    if (this.els.summary) {
      if (!points.length) {
        this.els.summary.innerHTML =
          '<div class="ugr-chip ugr-chip--muted">لا توجد نقاط مسجّلة في هذا اليوم</div>';
      } else {
        this.els.summary.innerHTML =
          chip('المسافة', summary.distance_label || '—') +
          chip('من', summary.first_time || '—') +
          chip('إلى', summary.last_time || '—') +
          chip('المدة', summary.active_label || '—') +
          chip('متوسط السرعة', summary.avg_speed_label || '—') +
          chip('أقصى سرعة', summary.max_speed_label || '—') +
          chip('التوقفات', String(summary.stops_count || 0)) +
          chip('المقاطع', String(summary.travel_segments || 0)) +
          chip('النقاط', String(summary.points_count || 0)) +
          (summary.coverage_note
            ? '<div class="ugr-chip ugr-chip--warn"><span class="ugr-chip__k">ملاحظة</span><span class="ugr-chip__v">' +
              esc(summary.coverage_note) +
              '</span></div>'
            : '');
      }
    }

    // القائمة الجانبية للتوقفات
    if (this.els.stops) {
      var sh = '<div class="ugr-sidebar__head">التوقفات (' + (stops.length || 0) + ')</div>';
      if (!stops.length) {
        sh += '<div class="ugr-empty">لا توجد توقفات مكتشفة.</div>';
      } else {
        for (var s = 0; s < stops.length; s++) {
          var st = stops[s];
          sh +=
            '<button type="button" class="ugr-stop" data-lat="' +
            esc(st.latitude) +
            '" data-lng="' +
            esc(st.longitude) +
            '">' +
            '<span class="ugr-stop__num">' +
            (s + 1) +
            '</span>' +
            '<span class="ugr-stop__body">' +
            '<span class="ugr-stop__time">' +
            esc(st.arrive) +
            ' — ' +
            esc(st.leave) +
            '</span>' +
            '<span class="ugr-stop__dur">توقّف ' +
            esc(st.duration_label) +
            '</span>' +
            '</span>' +
            '</button>';
        }
      }
      if (presence.length) {
        sh += '<div class="ugr-sidebar__head" style="margin-top:10px">أماكن تواجد بدون حركة (' + presence.length + ')</div>';
        for (var p = 0; p < presence.length; p++) {
          var pr = presence[p];
          sh +=
            '<button type="button" class="ugr-stop ugr-stop--presence" data-lat="' +
            esc(pr.latitude) +
            '" data-lng="' +
            esc(pr.longitude) +
            '">' +
            '<span class="ugr-stop__num">•</span>' +
            '<span class="ugr-stop__body">' +
            '<span class="ugr-stop__time">' +
            esc(pr.label || pr.time || '') +
            '</span>' +
            '<span class="ugr-stop__dur">نقطة تواجد — بلا خط سير</span>' +
            '</span>' +
            '</button>';
        }
      }
      this.els.stops.innerHTML = sh;
      var self = this;
      this.els.stops.querySelectorAll('.ugr-stop').forEach(function (btn) {
        btn.addEventListener('click', function () {
          var lat = parseFloat(btn.getAttribute('data-lat'));
          var lng = parseFloat(btn.getAttribute('data-lng'));
          if (self.map && !isNaN(lat) && !isNaN(lng)) {
            self.focusPoint(lat, lng, 17);
          }
        });
      });
    }

    this.drawMap(points, segments, stops, trackLines, presence, roadMatched, data.user_label || '');

    var label = data.user_label ? data.user_label + ' · ' : '';
    var mode = !points.length
      ? ' — لا توجد بيانات'
      : roadMatched
        ? ' — خط السير ملتصق بالشارع (' + trackLines.length + ' مقطع)'
        : trackLines.length
          ? ' — خط السير من GPS (' + trackLines.length + ' مقطع)'
          : ' — نقاط تواجد بدون حركة كافية لرسم خط';
    this.setStatus(label + (data.date_dmy || '') + mode);

    function chip(label, val) {
      return (
        '<div class="ugr-chip"><span class="ugr-chip__k">' +
        esc(label) +
        '</span><span class="ugr-chip__v">' +
        esc(val) +
        '</span></div>'
      );
    }
  };

  RouteView.prototype._speedColor = function (kmh) {
    var s = parseFloat(kmh);
    if (isNaN(s) || s < 3) return '#94a3b8';
    if (s < 40) return '#16a34a';
    if (s < 80) return '#f59e0b';
    return '#dc2626';
  };

  RouteView.prototype._addSpeedSegments = function (points, segments) {
    if (!this.lineLayer || !Array.isArray(segments) || !segments.length || !points.length) return;
    var self = this;
    for (var si = 0; si < segments.length; si++) {
      var seg = segments[si];
      if (!seg || seg.length < 2) continue;
      for (var j = 1; j < seg.length; j++) {
        var prev = points[seg[j - 1]];
        var cur = points[seg[j]];
        if (!prev || !cur) continue;
        global.L.polyline(
          [
            [prev.latitude, prev.longitude],
            [cur.latitude, cur.longitude],
          ],
          {
            color: self._speedColor(cur.speed_kmh),
            weight: 5,
            opacity: 0.92,
            lineJoin: 'round',
            lineCap: 'round',
          }
        ).addTo(self.lineLayer);
      }
    }
  };

  RouteView.prototype._pathLatLngs = function (path) {
    var latlngs = [];
    if (!path || !path.length) return latlngs;
    for (var j = 0; j < path.length; j++) {
      var pt = path[j];
      var lat = pt.latitude != null ? pt.latitude : pt.lat;
      var lng = pt.longitude != null ? pt.longitude : pt.lng;
      if (lat == null || lng == null) continue;
      lat = parseFloat(lat);
      lng = parseFloat(lng);
      if (isNaN(lat) || isNaN(lng)) continue;
      latlngs.push([lat, lng]);
    }
    return latlngs;
  };

  RouteView.prototype._addPolyline = function (latlngs, layer) {
    if (!latlngs || latlngs.length < 2) return;
    var target = layer || this.lineLayer;
    if (!target) return;
    // خط واحد فقط — الظل المزدوج كان يبدو كخطّين متوازيين بالخطأ.
    global.L.polyline(latlngs, {
      color: '#1d4ed8',
      weight: 5,
      opacity: 0.92,
      lineJoin: 'round',
      lineCap: 'round',
      smoothFactor: 1.4,
    }).addTo(target);
  };

  RouteView.prototype.drawMap = function (points, segments, stops, trackLines, presence, roadMatched, userLabel) {
    if (!this.map || !this.lineLayer || !this.markerLayer) return;
    this.lineLayer.clearLayers();
    this.markerLayer.clearLayers();
    if (!points.length) return;

    var bounds = [];
    var i, j;
    trackLines = Array.isArray(trackLines) ? trackLines : [];
    presence = Array.isArray(presence) ? presence : [];
    segments = Array.isArray(segments) ? segments : [];
    userLabel = String(userLabel || '').trim();

    // خط السير — مصدر واحد (track_lines من السيرفر)
    var drewAny = false;
    for (i = 0; i < trackLines.length; i++) {
      var latlngs = this._pathLatLngs(trackLines[i]);
      if (latlngs.length < 2) continue;
      this._addPolyline(latlngs);
      for (j = 0; j < latlngs.length; j++) bounds.push(latlngs[j]);
      drewAny = true;
    }

    // احتياطي: مقاطع GPS ملوّنة حسب السرعة
    if (!drewAny) {
      this._addSpeedSegments(points, segments);
      for (i = 0; i < segments.length; i++) {
        var seg = segments[i];
        if (!seg || seg.length < 2) continue;
        for (j = 0; j < seg.length; j++) {
          var pti = points[seg[j]];
          if (!pti) continue;
          bounds.push([pti.latitude, pti.longitude]);
        }
      }
      drewAny = segments.length > 0;
    }

    // احتياطي أخير: كل النقاط
    if (!drewAny && points.length >= 2) {
      this._addSpeedSegments(
        points,
        [Array.from({ length: points.length }, function (_, idx) {
          return idx;
        })]
      );
      for (i = 0; i < points.length; i++) {
        bounds.push([points[i].latitude, points[i].longitude]);
      }
      drewAny = true;
    }

    // نقاط GPS الخام — عيّنة خفيفة، وتخطّي التذبذب داخل التوقفات.
    var stopZones = [];
    for (i = 0; i < stops.length; i++) {
      stopZones.push({
        lat: parseFloat(stops[i].latitude),
        lng: parseFloat(stops[i].longitude),
        r: 55,
      });
    }
    var step = points.length > 120 ? 8 : points.length > 60 ? 5 : points.length > 30 ? 3 : 2;
    var lastDot = null;
    for (i = 0; i < points.length; i += step) {
      if (i === 0 || i === points.length - 1) continue;
      var pt = points[i];
      var plat = parseFloat(pt.latitude);
      var plng = parseFloat(pt.longitude);
      // لا ترسم نقاط داخل دائرة التوقف — يكفي رقم التوقف.
      var inStop = false;
      for (j = 0; j < stopZones.length; j++) {
        if (this._haversine(stopZones[j].lat, stopZones[j].lng, plat, plng) <= stopZones[j].r) {
          inStop = true;
          break;
        }
      }
      if (inStop) continue;
      // لا ترسم نقاط متقاربة جداً (اهتزاز GPS).
      if (lastDot && this._haversine(lastDot[0], lastDot[1], plat, plng) < 35) continue;
      // متوقف تقريباً حسب السرعة المحسوبة.
      if (pt.speed_kmh != null && parseFloat(pt.speed_kmh) < 3) continue;

      lastDot = [plat, plng];
      bounds.push(lastDot);
      var dot = global.L.circleMarker(lastDot, {
        radius: 2.5,
        color: '#1e40af',
        weight: 1,
        fillColor: '#93c5fd',
        fillOpacity: 0.7,
      });
      dot.bindPopup(
        '<div class="ugr-popup"><b>' +
          esc(pt.time_full || pt.time) +
          '</b>' +
          (userLabel ? '<br>' + esc(userLabel) : '') +
          (pt.speed_label ? '<br>السرعة: ' + esc(pt.speed_label) : '') +
          (pt.accuracy_label ? '<br>دقة: ' + esc(pt.accuracy_label) : '') +
          (pt.source_label ? '<br>' + esc(pt.source_label) : '') +
          '</div>'
      );
      this._bindMarkerFocus(dot, plat, plng, 17);
      dot.addTo(this.markerLayer);
    }
    // تأكد أن الحدود تشمل كل النقاط حتى لو لم تُرسم كلها.
    for (i = 0; i < points.length; i++) {
      bounds.push([points[i].latitude, points[i].longitude]);
    }

    // علامات التواجد
    for (i = 0; i < presence.length; i++) {
      var pr = presence[i];
      var pm = global.L.marker([pr.latitude, pr.longitude], {
        icon: this.pinIcon('presence', '•'),
      });
      pm.bindPopup(
        '<div class="ugr-popup"><b>توقف / تواجد</b>' +
          (userLabel ? '<br>' + esc(userLabel) : '') +
          '<br>' +
          esc(pr.label || pr.time || '') +
          '</div>'
      );
      this._bindMarkerFocus(pm, pr.latitude, pr.longitude, 17);
      pm.addTo(this.markerLayer);
      bounds.push([pr.latitude, pr.longitude]);
    }

    // علامات التوقف — رقم مختصر داخل دبوس GPS
    for (i = 0; i < stops.length; i++) {
      var s = stops[i];
      var sm = global.L.marker([s.latitude, s.longitude], {
        icon: this.pinIcon('stop', String(i + 1)),
      });
      sm.bindPopup(
        '<div class="ugr-popup"><b>توقف ' +
          (i + 1) +
          '</b>' +
          (userLabel ? '<br>' + esc(userLabel) : '') +
          '<br>' +
          esc(s.arrive) +
          ' — ' +
          esc(s.leave) +
          '<br>المدة: ' +
          esc(s.duration_label) +
          '</div>'
      );
      this._bindMarkerFocus(sm, s.latitude, s.longitude, 17);
      sm.addTo(this.markerLayer);
    }

    // بداية / نهاية — اختصار داخل دبوس GPS (التفاصيل في النافذة المنبثقة)
    var start = points[0];
    var end = points[points.length - 1];
    var startM = global.L.marker([start.latitude, start.longitude], {
      icon: this.pinIcon('start', 'ب'),
    });
    startM
      .bindPopup(
        '<div class="ugr-popup"><b>البداية</b>' +
          (userLabel ? '<br>' + esc(userLabel) : '') +
          '<br>' +
          esc(start.time) +
          '</div>'
      )
      .addTo(this.markerLayer);
    this._bindMarkerFocus(startM, start.latitude, start.longitude, 17);

    var endM = global.L.marker([end.latitude, end.longitude], {
      icon: this.pinIcon('end', 'ن'),
    });
    endM
      .bindPopup(
        '<div class="ugr-popup"><b>النهاية</b>' +
          (userLabel ? '<br>' + esc(userLabel) : '') +
          '<br>' +
          esc(end.time) +
          (end.speed_label && end.speed_label !== '—'
            ? '<br>السرعة: ' + esc(end.speed_label)
            : '') +
          '</div>'
      )
      .addTo(this.markerLayer);
    this._bindMarkerFocus(endM, end.latitude, end.longitude, 17);

    if (bounds.length) {
      try {
        this.map.fitBounds(bounds, { padding: [36, 36], maxZoom: 16 });
      } catch (e) {
        /* ignore */
      }
    }
    this.invalidate();
    var self = this;
    setTimeout(function () {
      self.invalidate();
    }, 120);
    setTimeout(function () {
      self.invalidate();
    }, 400);
  };

  RouteView.prototype.pinIcon = function (kind, abbrev) {
    return global.L.divIcon({
      className: 'ugr-marker',
      html:
        '<div class="ugr-pin ugr-pin--' +
        esc(kind) +
        '"><span>' +
        esc(String(abbrev)) +
        '</span></div>',
      iconSize: [28, 28],
      iconAnchor: [14, 26],
      popupAnchor: [0, -24],
    });
  };

  function boot() {
    var root = document.getElementById('ugr-root');
    if (!root || root._ugrBooted) return;
    root._ugrBooted = true;
    var view = new RouteView(root);
    global.UserGpsRoute = view;

    var liveBtn = document.getElementById('ugt-mode-live');
    var routeBtn = document.getElementById('ugt-mode-route');
    var liveView = document.getElementById('ugt-live-view');
    var routeViewEl = document.getElementById('ugt-route-view');

    function setMode(mode) {
      var isRoute = mode === 'route';
      if (liveView) liveView.hidden = isRoute;
      if (routeViewEl) routeViewEl.hidden = !isRoute;
      if (liveBtn) liveBtn.classList.toggle('is-active', !isRoute);
      if (routeBtn) routeBtn.classList.toggle('is-active', isRoute);
      // أوقف/استأنف التحديث الحي لتوفير الموارد
      if (global.UserGpsTracker) {
        try {
          if (isRoute && global.UserGpsTracker.timer) {
            clearInterval(global.UserGpsTracker.timer);
            global.UserGpsTracker.timer = null;
          } else if (!isRoute && !global.UserGpsTracker.timer) {
            global.UserGpsTracker.startPoll();
          }
        } catch (e) {
          /* ignore */
        }
      }
      if (isRoute) {
        // أولاً أظهر الحاوية ثم ابنِ الخريطة — ضروري لـ Leaflet على iPhone
        view.activate().then(function () {
          view.invalidate();
          setTimeout(function () {
            view.invalidate();
          }, 200);
          if (view.map && view.els.user && view.els.user.value) {
            view.loadTrack();
          } else if (!view.map) {
            view.setStatus('تعذّر تحميل الخريطة — جرّب Leaflet من إعدادات التتبّع');
          }
        });
      } else {
        if (view.setMapFocus) view.setMapFocus(false);
        if (view.setStopsSheet) view.setStopsSheet(false);
        if (global.UserGpsTracker) {
          setTimeout(function () {
            try {
              if (global.UserGpsTracker.usesArcgis && global.UserGpsTracker.usesArcgis()) {
                if (global.MapInterop && global.MapInterop.invalidateSize) {
                  global.MapInterop.invalidateSize();
                }
              } else if (global.UserGpsTracker.map && global.UserGpsTracker.map.invalidateSize) {
                global.UserGpsTracker.map.invalidateSize({ animate: false });
              }
            } catch (e2) {
              /* ignore */
            }
          }, 80);
          setTimeout(function () {
            try {
              if (global.UserGpsTracker.usesArcgis && global.UserGpsTracker.usesArcgis()) {
                if (global.MapInterop && global.MapInterop.invalidateSize) {
                  global.MapInterop.invalidateSize();
                }
              } else if (global.UserGpsTracker.map && global.UserGpsTracker.map.invalidateSize) {
                global.UserGpsTracker.map.invalidateSize({ animate: false });
              }
            } catch (e3) {
              /* ignore */
            }
          }, 350);
        }
      }
    }

    if (routeBtn) {
      routeBtn.addEventListener('click', function () {
        setMode('route');
      });
    }
    if (liveBtn) {
      liveBtn.addEventListener('click', function () {
        setMode('live');
      });
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})(window);
