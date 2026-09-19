'use strict';

const db = require('../db');

async function safeQuery(sql, params = []) {
  try {
    return await db.query(sql, params);
  } catch (e) {
    console.error('main kpi', e.message);
    return [];
  }
}

function monthStart() {
  const d = new Date();
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-01`;
}

function today() {
  return new Date().toISOString().slice(0, 10);
}

async function scalar(sql, params = []) {
  const rows = await safeQuery(sql, params);
  if (!rows[0]) return 0;
  return Object.values(rows[0])[0] || 0;
}

async function salesKpis() {
  const ms = monthStart();
  return {
    count_month: await scalar(
      `SELECT COUNT(*) c FROM sal_invoice WHERE status='confirmed' AND invoice_date >= ?`,
      [ms]
    ),
    total_month: await scalar(
      `SELECT COALESCE(SUM(total),0) s FROM sal_invoice WHERE status='confirmed' AND invoice_date >= ?`,
      [ms]
    ),
    total_all: await scalar(
      `SELECT COALESCE(SUM(total),0) s FROM sal_invoice WHERE status='confirmed'`
    ),
    count_all: await scalar(`SELECT COUNT(*) c FROM sal_invoice WHERE status='confirmed'`),
  };
}

async function purchaseKpis() {
  const ms = monthStart();
  return {
    count_month: await scalar(
      `SELECT COUNT(*) c FROM pur_invoice WHERE status='confirmed' AND invoice_date >= ?`,
      [ms]
    ),
    total_month: await scalar(
      `SELECT COALESCE(SUM(total),0) s FROM pur_invoice WHERE status='confirmed' AND invoice_date >= ?`,
      [ms]
    ),
  };
}

async function journalDaily() {
  const t = today();
  return {
    count: await scalar(`SELECT COUNT(*) c FROM acc_journal_entry WHERE entry_date = ?`, [t]),
    posted: await scalar(
      `SELECT COUNT(*) c FROM acc_journal_entry WHERE entry_date = ? AND status IN ('posted','ترحيل','مرحّل')`,
      [t]
    ),
  };
}

async function cashflowKpis() {
  const ms = monthStart();
  return {
    receipts: await scalar(
      `SELECT COALESCE(SUM(amount),0) s FROM fin_voucher
       WHERE voucher_type='receipt' AND (is_cancelled=0 OR is_cancelled IS NULL) AND voucher_date >= ?`,
      [ms]
    ),
    payments: await scalar(
      `SELECT COALESCE(SUM(amount),0) s FROM fin_voucher
       WHERE voucher_type='payment' AND (is_cancelled=0 OR is_cancelled IS NULL) AND voucher_date >= ?`,
      [ms]
    ),
  };
}

async function receivablesList(limit = 40) {
  return safeQuery(
    `SELECT i.id, i.invoice_no, i.invoice_date, i.total, c.name_ar AS party_name
     FROM sal_invoice i
     INNER JOIN crm_customer c ON c.id = i.customer_id
     WHERE i.status='confirmed' AND i.payment_type='credit'
     ORDER BY i.invoice_date DESC LIMIT ${Math.min(100, limit)}`
  );
}

async function payablesList(limit = 40) {
  return safeQuery(
    `SELECT i.id, i.invoice_no, i.invoice_date, i.total, s.name_ar AS party_name
     FROM pur_invoice i
     INNER JOIN crm_supplier s ON s.id = i.supplier_id
     WHERE i.status='confirmed' AND i.payment_type='credit'
     ORDER BY i.invoice_date DESC LIMIT ${Math.min(100, limit)}`
  );
}

async function recentSales(limit = 15) {
  return safeQuery(
    `SELECT i.id, i.invoice_no, i.invoice_date, i.total, c.name_ar AS customer_name
     FROM sal_invoice i
     INNER JOIN crm_customer c ON c.id = i.customer_id
     WHERE i.status='confirmed'
     ORDER BY i.id DESC LIMIT ${Math.min(50, limit)}`
  );
}

async function checksKpis() {
  return {
    incoming: await scalar(
      `SELECT COUNT(*) c FROM fin_voucher WHERE voucher_type='receipt'
       AND (payment_method='check' OR pay_method='check' OR IFNULL(check_no,'')<>'')
       AND (is_cancelled=0 OR is_cancelled IS NULL)`
    ),
    outgoing: await scalar(
      `SELECT COUNT(*) c FROM fin_voucher WHERE voucher_type='payment'
       AND (payment_method='check' OR pay_method='check' OR IFNULL(check_no,'')<>'')
       AND (is_cancelled=0 OR is_cancelled IS NULL)`
    ),
  };
}

async function treasuryRows() {
  return safeQuery(
    `SELECT a.code, a.name_ar, a.account_type
     FROM sys_dashboard_account d
     INNER JOIN acc_account a ON a.id = d.account_id
     WHERE d.is_visible = 1
     ORDER BY a.code
     LIMIT 50`
  );
}

module.exports = {
  salesKpis,
  purchaseKpis,
  journalDaily,
  cashflowKpis,
  receivablesList,
  payablesList,
  recentSales,
  checksKpis,
  treasuryRows,
};
