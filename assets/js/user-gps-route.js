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
    this.layer = global.L.layerGroup().addTo(this.map);
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
    if (this.layer) this.layer.clearLayers();
  };

  RouteView.prototype.render = function (data) {
    var points = Array.isArray(data.points) ? data.points : [];
    var segments = Array.isArray(data.segments) ? data.segments : [];
    var stops = Array.isArray(data.stops) ? data.stops : [];
    var roadPath = Array.isArray(data.road_path) ? data.road_path : [];
    var roadMatched = !!data.road_matched;
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
          chip('النقاط', String(summary.points_count || 0)) +
          chip('المسار', roadMatched ? 'على الشوارع' : 'خط مباشر');
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

    this.drawMap(points, segments, stops, roadPath, roadMatched);

    var label = data.user_label ? data.user_label + ' · ' : '';
    var mode = points.length
      ? roadMatched
        ? ' — مسار على الشوارع'
        : ' — مسار مباشر بين النقاط'
      : ' — لا توجد بيانات';
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

  RouteView.prototype.drawMap = function (points, segments, stops, roadPath, roadMatched) {
    if (!this.map || !this.layer) return;
    this.layer.clearLayers();
    if (!points.length) return;

    var bounds = [];
    var i;
    roadPath = Array.isArray(roadPath) ? roadPath : [];
    roadMatched = !!roadMatched && roadPath.length >= 2;

    // خط السير: على الشوارع إن توفّر، وإلا خط مباشر بين نقاط GPS.
    if (roadMatched) {
      var roadLatLngs = [];
      for (i = 0; i < roadPath.length; i++) {
        var rp = roadPath[i];
        var rll = [rp.latitude, rp.longitude];
        roadLatLngs.push(rll);
        bounds.push(rll);
      }
      global.L.polyline(roadLatLngs, {
        color: '#1d4ed8',
        weight: 5,
        opacity: 0.92,
        lineJoin: 'round',
        lineCap: 'round',
      }).addTo(this.layer);
    } else if (points.length >= 2) {
      var allLatLngs = [];
      for (i = 0; i < points.length; i++) {
        allLatLngs.push([points[i].latitude, points[i].longitude]);
        bounds.push(allLatLngs[i]);
      }
      global.L.polyline(allLatLngs, {
        color: '#1d4ed8',
        weight: 5,
        opacity: 0.9,
        lineJoin: 'round',
        lineCap: 'round',
      }).addTo(this.layer);

      // تمييز الفجوات الزمنية الكبيرة بخط متقطع فوق المسار المباشر فقط.
      for (i = 0; i < segments.length; i++) {
        var seg = segments[i];
        if (!seg || !seg.length) continue;
        var lastIdx = seg[seg.length - 1];
        var nextSeg = segments[i + 1];
        if (!nextSeg || !nextSeg.length) continue;
        var nextIdx = nextSeg[0];
        var a = points[lastIdx];
        var b = points[nextIdx];
        if (!a || !b) continue;
        global.L.polyline(
          [
            [a.latitude, a.longitude],
            [b.latitude, b.longitude],
          ],
          {
            color: '#94a3b8',
            weight: 4,
            opacity: 0.95,
            dashArray: '8 10',
            lineJoin: 'round',
            lineCap: 'round',
          }
        ).addTo(this.layer);
      }
    } else {
      bounds.push([points[0].latitude, points[0].longitude]);
    }

    // نقاط GPS الخام (ليست بالضرورة على الشارع)
    for (i = 0; i < points.length; i++) {
      var pt = points[i];
      if (!roadMatched) {
        bounds.push([pt.latitude, pt.longitude]);
      }
      var dot = global.L.circleMarker([pt.latitude, pt.longitude], {
        radius: 4,
        color: '#1e40af',
        weight: 1,
        fillColor: '#60a5fa',
        fillOpacity: 0.95,
      });
      dot.bindPopup(
        '<div class="ugr-popup"><b>' +
          esc(pt.time_full || pt.time) +
          '</b>' +
          (pt.accuracy_label ? '<br>دقة: ' + esc(pt.accuracy_label) : '') +
          (pt.source_label ? '<br>' + esc(pt.source_label) : '') +
          '</div>'
      );
      dot.addTo(this.layer);
    }

    // علامات التوقف مرقّمة
    for (i = 0; i < stops.length; i++) {
      var s = stops[i];
      var sm = global.L.marker([s.latitude, s.longitude], {
        icon: this.numberIcon(i + 1, 'stop'),
      });
      sm.bindPopup(
        '<div class="ugr-popup"><b>توقف ' +
          (i + 1) +
          '</b><br>' +
          esc(s.arrive) +
          ' — ' +
          esc(s.leave) +
          '<br>المدة: ' +
          esc(s.duration_label) +
          '</div>'
      );
      sm.addTo(this.layer);
    }

    // بداية / نهاية
    var start = points[0];
    var end = points[points.length - 1];
    global.L.marker([start.latitude, start.longitude], { icon: this.pinIcon('start', 'ب') })
      .bindPopup('<div class="ugr-popup"><b>البداية</b><br>' + esc(start.time) + '</div>')
      .addTo(this.layer);
    global.L.marker([end.latitude, end.longitude], { icon: this.pinIcon('end', 'ن') })
      .bindPopup('<div class="ugr-popup"><b>النهاية</b><br>' + esc(end.time) + '</div>')
      .addTo(this.layer);

    if (bounds.length) {
      try {
        this.map.fitBounds(bounds, { padding: [40, 40], maxZoom: 16 });
      } catch (e) {
        /* ignore */
      }
    }
  };

  RouteView.prototype.numberIcon = function (num, kind) {
    return global.L.divIcon({
      className: 'ugr-marker',
      html: '<div class="ugr-pin ugr-pin--' + kind + '"><span>' + num + '</span></div>',
      iconSize: [30, 30],
      iconAnchor: [15, 28],
      popupAnchor: [0, -26],
    });
  };

  RouteView.prototype.pinIcon = function (kind, label) {
    return global.L.divIcon({
      className: 'ugr-marker',
      html: '<div class="ugr-pin ugr-pin--' + kind + '"><span>' + esc(label) + '</span></div>',
      iconSize: [30, 30],
      iconAnchor: [15, 28],
      popupAnchor: [0, -26],
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
