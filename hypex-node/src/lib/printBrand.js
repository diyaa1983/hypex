'use strict';

/**
 * بيانات ترويسة/تذييل الطباعة من sys_company_settings (الإعدادات)
 */
const fs = require('fs');
const path = require('path');
const db = require('../db');
const basePath = require('./basePath');
const { backLinkHtml, printBtnHtml } = require('./toolbarIcons');

const DEFAULT = {
  companyName: 'Hypex',
  logoUrl: '',
  /** إظهار علامة مائية عند الطباعة — افتراضي مفعّل */
  watermarkEnabled: true,
};

let cache = { ...DEFAULT };
let lastLoad = 0;
let loadPromise = null;

const CACHE_MS = 60_000;

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

function parseWatermarkEnabled(v) {
  if (v == null || v === '') return true;
  if (v === false || v === 0 || v === '0' || v === 'false' || v === 'off') return false;
  return !(Number(v) === 0);
}

async function refreshBrand(force = false) {
  if (loadPromise && !force) return loadPromise;
  loadPromise = (async () => {
    try {
      let rows;
      try {
        rows = await db.query(
          `SELECT company_name_ar, logo_path, print_watermark_enabled
           FROM sys_company_settings WHERE id = 1 LIMIT 1`
        );
      } catch {
        rows = await db.query(
          `SELECT company_name_ar, logo_path FROM sys_company_settings WHERE id = 1 LIMIT 1`
        );
      }
      const r = rows[0] || {};
      cache = {
        companyName: normalizeName(r.company_name_ar),
        logoUrl: logoUrlFromPath(r.logo_path),
        watermarkEnabled: parseWatermarkEnabled(r.print_watermark_enabled),
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
      watermarkEnabled:
        snapshot.watermarkEnabled != null
          ? !!snapshot.watermarkEnabled
          : snapshot.print_watermark_enabled != null
            ? parseWatermarkEnabled(snapshot.print_watermark_enabled)
            : cache.watermarkEnabled,
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
  const rel = String(relFromPublic || '')
    .replace(/^\/+/, '')
    .replace(/^assets\//, '');
  const candidates = [
    path.join(__dirname, '..', '..', 'public', rel),
    path.join(__dirname, '..', '..', '..', 'assets', rel),
  ];
  for (const file of candidates) {
    try {
      return String(Math.floor(fs.statSync(file).mtimeMs));
    } catch {
      /* try next */
    }
  }
  return String(Date.now());
}

function wrapPrintShell(bodyHtml) {
  const brand = getPrintBrand();
  const logo = brand.logoUrl || '';
  const wm = brand.watermarkEnabled !== false ? watermarkMarkup(logo) : '';
  return `<div class="hx-print-content" data-hx-print-shell="standalone-v3">${wm}${bodyHtml}</div>`;
}

/** CSS علامة مائية — تظهر عند الطباعة فقط */
function watermarkCss() {
  return `
    .hx-logo-wm{display:none!important}
    .hx-doc-sheet,.hx-print-content,.si-print-area,.ora-stmt,.hx-print-doc{position:relative}
    @media print{
      .hx-logo-wm{
        display:flex!important;position:fixed!important;inset:0!important;
        align-items:center;justify-content:center;pointer-events:none;z-index:0;opacity:.05;
        -webkit-print-color-adjust:exact!important;print-color-adjust:exact!important
      }
      .hx-logo-wm img{
        width:min(58%,380px)!important;max-width:380px!important;max-height:380px!important;
        height:auto!important;object-fit:contain;filter:grayscale(.15)
      }
      .hx-doc-sheet > *:not(.hx-logo-wm),
      .hx-print-content > *:not(.hx-logo-wm),
      .si-print-area > *:not(.hx-logo-wm),
      .hx-print-doc > *:not(.hx-logo-wm){
        position:relative;z-index:1
      }
    }
  `;
}

function watermarkMarkup(logoUrl) {
  const src = String(logoUrl || '').trim();
  if (!src) return '';
  return (
    `<div class="hx-logo-wm hx-logo-wm--sheet" aria-hidden="true">` +
    `<img src="${escapeAttr(src)}" alt="">` +
    `</div>`
  );
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
    watermarkEnabled: brand.watermarkEnabled !== false,
  };
}

function bodyPrintDataHtml(opts = {}) {
  const d = printDataAttrs(opts);
  return (
    ` data-hx-print="standalone-v3"` +
    ` data-hx-company="${escapeAttr(d.company)}"` +
    ` data-hx-logo="${escapeAttr(d.logo)}"` +
    ` data-hx-user="${escapeAttr(d.user)}"` +
    ` data-hx-print-title="${escapeAttr(d.title)}"` +
    ` data-hx-watermark="${d.watermarkEnabled ? '1' : '0'}"`
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
  const wmHtml =
    brand.watermarkEnabled !== false ? watermarkMarkup(brand.logo) : '';

  const back = backHref ? backLinkHtml(basePath.ensurePrefixed(backHref)) : '';

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
    .inv-v1-table{width:100%;border-collapse:collapse;font-size:7.5pt;margin:0 0 6px}
    .inv-v1-table thead th{
      background:#5b6b7c;color:#fff;font-weight:700;font-size:7pt;
      border:1px solid #4a5568;padding:4px 2px;text-align:center;white-space:nowrap
    }
    .inv-v1-table tbody td{border:1px solid #94a3b8;padding:3px 2px;vertical-align:middle;background:#fff}
    .inv-v1-table .c-idx,.inv-v1-table .c-code,.inv-v1-table .c-num{text-align:center;font-variant-numeric:tabular-nums}
    .inv-v1-table .c-name{text-align:right;font-weight:600}
    .inv-v1-table .c-unit{text-align:center}
    .inv-v1-table .c-disc{color:#b45309;font-weight:700}
    .inv-v1-table .c-gross{font-weight:800}
    .inv-v1-table .empty{text-align:center;color:#64748b;padding:12px}
    /* بنود الفاتورة: اسم ضيّق مع التفاف · العناوين الأخرى أفقية */
    .inv-v1-table--lines{table-layout:fixed}
    .inv-v1-table--lines col.c-col-name{width:28%}
    .inv-v1-table--lines col.c-col-unit{width:7%}
    .inv-v1-table--lines col.c-col-qty{width:9%}
    .inv-v1-table--lines col.c-col-extra{width:7%}
    .inv-v1-table--lines col.c-col-price{width:12%}
    .inv-v1-table--lines col.c-col-disc{width:10%}
    .inv-v1-table--lines col.c-col-tax{width:11%}
    .inv-v1-table--lines col.c-col-total{width:16%}
    .inv-v1-table--lines thead th.c-name-h{
      white-space:normal;font-size:7.5pt;line-height:1.2;padding:5px 4px
    }
    .inv-v1-table--lines thead th.c-h{
      vertical-align:middle;padding:4px 3px;font-size:7pt;line-height:1.2;white-space:nowrap;
      writing-mode:horizontal-tb!important;text-orientation:mixed!important;transform:none!important
    }
    .inv-v1-table--lines thead th.c-h span{display:inline!important;writing-mode:horizontal-tb!important;transform:none!important}
    .inv-v1-table--lines .c-name{
      max-width:0;width:28%;white-space:normal;word-break:break-word;overflow-wrap:anywhere;
      line-height:1.25;font-size:7pt;padding:3px 4px
    }
    .inv-v1-table--lines .c-unit{
      white-space:normal;word-break:break-word;line-height:1.15;font-size:6.5pt;padding:2px 1px
    }
    .inv-v1-table--lines .c-num{font-size:7pt;white-space:nowrap;padding:2px 1px}
    /* مجاميع كلاسيكية — جدول يسار/أسفل */
    .inv-v1-foot{
      display:flex;flex-direction:column;align-items:flex-start;
      gap:0;margin-top:12px;width:100%
    }
    .inv-v1-sumwrap{min-width:14rem;max-width:100%;width:100%;align-self:flex-start}
    .inv-v1-sum{width:auto;min-width:14rem;border-collapse:collapse;font-size:10pt}
    .inv-v1-sum td{border:0!important;padding:3px 6px;background:transparent!important}
    .inv-v1-sum .lbl{text-align:right;font-weight:700;color:#1e3a5f;white-space:nowrap}
    .inv-v1-sum .val{text-align:left;font-weight:700;font-variant-numeric:tabular-nums;min-width:5.5rem;color:#0f172a}
    .inv-v1-sum tr.grand td{font-size:12pt;font-weight:800;color:#1e3a5f;padding-top:7px;
      border-top:1px solid #1e3a5f!important}
    .inv-v1-notes{
      margin-top:10px;font-size:9.5pt;text-align:right;color:#334155;
      max-width:100%;width:100%;white-space:pre-wrap;word-break:break-word;
      overflow-wrap:anywhere;line-height:1.45
    }
    .inv-v1-notes span{font-weight:700;color:#1e3a5f}
    .inv-v1-sign{
      margin-top:2.8rem;width:100%;
      display:flex;flex-direction:column;align-items:flex-end
    }
    .inv-v1-sign-label{font-size:10pt;font-weight:700;color:#1e3a5f;margin-bottom:1.8rem;text-align:center;width:12rem}
    .inv-v1-sign-line{border-bottom:1px solid #0f172a;width:12rem;margin:0}
    /* تاريخ الطباعة + المستخدم — زاوية الصفحة السفلية اليسرى (ليس تحت المحتوى) */
    .inv-v1-printmeta{
      font:500 6.5pt/1.2 Arial,Helvetica,sans-serif;color:#64748b;
      direction:rtl;text-align:left;white-space:nowrap;
      pointer-events:none;z-index:5
    }
    .inv-v1-printmeta span[dir="ltr"]{unicode-bidi:embed}
    /* معاينة الشاشة: أسفل يسار ورقة A4 */
    .hx-doc-sheet{position:relative;min-height:277mm;padding-bottom:14mm}
    .hx-doc-sheet > .inv-v1-printmeta{
      position:absolute;left:8mm;bottom:6mm;margin:0;width:auto
    }
    @media print{
      .inv-v1-table thead th{background:#5b6b7c!important;color:#fff!important;-webkit-print-color-adjust:exact;print-color-adjust:exact}
      /* الطباعة: زاوية الورقة السفلية اليسرى ثابتاً */
      .hx-doc-sheet{min-height:0;padding-bottom:10mm}
      .hx-doc-sheet > .inv-v1-printmeta,
      .inv-v1-printmeta{
        position:fixed!important;
        left:7mm!important;
        bottom:4mm!important;
        right:auto!important;
        margin:0!important;
        width:auto!important;
        text-align:left!important
      }
    }
    @media (max-width:720px){
      .inv-v1-top{grid-template-columns:1fr;text-align:center}
      .inv-v1-meta,.inv-v1-title-block,.inv-v1-qr{grid-column:1}
      .inv-v1-meta{text-align:right}
      .inv-v1-qr{justify-self:center}
    }`
    : '';

  // طابع صغير — زاوية سفلية يسرى للصفحة
  const printStamp = (() => {
    const now = new Date();
    const pad = (n) => String(n).padStart(2, '0');
    const when =
      pad(now.getDate()) +
      '-' +
      pad(now.getMonth() + 1) +
      '-' +
      now.getFullYear() +
      ' ' +
      pad(now.getHours()) +
      ':' +
      pad(now.getMinutes());
    const userLine = brand.user && brand.user !== '—' ? ' · ' + escapeHtml(brand.user) : '';
    if (!isInv) return '';
    return `<div class="inv-v1-printmeta" dir="rtl">طُبع <span dir="ltr">${escapeHtml(when)}</span>${userLine}</div>`;
  })();

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
    .hx-doc-btn--icon,.si-btn--icon{min-width:2.25rem;min-height:2.25rem;padding:.35rem;display:inline-flex;align-items:center;justify-content:center;line-height:0}
    .hx-doc-btn--icon svg,.si-btn--icon svg{display:block;flex-shrink:0}
    .hx-doc-btn--pri{background:linear-gradient(180deg,#0ea5e9 0%,#0369a1 100%);color:#fff}
    .hx-doc-sheet{max-width:210mm;margin:1rem auto 2rem;background:#fff;padding:10mm 8mm;
      box-shadow:0 8px 28px rgba(15,23,42,.1);position:relative}
    .hx-doc-head{margin:0 0 12px;padding:0 0 10px;border-bottom:1px solid #222}
    .hx-doc-head__row{display:flex;direction:ltr;align-items:center;justify-content:space-between;gap:16px;width:100%}
    .hx-doc-logo-wrap{flex:0 0 auto;max-width:140px;max-height:120px;overflow:visible}
    .hx-doc-logo{display:block;max-width:120px;max-height:120px;width:auto;height:auto;object-fit:contain}
    .hx-doc-logo-fallback{display:inline-flex;align-items:center;justify-content:center;width:56px;height:56px;
      border:1px solid #333;font:800 22px Arial,sans-serif}
    .hx-doc-company{flex:1 1 auto;text-align:right;font:800 16pt/1.3 Arial,Helvetica,sans-serif;color:#0f172a;direction:rtl}
    .hx-doc-title{text-align:center;font:700 12pt/1.3 Arial,Helvetica,sans-serif;margin-top:10px;color:#1e293b}
    .hx-doc-stamp{text-align:center;font:500 7.5pt Arial,Helvetica,sans-serif;color:#64748b;margin-top:4px}
    .empty{color:#64748b;text-align:center;padding:.75rem}
    ${watermarkCss()}
    ${invCss}
    @media print{
      body{background:#fff}
      .no-print,.hx-doc-bar{display:none!important}
      .hx-doc-sheet{max-width:none;margin:0;padding:0;box-shadow:none;position:relative}
      @page{size:A4 portrait;margin:8mm 7mm 14mm 7mm}
    }
  </style>
</head>
<body${bodyPrintDataHtml({ user, documentTitle })}${autoPrint ? ' data-hx-auto-print="1"' : ''} data-hx-print-mode="${mode}"${isInv ? ' data-hx-theme="invoice-v1"' : ''}>
  <div class="hx-doc-bar no-print">
    <strong>${escapeHtml(documentTitle)}</strong>
    <div style="display:flex;gap:.4rem;flex-wrap:wrap">
      ${printBtnHtml('طباعة', 'hx-doc-btn hx-doc-btn--pri hx-doc-btn--icon', ' id="hx-print-btn"')}
      ${back}
    </div>
  </div>
  <div class="hx-doc-sheet" id="hx-print-sheet">
    ${wmHtml}
    <header class="hx-doc-head" aria-label="ترويسة الشركة">
      <div class="hx-doc-head__row">
        <div class="hx-doc-logo-wrap">${logoHtml}</div>
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
    ${printStamp}
  </div>
  <script src="${escapeAttr(printSrc)}" defer></script>
</body>
</html>`;
}

/**
 * جدول بنود طباعة الفاتورة/الطلب:
 * اسم المادة (ضيّق مع التفاف) ثم وحدة، كمية، اضافي، سعر، خصم، نسبة الضريبة، إجمالي.
 */
function formatPrintTaxCell(ln, fmt) {
  const taxPct = Number(ln.tax_rate_percent);
  if (Number.isFinite(taxPct) && taxPct > 0.000001) {
    return `${fmt(taxPct)}%`;
  }
  const lineTotal = Number(ln.line_total) || 0;
  const taxAmt = Number(ln.tax_amount) || 0;
  if (lineTotal > 0.000001 && taxAmt > 0.000001) {
    const derived = Math.round((taxAmt / lineTotal) * 10000) / 100;
    if (derived > 0.000001) return `${fmt(derived)}%`;
  }
  if (taxAmt > 0.000001) return fmt(taxAmt);
  return '—';
}

function invoiceV1LinesTableHtml(lines, fmtAmtFn, escFn) {
  const fmt = typeof fmtAmtFn === 'function' ? fmtAmtFn : (n) => String(n ?? '');
  const hx = typeof escFn === 'function' ? escFn : escapeHtml;
  const rows = (Array.isArray(lines) ? lines : [])
    .map((ln) => {
      const qty = Number(ln.qty) || 0;
      const qtyExtra = Number(ln.qty_extra) || 0;
      const discPct = Number(ln.discount_pct) || 0;
      const discAmt = Number(ln.discount_amount) || 0;
      const discCell = discPct > 0.000001 ? `${fmt(discPct)}%` : fmt(discAmt);
      const taxCell = formatPrintTaxCell(ln, fmt);
      const name = ln.name_ar || ln.item_name || ln.line_desc || '';
      return `<tr>
            <td class="c-name">${hx(name)}</td>
            <td class="c-unit">${hx(ln.unit_name || 'قطعة')}</td>
            <td class="c-num" dir="ltr">${hx(fmt(qty))}</td>
            <td class="c-num" dir="ltr">${hx(fmt(qtyExtra))}</td>
            <td class="c-num" dir="ltr">${hx(fmt(ln.unit_price))}</td>
            <td class="c-num c-disc" dir="ltr">${hx(discCell)}</td>
            <td class="c-num c-tax" dir="ltr">${hx(taxCell)}</td>
            <td class="c-num c-gross" dir="ltr">${hx(fmt(ln.line_gross))}</td>
          </tr>`;
    })
    .join('');
  const body = rows || '<tr><td colspan="8" class="empty">لا بنود</td></tr>';
  return `<table class="inv-v1-table inv-v1-table--lines">
          <colgroup>
            <col class="c-col-name">
            <col class="c-col-unit">
            <col class="c-col-qty">
            <col class="c-col-extra">
            <col class="c-col-price">
            <col class="c-col-disc">
            <col class="c-col-tax">
            <col class="c-col-total">
          </colgroup>
          <thead>
            <tr>
              <th class="c-name-h">اسم المادة</th>
              <th class="c-h">وحدة</th>
              <th class="c-h">الكمية</th>
              <th class="c-h">اضافي</th>
              <th class="c-h">السعر</th>
              <th class="c-h">الخصم</th>
              <th class="c-h">نسبة الضريبة</th>
              <th class="c-h">الإجمالي</th>
            </tr>
          </thead>
          <tbody>${body}</tbody>
        </table>`;
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
  invoiceV1LinesTableHtml,
  watermarkCss,
  watermarkMarkup,
  assetVersion,
  escapeHtml,
  escapeAttr,
};
