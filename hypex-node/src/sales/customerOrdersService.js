'use strict';

const db = require('../db');
const { parseDateToIso, todayIso } = require('../lib/html');
const itemPricing = require('../lib/itemPricing');

function r3(n) {
  return Math.round((Number(n) || 0) * 1000) / 1000;
}

function r6(n) {
  return Math.round((Number(n) || 0) * 1e6) / 1e6;
}

async function nextOrderNo(orderDate) {
  const iso = parseDateToIso(orderDate);
  const year = iso.slice(0, 4);
  const suffix = `-${year}`;
  const rows = await db.query(
    `SELECT order_no FROM sal_customer_order WHERE order_no LIKE ?`,
    [`%${suffix}`]
  );
  let maxSeq = 0;
  const re = new RegExp(`^CO(\\d+)${suffix.replace('-', '\\-')}$`);
  for (const row of rows) {
    const m = String(row.order_no || '').match(re);
    if (m) maxSeq = Math.max(maxSeq, Number(m[1]));
  }
  return `CO${String(maxSeq + 1).padStart(3, '0')}${suffix}`;
}

async function getOrder(id) {
  const orderId = Number(id);
  if (!orderId) return null;
  const headers = await db.query(
    `SELECT o.*, c.name_ar AS customer_name, c.code AS customer_code,
            w.name_ar AS warehouse_name,
            COALESCE(r.name_ar, '') AS sales_rep_name
     FROM sal_customer_order o
     INNER JOIN crm_customer c ON c.id = o.customer_id
     INNER JOIN inv_warehouse w ON w.id = o.warehouse_id
     LEFT JOIN crm_sales_rep r ON r.id = o.sales_rep_id
     WHERE o.id = ?
     LIMIT 1`,
    [orderId]
  );
  if (!headers[0]) return null;
  const h = headers[0];
  let lines = [];
  try {
    lines = await db.query(
      `SELECT l.*,
              COALESCE(NULLIF(TRIM(i.barcode), ''), i.sku) AS item_code,
              i.sku AS item_sku,
              COALESCE(NULLIF(TRIM(l.item_name), ''), i.name_ar, '') AS item_name_resolved,
              COALESCE(NULLIF(TRIM(l.unit_name), ''), NULLIF(TRIM(i.unit_name), ''), 'قطعة') AS unit_name_resolved,
              COALESCE(i.default_sale, 0) AS item_default_sale
       FROM sal_customer_order_line l
       LEFT JOIN inv_item i ON i.id = l.item_id
       WHERE l.order_id = ?
       ORDER BY l.line_no, l.id`,
      [orderId]
    );
  } catch {
    lines = await db.query(
      `SELECT l.*, i.sku AS item_code, i.sku AS item_sku,
              COALESCE(NULLIF(TRIM(l.item_name), ''), i.name_ar, '') AS item_name_resolved,
              COALESCE(NULLIF(TRIM(l.unit_name), ''), 'قطعة') AS unit_name_resolved,
              COALESCE(i.default_sale, 0) AS item_default_sale
       FROM sal_customer_order_line l
       LEFT JOIN inv_item i ON i.id = l.item_id
       WHERE l.order_id = ?
       ORDER BY l.line_no, l.id`,
      [orderId]
    );
  }

  const status = String(h.status || 'draft');
  const mappedLines = [];
  for (const ln of lines) {
    const itemId = Number(ln.item_id);
    let units = [];
    try {
      const pricing = await itemPricing.getItemPricing(itemId);
      if (pricing) units = pricing.units || [];
    } catch {
      units = [];
    }
    mappedLines.push({
      item_id: itemId,
      item_code: ln.item_code || ln.item_sku || '',
      name_ar: ln.item_name_resolved || ln.item_name || '',
      qty: Number(ln.qty || 0),
      qty_extra: Number(ln.qty_extra || 0),
      unit_price: Number(ln.unit_price || 0),
      base_sale: Number(ln.item_default_sale || 0),
      discount_pct: Number(ln.discount_pct || 0),
      discount_amount: Number(ln.discount_amount || 0),
      tax_rate_percent: Number(ln.tax_rate_percent || 0),
      tax_amount: Number(ln.tax_amount || 0),
      line_total: Number(ln.line_total || 0),
      line_gross: Number(ln.line_gross || 0),
      unit_id: ln.unit_id != null ? Number(ln.unit_id) : null,
      unit_name: ln.unit_name_resolved || ln.unit_name || 'قطعة',
      unit_factor: Number(ln.unit_factor || 1),
      qty_base: Number(ln.qty_base || ln.qty || 0),
      units,
    });
  }

  return {
    id: Number(h.id),
    order_no: h.order_no,
    order_date: h.order_date,
    customer_id: Number(h.customer_id),
    customer_name: h.customer_name,
    customer_code: h.customer_code,
    sales_rep_id: h.sales_rep_id != null ? Number(h.sales_rep_id) : null,
    sales_rep_name: h.sales_rep_name || '',
    warehouse_id: Number(h.warehouse_id),
    warehouse_name: h.warehouse_name || '',
    notes: h.notes || '',
    status,
    is_approved: status === 'approved',
    status_label: status === 'approved' ? 'معتمد' : 'مسودة',
    subtotal: Number(h.subtotal || 0),
    discount_amount: Number(h.discount_amount || 0),
    tax_amount: Number(h.tax_amount || 0),
    total: Number(h.total || 0),
    invoice_discount_input: h.invoice_discount_input || '',
    lines: mappedLines,
  };
}

