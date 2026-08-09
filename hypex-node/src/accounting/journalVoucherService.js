'use strict';

const { spawn } = require('child_process');
const path = require('path');
const fs = require('fs');
const db = require('../db');

function hypexRoot() {
  return path.resolve(__dirname, '..', '..', '..');
}

function phpBin() {
  if (process.env.PHP_BIN) return process.env.PHP_BIN;
  const candidates = ['C:\\xampp\\php\\php.exe', 'C:\\xampp\\php\\php', 'php'];
  for (const c of candidates) {
    if (c === 'php') return c;
    if (fs.existsSync(c)) return c;
  }
  return 'php';
}

async function q(sql, params = []) {
  return db.query(sql, params);
}

async function safe(sql, params = []) {
  try {
    return await q(sql, params);
  } catch (e) {
    console.error('journal voucher', e.message);
    return [];
  }
}

function phpAction(action, userId, payload = {}) {
  return new Promise((resolve) => {
    const script = path.join(__dirname, '..', '..', 'cli', 'fin_journal_action.php');
    if (!fs.existsSync(script)) {
      return resolve({ ok: false, error: 'سكربت CLI غير موجود: ' + script });
    }
    const child = spawn(phpBin(), [script, action, String(userId || 0)], {
      cwd: hypexRoot(),
      windowsHide: true,
    });
    let out = '';
    let err = '';
    child.stdout.on('data', (d) => {
      out += String(d);
    });
    child.stderr.on('data', (d) => {
      err += String(d);
    });
    child.on('error', (e) => {
      resolve({ ok: false, error: 'تعذر تشغيل PHP: ' + (e.message || '') });
    });
    child.on('close', () => {
      const line = out
        .split(/\r?\n/)
        .map((s) => s.trim())
        .filter(Boolean)
        .pop();
      if (!line) {
        return resolve({
          ok: false,
          error: err.trim() || 'لا استجابة من PHP (تحقق من PHP_BIN ومسار XAMPP).',
        });
      }
      try {
        resolve(JSON.parse(line));
      } catch {
        resolve({ ok: false, error: 'استجابة PHP غير صالحة: ' + line.slice(0, 200) });
      }
    });
    child.stdin.write(JSON.stringify(payload || {}));
    child.stdin.end();
  });
}

async function tableReady() {
  try {
    await q(`SELECT id FROM acc_journal_entry LIMIT 1`);
    await q(`SELECT id FROM acc_journal_line LIMIT 1`);
    return true;
  } catch {
    return false;
  }
}

async function hasPartyColumns() {
  const rows = await safe(
    `SELECT 1 AS ok FROM information_schema.columns
     WHERE table_schema = DATABASE() AND table_name = 'acc_journal_line' AND column_name = 'party_type'
     LIMIT 1`
  );
  return rows.length > 0;
}

async function listLeafAccounts() {
  return safe(
    `SELECT a.id, a.code, a.name_ar, a.account_type
     FROM acc_account a
     WHERE a.is_active = 1
       AND (
         a.is_leaf = 1
         OR NOT EXISTS (
           SELECT 1 FROM acc_account c WHERE c.parent_id = a.id AND c.is_active = 1
         )
       )
     ORDER BY a.code ASC
     LIMIT 5000`
  );
}

async function listCustomers() {
  return safe(
    `SELECT id, code, name_ar FROM crm_customer
     WHERE is_active = 1 ORDER BY name_ar LIMIT 3000`
  );
}

async function listSuppliers() {
  return safe(
    `SELECT id, code, name_ar FROM crm_supplier
     WHERE is_active = 1 OR is_active IS NULL
     ORDER BY name_ar LIMIT 3000`
  );
}

async function partyArApIds() {
  const rows = await safe(
    `SELECT rule_code, account_id FROM acc_posting_setting
     WHERE rule_code IN ('ar_customers', 'ap_suppliers')`
  );
  let ar = 0;
  let ap = 0;
  for (const r of rows) {
    if (r.rule_code === 'ar_customers') ar = Number(r.account_id || 0);
    if (r.rule_code === 'ap_suppliers') ap = Number(r.account_id || 0);
  }
  return { ar, ap };
}

async function neighbor(id, dir) {
  const n = Number(id) || 0;
  if (n < 1) return 0;
  const manual = `COALESCE(source, 'manual') = 'manual'`;
  const rows =
    dir === 'prev'
      ? await safe(
          `SELECT id FROM acc_journal_entry WHERE id < ? AND ${manual} ORDER BY id DESC LIMIT 1`,
          [n]
        )
      : await safe(
          `SELECT id FROM acc_journal_entry WHERE id > ? AND ${manual} ORDER BY id ASC LIMIT 1`,
          [n]
        );
  return rows[0] ? Number(rows[0].id) : 0;
}

