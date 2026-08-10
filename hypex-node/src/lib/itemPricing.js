'use strict';

/**
 * تسعير المواد: default_sale / default_wholesale دائماً لـ أقل وحدة (base).
 * سعر وحدة الصرف = سعر الحبة × factor_to_base
 */
const db = require('../db');
const companyDecimals = require('./companyDecimals');

function r6(n) {
  return companyDecimals.roundUnit(n);
}

function r3(n) {
  return companyDecimals.roundAmount(n);
}

/** سعر وحدة الصرف من سعر أقل وحدة (غير شامل الضريبة) */
function unitPriceFromBase(basePrice, factor) {
  const f = Number(factor) > 0 ? Number(factor) : 1;
  return r6((Number(basePrice) || 0) * f);
}

async function fetchItemCore(itemId) {
  const id = Number(itemId);
  if (!id) return null;
  try {
    const rows = await db.query(
      `SELECT i.id, i.default_sale, i.default_cost, i.default_wholesale, i.unit_id, i.unit_name,
              i.tax_rate_id, tr.rate_percent AS tax_rate_percent
       FROM inv_item i
       LEFT JOIN sys_tax_rate tr ON tr.id = i.tax_rate_id AND tr.is_active = 1
       WHERE i.id = ? LIMIT 1`,
      [id]
    );
    return rows[0] || null;
  } catch {
    try {
      const rows = await db.query(
        `SELECT i.id, i.default_sale, i.default_cost, i.unit_id, i.unit_name
         FROM inv_item i WHERE i.id = ? LIMIT 1`,
        [id]
      );
      return rows[0] || null;
    } catch {
      return null;
    }
  }
}

async function fetchItemUnits(itemId) {
  const id = Number(itemId);
  if (!id) return [];
  try {
    const rows = await db.query(
      `SELECT iu.unit_id, iu.factor_to_base AS factor, iu.is_base, iu.is_default_issue,
              COALESCE(u.name_ar, '') AS name
       FROM inv_item_unit iu
       INNER JOIN inv_unit u ON u.id = iu.unit_id
       WHERE iu.item_id = ?
       ORDER BY iu.is_base DESC, iu.is_default_issue DESC, iu.id ASC`,
      [id]
    );
    if (rows.length) {
      return rows.map((r) => ({
        unit_id: Number(r.unit_id),
        factor: Number(r.factor) > 0 ? Number(r.factor) : 1,
        is_base: Number(r.is_base) === 1,
        is_default: Number(r.is_default_issue) === 1,
        name: String(r.name || ''),
      }));
    }
  } catch {
    /* */
  }
  // fallback: base unit from item
  try {
    const rows = await db.query(
      `SELECT unit_id, unit_name FROM inv_item WHERE id = ? LIMIT 1`,
      [id]
    );
    const u = rows[0];
    if (u && u.unit_id) {
      return [
        {
          unit_id: Number(u.unit_id),
          factor: 1,
          is_base: true,
          is_default: true,
          name: String(u.unit_name || 'قطعة'),
        },
      ];
    }
  } catch {
    /* */
  }
  return [{ unit_id: 0, factor: 1, is_base: true, is_default: true, name: 'قطعة' }];
}

/**
 * تسعيرة كاملة للمادة لاستخدام الواجهة/API
 */
async function getItemPricing(itemId) {
  const core = await fetchItemCore(itemId);
  if (!core) return null;
  const units = await fetchItemUnits(itemId);
  const baseSale = Number(core.default_sale) || 0;
  const baseWholesale =
    core.default_wholesale != null ? Number(core.default_wholesale) || 0 : 0;
  return {
    item_id: Number(core.id),
    base_sale: baseSale,
    base_cost: Number(core.default_cost) || 0,
    base_wholesale: baseWholesale,
    tax_rate_id: core.tax_rate_id != null ? Number(core.tax_rate_id) : null,
    tax_rate_percent:
      core.tax_rate_percent != null && core.tax_rate_percent !== ''
        ? Number(core.tax_rate_percent)
        : null,
    units: units.map((u) => ({
      ...u,
      sale_price: unitPriceFromBase(baseSale, u.factor),
      wholesale_price: unitPriceFromBase(baseWholesale, u.factor),
    })),
  };
}

/**
 * اربط بنود بحث المواد بوحدات وأسعار محسوبة.
 * @param {Array<object>} items rows from inv_item
 */
