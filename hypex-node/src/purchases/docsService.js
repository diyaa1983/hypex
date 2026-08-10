'use strict';

const db = require('../db');
const { parseDateToIso, todayIso } = require('../lib/html');
const { normalizeLines, applyHeaderDiscount, requirePositiveUnitPrices } = require('../lib/docMath');
const invSvc = require('../sales/invoicesService');

async function defaultTax() {
  try {
    const s = await db.query(`SELECT tax_rate_percent FROM sys_company_settings ORDER BY id LIMIT 1`);
    if (s[0] && s[0].tax_rate_percent != null) return Number(s[0].tax_rate_percent);
  } catch {
    /* */
  }
  return 16;
}

async function nextNo(table, col, prefix, dateIso) {
  const year = String(dateIso).slice(0, 4);
  const suffix = `-${year}`;
  const rows = await db.query(`SELECT \`${col}\` AS no FROM \`${table}\` WHERE \`${col}\` LIKE ?`, [
    `%${suffix}`,
  ]);
  let max = 0;
  const re = new RegExp(`^${prefix}(\\d+)${suffix.replace('-', '\\-')}$`);
  for (const r of rows) {
    const m = String(r.no || '').match(re);
    if (m) max = Math.max(max, Number(m[1]));
  }
  return `${prefix}${String(max + 1).padStart(3, '0')}${suffix}`;
}

async function searchSuppliers(q, limit = 30) {
  const like = `%${String(q || '').trim()}%`;
  if (!String(q || '').trim()) {
    return db.query(
      `SELECT id, code, name_ar, phone FROM crm_supplier
       WHERE is_active = 1 ORDER BY name_ar LIMIT ${Math.min(50, Number(limit) || 30)}`
    );
  }
  return db.query(
    `SELECT id, code, name_ar, phone FROM crm_supplier
     WHERE is_active = 1 AND (name_ar LIKE ? OR code LIKE ? OR IFNULL(phone,'') LIKE ?)
     ORDER BY name_ar LIMIT ${Math.min(50, Number(limit) || 30)}`,
    [like, like, like]
  );
}

/* ── Purchase Order ── */
async function getOrder(id) {
  const orderId = Number(id);
  if (!orderId) return null;
  const headers = await db.query(
    `SELECT o.*, s.name_ar AS supplier_name, s.code AS supplier_code
     FROM pur_order o
     INNER JOIN crm_supplier s ON s.id = o.supplier_id
     WHERE o.id = ? LIMIT 1`,
    [orderId]
  );
  if (!headers[0]) return null;
  const h = headers[0];
  const lines = await db.query(
    `SELECT l.*, it.sku AS item_code, it.name_ar AS item_name
     FROM pur_order_line l
     LEFT JOIN inv_item it ON it.id = l.item_id
     WHERE l.order_id = ?
     ORDER BY l.sort_order, l.id`,
    [orderId]
  );
  const status = String(h.status || 'draft');
  const locked = !['draft', 'submitted'].includes(status);
  return {
    id: Number(h.id),
    doc_no: h.order_no,
    doc_date: h.order_date,
    expected_date: h.expected_date || '',
    supplier_id: Number(h.supplier_id),
    supplier_name: h.supplier_name,
    supplier_code: h.supplier_code,
    warehouse_id: h.warehouse_id != null ? Number(h.warehouse_id) : null,
    payment_type: h.payment_type || 'credit',
    reference_no: h.reference_no || '',
    notes: h.notes || '',
    status,
    is_locked: locked,
    status_label: status,
    subtotal: Number(h.subtotal || 0),
    tax_amount: Number(h.tax_amount || 0),
    total: Number(h.total || 0),
    invoice_discount_input: h.invoice_discount_input || '',
    lines: lines.map(mapLine),
  };
}

function mapLine(ln) {
  return {
    item_id: Number(ln.item_id),
    item_code: ln.item_code || '',
    name_ar: ln.line_desc || ln.item_name || '',
    qty: Number(ln.qty || 0),
    qty_extra: Number(ln.qty_extra || 0),
    unit_price: Number(ln.unit_price || 0),
    discount_pct: Number(ln.discount_pct || 0),
    discount_amount: Number(ln.discount_amount || 0),
    tax_rate_percent: Number(ln.tax_rate_percent || 0),
    tax_amount: Number(ln.tax_amount || 0),
    line_total: Number(ln.line_total || 0),
    line_gross: Number(ln.line_gross || 0),
  };
}