function computeLine(raw, defaultTax) {
  const qty = Math.max(0, Math.round(Number(raw.qty) || 0));
  const qtyExtra = Math.max(0, Math.round(Number(raw.qty_extra) || 0));
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
    name_ar: String(raw.name_ar || raw.item_name || '').trim(),
    qty,
    qty_extra: qtyExtra,
    unit_price: unitPrice,
    discount_pct: discountPct,
    discount_amount: discAmt,
    tax_rate_percent: taxRate,
    tax_amount: taxAmt,
    line_total: lineSub,
    line_gross: lineGross,
    unit_id: raw.unit_id ? Number(raw.unit_id) : null,
    unit_name: String(raw.unit_name || '').trim() || null,
    unit_factor: unitFactor,
    qty_base: r6((qty + qtyExtra) * unitFactor),
    notes: String(raw.notes || '').trim() || null,
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
      discount_amount: r3(lines.reduce((a, l) => a + (l.discount_amount || 0), 0)),
      tax_amount: sumTaxBefore,
      total: sumGrossBefore,
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
      discount_amount: r3(lines.reduce((a, l) => a + (l.discount_amount || 0), 0)),
      tax_amount: sumTaxBefore,
      total: sumGrossBefore,
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
      tax_amount: tax,
      line_gross: r3(newSub + tax),
      discount_amount: r3((l.discount_amount || 0) + share),
    };
  });

  return {
    lines: out,
    subtotal: r3(out.reduce((a, l) => a + l.line_total, 0)),
    discount_amount: r3(out.reduce((a, l) => a + (l.discount_amount || 0), 0)),
    tax_amount: r3(out.reduce((a, l) => a + l.tax_amount, 0)),
    total: r3(out.reduce((a, l) => a + l.line_gross, 0)),
  };
}

