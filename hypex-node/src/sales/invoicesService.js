'use strict';

const fs = require('fs');
const path = require('path');
const { spawn } = require('child_process');
const db = require('../db');
const { parseDateToIso, todayIso } = require('../lib/html');
const itemPricing = require('../lib/itemPricing');
const companyDecimals = require('../lib/companyDecimals');

const POSTED_SQL = `(
  EXISTS (SELECT 1 FROM crm_customer_ledger l WHERE l.txn_type = 'sale_invoice' AND l.ref_id = i.id)
  OR EXISTS (SELECT 1 FROM inv_stock_move m WHERE m.ref_type = 'sale_invoice' AND m.ref_id = i.id)
)`;

function r3(n) {
  return companyDecimals.roundAmount(n);
}

function r6(n) {
  return companyDecimals.roundUnit(n);
}

async function nextInvoiceNo(invoiceDate) {
  const iso = parseDateToIso(invoiceDate);
  const year = iso.slice(0, 4);
  const suffix = `-${year}`;
  const rows = await db.query(
    `SELECT invoice_no FROM sal_invoice WHERE invoice_no LIKE ?`,
    [`%${suffix}`]
  );
  let maxSeq = 0;
  const re = new RegExp(`^(\\d+)${suffix.replace('-', '\\-')}$`);
  for (const row of rows) {
    const no = String(row.invoice_no || '');
    const m = no.match(re);
    if (m) maxSeq = Math.max(maxSeq, Number(m[1]));
  }
  return String(maxSeq + 1).padStart(3, '0') + suffix;
}

