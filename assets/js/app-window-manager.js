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

  // هوية النافذة مستقرة: run=1 من PRG لا يُنشئ نافذة جديدة ولا يُفقد المطابقة
  function identityKey(href) {
    try {
      var u = new URL(href, global.location.origin);
      u.searchParams.delete('embed');
      u.searchParams.delete('mdi_id');
      u.searchParams.delete('run');
      var parts = [];
      u.searchParams.forEach(function (val, key) {
        parts.push(key + '=' + val);
      });
      parts.sort();
      return u.pathname + (parts.length ? '?' + parts.join('&') : '');
    } catch (e) {
      return normalizeKey(href);
    }
  }

  function withRunFlag(href) {
    try {
      var u = new URL(href, global.location.origin);
      u.searchParams.set('run', '1');
      u.searchParams.delete('embed');
      u.searchParams.delete('mdi_id');
      return u.pathname + u.search + u.hash;
    } catch (e) {
      return href;
    }
  }

  function pageHasReportResults() {
    try {
      return !!(
        document.querySelector('.report-sales-result, .si-print-area.report-sales-result, [data-ora-view-panel]:not([hidden])') ||
        document.querySelector('.ora-summary-block, .ora-view[data-ora-view-panel]')
      );
    } catch (e) {
      return false;
    }
  }

  function preferRestoreHref(href) {
    if (!href) {
      return href;
    }
    try {
      var u = new URL(href, global.location.origin);
      if (u.searchParams.get('run') === '1') {
        return href;
      }
      // فقط إذا كان الرابط لنفس الصفحة الحالية وفيها نتائج معروضة
      var samePage = false;
      try {
        samePage = stripBasePath(u.pathname) === stripBasePath(global.location.pathname);
      } catch (eSame) {
        samePage = false;
      }
      if (samePage && pageHasReportResults()) {
        return withAppBase(withRunFlag(href)) || withRunFlag(href);
      }
      var raw = null;
      try {
        raw = sessionStorage.getItem(PAGE_STATE_PREFIX + identityKey(href));
        if (!raw) {
          raw = sessionStorage.getItem(PAGE_STATE_PREFIX + normalizeKey(href));
        }
      } catch (eRaw) {
        raw = null;
      }
      if (raw) {
        try {
          var payload = JSON.parse(raw);
          var best = pickRestoreForm(payload && payload.forms);
          if (best && best.fields && String(best.fields.run) === '1') {
            return withAppBase(withRunFlag(href)) || withRunFlag(href);
          }
          // حالة محفوظة بعد عرض ناجح (حتى لو الحقل run غير موجود في النموذج)
          if (payload && payload.href) {
            var ph = new URL(payload.href, global.location.origin);
            if (ph.searchParams.get('run') === '1') {
              return withAppBase(withRunFlag(href)) || withRunFlag(href);
            }
          }
        } catch (eParse) {
          /* ignore */
        }
      }
    } catch (e) {
      /* ignore */
    }
    return href;
  }

  function buildEmbedUrl(href, mdiId) {
    var u = new URL(withAppBase(href) || href, global.location.origin);
    u.searchParams.set('embed', '1');
    u.searchParams.delete('mdi_id');
    if (mdiId) {
      u.searchParams.set('mdi_id', mdiId);
    }
    return withAppBase(u.pathname) + u.search + u.hash;
  }

  var STORAGE_KEY = 'manager:mdi-windows-v1';
  var PAGE_STATE_PREFIX = 'manager:mdi-page-state-v1:';
  var RESTORE_PENDING_KEY = 'manager:mdi-restore-pending';

  function pageStateStorageKey(winOrKey) {
    if (!winOrKey) {
      return PAGE_STATE_PREFIX + identityKey(global.location.href);
    }
    if (typeof winOrKey === 'string') {
      return PAGE_STATE_PREFIX + identityKey(winOrKey);
    }
    return PAGE_STATE_PREFIX + (winOrKey.key || identityKey(winOrKey.href || global.location.href));
  }

  function capturePageState(win) {
    try {
      var forms = [];
      Array.prototype.forEach.call(document.querySelectorAll('form'), function (form) {
        var fields = {};
        try {
          var fd = new FormData(form);
          fd.forEach(function (val, key) {
            if (Object.prototype.hasOwnProperty.call(fields, key)) {
              if (!Array.isArray(fields[key])) {
                fields[key] = [fields[key]];
              }
              fields[key].push(String(val));
            } else {
              fields[key] = String(val);
            }
          });
        } catch (err) {
          return;
        }
        forms.push({
          id: form.id || '',
          name: form.getAttribute('name') || '',
          action: form.getAttribute('action') || '',
          method: String(form.method || 'get').toLowerCase(),
          fields: fields,
        });
      });
      var hrefNow = global.location.href;
      if (pageHasReportResults()) {
        hrefNow = withAppBase(withRunFlag(hrefNow)) || withRunFlag(hrefNow);
      }
      var key = (win && win.key) || identityKey((win && win.href) || hrefNow);
      if (win) {
        win.key = key;
        win.href = hrefNow;
      }
      var payload = {
        key: key,
        href: hrefNow,
        path: global.location.pathname,
        scrollY: global.scrollY || 0,
        forms: forms,
        ts: Date.now(),
      };
      sessionStorage.setItem(pageStateStorageKey(key), JSON.stringify(payload));
    } catch (e) {
      /* ignore */
    }
  }

  function pickRestoreForm(forms) {
    if (!forms || !forms.length) {
      return null;
    }
    var i;
    for (i = 0; i < forms.length; i += 1) {
      var f = forms[i];
      if (f && f.fields && String(f.fields.run) === '1') {
        return f;
      }
    }
    for (i = 0; i < forms.length; i += 1) {
      var form = forms[i];
      if (form && form.fields && Object.keys(form.fields).length) {
        return form;
      }
    }
    return forms[0] || null;
  }

  function resolveFormAction(action) {
    if (!action) {
      return global.location.pathname + global.location.search;
    }
    try {
      return new URL(action, global.location.href).pathname + new URL(action, global.location.href).search;
    } catch (e) {
      return action;
    }
  }

  function resubmitSavedState(payload) {
    if (!payload || !payload.forms || !payload.forms.length) {
      return false;
    }
    var best = pickRestoreForm(payload.forms);
    if (!best || !best.fields || !Object.keys(best.fields).length) {
      return false;
    }
    try {
      var cur = new URL(global.location.href);
      // تقارير PRG (POST ثم GET?run=1): الصفحة الحالية جاهزة من الجلسة — لا نعيد العرض
      if (cur.searchParams.get('run') === '1') {
        return false;
      }
      if (String(best.fields.run) === '1' && document.querySelector('.report-sales-result, .si-print-area.report-sales-result, [data-ora-view-panel]')) {
        return false;
      }
    } catch (e0) {
      /* ignore */
    }
    var form = document.createElement('form');
    form.method = String(best.method || 'get').toLowerCase() === 'post' ? 'post' : 'get';
    form.action = resolveFormAction(best.action);
    form.style.display = 'none';
    Object.keys(best.fields).forEach(function (name) {
      var vals = best.fields[name];
      var list = Array.isArray(vals) ? vals : [vals];
      list.forEach(function (v) {
        var input = document.createElement('input');
        input.type = 'hidden';
        input.name = name;
        input.value = v == null ? '' : String(v);
        form.appendChild(input);
      });
    });
    document.body.appendChild(form);
    global.__managerAllowUnload = true;
    form.submit();
    return true;
  }

  function tryRestorePendingFilters() {
    var pending = null;
    try {
      pending = sessionStorage.getItem(RESTORE_PENDING_KEY);
    } catch (e) {
      return;
    }
    if (!pending) {
      return;
    }
    try {
      sessionStorage.removeItem(RESTORE_PENDING_KEY);
    } catch (e2) {
      /* ignore */
    }
    if (isMenuLikeLocation(global.location.href)) {
      return;
    }
    var raw = null;
    try {
      raw = sessionStorage.getItem(PAGE_STATE_PREFIX + pending);
    } catch (e3) {
      return;
    }
    if (!raw) {
      return;
    }
    var payload;
    try {
      payload = JSON.parse(raw);
    } catch (e4) {
      return;
    }
    try {
      if (payload.path && payload.path !== global.location.pathname) {
        var ph = new URL(payload.href || '', global.location.origin);
        if (ph.pathname !== global.location.pathname) {
          return;
        }
      }
    } catch (e5) {
      /* ignore */
    }
    resubmitSavedState(payload);
  }

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
      var key = identityKey(w.key || w.href);
      if (seen[key]) {
        return;
      }
      seen[key] = true;
      w.key = key;
      data.push({
        id: w.id,
        key: key,
        href: preferRestoreHref(w.href),
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
    placeTaskbar();
  }

  function clearParkedTaskbarOffset() {
    try {
      document.documentElement.style.removeProperty('--app-mdi-parked-taskbar-top');
    } catch (eClear) {
      /* ignore */
    }
    if (taskbarEl) {
      taskbarEl.style.top = '';
    }
  }

  function removeHubTaskbarMirror() {
    try {
      if (!hubFrameEl || !hubFrameEl.contentDocument) {
        return;
      }
      var hubDoc = hubFrameEl.contentDocument;
      var mirror = hubDoc.getElementById('app-mdi-taskbar-hub');
      if (mirror && mirror.parentNode) {
        mirror.parentNode.removeChild(mirror);
      }
    } catch (eRm) {
      /* ignore */
    }
  }

  function buildTaskbarItemsHtml() {
    var html = '';
    windows.forEach(function (win) {
      html +=
        '<div class="app-mdi-task-item' +
        (isTaskbarWindowActive(win) ? ' is-active' : '') +
        (win.minimized ? ' is-minimized' : '') +
        '">' +
        '<button type="button" class="app-mdi-task-btn" data-mdi-id="' +
        esc(win.id) +
        '" title="' +
        esc(win.title) +
        '"><span class="app-mdi-task-label">' +
        esc(win.title) +
        '</span></button>' +
        '<button type="button" class="app-mdi-task-close" data-mdi-close="' +
        esc(win.id) +
        '" aria-label="إغلاق">×</button>' +
        (!win.minimized
          ? '<button type="button" class="app-mdi-task-minimize" data-mdi-minimize="' +
            esc(win.id) +
            '" aria-label="تصغير" title="تصغير الشاشة">_</button>'
          : '') +
        '</div>';
    });
    panelIds().forEach(function (panelId) {
      var panel = panels[panelId];
      if (!panel) {
        return;
      }
      html +=
        '<div class="app-mdi-task-item app-mdi-task-item--panel' +
        (panelId === activePanelId && !panel.minimized ? ' is-active' : '') +
        (panel.minimized !== false ? ' is-minimized' : '') +
        '">' +
        '<button type="button" class="app-mdi-task-btn" data-mdi-panel="' +
        esc(panelId) +
        '" title="' +
        esc(panel.title || panelId) +
        '"><span class="app-mdi-task-label">' +
        esc(panel.title || panelId) +
        '</span></button>' +
        '<button type="button" class="app-mdi-task-close" data-mdi-panel-close="' +
        esc(panelId) +
        '" aria-label="إغلاق">×</button></div>';
    });
    return html;
  }

  function bindHubTaskbarMirrorOnce(hubDoc, mirror) {
    if (!hubDoc || !mirror || mirror.getAttribute('data-mdi-bound') === '1') {
      return;
    }
    mirror.setAttribute('data-mdi-bound', '1');
    // المستمع من وثيقة الأب على عنصر الـ iframe — استدعاء مباشر بدون postMessage
    mirror.addEventListener('click', function (e) {
      var t = e.target;
      if (!t || !t.closest) {
        return;
      }
      e.preventDefault();
      e.stopPropagation();
      var closeBtn = t.closest('[data-mdi-close]');
      if (closeBtn) {
        closeWindow(closeBtn.getAttribute('data-mdi-close'));
        return;
      }
      var minBtn = t.closest('[data-mdi-minimize]');
      if (minBtn) {
        minimizeWindow(minBtn.getAttribute('data-mdi-minimize'));
        return;
      }
      var panelCloseBtn = t.closest('[data-mdi-panel-close]');
      if (panelCloseBtn) {
        var panelCloseId = panelCloseBtn.getAttribute('data-mdi-panel-close');
        var panelClose = panels[panelCloseId];
        if (panelClose && typeof panelClose.onClose === 'function') {
          panelClose.onClose();
        } else {
          removePanel(panelCloseId);
        }
        return;
      }
      var panelBtn = t.closest('[data-mdi-panel]');
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
      var taskBtn = t.closest('[data-mdi-id]');
      if (taskBtn) {
        taskbarWindowClick(taskBtn.getAttribute('data-mdi-id'));
      }
    });
  }

  function syncHubTaskbarMirror() {
    if (!document.body.classList.contains('app-mdi-page-parked')) {
      removeHubTaskbarMirror();
      return;
    }
    try {
      if (!hubFrameEl) {
        return;
      }
      var hubDoc = hubFrameEl.contentDocument;
      if (!hubDoc || !hubDoc.body) {
        return;
      }
      var nav = hubDoc.querySelector('.hx-topnav');
      if (!nav) {
        return;
      }
      var styleId = 'app-mdi-taskbar-hub-style';
      if (!hubDoc.getElementById(styleId)) {
        var st = hubDoc.createElement('style');
        st.id = styleId;
        st.textContent = [
          '.hx-topnav{overflow:visible!important;position:relative;z-index:1200}',
          '.hx-nav-drop,.hx-nav-fly{z-index:1300!important}',
          '#app-mdi-taskbar-hub{position:relative;z-index:41;width:100%;min-height:2.15rem;padding:4px 10px 6px;background:#111827;border-top:1px solid rgba(255,255,255,.1);box-sizing:border-box;display:flex;align-items:center;gap:.45rem;flex:0 0 auto}',
          '#app-mdi-taskbar-hub[hidden]{display:none!important}',
          '#app-mdi-taskbar-hub .app-mdi-taskbar-windows{display:flex;align-items:stretch;gap:.35rem;flex-wrap:wrap;min-width:0}',
          '#app-mdi-taskbar-hub .app-mdi-task-item{display:inline-flex;align-items:stretch;max-width:min(280px,46vw);border:1px solid rgba(255,255,255,.12);background:#1f2937}',
          '#app-mdi-taskbar-hub .app-mdi-task-btn{appearance:none;border:0;background:#1f2937;color:#e5e7eb;font-size:12px;min-height:28px;padding:0 10px;cursor:pointer;border-inline-end:1px solid rgba(255,255,255,.1)}',
          '#app-mdi-taskbar-hub .app-mdi-task-item.is-minimized .app-mdi-task-btn{background:#1e3a5f;color:#e0f2fe}',
          '#app-mdi-taskbar-hub .app-mdi-task-btn:hover{background:#334155;color:#fff}',
          '#app-mdi-taskbar-hub .app-mdi-task-label{display:inline-block;max-width:14rem;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}',
          '#app-mdi-taskbar-hub .app-mdi-task-close,#app-mdi-taskbar-hub .app-mdi-task-minimize{appearance:none;border:0;background:#1f2937;color:#e5e7eb;min-width:1.6rem;cursor:pointer;border-inline-end:1px solid rgba(255,255,255,.1)}',
          '#app-mdi-taskbar-hub .app-mdi-task-close:hover{background:#7f1d1d;color:#fff}',
        ].join('');
        (hubDoc.head || hubDoc.documentElement).appendChild(st);
      }
      var mirror = hubDoc.getElementById('app-mdi-taskbar-hub');
      if (!mirror) {
        mirror = hubDoc.createElement('div');
        mirror.id = 'app-mdi-taskbar-hub';
        mirror.className = 'app-mdi-taskbar app-mdi-taskbar--hub';
        mirror.innerHTML = '<div class="app-mdi-taskbar-windows"></div>';
        nav.appendChild(mirror);
        bindHubTaskbarMirrorOnce(hubDoc, mirror);
      } else if (mirror.parentNode !== nav) {
        nav.appendChild(mirror);
      }
      var winHost = mirror.querySelector('.app-mdi-taskbar-windows');
      if (!winHost) {
        return;
      }
      var itemsHtml = buildTaskbarItemsHtml();
      winHost.innerHTML = itemsHtml;
      mirror.hidden = !itemsHtml;
    } catch (eSync) {
      /* ignore */
    }
  }

  function placeTaskbar() {
    if (!taskbarEl) {
      return;
    }
    // أثناء التصغير: الشريط التفاعلي داخل iframe القائمة حتى لا يغطي القوائم المنسدلة
    if (document.body.classList.contains('app-mdi-page-parked')) {
      if (taskbarEl.parentNode !== document.body) {
        document.body.appendChild(taskbarEl);
      }
      taskbarEl.classList.add('is-parked-top');
      syncHubTaskbarMirror();
      return;
    }
    taskbarEl.classList.remove('is-parked-top');
    clearParkedTaskbarOffset();
    removeHubTaskbarMirror();
    var nav = document.querySelector('.hx-topnav');
    if (nav && taskbarEl.parentNode !== nav) {
      nav.appendChild(taskbarEl);
    }
  }

  function appBase() {
    var base = typeof global.__HYPEX_BASE__ === 'string' ? global.__HYPEX_BASE__ : '';
    if (base && base.charAt(base.length - 1) === '/') base = base.slice(0, -1);
    return base;
  }

  function stripBasePath(pathname) {
    var base = appBase();
    var path = String(pathname || '');
    if (base && (path === base || path.indexOf(base + '/') === 0)) {
      path = path.slice(base.length) || '/';
    }
    if (path.length > 1 && path.charAt(path.length - 1) === '/') path = path.slice(0, -1);
    return path || '/';
  }

  /** يضمن أن كل تنقل يبقى تحت /hypex (يتجنب Apache 404 على الجذر) */
  function withAppBase(href) {
    if (!href) {
      return href;
    }
    var raw = String(href);
    if (/^https?:\/\//i.test(raw) || raw.indexOf('//') === 0) {
      try {
        var abs = new URL(raw, global.location.origin);
        if (abs.origin === global.location.origin) {
          abs.pathname = withAppBase(abs.pathname) || abs.pathname;
          return abs.pathname + abs.search + abs.hash;
        }
      } catch (e) {
        /* keep absolute external */
      }
      return raw;
    }
    if (raw.charAt(0) !== '/') {
      return raw;
    }
    var base = appBase();
    if (!base) {
      return raw;
    }
    if (raw === base || raw.indexOf(base + '/') === 0) {
      return raw;
    }
    return base + raw;
  }

  function isMenuLikeLocation(url) {
    try {
      var u = typeof url === 'string' ? new URL(url, global.location.origin) : url;
      var route = u.searchParams.get('r') || '';
      if (route === 'dashboard' || route === 'menu_hub') return true;
      var path = stripBasePath(u.pathname);
      if (path.length > 1 && path.charAt(path.length - 1) === '/') {
        path = path.slice(0, -1);
      }
      if (path === '/' || path === '/app' || path === '/login' || path === '/menu' || path.indexOf('/hub/') === 0) {
        return true;
      }
      // index.php بدون مسار شاشة = لوحة فقط
      if (/\/index\.php$/i.test(path) && (!route || route === 'dashboard' || route === 'menu_hub')) {
        return true;
      }
      return false;
    } catch (e) {
      return false;
    }
  }

  function isAppScreenPath(path) {
    path = String(path || '');
    if (path.length > 1 && path.charAt(path.length - 1) === '/') {
      path = path.slice(0, -1);
    }
    return (
      /^\/(sales|inventory|customers|purchases|accounting|hr|system|finance|warehouse|reports|mobile|sales-reps)\b/i.test(
        path
      ) || /^\/embed\//i.test(path)
    );
  }

  function isHubFrameLocation(u) {
    try {
      if (!u) return false;
      if (isMenuLikeLocation(u)) return true;
      var path = stripBasePath(u.pathname);
      if (path.length > 1 && path.charAt(path.length - 1) === '/') {
        path = path.slice(0, -1);
      }
      var route = u.searchParams.get('r') || '';
      if (route === 'dashboard' || route === 'menu_hub') return true;
      if (path === '/app' || path === '/' || path === '/menu' || path.indexOf('/hub/') === 0) return true;
      if (/\/index\.php$/i.test(path) && (!route || route === 'dashboard' || route === 'menu_hub')) {
        return true;
      }
      try {
        var hub = new URL(defaultHubUrl(), global.location.origin);
        var hubPath = stripBasePath(hub.pathname);
        if (hubPath.length > 1 && hubPath.charAt(hubPath.length - 1) === '/') {
          hubPath = hubPath.slice(0, -1);
        }
        if (hubPath === path) {
          var hubRoute = hub.searchParams.get('r') || '';
          if (!hubRoute || !route || hubRoute === route) return true;
        }
      } catch (eHub) {
        /* ignore */
      }
      // /app مع embed=menu = قائمة التصغير (حتى لو فشل تطابق المسار بسبب القاعدة)
      if (u.searchParams.get('embed') === 'menu' && (path === '/app' || path === '/' || path.indexOf('/hub/') === 0)) {
        return true;
      }
      return false;
    } catch (e) {
      return false;
    }
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
    var url = config.afterMinimizeUrl || (config.baseUrl || 'index.php') + '?r=dashboard';
    return withAppBase(url) || url;
  }

  function showHubOverlay(url) {
    ensureShell();
    if (!hubOverlayEl || !hubFrameEl) {
      return;
    }
    ensureHubFrameGuard();
    url = buildHubEmbedUrl(url || defaultHubUrl());
    // أعد استخدام iframe القائمة إن كانت محمّلة — وأعد التحميل إن كانت بدون قائمة
    var needReload = hubOverlayUrl !== url;
    if (!needReload) {
      try {
        var hubDoc = hubFrameEl.contentDocument;
        if (!hubDoc || !hubDoc.querySelector(".hx-topnav, .hx-topnav__nav")) {
          needReload = true;
        }
      } catch (eHubDoc) {
        needReload = true;
      }
    }
    if (needReload) {
      hubFrameEl.src = url;
      hubOverlayUrl = url;
    }
    hubOverlayEl.hidden = false;
    hubOverlayEl.setAttribute('aria-hidden', 'false');
    document.body.classList.add('app-mdi-page-parked');
    placeTaskbar();
  }

  function hideHubOverlay() {
    if (!hubOverlayEl) {
      document.body.classList.remove('app-mdi-page-parked');
      return;
    }
    hubOverlayEl.hidden = true;
    hubOverlayEl.setAttribute('aria-hidden', 'true');
    document.body.classList.remove('app-mdi-page-parked');
    placeTaskbar();
  }

  function samePagePath(aHref, bHref) {
    try {
      var a = new URL(aHref, global.location.origin);
      var b = new URL(bHref, global.location.origin);
      return stripBasePath(a.pathname) === stripBasePath(b.pathname);
    } catch (e) {
      return false;
    }
  }

  function isParkedLiveWindow(win) {
    if (!win || !win.parkedLive) {
      return false;
    }
    if (normalizeKey(win.href) === normalizeKey(global.location.href)) {
      return true;
    }
    // نفس مسار التقرير يكفي لإلغاء التوقيف دون إعادة تحميل
    return samePagePath(win.href, global.location.href);
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
    try {
      if (global.AppBusy && typeof global.AppBusy.hide === 'function') {
        global.AppBusy.hide(true);
      }
      document.documentElement.classList.remove('hypex-nav-busy');
    } catch (eBusy) {
      /* ignore */
    }
    try {
      var raw = sessionStorage.getItem(pageStateStorageKey(win));
      if (raw) {
        var payload = JSON.parse(raw);
        var y = Number(payload && payload.scrollY) || 0;
        if (y > 0) {
          requestAnimationFrame(function () {
            global.scrollTo(0, y);
          });
        }
      }
    } catch (eScroll) {
      /* ignore */
    }
  }

  function dismissFloatingUi() {
    try {
      Array.prototype.forEach.call(document.querySelectorAll('[data-ora-multi-panel]'), function (el) {
        el.hidden = true;
      });
      Array.prototype.forEach.call(document.querySelectorAll('.report-cust-pick-list.is-floating'), function (el) {
        el.hidden = true;
        el.classList.remove('is-floating');
      });
      Array.prototype.forEach.call(document.querySelectorAll('.si-suggest:not([hidden])'), function (el) {
        el.hidden = true;
      });
    } catch (e) {
      /* ignore */
    }
  }

  function showBusyNav(message) {
    try {
      sessionStorage.setItem('hypex:nav-busy', String(Date.now()));
    } catch (e) {
      /* ignore */
    }
    try {
      document.documentElement.classList.add('hypex-nav-busy');
    } catch (e2) {
      /* ignore */
    }
    if (global.AppBusy && typeof global.AppBusy.show === 'function') {
      global.AppBusy.show(message || 'جاري التحميل…');
    }
  }

  function parkLivePage(win, hubUrl) {
    if (!win) {
      return;
    }
    dismissFloatingUi();
    win.minimized = true;
    win.fullPage = true;
    win.parkedLive = true;
    parkedLiveId = win.id;
    // لا تُظهر طبقة التحميل عند التصغير — الصفحة تبقى في الذاكرة
    try {
      if (global.AppBusy && typeof global.AppBusy.hide === 'function') {
        global.AppBusy.hide(true);
      }
      document.documentElement.classList.remove('hypex-nav-busy');
      sessionStorage.removeItem('hypex:nav-busy');
    } catch (eParkBusy) {
      /* ignore */
    }
    showHubOverlay(hubUrl);
    syncTaskbar();
  }

  function releaseParkedLiveForNavigation() {
    var win = parkedLiveId ? findWindow(parkedLiveId) : null;
    if (!win || !win.parkedLive) {
      return;
    }
    capturePageState(win);
    win.parkedLive = false;
    win.fullPage = true;
    win.minimized = true;
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
    var target = withAppBase(stripEmbedParams(href));
    var parked = parkedLiveId ? findWindow(parkedLiveId) : null;
    // الرجوع لنفس التقرير الموقف: إلغاء التوقيف فقط — بدون إعادة تحميل
    if (parked && (normalizeKey(parked.href) === normalizeKey(target) || samePagePath(parked.href, target))) {
      unparkLiveWindow(parked);
      return;
    }
    if (parked) {
      capturePageState(parked);
    }
    releaseParkedLiveForNavigation();
    hideHubOverlay();
    parkedLiveId = null;
    document.body.classList.remove('app-mdi-page-parked');
    showBusyNav('جاري فتح الشاشة…');
    global.__managerAllowUnload = true;
    global.location.href = target;
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
    href = withAppBase(stripEmbedParams(href));
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
      return;
    }
    if (e.data.type === 'manager:mdi-taskbar') {
      var action = e.data.action;
      var id = e.data.id;
      if (action === 'close' && id) {
        closeWindow(id);
      } else if (action === 'minimize' && id) {
        minimizeWindow(id);
      } else if (action === 'activate' && id) {
        taskbarWindowClick(id);
      } else if (action === 'panel-close' && id) {
        var panelClose = panels[id];
        if (panelClose && typeof panelClose.onClose === 'function') {
          panelClose.onClose();
        } else {
          removePanel(id);
        }
      } else if (action === 'panel' && id) {
        var panel = panels[id];
        if (panel && typeof panel.onActivate === 'function') {
          activePanelId = id;
          activeId = null;
          panel.onActivate();
          syncTaskbar();
        }
      }
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
    if (route === 'menu_hub' || route === 'dashboard' || isMenuLikeLocation(global.location.href)) {
      syncTaskbar();
      return;
    }

    var hrefNow = global.location.href;
    if (pageHasReportResults()) {
      hrefNow = withAppBase(withRunFlag(hrefNow)) || withRunFlag(hrefNow);
    }
    var matched = findByKey(identityKey(hrefNow));
    if (matched) {
      matched.href = hrefNow;
      matched.key = identityKey(hrefNow);
      matched.minimized = false;
      matched.parkedLive = false;
      matched.fullPage = true;
      matched.title = currentPageTitle() || matched.title;
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
      // قائمة/لوحة بعد التصغير تبقى في الـ iframe — لا تُفتح في الأب (كانت تسبب التعليق)
      if (isHubFrameLocation(u)) {
        if (u.searchParams.get('embed') !== 'menu') {
          u.searchParams.set('embed', 'menu');
          u.searchParams.delete('mdi_id');
          hubFrameEl.src = u.pathname + u.search + u.hash;
          return;
        }
        if (document.body.classList.contains('app-mdi-page-parked')) {
          placeTaskbar();
        }
        return;
      }
      // المستخدم فتح شاشة من القائمة داخل الـ iframe → انقلها للأب
      navigateFromHubParent(cw.location.href);
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
    if (!global.__mdiParkedTaskbarResizeBound) {
      global.__mdiParkedTaskbarResizeBound = true;
      global.addEventListener('resize', function () {
        if (document.body.classList.contains('app-mdi-page-parked')) {
          syncHubTaskbarMirror();
        }
      });
    }
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
    if (!key) {
      return null;
    }
    var idKey = identityKey(key);
    for (var i = 0; i < windows.length; i++) {
      var wKey = windows[i].key || identityKey(windows[i].href);
      if (wKey === key || wKey === idKey || identityKey(wKey) === idKey || identityKey(windows[i].href) === idKey) {
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
    placeTaskbar();
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
    if (spec && spec.href) {
      spec.href = withAppBase(spec.href) || spec.href;
    }
    var key = identityKey(spec.key || spec.href);
    var existing = findByKey(key);
    if (existing) {
      if (spec.unsaved) {
        existing.unsaved = true;
      }
      if (spec.title) {
        existing.title = spec.title;
      }
      if (spec.href) {
        existing.href = preferRestoreHref(spec.href);
        existing.key = key;
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
      key: key,
      href: preferRestoreHref(spec.href),
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

    if (spec.href) {
      spec.href = withAppBase(spec.href) || spec.href;
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
            global.location.href = withAppBase(win.href) || win.href;
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
        global.location.href = withAppBase(win.href) || win.href;
      }
      return;
    }
    win.unsaved = false;
    onProceed();
  }

  function minimizeThisPage(goMenu) {
    var href = global.location.href;
    if (pageHasReportResults()) {
      href = withAppBase(withRunFlag(href)) || withRunFlag(href);
    }
    var key = identityKey(href);
    var existing = findByKey(key);
    var minimizeDetail = preparePageMinimize();
    var id;
    if (existing) {
      existing.unsaved = !!minimizeDetail.dirty;
      existing.href = href;
      existing.key = key;
      existing.title = currentPageTitle();
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
    var win = findWindow(id);
    if (win) {
      win.unsaved = !!minimizeDetail.dirty;
      win.href = href;
      win.key = key;
      win.title = currentPageTitle();
      win.fullPage = true;
    }
    capturePageState(win || { key: key, href: href });
    if (goMenu !== false && id && win) {
      if (!isMenuLikeLocation(global.location.href)) {
        parkLivePage(win, defaultHubUrl());
        persistWindowsNow();
        return id;
      }
      win.minimized = true;
      win.parkedLive = false;
      persistWindowsNow();
      syncTaskbar();
      return id;
    }
    if (win) {
      win.minimized = true;
      win.parkedLive = false;
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
    if (isParkedLiveWindow(win) || (win.parkedLive && isWindowCurrentPage(win))) {
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
    win.href = preferRestoreHref(withAppBase(win.href) || win.href);
    win.key = identityKey(win.href);
    // إن كان الرابط يعيد التقرير الجاهز (?run=1) لا نعيد إرسال النموذج بعد التحميل
    var needsResubmit = true;
    try {
      var uh = new URL(win.href, global.location.origin);
      if (uh.searchParams.get('run') === '1') {
        needsResubmit = false;
      }
    } catch (eRun) {
      /* ignore */
    }
    if (needsResubmit) {
      try {
        sessionStorage.setItem(RESTORE_PENDING_KEY, win.key || identityKey(win.href));
      } catch (e) {
        /* ignore */
      }
    } else {
      try {
        sessionStorage.removeItem(RESTORE_PENDING_KEY);
      } catch (eClr) {
        /* ignore */
      }
    }
    showBusyNav('جاري فتح الشاشة…');
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
      global.location.href = withAppBase(stripEmbedParams(defaultHubUrl()));
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
    href = withAppBase(href) || href;
    var key = identityKey(href);
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
    return createWindow(withAppBase(href) || href, title);
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

  function ensureMinimizeButton() {
    var btn = document.getElementById('app-mdi-minimize-screen');
    if (btn) {
      return btn;
    }
    if (isMenuLikeLocation(global.location.href)) {
      return null;
    }
    var close = document.querySelector(
      '.hx-ora-screen-title .ora12-title-bar__close, .hx-ora-screen-title .app-screen-exit-btn, header.dashboard-ora-screen-title .ora12-title-bar__close, .report-ora12-screen-title .ora12-title-bar__close, .app-screen-title-bar .ora12-title-bar__close, .ora12-title-bar__controls .app-screen-exit-btn, .app-screen-exit-btn'
    );
    var bar =
      document.querySelector(
        '.hx-ora-screen-title, header.dashboard-ora-screen-title, .report-ora12-screen-title, .app-screen-title-bar'
      ) || (close && close.closest('header, .hx-ora-screen-title, .dashboard-ora-screen-title, .report-ora12-screen-title'));
    if (!bar) {
      bar = close && close.parentNode;
    }
    if (!bar) {
      return null;
    }
    btn = document.createElement('button');
    btn.type = 'button';
    btn.id = 'app-mdi-minimize-screen';
    btn.className = 'ora12-title-bar__btn ora12-title-bar__minimize';
    btn.title = 'تصغير';
    btn.setAttribute('aria-label', 'تصغير');
    btn.innerHTML =
      '<svg viewBox="0 0 24 24" width="14" height="14" aria-hidden="true"><path d="M6 16h12" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"/></svg>';
    var closeEl = close || bar.querySelector('.ora12-title-bar__close, .app-screen-exit-btn');
    var controls =
      (bar.querySelector && bar.querySelector('.ora12-title-bar__controls')) ||
      (closeEl && closeEl.closest && closeEl.closest('.ora12-title-bar__controls'));
    if (controls && closeEl && controls.contains(closeEl)) {
      controls.insertBefore(btn, closeEl);
    } else if (closeEl && closeEl.parentNode) {
      closeEl.parentNode.insertBefore(btn, closeEl);
    } else if (controls) {
      controls.insertBefore(btn, controls.firstChild);
    } else {
      bar.appendChild(btn);
    }
    return btn;
  }

  function bindMinimizeButton(btn) {
    if (!btn || btn.getAttribute('data-mdi-min-bound') === '1') {
      return;
    }
    btn.setAttribute('data-mdi-min-bound', '1');
    btn.addEventListener('click', function (e) {
      e.preventDefault();
      minimizeThisPage(true);
    });
  }

  function initMdiUi() {
    ensureShell();
    restorePersistedWindows();
    convertLegacyIframeWindows();
    reconcileParkStateOnLoad();
    tryRestorePendingFilters();
    global.addEventListener('message', handleHubParentMessage);
    bindMinimizeButton(ensureMinimizeButton());
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
