'use strict';

const fs = require('fs');
const path = require('path');
const { spawn } = require('child_process');
const db = require('../db');
const { parseDateToIso, todayIso } = require('../lib/html');

const POSTED_SQL = `(
  (
    EXISTS (SELECT 1 FROM crm_customer_ledger l WHERE l.txn_type = 'sale_return' AND l.ref_id = r.id)
    OR EXISTS (
      SELECT 1 FROM sal_return r_c
      INNER JOIN sal_invoice i_c ON i_c.id = r_c.invoice_id AND i_c.payment_type = 'cash'
      INNER JOIN crm_customer_ledger l_inv
        ON l_inv.txn_type = 'sale_invoice' AND l_inv.ref_id = i_c.id
        AND l_inv.debit > 0.000001 AND l_inv.credit < 0.000001
      WHERE r_c.id = r.id
    )
  )
  AND (
    NOT (
      r.warehouse_id IS NOT NULL AND EXISTS (
        SELECT 1 FROM sal_return_line rl
        INNER JOIN inv_item it ON it.id = rl.item_id
        WHERE rl.return_id = r.id AND it.track_inventory = 1
          AND (rl.qty + COALESCE(rl.qty_extra, 0)) > 0
      )
    )
    OR EXISTS (
      SELECT 1 FROM inv_stock_move m
      WHERE m.ref_type = 'sale_return' AND m.ref_id = r.id
    )
  )
)`;

function phpBin() {
  if (process.env.PHP_BIN) return process.env.PHP_BIN;
  for (const c of ['C:\\xampp\\php\\php.exe', 'C:\\xampp\\php\\php', 'php']) {
    if (c === 'php' || fs.existsSync(c)) return c;
  }
  return 'php';
}

function hypexRoot() {
  return path.resolve(__dirname, '..', '..', '..');
}

function phpArgs(script, action, userId) {
  const args = [];
  const ini = process.env.PHP_INI || 'C:\\xampp\\php\\php.ini';
  if (fs.existsSync(ini)) args.push('-c', ini);
  args.push(script, action, String(userId || 0));
  return args;
}

