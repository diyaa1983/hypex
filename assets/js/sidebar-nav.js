(function () {
  'use strict';

  var STORAGE_KEY = 'manager:sidebar-nav-open';

  function loadState() {
    try {
      var raw = localStorage.getItem(STORAGE_KEY);
      if (!raw) {
        return { domains: [], subfolds: [] };
      }
      var data = JSON.parse(raw);
      return {
        domains: Array.isArray(data.domains) ? data.domains.filter(Boolean) : [],
        subfolds: Array.isArray(data.subfolds) ? data.subfolds.filter(Boolean) : [],
      };
    } catch (e) {
      return { domains: [], subfolds: [] };
    }
  }

  function saveState(domains, subfolds) {
    try {
      localStorage.setItem(
        STORAGE_KEY,
        JSON.stringify({ domains: domains, subfolds: subfolds })
      );
    } catch (e) {}
  }

  function subfoldKey(el) {
    var d = el.getAttribute('data-domain-id');
    var s = el.getAttribute('data-sub-id');
    if (!d || !s) {
      return '';
    }
    return d + '/' + s;
  }

  function collectOpen(nav) {
    var domains = [];
    var subfolds = [];
    nav.querySelectorAll('.nav-domain-fold[open]').forEach(function (el) {
      var id = el.getAttribute('data-domain-id');
      if (id) {
        domains.push(id);
      }
    });
    nav.querySelectorAll('.nav-subfold[open]').forEach(function (el) {
      var key = subfoldKey(el);
      if (key) {
        subfolds.push(key);
      }
    });
    return { domains: domains, subfolds: subfolds };
  }

  function applyStoredOpenState(nav) {
    var stored = loadState();
    var domainSet = {};
    var subSet = {};
    stored.domains.forEach(function (id) {
      domainSet[id] = true;
    });
    stored.subfolds.forEach(function (key) {
      subSet[key] = true;
    });

    nav.querySelectorAll('.nav-domain-fold').forEach(function (el) {
      var id = el.getAttribute('data-domain-id');
      if (!id) {
        return;
      }
      if (domainSet[id] || el.hasAttribute('open')) {
        el.setAttribute('open', '');
        domainSet[id] = true;
      }
    });

    nav.querySelectorAll('.nav-subfold').forEach(function (el) {
      var key = subfoldKey(el);
      if (!key) {
        return;
      }
      if (subSet[key] || el.hasAttribute('open')) {
        el.setAttribute('open', '');
        subSet[key] = true;
        var domainId = el.getAttribute('data-domain-id');
        if (domainId) {
          var parent = nav.querySelector(
            '.nav-domain-fold[data-domain-id="' + domainId + '"]'
          );
          if (parent) {
            parent.setAttribute('open', '');
            domainSet[domainId] = true;
          }
        }
      }
    });

    var open = collectOpen(nav);
    saveState(open.domains, open.subfolds);
  }

  function clearStoredOpenState() {
    try {
      localStorage.removeItem(STORAGE_KEY);
    } catch (e) {}
  }

  function init() {
    var nav = document.querySelector('.sidebar-nav');
    if (!nav) {
      return;
    }

    applyStoredOpenState(nav);

    nav.addEventListener(
      'toggle',
      function (ev) {
        var el = ev.target;
        if (!el || !el.classList) {
          return;
        }
        if (
          !el.classList.contains('nav-domain-fold') &&
          !el.classList.contains('nav-subfold')
        ) {
          return;
        }
        var open = collectOpen(nav);
        saveState(open.domains, open.subfolds);
      },
      true
    );

    document.querySelectorAll('.sidebar-logout-btn, .app-topbar-logout').forEach(function (link) {
      link.addEventListener('click', clearStoredOpenState);
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
