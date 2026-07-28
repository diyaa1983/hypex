(function (global) {
  'use strict';

  var DEFAULT_TILE =
    'https://{s}.basemaps.cartocdn.com/rastertiles/voyager/{z}/{x}/{y}{r}.png';
  var DEFAULT_ATTR =
    '&copy; <a href="https://www.openstreetmap.org/copyright">OSM</a> &copy; CARTO';

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

  function attachCarto(map, tileUrl, attribution) {
    var url = tileUrl && String(tileUrl).indexOf('{z}') >= 0 ? tileUrl : DEFAULT_TILE;
    global.L.tileLayer(url, {
      attribution: attribution || DEFAULT_ATTR,
      maxZoom: 20,
      subdomains: 'abcd',
    }).addTo(map);
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
    var provider = opts.mapProvider || 'carto';
    var googleKey = opts.googleKey || '';
    var cfg = global.AppOsmConfig || {};
    if (!googleKey && cfg.googleMapsKey) googleKey = cfg.googleMapsKey;
    if (!googleKey && cfg.google_maps_key) googleKey = cfg.google_maps_key;
    if (provider !== 'google' && cfg.mapProvider === 'google') provider = 'google';

    if (provider === 'google' && googleKey) {
      return ensureGoogle(googleKey)
        .then(function () {
          if (global.L.gridLayer && global.L.gridLayer.googleMutant) {
            attachGoogle(map);
            return 'google';
          }
          attachCarto(map, opts.tileUrl, opts.attribution);
          return 'carto';
        })
        .catch(function () {
          attachCarto(map, opts.tileUrl, opts.attribution);
          return 'carto';
        });
    }

    attachCarto(map, opts.tileUrl, opts.attribution);
    return Promise.resolve('carto');
  }

  global.LeafletMapLayers = {
    attach: attachBaseLayer,
    ensureGoogle: ensureGoogle,
  };
})(window);
