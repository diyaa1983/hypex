'use strict';

const db = require('../db');
const { parseDateToIso, todayIso } = require('../lib/html');
const itemPricing = require('../lib/itemPricing');
const companyDecimals = require('../lib/companyDecimals');

function r3(n) {
  return companyDecimals.roundAmount(n);
}

function r6(n) {
  return companyDecimals.roundUnit(n);
}

let paymentTypeReady = false;
async function ensurePaymentTypeColumn() {
  if (paymentTypeReady) return true;
  try {
    await db.query(`SELECT payment_type FROM sal_customer_order LIMIT 1`);
    paymentTypeReady = true;
    return true;
  } catch {
    try {
      await db.query(
        `ALTER TABLE sal_customer_order ADD COLUMN payment_type ENUM('credit','cash') NOT NULL DEFAULT 'credit'`
      );
      paymentTypeReady = true;
      return true;
    } catch {
      return false;
    }
  }
}

function normalizePaymentType(v) {
  return String(v || '').toLowerCase() === 'cash' ? 'cash' : 'credit';
}

async function nextOrderNo(orderDate) {
  const iso = parseDateToIso(orderDate);
  const year = iso.slice(0, 4);
  const suffix = `-${year}`;
  const rows = await db.query(
    `SELECT order_no FROM sal_customer_order WHERE order_no LIKE ?`,
    [`%${suffix}`]
  );
  let maxSeq = 0;
  const re = new RegExp(`^CO(\\d+)${suffix.replace('-', '\\-')}$`);
  for (const row of rows) {
    const m = String(row.order_no || '').match(re);
    if (m) maxSeq = Math.max(maxSeq, Number(m[1]));
  }
  return `CO${String(maxSeq + 1).padStart(3, '0')}${suffix}`;
}

