'use strict';

const db = require('../db');

async function q(sql, params = []) {
  return db.query(sql, params);
}

async function safe(sql, params = []) {
  try {
    return await q(sql, params);
  } catch (e) {
    console.error('advances', e.message);
    return [];
  }
}

async function hasColumn(table, column) {
  const rows = await safe(
    `SELECT 1 AS ok FROM information_schema.columns
     WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?
     LIMIT 1`,
    [table, column]
  );
  return rows.length > 0;
}

function typeLabel(type) {
  return String(type || '') === 'long' ? 'سلفة طويلة' : 'سلفة لمرة واحدة';
}

function periodLabel(startIso, endIso) {
  const start = String(startIso || '').slice(0, 10);
  const end = String(endIso || '').slice(0, 10);
  if (!start) return '—';
  const m = start.match(/^(\d{4})-(\d{2})/);
  if (m) {
    const base = 'شهر ' + Number(m[2]) + ' / ' + m[1];
    return end && end !== start ? base + ' (أقساط)' : base;
  }
  if (end && end !== start) return start + ' → ' + end;
  return start;
}

async function tableReady() {
  try {
    await q(`SELECT id FROM hr_employee_advance LIMIT 1`);
    return true;
  } catch {
    return false;
  }
}

async function postReady() {
  return hasColumn('hr_employee_advance', 'is_posted');
}

/**
 * @returns {Promise<Array<object>>}
 */
async function listPendingDisbursement() {
  if (!(await tableReady()) || !(await postReady())) return [];

  const hasDisb = await hasColumn('hr_employee_advance', 'disbursement_voucher_id');
  const disbursedFilter = hasDisb
    ? ' AND (a.disbursement_voucher_id IS NULL OR a.disbursement_voucher_id = 0)'
    : '';

  const rows = await safe(
    `SELECT a.id, a.advance_code, a.advance_type, a.total_amount, a.start_date, a.end_date, a.notes,
            a.posted_at, a.employee_id, e.emp_code, e.name_ar AS emp_name
     FROM hr_employee_advance a
     INNER JOIN hr_employee e ON e.id = a.employee_id
     WHERE COALESCE(a.is_posted, 0) = 1
       ${disbursedFilter}
       AND COALESCE(NULLIF(TRIM(a.status), ''), 'active') <> 'cancelled'
     ORDER BY COALESCE(a.posted_at, a.created_at) DESC, a.id DESC
     LIMIT 500`
  );

  return rows.map((row) => {
    const startIso = String(row.start_date || '').slice(0, 10);
    const endIso = String(row.end_date || '').slice(0, 10);
    return {
      id: Number(row.id || 0),
      employee_id: Number(row.employee_id || 0),
      emp_code: String(row.emp_code || ''),
      emp_name: String(row.emp_name || ''),
      advance_code: String(row.advance_code || ''),
      advance_type: String(row.advance_type || ''),
      advance_type_label: typeLabel(row.advance_type),
      total_amount: Number(row.total_amount || 0),
      start_date: startIso,
      end_date: endIso,
      period_label: periodLabel(startIso, endIso),
      posted_at: String(row.posted_at || ''),
      notes: String(row.notes || ''),
    };
  });
}

async function listCashAccounts() {
  const rows = await safe(
    `SELECT a.id, a.code, a.name_ar
     FROM acc_account a
     WHERE a.is_active = 1 AND a.is_leaf = 1
       AND (
         a.code LIKE '1001%'
         OR a.name_ar LIKE '%صندوق%'
         OR a.name_ar LIKE '%بنك%'
         OR a.name_ar LIKE '%نقد%'
         OR a.name_ar LIKE '%شيك%'
       )
     ORDER BY a.code ASC LIMIT 300`
  );
  if (rows.length) return rows;
  return safe(
    `SELECT id, code, name_ar FROM acc_account
     WHERE is_active = 1 AND is_leaf = 1 ORDER BY code ASC LIMIT 200`
  );
}

