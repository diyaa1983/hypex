'use strict';

const db = require('../db');
const companyDecimals = require('../lib/companyDecimals');

async function safeQuery(sql, params = []) {
  try {
    return await db.query(sql, params);
  } catch (e) {
    console.error('inventory masters', e.message);
    throw e;
  }
}

async function tableHasColumn(table, column) {
  try {
    await db.query(`SELECT \`${column}\` FROM \`${table}\` LIMIT 1`);
    return true;
  } catch {
    return false;
  }
}

/* ── Warehouses ── */
async function listWarehouses({ q = '' } = {}) {
  const params = [];
  let where = '1=1';
  if (q) {
    where += ` AND (w.name_ar LIKE ? OR IFNULL(w.code,'') LIKE ?)`;
    params.push(`%${q}%`, `%${q}%`);
  }
  return safeQuery(
    `SELECT w.id, w.code, w.name_ar, w.is_active
     FROM inv_warehouse w
     WHERE ${where}
     ORDER BY w.id DESC LIMIT 300`,
    params
  );
}

async function getWarehouse(id) {
  const rows = await safeQuery(`SELECT * FROM inv_warehouse WHERE id = ? LIMIT 1`, [Number(id)]);
  return rows[0] || null;
}

async function saveWarehouse(payload) {
  const id = Number(payload.id || 0);
  let code = String(payload.code || '').trim();
  const name = String(payload.name_ar || '').trim();
  if (!name) return { ok: false, error: 'اسم المستودع مطلوب.' };

  if (!code) {
    const max = await safeQuery(`SELECT IFNULL(MAX(id), 0) AS m FROM inv_warehouse`);
    code = 'WH-' + String(Number(max[0]?.m || 0) + 1).padStart(4, '0');
  }

  const dup = id
    ? await safeQuery(`SELECT id FROM inv_warehouse WHERE code = ? AND id <> ? LIMIT 1`, [code, id])
    : await safeQuery(`SELECT id FROM inv_warehouse WHERE code = ? LIMIT 1`, [code]);
  if (dup[0]) return { ok: false, error: 'رمز المستودع مستخدم مسبقاً.' };

  if (id > 0) {
    await safeQuery(`UPDATE inv_warehouse SET code = ?, name_ar = ? WHERE id = ?`, [code, name, id]);
    return { ok: true, id, message: 'تم تحديث المستودع.' };
  }
  const [result] = await db.getPool().execute(
    `INSERT INTO inv_warehouse (code, name_ar, is_active) VALUES (?,?,1)`,
    [code, name]
  );
  return { ok: true, id: Number(result.insertId), message: 'تم إضافة المستودع.' };
}

async function deleteWarehouse(id) {
  const whId = Number(id);
  if (!whId) return { ok: false, error: 'معرّف غير صالح.' };

  try {
    const moves = await safeQuery(
      `SELECT COUNT(*) AS c FROM inv_stock_move WHERE warehouse_id = ?`,
      [whId]
    );
    if (Number(moves[0]?.c || 0) > 0) {
      return { ok: false, error: 'لا يمكن حذف مستودع مرتبط بحركات مخزون.' };
    }
  } catch {
    /* table may not exist */
  }
  try {
    const items = await safeQuery(
      `SELECT COUNT(*) AS c FROM inv_item WHERE default_warehouse_id = ?`,
      [whId]
    );
    if (Number(items[0]?.c || 0) > 0) {
      return { ok: false, error: 'لا يمكن حذف مستودع مرتبط بمواد.' };
    }
  } catch {
    /* */
  }

  await safeQuery(`DELETE FROM inv_warehouse WHERE id = ?`, [whId]);
  return { ok: true, message: 'تم حذف المستودع.' };
}

/* ── Items ── */

async function ensureItemCardColumns() {
  const alters = [
    ['name_en', 'VARCHAR(200) NULL DEFAULT NULL'],
    ['default_wholesale', 'DECIMAL(18,6) NOT NULL DEFAULT 0'],
    ['tax_rate_id', 'INT UNSIGNED NULL DEFAULT NULL'],
    ['expiry_date', 'DATE NULL DEFAULT NULL'],
    ['notify_on_expiry', 'TINYINT(1) NOT NULL DEFAULT 0'],
  ];
  for (const [col, def] of alters) {
    if (!(await tableHasColumn('inv_item', col))) {
      try {
        await db.query(`ALTER TABLE inv_item ADD COLUMN \`${col}\` ${def}`);
      } catch (e) {
        console.error('ensureItemCardColumns', col, e.message);
      }
    }
  }
}

/** رقم العرض على الشاشات والتقارير والفواتير: الباركود فقط (رقم المادة/sku للبطاقة فقط) */
function itemDisplayCode(row) {
  const bc = String(row?.barcode || '').trim();
  if (bc) return bc;
  return String(row?.sku || '').trim();
}