async function getOrder(id) {
  await ensurePaymentTypeColumn();
  const orderId = Number(id);
  if (!orderId) return null;
  const headers = await db.query(
    `SELECT o.*, c.name_ar AS customer_name, c.code AS customer_code,
            COALESCE(c.use_wholesale_price, 0) AS use_wholesale_price,
            w.name_ar AS warehouse_name,
            COALESCE(r.name_ar, '') AS sales_rep_name
     FROM sal_customer_order o
     INNER JOIN crm_customer c ON c.id = o.customer_id
     INNER JOIN inv_warehouse w ON w.id = o.warehouse_id
     LEFT JOIN crm_sales_rep r ON r.id = o.sales_rep_id
     WHERE o.id = ?
     LIMIT 1`,
    [orderId]
  );
  if (!headers[0]) return null;
  const h = headers[0];
  let lines = [];
  try {
    lines = await db.query(
      `SELECT l.*,
              COALESCE(NULLIF(TRIM(i.barcode), ''), i.sku) AS item_code,
              COALESCE(NULLIF(TRIM(i.barcode), ''), i.sku) AS item_sku,
              COALESCE(NULLIF(TRIM(l.item_name), ''), i.name_ar, '') AS item_name_resolved,
              COALESCE(NULLIF(TRIM(l.unit_name), ''), NULLIF(TRIM(i.unit_name), ''), 'قطعة') AS unit_name_resolved,
              COALESCE(i.default_sale, 0) AS item_default_sale
       FROM sal_customer_order_line l
       LEFT JOIN inv_item i ON i.id = l.item_id
       WHERE l.order_id = ?
       ORDER BY l.line_no, l.id`,
      [orderId]
    );
  } catch {
    lines = await db.query(
      `SELECT l.*,
              COALESCE(NULLIF(TRIM(i.barcode), ''), i.sku) AS item_code,
              COALESCE(NULLIF(TRIM(i.barcode), ''), i.sku) AS item_sku,
              COALESCE(NULLIF(TRIM(l.item_name), ''), i.name_ar, '') AS item_name_resolved,
              COALESCE(NULLIF(TRIM(l.unit_name), ''), 'قطعة') AS unit_name_resolved,
              COALESCE(i.default_sale, 0) AS item_default_sale
       FROM sal_customer_order_line l
       LEFT JOIN inv_item i ON i.id = l.item_id
       WHERE l.order_id = ?
       ORDER BY l.line_no, l.id`,
      [orderId]
    );
  }

  const status = String(h.status || 'draft');
  const mappedLines = [];
  for (const ln of lines) {
    const itemId = Number(ln.item_id);
    let units = [];
    let baseSale = Number(ln.item_default_sale || 0);
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
      item_code: ln.item_code || ln.item_sku || '',
      item_barcode: ln.item_code || ln.item_sku || '',
      name_ar: ln.item_name_resolved || ln.item_name || '',
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
      unit_name: ln.unit_name_resolved || ln.unit_name || 'قطعة',
      unit_factor: Number(ln.unit_factor || 1),
      qty_base: Number(ln.qty_base || ln.qty || 0),
      units,
    });
  }

  return {
    id: Number(h.id),
    order_no: h.order_no,
    order_date: h.order_date,
    customer_id: Number(h.customer_id),
    customer_name: h.customer_name,
    customer_code: h.customer_code,
    use_wholesale_price: Number(h.use_wholesale_price) === 1 ? 1 : 0,
    sales_rep_id: h.sales_rep_id != null ? Number(h.sales_rep_id) : null,
    sales_rep_name: h.sales_rep_name || '',
    warehouse_id: Number(h.warehouse_id),
    warehouse_name: h.warehouse_name || '',
    payment_type: normalizePaymentType(h.payment_type),
    notes: h.notes || '',
    status,
    is_approved: status === 'approved',
    status_label: status === 'approved' ? 'معتمد' : 'مسودة',
    subtotal: Number(h.subtotal || 0),
    discount_amount: Number(h.discount_amount || 0),
    tax_amount: Number(h.tax_amount || 0),
    total: Number(h.total || 0),
    invoice_discount_input: h.invoice_discount_input || '',
    oracle_v_num: Number(h.oracle_v_num || 0) || 0,
    oracle_vyear: Number(h.oracle_vyear || 0) || 0,
    oracle_post_status: h.oracle_post_status || '',
    oracle_post_message: h.oracle_post_message || '',
    lines: mappedLines,
  };
}

function computeLine(raw, defaultTax) {
  const qty = Math.max(0, r6(raw.qty));
  const qtyExtra = Math.max(0, r6(raw.qty_extra));
  const unitPrice = r6(raw.unit_price);
  const discountPct = r6(raw.discount_pct);
  const taxRate =
    raw.tax_rate_percent != null && raw.tax_rate_percent !== ''
      ? r6(raw.tax_rate_percent)
      : Number(defaultTax) || 0;
  let lineSub = qty * unitPrice;
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
    name_ar: String(raw.name_ar || raw.item_name || '').trim(),
    qty,
    qty_extra: qtyExtra,
    unit_price: unitPrice,
    discount_pct: discountPct,
    discount_amount: discAmt,
    tax_rate_percent: taxRate,
    tax_amount: taxAmt,
    line_total: lineSub,
    line_gross: lineGross,
    unit_id: raw.unit_id ? Number(raw.unit_id) : null,
    unit_name: String(raw.unit_name || '').trim() || null,
    unit_factor: unitFactor,
    qty_base: r6((qty + qtyExtra) * unitFactor),
    notes: String(raw.notes || '').trim() || null,
  };
}