async function saveOrder(payload, userId) {
  const supplierId = Number(payload.supplier_id || 0);
  const warehouseId = Number(payload.warehouse_id || 0);
  if (supplierId < 1) return { ok: false, error: 'اختر المورد.' };
  if (warehouseId < 1) return { ok: false, error: 'اختر المستودع.' };

  const orderDate = parseDateToIso(payload.doc_date || payload.order_date || todayIso());
  const expectedRaw = String(payload.expected_date || '').trim();
  const expectedDate = expectedRaw ? parseDateToIso(expectedRaw) : null;
  const paymentType = payload.payment_type === 'cash' ? 'cash' : 'credit';
  const notes = String(payload.notes || '').trim() || null;
  const referenceNo = String(payload.reference_no || '').trim() || null;
  const discountInput = String(payload.invoice_discount || '').trim();
  const orderId = Number(payload.id || 0);
  const tax = await defaultTax();
  const lines = normalizeLines(payload.lines, tax);
  if (!lines.length) return { ok: false, error: 'أضف بند مادة واحداً على الأقل.' };
  const priceCheck = requirePositiveUnitPrices(lines);
  if (!priceCheck.ok) {
    return {
      ok: false,
      error: 'أدخل السعر لكل بند مادة. لا يمكن حفظ طلب الشراء بدون سعر.',
    };
  }
  const totals = applyHeaderDiscount(lines, discountInput);

  const pool = db.getPool();
  const conn = await pool.getConnection();
  try {
    await conn.beginTransaction();
    if (orderId > 0) {
      const [rows] = await conn.execute(`SELECT status FROM pur_order WHERE id=? FOR UPDATE`, [orderId]);
      const st = String(rows[0]?.status || '');
      if (!rows[0] || !['draft', 'submitted'].includes(st)) {
        await conn.rollback();
        return { ok: false, error: 'لا يمكن تعديل هذا الطلب.' };
      }
      await conn.execute(
        `UPDATE pur_order SET order_date=?, expected_date=?, supplier_id=?, warehouse_id=?,
         reference_no=?, payment_type=?, subtotal=?, tax_amount=?, total=?, notes=?,
         invoice_discount_input=? WHERE id=?`,
        [
          orderDate,
          expectedDate,
          supplierId,
          warehouseId,
          referenceNo,
          paymentType,
          totals.subtotal,
          totals.tax_amount,
          totals.total,
          notes,
          discountInput || null,
          orderId,
        ]
      );
      await conn.execute(`DELETE FROM pur_order_line WHERE order_id=?`, [orderId]);
      await insertOrderLines(conn, orderId, totals.lines);
      await conn.commit();
      const doc = await getOrder(orderId);
      return { ok: true, id: orderId, doc_no: doc?.doc_no, order: doc };
    }

    const orderNo = await nextNo('pur_order', 'order_no', 'PO', orderDate);
    const [result] = await conn.execute(
      `INSERT INTO pur_order
       (order_no, order_date, expected_date, supplier_id, warehouse_id, reference_no, payment_type,
        subtotal, tax_amount, total, status, notes, invoice_discount_input, created_by)
       VALUES (?,?,?,?,?,?,?,?,?,?,'draft',?,?,?)`,
      [
        orderNo,
        orderDate,
        expectedDate,
        supplierId,
        warehouseId,
        referenceNo,
        paymentType,
        totals.subtotal,
        totals.tax_amount,
        totals.total,
        notes,
        discountInput || null,
        userId || null,
      ]
    );
    const newId = Number(result.insertId);
    await insertOrderLines(conn, newId, totals.lines);
    await conn.commit();
    return { ok: true, id: newId, doc_no: orderNo, order: await getOrder(newId) };
  } catch (e) {
    try {
      await conn.rollback();
    } catch {
      /* */
    }
    console.error('saveOrder', e);
    return { ok: false, error: e.message || 'تعذر الحفظ.' };
  } finally {
    conn.release();
  }
}

