'use strict';

const db = require('../db');
const { todayIso, parseDateToIso } = require('../lib/html');

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
  const where = ['IFNULL(o.is_sent,1) = 1'];
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
  return {
    from: parseDateToIso(from || '', monthStart()),
    to: parseDateToIso(to || '', todayIso()),
  };
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
       AND IFNULL(o.is_sent,1) = 1
     ORDER BY o.id DESC
     LIMIT 200`,
    [r.from, r.to]
  );
}

async function reportCustomerOrdersByItem({ itemId, from, to }) {
  const id = Number(itemId || 0);
  if (id < 1) return { item: null, rows: [], totals: { qty: 0, line_gross: 0 } };
  const r = dateRange(from, to);
  const items = await safeQuery(
    `SELECT id, barcode, sku, name_ar FROM inv_item WHERE id = ? LIMIT 1`,
    [id]
  );
  const item = items[0] || null;
  if (!item) return { item: null, rows: [], totals: { qty: 0, line_gross: 0 } };

  let rows = [];
  try {
    rows = await safeQuery(
      `SELECT o.id, o.order_no, o.order_date, o.status,
              c.name_ar AS customer_name,
              COALESCE(r.name_ar, '') AS sales_rep_name,
              l.unit_name,
              COALESCE(l.qty, 0) AS qty,
              COALESCE(l.qty_extra, 0) AS qty_extra,
              COALESCE(l.unit_price, 0) AS unit_price,
              COALESCE(l.line_gross, 0) AS line_gross
       FROM sal_customer_order_line l
       INNER JOIN sal_customer_order o ON o.id = l.order_id
       INNER JOIN crm_customer c ON c.id = o.customer_id
       LEFT JOIN crm_sales_rep r ON r.id = o.sales_rep_id
       WHERE l.item_id = ?
         AND o.order_date BETWEEN ? AND ?
         AND IFNULL(o.is_sent, 1) = 1
       ORDER BY o.order_date ASC, o.id ASC, l.line_no ASC
       LIMIT 2000`,
      [id, r.from, r.to]
    );
  } catch (_) {
    rows = await safeQuery(
      `SELECT o.id, o.order_no, o.order_date, o.status,
              c.name_ar AS customer_name,
              COALESCE(r.name_ar, '') AS sales_rep_name,
              l.unit_name,
              COALESCE(l.qty, 0) AS qty,
              0 AS qty_extra,
              0 AS unit_price,
              0 AS line_gross
       FROM sal_customer_order_line l
       INNER JOIN sal_customer_order o ON o.id = l.order_id
       INNER JOIN crm_customer c ON c.id = o.customer_id
       LEFT JOIN crm_sales_rep r ON r.id = o.sales_rep_id
       WHERE l.item_id = ?
         AND o.order_date BETWEEN ? AND ?
       ORDER BY o.order_date ASC, o.id ASC, l.line_no ASC
       LIMIT 2000`,
      [id, r.from, r.to]
    );
  }

  const totals = { qty: 0, line_gross: 0 };
  for (const row of rows) {
    totals.qty += Number(row.qty || 0) + Number(row.qty_extra || 0);
    totals.line_gross += Number(row.line_gross || 0);
  }
  return { item, rows, totals };
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

const SALES_DETAILED_GROUP_BY = [
  'customer',
  'sales_rep',
  'region',
  'category',
  'item',
  'invoice_date',
  'warehouse',
  'payment_type',
];

function normalizeSalesDetailedGroupBy(groupBy) {
  const g = String(groupBy || 'customer');
  return SALES_DETAILED_GROUP_BY.includes(g) ? g : 'customer';
}

function salesDetailedGroupKey(row, groupBy) {
  switch (groupBy) {
    case 'sales_rep':
      return {
        key: `rep:${Number(row.sales_rep_id || 0)}`,
        label: row.sales_rep_name || '— بدون مندوب —',
        code: row.sales_rep_code || '',
      };
    case 'region':
      return {
        key: `region:${Number(row.region_id || 0)}`,
        label: row.region_name || '— بدون منطقة —',
        code: '',
      };
    case 'category':
      return {
        key: `cat:${Number(row.category_id || 0)}`,
        label: row.category_name || '— بدون فئة —',
        code: '',
      };
    case 'item':
      return {
        key: `item:${Number(row.item_id || 0)}`,
        label: row.item_name || '',
        code: row.item_sku || '',
      };
    case 'invoice_date':
      return { key: `date:${row.invoice_date || ''}`, label: row.invoice_date || '', code: '' };
    case 'warehouse':
      return {
        key: `wh:${Number(row.warehouse_id || 0)}`,
        label: row.warehouse_name || '— بدون مستودع —',
        code: '',
      };
    case 'payment_type': {
      const pt = String(row.payment_type || '');
      const label = pt === 'credit' ? 'آجل' : pt === 'cash' ? 'نقد' : pt || '—';
      return { key: `pay:${pt}`, label, code: '' };
    }
    default:
      return {
        key: `cust:${Number(row.customer_id || 0)}`,
        label: row.customer_name || '',
        code: row.customer_code || '',
      };
  }
}

function buildSalesDetailedSummary(details, groupBy) {
  const map = new Map();
  for (const row of details) {
    const { key, label, code } = salesDetailedGroupKey(row, groupBy);
    if (!map.has(key)) {
      map.set(key, {
        group_key: key,
        label,
        code,
        qty: 0,
        line_total: 0,
        line_gross: 0,
        tax_amount: 0,
        line_count: 0,
        invoices: new Set(),
      });
    }
    const block = map.get(key);
    block.qty += Number(row.qty || 0);
    block.line_total += Number(row.line_total || 0);
    block.line_gross += Number(row.line_gross || 0);
    block.tax_amount += Number(row.tax_amount || 0);
    block.line_count += 1;
    const invId = Number(row.invoice_id || 0);
    if (invId > 0) block.invoices.add(invId);
  }
  const summary = [...map.values()]
    .map((b) => ({
      group_key: b.group_key,
      label: b.label,
      code: b.code,
      qty: b.qty,
      line_total: b.line_total,
      line_gross: b.line_gross,
      tax_amount: b.tax_amount,
      line_count: b.line_count,
      invoice_count: b.invoices.size,
    }))
    .sort((a, b) => Number(b.line_gross || 0) - Number(a.line_gross || 0));
  return summary;
}

/** تقرير المبيعات التفصيلي — بنود + ملخص مجمّع */
async function reportSalesDetailed(filters = {}) {
  const r = dateRange(filters.from, filters.to);
  const groupBy = normalizeSalesDetailedGroupBy(filters.group_by);
  const empty = {
    summary: [],
    details: [],
    totals: { qty: 0, line_total: 0, line_gross: 0, tax_amount: 0, line_count: 0, invoice_count: 0 },
    group_by: groupBy,
  };
  if (!r.from || !r.to) return empty;

  const customerId = Number(filters.customer_id || 0) || 0;
  const salesRepId = Number(filters.sales_rep_id || 0) || 0;
  const regionId = Number(filters.region_id || 0) || 0;
  const categoryId = Number(filters.category_id || 0) || 0;
  const itemId = Number(filters.item_id || 0) || 0;
  const warehouseId = Number(filters.warehouse_id || 0) || 0;
  const paymentType = String(filters.payment_type || '').toLowerCase();
  const postedOnly = String(filters.posted_only || '') === '1' || filters.posted_only === true;

  const where = [`i.status = 'confirmed'`, 'i.invoice_date BETWEEN ? AND ?'];
  const params = [r.from, r.to];
  if (customerId > 0) {
    where.push('i.customer_id = ?');
    params.push(customerId);
  }
  if (salesRepId > 0) {
    where.push('COALESCE(i.sales_rep_id, c.sales_rep_id) = ?');
    params.push(salesRepId);
  }
  if (regionId > 0) {
    where.push('c.region_id = ?');
    params.push(regionId);
  }
  if (categoryId > 0) {
    where.push('it.category_id = ?');
    params.push(categoryId);
  }
  if (itemId > 0) {
    where.push('l.item_id = ?');
    params.push(itemId);
  }
  if (warehouseId > 0) {
    where.push('i.warehouse_id = ?');
    params.push(warehouseId);
  }
  if (paymentType === 'cash' || paymentType === 'credit') {
    where.push('i.payment_type = ?');
    params.push(paymentType);
  }
  if (postedOnly) {
    where.push(`(
      (
        (COALESCE(i.total, 0) > 0.000001 AND EXISTS (
          SELECT 1 FROM crm_customer_ledger lg
          WHERE lg.txn_type = 'sale_invoice' AND lg.ref_id = i.id
        ))
        OR (
          NOT (COALESCE(i.total, 0) > 0.000001)
          AND EXISTS (SELECT 1 FROM sal_invoice_line ils WHERE ils.invoice_id = i.id AND COALESCE(ils.qty,0) > 0)
        )
      )
      AND (
        NOT (
          i.warehouse_id IS NOT NULL AND EXISTS (
            SELECT 1 FROM sal_invoice_line il
            INNER JOIN inv_item it2 ON it2.id = il.item_id
            WHERE il.invoice_id = i.id AND it2.track_inventory = 1 AND COALESCE(il.qty,0) > 0
          )
        )
        OR EXISTS (
          SELECT 1 FROM inv_stock_move m
          WHERE m.ref_type = 'sale_invoice' AND m.ref_id = i.id
        )
      )
    )`);
  }

  const limit = Math.min(5000, Math.max(100, Number(filters.limit || 3000)));
  const rows = await safeQuery(
    `SELECT i.id AS invoice_id, i.invoice_no, i.invoice_date, i.payment_type,
            c.id AS customer_id, c.code AS customer_code, c.name_ar AS customer_name,
            COALESCE(sr.id, 0) AS sales_rep_id,
            COALESCE(sr.name_ar, '') AS sales_rep_name,
            COALESCE(sr.code, '') AS sales_rep_code,
            COALESCE(rg.id, 0) AS region_id,
            COALESCE(rg.name_ar, '') AS region_name,
            COALESCE(w.id, 0) AS warehouse_id,
            COALESCE(w.name_ar, '') AS warehouse_name,
            it.id AS item_id,
            COALESCE(NULLIF(TRIM(it.sku), ''), it.barcode, '') AS item_sku,
            COALESCE(NULLIF(TRIM(l.line_desc), ''), it.name_ar, '') AS item_name,
            COALESCE(cat.id, 0) AS category_id,
            COALESCE(cat.name_ar, '') AS category_name,
            l.qty, l.unit_price, l.discount_pct,
            l.line_total, COALESCE(l.tax_amount, 0) AS tax_amount, COALESCE(l.line_gross, l.line_total, 0) AS line_gross
     FROM sal_invoice_line l
     INNER JOIN sal_invoice i ON i.id = l.invoice_id
     INNER JOIN crm_customer c ON c.id = i.customer_id
     LEFT JOIN crm_sales_rep sr ON sr.id = COALESCE(i.sales_rep_id, c.sales_rep_id)
     LEFT JOIN crm_region rg ON rg.id = c.region_id
     LEFT JOIN inv_warehouse w ON w.id = i.warehouse_id
     INNER JOIN inv_item it ON it.id = l.item_id
     LEFT JOIN inv_item_category cat ON cat.id = it.category_id
     WHERE ${where.join(' AND ')}
     ORDER BY i.invoice_date ASC, i.id ASC, l.id ASC
     LIMIT ${limit}`,
    params
  );

  if (!rows.length) return empty;

  const details = rows.map((row) => ({
    invoice_id: Number(row.invoice_id || 0),
    invoice_no: row.invoice_no || '',
    invoice_date: row.invoice_date || '',
    payment_type: row.payment_type || '',
    customer_id: Number(row.customer_id || 0),
    customer_code: row.customer_code || '',
    customer_name: row.customer_name || '',
    sales_rep_id: Number(row.sales_rep_id || 0),
    sales_rep_name: row.sales_rep_name || '',
    sales_rep_code: row.sales_rep_code || '',
    region_id: Number(row.region_id || 0),
    region_name: row.region_name || '',
    warehouse_id: Number(row.warehouse_id || 0),
    warehouse_name: row.warehouse_name || '',
    item_id: Number(row.item_id || 0),
    item_sku: row.item_sku || '',
    item_name: row.item_name || '',
    category_id: Number(row.category_id || 0),
    category_name: row.category_name || '',
    qty: Number(row.qty || 0),
    unit_price: Number(row.unit_price || 0),
    discount_pct: Number(row.discount_pct || 0),
    line_total: Number(row.line_total || 0),
    line_gross: Number(row.line_gross || 0),
    tax_amount: Number(row.tax_amount || 0),
  }));

  const totals = { qty: 0, line_total: 0, line_gross: 0, tax_amount: 0, line_count: 0, invoice_count: 0 };
  const invoiceIds = new Set();
  for (const row of details) {
    totals.qty += row.qty;
    totals.line_total += row.line_total;
    totals.line_gross += row.line_gross;
    totals.tax_amount += row.tax_amount;
    if (row.invoice_id > 0) invoiceIds.add(row.invoice_id);
  }
  totals.line_count = details.length;
  totals.invoice_count = invoiceIds.size;

  return {
    summary: buildSalesDetailedSummary(details, groupBy),
    details,
    totals,
    group_by: groupBy,
    source: 'sales',
  };
}

function normalizeDetailedSource(source) {
  const s = String(source || 'sales').toLowerCase();
  if (s === 'orders' || s === 'order') return 'orders';
  if (s === 'both' || s === 'all') return 'both';
  return 'sales';
}

function computeDetailedTotals(details) {
  const totals = {
    qty: 0,
    line_total: 0,
    line_gross: 0,
    tax_amount: 0,
    line_count: 0,
    invoice_count: 0,
    order_count: 0,
    doc_count: 0,
  };
  const invIds = new Set();
  const ordIds = new Set();
  for (const row of details) {
    totals.qty += Number(row.qty || 0);
    totals.line_total += Number(row.line_total || 0);
    totals.line_gross += Number(row.line_gross || 0);
    totals.tax_amount += Number(row.tax_amount || 0);
    const docId = Number(row.invoice_id || 0);
    if (docId > 0) {
      if (row.doc_type === 'order') ordIds.add(docId);
      else invIds.add(docId);
    }
  }
  totals.line_count = details.length;
  totals.invoice_count = invIds.size;
  totals.order_count = ordIds.size;
  totals.doc_count = invIds.size + ordIds.size;
  return totals;
}

function orderStatusLabel(st) {
  const s = String(st || '').toLowerCase();
  if (s === 'approved' || s === 'posted') return 'معتمد';
  if (s === 'draft') return 'مسودة';
  if (s === 'pending') return 'معلّق';
  return st || '—';
}

/** طلبات شراء العملاء — بنود + ملخص (نفس بنية المبيعات) */
async function reportCustomerOrdersDetailed(filters = {}) {
  const r = dateRange(filters.from, filters.to);
  const groupBy = normalizeSalesDetailedGroupBy(filters.group_by);
  const empty = {
    summary: [],
    details: [],
    totals: computeDetailedTotals([]),
    group_by: groupBy,
    source: 'orders',
  };
  if (!r.from || !r.to) return empty;

  const customerId = Number(filters.customer_id || 0) || 0;
  const salesRepId = Number(filters.sales_rep_id || 0) || 0;
  const regionId = Number(filters.region_id || 0) || 0;
  const categoryId = Number(filters.category_id || 0) || 0;
  const itemId = Number(filters.item_id || 0) || 0;
  const warehouseId = Number(filters.warehouse_id || 0) || 0;
  const approvedOnly = String(filters.posted_only || '') === '1' || filters.posted_only === true;

  const where = ['o.order_date BETWEEN ? AND ?', 'IFNULL(o.is_sent, 1) = 1'];
  const params = [r.from, r.to];
  if (customerId > 0) {
    where.push('o.customer_id = ?');
    params.push(customerId);
  }
  if (salesRepId > 0) {
    where.push('COALESCE(o.sales_rep_id, c.sales_rep_id) = ?');
    params.push(salesRepId);
  }
  if (regionId > 0) {
    where.push('c.region_id = ?');
    params.push(regionId);
  }
  if (categoryId > 0) {
    where.push('it.category_id = ?');
    params.push(categoryId);
  }
  if (itemId > 0) {
    where.push('l.item_id = ?');
    params.push(itemId);
  }
  if (warehouseId > 0) {
    where.push('o.warehouse_id = ?');
    params.push(warehouseId);
  }
  if (approvedOnly) {
    where.push(`o.status IN ('approved','posted')`);
  }

  const limit = Math.min(5000, Math.max(100, Number(filters.limit || 3000)));
  let rows = [];
  try {
    rows = await safeQuery(
      `SELECT o.id AS invoice_id, o.order_no AS invoice_no, o.order_date AS invoice_date, o.status AS payment_type,
              c.id AS customer_id, c.code AS customer_code, c.name_ar AS customer_name,
              COALESCE(sr.id, 0) AS sales_rep_id,
              COALESCE(sr.name_ar, '') AS sales_rep_name,
              COALESCE(sr.code, '') AS sales_rep_code,
              COALESCE(rg.id, 0) AS region_id,
              COALESCE(rg.name_ar, '') AS region_name,
              COALESCE(w.id, 0) AS warehouse_id,
              COALESCE(w.name_ar, '') AS warehouse_name,
              it.id AS item_id,
              COALESCE(NULLIF(TRIM(it.sku), ''), it.barcode, '') AS item_sku,
              COALESCE(NULLIF(TRIM(l.line_desc), ''), it.name_ar, '') AS item_name,
              COALESCE(cat.id, 0) AS category_id,
              COALESCE(cat.name_ar, '') AS category_name,
              l.qty, COALESCE(l.unit_price, 0) AS unit_price, COALESCE(l.discount_pct, 0) AS discount_pct,
              COALESCE(l.line_total, 0) AS line_total,
              COALESCE(l.tax_amount, 0) AS tax_amount,
              COALESCE(l.line_gross, l.line_total, 0) AS line_gross
       FROM sal_customer_order_line l
       INNER JOIN sal_customer_order o ON o.id = l.order_id
       INNER JOIN crm_customer c ON c.id = o.customer_id
       LEFT JOIN crm_sales_rep sr ON sr.id = COALESCE(o.sales_rep_id, c.sales_rep_id)
       LEFT JOIN crm_region rg ON rg.id = c.region_id
       LEFT JOIN inv_warehouse w ON w.id = o.warehouse_id
       INNER JOIN inv_item it ON it.id = l.item_id
       LEFT JOIN inv_item_category cat ON cat.id = it.category_id
       WHERE ${where.join(' AND ')}
       ORDER BY o.order_date ASC, o.id ASC, l.line_no ASC
       LIMIT ${limit}`,
      params
    );
  } catch (_) {
    return empty;
  }

  if (!rows.length) return empty;

  const details = rows.map((row) => ({
    doc_type: 'order',
    doc_label: 'طلب',
    invoice_id: Number(row.invoice_id || 0),
    invoice_no: row.invoice_no || '',
    invoice_date: row.invoice_date || '',
    payment_type: orderStatusLabel(row.payment_type),
    customer_id: Number(row.customer_id || 0),
    customer_code: row.customer_code || '',
    customer_name: row.customer_name || '',
    sales_rep_id: Number(row.sales_rep_id || 0),
    sales_rep_name: row.sales_rep_name || '',
    sales_rep_code: row.sales_rep_code || '',
    region_id: Number(row.region_id || 0),
    region_name: row.region_name || '',
    warehouse_id: Number(row.warehouse_id || 0),
    warehouse_name: row.warehouse_name || '',
    item_id: Number(row.item_id || 0),
    item_sku: row.item_sku || '',
    item_name: row.item_name || '',
    category_id: Number(row.category_id || 0),
    category_name: row.category_name || '',
    qty: Number(row.qty || 0),
    unit_price: Number(row.unit_price || 0),
    discount_pct: Number(row.discount_pct || 0),
    line_total: Number(row.line_total || 0),
    line_gross: Number(row.line_gross || 0),
    tax_amount: Number(row.tax_amount || 0),
  }));

  return {
    summary: buildSalesDetailedSummary(details, groupBy),
    details,
    totals: computeDetailedTotals(details),
    group_by: groupBy,
    source: 'orders',
  };
}

function tagSalesDetails(details) {
  return details.map((d) => ({ ...d, doc_type: 'sales', doc_label: 'فاتورة' }));
}

async function reportCombinedDetailed(filters = {}) {
  const source = normalizeDetailedSource(filters.source);
  const groupBy = normalizeSalesDetailedGroupBy(filters.group_by);
  if (source === 'sales') {
    const data = await reportSalesDetailed(filters);
    return {
      ...data,
      details: tagSalesDetails(data.details),
      totals: computeDetailedTotals(tagSalesDetails(data.details)),
      source: 'sales',
    };
  }
  if (source === 'orders') {
    return reportCustomerOrdersDetailed(filters);
  }
  const [salesRaw, ordersRaw] = await Promise.all([
    reportSalesDetailed(filters),
    reportCustomerOrdersDetailed(filters),
  ]);
  const details = [...tagSalesDetails(salesRaw.details), ...ordersRaw.details];
  return {
    summary: buildSalesDetailedSummary(details, groupBy),
    details,
    totals: computeDetailedTotals(details),
    group_by: groupBy,
    source: 'both',
    sales_totals: computeDetailedTotals(tagSalesDetails(salesRaw.details)),
    orders_totals: ordersRaw.totals,
  };
}

async function listCustomersSimple() {
  return safeQuery(
    `SELECT id, code, name_ar FROM crm_customer WHERE is_active = 1 ORDER BY name_ar LIMIT 800`
  );
}

async function listRegionsSimple() {
  return safeQuery(`SELECT id, name_ar FROM crm_region WHERE is_active = 1 ORDER BY name_ar LIMIT 500`);
}

async function listCategoriesSimple() {
  return safeQuery(`SELECT id, name_ar FROM inv_item_category WHERE is_active = 1 ORDER BY name_ar LIMIT 500`);
}

async function listWarehousesSimple() {
  return safeQuery(`SELECT id, name_ar FROM inv_warehouse WHERE is_active = 1 ORDER BY name_ar LIMIT 500`);
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
  reportCustomerOrdersByItem,
  reportDelivery,
  reportReturns,
  reportReturnsTotals,
  reportSalesDetailed,
  reportCustomerOrdersDetailed,
  reportCombinedDetailed,
  listRegionsSimple,
  listCategoriesSimple,
  listWarehousesSimple,
  listCustomersSimple,
  dateRange,
};
