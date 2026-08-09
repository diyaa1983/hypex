'use strict';

/**
 * بيانات ترويسة/تذييل الطباعة من sys_company_settings (الإعدادات)
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
let lastLoad = 0;
let loadPromise = null;

const CACHE_MS = 5_000;

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

function normalizeName(raw) {
  let companyName = String(raw || '').trim();
  if (!companyName || companyName === 'اسم الشركة') companyName = 'Hypex';
  return companyName;
}

async function refreshBrand(force = false) {
  if (loadPromise && !force) return loadPromise;
  loadPromise = (async () => {
    try {
      const rows = await db.query(
        `SELECT company_name_ar, logo_path FROM sys_company_settings WHERE id = 1 LIMIT 1`
      );
      const r = rows[0] || {};
      cache = {
        companyName: normalizeName(r.company_name_ar),
        logoUrl: logoUrlFromPath(r.logo_path),
      };
      lastLoad = Date.now();
    } catch (e) {
      console.error('printBrand refresh', e.message || e);
      /* keep previous cache */
    } finally {
      loadPromise = null;
    }
    return cache;
  })();
  return loadPromise;
}

/** يحدّث الكاش إن انتهت مدته أو force */
async function ensurePrintBrand(force = false) {
  if (force || Date.now() - lastLoad > CACHE_MS || !lastLoad) {
    return refreshBrand(force);
  }
  return cache;
}

function getPrintBrand() {
  if (Date.now() - lastLoad > CACHE_MS || !lastLoad) {
    refreshBrand().catch(() => {});
  }
  return { ...cache };
}

function warmPrintBrand() {
  return refreshBrand(true);
}

