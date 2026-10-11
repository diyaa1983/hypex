(function () {
  'use strict';

  /** منع زوم المتصفح حتى لا يتغيّر شكل القوائم والواجهة */
  (function lockAppZoom() {
    function isZoomKey(e) {
      var k = e.key || '';
      return (
        k === '+' ||
        k === '=' ||
        k === '-' ||
        k === '_' ||
        k === 'Add' ||
        k === 'Subtract' ||
        k === '0' ||
        e.code === 'NumpadAdd' ||
        e.code === 'NumpadSubtract' ||
        e.code === 'Equal' ||
        e.code === 'Minus' ||
        e.code === 'Digit0'
      );
    }
    document.addEventListener(
      'keydown',
      function (e) {
        if ((e.ctrlKey || e.metaKey) && isZoomKey(e)) {
          e.preventDefault();
        }
      },
      { passive: false }
    );
    document.addEventListener(
      'wheel',
      function (e) {
        if (e.ctrlKey || e.metaKey) {
          e.preventDefault();
        }
      },
      { passive: false }
    );
    document.addEventListener(
      'gesturestart',
      function (e) {
        e.preventDefault();
      },
      { passive: false }
    );
    document.addEventListener(
      'gesturechange',
      function (e) {
        e.preventDefault();
      },
      { passive: false }
    );
    try {
      document.documentElement.style.touchAction = 'pan-x pan-y';
      document.body && (document.body.style.touchAction = 'pan-x pan-y');
    } catch (err) {
      /* ignore */
    }
  })();

  document.querySelectorAll('[data-toggle-domain]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var section = btn.closest('.nav-domain');
      if (section) section.classList.toggle('is-open');
    });
  });

  var root = document.querySelector('.hx-topnav');
  if (root) {
    function resetFlyPlace(fly) {
      if (!fly) return;
      fly.style.top = '';
      fly.style.maxHeight = '';
    }

    /** ارفع القائمة الجانبية الطويلة لتبقى داخل الشاشة مع تمرير داخلي */
    function placeFlyInView(fly) {
      if (!fly) return;
      fly.style.top = '0px';
      window.requestAnimationFrame(function () {
        var pad = 12;
        var vh = window.innerHeight || 600;
        var maxH = Math.min(Math.floor(vh * 0.58), vh - pad * 2);
        fly.style.maxHeight = Math.max(160, maxH) + 'px';

        var rect = fly.getBoundingClientRect();
        var overflowBottom = rect.bottom - (vh - pad);
        if (overflowBottom > 0) {
          var nextTop = -overflowBottom;
          var newTopEdge = rect.top - overflowBottom;
          if (newTopEdge < pad) nextTop += pad - newTopEdge;
          fly.style.top = Math.round(nextTop) + 'px';
          rect = fly.getBoundingClientRect();
        }
        var space = vh - pad - rect.top;
        if (space > 0 && space < maxH) {
          fly.style.maxHeight = Math.max(160, Math.floor(space)) + 'px';
        }
      });
    }

    function closeMenus(except) {
      root.querySelectorAll('.hx-nav-item.is-open').forEach(function (el) {
        if (el === except) return;
        el.classList.remove('is-open');
        var trigger = el.querySelector('[data-nav-menu]');
        if (trigger) trigger.setAttribute('aria-expanded', 'false');
      });
      root.querySelectorAll('.hx-nav-sub.is-open').forEach(function (el) {
        if (except && except.contains(el)) return;
        el.classList.remove('is-open');
        resetFlyPlace(el.querySelector('.hx-nav-fly'));
      });
    }

    function setItemOpen(item, open) {
      if (!item) return;
      item.classList.toggle('is-open', open);
      var trigger = item.querySelector('[data-nav-menu]');
      if (trigger) trigger.setAttribute('aria-expanded', String(open));
      if (!open) {
        item.querySelectorAll('.hx-nav-fly').forEach(resetFlyPlace);
      }
    }

    root.querySelectorAll('.hx-nav-item').forEach(function (item) {
      item.addEventListener('mouseenter', function () {
        closeMenus(item);
        setItemOpen(item, true);
      });
      item.addEventListener('mouseleave', function () {
        setItemOpen(item, false);
      });
    });

    root.querySelectorAll('[data-nav-menu]').forEach(function (btn) {
      btn.addEventListener('click', function (e) {
        e.preventDefault();
        e.stopPropagation();
        var item = btn.closest('.hx-nav-item');
        if (!item) return;
        var open = item.classList.contains('is-open');
        closeMenus(open ? null : item);
        setItemOpen(item, !open);
      });
    });

    root.querySelectorAll('.hx-nav-sub').forEach(function (sub) {
      var subBtn = sub.querySelector('.hx-nav-drop__item--has-sub');
      var fly = sub.querySelector('.hx-nav-fly');
      if (!subBtn || !fly) return;

      function openFly() {
        root.querySelectorAll('.hx-nav-sub.is-open').forEach(function (other) {
          if (other !== sub && !other.contains(sub)) {
            other.classList.remove('is-open');
            resetFlyPlace(other.querySelector('.hx-nav-fly'));
          }
        });
        sub.classList.add('is-open');
        placeFlyInView(fly);
      }

      sub.addEventListener('mouseenter', openFly);
      subBtn.addEventListener('click', function (e) {
        e.preventDefault();
        e.stopPropagation();
        var open = sub.classList.contains('is-open');
        root.querySelectorAll('.hx-nav-sub.is-open').forEach(function (other) {
          if (other !== sub && !other.contains(sub)) {
            other.classList.remove('is-open');
            resetFlyPlace(other.querySelector('.hx-nav-fly'));
          }
        });
        if (open) {
          sub.classList.remove('is-open');
          resetFlyPlace(fly);
        } else {
          openFly();
        }
      });
    });

    document.addEventListener('click', function (e) {
      if (!e.target.closest('.hx-nav-item')) closeMenus();
    });
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') closeMenus();
    });
    window.addEventListener('resize', function () {
      var openFly = root.querySelector('.hx-nav-sub.is-open > .hx-nav-fly');
      if (openFly) placeFlyInView(openFly);
    });
  }

  /** تمييز القسم النشط من المسار الحالي (أو المسار الحقيقي عند إخفاء الروابط) */
  var base = typeof window.__HYPEX_BASE__ === 'string' ? window.__HYPEX_BASE__ : '';
  if (base && base.charAt(base.length - 1) === '/') base = base.slice(0, -1);
  var path =
    typeof window.__HYPEX_PATH__ === 'string' && window.__HYPEX_PATH__
      ? window.__HYPEX_PATH__
      : window.location.pathname || '';
  if (
    !(typeof window.__HYPEX_PATH__ === 'string' && window.__HYPEX_PATH__) &&
    base &&
    (path === base || path.indexOf(base + '/') === 0)
  ) {
    path = path.slice(base.length) || '/';
  }
  var sidebar = root || document.querySelector('.sidebar--2027');
  if (!sidebar || !path) return;

  var PREFIXES = {
    main: ['/app'],
    sales: ['/hub/sales', '/sales'],
    customers: ['/hub/customers', '/customers'],
    suppliers: ['/hub/suppliers', '/suppliers'],
    sales_reps: ['/hub/sales-reps', '/sales-reps'],
    purchases: ['/hub/purchases', '/purchases'],
    inventory: ['/hub/inventory', '/inventory'],
    accounting: ['/hub/accounting', '/accounting'],
    hr: ['/hub/hr', '/hr'],
    system: ['/hub/system', '/system', '/mobile'],
    mobile: ['/mobile'],
    favorites: ['/hub/favorites'],
    backup: ['/system/backup'],
  };

  function matchDomain(id, href) {
    if (href === '/app') return path === '/' || path === '/app';
    if (id === 'backup') return path.indexOf('backup') !== -1;
    if (id === 'system' && path.indexOf('/system/backup') === 0) return false;
    var list = PREFIXES[id] || [];
    for (var i = 0; i < list.length; i++) {
      var p = list[i];
      if (path === p || path.indexOf(p + '/') === 0) return true;
    }
    if (href && (path === href || path.indexOf(href + '/') === 0)) return true;
    return false;
  }

  sidebar.querySelectorAll('.nav-domain-link').forEach(function (a) {
    a.classList.toggle(
      'is-active',
      matchDomain(a.getAttribute('data-domain') || '', a.getAttribute('data-nav-path') || a.getAttribute('href') || '')
    );
  });
})();
