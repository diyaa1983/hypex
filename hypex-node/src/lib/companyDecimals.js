'use strict';

/**
 * خانات عشرية الشركة من sys_company_settings (شاشة الإعدادات).
 * decimal_places              → مبالغ عامة (عرض/حساب)
 * invoice_unit_price_decimal_places → أسعار الوحدة / أسعار المادة
 */

const db = require('../db');

let cache = {
  amount: 3,
  unit: 3,
  loaded: false,
  at: 0,
};

const CACHE_MS = 15_000;

function clampDp(n, fallback = 3) {
  const fb = Number.isFinite(Number(fallback)) ? Math.floor(Number(fallback)) : 3;
  if (n === undefined || n === null || n === '') return Math.max(0, Math.min(6, fb));
  const x = Number(n);
  if (!Number.isFinite(x)) return Math.max(0, Math.min(6, fb));
  return Math.max(0, Math.min(6, Math.floor(x)));
}

function applyRow(row) {
  const amount = clampDp(row?.decimal_places, 3);
  const unit = clampDp(
    row?.invoice_unit_price_decimal_places != null
      ? row.invoice_unit_price_decimal_places
      : row?.decimal_places,
    amount
  );
  cache = { amount, unit, loaded: true, at: Date.now() };
  return { amount, unit };
}

async function load(force = false) {
  if (!force && cache.loaded && Date.now() - cache.at < CACHE_MS) {
    return { amount: cache.amount, unit: cache.unit };
  }
  try {
    const rows = await db.query(
      `SELECT decimal_places, invoice_unit_price_decimal_places
       FROM sys_company_settings WHERE id = 1 LIMIT 1`
    );
    if (rows[0]) return applyRow(rows[0]);
  } catch (e) {
    console.error('companyDecimals.load', e.message);
  }
  if (!cache.loaded) applyRow({ decimal_places: 3, invoice_unit_price_decimal_places: 3 });
  return { amount: cache.amount, unit: cache.unit };
}

function invalidate(row) {
  if (row && typeof row === 'object') {
    applyRow(row);
    return;
  }
  cache.loaded = false;
  cache.at = 0;
}

function amountPlaces() {
  return cache.amount;
}

function unitPlaces() {
  return cache.unit;
}

function snapshot() {
  return { amount: cache.amount, unit: cache.unit };
}

function step(dp) {
  const d = clampDp(dp, amountPlaces());
  if (d <= 0) return '1';
  return '0.' + '0'.repeat(d - 1) + '1';
}

function amountStep() {
  return step(amountPlaces());
}

function unitStep() {
  return step(unitPlaces());
}

function roundTo(n, dp) {
  const d = clampDp(dp, 3);
  const x = Number(n) || 0;
  if (d <= 0) return Math.round(x);
  const f = 10 ** d;
  return Math.round((x + Number.EPSILON) * f) / f;
}

function roundAmount(n) {
  return roundTo(n, amountPlaces());
}

function roundUnit(n) {
  return roundTo(n, unitPlaces());
}

/** قيمة number input بدون فواصل آلاف */
function formatInput(n, dp) {
  const d = clampDp(dp, amountPlaces());
  const x = Number(n);
  if (!Number.isFinite(x)) return (0).toFixed(d);
  return x.toFixed(d);
}

function formatAmountInput(n) {
  return formatInput(n, amountPlaces());
}

function formatUnitInput(n) {
  return formatInput(n, unitPlaces());
}

/** عرض مع فواصل آلاف */
function formatDisplay(n, dp) {
  const d = clampDp(dp != null ? dp : amountPlaces(), amountPlaces());
  const x = Number(n) || 0;
  return x.toLocaleString('en-US', {
    minimumFractionDigits: d,
    maximumFractionDigits: d,
  });
}

module.exports = {
  load,
  invalidate,
  applyRow,
  clampDp,
  amountPlaces,
  unitPlaces,
  snapshot,
  step,
  amountStep,
  unitStep,
  roundTo,
  roundAmount,
  roundUnit,
  formatInput,
  formatAmountInput,
  formatUnitInput,
  formatDisplay,
};
