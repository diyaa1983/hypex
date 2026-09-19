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

/**
 * DD-MM-YYYY أو YYYY-MM-DD → YYYY-MM-DD.
 * إن فشل التحليل: إن وُجد fallback يُعاد، وإلا تاريخ اليوم (توافق مع حفظ المستندات).
 */
function parseDateToIso(v, fallback) {
  const s = String(v || '').trim();
  if (!s) {
    return arguments.length >= 2 ? fallback : todayIso();
  }
  if (/^\d{4}-\d{2}-\d{2}$/.test(s)) return s;
  const m = s.match(/^(\d{1,2})[\/\-.](\d{1,2})[\/\-.](\d{4})$/);
  if (m) {
    const d = Number(m[1]);
    const mo = Number(m[2]);
    const y = Number(m[3]);
    if (d >= 1 && d <= 31 && mo >= 1 && mo <= 12 && y >= 1900 && y <= 2100) {
      return `${y}-${String(mo).padStart(2, '0')}-${String(d).padStart(2, '0')}`;
    }
  }
  return arguments.length >= 2 ? fallback : todayIso();
}

/** فلاتر التقارير: من/إلى بصيغة ISO مع قبول d-m-Y من الواجهة */
function dateRange(from, to) {
  const d = new Date();
  const monthStart = `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-01`;
  return {
    from: parseDateToIso(from || '', monthStart),
    to: parseDateToIso(to || '', todayIso()),
  };
}

module.exports = { esc, fmtAmt, fmtUnitPrice, todayIso, isoToDmy, parseDateToIso, dateRange };
