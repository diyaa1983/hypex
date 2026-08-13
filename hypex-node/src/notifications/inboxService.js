'use strict';

const db = require('../db');
const { userCan } = require('../auth');
const { invoicesService } = (() => {
  try {
    return { invoicesService: require('../sales/invoicesService') };
  } catch {
    return { invoicesService: null };
  }
})();

const SALE_INV_POSTED =
  invoicesService && invoicesService.POSTED_SQL
    ? invoicesService.POSTED_SQL
    : `(
  EXISTS (SELECT 1 FROM crm_customer_ledger l WHERE l.txn_type = 'sale_invoice' AND l.ref_id = i.id)
  OR EXISTS (SELECT 1 FROM inv_stock_move m WHERE m.ref_type = 'sale_invoice' AND m.ref_id = i.id)
)`;

const SALE_RET_POSTED = `(
  EXISTS (SELECT 1 FROM crm_customer_ledger l WHERE l.txn_type = 'sale_return' AND l.ref_id = r.id)
  OR EXISTS (SELECT 1 FROM inv_stock_move m WHERE m.ref_type = 'sale_return' AND m.ref_id = r.id)
)`;

const PUR_INV_POSTED = `(
  EXISTS (SELECT 1 FROM crm_supplier_ledger l WHERE l.txn_type IN ('purchase_invoice','pur_invoice','purchase') AND l.ref_id = i.id)
  OR EXISTS (SELECT 1 FROM inv_stock_move m WHERE m.ref_type IN ('purchase_invoice','pur_invoice') AND m.ref_id = i.id)
)`;

const PUR_RET_POSTED = `(
  EXISTS (SELECT 1 FROM crm_supplier_ledger l WHERE l.txn_type IN ('purchase_return','pur_return') AND l.ref_id = r.id)
  OR EXISTS (SELECT 1 FROM inv_stock_move m WHERE m.ref_type IN ('purchase_return','pur_return') AND m.ref_id = r.id)
)`;

const PER_KIND = 8;
const PANEL_MAX = 20;
const LIST_LIMIT = 20;

const tableCache = new Map();
const columnCache = new Map();

function emptyPayload() {
  return {
    enabled: false,
    alert_checks: [],
    delivery_alerts: [],
    unposted_alerts: [],
    customer_order_alerts: [],
    visit_checkout_alerts: [],
    summary: {
      total: 0,
      overdue: 0,
      today: 0,
      soon: 0,
      alert_count: 0,
      delivery_count: 0,
      unposted_count: 0,
      customer_order_count: 0,
      visit_checkout_count: 0,
    },
    soon_days: 7,
  };
}

function canAny(user, codes) {
  return codes.some((c) => userCan(user, c));
}

async function tableExists(name) {
  if (tableCache.has(name)) return tableCache.get(name);
  try {
    const rows = await db.query(
      `SELECT 1 AS ok FROM information_schema.tables
       WHERE table_schema = DATABASE() AND table_name = ? LIMIT 1`,
      [name]
    );
    const ok = !!(rows && rows[0]);
    tableCache.set(name, ok);
    return ok;
  } catch {
    tableCache.set(name, false);
    return false;
  }
}

async function columnExists(table, column) {
  const key = `${table}.${column}`;
  if (columnCache.has(key)) return columnCache.get(key);
  try {
    const rows = await db.query(
      `SELECT 1 AS ok FROM information_schema.columns
       WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?
       LIMIT 1`,
      [table, column]
    );
    const ok = !!(rows && rows[0]);
    columnCache.set(key, ok);
    return ok;
  } catch {
    columnCache.set(key, false);
    return false;
  }
}

async function safeQuery(sql, params = []) {
  try {
    return await db.query(sql, params);
  } catch {
    return [];
  }
}

async function safeCount(sql, params = []) {
  const rows = await safeQuery(sql, params);
  return Number(rows[0] && (rows[0].c ?? rows[0]['COUNT(*)'] ?? Object.values(rows[0])[0])) || 0;
}

function fmtDate(iso) {
  const s = String(iso || '').slice(0, 10);
  if (!/^\d{4}-\d{2}-\d{2}$/.test(s)) return String(iso || '—');
  const [y, m, d] = s.split('-');
  return `${d}/${m}/${y}`;
}

function mapUnposted(rows, kind, typeLabel, urlFor) {
  return (rows || []).map((r) => ({
    id: Number(r.id),
    kind,
    type_label: typeLabel,
    doc_no: String(r.doc_no || ''),
    doc_date: String(r.doc_date || '').slice(0, 10),
    doc_date_fmt: fmtDate(r.doc_date),
    amount: Number(r.amount || 0),
    party_name: String(r.party_name || ''),
    url: urlFor(Number(r.id)),
  }));
}

