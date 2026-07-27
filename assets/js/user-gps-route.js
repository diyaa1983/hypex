(function (global) {
  'use strict';

  function ensureLeaflet() {
    if (global.L && global.L.map) return Promise.resolve();
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
      global.__ugrLeafletPromise = loadStyle('https://unpkg.com/leaflet@1.9.4/dist/leaflet.css')
        .then(function () {
          return loadScript('https://unpkg.com/leaflet@1.9.4/dist/leaflet.js');
        })
        .then(function () {
          if (!global.L || !global.L.map) throw new Error('leaflet_load_failed');
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
    this.tileUrl = root.getAttribute('data-tile-url') || 'https://tile.openstreetmap.org/{z}/{x}/{y}.png';
    this.attribution = root.getAttribute('data-attribution') || '&copy; OpenStreetMap';
    this.today = root.getAttribute('data-today') || '';
    this.mode = root.getAttribute('data-mode') || 'desktop';
    this.map = null;
    this.layer = null;
    this.usersLoaded = false;
    this.loading = false;
    this.built = false;

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
    };
    this.bind();
  }

  RouteView.prototype.bind = function () {
    var self = this;
    if (this.els.load) {
      this.els.load.addEventListener('click', function () {
        self.loadTrack();
      });
    }
    if (this.els.user) {
      this.els.user.addEventListener('change', function () {
        self.loadTrack();
      });
    }
    if (this.els.date) {
      this.els.date.addEventListener('change', function () {
        self.loadTrack();
      });
    }
    if (this.els.prev) {
      this.els.prev.addEventListener('click', function () {
        if (self.els.date) {
          self.els.date.value = shiftDate(self.els.date.value || self.today, -1);
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
          self.loadTrack();
        }
      });
    }
  };

  RouteView.prototype.activate = function () {
    var self = this;
    return ensureLeaflet()
      .then(function () {
        self.buildMap();
        if (!self.usersLoaded) return self.loadUsers();
      })
      .catch(function (err) {
        self.setStatus('تعذّر تحميل الخريطة');
        console.error(err);
      });
  };

  RouteView.prototype.buildMap = function () {
    if (this.map || !this.els.map) return;
    this.map = global.L.map(this.els.map, {
      zoomControl: true,
      attributionControl: true,
    }).setView([31.9539, 35.9106], 8);
    global.L.tileLayer(this.tileUrl, { attribution: this.attribution, maxZoom: 19 }).addTo(this.map);
    this.lineLayer = global.L.layerGroup().addTo(this.map);
    this.markerLayer = global.L.layerGroup().addTo(this.map);
    this.layer = this.markerLayer;
    var self = this;
    setTimeout(function () {
      if (self.map) self.map.invalidateSize();
    }, 150);
  };

  RouteView.prototype.invalidate = function () {
    var self = this;
    setTimeout(function () {
      if (self.map) self.map.invalidateSize();
    }, 80);
  };

  RouteView.prototype.setStatus = function (t) {
    if (this.els.status) this.els.status.textContent = t || '';
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
    var uid = this.els.user ? this.els.user.value : '';
    var date = this.els.date ? this.els.date.value : this.today;
    if (!uid) {
      this.renderEmpty('اختر المندوب لعرض مساره.');
      return;
    }
    if (this.loading) return;
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
        self.loading = false;
        if (!data || data.ok !== true) {
          self.setStatus((data && data.message) || 'تعذّر التحميل');
          return;
        }
        self.render(data);
      })
      .catch(function () {
        self.loading = false;
        self.setStatus('تعذّر الاتصال بالسيرفر');
      });
  };

  RouteView.prototype.renderEmpty = function (msg) {
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
          chip('التوقفات', String(summary.stops_count || 0)) +
          chip('النقاط', String(summary.points_count || 0));
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
            self.map.setView([lat, lng], Math.max(self.map.getZoom(), 16), { animate: true });
          }
        });
      });
    }

    this.drawMap(points, segments, stops, trackLines, presence, roadMatched, data.user_label || '');

    var label = data.user_label ? data.user_label + ' · ' : '';
    var mode = !points.length
      ? ' — لا توجد بيانات'
      : trackLines.length
        ? ' — خط السير مرسوم (' + trackLines.length + ' مقطع)'
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
    global.L.polyline(latlngs, {
      color: '#93c5fd',
      weight: 14,
      opacity: 0.5,
      lineJoin: 'round',
      lineCap: 'round',
    }).addTo(target);
    global.L.polyline(latlngs, {
      color: '#1d4ed8',
      weight: 7,
      opacity: 1,
      lineJoin: 'round',
      lineCap: 'round',
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

    // احتياطي: مقاطع GPS
    if (!drewAny) {
      for (i = 0; i < segments.length; i++) {
        var seg = segments[i];
        if (!seg || seg.length < 2) continue;
        var segLl = [];
        for (j = 0; j < seg.length; j++) {
          var pti = points[seg[j]];
          if (!pti) continue;
          segLl.push([pti.latitude, pti.longitude]);
        }
        if (segLl.length < 2) continue;
        this._addPolyline(segLl);
        for (j = 0; j < segLl.length; j++) bounds.push(segLl[j]);
        drewAny = true;
      }
    }

    // احتياطي أخير: كل النقاط
    if (!drewAny && points.length >= 2) {
      var allLl = [];
      for (i = 0; i < points.length; i++) {
        allLl.push([points[i].latitude, points[i].longitude]);
      }
      this._addPolyline(allLl);
      for (j = 0; j < allLl.length; j++) bounds.push(allLl[j]);
    }

    // نقاط GPS الخام
    for (i = 0; i < points.length; i++) {
      var pt = points[i];
      bounds.push([pt.latitude, pt.longitude]);
      var dot = global.L.circleMarker([pt.latitude, pt.longitude], {
        radius: 3,
        color: '#1e40af',
        weight: 1,
        fillColor: '#60a5fa',
        fillOpacity: 0.85,
      });
      dot.bindPopup(
        '<div class="ugr-popup"><b>' +
          esc(pt.time_full || pt.time) +
          '</b>' +
          (userLabel ? '<br>' + esc(userLabel) : '') +
          (pt.accuracy_label ? '<br>دقة: ' + esc(pt.accuracy_label) : '') +
          (pt.source_label ? '<br>' + esc(pt.source_label) : '') +
          '</div>'
      );
      dot.addTo(this.markerLayer);
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
      sm.addTo(this.markerLayer);
    }

    // بداية / نهاية — اختصار داخل دبوس GPS (التفاصيل في النافذة المنبثقة)
    var start = points[0];
    var end = points[points.length - 1];
    global.L.marker([start.latitude, start.longitude], {
      icon: this.pinIcon('start', 'ب'),
    })
      .bindPopup(
        '<div class="ugr-popup"><b>البداية</b>' +
          (userLabel ? '<br>' + esc(userLabel) : '') +
          '<br>' +
          esc(start.time) +
          '</div>'
      )
      .addTo(this.markerLayer);
    global.L.marker([end.latitude, end.longitude], {
      icon: this.pinIcon('end', 'ن'),
    })
      .bindPopup(
        '<div class="ugr-popup"><b>النهاية</b>' +
          (userLabel ? '<br>' + esc(userLabel) : '') +
          '<br>' +
          esc(end.time) +
          '</div>'
      )
      .addTo(this.markerLayer);

    if (bounds.length) {
      try {
        this.map.fitBounds(bounds, { padding: [40, 40], maxZoom: 16 });
      } catch (e) {
        /* ignore */
      }
    }
    var self = this;
    setTimeout(function () {
      if (self.map) self.map.invalidateSize();
    }, 120);
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
        view.activate().then(function () {
          view.invalidate();
        });
      } else if (global.UserGpsTracker && global.UserGpsTracker.map) {
        setTimeout(function () {
          global.UserGpsTracker.map.invalidateSize();
        }, 80);
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
