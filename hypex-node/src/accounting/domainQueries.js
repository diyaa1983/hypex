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
    console.error('accounting query', e.message);
    return [];
  }
}

function dateRange(from, to) {
  return {
    from: parseDateToIso(from || '', monthStart()),
    to: parseDateToIso(to || '', todayIso()),
  };
}

async function listAccounts({ q = '', activeOnly = true } = {}) {
  const where = ['1=1'];
  const params = [];
  if (activeOnly) where.push('a.is_active = 1');
  if (q) {
    const like = `%${q}%`;
    where.push(`(a.code LIKE ? OR a.name_ar LIKE ?)`);
    params.push(like, like);
  }
  return safeQuery(
    `SELECT a.id, a.code, a.name_ar, a.account_type, a.is_leaf, a.is_active, a.parent_id,
            p.code AS parent_code, p.name_ar AS parent_name
     FROM acc_account a
     LEFT JOIN acc_account p ON p.id = a.parent_id
     WHERE ${where.join(' AND ')}
     ORDER BY a.code ASC
     LIMIT 500`,
    params
  );
}

async function listVouchers({ type = '', q = '', unpostedOnly = false, cancelledOnly = false, from, to, limit = 120 } = {}) {
  const where = ['1=1'];
  const params = [];
  if (type) {
    where.push('v.voucher_type = ?');
    params.push(type);
  }
  if (unpostedOnly) where.push('(v.is_posted = 0 OR v.is_posted IS NULL) AND (v.is_cancelled = 0 OR v.is_cancelled IS NULL)');
  if (cancelledOnly) where.push('v.is_cancelled = 1');
  if (from && to) {
    where.push('v.voucher_date BETWEEN ? AND ?');
    params.push(from, to);
  }
  if (q) {
    const like = `%${q}%`;
    where.push(`(v.voucher_no LIKE ? OR IFNULL(v.description,'') LIKE ? OR IFNULL(v.check_no,'') LIKE ?)`);
    params.push(like, like, like);
  }
  return safeQuery(
    `SELECT v.id, v.voucher_type, v.voucher_no, v.voucher_date, v.amount, v.description,
            v.is_posted, v.is_cancelled, v.payment_method, v.pay_method, v.check_no
     FROM fin_voucher v
     WHERE ${where.join(' AND ')}
     ORDER BY v.id DESC
     LIMIT ${Math.min(300, limit)}`,
    params
  );
}

async function listJournals({ q = '', from, to } = {}) {
  const where = ['1=1'];
  const params = [];
  if (from && to) {
    where.push('e.entry_date BETWEEN ? AND ?');
    params.push(from, to);
  }
  if (q) {
    const like = `%${q}%`;
    where.push(`(e.entry_no LIKE ? OR IFNULL(e.description_ar,'') LIKE ?)`);
    params.push(like, like);
  }
  return safeQuery(
    `SELECT e.id, e.entry_no, e.entry_date, e.description_ar, e.status, e.ref_type, e.source
     FROM acc_journal_entry e
     WHERE ${where.join(' AND ')}
     ORDER BY e.id DESC
     LIMIT 150`,
    params
  );
}

async function listDebitNotes({ q = '' } = {}) {
  const params = [];
  let extra = '';
  if (q) {
    extra =
      ' WHERE (n.note_no LIKE ? OR IFNULL(n.reason,"") LIKE ? OR IFNULL(c.name_ar,"") LIKE ? OR IFNULL(s.name_ar,"") LIKE ?)';
    const like = `%${q}%`;
    params.push(like, like, like, like);
  }
  return safeQuery(
    `SELECT n.id, n.note_no AS doc_no, n.note_date AS doc_date, n.total, n.reason,
            n.party_type, n.party_id,
            CASE n.party_type
              WHEN 'customer' THEN c.name_ar
              WHEN 'supplier' THEN s.name_ar
              ELSE NULL
            END AS party_name
     FROM fin_debit_note n
     LEFT JOIN crm_customer c ON c.id = n.party_id AND n.party_type = 'customer'
     LEFT JOIN crm_supplier s ON s.id = n.party_id AND n.party_type = 'supplier'
     ${extra}
     ORDER BY n.id DESC LIMIT 150`,
    params
  );
}

async function listCreditNotes({ q = '' } = {}) {
  const params = [];
  let extra = '';
  if (q) {
    extra =
      ' WHERE (n.note_no LIKE ? OR IFNULL(n.reason,"") LIKE ? OR IFNULL(c.name_ar,"") LIKE ? OR IFNULL(s.name_ar,"") LIKE ?)';
    const like = `%${q}%`;
    params.push(like, like, like, like);
  }
  return safeQuery(
    `SELECT n.id, n.note_no AS doc_no, n.note_date AS doc_date, n.total, n.reason,
            n.party_type, n.party_id,
            CASE n.party_type
              WHEN 'customer' THEN c.name_ar
              WHEN 'supplier' THEN s.name_ar
              ELSE NULL
            END AS party_name
     FROM fin_credit_note n
     LEFT JOIN crm_customer c ON c.id = n.party_id AND n.party_type = 'customer'
     LEFT JOIN crm_supplier s ON s.id = n.party_id AND n.party_type = 'supplier'
     ${extra}
     ORDER BY n.id DESC LIMIT 150`,
    params
  );
}

async function listPrivateChecks({ q = '' } = {}) {
  const params = [];
  let extra = '';
  if (q) {
    extra = ' WHERE (c.check_no LIKE ? OR IFNULL(c.beneficiary,"") LIKE ? OR IFNULL(c.entry_no,"") LIKE ?)';
    params.push(`%${q}%`, `%${q}%`, `%${q}%`);
  }
  return safeQuery(
    `SELECT c.id, c.entry_no, c.check_no, c.bank_name, c.check_amount, c.due_date, c.beneficiary, c.status
     FROM fin_private_out_check c${extra}
     ORDER BY c.id DESC LIMIT 100`,
    params
  );
}

async function listCheckVouchers({ direction = 'in', q = '' } = {}) {
  // الشيكات عبر fin_voucher + pay_method/check
  const where = [`(v.payment_method = 'check' OR v.pay_method = 'check' OR IFNULL(v.check_no,'') <> '')`];
  const params = [];
  if (direction === 'in') where.push(`v.voucher_type = 'receipt'`);
  if (direction === 'out') where.push(`v.voucher_type = 'payment'`);
  if (q) {
    const like = `%${q}%`;
    where.push(`(v.voucher_no LIKE ? OR IFNULL(v.check_no,'') LIKE ? OR IFNULL(v.bank_name,'') LIKE ?)`);
    params.push(like, like, like);
  }
  return safeQuery(
    `SELECT v.id, v.voucher_no, v.voucher_date, v.amount, v.check_no, v.bank_name,
            v.is_posted, v.is_cancelled, v.description, v.voucher_type
     FROM fin_voucher v
     WHERE ${where.join(' AND ')}
     ORDER BY v.id DESC LIMIT 150`,
    params
  );
}

module.exports = {
  listAccounts,
  listVouchers,
  listJournals,
  listDebitNotes,
  listCreditNotes,
  listPrivateChecks,
  listCheckVouchers,
  dateRange,
};
