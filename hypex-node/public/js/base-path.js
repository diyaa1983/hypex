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
    if (typeof loc.assign === 'function') {
      var origAssign = loc.assign.bind(loc);
      loc.assign = function (v) {
        return origAssign(fix(String(v)));
      };
    }
    if (typeof loc.replace === 'function') {
      var origReplace = loc.replace.bind(loc);
      loc.replace = function (v) {
        return origReplace(fix(String(v)));
      };
    }
  } catch (e) {
    /* ignore — rewriteJs / hxPath cover most scripts */
  }

  try {
    var hist = window.history;
    if (hist && typeof hist.pushState === 'function') {
      var origPush = hist.pushState.bind(hist);
      hist.pushState = function (state, title, url) {
        if (typeof url === 'string') url = fix(url);
        return origPush(state, title, url);
      };
    }
    if (hist && typeof hist.replaceState === 'function') {
      var origRep = hist.replaceState.bind(hist);
      hist.replaceState = function (state, title, url) {
        if (typeof url === 'string') url = fix(url);
        return origRep(state, title, url);
      };
    }
  } catch (e2) {
    /* ignore */
  }

  window.__hypexUrl = fix;

  /** فتح معاينة الطباعة في نفس التبويب (بدون tab جديد) */
  window.__hypexOpenPrint = function (path) {
    window.location.assign(fix(String(path || '')));
  };
})();