async function itemHasMovements(itemId) {
  const id = Number(itemId);
  if (!id) return false;
  try {
    const m = await safeQuery(
      `SELECT COUNT(*) AS c FROM inv_stock_move WHERE item_id = ? LIMIT 1`,
      [id]
    );
    if (Number(m[0]?.c || 0) > 0) return true;
  } catch {
    /* */
  }
  const tables = [
    'sal_invoice_line',
    'pur_invoice_line',
    'sal_customer_order_line',
    'pur_order_line',
  ];
  for (const t of tables) {
    try {
      const r = await safeQuery(`SELECT COUNT(*) AS c FROM \`${t}\` WHERE item_id = ? LIMIT 1`, [id]);
      if (Number(r[0]?.c || 0) > 0) return true;
    } catch {
      /* */
    }
  }
  return false;
}

async function listItems({ q = '', activeOnly = false } = {}) {
  await ensureItemCardColumns();
  const where = ['1=1'];
  const params = [];
  if (activeOnly) where.push('i.is_active = 1');
  if (q) {
    const like = `%${q}%`;
    where.push(
      `(i.name_ar LIKE ? OR IFNULL(i.name_en,'') LIKE ? OR IFNULL(i.sku,'') LIKE ? OR IFNULL(i.barcode,'') LIKE ?)`
    );
    params.push(like, like, like, like);
  }
  const hasWholesale = await tableHasColumn('inv_item', 'default_wholesale');
  const wholesaleSel = hasWholesale ? 'i.default_wholesale' : '0 AS default_wholesale';
  return safeQuery(
    `SELECT i.id, i.sku, i.barcode, i.name_ar, i.name_en, i.default_cost, i.default_sale,
            ${wholesaleSel}, i.is_active, i.expiry_date,
            c.name_ar AS category_name, u.name_ar AS unit_name, w.name_ar AS warehouse_name
     FROM inv_item i
     LEFT JOIN inv_item_category c ON c.id = i.category_id
     LEFT JOIN inv_unit u ON u.id = i.unit_id
     LEFT JOIN inv_warehouse w ON w.id = i.default_warehouse_id
     WHERE ${where.join(' AND ')}
     ORDER BY i.id DESC LIMIT 300`,
    params
  );
}

async function getItem(id) {
  await ensureItemCardColumns();
  const rows = await safeQuery(`SELECT i.* FROM inv_item i WHERE i.id = ? LIMIT 1`, [Number(id)]);
  const item = rows[0] || null;
  if (!item) return null;
  item.item_units = await getItemUnits(Number(id));
  item.prices_locked = await itemHasMovements(Number(id));
  item.units_locked = item.prices_locked;
  return item;
}

async function getItemUnits(itemId) {
  const id = Number(itemId);
  if (!id) return [];
  try {
    return await safeQuery(
      `SELECT iu.id, iu.unit_id, iu.factor_to_base, iu.is_base, iu.is_default_issue,
              u.name_ar AS unit_name, u.code AS unit_code
       FROM inv_item_unit iu
       INNER JOIN inv_unit u ON u.id = iu.unit_id
       WHERE iu.item_id = ?
       ORDER BY iu.is_base DESC, iu.is_default_issue DESC, iu.id ASC`,
      [id]
    );
  } catch {
    return [];
  }
}

async function itemLookups() {
  let categories = [];
  let units = [];
  let warehouses = [];
  let taxRates = [];
  try {
    categories = await safeQuery(
      `SELECT id, code, name_ar FROM inv_item_category WHERE is_active = 1 ORDER BY name_ar LIMIT 300`
    );
  } catch {
    categories = [];
  }
  try {
    units = await safeQuery(`SELECT id, code, name_ar FROM inv_unit WHERE is_active = 1 ORDER BY name_ar LIMIT 300`);
  } catch {
    try {
      units = await safeQuery(`SELECT id, code, name_ar FROM inv_unit ORDER BY name_ar LIMIT 300`);
    } catch {
      units = [];
    }
  }
  try {
    warehouses = await safeQuery(
      `SELECT id, code, name_ar FROM inv_warehouse WHERE is_active = 1 ORDER BY name_ar LIMIT 200`
    );
  } catch {
    warehouses = [];
  }
  try {
    taxRates = await safeQuery(
      `SELECT id, name_ar, rate_percent FROM sys_tax_rate WHERE is_active = 1 ORDER BY sort_order, id LIMIT 100`
    );
  } catch {
    taxRates = [];
  }
  return { categories, units, warehouses, taxRates };
}

