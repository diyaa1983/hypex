'use strict';

const config = require('../config');
const nav = require('../nav');
const { esc } = require('./html');
const { iconFor, isPathActive } = require('./navIcons');
const basePath = require('./basePath');
const { printChromeHtml, getPrintBrand } = require('./printBrand');

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

  return `<aside class="sidebar sidebar--2027 no-print" data-active-path="${esc(activePath || '')}">
    <div class="sidebar-brand">
      <span class="brand-mark" aria-hidden="true">H</span>
      <div class="sidebar-brand__text">
        <strong>Hypex</strong>
        <small>Node · 2027</small>
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
  /** false لتعطيل الترويسة/التذييل (نادر — مثل شاشة login / iframe) */
  printChrome = true,
  printTitle = '',
}) {
  getPrintBrand();

  const base = basePath.basePath || '';
  const allCss = ['/assets/css/print-chrome.css', ...css];
  const allJs = [...js];
  if (printChrome && user && !allJs.includes('/assets/js/sales-print.js')) {
    allJs.push('/assets/js/sales-print.js');
  }
  const cssLinks = allCss.map((c) => `<link rel="stylesheet" href="${esc(c)}">`).join('\n');
  const jsLinks = allJs.map((j) => `<script src="${esc(j)}" defer></script>`).join('\n');
  const bodyCls = ['app-body', bodyClass, printChrome && user ? 'has-print-chrome' : '']
    .filter(Boolean)
    .join(' ');
  const mainCls = mainClass || 'main main--wide';
  const chrome =
    printChrome && user
      ? printChromeHtml({ user, documentTitle: printTitle || title })
      : '';

  return `<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>${esc(title)} · Hypex</title>
  <script>window.__HYPEX_BASE__=${JSON.stringify(base)};</script>
  <script src="/assets/js/base-path.js"></script>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans+Arabic:wght@400;500;600;700&family=Outfit:wght@500;600;700;800&family=JetBrains+Mono:wght@500;600&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="/assets/css/shell.css">
  ${cssLinks}
  ${extraHead}
</head>
<body class="${esc(bodyCls)}">
  ${chrome}
  <div class="app-shell">
    ${user ? renderSidebar(user, activePath) : ''}
    <main class="${esc(mainCls)}">
      ${bodyHtml}
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
