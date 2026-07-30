/**
 * جسر ArcGIS MapView لتتبّع المواقع (مستوحى من MapInterop + Blazor).
 * يدعم عدة مستخدمين مع مسار حي لكل منهم.
 */
window.MapInterop = (function () {
  'use strict';

  var ESRI_VERSION = '4.29';
  var ARCGIS_CSS = 'https://js.arcgis.com/' + ESRI_VERSION + '/esri/themes/light/main.css';
  var ARCGIS_JS = 'https://js.arcgis.com/' + ESRI_VERSION + '/';
  var DEFAULT_TILE_URL =
    'https://services.arcgisonline.com/ArcGIS/rest/services/World_Street_Map/MapServer';
  var NATGEO_TILE_URL =
    'https://services.arcgisonline.com/ArcGIS/rest/services/NatGeo_World_Map/MapServer';

  var _Graphic;
  var _Point;
  var _Polyline;
  var _SimpleMarkerSymbol;
  var _SimpleLineSymbol;

  var view = null;
  var graphicsLayer = null;
  var apiPromise = null;
  var initPromise = null;
  var basemapUrl = DEFAULT_TILE_URL;

  var trailsById = {};
  var markerGraphicsById = {};
  var trailGraphicsById = {};
  var MAX_TRAIL = 40;

  function loadStyle(href) {
    return new Promise(function (resolve, reject) {
      var link = document.createElement('link');
      link.rel = 'stylesheet';
      link.href = href;
      link.onload = resolve;
      link.onerror = reject;
      document.head.appendChild(link);
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

  function loadApi() {
    if (typeof globalThis.require === 'function' && globalThis.require.toUrl) {
      return Promise.resolve();
    }
    if (apiPromise) {
      return apiPromise;
    }
    apiPromise = loadStyle(ARCGIS_CSS)
      .then(function () {
        return loadScript(ARCGIS_JS);
      })
      .then(function () {
        if (typeof globalThis.require !== 'function') {
          throw new Error('arcgis_api_load_failed');
        }
      });
    return apiPromise;
  }

  function loadModules() {
    return new Promise(function (resolve, reject) {
      require(
        [
          'esri/Map',
          'esri/views/MapView',
          'esri/layers/TileLayer',
          'esri/layers/GraphicsLayer',
          'esri/Graphic',
          'esri/geometry/Point',
          'esri/geometry/Polyline',
          'esri/symbols/SimpleMarkerSymbol',
          'esri/symbols/SimpleLineSymbol',
        ],
        function (
          Map,
          MapView,
          TileLayer,
          GraphicsLayer,
          Graphic,
          Point,
          Polyline,
          SimpleMarkerSymbol,
          SimpleLineSymbol
        ) {
          resolve({
            Map: Map,
            MapView: MapView,
            TileLayer: TileLayer,
            GraphicsLayer: GraphicsLayer,
            Graphic: Graphic,
            Point: Point,
            Polyline: Polyline,
            SimpleMarkerSymbol: SimpleMarkerSymbol,
            SimpleLineSymbol: SimpleLineSymbol,
          });
        },
        reject
      );
    });
  }

  function markerColor(attrs) {
    if (attrs && attrs.is_active) {
      return [220, 38, 38];
    }
    if (attrs && attrs.is_online) {
      return [0, 166, 255];
    }
    return [148, 163, 184];
  }

  function buildVehicleGraphic(lon, lat, attrs) {
    attrs = attrs || {};
    var color = markerColor(attrs);
    return new _Graphic({
      geometry: new _Point({ longitude: lon, latitude: lat }),
      attributes: attrs,
      symbol: new _SimpleMarkerSymbol({
        color: color,
        size: attrs.is_active ? 20 : 18,
        outline: { color: [255, 255, 255], width: 2.5 },
      }),
      popupTemplate: {
        title: '{user_label}',
        content: function (feature) {
          var html = feature.graphic.attributes.popup_html || '';
          return html || 'Lat: ' + lat.toFixed(6) + '<br>Lon: ' + lon.toFixed(6);
        },
      },
    });
  }

  function syncTrailGraphic(userId, isActive) {
    var key = String(userId);
    var trail = trailsById[key] || [];
    var existing = trailGraphicsById[key];

    if (trail.length < 2) {
      if (existing && graphicsLayer) {
        graphicsLayer.remove(existing);
      }
      delete trailGraphicsById[key];
      return;
    }

    var graphic = new _Graphic({
      geometry: new _Polyline({
        paths: [trail],
        spatialReference: { wkid: 4326 },
      }),
      symbol: new _SimpleLineSymbol({
        color: isActive ? [220, 38, 38, 200] : [0, 166, 255, 160],
        width: isActive ? 4 : 3,
        style: 'dash',
      }),
    });

    if (existing && graphicsLayer) {
      graphicsLayer.remove(existing);
    }
    trailGraphicsById[key] = graphic;
    if (graphicsLayer) {
      graphicsLayer.add(graphic);
    }
  }

  function appendTrailPoint(userId, lon, lat) {
    if (!isFinite(lat) || !isFinite(lon)) {
      return;
    }
    var key = String(userId);
    var point = [lon, lat];
    var trail = trailsById[key];
    if (!trail) {
      trailsById[key] = [point];
      return;
    }
    var last = trail[trail.length - 1];
    if (last && last[0] === lon && last[1] === lat) {
      return;
    }
    trail.push(point);
    if (trail.length > MAX_TRAIL) {
      trail.shift();
    }
  }

  return {
    loadApi: loadApi,

    /**
     * @param {HTMLElement} containerElement
     * @param {number} startLat
     * @param {number} startLon
     * @param {{basemapUrl?:string, mapProvider?:string}} [options]
     */
    initialize: function (containerElement, startLat, startLon, options) {
      if (initPromise) {
        return initPromise;
      }

      options = options || {};
      var provider = String(options.mapProvider || '').toLowerCase();
      if (options.basemapUrl) {
        basemapUrl = options.basemapUrl;
      } else if (provider === 'natgeo') {
        basemapUrl = NATGEO_TILE_URL;
      } else {
        basemapUrl = DEFAULT_TILE_URL;
      }

      var lat = isFinite(startLat) ? startLat : 31.9539;
      var lon = isFinite(startLon) ? startLon : 35.9106;
      var startZoom = provider === 'natgeo' ? 12 : 14;

      initPromise = loadApi()
        .then(loadModules)
        .then(function (mods) {
          _Graphic = mods.Graphic;
          _Point = mods.Point;
          _Polyline = mods.Polyline;
          _SimpleMarkerSymbol = mods.SimpleMarkerSymbol;
          _SimpleLineSymbol = mods.SimpleLineSymbol;

          var tileLayer = new mods.TileLayer({
            url: basemapUrl,
            title: provider === 'natgeo' ? 'NatGeo World Map' : 'World Street Map',
          });

          graphicsLayer = new mods.GraphicsLayer({ title: 'Fleet' });
          var map = new mods.Map({ layers: [tileLayer, graphicsLayer] });

          view = new mods.MapView({
            container: containerElement,
            map: map,
            center: [lon, lat],
            zoom: startZoom,
            ui: { components: ['zoom', 'compass'] },
          });

          return new Promise(function (resolve, reject) {
            view.when(resolve, reject);
          });
        });

      return initPromise;
    },

  /** تحديث مركبة واحدة (واجهة الكود الأصلي). */
    updatePosition: function (lat, lon, heading) {
      if (!view || !graphicsLayer) {
        return;
      }
      this.updateFleet(
        [
          {
            user_id: 'vehicle',
            latitude: lat,
            longitude: lon,
            user_label: 'Vehicle',
            is_online: true,
            popup_html: 'Lat: ' + lat.toFixed(6) + '<br>Lon: ' + lon.toFixed(6),
          },
        ],
        { activeId: 'vehicle', followActive: true }
      );
    },

    /**
     * تحديث عدة مستخدمين على الخريطة.
     * @param {Array} rows
     * @param {{activeId?:*, fitOnce?:boolean, followActive?:boolean}} opts
     */
    updateFleet: function (rows, opts) {
      if (!view || !graphicsLayer || !_Graphic) {
        return;
      }

      opts = opts || {};
      var activeKey = opts.activeId != null ? String(opts.activeId) : '';
      var keep = {};
      var lats = [];
      var lngs = [];

      for (var i = 0; i < rows.length; i++) {
        var r = rows[i];
        var id = r.user_id;
        var key = String(id);
        keep[key] = true;
        var lat = parseFloat(r.latitude);
        var lon = parseFloat(r.longitude);
        if (!isFinite(lat) || !isFinite(lon)) {
          continue;
        }

        lats.push(lat);
        lngs.push(lon);
        appendTrailPoint(id, lon, lat);

        var attrs = {
          user_id: key,
          user_label: r.user_label || '',
          is_online: !!(r.is_online || r.status === 'online'),
          is_active: activeKey === key,
          popup_html: r.popup_html || '',
        };

        var existing = markerGraphicsById[key];
        if (existing) {
          existing.geometry = new _Point({ longitude: lon, latitude: lat });
          existing.attributes = attrs;
          existing.symbol = new _SimpleMarkerSymbol({
            color: markerColor(attrs),
            size: attrs.is_active ? 20 : 18,
            outline: { color: [255, 255, 255], width: 2.5 },
          });
        } else {
          var g = buildVehicleGraphic(lon, lat, attrs);
          markerGraphicsById[key] = g;
          graphicsLayer.add(g);
        }

        syncTrailGraphic(id, activeKey === key);
      }

      Object.keys(markerGraphicsById).forEach(function (key) {
        if (!keep[key]) {
          var marker = markerGraphicsById[key];
          if (marker && graphicsLayer) {
            graphicsLayer.remove(marker);
          }
          delete markerGraphicsById[key];
          delete trailsById[key];
          if (trailGraphicsById[key] && graphicsLayer) {
            graphicsLayer.remove(trailGraphicsById[key]);
          }
          delete trailGraphicsById[key];
        }
      });

      if (opts.followActive && activeKey && markerGraphicsById[activeKey]) {
        var active = markerGraphicsById[activeKey];
        var pt = active.geometry;
        if (pt) {
          view.goTo(
            { center: [pt.longitude, pt.latitude], zoom: Math.max(view.zoom || 14, 15) },
            { duration: 900, easing: 'ease-in-out' }
          );
        }
      } else if (opts.fitOnce && lats.length) {
        opts.fitOnce = false;
        var minLat = Math.min.apply(null, lats);
        var maxLat = Math.max.apply(null, lats);
        var minLon = Math.min.apply(null, lngs);
        var maxLon = Math.max.apply(null, lngs);
        if (minLat === maxLat) {
          minLat -= 0.01;
          maxLat += 0.01;
        }
        if (minLon === maxLon) {
          minLon -= 0.01;
          maxLon += 0.01;
        }
        view.goTo(
          {
            center: [(minLon + maxLon) / 2, (minLat + maxLat) / 2],
            zoom: 14,
          },
          { duration: 600 }
        );
      }
    },

    focusUser: function (userId, lat, lon, zoom) {
      if (!view) {
        return;
      }
      var key = String(userId);
      var marker = markerGraphicsById[key];
      if (marker && marker.geometry) {
        lat = marker.geometry.latitude;
        lon = marker.geometry.longitude;
      }
      if (!isFinite(lat) || !isFinite(lon)) {
        return;
      }
      view.goTo(
        { center: [lon, lat], zoom: zoom || Math.max(view.zoom || 14, 15) },
        { duration: 700, easing: 'ease-in-out' }
      );
      if (marker && view.popup) {
        view.popup.open({ features: [marker] });
      }
    },

    clearTrails: function () {
      trailsById = {};
      Object.keys(trailGraphicsById).forEach(function (key) {
        if (trailGraphicsById[key] && graphicsLayer) {
          graphicsLayer.remove(trailGraphicsById[key]);
        }
      });
      trailGraphicsById = {};
    },

    invalidateSize: function () {
      if (view && typeof view.resize === 'function') {
        view.resize();
      }
    },

    dispose: function () {
      if (view) {
        view.destroy();
      }
      view = null;
      graphicsLayer = null;
      initPromise = null;
      trailsById = {};
      markerGraphicsById = {};
      trailGraphicsById = {};
    },
  };
})();
