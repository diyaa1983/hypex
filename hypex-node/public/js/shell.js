(function () {
  'use strict';

  document.querySelectorAll('[data-toggle-domain]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var section = btn.closest('.nav-domain');
      if (section) section.classList.toggle('is-open');
    });
  });

  /** تمييز القسم النشط من المسار الحالي */
  var base = typeof window.__HYPEX_BASE__ === 'string' ? window.__HYPEX_BASE__ : '';
  if (base && base.charAt(base.length - 1) === '/') base = base.slice(0, -1);
  var path = window.location.pathname || '';
  if (base && (path === base || path.indexOf(base + '/') === 0)) {
    path = path.slice(base.length) || '/';
  }
  var sidebar = document.querySelector('.sidebar--2027');
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
    system: ['/hub/system', '/system'],
    mobile: ['/hub/mobile', '/mobile'],
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
