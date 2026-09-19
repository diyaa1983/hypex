'use strict';

const db = require('../db');
const { parseDateToIso, todayIso } = require('../lib/html');

async function safeQuery(sql, params = []) {
  try {
    return await db.query(sql, params);
  } catch (e) {
    console.error('pur return', e.message);
    throw e;
  }
}

function r3(n) {
  return Math.round((Number(n) || 0) * 1000) / 1000;
}
function r6(n) {
  return Math.round((Number(n) || 0) * 1e6) / 1e6;
}

function lineAmounts(qty, unitPrice, taxRate) {
  const sub = r3(qty * unitPrice);
  const tax = r3((sub * (Number(taxRate) || 0)) / 100);
  return { line_subtotal: sub, tax_amount: tax, line_gross: r3(sub + tax) };
}

async function nextReturnNo(returnDate) {
  const year = String(returnDate).slice(0, 4);
  const suffix = `-${year}`;
  const rows = await safeQuery(`SELECT return_no FROM pur_return WHERE return_no LIKE ?`, [`%${suffix}`]);
  let max = 0;
  const re = new RegExp(`^(?:MR|PR)?(\\d+)${suffix.replace('-', '\\-')}$`);
  for (const r of rows) {
    const m = String(r.return_no || '').match(re);
    if (m) max = Math.max(max, Number(m[1]));
  }
  return 'PR' + String(max + 1).padStart(3, '0') + suffix;
}

async function listSuppliers() {
  return safeQuery(
    `SELECT id, code, name_ar FROM crm_supplier WHERE is_active = 1 ORDER BY name_ar LIMIT 500`
  );
}

async function isInvoicePosted(invoiceId) {
  const id = Number(invoiceId);
  if (!id) return false;
  try {
    const led = await safeQuery(
      `SELECT 1 AS ok FROM crm_supplier_ledger
       WHERE txn_type = 'purchase_invoice' AND ref_id = ? LIMIT 1`,
      [id]
    );
    if (!led[0]) return false;
  } catch {
    // لا جدول ذمة — اعتمد الحالة فقط
    const inv = await safeQuery(`SELECT status FROM pur_invoice WHERE id = ? LIMIT 1`, [id]);
    return inv[0] && String(inv[0].status) === 'confirmed';
  }
  try {
    const need = await safeQuery(
      `SELECT COUNT(*) AS c FROM pur_invoice_line l
       INNER JOIN inv_item i ON i.id = l.item_id
       WHERE l.invoice_id = ? AND COALESCE(i.track_inventory, 1) = 1`,
      [id]
    );
    if (Number(need[0]?.c || 0) === 0) return true;
    const stock = await safeQuery(
      `SELECT 1 AS ok FROM inv_stock_move
       WHERE ref_type = 'purchase_invoice' AND ref_id = ? LIMIT 1`,
      [id]
    );
    return !!stock[0];
  } catch {
    return true;
  }
}

async function invoicesForSupplier(supplierId) {
  const sid = Number(supplierId);
  if (sid < 1) return [];
  const rows = await safeQuery(
    `SELECT i.id, i.invoice_no, i.invoice_date, i.total, i.warehouse_id, i.status
     FROM pur_invoice i
     WHERE i.supplier_id = ? AND i.status = 'confirmed'
     ORDER BY i.invoice_date DESC, i.id DESC
     LIMIT 200`,
    [sid]
  );
  const out = [];
  for (const row of rows) {
    const posted = await isInvoicePosted(row.id);
    if (!posted) continue;
    const lines = await fetchInvoiceLines(row.id);
    if (!lines.length) continue;
    out.push({
      id: Number(row.id),
      invoice_no: row.invoice_no,
      invoice_date: row.invoice_date,
      total: Number(row.total || 0),
      warehouse_id: row.warehouse_id != null ? Number(row.warehouse_id) : null,
    });
  }
  return out;
}

