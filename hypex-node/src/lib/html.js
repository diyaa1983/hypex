'use strict';

const companyDecimals = require('./companyDecimals');

function esc(s) {
  return String(s ?? '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;');
}

/** عرض مبالغ حسب خانات النظام (إعدادات الشركة) ما لم يُمرَّر dp صراحة */
function fmtAmt(n, dp) {
  const places =
    dp != null && dp !== ''
      ? companyDecimals.clampDp(dp, companyDecimals.amountPlaces())
      : companyDecimals.amountPlaces();
  return companyDecimals.formatDisplay(n, places);
}

/** أسعار الوحدة / بطاقة المادة */
function fmtUnitPrice(n, dp) {
  const places =
    dp != null && dp !== ''
      ? companyDecimals.clampDp(dp, companyDecimals.unitPlaces())
      : companyDecimals.unitPlaces();
  return companyDecimals.formatDisplay(n, places);
}

function todayIso() {
  const d = new Date();
  const y = d.getFullYear();
  const m = String(d.getMonth() + 1).padStart(2, '0');
  const day = String(d.getDate()).padStart(2, '0');
  return `${y}-${m}-${day}`;
}

/** iso YYYY-MM-DD → DD-MM-YYYY */
function isoToDmy(iso) {
  const s = String(iso || '');
  const m = s.match(/^(\d{4})-(\d{2})-(\d{2})/);
  if (!m) return s;
  return `${m[3]}-${m[2]}-${m[1]}`;
}

/** DD-MM-YYYY or YYYY-MM-DD → YYYY-MM-DD */
function parseDateToIso(v) {
  const s = String(v || '').trim();
  if (/^\d{4}-\d{2}-\d{2}$/.test(s)) return s;
  const m = s.match(/^(\d{1,2})[\/\-.](\d{1,2})[\/\-.](\d{4})$/);
  if (m) {
    return `${m[3]}-${String(m[2]).padStart(2, '0')}-${String(m[1]).padStart(2, '0')}`;
  }
  return todayIso();
}

module.exports = { esc, fmtAmt, fmtUnitPrice, todayIso, isoToDmy, parseDateToIso };
