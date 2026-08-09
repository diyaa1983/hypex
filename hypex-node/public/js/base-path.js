/**
 * Keep fetch / navigation working when the app is served under /hypex.
 * window.__HYPEX_BASE__ is injected by the shell (e.g. "/hypex").
 */
(function () {
  'use strict';
  var base = typeof window.__HYPEX_BASE__ === 'string' ? window.__HYPEX_BASE__ : '';
  if (!base || base === '/') return;
  if (base.charAt(base.length - 1) === '/') base = base.slice(0, -1);

  function needsPrefix(u) {
    return (
      typeof u === 'string' &&
      u.charAt(0) === '/' &&
      u.indexOf('//') !== 0 &&
      u !== base &&
      u.indexOf(base + '/') !== 0
    );
  }

  function fix(u) {
    return needsPrefix(u) ? base + u : u;
  }

  var origFetch = window.fetch;
  if (typeof origFetch === 'function') {
    window.fetch = function (input, init) {
      if (typeof input === 'string') input = fix(input);
      else if (input && typeof Request !== 'undefined' && input instanceof Request) {
        if (needsPrefix(input.url)) {
          input = new Request(fix(input.url.replace(/^https?:\/\/[^/]+/i, '') || input.url), input);
        }
      }
      return origFetch.call(this, input, init);
    };
  }

  try {
    var loc = window.location;
    var desc = Object.getOwnPropertyDescriptor(Location.prototype, 'href');
    if (desc && desc.set) {
      Object.defineProperty(loc, 'href', {
        configurable: true,
        enumerable: true,
        get: function () {
          return desc.get.call(loc);
        },
        set: function (v) {
          desc.set.call(loc, fix(String(v)));
        },
      });
    }
  } catch (e) {
    /* ignore — rewriteJs covers most scripts */
  }

  window.__hypexUrl = fix;
})();
