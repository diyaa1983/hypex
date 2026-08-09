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
    console.error('sales-reps query', e.message);
    return [];
  }
}

function dateRange(from, to) {
  return { from: from || monthStart(), to: to || todayIso() };
}

async function listReps({ q = '', activeOnly = true } = {}) {
  const where = ['1=1'];
  const params = [];
  if (activeOnly) where.push('r.is_active = 1');
  if (q) {
    const like = `%${q}%`;
    where.push(`(r.name_ar LIKE ? OR IFNULL(r.code,'') LIKE ? OR IFNULL(r.phone,'') LIKE ?)`);
    params.push(like, like, like);
  }
  const rows = await safeQuery(
    `SELECT r.id, r.code, r.name_ar, r.phone, r.address_ar, r.is_active, r.warehouse_id,
            w.name_ar AS warehouse_name,
            (SELECT COUNT(*) FROM crm_customer c WHERE c.sales_rep_id = r.id) AS customer_count
     FROM crm_sales_rep r
     LEFT JOIN inv_warehouse w ON w.id = r.warehouse_id
     WHERE ${where.join(' AND ')}
     ORDER BY r.name_ar ASC
     LIMIT 300`,
    params
  );
  // enrich with m2m links if table exists
  try {
    for (const r of rows) {
      const extra = await safeQuery(
        `SELECT COUNT(*) AS c FROM crm_customer_sales_rep WHERE sales_rep_id = ?`,
        [r.id]
      );
      const base = Number(r.customer_count || 0);
      const m2m = Number(extra[0]?.c || 0);
      r.customer_count = Math.max(base, m2m);
    }
  } catch {
    /* optional */
  }
  return rows;
}

async function listRepsSimple() {
  return safeQuery(
    `SELECT id, code, name_ar FROM crm_sales_rep WHERE is_active = 1 ORDER BY name_ar LIMIT 300`
  );
}

/** تجميع فواتير حسب المندوب */
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

/** تفصيل فواتير مندوب واحد (مثل PHP) */
async function reportSalesByRepDetail(salesRepId, from, to) {
  const r = dateRange(from, to);
  const rid = Number(salesRepId);
  if (rid < 1) return [];
  return safeQuery(
    `SELECT i.id, i.invoice_no, i.invoice_date, i.subtotal, i.tax_amount, i.total, i.payment_type,
            c.code AS customer_code, c.name_ar AS customer_name
     FROM sal_invoice i
     INNER JOIN crm_customer c ON c.id = i.customer_id
     LEFT JOIN crm_sales_rep sr ON sr.id = COALESCE(i.sales_rep_id, c.sales_rep_id)
     WHERE i.status = 'confirmed'
       AND i.invoice_date BETWEEN ? AND ?
       AND COALESCE(i.sales_rep_id, c.sales_rep_id) = ?
     ORDER BY i.invoice_date ASC, i.id ASC
     LIMIT 2000`,
    [r.from, r.to, rid]
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

module.exports = {
  listReps,
  listRepsSimple,
  reportSalesByRepSummary,
  reportSalesByRepDetail,
  reportSalesByRegion,
  dateRange,
};