function applyHeaderDiscount(lines, discountInput) {
  const sumLineSub = r3(lines.reduce((a, l) => a + l.line_total, 0));
  const sumTaxBefore = r3(lines.reduce((a, l) => a + l.tax_amount, 0));
  const sumGrossBefore = r3(lines.reduce((a, l) => a + l.line_gross, 0));
  const raw = String(discountInput || '').trim();
  if (!raw || sumLineSub <= 0) {
    return {
      lines,
      subtotal: sumLineSub,
      discount_amount: r3(lines.reduce((a, l) => a + (l.discount_amount || 0), 0)),
      tax_amount: sumTaxBefore,
      total: sumGrossBefore,
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
      discount_amount: r3(lines.reduce((a, l) => a + (l.discount_amount || 0), 0)),
      tax_amount: sumTaxBefore,
      total: sumGrossBefore,
    };
  }

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
      tax_amount: tax,
      line_gross: r3(newSub + tax),
      discount_amount: r3((l.discount_amount || 0) + share),
    };
  });

  return {
    lines: out,
    subtotal: r3(out.reduce((a, l) => a + l.line_total, 0)),
    discount_amount: r3(out.reduce((a, l) => a + (l.discount_amount || 0), 0)),
    tax_amount: r3(out.reduce((a, l) => a + l.tax_amount, 0)),
    total: r3(out.reduce((a, l) => a + l.line_gross, 0)),
  };
}

