(function (global) {
  'use strict';

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

  function initials(name) {
    var s = String(name || '').trim();
    if (!s) return '?';
    var parts = s.split(/\s+/).filter(Boolean);
    if (parts.length === 1) return parts[0].slice(0, 2);
    return (parts[0].charAt(0) + parts[1].charAt(0));
  }

  function esc(s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function Tracker(root) {
    this.root = root;
    this.api = root.getAttribute('data-api') || '';
    this.tileUrl = root.getAttribute('data-tile-url') || 'https://{s}.basemaps.cartocdn.com/rastertiles/voyager/{z}/{x}/{y}{r}.png';
    this.attribution =
      root.getAttribute('data-attribution') ||
      '&copy; <a href="https://www.openstreetmap.org/copyright">OSM</a> &copy; CARTO';
    this.mapProvider = root.getAttribute('data-map-provider') || 'carto';
    this.googleKey = root.getAttribute('data-google-key') || '';
    this.pollSec = parseInt(root.getAttribute('data-poll-sec') || '5', 10) || 5;
    this.onlineSeconds = parseInt(root.getAttribute('data-online-seconds') || '60', 10) || 60;
    this.staleSeconds = parseInt(root.getAttribute('data-stale-seconds') || String(this.onlineSeconds), 10) || this.onlineSeconds;
    if (!root.hasAttribute('data-online-seconds') && root.hasAttribute('data-online-minutes')) {
      this.onlineSeconds = (parseInt(root.getAttribute('data-online-minutes') || '1', 10) || 1) * 60;
    }
    if (!root.hasAttribute('data-stale-seconds') && root.hasAttribute('data-stale-minutes')) {
      this.staleSeconds = (parseInt(root.getAttribute('data-stale-minutes') || '1', 10) || 1) * 60;
    }
    this.onlineMinutes = Math.max(1, Math.ceil(this.onlineSeconds / 60));
    this.staleMinutes = Math.max(1, Math.ceil(this.staleSeconds / 60));
    this.mode = root.getAttribute('data-mode') || 'desktop';
    this.map = null;
    this.layer = null;
    this.markersById = {};
    this.rows = [];
    this.lastHint = '';
    this.lastPings = [];
    this.activeId = null;
    this.timer = null;
    this.loading = false;
    this.fitOnce = true;

    this.els = {
      list: root.querySelector('#ugt-list'),
      search: root.querySelector('#ugt-search'),
      refresh: root.querySelector('#ugt-refresh'),
      includeStale: root.querySelector('#ugt-include-stale'),
      status: root.querySelector('#ugt-status'),
      cntOnline: root.querySelector('#ugt-cnt-online'),
      cntAway: root.querySelector('#ugt-cnt-away'),
      cntTotal: root.querySelector('#ugt-cnt-total'),
      mobileSummary: root.querySelector('#ugt-mobile-summary'),
      sidebar: root.querySelector('#ugt-sidebar'),
      drawerBackdrop: root.querySelector('#ugt-drawer-backdrop'),
      closeList: root.querySelector('#ugt-close-list'),
      toggleList: root.querySelector('#ugt-toggle-list'),
      map: root.querySelector('#ugt-map'),
    };
  }

  Tracker.prototype.init = function () {
    var self = this;
    return ensureLeaflet()
      .then(function () {
        self.buildMap();
        self.bind();
        return self.load();
      })
      .then(function () {
        self.startPoll();
      })
      .catch(function (err) {
        self.setStatus('تعذّر تحميل الخريطة');
        if (self.els.list) {
          self.els.list.innerHTML =
            '<div class="ugt-empty">تعذّر تحميل الخريطة. تحقق من الإنترنت.</div>';
        }
        console.error(err);
      });
  };

  Tracker.prototype.buildMap = function () {
    var mapEl = this.els.map;
    if (!mapEl || this.map) return;

    this.map = global.L.map(mapEl, {
      zoomControl: true,
      attributionControl: true,
    }).setView([31.9539, 35.9106], 8);

    var tileUrl =
      this.tileUrl && this.tileUrl.indexOf('{z}') >= 0
        ? this.tileUrl
        : 'https://{s}.basemaps.cartocdn.com/rastertiles/voyager/{z}/{x}/{y}{r}.png';
    global.L.tileLayer(tileUrl, {
      attribution: this.attribution,
      maxZoom: 20,
      subdomains: 'abcd',
    }).addTo(this.map);

    this.layer = global.L.layerGroup().addTo(this.map);

    // إصلاح حجم الخريطة بعد الظهور في التخطيط (مهم جداً على iPhone)
    var self = this;
    function bumpSize() {
      if (self.map) {
        try {
          self.map.invalidateSize({ animate: false });
        } catch (e) {
          /* ignore */
        }
      }
    }
    bumpSize();
    setTimeout(bumpSize, 120);
    setTimeout(bumpSize, 350);
    setTimeout(bumpSize, 700);
    if (this.mode === 'mobile') {
      global.addEventListener('resize', bumpSize);
      global.addEventListener('orientationchange', function () {
        setTimeout(bumpSize, 250);
      });
      // بعد رسم الصفحة بالكامل (شريط العنوان / الـ dock)
      if (global.requestAnimationFrame) {
        global.requestAnimationFrame(function () {
          global.requestAnimationFrame(bumpSize);
        });
      }
    }
  };

  Tracker.prototype.setDrawerOpen = function (open) {
    if (!this.els.sidebar) return;
    if (open) {
      this.els.sidebar.removeAttribute('hidden');
      if (this.els.drawerBackdrop) {
        this.els.drawerBackdrop.removeAttribute('hidden');
      }
    } else {
      this.els.sidebar.setAttribute('hidden', 'hidden');
      if (this.els.drawerBackdrop) {
        this.els.drawerBackdrop.setAttribute('hidden', 'hidden');
      }
    }
    var self = this;
    setTimeout(function () {
      if (self.map) self.map.invalidateSize();
    }, 80);
  };

  Tracker.prototype.bind = function () {
    var self = this;
    if (this.els.refresh) {
      this.els.refresh.addEventListener('click', function () {
        self.load(true);
      });
    }
    if (this.els.search) {
      var t = null;
      this.els.search.addEventListener('input', function () {
        clearTimeout(t);
        t = setTimeout(function () {
          self.load(true);
        }, 350);
      });
    }
    if (this.els.includeStale) {
      this.els.includeStale.addEventListener('change', function () {
        self.load(true);
      });
    }
    if (this.els.toggleList && this.els.sidebar) {
      this.els.toggleList.addEventListener('click', function () {
        self.setDrawerOpen(self.els.sidebar.hasAttribute('hidden'));
      });
    }
    if (this.els.closeList) {
      this.els.closeList.addEventListener('click', function () {
        self.setDrawerOpen(false);
      });
    }
    if (this.els.drawerBackdrop) {
      this.els.drawerBackdrop.addEventListener('click', function () {
        self.setDrawerOpen(false);
      });
    }
  };

  Tracker.prototype.startPoll = function () {
    var self = this;
    clearInterval(this.timer);
    this.timer = setInterval(function () {
      self.load(false);
    }, Math.max(3, this.pollSec) * 1000);
  };

  Tracker.prototype.setStatus = function (text) {
    if (this.els.status) this.els.status.textContent = text || '';
  };

  Tracker.prototype.queryUrl = function () {
    var q = (this.els.search && this.els.search.value) || '';
    // المتصلون فقط افتراضياً (نبضة خلال online_seconds).
    var includeStale = '0';
    if (this.els.includeStale) {
      includeStale = this.els.includeStale.checked ? '1' : '0';
    }
    var u =
      this.api +
      (this.api.indexOf('?') >= 0 ? '&' : '?') +
      'online_seconds=' +
      encodeURIComponent(this.onlineSeconds) +
      '&stale_seconds=' +
      encodeURIComponent(this.staleSeconds) +
      '&include_stale=' +
      includeStale +
      '&q=' +
      encodeURIComponent(q.trim());
    return u;
  };

  Tracker.prototype.load = function (forceFit) {
    var self = this;
    if (this.loading) return Promise.resolve();
    this.loading = true;
    if (forceFit) this.fitOnce = true;
    this.setStatus('جاري التحديث...');

    return fetch(this.queryUrl(), {
      credentials: 'same-origin',
      headers: { Accept: 'application/json' },
    })
      .then(function (r) {
        return r.json();
      })
      .then(function (data) {
        self.loading = false;
        if (!data || data.ok !== true) {
          self.setStatus((data && data.message) || 'تعذّر التحميل');
          return;
        }
        if (data.map && data.map.tile_url) {
          // لا نغيّر البلاط أثناء التشغيل
        }
        self.rows = Array.isArray(data.markers) ? data.markers : [];
        self.lastHint = (data && data.hint) || '';
        self.lastPings = Array.isArray(data.last_pings) ? data.last_pings : [];
        self.renderStats(data.counts || {});
        self.renderList();
        self.renderMarkers();
        var now = new Date();
        var hh = String(now.getHours()).padStart(2, '0');
        var mm = String(now.getMinutes()).padStart(2, '0');
        self.setStatus(
          'آخر تحديث ' +
            hh +
            ':' +
            mm +
            ':' +
            String(now.getSeconds()).padStart(2, '0') +
            ' — تحديث لحظي كل ' +
            self.pollSec +
            ' ث'
        );
      })
      .catch(function () {
        self.loading = false;
        self.setStatus('تعذّر الاتصال بالسيرفر');
      });
  };

  Tracker.prototype.renderStats = function (counts) {
    if (this.els.cntOnline) this.els.cntOnline.textContent = String(counts.online || 0);
    if (this.els.cntAway) this.els.cntAway.textContent = String(counts.offline || counts.away || 0);
    if (this.els.cntTotal) this.els.cntTotal.textContent = String(counts.total || 0);
    if (this.els.mobileSummary) {
      this.els.mobileSummary.textContent =
        (counts.online || 0) + ' متصل';
    }
  };

  Tracker.prototype.renderList = function () {
    var list = this.els.list;
    if (!list) return;
    if (!this.rows.length) {
      var hint = this.lastHint
        ? '<div class="ugt-hint">' + esc(this.lastHint) + '</div>'
        : '';
      var extra = '';
      if (this.mode !== 'mobile' && this.lastPings && this.lastPings.length) {
        extra = '<div class="ugt-lastpings"><strong>آخر المواقع في قاعدة البيانات:</strong><ul>';
        for (var j = 0; j < Math.min(5, this.lastPings.length); j++) {
          var p = this.lastPings[j];
          extra +=
            '<li>' +
            esc(p.user_label || '') +
            ' — ' +
            esc(p.age_label || '') +
            '</li>';
        }
        extra += '</ul></div>';
      }
      list.innerHTML =
        '<div class="ugt-empty">لا يوجد متصل الآن.<br>فعّل تتبّع الموقع على هاتف المندوب (كل 10 ثوانٍ).</div>' +
        (this.mode === 'mobile' ? '' : hint) +
        extra;
      if (this.mode === 'mobile' && hint) {
        this.setStatus(this.lastHint);
      }
      return;
    }
    var html = '';
    for (var i = 0; i < this.rows.length; i++) {
      var r = this.rows[i];
      var status = r.status || (r.is_online ? 'online' : 'offline');
      var active = this.activeId === r.user_id ? ' is-active' : '';
      html +=
        '<button type="button" class="ugt-item' +
        active +
        '" data-id="' +
        esc(r.user_id) +
        '">' +
        '<div class="ugt-item__avatar ugt-item__avatar--' +
        esc(status) +
        '">' +
        esc(initials(r.user_label)) +
        '</div>' +
        '<div class="ugt-item__body">' +
        '<div class="ugt-item__name">' +
        esc(r.user_label) +
        '</div>' +
        '<div class="ugt-item__meta">' +
        esc(r.status_label || (r.is_online ? 'متصل' : 'غير متصل')) +
        (r.age_label ? ' · ' + esc(r.age_label) : '') +
        (r.source_label ? ' · ' + esc(r.source_label) : '') +
        '</div>' +
        '</div>' +
        '<span class="ugt-item__badge ugt-item__badge--' +
        esc(status) +
        '">' +
        esc(r.status_label || (r.is_online ? 'متصل' : 'غير متصل')) +
        '</span>' +
        '</button>';
    }
    list.innerHTML = html;

    var self = this;
    list.querySelectorAll('.ugt-item').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var id = parseInt(btn.getAttribute('data-id') || '0', 10);
        self.focusUser(id, true);
        if (self.mode === 'mobile' && self.els.sidebar) {
          self.setDrawerOpen(false);
        }
      });
    });
  };

  Tracker.prototype.markerIcon = function (row) {
    var status = row.status || (row.is_online ? 'online' : 'offline');
    var label = initials(row.user_label);
    var html =
      '<div class="ugt-pin ugt-pin--' +
      esc(status) +
      '"><span>' +
      esc(label) +
      '</span></div>';
    return global.L.divIcon({
      className: 'ugt-marker',
      html: html,
      iconSize: [34, 34],
      iconAnchor: [17, 30],
      popupAnchor: [0, -28],
    });
  };

  Tracker.prototype.popupHtml = function (row) {
    return (
      '<div class="ugt-popup">' +
      '<strong>' +
      esc(row.user_label) +
      '</strong>' +
      esc(row.status_label || '') +
      ' · ' +
      esc(row.age_label || '') +
      '<br>' +
      (row.source_label ? esc(row.source_label) + '<br>' : '') +
      (row.accuracy_label ? 'دقة: ' + esc(row.accuracy_label) + '<br>' : '') +
      (row.captured_at_dmy ? esc(row.captured_at_dmy) + '<br>' : '') +
      (row.map_url
        ? '<a href="' + esc(row.map_url) + '" target="_blank" rel="noopener">فتح في Google Maps</a>'
        : '') +
      '</div>'
    );
  };

  Tracker.prototype.renderMarkers = function () {
    if (!this.map || !this.layer) return;
    var keep = {};
    var bounds = [];
    for (var i = 0; i < this.rows.length; i++) {
      var r = this.rows[i];
      var id = r.user_id;
      keep[id] = true;
      var latlng = [r.latitude, r.longitude];
      bounds.push(latlng);
      var existing = this.markersById[id];
      if (existing) {
        existing.setLatLng(latlng);
        existing.setIcon(this.markerIcon(r));
        existing.setPopupContent(this.popupHtml(r));
        existing._ugtRow = r;
      } else {
        var m = global.L.marker(latlng, { icon: this.markerIcon(r) });
        m.bindPopup(this.popupHtml(r));
        m._ugtRow = r;
        m.addTo(this.layer);
        this.markersById[id] = m;
        var self = this;
        (function (uid) {
          m.on('click', function () {
            self.focusUser(uid, false);
          });
        })(id);
      }
    }

    Object.keys(this.markersById).forEach(function (id) {
      if (!keep[id]) {
        this.layer.removeLayer(this.markersById[id]);
        delete this.markersById[id];
      }
    }, this);

    if (this.fitOnce && bounds.length) {
      this.fitOnce = false;
      try {
        this.map.fitBounds(bounds, { padding: [40, 40], maxZoom: 14 });
      } catch (e) {
        /* ignore */
      }
    } else if (this.activeId && this.markersById[this.activeId]) {
      // حافظ على التركيز إن وُجد
    }
  };

  Tracker.prototype.focusUser = function (userId, pan) {
    this.activeId = userId;
    this.renderList();
    var m = this.markersById[userId];
    if (!m) return;
    if (pan !== false) {
      this.map.setView(m.getLatLng(), Math.max(this.map.getZoom(), 14), {
        animate: true,
      });
    }
    m.openPopup();
  };

  function boot() {
    var root = document.getElementById('ugt-root');
    if (!root || root._ugtBooted) return;
    root._ugtBooted = true;
    var t = new Tracker(root);
    t.init();
    global.UserGpsTracker = t;
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})(window);