async function nextBarcode() {
  try {
    const rows = await safeQuery(
      `SELECT barcode FROM inv_item WHERE barcode REGEXP '^[0-9]+$' ORDER BY LENGTH(barcode) DESC, barcode DESC LIMIT 1`
    );
    const last = String(rows[0]?.barcode || '0');
    const n = Number(last) || 0;
    return String(n + 1).padStart(6, '0');
  } catch {
    const rows = await safeQuery(`SELECT COUNT(*) AS c FROM inv_item`);
    return String(Number(rows[0]?.c || 0) + 1).padStart(6, '0');
  }
}

async function allocateSku(itemId) {
  return 'I' + String(itemId).padStart(6, '0');
}

/**
 * Form arrays may arrive as pack_unit_id (extended:true) or pack_unit_id[] (extended:false).
 */
function bodyArray(payload, keys) {
  for (const k of keys) {
    const raw = payload?.[k];
    if (raw == null || raw === '') continue;
    return Array.isArray(raw) ? raw : [raw];
  }
  return [];
}

/**
 * Parse multi-unit rows: no duplicate unit_id; one base (factor 1).
 * payload:
 *  - unit_id (base)
 *  - pack_unit_id / pack_unit_id[] + pack_factor / pack_factor[] additional issue units
 */
function parsePackUnits(payload, baseUnitId) {
  const baseId = Number(baseUnitId) || 0;
  const seen = new Set();
  if (baseId > 0) seen.add(baseId);

  const ids = bodyArray(payload, ['pack_unit_id', 'pack_unit_id[]']);
  const factors = bodyArray(payload, ['pack_factor', 'pack_factor[]']);

  // legacy single issue_unit_id
  if (!ids.length && Number(payload.issue_unit_id || 0) > 0) {
    ids.push(payload.issue_unit_id);
    factors.push(payload.issue_factor || 1);
  }

  const extras = [];
  for (let i = 0; i < ids.length; i++) {
    const uid = Number(ids[i] || 0);
    if (!uid || seen.has(uid)) continue;
    const factorRaw = factors[i];
    // unit chosen without factor → skip (empty row or incomplete)
    if (factorRaw === '' || factorRaw == null) continue;
    let factor = Number(String(factorRaw).replace(',', '.')) || 0;
    if (factor <= 0) continue;
    // base unit pack row → ignore (base is always factor 1)
    if (uid === baseId) continue;
    seen.add(uid);
    extras.push({ unit_id: uid, factor });
  }
  return { baseUnitId: baseId, extras };
}

async function findPieceUnitId() {
  try {
    const rows = await safeQuery(
      `SELECT id FROM inv_unit
       WHERE code = 'PCS' OR name_ar IN ('قطعة','حبة','pcs','PCS')
       ORDER BY id LIMIT 1`
    );
    return Number(rows[0]?.id || 0) || null;
  } catch {
    return null;
  }
}

/**
 * If pack unit is carton with factor>1 and base is same carton, force base = piece.
 */
async function normalizeBaseAndPacks(baseUnitId, extras) {
  let baseId = Number(baseUnitId) || 0;
  let packList = extras.slice();
  if (!baseId) return { baseUnitId: baseId, extras: packList };

  const baseRow = await safeQuery(`SELECT id, code, name_ar FROM inv_unit WHERE id = ? LIMIT 1`, [baseId]);
  const baseName = String(baseRow[0]?.name_ar || '').trim();
  const baseCode = String(baseRow[0]?.code || '').trim().toUpperCase();

  const isCartonLike = (name, code) => {
    const n = String(name || '').toLowerCase();
    const c = String(code || '').toUpperCase();
    return (
      n.includes('كرتون') ||
      n.includes('كرتونة') ||
      n.includes('carton') ||
      n.includes('box') ||
      c === 'BOX' ||
      c === 'CTN'
    );
  };

  // if user put carton as "base" with a pack factor on same, convert
  const selfPack = packList.find((p) => p.unit_id === baseId && p.factor > 1);
  if (selfPack || (isCartonLike(baseName, baseCode) && packList.some((p) => p.factor > 1 && p.unit_id === baseId))) {
    const pcs = await findPieceUnitId();
    if (pcs && pcs !== baseId) {
      const cartFactor = selfPack?.factor || packList.find((p) => p.unit_id === baseId)?.factor || 1;
      packList = packList.filter((p) => p.unit_id !== baseId && p.unit_id !== pcs);
      packList.unshift({ unit_id: baseId, factor: cartFactor > 1 ? cartFactor : 1 });
      baseId = pcs;
    }
  }

  // remove duplicates again
  const seen = new Set([baseId]);
  packList = packList.filter((p) => {
    if (!p.unit_id || seen.has(p.unit_id) || p.factor <= 0) return false;
    seen.add(p.unit_id);
    return true;
  });

  return { baseUnitId: baseId, extras: packList };
}

