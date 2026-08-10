'use strict';

/**
 * حسابات أسطر المستندات المشتركة (مبيعات/مشتريات)
 */
function r3(n) {
  return Math.round((Number(n) || 0) * 1000) / 1000;
}

function r6(n) {
  return Math.round((Number(n) || 0) * 1e6) / 1e6;
}

function computeLine(raw, defaultTax = 16) {
  const qty = r6(raw.qty);
  const qtyExtra = r6(raw.qty_extra);
  const unitPrice = r6(raw.unit_price);
  const discountPct = r6(raw.discount_pct);
  const taxRate =
    raw.tax_rate_percent != null && raw.tax_rate_percent !== ''
      ? r6(raw.tax_rate_percent)
      : Number(defaultTax) || 0;
  let lineSub = qty * unitPrice;
  let discAmt = 0;
  if (discountPct > 0) {
    discAmt = r3((lineSub * discountPct) / 100);
    lineSub = r3(lineSub - discAmt);
  } else {
    lineSub = r3(lineSub);
  }
  const taxAmt = r3((lineSub * taxRate) / 100);
  const lineGross = r3(lineSub + taxAmt);
  const unitFactor = Number(raw.unit_factor) > 0 ? Number(raw.unit_factor) : 1;
  return {
    item_id: Number(raw.item_id),
    name_ar: String(raw.name_ar || raw.line_desc || raw.item_name || '').trim(),
    qty,
    qty_extra: qtyExtra,
    unit_price: unitPrice,
    discount_pct: discountPct,
    discount_amount: discAmt,
    tax_rate_percent: taxRate,
    tax_amount: taxAmt,
    line_total: lineSub,
    line_subtotal: lineSub,
    line_gross: lineGross,
    unit_id: raw.unit_id ? Number(raw.unit_id) : null,
    unit_name: String(raw.unit_name || '').trim() || null,
    unit_factor: unitFactor,
    qty_base: r6((qty + qtyExtra) * unitFactor),
  };
}

function applyHeaderDiscount(lines, discountInput) {
  const sumLineSub = r3(lines.reduce((a, l) => a + l.line_total, 0));
  const sumTaxBefore = r3(lines.reduce((a, l) => a + l.tax_amount, 0));
  const sumGrossBefore = r3(lines.reduce((a, l) => a + l.line_gross, 0));
  const raw = String(discountInput || '').trim();
  if (!raw || sumLineSub <= 0) {
    return {
      lines,
      subtotal: sumLineSub,
      tax_amount: sumTaxBefore,
      total: sumGrossBefore,
      discount_amount: r3(lines.reduce((a, l) => a + (l.discount_amount || 0), 0)),
    };
  }

  let headerDisc = 0;
  if (raw.endsWith('%')) {
    const pct = parseFloat(raw.slice(0, -1));
    if (pct > 0) headerDisc = r3((sumLineSub * pct) / 100);
  } else if (!raw.includes('.') && Number(raw) >= 1 && Number(raw) <= 100) {
    headerDisc = r3((sumLineSub * Number(raw)) / 100);
  } else {
    headerDisc = r3(parseFloat(raw) || 0);
  }
  headerDisc = Math.min(headerDisc, sumLineSub);
  if (headerDisc <= 0) {
    return {
      lines,
      subtotal: sumLineSub,
      tax_amount: sumTaxBefore,
      total: sumGrossBefore,
      discount_amount: r3(lines.reduce((a, l) => a + (l.discount_amount || 0), 0)),
    };
  }

  let allocated = 0;
  const out = lines.map((l, idx) => {
    const share =
      idx === lines.length - 1
        ? r3(headerDisc - allocated)
        : r3((headerDisc * l.line_total) / sumLineSub);
    allocated = r3(allocated + share);
    const newSub = r3(Math.max(0, l.line_total - share));
    const tax = r3((newSub * l.tax_rate_percent) / 100);
    return {
      ...l,
      line_total: newSub,
      line_subtotal: newSub,
      tax_amount: tax,
      line_gross: r3(newSub + tax),
      discount_amount: r3((l.discount_amount || 0) + share),
    };
  });

  return {
    lines: out,
    subtotal: r3(out.reduce((a, l) => a + l.line_total, 0)),
    tax_amount: r3(out.reduce((a, l) => a + l.tax_amount, 0)),
    total: r3(out.reduce((a, l) => a + l.line_gross, 0)),
    discount_amount: r3(out.reduce((a, l) => a + (l.discount_amount || 0), 0)),
  };
}

function normalizeLines(rawLines, defaultTax) {
  const normalized = [];
  for (const ln of rawLines || []) {
    if (!ln || !Number(ln.item_id)) continue;
    if (Number(ln.qty) <= 0 && Number(ln.qty_extra) <= 0) continue;
    normalized.push(computeLine(ln, defaultTax));
  }
  return normalized;
}

/**
 * يرفض الحفظ إن وُجد بند مادة بدون سعر موجب.
 * @returns {{ ok: true } | { ok: false, error: string }}
 */
function requirePositiveUnitPrices(lines) {
  if (!Array.isArray(lines) || !lines.length) return { ok: true };
  for (let i = 0; i < lines.length; i++) {
    const ln = lines[i];
    if (!ln || !Number(ln.item_id)) continue;
    if (!(Number(ln.unit_price) > 0)) {
      return {
        ok: false,
        error: `أدخل السعر للمادة في البند رقم ${i + 1}. لا يمكن الحفظ بدون سعر.`,
      };
    }
  }
  return { ok: true };
}

module.exports = {
  r3,
  r6,
  computeLine,
  applyHeaderDiscount,
  normalizeLines,
  requirePositiveUnitPrices,
};