async function saveOrder(payload, userId) {
  const customerId = Number(payload.customer_id || 0);
  const warehouseId = Number(payload.warehouse_id || 0);
  if (customerId < 1 || warehouseId < 1) {
    return { ok: false, error: 'العميل والمستودع مطلوبان.' };
  }

  const orderDate = parseDateToIso(payload.order_date || todayIso());
  const salesRepId = payload.sales_rep_id ? Number(payload.sales_rep_id) : null;
  const notes = String(payload.notes || '').trim() || null;
  const discountInput = String(payload.invoice_discount || payload.invoice_discount_input || '').trim();
  const orderId = Number(payload.id || 0);

  let defaultTax = 16;
  try {
    const s = await db.query(`SELECT tax_rate_percent FROM sys_company_settings ORDER BY id LIMIT 1`);
    if (s[0] && s[0].tax_rate_percent != null) defaultTax = Number(s[0].tax_rate_percent);
  } catch {
    /* keep */
  }

  const rawLines = Array.isArray(payload.lines) ? payload.lines : [];
  const normalized = [];
  for (const ln of rawLines) {
    if (!ln || !Number(ln.item_id)) continue;
    if (Number(ln.qty) < 1) continue;
    const priced = await itemPricing.resolveDocLinePricing(ln);
    if (!(priced.unit_price > 0)) {
      return {
        ok: false,
        error: 'لا يمكن حفظ الطلب: سعر المادة في البطاقة صفر. عدّل السعر من شاشة الأسعار.',
      };
    }
    const taxFromItem =
      priced.tax_rate_percent != null && priced.tax_rate_percent !== ''
        ? priced.tax_rate_percent
        : ln.tax_rate_percent;
    const computed = computeLine(
      {
        ...ln,
        unit_price: priced.unit_price,
        unit_factor: priced.unit_factor,
        unit_id: priced.unit_id,
        unit_name: priced.unit_name,
        tax_rate_percent: taxFromItem,
      },
      defaultTax
    );
    if (!computed.name_ar) {
      const rows = await db.query(`SELECT name_ar FROM inv_item WHERE id = ? LIMIT 1`, [computed.item_id]);
      computed.name_ar = String(rows[0]?.name_ar || '');
    }
    if (!computed.name_ar) {
      return { ok: false, error: 'صنف غير صالح في أحد البنود.' };
    }
    normalized.push(computed);
  }
  if (!normalized.length) {
    return { ok: false, error: 'أدخل بنداً واحداً بكمية موجبة على الأقل.' };
  }

  const totals = applyHeaderDiscount(normalized, discountInput);
  const pool = db.getPool();
  const conn = await pool.getConnection();
  try {
    await conn.beginTransaction();

    if (orderId > 0) {
      const [rows] = await conn.execute(
        `SELECT status, sales_rep_id FROM sal_customer_order WHERE id = ? FOR UPDATE`,
        [orderId]
      );
      const old = rows[0];
      if (!old) {
        await conn.rollback();
        return { ok: false, error: 'الطلب غير موجود.' };
      }
      if (String(old.status) !== 'draft') {
        await conn.rollback();
        return { ok: false, error: 'لا يمكن تعديل طلب معتمد. فك الاعتماد أولاً.' };
      }
      const repToStore = salesRepId > 0 ? salesRepId : old.sales_rep_id || null;
      try {
        await conn.execute(
          `UPDATE sal_customer_order
           SET order_date=?, customer_id=?, sales_rep_id=?, warehouse_id=?, notes=?,
               subtotal=?, discount_amount=?, tax_amount=?, total=?, invoice_discount_input=?, updated_by=?
           WHERE id=?`,
          [
            orderDate,
            customerId,
            repToStore,
            warehouseId,
            notes,
            totals.subtotal,
            totals.discount_amount,
            totals.tax_amount,
            totals.total,
            discountInput || null,
            userId || null,
            orderId,
          ]
        );
      } catch {
        await conn.execute(
          `UPDATE sal_customer_order
           SET order_date=?, customer_id=?, sales_rep_id=?, warehouse_id=?, notes=?, updated_by=?
           WHERE id=?`,
          [orderDate, customerId, repToStore, warehouseId, notes, userId || null, orderId]
        );
      }
      await conn.execute(`DELETE FROM sal_customer_order_line WHERE order_id = ?`, [orderId]);
      await insertLines(conn, orderId, totals.lines);
      await conn.commit();
      const ord = await getOrder(orderId);
      return { ok: true, id: orderId, order_no: ord?.order_no || payload.order_no || '', order: ord };
    }

    const orderNo = await nextOrderNo(orderDate);
    let newId;
    try {
      const [result] = await conn.execute(
        `INSERT INTO sal_customer_order
         (order_no, order_date, customer_id, sales_rep_id, warehouse_id, notes,
          subtotal, discount_amount, tax_amount, total, invoice_discount_input, created_by, updated_by)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)`,
        [
          orderNo,
          orderDate,
          customerId,
          salesRepId > 0 ? salesRepId : null,
          warehouseId,
          notes,
          totals.subtotal,
          totals.discount_amount,
          totals.tax_amount,
          totals.total,
          discountInput || null,
          userId || null,
          userId || null,
        ]
      );
      newId = Number(result.insertId);
    } catch {
      const [result] = await conn.execute(
        `INSERT INTO sal_customer_order
         (order_no, order_date, customer_id, sales_rep_id, warehouse_id, notes, created_by, updated_by)
         VALUES (?,?,?,?,?,?,?,?)`,
        [
          orderNo,
          orderDate,
          customerId,
          salesRepId > 0 ? salesRepId : null,
          warehouseId,
          notes,
          userId || null,
          userId || null,
        ]
      );
      newId = Number(result.insertId);
    }
    await insertLines(conn, newId, totals.lines);
    await conn.commit();
    const ord = await getOrder(newId);
    return { ok: true, id: newId, order_no: orderNo, order: ord };
  } catch (e) {
    try {
      await conn.rollback();
    } catch {
      /* ignore */
    }
    console.error('saveOrder', e);
    return { ok: false, error: e.message || 'تعذر الحفظ.' };
  } finally {
    conn.release();
  }
}

