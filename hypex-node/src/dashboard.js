'use strict';

const db = require('./db');

function fmt(n) {
  const x = Number(n) || 0;
  return x.toLocaleString('en-US', { minimumFractionDigits: 3, maximumFractionDigits: 3 });
}

async function safeScalar(sql, params = []) {
  try {
    const rows = await db.query(sql, params);
    if (!rows[0]) return 0;
    const v = Object.values(rows[0])[0];
    return v == null ? 0 : v;
  } catch {
    return 0;
  }
}

async function collectDashboard() {
  const now = new Date();
  const y = now.getFullYear();
  const m = String(now.getMonth() + 1).padStart(2, '0');
  const monthStart = `${y}-${m}-01`;

  const [
    salesMonthCount,
    salesMonthTotal,
    unpostedInvoices,
    customers,
    items,
    recent,
  ] = await Promise.all([
    safeScalar(
      `SELECT COUNT(*) AS c FROM sal_invoice
       WHERE status = 'confirmed' AND invoice_date >= ?`,
      [monthStart]
    ),
    safeScalar(
      `SELECT COALESCE(SUM(total), 0) AS s FROM sal_invoice
       WHERE status = 'confirmed' AND invoice_date >= ?`,
      [monthStart]
    ),
    safeScalar(
      `SELECT COUNT(*) AS c FROM sal_invoice i
       WHERE i.status = 'confirmed'
         AND COALESCE(i.is_posted, 0) = 0`
    ),
    safeScalar('SELECT COUNT(*) AS c FROM crm_customer WHERE is_active = 1'),
    safeScalar('SELECT COUNT(*) AS c FROM inv_item WHERE is_active = 1'),
    (async () => {
      try {
        return await db.query(
          `SELECT i.id, i.invoice_no, i.invoice_date, i.total, c.name_ar AS customer_name
           FROM sal_invoice i
           INNER JOIN crm_customer c ON c.id = i.customer_id
           WHERE i.status = 'confirmed'
           ORDER BY i.id DESC
           LIMIT 8`
        );
      } catch {
        return [];
      }
    })(),
  ]);

  return {
    kpis: [
      { label: 'عملاء نشطون', value: String(customers), tone: 'primary' },
      { label: 'مواد نشطة', value: String(items), tone: 'primary' },
      {
        label: 'فواتير هذا الشهر',
        value: String(salesMonthCount),
        hint: fmt(salesMonthTotal) + ' د.أ',
        tone: 'success',
      },
      {
        label: 'فواتير بانتظار الترحيل',
        value: String(unpostedInvoices),
        tone: Number(unpostedInvoices) > 0 ? 'warn' : 'primary',
      },
    ],
    recent_sales: (recent || []).map((r) => ({
      id: Number(r.id),
      invoice_no: r.invoice_no,
      invoice_date: r.invoice_date,
      total: fmt(r.total),
      customer_name: r.customer_name,
    })),
  };
}

module.exports = { collectDashboard };