async function defaultCashAccountId(accounts) {
  const list = accounts || [];
  const cashLike = list.find(
    (a) =>
      String(a.name_ar || '').includes('صندوق') || String(a.code || '').startsWith('1001')
  );
  if (cashLike) return Number(cashLike.id);
  return list[0] ? Number(list[0].id) : 0;
}

async function payableAccountLabel() {
  const rows = await safe(
    `SELECT a.id, a.code, a.name_ar
     FROM acc_posting_setting ps
     INNER JOIN acc_account a ON a.id = ps.account_id
     WHERE ps.rule_code = 'hr_employee_advance_payable'
     LIMIT 1`
  );
  if (rows[0]) {
    return (
      String(rows[0].code || '').trim() +
      ' — ' +
      String(rows[0].name_ar || rows[0].label || 'سلف موظفين مستحقة الصرف').trim()
    );
  }
  return '2009 — سلف موظفين مستحقة الصرف';
}

/**
 * @returns {Promise<object|null>}
 */
async function getDisburseBootstrap(advanceId, cashAccountId = 0) {
  const id = Number(advanceId) || 0;
  if (id < 1) return null;
  if (!(await tableReady()) || !(await postReady())) return null;

  const hasDisb = await hasColumn('hr_employee_advance', 'disbursement_voucher_id');
  const disbSelect = hasDisb ? ', a.disbursement_voucher_id' : '';

  const rows = await safe(
    `SELECT a.id, a.advance_code, a.advance_type, a.total_amount, a.employee_id,
            a.is_posted, a.status, a.notes${disbSelect},
            e.emp_code, e.name_ar AS emp_name
     FROM hr_employee_advance a
     INNER JOIN hr_employee e ON e.id = a.employee_id
     WHERE a.id = ?
     LIMIT 1`,
    [id]
  );
  const adv = rows[0];
  if (!adv) return null;
  if (Number(adv.is_posted || 0) !== 1) return null;
  if (String(adv.status || 'active').trim() === 'cancelled') return null;
  if (hasDisb && Number(adv.disbursement_voucher_id || 0) > 0) return null;

  const employeeId = Number(adv.employee_id || 0);
  if (employeeId < 1) return null;

  let cashId = Number(cashAccountId) || 0;
  if (cashId > 0) {
    const cash = await listCashAccounts();
    if (!cash.some((c) => Number(c.id) === cashId)) cashId = 0;
  }

  const payableRows = await safe(
    `SELECT a.id, a.code, a.name_ar
     FROM acc_posting_setting ps
     INNER JOIN acc_account a ON a.id = ps.account_id
     WHERE ps.rule_code = 'hr_employee_advance_payable'
     LIMIT 1`
  );
  const payableId = payableRows[0] ? Number(payableRows[0].id) : 0;
  const payableLabel = payableRows[0]
    ? String(payableRows[0].code || '').trim() +
      ' — ' +
      String(payableRows[0].name_ar || '').trim()
    : '';

  const code = String(adv.advance_code || '').trim() || String(id);
  const empName = String(adv.emp_name || '').trim();
  const tLabel = typeLabel(adv.advance_type);
  const memo =
    'سلفة رقم ' +
    code +
    (empName ? ' باسم الموظف ' + empName : '') +
    (tLabel ? ' (' + tLabel + ')' : '');

  return {
    advance_id: id,
    employee_id: employeeId,
    emp_code: String(adv.emp_code || '').trim(),
    emp_name: empName,
    advance_code: code,
    advance_type_label: tLabel,
    amount: Number(adv.total_amount || 0),
    payable_account_id: payableId,
    payable_account_label: payableLabel,
    cash_account_id: cashId,
    notes: memo,
    lock_hr_fields: true,
  };
}

module.exports = {
  tableReady,
  listPendingDisbursement,
  listCashAccounts,
  defaultCashAccountId,
  payableAccountLabel,
  getDisburseBootstrap,
};