async function insertLines(conn, orderId, lines) {
  let n = 0;
  for (const ln of lines) {
    n += 1;
    try {
      await conn.execute(
        `INSERT INTO sal_customer_order_line
         (order_id, line_no, item_id, item_name, unit_id, unit_name, unit_factor, qty, qty_extra, qty_base,
          unit_price, discount_pct, discount_amount, line_total, tax_rate_percent, tax_amount, line_gross, notes)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)`,
        [
          orderId,
          n,
          ln.item_id,
          ln.name_ar,
          ln.unit_id,
          ln.unit_name,
          ln.unit_factor,
          ln.qty,
          ln.qty_extra,
          ln.qty_base,
          ln.unit_price,
          ln.discount_pct,
          ln.discount_amount,
          ln.line_total,
          ln.tax_rate_percent,
          ln.tax_amount,
          ln.line_gross,
          ln.notes,
        ]
      );
    } catch {
      try {
        await conn.execute(
          `INSERT INTO sal_customer_order_line
           (order_id, line_no, item_id, item_name, unit_id, unit_name, qty, qty_extra,
            unit_price, discount_pct, discount_amount, line_total, tax_rate_percent, tax_amount, line_gross, notes)
           VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)`,
          [
            orderId,
            n,
            ln.item_id,
            ln.name_ar,
            ln.unit_id,
            ln.unit_name,
            ln.qty,
            ln.qty_extra,
            ln.unit_price,
            ln.discount_pct,
            ln.discount_amount,
            ln.line_total,
            ln.tax_rate_percent,
            ln.tax_amount,
            ln.line_gross,
            ln.notes,
          ]
        );
      } catch {
        await conn.execute(
          `INSERT INTO sal_customer_order_line
           (order_id, line_no, item_id, item_name, unit_id, unit_name, qty, notes)
           VALUES (?,?,?,?,?,?,?,?)`,
          [orderId, n, ln.item_id, ln.name_ar, ln.unit_id, ln.unit_name, ln.qty, ln.notes]
        );
      }
    }
  }
}

