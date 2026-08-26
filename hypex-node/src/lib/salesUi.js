'use strict';

const { esc, fmtAmt, fmtUnitPrice, isoToDmy, todayIso } = require('../lib/html');
const { renderApp, phpUrl, embedUrl } = require('../lib/layout');
const { salesCatalog } = require('../sales/catalog');

const SALES_CSS = ['/assets/css/sales-2027.css'];

const {
  BACK_ICON_SVG,
  PRINT_ICON_SVG,
  backAction,
  printAction,
  backLinkHtml,
  printBtnHtml,
  siPrintBtnHtml,
  siBackLinkHtml,
  heroActionHtml,
} = require('./toolbarIcons');

/** إزالة ذكر Node/Node.js من العناوين الفرعية والنصوص التسويقية */
function stripNodeMarketing(text) {
  if (!text || typeof text !== 'string') return '';
  const hadNode = /\bNode(\.js)?\b/i.test(text);
  let s = text.replace(/\s*·\s*Node(\.js)?\.?\s*$/gi, '').trim();
  const parts = s.split(/\s*[—–]\s*/).filter(Boolean);
  const kept = parts
    .filter((p) => !/\bNode(\.js)?\b/i.test(p))
    .map((p) => p.replace(/\s*(?:داخل|على|من|في|واجهة)\s+Node(\.js)?/gi, '').trim())
    .filter(Boolean);
  s = kept.join(' — ').replace(/\s{2,}/g, ' ').trim();
  if (hadNode && /^(?:قائمة|جميع)\s+\S+$/u.test(s)) return '';
  return s;
}

function salesPage({ user, title, bodyHtml, js = [], css = [], activePath = '', printTitle = '' }) {
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
    printChrome: true,
    printTitle: printTitle || title,
  });
}

function hero(opts) {
  const {
    mark = '',
    kicker = '',
    title,
    subtitle = '',
    actions = [],
  } = opts;
  const cleanKickerRaw = stripNodeMarketing(kicker);
  const cleanKicker =
    /\bNode(\.js)?\b/i.test(kicker) && /^Hypex\b/i.test(cleanKickerRaw) ? '' : cleanKickerRaw;
  const cleanSubtitle = stripNodeMarketing(subtitle);
  const acts = actions
    .map((a) => {
      if (a.icon === 'back' || a.icon === 'print') {
        const cls = a.primary ? 'si-btn si-btn--primary' : a.ghost ? 'si-btn si-btn--ghost' : 'si-btn';
        const iconHtml = heroActionHtml(a, cls);
        if (iconHtml) return iconHtml;
      }
      const cls = a.primary ? 'si-btn si-btn--primary' : a.ghost ? 'si-btn si-btn--ghost' : 'si-btn';
      if (a.onclick || a.print) {
        return `<button type="button" class="${cls} ${a.print ? 'si-btn--print no-print' : 'no-print'}" data-print="1">${esc(a.label)}</button>`;
      }
      // زر إرسال لنموذج في الصفحة (form="…") — يسمح بوضع أزرار الحفظ في الترويسة
      if (a.submit) {
        const formAttr = a.form ? ` form="${esc(a.form)}"` : '';
        const nameAttr = a.name ? ` name="${esc(a.name)}" value="${esc(a.value ?? '')}"` : '';
        const titleAttr = a.title ? ` title="${esc(a.title)}"` : '';
        return `<button type="submit" class="${cls} no-print"${formAttr}${nameAttr}${titleAttr}${
          a.hxSave ? ' data-hx-save="1"' : ''
        }>${esc(a.label)}</button>`;
      }
      const target = a.external ? ' target="_blank" rel="noopener"' : '';
      const extraCls = a.className ? ` ${a.className}` : '';
      return `<a class="${cls}${extraCls}${a.external || a.ghost ? ' no-print' : ' no-print'}" href="${esc(a.href || '#')}"${target}>${esc(a.label)}</a>`;
    })
    .join('');
  const markHtml = mark
    ? `<div class="si-brand-mark" aria-hidden="true">${esc(mark)}</div>`
    : '';
  const kickerHtml = cleanKicker ? `<p class="si-kicker">${esc(cleanKicker)}</p>` : '';
  // العناوين فقط — بدون نصوص تسويقية/إرشادية افتراضية
  return `
    <header class="si-hero">
      <div class="si-brand-lockup">
        ${markHtml}
        <div class="si-brand-text">
          ${kickerHtml}
          <h1>${esc(title)}</h1>
          ${cleanSubtitle ? `<p class="si-hero-sub">${esc(cleanSubtitle)}</p>` : ''}
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
          <input class="si-field si-field--mono" type="date" name="from" value="${esc(f)}" style="min-height:2.1rem;width:auto" title="يوم-شهر-سنة">
        </label>
        <label style="display:flex;align-items:center;gap:.35rem;font-size:.8rem;font-weight:700;color:#5c6578">إلى
          <input class="si-field si-field--mono" type="date" name="to" value="${esc(t)}" style="min-height:2.1rem;width:auto" title="يوم-شهر-سنة">
        </label>
        ${extra}
        <button class="si-btn si-btn--primary" type="submit">عرض</button>
        ${siPrintBtnHtml('طباعة')}
      </form>
    </div>
    <div class="si-print-meta print-only">
      <strong>الفترة:</strong> <span dir="ltr">${esc(isoToDmy(f))}</span> — <span dir="ltr">${esc(isoToDmy(t))}</span>
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
  return `
    <section class="si-surface">
      <div class="si-surface-head">
        <h2>${esc(title)}</h2>
        <a class="si-btn si-btn--icon no-print" href="${esc(backHref)}" aria-label="${esc(backLabel)}" title="${esc(backLabel)}">${BACK_ICON_SVG}</a>
      </div>
      <div style="padding:1rem 1.1rem 1.25rem">
        <p style="margin:0 0 .75rem;color:#5c6578;font-size:.9rem;line-height:1.5">${esc(stripNodeMarketing(desc))}</p>
        <a class="si-btn si-btn--primary" href="/embed/${encodeURIComponent(phpRoute)}">فتح الشاشة</a>
      </div>
    </section>`;
}

/** لم يعد يضمّن PHP — يوجّه إلى /embed */
function phpEmbedBlock(phpRoute, title = '') {
  return `<div class="si-surface" style="padding:1rem"><a class="si-btn si-btn--primary" href="/embed/${encodeURIComponent(
    phpRoute
  )}">${esc(title || phpRoute)}</a></div>`;
}