async function fetchInvoiceLines(invoiceId) {
  const id = Number(invoiceId);
  if (id < 1) return [];
  if (!(await isInvoicePosted(id))) return [];

  let rows;
  try {
    rows = await safeQuery(
      `SELECT il.id AS invoice_line_id, il.item_id, il.line_desc, il.qty AS qty_sold,
              il.unit_price, COALESCE(il.tax_rate_percent, 0) AS tax_rate_percent,
              COALESCE(SUM(rl.qty), 0) AS qty_returned,
              COALESCE(i.barcode, i.sku, '') AS barcode, i.name_ar
       FROM pur_invoice_line il
       INNER JOIN inv_item i ON i.id = il.item_id
       LEFT JOIN pur_return_line rl ON rl.invoice_line_id = il.id
       LEFT JOIN pur_return r ON r.id = rl.return_id AND r.status <> 'cancelled'
       WHERE il.invoice_id = ?
       GROUP BY il.id, il.item_id, il.line_desc, il.qty, il.unit_price, il.tax_rate_percent, i.barcode, i.sku, i.name_ar
       ORDER BY il.id ASC`,
      [id]
    );
  } catch {
    rows = await safeQuery(
      `SELECT il.id AS invoice_line_id, il.item_id, il.line_desc, il.qty AS qty_sold,
              il.unit_price, 0 AS tax_rate_percent,
              COALESCE(SUM(rl.qty), 0) AS qty_returned,
              COALESCE(i.sku, '') AS barcode, i.name_ar
       FROM pur_invoice_line il
       INNER JOIN inv_item i ON i.id = il.item_id
       LEFT JOIN pur_return_line rl ON rl.invoice_line_id = il.id
       LEFT JOIN pur_return r ON r.id = rl.return_id AND r.status <> 'cancelled'
       WHERE il.invoice_id = ?
       GROUP BY il.id
       ORDER BY il.id ASC`,
      [id]
    );
  }

  return rows
    .map((row) => {
      const sold = Number(row.qty_sold || 0);
      const returned = Number(row.qty_returned || 0);
      const remaining = Math.max(0, sold - returned);
      return {
        invoice_line_id: Number(row.invoice_line_id),
        item_id: Number(row.item_id),
        line_desc: row.line_desc || '',
        name_ar: row.name_ar || '',
        barcode: row.barcode || '',
        qty_sold: sold,
        qty_returned: returned,
        qty_remaining: remaining,
        unit_price: Number(row.unit_price || 0),
        tax_rate_percent: Number(row.tax_rate_percent || 0),
      };
    })
    .filter((l) => l.qty_remaining > 0.000001);
}

async function getReturn(id) {
  const returnId = Number(id);
  if (!returnId) return null;
  const headers = await safeQuery(
    `SELECT r.*, s.name_ar AS supplier_name, s.code AS supplier_code,
            i.invoice_no, i.payment_type
     FROM pur_return r
     INNER JOIN crm_supplier s ON s.id = r.supplier_id
     INNER JOIN pur_invoice i ON i.id = r.invoice_id
     WHERE r.id = ? LIMIT 1`,
    [returnId]
  );
  if (!headers[0]) return null;
  const h = headers[0];
  const lines = await safeQuery(
    `SELECT rl.*, COALESCE(NULLIF(TRIM(it.barcode), ''), it.sku, '') AS item_code, it.name_ar AS item_name,
            COALESCE(NULLIF(TRIM(it.barcode), ''), it.sku, '') AS barcode
     FROM pur_return_line rl
     LEFT JOIN inv_item it ON it.id = rl.item_id
     WHERE rl.return_id = ?
     ORDER BY rl.id`,
    [returnId]
  );
  const posted = await isReturnPosted(returnId);
  return {
    id: Number(h.id),
    return_no: h.return_no,
    return_date: h.return_date,
    supplier_id: Number(h.supplier_id),
    supplier_name: h.supplier_name,
    supplier_code: h.supplier_code,
    invoice_id: Number(h.invoice_id),
    invoice_no: h.invoice_no,
    warehouse_id: h.warehouse_id != null ? Number(h.warehouse_id) : null,
    subtotal: Number(h.subtotal || 0),
    tax_amount: Number(h.tax_amount || 0),
    total: Number(h.total || 0),
    status: h.status,
    notes: h.notes || '',
    payment_type: h.payment_type || 'credit',
    is_posted: posted,
    is_locked: posted || h.status === 'cancelled',
    status_label: posted ? 'مرحّل' : h.status === 'cancelled' ? 'ملغى' : 'مؤكد (غير مرحّل)',
    lines: lines.map((ln) => ({
      invoice_line_id: Number(ln.invoice_line_id),
      item_id: Number(ln.item_id),
      item_code: ln.item_code || '',
      name_ar: ln.item_name || '',
      barcode: ln.barcode || '',
      qty: Number(ln.qty || 0),
      unit_price: Number(ln.unit_price || 0),
      tax_rate_percent: Number(ln.tax_rate_percent || 0),
      line_subtotal: Number(ln.line_subtotal || 0),
      tax_amount: Number(ln.tax_amount || 0),
      line_gross: Number(ln.line_gross || 0),
    })),
  };
}

