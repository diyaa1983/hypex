'use strict';

/**
 * ترويسة/تذييل طباعة موحّدان — نفس أسلوب document_print_header (PHP)
 * وواجهات Node 2027: شعار + اسم شركة + عنوان، وتذييل مستخدم.
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
    /* keep */
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

/**
 * يلف المحتوى بترويسة/تذييل الطباعة داخل تدفق المستند (يظهر في معاينة الطباعة).
 * الهيكل مطابق لتقارير PHP الحديثة (document_print_header).
 */
function wrapPrintShell(bodyHtml, opts = {}) {
  const brand = getPrintBrand();
  const user = opts.user || {};
  const userLabel =
    String(user.full_name_ar || user.full_name || user.username || '').trim() || '—';
  const username = String(user.username || '').trim();
  const docTitle = String(opts.documentTitle || '').trim();
  const logo = brand.logoUrl
    ? `<img class="hx-print-logo-img" src="${escapeAttr(brand.logoUrl)}" alt="" width="72" height="48">`
    : `<span class="hx-print-logo-fallback" aria-hidden="true">H</span>`;

  const userLine = username
    ? `${escapeHtml(userLabel)} (@${escapeHtml(username)})`
    : escapeHtml(userLabel);

  return `
<div class="hx-print-root">
  <header class="hx-print-header hx-print-only" role="banner" aria-hidden="true">
    <div class="hx-print-header-top">
      <div class="hx-print-header-brand">
        <div class="hx-print-header-co">${escapeHtml(brand.companyName)}</div>
        <div class="hx-print-header-logo">${logo}</div>
      </div>
    </div>
    ${docTitle ? `<div class="hx-print-header-title">${escapeHtml(docTitle)}</div>` : ''}
    <div class="hx-print-header-meta">
      طُبع: <span class="si-print-when" dir="ltr"></span>
    </div>
  </header>

  <div class="hx-print-content">
    ${bodyHtml}
  </div>

  <footer class="hx-print-footer hx-print-only" aria-hidden="true">
    طبع بواسطة: ${userLine}
  </footer>
</div>`;
}

function printChromeHtml(opts) {
  return wrapPrintShell('', opts);
}

module.exports = {
  getPrintBrand,
  warmPrintBrand,
  printChromeHtml,
  wrapPrintShell,
  refreshBrand,
};
