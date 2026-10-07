'use strict';

const config = require('../config');
const nav = require('../nav');
const { esc } = require('./html');
const { iconFor, isPathActive } = require('./navIcons');
const basePath = require('./basePath');
const {
  wrapPrintShell,
  getPrintBrand,
  assetVersion,
  bodyPrintDataHtml,
} = require('./printBrand');

function faviconLinksHtml() {
  const brand = getPrintBrand();
  let href = brand.logoUrl || '';
  let type = 'image/png';
  if (!href) {
    href = basePath.ensurePrefixed('/assets/favicon.svg');
    type = 'image/svg+xml';
  } else {
    const lower = href.toLowerCase();
    if (lower.includes('.svg')) type = 'image/svg+xml';
    else if (lower.includes('.jpg') || lower.includes('.jpeg')) type = 'image/jpeg';
    else if (lower.includes('.webp')) type = 'image/webp';
    else if (lower.includes('.ico')) type = 'image/x-icon';
    else type = 'image/png';
  }
  const h = esc(href);
  return (
    `<link rel="icon" href="${h}" type="${esc(type)}">\n` +
    `<link rel="shortcut icon" href="${h}">\n` +
    `<link rel="apple-touch-icon" href="${h}">`
  );
}

function phpUrl(route, extra = '') {
  const extraStr = extra || '';
  const rel = route
    ? `/index.php?r=${encodeURIComponent(route)}${extraStr}`
    : '/index.php';
  const base = String(config.phpBaseUrl || '').replace(/\/$/, '');
  const isLoopback = !base || /127\.0\.0\.1|localhost/i.test(base);
  // من نطاق عام (مثل 176.x) لا نفتح iframe نحو localhost — Chrome يحظر Private Network Access
  if (isLoopback) {
    return basePath.ensurePrefixed(rel);
  }
  return `${base}${rel}`;
}

/** مسار الطلب الحالي — يُضبط من وسيط Express حتى يظهر شريط الخروج في كل الشاشات */
let currentRequestPath = '';
let currentRequestEmbed = '';

function setRequestPath(p, embed) {
  currentRequestPath = String(p || '').trim();
  if (embed !== undefined) {
    currentRequestEmbed = String(embed || '').trim();
  }
}

function isMdiEmbedRequest() {
  return currentRequestEmbed === '1' || currentRequestEmbed === 'menu';
}

function normalizeAppPath(p) {
  let path = String(p || '').trim();
  if (!path) return '';
  if (!path.startsWith('/')) path = '/' + path;
  if (path.length > 1 && path.endsWith('/')) path = path.slice(0, -1);
  return path;
}

function isDashboardPath(path) {
  return !path || path === '/' || path === '/app' || path === '/login';
}

function screenExitHref(activePath) {
  const path = normalizeAppPath(currentRequestPath || activePath);
  if (isDashboardPath(path)) return '';
  return '/app';
}

function wrapScreenChrome(title, bodyHtml, activePath) {
  const path = normalizeAppPath(currentRequestPath || activePath);
  if (isDashboardPath(path)) return bodyHtml;
  const href = screenExitHref(activePath) || '/app';
  const minimizeBtn = isMdiEmbedRequest()
    ? ''
    : `<button type="button" class="ora12-title-bar__btn ora12-title-bar__minimize" id="app-mdi-minimize-screen" title="تصغير" aria-label="تصغير"><svg viewBox="0 0 24 24" width="14" height="14" aria-hidden="true"><path d="M6 16h12" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"/></svg></button>`;
  return `<div class="hx-ora-screen">
      <header class="hx-ora-screen-title dashboard-ora-screen-title no-print" role="banner">
        <h1 class="dashboard-ora-screen-title__text">${esc(title || '')}</h1>
        <div class="ora12-title-bar__controls no-print">
          ${minimizeBtn}
          <a class="ora12-title-bar__close app-screen-exit-btn" href="${esc(href)}"
             title="خروج من الشاشة" aria-label="خروج من الشاشة">
            <svg class="app-screen-exit-btn__icon" viewBox="0 0 24 24" width="14" height="14" aria-hidden="true" focusable="false">
              <path d="M7 7l10 10M17 7L7 17" fill="none" stroke="currentColor" stroke-width="2.25" stroke-linecap="round"/>
            </svg>
          </a>
        </div>
      </header>
      <div class="hx-ora-screen-body">${bodyHtml}</div>
    </div>`;
}