async function isReturnPosted(returnId) {
  const id = Number(returnId);
  if (!id) return false;
  try {
    const led = await safeQuery(
      `SELECT 1 AS ok FROM crm_supplier_ledger
       WHERE txn_type = 'purchase_return' AND ref_id = ? LIMIT 1`,
      [id]
    );
    if (!led[0]) return false;
  } catch {
    return false;
  }
  try {
    const need = await safeQuery(
      `SELECT COUNT(*) AS c FROM pur_return_line pl
       INNER JOIN inv_item i ON i.id = pl.item_id
       INNER JOIN pur_return r ON r.id = pl.return_id
       WHERE pl.return_id = ? AND COALESCE(i.track_inventory,1)=1 AND pl.qty > 0
         AND r.warehouse_id IS NOT NULL`,
      [id]
    );
    if (Number(need[0]?.c || 0) === 0) return true;
    const stock = await safeQuery(
      `SELECT 1 AS ok FROM inv_stock_move
       WHERE ref_type = 'purchase_return' AND ref_id = ? LIMIT 1`,
      [id]
    );
    return !!stock[0];
  } catch {
    return true;
  }
}

async function saveReturn(payload, userId) {
  const supplierId = Number(payload.supplier_id || 0);
  const invoiceId = Number(payload.invoice_id || 0);
  const returnDate = parseDateToIso(payload.return_date || todayIso());
  const notes = String(payload.notes || '').trim() || null;
  const rawLines = Array.isArray(payload.lines) ? payload.lines : [];

  if (supplierId < 1) return { ok: false, error: 'اختر المورد.' };
  if (invoiceId < 1) return { ok: false, error: 'اختر فاتورة الشراء.' };

  const inv = await safeQuery(
    `SELECT id, supplier_id, warehouse_id, status, invoice_no, payment_type
     FROM pur_invoice WHERE id = ? LIMIT 1`,
    [invoiceId]
  );
  if (!inv[0]) return { ok: false, error: 'فاتورة الشراء غير موجودة.' };
  if (Number(inv[0].supplier_id) !== supplierId) return { ok: false, error: 'الفاتورة لا تخص المورد المختار.' };
  if (String(inv[0].status) !== 'confirmed') return { ok: false, error: 'لا يمكن إرجاع فاتورة غير مؤكدة.' };
  if (!(await isInvoicePosted(invoiceId))) {
    return { ok: false, error: 'لا يمكن إرجاع فاتورة غير مرحّلة. رحّل الفاتورة أولاً.' };
  }

  const checked = [];
  for (const ln of rawLines) {
    const lineId = Number(ln.invoice_line_id || 0);
    const qty = Number(ln.qty || 0);
    if (lineId < 1 || qty <= 0) continue;

    const rows = await safeQuery(
      `SELECT il.id, il.item_id, il.qty AS qty_sold, il.unit_price,
              COALESCE(il.tax_rate_percent, 0) AS tax_rate_percent,
              COALESCE(SUM(rl.qty), 0) AS qty_returned
       FROM pur_invoice_line il
       LEFT JOIN pur_return_line rl ON rl.invoice_line_id = il.id
       LEFT JOIN pur_return r ON r.id = rl.return_id AND r.status <> 'cancelled'
       WHERE il.id = ? AND il.invoice_id = ?
       GROUP BY il.id`,
      [lineId, invoiceId]
    );
    if (!rows[0]) return { ok: false, error: 'سطر فاتورة غير صالح.' };
    const remaining = Number(rows[0].qty_sold) - Number(rows[0].qty_returned);
    if (qty > remaining + 0.000001) {
      return { ok: false, error: 'كمية الإرجاع أكبر من الكمية المتبقية للمادة.' };
    }
    const unitPrice = Number(rows[0].unit_price || 0);
    const taxRate = Number(rows[0].tax_rate_percent || 0);
    const amounts = lineAmounts(qty, unitPrice, taxRate);
    checked.push({
      invoice_line_id: lineId,
      item_id: Number(rows[0].item_id),
      qty: r6(qty),
      unit_price: r6(unitPrice),
      tax_rate_percent: r6(taxRate),
      ...amounts,
    });
  }
  if (!checked.length) return { ok: false, error: 'أدخل كمية إرجاع لمادة واحدة على الأقل.' };

  let sumSub = 0;
  let sumTax = 0;
  let sumGross = 0;
  for (const ln of checked) {
    sumSub += ln.line_subtotal;
    sumTax += ln.tax_amount;
    sumGross += ln.line_gross;
  }
  sumSub = r3(sumSub);
  sumTax = r3(sumTax);
  sumGross = r3(sumGross);

  const whId = inv[0].warehouse_id != null && Number(inv[0].warehouse_id) > 0 ? Number(inv[0].warehouse_id) : null;
  const returnNo = await nextReturnNo(returnDate);

  const pool = db.getPool();
  const conn = await pool.getConnection();
  try {
    await conn.beginTransaction();
    const [ins] = await conn.execute(
      `INSERT INTO pur_return
       (return_no, return_date, supplier_id, invoice_id, warehouse_id, subtotal, tax_amount, total, status, notes, created_by)
       VALUES (?,?,?,?,?,?,?,?,?,?,?)`,
      [
        returnNo,
        returnDate,
        supplierId,
        invoiceId,
        whId,
        sumSub,
        sumTax,
        sumGross,
        'confirmed',
        notes,
        userId || null,
      ]
    );
    const returnId = Number(ins.insertId);
    for (const ln of checked) {
      await conn.execute(
        `INSERT INTO pur_return_line
         (return_id, invoice_line_id, item_id, qty, unit_price, tax_rate_percent, line_subtotal, tax_amount, line_gross)
         VALUES (?,?,?,?,?,?,?,?,?)`,
        [
          returnId,
          ln.invoice_line_id,
          ln.item_id,
          ln.qty,
          ln.unit_price,
          ln.tax_rate_percent,
          ln.line_subtotal,
          ln.tax_amount,
          ln.line_gross,
        ]
      );
    }
    await conn.commit();
    return {
      ok: true,
      id: returnId,
      return_no: returnNo,
      message: `تم حفظ مردود المشتريات. الرقم: ${returnNo}`,
    };
  } catch (e) {
    await conn.rollback();
    console.error('saveReturn', e.message);
    return { ok: false, error: 'تعذر حفظ مردود المشتريات: ' + (e.message || '') };
  } finally {
    conn.release();
  }
}

