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

function phpUrl(route, extra = '') {
  if (!route) return config.phpBaseUrl;
  return `${config.phpBaseUrl}/index.php?r=${encodeURIComponent(route)}${extra}`;
}

/** فتح شاشة داخل Node عبر /embed (بدون تبويب خارجي) */
function embedUrl(route, extra = '') {
  let e = String(extra || '');
  if (e.startsWith('&') || e.startsWith('?')) e = e.slice(1);
  return e ? `/embed/${encodeURIComponent(route)}?${e}` : `/embed/${encodeURIComponent(route)}`;
}

function renderSidebar(user, activePath = '') {
  const sidebarItems = nav.buildSidebar(user);
  const itemsHtml = sidebarItems
    .map((it) => {
      const href = it.path || '#';
      const id = it.id || '';
      const active = isPathActive(id, href, activePath) ? ' is-active' : '';
      return `<a class="nav-domain-link${active}" href="${esc(href)}" data-domain="${esc(id)}" data-nav-path="${esc(href)}">
        <span class="nav-domain-link__icon" aria-hidden="true">${iconFor(id)}</span>
        <span class="nav-domain-link__label">${esc(it.title)}</span>
        <span class="nav-domain-link__rail" aria-hidden="true"></span>
      </a>`;
    })
    .join('');

  const name = user.full_name_ar || user.username || '';
  const initial = String(name).trim().charAt(0) || 'U';
  const brand = getPrintBrand();
  const companyName = brand.companyName || 'Hypex';
  const markHtml = brand.logoUrl
    ? `<span class="brand-mark brand-mark--logo" aria-hidden="true"><img src="${esc(brand.logoUrl)}" alt="" width="40" height="40"></span>`
    : `<span class="brand-mark" aria-hidden="true">${esc(String(companyName).charAt(0) || 'H')}</span>`;

  return `<aside class="sidebar sidebar--2027 no-print" data-active-path="${esc(activePath || '')}">
    <div class="sidebar-brand">
      ${markHtml}
      <div class="sidebar-brand__text">
        <strong title="${esc(companyName)}">${esc(companyName)}</strong>
      </div>
    </div>
    <p class="sidebar-section-label">الأقسام</p>
    <nav class="sidebar-nav sidebar-nav--domains" aria-label="القائمة الرئيسية">${itemsHtml}</nav>
    <div class="sidebar-foot">
      <div class="user-chip">
        <span class="user-chip__avatar" aria-hidden="true">${esc(initial)}</span>
        <div class="user-chip__meta">
          <strong>${esc(name)}</strong>
          <span dir="ltr">@${esc(user.username || '')}</span>
        </div>
      </div>
      <form method="post" action="/logout">
        <button type="submit" class="sidebar-logout">خروج</button>
      </form>
    </div>
  </aside>`;
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
}) {
  getPrintBrand();

  const base = basePath.basePath || '';
  const spVer = assetVersion('js/sales-print.js');
  const allCss = [...css];
  const allJs = [...js];
  if (printChrome && user) {
    const hasPrint = allJs.some((j) => String(j).indexOf('sales-print.js') !== -1);
    if (!hasPrint) allJs.push(`/assets/js/sales-print.js?v=${spVer}`);
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

  const bodyCls = ['app-body', bodyClass, printChrome && user ? 'has-print-chrome' : '']
    .filter(Boolean)
    .join(' ');
  const mainCls = mainClass || 'main main--wide';
  const mainBody = printChrome && user ? wrapPrintShell(bodyHtml) : bodyHtml;
  const printAttrs =
    printChrome && user
      ? bodyPrintDataHtml({ user, documentTitle: printTitle || title })
      : '';

  return `<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta http-equiv="Cache-Control" content="no-store, no-cache, must-revalidate">
  <meta name="hx-print-engine" content="standalone-v3">
  <title>${esc(title)} · ${esc(getPrintBrand().companyName || 'Hypex')}</title>
  <script>window.__HYPEX_BASE__=${JSON.stringify(base)};</script>
  <script src="/assets/js/base-path.js"></script>
  <link rel="stylesheet" href="/assets/css/shell.css">
  ${cssLinks}
  ${extraHead}
</head>
<body class="${esc(bodyCls)}"${printAttrs}>
  <div class="app-shell">
    ${user ? renderSidebar(user, activePath) : ''}
    <main class="${esc(mainCls)}">
      ${mainBody}
    </main>
  </div>
  <script src="/assets/js/shell.js" defer></script>
  ${jsLinks}
</body>
</html>`;
}

/** تضمين شاشة PHP داخل غلاف Node */
function phpEmbedPage({ user, title, phpRoute, extra = '', backHref = '/app' }) {
  const src = phpUrl(phpRoute, extra);
  const bodyHtml = `
    <div class="embed-stage">
      <header class="embed-bar no-print">
        <div>
          <strong class="embed-kicker">Hypex Node</strong>
          <h1>${esc(title)}</h1>
        </div>
        <div class="embed-bar-actions">
          <a class="btn" href="${esc(backHref)}">القسم</a>
          <a class="btn" href="/app">لوحة التحكم</a>
        </div>
      </header>
      <iframe class="php-embed-frame" src="${esc(src)}" title="${esc(title)}"></iframe>
    </div>`;
  return renderApp({
    user,
    title,
    bodyHtml,
    bodyClass: 'embed-app',
    mainClass: 'main main--embed',
    printChrome: false,
  });
}

module.exports = {
  renderApp,
  phpUrl,
  embedUrl,
  phpEmbedPage,
  renderSidebar,
};