function renderMdiLayer() {
  return `<div id="app-mdi-hub-overlay" class="app-mdi-hub-overlay no-print" hidden aria-hidden="true">
    <iframe id="app-mdi-hub-frame" class="app-mdi-hub-frame" title="القائمة"></iframe>
  </div>
  <div id="app-mdi-layer" class="app-mdi-layer no-print" aria-hidden="true"></div>
  <div id="app-mdi-taskbar" class="app-mdi-taskbar no-print" hidden>
    <div class="app-mdi-taskbar-windows"></div>
  </div>`;
}

function embedUrl(route, extra = '') {
  let e = String(extra || '');
  if (e.startsWith('&') || e.startsWith('?')) e = e.slice(1);
  return e ? `/embed/${encodeURIComponent(route)}?${e}` : `/embed/${encodeURIComponent(route)}`;
}

function isTopNavScreen(it) {
  const r = String((it && it.r) || '');
  return r !== '' && !r.startsWith('dashboard_');
}

function topNavChevron() {
  return `<svg class="hx-nav-drop__chev" viewBox="0 0 24 24" width="14" height="14" aria-hidden="true"><path d="M15 6l-6 6 6 6" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>`;
}

function renderTopNavDropLink(it, activePath) {
  const href = it.path || '#';
  const active =
    href !== '#' && activePath && (activePath === href || activePath.startsWith(`${href}/`))
      ? ' is-active'
      : '';
  return `<a class="hx-nav-drop__item${active}" href="${esc(href)}">${esc(it.label || '')}</a>`;
}

function renderTopNavDropGroup(group, activePath) {
  const items = (group.items || []).filter(isTopNavScreen);
  if (!items.length) return '';
  if (items.length === 1) return renderTopNavDropLink(items[0], activePath);
  const kids = items.map((it) => renderTopNavDropLink(it, activePath)).join('');
  return `<div class="hx-nav-sub">
    <button type="button" class="hx-nav-drop__item hx-nav-drop__item--has-sub">
      <span>${esc(group.title || '')}</span>${topNavChevron()}
    </button>
    <div class="hx-nav-fly">${kids}</div>
  </div>`;
}

function renderTopNavTrigger(id, title, href, activePath, dropHtml) {
  const active = isPathActive(id, href, activePath) ? ' is-active' : '';
  const icon = `<span class="nav-domain-link__icon" aria-hidden="true">${iconFor(id)}</span><span class="nav-domain-link__label">${esc(title)}</span>`;
  if (!dropHtml) {
    return `<a class="nav-domain-link${active}" href="${esc(href)}" data-domain="${esc(id)}" data-nav-path="${esc(href)}">${icon}</a>`;
  }
  return `<div class="hx-nav-item">
    <button type="button" class="nav-domain-link${active}" data-nav-menu="1" aria-expanded="false" aria-haspopup="true" data-domain="${esc(id)}" data-nav-path="${esc(href)}">${icon}</button>
    <div class="hx-nav-drop">${dropHtml}</div>
  </div>`;
}

function renderSidebar(user, activePath = '', notifyBellHtml = '', pageTitle = '') {
  const parts = [];
  for (const domain of nav.DOMAIN_CATALOGS || []) {
    const hub = nav.domainHubContent(user, domain.id);
    if (!hub) continue;
    const groups = (hub.groups || [])
      .map((g) => ({ title: g.title, items: (g.items || []).filter(isTopNavScreen) }))
      .filter((g) => g.items.length);
    if (!groups.length) continue;
    const leafCount = groups.reduce((n, g) => n + g.items.length, 0);
    const dropHtml =
      hub.id === 'main' && leafCount <= 1 ? '' : groups.map((g) => renderTopNavDropGroup(g, activePath)).join('');
    parts.push(renderTopNavTrigger(hub.id, hub.title, hub.hub, activePath, dropHtml));
  }
  for (const it of nav.buildSidebar(user)) {
    if (it.id === 'favorites') {
      parts.push(renderTopNavTrigger(it.id, it.title, it.path, activePath, ''));
    }
  }
  const itemsHtml = parts.join('');

  const brand = getPrintBrand();
  const companyName = brand.companyName || 'Hypex';
  const screenTitle = String(pageTitle || '').trim() || 'لوحة التحكم';
  const userLabel = String(
    (user && (user.full_name_ar || user.username)) || ''
  ).trim();

  return `<header class="hx-topnav no-print" data-active-path="${esc(activePath || '')}">
    <div class="hx-topnav__title">${esc(companyName)} — ${esc(screenTitle)}</div>
    <div class="hx-topnav__menu">
      <nav class="hx-topnav__nav" aria-label="القائمة الرئيسية">${itemsHtml}</nav>
      <div class="hx-topnav__tools">
        ${notifyBellHtml || ''}
        <div class="hx-topnav__account">
          ${
            userLabel
              ? `<span class="hx-topnav__user" title="${esc(userLabel)}">${esc(userLabel)}</span>`
              : ''
          }
          <form method="post" action="/logout">
            <button type="submit" class="sidebar-logout" title="تسجيل خروج" aria-label="تسجيل خروج">خروج</button>
          </form>
        </div>
      </div>
    </div>
  </header>`;
}