async function setApproved(id, approved, userId) {
  const orderId = Number(id);
  if (!orderId) return { ok: false, error: 'معرّف غير صالح.' };
  const pool = db.getPool();
  const conn = await pool.getConnection();
  try {
    await conn.beginTransaction();
    const [rows] = await conn.execute(
      `SELECT status FROM sal_customer_order WHERE id = ? FOR UPDATE`,
      [orderId]
    );
    const status = rows[0]?.status;
    if (status == null) {
      await conn.rollback();
      return { ok: false, error: 'الطلب غير موجود.' };
    }
    if (approved && status !== 'draft') {
      await conn.rollback();
      return { ok: false, error: 'الطلب معتمد بالفعل.' };
    }
    if (!approved && status !== 'approved') {
      await conn.rollback();
      return { ok: false, error: 'الطلب ليس معتمداً.' };
    }
    if (approved) {
      await conn.execute(
        `UPDATE sal_customer_order SET status='approved', approved_by=?, approved_at=NOW(), updated_by=? WHERE id=?`,
        [userId || null, userId || null, orderId]
      );
    } else {
      await conn.execute(
        `UPDATE sal_customer_order SET status='draft', approved_by=NULL, approved_at=NULL, updated_by=? WHERE id=?`,
        [userId || null, orderId]
      );
    }
    await conn.commit();
    return { ok: true, id: orderId, order: await getOrder(orderId) };
  } catch (e) {
    try {
      await conn.rollback();
    } catch {
      /* ignore */
    }
    return { ok: false, error: e.message || 'تعذر تحديث الحالة.' };
  } finally {
    conn.release();
  }
}

async function deleteOrder(id) {
  const orderId = Number(id);
  if (!orderId) return { ok: false, error: 'معرّف غير صالح.' };
  const pool = db.getPool();
  const conn = await pool.getConnection();
  try {
    await conn.beginTransaction();
    const [rows] = await conn.execute(
      `SELECT status FROM sal_customer_order WHERE id = ? FOR UPDATE`,
      [orderId]
    );
    const status = rows[0]?.status;
    if (status == null) {
      await conn.rollback();
      return { ok: false, error: 'الطلب غير موجود.' };
    }
    if (String(status) !== 'draft') {
      await conn.rollback();
      return { ok: false, error: 'لا يمكن حذف طلب معتمد. فك الاعتماد أولاً.' };
    }
    await conn.execute(`DELETE FROM sal_customer_order_line WHERE order_id = ?`, [orderId]);
    await conn.execute(`DELETE FROM sal_customer_order WHERE id = ?`, [orderId]);
    await conn.commit();
    return { ok: true };
  } catch (e) {
    try {
      await conn.rollback();
    } catch {
      /* ignore */
    }
    return { ok: false, error: e.message || 'تعذر الحذف.' };
  } finally {
    conn.release();
  }
}

async function lookups() {
  const inv = require('./invoicesService');
  const base = await inv.lookups();
  let reps = [];
  try {
    reps = await db.query(
      `SELECT id, code, name_ar FROM crm_sales_rep WHERE is_active = 1 ORDER BY name_ar`
    );
  } catch {
    try {
      reps = await db.query(`SELECT id, code, name_ar FROM crm_sales_rep ORDER BY name_ar`);
    } catch {
      reps = [];
    }
  }
  return { ...base, sales_reps: reps };
}

const { neighbors, findIdByNo } = require('../lib/docBrowse');

async function browseNeighbors(id) {
  return neighbors('sal_customer_order', id);
}

async function findOrderIdByNo(no) {
  return findIdByNo('sal_customer_order', 'order_no', no);
}

module.exports = {
  getOrder,
  saveOrder,
  setApproved,
  deleteOrder,
  nextOrderNo,
  lookups,
  browseNeighbors,
  findOrderIdByNo,
};