async function collectCustomerOrders(user) {
  if (!userCan(user, 'sales_customer_orders_approve')) return { items: [], count: 0 };
  if (!(await tableExists('sal_customer_order'))) return { items: [], count: 0 };
  const count = await safeCount(
    `SELECT COUNT(*) AS c FROM sal_customer_order WHERE status IN ('draft','pending')`
  );
  const rows = await safeQuery(
    `SELECT o.id, o.order_no, o.order_date, c.name_ar AS customer_name,
            COALESCE(r.name_ar, '') AS sales_rep_name
     FROM sal_customer_order o
     INNER JOIN crm_customer c ON c.id = o.customer_id
     LEFT JOIN crm_sales_rep r ON r.id = o.sales_rep_id
     WHERE o.status IN ('draft','pending')
     ORDER BY o.order_date ASC, o.id ASC
     LIMIT ${LIST_LIMIT}`
  );
  const items = rows.map((row) => ({
    id: Number(row.id),
    order_no: String(row.order_no || ''),
    order_date: String(row.order_date || '').slice(0, 10),
    order_date_fmt: fmtDate(row.order_date),
    customer_name: String(row.customer_name || ''),
    sales_rep_name: String(row.sales_rep_name || ''),
    url: `/sales/orders/${Number(row.id)}`,
    urgency_label: 'بانتظار الاعتماد',
    type_label: 'طلب شراء عميل',
  }));
  return { items, count: count || items.length };
}

async function collectVisitCheckout(user) {
  if (!userCan(user, 'sales_rep_visit_checkout_approve') && !user.is_admin) {
    return { items: [], count: 0 };
  }
  if (!(await tableExists('sal_rep_visit_checkout_request'))) return { items: [], count: 0 };
  const count = await safeCount(
    `SELECT COUNT(*) AS c FROM sal_rep_visit_checkout_request WHERE status = 'pending'`
  );
  const rows = await safeQuery(
    `SELECT q.id, q.created_at, q.reason,
            c.name_ar AS customer_name, c.code AS customer_code,
            COALESCE(sr.name_ar,'') AS sales_rep_name
     FROM sal_rep_visit_checkout_request q
     INNER JOIN crm_customer c ON c.id = q.customer_id
     LEFT JOIN crm_sales_rep sr ON sr.id = q.sales_rep_id
     WHERE q.status = 'pending'
     ORDER BY q.created_at ASC, q.id ASC
     LIMIT ${LIST_LIMIT}`
  );
  const items = rows.map((row) => ({
    id: Number(row.id),
    customer_name: String(row.customer_name || ''),
    customer_code: String(row.customer_code || ''),
    sales_rep_name: String(row.sales_rep_name || ''),
    reason: String(row.reason || ''),
    created_at: String(row.created_at || ''),
    url: `/sales-reps/visit-checkout-approve?status=pending`,
    urgency_label: 'بانتظار اعتماد الخروج',
    type_label: 'خروج يدوي من زيارة',
  }));
  return { items, count: count || items.length };
}

