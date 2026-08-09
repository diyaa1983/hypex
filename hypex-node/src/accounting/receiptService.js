'use strict';

const { spawn } = require('child_process');
const path = require('path');
const fs = require('fs');
const db = require('../db');
const config = require('../config');

const TYPE = 'receipt';

function hypexRoot() {
  // hypex-node/src/accounting → ../../..
  return path.resolve(__dirname, '..', '..', '..');
}

function phpBin() {
  if (process.env.PHP_BIN) return process.env.PHP_BIN;
  const candidates = [
    'C:\\xampp\\php\\php.exe',
    'C:\\xampp\\php\\php',
    'php',
  ];
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
    console.error('receipt', e.message);
    return [];
  }
}

/**
 * تشغيل إجراء PHP (ترحيل/حفظ/حذف…) بنفس منطق النظام القديم
 * @returns {Promise<{ok:boolean, error?:string, message?:string, voucher_id?:number, voucher_no?:string, is_posted?:boolean}>}
 */
function phpAction(action, userId, payload = {}) {
  return new Promise((resolve) => {
    const script = path.join(__dirname, '..', '..', 'cli', 'fin_receipt_action.php');
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
    `SELECT c.id, c.code, c.name_ar, c.sales_rep_id, r.name_ar AS sales_rep_name
     FROM crm_customer c
     LEFT JOIN crm_sales_rep r ON r.id = c.sales_rep_id AND r.is_active = 1
     WHERE c.is_active = 1
     ORDER BY c.name_ar
     LIMIT 3000`
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

  let customer_name = '';
  let sales_rep_name = '';
  let customer_code = '';
  if (String(row.party_type || '') === 'customer' && Number(row.party_id) > 0) {
    const c = await safe(
      `SELECT c.name_ar, c.code, r.name_ar AS sales_rep_name
       FROM crm_customer c
       LEFT JOIN crm_sales_rep r ON r.id = c.sales_rep_id AND r.is_active = 1
       WHERE c.id = ? LIMIT 1`,
      [row.party_id]
    );
    if (c[0]) {
      customer_name = String(c[0].name_ar || '');
      customer_code = String(c[0].code || '');
      sales_rep_name = String(c[0].sales_rep_name || '');
    }
  }

  let checks = [];
  if (String(row.pay_method || '') === 'check') {
    checks = await safe(
      `SELECT id, sort_order, check_no, bank_name, check_amount, due_date, notes
       FROM fin_voucher_check WHERE voucher_id = ? ORDER BY sort_order ASC, id ASC`,
      [n]
    );
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
    customer_name,
    customer_code,
    sales_rep_name,
    checks,
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
    `v.voucher_type = 'receipt'`,
    `(v.is_posted = 0 OR v.is_posted IS NULL)`,
    `(v.is_cancelled = 0 OR v.is_cancelled IS NULL)`,
  ];
  const params = [];
  if (q) {
    where.push(`(v.voucher_no LIKE ? OR IFNULL(v.description,'') LIKE ? OR IFNULL(c.name_ar,'') LIKE ?)`);
    const like = `%${q}%`;
    params.push(like, like, like);
  }
  return safe(
    `SELECT v.id, v.voucher_no, v.voucher_date, v.amount, v.description, v.is_posted, v.is_cancelled,
            c.name_ar AS customer_name
     FROM fin_voucher v
     LEFT JOIN crm_customer c ON c.id = v.party_id AND v.party_type = 'customer'
     WHERE ${where.join(' AND ')}
     ORDER BY v.id DESC
     LIMIT ${Math.min(300, limit)}`,
    params
  );
}

function parseChecksFromBody(body) {
  const checks = [];
  const raw = body.checks;
  if (Array.isArray(raw)) {
    for (const c of raw) {
      if (!c || typeof c !== 'object') continue;
      checks.push({
        check_no: c.check_no,
        bank_name: c.bank_name,
        check_amount: c.check_amount,
        due_date: c.due_date,
        notes: c.notes,
      });
    }
    if (checks.length) return checks;
  }
  if (raw && typeof raw === 'object') {
    for (const k of Object.keys(raw).sort((a, b) => Number(a) - Number(b))) {
      const c = raw[k];
      if (!c || typeof c !== 'object') continue;
      checks.push({
        check_no: c.check_no,
        bank_name: c.bank_name,
        check_amount: c.check_amount,
        due_date: c.due_date,
        notes: c.notes,
      });
    }
    if (checks.length) return checks;
  }
  // express.urlencoded extended:false → checks[0][check_no]
  const map = new Map();
  for (const [k, v] of Object.entries(body || {})) {
    const m = String(k).match(/^checks\[(\d+)\]\[(\w+)\]$/);
    if (!m) continue;
    const i = Number(m[1]);
    if (!map.has(i)) map.set(i, {});
    map.get(i)[m[2]] = v;
  }
  const idxs = [...map.keys()].sort((a, b) => a - b);
  for (const i of idxs) {
    const c = map.get(i);
    checks.push({
      check_no: c.check_no,
      bank_name: c.bank_name,
      check_amount: c.check_amount,
      due_date: c.due_date,
      notes: c.notes,
    });
  }
  return checks;
}

module.exports = {
  phpAction,
  listCustomers,
  getVoucher,
  findByNo,
  listUnposted,
  parseChecksFromBody,
  phpBaseUrl: () => config.phpBaseUrl,
};