/** أعمدة جدول بنود فاتورة البيع / طلب شراء العميل — العروض في sales-2027.css */
function linesColgroup() {
  return `<colgroup>
    <col class="co-c-idx"><col class="co-c-sku"><col class="co-c-code"><col class="co-c-name"><col class="co-c-unit">
    <col class="co-c-qty"><col class="co-c-extra"><col class="co-c-price"><col class="co-c-disc">
    <col class="co-c-tax"><col class="co-c-net"><col class="co-c-total"><col class="co-c-del">
  </colgroup>`;
}

/**
 * رابط كشف حساب Oracle التفصيلي داخل Node — بدل رابط PHP الذي يطلب تسجيل دخول منفصل.
 * يرجع '' إن كان العميل غير محدد أو المستخدم بلا صلاحية التقرير (فيُخفى الزر).
 */
function oracleStatementUrl(user, customerId, data = {}) {
  const auth = require('../auth');
  const basePath = require('./basePath');
  const cid = Number(customerId) || 0;
  if (cid < 1 || !user) return '';
  if (!user.is_admin && !auth.userCan(user, 'report_oracle_customer_statement')) return '';
  const qs = new URLSearchParams({ customer_id: String(cid), run: '1' });
  if (data.from) qs.set('from', String(data.from));
  if (data.to) qs.set('to', String(data.to));
  if (data.account) qs.set('account_no', String(data.account));
  return basePath.ensurePrefixed('/accounting/reports/oracle-statement?' + qs.toString());
}

module.exports = {
  salesPage,
  oracleStatementUrl,
  linesColgroup,
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
  fmtUnitPrice,
  isoToDmy,
  esc,
  phpUrl,
  embedUrl,
  todayIso,
  monthStart,
  SALES_CSS,
  BACK_ICON_SVG,
  PRINT_ICON_SVG,
  backAction,
  printAction,
  backLinkHtml,
  printBtnHtml,
  siPrintBtnHtml,
  siBackLinkHtml,
};