async function saveOrder(payload, userId) {
  await ensurePaymentTypeColumn();
  const customerId = Number(payload.customer_id || 0);
  const warehouseId = Number(payload.warehouse_id || 0);
  if (customerId < 1 || warehouseId < 1) {
    return { ok: false, error: 'العميل والمستودع مطلوبان.' };
  }

  const orderDate = parseDateToIso(payload.order_date || todayIso());
  const salesRepId = payload.sales_rep_id ? Number(payload.sales_rep_id) : null;
  const paymentType = normalizePaymentType(payload.payment_type);
  const notes = String(payload.notes || '').trim() || null;
  const discountInput = String(payload.invoice_discount || payload.invoice_discount_input || '').trim();
  const orderId = Number(payload.id || 0);
  const useWholesale = await itemPricing.customerUsesWholesale(customerId);
  const priceLabel = useWholesale ? 'سعر الجملة' : 'سعر البيع';

  let defaultTax = 16;
  try {
    const s = await db.query(`SELECT tax_rate_percent FROM sys_company_settings ORDER BY id LIMIT 1`);
    if (s[0] && s[0].tax_rate_percent != null) defaultTax = Number(s[0].tax_rate_percent);
  } catch {
    /* keep */
  }

  const rawLines = Array.isArray(payload.lines) ? payload.lines : [];
  const offerApply = require('./offerApply');
  const offersSvc = require('./offersService');
  const offered = await offerApply.applyOffersToRawLines(rawLines, orderDate);
  const normalized = [];
  for (const ln of offered.lines) {
    if (!ln || !Number(ln.item_id)) continue;
    if (Number(ln.qty) < 1) continue;
    const priced = await itemPricing.resolveDocLinePricing(ln, { useWholesale });
    if (!(priced.unit_price > 0)) {
      return {
        ok: false,
        error: `لا يمكن حفظ الطلب: ${priceLabel} للمادة صفر في البطاقة. عدّل السعر من بطاقة المادة.`,
      };
    }
    const taxFromItem =
      priced.tax_rate_percent != null && priced.tax_rate_percent !== ''
        ? priced.tax_rate_percent
        : ln.tax_rate_percent;
    const computed = computeLine(
      {
        ...ln,
        unit_price: priced.unit_price,
        unit_factor: priced.unit_factor,
        unit_id: priced.unit_id,
        unit_name: priced.unit_name,
        tax_rate_percent: taxFromItem,
      },
      defaultTax
    );
    if (!computed.name_ar) {
      const rows = await db.query(`SELECT name_ar FROM inv_item WHERE id = ? LIMIT 1`, [computed.item_id]);
      computed.name_ar = String(rows[0]?.name_ar || '');
    }
    if (!computed.name_ar) {
      return { ok: false, error: 'صنف غير صالح في أحد البنود.' };
    }
    normalized.push(computed);
  }
  if (!normalized.length) {
    return { ok: false, error: 'أدخل بنداً واحداً بكمية موجبة على الأقل.' };
  }

  const totals = applyHeaderDiscount(normalized, discountInput);
  const pendingOfferApps = offered.applications || [];
  const pool = db.getPool();
  const conn = await pool.getConnection();
  try {
    await conn.beginTransaction();

    if (orderId > 0) {
      const [rows] = await conn.execute(
        `SELECT status, sales_rep_id FROM sal_customer_order WHERE id = ? FOR UPDATE`,
        [orderId]
      );
      const old = rows[0];
      if (!old) {
        await conn.rollback();
        return { ok: false, error: 'الطلب غير موجود.' };
      }
      if (String(old.status) !== 'draft') {
        await conn.rollback();
        return { ok: false, error: 'لا يمكن تعديل طلب معتمد. فك الاعتماد أولاً.' };
      }
      const repToStore = salesRepId > 0 ? salesRepId : old.sales_rep_id || null;
      try {
        await conn.execute(
          `UPDATE sal_customer_order
           SET order_date=?, customer_id=?, sales_rep_id=?, warehouse_id=?, payment_type=?, notes=?,
               subtotal=?, discount_amount=?, tax_amount=?, total=?, invoice_discount_input=?, updated_by=?
           WHERE id=?`,
          [
            orderDate,
            customerId,
            repToStore,
            warehouseId,
            paymentType,
            notes,
            totals.subtotal,
            totals.discount_amount,
            totals.tax_amount,
            totals.total,
            discountInput || null,
            userId || null,
            orderId,
          ]
        );
      } catch {
        await conn.execute(
          `UPDATE sal_customer_order
           SET order_date=?, customer_id=?, sales_rep_id=?, warehouse_id=?, notes=?, updated_by=?
           WHERE id=?`,
          [orderDate, customerId, repToStore, warehouseId, notes, userId || null, orderId]
        );
      }
      await conn.execute(`DELETE FROM sal_customer_order_line WHERE order_id = ?`, [orderId]);
      await insertLines(conn, orderId, totals.lines);
      await conn.commit();
      try {
        await offersSvc.clearApplications('order', orderId);
        if (pendingOfferApps.length) {
          const ordNo = (await getOrder(orderId))?.order_no || '';
          await offersSvc.logApplications(
            pendingOfferApps.map((a) => ({
              ...a,
              doc_type: 'order',
              doc_id: orderId,
              doc_no: ordNo,
              doc_date: orderDate,
            }))
          );
        }
      } catch (e) {
        console.error('offer log order', e.message);
      }
      const ord = await getOrder(orderId);
      return { ok: true, id: orderId, order_no: ord?.order_no || payload.order_no || '', order: ord };
    }

    const orderNo = await nextOrderNo(orderDate);
    let newId;
    try {
      const [result] = await conn.execute(
        `INSERT INTO sal_customer_order
         (order_no, order_date, customer_id, sales_rep_id, warehouse_id, payment_type, notes,
          subtotal, discount_amount, tax_amount, total, invoice_discount_input, created_by, updated_by)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)`,
        [
          orderNo,
          orderDate,
          customerId,
          salesRepId > 0 ? salesRepId : null,
          warehouseId,
          paymentType,
          notes,
          totals.subtotal,
          totals.discount_amount,
          totals.tax_amount,
          totals.total,
          discountInput || null,
          userId || null,
          userId || null,
        ]
      );
      newId = Number(result.insertId);
    } catch {
      const [result] = await conn.execute(
        `INSERT INTO sal_customer_order
         (order_no, order_date, customer_id, sales_rep_id, warehouse_id, notes, created_by, updated_by)
         VALUES (?,?,?,?,?,?,?,?)`,
        [
          orderNo,
          orderDate,
          customerId,
          salesRepId > 0 ? salesRepId : null,
          warehouseId,
          notes,
          userId || null,
          userId || null,
        ]
      );
      newId = Number(result.insertId);
    }
    await insertLines(conn, newId, totals.lines);
    await conn.commit();
    try {
      if (pendingOfferApps.length) {
        await offersSvc.logApplications(
          pendingOfferApps.map((a) => ({
            ...a,
            doc_type: 'order',
            doc_id: newId,
            doc_no: orderNo,
            doc_date: orderDate,
          }))
        );
      }
    } catch (e) {
      console.error('offer log order new', e.message);
    }
    const ord = await getOrder(newId);
    return { ok: true, id: newId, order_no: orderNo, order: ord };
  } catch (e) {
    try {
      await conn.rollback();
    } catch {
      /* ignore */
    }
    console.error('saveOrder', e);
    return { ok: false, error: e.message || 'تعذر الحفظ.' };
  } finally {
    conn.release();
  }
}

