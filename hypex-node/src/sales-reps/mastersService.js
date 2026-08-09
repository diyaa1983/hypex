'use strict';

const db = require('../db');

async function safeQuery(sql, params = []) {
  try {
    return await db.query(sql, params);
  } catch (e) {
    console.error('sales-reps masters', e.message);
    throw e;
  }
}

function nullIfEmpty(v) {
  const s = String(v || '').trim();
  return s === '' ? null : s;
}

async function getRep(id) {
  const rows = await safeQuery(`SELECT * FROM crm_sales_rep WHERE id = ? LIMIT 1`, [Number(id)]);
  return rows[0] || null;
}

async function nextRepCode() {
  const m = await safeQuery(`SELECT IFNULL(MAX(id), 0) AS m FROM crm_sales_rep`);
  return 'REP-' + String(Number(m[0]?.m || 0) + 1).padStart(4, '0');
}

async function listWarehouses() {
  try {
    return await safeQuery(
      `SELECT id, code, name_ar FROM inv_warehouse WHERE is_active = 1 ORDER BY name_ar LIMIT 300`
    );
  } catch {
    return [];
  }
}

async function saveRep(payload) {
  const id = Number(payload.id || 0);
  let code = String(payload.code || '').trim();
  const name = String(payload.name_ar || '').trim();
  const phone = nullIfEmpty(payload.phone);
  const address = nullIfEmpty(payload.address_ar);
  let warehouseId = Number(payload.warehouse_id || 0) || null;
  if (warehouseId !== null && warehouseId < 1) warehouseId = null;

  if (!name) return { ok: false, error: 'اسم المندوب مطلوب.' };
  if (!code) code = await nextRepCode();

  const dup = id
    ? await safeQuery(`SELECT id FROM crm_sales_rep WHERE code = ? AND id <> ? LIMIT 1`, [code, id])
    : await safeQuery(`SELECT id FROM crm_sales_rep WHERE code = ? LIMIT 1`, [code]);
  if (dup[0]) return { ok: false, error: 'رمز المندوب مستخدم مسبقاً.' };

  if (id > 0) {
    try {
      await safeQuery(
        `UPDATE crm_sales_rep SET code=?, name_ar=?, phone=?, address_ar=?, warehouse_id=? WHERE id=?`,
        [code, name, phone, address, warehouseId, id]
      );
    } catch {
      await safeQuery(`UPDATE crm_sales_rep SET code=?, name_ar=?, phone=?, address_ar=? WHERE id=?`, [
        code,
        name,
        phone,
        address,
        id,
      ]);
    }
    return { ok: true, id, message: 'تم تحديث بيانات المندوب.' };
  }

  try {
    const [result] = await db.getPool().execute(
      `INSERT INTO crm_sales_rep (code, name_ar, phone, address_ar, warehouse_id, is_active)
       VALUES (?,?,?,?,?,1)`,
      [code, name, phone, address, warehouseId]
    );
    return { ok: true, id: Number(result.insertId), message: 'تم إضافة المندوب.' };
  } catch {
    const [result] = await db.getPool().execute(
      `INSERT INTO crm_sales_rep (code, name_ar, phone, address_ar, is_active) VALUES (?,?,?,?,1)`,
      [code, name, phone, address]
    );
    return { ok: true, id: Number(result.insertId), message: 'تم إضافة المندوب.' };
  }
}

async function toggleRep(id) {
  const repId = Number(id);
  if (!repId) return { ok: false, error: 'معرّف غير صالح.' };
  await safeQuery(`UPDATE crm_sales_rep SET is_active = 1 - is_active WHERE id = ?`, [repId]);
  return { ok: true, message: 'تم تحديث حالة المندوب.' };
}

/* ── Rep routes (خط السير) ── */
async function listRoutes({ salesRepId = 0, limit = 80 } = {}) {
  const params = [];
  let where = '1=1';
  if (salesRepId > 0) {
    where += ' AND r.sales_rep_id = ?';
    params.push(Number(salesRepId));
  }
  try {
    return await safeQuery(
      `SELECT r.id, r.sales_rep_id, r.route_date, r.notes, r.is_active,
              COALESCE(sr.name_ar, '') AS sales_rep_name,
              COALESCE(lc.cnt, 0) AS customer_count
       FROM sal_rep_route r
       INNER JOIN crm_sales_rep sr ON sr.id = r.sales_rep_id
       LEFT JOIN (
         SELECT route_id, COUNT(*) AS cnt FROM sal_rep_route_line GROUP BY route_id
       ) lc ON lc.route_id = r.id
       WHERE ${where}
       ORDER BY r.route_date DESC, r.id DESC
       LIMIT ${Math.min(200, limit)}`,
      params
    );
  } catch (e) {
    console.error('listRoutes', e.message);
    return [];
  }
}

