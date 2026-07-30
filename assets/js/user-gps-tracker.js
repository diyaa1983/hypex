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
    if (global.LeafletMapLayers && global.LeafletMapLayers.ensureLeaflet) {
      return global.LeafletMapLayers.ensureLeaflet();
    }
    if (leafletPromise) {
      return leafletPromise;
    }
    leafletPromise = loadStyle('https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.css')
      .then(function () {
        return loadScript('https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.js');
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
    this.mapProvider = root.getAttribute('data-map-provider') || 'esri';
    this.mapEngine = (root.getAttribute('data-map-engine') || 'leaflet').toLowerCase();
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
    this.trailLayer = null;
    this.markersById = {};
    /** @type {Object.<string, Array.<[number, number]>>} */
    this.trailsById = {};
    /** @type {Object.<string, *>} */
    this.trailLinesById = {};
    /** @type {Object.<string, {from:[number,number], to:[number,number], start:number, duration:number, follow:boolean}>} */
    this.moveAnims = {};
    this._animRaf = null;
    this.maxTrailPoints = 400;
    this.minTrailMoveMeters = 5;
    this.maxTrailJumpMeters = 800;
    this.smoothMoveMs = Math.max(900, (this.pollSec * 1000) * 0.92);
    this.livePulseTimers = {};
    this.rows = [];
    this.lastHint = '';
    this.lastPings = [];
    this.activeId = null;
    this.timer = null;
    this.loading = false;
    this.fitOnce = true;
    this.arcgisFitOnce = true;

    this.els = {
      list: root.querySelector('#ugt-list'),
      search: root.querySelector('#ugt-search'),
      refresh: root.querySelector('#ugt-refresh'),
      includeStale: root.querySelector('#ugt-include-stale'),
      clearTrails: root.querySelector('#ugt-clear-trails'),
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

  Tracker.prototype.usesArcgis = function () {
    return this.mapEngine === 'arcgis' && global.MapInterop;
  };

  Tracker.prototype.bumpMapLayout = function () {
    var self = this;
    function bump() {
      var mapEl = self.els.map;
      var wrap = mapEl && mapEl.parentElement;
      if (wrap && mapEl) {
        var rect = wrap.getBoundingClientRect();
        if (rect.height > 80) {
          mapEl.style.height = Math.floor(rect.height) + 'px';
          mapEl.style.minHeight = Math.floor(rect.height) + 'px';
        }
      }
      if (self.usesArcgis()) {
        if (global.MapInterop && global.MapInterop.invalidateSize) {
          global.MapInterop.invalidateSize();
        }
      } else if (self.map && self.map.invalidateSize) {
        try {
          self.map.invalidateSize({ animate: false });
        } catch (e) {
          /* ignore */
        }
      }
    }
    bump();
    [60, 180, 400, 800, 1400].forEach(function (ms) {
      setTimeout(bump, ms);
    });
  };

  Tracker.prototype.init = function () {
    var self = this;
    var mapReady = this.usesArcgis()
      ? (global.MapInterop.loadApi ? global.MapInterop.loadApi() : Promise.resolve()).then(function () {
          return self.buildArcgisMap();
        })
      : ensureLeaflet().then(function () {
          return self.buildMap();
        });

    return mapReady
      .then(function () {
        self.bind();
        self.bumpMapLayout();
        return self.load();
      })
      .then(function () {
        self.startPoll();
        self.bumpMapLayout();
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

  Tracker.prototype.buildArcgisMap = function () {
    var mapEl = this.els.map;
    if (!mapEl || this.map) {
      return Promise.resolve();
    }

    var self = this;
    this.map = { engine: 'arcgis' };

    return global.MapInterop.initialize(mapEl, 31.9539, 35.9106, {
      mapProvider: self.mapProvider,
      basemapUrl:
        (global.AppOsmConfig && global.AppOsmConfig.arcgisUrl) ||
        (self.mapProvider === 'natgeo'
          ? 'https://services.arcgisonline.com/ArcGIS/rest/services/NatGeo_World_Map/MapServer'
          : 'https://services.arcgisonline.com/ArcGIS/rest/services/World_Street_Map/MapServer'),
    }).then(function () {
      function bumpSize() {
        if (global.MapInterop && global.MapInterop.invalidateSize) {
          global.MapInterop.invalidateSize();
        }
      }
      bumpSize();
      setTimeout(bumpSize, 120);
      setTimeout(bumpSize, 350);
      setTimeout(bumpSize, 700);
      global.addEventListener('resize', bumpSize);
      if (typeof ResizeObserver !== 'undefined' && mapEl) {
        try {
          var ro = new ResizeObserver(function () {
            self.bumpMapLayout();
          });
          ro.observe(mapEl.parentElement || mapEl);
        } catch (e2) {
          /* ignore */
        }
      }
      self.bumpMapLayout();
      if (self.mode === 'mobile') {
        global.addEventListener('orientationchange', function () {
          setTimeout(bumpSize, 250);
        });
      }
    });
  };

  Tracker.prototype.buildMap = function () {
    var mapEl = this.els.map;
    if (!mapEl || this.map) return Promise.resolve();

    var self = this;
    this.map = global.L.map(mapEl, {
      zoomControl: true,
      attributionControl: true,
    }).setView([31.9539, 35.9106], 8);

    this.trailLayer = global.L.layerGroup().addTo(this.map);
    this.layer = global.L.layerGroup().addTo(this.map);

    var attach = global.LeafletMapLayers && global.LeafletMapLayers.attach
      ? global.LeafletMapLayers.attach(this.map, {
          tileUrl: this.tileUrl,
          attribution: this.attribution,
          mapProvider: this.mapProvider,
          googleKey: this.googleKey,
        })
      : Promise.resolve();

    return attach.then(function () {
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
      global.addEventListener('resize', bumpSize);
      if (typeof ResizeObserver !== 'undefined' && mapEl) {
        try {
          var ro = new ResizeObserver(function () {
            bumpSize();
            self.bumpMapLayout();
          });
          ro.observe(mapEl.parentElement || mapEl);
        } catch (e1) {
          /* ignore */
        }
      }
      self.bumpMapLayout();
      if (self.mode === 'mobile') {
        global.addEventListener('orientationchange', function () {
          setTimeout(bumpSize, 250);
        });
        if (global.requestAnimationFrame) {
          global.requestAnimationFrame(function () {
            global.requestAnimationFrame(bumpSize);
          });
        }
      }
    });
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
      if (self.usesArcgis()) {
        global.MapInterop.invalidateSize();
      } else if (self.map) {
        self.map.invalidateSize();
      }
    }, 80);
  };

  Tracker.prototype.bind = function () {
    var self = this;
    if (this.els.refresh) {
      this.els.refresh.addEventListener('click', function () {
        self.load(true);
      });
    }
    if (this.els.clearTrails) {
      this.els.clearTrails.addEventListener('click', function () {
        self.clearLiveTrails();
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
    }, Math.max(2, this.pollSec) * 1000);
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
    if (forceFit) {
      this.fitOnce = true;
      this.arcgisFitOnce = true;
    }
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
        self.bumpMapLayout();
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

  Tracker.prototype.markerIcon = function (row, bearing) {
    var status = row.status || (row.is_online ? 'online' : 'offline');
    var label = initials(row.user_label);
    var heading = bearing != null && isFinite(bearing) ? Math.round(bearing) : 0;
    var html =
      '<div class="ugt-mover" style="--ugt-heading:' +
      heading +
      'deg">' +
      '<span class="ugt-mover__pulse" aria-hidden="true"></span>' +
      '<div class="ugt-mover__arrow" aria-hidden="true"></div>' +
      '<div class="ugt-pin ugt-pin--' +
      esc(status) +
      '"><span>' +
      esc(label) +
      '</span></div>' +
      '</div>';
    return global.L.divIcon({
      className: 'ugt-marker',
      html: html,
      iconSize: [40, 48],
      iconAnchor: [20, 42],
      popupAnchor: [0, -36],
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

  Tracker.prototype._trailKey = function (userId) {
    return String(userId);
  };

  Tracker.prototype._haversineMeters = function (a, b) {
    if (!a || !b) return 0;
    var lat1 = a[0];
    var lng1 = a[1];
    var lat2 = b[0];
    var lng2 = b[1];
    var toRad = Math.PI / 180;
    var dLat = (lat2 - lat1) * toRad;
    var dLng = (lng2 - lng1) * toRad;
    var x =
      Math.sin(dLat / 2) * Math.sin(dLat / 2) +
      Math.cos(lat1 * toRad) * Math.cos(lat2 * toRad) * Math.sin(dLng / 2) * Math.sin(dLng / 2);
    return 6371000 * 2 * Math.atan2(Math.sqrt(x), Math.sqrt(1 - x));
  };

  Tracker.prototype._bearingDeg = function (from, to) {
    if (!from || !to) return 0;
    var toRad = Math.PI / 180;
    var lat1 = from[0] * toRad;
    var lat2 = to[0] * toRad;
    var dLng = (to[1] - from[1]) * toRad;
    var y = Math.sin(dLng) * Math.cos(lat2);
    var x =
      Math.cos(lat1) * Math.sin(lat2) -
      Math.sin(lat1) * Math.cos(lat2) * Math.cos(dLng);
    return ((Math.atan2(y, x) * 180) / Math.PI + 360) % 360;
  };

  Tracker.prototype._easeInOut = function (t) {
    return t < 0.5 ? 2 * t * t : 1 - Math.pow(-2 * t + 2, 2) / 2;
  };

  Tracker.prototype._lerp = function (a, b, t) {
    return a + (b - a) * t;
  };

  Tracker.prototype._setMarkerHeading = function (marker, bearing) {
    if (!marker) return;
    var el = marker.getElement && marker.getElement();
    if (!el) return;
    var wrap = el.querySelector('.ugt-mover');
    if (wrap) {
      wrap.style.setProperty('--ugt-heading', String(Math.round(bearing)) + 'deg');
      wrap.classList.add('is-moving');
      this._pulseMarker(marker);
    }
  };

  Tracker.prototype._pulseMarker = function (marker) {
    if (!marker) return;
    var el = marker.getElement && marker.getElement();
    if (!el) return;
    var wrap = el.querySelector('.ugt-mover');
    if (!wrap) return;
    wrap.classList.add('is-live');
    var key = marker._ugtUserId != null ? String(marker._ugtUserId) : '';
    if (this.livePulseTimers[key]) {
      clearTimeout(this.livePulseTimers[key]);
    }
    var self = this;
    this.livePulseTimers[key] = setTimeout(function () {
      wrap.classList.remove('is-live');
      if (!self.moveAnims[key]) {
        wrap.classList.remove('is-moving');
      }
    }, 3500);
  };

  Tracker.prototype._stopMarkerMovingClass = function (marker) {
    if (!marker) return;
    var el = marker.getElement && marker.getElement();
    if (!el) return;
    var wrap = el.querySelector('.ugt-mover');
    if (wrap) wrap.classList.remove('is-moving');
  };

  Tracker.prototype.animateMarkerTo = function (userId, lat, lng, opts) {
    opts = opts || {};
    var key = this._trailKey(userId);
    var marker = this.markersById[userId] || this.markersById[key];
    if (!marker) return;

    var to = [lat, lng];
    var cur = marker.getLatLng();
    var from = [cur.lat, cur.lng];
    var dist = this._haversineMeters(from, to);

    if (dist < 1.5) {
      marker.setLatLng(to);
      delete this.moveAnims[key];
      this._pulseMarker(marker);
      return;
    }

    if (dist > this.maxTrailJumpMeters || opts.snap) {
      marker.setLatLng(to);
      delete this.moveAnims[key];
      this._stopMarkerMovingClass(marker);
      return;
    }

    this._setMarkerHeading(marker, this._bearingDeg(from, to));
    this.moveAnims[key] = {
      from: from,
      to: to,
      start: performance.now(),
      duration: Math.min(this.smoothMoveMs, Math.max(650, dist * 10)),
      follow: this.activeId != null && String(this.activeId) === key,
      userId: userId,
    };
    this._ensureAnimLoop();
  };

  Tracker.prototype._ensureAnimLoop = function () {
    if (this._animRaf) return;
    var self = this;
    var tick = function (now) {
      var keys = Object.keys(self.moveAnims);
      if (!keys.length) {
        self._animRaf = null;
        return;
      }
      for (var i = 0; i < keys.length; i++) {
        var key = keys[i];
        var anim = self.moveAnims[key];
        var marker = self.markersById[anim.userId] || self.markersById[key];
        if (!marker || !anim) {
          delete self.moveAnims[key];
          continue;
        }
        var t = (now - anim.start) / anim.duration;
        if (t >= 1) {
          marker.setLatLng(anim.to);
          self.appendLiveTrailPoint(anim.userId, anim.to[0], anim.to[1]);
          self._stopMarkerMovingClass(marker);
          delete self.moveAnims[key];
          if (anim.follow && self.map) {
            self.map.panTo(anim.to, { animate: true, duration: 0.25 });
          }
          continue;
        }
        var e = self._easeInOut(Math.max(0, t));
        var lat = self._lerp(anim.from[0], anim.to[0], e);
        var lng = self._lerp(anim.from[1], anim.to[1], e);
        marker.setLatLng([lat, lng]);
        if (t > 0.15 && t < 0.95 && Math.floor(t * 20) % 2 === 0) {
          self.appendLiveTrailPoint(anim.userId, lat, lng);
        }
        if (anim.follow && self.map && Math.floor(now / 60) !== Math.floor((now - 16) / 60)) {
          self.map.panTo([lat, lng], { animate: false });
        }
      }
      self._animRaf = global.requestAnimationFrame(tick);
    };
    this._animRaf = global.requestAnimationFrame(tick);
  };

  Tracker.prototype.appendLiveTrailPoint = function (userId, lat, lng) {
    if (!isFinite(lat) || !isFinite(lng)) return;
    var key = this._trailKey(userId);
    var point = [lat, lng];
    var trail = this.trailsById[key];
    if (!trail) {
      this.trailsById[key] = [point];
      this.syncTrailLine(userId);
      return;
    }
    var last = trail[trail.length - 1];
    var dist = this._haversineMeters(last, point);
    if (dist < this.minTrailMoveMeters) return;
    if (dist > this.maxTrailJumpMeters) {
      // قفزة غير منطقية (GPS ضعيف / إعادة اتصال بعيد) — ابدأ مقطعاً جديداً من النقطة الحالية.
      this.trailsById[key] = [point];
      this.syncTrailLine(userId);
      return;
    }
    trail.push(point);
    if (trail.length > this.maxTrailPoints) {
      trail.splice(0, trail.length - this.maxTrailPoints);
    }
    this.syncTrailLine(userId);
  };

  Tracker.prototype.syncTrailLine = function (userId) {
    if (!this.trailLayer || !global.L) return;
    var key = this._trailKey(userId);
    var trail = this.trailsById[key] || [];
    var existing = this.trailLinesById[key];
    var isActive = this.activeId != null && String(this.activeId) === key;
    var style = {
      color: isActive ? '#dc2626' : '#2563eb',
      weight: isActive ? 5 : 4,
      opacity: isActive ? 0.95 : 0.78,
      lineJoin: 'round',
      lineCap: 'round',
      smoothFactor: 1.2,
    };
    if (trail.length < 2) {
      if (existing) {
        this.trailLayer.removeLayer(existing);
        delete this.trailLinesById[key];
      }
      return;
    }
    if (existing) {
      existing.setLatLngs(trail);
      existing.setStyle(style);
    } else {
      this.trailLinesById[key] = global.L.polyline(trail, style).addTo(this.trailLayer);
    }
  };

  Tracker.prototype.refreshTrailStyles = function () {
    var self = this;
    Object.keys(this.trailsById).forEach(function (key) {
      self.syncTrailLine(key);
    });
  };

  Tracker.prototype.clearLiveTrails = function () {
    this.trailsById = {};
    if (this.usesArcgis()) {
      global.MapInterop.clearTrails();
    } else if (this.trailLayer) {
      this.trailLayer.clearLayers();
    }
    this.trailLinesById = {};
    this.setStatus('تم مسح الخطوط الحيّة');
  };

  Tracker.prototype.rowsForArcgis = function () {
    var self = this;
    return this.rows.map(function (r) {
      return {
        user_id: r.user_id,
        latitude: r.latitude,
        longitude: r.longitude,
        user_label: r.user_label,
        is_online: r.is_online || r.status === 'online',
        popup_html: self.popupHtml(r),
      };
    });
  };

  Tracker.prototype.renderArcgisMarkers = function () {
    if (!this.map || !global.MapInterop) {
      return;
    }
    var follow =
      this.activeId != null &&
      this.moveAnims[this._trailKey(this.activeId)] &&
      this.moveAnims[this._trailKey(this.activeId)].follow;
    global.MapInterop.updateFleet(this.rowsForArcgis(), {
      activeId: this.activeId,
      fitOnce: this.arcgisFitOnce,
      followActive: !!follow,
    });
    if (this.arcgisFitOnce && this.rows.length) {
      this.arcgisFitOnce = false;
    }
  };

  Tracker.prototype.renderMarkers = function () {
    if (!this.map) return;
    if (this.usesArcgis()) {
      this.renderArcgisMarkers();
      return;
    }
    if (!this.layer) return;
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
        var cur = existing.getLatLng();
        var bearing = this._bearingDeg([cur.lat, cur.lng], latlng);
        existing.setIcon(this.markerIcon(r, bearing));
        existing.setPopupContent(this.popupHtml(r));
        existing._ugtRow = r;
        this.animateMarkerTo(id, r.latitude, r.longitude);
      } else {
        this.appendLiveTrailPoint(id, r.latitude, r.longitude);
        var m = global.L.marker(latlng, { icon: this.markerIcon(r, 0) });
        m.bindPopup(this.popupHtml(r));
        m._ugtRow = r;
        m._ugtUserId = id;
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
        delete this.moveAnims[this._trailKey(id)];
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
    }
  };

  Tracker.prototype.focusUser = function (userId, pan) {
    this.activeId = userId;
    this.renderList();
    this.refreshTrailStyles();
    var key = this._trailKey(userId);
    if (this.moveAnims[key]) {
      this.moveAnims[key].follow = true;
    }

    if (this.usesArcgis()) {
      var row = null;
      for (var i = 0; i < this.rows.length; i++) {
        if (String(this.rows[i].user_id) === String(userId)) {
          row = this.rows[i];
          break;
        }
      }
      if (row) {
        global.MapInterop.updateFleet(this.rowsForArcgis(), {
          activeId: userId,
          followActive: pan !== false,
        });
        if (pan !== false) {
          global.MapInterop.focusUser(userId, row.latitude, row.longitude, 15);
        }
      }
      return;
    }

    var m = this.markersById[userId];
    if (!m) return;
    if (pan !== false) {
      this.map.setView(m.getLatLng(), Math.max(this.map.getZoom(), 15), {
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