async function insertLines(conn, orderId, lines) {
  let n = 0;
  for (const ln of lines) {
    n += 1;
    try {
      await conn.execute(
        `INSERT INTO sal_customer_order_line
         (order_id, line_no, item_id, item_name, unit_id, unit_name, unit_factor, qty, qty_extra, qty_base,
          unit_price, discount_pct, discount_amount, line_total, tax_rate_percent, tax_amount, line_gross, notes)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)`,
        [
          orderId,
          n,
          ln.item_id,
          ln.name_ar,
          ln.unit_id,
          ln.unit_name,
          ln.unit_factor,
          ln.qty,
          ln.qty_extra,
          ln.qty_base,
          ln.unit_price,
          ln.discount_pct,
          ln.discount_amount,
          ln.line_total,
          ln.tax_rate_percent,
          ln.tax_amount,
          ln.line_gross,
          ln.notes,
        ]
      );
    } catch {
      try {
        await conn.execute(
          `INSERT INTO sal_customer_order_line
           (order_id, line_no, item_id, item_name, unit_id, unit_name, qty, qty_extra,
            unit_price, discount_pct, discount_amount, line_total, tax_rate_percent, tax_amount, line_gross, notes)
           VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)`,
          [
            orderId,
            n,
            ln.item_id,
            ln.name_ar,
            ln.unit_id,
            ln.unit_name,
            ln.qty,
            ln.qty_extra,
            ln.unit_price,
            ln.discount_pct,
            ln.discount_amount,
            ln.line_total,
            ln.tax_rate_percent,
            ln.tax_amount,
            ln.line_gross,
            ln.notes,
          ]
        );
      } catch {
        await conn.execute(
          `INSERT INTO sal_customer_order_line
           (order_id, line_no, item_id, item_name, unit_id, unit_name, qty, notes)
           VALUES (?,?,?,?,?,?,?,?)`,
          [orderId, n, ln.item_id, ln.name_ar, ln.unit_id, ln.unit_name, ln.qty, ln.notes]
        );
      }
    }
  }
}

async function setApproved(id, approved, userId) {
  const orderId = Number(id);
  if (!orderId) return { ok: false, error: 'معرّف غير صالح.' };
  const pool = db.getPool();
  const conn = await pool.getConnection();
  try {
    await conn.beginTransaction();
    const [rows] = await conn.execute(
      `SELECT status FROM sal_customer_order WHERE id = ? FOR UPDATE`,
      [orderId]
    );
    const status = rows[0]?.status;
    if (status == null) {
      await conn.rollback();
      return { ok: false, error: 'الطلب غير موجود.' };
    }
    if (approved && status !== 'draft') {
      await conn.rollback();
      return { ok: false, error: 'الطلب معتمد بالفعل.' };
    }
    if (!approved && status !== 'approved') {
      await conn.rollback();
      return { ok: false, error: 'الطلب ليس معتمداً.' };
    }
    if (approved) {
      await conn.execute(
        `UPDATE sal_customer_order SET status='approved', approved_by=?, approved_at=NOW(), updated_by=? WHERE id=?`,
        [userId || null, userId || null, orderId]
      );
    } else {
      await conn.execute(
        `UPDATE sal_customer_order SET status='draft', approved_by=NULL, approved_at=NULL, updated_by=? WHERE id=?`,
        [userId || null, orderId]
      );
    }
    await conn.commit();
    return { ok: true, id: orderId, order: await getOrder(orderId) };
  } catch (e) {
    try {
      await conn.rollback();
    } catch {
      /* ignore */
    }
    return { ok: false, error: e.message || 'تعذر تحديث الحالة.' };
  } finally {
    conn.release();
  }
}

