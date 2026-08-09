'use strict';

/**
 * بيانات ترويسة/تذييل الطباعة الموحدة (شعار + اسم الشركة).
 * التكرار على كل صفحة عبر <thead>/<tfoot> (موثوق في Chrome).
 */
const db = require('../db');
const basePath = require('./basePath');

const DEFAULT = {
  companyName: 'Hypex',
  logoUrl: '',
};

let cache = { ...DEFAULT };
let loading = false;
let lastLoad = 0;

function logoUrlFromPath(lp) {
  const raw = String(lp || '')
    .trim()
    .replace(/\\/g, '/')
    .replace(/^\/+/, '');
  if (!raw) return '';
  if (/^https?:\/\//i.test(raw)) return raw;
  let path = raw;
  if (path.startsWith('uploads/')) path = '/' + path;
  else if (path.startsWith('hypex/')) path = '/' + path.replace(/^hypex\//, '');
  else path = '/uploads/' + path.replace(/^uploads\//, '');
  if (!path.startsWith('/')) path = '/' + path;
  return basePath.ensurePrefixed(path);
}

async function refreshBrand() {
  if (loading) return;
  loading = true;
  try {
    const rows = await db.query(
      `SELECT company_name_ar, logo_path FROM sys_company_settings WHERE id = 1 LIMIT 1`
    );
    const r = rows[0] || {};
    let companyName = String(r.company_name_ar || '').trim();
    if (!companyName || companyName === 'اسم الشركة') companyName = 'Hypex';
    cache = {
      companyName,
      logoUrl: logoUrlFromPath(r.logo_path),
    };
    lastLoad = Date.now();
  } catch {
    /* keep cache */
  } finally {
    loading = false;
  }
}

function getPrintBrand() {
  if (Date.now() - lastLoad > 60_000) {
    refreshBrand().catch(() => {});
  }
  return cache;
}

function warmPrintBrand() {
  return refreshBrand();
}

function escapeHtml(s) {
  return String(s)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;');
}

function escapeAttr(s) {
  return escapeHtml(s).replace(/'/g, '&#39;');
}

function printHeadInner(opts = {}) {
  const brand = getPrintBrand();
  const docTitle = String(opts.documentTitle || '').trim();
  const logo = brand.logoUrl
    ? `<img class="hx-print-logo-img" src="${escapeAttr(brand.logoUrl)}" alt="">`
    : `<span class="hx-print-logo-fallback" aria-hidden="true">H</span>`;

  return `
    <div class="hx-print-head">
      <div class="hx-print-logo">${logo}</div>
      <div class="hx-print-co">
        <div class="hx-print-co-name">${escapeHtml(brand.companyName)}</div>
        ${docTitle ? `<div class="hx-print-doc-title">${escapeHtml(docTitle)}</div>` : ''}
      </div>
    </div>`;
}

function printFootInner(opts = {}) {
  const user = opts.user || {};
  const userLabel =
    String(user.full_name_ar || user.full_name || user.username || '').trim() || '—';
  const username = String(user.username || '').trim();

  return `
    <div class="hx-print-foot">
      <span class="hx-print-user">المستخدم: ${escapeHtml(userLabel)}${
        username ? ` <span dir="ltr">(@${escapeHtml(username)})</span>` : ''
      }</span>
      <span class="hx-print-when-label">طُبع: <span class="si-print-when"></span></span>
      <span class="hx-print-pages">صفحة <span class="hx-page-num">—</span></span>
    </div>`;
}

/**
 * يلف محتوى الصفحة بجدول طباعة — thead/tfoot يتكرران على كل صفحة.
 */
function wrapPrintShell(bodyHtml, opts = {}) {
  return `
<table class="hx-print-shell">
  <thead>
    <tr>
      <td class="hx-print-shell-cell">${printHeadInner(opts)}</td>
    </tr>
  </thead>
  <tfoot>
    <tr>
      <td class="hx-print-shell-cell">${printFootInner(opts)}</td>
    </tr>
  </tfoot>
  <tbody>
    <tr>
      <td class="hx-print-shell-cell hx-print-shell-body">${bodyHtml}</td>
    </tr>
  </tbody>
</table>`;
}

/** توافق قديم — لم يعد يُستخدم منفصلاً */
function printChromeHtml(opts = {}) {
  return wrapPrintShell('', opts);
}

module.exports = {
  getPrintBrand,
  warmPrintBrand,
  printChromeHtml,
  wrapPrintShell,
  refreshBrand,
};
