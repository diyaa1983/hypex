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
    console.error('sales query', e.message);
    return [];
  }
}

async function listInvoices({ q = '', filter = 'all', limit = 80 } = {}) {
  const where = [`i.status = 'confirmed'`];
  const params = [];
  if (filter === 'unposted') {
    where.push(`NOT EXISTS (SELECT 1 FROM crm_customer_ledger l WHERE l.txn_type='sale_invoice' AND l.ref_id=i.id)
      AND NOT EXISTS (SELECT 1 FROM inv_stock_move m WHERE m.ref_type='sale_invoice' AND m.ref_id=i.id)`);
  } else if (filter === 'posted') {
    where.push(`(EXISTS (SELECT 1 FROM crm_customer_ledger l WHERE l.txn_type='sale_invoice' AND l.ref_id=i.id)
      OR EXISTS (SELECT 1 FROM inv_stock_move m WHERE m.ref_type='sale_invoice' AND m.ref_id=i.id))`);
  }
  if (q) {
    where.push(`(i.invoice_no LIKE ? OR c.name_ar LIKE ? OR c.code LIKE ?)`);
    const like = `%${q}%`;
    params.push(like, like, like);
  }
  return safeQuery(
    `SELECT i.id, i.invoice_no, i.invoice_date, i.total, i.payment_type, i.subtotal, i.tax_amount,
            c.name_ar AS customer_name, c.code AS customer_code,
            EXISTS (SELECT 1 FROM crm_customer_ledger l WHERE l.txn_type='sale_invoice' AND l.ref_id=i.id) AS fin_posted
     FROM sal_invoice i
     INNER JOIN crm_customer c ON c.id = i.customer_id
     WHERE ${where.join(' AND ')}
     ORDER BY i.id DESC
     LIMIT ${Math.min(200, limit)}`,
    params
  );
}