async function saveItemUnits(itemId, baseUnitId, extras, locked) {
  if (locked) return;
  const id = Number(itemId);
  if (!id || !baseUnitId) return;
  try {
    await safeQuery(`DELETE FROM inv_item_unit WHERE item_id = ?`, [id]);
    await safeQuery(
      `INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
       VALUES (?,?,1,1,?)`,
      [id, baseUnitId, extras.length ? 0 : 1]
    );
    let first = true;
    for (const ex of extras) {
      await safeQuery(
        `INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
         VALUES (?,?,?,0,?)`,
        [id, ex.unit_id, ex.factor, first ? 1 : 0]
      );
      first = false;
    }
  } catch (e) {
    console.error('saveItemUnits', e.message);
  }
}

async function saveItem(payload) {
  await ensureItemCardColumns();
  const id = Number(payload.id || 0);
  let sku = String(payload.sku || '').trim();
  let barcode = String(payload.barcode || '').trim();
  const name = String(payload.name_ar || '').trim();
  const nameEn = String(payload.name_en || '').trim();
  const categoryId = Number(payload.category_id || 0) || null;
  let unitId = Number(payload.unit_id || 0) || null;
  const warehouseId = Number(payload.default_warehouse_id || 0) || null;
  const taxRateId = Number(payload.tax_rate_id || 0) || null;
  let cost = Number(String(payload.default_cost || '0').replace(',', '.')) || 0;
  let sale = Number(String(payload.default_sale || '0').replace(',', '.')) || 0;
  let wholesale = Number(String(payload.default_wholesale || '0').replace(',', '.')) || 0;
  const isActive =
    payload.is_active === undefined || payload.is_active === null || payload.is_active === ''
      ? 1
      : payload.is_active === '1' || payload.is_active === 1 || payload.is_active === true || payload.is_active === 'on'
        ? 1
        : 0;
  let expiryDate = String(payload.expiry_date || '').trim().slice(0, 10);
  if (expiryDate && !/^\d{4}-\d{2}-\d{2}$/.test(expiryDate)) expiryDate = null;
  if (!expiryDate) expiryDate = null;
  const notifyExpiry =
    payload.notify_on_expiry === '1' || payload.notify_on_expiry === 1 || payload.notify_on_expiry === 'on' ? 1 : 0;

  if (!name) return { ok: false, error: 'اسم المادة بالعربي مطلوب.' };
  if (cost < 0 || sale < 0 || wholesale < 0) return { ok: false, error: 'الأسعار يجب أن تكون ≥ 0.' };

  try {
    await companyDecimals.load();
  } catch {
    /* use cache defaults */
  }
  cost = companyDecimals.roundUnit(cost);
  sale = companyDecimals.roundUnit(sale);
  wholesale = companyDecimals.roundUnit(wholesale);

  const hasBarcode = await tableHasColumn('inv_item', 'barcode');
  const hasCategory = await tableHasColumn('inv_item', 'category_id');
  const hasUnitId = await tableHasColumn('inv_item', 'unit_id');
  const hasWh = await tableHasColumn('inv_item', 'default_warehouse_id');
  const hasNameEn = await tableHasColumn('inv_item', 'name_en');
  const hasWholesale = await tableHasColumn('inv_item', 'default_wholesale');
  const hasTax = await tableHasColumn('inv_item', 'tax_rate_id');
  const hasExpiry = await tableHasColumn('inv_item', 'expiry_date');

  if (hasUnitId && !unitId) return { ok: false, error: 'اختر الوحدة الأساسية (مثال: قطعة).' };

  const pricesLocked = id > 0 ? await itemHasMovements(id) : false;
  const unitsLocked = pricesLocked;

  if (pricesLocked) {
    const cur = await safeQuery(
      `SELECT default_cost, default_sale${hasWholesale ? ', default_wholesale' : ''} FROM inv_item WHERE id = ? LIMIT 1`,
      [id]
    );
    if (!cur[0]) return { ok: false, error: 'المادة غير موجودة.' };
    cost = Number(cur[0].default_cost || 0);
    sale = Number(cur[0].default_sale || 0);
    wholesale = hasWholesale ? Number(cur[0].default_wholesale || 0) : wholesale;
  }

  // multi unit normalize
  let packExtras = [];
  /** When item has movements, base unit stays locked — but allow *first* carton/pack definition if none yet */
  let packWriteAllowed = !unitsLocked;
  if (unitId && !unitsLocked) {
    const parsed = parsePackUnits(payload, unitId);
    const norm = await normalizeBaseAndPacks(parsed.baseUnitId, parsed.extras);
    unitId = norm.baseUnitId || unitId;
    packExtras = norm.extras;
  } else if (unitId && unitsLocked) {
    // keep existing base
    const curU = await safeQuery(`SELECT unit_id FROM inv_item WHERE id = ? LIMIT 1`, [id]);
    if (curU[0]?.unit_id) unitId = Number(curU[0].unit_id);
    const existing = await getItemUnits(id);
    const hasPack = existing.some((u) => !Number(u.is_base));
    if (!hasPack) {
      const parsed = parsePackUnits(payload, unitId);
      const norm = await normalizeBaseAndPacks(unitId, parsed.extras);
      packExtras = norm.extras;
      if (packExtras.length) packWriteAllowed = true;
    }
  }

  let unitName = 'قطعة';
  if (unitId) {
    const u = await safeQuery(`SELECT name_ar FROM inv_unit WHERE id = ? LIMIT 1`, [unitId]);
    if (u[0]?.name_ar) unitName = String(u[0].name_ar);
  } else if (payload.unit_name) {
    unitName = String(payload.unit_name);
  }

  if (!barcode && hasBarcode) {
    barcode = await nextBarcode();
  }
  if (hasBarcode && barcode) {
    if (!/^\d{1,14}$/.test(barcode)) {
      return { ok: false, error: 'الباركود: أرقام فقط (حتى 14 رقماً).' };
    }
    const dupBc = id
      ? await safeQuery(`SELECT id FROM inv_item WHERE barcode = ? AND id <> ? LIMIT 1`, [barcode, id])
      : await safeQuery(`SELECT id FROM inv_item WHERE barcode = ? LIMIT 1`, [barcode]);
    if (dupBc[0]) return { ok: false, error: 'الباركود مستخدم مسبقاً.' };
  }

  const autoSku = id < 1 && !sku;
  if (autoSku) sku = 'tmp-' + Date.now();

  if (!autoSku) {
    const dupSku = id
      ? await safeQuery(`SELECT id FROM inv_item WHERE sku = ? AND id <> ? LIMIT 1`, [sku, id])
      : await safeQuery(`SELECT id FROM inv_item WHERE sku = ? LIMIT 1`, [sku]);
    if (dupSku[0]) return { ok: false, error: 'رقم المادة مستخدم مسبقاً.' };
  }

  // build dynamic update/insert
  if (id > 0) {
    const sets = [
      'sku=?',
      'name_ar=?',
      'unit_name=?',
      'default_cost=?',
      'default_sale=?',
      'track_inventory=1',
      'is_active=?',
    ];
    const params = [sku, name, unitName, cost, sale, isActive];

    if (hasBarcode) {
      sets.push('barcode=?');
      params.push(barcode || null);
    }
    if (hasNameEn) {
      sets.push('name_en=?');
      params.push(nameEn || null);
    }
    if (hasCategory) {
      sets.push('category_id=?');
      params.push(categoryId);
    }
    if (hasUnitId && !unitsLocked) {
      sets.push('unit_id=?');
      params.push(unitId);
    }
    if (hasWh) {
      sets.push('default_warehouse_id=?');
      params.push(warehouseId);
    }
    if (hasWholesale) {
      sets.push('default_wholesale=?');
      params.push(wholesale);
    }
    if (hasTax) {
      sets.push('tax_rate_id=?');
      params.push(taxRateId);
    }
    if (hasExpiry) {
      sets.push('expiry_date=?', 'notify_on_expiry=?');
      params.push(expiryDate, notifyExpiry);
    }
    params.push(id);
    await safeQuery(`UPDATE inv_item SET ${sets.join(', ')} WHERE id=?`, params);
    await saveItemUnits(id, unitId, packExtras, !packWriteAllowed);
    let msg = 'تم تحديث المادة.';
    if (pricesLocked) {
      msg = packWriteAllowed && packExtras.length
        ? 'تم تحديث المادة. أُضيفت وحدة التعبئة. الأسعار ما زالت مقفلة بعد الحركات.'
        : 'تم تحديث المادة (الأسعار والوحدات مقفلة بعد الحركات — عدّل الأسعار من شاشات الأسعار).';
    }
    return { ok: true, id, message: msg };
  }

  // insert
  const cols = ['sku', 'name_ar', 'unit_name', 'default_cost', 'default_sale', 'track_inventory', 'is_active'];
  const vals = [sku, name, unitName, cost, sale, 1, isActive];
  if (hasBarcode) {
    cols.push('barcode');
    vals.push(barcode || null);
  }
  if (hasNameEn) {
    cols.push('name_en');
    vals.push(nameEn || null);
  }
  if (hasCategory) {
    cols.push('category_id');
    vals.push(categoryId);
  }
  if (hasUnitId) {
    cols.push('unit_id');
    vals.push(unitId);
  }
  if (hasWh) {
    cols.push('default_warehouse_id');
    vals.push(warehouseId);
  }
  if (hasWholesale) {
    cols.push('default_wholesale');
    vals.push(wholesale);
  }
  if (hasTax) {
    cols.push('tax_rate_id');
    vals.push(taxRateId);
  }
  if (hasExpiry) {
    cols.push('expiry_date', 'notify_on_expiry');
    vals.push(expiryDate, notifyExpiry);
  }

  const placeholders = cols.map(() => '?').join(',');
  const [result] = await db.getPool().execute(
    `INSERT INTO inv_item (${cols.join(',')}) VALUES (${placeholders})`,
    vals
  );
  const newId = Number(result.insertId);

  if (autoSku && newId) {
    const finalSku = await allocateSku(newId);
    await safeQuery(`UPDATE inv_item SET sku = ? WHERE id = ?`, [finalSku, newId]);
  }

  await saveItemUnits(newId, unitId, packExtras, false);

  const openingQty = Number(String(payload.opening_qty || '0').replace(',', '.')) || 0;
  if (openingQty > 0 && warehouseId && newId) {
    try {
      await safeQuery(
        `INSERT INTO inv_stock_move
         (move_date, warehouse_id, item_id, qty_in, qty_out, ref_type, ref_id, notes)
         VALUES (CURDATE(), ?, ?, ?, 0, 'item_opening', ?, ?)`,
        [warehouseId, newId, openingQty, newId, 'رصيد افتتاحي — ' + name]
      );
    } catch (e) {
      // alternate schema
      try {
        await safeQuery(
          `INSERT INTO inv_stock_move (move_date, warehouse_id, item_id, qty, direction, ref_type, ref_id, notes)
           VALUES (CURDATE(),?,?,?,'in','item_opening',?,?)`,
          [warehouseId, newId, openingQty, newId, 'رصيد افتتاحي — ' + name]
        );
      } catch (e2) {
        console.error('opening stock', e2.message);
      }
    }
  }

  return { ok: true, id: newId, message: 'تم إضافة المادة.' };
}