async function attachUnitsToSearchRows(items) {
  const list = Array.isArray(items) ? items : [];
  if (!list.length) return list;
  const ids = list.map((r) => Number(r.id)).filter((n) => n > 0);
  /** @type {Map<number, any[]>} */
  const unitsByItem = new Map();
  if (ids.length) {
    try {
      const ph = ids.map(() => '?').join(',');
      const rows = await db.query(
        `SELECT iu.item_id, iu.unit_id, iu.factor_to_base AS factor, iu.is_base, iu.is_default_issue,
                COALESCE(u.name_ar, '') AS name
         FROM inv_item_unit iu
         INNER JOIN inv_unit u ON u.id = iu.unit_id
         WHERE iu.item_id IN (${ph})
         ORDER BY iu.item_id, iu.is_base DESC, iu.is_default_issue DESC, iu.id`,
        ids
      );
      for (const r of rows) {
        const iid = Number(r.item_id);
        if (!unitsByItem.has(iid)) unitsByItem.set(iid, []);
        unitsByItem.get(iid).push({
          unit_id: Number(r.unit_id),
          factor: Number(r.factor) > 0 ? Number(r.factor) : 1,
          is_base: Number(r.is_base) === 1,
          is_default: Number(r.is_default_issue) === 1,
          name: String(r.name || ''),
        });
      }
    } catch {
      /* */
    }
  }

  // tax rates map
  let taxMap = new Map();
  try {
    const rates = await db.query(
      `SELECT id, rate_percent FROM sys_tax_rate WHERE is_active = 1`
    );
    for (const t of rates) taxMap.set(Number(t.id), Number(t.rate_percent));
  } catch {
    taxMap = new Map();
  }

  return list.map((it) => {
    const id = Number(it.id);
    const baseSale = Number(it.sale_price != null ? it.sale_price : it.default_sale) || 0;
    const baseWholesale =
      Number(it.wholesale_price != null ? it.wholesale_price : it.default_wholesale) || 0;
    let units = unitsByItem.get(id);
    if (!units || !units.length) {
      units = [
        {
          unit_id: Number(it.unit_id) || 0,
          factor: 1,
          is_base: true,
          is_default: true,
          name: String(it.unit_name || 'قطعة'),
        },
      ];
    }
    const unitsWithPrice = units.map((u) => ({
      ...u,
      sale_price: unitPriceFromBase(baseSale, u.factor),
      wholesale_price: unitPriceFromBase(baseWholesale, u.factor),
    }));
    const def =
      unitsWithPrice.find((u) => u.is_default) ||
      unitsWithPrice.find((u) => u.is_base) ||
      unitsWithPrice[0];
    let taxPct = null;
    if (it.tax_rate_percent != null && it.tax_rate_percent !== '') {
      taxPct = Number(it.tax_rate_percent);
    } else if (it.tax_rate_id && taxMap.has(Number(it.tax_rate_id))) {
      taxPct = taxMap.get(Number(it.tax_rate_id));
    }
    return {
      ...it,
      sale_price: baseSale,
      base_sale: baseSale,
      wholesale_price: baseWholesale,
      base_wholesale: baseWholesale,
      tax_rate_percent: taxPct,
      units: unitsWithPrice,
      default_unit_id: def ? def.unit_id : 0,
      default_unit_name: def ? def.name : 'قطعة',
      default_unit_factor: def ? def.factor : 1,
      unit_sale_price: def ? def.sale_price : baseSale,
    };
  });
}

/**
 * يفرض سعر البند من بطاقة المادة × معامل الوحدة (لا يقبل سعر يدوي).
 * @returns {{ unit_price:number, unit_factor:number, unit_id:number|null, unit_name:string|null, tax_rate_percent:?number, base_sale:number }}
 */
async function resolveDocLinePricing(raw) {
  const itemId = Number(raw.item_id || 0);
  if (!itemId) {
    return {
      unit_price: 0,
      unit_factor: 1,
      unit_id: null,
      unit_name: null,
      tax_rate_percent: null,
      base_sale: 0,
    };
  }
  const pricing = await getItemPricing(itemId);
  if (!pricing) {
    return {
      unit_price: r6(raw.unit_price),
      unit_factor: Number(raw.unit_factor) > 0 ? Number(raw.unit_factor) : 1,
      unit_id: raw.unit_id ? Number(raw.unit_id) : null,
      unit_name: String(raw.unit_name || '').trim() || null,
      tax_rate_percent: null,
      base_sale: 0,
    };
  }

  let unitId = raw.unit_id ? Number(raw.unit_id) : 0;
  let factor = Number(raw.unit_factor) > 0 ? Number(raw.unit_factor) : 0;
  let unitName = String(raw.unit_name || '').trim();

  let match = null;
  if (unitId > 0) {
    match = pricing.units.find((u) => u.unit_id === unitId) || null;
  }
  if (!match && factor > 0) {
    match = pricing.units.find((u) => Math.abs(u.factor - factor) < 0.000001) || null;
  }
  if (!match) {
    match =
      pricing.units.find((u) => u.is_default) ||
      pricing.units.find((u) => u.is_base) ||
      pricing.units[0] ||
      null;
  }

  if (match) {
    unitId = match.unit_id || unitId || null;
    factor = match.factor || 1;
    unitName = match.name || unitName || 'قطعة';
  } else {
    factor = factor > 0 ? factor : 1;
    if (!unitName) unitName = 'قطعة';
  }

  const unitPrice = unitPriceFromBase(pricing.base_sale, factor);

  return {
    unit_price: unitPrice,
    unit_factor: factor,
    unit_id: unitId || null,
    unit_name: unitName || null,
    tax_rate_percent: pricing.tax_rate_percent,
    base_sale: pricing.base_sale,
  };
}

module.exports = {
  r3,
  r6,
  unitPriceFromBase,
  getItemPricing,
  attachUnitsToSearchRows,
  resolveDocLinePricing,
  fetchItemUnits,
};