async function getRoute(id) {
  const routeId = Number(id);
  if (!routeId) return null;
  try {
    const rows = await safeQuery(
      `SELECT r.*, COALESCE(sr.name_ar, '') AS sales_rep_name
       FROM sal_rep_route r
       INNER JOIN crm_sales_rep sr ON sr.id = r.sales_rep_id
       WHERE r.id = ? LIMIT 1`,
      [routeId]
    );
    if (!rows[0]) return null;
    const lines = await safeQuery(
      `SELECT l.id, l.customer_id, l.sort_order, c.code AS customer_code, c.name_ar AS customer_name
       FROM sal_rep_route_line l
       INNER JOIN crm_customer c ON c.id = l.customer_id
       WHERE l.route_id = ?
       ORDER BY l.sort_order, l.id`,
      [routeId]
    );
    return { ...rows[0], lines };
  } catch {
    return null;
  }
}

async function listActiveCustomers() {
  try {
    return await safeQuery(
      `SELECT id, code, name_ar, sales_rep_id FROM crm_customer WHERE is_active = 1 ORDER BY name_ar LIMIT 800`
    );
  } catch {
    return await safeQuery(
      `SELECT id, code, name_ar FROM crm_customer WHERE is_active = 1 ORDER BY name_ar LIMIT 800`
    );
  }
}

async function saveRoute(payload, userId) {
  const id = Number(payload.id || 0);
  const salesRepId = Number(payload.sales_rep_id || 0);
  const routeDate = String(payload.route_date || '').trim().slice(0, 10);
  const notes = nullIfEmpty(payload.notes);
  let custIds = [];
  if (Array.isArray(payload.customer_ids)) {
    custIds = payload.customer_ids.map(Number).filter((n) => n > 0);
  } else if (payload.customer_ids != null) {
    custIds = [Number(payload.customer_ids)].filter((n) => n > 0);
  }
  // unique preserve order
  const seen = new Set();
  custIds = custIds.filter((cid) => {
    if (seen.has(cid)) return false;
    seen.add(cid);
    return true;
  });

  if (salesRepId < 1) return { ok: false, error: 'اختر المندوب.' };
  if (!/^\d{4}-\d{2}-\d{2}$/.test(routeDate)) return { ok: false, error: 'تاريخ خط السير غير صالح.' };

  const rep = await safeQuery(
    `SELECT id FROM crm_sales_rep WHERE id = ? AND is_active = 1 LIMIT 1`,
    [salesRepId]
  );
  if (!rep[0]) return { ok: false, error: 'المندوب غير موجود أو غير نشط.' };

  const conn = await db.getPool().getConnection();
  try {
    await conn.beginTransaction();
    let routeId = id;
    if (routeId > 0) {
      await conn.execute(
        `UPDATE sal_rep_route SET sales_rep_id=?, route_date=?, notes=?, updated_by=?, is_active=1 WHERE id=?`,
        [salesRepId, routeDate, notes, userId || null, routeId]
      );
      await conn.execute(`DELETE FROM sal_rep_route_line WHERE route_id = ?`, [routeId]);
    } else {
      // upsert by day uniqueness
      const [exist] = await conn.execute(
        `SELECT id FROM sal_rep_route WHERE sales_rep_id = ? AND route_date = ? LIMIT 1`,
        [salesRepId, routeDate]
      );
      if (exist[0]) {
        routeId = Number(exist[0].id);
        await conn.execute(
          `UPDATE sal_rep_route SET notes=?, updated_by=?, is_active=1 WHERE id=?`,
          [notes, userId || null, routeId]
        );
        await conn.execute(`DELETE FROM sal_rep_route_line WHERE route_id = ?`, [routeId]);
      } else {
        const [ins] = await conn.execute(
          `INSERT INTO sal_rep_route (sales_rep_id, route_date, notes, is_active, created_by)
           VALUES (?,?,?,1,?)`,
          [salesRepId, routeDate, notes, userId || null]
        );
        routeId = Number(ins.insertId);
      }
    }
    let sort = 0;
    for (const cid of custIds) {
      await conn.execute(
        `INSERT INTO sal_rep_route_line (route_id, customer_id, sort_order) VALUES (?,?,?)`,
        [routeId, cid, sort++]
      );
    }
    await conn.commit();
    return {
      ok: true,
      id: routeId,
      message: `تم حفظ خط السير ليوم ${routeDate} (${custIds.length} عميل).`,
    };
  } catch (e) {
    await conn.rollback();
    console.error('saveRoute', e.message);
    return { ok: false, error: 'تعذر حفظ خط السير. تأكد من وجود جداول sal_rep_route.' };
  } finally {
    conn.release();
  }
}

async function deleteRoute(id) {
  const routeId = Number(id);
  if (!routeId) return { ok: false, error: 'معرّف غير صالح.' };
  try {
    await safeQuery(`DELETE FROM sal_rep_route WHERE id = ?`, [routeId]);
    return { ok: true, message: 'تم حذف خط السير.' };
  } catch (e) {
    return { ok: false, error: e.message || 'تعذر الحذف.' };
  }
}

module.exports = {
  getRep,
  saveRep,
  toggleRep,
  nextRepCode,
  listWarehouses,
  listRoutes,
  getRoute,
  listActiveCustomers,
  saveRoute,
  deleteRoute,
};