async function collectUnposted(user) {
  const items = [];
  let count = 0;

  if (canAny(user, ['sales_invoices', 'sales_invoices_list']) && (await tableExists('sal_invoice'))) {
    const rows = await safeQuery(
      `SELECT i.id, i.invoice_no AS doc_no, i.invoice_date AS doc_date, i.total AS amount,
              c.name_ar AS party_name
       FROM sal_invoice i
       INNER JOIN crm_customer c ON c.id = i.customer_id
       WHERE i.status = 'confirmed' AND NOT ${SALE_INV_POSTED}
       ORDER BY i.invoice_date ASC, i.id ASC
       LIMIT ${PER_KIND}`
    );
    items.push(...mapUnposted(rows, 'sale_invoice', 'فاتورة بيع', (id) => `/sales/invoices/${id}`));
    count += await safeCount(
      `SELECT COUNT(*) AS c FROM sal_invoice i
       WHERE i.status = 'confirmed' AND NOT ${SALE_INV_POSTED}`
    );
  }

  if (canAny(user, ['sales_returns', 'sales_returns_list']) && (await tableExists('sal_return'))) {
    const rows = await safeQuery(
      `SELECT r.id, r.return_no AS doc_no, r.return_date AS doc_date, r.total AS amount,
              c.name_ar AS party_name
       FROM sal_return r
       INNER JOIN crm_customer c ON c.id = r.customer_id
       WHERE r.status <> 'cancelled' AND NOT ${SALE_RET_POSTED}
       ORDER BY r.return_date ASC, r.id ASC
       LIMIT ${PER_KIND}`
    );
    items.push(...mapUnposted(rows, 'sale_return', 'مردود مبيعات', (id) => `/sales/returns/${id}`));
    count += await safeCount(
      `SELECT COUNT(*) AS c FROM sal_return r
       WHERE r.status <> 'cancelled' AND NOT ${SALE_RET_POSTED}`
    );
  }

  if (
    canAny(user, ['purchase_invoices', 'purchase_invoices_list']) &&
    (await tableExists('pur_invoice'))
  ) {
    const rows = await safeQuery(
      `SELECT i.id, i.invoice_no AS doc_no, i.invoice_date AS doc_date, i.total AS amount,
              s.name_ar AS party_name
       FROM pur_invoice i
       INNER JOIN crm_supplier s ON s.id = i.supplier_id
       WHERE i.status = 'confirmed' AND NOT ${PUR_INV_POSTED}
       ORDER BY i.invoice_date ASC, i.id ASC
       LIMIT ${PER_KIND}`
    );
    items.push(
      ...mapUnposted(rows, 'purchase_invoice', 'فاتورة شراء', (id) => `/purchases/invoices/${id}`)
    );
    count += await safeCount(
      `SELECT COUNT(*) AS c FROM pur_invoice i
       WHERE i.status = 'confirmed' AND NOT ${PUR_INV_POSTED}`
    );
  }

  if (
    canAny(user, ['purchase_returns', 'purchase_returns_list']) &&
    (await tableExists('pur_return'))
  ) {
    const rows = await safeQuery(
      `SELECT r.id, r.return_no AS doc_no, r.return_date AS doc_date, r.total AS amount,
              s.name_ar AS party_name
       FROM pur_return r
       INNER JOIN crm_supplier s ON s.id = r.supplier_id
       WHERE r.status = 'confirmed' AND NOT ${PUR_RET_POSTED}
       ORDER BY r.return_date ASC, r.id ASC
       LIMIT ${PER_KIND}`
    );
    items.push(
      ...mapUnposted(rows, 'purchase_return', 'مردود مشتريات', (id) => `/purchases/returns/${id}`)
    );
    count += await safeCount(
      `SELECT COUNT(*) AS c FROM pur_return r
       WHERE r.status = 'confirmed' AND NOT ${PUR_RET_POSTED}`
    );
  }

  if (canAny(user, ['cash_receipt', 'cash_receipts_list']) && (await tableExists('fin_voucher'))) {
    const cancelSql = (await columnExists('fin_voucher', 'is_cancelled'))
      ? `AND (v.is_cancelled = 0 OR v.is_cancelled IS NULL)`
      : '';
    const rows = await safeQuery(
      `SELECT v.id, v.voucher_no AS doc_no, v.voucher_date AS doc_date, v.amount,
              COALESCE(c.name_ar, s.name_ar, '') AS party_name
       FROM fin_voucher v
       LEFT JOIN crm_customer c ON v.party_type = 'customer' AND v.party_id = c.id
       LEFT JOIN crm_supplier s ON v.party_type = 'supplier' AND v.party_id = s.id
       WHERE v.voucher_type = 'receipt'
         AND (v.is_posted = 0 OR v.is_posted IS NULL)
         ${cancelSql}
       ORDER BY v.voucher_date ASC, v.id ASC
       LIMIT ${PER_KIND}`
    );
    items.push(
      ...mapUnposted(
        rows,
        'cash_receipt',
        'سند قبض',
        (id) => `/accounting/receipts/entry?id=${id}`
      )
    );
    count += await safeCount(
      `SELECT COUNT(*) AS c FROM fin_voucher v
       WHERE v.voucher_type = 'receipt'
         AND (v.is_posted = 0 OR v.is_posted IS NULL)
         ${cancelSql}`
    );
  }

  if (canAny(user, ['cash_payment', 'cash_payments_list']) && (await tableExists('fin_voucher'))) {
    const cancelSql = (await columnExists('fin_voucher', 'is_cancelled'))
      ? `AND (v.is_cancelled = 0 OR v.is_cancelled IS NULL)`
      : '';
    const rows = await safeQuery(
      `SELECT v.id, v.voucher_no AS doc_no, v.voucher_date AS doc_date, v.amount,
              COALESCE(c.name_ar, s.name_ar, '') AS party_name
       FROM fin_voucher v
       LEFT JOIN crm_customer c ON v.party_type = 'customer' AND v.party_id = c.id
       LEFT JOIN crm_supplier s ON v.party_type = 'supplier' AND v.party_id = s.id
       WHERE v.voucher_type = 'payment'
         AND (v.is_posted = 0 OR v.is_posted IS NULL)
         ${cancelSql}
       ORDER BY v.voucher_date ASC, v.id ASC
       LIMIT ${PER_KIND}`
    );
    items.push(
      ...mapUnposted(
        rows,
        'cash_payment',
        'سند صرف',
        (id) => `/accounting/payments/entry?id=${id}`
      )
    );
    count += await safeCount(
      `SELECT COUNT(*) AS c FROM fin_voucher v
       WHERE v.voucher_type = 'payment'
         AND (v.is_posted = 0 OR v.is_posted IS NULL)
         ${cancelSql}`
    );
  }

  if (userCan(user, 'journal_entries') && (await tableExists('acc_journal_entry'))) {
    const rows = await safeQuery(
      `SELECT e.id, e.entry_no AS doc_no, e.entry_date AS doc_date, 0 AS amount,
              COALESCE(e.description_ar, '') AS party_name
       FROM acc_journal_entry e
       WHERE e.status = 'draft' AND COALESCE(e.source, 'manual') = 'manual'
       ORDER BY e.entry_date ASC, e.id ASC
       LIMIT ${PER_KIND}`
    );
    items.push(
      ...mapUnposted(rows, 'journal_entry', 'قيد يومية', (id) => `/accounting/journals?id=${id}`)
    );
    count += await safeCount(
      `SELECT COUNT(*) AS c FROM acc_journal_entry e
       WHERE e.status = 'draft' AND COALESCE(e.source, 'manual') = 'manual'`
    );
  }

  if (userCan(user, 'sales_delivery') && (await tableExists('sal_delivery'))) {
    const rows = await safeQuery(
      `SELECT d.id, d.delivery_no AS doc_no, d.delivery_date AS doc_date, 0 AS amount,
              c.name_ar AS party_name
       FROM sal_delivery d
       INNER JOIN crm_customer c ON c.id = d.customer_id
       WHERE d.is_posted = 0
         AND EXISTS (SELECT 1 FROM sal_delivery_line dl WHERE dl.delivery_id = d.id)
       ORDER BY d.delivery_date ASC, d.id ASC
       LIMIT ${PER_KIND}`
    );
    items.push(
      ...mapUnposted(rows, 'sales_delivery', 'سند تسليم', (id) => `/sales/delivery/${id}`)
    );
    count += await safeCount(
      `SELECT COUNT(*) AS c FROM sal_delivery d
       WHERE d.is_posted = 0
         AND EXISTS (SELECT 1 FROM sal_delivery_line dl WHERE dl.delivery_id = d.id)`
    );
  }

  items.sort((a, b) => {
    const d = String(a.doc_date).localeCompare(String(b.doc_date));
    if (d !== 0) return d;
    return String(a.kind).localeCompare(String(b.kind));
  });

  return { items: items.slice(0, PANEL_MAX), count: count || items.length };
}