/** تقريبي: فواتير مؤكدة برصيد عميل مدين */
async function listUnpaid({ q = '', limit = 80 } = {}) {
  const params = [];
  let extra = '';
  if (q) {
    extra = ` AND (i.invoice_no LIKE ? OR c.name_ar LIKE ? OR c.code LIKE ?)`;
    const like = `%${q}%`;
    params.push(like, like, like);
  }
  return safeQuery(
    `SELECT i.id, i.invoice_no, i.invoice_date, i.total, c.name_ar AS customer_name, c.code AS customer_code,
            i.total AS remaining
     FROM sal_invoice i
     INNER JOIN crm_customer c ON c.id = i.customer_id
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
    where.push(`(o.order_no LIKE ? OR c.name_ar LIKE ?)`);
    params.push(`%${q}%`, `%${q}%`);
  }
  return safeQuery(
    `SELECT o.id, o.order_no, o.order_date, o.status, o.total, c.name_ar AS customer_name
     FROM sal_customer_order o
     LEFT JOIN crm_customer c ON c.id = o.customer_id
     WHERE ${where.join(' AND ')}
     ORDER BY o.id DESC
     LIMIT ${Math.min(200, limit)}`,
    params
  );
}

async function listDeliveries({ q = '', limit = 80 } = {}) {
  const params = [];
  let extra = '';
  if (q) {
    extra = ` AND (d.delivery_no LIKE ? OR c.name_ar LIKE ?)`;
    params.push(`%${q}%`, `%${q}%`);
  }
  return safeQuery(
    `SELECT d.id, d.delivery_no, d.delivery_date, d.status, d.is_posted, c.name_ar AS customer_name
     FROM sal_delivery d
     LEFT JOIN crm_customer c ON c.id = d.customer_id
     WHERE 1=1 ${extra}
     ORDER BY d.id DESC
     LIMIT ${Math.min(200, limit)}`,
    params
  );
}

async function listReturns({ q = '', limit = 80 } = {}) {
  const params = [];
  let extra = '';
  if (q) {
    extra = ` AND (r.return_no LIKE ? OR c.name_ar LIKE ?)`;
    params.push(`%${q}%`, `%${q}%`);
  }
  return safeQuery(
    `SELECT r.id, r.return_no, r.return_date, r.status, r.total, c.name_ar AS customer_name
     FROM sal_return r
     LEFT JOIN crm_customer c ON c.id = r.customer_id
     WHERE 1=1 ${extra}
     ORDER BY r.id DESC
     LIMIT ${Math.min(200, limit)}`,
    params
  );
}

function dateRange(from, to) {
  const f = from || monthStart();
  const t = to || todayIso();
  return { from: f, to: t };
}

async function reportSalesByCustomer(from, to) {
  const r = dateRange(from, to);
  return safeQuery(
    `SELECT c.name_ar AS label, c.code AS code,
            COUNT(i.id) AS cnt, COALESCE(SUM(i.total),0) AS total
     FROM sal_invoice i
     INNER JOIN crm_customer c ON c.id = i.customer_id
     WHERE i.status='confirmed' AND i.invoice_date BETWEEN ? AND ?
     GROUP BY c.id, c.name_ar, c.code
     ORDER BY total DESC
     LIMIT 200`,
    [r.from, r.to]
  );
}

async function reportSalesBetweenDates(from, to) {
  const r = dateRange(from, to);
  return safeQuery(
    `SELECT i.invoice_date AS label, COUNT(*) AS cnt, COALESCE(SUM(i.total),0) AS total
     FROM sal_invoice i
     WHERE i.status='confirmed' AND i.invoice_date BETWEEN ? AND ?
     GROUP BY i.invoice_date
     ORDER BY i.invoice_date`,
    [r.from, r.to]
  );
}

async function reportSalesByItem(from, to) {
  const r = dateRange(from, to);
  return safeQuery(
    `SELECT COALESCE(il.line_desc, it.name_ar) AS label, COALESCE(NULLIF(TRIM(it.barcode), ''), it.sku) AS code,
            SUM(il.qty) AS qty, COALESCE(SUM(il.line_gross), SUM(il.line_total),0) AS total
     FROM sal_invoice_line il
     INNER JOIN sal_invoice i ON i.id = il.invoice_id
     LEFT JOIN inv_item it ON it.id = il.item_id
     WHERE i.status='confirmed' AND i.invoice_date BETWEEN ? AND ?
     GROUP BY il.item_id, label, code
     ORDER BY total DESC
     LIMIT 200`,
    [r.from, r.to]
  );
}

async function reportSalesByRegion(from, to) {
  const r = dateRange(from, to);
  return safeQuery(
    `SELECT COALESCE(rg.name_ar, '— بدون منطقة —') AS label,
            COUNT(i.id) AS cnt,
            COALESCE(SUM(i.subtotal), 0) AS subtotal,
            COALESCE(SUM(i.total), 0) AS total
     FROM sal_invoice i
     LEFT JOIN crm_customer c ON c.id = i.customer_id
     LEFT JOIN crm_region rg ON rg.id = c.region_id
     WHERE i.status = 'confirmed' AND i.invoice_date BETWEEN ? AND ?
     GROUP BY rg.id, label
     ORDER BY total DESC
     LIMIT 200`,
    [r.from, r.to]
  );
}

async function listRepsSimple() {
  return safeQuery(
    `SELECT id, code, name_ar FROM crm_sales_rep WHERE is_active = 1 ORDER BY name_ar LIMIT 300`
  );
}

/** تجميع فواتير حسب المندوب (من الفاتورة أو العميل) */
async function reportSalesByRepSummary(from, to) {
  const r = dateRange(from, to);
  return safeQuery(
    `SELECT COALESCE(sr.id, 0) AS rep_id,
            COALESCE(sr.name_ar, '— بدون مندوب —') AS label,
            COALESCE(sr.code, '') AS code,
            COUNT(i.id) AS cnt,
            COALESCE(SUM(i.subtotal), 0) AS subtotal,
            COALESCE(SUM(i.total), 0) AS total
     FROM sal_invoice i
     LEFT JOIN crm_customer c ON c.id = i.customer_id
     LEFT JOIN crm_sales_rep sr ON sr.id = COALESCE(i.sales_rep_id, c.sales_rep_id)
     WHERE i.status = 'confirmed' AND i.invoice_date BETWEEN ? AND ?
     GROUP BY sr.id, label, code
     ORDER BY total DESC
     LIMIT 200`,
    [r.from, r.to]
  );
}

async function reportSalesByRepDetail(salesRepId, from, to) {
  const r = dateRange(from, to);
  const rid = Number(salesRepId);
  if (rid < 1) return [];
  return safeQuery(
    `SELECT i.id, i.invoice_no, i.invoice_date, i.subtotal, i.tax_amount, i.total, i.payment_type,
            c.code AS customer_code, c.name_ar AS customer_name
     FROM sal_invoice i
     INNER JOIN crm_customer c ON c.id = i.customer_id
     WHERE i.status = 'confirmed'
       AND i.invoice_date BETWEEN ? AND ?
       AND COALESCE(i.sales_rep_id, c.sales_rep_id) = ?
     ORDER BY i.invoice_date ASC, i.id ASC
     LIMIT 2000`,
    [r.from, r.to, rid]
  );
}

async function reportQtyExtra(from, to) {
  const r = dateRange(from, to);
  return safeQuery(
    `SELECT i.invoice_no AS code, i.invoice_date AS d, c.name_ar AS label,
            il.line_desc AS item, il.qty_extra AS qty
     FROM sal_invoice_line il
     INNER JOIN sal_invoice i ON i.id = il.invoice_id
     LEFT JOIN crm_customer c ON c.id = i.customer_id
     WHERE i.status='confirmed' AND i.invoice_date BETWEEN ? AND ?
       AND COALESCE(il.qty_extra,0) > 0
     ORDER BY i.invoice_date DESC, i.id DESC
     LIMIT 200`,
    [r.from, r.to]
  );
}

async function reportInvoiceDiscount(from, to) {
  const r = dateRange(from, to);
  return safeQuery(
    `SELECT i.invoice_no AS code, i.invoice_date AS d, c.name_ar AS label,
            i.invoice_discount_input AS disc, i.subtotal, i.total
     FROM sal_invoice i
     LEFT JOIN crm_customer c ON c.id = i.customer_id
     WHERE i.status='confirmed' AND i.invoice_date BETWEEN ? AND ?
       AND i.invoice_discount_input IS NOT NULL AND TRIM(i.invoice_discount_input) <> ''
     ORDER BY i.id DESC
     LIMIT 200`,
    [r.from, r.to]
  );
}

async function reportCustomerOrders(from, to) {
  const r = dateRange(from, to);
  return safeQuery(
    `SELECT o.order_no AS code, o.order_date AS d, o.status, c.name_ar AS label, o.total
     FROM sal_customer_order o
     LEFT JOIN crm_customer c ON c.id = o.customer_id
     WHERE o.order_date BETWEEN ? AND ?
     ORDER BY o.id DESC
     LIMIT 200`,
    [r.from, r.to]
  );
}

async function reportDelivery(from, to) {
  const r = dateRange(from, to);
  return safeQuery(
    `SELECT d.delivery_no AS code, d.delivery_date AS d, d.status, d.is_posted,
            c.name_ar AS label
     FROM sal_delivery d
     LEFT JOIN crm_customer c ON c.id = d.customer_id
     WHERE d.delivery_date BETWEEN ? AND ?
     ORDER BY d.id DESC
     LIMIT 200`,
    [r.from, r.to]
  );
}

async function reportReturns(from, to) {
  const r = dateRange(from, to);
  return safeQuery(
    `SELECT r.return_no AS code, r.return_date AS d, r.status, c.name_ar AS label, r.total
     FROM sal_return r
     LEFT JOIN crm_customer c ON c.id = r.customer_id
     WHERE r.return_date BETWEEN ? AND ?
     ORDER BY r.id DESC
     LIMIT 200`,
    [r.from, r.to]
  );
}

async function reportReturnsTotals(from, to) {
  const r = dateRange(from, to);
  return safeQuery(
    `SELECT COALESCE(c.name_ar, '—') AS label, COUNT(r.id) AS cnt, COALESCE(SUM(r.total),0) AS total
     FROM sal_return r
     LEFT JOIN crm_customer c ON c.id = r.customer_id
     WHERE r.return_date BETWEEN ? AND ?
     GROUP BY c.id, label
     ORDER BY total DESC
     LIMIT 200`,
    [r.from, r.to]
  );
}

module.exports = {
  listInvoices,
  listUnpaid,
  listOrders,
  listDeliveries,
  listReturns,
  reportSalesByCustomer,
  reportSalesBetweenDates,
  reportSalesByItem,
  reportSalesByRegion,
  listRepsSimple,
  reportSalesByRepSummary,
  reportSalesByRepDetail,
  reportQtyExtra,
  reportInvoiceDiscount,
  reportCustomerOrders,
  reportDelivery,
  reportReturns,
  reportReturnsTotals,
  dateRange,
};
