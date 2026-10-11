/**
 * Keep fetch / navigation working when the app is served under /hypex,
 * and optionally obscure page paths when APP_OBSCURE_ROUTES is on.
 * window.__HYPEX_BASE__ is injected by the shell (e.g. "/hypex").
 */
(function () {
  'use strict';
  var base = typeof window.__HYPEX_BASE__ === 'string' ? window.__HYPEX_BASE__ : '';
  if (base === '/') base = '';
  if (base && base.charAt(base.length - 1) === '/') base = base.slice(0, -1);
  var obscure = window.__HYPEX_OBSCURE__ === 1 || window.__HYPEX_OBSCURE__ === true;

  function needsPrefix(u) {
    return (
      !!base &&
      typeof u === 'string' &&
      u.charAt(0) === '/' &&
      u.indexOf('//') !== 0 &&
      u !== base &&
      u.indexOf(base + '/') !== 0
    );
  }

  function isAssetOrApi(p) {
    return (
      p.indexOf('/api/') === 0 ||
      p === '/api' ||
      p.indexOf('/assets/') === 0 ||
      p.indexOf('/static/') === 0 ||
      p === '/health' ||
      p === '/login' ||
      p === '/logout' ||
      p.indexOf('/n/') === 0 ||
      p.indexOf('/favicon') === 0 ||
      /\.[a-z0-9]{1,8}$/i.test(p.split('?')[0])
    );
  }

  /** تشفير متزامن بسيط متوافق مع السيرفر — يُستخدم للتنقل فقط */
  function obscurePath(u) {
    if (!obscure || typeof u !== 'string') return u;
    if (u.charAt(0) !== '/' || u.indexOf('//') === 0) return u;
    var path = u;
    if (base && (path === base || path.indexOf(base + '/') === 0)) {
      path = path.slice(base.length) || '/';
    }
    if (isAssetOrApi(path.split('?')[0])) return u;
    // اترك السيرفر يعيد التوجيه للرمز — تجنباً لنسخ مفتاح التشفير في المتصفح
    return u;
  }

  function fix(u) {
    var s = obscurePath(String(u || ''));
    return needsPrefix(s) ? base + s : s;
  }

  if (!base && !obscure) return;

  var origFetch = window.fetch;
  if (typeof origFetch === 'function') {
    window.fetch = function (input, init) {
      if (typeof input === 'string') input = fix(input);
      else if (input && typeof Request !== 'undefined' && input instanceof Request) {
        if (needsPrefix(input.url) || (obscure && typeof input.url === 'string')) {
          var rel = input.url.replace(/^https?:\/\/[^/]+/i, '') || input.url;
          input = new Request(fix(rel), input);
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