function renderApp({
  user,
  title,
  bodyHtml,
  css = [],
  js = [],
  extraHead = '',
  bodyClass = '',
  mainClass = 'main main--wide',
  activePath = '',
  /** ترويسة/تذييل الطباعة عبر iframe (افتراضي مفعّل) */
  printChrome = true,
  printTitle = '',
  notifyBellHtml = '',
}) {
  getPrintBrand();

  let bellHtml = notifyBellHtml || '';
  if (user && !bellHtml) {
    try {
      const { userCanSeeBell, emptyPayload } = require('../notifications/inboxService');
      const { renderBellShell } = require('../notifications/bellHtml');
      if (userCanSeeBell(user)) {
        const shell = emptyPayload();
        shell.enabled = true;
        bellHtml = renderBellShell(shell);
      }
    } catch {
      bellHtml = '';
    }
  }
  const base = basePath.basePath || '';
  const spVer = assetVersion('js/sales-print.js');
  const scVer = assetVersion('js/hx-shortcuts.js');
  const scCssVer = assetVersion('css/hx-shortcuts.css');
  const uiVer = assetVersion('js/hx-ui.js');
  const uiCssVer = assetVersion('css/hx-ui.css');
  const decVer = assetVersion('js/hx-decimals.js');
  const fmtVer = assetVersion('js/app-format.js');
  const dateJsVer = assetVersion('js/app-date-picker.js');
  const dateCssVer = assetVersion('css/app-date-picker.css');
  const uiDlgJsVer = assetVersion('js/ui-dialog.js');
  const uiDlgCssVer = assetVersion('css/ui-dialog.css');
  const exitGuardVer = assetVersion('js/screen-exit-guard.js');
  const allCss = [...css];
  const allJs = [...js];
  if (printChrome && user) {
    const hasPrint = allJs.some((j) => String(j).indexOf('sales-print.js') !== -1);
    if (!hasPrint) allJs.push(`/assets/js/sales-print.js?v=${spVer}`);
  }
  // واجهة تنبيهات/تأكيد + اختصارات + تواريخ يوم-شهر-سنة لكل الشاشات
  if (user) {
    // جلد Oracle Forms لكل شاشات النظام (قوائم / تقارير / مستندات)
    if (!/\bco-ora-body\b/.test(String(bodyClass || ''))) {
      bodyClass = `${String(bodyClass || '').trim()} co-ora-body`.trim();
    }
    const hasSalesCss = allCss.some((c) => String(c).indexOf('sales-2027.css') !== -1);
    if (!hasSalesCss) allCss.push('/assets/css/sales-2027.css');
    const hasOraCss = allCss.some((c) => String(c).indexOf('customer-order-ora.css') !== -1);
    if (!hasOraCss) allCss.push('/assets/css/customer-order-ora.css');
    const hasOraGlobal = allCss.some((c) => String(c).indexOf('hypex-ora-global.css') !== -1);
    if (!hasOraGlobal) allCss.push('/assets/css/hypex-ora-global.css');
    if (!isMdiEmbedRequest()) {
      const hasMdiCss = allCss.some((c) => String(c).indexOf('app-window-manager.css') !== -1);
      if (!hasMdiCss) allCss.push('/assets/css/app-window-manager.css');
      const hasMdiJs = allJs.some((j) => String(j).indexOf('app-window-manager.js') !== -1);
      if (!hasMdiJs) allJs.push('/assets/js/app-window-manager.js');
    }

    const hasDec = allJs.some((j) => String(j).indexOf('hx-decimals.js') !== -1);
    if (!hasDec) allJs.unshift(`/assets/js/hx-decimals.js?v=${decVer}`);
    const hasUi = allJs.some((j) => String(j).indexOf('hx-ui.js') !== -1);
    if (!hasUi) allJs.unshift(`/assets/js/hx-ui.js?v=${uiVer}`);
    const hasUiCss = allCss.some((c) => String(c).indexOf('hx-ui.css') !== -1);
    if (!hasUiCss) allCss.unshift(`/assets/css/hx-ui.css?v=${uiCssVer}`);
    // حوار حفظ/عدم حفظ عند مغادرة شاشة بها تعديلات — قبل سكربتات الصفحات
    const hasDlgCss = allCss.some((c) => String(c).indexOf('ui-dialog.css') !== -1);
    if (!hasDlgCss) allCss.unshift(`/assets/css/ui-dialog.css?v=${uiDlgCssVer}`);
    const hasExitGuard = allJs.some((j) => String(j).indexOf('screen-exit-guard.js') !== -1);
    if (!hasExitGuard) allJs.unshift(`/assets/js/screen-exit-guard.js?v=${exitGuardVer}`);
    const hasDlgJs = allJs.some((j) => String(j).indexOf('ui-dialog.js') !== -1);
    if (!hasDlgJs) allJs.unshift(`/assets/js/ui-dialog.js?v=${uiDlgJsVer}`);
    const hasSc = allJs.some((j) => String(j).indexOf('hx-shortcuts.js') !== -1);
    if (!hasSc) allJs.push(`/assets/js/hx-shortcuts.js?v=${scVer}`);
    const hasScCss = allCss.some((c) => String(c).indexOf('hx-shortcuts.css') !== -1);
    if (!hasScCss) allCss.unshift(`/assets/css/hx-shortcuts.css?v=${scCssVer}`);
    // تاريخ: يوم-شهر-سنة (عرض وإدخال) — قبل سكربتات الصفحات
    const hasDateCss = allCss.some((c) => String(c).indexOf('app-date-picker.css') !== -1);
    if (!hasDateCss) allCss.unshift(`/assets/css/app-date-picker.css?v=${dateCssVer}`);
    const hasDateJs = allJs.some((j) => String(j).indexOf('app-date-picker.js') !== -1);
    const hasFmt = allJs.some((j) => String(j).indexOf('app-format.js') !== -1);
    if (!hasDateJs) allJs.unshift(`/assets/js/app-date-picker.js?v=${dateJsVer}`);
    if (!hasFmt) allJs.unshift(`/assets/js/app-format.js?v=${fmtVer}`);
    // تنقّل Enter / الأسهم بين الحقول في كل الشاشات
    const hasFieldNav = allJs.some((j) => String(j).indexOf('hx-field-nav.js') !== -1);
    if (!hasFieldNav) allJs.push('/assets/js/hx-field-nav.js');
    const hasListKb = allJs.some((j) => String(j).indexOf('app-list-keyboard.js') !== -1);
    if (!hasListKb) allJs.push('/assets/js/app-list-keyboard.js');
    // جرس التنبيهات (طلبات اعتماد / مستندات غير مرحّلة / شيكات…)
    if (bellHtml) {
      const hasBellCss = allCss.some((c) => String(c).indexOf('header-check-notifications.css') !== -1);
      if (!hasBellCss) {
        allCss.push('/assets/css/check-alerts-modal.css');
        allCss.push('/assets/css/header-check-notifications.css');
      }
      const hasBellJs = allJs.some((j) => String(j).indexOf('header-notifications.js') !== -1);
      if (!hasBellJs) allJs.push('/assets/js/header-notifications.js');
    }
  }

  const cssLinks = allCss
    .map((c) => {
      let href = c;
      if (c.startsWith('/assets/') && !String(c).includes('?')) {
        const rel = c.replace(/^\/assets\//, '');
        try {
          href = `${c}?v=${assetVersion(rel)}`;
        } catch {
          href = c;
        }
      }
      return `<link rel="stylesheet" href="${esc(href)}">`;
    })
    .join('\n');
  const jsLinks = allJs
    .map((j) => {
      let src = j;
      if (j.startsWith('/assets/') && !String(j).includes('?')) {
        const rel = j.replace(/^\/assets\//, '');
        src = `${j}?v=${assetVersion(rel)}`;
      }
      return `<script src="${esc(src)}" defer></script>`;
    })
    .join('\n');

  const bodyCls = ['app-body', bodyClass, user ? 'has-topnav' : '', printChrome && user ? 'has-print-chrome' : '']
    .filter(Boolean)
    .join(' ');
  const mainCls = mainClass || 'main main--wide';
  const printed = printChrome && user ? wrapPrintShell(bodyHtml) : bodyHtml;
  const mainBody = user ? wrapScreenChrome(title, printed, activePath) : printed;
  const printAttrs =
    printChrome && user
      ? bodyPrintDataHtml({ user, documentTitle: printTitle || title })
      : '';

  let decimalsScript = '';
  try {
    const companyDecimals = require('./companyDecimals');
    const snap = companyDecimals.snapshot();
    decimalsScript = `<script>window.__HYPEX_DECIMALS__=${JSON.stringify(snap)};</script>`;
  } catch {
    decimalsScript = `<script>window.__HYPEX_DECIMALS__={"amount":3,"unit":3};</script>`;
  }

  return `<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, minimum-scale=1, user-scalable=no, viewport-fit=cover">
  <meta http-equiv="Cache-Control" content="no-store, no-cache, must-revalidate">
  <meta name="hx-print-engine" content="standalone-v3">
  <meta name="theme-color" content="#1e3a5f">
  <meta name="mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-capable" content="yes">
  <link rel="manifest" href="/manifest.php">
  <title>${esc(title)} · ${esc(getPrintBrand().companyName || 'Hypex')}</title>
  ${faviconLinksHtml()}
  <script>window.__HYPEX_BASE__=${JSON.stringify(base)};</script>
  ${decimalsScript}
  <script src="/assets/js/base-path.js"></script>
  <link rel="stylesheet" href="/assets/css/shell.css?v=${assetVersion('css/shell.css')}">
  ${cssLinks}
  ${extraHead}
</head>
<body class="${esc(bodyCls)}"${printAttrs}>
  <div class="app-shell">
    ${user ? renderSidebar(user, activePath, bellHtml, title) : ''}
    <main class="${esc(mainCls)}">
      ${mainBody}
    </main>
  </div>
  <script>
  (function(){
    var el=!!(window.hypexDesktop&&window.hypexDesktop.isElectron)||/\\bElectron\\//i.test(navigator.userAgent||'');
    if(el){document.documentElement.classList.add('hypex-desktop-app');document.body.classList.add('app-body--standalone','app-body--electron');}
    if(window.matchMedia('(display-mode: standalone)').matches||window.matchMedia('(display-mode: fullscreen)').matches){document.body.classList.add('app-body--standalone');}
  })();
  </script>
  ${
    user && !isMdiEmbedRequest()
      ? `<script>window.AppMdiConfig=${JSON.stringify({
          baseUrl: '/app',
          afterMinimizeUrl: '/app',
          currentRoute: normalizeAppPath(currentRequestPath || activePath) || '/app',
          currentTitle: title || '',
          routes: {},
          excludeRoutes: ['dashboard', 'menu_hub'],
        })};</script>
  ${renderMdiLayer()}`
      : ''
  }
  <script src="/assets/js/shell.js?v=${assetVersion('js/shell.js')}" defer></script>
  ${jsLinks}
</body>
</html>`;
}

/** @deprecated لم يعد يضمّن PHP — يُعاد التوجيه عبر /embed */
function phpEmbedPage({ user, title, phpRoute, extra = '', backHref = '/app' }) {
  const q = String(extra || '').replace(/^&/, '');
  const loc = q ? `/embed/${encodeURIComponent(phpRoute)}?${q}` : `/embed/${encodeURIComponent(phpRoute)}`;
  return renderApp({
    user,
    title: title || phpRoute,
    bodyHtml: `<div class="si-stage" style="padding:2rem"><p>جاري التحويل…</p><script>location.replace(${JSON.stringify(
      loc
    )})</script><a href="${esc(loc)}">متابعة</a> · <a href="${esc(backHref)}">رجوع</a></div>`,
    bodyClass: 'si-2027',
    mainClass: 'main si-main',
    printChrome: false,
  });
}

module.exports = {
  renderApp,
  phpUrl,
  embedUrl,
  phpEmbedPage,
  renderSidebar,
  faviconLinksHtml,
  setRequestPath,
  screenExitHref,
};
