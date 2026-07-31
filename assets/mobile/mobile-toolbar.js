/**
 * شريط الأدوات السفلي الموحّد لكل شاشات الموبايل — ظاهر دائماً؛ الأزرار حسب الشاشة.
 */
(function (global) {
  'use strict';

  var ROOT_ID = 'm-main-toolbar';
  var NAMES = ['save', 'open', 'edit', 'delete', 'run', 'print', 'pdf', 'post', 'einvoice', 'camera', 'archive'];

  function root() {
    return document.getElementById(ROOT_ID);
  }

  function btn(name) {
    return document.getElementById('m-toolbar-' + name);
  }

  function titleEl() {
    return document.getElementById('m-toolbar-title');
  }

  function actionsEl() {
    return document.getElementById('m-toolbar-actions');
  }

  function visibleButtonCount() {
    var cols = 0;
    NAMES.forEach(function (n) {
      var b = btn(n);
      if (b && !b.hidden) cols++;
    });
    return cols;
  }

  function syncDockHeight() {
    var dock = document.getElementById('m-bottom-dock');
    if (!dock || dock.hidden) return;
    var h = Math.ceil(dock.getBoundingClientRect().height);
    if (h > 0) {
      document.documentElement.style.setProperty('--m-action-dock-h', h + 'px');
    }
  }

  function refresh() {
    var bar = root();
    if (!bar) return;
    bar.hidden = false;
    var cols = visibleButtonCount();
    var t = titleEl();
    var hasTitle = !!(t && !t.hidden && (t.textContent || '').trim() !== '');
    document.body.classList.add('m-has-action-dock');
    document.body.classList.toggle('m-action-dock--titled', hasTitle);
    document.body.classList.toggle('m-action-dock--empty', cols === 0 && !hasTitle);
    document.body.classList.toggle('m-action-dock--multiline', cols > 4);
    var actions = actionsEl();
    if (actions) {
      actions.classList.toggle('m-action-dock-actions--empty', cols === 0);
      applyActionCols(actions, cols);
    }
    requestAnimationFrame(syncDockHeight);
  }

  function applyActionCols(actions, cols) {
    if (!actions) return;
    var i;
    for (i = 1; i <= 10; i++) {
      actions.classList.remove('m-action-dock-actions--' + i);
    }
    actions.classList.remove('m-action-dock-actions--many');
    if (cols === 1) {
      actions.classList.add('m-action-dock-actions--1');
      actions.style.removeProperty('--m-action-cols');
    } else if (cols >= 2 && cols <= 10) {
      actions.classList.add('m-action-dock-actions--' + cols);
      actions.style.removeProperty('--m-action-cols');
    } else if (cols > 10) {
      actions.classList.add('m-action-dock-actions--many');
      actions.style.setProperty('--m-action-cols', String(cols));
    } else {
      actions.style.removeProperty('--m-action-cols');
    }
  }

  function hideAll() {
    NAMES.forEach(function (n) {
      var b = btn(n);
      if (b) {
        b.hidden = true;
        b.disabled = false;
      }
    });
    var t = titleEl();
    if (t) {
      t.hidden = true;
      t.textContent = '';
    }
    var s = btn('save');
    if (s) s.removeAttribute('form');
    refresh();
  }

  function ensureVisible() {
    refresh();
  }

  /**
   * @param {Record<string, boolean>} visible
   * @param {{ title?: string, formId?: string, cols?: number, disabled?: Record<string, boolean> }} [opts]
   */
  function show(visible, opts) {
    opts = opts || {};
    hideAll();
    Object.keys(visible || {}).forEach(function (key) {
      // إخفاء زر PDF في كل الشاشات — الطباعة فقط.
      if (key === 'pdf') return;
      var b = btn(key);
      if (b && visible[key]) b.hidden = false;
    });
    var t = titleEl();
    if (t && opts.title) {
      t.textContent = opts.title;
      t.hidden = false;
    }
    var s = btn('save');
    if (s && opts.formId) {
      s.setAttribute('form', opts.formId);
      s.type = 'submit';
      s.disabled = !!(opts.disabled && opts.disabled.save);
    } else if (s) {
      s.type = 'button';
      s.removeAttribute('form');
    }
    if (opts.disabled) {
      Object.keys(opts.disabled).forEach(function (key) {
        var b = btn(key);
        if (b && visible[key]) b.disabled = !!opts.disabled[key];
      });
    }
    var actions = actionsEl();
    if (actions) {
      var cols = opts.cols;
      if (!cols) cols = visibleButtonCount();
      applyActionCols(actions, cols);
    }
    refresh();
  }

  function applyRouteDefaults(route) {
    var cfg = global.MobileToolbarRoutes;
    if (!cfg || !cfg[route]) {
      ensureVisible();
      return;
    }
    var def = cfg[route];
    if (def.skip) {
      return;
    }
    if (def.title && (!def.buttons || !Object.keys(def.buttons).length)) {
      show({}, { title: def.title });
      return;
    }
    if (def.buttons) {
      show(def.buttons, { title: def.title, cols: def.cols, formId: def.formId });
      return;
    }
    ensureVisible();
  }

  function resetRoute() {
    var route = global.AppMobile && AppMobile.activeRoute;
    applyRouteDefaults(route || '');
  }

  function pinToBottomDock() {
    var toolbar = root();
    var slot = document.getElementById('m-action-dock-slot');
    if (!toolbar || !slot) return false;
    if (toolbar.parentNode !== slot) {
      slot.appendChild(toolbar);
    }
    var dock = document.getElementById('m-bottom-dock');
    if (dock) {
      dock.classList.remove('m-bottom-dock--toolbar-inline');
    }
    document.body.classList.remove('m-inv-actions-inline');
    refresh();
    return true;
  }

  function mountInto(hostId) {
    var host = typeof hostId === 'string' ? document.getElementById(hostId) : hostId;
    var toolbar = root();
    if (!host || !toolbar) return false;
    if (
      host.classList &&
      (host.classList.contains('m-inv-fixed-actions') || host.classList.contains('m-inv-doc-actions--fixed')) &&
      host.parentNode !== document.body
    ) {
      document.body.appendChild(host);
    }
    host.appendChild(toolbar);
    var dock = document.getElementById('m-bottom-dock');
    if (dock) {
      dock.classList.add('m-bottom-dock--toolbar-inline');
    }
    document.body.classList.add('m-inv-actions-inline');
    refresh();
    return true;
  }

  global.MobileToolbar = {
    root: root,
    btn: btn,
    titleEl: titleEl,
    show: show,
    hideAll: hideAll,
    refresh: refresh,
    syncDockHeight: syncDockHeight,
    ensureVisible: ensureVisible,
    applyRouteDefaults: applyRouteDefaults,
    resetRoute: resetRoute,
    mountInto: mountInto,
    pinToBottomDock: pinToBottomDock,
  };

  window.addEventListener('resize', syncDockHeight);
  window.addEventListener('orientationchange', function () {
    setTimeout(syncDockHeight, 120);
  });
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', syncDockHeight);
  } else {
    syncDockHeight();
  }
})(typeof window !== 'undefined' ? window : this);