async function deleteItem(id) {
  const itemId = Number(id);
  if (!itemId) return { ok: false, error: 'معرّف غير صالح.' };
  if (await itemHasMovements(itemId)) {
    return { ok: false, error: 'لا يمكن حذف مادة لها حركات. أوقفها من بطاقة المادة بدلاً من ذلك.' };
  }
  try {
    await safeQuery(`DELETE FROM inv_item_unit WHERE item_id = ?`, [itemId]);
  } catch {
    /* */
  }
  await safeQuery(`DELETE FROM inv_item WHERE id = ?`, [itemId]);
  return { ok: true, message: 'تم حذف المادة.' };
}

async function toggleItemActive(id) {
  const itemId = Number(id);
  if (!itemId) return { ok: false, error: 'معرّف غير صالح.' };
  await safeQuery(`UPDATE inv_item SET is_active = 1 - is_active WHERE id = ?`, [itemId]);
  const rows = await safeQuery(`SELECT is_active FROM inv_item WHERE id = ? LIMIT 1`, [itemId]);
  const on = Number(rows[0]?.is_active) === 1;
  return { ok: true, message: on ? 'تم تفعيل المادة.' : 'تم إيقاف المادة.' };
}

/* ── Units ── */
async function listUnits({ q = '' } = {}) {
  const params = [];
  let where = '1=1';
  if (q) {
    where += ` AND (u.name_ar LIKE ? OR IFNULL(u.code,'') LIKE ?)`;
    params.push(`%${q}%`, `%${q}%`);
  }
  return safeQuery(
    `SELECT u.id, u.code, u.name_ar, u.is_active FROM inv_unit u
     WHERE ${where} ORDER BY u.id DESC LIMIT 300`,
    params
  );
}

