(function (global) {
  'use strict';

  if (global.APP_EMBED) {
    return;
  }

  var config = global.AppMdiConfig || { routes: {}, excludeRoutes: [], baseUrl: 'index.php' };
  var windows = [];
  var panels = {};
  var activeId = null;
  var activePanelId = null;
  var nextZ = 1200;
  var windowSeq = 0;
  var layerEl = null;
  var taskbarEl = null;
  var taskbarWindowsEl = null;
  var taskbarEventsBound = false;
  var persistTimer = null;
  var hubOverlayEl = null;
  var hubFrameEl = null;
  var hubOverlayUrl = '';
  var parkedLiveId = null;
  var prefetchedUrls = {};

  function esc(text) {
    var d = document.createElement('div');
    d.textContent = text == null ? '' : String(text);
    return d.innerHTML;
  }

  function parseRouteFromHref(href) {
    try {
      var u = new URL(href, global.location.origin);
      return u.searchParams.get('r') || '';
    } catch (e) {
      return '';
    }
  }

  function normalizeKey(href) {
    try {
      var u = new URL(href, global.location.origin);
      u.searchParams.delete('embed');
      u.searchParams.delete('mdi_id');
      var parts = [];
      u.searchParams.forEach(function (val, key) {
        parts.push(key + '=' + val);
      });
      parts.sort();
      return u.pathname + '?' + parts.join('&');
    } catch (e) {
      return String(href || '');
    }
  }

  function buildEmbedUrl(href, mdiId) {
    var u = new URL(href, global.location.origin);
    u.searchParams.set('embed', '1');
    u.searchParams.delete('mdi_id');
    if (mdiId) {
      u.searchParams.set('mdi_id', mdiId);
    }
    return u.pathname + u.search + u.hash;
  }

  var STORAGE_KEY = 'manager:mdi-windows-v1';

  function loadStoredWindows() {
    try {
      var raw = sessionStorage.getItem(STORAGE_KEY);
      if (!raw) {
        return [];
      }
      var data = JSON.parse(raw);
      return Array.isArray(data) ? data : [];
    } catch (e) {
      return [];
    }
  }

  function persistWindowsNow() {
    var seen = {};
    var data = [];
    windows.forEach(function (w) {
      var key = w.key || normalizeKey(w.href);
      if (seen[key]) {
        return;
      }
      seen[key] = true;
      data.push({
        id: w.id,
        key: key,
        href: w.href,
        title: w.title,
        route: w.route,
        minimized: w.minimized,
        maximized: w.maximized,
        fullPage: !!w.fullPage,
        unsaved: !!w.unsaved,
      });
    });
    try {
      sessionStorage.setItem(STORAGE_KEY, JSON.stringify(data));
    } catch (e) {
      /* ignore */
    }
  }

  function persistWindows() {
    if (persistTimer) {
      clearTimeout(persistTimer);
    }
    persistTimer = setTimeout(function () {
      persistTimer = null;
      persistWindowsNow();
    }, 120);
  }

  function isRouteExcluded(route) {
    var list = config.excludeRoutes || [];
    for (var i = 0; i < list.length; i++) {
      if (list[i] === route) {
        return true;
      }
    }
    return false;
  }

  function routeTitle(route, fallback) {
    if (config.routes && config.routes[route] && config.routes[route].title) {
      return config.routes[route].title;
    }
    return fallback || route || 'شاشة';
  }

  function linkLabel(anchor, route) {
    var custom = anchor.getAttribute('data-mdi-title');
    if (custom) {
      return custom.replace(/\s+/g, ' ').trim();
    }
    var text = (anchor.textContent || '').replace(/\s+/g, ' ').trim();
    if (text && text.length > 1 && !/^[\u2190-\u27BF\uE000-\uF8FF\u2600-\u26FF\u2700-\u27BF\s]+$/.test(text)) {
      return text;
    }
    return routeTitle(route, route);
  }

  function titleFromHref(href) {
    var route = parseRouteFromHref(href);
    var base = routeTitle(route, route);
    try {
      var u = new URL(href, global.location.origin);
      var id = u.searchParams.get('id');
      if (id) {
        return base + ' #' + id;
      }
    } catch (e) {
      /* ignore */
    }
    return base;
  }

  function isMdiLink(anchor) {
    if (!anchor || anchor.tagName !== 'A') {
      return false;
    }
    if (anchor.target === '_blank' || anchor.hasAttribute('download')) {
      return false;
    }
    if (anchor.closest && anchor.closest('.app-mdi-window, .fin-voucher-archive-modal, .ui-dialog-root')) {
      return false;
    }
    var href = anchor.getAttribute('href') || '';
    if (!href || href.charAt(0) === '#') {
      return false;
    }
    if (href.indexOf('logout.php') >= 0) {
      return false;
    }
    if (href.indexOf('index.php') < 0 && href.indexOf('?r=') < 0) {
      return false;
    }
    try {
      var u = new URL(anchor.href, global.location.origin);
      if (u.origin !== global.location.origin) {
        return false;
      }
    } catch (e) {
      return false;
    }
    var route = parseRouteFromHref(anchor.href);
    if (!route || isRouteExcluded(route)) {
      return false;
    }
    return true;
  }

  function ensureShell() {
    if (layerEl) {
      return;
    }
    layerEl = document.getElementById('app-mdi-layer');
    taskbarEl = document.getElementById('app-mdi-taskbar');
    hubOverlayEl = document.getElementById('app-mdi-hub-overlay');
    hubFrameEl = document.getElementById('app-mdi-hub-frame');
    if (!layerEl || !taskbarEl) {
      return;
    }
    taskbarWindowsEl = taskbarEl.querySelector('.app-mdi-taskbar-windows');
    layerEl.setAttribute('aria-hidden', 'false');
    bindTaskbarEventsOnce();
  }

  function buildHubEmbedUrl(href) {
    try {
      var u = new URL(href, global.location.origin);
      u.searchParams.set('embed', 'menu');
      u.searchParams.delete('mdi_id');
      return u.pathname + u.search + u.hash;
    } catch (e) {
      return href;
    }
  }

  function defaultHubUrl() {
    return config.afterMinimizeUrl || (config.baseUrl || 'index.php') + '?r=dashboard';
  }

  function showHubOverlay(url) {
    ensureShell();
    if (!hubOverlayEl || !hubFrameEl) {
      return;
    }
    ensureHubFrameGuard();
    url = buildHubEmbedUrl(url || defaultHubUrl());
    if (hubOverlayUrl !== url) {
      hubFrameEl.src = url;
      hubOverlayUrl = url;
    }
    hubOverlayEl.hidden = false;
    hubOverlayEl.setAttribute('aria-hidden', 'false');
    document.body.classList.add('app-mdi-page-parked');
  }

  function hideHubOverlay() {
    if (!hubOverlayEl) {
      document.body.classList.remove('app-mdi-page-parked');
      return;
    }
    hubOverlayEl.hidden = true;
    hubOverlayEl.setAttribute('aria-hidden', 'true');
    document.body.classList.remove('app-mdi-page-parked');
  }

  function isParkedLiveWindow(win) {
    return !!(win && win.parkedLive && normalizeKey(win.href) === normalizeKey(global.location.href));
  }

  function unparkLiveWindow(win) {
    if (!win) {
      return;
    }
    win.minimized = false;
    win.parkedLive = false;
    win.fullPage = true;
    activeId = win.id;
    if (parkedLiveId === win.id) {
      parkedLiveId = null;
    }
    hideHubOverlay();
    persistWindowsNow();
    syncTaskbar();
  }

  function parkLivePage(win, hubUrl) {
    if (!win) {
      return;
    }
    win.minimized = true;
    win.fullPage = true;
    win.parkedLive = true;
    parkedLiveId = win.id;
    showHubOverlay(hubUrl);
    syncTaskbar();
  }

  function releaseParkedLiveForNavigation() {
    var win = parkedLiveId ? findWindow(parkedLiveId) : null;
    if (!win || !win.parkedLive) {
      return;
    }
    win.parkedLive = false;
    win.fullPage = true;
    parkedLiveId = null;
    hideHubOverlay();
    persistWindowsNow();
  }

  function stripEmbedParams(href) {
    try {
      var u = new URL(href, global.location.origin);
      u.searchParams.delete('embed');
      u.searchParams.delete('mdi_id');
      return u.pathname + u.search + u.hash;
    } catch (e) {
      return href;
    }
  }

  function navigateFromHubParent(href) {
    if (!href) {
      return;
    }
    releaseParkedLiveForNavigation();
    hideHubOverlay();
    parkedLiveId = null;
    document.body.classList.remove('app-mdi-page-parked');
    global.__managerAllowUnload = true;
    global.location.href = stripEmbedParams(href);
  }

  function findCurrentFullPageWindow() {
    return findByKey(normalizeKey(global.location.href));
  }

  function hasCurrentFullPageWindow() {
    var win = findCurrentFullPageWindow();
    return !!(win && win.fullPage);
  }

  function removeFullPageWindowRecord(win) {
    if (!win) {
      return false;
    }
    var idx = -1;
    for (var i = 0; i < windows.length; i++) {
      if (windows[i].id === win.id) {
        idx = i;
        break;
      }
    }
    if (idx < 0) {
      return false;
    }
    if (win.parkedLive && parkedLiveId === win.id) {
      parkedLiveId = null;
    }
    if (win.el && win.el.parentNode) {
      win.el.parentNode.removeChild(win.el);
    }
    windows.splice(idx, 1);
    if (activeId === win.id) {
      activeId = null;
    }
    hideHubOverlay();
    document.body.classList.remove('app-mdi-page-parked');
    persistWindowsNow();
    syncTaskbar();
    return true;
  }

  function exitCurrentPage(href) {
    if (!href) {
      href = defaultHubUrl();
    }
    removeFullPageWindowRecord(findCurrentFullPageWindow());
    global.__managerAllowUnload = true;
    href = stripEmbedParams(href);
    if (global.APP_EMBED && global.parent && global.parent !== global) {
      global.parent.postMessage({ type: 'manager:mdi-parent-nav', href: href }, global.location.origin);
      return;
    }
    global.location.href = href;
  }

  function handleHubParentMessage(e) {
    if (!e.data || e.origin !== global.location.origin) {
      return;
    }
    if (e.data.type === 'manager:mdi-parent-nav' && e.data.href) {
      navigateFromHubParent(e.data.href);
    }
  }

  function isWindowCurrentPage(win) {
    return !!(win && normalizeKey(win.href) === normalizeKey(global.location.href));
  }

  function isTaskbarWindowActive(win) {
    if (!win || win.minimized) {
      return false;
    }
    if (win.parkedLive && isParkedLiveWindow(win)) {
      return true;
    }
    if (win.fullPage && isWindowCurrentPage(win)) {
      return true;
    }
    if (win.el && win.id === activeId) {
      return true;
    }
    return false;
  }

  function taskbarWindowClick(id) {
    var win = findWindow(id);
    if (!win) {
      return;
    }
    activePanelId = null;
    if (win.minimized) {
      restoreWindow(id);
      return;
    }
    if (isTaskbarWindowActive(win)) {
      if (isWindowCurrentPage(win)) {
        minimizeThisPage(true);
      } else {
        win.minimized = true;
        syncTaskbar();
        persistWindowsNow();
      }
      return;
    }
    restoreWindow(id);
  }

  function reconcileParkStateOnLoad() {
    hideHubOverlay();
    parkedLiveId = null;
    document.body.classList.remove('app-mdi-page-parked');

    var route = parseRouteFromHref(global.location.href);
    if (route === 'menu_hub' || route === 'dashboard') {
      syncTaskbar();
      return;
    }

    var matched = findByKey(normalizeKey(global.location.href));
    if (matched) {
      matched.minimized = false;
      matched.parkedLive = false;
      matched.fullPage = true;
      activeId = matched.id;
      persistWindowsNow();
    }
    syncTaskbar();
  }

  function prefetchHref(href) {
    if (!href) {
      return;
    }
    var key = normalizeKey(href);
    if (prefetchedUrls[key]) {
      return;
    }
    prefetchedUrls[key] = true;
    var link = document.createElement('link');
    link.rel = 'prefetch';
    link.href = href;
    document.head.appendChild(link);
  }

  function stripIframeMdiChrome(doc) {
    if (!doc) {
      return;
    }
    ['app-mdi-taskbar', 'app-mdi-layer', 'app-mdi-hub-overlay'].forEach(function (id) {
      var el = doc.getElementById(id);
      if (el && el.parentNode) {
        el.parentNode.removeChild(el);
      }
    });
    if (doc.body) {
      doc.body.classList.remove('app-mdi-taskbar-open', 'app-mdi-page-parked');
    }
  }

  function enforceHubFrameEmbed() {
    if (!hubFrameEl || !hubFrameEl.contentWindow) {
      return;
    }
    try {
      var cw = hubFrameEl.contentWindow;
      var doc = cw.document;
      stripIframeMdiChrome(doc);
      var u = new URL(cw.location.href);
      if (u.origin !== global.location.origin) {
        return;
      }
      var route = u.searchParams.get('r') || '';
      if (route !== '' && route !== 'menu_hub' && route !== 'dashboard') {
        navigateFromHubParent(cw.location.href);
        return;
      }
      if (u.searchParams.get('embed') === 'menu') {
        return;
      }
      u.searchParams.set('embed', 'menu');
      u.searchParams.delete('mdi_id');
      hubFrameEl.src = u.pathname + u.search + u.hash;
    } catch (e) {
      /* ignore */
    }
  }

  function ensureHubFrameGuard() {
    if (!hubFrameEl || hubFrameEl.getAttribute('data-mdi-guard') === '1') {
      return;
    }
    hubFrameEl.setAttribute('data-mdi-guard', '1');
    hubFrameEl.addEventListener('load', enforceHubFrameEmbed);
  }

  function bindTaskbarEventsOnce() {
    if (taskbarEventsBound || !taskbarWindowsEl) {
      return;
    }
    taskbarEventsBound = true;
    taskbarWindowsEl.addEventListener(
      'mouseover',
      function (e) {
        var taskBtn = e.target.closest('[data-mdi-id]');
        if (!taskBtn) {
          return;
        }
        var win = findWindow(taskBtn.getAttribute('data-mdi-id'));
        if (win && win.minimized && win.fullPage && !win.parkedLive && win.href) {
          prefetchHref(win.href);
        }
      },
      true
    );
    taskbarWindowsEl.addEventListener('click', function (e) {
      var closeBtn = e.target.closest('[data-mdi-close]');
      if (closeBtn) {
        e.stopPropagation();
        closeWindow(closeBtn.getAttribute('data-mdi-close'));
        return;
      }
      var minBtn = e.target.closest('[data-mdi-minimize]');
      if (minBtn) {
        e.stopPropagation();
        minimizeWindow(minBtn.getAttribute('data-mdi-minimize'));
        return;
      }
      var panelCloseBtn = e.target.closest('[data-mdi-panel-close]');
      if (panelCloseBtn) {
        e.stopPropagation();
        var panelCloseId = panelCloseBtn.getAttribute('data-mdi-panel-close');
        var panelClose = panels[panelCloseId];
        if (panelClose && typeof panelClose.onClose === 'function') {
          panelClose.onClose();
        } else {
          removePanel(panelCloseId);
        }
        return;
      }
      var panelBtn = e.target.closest('[data-mdi-panel]');
      if (panelBtn) {
        var panelId = panelBtn.getAttribute('data-mdi-panel');
        var panel = panels[panelId];
        if (!panel || typeof panel.onActivate !== 'function') {
          return;
        }
        activePanelId = panelId;
        activeId = null;
        panel.onActivate();
        syncTaskbar();
        return;
      }
      var taskBtn = e.target.closest('[data-mdi-id]');
      if (!taskBtn) {
        return;
      }
      var id = taskBtn.getAttribute('data-mdi-id');
      taskbarWindowClick(id);
    });
  }

  function panelIds() {
    return Object.keys(panels);
  }

  function findWindow(id) {
    for (var i = 0; i < windows.length; i++) {
      if (windows[i].id === id) {
        return windows[i];
      }
    }
    return null;
  }

  function findByKey(key) {
    for (var i = 0; i < windows.length; i++) {
      if (windows[i].key === key) {
        return windows[i];
      }
    }
    return null;
  }

  function hasTaskbarItems() {
    return windows.length > 0 || panelIds().length > 0;
  }

  function updateShellState() {
    var hasVisible = windows.some(function (w) {
      return !w.minimized && w.el;
    });
    var hasMaximized = windows.some(function (w) {
      return !w.minimized && w.maximized && w.el;
    });
    document.body.classList.toggle('app-mdi-taskbar-open', hasTaskbarItems());
    if (taskbarEl) {
      taskbarEl.hidden = !hasTaskbarItems();
    }
    if (layerEl) {
      layerEl.classList.toggle('app-mdi-layer--active', hasVisible);
      layerEl.classList.toggle('app-mdi-layer--maximized', hasMaximized);
    }
  }

  function pruneDuplicateWindows() {
    var seen = {};
    var unique = [];
    windows.forEach(function (w) {
      var key = w.key || normalizeKey(w.href);
      if (seen[key]) {
        if (w.el && w.el.parentNode) {
          w.el.parentNode.removeChild(w.el);
        }
        if (parkedLiveId === w.id) {
          parkedLiveId = seen[key].id;
        }
        return;
      }
      seen[key] = w;
      unique.push(w);
    });
    windows = unique;
  }

  function syncTaskbar() {
    ensureShell();
    if (!taskbarWindowsEl) {
      return;
    }
    pruneDuplicateWindows();
    taskbarWindowsEl.innerHTML = '';

    windows.forEach(function (win) {
      var wrap = document.createElement('div');
      wrap.className = 'app-mdi-task-item';
      if (isTaskbarWindowActive(win)) {
        wrap.classList.add('is-active');
      }
      if (win.minimized) {
        wrap.classList.add('is-minimized');
      }
      wrap.innerHTML =
        '<button type="button" class="app-mdi-task-btn" data-mdi-id="' +
        esc(win.id) +
        '" title="' +
        esc(win.title) +
        '">' +
        '<span class="app-mdi-task-label">' +
        esc(win.title) +
        '</span>' +
        '</button>' +
        '<button type="button" class="app-mdi-task-close" data-mdi-close="' +
        esc(win.id) +
        '" aria-label="إغلاق">×</button>' +
        (!win.minimized
          ? '<button type="button" class="app-mdi-task-minimize" data-mdi-minimize="' +
            esc(win.id) +
            '" aria-label="تصغير" title="تصغير الشاشة">_</button>'
          : '');
      taskbarWindowsEl.appendChild(wrap);
    });

    panelIds().forEach(function (panelId) {
      var panel = panels[panelId];
      if (!panel) {
        return;
      }
      var wrap = document.createElement('div');
      wrap.className = 'app-mdi-task-item app-mdi-task-item--panel';
      if (panelId === activePanelId && !panel.minimized) {
        wrap.classList.add('is-active');
      }
      if (panel.minimized !== false) {
        wrap.classList.add('is-minimized');
      }
      wrap.innerHTML =
        '<button type="button" class="app-mdi-task-btn" data-mdi-panel="' +
        esc(panelId) +
        '" title="' +
        esc(panel.title || panelId) +
        '">' +
        '<span class="app-mdi-task-label">' +
        esc(panel.title || panelId) +
        '</span>' +
        '</button>' +
        '<button type="button" class="app-mdi-task-close" data-mdi-panel-close="' +
        esc(panelId) +
        '" aria-label="إغلاق">×</button>';
      taskbarWindowsEl.appendChild(wrap);
    });

    updateShellState();
    persistWindows();
  }

  function currentPageTitle() {
    if (config.currentTitle) {
      return String(config.currentTitle);
    }
    var el = document.querySelector(
      '.dashboard-ora-screen-title__text, .report-ora12-screen-title__text, .app-screen-title-bar h1'
    );
    if (el) {
      return el.textContent.replace(/\s+/g, ' ').trim();
    }
    return titleFromHref(global.location.href);
  }

  function registerFullPageWindow(spec) {
    var key = spec.key || normalizeKey(spec.href);
    var existing = findByKey(key);
    if (existing) {
      if (spec.unsaved) {
        existing.unsaved = true;
      }
      if (spec.title) {
        existing.title = spec.title;
      }
      return existing.id;
    }
    windowSeq += 1;
    var id = spec.id || 'app-mdi-' + windowSeq;
    if (/^app-mdi-(\d+)$/.test(id)) {
      var n = parseInt(id.replace('app-mdi-', ''), 10);
      if (n > windowSeq) {
        windowSeq = n;
      }
    }
    var win = {
      id: id,
      key: spec.key || normalizeKey(spec.href),
      href: spec.href,
      route: spec.route || parseRouteFromHref(spec.href),
      title: spec.title || titleFromHref(spec.href),
      minimized: true,
      maximized: false,
      fullPage: true,
      unsaved: !!spec.unsaved,
      el: null,
    };
    windows.push(win);
    syncTaskbar();
    return id;
  }

  function registerWindow(spec) {
    spec = spec || {};
    spec.fullPage = true;
    if (spec.minimized !== false) {
      spec.minimized = true;
    }
    return registerFullPageWindow(spec);
  }

  function convertLegacyIframeWindows() {
    var changed = false;
    windows.forEach(function (w) {
      if (w.el && w.el.parentNode) {
        w.el.parentNode.removeChild(w.el);
        changed = true;
      }
      if (w.el || !w.fullPage || w.maximized) {
        changed = true;
      }
      w.el = null;
      w.fullPage = true;
      w.maximized = false;
      w.parkedLive = false;
    });
    if (changed) {
      persistWindowsNow();
    }
  }

  function ensureIframeLoaded(win) {
    if (!win || !win.el) {
      return;
    }
    var frame = win.el.querySelector('.app-mdi-frame');
    if (!frame) {
      return;
    }
    var pending = frame.getAttribute('data-src');
    if (pending && !frame.getAttribute('src')) {
      frame.setAttribute('src', pending);
      frame.removeAttribute('data-src');
    }
  }

  function mountWindow(spec, focusOnOpen) {
    ensureShell();
    if (!layerEl) {
      return null;
    }

    windowSeq += 1;
    var id = spec.id || 'app-mdi-' + windowSeq;
    if (/^app-mdi-(\d+)$/.test(id)) {
      var n = parseInt(id.replace('app-mdi-', ''), 10);
      if (n > windowSeq) {
        windowSeq = n;
      }
    }

    var route = spec.route || parseRouteFromHref(spec.href);
    var winTitle = spec.title || titleFromHref(spec.href);
    var frameSrc = buildEmbedUrl(spec.href, id);
    var lazyFrame = !!spec.minimized;
    var iframeTag = lazyFrame
      ? '<iframe class="app-mdi-frame" scrolling="yes" data-src="' +
        esc(frameSrc) +
        '" title="' +
        esc(winTitle) +
        '"></iframe>'
      : '<iframe class="app-mdi-frame" scrolling="yes" title="' +
        esc(winTitle) +
        '" src="' +
        esc(frameSrc) +
        '"></iframe>';

    var el = document.createElement('div');
    el.className = 'app-mdi-window';
    el.setAttribute('data-mdi-id', id);
    el.innerHTML =
      '<header class="app-mdi-window-head">' +
      '<div class="app-mdi-window-chrome">' +
      '<button type="button" class="app-mdi-tool-btn app-mdi-minimize" title="تصغير">_</button>' +
      '<button type="button" class="app-mdi-tool-btn app-mdi-close" title="إغلاق">×</button>' +
      '</div>' +
      '<h2 class="app-mdi-window-title">' +
      esc(winTitle) +
      '</h2>' +
      '</header>' +
      '<div class="app-mdi-window-body">' +
      iframeTag +
      '</div>';

    layerEl.appendChild(el);

    var win = {
      id: id,
      key: spec.key || normalizeKey(spec.href),
      href: spec.href,
      route: route,
      title: winTitle,
      minimized: !!spec.minimized,
      maximized: !!spec.maximized,
      fullPage: !!spec.fullPage,
      unsaved: !!spec.unsaved,
      el: el,
    };
    windows.push(win);
    bindWindowEvents(win);
    layoutWindow(win);
    if (!win.minimized && focusOnOpen !== false) {
      focusWindow(id);
    } else {
      syncTaskbar();
    }
    return id;
  }

  function restorePersistedWindows() {
    loadStoredWindows().forEach(function (spec) {
      if (!spec || !spec.href) {
        return;
      }
      var key = spec.key || normalizeKey(spec.href);
      if (findByKey(key)) {
        return;
      }
      registerFullPageWindow({
        id: spec.id,
        key: key,
        href: spec.href,
        title: spec.title,
        route: spec.route,
        minimized: spec.minimized !== false,
        unsaved: !!spec.unsaved,
      });
    });
  }

  function preparePageMinimize() {
    if (global.ScreenExitGuard && typeof global.ScreenExitGuard.prepareForMinimize === 'function') {
      return global.ScreenExitGuard.prepareForMinimize();
    }
    var detail = { dirty: false };
    global.document.dispatchEvent(new CustomEvent('manager:before-minimize', { detail: detail }));
    global.__managerAllowUnload = true;
    return detail;
  }

  function getIframeActiveGuard(win) {
    if (!win || !win.el) {
      return null;
    }
    var frame = win.el.querySelector('.app-mdi-frame');
    if (!frame || !frame.contentWindow) {
      return null;
    }
    try {
      var cw = frame.contentWindow;
      if (cw.ManagerScreenExit && typeof cw.ManagerScreenExit.confirmLeave === 'function') {
        return cw.ManagerScreenExit;
      }
      if (cw.ScreenExitGuard && typeof cw.ScreenExitGuard.getActiveGuard === 'function') {
        return cw.ScreenExitGuard.getActiveGuard();
      }
    } catch (e) {
      return null;
    }
    return null;
  }

  function confirmIframeLeave(handler, onProceed) {
    if (!handler) {
      if (onProceed) {
        onProceed();
      }
      return;
    }
    if (handler.confirmLeave && typeof handler.confirmLeave === 'function') {
      if (handler.hasUnsaved && !handler.hasUnsaved()) {
        if (onProceed) {
          onProceed();
        }
        return;
      }
      handler.confirmLeave(onProceed, function () {});
      return;
    }
    if (handler.confirmUnsavedChanges && handler.hasUnsavedChanges && handler.hasUnsavedChanges()) {
      handler.confirmUnsavedChanges(onProceed, function () {});
      return;
    }
    if (onProceed) {
      onProceed();
    }
  }

  function confirmCloseWindow(win, onProceed) {
    if (global.ScreenExitGuard && typeof global.ScreenExitGuard.confirmTaskbarClose === 'function') {
      global.ScreenExitGuard.confirmTaskbarClose(
        function () {
          if (win && win.href) {
            global.location.href = win.href;
          }
        },
        function () {
          win.unsaved = false;
          onProceed();
        },
        function () {}
      );
      return;
    }
    if (global.confirm('هل تريد حفظ التغييرات قبل إغلاق الشاشة؟')) {
      if (win && win.href) {
        global.location.href = win.href;
      }
      return;
    }
    win.unsaved = false;
    onProceed();
  }

  function minimizeThisPage(goMenu) {
    var href = global.location.href;
    var key = normalizeKey(href);
    var existing = findByKey(key);
    var minimizeDetail = preparePageMinimize();
    var id;
    if (existing) {
      existing.unsaved = !!minimizeDetail.dirty;
      id = existing.id;
    } else {
      id = registerWindow({
        key: key,
        href: href,
        title: currentPageTitle(),
        minimized: true,
        unsaved: !!minimizeDetail.dirty,
      });
    }
    if (goMenu !== false && id) {
      var win = findWindow(id);
      if (win) {
        win.unsaved = !!minimizeDetail.dirty;
        parkLivePage(win, defaultHubUrl());
      }
      persistWindowsNow();
      return id;
    }
    persistWindows();
    return id;
  }

  function getMdiLayerBounds() {
    if (!layerEl) {
      return {
        left: 0,
        top: 0,
        width: global.innerWidth,
        height: global.innerHeight,
      };
    }
    var rect = layerEl.getBoundingClientRect();
    return {
      left: rect.left,
      top: rect.top,
      width: rect.width,
      height: rect.height,
    };
  }

  function clampWindowPosition(win, left, top) {
    if (!win || !win.el) {
      return { left: left, top: top };
    }
    var bounds = getMdiLayerBounds();
    var rect = win.el.getBoundingClientRect();
    var w = rect.width || win.el.offsetWidth || 320;
    var h = rect.height || win.el.offsetHeight || 240;
    var pad = 8;
    var minLeft = bounds.left + pad;
    var minTop = bounds.top + pad;
    var maxLeft = bounds.left + bounds.width - w - pad;
    var maxTop = bounds.top + bounds.height - h - pad;
    if (maxLeft < minLeft) {
      maxLeft = minLeft;
    }
    if (maxTop < minTop) {
      maxTop = minTop;
    }
    return {
      left: Math.min(Math.max(left, minLeft), maxLeft),
      top: Math.min(Math.max(top, minTop), maxTop),
    };
  }

  function applyWindowPosition(win) {
    if (!win || !win.el || win.minimized) {
      return;
    }
    if (win.maximized) {
      return;
    }
    if (typeof win.posLeft === 'number' && typeof win.posTop === 'number') {
      var clamped = clampWindowPosition(win, win.posLeft, win.posTop);
      win.posLeft = clamped.left;
      win.posTop = clamped.top;
      win.el.style.top = clamped.top + 'px';
      win.el.style.left = clamped.left + 'px';
      win.el.style.right = 'auto';
      win.el.style.bottom = 'auto';
      win.el.style.transform = 'none';
      return;
    }
    var offset = windows.indexOf(win) * 24;
    win.el.style.top = '50%';
    win.el.style.left = '50%';
    win.el.style.right = 'auto';
    win.el.style.bottom = 'auto';
    win.el.style.transform = 'translate(calc(-50% + ' + offset + 'px), calc(-50% + ' + offset + 'px))';
  }

  function bindWindowDrag(win) {
    var head = win.el.querySelector('.app-mdi-window-head');
    if (!head || head.getAttribute('data-mdi-drag-bound') === '1') {
      return;
    }
    head.setAttribute('data-mdi-drag-bound', '1');

    head.addEventListener('mousedown', function (e) {
      if (e.button !== 0) {
        return;
      }
      if (e.target.closest('.app-mdi-tool-btn, button, a')) {
        return;
      }
      if (win.minimized || win.maximized) {
        focusWindow(win.id);
        return;
      }

      focusWindow(win.id);

      var rect = win.el.getBoundingClientRect();
      var startX = e.clientX;
      var startY = e.clientY;
      var startLeft = rect.left;
      var startTop = rect.top;

      win.posLeft = startLeft;
      win.posTop = startTop;
      win.el.style.top = startTop + 'px';
      win.el.style.left = startLeft + 'px';
      win.el.style.right = 'auto';
      win.el.style.bottom = 'auto';
      win.el.style.transform = 'none';

      function onMove(ev) {
        var next = clampWindowPosition(win, startLeft + (ev.clientX - startX), startTop + (ev.clientY - startY));
        win.posLeft = next.left;
        win.posTop = next.top;
        win.el.style.left = next.left + 'px';
        win.el.style.top = next.top + 'px';
      }

      function onUp() {
        document.removeEventListener('mousemove', onMove);
        document.removeEventListener('mouseup', onUp);
        document.body.classList.remove('app-mdi-window-dragging');
      }

      e.preventDefault();
      document.body.classList.add('app-mdi-window-dragging');
      document.addEventListener('mousemove', onMove);
      document.addEventListener('mouseup', onUp);
    });
  }

  function layoutWindow(win) {
    if (!win.el) {
      return;
    }
    var maxBtn = win.el.querySelector('.app-mdi-maximize');
    if (maxBtn) {
      maxBtn.textContent = win.maximized ? '❐' : '□';
      maxBtn.title = win.maximized ? 'استعادة' : 'تكبير';
    }
    if (win.minimized) {
      win.el.classList.add('is-minimized');
      win.el.classList.remove('is-maximized');
      win.el.style.width = '';
      win.el.style.height = '';
      win.el.style.transform = '';
      return;
    }
    win.el.classList.remove('is-minimized');
    if (win.maximized) {
      win.el.classList.add('is-maximized');
      win.el.style.width = '';
      win.el.style.height = '';
      win.el.style.transform = '';
      win.el.style.top = '';
      win.el.style.left = '';
      win.el.style.right = '';
      win.el.style.bottom = '';
      updateShellState();
      return;
    }
    win.el.classList.remove('is-maximized');
    win.el.style.width = 'min(760px, 92%)';
    win.el.style.height = 'min(520px, 82%)';
    applyWindowPosition(win);
    updateShellState();
  }

  function focusWindow(id) {
    var win = findWindow(id);
    if (!win || !win.el) {
      return;
    }
    activeId = id;
    activePanelId = null;
    nextZ += 1;
    win.el.style.zIndex = String(nextZ);
    win.el.classList.add('is-focused');
    windows.forEach(function (w) {
      if (w.el && w.id !== id) {
        w.el.classList.remove('is-focused');
      }
    });
    syncTaskbar();
  }

  function restoreWindow(id) {
    var win = findWindow(id);
    if (!win) {
      return;
    }
    if (isParkedLiveWindow(win)) {
      unparkLiveWindow(win);
      return;
    }
    if (parkedLiveId && parkedLiveId !== id) {
      releaseParkedLiveForNavigation();
    }
    var samePage = isWindowCurrentPage(win);
    win.minimized = false;
    win.parkedLive = false;
    win.fullPage = true;
    activeId = id;
    persistWindowsNow();
    if (samePage) {
      hideHubOverlay();
      document.body.classList.remove('app-mdi-page-parked');
      syncTaskbar();
      return;
    }
    prefetchHref(win.href);
    global.__managerAllowUnload = true;
    global.location.href = win.href;
  }

  function unloadIframe(win) {
    if (!win || !win.el) {
      return;
    }
    var frame = win.el.querySelector('.app-mdi-frame');
    if (!frame) {
      return;
    }
    var src = frame.getAttribute('src');
    if (src) {
      frame.setAttribute('data-src', src);
      frame.removeAttribute('src');
    }
  }

  function minimizeWindow(id) {
    var win = findWindow(id);
    if (!win) {
      return;
    }
    if (isWindowCurrentPage(win)) {
      minimizeThisPage(true);
      return;
    }
    win.minimized = true;
    win.parkedLive = false;
    win.fullPage = true;
    syncTaskbar();
    persistWindowsNow();
  }

  function toggleMaximize(id) {
    var win = findWindow(id);
    if (!win || win.minimized) {
      return;
    }
    win.maximized = !win.maximized;
    layoutWindow(win);
    focusWindow(id);
  }

  function closeWindow(id, force) {
    var idx = -1;
    for (var i = 0; i < windows.length; i++) {
      if (windows[i].id === id) {
        idx = i;
        break;
      }
    }
    if (idx < 0) {
      return;
    }
    var win = windows[idx];
    if (!force) {
      if (win.unsaved) {
        confirmCloseWindow(win, function () {
          closeWindow(id, true);
        });
        return;
      }
      if (!win.minimized) {
        var iframeLeave = getIframeActiveGuard(win);
        if (iframeLeave) {
          confirmIframeLeave(iframeLeave, function () {
            closeWindow(id, true);
          });
          return;
        }
        if (
          normalizeKey(win.href) === normalizeKey(global.location.href) &&
          global.ScreenExitGuard &&
          typeof global.ScreenExitGuard.confirmLeave === 'function'
        ) {
          global.ScreenExitGuard.confirmLeave(function () {
            closeWindow(id, true);
          });
          return;
        }
      }
    }
    var isCurrentPage = normalizeKey(win.href) === normalizeKey(global.location.href);
    var navigateToHub = win.parkedLive && isCurrentPage;
    if (navigateToHub) {
      parkedLiveId = null;
      win.parkedLive = false;
    }
    if (win.el && win.el.parentNode) {
      win.el.parentNode.removeChild(win.el);
    }
    windows.splice(idx, 1);
    if (navigateToHub) {
      global.__managerAllowUnload = true;
      persistWindowsNow();
      navigateFromHubParent(defaultHubUrl());
      return;
    }
    if (isCurrentPage && win.fullPage) {
      global.__managerAllowUnload = true;
      persistWindowsNow();
      syncTaskbar();
      global.location.href = stripEmbedParams(defaultHubUrl());
      return;
    }
    if (activeId === id) {
      activeId = null;
      for (var j = windows.length - 1; j >= 0; j--) {
        if (!windows[j].minimized) {
          activeId = windows[j].id;
          focusWindow(activeId);
          break;
        }
      }
    }
    persistWindowsNow();
    syncTaskbar();
  }

  function bindWindowEvents(win) {
    bindWindowDrag(win);

    win.el.querySelector('.app-mdi-minimize').addEventListener('click', function (e) {
      e.stopPropagation();
      minimizeWindow(win.id);
    });

    win.el.querySelector('.app-mdi-close').addEventListener('click', function (e) {
      e.stopPropagation();
      closeWindow(win.id);
    });
  }

  function createWindow(href, title, opts) {
    opts = opts || {};
    var key = normalizeKey(href);
    var existing = findByKey(key);
    if (existing) {
      if (opts.minimized) {
        minimizeWindow(existing.id);
      } else {
        restoreWindow(existing.id);
      }
      return existing.id;
    }
    if (opts.minimized) {
      return registerFullPageWindow({
        key: key,
        href: href,
        title: title || titleFromHref(href),
        minimized: true,
      });
    }
    global.__managerAllowUnload = true;
    global.location.href = href;
    return null;
  }

  function openUrl(href, title) {
    if (!href) {
      return null;
    }
    return createWindow(href, title);
  }

  function openRoute(route, params, title) {
    var u = new URL(config.baseUrl || 'index.php', global.location.origin);
    u.searchParams.set('r', route);
    if (params && typeof params === 'object') {
      Object.keys(params).forEach(function (key) {
        if (params[key] != null && params[key] !== '') {
          u.searchParams.set(key, String(params[key]));
        }
      });
    }
    return createWindow(u.toString(), title || routeTitle(route, route));
  }

  function openFromLink(anchor) {
    var route = parseRouteFromHref(anchor.href);
    return openUrl(anchor.href, linkLabel(anchor, route));
  }

  function setPanel(spec) {
    if (!spec || !spec.id) {
      return;
    }
    panels[spec.id] = {
      id: spec.id,
      title: spec.title || spec.id,
      minimized: spec.minimized !== false,
      onActivate: spec.onActivate || null,
      onClose: spec.onClose || null,
    };
    syncTaskbar();
  }

  function removePanel(id) {
    if (!id || !panels[id]) {
      return;
    }
    delete panels[id];
    if (activePanelId === id) {
      activePanelId = null;
    }
    syncTaskbar();
  }

  function initMdiUi() {
    ensureShell();
    restorePersistedWindows();
    convertLegacyIframeWindows();
    reconcileParkStateOnLoad();
    global.addEventListener('message', handleHubParentMessage);
    var btn = document.getElementById('app-mdi-minimize-screen');
    if (btn) {
      btn.addEventListener('click', function () {
        minimizeThisPage(true);
      });
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initMdiUi);
  } else {
    initMdiUi();
  }

  global.AppScreenWindows = {
    open: openUrl,
    openRoute: openRoute,
    close: closeWindow,
    minimize: minimizeWindow,
    restore: restoreWindow,
    focus: focusWindow,
    minimizeThisPage: minimizeThisPage,
    navigateFromHubParent: navigateFromHubParent,
    exitCurrentPage: exitCurrentPage,
    hasCurrentFullPageWindow: hasCurrentFullPageWindow,
    findCurrentFullPageWindow: findCurrentFullPageWindow,
    persist: persistWindows,
    setPanel: setPanel,
    removePanel: removePanel,
    syncTaskbar: syncTaskbar,
    list: function () {
      return windows.slice();
    },
  };
})(window);
