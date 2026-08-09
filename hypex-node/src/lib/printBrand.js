'use strict';

/**
 * بيانات العلامة التجارية للطباعة (بدون إدراج <img> في صفحة الشاشة —
 * الشعار يظهر فقط داخل إطار الطباعة المنفصل مثل تقارير Node 2027).
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

function assetVersion(relFromPublic) {
  try {
    const file = path.join(__dirname, '..', '..', 'public', relFromPublic.replace(/^\/+/, ''));
    return String(Math.floor(fs.statSync(file).mtimeMs));
  } catch {
    return String(Date.now());
  }
}

/**
 * لا نضع شعار الشركة كـ <img> داخل الصفحة الرئيسية (كان سبب ظهور شعار عملاق في المعاينة).
 * المحتوى فقط — الطباعة عبر iframe في sales-print.js.
 */
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
  wrapPrintShell,
  printDataAttrs,
  bodyPrintDataHtml,
  assetVersion,
  refreshBrand,
  escapeHtml,
  escapeAttr,
};
