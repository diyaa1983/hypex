/**
 * شريط الأدوات السفلي الموحّد لكل شاشات الموبايل — ظاهر دائماً؛ الأزرار حسب الشاشة.
 */
(function (global) {
  'use strict';

  var ROOT_ID = 'm-main-toolbar';
  var NAMES = ['save', 'open', 'edit', 'delete', 'run', 'print', 'pdf', 'post', 'einvoice'];

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
    var actions = actionsEl();
    if (actions) {
      actions.classList.toggle('m-action-dock-actions--empty', cols === 0);
      actions.classList.remove(
        'm-action-dock-actions--1',
        'm-action-dock-actions--2',
        'm-action-dock-actions--3',
        'm-action-dock-actions--4',
        'm-action-dock-actions--5'
      );
      if (cols === 1) {
        actions.classList.add('m-action-dock-actions--1');
      } else if (cols >= 2 && cols <= 5) {
        actions.classList.add('m-action-dock-actions--' + cols);
      }
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
   * @param {{ title?: string, formId?: string, cols?: number }} [opts]
   */
  function show(visible, opts) {
    opts = opts || {};
    hideAll();
    Object.keys(visible || {}).forEach(function (key) {
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
    } else if (s) {
      s.type = 'button';
      s.removeAttribute('form');
    }
    var actions = actionsEl();
    if (actions) {
      actions.classList.remove(
        'm-action-dock-actions--1',
        'm-action-dock-actions--2',
        'm-action-dock-actions--3',
        'm-action-dock-actions--4',
        'm-action-dock-actions--5'
      );
      var cols = opts.cols;
      if (!cols) cols = visibleButtonCount();
      if (cols === 1) {
        actions.classList.add('m-action-dock-actions--1');
      } else if (cols >= 2 && cols <= 5) {
        actions.classList.add('m-action-dock-actions--' + cols);
      }
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

  global.MobileToolbar = {
    root: root,
    btn: btn,
    titleEl: titleEl,
    show: show,
    hideAll: hideAll,
    refresh: refresh,
    ensureVisible: ensureVisible,
    applyRouteDefaults: applyRouteDefaults,
    resetRoute: resetRoute,
  };
})(typeof window !== 'undefined' ? window : this);