async function postReturn(returnId) {
  const id = Number(returnId);
  if (!id) return { ok: false, error: 'معرّف غير صالح.' };
  if (await isReturnPosted(id)) return { ok: true, skipped: true, message: 'المردود مرحّل مسبقاً.' };

  const doc = await getReturn(id);
  if (!doc) return { ok: false, error: 'المردود غير موجود.' };
  if (doc.status !== 'confirmed') return { ok: false, error: 'لا يمكن ترحيل مردود غير مؤكد.' };
  if (doc.total <= 0) return { ok: false, error: 'إجمالي المردود غير صالح.' };

  if (!(await isInvoicePosted(doc.invoice_id))) {
    return { ok: false, error: 'فاتورة الشراء المرتبطة غير مرحّلة بالكامل.' };
  }

  const pool = db.getPool();
  const conn = await pool.getConnection();
  try {
    await conn.beginTransaction();

    // stock issue
    if (doc.warehouse_id) {
      const [stockItems] = await conn.execute(
        `SELECT pl.item_id, pl.qty, i.name_ar, COALESCE(i.track_inventory,1) AS track_inventory
         FROM pur_return_line pl
         INNER JOIN inv_item i ON i.id = pl.item_id
         WHERE pl.return_id = ? AND pl.qty > 0`,
        [id]
      );
      for (const row of stockItems) {
        if (Number(row.track_inventory) !== 1) continue;
        const qty = Number(row.qty || 0);
        if (qty <= 0) continue;
        await conn.execute(
          `INSERT INTO inv_stock_move (move_date, warehouse_id, item_id, qty_delta, ref_type, ref_id, note)
           VALUES (?,?,?,?,?,?,?)`,
          [
            doc.return_date,
            doc.warehouse_id,
            row.item_id,
            -qty,
            'purchase_return',
            id,
            'صرف مردود مشتريات ' + doc.return_no,
          ]
        );
      }
    }

    // supplier ledger
    try {
      const [exists] = await conn.execute(
        `SELECT 1 FROM crm_supplier_ledger WHERE txn_type='purchase_return' AND ref_id=? LIMIT 1`,
        [id]
      );
      if (!exists[0]) {
        const pay = doc.payment_type === 'cash' ? 'cash' : 'credit';
        const debit = doc.total;
        const credit = pay === 'cash' ? doc.total : 0;
        const memo =
          'مردود مشتريات ' +
          doc.return_no +
          ' — فاتورة ' +
          (doc.invoice_no || '') +
          (pay === 'cash' ? ' — نقدي' : ' — ذمم');
        await conn.execute(
          `INSERT INTO crm_supplier_ledger
           (supplier_id, txn_date, txn_type, ref_id, ref_no, payment_type, debit, credit, memo)
           VALUES (?,?,?,?,?,?,?,?,?)`,
          [doc.supplier_id, doc.return_date, 'purchase_return', id, doc.return_no, pay, debit, credit, memo]
        );
      }
    } catch (e) {
      // ledger optional if table missing
      if (!String(e.message || '').includes("doesn't exist")) throw e;
    }

    await conn.commit();
    return { ok: true, message: 'تم ترحيل مردود المشتريات (مخزون + ذمة المورد).' };
  } catch (e) {
    await conn.rollback();
    console.error('postReturn', e.message);
    return { ok: false, error: 'تعذر الترحيل: ' + (e.message || '') };
  } finally {
    conn.release();
  }
}

async function deleteReturn(returnId) {
  const id = Number(returnId);
  if (!id) return { ok: false, error: 'معرّف غير صالح.' };
  if (await isReturnPosted(id)) return { ok: false, error: 'لا يمكن حذف مردود مرحّل. فك الترحيل أولاً.' };
  const row = await safeQuery(`SELECT status FROM pur_return WHERE id = ? LIMIT 1`, [id]);
  if (!row[0]) return { ok: false, error: 'غير موجود.' };
  await safeQuery(`DELETE FROM pur_return_line WHERE return_id = ?`, [id]);
  await safeQuery(`DELETE FROM pur_return WHERE id = ?`, [id]);
  return { ok: true, message: 'تم حذف المردود.' };
}

const { neighbors, findIdByNo } = require('../lib/docBrowse');

async function browseNeighbors(id) {
  return neighbors('pur_return', id);
}

async function findReturnIdByNo(no) {
  return findIdByNo('pur_return', 'return_no', no);
}

module.exports = {
  listSuppliers,
  invoicesForSupplier,
  fetchInvoiceLines,
  getReturn,
  saveReturn,
  postReturn,
  deleteReturn,
  isReturnPosted,
  browseNeighbors,
  findReturnIdByNo,
};
