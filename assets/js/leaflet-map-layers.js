(function (global) {
  'use strict';

  var PROVIDERS = {
    esri: {
      tileUrl:
        'https://server.arcgisonline.com/ArcGIS/rest/services/World_Street_Map/MapServer/tile/{z}/{y}/{x}',
      attribution: '&copy; Esri &mdash; OpenStreetMap contributors',
      maxZoom: 20,
      maxNativeZoom: 17,
    },
    natgeo: {
      tileUrl:
        'https://server.arcgisonline.com/ArcGIS/rest/services/NatGeo_World_Map/MapServer/tile/{z}/{y}/{x}',
      attribution: '&copy; National Geographic, Esri, Garmin, HERE',
      maxZoom: 16,
      maxNativeZoom: 16,
    },
    carto: {
      tileUrl:
        'https://{s}.basemaps.cartocdn.com/rastertiles/voyager/{z}/{x}/{y}{r}.png',
      attribution:
        '&copy; <a href="https://www.openstreetmap.org/copyright">OSM</a> &copy; CARTO',
      maxZoom: 20,
      subdomains: 'abcd',
    },
  };

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

  function ensureGoogle(apiKey) {
    if (!apiKey) return Promise.reject(new Error('no_google_key'));
    if (
      global.L &&
      global.L.gridLayer &&
      global.L.gridLayer.googleMutant &&
      global.google &&
      global.google.maps
    ) {
      return Promise.resolve();
    }
    if (global.__leafletGooglePromise) return global.__leafletGooglePromise;

    global.__leafletGooglePromise = new Promise(function (resolve, reject) {
      function loadMutant() {
        loadScript(
          'https://unpkg.com/leaflet.gridlayer.googlemutant@0.14.1/dist/Leaflet.GoogleMutant.js'
        )
          .then(resolve)
          .catch(reject);
      }
      if (global.google && global.google.maps) {
        loadMutant();
        return;
      }
      var g = document.createElement('script');
      g.src =
        'https://maps.googleapis.com/maps/api/js?key=' +
        encodeURIComponent(apiKey) +
        '&v=weekly&loading=async';
      g.async = true;
      g.onload = loadMutant;
      g.onerror = reject;
      document.head.appendChild(g);
    });
    return global.__leafletGooglePromise;
  }

  function attachRaster(map, providerKey, tileUrl, attribution, extra) {
    var def = PROVIDERS[providerKey] || PROVIDERS.esri;
    var url =
      tileUrl && String(tileUrl).indexOf('{z}') >= 0
        ? tileUrl
        : def.tileUrl;
    var opts = {
      attribution: attribution || def.attribution,
      maxZoom: (extra && extra.maxZoom) || def.maxZoom || 20,
    };
    if (def.maxNativeZoom) opts.maxNativeZoom = def.maxNativeZoom;
    if (def.subdomains) opts.subdomains = def.subdomains;
    if (extra && extra.maxNativeZoom) opts.maxNativeZoom = extra.maxNativeZoom;
    if (extra && extra.noAttribution) opts.attribution = '';
    global.L.tileLayer(url, opts).addTo(map);
  }

  /** Carto دائماً + Esri للتكبير المنخفض فقط (يمنع بلاطات «Map data not yet available»). */
  function attachEsriHybrid(map, tileUrl, attribution) {
    var carto = global.L.tileLayer(PROVIDERS.carto.tileUrl, {
      attribution: PROVIDERS.carto.attribution,
      maxZoom: 20,
      subdomains: PROVIDERS.carto.subdomains,
    });
    var esriUrl =
      tileUrl && String(tileUrl).indexOf('{z}') >= 0
        ? tileUrl
        : PROVIDERS.esri.tileUrl;
    var esri = global.L.tileLayer(esriUrl, {
      attribution: attribution || PROVIDERS.esri.attribution,
      maxNativeZoom: 17,
      maxZoom: 17,
    });
    carto.addTo(map);

    var esriCutoff = 14;

    function syncEsriLayer() {
      var z = map.getZoom();
      if (z > esriCutoff) {
        if (map.hasLayer(esri)) map.removeLayer(esri);
      } else if (!map.hasLayer(esri)) {
        esri.addTo(map);
      }
    }

    map.on('zoomend', syncEsriLayer);
    syncEsriLayer();
  }

  function attachGoogle(map) {
    global.L.gridLayer
      .googleMutant({
        type: 'roadmap',
        maxZoom: 21,
      })
      .addTo(map);
  }

  /**
   * @param {L.Map} map
   * @param {{tileUrl?:string, attribution?:string, mapProvider?:string, googleKey?:string}} opts
   */
  function attachBaseLayer(map, opts) {
    opts = opts || {};
    var cfg = global.AppOsmConfig || {};
    var provider = (opts.mapProvider || cfg.mapProvider || 'esri').toLowerCase();
    var googleKey = opts.googleKey || cfg.googleMapsKey || cfg.google_maps_key || '';

    if (provider === 'google' && googleKey) {
      return ensureGoogle(googleKey)
        .then(function () {
          if (global.L.gridLayer && global.L.gridLayer.googleMutant) {
            attachGoogle(map);
            return 'google';
          }
          attachEsriHybrid(map, opts.tileUrl, opts.attribution);
          return 'esri';
        })
        .catch(function () {
          attachEsriHybrid(map, opts.tileUrl, opts.attribution);
          return 'esri';
        });
    }

    if (provider === 'carto') {
      attachRaster(map, 'carto', opts.tileUrl, opts.attribution);
      return Promise.resolve('carto');
    }

    attachEsriHybrid(map, opts.tileUrl, opts.attribution);
    return Promise.resolve('esri');
  }

  global.LeafletMapLayers = {
    attach: attachBaseLayer,
    ensureGoogle: ensureGoogle,
  };
})(window);
