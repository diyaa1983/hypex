'use strict';

const db = require('../db');
const { todayIso } = require('../lib/html');

function monthStart() {
  const d = new Date();
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-01`;
}

async function safeQuery(sql, params = []) {
  try {
    return await db.query(sql, params);
  } catch (e) {
    console.error('purchases query', e.message);
    return [];
  }
}

function dateRange(from, to) {
  return { from: from || monthStart(), to: to || todayIso() };
}

async function listInvoices({ q = '', filter = 'all', limit = 80 } = {}) {
  const where = [`i.status = 'confirmed'`];
  const params = [];
  if (filter === 'unposted') {
    where.push(`NOT EXISTS (
      SELECT 1 FROM crm_supplier_ledger l
      WHERE l.txn_type IN ('purchase_invoice','pur_invoice','purchase') AND l.ref_id = i.id
    )`);
  }
  if (q) {
    where.push(`(i.invoice_no LIKE ? OR s.name_ar LIKE ? OR s.code LIKE ? OR IFNULL(i.supplier_invoice_no,'') LIKE ?)`);
    const like = `%${q}%`;
    params.push(like, like, like, like);
  }
  return safeQuery(
    `SELECT i.id, i.invoice_no, i.supplier_invoice_no, i.invoice_date, i.total, i.payment_type, i.status,
            s.name_ar AS supplier_name, s.code AS supplier_code
     FROM pur_invoice i
     INNER JOIN crm_supplier s ON s.id = i.supplier_id
     WHERE ${where.join(' AND ')}
     ORDER BY i.id DESC
     LIMIT ${Math.min(200, limit)}`,
    params
  );
}

async function listUnpaid({ q = '', limit = 80 } = {}) {
  const params = [];
  let extra = '';
  if (q) {
    extra = ` AND (i.invoice_no LIKE ? OR s.name_ar LIKE ? OR s.code LIKE ?)`;
    const like = `%${q}%`;
    params.push(like, like, like);
  }
  return safeQuery(
    `SELECT i.id, i.invoice_no, i.invoice_date, i.total, s.name_ar AS supplier_name, s.code AS supplier_code,
            i.total AS remaining
     FROM pur_invoice i
     INNER JOIN crm_supplier s ON s.id = i.supplier_id
     WHERE i.status = 'confirmed' AND COALESCE(i.total,0) > 0
       AND i.payment_type = 'credit'
       ${extra}
     ORDER BY i.invoice_date DESC, i.id DESC
     LIMIT ${Math.min(200, limit)}`,
    params
  );
}

async function listOrders({ q = '', status = '', limit = 80 } = {}) {
  const where = ['1=1'];
  const params = [];
  if (status) {
    where.push('o.status = ?');
    params.push(status);
  }
  if (q) {
    where.push(`(o.order_no LIKE ? OR s.name_ar LIKE ? OR IFNULL(o.reference_no,'') LIKE ?)`);
    params.push(`%${q}%`, `%${q}%`, `%${q}%`);
  }
  return safeQuery(
    `SELECT o.id, o.order_no, o.order_date, o.status, o.total, s.name_ar AS supplier_name
     FROM pur_order o
     LEFT JOIN crm_supplier s ON s.id = o.supplier_id
     WHERE ${where.join(' AND ')}
     ORDER BY o.id DESC
     LIMIT ${Math.min(200, limit)}`,
    params
  );
}

async function listOpenOrders(limit = 100) {
  return safeQuery(
    `SELECT o.id, o.order_no, o.order_date, o.status, o.total, s.name_ar AS supplier_name
     FROM pur_order o
     LEFT JOIN crm_supplier s ON s.id = o.supplier_id
     WHERE o.status IN ('draft','approved','open','partial','confirmed')
       AND o.status <> 'cancelled'
       AND o.status <> 'closed'
       AND o.status <> 'invoiced'
     ORDER BY o.id DESC
     LIMIT ${Math.min(200, limit)}`
  );
}

async function listReturns({ q = '', limit = 80 } = {}) {
  const params = [];
  let extra = '';
  if (q) {
    extra = ` AND (r.return_no LIKE ? OR s.name_ar LIKE ?)`;
    params.push(`%${q}%`, `%${q}%`);
  }
  return safeQuery(
    `SELECT r.id, r.return_no, r.return_date, r.status, r.total, s.name_ar AS supplier_name
     FROM pur_return r
     LEFT JOIN crm_supplier s ON s.id = r.supplier_id
     WHERE 1=1 ${extra}
     ORDER BY r.id DESC
     LIMIT ${Math.min(200, limit)}`,
    params
  );
}

async function reportOrders(from, to) {
  const r = dateRange(from, to);
  return safeQuery(
    `SELECT o.order_no AS code, o.order_date AS d, o.status, s.name_ar AS label, o.total
     FROM pur_order o
     LEFT JOIN crm_supplier s ON s.id = o.supplier_id
     WHERE o.order_date BETWEEN ? AND ?
     ORDER BY o.id DESC
     LIMIT 200`,
    [r.from, r.to]
  );
}

async function reportOrdersByItem(from, to) {
  const r = dateRange(from, to);
  return safeQuery(
    `SELECT COALESCE(ol.line_desc, it.name_ar) AS label, it.sku AS code,
            SUM(ol.qty) AS qty, COALESCE(SUM(ol.line_total),0) AS total
     FROM pur_order_line ol
     INNER JOIN pur_order o ON o.id = ol.order_id
     LEFT JOIN inv_item it ON it.id = ol.item_id
     WHERE o.order_date BETWEEN ? AND ?
     GROUP BY ol.item_id, label, code
     ORDER BY total DESC
     LIMIT 200`,
    [r.from, r.to]
  );
}

async function reportPurchasesBetween(from, to) {
  const r = dateRange(from, to);
  return safeQuery(
    `SELECT i.invoice_date AS label, COUNT(*) AS cnt, COALESCE(SUM(i.total),0) AS total
     FROM pur_invoice i
     WHERE i.status = 'confirmed' AND i.invoice_date BETWEEN ? AND ?
     GROUP BY i.invoice_date
     ORDER BY i.invoice_date`,
    [r.from, r.to]
  );
}

async function reportPurchasesByItem(from, to) {
  const r = dateRange(from, to);
  return safeQuery(
    `SELECT COALESCE(il.line_desc, it.name_ar) AS label, it.sku AS code,
            SUM(il.qty) AS qty, COALESCE(SUM(COALESCE(il.line_total, il.line_gross, 0)),0) AS total
     FROM pur_invoice_line il
     INNER JOIN pur_invoice i ON i.id = il.invoice_id
     LEFT JOIN inv_item it ON it.id = il.item_id
     WHERE i.status = 'confirmed' AND i.invoice_date BETWEEN ? AND ?
     GROUP BY il.item_id, label, code
     ORDER BY total DESC
     LIMIT 200`,
    [r.from, r.to]
  );
}

async function reportPurchaseReturns(from, to) {
  const r = dateRange(from, to);
  return safeQuery(
    `SELECT r.return_no AS code, r.return_date AS d, r.status, s.name_ar AS label, r.total
     FROM pur_return r
     LEFT JOIN crm_supplier s ON s.id = r.supplier_id
     WHERE r.return_date BETWEEN ? AND ?
     ORDER BY r.id DESC
     LIMIT 200`,
    [r.from, r.to]
  );
}

module.exports = {
  listInvoices,
  listUnpaid,
  listOrders,
  listOpenOrders,
  listReturns,
  reportOrders,
  reportOrdersByItem,
  reportPurchasesBetween,
  reportPurchasesByItem,
  reportPurchaseReturns,
  dateRange,
};