async function insertOrderLines(conn, orderId, lines) {
  let i = 0;
  for (const ln of lines) {
    i += 1;
    await conn.execute(
      `INSERT INTO pur_order_line
       (order_id, item_id, line_desc, qty, qty_extra, unit_price, discount_pct, discount_amount,
        line_total, tax_rate_percent, tax_amount, line_gross, sort_order)
       VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)`,
      [
        orderId,
        ln.item_id,
        ln.name_ar || null,
        ln.qty,
        ln.qty_extra,
        ln.unit_price,
        ln.discount_pct,
        ln.discount_amount,
        ln.line_total,
        ln.tax_rate_percent,
        ln.tax_amount,
        ln.line_gross,
        i,
      ]
    );
  }
}

/* ── Purchase Invoice ── */
async function getInvoice(id) {
  const invId = Number(id);
  if (!invId) return null;
  const headers = await db.query(
    `SELECT i.*, s.name_ar AS supplier_name, s.code AS supplier_code
     FROM pur_invoice i
     INNER JOIN crm_supplier s ON s.id = i.supplier_id
     WHERE i.id = ? LIMIT 1`,
    [invId]
  );
  if (!headers[0]) return null;
  const h = headers[0];
  const lines = await db.query(
    `SELECT l.*, it.sku AS item_code, it.name_ar AS item_name
     FROM pur_invoice_line l
     LEFT JOIN inv_item it ON it.id = l.item_id
     WHERE l.invoice_id = ?
     ORDER BY l.id`,
    [invId]
  );
  const posted = await isInvoicePosted(invId);
  return {
    id: Number(h.id),
    doc_no: h.invoice_no,
    doc_date: h.invoice_date,
    supplier_id: Number(h.supplier_id),
    supplier_name: h.supplier_name,
    supplier_code: h.supplier_code,
    warehouse_id: h.warehouse_id != null ? Number(h.warehouse_id) : null,
    payment_type: h.payment_type || 'credit',
    reference_no: h.supplier_invoice_no || '',
    notes: h.notes || '',
    status: h.status,
    is_locked: posted || h.status === 'cancelled',
    is_posted: posted,
    status_label: posted ? 'مرحّلة' : h.status || 'مسودة',
    subtotal: Number(h.subtotal || 0),
    tax_amount: Number(h.tax_amount || 0),
    total: Number(h.total || 0),
    invoice_discount_input: h.invoice_discount_input || '',
    lines: lines.map(mapLine),
  };
}

async function isInvoicePosted(invoiceId) {
  const rows = await db.query(
    `SELECT 1 AS x FROM crm_supplier_ledger
     WHERE txn_type IN ('purchase_invoice','pur_invoice','purchase') AND ref_id = ?
     LIMIT 1`,
    [invoiceId]
  );
  if (rows[0]) return true;
  try {
    const m = await db.query(
      `SELECT 1 AS x FROM inv_stock_move WHERE ref_type IN ('purchase_invoice','pur_invoice') AND ref_id = ? LIMIT 1`,
      [invoiceId]
    );
    return !!m[0];
  } catch {
    return false;
  }
}

