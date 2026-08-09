'use strict';

/**
 * ترويسة/تذييل طباعة — داخل تدفق المستند + أنماط مضمّنة (لا تعتمد على كاش CSS)
 * شعار يسار | اسم الشركة يمين | تذييل: طبع بواسطة المستخدم
 */
const fs = require('fs');
const path = require('path');
const db = require('../db');
const basePath = require('./basePath');

const DEFAULT = {
  companyName: 'Hypex',
  logoUrl: '',
};

let cache = { ...DEFAULT };
let loading = false;
let lastLoad = 0;

/** CSS حرج مضمّن — يظهر في الطباعة دائماً حتى لو فشل تحميل print-chrome.css */
const CRITICAL_PRINT_CSS = `
.hx-print-header,.hx-print-footer{display:none!important}
@media print{
  .hx-print-header{
    display:block!important;
    position:static!important;
    width:100%!important;
    max-width:100%!important;
    margin:0 0 8px 0!important;
    padding:0 0 6px 0!important;
    border:0!important;
    border-bottom:1px solid #222!important;
    background:#fff!important;
    page-break-after:avoid;
    break-after:avoid;
    box-sizing:border-box!important;
  }
  .hx-print-header *{box-sizing:border-box!important;max-width:100%!important}
  .hx-print-header-brand{
    display:flex!important;
    flex-direction:row!important;
    flex-wrap:nowrap!important;
    align-items:center!important;
    justify-content:space-between!important;
    width:100%!important;
    max-width:100%!important;
    gap:10px!important;
    direction:ltr!important;
  }
  .hx-print-header-logo{
    flex:0 0 auto!important;
    width:90px!important;
    max-width:90px!important;
    height:42px!important;
    max-height:42px!important;
    overflow:hidden!important;
    display:flex!important;
    align-items:center!important;
    justify-content:flex-start!important;
  }
  .hx-print-header-logo img,
  .hx-print-logo-img{
    display:block!important;
    width:auto!important;
    height:auto!important;
    max-width:88px!important;
    max-height:40px!important;
    object-fit:contain!important;
  }
  .hx-print-logo-fallback{
    display:inline-flex!important;
    align-items:center!important;
    justify-content:center!important;
    width:36px!important;
    height:36px!important;
    border:1px solid #333!important;
    font:800 14px/1 Arial,sans-serif!important;
  }
  .hx-print-header-co{
    flex:1 1 auto!important;
    min-width:0!important;
    text-align:right!important;
    direction:rtl!important;
    font:800 13pt/1.25 Arial,Tahoma,sans-serif!important;
    color:#0f172a!important;
    white-space:nowrap!important;
    overflow:hidden!important;
    text-overflow:ellipsis!important;
  }
  .hx-print-header-title{
    display:block!important;
    text-align:center!important;
    font:700 12pt/1.3 Arial,Tahoma,sans-serif!important;
    color:#1e293b!important;
    margin:6px 0 0!important;
    padding:0!important;
  }
  .hx-print-header-meta{
    display:block!important;
    text-align:center!important;
    font:600 8pt/1.3 Arial,Tahoma,sans-serif!important;
    color:#475569!important;
    margin:2px 0 0!important;
  }
  .hx-print-footer{
    display:block!important;
    position:fixed!important;
    bottom:0!important;left:0!important;right:0!important;
    margin:0!important;padding:1px 6px!important;
    font:500 6.5pt/1.2 Arial,Tahoma,sans-serif!important;
    color:#64748b!important;text-align:center!important;direction:rtl!important;
    background:transparent!important;border:0!important;z-index:99999!important;
    pointer-events:none!important;
  }
  body.has-print-chrome .si-hero{display:none!important}
  .hx-print-content{display:block!important;width:100%!important}
  .sidebar,.no-print,.si-rail,.si-hero-actions,.ora-filters,.si-btn--print,[data-print]{display:none!important}
}
`.replace(/\s+/g, ' ').trim();

function logoUrlFromPath(lp) {
  const raw = String(lp || '')
    .trim()
    .replace(/\\/g, '/')
    .replace(/^\/+/, '');
  if (!raw) return '';
  if (/^https?:\/\//i.test(raw)) return raw;
  let p = raw;
  if (p.startsWith('uploads/')) p = '/' + p;
  else if (p.startsWith('hypex/')) p = '/' + p.replace(/^hypex\//, '');
  else p = '/uploads/' + p.replace(/^uploads\//, '');
  if (!p.startsWith('/')) p = '/' + p;
  return basePath.ensurePrefixed(p);
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
 * v=mtime لكسر كاش المتصفح القوي (Expires 1 year في .htaccess)
 */
function assetVersion(relFromPublic) {
  try {
    const file = path.join(__dirname, '..', '..', 'public', relFromPublic.replace(/^\/+/, ''));
    return String(Math.floor(fs.statSync(file).mtimeMs));
  } catch {
    return String(Date.now());
  }
}

function criticalPrintStyleTag() {
  return `<style id="hx-print-critical">${CRITICAL_PRINT_CSS}</style>`;
}

/**
 * يلف المحتوى بترويسة/تذييل الطباعة (تدفق مستند + أنماط مضمّنة).
 */
function wrapPrintShell(bodyHtml, opts = {}) {
  const brand = getPrintBrand();
  const user = opts.user || {};
  const userLabel =
    String(user.full_name_ar || user.full_name || user.username || '').trim() || '—';
  const username = String(user.username || '').trim();
  const docTitle = String(opts.documentTitle || '').trim();

  const logo = brand.logoUrl
    ? `<img class="hx-print-logo-img" src="${escapeAttr(brand.logoUrl)}" alt="" ` +
      `width="72" height="40" ` +
      `style="display:block;max-width:72px;max-height:40px;width:auto;height:auto;object-fit:contain">`
    : `<span class="hx-print-logo-fallback" aria-hidden="true">H</span>`;

  const userLine = username
    ? `${escapeHtml(userLabel)} (@${escapeHtml(username)})`
    : escapeHtml(userLabel);

  return `
<div class="hx-print-root">
  <header class="hx-print-header" role="banner">
    <div class="hx-print-header-brand" style="display:flex;direction:ltr;justify-content:space-between;align-items:center;width:100%;gap:10px">
      <div class="hx-print-header-logo" style="flex:0 0 auto;width:90px;max-width:90px;height:42px;max-height:42px;overflow:hidden">
        ${logo}
      </div>
      <div class="hx-print-header-co" dir="rtl" style="flex:1;text-align:right;font:800 13pt/1.25 Arial,Tahoma,sans-serif;color:#0f172a">
        ${escapeHtml(brand.companyName)}
      </div>
    </div>
    ${
      docTitle
        ? `<div class="hx-print-header-title" style="text-align:center;font:700 12pt Arial,Tahoma,sans-serif;margin-top:6px">${escapeHtml(
            docTitle
          )}</div>`
        : ''
    }
    <div class="hx-print-header-meta" style="text-align:center;font:600 8pt Arial,Tahoma,sans-serif;color:#475569;margin-top:2px">
      طُبع: <span class="si-print-when" dir="ltr"></span>
    </div>
  </header>

  <div class="hx-print-content">
    ${bodyHtml}
  </div>

  <footer class="hx-print-footer">
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
  assetVersion,
  criticalPrintStyleTag,
};
