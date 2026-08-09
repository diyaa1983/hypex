'use strict';

function esc(s) {
  return String(s ?? '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;');
}

function fmtAmt(n, dp = 3) {
  const x = Number(n) || 0;
  return x.toLocaleString('en-US', {
    minimumFractionDigits: dp,
    maximumFractionDigits: dp,
  });
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

module.exports = { esc, fmtAmt, todayIso, isoToDmy, parseDateToIso };