/** بعد حفظ الإعدادات — استخدم الاسم فوراً */
function invalidatePrintBrand(snapshot = null) {
  if (snapshot && (snapshot.companyName || snapshot.company_name_ar)) {
    cache = {
      companyName: normalizeName(snapshot.companyName || snapshot.company_name_ar),
      logoUrl:
        snapshot.logoUrl != null
          ? String(snapshot.logoUrl)
          : snapshot.logo_path != null
            ? logoUrlFromPath(snapshot.logo_path)
            : cache.logoUrl,
    };
    lastLoad = Date.now();
  } else {
    lastLoad = 0;
    cache = { ...DEFAULT };
  }
  return refreshBrand(true);
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

function assetVersion(relFromPublic) {
  try {
    const file = path.join(__dirname, '..', '..', 'public', relFromPublic.replace(/^\/+/, ''));
    return String(Math.floor(fs.statSync(file).mtimeMs));
  } catch {
    return String(Date.now());
  }
}

/** غلاف محتوى الطباعة — بدون صورة شعار في DOM الشاشة */
function wrapPrintShell(bodyHtml) {
  return `<div class="hx-print-content" data-hx-print-shell="standalone-v3">${bodyHtml}</div>`;
}

function printDataAttrs(opts = {}) {
  const brand = getPrintBrand();
  const user = opts.user || {};
  const userLabel =
    String(user.full_name_ar || user.full_name || user.username || '').trim() || '—';
  const username = String(user.username || '').trim();
  const userLine = username ? `${userLabel} (@${username})` : userLabel;
  const title = String(opts.documentTitle || '').trim();
  return {
    company: brand.companyName || 'Hypex',
    logo: brand.logoUrl || '',
    user: userLine,
    title,
  };
}

function bodyPrintDataHtml(opts = {}) {
  const d = printDataAttrs(opts);
  return (
    ` data-hx-print="standalone-v3"` +
    ` data-hx-company="${escapeAttr(d.company)}"` +
    ` data-hx-logo="${escapeAttr(d.logo)}"` +
    ` data-hx-user="${escapeAttr(d.user)}"` +
    ` data-hx-print-title="${escapeAttr(d.title)}"`
  );
}

/**
 * صفحة طباعة مستقلة (فاتورة/مرتجع…) — ترويسة الشركة + المحتوى + محرك sales-print
 * مثل كشف حساب Oracle
 */
async function renderStandalonePrintPage({
  user,
  documentTitle,
  backHref = '',
  contentHtml,
  autoPrint = false,
}) {
  await ensurePrintBrand();
  const brand = printDataAttrs({ user, documentTitle });
  const base = basePath.basePath || '';
  const spVer = assetVersion('js/sales-print.js');
  const printSrc = `${base}/assets/js/sales-print.js?v=${spVer}`;
  const logoHtml = brand.logo
    ? `<img src="${escapeAttr(brand.logo)}" alt="" class="hx-doc-logo">`
    : `<span class="hx-doc-logo-fallback">H</span>`;

  const back = backHref
    ? `<a class="hx-doc-btn" href="${escapeAttr(basePath.ensurePrefixed(backHref))}">عودة</a>`
    : '';

  return `<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="hx-print-engine" content="standalone-v3">
  <title>${escapeHtml(documentTitle)} · ${escapeHtml(brand.company)}</title>
  <script>window.__HYPEX_BASE__=${JSON.stringify(base)};</script>
  <script src="${escapeAttr(base + '/assets/js/base-path.js')}"></script>
  <style>
    *{box-sizing:border-box}
    html,body{margin:0;padding:0;font-family:Arial,Helvetica,sans-serif;color:#0f172a;background:#f1f5f9}
    .hx-doc-bar{display:flex;flex-wrap:wrap;gap:.5rem;align-items:center;justify-content:space-between;
      padding:.65rem 1rem;background:#0f172a;color:#f8fafc}
    .hx-doc-bar a,.hx-doc-btn{font:700 .88rem Arial,Helvetica,sans-serif;color:#0f172a;background:#f8fafc;
      border:0;border-radius:8px;padding:.45rem .85rem;text-decoration:none;cursor:pointer}
    .hx-doc-btn--pri{background:#0f6e6a;color:#fff}
    .hx-doc-sheet{max-width:210mm;margin:1rem auto 2rem;background:#fff;padding:12mm 10mm;
      box-shadow:0 8px 28px rgba(15,23,42,.1)}
    /* ترويسة معتمدة — شعار يسار · اسم الشركة يمين */
    .hx-doc-head{margin:0 0 12px;padding:0 0 10px;border-bottom:1px solid #222}
    .hx-doc-head__row{display:flex;direction:ltr;align-items:center;justify-content:space-between;gap:14px;width:100%}
    .hx-doc-logo{display:block;max-width:72px;max-height:72px;width:auto;height:auto;object-fit:contain}
    .hx-doc-logo-fallback{display:inline-flex;align-items:center;justify-content:center;width:36px;height:36px;
      border:1px solid #333;font:800 14px Arial,sans-serif}
    .hx-doc-company{flex:1 1 auto;text-align:right;font:800 15pt/1.3 Arial,Helvetica,sans-serif;color:#0f172a;direction:rtl}
    .hx-doc-title{text-align:center;font:700 12pt/1.3 Arial,Helvetica,sans-serif;margin-top:10px;color:#1e293b}
    .hx-doc-stamp{text-align:center;font:500 7.5pt Arial,Helvetica,sans-serif;color:#64748b;margin-top:4px}
    .ora-stmt-head{display:block;margin:0 0 10px;padding:0 0 8px;border-bottom:1px solid #ccc}
    .ora-stmt-kicker{font-size:8pt;color:#334155;margin:0}
    .ora-stmt-name{font:800 12pt Arial,Helvetica,sans-serif;margin:2px 0}
    .ora-stmt-meta{font-size:9pt;color:#334155;margin:2px 0 0;line-height:1.7}
    .ora-stmt-totals{display:grid;grid-template-columns:repeat(3,1fr);gap:6px;margin:10px 0 0;width:100%}
    .ora-stat{display:flex;flex-direction:column;gap:2px;border:1px solid #cbd5e1;padding:6px 8px;background:#fff}
    .ora-stat span{font-size:7.5pt;color:#64748b;font-weight:700}
    .ora-stat strong{font-size:10pt;font-weight:800}
    .ora-stat--balance{background:#e8f5f4!important;border-color:#0f6e6a!important}
    .ora-stat--balance span,.ora-stat--balance strong{color:#0a4f4c!important}
    table{width:100%;border-collapse:collapse;font-size:9pt;margin-top:6px}
    th,td{border:1px solid #334155;padding:4px 5px;vertical-align:top;text-align:right}
    th{background:#e2e8f0;font-weight:800}
    thead{display:table-header-group}
    tr{page-break-inside:avoid}
    tr.hx-print-total-row{font-weight:800;background:#f1f5f9}
    tr.hx-print-total-row td{border-top:2px solid #0f172a}
    .si-surface{border:1px solid #bbb;margin:0 0 8px;overflow:visible}
    .si-surface-head{padding:4px 6px;border-bottom:1px solid #ccc;font-weight:700;font-size:9pt}
    .empty{color:#64748b;text-align:center;padding:.75rem}
    @media print{
      body{background:#fff}
      .no-print,.hx-doc-bar{display:none!important}
      .hx-doc-sheet{max-width:none;margin:0;padding:0;box-shadow:none}
      @page{size:A4 portrait;margin:10mm 8mm 12mm 8mm}
    }
  </style>
</head>
<body${bodyPrintDataHtml({ user, documentTitle })}${autoPrint ? ' data-hx-auto-print="1"' : ''}>
  <div class="hx-doc-bar no-print">
    <strong>${escapeHtml(documentTitle)}</strong>
    <div style="display:flex;gap:.4rem;flex-wrap:wrap">
      <button type="button" class="hx-doc-btn hx-doc-btn--pri" data-print="1">طباعة / PDF</button>
      ${back}
    </div>
  </div>
  <div class="hx-doc-sheet">
    <header class="hx-doc-head" aria-label="ترويسة الشركة">
      <div class="hx-doc-head__row">
        <div style="flex:0 0 auto;max-width:90px;max-height:72px;overflow:hidden">${logoHtml}</div>
        <div class="hx-doc-company">${escapeHtml(brand.company)}</div>
      </div>
      <div class="hx-doc-title">${escapeHtml(documentTitle)}</div>
      <div class="hx-doc-stamp" dir="rtl">طُبع بواسطة ${escapeHtml(brand.user)}</div>
    </header>
    <div class="si-print-area hx-print-content">
      ${contentHtml}
    </div>
  </div>
  <script src="${escapeAttr(printSrc)}" defer></script>
</body>
</html>`;
}

module.exports = {
  getPrintBrand,
  warmPrintBrand,
  ensurePrintBrand,
  invalidatePrintBrand,
  refreshBrand,
  wrapPrintShell,
  printDataAttrs,
  bodyPrintDataHtml,
  renderStandalonePrintPage,
  assetVersion,
  escapeHtml,
  escapeAttr,
};
