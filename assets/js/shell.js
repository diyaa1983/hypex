(function () {
  'use strict';

  document.querySelectorAll('[data-toggle-domain]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var section = btn.closest('.nav-domain');
      if (section) section.classList.toggle('is-open');
    });
  });

  var root = document.querySelector('.hx-topnav');
  if (root) {
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
      });
    }

    function setItemOpen(item, open) {
      if (!item) return;
      item.classList.toggle('is-open', open);
      var trigger = item.querySelector('[data-nav-menu]');
      if (trigger) trigger.setAttribute('aria-expanded', String(open));
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
      if (!subBtn) return;
      subBtn.addEventListener('click', function (e) {
        e.preventDefault();
        e.stopPropagation();
        var open = sub.classList.contains('is-open');
        root.querySelectorAll('.hx-nav-sub.is-open').forEach(function (other) {
          if (other !== sub && !other.contains(sub)) other.classList.remove('is-open');
        });
        sub.classList.toggle('is-open', !open);
      });
    });

    document.addEventListener('click', function (e) {
      if (!e.target.closest('.hx-nav-item')) closeMenus();
    });
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') closeMenus();
    });
  }

  /** تمييز القسم النشط من المسار الحالي */
  var base = typeof window.__HYPEX_BASE__ === 'string' ? window.__HYPEX_BASE__ : '';
  if (base && base.charAt(base.length - 1) === '/') base = base.slice(0, -1);
  var path = window.location.pathname || '';
  if (base && (path === base || path.indexOf(base + '/') === 0)) {
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
