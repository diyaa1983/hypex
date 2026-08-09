'use strict';

const { spawn } = require('child_process');
const path = require('path');
const fs = require('fs');
const db = require('../db');

const TYPE = 'payment';

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
    console.error('payment', e.message);
    return [];
  }
}

function phpAction(action, userId, payload = {}) {
  return new Promise((resolve) => {
    const script = path.join(__dirname, '..', '..', 'cli', 'fin_payment_action.php');
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

async function listCustomers() {
  return safe(
    `SELECT c.id, c.code, c.name_ar FROM crm_customer c
     WHERE c.is_active = 1 ORDER BY c.name_ar LIMIT 3000`
  );
}

async function listSuppliers() {
  return safe(
    `SELECT id, code, name_ar FROM crm_supplier
     WHERE is_active = 1 OR is_active IS NULL
     ORDER BY name_ar LIMIT 3000`
  );
}

async function listEmployees() {
  return safe(
    `SELECT id, emp_code, name_ar FROM hr_employee
     WHERE is_active = 1
       AND (resignation_date IS NULL OR resignation_date = 0 OR resignation_date < '1000-01-01')
     ORDER BY name_ar LIMIT 1500`
  );
}

async function listCashAccounts() {
  // تقريب مجموعة حسابات الصرف (صناديق/بنوك/شيكات) — التصفية الدقيقة في PHP عند الحفظ
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

async function listOffsetAccounts() {
  return safe(
    `SELECT id, code, name_ar, account_type FROM acc_account
     WHERE is_active = 1 AND is_leaf = 1
       AND account_type IN ('liability','expense')
     ORDER BY account_type ASC, code ASC LIMIT 500`
  );
}

async function getVoucher(id) {
  const n = Number(id);
  if (n < 1) return null;
  const rows = await safe(`SELECT * FROM fin_voucher WHERE id = ? AND voucher_type = ? LIMIT 1`, [
    n,
    TYPE,
  ]);
  const row = rows[0];
  if (!row) return null;

  let party_name = '';
  let party_code = '';
  const pt = String(row.party_type || '');
  const pid = Number(row.party_id || 0);
  if (pt === 'customer' && pid > 0) {
    const c = await safe(`SELECT code, name_ar FROM crm_customer WHERE id = ? LIMIT 1`, [pid]);
    if (c[0]) {
      party_code = String(c[0].code || '');
      party_name = String(c[0].name_ar || '');
    }
  } else if (pt === 'supplier' && pid > 0) {
    const s = await safe(`SELECT code, name_ar FROM crm_supplier WHERE id = ? LIMIT 1`, [pid]);
    if (s[0]) {
      party_code = String(s[0].code || '');
      party_name = String(s[0].name_ar || '');
    }
  } else if (pt === 'employee' && pid > 0) {
    const e = await safe(`SELECT emp_code AS code, name_ar FROM hr_employee WHERE id = ? LIMIT 1`, [
      pid,
    ]);
    if (e[0]) {
      party_code = String(e[0].code || '');
      party_name = String(e[0].name_ar || '');
    }
  } else if (pt === 'account') {
    const aid = Number(row.offset_account_id || row.party_id || 0);
    if (aid > 0) {
      const a = await safe(`SELECT code, name_ar FROM acc_account WHERE id = ? LIMIT 1`, [aid]);
      if (a[0]) {
        party_code = String(a[0].code || '');
        party_name = String(a[0].name_ar || '');
      }
    }
  }

  let check_due_date = '';
  if (String(row.pay_method || '') === 'check') {
    const chk = await safe(
      `SELECT due_date FROM fin_voucher_check WHERE voucher_id = ? ORDER BY sort_order, id LIMIT 1`,
      [n]
    );
    if (chk[0]) check_due_date = String(chk[0].due_date || '').slice(0, 10);
  }

  const prev = await safe(
    `SELECT id FROM fin_voucher WHERE voucher_type = ? AND id < ? ORDER BY id DESC LIMIT 1`,
    [TYPE, n]
  );
  const next = await safe(
    `SELECT id FROM fin_voucher WHERE voucher_type = ? AND id > ? ORDER BY id ASC LIMIT 1`,
    [TYPE, n]
  );

  return {
    ...row,
    party_name,
    party_code,
    hr_advance_id: Number(row.hr_advance_id || 0) || 0,
    check_due_date,
    prev_id: prev[0] ? Number(prev[0].id) : 0,
    next_id: next[0] ? Number(next[0].id) : 0,
    is_posted: Number(row.is_posted) === 1 ? 1 : 0,
    is_cancelled: Number(row.is_cancelled) === 1 ? 1 : 0,
  };
}

async function findByNo(no) {
  const s = String(no || '').trim();
  if (!s) return null;
  const exact = await safe(
    `SELECT id FROM fin_voucher WHERE voucher_type = ? AND voucher_no = ? LIMIT 1`,
    [TYPE, s]
  );
  if (exact[0]) return getVoucher(exact[0].id);
  const frag = await safe(
    `SELECT id FROM fin_voucher WHERE voucher_type = ? AND voucher_no LIKE ?
     ORDER BY id DESC LIMIT 1`,
    [TYPE, '%' + s + '%']
  );
  if (frag[0]) return getVoucher(frag[0].id);
  return null;
}

async function listUnposted({ q = '', limit = 120 } = {}) {
  const where = [
    `v.voucher_type = 'payment'`,
    `(v.is_posted = 0 OR v.is_posted IS NULL)`,
    `(v.is_cancelled = 0 OR v.is_cancelled IS NULL)`,
  ];
  const params = [];
  if (q) {
    where.push(
      `(v.voucher_no LIKE ? OR IFNULL(v.description,'') LIKE ?
        OR IFNULL(c.name_ar,'') LIKE ? OR IFNULL(s.name_ar,'') LIKE ?)`
    );
    const like = `%${q}%`;
    params.push(like, like, like, like);
  }
  return safe(
    `SELECT v.id, v.voucher_no, v.voucher_date, v.amount, v.description, v.is_posted, v.is_cancelled,
            v.party_type,
            COALESCE(c.name_ar, s.name_ar, e.name_ar, '') AS party_name
     FROM fin_voucher v
     LEFT JOIN crm_customer c ON c.id = v.party_id AND v.party_type = 'customer'
     LEFT JOIN crm_supplier s ON s.id = v.party_id AND v.party_type = 'supplier'
     LEFT JOIN hr_employee e ON e.id = v.party_id AND v.party_type = 'employee'
     WHERE ${where.join(' AND ')}
     ORDER BY v.id DESC
     LIMIT ${Math.min(300, limit)}`,
    params
  );
}

module.exports = {
  phpAction,
  listCustomers,
  listSuppliers,
  listEmployees,
  listCashAccounts,
  listOffsetAccounts,
  getVoucher,
  findByNo,
  listUnposted,
};
