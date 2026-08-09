'use strict';

const db = require('../db');

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
async function listItems({ q = '', activeOnly = true } = {}) {
  const where = ['1=1'];
  const params = [];
  if (activeOnly) where.push('i.is_active = 1');
  if (q) {
    const like = `%${q}%`;
    where.push(`(i.name_ar LIKE ? OR IFNULL(i.sku,'') LIKE ? OR IFNULL(i.barcode,'') LIKE ?)`);
    params.push(like, like, like);
  }
  return safeQuery(
    `SELECT i.id, i.sku, i.barcode, i.name_ar, i.default_cost, i.default_sale, i.is_active,
            c.name_ar AS category_name, u.name_ar AS unit_name
     FROM inv_item i
     LEFT JOIN inv_item_category c ON c.id = i.category_id
     LEFT JOIN inv_unit u ON u.id = i.unit_id
     WHERE ${where.join(' AND ')}
     ORDER BY i.id DESC LIMIT 300`,
    params
  );
}

async function getItem(id) {
  const rows = await safeQuery(
    `SELECT i.* FROM inv_item i WHERE i.id = ? LIMIT 1`,
    [Number(id)]
  );
  return rows[0] || null;
}

async function itemLookups() {
  let categories = [];
  let units = [];
  let warehouses = [];
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
    units = await safeQuery(`SELECT id, code, name_ar FROM inv_unit ORDER BY name_ar LIMIT 300`);
  }
  try {
    warehouses = await safeQuery(
      `SELECT id, code, name_ar FROM inv_warehouse WHERE is_active = 1 ORDER BY name_ar LIMIT 200`
    );
  } catch {
    warehouses = [];
  }
  return { categories, units, warehouses };
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

async function saveItem(payload) {
  const id = Number(payload.id || 0);
  let sku = String(payload.sku || '').trim();
  let barcode = String(payload.barcode || '').trim();
  const name = String(payload.name_ar || '').trim();
  const categoryId = Number(payload.category_id || 0) || null;
  const unitId = Number(payload.unit_id || 0) || null;
  const warehouseId = Number(payload.default_warehouse_id || 0) || null;
  const cost = Number(String(payload.default_cost || '0').replace(',', '.')) || 0;
  const sale = Number(String(payload.default_sale || '0').replace(',', '.')) || 0;

  if (!name) return { ok: false, error: 'اسم المادة مطلوب.' };
  if (cost < 0 || sale < 0) return { ok: false, error: 'الأسعار يجب أن تكون ≥ 0.' };

  const hasBarcode = await tableHasColumn('inv_item', 'barcode');
  const hasCategory = await tableHasColumn('inv_item', 'category_id');
  const hasUnitId = await tableHasColumn('inv_item', 'unit_id');
  const hasWh = await tableHasColumn('inv_item', 'default_warehouse_id');

  if (hasUnitId && !unitId) return { ok: false, error: 'اختر الوحدة الأساسية.' };

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
    if (dupSku[0]) return { ok: false, error: 'رمز SKU مستخدم مسبقاً.' };
  }

  if (id > 0) {
    // on edit keep sale price from DB if not provided? use provided
    if (hasBarcode && hasCategory) {
      await safeQuery(
        `UPDATE inv_item SET sku=?, barcode=?, name_ar=?, category_id=?, unit_id=?,
         default_warehouse_id=?, unit_name=?, default_cost=?, default_sale=?, track_inventory=1
         WHERE id=?`,
        [sku, barcode || null, name, categoryId, unitId, warehouseId, unitName, cost, sale, id]
      );
    } else if (hasBarcode) {
      await safeQuery(
        `UPDATE inv_item SET sku=?, barcode=?, name_ar=?, unit_name=?, default_cost=?, default_sale=?, track_inventory=1
         WHERE id=?`,
        [sku, barcode || null, name, unitName, cost, sale, id]
      );
    } else {
      await safeQuery(
        `UPDATE inv_item SET sku=?, name_ar=?, unit_name=?, default_cost=?, default_sale=?, track_inventory=1
         WHERE id=?`,
        [sku, name, unitName, cost, sale, id]
      );
    }
    return { ok: true, id, message: 'تم تحديث المادة.' };
  }

  let newId;
  if (hasBarcode && hasCategory && hasUnitId && hasWh) {
    const [result] = await db.getPool().execute(
      `INSERT INTO inv_item
       (sku, barcode, name_ar, category_id, unit_id, default_warehouse_id, unit_name,
        default_cost, default_sale, track_inventory, is_active)
       VALUES (?,?,?,?,?,?,?,?,?,1,1)`,
      [sku, barcode || null, name, categoryId, unitId, warehouseId, unitName, cost, sale]
    );
    newId = Number(result.insertId);
  } else if (hasBarcode) {
    const [result] = await db.getPool().execute(
      `INSERT INTO inv_item (sku, barcode, name_ar, unit_name, default_cost, default_sale, track_inventory, is_active)
       VALUES (?,?,?,?,?,?,1,1)`,
      [sku, barcode || null, name, unitName, cost, sale]
    );
    newId = Number(result.insertId);
  } else {
    const [result] = await db.getPool().execute(
      `INSERT INTO inv_item (sku, name_ar, unit_name, default_cost, default_sale, track_inventory, is_active)
       VALUES (?,?,?,?,?,1,1)`,
      [sku, name, unitName, cost, sale]
    );
    newId = Number(result.insertId);
  }

  if (autoSku && newId) {
    const finalSku = await allocateSku(newId);
    await safeQuery(`UPDATE inv_item SET sku = ? WHERE id = ?`, [finalSku, newId]);
  }

  // optional issue unit (one packing unit)
  const issueUnitId = Number(payload.issue_unit_id || 0);
  const issueFactor = Number(String(payload.issue_factor || '0').replace(',', '.')) || 0;
  if (newId && unitId && issueUnitId > 0 && issueFactor > 0) {
    try {
      await safeQuery(
        `INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
         VALUES (?,?,1,1,0)
         ON DUPLICATE KEY UPDATE factor_to_base=1, is_base=1`,
        [newId, unitId]
      );
      await safeQuery(
        `INSERT INTO inv_item_unit (item_id, unit_id, factor_to_base, is_base, is_default_issue)
         VALUES (?,?,?,0,1)
         ON DUPLICATE KEY UPDATE factor_to_base=VALUES(factor_to_base), is_default_issue=1`,
        [newId, issueUnitId, issueFactor]
      );
    } catch (e) {
      console.error('issue unit', e.message);
    }
  }

  return { ok: true, id: newId, message: 'تم إضافة المادة.' };
}

async function deleteItem(id) {
  const itemId = Number(id);
  if (!itemId) return { ok: false, error: 'معرّف غير صالح.' };
  try {
    const m = await safeQuery(
      `SELECT COUNT(*) AS c FROM inv_stock_move WHERE item_id = ? LIMIT 1`,
      [itemId]
    );
    if (Number(m[0]?.c || 0) > 0) {
      return { ok: false, error: 'لا يمكن حذف مادة لها حركات مخزون. أوقفها بدلاً من ذلك.' };
    }
  } catch {
    /* */
  }
  await safeQuery(`DELETE FROM inv_item WHERE id = ?`, [itemId]);
  return { ok: true, message: 'تم حذف المادة.' };
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
  itemLookups,
  saveItem,
  deleteItem,
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
