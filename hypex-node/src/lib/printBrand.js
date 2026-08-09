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

module.exports = {
  getPrintBrand,
  warmPrintBrand,
  ensurePrintBrand,
  invalidatePrintBrand,
  refreshBrand,
  wrapPrintShell,
  printDataAttrs,
  bodyPrintDataHtml,
  assetVersion,
  escapeHtml,
  escapeAttr,
};
