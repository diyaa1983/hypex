'use strict';

/**
 * تطبيق عروض المبيعات على بنود الطلب/الفاتورة
 * — كمية إضافية → qty_extra
 * — خصم % → discount_pct
 */
const offers = require('./offersService');

/**
 * يعدّل بنود raw (قبل computeLine) حسب عروض التاريخ.
 * @returns {{ lines: object[], applications: object[] }}
 */
async function applyOffersToRawLines(rawLines, docDate) {
  const map = await offers.activeOfferMapForDate(docDate);
  const applications = [];
  const lines = (Array.isArray(rawLines) ? rawLines : []).map((ln) => {
    if (!ln || !Number(ln.item_id)) return ln;
    const qty = Number(ln.qty) || 0;
    const offer = map.get(Number(ln.item_id));
    const effect = offers.computeOfferEffect(qty, offer);
    if (!effect.applied) return ln;
    const next = { ...ln };
    if (effect.offer.offer_type === 'bonus') {
      next.qty_extra = effect.bonus_qty;
    } else {
      next.discount_pct = effect.discount_pct;
    }
    applications.push({
      offer_id: effect.offer.offer_id,
      offer_line_id: effect.offer.offer_line_id,
      item_id: Number(ln.item_id),
      qty,
      bonus_qty: effect.bonus_qty,
      discount_pct: effect.discount_pct,
      offer_no: effect.offer.offer_no,
      offer_name: effect.offer.offer_name,
      offer_type: effect.offer.offer_type,
    });
    return next;
  });
  return { lines, applications };
}

async function previewOffers(lines, docDate) {
  const { applications } = await applyOffersToRawLines(lines, docDate);
  return applications.map((a) => ({
    item_id: a.item_id,
    offer_no: a.offer_no,
    offer_name: a.offer_name,
    offer_type: a.offer_type,
    bonus_qty: a.bonus_qty,
    discount_pct: a.discount_pct,
  }));
}

module.exports = {
  applyOffersToRawLines,
  previewOffers,
};
