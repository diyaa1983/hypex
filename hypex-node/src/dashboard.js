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

async function safeRows(sql, params = []) {
  try {
    return await db.query(sql, params);
  } catch {
    return [];
  }
}

function mapOrderRows(rows) {
  return (rows || []).map((r) => ({
    id: Number(r.id),
    order_no: r.order_no,
    order_date: r.order_date,
    total: fmt(r.total),
    customer_name: r.customer_name || '—',
    status: r.status === 'approved' ? 'approved' : 'open',
    status_label: r.status === 'approved' ? 'معتمد' : 'مفتوح',
  }));
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
    ordersTotal,
    ordersOpen,
    ordersApproved,
    recentOrders,
    recentOpenOrders,
    recentApprovedOrders,
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
    safeRows(
      `SELECT i.id, i.invoice_no, i.invoice_date, i.total, c.name_ar AS customer_name
       FROM sal_invoice i
       INNER JOIN crm_customer c ON c.id = i.customer_id
       WHERE i.status = 'confirmed'
       ORDER BY i.id DESC
       LIMIT 8`
    ),
    safeScalar(`SELECT COUNT(*) AS c FROM sal_customer_order`),
    safeScalar(
      `SELECT COUNT(*) AS c FROM sal_customer_order
       WHERE status IS NULL OR status = '' OR status IN ('draft','pending','open')`
    ),
    safeScalar(
      `SELECT COUNT(*) AS c FROM sal_customer_order WHERE status = 'approved'`
    ),
    safeRows(
      `SELECT o.id, o.order_no, o.order_date, o.status, o.total, c.name_ar AS customer_name
       FROM sal_customer_order o
       LEFT JOIN crm_customer c ON c.id = o.customer_id
       ORDER BY o.id DESC
       LIMIT 8`
    ),
    safeRows(
      `SELECT o.id, o.order_no, o.order_date, o.status, o.total, c.name_ar AS customer_name
       FROM sal_customer_order o
       LEFT JOIN crm_customer c ON c.id = o.customer_id
       WHERE o.status IS NULL OR o.status = '' OR o.status IN ('draft','pending','open')
       ORDER BY o.id DESC
       LIMIT 8`
    ),
    safeRows(
      `SELECT o.id, o.order_no, o.order_date, o.status, o.total, c.name_ar AS customer_name
       FROM sal_customer_order o
       LEFT JOIN crm_customer c ON c.id = o.customer_id
       WHERE o.status = 'approved'
       ORDER BY o.id DESC
       LIMIT 8`
    ),
  ]);

  return {
    kpis: [
      { label: 'عملاء نشطون', value: String(customers), tone: 'primary', href: '/customers' },
      { label: 'مواد نشطة', value: String(items), tone: 'primary', href: '/inventory/items' },
      {
        label: 'فواتير هذا الشهر',
        value: String(salesMonthCount),
        hint: fmt(salesMonthTotal) + ' د.أ',
        tone: 'success',
        href: '/sales/invoices',
      },
      {
        label: 'فواتير بانتظار الترحيل',
        value: String(unpostedInvoices),
        tone: Number(unpostedInvoices) > 0 ? 'warn' : 'primary',
        href: '/sales/posting',
      },
      {
        label: 'طلبات شراء العملاء',
        value: String(ordersTotal),
        tone: 'primary',
        href: '/sales/orders',
      },
      {
        label: 'الطلبات المفتوحة',
        value: String(ordersOpen),
        tone: Number(ordersOpen) > 0 ? 'warn' : 'primary',
        href: '/sales/orders/approve',
      },
      {
        label: 'الطلبات المعتمدة',
        value: String(ordersApproved),
        tone: 'success',
        href: '/sales/orders/approved',
      },
    ],
    recent_sales: (recent || []).map((r) => ({
      id: Number(r.id),
      invoice_no: r.invoice_no,
      invoice_date: r.invoice_date,
      total: fmt(r.total),
      customer_name: r.customer_name,
    })),
    recent_orders: mapOrderRows(recentOrders),
    open_orders: mapOrderRows(recentOpenOrders),
    approved_orders: mapOrderRows(recentApprovedOrders),
  };
}

module.exports = { collectDashboard };