async function countVisitOrders(conn, routeLineId) {
  const lineId = Number(routeLineId);
  if (!lineId) return 0;
  try {
    const [rows] = await conn.execute(
      `SELECT COUNT(*) AS c
       FROM sal_customer_order o
       INNER JOIN sal_rep_route_line l ON l.id = o.visit_route_line_id
       WHERE o.visit_route_line_id = ?
         AND l.visit_checkin_at IS NOT NULL
         AND o.created_at >= l.visit_checkin_at`,
      [lineId]
    );
    return Number(rows[0]?.c || 0);
  } catch {
    return 0;
  }
}

async function resetVisitLine(conn, routeLineId, note) {
  const lineId = Number(routeLineId);
  if (!lineId) return false;
  const [lines] = await conn.execute(
    `SELECT l.id, l.customer_id, l.visit_checkin_at, r.sales_rep_id, r.route_date
     FROM sal_rep_route_line l
     INNER JOIN sal_rep_route r ON r.id = l.route_id
     WHERE l.id = ? LIMIT 1`,
    [lineId]
  );
  const line = lines[0];
  if (!line || !line.visit_checkin_at) return false;
  const now = new Date().toISOString().slice(0, 19).replace('T', ' ');
  const decisionNote = note || 'أُلغيت الزيارة مع حذف آخر طلب مرتبط';
  try {
    await conn.execute(`DELETE FROM sal_rep_visit_no_order_reason WHERE route_line_id = ?`, [lineId]);
  } catch {
    /* ignore */
  }
  try {
    await conn.execute(
      `UPDATE sal_rep_visit_checkout_request
       SET status='rejected', decided_at=?, decision_note=?
       WHERE route_line_id=? AND status='pending'`,
      [now, decisionNote, lineId]
    );
  } catch {
    /* ignore */
  }
  await conn.execute(
    `UPDATE sal_rep_route_line SET
       visit_checkin_at=NULL, visit_checkout_at=NULL,
       checkin_method=NULL, checkout_method=NULL,
       checkin_lat=NULL, checkin_lng=NULL, checkin_accuracy=NULL, checkin_distance_m=NULL,
       checkout_lat=NULL, checkout_lng=NULL, checkout_accuracy=NULL, checkout_distance_m=NULL
     WHERE id=?`,
    [lineId]
  );
  try {
    const routeDate = String(line.route_date || '').slice(0, 10);
    const dow = new Date(`${routeDate}T12:00:00`).getDay();
    const [tours] = await conn.execute(
      `SELECT tl.id FROM sal_rep_tour_line tl
       INNER JOIN sal_rep_tour t ON t.id = tl.tour_id
       WHERE t.sales_rep_id = ? AND t.status = 'posted'
         AND t.date_from <= ? AND t.date_to >= ?
         AND tl.weekday = ? AND tl.customer_id = ?
       ORDER BY t.id DESC, tl.id DESC LIMIT 1`,
      [Number(line.sales_rep_id), routeDate, routeDate, dow, Number(line.customer_id)]
    );
    if (tours[0]?.id) {
      await conn.execute(
        `UPDATE sal_rep_tour_line
         SET visit_checkin_at=NULL, visit_checkout_at=NULL, checkin_method=NULL, checkout_method=NULL
         WHERE id=?`,
        [Number(tours[0].id)]
      );
    }
  } catch {
    /* ignore */
  }
  return true;
}