async function getUnit(id) {
  const rows = await safeQuery(`SELECT * FROM inv_unit WHERE id = ? LIMIT 1`, [Number(id)]);
  return rows[0] || null;
}

async function saveUnit(payload) {
  const id = Number(payload.id || 0);
  let code = String(payload.code || '').trim();
  const name = String(payload.name_ar || '').trim();
  if (!name) return { ok: false, error: 'اسم الوحدة مطلوب.' };

  if (!code) {
    const max = await safeQuery(`SELECT IFNULL(MAX(id), 0) AS m FROM inv_unit`);
    code = 'UN-' + String(Number(max[0]?.m || 0) + 1).padStart(4, '0');
  }

  const dup = id
    ? await safeQuery(`SELECT id FROM inv_unit WHERE code = ? AND id <> ? LIMIT 1`, [code, id])
    : await safeQuery(`SELECT id FROM inv_unit WHERE code = ? LIMIT 1`, [code]);
  if (dup[0]) return { ok: false, error: 'رمز الوحدة مستخدم مسبقاً.' };

  if (id > 0) {
    await safeQuery(`UPDATE inv_unit SET code = ?, name_ar = ? WHERE id = ?`, [code, name, id]);
    try {
      await safeQuery(`UPDATE inv_item SET unit_name = ? WHERE unit_id = ?`, [name, id]);
    } catch {
      /* optional */
    }
    return { ok: true, id, message: 'تم تحديث الوحدة.' };
  }

  const [result] = await db.getPool().execute(
    `INSERT INTO inv_unit (code, name_ar, is_active) VALUES (?,?,1)`,
    [code, name]
  );
  return { ok: true, id: Number(result.insertId), message: 'تم إضافة الوحدة.' };
}

