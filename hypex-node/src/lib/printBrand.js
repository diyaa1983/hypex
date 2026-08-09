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
  /** sheet = اطبع الصفحة كما هي · iframe = محرك sales-print */
  printMode = 'sheet',
  /** invoice-v1 = شكل فاتورة مبيعات المرجع */
  theme = '',
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

  const mode = printMode === 'iframe' ? 'iframe' : 'sheet';
  const isInv = theme === 'invoice-v1';

  const invCss = isInv
    ? `
    /* ── فاتورة مبيعات v1 (شكل المرجع) ── */
    .hx-doc-title,.hx-doc-stamp{display:none!important}
    .hx-doc-head{margin:0 0 8px;padding:0 0 8px;border-bottom:1px solid #cbd5e1}
    .hx-doc-company{font-size:12pt!important}
    .inv-v1{color:#0f172a;font-family:Arial,Helvetica,sans-serif}
    /* meta يمين · عنوان وسط الصفحة · QR يسار */
    .inv-v1-top{
      display:grid;
      grid-template-columns:1fr auto 1fr;
      gap:8px 12px;
      align-items:start;
      margin:0 0 14px;
      position:relative
    }
    .inv-v1-meta{grid-column:1;justify-self:stretch;font-size:9.5pt;line-height:1.75;text-align:right}
    .inv-v1-title-block{
      grid-column:2;justify-self:center;align-self:center;
      display:flex;align-items:center;justify-content:center;min-height:48px;padding:0 8px
    }
    .inv-v1-title{margin:0;font:800 18pt/1.2 Arial,Helvetica,sans-serif;color:#1e3a5f;text-align:center;white-space:nowrap}
    .inv-v1-qr{grid-column:3;justify-self:start}
    .inv-v1--draft .inv-v1-top{grid-template-columns:1fr auto 1fr}
    .inv-v1--draft .inv-v1-title-block{grid-column:2}
    .inv-v1-qr img{display:block;width:118px;height:118px;border:1px solid #94a3b8;padding:3px;background:#fff}
    .inv-v1-meta div{margin:0}
    .inv-v1-meta span{color:#475569;font-weight:600}
    .inv-v1-meta strong{color:#0f172a;font-weight:700}
    .inv-v1-table{width:100%;border-collapse:collapse;font-size:8pt;margin:0 0 6px}
    .inv-v1-table thead th{
      background:#5b6b7c;color:#fff;font-weight:700;font-size:7.5pt;
      border:1px solid #4a5568;padding:5px 3px;text-align:center;white-space:nowrap
    }
    .inv-v1-table tbody td{border:1px solid #94a3b8;padding:4px 3px;vertical-align:middle;background:#fff}
    .inv-v1-table .c-idx,.inv-v1-table .c-code,.inv-v1-table .c-num{text-align:center;font-variant-numeric:tabular-nums}
    .inv-v1-table .c-name{text-align:right;font-weight:600}
    .inv-v1-table .c-unit{text-align:center}
    .inv-v1-table .c-gross{font-weight:800}
    .inv-v1-table .empty{text-align:center;color:#64748b;padding:12px}
    /* مجاميع على اليمين، التوقيع أسفلها في الأسفل */
    .inv-v1-foot{
      display:flex;flex-direction:column;align-items:flex-start;
      gap:0;margin-top:12px
    }
    .inv-v1-sumwrap{min-width:14rem;align-self:flex-start}
    .inv-v1-sum{width:auto;min-width:14rem;border-collapse:collapse;font-size:10pt}
    .inv-v1-sum td{border:0!important;padding:3px 6px;background:transparent!important}
    .inv-v1-sum .lbl{text-align:right;font-weight:700;color:#1e3a5f;white-space:nowrap}
    .inv-v1-sum .val{text-align:left;font-weight:700;font-variant-numeric:tabular-nums;min-width:5.5rem;color:#0f172a}
    .inv-v1-sum tr.grand td{font-size:12pt;font-weight:800;color:#1e3a5f;padding-top:7px;
      border-top:1px solid #1e3a5f!important}
    .inv-v1-notes{margin-top:10px;font-size:9.5pt;text-align:right;color:#334155}
    .inv-v1-notes span{font-weight:700;color:#1e3a5f}
    .inv-v1-sign{
      margin-top:2.8rem;width:100%;text-align:center;
      display:flex;flex-direction:column;align-items:center
    }
    .inv-v1-sign-label{font-size:10pt;font-weight:700;color:#1e3a5f;margin-bottom:1.8rem}
    .inv-v1-sign-line{border-bottom:1px solid #0f172a;width:12rem;margin:0 auto}
    @media print{
      .inv-v1-table thead th{background:#5b6b7c!important;color:#fff!important;-webkit-print-color-adjust:exact;print-color-adjust:exact}
    }
    @media (max-width:720px){
      .inv-v1-top{grid-template-columns:1fr;text-align:center}
      .inv-v1-meta,.inv-v1-title-block,.inv-v1-qr{grid-column:1}
      .inv-v1-meta{text-align:right}
      .inv-v1-qr{justify-self:center}
    }`
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
    .hx-doc-sheet{max-width:210mm;margin:1rem auto 2rem;background:#fff;padding:10mm 8mm;
      box-shadow:0 8px 28px rgba(15,23,42,.1)}
    .hx-doc-head{margin:0 0 12px;padding:0 0 10px;border-bottom:1px solid #222}
    .hx-doc-head__row{display:flex;direction:ltr;align-items:center;justify-content:space-between;gap:14px;width:100%}
    .hx-doc-logo{display:block;max-width:72px;max-height:72px;width:auto;height:auto;object-fit:contain}
    .hx-doc-logo-fallback{display:inline-flex;align-items:center;justify-content:center;width:36px;height:36px;
      border:1px solid #333;font:800 14px Arial,sans-serif}
    .hx-doc-company{flex:1 1 auto;text-align:right;font:800 15pt/1.3 Arial,Helvetica,sans-serif;color:#0f172a;direction:rtl}
    .hx-doc-title{text-align:center;font:700 12pt/1.3 Arial,Helvetica,sans-serif;margin-top:10px;color:#1e293b}
    .hx-doc-stamp{text-align:center;font:500 7.5pt Arial,Helvetica,sans-serif;color:#64748b;margin-top:4px}
    .empty{color:#64748b;text-align:center;padding:.75rem}
    ${invCss}
    @media print{
      body{background:#fff}
      .no-print,.hx-doc-bar{display:none!important}
      .hx-doc-sheet{max-width:none;margin:0;padding:0;box-shadow:none}
      @page{size:A4 portrait;margin:8mm 7mm 10mm 7mm}
    }
  </style>
</head>
<body${bodyPrintDataHtml({ user, documentTitle })}${autoPrint ? ' data-hx-auto-print="1"' : ''} data-hx-print-mode="${mode}"${isInv ? ' data-hx-theme="invoice-v1"' : ''}>
  <div class="hx-doc-bar no-print">
    <strong>${escapeHtml(documentTitle)}</strong>
    <div style="display:flex;gap:.4rem;flex-wrap:wrap">
      <button type="button" class="hx-doc-btn hx-doc-btn--pri" data-print="1" id="hx-print-btn">طباعة</button>
      ${back}
    </div>
  </div>
  <div class="hx-doc-sheet" id="hx-print-sheet">
    <header class="hx-doc-head" aria-label="ترويسة الشركة">
      <div class="hx-doc-head__row">
        <div style="flex:0 0 auto;max-width:90px;max-height:72px;overflow:hidden">${logoHtml}</div>
        <div class="hx-doc-company">${escapeHtml(brand.company)}</div>
      </div>
      ${
        isInv
          ? ''
          : `<div class="hx-doc-title">${escapeHtml(documentTitle)}</div>
      <div class="hx-doc-stamp" dir="rtl">طُبع بواسطة ${escapeHtml(brand.user)}</div>`
      }
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
