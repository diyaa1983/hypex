'use strict';

const { esc, fmtAmt, isoToDmy, todayIso } = require('../lib/html');
const { renderApp, phpUrl, embedUrl } = require('../lib/layout');
const { salesCatalog } = require('../sales/catalog');

const SALES_CSS = ['/assets/css/sales-2027.css'];

function salesPage({ user, title, bodyHtml, js = [], css = [], activePath = '', printTitle = '' }) {
  void printTitle;
  const printJs = js.includes('/assets/js/sales-print.js') ? js : [...js, '/assets/js/sales-print.js'];
  return renderApp({
    user,
    title,
    bodyHtml,
    bodyClass: 'si-2027',
    mainClass: 'main si-main',
    css: [...SALES_CSS, ...css],
    js: printJs,
    activePath,
    printChrome: false,
  });
}

function hero(opts) {
  const {
    mark = 'Hx',
    kicker = 'Hypex Sales · Node',
    title,
    subtitle = '',
    actions = [],
  } = opts;
  const acts = actions
    .map((a) => {
      const cls = a.primary ? 'si-btn si-btn--primary' : a.ghost ? 'si-btn si-btn--ghost' : 'si-btn';
      if (a.onclick || a.print) {
        return `<button type="button" class="${cls} ${a.print ? 'si-btn--print no-print' : 'no-print'}" data-print="1">${esc(a.label)}</button>`;
      }
      const target = a.external ? ' target="_blank" rel="noopener"' : '';
      const extraCls = a.className ? ` ${a.className}` : '';
      return `<a class="${cls}${extraCls}${a.external || a.ghost ? ' no-print' : ' no-print'}" href="${esc(a.href || '#')}"${target}>${esc(a.label)}</a>`;
    })
    .join('');
  return `
    <header class="si-hero">
      <div class="si-brand-lockup">
        <div class="si-brand-mark" aria-hidden="true">${esc(mark)}</div>
        <div class="si-brand-text">
          <p class="si-kicker">${esc(kicker)}</p>
          <h1>${esc(title)}</h1>
          ${subtitle ? `<p>${subtitle}</p>` : ''}
        </div>
      </div>
      <div class="si-hero-actions">${acts}</div>
    </header>`;
}

function railSearch(action, q, hidden = {}) {
  const hiddens = Object.entries(hidden)
    .map(([k, v]) => `<input type="hidden" name="${esc(k)}" value="${esc(v)}">`)
    .join('');
  return `
    <div class="si-rail">
      <form class="si-search" method="get" action="${esc(action)}" style="max-width:100%;margin:0;flex:1">
        ${hiddens}
        <input type="search" name="q" value="${esc(q || '')}" placeholder="بحث…" autocomplete="off">
        <button class="si-btn si-btn--primary" type="submit">بحث</button>
      </form>
    </div>`;
}

function dateFilters(action, from, to, extra = '') {
  const f = from || monthStart();
  const t = to || todayIso();
  return `
    <div class="si-rail no-print">
      <form class="si-search" method="get" action="${esc(action)}" style="max-width:100%;margin:0;display:flex;flex-wrap:wrap;gap:.4rem;align-items:center">
        <label style="display:flex;align-items:center;gap:.35rem;font-size:.8rem;font-weight:700;color:#5c6578">من
          <input class="si-field si-field--mono" type="date" name="from" value="${esc(f)}" style="min-height:2.1rem;width:auto">
        </label>
        <label style="display:flex;align-items:center;gap:.35rem;font-size:.8rem;font-weight:700;color:#5c6578">إلى
          <input class="si-field si-field--mono" type="date" name="to" value="${esc(t)}" style="min-height:2.1rem;width:auto">
        </label>
        ${extra}
        <button class="si-btn si-btn--primary" type="submit">عرض</button>
        <button type="button" class="si-btn si-btn--print" data-print="1">🖨 طباعة</button>
      </form>
    </div>
    <div class="si-print-meta print-only">
      <strong>الفترة:</strong> <span dir="ltr">${esc(f)}</span> — <span dir="ltr">${esc(t)}</span>
      · طُبع: <span class="si-print-when" dir="ltr"></span>
    </div>`;
}

function monthStart() {
  const d = new Date();
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-01`;
}

function tableSurface(title, countLabel, headers, rowsHtml) {
  const th = headers.map((h) => `<th>${esc(h)}</th>`).join('');
  return `
    <section class="si-surface">
      <div class="si-surface-head">
        <h2>${esc(title)}</h2>
        <span class="si-count">${esc(countLabel)}</span>
      </div>
      <div class="si-table-wrap">
        <table class="si-table">
          <thead><tr>${th}</tr></thead>
          <tbody>${rowsHtml}</tbody>
        </table>
      </div>
    </section>`;
}

function emptyRow(cols, msg = 'لا توجد بيانات') {
  return `<tr><td colspan="${cols}" class="empty">${esc(msg)}</td></tr>`;
}

function statusPill(kind, text) {
  const cls =
    kind === 'ok' ? 'si-pill si-pill--live' : kind === 'lock' ? 'si-pill si-pill--lock' : 'si-pill si-pill--wait';
  return `<span class="${cls}">${esc(text)}</span>`;
}

function hubTiles(userCan, user, catalog = salesCatalog) {
  return catalog
    .map((g) => {
      const tiles = g.items
        .filter((it) => !it.r || userCan(user, it.r) || user.is_admin)
        .map(
          (it) => `
          <a class="si-tile" href="${esc(it.path)}">
            <span class="si-tile-ico">${esc(it.icon)}</span>
            <span class="si-tile-label">${esc(it.label)}</span>
            <span class="si-tile-kind">${esc(it.kind)}</span>
          </a>`
        )
        .join('');
      if (!tiles) return '';
      return `
        <section class="si-surface" style="margin-top:.85rem">
          <div class="si-surface-head"><h2>${esc(g.title)}</h2></div>
          <div class="si-tiles">${tiles}</div>
        </section>`;
    })
    .join('');
}

function bridgeCard(title, phpRoute, desc, backHref = '/sales', backLabel = 'عودة') {
  const src = phpUrl(phpRoute);
  return `
    <section class="si-surface si-surface--embed">
      <div class="si-surface-head">
        <h2>${esc(title)}</h2>
        <a class="si-btn" href="${esc(backHref)}">${esc(backLabel)}</a>
      </div>
      <p style="margin:0;padding:.5rem 1.1rem 0;color:#5c6578;font-size:.85rem;line-height:1.5">${esc(desc)}</p>
      <iframe class="php-embed-frame php-embed-frame--in-card" src="${esc(src)}" title="${esc(title)}"></iframe>
    </section>`;
}

/** تضمين PHP كامل داخل صفحة 2027 */
function phpEmbedBlock(phpRoute, title = '') {
  return `<iframe class="php-embed-frame" src="${esc(phpUrl(phpRoute))}" title="${esc(title || phpRoute)}"></iframe>`;
}

module.exports = {
  salesPage,
  hero,
  railSearch,
  dateFilters,
  tableSurface,
  emptyRow,
  statusPill,
  hubTiles,
  bridgeCard,
  phpEmbedBlock,
  fmtAmt,
  isoToDmy,
  esc,
  phpUrl,
  embedUrl,
  todayIso,
  monthStart,
  SALES_CSS,
};