function phpAction(action, userId, payload = {}) {
  return new Promise((resolve) => {
    const script = path.join(__dirname, '..', '..', 'cli', 'sal_return_action.php');
    if (!fs.existsSync(script)) {
      return resolve({ ok: false, error: 'سكربت CLI غير موجود: ' + script });
    }
    const child = spawn(phpBin(), phpArgs(script, action, userId), {
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
        return resolve({ ok: false, error: err.trim() || 'لا استجابة من PHP.' });
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

async function listReturns({ q = '', filter = 'all', page = 1, pageSize = 50 } = {}) {
  const where = [`r.status <> 'cancelled'`];
  const params = [];
  if (filter === 'unposted') where.push(`NOT ${POSTED_SQL}`);
  else if (filter === 'posted') where.push(POSTED_SQL);
  if (q) {
    where.push(`(r.return_no LIKE ? OR c.name_ar LIKE ? OR c.code LIKE ? OR i.invoice_no LIKE ?)`);
    const like = `%${q}%`;
    params.push(like, like, like, like);
  }
  const whereSql = where.join(' AND ');
  const countRows = await db.query(
    `SELECT COUNT(*) AS c
     FROM sal_return r
     INNER JOIN crm_customer c ON c.id = r.customer_id
     INNER JOIN sal_invoice i ON i.id = r.invoice_id
     WHERE ${whereSql}`,
    params
  );
  const total = Number(countRows[0]?.c || 0);
  const limit = Math.min(100, Math.max(10, Number(pageSize) || 50));
  const offset = Math.max(0, (Math.max(1, Number(page) || 1) - 1) * limit);
  const rows = await db.query(
    `SELECT r.id, r.return_no, r.return_date, r.total, r.status,
            c.name_ar AS customer_name, c.code AS customer_code,
            i.invoice_no, (${POSTED_SQL}) AS is_posted
     FROM sal_return r
     INNER JOIN crm_customer c ON c.id = r.customer_id
     INNER JOIN sal_invoice i ON i.id = r.invoice_id
     WHERE ${whereSql}
     ORDER BY r.id DESC
     LIMIT ${limit} OFFSET ${offset}`,
    params
  );
  return {
    rows: rows.map((r) => ({
      ...r,
      is_posted: !!(r.is_posted === 1 || r.is_posted === true || r.is_posted === '1'),
    })),
    total,
    page: Math.floor(offset / limit) + 1,
    pageSize: limit,
  };
}

async function isPosted(returnId) {
  const rows = await db.query(
    `SELECT (${POSTED_SQL}) AS is_posted FROM sal_return r WHERE r.id = ? LIMIT 1`,
    [returnId]
  );
  const v = rows[0]?.is_posted;
  return !!(v === 1 || v === true || v === '1');
}

async function getReturn(id) {
  const rid = Number(id);
  if (!rid) return null;
  const headers = await db.query(
    `SELECT r.*, c.name_ar AS customer_name, c.code AS customer_code,
            i.invoice_no, i.invoice_date, (${POSTED_SQL}) AS is_posted
     FROM sal_return r
     INNER JOIN crm_customer c ON c.id = r.customer_id
     INNER JOIN sal_invoice i ON i.id = r.invoice_id
     WHERE r.id = ? LIMIT 1`,
    [rid]
  );
  if (!headers[0]) return null;
  const h = headers[0];
  const lines = await db.query(
    `SELECT rl.*, it.sku AS item_code, it.barcode, it.name_ar AS item_name,
            il.qty AS qty_sold, COALESCE(il.qty_extra, 0) AS qty_extra_sold, il.line_desc
     FROM sal_return_line rl
     INNER JOIN inv_item it ON it.id = rl.item_id
     INNER JOIN sal_invoice_line il ON il.id = rl.invoice_line_id
     WHERE rl.return_id = ?
     ORDER BY rl.id`,
    [rid]
  );
  return {
    id: Number(h.id),
    return_no: h.return_no,
    return_date: h.return_date,
    customer_id: Number(h.customer_id),
    customer_name: h.customer_name,
    customer_code: h.customer_code,
    invoice_id: Number(h.invoice_id),
    invoice_no: h.invoice_no,
    invoice_date: h.invoice_date,
    warehouse_id: h.warehouse_id != null ? Number(h.warehouse_id) : null,
    subtotal: Number(h.subtotal || 0),
    tax_amount: Number(h.tax_amount || 0),
    total: Number(h.total || 0),
    notes: h.notes || '',
    reason_return: h.reason_return || '',
    status: h.status,
    is_posted: !!(h.is_posted === 1 || h.is_posted === true || h.is_posted === '1'),
    lines: lines.map((ln) => ({
      invoice_line_id: Number(ln.invoice_line_id),
      item_id: Number(ln.item_id),
      item_code: ln.item_code,
      barcode: ln.barcode || '',
      name_ar: ln.line_desc || ln.item_name,
      qty: Number(ln.qty || 0),
      qty_extra: Number(ln.qty_extra || 0),
      qty_sold: Number(ln.qty_sold || 0),
      qty_extra_sold: Number(ln.qty_extra_sold || 0),
      unit_price: Number(ln.unit_price || 0),
      tax_rate_percent: Number(ln.tax_rate_percent || 0),
      line_subtotal: Number(ln.line_subtotal || 0),
      tax_amount: Number(ln.tax_amount || 0),
      line_gross: Number(ln.line_gross || 0),
    })),
  };
}

async function saveReturn(payload, userId) {
  return phpAction('save', userId, {
    return_id: Number(payload.id || payload.return_id || 0),
    return_date: parseDateToIso(payload.return_date || todayIso()),
    customer_id: Number(payload.customer_id || 0),
    invoice_id: Number(payload.invoice_id || 0),
    notes: String(payload.notes || ''),
    reason_return: String(payload.reason_return || payload.reason || ''),
    lines: Array.isArray(payload.lines) ? payload.lines : [],
  });
}

async function postReturn(returnId, userId, { autoEinvoice = true, reason = '' } = {}) {
  const id = Number(returnId);
  if (id < 1) return { ok: false, error: 'مرتجع غير صالح.' };
  if (await isPosted(id)) return { ok: false, error: 'المرتجع مرحّل مسبقاً.' };
  const post = await phpAction('post', userId, { return_id: id });
  if (!post.ok) return post;
  let einvoice = null;
  if (autoEinvoice) {
    einvoice = await phpAction('einvoice', userId, {
      return_id: id,
      reason: reason || 'إرجاع بضاعة',
    });
    const einvMsg = einvoice.ok
      ? einvoice.skipped
        ? ' · الفوترة: ' + (einvoice.message || 'تخطي')
        : ' · أُرسل للفوترة الإلكترونية'
      : ' · تنبيه فوترة: ' + (einvoice.error || einvoice.message || 'فشل');
    return {
      ok: true,
      return_id: id,
      message: (post.message || 'تم الترحيل.') + einvMsg,
      einvoice,
    };
  }
  return { ...post, return_id: id, einvoice: null };
}

async function unpostReturn(returnId, userId) {
  return phpAction('unpost', userId, { return_id: Number(returnId) });
}

async function deleteReturn(returnId, userId) {
  const id = Number(returnId);
  if (id < 1) return { ok: false, error: 'مرتجع غير صالح.' };
  if (await isPosted(id)) {
    return { ok: false, error: 'لا يمكن حذف مرتجع مرحّل. فك الترحيل أولاً.' };
  }
  return phpAction('delete', userId, { return_id: id });
}

async function sendEinvoice(returnId, userId, reason) {
  const id = Number(returnId);
  if (id < 1) return { ok: false, error: 'مرتجع غير صالح.' };
  if (!(await isPosted(id))) {
    return { ok: false, error: 'يجب ترحيل المرتجع قبل الإرسال للفوترة.' };
  }
  return phpAction('einvoice', userId, {
    return_id: id,
    reason: reason || 'إرجاع بضاعة',
  });
}

async function invoicesForCustomer(customerId, userId) {
  return phpAction('invoices', userId || 1, { customer_id: Number(customerId) });
}

async function linesForInvoice(invoiceId, excludeReturnId, customerId, userId) {
  return phpAction('lines', userId || 1, {
    invoice_id: Number(invoiceId),
    exclude_return_id: Number(excludeReturnId || 0),
    customer_id: Number(customerId || 0),
  });
}

async function searchCustomers(q, limit = 30) {
  const like = `%${String(q || '').trim()}%`;
  if (!String(q || '').trim()) {
    return db.query(
      `SELECT id, code, name_ar, phone FROM crm_customer
       WHERE is_active = 1 ORDER BY name_ar LIMIT ${Math.min(50, Number(limit) || 30)}`
    );
  }
  return db.query(
    `SELECT id, code, name_ar, phone FROM crm_customer
     WHERE is_active = 1 AND (name_ar LIKE ? OR code LIKE ? OR IFNULL(phone,'') LIKE ?)
     ORDER BY name_ar LIMIT ${Math.min(50, Number(limit) || 30)}`,
    [like, like, like]
  );
}

module.exports = {
  listReturns,
  getReturn,
  saveReturn,
  postReturn,
  unpostReturn,
  deleteReturn,
  sendEinvoice,
  invoicesForCustomer,
  linesForInvoice,
  searchCustomers,
  isPosted,
  POSTED_SQL,
  phpAction,
};
