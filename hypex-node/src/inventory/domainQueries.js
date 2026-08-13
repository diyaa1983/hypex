'use strict';

const db = require('../db');
const { todayIso, parseDateToIso } = require('../lib/html');

/** المعرّف الظاهر: الباركود (رقم المادة/sku فقط في بطاقة المادة) */
function ITEM_CODE_SQL(alias = 'i') {
  return `COALESCE(NULLIF(TRIM(${alias}.barcode), ''), ${alias}.sku)`;
}

function monthStart() {
  const d = new Date();
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-01`;
}

async function safeQuery(sql, params = []) {
  try {
    return await db.query(sql, params);
  } catch (e) {
    console.error('inventory query', e.message);
    return [];
  }
}

function dateRange(from, to) {
  return {
    from: parseDateToIso(from || '', monthStart()),
    to: parseDateToIso(to || '', todayIso()),
  };
}

async function listWarehouses({ q = '', activeOnly = true } = {}) {
  const where = ['1=1'];
  const params = [];
  if (activeOnly) where.push('w.is_active = 1');
  if (q) {
    const like = `%${q}%`;
    where.push(`(w.name_ar LIKE ? OR IFNULL(w.code,'') LIKE ?)`);
    params.push(like, like);
  }
  return safeQuery(
    `SELECT w.id, w.code, w.name_ar, w.is_active
     FROM inv_warehouse w
     WHERE ${where.join(' AND ')}
     ORDER BY w.name_ar LIMIT 200`,
    params
  );
}

/** المستودع الافتراضي: MAIN ثم اسم يحتوي «رئيس» ثم أول مستودع. */
function resolveDefaultWarehouseId(warehouses) {
  const list = Array.isArray(warehouses) ? warehouses : [];
  if (!list.length) return 0;
  const byMain = list.find((w) => String(w.code || '').trim().toUpperCase() === 'MAIN');
  if (byMain) return Number(byMain.id) || 0;
  const byName = list.find((w) => String(w.name_ar || '').includes('رئيس'));
  if (byName) return Number(byName.id) || 0;
  return Number(list[0].id) || 0;
}

async function listItems({ q = '', activeOnly = true, limit = 200 } = {}) {
  const where = ['1=1'];
  const params = [];
  if (activeOnly) where.push('i.is_active = 1');
  if (q) {
    const like = `%${q}%`;
    where.push(`(i.name_ar LIKE ? OR IFNULL(i.sku,'') LIKE ? OR IFNULL(i.barcode,'') LIKE ?)`);
    params.push(like, like, like);
  }
  const lim = Math.min(5000, Math.max(50, Number(limit) || 200));
  return safeQuery(
    `SELECT i.id, i.sku, i.barcode, i.name_ar, i.default_cost, i.default_sale, i.is_active,
            c.name_ar AS category_name, w.name_ar AS warehouse_name
     FROM inv_item i
     LEFT JOIN inv_item_category c ON c.id = i.category_id
     LEFT JOIN inv_warehouse w ON w.id = i.default_warehouse_id
     WHERE ${where.join(' AND ')}
     ORDER BY i.name_ar, i.id
     LIMIT ${lim}`,
    params
  );
}

async function listCategories() {
  return safeQuery(
    `SELECT c.id, c.code, c.name_ar, c.is_active,
            (SELECT COUNT(*) FROM inv_item i WHERE i.category_id = c.id) AS item_count
     FROM inv_item_category c
     ORDER BY c.name_ar LIMIT 300`
  );
}

async function listUnits() {
  return safeQuery(`SELECT id, code, name_ar, is_active FROM inv_unit ORDER BY name_ar LIMIT 300`);
}

async function listMovementTypes() {
  return safeQuery(`SELECT * FROM inv_movement_type ORDER BY id LIMIT 200`);
}

async function listMoves({ q = '', from, to } = {}) {
  const where = ['1=1'];
  const params = [];
  if (from && to) {
    where.push('m.move_date BETWEEN ? AND ?');
    params.push(from, to);
  }
  if (q) {
    where.push(`(m.move_no LIKE ? OR IFNULL(m.movement_type_code,'') LIKE ?)`);
    params.push(`%${q}%`, `%${q}%`);
  }
  return safeQuery(
    `SELECT m.id, m.move_no, m.move_date, m.movement_type_code, m.status,
            w.name_ar AS warehouse_name, wt.name_ar AS warehouse_to_name
     FROM inv_wh_move m
     LEFT JOIN inv_warehouse w ON w.id = m.warehouse_id
     LEFT JOIN inv_warehouse wt ON wt.id = m.warehouse_to_id
     WHERE ${where.join(' AND ')}
     ORDER BY m.id DESC LIMIT 150`,
    params
  );
}

async function listStocktakeDocs({ q = '' } = {}) {
  const where = ['1=1'];
  const params = [];
  if (q) {
    where.push(`(d.take_no LIKE ? OR IFNULL(d.notes,'') LIKE ?)`);
    params.push(`%${q}%`, `%${q}%`);
  }
  return safeQuery(
    `SELECT d.id, d.take_no AS doc_no, d.take_date AS doc_date, d.status, d.notes, w.name_ar AS warehouse_name
     FROM inv_stocktake_doc d
     LEFT JOIN inv_warehouse w ON w.id = d.warehouse_id
     WHERE ${where.join(' AND ')}
     ORDER BY d.id DESC LIMIT 100`,
    params
  );
}

async function reportItems() {
  return safeQuery(
    `SELECT ${ITEM_CODE_SQL('i')} AS item_code, i.barcode, i.sku, i.name_ar, i.default_cost, i.default_sale, i.is_active,
            c.name_ar AS category_name,
            COALESCE((SELECT SUM(m.qty_delta) FROM inv_stock_move m WHERE m.item_id = i.id),0) AS qty
     FROM inv_item i
     LEFT JOIN inv_item_category c ON c.id = i.category_id
     WHERE i.is_active = 1
     ORDER BY i.name_ar LIMIT 500`
  );
}

async function reportQtyFilter(mode) {
  const having = mode === 'zero' ? 'HAVING qty = 0' : mode === 'neg' ? 'HAVING qty < 0' : '';
  return safeQuery(
    `SELECT ${ITEM_CODE_SQL('i')} AS item_code, i.barcode, i.sku, i.name_ar,
            COALESCE(SUM(m.qty_delta),0) AS qty,
            w.name_ar AS warehouse_name
     FROM inv_item i
     LEFT JOIN inv_stock_move m ON m.item_id = i.id
     LEFT JOIN inv_warehouse w ON w.id = i.default_warehouse_id
     WHERE i.is_active = 1
     GROUP BY i.id, i.barcode, i.sku, i.name_ar, warehouse_name
     ${having}
     ORDER BY qty ASC, i.name_ar
     LIMIT 500`
  );
}

async function reportMoves(from, to) {
  const r = dateRange(from, to);
  return safeQuery(
    `SELECT m.move_no, m.move_date, m.movement_type_code, m.status,
            w.name_ar AS warehouse_name
     FROM inv_wh_move m
     LEFT JOIN inv_warehouse w ON w.id = m.warehouse_id
     WHERE m.move_date BETWEEN ? AND ?
     ORDER BY m.id DESC LIMIT 300`,
    [r.from, r.to]
  );
}

async function itemOnHand(itemId, warehouseId) {
  const rows = await safeQuery(
    `SELECT COALESCE(SUM(qty_delta), 0) AS bal
     FROM inv_stock_move
     WHERE item_id = ? AND warehouse_id = ?`,
    [Number(itemId), Number(warehouseId)]
  );
  return Number(rows[0]?.bal || 0);
}

const REF_TYPE_LABELS = {
  sale_invoice: 'فاتورة بيع',
  sales_invoice: 'فاتورة بيع',
  sale_return: 'مردود مبيعات',
  sales_return: 'مردود مبيعات',
  purchase_invoice: 'فاتورة شراء',
  pur_invoice: 'فاتورة شراء',
  purchase_return: 'مردود مشتريات',
  pur_return: 'مردود مشتريات',
  sales_delivery: 'سند تسليم',
  sal_delivery: 'سند تسليم',
  warehouse_move: 'حركة مستودع',
  inv_wh_move: 'حركة مستودع',
  wh_move: 'حركة مستودع',
  stocktake: 'جرد مخزون',
  inventory_stocktake: 'جرد مخزون',
  item_opening: 'رصيد افتتاحي',
  opening: 'رصيد افتتاحي',
  opening_balance: 'رصيد افتتاحي',
  adjustment: 'تسوية مخزون',
  stock_adjust: 'تسوية مخزون',
};

function refTypeLabel(refType) {
  const key = String(refType || '').trim();
  if (!key) return '—';
  return REF_TYPE_LABELS[key] || REF_TYPE_LABELS[key.toLowerCase()] || key;
}

function refDocUrl(refType, refId) {
  const id = Number(refId || 0);
  if (id < 1) return '';
  const t = String(refType || '').toLowerCase();
  if (t === 'sale_invoice' || t === 'sales_invoice') return `/sales/invoices/${id}`;
  if (t === 'sale_return' || t === 'sales_return') return `/sales/returns/${id}`;
  if (t === 'purchase_invoice' || t === 'pur_invoice') return `/purchases/invoices/${id}`;
  if (t === 'purchase_return' || t === 'pur_return') return `/purchases/returns/${id}`;
  if (t === 'sales_delivery' || t === 'sal_delivery') return `/sales/delivery/${id}`;
  return '';
}

/** كشف حركات مادة في مستودع مع رصيد تراكمي */
async function itemStockLedger(itemId, warehouseId) {
  const raw = await safeQuery(
    `SELECT m.id AS move_id, m.move_date, m.created_at, m.qty_delta, m.ref_type, m.ref_id,
            COALESCE(m.note, '') AS note,
            ${ITEM_CODE_SQL('it')} AS item_code, it.barcode, it.sku, it.name_ar AS item_name,
            COALESCE(
              si.invoice_no,
              sr.return_no,
              pi.invoice_no,
              pr.return_no,
              sd.delivery_no,
              NULLIF(CAST(m.ref_id AS CHAR), '0')
            ) AS doc_no,
            COALESCE(cust.name_ar, cust2.name_ar, sup.name_ar, '') AS party_name
     FROM inv_stock_move m
     INNER JOIN inv_item it ON it.id = m.item_id
     LEFT JOIN sal_invoice si
       ON m.ref_type IN ('sale_invoice','sales_invoice') AND si.id = m.ref_id
     LEFT JOIN crm_customer cust ON cust.id = si.customer_id
     LEFT JOIN sal_return sr
       ON m.ref_type IN ('sale_return','sales_return') AND sr.id = m.ref_id
     LEFT JOIN crm_customer cust2 ON cust2.id = sr.customer_id
     LEFT JOIN pur_invoice pi
       ON m.ref_type IN ('purchase_invoice','pur_invoice') AND pi.id = m.ref_id
     LEFT JOIN crm_supplier sup ON sup.id = pi.supplier_id
     LEFT JOIN pur_return pr
       ON m.ref_type IN ('purchase_return','pur_return') AND pr.id = m.ref_id
     LEFT JOIN sal_delivery sd
       ON m.ref_type IN ('sales_delivery','sal_delivery') AND sd.id = m.ref_id
     WHERE m.item_id = ? AND m.warehouse_id = ?
     ORDER BY COALESCE(m.move_date, DATE(m.created_at)) ASC, m.created_at ASC, m.id ASC
     LIMIT 5000`,
    [Number(itemId), Number(warehouseId)]
  );

  let rows = raw;
  if (!rows.length) {
    /* إن فشل الاستعلام المعزّز (جدول ناقص) جرّب الاستعلام الأساسي */
    rows = await safeQuery(
      `SELECT m.id AS move_id, m.move_date, m.created_at, m.qty_delta, m.ref_type, m.ref_id,
              COALESCE(m.note, '') AS note,
              ${ITEM_CODE_SQL('it')} AS item_code, it.barcode, it.sku, it.name_ar AS item_name,
              CAST(NULLIF(m.ref_id, 0) AS CHAR) AS doc_no,
              '' AS party_name
       FROM inv_stock_move m
       INNER JOIN inv_item it ON it.id = m.item_id
       WHERE m.item_id = ? AND m.warehouse_id = ?
       ORDER BY m.created_at ASC, m.id ASC
       LIMIT 5000`,
      [Number(itemId), Number(warehouseId)]
    );
  }

  let bal = 0;
  return rows.map((r) => {
    const qty = Number(r.qty_delta || 0);
    bal += qty;
    const refType = String(r.ref_type || '');
    return {
      ...r,
      qty_delta: qty,
      balance_after: bal,
      mov_type_label: refTypeLabel(refType),
      doc_no: String(r.doc_no || r.ref_id || ''),
      party_name: String(r.party_name || ''),
      doc_url: refDocUrl(refType, r.ref_id),
    };
  });
}

async function getItemBrief(itemId) {
  const rows = await safeQuery(
    `SELECT id, sku, barcode, ${ITEM_CODE_SQL('inv_item')} AS item_code, name_ar FROM inv_item WHERE id = ? LIMIT 1`,
    [Number(itemId)]
  );
  return rows[0] || null;
}

async function customerPurchasesByItem({ customerId, from, to, warehouseId = 0, summaryOnly = false } = {}) {
  const empty = { summary: [], details: [], totals: { qty: 0, line_total: 0, line_gross: 0 } };
  if (!customerId || !from || !to) return empty;

  const params = [Number(customerId), from, to];
  let wh = '';
  if (warehouseId > 0) {
    wh = ' AND i.warehouse_id = ? ';
    params.push(Number(warehouseId));
  }

  let details = [];
  try {
    details = await safeQuery(
      `SELECT i.id AS invoice_id, i.invoice_no, i.invoice_date,
              COALESCE(w.name_ar, '') AS warehouse_name,
              COALESCE(NULLIF(TRIM(it.barcode), ''), it.sku, '') AS item_sku,
              COALESCE(NULLIF(TRIM(it.barcode), ''), it.sku, '') AS item_code,
              COALESCE(NULLIF(TRIM(l.line_desc), ''), it.name_ar, '') AS item_name,
              it.id AS item_id,
              l.qty, l.unit_price,
              l.line_total,
              COALESCE(l.line_gross, l.line_total) AS line_gross
       FROM sal_invoice_line l
       INNER JOIN sal_invoice i ON i.id = l.invoice_id
       INNER JOIN inv_item it ON it.id = l.item_id
       LEFT JOIN inv_warehouse w ON w.id = i.warehouse_id
       WHERE i.status = 'confirmed'
         AND i.customer_id = ?
         AND i.invoice_date >= ?
         AND i.invoice_date <= ?
         ${wh}
       ORDER BY it.name_ar ASC, i.invoice_date ASC, i.id ASC
       LIMIT 5000`,
      params
    );
  } catch {
    return empty;
  }

  const map = new Map();
  let totQty = 0;
  let totLine = 0;
  let totGross = 0;
  for (const row of details) {
    const itemId = Number(row.item_id || 0);
    const qty = Number(row.qty || 0);
    const lineTotal = Number(row.line_total || 0);
    const lineGross = Number(row.line_gross || 0);
    totQty += qty;
    totLine += lineTotal;
    totGross += lineGross;
    if (!map.has(itemId)) {
      map.set(itemId, {
        item_id: itemId,
        item_sku: row.item_sku || '',
        item_name: row.item_name || '',
        qty: 0,
        line_total: 0,
        line_gross: 0,
        invoice_count: 0,
        _invs: new Set(),
      });
    }
    const s = map.get(itemId);
    s.qty += qty;
    s.line_total += lineTotal;
    s.line_gross += lineGross;
    s._invs.add(Number(row.invoice_id));
    s.invoice_count = s._invs.size;
  }
  const summary = [...map.values()].map(({ _invs, ...rest }) => rest);
  return {
    summary,
    details: summaryOnly ? [] : details,
    totals: { qty: totQty, line_total: totLine, line_gross: totGross },
  };
}

async function listCustomersForPicker(q = '', limit = 80) {
  const params = [];
  let where = 'c.is_active = 1';
  if (q) {
    where += ' AND (c.name_ar LIKE ? OR c.code LIKE ? OR IFNULL(c.phone,"") LIKE ?)';
    const like = `%${q}%`;
    params.push(like, like, like);
  }
  return safeQuery(
    `SELECT c.id, c.code, c.name_ar FROM crm_customer c
     WHERE ${where} ORDER BY c.name_ar LIMIT ${Math.min(200, limit)}`,
    params
  );
}

module.exports = {
  listWarehouses,
  resolveDefaultWarehouseId,
  listItems,
  listCategories,
  listUnits,
  listMovementTypes,
  listMoves,
  listStocktakeDocs,
  reportItems,
  reportQtyFilter,
  reportMoves,
  itemOnHand,
  itemStockLedger,
  getItemBrief,
  customerPurchasesByItem,
  listCustomersForPicker,
  dateRange,
};