async function collectDueChecks(user) {
  if (!canAny(user, ['cash_receipt', 'cash_receipts_list', 'fin_checks'])) {
    return { items: [], summary: { total: 0, overdue: 0, today: 0, soon: 0 } };
  }
  if (!(await tableExists('fin_voucher_check')) || !(await tableExists('fin_voucher'))) {
    return { items: [], summary: { total: 0, overdue: 0, today: 0, soon: 0 } };
  }

  const hasLifecycle = await columnExists('fin_voucher_check', 'lifecycle_status');
  const hasCancel = await columnExists('fin_voucher', 'is_cancelled');
  const lifecycleSql = hasLifecycle
    ? `AND (c.lifecycle_status = 'pending' OR c.lifecycle_status IS NULL OR c.lifecycle_status = '')`
    : '';
  const cancelSql = hasCancel ? `AND (v.is_cancelled = 0 OR v.is_cancelled IS NULL)` : '';

  const rows = await safeQuery(
    `SELECT c.id AS check_id, c.check_no, c.bank_name, c.check_amount, c.due_date,
            v.id AS voucher_id, v.voucher_no,
            COALESCE(cust.name_ar, '') AS party_name
     FROM fin_voucher_check c
     INNER JOIN fin_voucher v ON v.id = c.voucher_id AND v.voucher_type = 'receipt'
     LEFT JOIN crm_customer cust ON v.party_type = 'customer' AND v.party_id = cust.id
     WHERE v.is_posted = 1
       AND c.check_amount > 0
       ${lifecycleSql}
       ${cancelSql}
     ORDER BY c.due_date ASC, c.id ASC
     LIMIT 80`
  );

  const today = new Date();
  today.setHours(0, 0, 0, 0);
  const summary = { total: 0, overdue: 0, today: 0, soon: 0 };
  const items = [];

  for (const row of rows) {
    const due = String(row.due_date || '').slice(0, 10);
    let urgency = 'pending';
    let urgencyLabel = 'قيد التحصيل';
    if (/^\d{4}-\d{2}-\d{2}$/.test(due)) {
      const dueDt = new Date(due + 'T00:00:00');
      const days = Math.round((dueDt - today) / 86400000);
      if (days < 0) {
        urgency = 'overdue';
        urgencyLabel = 'متأخر';
        summary.overdue++;
      } else if (days === 0) {
        urgency = 'today';
        urgencyLabel = 'مستحق اليوم';
        summary.today++;
      } else if (days <= 7) {
        urgency = 'soon';
        urgencyLabel = 'قريب الاستحقاق';
        summary.soon++;
      } else {
        continue;
      }
    } else {
      continue;
    }
    summary.total++;
    items.push({
      check_id: Number(row.check_id),
      check_no: String(row.check_no || ''),
      bank_name: String(row.bank_name || ''),
      check_amount: Number(row.check_amount || 0),
      due_date: due,
      due_date_fmt: fmtDate(due),
      voucher_no: String(row.voucher_no || ''),
      party_name: String(row.party_name || ''),
      url: `/accounting/checks-in`,
      urgency,
      urgency_label: urgencyLabel,
    });
  }

  return { items, summary };
}