async function wasEverPosted(id) {
  const n = Number(id) || 0;
  if (n < 1) return false;
  const st = await safe(`SELECT status FROM acc_journal_entry WHERE id = ? LIMIT 1`, [n]);
  const status = String(st[0]?.status || '');
  if (status === 'posted' || status === 'cancelled') return true;
  const audit = await safe(
    `SELECT 1 AS ok FROM sys_audit_log
     WHERE screen_code = 'journal_voucher' AND entity_id = ? AND action_code = 'post'
     LIMIT 1`,
    [n]
  );
  return audit.length > 0;
}

function statusLabel(status) {
  return { draft: 'مسودة', posted: 'مرحّل', cancelled: 'ملغى' }[status] || status;
}

async function getEntry(id) {
  const n = Number(id) || 0;
  if (n < 1) return null;
  const headers = await safe(`SELECT * FROM acc_journal_entry WHERE id = ? LIMIT 1`, [n]);
  const header = headers[0];
  if (!header) return null;

  const source = String(header.source || 'manual') || 'manual';
  const isManual = source === 'manual';
  const status = String(header.status || 'draft');
  const hasParty = await hasPartyColumns();

  let linesSql = hasParty
    ? `SELECT l.id, l.account_id, l.debit, l.credit, l.memo, l.party_type, l.party_id,
              a.code AS account_code, a.name_ar AS account_name,
              c.name_ar AS customer_name, c.code AS customer_code,
              s.name_ar AS supplier_name, s.code AS supplier_code
       FROM acc_journal_line l
       INNER JOIN acc_account a ON a.id = l.account_id
       LEFT JOIN crm_customer c ON l.party_type = 'customer' AND c.id = l.party_id
       LEFT JOIN crm_supplier s ON l.party_type = 'supplier' AND s.id = l.party_id
       WHERE l.journal_id = ?
       ORDER BY l.id ASC`
    : `SELECT l.id, l.account_id, l.debit, l.credit, l.memo,
              a.code AS account_code, a.name_ar AS account_name
       FROM acc_journal_line l
       INNER JOIN acc_account a ON a.id = l.account_id
       WHERE l.journal_id = ?
       ORDER BY l.id ASC`;

  const rawLines = await safe(linesSql, [n]);
  const lines = rawLines.map((ln) => {
    const partyType = String(ln.party_type || '');
    let partyName = '';
    let partyCode = '';
    if (partyType === 'customer') {
      partyName = String(ln.customer_name || '');
      partyCode = String(ln.customer_code || '');
    } else if (partyType === 'supplier') {
      partyName = String(ln.supplier_name || '');
      partyCode = String(ln.supplier_code || '');
    }
    return {
      id: Number(ln.id || 0),
      account_id: Number(ln.account_id || 0),
      account_code: String(ln.account_code || ''),
      account_name: String(ln.account_name || ''),
      debit: Number(ln.debit || 0),
      credit: Number(ln.credit || 0),
      memo: String(ln.memo || ''),
      party_type: partyType,
      party_id: Number(ln.party_id || 0),
      party_name: partyName,
      party_code: partyCode,
    };
  });

  const [prevId, nextId, noDelete] = await Promise.all([
    isManual ? neighbor(n, 'prev') : Promise.resolve(0),
    isManual ? neighbor(n, 'next') : Promise.resolve(0),
    wasEverPosted(n),
  ]);

  return {
    id: n,
    entry_no: String(header.entry_no || ''),
    entry_date: String(header.entry_date || '').slice(0, 10),
    description_ar: String(header.description_ar || ''),
    status,
    status_label: statusLabel(status),
    source,
    is_manual: isManual,
    is_editable: isManual && status === 'draft',
    can_edit_unlock: isManual && status === 'posted',
    is_cancelled: status === 'cancelled',
    is_posted: status === 'posted',
    no_delete: noDelete || status === 'cancelled',
    lines,
    prev_id: prevId,
    next_id: nextId,
  };
}

async function findByNo(no) {
  const s = String(no || '').trim();
  if (!s) return null;
  const exact = await safe(
    `SELECT id FROM acc_journal_entry
     WHERE entry_no = ? AND COALESCE(source, 'manual') = 'manual'
     LIMIT 1`,
    [s]
  );
  if (exact[0]) return getEntry(exact[0].id);

  const frag = await safe(
    `SELECT id FROM acc_journal_entry
     WHERE entry_no LIKE ? AND COALESCE(source, 'manual') = 'manual'
     ORDER BY entry_no ASC, id ASC LIMIT 1`,
    ['%' + s + '%']
  );
  if (frag[0]) return getEntry(frag[0].id);

  const any = await safe(`SELECT id, source FROM acc_journal_entry WHERE entry_no = ? LIMIT 1`, [s]);
  if (any[0] && String(any[0].source || 'manual') !== 'manual') {
    return {
      _auto: true,
      message:
        'رقم القيد يخص قيداً تلقائياً. عدّله من شاشة المستند الأصلي وليس من سند القيد.',
    };
  }
  return null;
}

module.exports = {
  phpAction,
  tableReady,
  listLeafAccounts,
  listCustomers,
  listSuppliers,
  partyArApIds,
  getEntry,
  findByNo,
};