async function toggleUnit(id) {
  const unitId = Number(id);
  if (!unitId) return { ok: false, error: 'معرّف غير صالح.' };
  await safeQuery(`UPDATE inv_unit SET is_active = 1 - is_active WHERE id = ?`, [unitId]);
  return { ok: true, message: 'تم تحديث حالة الوحدة.' };
}

/* ── Item categories ── */
async function nextCategoryCode() {
  const codes = await safeQuery(`SELECT code FROM inv_item_category`);
  let max = 0;
  for (const row of codes) {
    const c = String(row.code || '').trim();
    if (/^\d+$/.test(c)) max = Math.max(max, Number(c));
  }
  const m = await safeQuery(`SELECT IFNULL(MAX(id), 0) AS m FROM inv_item_category`);
  return String(Math.max(max, Number(m[0]?.m || 0)) + 1);
}

async function listCategories({ q = '' } = {}) {
  const params = [];
  let where = '1=1';
  if (q) {
    where += ` AND (c.name_ar LIKE ? OR IFNULL(c.code,'') LIKE ?)`;
    params.push(`%${q}%`, `%${q}%`);
  }
  return safeQuery(
    `SELECT c.id, c.code, c.name_ar, c.is_active,
            (SELECT COUNT(*) FROM inv_item i WHERE i.category_id = c.id) AS item_count
     FROM inv_item_category c
     WHERE ${where}
     ORDER BY c.name_ar LIMIT 300`,
    params
  );
}

async function getCategory(id) {
  const rows = await safeQuery(`SELECT * FROM inv_item_category WHERE id = ? LIMIT 1`, [Number(id)]);
  return rows[0] || null;
}

async function saveCategory(payload) {
  const id = Number(payload.id || 0);
  let code = String(payload.code || '').trim();
  const name = String(payload.name_ar || '').trim();
  if (!name) return { ok: false, error: 'اسم الفئة مطلوب.' };

  if (id > 0) {
    if (code) {
      const dup = await safeQuery(`SELECT id FROM inv_item_category WHERE code = ? AND id <> ? LIMIT 1`, [
        code,
        id,
      ]);
      if (dup[0]) return { ok: false, error: 'رمز الفئة مستخدم مسبقاً.' };
      await safeQuery(`UPDATE inv_item_category SET code = ?, name_ar = ? WHERE id = ?`, [code, name, id]);
    } else {
      await safeQuery(`UPDATE inv_item_category SET name_ar = ? WHERE id = ?`, [name, id]);
    }
    return { ok: true, id, message: 'تم تحديث الفئة.' };
  }

  if (!code) code = await nextCategoryCode();
  const dup = await safeQuery(`SELECT id FROM inv_item_category WHERE code = ? LIMIT 1`, [code]);
  if (dup[0]) {
    code = await nextCategoryCode();
  }
  const [result] = await db.getPool().execute(
    `INSERT INTO inv_item_category (code, name_ar, is_active) VALUES (?,?,1)`,
    [code, name]
  );
  return { ok: true, id: Number(result.insertId), message: `تم إضافة الفئة. الرمز: ${code}` };
}

async function toggleCategory(id) {
  const catId = Number(id);
  if (!catId) return { ok: false, error: 'معرّف غير صالح.' };
  await safeQuery(`UPDATE inv_item_category SET is_active = 1 - is_active WHERE id = ?`, [catId]);
  return { ok: true, message: 'تم تحديث حالة الفئة.' };
}

async function deleteCategory(id) {
  const catId = Number(id);
  if (!catId) return { ok: false, error: 'معرّف غير صالح.' };
  const cat = await getCategory(catId);
  if (!cat) return { ok: false, error: 'الفئة غير موجودة.' };

  try {
    const moves = await safeQuery(
      `SELECT COUNT(*) AS c FROM inv_stock_move m
       INNER JOIN inv_item i ON i.id = m.item_id
       WHERE i.category_id = ?`,
      [catId]
    );
    if (Number(moves[0]?.c || 0) > 0) {
      return {
        ok: false,
        error: `لا يمكن حذف الفئة: توجد حركات مخزنية على مواد تابعة لها.`,
      };
    }
  } catch {
    /* table optional */
  }

  try {
    await safeQuery(`UPDATE inv_item SET category_id = NULL WHERE category_id = ?`, [catId]);
  } catch {
    /* */
  }
  await safeQuery(`DELETE FROM inv_item_category WHERE id = ?`, [catId]);
  return { ok: true, message: 'تم حذف الفئة.' };
}