async function afterOrderDeletedVisitCleanup(conn, visitRouteLineId) {
  const lineId = Number(visitRouteLineId);
  if (!lineId) return false;
  const remaining = await countVisitOrders(conn, lineId);
  if (remaining > 0) return false;
  return resetVisitLine(conn, lineId);
}

async function deleteOrder(id) {
  const orderId = Number(id);
  if (!orderId) return { ok: false, error: 'معرّف غير صالح.' };
  const pool = db.getPool();
  const conn = await pool.getConnection();
  try {
    await conn.beginTransaction();
    let visitLineId = 0;
    try {
      const [rows] = await conn.execute(
        `SELECT status, visit_route_line_id FROM sal_customer_order WHERE id = ? FOR UPDATE`,
        [orderId]
      );
      const row = rows[0];
      visitLineId = Number(row?.visit_route_line_id || 0);
      const status = row?.status;
      if (status == null) {
        await conn.rollback();
        return { ok: false, error: 'الطلب غير موجود.' };
      }
      if (String(status) !== 'draft') {
        await conn.rollback();
        return { ok: false, error: 'لا يمكن حذف طلب معتمد. فك الاعتماد أولاً.' };
      }
    } catch {
      const [rows] = await conn.execute(
        `SELECT status FROM sal_customer_order WHERE id = ? FOR UPDATE`,
        [orderId]
      );
      const status = rows[0]?.status;
      if (status == null) {
        await conn.rollback();
        return { ok: false, error: 'الطلب غير موجود.' };
      }
      if (String(status) !== 'draft') {
        await conn.rollback();
        return { ok: false, error: 'لا يمكن حذف طلب معتمد. فك الاعتماد أولاً.' };
      }
    }
    await conn.execute(`DELETE FROM sal_customer_order_line WHERE order_id = ?`, [orderId]);
    await conn.execute(`DELETE FROM sal_customer_order WHERE id = ?`, [orderId]);
    const visitReset = visitLineId > 0 ? await afterOrderDeletedVisitCleanup(conn, visitLineId) : false;
    await conn.commit();
    return {
      ok: true,
      visit_reset: visitReset,
      visit_route_line_id: visitLineId > 0 ? visitLineId : null,
      message: visitReset ? 'تم حذف الطلب وإلغاء تسجيل الزيارة.' : 'تم حذف الطلب.',
    };
  } catch (e) {
    try {
      await conn.rollback();
    } catch {
      /* ignore */
    }
    return { ok: false, error: e.message || 'تعذر الحذف.' };
  } finally {
    conn.release();
  }
}

async function lookups() {
  const inv = require('./invoicesService');
  const base = await inv.lookups();
  let reps = [];
  try {
    reps = await db.query(
      `SELECT id, code, name_ar FROM crm_sales_rep WHERE is_active = 1 ORDER BY name_ar`
    );
  } catch {
    try {
      reps = await db.query(`SELECT id, code, name_ar FROM crm_sales_rep ORDER BY name_ar`);
    } catch {
      reps = [];
    }
  }
  return { ...base, sales_reps: reps };
}

const { neighbors, findIdByNo } = require('../lib/docBrowse');

async function browseNeighbors(id) {
  return neighbors('sal_customer_order', id);
}

async function findOrderIdByNo(no) {
  return findIdByNo('sal_customer_order', 'order_no', no);
}

/**
 * ملخص رصيد العميل (مدين/دائن) + الشيكات قيد التحصيل من Oracle.
 */