async function collectDeliveries(user) {
  if (!canAny(user, ['sales_invoices', 'sales_delivery'])) return { items: [], count: 0 };
  if (!(await tableExists('sal_delivery'))) return { items: [], count: 0 };
  const rows = await safeQuery(
    `SELECT d.id, d.delivery_no, d.delivery_date, c.name_ar AS customer_name
     FROM sal_delivery d
     INNER JOIN crm_customer c ON c.id = d.customer_id
     WHERE d.is_posted = 1
       AND NOT EXISTS (SELECT 1 FROM sal_invoice i WHERE i.delivery_id = d.id)
     ORDER BY d.delivery_date ASC, d.id ASC
     LIMIT 8`
  );
  const items = rows.map((r) => ({
    id: Number(r.id),
    delivery_no: String(r.delivery_no || ''),
    delivery_date: String(r.delivery_date || '').slice(0, 10),
    delivery_date_fmt: fmtDate(r.delivery_date),
    customer_name: String(r.customer_name || ''),
    url: `/sales/delivery/${Number(r.id)}`,
    urgency_label: 'بلا فاتورة',
  }));
  return { items, count: items.length };
}

function userCanSeeBell(user) {
  return (
    canAny(user, [
      'cash_receipt',
      'cash_receipts_list',
      'cash_payment',
      'cash_payments_list',
      'sales_invoices',
      'sales_invoices_list',
      'sales_returns',
      'sales_returns_list',
      'purchase_invoices',
      'purchase_invoices_list',
      'purchase_returns',
      'purchase_returns_list',
      'journal_entries',
      'warehouse_moves',
      'inventory_stocktake',
      'sales_delivery',
      'sales_customer_orders_approve',
      'sales_rep_visit_checkout_approve',
      'fin_checks',
    ]) || !!user.is_admin
  );
}

async function collectInbox(user) {
  const empty = emptyPayload();
  if (!user || !userCanSeeBell(user)) return empty;

  const [orders, visits, unposted, checks, deliveries] = await Promise.all([
    collectCustomerOrders(user),
    collectVisitCheckout(user),
    collectUnposted(user),
    collectDueChecks(user),
    collectDeliveries(user),
  ]);

  const summary = {
    ...empty.summary,
    ...checks.summary,
    delivery_count: deliveries.count,
    unposted_count: unposted.count,
    customer_order_count: orders.count,
    visit_checkout_count: visits.count,
  };
  summary.alert_count =
    checks.items.length +
    summary.delivery_count +
    summary.unposted_count +
    summary.customer_order_count +
    summary.visit_checkout_count;

  return {
    enabled: true,
    alert_checks: checks.items,
    delivery_alerts: deliveries.items,
    unposted_alerts: unposted.items,
    customer_order_alerts: orders.items,
    visit_checkout_alerts: visits.items,
    summary,
    soon_days: 7,
  };
}

module.exports = {
  collectInbox,
  userCanSeeBell,
  emptyPayload,
};