/* ── Movement types ── */
function checkboxOn(v) {
  return v === true || v === 1 || v === '1' || v === 'on';
}

function slugMovementCode(raw) {
  return String(raw || '')
    .trim()
    .toLowerCase()
    .replace(/\s+/g, '_')
    .replace(/[^a-z0-9_]/g, '')
    .slice(0, 40);
}

async function listMovementTypes({ q = '' } = {}) {
  const params = [];
  let where = '1=1';
  if (q) {
    where += ` AND (t.name_ar LIKE ? OR t.code LIKE ? OR IFNULL(t.hint_ar,'') LIKE ?)`;
    params.push(`%${q}%`, `%${q}%`, `%${q}%`);
  }
  return safeQuery(
    `SELECT t.* FROM inv_movement_type t
     WHERE ${where}
     ORDER BY t.sort_order ASC, t.id ASC LIMIT 200`,
    params
  );
}

async function getMovementType(id) {
  const rows = await safeQuery(`SELECT * FROM inv_movement_type WHERE id = ? LIMIT 1`, [Number(id)]);
  return rows[0] || null;
}

async function saveMovementType(payload) {
  const id = Number(payload.id || 0);
  let code = slugMovementCode(payload.code);
  const name = String(payload.name_ar || '').trim();
  const hint = String(payload.hint_ar || '').trim() || null;
  let postAuto = checkboxOn(payload.post_auto) ? 1 : 0;
  let postManual = checkboxOn(payload.post_manual) ? 1 : 0;
  if (postAuto === 1 && postManual === 1) postManual = 0;
  if (postAuto === 0 && postManual === 0) postManual = 1;
  const isActive = checkboxOn(payload.is_active) ? 1 : 0;
  const affectsGl = checkboxOn(payload.affects_gl) ? 1 : 0;
  const sortOrder = Number(payload.sort_order || 0) || 0;

  if (!name) return { ok: false, error: 'اسم نوع الحركة مطلوب.' };
  if (!code) code = 'type_' + Date.now().toString(36);

  const dup = id
    ? await safeQuery(`SELECT id FROM inv_movement_type WHERE code = ? AND id <> ? LIMIT 1`, [code, id])
    : await safeQuery(`SELECT id FROM inv_movement_type WHERE code = ? LIMIT 1`, [code]);
  if (dup[0]) return { ok: false, error: 'رمز النوع مستخدم مسبقاً.' };

  const hasAffectsGl = await tableHasColumn('inv_movement_type', 'affects_gl');

  if (id > 0) {
    if (hasAffectsGl) {
      await safeQuery(
        `UPDATE inv_movement_type
         SET code=?, name_ar=?, hint_ar=?, post_auto=?, post_manual=?, is_active=?, affects_gl=?, sort_order=?
         WHERE id=?`,
        [code, name, hint, postAuto, postManual, isActive, affectsGl, sortOrder, id]
      );
    } else {
      await safeQuery(
        `UPDATE inv_movement_type
         SET code=?, name_ar=?, hint_ar=?, post_auto=?, post_manual=?, is_active=?, sort_order=?
         WHERE id=?`,
        [code, name, hint, postAuto, postManual, isActive, sortOrder, id]
      );
    }
    return { ok: true, id, message: 'تم تحديث نوع الحركة.' };
  }

  if (hasAffectsGl) {
    const [result] = await db.getPool().execute(
      `INSERT INTO inv_movement_type
       (code, name_ar, hint_ar, post_auto, post_manual, is_active, affects_gl, sort_order)
       VALUES (?,?,?,?,?,?,?,?)`,
      [code, name, hint, postAuto, postManual, isActive || 1, affectsGl, sortOrder]
    );
    return { ok: true, id: Number(result.insertId), message: 'تم إضافة نوع الحركة.' };
  }

  const [result] = await db.getPool().execute(
    `INSERT INTO inv_movement_type
     (code, name_ar, hint_ar, post_auto, post_manual, is_active, sort_order)
     VALUES (?,?,?,?,?,?,?)`,
    [code, name, hint, postAuto, postManual, isActive || 1, sortOrder]
  );
  return { ok: true, id: Number(result.insertId), message: 'تم إضافة نوع الحركة.' };
}

module.exports = {
  listWarehouses,
  getWarehouse,
  saveWarehouse,
  deleteWarehouse,
  listItems,
  getItem,
  getItemUnits,
  itemLookups,
  saveItem,
  deleteItem,
  toggleItemActive,
  itemHasMovements,
  itemDisplayCode,
  nextBarcode,
  listUnits,
  getUnit,
  saveUnit,
  toggleUnit,
  listCategories,
  getCategory,
  saveCategory,
  toggleCategory,
  deleteCategory,
  nextCategoryCode,
  listMovementTypes,
  getMovementType,
  saveMovementType,
};