function getCustomerArSummary(customerId) {
  const { spawn } = require('child_process');
  const path = require('path');
  const fs = require('fs');
  const cid = Number(customerId) || 0;

  return new Promise((resolve) => {
    if (cid < 1) {
      return resolve({ ok: false, message: 'اختر العميل أولاً.' });
    }
    const script = path.join(__dirname, '..', '..', 'cli', 'oracle_customer_ar_summary.php');
    if (!fs.existsSync(script)) {
      return resolve({ ok: false, message: 'سكربت Oracle غير موجود.' });
    }
    let phpBin = process.env.PHP_BIN || 'php';
    for (const c of [process.env.PHP_BIN, 'C:\\xampp\\php\\php.exe', 'C:\\xampp\\php\\php', 'php']) {
      if (!c) continue;
      if (c === 'php' || fs.existsSync(c)) {
        phpBin = c;
        break;
      }
    }
    const args = [];
    const ini = process.env.PHP_INI || 'C:\\xampp\\php\\php.ini';
    if (fs.existsSync(ini)) args.push('-c', ini);
    args.push(script, String(cid));

    const root = path.resolve(__dirname, '..', '..', '..');
    const child = spawn(phpBin, args, { cwd: root, windowsHide: true });
    let out = '';
    let err = '';
    child.stdout.on('data', (d) => {
      out += String(d);
    });
    child.stderr.on('data', (d) => {
      err += String(d);
    });
    child.on('error', (e) => resolve({ ok: false, message: e.message }));
    child.on('close', () => {
      const line = out
        .split(/\r?\n/)
        .map((s) => s.trim())
        .filter(Boolean)
        .pop();
      if (!line) {
        return resolve({
          ok: false,
          message: err.trim() || 'لا استجابة من Oracle/PHP.',
        });
      }
      try {
        resolve(JSON.parse(line));
      } catch {
        resolve({ ok: false, message: line.slice(0, 280) });
      }
    });
  });
}

function postOrderToOracle(orderId, userId, dryRun = false) {
  const { spawn } = require('child_process');
  const path = require('path');
  const fs = require('fs');
  const id = Number(orderId) || 0;
  const uid = Number(userId) || 0;

  return new Promise((resolve) => {
    if (id < 1) {
      return resolve({ ok: false, message: 'طلب غير صالح.', error: 'طلب غير صالح.' });
    }
    const script = path.join(__dirname, '..', '..', 'cli', 'oracle_order_post.php');
    if (!fs.existsSync(script)) {
      return resolve({ ok: false, message: 'سكربت ترحيل Oracle غير موجود.', error: 'سكربت ترحيل Oracle غير موجود.' });
    }
    let phpBin = process.env.PHP_BIN || 'php';
    for (const c of [process.env.PHP_BIN, 'C:\\xampp\\php\\php.exe', 'C:\\xampp\\php\\php', 'php']) {
      if (!c) continue;
      if (c === 'php' || fs.existsSync(c)) {
        phpBin = c;
        break;
      }
    }
    const args = [];
    const ini = process.env.PHP_INI || 'C:\\xampp\\php\\php.ini';
    if (fs.existsSync(ini)) args.push('-c', ini);
    args.push(script, String(id), String(uid));
    if (dryRun) args.push('--dry');

    const root = path.resolve(__dirname, '..', '..', '..');
    const child = spawn(phpBin, args, { cwd: root, windowsHide: true });
    let out = '';
    let err = '';
    child.stdout.on('data', (d) => {
      out += String(d);
    });
    child.stderr.on('data', (d) => {
      err += String(d);
    });
    child.on('error', (e) =>
      resolve({ ok: false, message: e.message, error: e.message })
    );
    child.on('close', () => {
      const line = out
        .split(/\r?\n/)
        .map((s) => s.trim())
        .filter(Boolean)
        .pop();
      if (!line) {
        const msg = err.trim() || 'لا استجابة من Oracle/PHP.';
        return resolve({ ok: false, message: msg, error: msg });
      }
      try {
        const parsed = JSON.parse(line);
        if (parsed && parsed.message && !parsed.error) parsed.error = parsed.message;
        resolve(parsed);
      } catch {
        resolve({ ok: false, message: line.slice(0, 280), error: line.slice(0, 280) });
      }
    });
  });
}

module.exports = {
  getOrder,
  saveOrder,
  setApproved,
  deleteOrder,
  nextOrderNo,
  lookups,
  browseNeighbors,
  findOrderIdByNo,
  getCustomerArSummary,
  postOrderToOracle,
};