async function listInvoices({ q = '', filter = 'all', page = 1, pageSize = 50 } = {}) {
  const where = [`i.status = 'confirmed'`];
  const params = [];

  if (filter === 'unposted') {
    where.push(`NOT ${POSTED_SQL}`);
  } else if (filter === 'posted') {
    where.push(POSTED_SQL);
  }

  if (q) {
    where.push(`(i.invoice_no LIKE ? OR c.name_ar LIKE ? OR c.code LIKE ?)`);
    const like = `%${q}%`;
    params.push(like, like, like);
  }

  const whereSql = where.join(' AND ');
  const countRows = await db.query(
    `SELECT COUNT(*) AS c
     FROM sal_invoice i
     INNER JOIN crm_customer c ON c.id = i.customer_id
     WHERE ${whereSql}`,
    params
  );
  const total = Number(countRows[0]?.c || 0);
  const limit = Math.min(100, Math.max(10, Number(pageSize) || 50));
  const offset = Math.max(0, (Math.max(1, Number(page) || 1) - 1) * limit);

  const rows = await db.query(
    `SELECT i.id, i.invoice_no, i.invoice_date, i.total, i.payment_type, i.status,
            c.name_ar AS customer_name, c.code AS customer_code,
            (${POSTED_SQL}) AS is_posted
     FROM sal_invoice i
     INNER JOIN crm_customer c ON c.id = i.customer_id
     WHERE ${whereSql}
     ORDER BY i.id DESC
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

async function getInvoice(id) {
  const invId = Number(id);
  if (!invId) return null;

  const headers = await db.query(
    `SELECT i.*, c.name_ar AS customer_name, c.code AS customer_code,
            COALESCE(c.use_wholesale_price, 0) AS use_wholesale_price,
            w.name_ar AS warehouse_name,
            COALESCE(r.name_ar, '') AS sales_rep_name,
            (${POSTED_SQL}) AS is_posted
     FROM sal_invoice i
     INNER JOIN crm_customer c ON c.id = i.customer_id
     LEFT JOIN inv_warehouse w ON w.id = i.warehouse_id
     LEFT JOIN crm_sales_rep r ON r.id = i.sales_rep_id
     WHERE i.id = ?
     LIMIT 1`,
    [invId]
  );
  if (!headers[0]) return null;
  const h = headers[0];
  const lines = await db.query(
    `SELECT il.*,
            NULLIF(TRIM(it.barcode), '') AS item_barcode,
            NULLIF(TRIM(it.sku), '') AS item_sku,
            COALESCE(NULLIF(TRIM(it.barcode), ''), it.sku) AS item_code,
            it.name_ar AS item_name,
            COALESCE(NULLIF(TRIM(il.unit_name), ''), NULLIF(TRIM(it.unit_name), ''), 'قطعة') AS unit_name,
            COALESCE(it.default_sale, 0) AS base_sale
     FROM sal_invoice_line il
     INNER JOIN inv_item it ON it.id = il.item_id
     WHERE il.invoice_id = ?
     ORDER BY il.id`,
    [invId]
  );

  const mappedLines = [];
  for (const ln of lines) {
    const itemId = Number(ln.item_id);
    let units = [];
    let baseSale = Number(ln.base_sale || 0);
    let baseWholesale = 0;
    try {
      const pricing = await itemPricing.getItemPricing(itemId);
      if (pricing) {
        units = pricing.units || [];
        baseSale = Number(pricing.base_sale || 0);
        baseWholesale = Number(pricing.base_wholesale || 0);
      }
    } catch {
      units = [];
    }
    mappedLines.push({
      item_id: itemId,
      item_sku: ln.item_sku || '',
      item_barcode: ln.item_barcode || '',
      item_code: ln.item_barcode || ln.item_sku || ln.item_code || '',
      name_ar: ln.line_desc || ln.item_name,
      qty: Number(ln.qty || 0),
      qty_extra: Number(ln.qty_extra || 0),
      unit_price: Number(ln.unit_price || 0),
      base_sale: baseSale,
      base_list_sale: baseSale,
      base_wholesale: baseWholesale,
      discount_pct: Number(ln.discount_pct || 0),
      discount_amount: Number(ln.discount_amount || 0),
      tax_rate_percent: Number(ln.tax_rate_percent || 0),
      tax_amount: Number(ln.tax_amount || 0),
      line_total: Number(ln.line_total || 0),
      line_gross: Number(ln.line_gross || 0),
      unit_id: ln.unit_id != null ? Number(ln.unit_id) : null,
      unit_name: ln.unit_name || 'قطعة',
      unit_factor: Number(ln.unit_factor || 1),
      qty_base: Number(ln.qty_base || ln.qty || 0),
      units,
    });
  }

  return {
    id: Number(h.id),
    invoice_no: h.invoice_no,
    invoice_date: h.invoice_date,
    customer_id: Number(h.customer_id),
    customer_name: h.customer_name,
    customer_code: h.customer_code,
    use_wholesale_price: Number(h.use_wholesale_price) === 1 ? 1 : 0,
    sales_rep_id: h.sales_rep_id != null ? Number(h.sales_rep_id) : null,
    sales_rep_name: h.sales_rep_name || '',
    warehouse_id: h.warehouse_id != null ? Number(h.warehouse_id) : null,
    warehouse_name: h.warehouse_name || '',
    payment_type: h.payment_type || 'credit',
    subtotal: Number(h.subtotal || 0),
    tax_amount: Number(h.tax_amount || 0),
    total: Number(h.total || 0),
    notes: h.notes || '',
    invoice_discount_input: h.invoice_discount_input || '',
    status: h.status,
    is_posted: !!(h.is_posted === 1 || h.is_posted === true || h.is_posted === '1'),
    einv_status: h.einv_status != null ? String(h.einv_status) : '',
    einv_qr: h.einv_qr != null ? String(h.einv_qr).trim() : '',
    einv_num: h.einv_num != null ? String(h.einv_num) : '',
    einv_inv_uuid: h.einv_inv_uuid != null ? String(h.einv_inv_uuid) : '',
    einv_sent_at: h.einv_sent_at || null,
    /** مرسلة للفوترة = وجود EINV_QR (نفس منطق PHP) */
    einv_sent: !!(h.einv_qr != null && String(h.einv_qr).trim() !== ''),
    customer_email: await getCustomerEmail(h.customer_id),
    lines: mappedLines,
  };
}

async function getCustomerEmail(customerId) {
  const cid = Number(customerId) || 0;
  if (!cid) return '';
  try {
    const rows = await db.query(
      `SELECT email FROM crm_customer WHERE id = ? LIMIT 1`,
      [cid]
    );
    return rows[0] && rows[0].email ? String(rows[0].email).trim() : '';
  } catch {
    return '';
  }
}

function escMail(s) {
  return String(s == null ? '' : s)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;');
}

function fmtMail(n) {
  return (Math.round((Number(n) || 0) * 1000) / 1000).toLocaleString('en-US', {
    minimumFractionDigits: 3,
    maximumFractionDigits: 3,
  });
}

function buildInvoiceEmailHtml(inv, companyName) {
  const lines = (inv.lines || [])
    .map(
      (ln, i) => `<tr>
      <td style="padding:6px;border:1px solid #e2e8f0;text-align:center">${i + 1}</td>
      <td style="padding:6px;border:1px solid #e2e8f0">${escMail(ln.name_ar || '')}</td>
      <td style="padding:6px;border:1px solid #e2e8f0;text-align:center" dir="ltr">${escMail(fmtMail(ln.qty))}</td>
      <td style="padding:6px;border:1px solid #e2e8f0;text-align:left" dir="ltr">${escMail(fmtMail(ln.unit_price))}</td>
      <td style="padding:6px;border:1px solid #e2e8f0;text-align:left" dir="ltr"><strong>${escMail(fmtMail(ln.line_gross))}</strong></td>
    </tr>`
    )
    .join('');
  const invDate = String(inv.invoice_date || '').slice(0, 10);
  return `<div style="font-family:Arial,Helvetica,sans-serif;font-size:14px;direction:rtl;color:#0f172a;line-height:1.5">
  <h2 style="margin:0 0 8px">فاتورة مبيعات ${escMail(inv.invoice_no || '')}</h2>
  <p style="margin:0 0 12px;color:#64748b">
    التاريخ: <strong dir="ltr">${escMail(invDate)}</strong>
    · العميل: <strong>${escMail(inv.customer_name || '')}</strong>
    ${inv.customer_code ? ' · الرمز: <strong dir="ltr">' + escMail(inv.customer_code) + '</strong>' : ''}
  </p>
  <table style="width:100%;border-collapse:collapse;margin:0 0 14px">
    <thead>
      <tr style="background:#f1f5f9">
        <th style="padding:6px;border:1px solid #e2e8f0">#</th>
        <th style="padding:6px;border:1px solid #e2e8f0">المادة</th>
        <th style="padding:6px;border:1px solid #e2e8f0">الكمية</th>
        <th style="padding:6px;border:1px solid #e2e8f0">السعر</th>
        <th style="padding:6px;border:1px solid #e2e8f0">الإجمالي</th>
      </tr>
    </thead>
    <tbody>${lines || '<tr><td colspan="5" style="padding:10px;border:1px solid #e2e8f0;text-align:center">لا بنود</td></tr>'}</tbody>
  </table>
  <p style="margin:0;text-align:left" dir="ltr">
    <span>Subtotal: ${escMail(fmtMail(inv.subtotal))}</span><br>
    <span>Tax: ${escMail(fmtMail(inv.tax_amount))}</span><br>
    <strong>Total: ${escMail(fmtMail(inv.total))}</strong>
  </p>
  ${inv.notes ? '<p style="margin:12px 0 0;color:#475569">ملاحظات: ' + escMail(inv.notes) + '</p>' : ''}
  ${companyName ? '<p style="margin:16px 0 0;color:#94a3b8;font-size:12px">' + escMail(companyName) + '</p>' : ''}
  <p style="margin:10px 0 0;color:#94a3b8;font-size:12px">يمكنك طباعة PDF من النظام عبر رابط الفاتورة.</p>
</div>`;
}

async function companyNameAr() {
  try {
    const rows = await db.query(
      `SELECT company_name_ar FROM sys_company_settings ORDER BY id LIMIT 1`
    );
    return rows[0] && rows[0].company_name_ar ? String(rows[0].company_name_ar) : 'Hypex';
  } catch {
    return 'Hypex';
  }
}

async function sendInvoiceEmail(invoiceId, toEmail, userId) {
  const id = Number(invoiceId);
  if (id < 1) return { ok: false, error: 'فاتورة غير صالحة.' };
  const to = String(toEmail || '').trim();
  if (!to || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(to)) {
    return { ok: false, error: 'البريد الإلكتروني للمستلم غير صالح.' };
  }
  const inv = await getInvoice(id);
  if (!inv) return { ok: false, error: 'الفاتورة غير موجودة.' };

  let company = 'Hypex';
  try {
    company = await companyNameAr();
  } catch {
    /* */
  }
  const subject =
    'فاتورة مبيعات ' +
    (inv.invoice_no || id) +
    (company ? ' — ' + company : '');
  const bodyHtml = buildInvoiceEmailHtml(inv, company);
  const bodyText =
    'فاتورة مبيعات ' +
    (inv.invoice_no || id) +
    ' — العميل: ' +
    (inv.customer_name || '') +
    ' — الإجمالي: ' +
    fmtMail(inv.total);

  return phpEmailSend(userId, {
    to_email: to,
    subject,
    body_html: bodyHtml,
    body_text: bodyText,
  });
}

function computeLine(raw) {
  const qty = r6(raw.qty);
  const qtyExtra = r6(raw.qty_extra);
  const unitPrice = r6(raw.unit_price);
  const discountPct = r6(raw.discount_pct);
  const taxRate = r6(raw.tax_rate_percent);
  const grossQty = qty; // price on main qty
  let lineSub = grossQty * unitPrice;
  let discAmt = 0;
  if (discountPct > 0) {
    discAmt = r3((lineSub * discountPct) / 100);
    lineSub = r3(lineSub - discAmt);
  } else {
    lineSub = r3(lineSub);
  }
  const taxAmt = r3((lineSub * taxRate) / 100);
  const lineGross = r3(lineSub + taxAmt);
  const unitFactor = Number(raw.unit_factor) > 0 ? Number(raw.unit_factor) : 1;
  return {
    item_id: Number(raw.item_id),
    name_ar: String(raw.name_ar || raw.line_desc || '').trim(),
    qty,
    qty_extra: qtyExtra,
    unit_price: unitPrice,
    discount_pct: discountPct,
    discount_amount: discAmt,
    tax_rate_percent: taxRate,
    tax_amount: taxAmt,
    line_subtotal: lineSub,
    line_total: lineSub,
    line_gross: lineGross,
    unit_id: raw.unit_id ? Number(raw.unit_id) : null,
    unit_name: String(raw.unit_name || '').trim() || null,
    unit_factor: unitFactor,
    qty_base: r6((qty + qtyExtra) * unitFactor),
  };
}

/**
 * تطبيق خصم مستوى الفاتورة: "10" أو "10%" نسبة، "1.000" مبلغ
 */
function applyHeaderDiscount(lines, discountInput) {
  const sumLineSub = r3(lines.reduce((a, l) => a + l.line_total, 0));
  const sumTaxBefore = r3(lines.reduce((a, l) => a + l.tax_amount, 0));
  const sumGrossBefore = r3(lines.reduce((a, l) => a + l.line_gross, 0));
  const raw = String(discountInput || '').trim();
  if (!raw || sumLineSub <= 0) {
    return {
      lines,
      subtotal: sumLineSub,
      tax_amount: sumTaxBefore,
      total: sumGrossBefore,
      header_disc: 0,
    };
  }

  let headerDisc = 0;
  if (raw.endsWith('%')) {
    const pct = parseFloat(raw.slice(0, -1));
    if (pct > 0) headerDisc = r3((sumLineSub * pct) / 100);
  } else if (!raw.includes('.') && Number(raw) >= 1 && Number(raw) <= 100) {
    headerDisc = r3((sumLineSub * Number(raw)) / 100);
  } else {
    headerDisc = r3(parseFloat(raw) || 0);
  }
  headerDisc = Math.min(headerDisc, sumLineSub);
  if (headerDisc <= 0) {
    return {
      lines,
      subtotal: sumLineSub,
      tax_amount: sumTaxBefore,
      total: sumGrossBefore,
      header_disc: 0,
    };
  }

  // نسبّع الخصم على البنود
  let allocated = 0;
  const out = lines.map((l, idx) => {
    const share =
      idx === lines.length - 1
        ? r3(headerDisc - allocated)
        : r3((headerDisc * l.line_total) / sumLineSub);
    allocated = r3(allocated + share);
    const newSub = r3(Math.max(0, l.line_total - share));
    const tax = r3((newSub * l.tax_rate_percent) / 100);
    return {
      ...l,
      line_total: newSub,
      line_subtotal: newSub,
      tax_amount: tax,
      line_gross: r3(newSub + tax),
      discount_amount: r3((l.discount_amount || 0) + share),
    };
  });

  return {
    lines: out,
    subtotal: r3(out.reduce((a, l) => a + l.line_total, 0)),
    tax_amount: r3(out.reduce((a, l) => a + l.tax_amount, 0)),
    total: r3(out.reduce((a, l) => a + l.line_gross, 0)),
    header_disc: headerDisc,
  };
}

async function isPosted(invoiceId) {
  const rows = await db.query(
    `SELECT (${POSTED_SQL}) AS is_posted FROM sal_invoice i WHERE i.id = ? LIMIT 1`,
    [invoiceId]
  );
  const v = rows[0]?.is_posted;
  return !!(v === 1 || v === true || v === '1');
}

async function saveInvoice(payload, userId) {
  const customerId = Number(payload.customer_id || 0);
  if (customerId < 1) {
    return { ok: false, error: 'اختر العميل.' };
  }

  const invoiceDate = parseDateToIso(payload.invoice_date || todayIso());
  const paymentType = payload.payment_type === 'cash' ? 'cash' : 'credit';
  const warehouseId = payload.warehouse_id ? Number(payload.warehouse_id) : null;
  const salesRepId = payload.sales_rep_id ? Number(payload.sales_rep_id) : null;
  const notes = String(payload.notes || '').trim() || null;
  const discountInput = String(payload.invoice_discount || '').trim();
  const invoiceId = Number(payload.id || 0);
  const useWholesale = await itemPricing.customerUsesWholesale(customerId);
  const priceLabel = useWholesale ? 'سعر الجملة' : 'سعر البيع';

  const rawLines = Array.isArray(payload.lines) ? payload.lines : [];
  const offerApply = require('./offerApply');
  const offersSvc = require('./offersService');
  const offered = await offerApply.applyOffersToRawLines(rawLines, invoiceDate);
  const normalized = [];
  for (const ln of offered.lines) {
    if (!ln || !Number(ln.item_id)) continue;
    if (Number(ln.qty) <= 0 && Number(ln.qty_extra) <= 0) continue;
    // السعر من بطاقة المادة (بيع أو جملة حسب العميل) × معامل الوحدة
    const priced = await itemPricing.resolveDocLinePricing(ln, { useWholesale });
    if (!(priced.unit_price > 0)) {
      return {
        ok: false,
        error: `لا يمكن حفظ فاتورة: ${priceLabel} للمادة صفر في البطاقة. حدّد السعر من بطاقة المادة أولاً.`,
      };
    }
    const taxFromItem =
      priced.tax_rate_percent != null && priced.tax_rate_percent !== ''
        ? priced.tax_rate_percent
        : ln.tax_rate_percent;
    normalized.push(
      computeLine({
        ...ln,
        unit_price: priced.unit_price,
        unit_factor: priced.unit_factor,
        unit_id: priced.unit_id,
        unit_name: priced.unit_name,
        tax_rate_percent: taxFromItem,
      })
    );
  }
  const totals = applyHeaderDiscount(normalized, discountInput);
  const pendingOfferApps = offered.applications || [];

  const pool = db.getPool();
  const conn = await pool.getConnection();
  try {
    await conn.beginTransaction();

    if (invoiceId > 0) {
      const posted = await isPosted(invoiceId);
      if (posted) {
        await conn.rollback();
        return { ok: false, error: 'لا يمكن تعديل فاتورة مرحّلة.' };
      }
      // السماح بحفظ بدون بنود (تفريغ) لمسودة — يُحذف رأس السطور
      await conn.execute(
        `UPDATE sal_invoice
         SET invoice_date=?, customer_id=?, sales_rep_id=?, warehouse_id=?, payment_type=?,
             subtotal=?, tax_amount=?, total=?, notes=?, invoice_discount_input=?
         WHERE id=? AND status='confirmed'`,
        [
          invoiceDate,
          customerId,
          salesRepId,
          warehouseId,
          paymentType,
          totals.subtotal,
          totals.tax_amount,
          totals.total,
          notes,
          discountInput || null,
          invoiceId,
        ]
      );
      await conn.execute('DELETE FROM sal_invoice_line WHERE invoice_id = ?', [invoiceId]);
      for (const ln of totals.lines) {
        await insertLine(conn, invoiceId, ln);
      }
      await conn.commit();
      try {
        await offersSvc.clearApplications('invoice', invoiceId);
        if (pendingOfferApps.length) {
          await offersSvc.logApplications(
            pendingOfferApps.map((a) => ({
              ...a,
              doc_type: 'invoice',
              doc_id: invoiceId,
              doc_no: payload.invoice_no || '',
              doc_date: invoiceDate,
            }))
          );
        }
      } catch (e) {
        console.error('offer log invoice', e.message);
      }
      return { ok: true, id: invoiceId, invoice_no: payload.invoice_no || '' };
    }

    if (!normalized.length) {
      await conn.rollback();
      return { ok: false, error: 'أضف بند مادة واحداً على الأقل.' };
    }

    const invoiceNo = await nextInvoiceNo(invoiceDate);
    const [result] = await conn.execute(
      `INSERT INTO sal_invoice
       (invoice_no, invoice_date, customer_id, sales_rep_id, warehouse_id, payment_type,
        subtotal, tax_amount, total, status, notes, invoice_discount_input, created_by)
       VALUES (?,?,?,?,?,?,?,?,?,'confirmed',?,?,?)`,
      [
        invoiceNo,
        invoiceDate,
        customerId,
        salesRepId,
        warehouseId,
        paymentType,
        totals.subtotal,
        totals.tax_amount,
        totals.total,
        notes,
        discountInput || null,
        userId || null,
      ]
    );
    const newId = Number(result.insertId);
    for (const ln of totals.lines) {
      await insertLine(conn, newId, ln);
    }
    await conn.commit();
    try {
      if (pendingOfferApps.length) {
        await offersSvc.logApplications(
          pendingOfferApps.map((a) => ({
            ...a,
            doc_type: 'invoice',
            doc_id: newId,
            doc_no: invoiceNo,
            doc_date: invoiceDate,
          }))
        );
      }
    } catch (e) {
      console.error('offer log invoice new', e.message);
    }
    return { ok: true, id: newId, invoice_no: invoiceNo };
  } catch (e) {
    try {
      await conn.rollback();
    } catch {
      /* ignore */
    }
    console.error('saveInvoice', e);
    return { ok: false, error: e.message || 'تعذر الحفظ.' };
  } finally {
    conn.release();
  }
}

async function insertLine(conn, invoiceId, ln) {
  await conn.execute(
    `INSERT INTO sal_invoice_line
     (invoice_id, item_id, line_desc, qty, qty_extra, unit_price, discount_pct, discount_amount,
      line_total, tax_rate_percent, tax_amount, line_gross, unit_id, unit_name, unit_factor, qty_base)
     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)`,
    [
      invoiceId,
      ln.item_id,
      ln.name_ar || null,
      ln.qty,
      ln.qty_extra,
      ln.unit_price,
      ln.discount_pct,
      ln.discount_amount,
      ln.line_total,
      ln.tax_rate_percent,
      ln.tax_amount,
      ln.line_gross,
      ln.unit_id,
      ln.unit_name,
      ln.unit_factor,
      ln.qty_base,
    ]
  );
}

async function searchCustomers(q, limit = 30) {
  const like = `%${String(q || '').trim()}%`;
  const cols = `id, code, name_ar, phone, COALESCE(use_wholesale_price, 0) AS use_wholesale_price`;
  try {
    if (String(q || '').trim() === '') {
      return db.query(
        `SELECT ${cols} FROM crm_customer
         WHERE is_active = 1 ORDER BY name_ar LIMIT ${Math.min(50, Number(limit) || 30)}`
      );
    }
    return db.query(
      `SELECT ${cols} FROM crm_customer
       WHERE is_active = 1 AND (name_ar LIKE ? OR code LIKE ? OR IFNULL(phone,'') LIKE ?)
       ORDER BY name_ar LIMIT ${Math.min(50, Number(limit) || 30)}`,
      [like, like, like]
    );
  } catch {
    if (String(q || '').trim() === '') {
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
}

async function searchItems(q, limit = 30, opts = {}) {
  const qTrim = String(q || '').trim();
  const like = `%${qTrim}%`;
  const cat = String(opts.cat || opts.category || '').trim();
  const catFilter = cat
    ? ` AND (
         EXISTS (
           SELECT 1 FROM inv_item_category c
           WHERE c.id = inv_item.category_id
             AND (
               TRIM(IFNULL(c.oracle_key,'')) = ?
               OR TRIM(IFNULL(c.code,'')) = ?
               OR TRIM(LEADING '0' FROM IFNULL(c.oracle_key,'')) = TRIM(LEADING '0' FROM ?)
               OR TRIM(LEADING '0' FROM IFNULL(c.code,'')) = TRIM(LEADING '0' FROM ?)
             )
         )
       )`
    : '';
  const catParams = cat ? [cat, cat, cat, cat] : [];
  // code المعروض = الباركود (ثم رقم المادة) · sale_price = أقل وحدة (غير شامل)
  const codeExpr = `COALESCE(NULLIF(TRIM(barcode), ''), sku) AS code`;
  const catCols = `, category_id,
      (SELECT TRIM(IFNULL(c.oracle_key, c.code)) FROM inv_item_category c WHERE c.id = inv_item.category_id LIMIT 1) AS oracle_cat,
      (SELECT c.name_ar FROM inv_item_category c WHERE c.id = inv_item.category_id LIMIT 1) AS category_name`;
  // تطابق تام لرقم المادة / الباركود أولاً ثم البحث الجزئي
  const rankExact = `CASE
      WHEN TRIM(IFNULL(sku,'')) = ? THEN 0
      WHEN TRIM(IFNULL(barcode,'')) = ? THEN 1
      WHEN TRIM(IFNULL(oracle_key,'')) = ? THEN 2
      WHEN TRIM(IFNULL(sku,'')) LIKE ? THEN 3
      WHEN TRIM(IFNULL(barcode,'')) LIKE ? THEN 4
      ELSE 5 END`;
  let rows;
  if (qTrim === '') {
    try {
      rows = await db.query(
        `SELECT id, ${codeExpr}, barcode, sku, name_ar, name_en, unit_id, unit_name,
                COALESCE(default_sale, 0) AS sale_price,
                COALESCE(default_wholesale, 0) AS wholesale_price,
                tax_rate_id${catCols}
         FROM inv_item WHERE is_active = 1${catFilter} ORDER BY name_ar LIMIT ${Math.min(40, limit)}`,
        catParams
      );
    } catch {
      try {
        rows = await db.query(
          `SELECT id, ${codeExpr}, barcode, sku, name_ar, name_en, unit_id, unit_name,
                  COALESCE(default_sale, 0) AS sale_price,
                  COALESCE(default_wholesale, 0) AS wholesale_price,
                  tax_rate_id, category_id
           FROM inv_item WHERE is_active = 1${catFilter} ORDER BY name_ar LIMIT ${Math.min(40, limit)}`,
          catParams
        );
      } catch {
        rows = await db.query(
          `SELECT id, COALESCE(NULLIF(TRIM(barcode), ''), sku) AS code, barcode, sku, name_ar, unit_id, unit_name,
                  COALESCE(default_sale, 0) AS sale_price
           FROM inv_item WHERE is_active = 1 ORDER BY name_ar LIMIT ${Math.min(40, limit)}`
        );
      }
    }
  } else {
    try {
      rows = await db.query(
        `SELECT id, ${codeExpr}, barcode, sku, name_ar, name_en, unit_id, unit_name,
                COALESCE(default_sale, 0) AS sale_price,
                COALESCE(default_wholesale, 0) AS wholesale_price,
                tax_rate_id${catCols}
         FROM inv_item
         WHERE is_active = 1${catFilter}
           AND (name_ar LIKE ? OR IFNULL(name_en,'') LIKE ? OR sku LIKE ? OR barcode LIKE ? OR IFNULL(oracle_key,'') LIKE ?)
         ORDER BY ${rankExact}, name_ar LIMIT ${Math.min(40, limit)}`,
        [...catParams, like, like, like, like, like, qTrim, qTrim, qTrim, like, like]
      );
    } catch {
      try {
        rows = await db.query(
          `SELECT id, ${codeExpr}, barcode, sku, name_ar, name_en, unit_id, unit_name,
                  COALESCE(default_sale, 0) AS sale_price,
                  COALESCE(default_wholesale, 0) AS wholesale_price,
                  tax_rate_id, category_id
           FROM inv_item
           WHERE is_active = 1${catFilter}
             AND (name_ar LIKE ? OR IFNULL(name_en,'') LIKE ? OR sku LIKE ? OR barcode LIKE ? OR IFNULL(oracle_key,'') LIKE ?)
           ORDER BY name_ar LIMIT ${Math.min(40, limit)}`,
          [...catParams, like, like, like, like, like]
        );
      } catch {
        rows = await db.query(
          `SELECT id, COALESCE(NULLIF(TRIM(barcode), ''), sku) AS code, barcode, sku, name_ar, unit_id, unit_name,
                  COALESCE(default_sale, 0) AS sale_price
           FROM inv_item
           WHERE is_active = 1 AND (name_ar LIKE ? OR sku LIKE ? OR barcode LIKE ?)
           ORDER BY
             CASE
               WHEN TRIM(IFNULL(sku,'')) = ? THEN 0
               WHEN TRIM(IFNULL(barcode,'')) = ? THEN 1
               WHEN TRIM(IFNULL(sku,'')) LIKE ? THEN 2
               WHEN TRIM(IFNULL(barcode,'')) LIKE ? THEN 3
               ELSE 4 END,
             name_ar
           LIMIT ${Math.min(40, limit)}`,
          [like, like, like, qTrim, qTrim, like, like]
        );
      }
    }
  }
  return itemPricing.attachUnitsToSearchRows(rows || []);
}

async function listItemCategoriesOracle() {
  const fallbackNames = {
    1: 'فئة أولى',
    2: 'فئة ثانية',
    3: 'فئة ثالثة',
    4: 'فئة رابعة',
    5: 'فئة خامسة',
    6: 'فئة سادسة',
    7: 'فئة سابعة',
    8: 'فئة ثامنة',
    11: 'فئة حادي عشر',
    12: 'فئة ثاني عشر',
    13: 'فئة ثالث عشر',
  };
  try {
    const rows = await db.query(
      `SELECT id,
              TRIM(IFNULL(NULLIF(oracle_key,''), code)) AS cat,
              name_ar AS name
       FROM inv_item_category
       WHERE is_active = 1
         AND (
           (oracle_key IS NOT NULL AND TRIM(oracle_key) <> '')
           OR (code REGEXP '^[0-9]{1,3}$')
         )
       ORDER BY CAST(TRIM(IFNULL(NULLIF(oracle_key,''), code)) AS UNSIGNED), name_ar
       LIMIT 200`
    );
    const mapped = (rows || [])
      .map((r) => ({
        cat: String(r.cat || '').trim(),
        name: String(r.name || '').trim() || fallbackNames[String(r.cat || '').trim()] || ('فئة ' + String(r.cat || '').trim()),
        id: Number(r.id || 0),
      }))
      .filter((r) => r.cat);
    if (mapped.length) return mapped;
  } catch {
    /* fall through */
  }
  try {
    const rows = await db.query(
      `SELECT id, code AS cat, name_ar AS name
       FROM inv_item_category WHERE is_active = 1 ORDER BY name_ar LIMIT 200`
    );
    const mapped = (rows || [])
      .map((r) => ({
        cat: String(r.cat || '').trim(),
        name: String(r.name || '').trim(),
        id: Number(r.id || 0),
      }))
      .filter((r) => r.cat);
    if (mapped.length) return mapped;
  } catch {
    /* fall through */
  }
  return Object.keys(fallbackNames).map((k) => ({
    cat: k,
    name: fallbackNames[k],
    id: 0,
  }));
}

async function lookups() {
  let warehouses = [];
  let taxRates = [];
  let defaultTax = 16;
  try {
    warehouses = await db.query(
      `SELECT id, code, name_ar FROM inv_warehouse WHERE is_active = 1 ORDER BY name_ar`
    );
  } catch {
    warehouses = [];
  }
  try {
    taxRates = await db.query(
      `SELECT id, name_ar, rate_percent FROM sys_tax_rate WHERE is_active = 1 ORDER BY sort_order, id`
    );
  } catch {
    taxRates = [];
  }
  try {
    const s = await db.query(
      `SELECT tax_rate_percent FROM sys_company_settings ORDER BY id LIMIT 1`
    );
    if (s[0] && s[0].tax_rate_percent != null) {
      defaultTax = Number(s[0].tax_rate_percent);
    }
  } catch {
    /* keep default */
  }
  if (!taxRates.length) {
    taxRates = [{ id: 0, name_ar: `افتراضي (${defaultTax}%)`, rate_percent: defaultTax }];
  }
  return { warehouses, tax_rates: taxRates, default_tax: defaultTax };
}

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
  if (fs.existsSync(ini)) {
    args.push('-c', ini);
  }
  args.push(script, action, String(userId || 0));
  return args;
}

function phpAction(action, userId, payload = {}) {
  return new Promise((resolve) => {
    const script = path.join(__dirname, '..', '..', 'cli', 'sal_invoice_action.php');
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
        return resolve({
          ok: false,
          error: err.trim() || 'لا استجابة من PHP.',
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

/** CLI: document_email_send.php <userId> + stdin JSON */
function phpEmailSend(userId, payload) {
  return new Promise((resolve) => {
    const script = path.join(__dirname, '..', '..', 'cli', 'document_email_send.php');
    if (!fs.existsSync(script)) {
      return resolve({ ok: false, error: 'سكربت إرسال البريد غير موجود.' });
    }
    const args = [];
    const ini = process.env.PHP_INI || 'C:\\xampp\\php\\php.ini';
    if (fs.existsSync(ini)) {
      args.push('-c', ini);
    }
    args.push(script, String(userId || 0));
    const child = spawn(phpBin(), args, {
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
          error: err.trim() || 'لا استجابة من خادم البريد.',
        });
      }
      try {
        resolve(JSON.parse(line));
      } catch {
        resolve({ ok: false, error: 'استجابة غير صالحة: ' + line.slice(0, 200) });
      }
    });
    child.stdin.write(JSON.stringify(payload || {}));
    child.stdin.end();
  });
}

/**
 * ترحيل: قيود محاسبية + خصم مستودعي، ثم إرسال للفوترة (اختياري افتراضي true).
 */
async function postInvoice(invoiceId, userId, { autoEinvoice = true } = {}) {
  const id = Number(invoiceId);
  if (id < 1) return { ok: false, error: 'فاتورة غير صالحة.' };
  if (await isPosted(id)) {
    return { ok: false, error: 'الفاتورة مرحّلة مسبقًا.' };
  }
  const post = await phpAction('post', userId, { invoice_id: id });
  if (!post.ok) return post;

  let einvoice = null;
  if (autoEinvoice) {
    einvoice = await phpAction('einvoice', userId, { invoice_id: id });
    const einvMsg = einvoice.ok
      ? einvoice.skipped
        ? ' · الفوترة: ' + (einvoice.message || 'تم التخطي')
        : ' · أُرسلت إلى الفوترة الإلكترونية'
      : ' · تنبيه فوترة: ' + (einvoice.error || einvoice.message || 'فشل الإرسال');
    return {
      ok: true,
      invoice_id: id,
      posted: post.posted,
      message: (post.message || 'تم الترحيل.') + einvMsg,
      einvoice,
      warnings: post.warnings || [],
    };
  }
  return { ...post, invoice_id: id, einvoice: null };
}

async function unpostInvoice(invoiceId, userId) {
  const id = Number(invoiceId);
  if (id < 1) return { ok: false, error: 'فاتورة غير صالحة.' };
  return phpAction('unpost', userId, { invoice_id: id });
}

async function deleteInvoice(invoiceId, userId) {
  const id = Number(invoiceId);
  if (id < 1) return { ok: false, error: 'فاتورة غير صالحة.' };
  if (await isPosted(id)) {
    return { ok: false, error: 'لا يمكن حذف فاتورة مرحّلة. فك الترحيل أولاً.' };
  }
  return phpAction('delete', userId, { invoice_id: id });
}

async function sendEinvoice(invoiceId, userId) {
  const id = Number(invoiceId);
  if (id < 1) return { ok: false, error: 'فاتورة غير صالحة.' };
  if (!(await isPosted(id))) {
    return { ok: false, error: 'يجب ترحيل الفاتورة قبل الإرسال إلى الفوترة.' };
  }
  return phpAction('einvoice', userId, { invoice_id: id });
}

const { neighbors, findIdByNo } = require('../lib/docBrowse');

async function browseNeighbors(id) {
  return neighbors('sal_invoice', id);
}

async function findInvoiceIdByNo(no) {
  return findIdByNo('sal_invoice', 'invoice_no', no);
}

module.exports = {
  listInvoices,
  getInvoice,
  saveInvoice,
  postInvoice,
  unpostInvoice,
  deleteInvoice,
  sendEinvoice,
  sendInvoiceEmail,
  getCustomerEmail,
  searchCustomers,
  searchItems,
  listItemCategoriesOracle,
  lookups,
  isPosted,
  nextInvoiceNo,
  POSTED_SQL,
  phpAction,
  browseNeighbors,
  findInvoiceIdByNo,
};
