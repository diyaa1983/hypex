'use strict';

const db = require('../db');
const { parseDateToIso, todayIso } = require('../lib/html');

async function nextDeliveryNo(deliveryDate) {
  const iso = parseDateToIso(deliveryDate);
  const year = iso.slice(0, 4);
  const suffix = `-${year}`;
  const rows = await db.query(`SELECT delivery_no FROM sal_delivery WHERE delivery_no LIKE ?`, [`%${suffix}`]);
  let maxSeq = 0;
  const re = new RegExp(`^(\\d+)${suffix.replace('-', '\\-')}$`);
  for (const row of rows) {
    const m = String(row.delivery_no || '').match(re);
    if (m) maxSeq = Math.max(maxSeq, Number(m[1]));
  }
  return String(maxSeq + 1).padStart(3, '0') + suffix;
}

async function getDelivery(id) {
  const deliveryId = Number(id);
  if (!deliveryId) return null;
  const headers = await db.query(
    `SELECT d.*, c.name_ar AS customer_name, c.code AS customer_code
     FROM sal_delivery d
     INNER JOIN crm_customer c ON c.id = d.customer_id
     WHERE d.id = ? LIMIT 1`,
    [deliveryId]
  );
  if (!headers[0]) return null;
  const h = headers[0];
  const lines = await db.query(
    `SELECT l.*, it.sku AS item_code, it.name_ar AS item_name
     FROM sal_delivery_line l
     LEFT JOIN inv_item it ON it.id = l.item_id
     WHERE l.delivery_id = ?
     ORDER BY l.sort_order, l.id`,
    [deliveryId]
  );
  const posted = Number(h.is_posted) === 1;
  return {
    id: Number(h.id),
    delivery_no: h.delivery_no,
    delivery_date: h.delivery_date,
    customer_id: Number(h.customer_id),
    customer_name: h.customer_name,
    customer_code: h.customer_code,
    warehouse_id: h.warehouse_id != null ? Number(h.warehouse_id) : null,
    notes: h.notes || '',
    status: h.status,
    is_posted: posted,
    is_locked: posted || h.status === 'cancelled',
    status_label: posted ? 'مرحّل' : h.status === 'cancelled' ? 'ملغى' : 'مسودة',
    lines: lines.map((ln) => ({
      item_id: Number(ln.item_id),
      item_code: ln.item_code || '',
      name_ar: ln.line_desc || ln.item_name || '',
      qty: Number(ln.qty || 0),
    })),
  };
}

async function saveDelivery(payload, userId) {
  const customerId = Number(payload.customer_id || 0);
  const warehouseId = Number(payload.warehouse_id || 0);
  if (customerId < 1) return { ok: false, error: 'اختر العميل.' };

  let whCount = 0;
  try {
    const r = await db.query(`SELECT COUNT(*) AS c FROM inv_warehouse WHERE is_active = 1`);
    whCount = Number(r[0]?.c || 0);
  } catch {
    whCount = 0;
  }
  if (whCount > 0 && warehouseId < 1) return { ok: false, error: 'اختر المستودع.' };

  const deliveryDate = parseDateToIso(payload.delivery_date || todayIso());
  const notes = String(payload.notes || '').trim() || null;
  const deliveryId = Number(payload.id || 0);
  const rawLines = Array.isArray(payload.lines) ? payload.lines : [];
  const lines = [];
  for (const ln of rawLines) {
    const itemId = Number(ln?.item_id || 0);
    const qty = Number(ln?.qty || 0);
    if (itemId < 1 || qty <= 0) continue;
    let name = String(ln.name_ar || ln.line_desc || '').trim();
    if (!name) {
      const rows = await db.query(`SELECT name_ar FROM inv_item WHERE id = ? LIMIT 1`, [itemId]);
      name = String(rows[0]?.name_ar || '');
    }
    if (!name) return { ok: false, error: 'صنف غير صالح في أحد البنود.' };
    lines.push({ item_id: itemId, name_ar: name, qty });
  }
  if (!lines.length) return { ok: false, error: 'أضف مادة واحدة على الأقل.' };

  const pool = db.getPool();
  const conn = await pool.getConnection();
  try {
    await conn.beginTransaction();
    const whFinal = whCount > 0 ? warehouseId : null;

    if (deliveryId > 0) {
      const [rows] = await conn.execute(
        `SELECT is_posted, status FROM sal_delivery WHERE id = ? FOR UPDATE`,
        [deliveryId]
      );
      const old = rows[0];
      if (!old) {
        await conn.rollback();
        return { ok: false, error: 'السند غير موجود.' };
      }
      if (Number(old.is_posted) === 1) {
        await conn.rollback();
        return { ok: false, error: 'لا يمكن تعديل سند مرحّل.' };
      }
      if (String(old.status) === 'cancelled') {
        await conn.rollback();
        return { ok: false, error: 'لا يمكن تعديل سند ملغى.' };
      }

      try {
        await conn.execute(
          `UPDATE sal_delivery SET delivery_date=?, customer_id=?, warehouse_id=?, notes=? WHERE id=?`,
          [deliveryDate, customerId, whFinal, notes, deliveryId]
        );
      } catch {
        await conn.execute(
          `UPDATE sal_delivery SET delivery_date=?, customer_id=?, notes=? WHERE id=?`,
          [deliveryDate, customerId, notes, deliveryId]
        );
      }
      await conn.execute(`DELETE FROM sal_delivery_line WHERE delivery_id=?`, [deliveryId]);
      await insertLines(conn, deliveryId, lines);
      await conn.commit();
      return {
        ok: true,
        id: deliveryId,
        delivery_no: (await getDelivery(deliveryId))?.delivery_no || '',
      };
    }

    const deliveryNo = await nextDeliveryNo(deliveryDate);
    let newId;
    try {
      const [result] = await conn.execute(
        `INSERT INTO sal_delivery
         (delivery_no, delivery_date, customer_id, warehouse_id, status, notes, is_posted, created_by)
         VALUES (?,?,?,?, 'confirmed', ?, 0, ?)`,
        [deliveryNo, deliveryDate, customerId, whFinal, notes, userId || null]
      );
      newId = Number(result.insertId);
    } catch {
      const [result] = await conn.execute(
        `INSERT INTO sal_delivery
         (delivery_no, delivery_date, customer_id, status, notes, is_posted, created_by)
         VALUES (?,?,?, 'confirmed', ?, 0, ?)`,
        [deliveryNo, deliveryDate, customerId, notes, userId || null]
      );
      newId = Number(result.insertId);
    }
    await insertLines(conn, newId, lines);
    await conn.commit();
    return { ok: true, id: newId, delivery_no: deliveryNo };
  } catch (e) {
    try {
      await conn.rollback();
    } catch {
      /* */
    }
    console.error('saveDelivery', e);
    return { ok: false, error: e.message || 'تعذر الحفظ.' };
  } finally {
    conn.release();
  }
}

async function insertLines(conn, deliveryId, lines) {
  let sort = 0;
  for (const ln of lines) {
    sort += 1;
    await conn.execute(
      `INSERT INTO sal_delivery_line (delivery_id, item_id, line_desc, qty, sort_order)
       VALUES (?,?,?,?,?)`,
      [deliveryId, ln.item_id, ln.name_ar || null, ln.qty, sort]
    );
  }
}

async function lookups() {
  return require('./invoicesService').lookups();
}

module.exports = {
  getDelivery,
  saveDelivery,
  nextDeliveryNo,
  lookups,
};