async function saveInvoice(payload, userId) {
  const supplierId = Number(payload.supplier_id || 0);
  const warehouseId = Number(payload.warehouse_id || 0);
  if (supplierId < 1) return { ok: false, error: 'اختر المورد.' };
  if (warehouseId < 1) return { ok: false, error: 'اختر المستودع.' };

  const invoiceDate = parseDateToIso(payload.doc_date || payload.invoice_date || todayIso());
  const paymentType = payload.payment_type === 'cash' ? 'cash' : 'credit';
  const notes = String(payload.notes || '').trim() || null;
  const supplierInvNo = String(payload.reference_no || payload.supplier_invoice_no || '').trim() || null;
  const discountInput = String(payload.invoice_discount || '').trim();
  const invoiceId = Number(payload.id || 0);
  const tax = await defaultTax();
  const lines = normalizeLines(payload.lines, tax);
  if (!lines.length) return { ok: false, error: 'أضف بند مادة واحداً على الأقل.' };
  const priceCheck = requirePositiveUnitPrices(lines);
  if (!priceCheck.ok) {
    return {
      ok: false,
      error: 'أدخل السعر لكل بند مادة. لا يمكن حفظ فاتورة المشتريات بدون سعر.',
    };
  }
  const totals = applyHeaderDiscount(lines, discountInput);

  const pool = db.getPool();
  const conn = await pool.getConnection();
  try {
    await conn.beginTransaction();
    if (invoiceId > 0) {
      if (await isInvoicePosted(invoiceId)) {
        await conn.rollback();
        return { ok: false, error: 'لا يمكن تعديل فاتورة مرحّلة.' };
      }
      await conn.execute(
        `UPDATE pur_invoice SET invoice_date=?, supplier_id=?, warehouse_id=?, payment_type=?,
         supplier_invoice_no=?, subtotal=?, tax_amount=?, total=?, notes=?, invoice_discount_input=?
         WHERE id=? AND status='confirmed'`,
        [
          invoiceDate,
          supplierId,
          warehouseId,
          paymentType,
          supplierInvNo,
          totals.subtotal,
          totals.tax_amount,
          totals.total,
          notes,
          discountInput || null,
          invoiceId,
        ]
      );
      await conn.execute(`DELETE FROM pur_invoice_line WHERE invoice_id=?`, [invoiceId]);
      await insertInvoiceLines(conn, invoiceId, totals.lines);
      await conn.commit();
      return { ok: true, id: invoiceId, doc_no: payload.doc_no || '', invoice: await getInvoice(invoiceId) };
    }

    const invoiceNo = await nextNo('pur_invoice', 'invoice_no', 'PI', invoiceDate);
    const [result] = await conn.execute(
      `INSERT INTO pur_invoice
       (invoice_no, invoice_date, supplier_id, warehouse_id, payment_type, supplier_invoice_no,
        subtotal, tax_amount, total, status, notes, invoice_discount_input, created_by)
       VALUES (?,?,?,?,?,?,?,?,?,'confirmed',?,?,?)`,
      [
        invoiceNo,
        invoiceDate,
        supplierId,
        warehouseId,
        paymentType,
        supplierInvNo,
        totals.subtotal,
        totals.tax_amount,
        totals.total,
        notes,
        discountInput || null,
        userId || null,
      ]
    );
    const newId = Number(result.insertId);
    await insertInvoiceLines(conn, newId, totals.lines);
    await conn.commit();
    return { ok: true, id: newId, doc_no: invoiceNo, invoice: await getInvoice(newId) };
  } catch (e) {
    try {
      await conn.rollback();
    } catch {
      /* */
    }
    console.error('savePurInvoice', e);
    return { ok: false, error: e.message || 'تعذر الحفظ.' };
  } finally {
    conn.release();
  }
}

async function insertInvoiceLines(conn, invoiceId, lines) {
  for (const ln of lines) {
    await conn.execute(
      `INSERT INTO pur_invoice_line
       (invoice_id, item_id, line_desc, qty, qty_extra, unit_price, discount_pct, discount_amount,
        line_total, tax_rate_percent, tax_amount, line_gross)
       VALUES (?,?,?,?,?,?,?,?,?,?,?,?)`,
      [
        invoiceId,
        ln.item_id,
        ln.name_ar || null,
        ln.qty,
        ln.qty_extra,
        ln.unit_price,
        ln.discount_pct,
        ln.discount_amount,
        ln.line_total,
        ln.tax_rate_percent,
        ln.tax_amount,
        ln.line_gross,
      ]
    );
  }
}

async function lookups() {
  const base = await invSvc.lookups();
  return base;
}

const { neighbors, findIdByNo } = require('../lib/docBrowse');

async function browseOrderNeighbors(id) {
  return neighbors('pur_order', id);
}

async function findOrderIdByNo(no) {
  return findIdByNo('pur_order', 'order_no', no);
}

async function browseInvoiceNeighbors(id) {
  return neighbors('pur_invoice', id);
}

async function findInvoiceIdByNo(no) {
  return findIdByNo('pur_invoice', 'invoice_no', no);
}

module.exports = {
  searchSuppliers,
  getOrder,
  saveOrder,
  getInvoice,
  saveInvoice,
  lookups,
  isInvoicePosted,
  browseOrderNeighbors,
  findOrderIdByNo,
  browseInvoiceNeighbors,
  findInvoiceIdByNo,
};
